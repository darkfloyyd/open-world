<?php
/**
 * Plugin Name:       Open World
 * Plugin URI:        https://github.com/darkfloyyd/open-world
 * Description:       Complete multilingual solution — dynamic strings, WooCommerce integration, URL-based language switcher for free.
 * Version:           1.0.1
 * Tested up to:      6.9
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Author:            Jakub Misiak
 * Author URI:        https://buymeacoffee.com/jakubmisiak
 * Text Domain:       open-world
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'OW_VERSION', '1.0.1' );
define( 'OW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OW_PLUGIN_FILE', __FILE__ );

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class_name ): void {
	if ( strpos( $class_name, 'OW_' ) !== 0 ) return;
	$file = OW_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
	if ( file_exists( $file ) ) require_once $file;
} );

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__, function (): void {
	OW_Languages::install(); // Creates wp_ow_languages + seeds WP default lang + EN
	OW_DB::install();        // Creates wp_ow_translations
	OW_Router::flush_rules();
} );

register_deactivation_hook( __FILE__, function (): void {
	flush_rewrite_rules();
} );

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {
	// Auto-create/upgrade tables without requiring deactivation + reactivation
	OW_Languages::ensure_table_exists();

	$engine = new OW_Engine();
	add_action( 'init', [ $engine, 'register_filters' ], 1 );

	$router = new OW_Router();
	$router->register_link_filters();
	add_action( 'init',              [ $router, 'setup_rewrite_rules' ], 5 );
	add_filter( 'request',           [ $router, 'filter_request' ] );
	add_action( 'template_redirect', [ $router, 'redirect_if_needed' ], 1 );
	add_action( 'wp_head',           [ $router, 'add_hreflang_tags' ] );


	OW_Switcher::register();
	OW_SEO::init();

	// Dynamic scan capture: when X-OW-Scan header present, record all gettext calls
	if ( ! empty( $_SERVER['HTTP_X_OW_SCAN'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$captured = [];
		$host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$page_url = ( is_ssl() ? 'https' : 'http' ) . '://' . $host . $uri;

		add_filter( 'gettext', function ( $t, $text, $domain ) use ( &$captured, $uri ) {
			$captured[] = [
				'msgid'       => $text,
				'domain'      => $domain,
				'context'     => null,
				'source_type' => 'dynamic',
				'source_name' => 'page:' . $uri,
				'source_file' => null,
			];
			return $t;
		}, 999, 3 );

		add_action( 'shutdown', function () use ( &$captured, $page_url ) {
			set_transient( 'ow_scan_' . md5( $page_url ), array_unique( $captured, SORT_REGULAR ), 60 );
		} );
	}

	if ( is_admin() ) {
		$admin = new OW_Admin();
		add_action( 'admin_menu',            [ $admin, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_assets' ] );
		add_action( 'admin_post_ow_scan_strings',   [ $admin, 'handle_scan_strings' ] );
		add_action( 'admin_post_ow_clean_unused',   [ $admin, 'handle_clean_unused' ] );
		add_action( 'admin_post_ow_import_po',      [ $admin, 'handle_import_po' ] );
		add_action( 'admin_post_ow_lang_action',    [ $admin, 'handle_lang_action' ] );
		add_action( 'admin_post_ow_export_po',        [ $admin, 'handle_export_po' ] );
		add_action( 'wp_ajax_ow_save_translation',     [ $admin, 'ajax_save_translation' ] );
		add_action( 'wp_ajax_ow_set_lang_status',      [ $admin, 'ajax_set_lang_status' ] );
		add_action( 'wp_ajax_ow_deepl_save_settings',  [ $admin, 'ajax_deepl_save_settings' ] );
		add_action( 'wp_ajax_ow_deepl_translate',      [ $admin, 'ajax_deepl_translate' ] );
		add_action( 'wp_ajax_ow_deepl_preview',        [ $admin, 'ajax_deepl_preview' ] );
		add_action( 'wp_ajax_ow_google_free_toggle',   [ $admin, 'ajax_google_free_toggle' ] );
		add_action( 'wp_ajax_ow_google_free_translate', [ $admin, 'ajax_google_free_translate' ] );
		add_action( 'wp_ajax_ow_delete_all_translations', [ $admin, 'ajax_delete_all_translations' ] );

	}

	// ── Frontend Inline Translation Editor (admin only) ───────────────────
	$inline = new OW_Inline();
	add_action( 'template_redirect', [ $inline, 'init' ], 5 );

	// AJAX handlers for inline editor (must be registered regardless of is_admin)
	add_action( 'wp_ajax_ow_inline_get_translations', [ $inline, 'ajax_get_translations' ] );
	add_action( 'wp_ajax_ow_inline_save_translation', [ $inline, 'ajax_save_translation' ] );
	add_action( 'wp_ajax_ow_inline_deepl_single',     [ $inline, 'ajax_deepl_single' ] );

	// Add Settings link on Plugins page
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
		$settings_link = '<a href="' . admin_url( 'admin.php?page=ow-settings' ) . '">' . __( 'Settings', 'open-world' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	} );

	if ( class_exists( 'WooCommerce' ) ) {
		$wc = new OW_WooCommerce();
		$wc->register_hooks();
	}
} );


