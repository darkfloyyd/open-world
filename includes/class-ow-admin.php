<?php
/**
 * Open World — Admin UI
 *
 * - Dashboard: per-language progress bars
 * - Translations: paginated inline editor with filters (domain/source/status/search)
 * - Settings: scanner, PO import/export, language management (add/remove/set default)
 *
 * Emoji policy: flags only — no other emoji in UI text.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OW_Admin {

	// ── Menu Registration ─────────────────────────────────────────────────────

	public function register_menu(): void {
		add_menu_page(
			__( 'Open World', 'open-world' ),
			'Open World',
			'manage_options',
			'open-world',
			[ $this, 'render_dashboard' ],
			'dashicons-translation',
			82
		);

		add_submenu_page( 'open-world', __( 'Translations', 'open-world' ),   __( 'Translations', 'open-world' ),   'manage_options', 'ow-translations',    [ $this, 'render_translations' ] );
		add_submenu_page( 'open-world', __( 'Languages', 'open-world' ),      __( 'Languages', 'open-world' ),      'manage_options', 'ow-languages',       [ $this, 'render_languages' ] );
		add_submenu_page( 'open-world', __( 'Auto-Translate', 'open-world' ), __( 'Auto-Translate', 'open-world' ), 'manage_options', 'ow-auto-translate', [ $this, 'render_auto_translate' ] );
		add_submenu_page( 'open-world', __( 'Settings', 'open-world' ),       __( 'Settings', 'open-world' ),       'manage_options', 'ow-settings',        [ $this, 'render_settings' ] );
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'open-world' ) === false && strpos( $hook, 'ow-' ) === false ) {
			return;
		}
		wp_enqueue_style( 'ow-admin', OW_PLUGIN_URL . 'assets/css/admin.css', [], OW_VERSION );
		wp_enqueue_script( 'ow-editor', OW_PLUGIN_URL . 'assets/js/editor.js', [], OW_VERSION, true );
		wp_localize_script( 'ow-editor', 'owEditor', [
			'nonce'   => wp_create_nonce( 'ow_save_translation' ),
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
		] );
	}

	// ── Dashboard ─────────────────────────────────────────────────────────────

	public function render_dashboard(): void {
		$stats   = OW_DB::get_stats();
		$names   = OW_Languages::get_names();
		$flags   = OW_Languages::get_flags();
		$default = OW_Languages::get_default();
		$targets = OW_Languages::get_target_languages();
		?>
		<div class="wrap ow-wrap">
			<div class="ow-page-header">
				<img src="<?php echo  esc_url( OW_PLUGIN_URL . 'assets/images/OpenWorldTransparentCompressed.png' ) ?>" alt="Open World" class="ow-logo">
				<div class="ow-page-header-text">
					<h1><?php echo  esc_html__( 'Open World', 'open-world' ) ?></h1>
					<p class="ow-tagline"><?php echo  esc_html__( 'Multilingual solution for WordPress + WooCommerce.', 'open-world' ) ?></p>
				</div>
			</div>

			<div class="ow-stats-grid">
				<?php foreach ( $targets as $lang ): ?>
				<?php $s = $stats[ $lang ] ?? [ 'total' => 0, 'translated' => 0, 'percent' => 0 ]; ?>
				<div class="ow-stat-card">
					<div class="ow-stat-card__stripe"></div>
					<div class="ow-stat-card__body">
					<div class="ow-stat-flag"><?php echo  esc_html( $flags[ $lang ] ?? '' ) ?></div>
					<div class="ow-stat-lang"><?php echo  esc_html( $names[ $lang ] ?? $lang ) ?></div>
					<div class="ow-stat-number"><?php echo  esc_html( $s['percent'] ) ?>%</div>
					<div class="ow-progress">
						<div class="ow-progress-bar" style="width:<?php echo  esc_attr( $s['percent'] ) ?>%"></div>
					</div>
					<div class="ow-stat-sub"><?php echo  esc_html( $s['translated'] ) ?> / <?php echo  esc_html( $s['total'] ) ?> strings</div>
					<a class="button ow-stat-link" href="<?php echo  esc_url( admin_url( 'admin.php?page=ow-translations&lang=' . $lang ) ) ?>"><?php echo  esc_html__( 'Translate', 'open-world' ) ?></a>
					</div>
				</div>
				<?php endforeach; ?>
				<?php if ( empty( $targets ) ): ?>
				<div class="ow-empty">
					<p><?php echo  esc_html__( 'No target languages configured. Go to Languages to add one.', 'open-world' ) ?></p>
					<a href="<?php echo  esc_url( admin_url( 'admin.php?page=ow-languages' ) ) ?>" class="button button-primary"><?php echo  esc_html__( 'Manage Languages', 'open-world' ) ?></a>
				</div>
				<?php endif; ?>
			</div>

			<div class="ow-actions">
				<h2><?php echo esc_html__( 'Quick Actions', 'open-world' ) ?></h2>
				<div class="ow-actions-row">
				<form method="post" action="<?php echo  esc_url( admin_url( 'admin-post.php' ) ) ?>" style="display:inline">
					<?php wp_nonce_field( 'ow_scan_strings', 'ow_scan_nonce' ); ?>
					<input type="hidden" name="action" value="ow_scan_strings">
					<input type="hidden" name="scan_mode" value="smart">
					<button type="submit" class="button button-primary"><?php echo  esc_html__( 'Smart Scan', 'open-world' ) ?></button>
				</form>
				<a href="<?php echo  esc_url( admin_url( 'admin.php?page=ow-languages' ) ) ?>" class="button"><?php echo  esc_html__( 'Manage Languages', 'open-world' ) ?></a>
				<a href="<?php echo  esc_url( admin_url( 'admin.php?page=ow-auto-translate' ) ) ?>" class="button"><?php echo  esc_html__( 'Auto-Translate', 'open-world' ) ?></a>
				<a href="<?php echo  esc_url( admin_url( 'admin.php?page=ow-settings' ) ) ?>" class="button"><?php echo  esc_html__( 'Settings', 'open-world' ) ?></a>
				</div>
			</div>

			<div class="ow-settings-card" style="margin-top:20px">
				<h2 style="cursor:pointer; display:flex; justify-content:space-between; align-items:center;" onclick="owToggleQuickStart()">
					<span><?php echo esc_html__( 'Quick Start', 'open-world' ) ?></span>
					<span id="ow-qs-arrow" style="font-size:12px; color:#888;">▼</span>
				</h2>
				
				<div id="ow-quick-start-content">
					<p class="description" style="margin-bottom:16px; margin-top:8px;"><?php echo esc_html__( 'New here? Follow these steps to set up multilingual translations in minutes.', 'open-world' ) ?></p>

					<ol style="margin:0;padding-left:0;list-style:none;display:flex;flex-direction:column;gap:10px">

						<li style="display:flex;align-items:flex-start;gap:14px;padding:12px 16px;border:1px solid #e0e0e0;border-radius:6px;background:#f9f9f9">
							<span style="min-width:28px;height:28px;border-radius:50%;background:#2271b1;color:#fff;font-weight:700;font-size:.9rem;display:flex;align-items:center;justify-content:center">1</span>
							<div>
								<strong><?php echo esc_html__( 'Add your languages', 'open-world' ) ?></strong>
								<p style="margin:4px 0 0;color:#555;font-size:.85rem"><?php echo esc_html__( 'Go to Languages, add a source language (e.g. English) and one or more target languages (e.g. Polish, German). Each target language gets its own URL prefix.', 'open-world' ) ?></p>
								<p style="margin:6px 0 0;color:#d63638;font-size:.85rem"><strong><?php echo esc_html__( 'Important:', 'open-world' ) ?></strong> <?php 
								/* translators: %s: URL to the Permalinks settings page */
								echo wp_kses_post( sprintf( __( 'After adding a new language, go to <a href="%s">Settings → Permalinks</a> and click "Save Changes" <strong>twice</strong> to refresh your URLs.', 'open-world' ), admin_url('options-permalink.php') ) ) 
								?></p>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=ow-languages' ) ) ?>" class="button button-small" style="margin-top:8px;font-size:.82rem"><?php echo esc_html__( 'Manage Languages →', 'open-world' ) ?></a>
							</div>
						</li>

						<li style="display:flex;align-items:flex-start;gap:14px;padding:12px 16px;border:1px solid #e0e0e0;border-radius:6px;background:#f9f9f9">
							<span style="min-width:28px;height:28px;border-radius:50%;background:#2271b1;color:#fff;font-weight:700;font-size:.9rem;display:flex;align-items:center;justify-content:center">2</span>
							<div>
								<strong><?php echo esc_html__( 'Run Smart Scan', 'open-world' ) ?></strong>
								<p style="margin:4px 0 0;color:#555;font-size:.85rem"><?php echo esc_html__( 'Smart Scan crawls your published pages and WooCommerce endpoints to collect the strings your visitors actually see — without importing thousands of unused strings.', 'open-world' ) ?></p>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ) ?>" style="display:inline;margin-top:8px">
									<?php wp_nonce_field( 'ow_scan_strings', 'ow_scan_nonce_qs' ); ?>
									<input type="hidden" name="action" value="ow_scan_strings">
									<input type="hidden" name="scan_mode" value="smart">
									<button type="submit" class="button button-primary button-small" style="font-size:.82rem"><?php echo esc_html__( 'Run Smart Scan →', 'open-world' ) ?></button>
								</form>
							</div>
						</li>

						<li style="display:flex;align-items:flex-start;gap:14px;padding:12px 16px;border:1px solid #e0e0e0;border-radius:6px;background:#f9f9f9">
							<span style="min-width:28px;height:28px;border-radius:50%;background:#2271b1;color:#fff;font-weight:700;font-size:.9rem;display:flex;align-items:center;justify-content:center">3</span>
							<div>
								<strong><?php echo esc_html__( 'Auto-translate everything', 'open-world' ) ?></strong>
								<p style="margin:4px 0 0;color:#555;font-size:.85rem"><?php echo esc_html__( 'Use Google Translate (free, no key needed) or DeepL to bulk-translate all scanned strings in one click. Switch providers anytime on the Auto-Translate page.', 'open-world' ) ?></p>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=ow-auto-translate' ) ) ?>" class="button button-primary button-small" style="margin-top:8px;font-size:.82rem"><?php echo esc_html__( 'Open Auto-Translate →', 'open-world' ) ?></a>
							</div>
						</li>

						<li style="display:flex;align-items:flex-start;gap:14px;padding:12px 16px;border:1px solid #e0e0e0;border-radius:6px;background:#f9f9f9">
							<span style="min-width:28px;height:28px;border-radius:50%;background:#2271b1;color:#fff;font-weight:700;font-size:.9rem;display:flex;align-items:center;justify-content:center">4</span>
							<div>
								<strong><?php echo esc_html__( 'Review &amp; refine', 'open-world' ) ?></strong>
								<p style="margin:4px 0 0;color:#555;font-size:.85rem"><?php echo esc_html__( 'Open the Translations editor to review, edit, or approve any string. You can also click any text on the frontend while logged in to edit it inline.', 'open-world' ) ?></p>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=ow-translations' ) ) ?>" class="button button-small" style="margin-top:8px;font-size:.82rem"><?php echo esc_html__( 'Open Translations →', 'open-world' ) ?></a>
							</div>
						</li>

						<li style="display:flex;align-items:flex-start;gap:14px;padding:12px 16px;border:1px solid #e0e0e0;border-radius:6px;background:#f9f9f9">
							<span style="min-width:28px;height:28px;border-radius:50%;background:#46b450;color:#fff;font-weight:700;font-size:.9rem;display:flex;align-items:center;justify-content:center">✓</span>
							<div>
								<strong><?php echo esc_html__( 'You\'re live!', 'open-world' ) ?></strong>
								<p style="margin:4px 0 0;color:#555;font-size:.85rem"><?php echo esc_html__( 'Add the language switcher wherever you need it: use the Open World widget in Appearance → Widgets, place the shortcode [open_world_switcher] in any page or template, or add the widget block in the Site Editor. URL-based routing (/en/, /pl/, /de/ etc.) is active automatically for all target languages.', 'open-world' ) ?></p>
							</div>
						</li>

					</ol>

					<p style="margin-top:16px;font-size:.82rem;color:#888"><?php echo esc_html__( 'Tip: Re-run Smart Scan whenever you publish new content to capture new strings.', 'open-world' ) ?></p>
				</div>
			</div>

			<script>
			function owToggleQuickStart() {
				var content = document.getElementById('ow-quick-start-content');
				var arrow = document.getElementById('ow-qs-arrow');
				if (content.style.display === 'none') {
					content.style.display = 'block';
					arrow.innerText = '▼';
					localStorage.setItem('ow_qs_closed', '0');
				} else {
					content.style.display = 'none';
					arrow.innerText = '▶';
					localStorage.setItem('ow_qs_closed', '1');
				}
			}
			document.addEventListener('DOMContentLoaded', function() {
				if (localStorage.getItem('ow_qs_closed') === '1') {
					document.getElementById('ow-quick-start-content').style.display = 'none';
					document.getElementById('ow-qs-arrow').innerText = '▶';
				}
			});
			</script>

			<div class="ow-settings-card" style="margin-top:20px; border-left:4px solid #FFDD00; background:#fffcf0;">
				<h2 style="margin-top:0">☕ <?php echo esc_html__( 'Support Open World', 'open-world' ) ?></h2>
				<p class="description" style="margin-bottom:16px; color:#444;">
					<?php echo wp_kses_post( __( 'I created Open World to give the WordPress community a truly free, native, and bloat-free multilingual solution. If this plugin saves you time or money, please consider buying me a coffee! It directly helps me maintain the project and develop new features.', 'open-world' ) ) ?>
				</p>
				<a href="https://buymeacoffee.com/jakubmisiak" target="_blank" rel="noopener noreferrer" class="button" style="background:#FFDD00; color:#000; border-color:#FFDD00; text-shadow:none; font-weight:600; padding:0 16px;">
					<?php echo esc_html__( 'Buy me a coffee', 'open-world' ) ?>
				</a>
			</div>
		</div>
		<?php
	}


	// ── Translations Editor ───────────────────────────────────────────────────

	public function render_translations(): void {
		$targets = OW_Languages::get_target_languages();

		if ( empty( $targets ) ) {
			echo '<div class="wrap ow-wrap"><p>' . esc_html__( 'No target languages. Add languages first.', 'open-world' ) . '</p></div>';
			return;
		}

		$lang = sanitize_key( wp_unslash( $_GET['lang'] ?? reset( $targets ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! OW_Languages::is_valid( $lang ) || $lang === OW_Languages::get_default() ) {
			$lang = reset( $targets );
		}

		$per_page    = min( max( 50, (int) sanitize_text_field( wp_unslash( $_GET['per_page'] ?? 50 ) ) ), 200 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page        = max( 1, (int) sanitize_text_field( wp_unslash( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset      = ( $page - 1 ) * $per_page;
		$domain      = sanitize_text_field( wp_unslash( $_GET['domain'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status      = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search      = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$source      = sanitize_text_field( wp_unslash( $_GET['source'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$source_type = sanitize_key( wp_unslash( $_GET['source_type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$rows         = OW_DB::get_page( $lang, $per_page, $offset, $domain, $status, $search, $source, $source_type );
		$total        = OW_DB::count( $lang, $domain, $status, $search, $source, $source_type );
		$pages        = max( 1, (int) ceil( $total / $per_page ) );
		$domains      = OW_DB::get_domains( $lang );
		$sources      = OW_DB::get_sources( $lang );
		$source_types = OW_DB::get_source_types( $lang );
		$flags   = OW_Languages::get_flags();
		$names   = OW_Languages::get_names();
		$stats   = OW_DB::get_stats();
		$base_url = admin_url( 'admin.php?page=ow-translations&lang=' . $lang );
		$filter_args = ( $domain ? '&domain=' . urlencode($domain) : '' )
		             . ( $status ? '&status=' . $status : '' )
		             . ( $search ? '&search=' . urlencode($search) : '' )
		             . ( $source ? '&source=' . urlencode($source) : '' )
		             . ( $source_type ? '&source_type=' . urlencode($source_type) : '' )
		             . ( $per_page !== 50 ? '&per_page=' . $per_page : '' );
		?>
		<div class="wrap ow-wrap">
			<div class="ow-page-header">
				<img src="<?php echo  esc_url( OW_PLUGIN_URL . 'assets/images/OpenWorldTransparentCompressed.png' ) ?>" alt="Open World" class="ow-logo">
				<div class="ow-page-header-text">
					<h1><?php echo  esc_html__( 'Translations', 'open-world' ) ?></h1>
					<span class="ow-lang-badge"><?php echo  esc_html( ( $flags[ $lang ] ?? '' ) . ' ' . ( $names[ $lang ] ?? $lang ) ) ?></span>
				</div>
			</div>

			<nav class="ow-lang-tabs">
				<?php foreach ( $targets as $l ): ?>
				<?php $s = $stats[ $l ] ?? [ 'percent' => 0 ]; ?>
				<a href="<?php echo  esc_url( admin_url( 'admin.php?page=ow-translations&lang=' . $l ) ) ?>"
				   class="ow-lang-tab <?php echo  $l === $lang ? 'is-active' : '' ?>">
					<?php echo  esc_html( $flags[ $l ] ?? '' ) ?> <?php echo  esc_html( $names[ $l ] ?? $l ) ?>
					<span class="ow-tab-pct"><?php echo  esc_html( $s['percent'] ) ?>%</span>
				</a>
				<?php endforeach; ?>
			</nav>

			<div class="ow-filters-bar">
			<form method="get" class="ow-filters" id="ow-filter-form">
				<input type="hidden" name="page" value="ow-translations">
				<input type="hidden" name="lang" value="<?php echo  esc_attr( $lang ) ?>">
				<select name="domain">
					<option value=""><?php echo  esc_html__( 'All domains', 'open-world' ) ?></option>
					<?php foreach ( $domains as $d ): ?>
					<option value="<?php echo  esc_attr( $d ) ?>" <?php echo  selected( $domain, $d, false ) ?>><?php echo  esc_html( $d ) ?></option>
					<?php endforeach; ?>
				</select>
				<select name="source">
					<option value=""><?php echo  esc_html__( 'All sources', 'open-world' ) ?></option>
					<?php foreach ( $sources as $src ): ?>
					<option value="<?php echo  esc_attr( $src ) ?>" <?php echo  selected( $source, $src, false ) ?>><?php echo  esc_html( $src ) ?></option>
					<?php endforeach; ?>
				</select>
				<select name="status">
					<option value=""><?php echo  esc_html__( 'All', 'open-world' ) ?></option>
					<option value="untranslated" <?php echo  selected( $status, 'untranslated', false ) ?>><?php echo  esc_html__( 'Untranslated', 'open-world' ) ?></option>
					<option value="translated"   <?php echo  selected( $status, 'translated',   false ) ?>><?php echo  esc_html__( 'Translated', 'open-world' ) ?></option>
				</select>
				<select name="source_type" style="padding-right: 25px;" title="<?php echo  esc_attr__( 'Filter by source type: theme = found in theme PHP files, plugin = from plugin PHP/POT, dynamic = captured at runtime during page crawl, static = manually imported or seeded', 'open-world' ) ?>">
					<option value=""><?php echo  esc_html__( 'All types', 'open-world' ) ?></option>
					<?php foreach ( $source_types as $st ): ?>
					<option value="<?php echo  esc_attr( $st ) ?>" <?php echo  selected( $source_type, $st, false ) ?>><?php echo  esc_html( $st ) ?></option>
					<?php endforeach; ?>
				</select>
				<select name="per_page" style="padding-right: 17px;">
					<?php foreach ( [ 50, 100, 200 ] as $pp ): ?>
					<option value="<?php echo  esc_attr( $pp ) ?>" <?php echo  selected( $per_page, $pp, false ) ?>><?php echo  esc_html( $pp ) ?> / <?php echo  esc_html__( 'page', 'open-world' ) ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="search" id="ow-search-input"
				       placeholder="<?php echo  esc_attr__( 'Search strings...', 'open-world' ) ?>"
				       value="<?php echo  esc_attr( $search ) ?>">
				<button type="submit" class="button"><?php echo  esc_html__( 'Filter', 'open-world' ) ?></button>
				<?php if ( $domain || $status || $search || $source || $source_type ): ?>
				<a href="<?php echo  esc_url( $base_url . ( $per_page !== 50 ? '&per_page=' . $per_page : '' ) ) ?>" class="button"><?php echo  esc_html__( 'Clear', 'open-world' ) ?></a>
				<?php endif; ?>
			</form>
			</div>

			<?php if ( empty( $rows ) ): ?>
			<div class="ow-empty">
				<p><?php echo  esc_html__( 'No strings found. Run the scanner first.', 'open-world' ) ?></p>
				<a href="<?php echo  esc_url( admin_url( 'admin.php?page=ow-settings' ) ) ?>" class="button button-primary"><?php echo  esc_html__( 'Go to Settings', 'open-world' ) ?></a>
			</div>
			<?php else: ?>

			<?php // ── TOP navigation bar ──────────────────────────────────── ?>
			<div class="ow-table-nav">
				<span class="ow-total-info"><?php
				echo esc_html( sprintf(
					/* translators: 1: start offset, 2: end offset, 3: total strings, 4: current page, 5: total pages */
					__( 'Showing %1$d–%2$d of %3$s strings (page %4$d of %5$s)', 'open-world' ),
					$offset + 1,
					min( $offset + $per_page, $total ),
					number_format_i18n( $total ),
					$page,
					number_format_i18n( $pages )
				) ); ?></span>
				<?php $this->render_pagination( $page, $pages, $base_url, $filter_args ); ?>
			</div>

			<table class="wp-list-table widefat fixed striped ow-editor-table" id="ow-editor-table">
				<thead>
					<tr>
						<th class="ow-col-source"><?php echo  esc_html__( 'Source', 'open-world' ) ?></th>
						<th class="ow-col-original"><?php echo  esc_html__( 'Original', 'open-world' ) ?></th>
						<th class="ow-col-translation"><?php echo  esc_html( $names[ $lang ] ?? $lang ) ?></th>
						<th class="ow-col-status"></th>
					</tr>
				</thead>
				<tbody id="ow-editor-tbody">
					<?php foreach ( $rows as $row ):
						$has_plural   = ! empty( $row['msgid_plural'] );
						$plural_forms = $row['msgstr_plural'] ? json_decode( $row['msgstr_plural'], true ) : [];
					?>
					<tr data-id="<?php echo  esc_attr( $row['id'] ) ?>" class="<?php echo  empty( $row['msgstr'] ) ? 'ow-row-untranslated' : 'ow-row-translated' ?>">
						<td class="ow-col-source">
							<span class="ow-source-badge ow-source-<?php echo  esc_attr( $row['source_type'] ?? 'static' ) ?>">
								<?php echo  esc_html( $row['source_type'] ?? '' ) ?>
							</span>
							<?php if ( $row['source_name'] ): ?>
							<span class="ow-source-name" title="<?php echo  esc_attr( $row['source_file'] ?? '' ) ?>"><?php echo  esc_html( $row['source_name'] ) ?></span>
							<?php endif; ?>
						</td>
						<td class="ow-col-original">
							<span class="ow-msgid"><?php echo  esc_html( $row['msgid'] ) ?></span>
							<?php if ( $has_plural ): ?>
							<span class="ow-msgid-plural"><?php echo  esc_html__( 'Plural:', 'open-world' ) ?> <?php echo  esc_html( $row['msgid_plural'] ) ?></span>
							<?php endif; ?>
						</td>
						<td class="ow-col-translation">
							<?php if ( $has_plural ): ?>
								<?php $num_forms = $this->get_num_plural_forms( $lang ); ?>
								<div class="ow-plural-forms" data-id="<?php echo  esc_attr( $row['id'] ) ?>">
									<?php for ( $f = 0; $f < $num_forms; $f++ ): ?>
									<div class="ow-plural-row">
										<label class="ow-plural-label"><?php echo  esc_html( $this->get_plural_label( $lang, $f ) ) ?></label>
										<div class="ow-msgstr ow-msgstr-plural"
										     contenteditable="true"
										     data-id="<?php echo  esc_attr( $row['id'] ) ?>"
										     data-form="<?php echo  esc_attr( $f ) ?>"><?php echo  esc_html( $plural_forms[ $f ] ?? '' ) ?></div>
									</div>
									<?php endfor; ?>
								</div>
							<?php else: ?>
								<div class="ow-msgstr"
								     contenteditable="true"
								     data-id="<?php echo  esc_attr( $row['id'] ) ?>"><?php echo  esc_html( $row['msgstr'] ) ?></div>
							<?php endif; ?>
						</td>
						<td class="ow-col-status">
							<span class="ow-save-status" data-id="<?php echo  esc_attr( $row['id'] ) ?>"></span>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php // ── BOTTOM navigation bar ─────────────────────────────── ?>
			<div class="ow-table-nav">
				<span class="ow-total-info"><?php
				echo esc_html( sprintf(
					/* translators: 1: current page, 2: total pages */
					__( 'Page %1$d of %2$s', 'open-world' ),
					$page,
					number_format_i18n( $pages )
				) ); ?></span>
				<?php $this->render_pagination( $page, $pages, $base_url, $filter_args ); ?>
			</div>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render smart pagination: « 1 … 5 6 [7] 8 9 … 281 »
	 */
	private function render_pagination( int $current, int $total_pages, string $base_url, string $filter_args ): void {
		if ( $total_pages <= 1 ) return;

		$link = function ( int $p ) use ( $base_url, $filter_args ): string {
			return esc_url( $base_url . '&paged=' . $p . $filter_args );
		};

		echo '<nav class="ow-pagination">';

		// « Prev
		if ( $current > 1 )
			echo wp_kses_post( sprintf( '<a class="ow-page-link" href="%s" title="%s">&laquo;</a>', $link( $current - 1 ), esc_attr__('Previous', 'open-world') ) );

		// Page 1
		if ( $current > 3 )
			echo wp_kses_post( sprintf( '<a class="ow-page-link" href="%s">1</a>', $link( 1 ) ) );
		if ( $current > 4 )
			echo '<span class="ow-page-ellipsis">&hellip;</span>';

		// Window: current ± 2
		$start = max( 1, $current - 2 );
		$end   = min( $total_pages, $current + 2 );
		for ( $i = $start; $i <= $end; $i++ ) {
			echo wp_kses_post( sprintf(
				'<a class="ow-page-link%s" href="%s">%d</a>',
				$i === $current ? ' is-current' : '',
				$link( $i ),
				$i
			) );
		}

		// Last page
		if ( $current < $total_pages - 3 )
			echo '<span class="ow-page-ellipsis">&hellip;</span>';
		if ( $current < $total_pages - 2 )
			echo wp_kses_post( sprintf( '<a class="ow-page-link" href="%s">%d</a>', $link( $total_pages ), $total_pages ) );

		// » Next
		if ( $current < $total_pages )
			echo wp_kses_post( sprintf( '<a class="ow-page-link" href="%s" title="%s">&raquo;</a>', $link( $current + 1 ), esc_attr__('Next', 'open-world') ) );

		echo '</nav>';
	}



	// ── Languages Management ──────────────────────────────────────────────────

	public function render_languages(): void {
		$all      = OW_Languages::get_all_including_inactive();
		$default  = OW_Languages::get_default();
		$fallback = OW_Languages::get_fallback();
		$source   = OW_Languages::get_source();
		$notice   = get_transient( 'ow_lang_notice' );
		if ( $notice ) delete_transient( 'ow_lang_notice' );
		$known    = OW_Languages::known_languages();
		uasort( $known, function( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );
		?>
		<div class="wrap ow-wrap">
			<div class="ow-page-header">
				<img src="<?php echo  esc_url( OW_PLUGIN_URL . 'assets/images/OpenWorldTransparentCompressed.png' ) ?>" alt="Open World" class="ow-logo">
				<div class="ow-page-header-text">
					<h1><?php echo  esc_html__( 'Language Management', 'open-world' ) ?></h1>
				</div>
			</div>

			<?php if ( $notice ): ?>
			<div class="notice notice-success is-dismissible"><p><?php echo  esc_html( $notice ) ?></p></div>
			<?php endif; ?>

			<div class="notice notice-warning inline" style="margin-top:20px; border-left-color: #f56e28;">
				<p><strong>ℹ️ <?php echo esc_html__( 'Pro Tip: URL Routing', 'open-world' ) ?></strong> — <?php 
				/* translators: %s: URL to the Permalinks settings page */
				echo wp_kses_post( sprintf( __( 'Whenever you add a new language, you must go to <a href="%s">Settings → Permalinks</a> and click "Save Changes" <strong>twice</strong> to make the new language URLs (e.g. /pl/) work correctly without 404 errors.', 'open-world' ), admin_url('options-permalink.php') ) ) 
				?></p>
			</div>

			<!-- Current Languages Table -->
			<h2 style="margin-bottom:6px"><?php echo  esc_html__( 'Languages', 'open-world' ) ?></h2>

			<p class="description" style="margin-bottom:14px">
				<?php echo  esc_html__( 'Manage all languages and their frontend visibility. Status controls who can access each language on the frontend.', 'open-world' ) ?>
			</p>

			<!-- Status legend -->
			<div class="ow-status-legend" style="display:flex;gap:18px;margin-bottom:16px;flex-wrap:wrap">
				<span class="ow-status-element ow-status-active" title="<?php echo  esc_attr__( 'Visible and accessible by all visitors on the frontend.', 'open-world' ) ?>">
					🟢 <?php echo  esc_html__( 'Active', 'open-world' ) ?>
					<span class="ow-status-hint">&mdash; <?php echo  esc_html__( 'public, in switcher, in hreflang', 'open-world' ) ?></span>
				</span>
				<span class="ow-status-element ow-status-pending" title="<?php echo  esc_attr__( 'Accessible only by administrators. Hidden from guests. Useful for translating before going live.', 'open-world' ) ?>">
					🟡 <?php echo  esc_html__( 'Pending', 'open-world' ) ?>
					<span class="ow-status-hint">&mdash; <?php echo  esc_html__( 'admins only, hidden from guests', 'open-world' ) ?></span>
				</span>
				<span class="ow-status-element ow-status-inactive" title="<?php echo  esc_attr__( 'Completely disabled. No URL endpoint. Not accessible by anyone, including admins.', 'open-world' ) ?>">
					⚫ <?php echo  esc_html__( 'Inactive', 'open-world' ) ?>
					<span class="ow-status-hint">&mdash; <?php echo  esc_html__( 'disabled, no frontend access', 'open-world' ) ?></span>
				</span>
			</div>

			<table class="wp-list-table widefat fixed striped" style="border-radius: var(--ow-radius-sm);">
				<thead>
					<tr>
						<th style="width:16px; border-top-left-radius: var(--ow-radius-sm);"><span class="screen-reader-text"><?php echo esc_html__( 'Flag', 'open-world' ) ?></span></th>
						<th style="width:144px"><?php echo  esc_html__( 'Language', 'open-world' ) ?></th>
						<th style="width:50px"><?php echo  esc_html__( 'Code', 'open-world' ) ?></th>
						<th style="width:78px"><?php echo  esc_html__( 'Locale', 'open-world' ) ?></th>
						<th style="width:250px"><?php echo  esc_html__( 'Role', 'open-world' ) ?></th>
						<th style="width:100px">
							<?php echo  esc_html__( 'Status', 'open-world' ) ?>
							<span class="ow-info" title="<?php echo  esc_attr__( 'Controls frontend visibility. Active = public. Pending = admins only. Inactive = disabled.', 'open-world' ) ?>">ⓘ</span>
						</th>
						<th style="width:300px; border-top-right-radius: var(--ow-radius-sm);"><?php echo  esc_html__( 'Actions', 'open-world' ) ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $all as $lang => $row ):
						$status     = $row['status'] ?? 'active';
						$is_default = $lang === $default;
						$is_source  = $lang === $source;
						$locked     = $is_default || $is_source;
					?>
					<tr id="ow-lang-row-<?php echo  esc_attr( $lang ) ?>">
						<td><?php echo  esc_html( $row['flag'] ?? '' ) ?></td>
						<td><strong><?php echo  esc_html( $row['name'] ) ?></strong></td>
						<td><code><?php echo  esc_html( $lang ) ?></code></td>
						<td><code><?php echo  esc_html( $row['locale'] ) ?></code></td>
						<td>
							<?php if ( $is_default ): ?>
								<span class="ow-role-badge ow-role-default"><?php echo  esc_html__( 'Default URL', 'open-world' ) ?></span>
							<?php endif; ?>
							<?php if ( $is_source ): ?>
								<span class="ow-role-badge ow-role-source"><?php echo  esc_html__( 'Source', 'open-world' ) ?></span>
							<?php endif; ?>
							<?php if ( $lang === $fallback ): ?>
								<span class="ow-role-badge ow-role-fallback"><?php echo  esc_html__( 'Fallback', 'open-world' ) ?></span>
							<?php endif; ?>
							<?php if ( ! $is_default && ! $is_source && $lang !== $fallback ): ?>
								<span class="ow-role-badge ow-role-target"><?php echo  esc_html__( 'Target', 'open-world' ) ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $locked ): ?>
								<span class="ow-status-element ow-status-active" title="<?php echo  esc_attr__( 'Default and Source languages are always active.', 'open-world' ) ?>">
									🟢 <?php echo  esc_html__( 'Active', 'open-world' ) ?>
								</span>
								<span style="font-size:.8em;color:#888;display:block;margin-top:2px;position: absolute; transform: translate(-34px, -27px);"><?php echo  esc_html__( 'locked', 'open-world' ) ?></span>
							<?php else: ?>
								<div class="ow-status-wrap" data-lang="<?php echo  esc_attr( $lang ) ?>" data-nonce="<?php echo  esc_attr( wp_create_nonce( 'ow_set_lang_status_' . $lang ) ) ?>">
									<select class="ow-status-select" style="max-width:160px">
										<option value="active"   <?php echo  selected( $status, 'active',   false ) ?>>🟢 <?php echo  esc_html__( 'Active',   'open-world' ) ?></option>
										<option value="pending"  <?php echo  selected( $status, 'pending',  false ) ?>>🟡 <?php echo  esc_html__( 'Pending',  'open-world' ) ?></option>
										<option value="inactive" <?php echo  selected( $status, 'inactive', false ) ?>>⚫ <?php echo  esc_html__( 'Inactive', 'open-world' ) ?></option>
									</select>
									<span class="ow-status-saving" style="display:none;margin-left:6px;color:#888;font-size:.85em">saving…</span>
									<span class="ow-status-saved"  style="display:none;margin-left:6px;color:#46b450;font-size:.85em">✓</span>
								</div>
							<?php endif; ?>
						</td>
						<td style="white-space:nowrap">
							<?php
							$this->lang_action_link( $lang, 'set_default',  __( 'Set Default URL', 'open-world' ), $is_default );
							$this->lang_action_link( $lang, 'set_source',   __( 'Set Source', 'open-world' ),     $is_source );
							$this->lang_action_link( $lang, 'set_fallback', __( 'Set Fallback', 'open-world' ),  $lang === $fallback );
							if ( ! $is_default && ! $is_source ): ?>
							<a href="<?php echo  esc_url( wp_nonce_url(
								admin_url( 'admin-post.php?action=ow_lang_action&ow_action=remove&lang_code=' . rawurlencode( $lang ) ),
								'ow_lang_action'
							) ) ?>" class="button button-small button-link-delete"
							   onclick="return confirm('Remove language &quot;<?php echo  esc_js( $lang ) ?>&quot; and all its translations? This cannot be undone.')"
							><?php echo  esc_html__( 'Remove', 'open-world' ) ?></a>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Add Language -->
			<h2 style="margin-top:30px"><?php echo  esc_html__( 'Add Language', 'open-world' ) ?></h2>
			<div class="ow-settings-grid">
				<div class="ow-settings-card">
					<h3><?php echo  esc_html__( 'Quick Add (Known Language)', 'open-world' ) ?></h3>
					<form method="post" action="<?php echo  esc_url( admin_url( 'admin-post.php' ) ) ?>">
						<?php wp_nonce_field( 'ow_lang_action' ); ?>
						<input type="hidden" name="action" value="ow_lang_action">
						<input type="hidden" name="ow_action" value="add_known">
						<select name="known_locale" style="width:100%">
							<?php foreach ( $known as $locale => $data ):
								$code = OW_Languages::locale_to_code( $locale );
								if ( isset( $all[ $code ] ) ) continue;
							?>
							<option value="<?php echo  esc_attr( $locale ) ?>"><?php echo  esc_html( $data['flag'] . ' ' . $data['name'] ) ?> (<?php echo  esc_html( $locale ) ?>)</option>
							<?php endforeach; ?>
						</select>
						<br><br>
						<p class="description" style="margin-bottom:8px">
							<?php echo  esc_html__( 'New languages are added as Active. Change status to Pending to work on translations before making it public.', 'open-world' ) ?>
						</p>
						<button type="submit" class="button button-primary"><?php echo  esc_html__( 'Add Language', 'open-world' ) ?></button>
					</form>
				</div>

				<div class="ow-settings-card">
					<h3><?php echo  esc_html__( 'Add Custom Language', 'open-world' ) ?></h3>
					<form method="post" action="<?php echo  esc_url( admin_url( 'admin-post.php' ) ) ?>">
						<?php wp_nonce_field( 'ow_lang_action' ); ?>
						<input type="hidden" name="action" value="ow_lang_action">
						<input type="hidden" name="ow_action" value="add_custom">
						<p>
							<label><?php echo  esc_html__( 'Code (e.g. de, nl, zh)', 'open-world' ) ?></label><br>
							<input type="text" name="lang_code" maxlength="10" style="width:100%">
						</p>
						<p>
							<label><?php echo  esc_html__( 'Locale (e.g. de_DE, en_US)', 'open-world' ) ?></label><br>
							<input type="text" name="locale" maxlength="20" style="width:100%">
						</p>
						<p>
							<label><?php echo  esc_html__( 'Name (e.g. Deutsch)', 'open-world' ) ?></label><br>
							<input type="text" name="lang_name" maxlength="100" style="width:100%">
						</p>
						<p>
							<label><?php echo  esc_html__( 'Flag emoji (optional)', 'open-world' ) ?></label><br>
							<input type="text" name="flag" maxlength="10" style="width:100%">
						</p>
						<button type="submit" class="button button-primary"><?php echo  esc_html__( 'Add Custom Language', 'open-world' ) ?></button>
					</form>
				</div>
			</div>
		</div>

		<script>
		(function($) {
			$('.ow-status-select').on('change', function() {
				var $wrap   = $(this).closest('.ow-status-wrap');
				var lang    = $wrap.data('lang');
				var nonce   = $wrap.data('nonce');
				var status  = $(this).val();
				$wrap.find('.ow-status-saving').show();
				$wrap.find('.ow-status-saved').hide();
				$.post(ajaxurl, {
					action:    'ow_set_lang_status',
					lang_code: lang,
					status:    status,
					nonce:     nonce
				}, function(res) {
					$wrap.find('.ow-status-saving').hide();
					if (res.success) {
						$wrap.find('.ow-status-saved').show().delay(2000).fadeOut();
					} else {
						alert(res.data || '<?php echo  esc_js( __( 'Failed to update status.', 'open-world' ) ) ?>');
					}
				});
			});
		})(jQuery);
		</script>
		<?php
	}


	// ── Auto-Translate Page ──────────────────────────────────────────────────

	public function render_auto_translate(): void {
		$targets      = OW_Languages::get_target_languages();
		$flags        = OW_Languages::get_flags();
		$names        = OW_Languages::get_names();
		$source_lang  = OW_Languages::get_source();
		$is_configured = OW_DeepL::is_configured();

		if ( empty( $targets ) ) {
			echo '<div class="wrap ow-wrap"><p>' . esc_html__( 'No target languages. Add languages first.', 'open-world' ) . '</p></div>';
			return;
		}

		$first_lang = reset( $targets );
		// Preload filter options for the first language
		$domains      = OW_DB::get_domains( $first_lang );
		$sources      = OW_DB::get_sources( $first_lang );
		$source_types = OW_DB::get_source_types( $first_lang );

		wp_enqueue_script( 'ow-deepl-translate',
			plugins_url( 'assets/js/deepl-translate.js', dirname( __FILE__ ) ),
			[], OW_VERSION, true
		);
		wp_localize_script( 'ow-deepl-translate', 'owDeepL', [
			'ajaxurl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'ow_deepl_translate' ),
			'provider'  => OW_Google_Free::is_enabled() ? 'google_free' : 'deepl',
			'i18n'      => [
				'translating' => __( 'Translating...', 'open-world' ),
				'done'        => __( 'Done!', 'open-world' ),
				'stopped'     => __( 'Stopped.', 'open-world' ),
				'error'       => __( 'Error:', 'open-world' ),
				'of'          => __( 'of', 'open-world' ),
				'strings'     => __( 'strings', 'open-world' ),
				'chars_used'  => __( 'chars used', 'open-world' ),
				'no_untranslated' => __( 'No untranslated strings match these filters.', 'open-world' ),
			],
		] );
		?>
		<div class="wrap ow-wrap">
			<div class="ow-page-header">
				<img src="<?php echo esc_url( OW_PLUGIN_URL . 'assets/images/OpenWorldTransparentCompressed.png' ) ?>" alt="Open World" class="ow-logo">
				<div class="ow-page-header-text">
					<h1><?php echo esc_html__( 'Auto-Translate', 'open-world' ) ?></h1>
					<p class="description"><?php echo esc_html__( 'Automatically translate all untranslated strings in your database using your preferred translation engine.', 'open-world' ) ?></p>
				</div>
			</div>

			<?php if ( ! $source_lang ): ?>
			<div class="notice notice-error" style="margin:12px 0">
				<p><?php echo esc_html__( 'No source language set. Go to Languages and set one.', 'open-world' ) ?></p>
			</div>
			<?php endif; ?>

			<?php
			$google_enabled   = OW_Google_Free::is_enabled();
			$deepl_configured = OW_DeepL::is_configured();
			// If nothing explicitly selected yet, default to Google Free
			$active_provider  = $google_enabled ? 'google_free' : ( $deepl_configured ? 'deepl' : 'google_free' );
			?>

			<!-- ── Provider Switcher ─────────────────────────────────────────── -->
			<div class="ow-settings-card" style="margin-bottom:16px">
				<h2><?php echo esc_html__( 'Translation Engine', 'open-world' ) ?></h2>
				<p class="description" style="margin-bottom:14px"><?php echo esc_html__( 'Choose which engine translates your strings. The selection is saved instantly.', 'open-world' ) ?></p>

				<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;" id="ow-provider-cards">

					<!-- Google Free card -->
					<label id="ow-provider-google-card" for="ow-provider-google" style="
						display:block;border:2px solid <?php echo ( $active_provider === 'google_free' ) ? '#2271b1' : '#ddd' ?>;
						border-radius:8px;padding:16px;cursor:pointer;transition:border-color .2s;
						background:<?php echo ( $active_provider === 'google_free' ) ? '#f0f6fc' : '#fff' ?>">
						<div style="display:flex;align-items:flex-start;gap:10px">
							<input type="radio" id="ow-provider-google" name="ow_provider" value="google_free"
								<?php checked( $active_provider, 'google_free' ) ?> style="margin-top:3px;width:16px;height:16px">
							<div>
								<strong style="font-size:1rem">🌐 <?php echo esc_html__( 'Google Translate', 'open-world' ) ?></strong>
								<span style="display:inline-block;margin-left:6px;font-size:.72rem;background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;border-radius:10px;padding:1px 8px"><?php echo esc_html__( 'Free', 'open-world' ) ?></span>
								<p style="margin:6px 0 0;font-size:.82rem;color:#555"><?php echo esc_html__( 'No API key required. Unlimited characters. Powered by the same engine as translate.google.com. Ideal for getting started immediately.', 'open-world' ) ?></p>
								<p style="margin:4px 0 0;font-size:.78rem;color:#888"><?php echo esc_html__( 'Note: unofficial endpoint — rate limited for large batches. Batches are intentionally small and paced.', 'open-world' ) ?></p>
							</div>
						</div>
					</label>

					<!-- DeepL card -->
					<?php $deepl_locked = ! $deepl_configured; ?>
					<label id="ow-provider-deepl-card" for="ow-provider-deepl" style="
						display:block;border:2px solid <?php echo ( $active_provider === 'deepl' ) ? '#2271b1' : '#ddd' ?>;
						border-radius:8px;padding:16px;cursor:<?php echo $deepl_locked ? 'not-allowed' : 'pointer' ?>;
						transition:border-color .2s;opacity:<?php echo $deepl_locked ? '.55' : '1' ?>;
						background:<?php echo ( $active_provider === 'deepl' ) ? '#f0f6fc' : '#fff' ?>">
						<div style="display:flex;align-items:flex-start;gap:10px">
							<input type="radio" id="ow-provider-deepl" name="ow_provider" value="deepl"
								<?php checked( $active_provider, 'deepl' ) ?>
								<?php disabled( $deepl_locked ) ?>
								style="margin-top:3px;width:16px;height:16px">
							<div>
								<strong style="font-size:1rem">⚡ DeepL</strong>
								<span style="display:inline-block;margin-left:6px;font-size:.72rem;background:#e3f2fd;color:#1565c0;border:1px solid #90caf9;border-radius:10px;padding:1px 8px"><?php echo $deepl_locked ? esc_html__( 'API key required', 'open-world' ) : esc_html__( 'Connected', 'open-world' ) ?></span>
								<p style="margin:6px 0 0;font-size:.82rem;color:#555"><?php echo esc_html__( 'Professional-grade translations. Free plan: 500,000 chars/month. Pro plans for higher limits.', 'open-world' ) ?></p>
								<?php if ( $deepl_locked ): ?>
								<p style="margin:4px 0 0;font-size:.78rem;color:#d63638">
									<?php echo esc_html__( 'Configure your API key in', 'open-world' ) ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=ow-settings' ) ) ?>"><?php echo esc_html__( 'Settings → DeepL API', 'open-world' ) ?></a>
									<?php echo esc_html__( 'to enable DeepL.', 'open-world' ) ?>
								</p>
								<?php else: ?>
								<p style="margin:4px 0 0;font-size:.78rem;color:#46b450"><?php echo esc_html__( '✓ DeepL API key is configured and ready.', 'open-world' ) ?></p>
								<?php endif; ?>
							</div>
						</div>
					</label>

				</div>

				<div style="margin-top:12px;">
					<span id="ow-provider-save-status" style="font-size:.85rem;color:#888"></span>
				</div>

				<script>
				(function(){
					var radios = document.querySelectorAll('input[name="ow_provider"]');
					var googleCard = document.getElementById('ow-provider-google-card');
					var deeplCard  = document.getElementById('ow-provider-deepl-card');
					var stat = document.getElementById('ow-provider-save-status');
					var nonce = '<?php echo esc_attr( wp_create_nonce( 'ow_google_free_toggle' ) ) ?>';

					function styleCards(val) {
						googleCard.style.borderColor = val === 'google_free' ? '#2271b1' : '#ddd';
						googleCard.style.background  = val === 'google_free' ? '#f0f6fc' : '#fff';
						deeplCard.style.borderColor  = val === 'deepl'       ? '#2271b1' : '#ddd';
						deeplCard.style.background   = val === 'deepl'       ? '#f0f6fc' : '#fff';
					}

					radios.forEach(function(r){
						r.addEventListener('change', async function(){
							var val = this.value;
							styleCards(val);
							stat.textContent = '<?php echo esc_js( __( 'Saving…', 'open-world' ) ) ?>';
							stat.style.color = '#888';
							// Update provider: google_free enabled = '1', deepl = '0'
							var body = new URLSearchParams({
								action: 'ow_google_free_toggle',
								enabled: val === 'google_free' ? '1' : '0',
								_ajax_nonce: nonce
							});
							try {
								var r2 = await fetch(ajaxurl, {method:'POST',credentials:'same-origin',body:body});
								var d  = await r2.json();
								if (d.success) {
									// Update the JS provider so translate runs the right action
									if (window.owDeepL) window.owDeepL.provider = val;
									
									// Dynamically update the disabled state of the 'Start' button
									var startBtn = document.getElementById('ow-at-start');
									if (startBtn) {
										var hasDeeplKey = <?php echo json_encode($is_configured); ?>;
										var hasSourceLang = <?php echo json_encode((bool) $source_lang); ?>;
										if (val === 'google_free') {
											startBtn.disabled = !hasSourceLang;
										} else {
											startBtn.disabled = !(hasDeeplKey && hasSourceLang);
										}
									}

									stat.textContent = '✓ ' + (val === 'google_free'
										? '<?php echo esc_js( __( 'Using Google Translate (Free)', 'open-world' ) ) ?>'
										: '<?php echo esc_js( __( 'Using DeepL', 'open-world' ) ) ?>');
									stat.style.color = '#46b450';
								} else {
									stat.textContent = '✗ <?php echo esc_js( __( 'Could not save.', 'open-world' ) ) ?>';
									stat.style.color = '#d63638';
								}
							} catch(e) {
								stat.textContent = '✗ Network error';
								stat.style.color = '#d63638';
							}
						});
					});
				})();
				</script>
			</div>

			<!-- ── Filters & Progress ────────────────────────────────────────── -->
			<div class="ow-settings-card">
				<h2><?php echo esc_html__( 'Translation Settings', 'open-world' ) ?></h2>

				<div class="ow-deepl-filters">
					<div>
						<label><strong><?php echo esc_html__( 'Target Language', 'open-world' ) ?></strong></label><br>
						<select id="ow-at-lang">
							<?php foreach ( $targets as $l ): ?>
							<option value="<?php echo esc_attr( $l ) ?>"><?php echo esc_html( ( $flags[ $l ] ?? '' ) . ' ' . ( $names[ $l ] ?? $l ) ) ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div>
						<label><strong><?php echo esc_html__( 'Domain', 'open-world' ) ?></strong></label><br>
						<select id="ow-at-domain">
							<option value=""><?php echo esc_html__( 'All domains', 'open-world' ) ?></option>
							<?php foreach ( $domains as $d ): ?>
							<option value="<?php echo esc_attr( $d ) ?>"><?php echo esc_html( $d ) ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div>
						<label><strong><?php echo esc_html__( 'Source Type', 'open-world' ) ?></strong></label><br>
						<select id="ow-at-source-type">
							<option value=""><?php echo esc_html__( 'All types', 'open-world' ) ?></option>
							<?php foreach ( $source_types as $st ): ?>
							<option value="<?php echo esc_attr( $st ) ?>"><?php echo esc_html( $st ) ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div>
						<label><strong><?php echo esc_html__( 'Source', 'open-world' ) ?></strong></label><br>
						<select id="ow-at-source">
							<option value=""><?php echo esc_html__( 'All sources', 'open-world' ) ?></option>
							<?php foreach ( $sources as $src ): ?>
							<option value="<?php echo esc_attr( $src ) ?>"><?php echo esc_html( $src ) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div style="margin-top:16px">
					<button type="button" class="button" id="ow-at-preview"><?php echo esc_html__( 'Preview', 'open-world' ) ?></button>
					<span id="ow-at-preview-info" style="margin-left:8px;color:#666"></span>
				</div>
			</div>

			<div class="ow-settings-card" style="margin-top:16px" id="ow-at-progress-card">
				<h2><?php echo esc_html__( 'Translation Progress', 'open-world' ) ?></h2>

				<div class="ow-progress" style="margin:12px 0;height:24px;border-radius:4px">
					<div class="ow-progress-bar" id="ow-at-progress-bar" style="width:0%;transition:width .3s;line-height:24px;text-align:center;color:#fff;font-size:.82rem"></div>
				</div>

				<p id="ow-at-status" style="margin:8px 0;font-size:.9rem;color:#555">
					<?php echo esc_html__( 'Press "Start" to begin auto-translation.', 'open-world' ) ?>
				</p>

				<div style="display:flex;gap:8px;align-items:center;margin-top:12px">
					<button type="button" class="button button-primary" id="ow-at-start" <?php echo ( $active_provider === 'google_free' || $is_configured ) && $source_lang ? '' : 'disabled' ?>>
						<?php echo esc_html__( 'Start Translation', 'open-world' ) ?>
					</button>
					<button type="button" class="button" id="ow-at-stop" disabled>
						<?php echo esc_html__( 'Stop', 'open-world' ) ?>
					</button>
				</div>

				<div id="ow-at-result" style="margin-top:12px;display:none" class="notice notice-success">
					<p id="ow-at-result-text"></p>
				</div>
			</div>
		</div>
		<?php
	}


	// ── Settings Page ─────────────────────────────────────────────────────────

	public function render_settings(): void {
		$scan_result = get_transient( 'ow_last_scan_result' );
		if ( $scan_result ) delete_transient( 'ow_last_scan_result' );
		?>
		<div class="wrap ow-wrap">
			<div class="ow-page-header">
				<img src="<?php echo  esc_url( OW_PLUGIN_URL . 'assets/images/OpenWorldTransparentCompressed.png' ) ?>" alt="Open World" class="ow-logo">
				<div class="ow-page-header-text">
					<h1><?php echo  esc_html__( 'Settings', 'open-world' ) ?></h1>
				</div>
			</div>

			<?php if ( $scan_result ): ?>
			<div class="notice notice-success is-dismissible"><p><?php echo  esc_html( $scan_result ) ?></p></div>
			<?php endif; ?>

			<div class="ow-settings-grid">
				<div class="ow-settings-card">
					<h2>🎯 <?php echo  esc_html__( 'Smart Scan', 'open-world' ) ?> <span style="font-size:.75rem;font-weight:400;color:var(--ow-emerald)"><?php echo  esc_html__( 'recommended', 'open-world' ) ?></span></h2>
					<p class="description"><?php echo  esc_html__( 'Crawls all published pages and auto-detected WooCommerce endpoints (shop, cart, checkout, account). Captures only strings that are actually rendered on the frontend — typically 200–500 strings instead of tens of thousands.', 'open-world' ) ?></p>
					<p class="description" style="color:var(--ow-muted);font-size:.85em;margin-top:4px">
						<?php echo esc_html__( 'Note: Page crawling requires a valid SSL certificate on your server to function properly.', 'open-world' ) ?>
					</p>
					<form method="post" action="<?php echo  esc_url( admin_url( 'admin-post.php' ) ) ?>" style="margin-top:12px">
						<?php wp_nonce_field( 'ow_scan_strings', 'ow_scan_nonce' ); ?>
						<input type="hidden" name="action" value="ow_scan_strings">
						<input type="hidden" name="scan_mode" value="smart">
						<button type="submit" class="button button-primary"><?php echo  esc_html__( 'Run Smart Scan', 'open-world' ) ?></button>
					</form>
				</div>

				<div class="ow-settings-card">
					<h2>🛡️ <?php echo esc_html__( 'Scanner Security', 'open-world' ) ?></h2>
					<p class="description"><?php echo esc_html__( 'Smart Scan crawls pages to find strings. It can forward your login cookies to accurately capture strings on restricted pages (e.g. My Account). If disabled, the scanner visits pages as a logged-out guest.', 'open-world' ) ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ) ?>" style="margin-top:12px">
						<?php wp_nonce_field( 'ow_save_scanner_settings', 'ow_scanner_settings_nonce' ); ?>
						<input type="hidden" name="action" value="ow_save_scanner_settings">
						<label>
							<input type="checkbox" name="ow_forward_cookies" value="1" <?php checked( get_option( 'ow_forward_cookies', 'yes' ), 'yes' ) ?>>
							<strong><?php echo esc_html__( 'Forward essential session cookies during scans', 'open-world' ) ?></strong>
						</label>
						<p class="description" style="margin-top:4px; margin-left:24px; font-size:.85em; color:var(--ow-muted)">
							<?php echo esc_html__( 'Only WordPress authentication and WooCommerce session cookies are forwarded. Requires acceptance for strict security compliance.', 'open-world' ) ?>
						</p>
						<button type="submit" class="button" style="margin-top:12px"><?php echo esc_html__( 'Save Preferences', 'open-world' ) ?></button>
					</form>
				</div>

				<div class="ow-settings-card">
					<h2><?php echo  esc_html__( 'Clean Unused Strings', 'open-world' ) ?></h2>
					<p class="description"><?php echo  esc_html__( 'Removes untranslated strings from previous full source scans that have no user translation. Already-translated strings are preserved.', 'open-world' ) ?></p>
					<form method="post" action="<?php echo  esc_url( admin_url( 'admin-post.php' ) ) ?>" style="margin-top:12px">
						<?php wp_nonce_field( 'ow_clean_unused', 'ow_clean_nonce' ); ?>
						<input type="hidden" name="action" value="ow_clean_unused">
						<button type="submit" class="button" onclick="return confirm('<?php echo  esc_js( __( 'This will remove all untranslated strings from full source scans. Already-translated strings are preserved. Continue?', 'open-world' ) ) ?>')"><?php echo  esc_html__( 'Clean Unused Strings', 'open-world' ) ?></button>
					</form>
					<hr style="margin:20px 0; border:0; border-top:1px solid #eee;">
					<p class="description" style="color:#d63638"><?php echo esc_html__( 'Warning: This will permanently delete ALL strings from the database, both translated and untranslated.', 'open-world' ) ?></p>
					<button type="button" class="button button-link-delete" id="ow-delete-all-btn" style="margin-top:8px"><?php echo esc_html__( 'Delete ALL translations', 'open-world' ) ?></button>
					<script>
					document.getElementById('ow-delete-all-btn').addEventListener('click', async function() {
						const typed = prompt('<?php echo esc_js( __( 'Type exactly: I understand what I am doing right now', 'open-world' ) ) ?>');
						if (typed === 'I understand what I am doing right now') {
							const secondConfirm = confirm('<?php echo esc_js( __( 'Are you sure?', 'open-world' ) ) ?>');
							if (secondConfirm) {
								const body = new URLSearchParams({
									action: 'ow_delete_all_translations',
									_ajax_nonce: '<?php echo esc_js( wp_create_nonce('ow_delete_all_translations') ) ?>'
								});
								document.getElementById('ow-delete-all-btn').disabled = true;
								document.getElementById('ow-delete-all-btn').textContent = '<?php echo esc_js( __( 'Deleting...', 'open-world' ) ) ?>';
								try {
									const r = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body });
									const d = await r.json();
									if(d.success) {
										alert('<?php echo esc_js( __( 'All translations deleted successfully.', 'open-world' ) ) ?>');
										location.reload();
									} else {
										alert('Error: ' + (d.data || 'Unknown error'));
										document.getElementById('ow-delete-all-btn').disabled = false;
										document.getElementById('ow-delete-all-btn').textContent = '<?php echo esc_js( __( 'Delete ALL translations', 'open-world' ) ) ?>';
									}
								} catch (e) {
									alert('Network error');
									document.getElementById('ow-delete-all-btn').disabled = false;
									document.getElementById('ow-delete-all-btn').textContent = '<?php echo esc_js( __( 'Delete ALL translations', 'open-world' ) ) ?>';
								}
							}
						} else if (typed !== null) {
							alert('<?php echo esc_js( __( 'Incorrect text provided. Aborted.', 'open-world' ) ) ?>');
						}
					});
					</script>
				</div>

				<div class="ow-settings-card">
					<h2><?php echo  esc_html__( 'Full Source Scan', 'open-world' ) ?> <span style="font-size:.75rem;font-weight:400;color:var(--ow-muted)"><?php echo  esc_html__( 'advanced', 'open-world' ) ?></span></h2>
					<p class="description"><?php echo  esc_html__( 'Scans PHP source files and POT files for all gettext calls. Imports everything including admin-only strings. Can result in 10,000+ strings per source. Use only if you need complete coverage.', 'open-world' ) ?></p>
					<form method="post" action="<?php echo  esc_url( admin_url( 'admin-post.php' ) ) ?>" style="margin-top:12px">
						<?php wp_nonce_field( 'ow_scan_strings', 'ow_scan_nonce' ); ?>
						<input type="hidden" name="action" value="ow_scan_strings">
						<input type="hidden" name="scan_mode" value="full">
						<label><input type="checkbox" name="scan_theme" value="1"> <?php echo  esc_html__( 'Child theme', 'open-world' ) ?></label>
						<label style="margin-left:12px"><input type="checkbox" name="scan_parent_theme" value="1"> <?php echo  esc_html__( 'Parent theme', 'open-world' ) ?></label>
						<label style="margin-left:12px"><input type="checkbox" name="scan_woocommerce" value="1"> <?php echo  esc_html__( 'WooCommerce POT', 'open-world' ) ?> <span style="font-size:.75em;color:var(--ow-teal-dark)">(~14,000 strings)</span></label>
						<label style="display: inline-flex;margin-top: 12px;gap: 4px;align-items: flex-end;"><input type="checkbox" name="scan_pages" value="1" checked> <?php echo  esc_html__( 'Page crawl', 'open-world' ) ?> <span style="font-size:.75em;color:var(--ow-muted)">(<?php echo esc_html__( 'requires valid SSL', 'open-world' ) ?>)</span></label>
						<br><br>
						<button type="submit" class="button"><?php echo  esc_html__( 'Run Full Source Scan', 'open-world' ) ?></button>
					</form>
				</div>

				<div class="ow-settings-card" style="grid-column: span 2;">
					<h2><?php echo  esc_html__( 'Language Switcher Shortcodes', 'open-world' ) ?></h2>
					<p class="description"><?php echo  esc_html__( 'Copy a shortcode below and paste it into any page, post, or widget to display a language switcher.', 'open-world' ) ?></p>
					<table class="widefat" style="margin-top:10px">
						<thead><tr><th><?php echo  esc_html__( 'Style', 'open-world' ) ?></th><th><?php echo  esc_html__( 'Shortcode', 'open-world' ) ?></th><th><?php echo  esc_html__( 'Description', 'open-world' ) ?></th></tr></thead>
						<tbody>
							<tr><td>Dropdown</td><td><code>[ow_language_switcher style="dropdown"]</code></td><td><?php echo  esc_html__( 'Expandable dropdown menu', 'open-world' ) ?></td></tr>
							<tr><td>Flags</td><td><code>[ow_language_switcher style="flags"]</code></td><td><?php echo  esc_html__( 'Flag emojis inline', 'open-world' ) ?></td></tr>
							<tr><td>Codes</td><td><code>[ow_language_switcher style="codes"]</code></td><td><?php echo  esc_html__( 'Compact language codes: PL EN DE', 'open-world' ) ?></td></tr>
							<tr><td>List</td><td><code>[ow_language_switcher style="list"]</code></td><td><?php echo  esc_html__( 'Flags + full names inline', 'open-world' ) ?></td></tr>
						</tbody>
					</table>
					<p class="description" style="margin-top:8px"><?php echo esc_html__( 'To place the switcher, use one of the shortcodes above, or add the "Language Switcher" widget in Appearance → Widgets. The switcher is never injected automatically — you decide where it appears.', 'open-world' ) ?></p>
			</div>

			<div class="ow-settings-card ow-deepl-settings-card">
				<h2><?php echo  esc_html__( 'DeepL API — Auto-Translation', 'open-world' ) ?></h2>
				<p class="description">
					<?php echo  esc_html__( 'Connect DeepL for automatic translation.', 'open-world' ) ?>
					<a href="https://www.deepl.com/pro-api" target="_blank" rel="noopener noreferrer"><?php echo  esc_html__( 'Create free account →', 'open-world' ) ?></a>
				</p>
				<p class="description" style="color:#888;margin-top:2px"><?php echo  esc_html__( 'Free plan: 500,000 chars/month. Pro plans available for higher limits.', 'open-world' ) ?></p>

				<?php $deepl_key = OW_DeepL::get_api_key(); $deepl_plan = get_option( OW_DeepL::OPTION_PLAN, 'free' ); ?>

				<div style="margin-top:12px">
					<label><strong><?php echo  esc_html__( 'API Key', 'open-world' ) ?></strong></label><br>
					<input type="password" id="ow-deepl-key" value="<?php echo  esc_attr( $deepl_key ) ?>" style="width:100%;margin-top:4px" placeholder="<?php echo  esc_attr__( 'Paste your DeepL API key here', 'open-world' ) ?>">
				</div>
				<div style="margin-top:10px">
					<label><strong><?php echo  esc_html__( 'Plan', 'open-world' ) ?></strong></label><br>
					<label style="margin-right:14px"><input type="radio" name="ow_deepl_plan" value="free" <?php echo  checked( $deepl_plan, 'free', false ) ?>> Free <code style="font-size:.78rem">api-free.deepl.com</code></label>
					<label><input type="radio" name="ow_deepl_plan" value="pro" <?php echo  checked( $deepl_plan, 'pro', false ) ?>> Pro <code style="font-size:.78rem">api.deepl.com</code></label>
				</div>
				<div style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
					<button type="button" class="button" id="ow-deepl-save"><?php echo  esc_html__( 'Save &amp; Test Connection', 'open-world' ) ?></button>
					<a href="<?php echo  esc_url( admin_url( 'admin.php?page=ow-auto-translate' ) ) ?>" class="button"><?php echo  esc_html__( 'Open Auto-Translate', 'open-world' ) ?></a>
					<span id="ow-deepl-status" style="font-weight:600"></span>
				</div>
				<div id="ow-deepl-usage" style="margin-top:10px;display:<?php echo  $deepl_key ? 'block' : 'none' ?>">
					<label><strong><?php echo  esc_html__( 'Usage this month', 'open-world' ) ?></strong></label>
					<div class="ow-progress" style="margin-top:4px"><div class="ow-progress-bar" id="ow-deepl-usage-bar" style="width:0%"></div></div>
					<span id="ow-deepl-usage-text" class="description"></span>
				</div>
				<script>
				(function(){
					var btn=document.getElementById('ow-deepl-save'),stat=document.getElementById('ow-deepl-status'),uBox=document.getElementById('ow-deepl-usage'),uBar=document.getElementById('ow-deepl-usage-bar'),uTxt=document.getElementById('ow-deepl-usage-text');
					if(!btn)return;
					btn.addEventListener('click',async function(){
						var key=document.getElementById('ow-deepl-key').value,plan=document.querySelector('input[name="ow_deepl_plan"]:checked').value;
						stat.textContent='<?php echo  esc_js( __( 'Testing…', 'open-world' ) ) ?>';stat.style.color='#888';btn.disabled=true;
						var body=new URLSearchParams({action:'ow_deepl_save_settings',api_key:key,plan:plan,_ajax_nonce:'<?php echo esc_attr( wp_create_nonce('ow_deepl_settings') ) ?>'});
						try{
							var r=await fetch(ajaxurl,{method:'POST',credentials:'same-origin',body:body}),d=await r.json();
							if(d.success){stat.textContent='\u2713 '+d.data.message;stat.style.color='#46b450';if(d.data.usage){var u=d.data.usage,pct=u.character_limit>0?Math.min(100,Math.round(u.character_count/u.character_limit*100)):0;uBar.style.width=pct+'%';uTxt.textContent=u.character_count.toLocaleString()+' / '+u.character_limit.toLocaleString()+' chars ('+pct+'%)';uBox.style.display='block';}}
							else{stat.textContent='\u2717 '+(d.data||'?');stat.style.color='#d63638';}
						}catch(e){stat.textContent='\u2717 Network error';stat.style.color='#d63638';}
						btn.disabled=false;
					});
				})();
				</script>
			</div>

			<div class="ow-settings-card ow-google-free-settings-card">
				<h2><?php echo esc_html__( 'Google Translate — Free Auto-Translation', 'open-world' ) ?></h2>
				<p class="description">
					<?php echo esc_html__( 'Translate strings automatically using Google Translate — no API key required.', 'open-world' ) ?>
				</p>
				<p class="description" style="color:#888;margin-top:2px"><?php echo esc_html__( 'Batches are sent thoughtfully to avoid rate limits. Unlimited characters.', 'open-world' ) ?></p>

				<div style="margin-top:12px">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=ow-auto-translate' ) ) ?>" class="button"><?php echo esc_html__( 'Open Auto-Translate', 'open-world' ) ?></a>
				</div>
			</div>

			<div class="ow-settings-card">
					<h2><?php echo  esc_html__( 'Import PO File', 'open-world' ) ?></h2>
					<p class="description"><?php echo  esc_html__( 'Upload a .po file to bulk-import translations for a specific language and domain.', 'open-world' ) ?></p>
					<form method="post" action="<?php echo  esc_url( admin_url( 'admin-post.php' ) ) ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( 'ow_import_po', 'ow_import_nonce' ); ?>
						<input type="hidden" name="action" value="ow_import_po">
						<select name="import_lang" style="width:100%">
							<?php foreach ( OW_Languages::get_target_languages() as $l ): ?>
							<option value="<?php echo  esc_attr( $l ) ?>"><?php echo  esc_html( OW_Languages::get_flag( $l ) . ' ' . OW_Languages::get_name( $l ) ) ?></option>
							<?php endforeach; ?>
						</select><br>
						<input type="text" name="import_domain" placeholder="<?php echo  esc_attr__( 'domain (e.g. woocommerce, generatepress)', 'open-world' ) ?>" style="width:100%;margin-top:8px"><br><br>
						<input type="file" name="po_file" accept=".po"><br><br>
						<button type="submit" class="button button-primary"><?php echo  esc_html__( 'Import', 'open-world' ) ?></button>
					</form>
				</div>

				<div class="ow-settings-card">
					<h2><?php echo  esc_html__( 'Export PO File', 'open-world' ) ?></h2>
					<p class="description"><?php echo  esc_html__( 'Download translations as .po files. Each button represents a domain (text source):', 'open-world' ) ?></p>
					<ul class="ow-domain-legend">
						<li><strong>default</strong> — <?php echo  esc_html__( 'WordPress core strings', 'open-world' ) ?></li>
						<li><strong>open-world</strong> — <?php echo  esc_html__( 'this plugin\'s own UI strings', 'open-world' ) ?></li>
						<li><strong>woocommerce</strong> — <?php echo  esc_html__( 'WooCommerce shop strings', 'open-world' ) ?></li>
						<li><strong>generatepress</strong> — <?php echo  esc_html__( 'parent theme strings', 'open-world' ) ?></li>
						<li><strong>generatepress-child</strong> — <?php echo  esc_html__( 'your child theme strings', 'open-world' ) ?></li>
					</ul>
					<?php foreach ( OW_Languages::get_target_languages() as $l ): ?>
					<div style="padding: 7px; min-height:26px; border-bottom: 1px solid #EFEFEF;">
						<div style="min-width:94px;display:inline-block;"><?php echo  esc_html( OW_Languages::get_flag( $l ) ) ?> <?php echo  esc_html( OW_Languages::get_name( $l ) ) ?>:</div>
						<?php foreach ( OW_DB::get_domains( $l ) as $d ): ?>
						<a href="<?php echo  esc_url( admin_url( 'admin-post.php?action=ow_export_po&lang=' . $l . '&domain=' . urlencode($d) . '&_wpnonce=' . wp_create_nonce('ow_export_po') ) ) ?>" class="button button-small"><?php echo  esc_html( $d ) ?></a>
						<?php endforeach; ?>
					</div>
					<?php endforeach; ?>
				</div>
		</div>
	</div>
	<?php
	}

	// ── AJAX: Save translation ────────────────────────────────────────────────

	public function ajax_save_translation(): void {
		check_ajax_referer( 'ow_save_translation' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

		$id     = absint( $_POST['id'] ?? 0 );
		$msgstr = sanitize_textarea_field( wp_unslash( $_POST['msgstr'] ?? '' ) );

		// Plural forms: sent as JSON array when editing plural strings
		$plural_json  = isset( $_POST['plural_forms'] ) ? wp_unslash( $_POST['plural_forms'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$plural_forms = $plural_json ? json_decode( $plural_json, true ) : null;

		if ( ! $id ) wp_send_json_error( 'Invalid ID' );

		$ok = OW_DB::update_msgstr( $id, $msgstr, $plural_forms );
		wp_send_json_success( [ 'saved' => $ok ] );
	}

	// ── AJAX: Set Language Status ─────────────────────────────────────────────

	public function ajax_set_lang_status(): void {
		$lang_code = sanitize_key( wp_unslash( $_POST['lang_code'] ?? '' ) );
		check_ajax_referer( 'ow_set_lang_status_' . $lang_code, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized', 'open-world' ), 403 );
		}

		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );

		if ( ! in_array( $status, [ 'active', 'pending', 'inactive' ], true ) ) {
			wp_send_json_error( __( 'Invalid status value.', 'open-world' ) );
		}

		$ok = OW_Languages::set_status( $lang_code, $status );

		if ( ! $ok ) {
			wp_send_json_error( __( 'Could not update status. Default and Source languages must stay active.', 'open-world' ) );
		}

		wp_send_json_success( [ 'lang' => $lang_code, 'status' => $status ] );
	}

	// ── AJAX: DeepL Save Settings + Test ─────────────────────────────────────

	public function ajax_deepl_save_settings(): void {
		check_ajax_referer( 'ow_deepl_settings' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

		$key  = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		$plan = sanitize_key( wp_unslash( $_POST['plan'] ?? 'free' ) );

		OW_DeepL::save_settings( $key, $plan );

		if ( ! $key ) {
			wp_send_json_success( [ 'message' => __( 'API key cleared.', 'open-world' ), 'usage' => null ] );
		}

		$result = OW_DeepL::test_connection();
		if ( $result['ok'] ) {
			wp_send_json_success( [
				'message' => __( 'Connected successfully!', 'open-world' ),
				'usage'   => [
					'character_count' => $result['character_count'],
					'character_limit' => $result['character_limit'],
				],
			] );
		} else {
			wp_send_json_error( $result['error'] );
		}
	}

	// ── AJAX: DeepL Translate Batch ───────────────────────────────────────────

	public function ajax_deepl_translate(): void {
		check_ajax_referer( 'ow_deepl_translate' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

		$lang        = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
		$domain      = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );
		$source_type = sanitize_key( wp_unslash( $_POST['source_type'] ?? '' ) );
		$source      = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );

		if ( ! $lang ) wp_send_json_error( 'Missing language.' );

		$result = OW_DeepL::translate_batch( $lang, $domain, $source_type, $source );
		wp_send_json_success( $result );
	}

	// ── AJAX: DeepL Preview Count ────────────────────────────────────────────

	public function ajax_deepl_preview(): void {
		check_ajax_referer( 'ow_deepl_translate' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

		$lang        = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
		$domain      = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );
		$source_type = sanitize_key( wp_unslash( $_POST['source_type'] ?? '' ) );
		$source      = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );

		if ( ! $lang ) wp_send_json_error( 'Missing language.' );

		$count = OW_DB::count( $lang, $domain, 'untranslated', '', $source, $source_type );
		wp_send_json_success( [ 'count' => $count ] );
	}


	// ── AJAX: Google Free Toggle ──────────────────────────────────────────────

	public function ajax_google_free_toggle(): void {
		check_ajax_referer( 'ow_google_free_toggle' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

		$enabled = sanitize_key( wp_unslash( $_POST['enabled'] ?? '' ) ) === '1';
		OW_Google_Free::set_enabled( $enabled );
		wp_send_json_success( [ 'enabled' => $enabled ] );
	}

	// ── AJAX: Google Free Translate Batch ─────────────────────────────────────

	public function ajax_google_free_translate(): void {
		check_ajax_referer( 'ow_deepl_translate' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

		$lang        = sanitize_key( wp_unslash( $_POST['lang'] ?? '' ) );
		$domain      = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) );
		$source_type = sanitize_key( wp_unslash( $_POST['source_type'] ?? '' ) );
		$source      = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );

		if ( ! $lang ) wp_send_json_error( 'Missing language.' );

		$result = OW_Google_Free::translate_batch( $lang, $domain, $source_type, $source );
		wp_send_json_success( $result );
	}

	// ── Admin POST: Language actions ──────────────────────────────────────────


	public function handle_lang_action(): void {
		// Support both POST (add forms) and GET (nonce action links)
		check_admin_referer( 'ow_lang_action' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$action = sanitize_key( $_REQUEST['ow_action'] ?? '' );
		$lang   = sanitize_key( $_REQUEST['lang_code'] ?? '' );

		switch ( $action ) {
			case 'set_default':
				OW_Languages::set_default( $lang );
				OW_Router::flush_rules();
				/* translators: %s: language code */
				set_transient( 'ow_lang_notice', sprintf( __( 'Default URL language set to: %s', 'open-world' ), $lang ), 60 );
				break;

			case 'set_source':
				OW_Languages::set_source( $lang );
				/* translators: %s: language code */
				set_transient( 'ow_lang_notice', sprintf( __( 'Source language set to: %s. Re-scan strings if content language changed.', 'open-world' ), $lang ), 60 );
				break;

			case 'set_fallback':
				OW_Languages::set_fallback( $lang );
				/* translators: %s: language code */
				set_transient( 'ow_lang_notice', sprintf( __( 'Fallback language changed to: %s', 'open-world' ), $lang ), 60 );
				break;

			case 'remove':
				OW_Languages::remove( $lang );
				/* translators: %s: language code */
				set_transient( 'ow_lang_notice', sprintf( __( 'Language removed: %s', 'open-world' ), $lang ), 60 );
				break;

			case 'add_known':
				$locale = sanitize_text_field( wp_unslash( $_POST['known_locale'] ?? '' ) );
				$known  = OW_Languages::known_languages();
				if ( isset( $known[ $locale ] ) ) {
					$code = OW_Languages::locale_to_code( $locale );
					OW_Languages::add( $code, $locale, $known[ $locale ]['name'], $known[ $locale ]['flag'] );
					/* translators: %s: language name */
					set_transient( 'ow_lang_notice', sprintf( __( 'Language added: %s', 'open-world' ), $known[ $locale ]['name'] ), 60 );
				}
				break;

			case 'add_custom':
				$code   = sanitize_key( wp_unslash( $_POST['lang_code'] ?? '' ) );
				$locale = sanitize_text_field( wp_unslash( $_POST['locale'] ?? '' ) );
				$name   = sanitize_text_field( wp_unslash( $_POST['lang_name'] ?? '' ) );
				$flag   = sanitize_text_field( wp_unslash( $_POST['flag'] ?? '' ) );
				if ( $code && $locale && $name ) {
					OW_Languages::add( $code, $locale, $name, $flag );
					/* translators: %s: language name */
					set_transient( 'ow_lang_notice', sprintf( __( 'Language added: %s', 'open-world' ), $name ), 60 );
				}
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ow-languages' ) );
		exit;
	}

	// ── Admin POST: Scan strings ──────────────────────────────────────────────

	public function handle_scan_strings(): void {
		check_admin_referer( 'ow_scan_strings', 'ow_scan_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		set_time_limit( 600 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		$scanner   = new OW_Scanner();
		$scan_mode = sanitize_key( wp_unslash( $_POST['scan_mode'] ?? 'smart' ) );
		$total     = 0;

		if ( $scan_mode === 'smart' ) {
			// Smart Scan: crawl rendered pages only
			$total = $scanner->scan_smart();
			$label = __( 'Smart Scan', 'open-world' );
		} else {
			// Full Source Scan: legacy per-source checkboxes
			if ( ! empty( $_POST['scan_theme'] ) ) {
				$total += $scanner->seed_to_db( $scanner->scan_theme() );
			}
			if ( ! empty( $_POST['scan_parent_theme'] ) ) {
				$total += $scanner->seed_to_db( $scanner->scan_parent_theme() );
			}
			if ( ! empty( $_POST['scan_woocommerce'] ) ) {
				$total += $scanner->seed_to_db( $scanner->scan_woocommerce() );
			}
			if ( ! empty( $_POST['scan_pages'] ) ) {
				$total += $scanner->scan_all_pages();
			}
			$label = __( 'Full Source Scan', 'open-world' );
		}

		set_transient( 'ow_last_scan_result', sprintf(
			/* translators: %1$s = scan type, %2$d = count */
			__( '%1$s complete: %2$d new strings added.', 'open-world' ),
			$label,
			$total
		), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=ow-settings' ) );
		exit;
	}

	public function handle_save_scanner_settings(): void {
		check_admin_referer( 'ow_save_scanner_settings', 'ow_scanner_settings_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$forward_cookies = ! empty( $_POST['ow_forward_cookies'] ) ? 'yes' : 'no';
		update_option( 'ow_forward_cookies', $forward_cookies );

		set_transient( 'ow_last_scan_result', __( 'Scanner security preferences saved.', 'open-world' ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=ow-settings' ) );
		exit;
	}

	public function handle_clean_unused(): void {
		check_admin_referer( 'ow_clean_unused', 'ow_clean_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$deleted = OW_Scanner::clean_unused();

		set_transient( 'ow_last_scan_result', sprintf(
			/* translators: %d: number of deleted strings */
			__( 'Cleaned: %d unused strings removed.', 'open-world' ),
			$deleted
		), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=ow-settings' ) );
		exit;
	}

	// ── Admin POST: Import PO ─────────────────────────────────────────────────

	public function handle_import_po(): void {
		check_admin_referer( 'ow_import_po', 'ow_import_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$lang   = sanitize_key( wp_unslash( $_POST['import_lang'] ?? '' ) );
		$domain = sanitize_text_field( wp_unslash( $_POST['import_domain'] ?? 'default' ) );

		if ( ! OW_Languages::is_valid( $lang ) ) wp_die( 'Invalid language' );
		if ( empty( $_FILES['po_file']['tmp_name'] ) ) wp_die( 'No file' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tmp_name = sanitize_text_field( wp_unslash( $_FILES['po_file']['tmp_name'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$content = file_get_contents( $tmp_name );
		if ( strlen( $content ) > 10 * 1024 * 1024 ) wp_die( 'File too large (max 10MB)' );

		$po      = new OW_PO();
		$entries = $po->parse( $content );
		$count   = 0;

		foreach ( $entries as $entry ) {
			if ( empty( $entry['msgid'] ) ) continue;

			// Handle plural forms from PO
			$plural_forms = $entry['msgstr_plural'] ?? null;

			OW_DB::upsert(
				$lang, $domain, $entry['msgid'],
				$entry['msgstr'] ?? '',
				$entry['context'] ?? null,
				'imported', $domain, null,
				$entry['msgid_plural'] ?? null,
				$plural_forms
			);
			$count++;
		}

		set_transient( 'ow_last_scan_result', sprintf(
			/* translators: 1: translations count, 2: language code, 3: text domain */
			__( 'Imported %1$d translations for %2$s (%3$s).', 'open-world' ),
			$count, $lang, $domain
		), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=ow-settings' ) );
		exit;
	}

	// ── Admin POST: Export PO ─────────────────────────────────────────────────

	public function handle_export_po(): void {
		check_admin_referer( 'ow_export_po' );
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

		$lang   = sanitize_key( wp_unslash( $_GET['lang'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$domain = sanitize_text_field( wp_unslash( $_GET['domain'] ?? 'default' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! OW_Languages::is_valid( $lang ) ) wp_die( 'Invalid language' );

		$po      = new OW_PO();
		$content = $po->export( $lang, $domain );
		$locale  = OW_Languages::get_locale( $lang );
		$fname   = $domain . '-' . $locale . '.po';

		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( $fname ) . '"' );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// ── Reusable lang action LINK (not form — forms in <td> are invalid HTML) ─────

	private function lang_action_link( string $lang, string $ow_action, string $label, bool $is_current ): void {
		if ( $is_current ) return;
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=ow_lang_action&ow_action=' . $ow_action . '&lang_code=' . rawurlencode( $lang ) ),
			'ow_lang_action'
		);
		printf(
			'<a href="%s" class="button button-small" style="margin-right:3px">%s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	// ── Plural form helpers ───────────────────────────────────────────────────

	private function get_num_plural_forms( string $lang ): int {
		// Languages with 3 plural forms
		if ( in_array( $lang, [ 'pl', 'ru', 'uk', 'cs', 'sk' ], true ) ) return 3;
		// Arabic: 6 forms
		if ( $lang === 'ar' ) return 6;
		// Default: 2 (singular + plural)
		return 2;
	}

	private function get_plural_label( string $lang, int $form ): string {
		$labels = [
			'pl' => [ 'Singular (1)', 'Few (2-4)', 'Many (5+)' ],
			'ru' => [ 'Singular (1)', 'Few (2-4)', 'Many (5+)' ],
			'uk' => [ 'Singular (1)', 'Few (2-4)', 'Many (5+)' ],
			'cs' => [ 'Singular (1)', 'Few (2-4)', 'Many (5+)' ],
			'ar' => [ 'Zero', 'Singular', 'Dual', 'Few', 'Many', 'Other' ],
		];
		return $labels[ $lang ][ $form ] ?? ( $form === 0 ? 'Singular' : 'Plural' );
	}
}
