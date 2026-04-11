<?php
/**
 * Open World Translate — PO File Parser and Exporter
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_PO {

	// ── Parse ─────────────────────────────────────────────────────────────────

	/**
	 * Parse a PO file content string into an array of translation entries.
	 *
	 * @return array[] [ ['msgid' => string, 'msgstr' => string, 'context' => string|null], ... ]
	 */
	public function parse( string $content ): array {
		$entries = [];
		$lines   = explode( "\n", $content );

		$current_msgid        = null;
		$current_msgstr       = null;
		$current_msgid_plural = null;
		$current_msgstr_plural = [];  // array of plural forms
		$current_plural_idx   = -1;  // current msgstr[N] index
		$current_context      = null;
		$reading          = null; // 'msgid' | 'msgstr' | 'msgctxt'

		$save = function () use ( &$entries, &$current_msgid, &$current_msgstr, &$current_msgid_plural, &$current_msgstr_plural, &$current_context, &$current_plural_idx ): void {
			if ( $current_msgid !== null && $current_msgid !== '' ) {
				$entries[] = [
					'msgid'         => $current_msgid,
					'msgstr'        => $current_msgstr ?? '',
					'msgid_plural'  => $current_msgid_plural,
					'msgstr_plural' => ! empty( $current_msgstr_plural ) ? $current_msgstr_plural : null,
					'context'       => $current_context,
				];
			}
			$current_msgid         = null;
			$current_msgstr        = null;
			$current_msgid_plural  = null;
			$current_msgstr_plural = [];
			$current_plural_idx    = -1;
			$current_context       = null;
		};

		foreach ( $lines as $line ) {
			$line = rtrim( $line );

			// Skip comments and blank lines (but blank line = end of entry)
			if ( $line === '' ) {
				if ( $current_msgid !== null ) {
					$save();
				}
				$reading = null;
				continue;
			}
			if ( $line[0] === '#' ) continue;

			// msgctxt
			if ( preg_match( '/^msgctxt\s+"(.*)"$/', $line, $m ) ) {
				$current_context = $this->unescape( $m[1] );
				$reading = 'msgctxt';
				continue;
			}

			// msgid_plural
			if ( preg_match( '/^msgid_plural\s+"(.*)"$/', $line, $m ) ) {
				$current_msgid_plural = $this->unescape( $m[1] );
				$reading = 'msgid_plural';
				continue;
			}

			// msgid
			if ( preg_match( '/^msgid\s+"(.*)"$/', $line, $m ) ) {
				if ( $current_msgid !== null ) $save();
				$current_msgid = $this->unescape( $m[1] );
				$reading = 'msgid';
				continue;
			}

			// msgstr[N] (plural forms)
			if ( preg_match( '/^msgstr\[(\d+)\]\s+"(.*)"$/', $line, $m ) ) {
				$current_plural_idx = (int) $m[1];
				$current_msgstr_plural[ $current_plural_idx ] = $this->unescape( $m[2] );
				// Also set msgstr from form[0] for fallback
				if ( $current_plural_idx === 0 ) $current_msgstr = $this->unescape( $m[2] );
				$reading = 'msgstr_plural';
				continue;
			}

			// msgstr (singular)
			if ( preg_match( '/^msgstr\s+"(.*)"$/', $line, $m ) ) {
				$current_msgstr = $this->unescape( $m[1] );
				$reading = 'msgstr';
				continue;
			}

			// Continuation line "..."
			if ( preg_match( '/^"(.*)"$/', $line, $m ) ) {
				$piece = $this->unescape( $m[1] );
				if ( $reading === 'msgid' )         $current_msgid   .= $piece;
				if ( $reading === 'msgstr' )         $current_msgstr  .= $piece;
				if ( $reading === 'msgid_plural' )   $current_msgid_plural .= $piece;
				if ( $reading === 'msgstr_plural' )  $current_msgstr_plural[ $current_plural_idx ] .= $piece;
				if ( $reading === 'msgctxt' )        $current_context .= $piece;
				continue;
			}
		}

		// Last entry
		if ( $current_msgid !== null ) {
			$save();
		}

		return $entries;
	}

	// ── Export ────────────────────────────────────────────────────────────────

	/**
	 * Generate PO file content from the database.
	 */
	public function export( string $lang, string $domain ): string {
		$locale = OW_Languages::get_locale( $lang );

		$date   = gmdate( 'Y-m-d H:i+0000' );
		$rows   = OW_DB::get_all( $lang, $domain );

		$lines   = [];
		$lines[] = '# Open World Translates';
		$lines[] = "# Language: {$lang} ({$locale})";
		$lines[] = "# Domain: {$domain}";
		$lines[] = "# Generated: {$date}";
		$lines[] = '';
		$lines[] = 'msgid ""';
		$lines[] = 'msgstr ""';
		$lines[] = '"Content-Type: text/plain; charset=UTF-8\n"';
		$lines[] = '"Content-Transfer-Encoding: 8bit\n"';
		$lines[] = '"Language: ' . str_replace( '_', '-', $locale ) . '\n"';
		$lines[] = '';

		foreach ( $rows as $row ) {
			$lines[] = '#. source: ' . ( $row['source_name'] ?? '' ) . ( $row['source_file'] ? ' — ' . $row['source_file'] : '' );
			if ( ! empty( $row['placeholder_warning'] ) ) {
				$warning = json_decode( (string) $row['placeholder_warning'], true );
				$lines[] = '#, fuzzy';
				$lines[] = '# OW-WARNING: placeholder mismatch — orig: ' . ( $warning['orig'] ?? '' ) . ', trans: ' . ( $warning['trans'] ?? '' );
			}
			if ( $row['context'] ) {
				$lines[] = 'msgctxt "' . $this->escape( $row['context'] ) . '"';
			}
			$lines[] = 'msgid "' . $this->escape( $row['msgid'] ) . '"';
			if ( ! empty( $row['msgid_plural'] ) ) {
				$lines[] = 'msgid_plural "' . $this->escape( $row['msgid_plural'] ) . '"';
				$plural_forms = ! empty( $row['msgstr_plural'] ) ? json_decode( (string) $row['msgstr_plural'], true ) : [];
				if ( is_array( $plural_forms ) && ! empty( $plural_forms ) ) {
					foreach ( $plural_forms as $index => $plural_form ) {
						$lines[] = 'msgstr[' . (int) $index . '] "' . $this->escape( (string) $plural_form ) . '"';
					}
				} else {
					$lines[] = 'msgstr[0] "' . $this->escape( $row['msgstr'] ) . '"';
				}
			} else {
				$lines[] = 'msgstr "' . $this->escape( $row['msgstr'] ) . '"';
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function unescape( string $s ): string {
		return strtr( $s, [
			'\\"'  => '"',
			'\\n'  => "\n",
			'\\t'  => "\t",
			'\\\\' => '\\',
		] );
	}

	private function escape( string $s ): string {
		return strtr( $s, [
			'\\'  => '\\\\',
			'"'   => '\\"',
			"\n"  => '\\n',
			"\t"  => '\\t',
		] );
	}
}
