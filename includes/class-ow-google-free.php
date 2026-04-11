<?php
/**
 * Open World Translate — Google Translate Free Client
 *
 * Uses the unofficial Google Translate GTX endpoint for free server-side
 * batch auto-translation. No API key required.
 *
 * Rate limit is handled gracefully: if Google returns HTTP 429, we return an
 * error identical in structure to OW_DeepL so the batch runner can pause.
 *
 * @see https://translate.googleapis.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_Google_Free {

	const OPTION_ENABLED = 'ow_google_free_enabled'; // '1' when selected as provider
	const MAX_BATCH      = 20;                        // Keep batches small to avoid rate limits
	const GTX_ENDPOINT   = 'https://translate.googleapis.com/translate_a/single';

	// ── Provider State ─────────────────────────────────────────────────────────

	public static function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, '1' );
	}

	public static function set_enabled( bool $enabled ): void {
		update_option( self::OPTION_ENABLED, $enabled ? '1' : '' );
	}

	// ── Translate ──────────────────────────────────────────────────────────────

	/**
	 * Translate an array of strings via the GTX endpoint.
	 *
	 * @param string[] $texts       Source strings.
	 * @param string   $source_lang ISO-639 code (e.g. 'en', 'pl').
	 * @param string   $target_lang ISO-639 code.
	 * @return array{ok:bool, translations:string[], chars_used:int, error:string}
	 */
	public static function translate( array $texts, string $source_lang, string $target_lang ): array {
		if ( empty( $texts ) ) {
			return [ 'ok' => true, 'translations' => [], 'chars_used' => 0, 'error' => '' ];
		}

		$chars_sent   = array_sum( array_map( 'mb_strlen', $texts ) );
		$translations = [];

		// GTX endpoint translates one string per request efficiently.
		// We loop and concatenate with a separator for batch requests.
		// Strategy: join with a rare sentinel, translate once, then split.
		// This minimises HTTP requests while keeping semantics intact.
		$sentinel = "\n⁂\n";
		$joined   = implode( $sentinel, $texts );

		$url = add_query_arg( [
			'client' => 'gtx',
			'sl'     => strtolower( $source_lang ),
			'tl'     => strtolower( $target_lang ),
			'dt'     => 't',
			'q'      => urlencode( $joined ),
		], self::GTX_ENDPOINT );

		$response = wp_remote_get( $url, [
			'timeout' => 30,
			'headers' => [
				'User-Agent' => 'Mozilla/5.0 (compatible; Open World Translate Plugin)',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'translations' => [], 'chars_used' => 0, 'error' => $response->get_error_message(), 'error_type' => 'network' ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 429 ) {
			return [
				'ok'           => false,
				'translations' => [],
				'chars_used'   => 0,
				/* translators: Google Translate API rate limit error */
				'error'        => __( 'Google Translate rate limit hit. Please wait a moment and try again.', 'open-world-translate' ),
				'error_type'   => 'rate_limit',
			];
		}

		if ( $code !== 200 || ! is_array( $body ) || ! isset( $body[0] ) ) {
			/* translators: %d: HTTP status code */
			return [ 'ok' => false, 'translations' => [], 'chars_used' => 0, 'error' => sprintf( __( 'Google Translate error (HTTP %d).', 'open-world-translate' ), $code ) ];
		}

		// Parse GTX response: array of [translated, original] pairs.
		$joined_translation = '';
		foreach ( $body[0] as $chunk ) {
			if ( isset( $chunk[0] ) && is_string( $chunk[0] ) ) {
				$joined_translation .= $chunk[0];
			}
		}

		// Split back by sentinel (Google may alter whitespace around it, so use a loose pattern).
		$parts = preg_split( '/\s*⁂\s*/u', $joined_translation );

		// Map each part back to the input index.
		foreach ( $texts as $i => $original ) {
			$translations[] = isset( $parts[ $i ] ) ? trim( $parts[ $i ] ) : '';
		}

		return [
			'ok'           => true,
			'translations' => $translations,
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
	 * Translate one batch of untranslated strings from the database.
	 * Mirrors OW_DeepL::translate_batch() exactly.
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

		$rows = OW_DB::get_page( $target_lang, $batch_size, 0, $domain, 'untranslated', '', $source, $source_type );
		if ( empty( $rows ) ) {
			return [ 'ok' => true, 'translated' => 0, 'chars_used' => 0, 'remaining' => 0, 'error' => '' ];
		}

		$total_remaining = OW_DB::count( $target_lang, $domain, 'untranslated', '', $source, $source_type );

		$texts = [];
		$ids   = [];
		foreach ( $rows as $row ) {
			$texts[] = $row['msgid'];
			$ids[]   = (int) $row['id'];
		}

		// Small delay between batches to be polite to Google's servers.
		if ( $batch_size > 5 ) usleep( 300000 ); // 300ms

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
}
