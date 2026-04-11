<?php
/**
 * Open World Translate — DeepL API Client
 *
 * Handles communication with DeepL Translate API for automatic translation.
 * Supports both Free and Pro plans.
 *
 * @see https://developers.deepl.com/docs/getting-started/intro
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_DeepL {

	const OPTION_KEY  = 'ow_deepl_api_key';
	const OPTION_PLAN = 'ow_deepl_plan'; // 'free' or 'pro'
	const MAX_BATCH   = 50;              // max strings per API call

	// ── Base URL ──────────────────────────────────────────────────────────────

	public static function get_base_url(): string {
		$plan = get_option( self::OPTION_PLAN, 'free' );
		return $plan === 'pro'
			? 'https://api.deepl.com/v2'
			: 'https://api-free.deepl.com/v2';
	}

	public static function get_api_key(): string {
		return trim( (string) get_option( self::OPTION_KEY, '' ) );
	}

	public static function is_configured(): bool {
		return self::get_api_key() !== '';
	}

	// ── Save settings ─────────────────────────────────────────────────────────

	public static function save_settings( string $api_key, string $plan ): void {
		update_option( self::OPTION_KEY,  sanitize_text_field( $api_key ) );
		update_option( self::OPTION_PLAN, in_array( $plan, [ 'free', 'pro' ], true ) ? $plan : 'free' );
	}

	// ── Test Connection (GET /v2/usage) ───────────────────────────────────────

	/**
	 * @return array{ok:bool, character_count:int, character_limit:int, error:string}
	 */
	public static function test_connection(): array {
		$key = self::get_api_key();
		if ( ! $key ) {
			return [ 'ok' => false, 'error' => __( 'No API key configured.', 'open-world-translate' ) ];
		}

		$response = wp_remote_get( self::get_base_url() . '/usage', [
			'timeout' => 15,
			'headers' => [
				'Authorization' => 'DeepL-Auth-Key ' . $key,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'error' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 403 ) {
			return [ 'ok' => false, 'error' => __( 'Invalid API key. Check your key at deepl.com/your-account/keys', 'open-world-translate' ) ];
		}

		if ( $code !== 200 || ! is_array( $body ) ) {
			/* translators: %d: HTTP code */
			return [ 'ok' => false, 'error' => sprintf( __( 'Unexpected response (HTTP %d)', 'open-world-translate' ), $code ) ];
		}

		return [
			'ok'              => true,
			'character_count' => (int) ( $body['character_count'] ?? 0 ),
			'character_limit' => (int) ( $body['character_limit'] ?? 0 ),
			'error'           => '',
		];
	}

	// ── Get Usage (cached 5 min) ──────────────────────────────────────────────

	/**
	 * @return array{character_count:int, character_limit:int}|null
	 */
	public static function get_usage(): ?array {
		$cached = get_transient( 'ow_deepl_usage' );
		if ( $cached !== false ) return $cached;

		$result = self::test_connection();
		if ( ! $result['ok'] ) return null;

		$usage = [
			'character_count' => $result['character_count'],
			'character_limit' => $result['character_limit'],
		];
		set_transient( 'ow_deepl_usage', $usage, 5 * MINUTE_IN_SECONDS );
		return $usage;
	}

	// ── Translate (POST /v2/translate) ─────────────────────────────────────────

	/**
	 * Translate an array of strings.
	 *
	 * @param string[] $texts        Array of source texts
	 * @param string   $source_lang  ISO code (e.g. 'PL', 'EN', 'DE')
	 * @param string   $target_lang  ISO code
	 * @return array{ok:bool, translations:string[], chars_used:int, error:string}
	 */
	public static function translate( array $texts, string $source_lang, string $target_lang ): array {
		$key = self::get_api_key();
		if ( ! $key ) {
			return [ 'ok' => false, 'translations' => [], 'chars_used' => 0, 'error' => __( 'No API key.', 'open-world-translate' ) ];
		}

		if ( empty( $texts ) ) {
			return [ 'ok' => true, 'translations' => [], 'chars_used' => 0, 'error' => '' ];
		}

		$chars_sent = array_sum( array_map( 'mb_strlen', $texts ) );

		$response = wp_remote_post( self::get_base_url() . '/translate', [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'DeepL-Auth-Key ' . $key,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( [
				'text'        => array_values( $texts ),
				'source_lang' => strtoupper( $source_lang ),
				'target_lang' => self::normalize_lang_code( $target_lang ),
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'translations' => [], 'chars_used' => 0, 'error' => $response->get_error_message(), 'error_type' => 'network' ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 456 ) {
			return [ 'ok' => false, 'translations' => [], 'chars_used' => 0, 'error' => __( 'DeepL quota exceeded. Upgrade your plan or wait until next month.', 'open-world-translate' ) ];
		}

		if ( $code === 429 ) {
			return [ 'ok' => false, 'translations' => [], 'chars_used' => 0, 'error' => __( 'Rate limited by DeepL. Please wait a moment and try again.', 'open-world-translate' ), 'error_type' => 'rate_limit' ];
		}

		if ( $code !== 200 || ! isset( $body['translations'] ) ) {
			/* translators: 1: HTTP code, 2: Error message */
			return [ 'ok' => false, 'translations' => [], 'chars_used' => 0, 'error' => sprintf( __( 'DeepL error (HTTP %1$d): %2$s', 'open-world-translate' ), $code, wp_remote_retrieve_body( $response ) ) ];
		}

		$translated = array_map( fn( $t ) => $t['text'] ?? '', $body['translations'] );

		// Invalidate usage cache
		delete_transient( 'ow_deepl_usage' );

		return [
			'ok'           => true,
			'translations' => $translated,
			'chars_used'   => $chars_sent,
			'error'        => '',
		];
	}

	public static function translate_single( string $text, string $target_lang ): string {
		$source_lang_code = OW_Languages::get_source();
		if ( ! $source_lang_code || $text === '' ) {
			return '';
		}

		$result = self::translate( [ $text ], $source_lang_code, $target_lang );
		if ( ! $result['ok'] ) {
			return '';
		}

		return (string) ( $result['translations'][0] ?? '' );
	}

	// ── Batch Translate from DB ───────────────────────────────────────────────

	/**
	 * Translate one batch of untranslated strings.
	 *
	 * @return array{ok:bool, translated:int, chars_used:int, remaining:int, error:string}
	 */
	public static function translate_batch(
		string $target_lang,
		string $domain      = '',
		string $source_type = '',
		string $source      = '',
		int    $batch_size  = 0
	): array {
		if ( $batch_size <= 0 ) $batch_size = self::MAX_BATCH;
		$batch_size = min( $batch_size, self::MAX_BATCH );

		$source_lang_code = OW_Languages::get_source();
		if ( ! $source_lang_code ) {
			return [ 'ok' => false, 'translated' => 0, 'chars_used' => 0, 'remaining' => 0, 'error' => __( 'No source language set.', 'open-world-translate' ) ];
		}

		// Fetch untranslated rows
		$rows = OW_DB::get_page( $target_lang, $batch_size, 0, $domain, 'untranslated', '', $source, $source_type );
		if ( empty( $rows ) ) {
			return [ 'ok' => true, 'translated' => 0, 'chars_used' => 0, 'remaining' => 0, 'error' => '' ];
		}

		// Remaining count
		$total_remaining = OW_DB::count( $target_lang, $domain, 'untranslated', '', $source, $source_type );

		// Prepare texts
		$texts = [];
		$ids   = [];
		foreach ( $rows as $row ) {
			$texts[] = $row['msgid'];
			$ids[]   = (int) $row['id'];
		}

		// Call DeepL
		$result = self::translate( $texts, $source_lang_code, $target_lang );

		if ( ! $result['ok'] ) {
			return [
				'ok'         => false,
				'translated' => 0,
				'chars_used' => 0,
				'remaining'  => $total_remaining,
				'error'      => $result['error'],
				'error_type' => $result['error_type'] ?? 'fatal',
			];
		}

		// Save translations
		$saved = 0;
		$placeholder_warnings = [];
		foreach ( $result['translations'] as $i => $msgstr ) {
			if ( isset( $ids[ $i ] ) && $msgstr !== '' ) {
				OW_DB::update_msgstr( $ids[ $i ], $msgstr );
				$row_after = OW_DB::get_row( $ids[ $i ] );
				if ( $row_after && ! empty( $row_after['placeholder_warning'] ) && empty( $row_after['warning_ignored'] ) ) {
					$detail = json_decode( (string) $row_after['placeholder_warning'], true );
					$placeholder_warnings[] = [
						'id'          => $ids[ $i ],
						'original'    => (string) $row_after['msgid'],
						'translation' => (string) $row_after['msgstr'],
						'reason'      => is_array( $detail ) ? (string) ( $detail['reason'] ?? '' ) : '',
					];
				}
				$saved++;
			}
		}

		return [
			'ok'                   => true,
			'translated'           => $saved,
			'chars_used'           => $result['chars_used'],
			'remaining'            => max( 0, $total_remaining - $saved ),
			'placeholder_warnings' => $placeholder_warnings,
			'error'                => '',
		];
	}

	// ── Language Code Mapping ─────────────────────────────────────────────────

	/**
	 * DeepL uses specific target language codes (e.g. 'PT-BR', 'EN-US', 'EN-GB').
	 * Map our 2-letter codes to DeepL-compatible codes.
	 */
	private static function normalize_lang_code( string $code ): string {
		$map = [
			'en' => 'EN-US',
			'pt' => 'PT-BR',
			'zh' => 'ZH-HANS',
		];
		return $map[ strtolower( $code ) ] ?? strtoupper( $code );
	}
}
