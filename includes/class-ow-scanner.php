<?php
/**
 * Open World — String Scanner
 *
 * Scans PHP source files for gettext calls and crawls pages for dynamic strings.
 * Stores all found strings as untranslated seeds (msgstr='') for all target languages.
 *
 * Two modes:
 *  - Smart Scan (default): crawls all published pages + WooCommerce endpoints,
 *    captures only strings that are actually rendered on the frontend.
 *  - Full Source Scan: regex scans PHP files + POT imports (legacy, high volume).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_Scanner {

	// ── Smart Scan (recommended) ─────────────────────────────────────────────

	/**
	 * Crawl all visible frontend URLs and capture rendered strings.
	 * Auto-detects WooCommerce endpoints if WC is active.
	 * Returns count of newly seeded strings.
	 */
	public function scan_smart(): int {
		set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged

		$urls = $this->get_frontend_urls();
		$all_results = [];

		foreach ( $urls as $url ) {
			$results     = $this->scan_dynamic_strings( $url );
			$all_results = array_merge( $all_results, $results );
		}

		// Also scan child theme source (lightweight, only user's own files)
		$all_results = array_merge( $all_results, $this->scan_theme() );

		return $this->seed_to_db( $all_results );
	}

	/**
	 * Collect all frontend URLs to crawl during Smart Scan.
	 */
	private function get_frontend_urls(): array {
		$urls = [];

		// Homepage
		$urls[] = home_url( '/' );

		// All published pages
		$page_ids = get_posts( [
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );
		foreach ( $page_ids as $id ) {
			$urls[] = get_permalink( $id );
		}

		// All published products (if WooCommerce)
		if ( class_exists( 'WooCommerce' ) ) {
			$product_ids = get_posts( [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 20, // sample — we don't need all products
				'fields'         => 'ids',
			] );
			foreach ( $product_ids as $id ) {
				$urls[] = get_permalink( $id );
			}

			// WooCommerce-specific endpoints
			$wc_pages = [
				'shop'       => wc_get_page_id( 'shop' ),
				'cart'       => wc_get_page_id( 'cart' ),
				'checkout'   => wc_get_page_id( 'checkout' ),
				'myaccount'  => wc_get_page_id( 'myaccount' ),
			];
			foreach ( $wc_pages as $slug => $page_id ) {
				if ( $page_id > 0 ) {
					$url = get_permalink( $page_id );
					if ( $url ) $urls[] = $url;
				}
			}
		}

		// Deduplicate
		return array_unique( array_filter( $urls ) );
	}

	// ── PHP Source Scanner (Full Source Scan) ─────────────────────────────────

	/**
	 * Scan a directory recursively for gettext function calls.
	 */
	public function scan_directory( string $dir, string $domain, string $source_type = 'plugin', ?string $source_name = null ): array {
		$results = [];

		if ( ! is_dir( $dir ) ) return $results;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			/** @var SplFileInfo $file */
			if ( $file->getExtension() !== 'php' ) continue;

			$content = file_get_contents( $file->getRealPath() );
			if ( ! $content ) continue;

			$results = array_merge( $results, $this->extract_from_source( $content, $domain, $source_type, $source_name, $file->getRealPath() ) );
		}

		return $results;
	}

	/**
	 * Extract all gettext calls from PHP source content.
	 */
	private function extract_from_source( string $content, string $domain, string $source_type, ?string $source_name, string $filepath ): array {
		$results = [];
		$rel_file = str_replace( ABSPATH, '', $filepath );

		// Match __(, _e(, esc_html__(, esc_attr__(, _x(, esc_html_x(
		// Captures: function name, opening quote, string content
		$pattern = '/(?:__|_e|esc_html__|esc_attr__|_x|esc_html_x)\s*\(\s*([\'"])(.+?)\1/s';

		if ( preg_match_all( $pattern, $content, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$msgid = $match[2];
				if ( strlen( $msgid ) < 2 ) continue; // Skip very short strings
				$results[] = [
					'msgid'       => $msgid,
					'domain'      => $domain,
					'context'     => null,
					'source_type' => $source_type,
					'source_name' => $source_name,
					'source_file' => $rel_file,
				];
			}
		}

		// Match _n( single, plural, count, domain )
		$plural_pattern = '/_n\s*\(\s*([\'"])(.+?)\1\s*,\s*([\'"])(.+?)\3/s';
		if ( preg_match_all( $plural_pattern, $content, $pm, PREG_SET_ORDER ) ) {
			foreach ( $pm as $match ) {
				foreach ( [ $match[2], $match[4] ] as $msgid ) {
					if ( strlen( $msgid ) < 2 ) continue;
					$results[] = [
						'msgid'       => $msgid,
						'domain'      => $domain,
						'context'     => null,
						'source_type' => $source_type,
						'source_name' => $source_name,
						'source_file' => $rel_file,
					];
				}
			}
		}

		return $results;
	}

	// ── Theme and WooCommerce Scanners ────────────────────────────────────────

	public function scan_theme(): array {
		$dir  = get_stylesheet_directory();
		$name = get_stylesheet(); // e.g. 'generatepress-child'
		return $this->scan_directory( $dir, $name, 'theme', $name );
	}

	public function scan_parent_theme(): array {
		$dir  = get_template_directory();  // parent theme dir
		$name = get_template();            // 'generatepress'

		// Skip if parent = child (no child theme active)
		if ( $dir === get_stylesheet_directory() ) return [];

		return $this->scan_directory( $dir, $name, 'theme', $name );
	}

	public function scan_woocommerce(): array {
		$pot_file = WP_PLUGIN_DIR . '/woocommerce/i18n/languages/woocommerce.pot';

		if ( ! file_exists( $pot_file ) ) {
			return [];
		}

		$content = file_get_contents( $pot_file );
		if ( ! $content ) return [];

		$results = [];

		// Parse PO/POT format: lines starting with msgid
		preg_match_all( '/^msgid\s+"(.+)"$/m', $content, $m );
		foreach ( $m[1] as $msgid ) {
			$msgid = $this->unescape_po_string( $msgid );
			if ( strlen( $msgid ) < 2 ) continue;
			$results[] = [
				'msgid'       => $msgid,
				'domain'      => 'woocommerce',
				'context'     => null,
				'source_type' => 'plugin',
				'source_name' => 'woocommerce',
				'source_file' => null,
			];
		}

		return $results;
	}

	private function unescape_po_string( string $s ): string {
		return strtr( $s, [ '\"' => '"', '\\n' => "\n", '\\t' => "\t", '\\\\' => '\\' ] );
	}

	// ── Dynamic String Capture (Page Crawl) ──────────────────────────────────

	/**
	 * Crawl a page URL and capture all gettext calls made during rendering.
	 * Uses X-OW-Scan header to activate capture mode in the plugin engine.
	 */
	public function scan_dynamic_strings( string $page_url ): array {
		$page_url = esc_url_raw( $page_url );

		// 1. Restrict scanner to same origin requests as an extra safety layer
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$page_host = wp_parse_url( $page_url, PHP_URL_HOST );
		if ( ! $home_host || ! $page_host || $home_host !== $page_host ) {
			return [];
		}

		$transient_key = 'ow_scan_' . md5( $page_url );

		$cookies = [];
		// Optionally disable cookie forwarding for security-sensitive users (via Settings UI or constant)
		$default_forward = get_option( 'ow_forward_cookies', 'yes' ) === 'yes' && ! defined( 'OW_SCANNER_DISABLE_COOKIE_FORWARDING' );
		$forward_cookies = apply_filters( 'ow_scanner_forward_cookies', $default_forward );

		if ( $forward_cookies ) {
			// Pass selective admin auth/session cookies so restricted pages are accessible
			foreach ( $_COOKIE as $name => $value ) {
				if (
					strpos( $name, 'wordpress_logged_in_' ) === 0 ||
					strpos( $name, 'woocommerce_' ) === 0 ||
					strpos( $name, 'wp_woocommerce_session_' ) === 0
				) {
					$cookies[] = new WP_Http_Cookie( [ 'name' => $name, 'value' => $value ] );
				}
			}
		}

		wp_remote_get( $page_url, [
			'timeout'   => 20,
			'headers'   => [ 'X-OW-Scan' => '1' ],
			'cookies'   => $cookies,
			'sslverify' => true,
		] );

		// Results are stored by the engine during the remote request
		$results = get_transient( $transient_key );
		delete_transient( $transient_key );

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Legacy: scan all published pages + products via crawl.
	 */
	public function scan_all_pages(): int {
		$urls = $this->get_frontend_urls();
		$all_results = [];

		foreach ( $urls as $url ) {
			$results     = $this->scan_dynamic_strings( $url );
			$all_results = array_merge( $all_results, $results );
		}

		return $this->seed_to_db( $all_results );
	}

	// ── Clean Unused Strings ─────────────────────────────────────────────────

	/**
	 * Remove untranslated strings from source scan that were never seen in page crawl.
	 * Only removes strings with empty msgstr (preserves user translations).
	 * Returns count of removed rows.
	 */
	public static function clean_unused(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'ow_translations';

		// Delete untranslated strings from static scans (theme/plugin source types)
		// that have no user translation (msgstr is empty).
		// Dynamic/imported strings are kept.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$deleted = $wpdb->query(
			"DELETE FROM {$table}
			 WHERE msgstr = ''
			   AND source_type IN ('theme', 'plugin')
			   AND (msgstr_plural IS NULL OR msgstr_plural = '')"
		);
		// phpcs:enable

		return (int) $deleted;
	}

	// ── DB Seeding ────────────────────────────────────────────────────────────

	/**
	 * Insert scan results as empty translations for every target language.
	 * Returns count of newly inserted strings (across all languages).
	 */
	public function seed_to_db( array $results ): int {
		$count   = 0;
		$targets = OW_Languages::get_target_languages();


		// Deduplicate by msgid+domain
		$deduped = [];
		foreach ( $results as $r ) {
			$key = $r['msgid'] . '||' . $r['domain'];
			if ( ! isset( $deduped[ $key ] ) ) {
				$deduped[ $key ] = $r;
			}
		}

		foreach ( $deduped as $r ) {
			foreach ( $targets as $lang ) {
				$inserted = OW_DB::upsert(
					$lang,
					$r['domain'],
					$r['msgid'],
					'',  // empty — awaiting translation
					$r['context'] ?? null,
					$r['source_type'] ?? 'static',
					$r['source_name'] ?? null,
					$r['source_file'] ?? null
				);
				if ( $inserted ) $count++;
			}
		}

		return $count;
	}
}
