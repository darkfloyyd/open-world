<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_HTML_Validator {

	public static function validate( string $original, string $translation ): true|array {
		$orig_tags  = self::extract_tags( $original );
		$trans_tags = self::extract_tags( $translation );

		if ( self::count_map( $orig_tags ) !== self::count_map( $trans_tags ) ) {
			return [
				'orig_tags'  => self::stringify( $orig_tags ),
				'trans_tags' => self::stringify( $trans_tags ),
				'reason'     => 'tag count mismatch',
			];
		}

		if ( ! self::is_valid_nesting( $translation ) ) {
			return [
				'orig_tags'  => self::stringify( $orig_tags ),
				'trans_tags' => self::stringify( $trans_tags ),
				'reason'     => 'invalid nesting',
			];
		}

		return true;
	}

	private static function extract_tags( string $text ): array {
		preg_match_all( '/<\/?[a-z][a-z0-9]*[^>]*>/i', $text, $matches );

		$tags = [];
		foreach ( $matches[0] as $tag ) {
			$parsed = self::parse_tag( $tag );
			if ( $parsed === null ) {
				continue;
			}
			$tags[] = $parsed;
		}

		return $tags;
	}

	private static function parse_tag( string $tag ): ?array {
		if ( ! preg_match( '/^<\s*(\/)?\s*([a-z][a-z0-9]*)/i', $tag, $match ) ) {
			return null;
		}

		$name          = strtolower( $match[2] );
		$is_closing    = ! empty( $match[1] );
		$self_closing  = ! $is_closing && ( preg_match( '/\/\s*>$/', $tag ) === 1 || self::is_void_tag( $name ) );

		return [
			'name'         => $name,
			'is_closing'   => $is_closing,
			'is_self'      => $self_closing,
		];
	}

	private static function count_map( array $tags ): array {
		$counts = [];

		foreach ( $tags as $tag ) {
			$key = ( $tag['is_closing'] ? '/' : '' ) . $tag['name'];
			if ( ! isset( $counts[ $key ] ) ) {
				$counts[ $key ] = 0;
			}
			$counts[ $key ]++;
		}

		ksort( $counts );
		return $counts;
	}

	private static function stringify( array $tags ): string {
		if ( empty( $tags ) ) {
			return '(none)';
		}

		return implode( ' ', array_map(
			static function ( array $tag ): string {
				if ( $tag['is_closing'] ) {
					return '</' . $tag['name'] . '>';
				}
				if ( $tag['is_self'] ) {
					return '<' . $tag['name'] . '/>';
				}
				return '<' . $tag['name'] . '>';
			},
			$tags
		) );
	}

	private static function is_valid_nesting( string $text ): bool {
		$tags  = self::extract_tags( $text );
		$stack = [];

		foreach ( $tags as $tag ) {
			if ( $tag['is_self'] ) {
				continue;
			}

			if ( ! $tag['is_closing'] ) {
				$stack[] = $tag['name'];
				continue;
			}

			$top = array_pop( $stack );
			if ( $top !== $tag['name'] ) {
				return false;
			}
		}

		return empty( $stack );
	}

	private static function is_void_tag( string $name ): bool {
		return in_array( $name, [
			'area',
			'base',
			'br',
			'col',
			'embed',
			'hr',
			'img',
			'input',
			'link',
			'meta',
			'param',
			'source',
			'track',
			'wbr',
		], true );
	}
}
