<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_Placeholder_Validator {

	private const RE = '/%(?:[0-9]+\$)?[-+0-9. ]*[a-zA-Z%]/';

	public static function validate(
		string $msgid,
		string $msgstr,
		?string $msgid_plural = null,
		?array $plural_forms = null
	): true|array {
		$singular = self::compare( $msgid, $msgstr );
		if ( $singular !== true ) {
			return $singular;
		}

		if ( $msgid_plural !== null && is_array( $plural_forms ) ) {
			foreach ( $plural_forms as $index => $plural_form ) {
				$result = self::compare( $msgid_plural, (string) $plural_form, (int) $index );
				if ( $result !== true ) {
					return $result;
				}
			}
		}

		return true;
	}

	private static function compare( string $original, string $translation, ?int $plural_index = null ): true|array {
		$orig_specs  = self::parse_specs( $original );
		$trans_specs = self::parse_specs( $translation );

		if ( count( $orig_specs ) !== count( $trans_specs ) ) {
			return [
				'orig'   => self::stringify( $orig_specs ),
				'trans'  => self::stringify( $trans_specs ),
				'reason' => $plural_index === null
					? 'count mismatch'
					: 'count mismatch in plural form ' . $plural_index,
			];
		}

		if ( self::all_numbered( $orig_specs ) && self::all_numbered( $trans_specs ) ) {
			$orig_map  = self::numbered_map( $orig_specs );
			$trans_map = self::numbered_map( $trans_specs );
			if ( $orig_map !== $trans_map ) {
				return [
					'orig'   => self::stringify( $orig_specs ),
					'trans'  => self::stringify( $trans_specs ),
					'reason' => $plural_index === null
						? 'type mismatch'
						: 'type mismatch in plural form ' . $plural_index,
				];
			}

			return true;
		}

		foreach ( $orig_specs as $index => $orig_spec ) {
			$trans_spec = $trans_specs[ $index ] ?? null;
			if ( ! $trans_spec || $orig_spec['type'] !== $trans_spec['type'] ) {
				return [
					'orig'   => self::stringify( $orig_specs ),
					'trans'  => self::stringify( $trans_specs ),
					'reason' => $plural_index === null
						? 'type mismatch'
						: 'type mismatch in plural form ' . $plural_index,
				];
			}
		}

		return true;
	}

	private static function parse_specs( string $text ): array {
		$matches = self::extract( $text );
		$specs   = [];

		foreach ( $matches as $placeholder ) {
			if ( $placeholder === '%%' ) {
				continue;
			}

			preg_match( '/^%(?:(\d+)\$)?[-+0-9. ]*([a-zA-Z])$/', $placeholder, $parts );
			$specs[] = [
				'raw'      => $placeholder,
				'position' => isset( $parts[1] ) && $parts[1] !== '' ? (int) $parts[1] : null,
				'type'     => isset( $parts[2] ) ? strtolower( $parts[2] ) : '',
			];
		}

		return $specs;
	}

	private static function all_numbered( array $specs ): bool {
		if ( empty( $specs ) ) {
			return false;
		}

		foreach ( $specs as $spec ) {
			if ( $spec['position'] === null ) {
				return false;
			}
		}

		return true;
	}

	private static function numbered_map( array $specs ): array {
		$map = [];
		foreach ( $specs as $spec ) {
			$map[ $spec['position'] ] = $spec['type'];
		}
		ksort( $map );
		return $map;
	}

	private static function stringify( array $specs ): string {
		if ( empty( $specs ) ) {
			return '(none)';
		}

		return implode( ' ', array_map( static fn( array $spec ): string => $spec['raw'], $specs ) );
	}

	private static function extract( string $s ): array {
		preg_match_all( self::RE, $s, $m );
		return $m[0];
	}
}
