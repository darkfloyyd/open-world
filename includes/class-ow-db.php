<?php
/**
 * Open World Translate — Database Layer
 *
 * Table: wp_ow_translations
 * Supports:
 *  - basic gettext strings (msgid → msgstr)
 *  - plural forms (msgid_plural + msgstr_plural as JSON array)
 *  - source tracking (source_type / source_name / source_file)
 *  - bulk mem+transient caching (O(1) per-string lookup)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_DB {

	const TABLE = 'ow_translations';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

	/** In-memory bulk cache: [ "{lang}_{domain}" => [ msgid => row ] ] */
	private static array $mem = [];

	// ── Install ───────────────────────────────────────────────────────────────

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id           bigint(20)   NOT NULL AUTO_INCREMENT,
			lang         varchar(10)  NOT NULL,
			domain       varchar(100) NOT NULL DEFAULT 'default',
			msgid        text         NOT NULL,
			msgid_plural text         DEFAULT NULL,
			msgstr       text         NOT NULL,
			msgstr_plural longtext    DEFAULT NULL,
			context      varchar(255) DEFAULT NULL,
			source_type  varchar(20)  DEFAULT 'static',
			source_name  varchar(255) DEFAULT NULL,
			source_file  varchar(500) DEFAULT NULL,
			placeholder_warning text DEFAULT NULL,
			html_warning        text DEFAULT NULL,
			warning_ignored     tinyint(1) DEFAULT 0,
			updated_at   datetime     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY   uniq_translation (lang, domain(50), msgid(200)),
			KEY          idx_lang_domain  (lang, domain),
			KEY          idx_source       (source_type, source_name(100))
		) ENGINE=InnoDB {$charset};";

		dbDelta( $sql );
	}

	// ── Cache helpers ─────────────────────────────────────────────────────────

	private static function cache_key( string $lang, string $domain ): string {
		return "ow_{$lang}_{$domain}";
	}

	private static function warning_column( string $type ): string {
		if ( $type === 'placeholder' ) return 'placeholder_warning';
		if ( $type === 'html' ) return 'html_warning';
		return '';
	}

	private static function clear_cache( string $lang, string $domain ): void {
		delete_transient( self::cache_key( $lang, $domain ) );
		unset( self::$mem[ "{$lang}_{$domain}" ] );
	}

	private static function load_bulk( string $lang, string $domain ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT msgid, msgstr, msgstr_plural, context
				 FROM {$table}
				 WHERE lang = %s AND domain = %s AND (msgstr != '' OR msgstr_plural IS NOT NULL)",
				$lang, $domain
			),
			ARRAY_A
		);

		$map = [];
		foreach ( $rows as $row ) {
			$map[ $row['msgid'] ] = [
				'msgstr'        => $row['msgstr'],
				'msgstr_plural' => $row['msgstr_plural'] ? json_decode( $row['msgstr_plural'], true ) : null,
			];
		}
		return $map;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Get a single translation (singular).
	 * Returns null on miss (caller falls back to original WP translation).
	 */
	public static function get_translation( string $lang, string $msgid, string $domain, ?string $context = null ): ?string {
		$key = "{$lang}_{$domain}";

		if ( ! isset( self::$mem[ $key ] ) ) {
			$cached = get_transient( self::cache_key( $lang, $domain ) );
			if ( $cached === false ) {
				$cached = self::load_bulk( $lang, $domain );
				set_transient( self::cache_key( $lang, $domain ), $cached, HOUR_IN_SECONDS );
			}
			self::$mem[ $key ] = $cached;
		}

		$row = self::$mem[ $key ][ $msgid ] ?? null;
		if ( $row === null ) return null;

		$val = $row['msgstr'] ?? '';
		return ( $val !== '' ) ? $val : null;
	}

	/**
	 * Get the correct plural form for a given count.
	 * Falls back to get_translation() (singular) if no plural stored.
	 */
	public static function get_plural( string $lang, string $msgid, ?string $msgid_plural, int $count, string $domain ): ?string {
		$key = "{$lang}_{$domain}";

		if ( ! isset( self::$mem[ $key ] ) ) {
			$cached = get_transient( self::cache_key( $lang, $domain ) );
			if ( $cached === false ) {
				$cached = self::load_bulk( $lang, $domain );
				set_transient( self::cache_key( $lang, $domain ), $cached, HOUR_IN_SECONDS );
			}
			self::$mem[ $key ] = $cached;
		}

		$row = self::$mem[ $key ][ $msgid ] ?? null;
		if ( $row === null ) return null;

		$forms = $row['msgstr_plural'] ?? null;

		if ( is_array( $forms ) && count( $forms ) > 0 ) {
			$idx = OW_Languages::get_plural_index( $lang, $count );
			return $forms[ $idx ] ?? $forms[ count( $forms ) - 1 ];
		}

		// No plural forms stored — fall back to singular
		$val = $row['msgstr'] ?? '';
		return ( $val !== '' ) ? $val : null;
	}

	/**
	 * Insert or update a translation. Clears caches.
	 *
	 * @param string[] $msgstr_plural_forms Optional array of plural forms (indexed 0, 1, 2…)
	 */
	public static function upsert(
		string  $lang,
		string  $domain,
		string  $msgid,
		string  $msgstr,
		?string $context         = null,
		string  $source_type     = 'static',
		?string $source_name     = null,
		?string $source_file     = null,
		?string $msgid_plural    = null,
		?array  $msgstr_plural   = null
	): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$plural_json = $msgstr_plural ? wp_json_encode( $msgstr_plural ) : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (lang, domain, msgid, msgid_plural, msgstr, msgstr_plural, context, source_type, source_name, source_file)
				 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE
				   msgid_plural    = COALESCE(VALUES(msgid_plural), msgid_plural),
				   msgstr          = IF(VALUES(msgstr) != '', VALUES(msgstr), msgstr),
				   msgstr_plural   = COALESCE(VALUES(msgstr_plural), msgstr_plural),
				   source_type     = VALUES(source_type),
				   source_name     = COALESCE(source_name, VALUES(source_name)),
				   source_file     = COALESCE(source_file, VALUES(source_file))",
				$lang, $domain, $msgid, $msgid_plural, $msgstr, $plural_json,
				$context, $source_type, $source_name, $source_file
			)
		);

		delete_transient( self::cache_key( $lang, $domain ) );
		unset( self::$mem[ "{$lang}_{$domain}" ] );

		return $result !== false;
	}

	/**
	 * Update msgstr + optional plural forms for a single row (inline editor).
	 */
	public static function update_msgstr( int $id, string $msgstr, ?array $plural_forms = null ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT msgid, msgid_plural, lang, domain FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) return false;

		$placeholder_result = OW_Placeholder_Validator::validate(
			(string) $row['msgid'],
			$msgstr,
			$row['msgid_plural'] ?: null,
			$plural_forms
		);

		$html_result = OW_HTML_Validator::validate( (string) $row['msgid'], $msgstr );

		$data = [ 'msgstr' => $msgstr ];
		if ( $plural_forms !== null ) {
			$data['msgstr_plural'] = wp_json_encode( $plural_forms );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update( $table, $data, [ 'id' => $id ] ) !== false;

		if ( $placeholder_result !== true ) {
			self::set_warning( $id, 'placeholder', $placeholder_result );
		} else {
			self::clear_warning( $id, 'placeholder' );
		}

		if ( $html_result !== true ) {
			self::set_warning( $id, 'html', $html_result );
		} else {
			self::clear_warning( $id, 'html' );
		}

		self::clear_cache( $row['lang'], $row['domain'] );

		return $ok;
	}

	public static function get_row( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, lang, domain, msgid, msgid_plural, msgstr, msgstr_plural, context, source_type, source_name, source_file, placeholder_warning, html_warning, warning_ignored, updated_at
				 FROM {$table}
				 WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public static function set_warning( int $id, string $type, array $detail ): bool {
		global $wpdb;
		$column = self::warning_column( $type );
		if ( ! $column ) return false;
		$table = $wpdb->prefix . self::TABLE;
		$data  = [
			$column            => wp_json_encode( $detail ),
			'warning_ignored'  => 0,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->update( $table, $data, [ 'id' => $id ] ) !== false;
	}

	public static function clear_warning( int $id, string $type ): bool {
		global $wpdb;
		$column = self::warning_column( $type );
		if ( ! $column ) return false;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update( $table, [ $column => null ], [ 'id' => $id ] ) !== false;
		if ( ! $ok ) return false;

		$row = self::get_row( $id );
		if ( ! $row ) return true;

		if ( empty( $row['placeholder_warning'] ) && empty( $row['html_warning'] ) ) {
			self::ignore_warning( $id, false );
		}

		return true;
	}

	public static function ignore_warning( int $id, bool $ignored = true ): bool {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->update( $table, [ 'warning_ignored' => $ignored ? 1 : 0 ], [ 'id' => $id ] ) !== false;
	}

	public static function get_warnings( string $lang, string $type = '' ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wheres = [
			$wpdb->prepare( 'lang = %s', $lang ),
			'warning_ignored = 0',
		];

		$column = self::warning_column( $type );
		if ( $column ) {
			$wheres[] = "{$column} IS NOT NULL";
		} else {
			$wheres[] = '(placeholder_warning IS NOT NULL OR html_warning IS NOT NULL)';
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $wheres );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			"SELECT id, lang, domain, msgid, msgid_plural, msgstr, msgstr_plural, placeholder_warning, html_warning, warning_ignored, updated_at
			 FROM {$table}
			 {$where_sql}
			 ORDER BY updated_at DESC, id DESC",
			ARRAY_A
		);
	}

	public static function count_warnings( string $lang, string $type = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$wheres = [
			$wpdb->prepare( 'lang = %s', $lang ),
			'warning_ignored = 0',
		];

		$column = self::warning_column( $type );
		if ( $column ) {
			$wheres[] = "{$column} IS NOT NULL";
		} else {
			$wheres[] = '(placeholder_warning IS NOT NULL OR html_warning IS NOT NULL)';
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $wheres );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
	}

	/**
	 * Fetch all rows for a lang+domain (editor + PO export).
	 */
	public static function get_all( string $lang, string $domain ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, msgid, msgid_plural, msgstr, msgstr_plural, context, source_type, source_name, source_file, placeholder_warning, html_warning, warning_ignored, updated_at
				 FROM {$table}
				 WHERE lang = %s AND domain = %s
				 ORDER BY source_type, source_name, id",
				$lang, $domain
			),
			ARRAY_A
		);
	}

	/**
	 * Paginated rows for the translation editor.
	 */
	public static function get_page(
		string $lang,
		int    $per_page    = 50,
		int    $offset      = 0,
		string $domain      = '',
		string $status      = '',
		string $search      = '',
		string $source      = '',
		string $source_type = ''
	): array {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$wheres = [ $wpdb->prepare( 'lang = %s', $lang ) ];

		if ( $domain ) $wheres[] = $wpdb->prepare( 'domain = %s', $domain );
		if ( $status === 'translated' )   $wheres[] = "msgstr != ''";
		if ( $status === 'untranslated' ) $wheres[] = "msgstr = ''";
		if ( $status === 'warning' ) $wheres[] = '((placeholder_warning IS NOT NULL OR html_warning IS NOT NULL) AND warning_ignored = 0)';
		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$wheres[] = $wpdb->prepare( '(msgid LIKE %s OR msgstr LIKE %s)', $like, $like );
		}
		if ( $source ) $wheres[] = $wpdb->prepare( 'source_name = %s', $source );
		if ( $source_type ) $wheres[] = $wpdb->prepare( 'source_type = %s', $source_type );

		$where_sql = 'WHERE ' . implode( ' AND ', $wheres );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, lang, domain, msgid, msgid_plural, msgstr, msgstr_plural, context, source_type, source_name, source_file, placeholder_warning, html_warning, warning_ignored, updated_at
				 FROM {$table}
				 {$where_sql}
				 ORDER BY source_type, source_name, id
				 LIMIT %d OFFSET %d",
				$per_page, $offset
			),
			ARRAY_A
		);
	}

	public static function count( string $lang, string $domain = '', string $status = '', string $search = '', string $source = '', string $source_type = '' ): int {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE;
		$wheres = [ $wpdb->prepare( 'lang = %s', $lang ) ];

		if ( $domain ) $wheres[] = $wpdb->prepare( 'domain = %s', $domain );
		if ( $status === 'translated' )   $wheres[] = "msgstr != ''";
		if ( $status === 'untranslated' ) $wheres[] = "msgstr = ''";
		if ( $status === 'warning' ) $wheres[] = '((placeholder_warning IS NOT NULL OR html_warning IS NOT NULL) AND warning_ignored = 0)';
		if ( $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$wheres[] = $wpdb->prepare( '(msgid LIKE %s OR msgstr LIKE %s)', $like, $like );
		}
		if ( $source ) $wheres[] = $wpdb->prepare( 'source_name = %s', $source );
		if ( $source_type ) $wheres[] = $wpdb->prepare( 'source_type = %s', $source_type );

		$where_sql = 'WHERE ' . implode( ' AND ', $wheres );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
	}

	public static function get_sources( string $lang ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT source_name FROM {$table} WHERE lang = %s AND source_name IS NOT NULL ORDER BY source_name",
			$lang
		) );
	}

	public static function get_source_types( string $lang ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT source_type FROM {$table} WHERE lang = %s AND source_type IS NOT NULL ORDER BY source_type",
			$lang
		) );
	}

	public static function get_domains( string $lang ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT domain FROM {$table} WHERE lang = %s ORDER BY domain",
			$lang
		) );
	}

	public static function get_stats(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results(
			"SELECT lang, COUNT(*) AS total, SUM(msgstr != '') AS translated FROM {$table} GROUP BY lang",
			ARRAY_A
		);

		$stats = [];
		foreach ( $rows as $row ) {
			$stats[ $row['lang'] ] = [
				'total'      => (int) $row['total'],
				'translated' => (int) $row['translated'],
				'percent'    => $row['total'] > 0 ? round( $row['translated'] / $row['total'] * 100 ) : 0,
			];
		}
		return $stats;
	}
}
// phpcs:enable
