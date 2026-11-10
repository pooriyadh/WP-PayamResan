<?php
/**
 * Admin settings pages, tabs, and save handlers.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan\Admin;

use WP_PayamResan\Dispatcher;
use WP_PayamResan\Logger;
use WP_PayamResan\Meta_Resolver;
use WP_PayamResan\Post_Type_Detector;
use WP_PayamResan\Settings;
use WP_PayamResan\Template_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the WP PayamResan admin dashboard and settings tabs.
 */
class Admin_Menu {

	/**
	 * Dispatcher instance.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Constructor.
	 *
	 * @param Dispatcher $dispatcher Dispatcher instance.
	 */
	public function __construct( Dispatcher $dispatcher ) {
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Register WordPress admin menu, save handlers, and assets.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_wp_payamresan_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . WP_PAYAMRESAN_BASENAME, array( $this, 'add_plugin_action_links' ) );
	}

	/**
	 * Add top-level menu and submenus.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			'WP PayamResan',
			'WP PayamResan',
			'manage_options',
			'wp-payamresan',
			array( $this, 'render_page' ),
			'dashicons-megaphone',
			80
		);

		add_submenu_page(
			'wp-payamresan',
			'تنظیمات WP PayamResan',
			'تنظیمات افزونه',
			'manage_options',
			'wp-payamresan',
			array( $this, 'render_page' )
		);

		add_submenu_page(
			'wp-payamresan',
			'ارسال دستی — WP PayamResan',
			'ارسال دستی',
			'manage_options',
			'wp-payamresan-manual',
			array( $this, 'render_manual_redirect_page' )
		);

		add_submenu_page(
			'wp-payamresan',
			'گزارش ارسال — WP PayamResan',
			'گزارش ارسال',
			'manage_options',
			'wp-payamresan-logs',
			array( $this, 'render_logs_redirect_page' )
		);
	}

	/**
	 * Add Settings link in the WordPress Plugins list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_plugin_action_links( array $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wp-payamresan' ) ),
			esc_html( 'تنظیمات' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Enqueue plugin CSS and JS only on relevant admin pages.
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$allowed_screens = array(
			'toplevel_page_wp-payamresan',
			'wp-payamresan_page_wp-payamresan-manual',
			'wp-payamresan_page_wp-payamresan-logs',
			'post.php',
			'post-new.php',
		);

		if ( ! in_array( $hook_suffix, $allowed_screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'wp-payamresan-admin',
			WP_PAYAMRESAN_URL . 'assets/css/admin.css',
			array(),
			WP_PAYAMRESAN_VERSION
		);

		wp_enqueue_script(
			'wp-payamresan-admin',
			WP_PAYAMRESAN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			WP_PAYAMRESAN_VERSION,
			true
		);

		wp_localize_script(
			'wp-payamresan-admin',
			'wpPayamResanAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_payamresan_admin' ),
				'i18n'    => array(
					'testing'         => 'در حال بررسی اتصال و مجوزهای کانال...',
					'sendingTest'     => 'در حال ارسال پیام آزمایشی...',
					'sendingPost'     => 'در حال ارسال پیام به کانال...',
					'loadingPreview'  => 'در حال آماده‌سازی پیش‌نمایش پیام...',
					'loadingMeta'     => 'در حال دریافت متادیتاها...',
					'confirmClearLog' => 'آیا از پاک کردن تمام گزارش‌های ارسال مطمئن هستید؟',
					'networkError'    => 'خطا در ارتباط با سرور وردپرس.',
				),
			)
		);
	}

	/**
	 * Render Manual Send submenu page directly using the shared layout.
	 *
	 * @return void
	 */
	public function render_manual_redirect_page() {
		$this->render_page( 'manual' );
	}

	/**
	 * Render Logs submenu page directly using the shared layout.
	 *
	 * @return void
	 */
	public function render_logs_redirect_page() {
		$this->render_page( 'logs' );
	}

	/**
	 * Save submitted settings tab cleanly.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز.' );
		}

		check_admin_referer( 'wp_payamresan_save_settings', 'wp_payamresan_nonce' );

		$tab = isset( $_POST['wp_payamresan_tab'] ) ? sanitize_key( wp_unslash( $_POST['wp_payamresan_tab'] ) ) : 'general';
		$raw = isset( $_POST['wp_payamresan'] ) && is_array( $_POST['wp_payamresan'] ) ? $_POST['wp_payamresan'] : array();

		$payload = array();
		if ( in_array( $tab, array( 'general', 'telegram', 'bale', 'post_types' ), true ) ) {
			$payload[ $tab ] = isset( $raw[ $tab ] ) && is_array( $raw[ $tab ] ) ? $raw[ $tab ] : array();
		}

		Settings::update( $payload );

		$redirect = add_query_arg(
			array(
				'page'             => 'wp-payamresan',
				'tab'              => $tab,
				'settings-updated' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the main plugin admin page with modern navigation and hero header.
	 *
	 * @param string $forced_tab Optional tab override.
	 * @return void
	 */
	public function render_page( $forced_tab = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tg_enabled    = (bool) Settings::get( 'telegram', 'enabled', false );
		$bl_enabled    = (bool) Settings::get( 'bale', 'enabled', false );
		$enabled_cpts  = Post_Type_Detector::get_enabled_post_type_slugs();
		$total_logs    = Logger::count_logs();
		$updated       = ! empty( $_GET['settings-updated'] );

		$tabs = array(
			'general'    => array(
				'label' => 'داشبورد و تنظیمات عمومی',
				'icon'  => 'dashboard',
			),
			'telegram'   => array(
				'label' => 'تلگرام',
				'icon'  => 'telegram',
				'dot'   => $tg_enabled ? 'on' : 'off',
			),
			'bale'       => array(
				'label' => 'بله',
				'icon'  => 'bale',
				'dot'   => $bl_enabled ? 'on' : 'off',
			),
			'post_types' => array(
				'label' => 'پست تایپ‌ها و متادیتا',
				'icon'  => 'layers',
				'badge' => count( $enabled_cpts ),
			),
			'manual'     => array(
				'label' => 'ارسال دستی',
				'icon'  => 'send',
			),
			'logs'       => array(
				'label' => 'گزارش ارسال',
				'icon'  => 'logs',
				'badge' => $total_logs > 0 ? $total_logs : null,
			),
		);

		$active_tab = '' !== $forced_tab
			? $forced_tab
			: ( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general' );

		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'general';
		}

		?>
		<div class="wrap wppr-wrap" dir="rtl">
			<div class="wppr-shell">
				<header class="wppr-hero">
					<div class="wppr-hero-brand">
						<div class="wppr-hero-logo" aria-hidden="true">
							<?php echo $this->get_svg_icon( 'brand' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<div class="wppr-hero-text">
							<div class="wppr-hero-title-row">
								<h1>WP PayamResan</h1>
								<span class="wppr-version-chip">نسخه <?php echo esc_html( WP_PAYAMRESAN_VERSION ); ?></span>
								<?php if ( function_exists( 'jet_engine' ) ) : ?>
									<span class="wppr-compat-chip">سازگار با JetEngine</span>
								<?php endif; ?>
							</div>
							<p class="wppr-hero-subtitle">مدیریت هوشمند انتشار خودکار و دستی انواع پست تایپ‌ها و متادیتاها در تلگرام و بله</p>
						</div>
					</div>

					<div class="wppr-hero-status">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-payamresan&tab=telegram' ) ); ?>"
							class="wppr-hero-pill <?php echo $tg_enabled ? 'is-on is-telegram' : 'is-off'; ?>">
							<span class="wppr-pill-icon"><?php echo $this->get_svg_icon( 'telegram' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="wppr-pill-label">تلگرام</span>
							<span class="wppr-pill-state"><?php echo $tg_enabled ? 'متصل و فعال' : 'غیرفعال'; ?></span>
						</a>

						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-payamresan&tab=bale' ) ); ?>"
							class="wppr-hero-pill <?php echo $bl_enabled ? 'is-on is-bale' : 'is-off'; ?>">
							<span class="wppr-pill-icon"><?php echo $this->get_svg_icon( 'bale' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="wppr-pill-label">بله</span>
							<span class="wppr-pill-state"><?php echo $bl_enabled ? 'متصل و فعال' : 'غیرفعال'; ?></span>
						</a>
					</div>
				</header>

				<?php if ( $updated ) : ?>
					<div class="wppr-toast-notice" role="status">
						<span class="wppr-toast-icon"><?php echo $this->get_svg_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span>تنظیمات افزونه <strong>WP PayamResan</strong> با موفقیت ذخیره و اعمال شد.</span>
					</div>
				<?php endif; ?>

				<nav class="wppr-nav" aria-label="بخش‌های WP PayamResan">
					<?php foreach ( $tabs as $tab_slug => $tab_info ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-payamresan&tab=' . $tab_slug ) ); ?>"
							class="wppr-nav-item <?php echo $active_tab === $tab_slug ? 'is-active' : ''; ?>">
							<span class="wppr-nav-icon"><?php echo $this->get_svg_icon( $tab_info['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span class="wppr-nav-label"><?php echo esc_html( $tab_info['label'] ); ?></span>
							<?php if ( isset( $tab_info['dot'] ) ) : ?>
								<span class="wppr-nav-dot <?php echo 'on' === $tab_info['dot'] ? 'is-on' : 'is-off'; ?>"></span>
							<?php endif; ?>
							<?php if ( isset( $tab_info['badge'] ) && null !== $tab_info['badge'] ) : ?>
								<span class="wppr-nav-badge"><?php echo esc_html( $tab_info['badge'] ); ?></span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<main class="wppr-main">
					<?php
					switch ( $active_tab ) {
						case 'telegram':
							$this->render_telegram_tab();
							break;
						case 'bale':
							$this->render_bale_tab();
							break;
						case 'post_types':
							$this->render_post_types_tab();
							break;
						case 'manual':
							$this->render_manual_tab();
							break;
						case 'logs':
							$this->render_logs_tab();
							break;
						default:
							$this->render_general_tab();
							break;
					}
					?>
				</main>

				<footer class="wppr-footer">
					<div class="wppr-footer-right">
						<span class="wppr-footer-brand">WP PayamResan v<?php echo esc_html( WP_PAYAMRESAN_VERSION ); ?></span>
						<span class="wppr-footer-sep">•</span>
						<span>توسعه‌دهنده: <strong>پوریا دایی حسینی</strong> (<a href="mailto:pouriadaeihosseini@gmail.com">pouriadaeihosseini@gmail.com</a>)</span>
					</div>
					<div class="wppr-footer-left">
						<?php if ( function_exists( 'jet_engine' ) ) : ?>
							<span class="wppr-ecosystem-tag is-active">JetEngine فعال</span>
						<?php endif; ?>
						<?php if ( function_exists( 'acf_get_field_groups' ) ) : ?>
							<span class="wppr-ecosystem-tag is-active">ACF فعال</span>
						<?php endif; ?>
						<?php if ( function_exists( 'rwmb_get_object_fields' ) ) : ?>
							<span class="wppr-ecosystem-tag is-active">Meta Box فعال</span>
						<?php endif; ?>
						<span class="wppr-ecosystem-tag">فونت وزیرمتن</span>
					</div>
				</footer>
			</div>
		</div>
		<?php
	}

	/**
	 * Render General Settings & Status Dashboard tab.
	 *
	 * @return void
	 */
	private function render_general_tab() {
		$general       = Settings::get( 'general', '', array() );
		$tg            = Settings::get( 'telegram', '', array() );
		$bale          = Settings::get( 'bale', '', array() );
		$tg_enabled    = ! empty( $tg['enabled'] );
		$bl_enabled    = ! empty( $bale['enabled'] );
		$tg_channels   = Settings::parse_channels( isset( $tg['channel_ids'] ) ? $tg['channel_ids'] : '' );
		$bl_channels   = Settings::parse_channels( isset( $bale['channel_ids'] ) ? $bale['channel_ids'] : '' );
		$all_cpts      = Post_Type_Detector::get_available_post_types();
		$enabled_cpts  = Post_Type_Detector::get_enabled_post_type_slugs();
		$success_count = Logger::count_logs( array( 'status' => 'success' ) );
		$error_count   = Logger::count_logs( array( 'status' => 'error' ) );
		?>
		<div class="wppr-kpi-grid">
			<div class="wppr-kpi-card">
				<div class="wppr-kpi-icon is-blue"><?php echo $this->get_svg_icon( 'layers' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="wppr-kpi-body">
					<span class="wppr-kpi-label">پست تایپ‌های شناسایی‌شده</span>
					<strong class="wppr-kpi-value"><?php echo esc_html( count( $enabled_cpts ) . ' فعال از ' . count( $all_cpts ) ); ?></strong>
					<span class="wppr-kpi-hint">پست، برگه، محصولات و CPTها</span>
				</div>
			</div>

			<div class="wppr-kpi-card">
				<div class="wppr-kpi-icon is-emerald"><?php echo $this->get_svg_icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="wppr-kpi-body">
					<span class="wppr-kpi-label">ارسال‌های موفق</span>
					<strong class="wppr-kpi-value"><?php echo esc_html( number_format_i18n( $success_count ) ); ?></strong>
					<span class="wppr-kpi-hint">پیام تحویل‌شده به کانال‌ها</span>
				</div>
			</div>

			<div class="wppr-kpi-card">
				<div class="wppr-kpi-icon is-rose"><?php echo $this->get_svg_icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="wppr-kpi-body">
					<span class="wppr-kpi-label">خطاهای ثبت‌شده</span>
					<strong class="wppr-kpi-value"><?php echo esc_html( number_format_i18n( $error_count ) ); ?></strong>
					<span class="wppr-kpi-hint"><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-payamresan&tab=logs' ) ); ?>">بررسی در گزارش ارسال</a></span>
				</div>
			</div>

			<div class="wppr-kpi-card">
				<div class="wppr-kpi-icon is-violet"><?php echo $this->get_svg_icon( 'spark' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="wppr-kpi-body">
					<span class="wppr-kpi-label">وضعیت متاباکس و جت‌انجین</span>
					<strong class="wppr-kpi-value"><?php echo function_exists( 'jet_engine' ) ? 'JetEngine متصل' : 'آماده شناسایی'; ?></strong>
					<span class="wppr-kpi-hint">خواندن خودکار متادیتاها</span>
				</div>
			</div>
		</div>

		<div class="wppr-messenger-cards">
			<div class="wppr-channel-card is-telegram">
				<div class="wppr-channel-card-top">
					<div class="wppr-channel-identity">
						<span class="wppr-channel-avatar is-telegram"><?php echo $this->get_svg_icon( 'telegram' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div>
							<h3>پیام‌رسان تلگرام (Telegram)</h3>
							<span class="wppr-channel-meta">
								<?php
								if ( ! $tg_enabled ) {
									echo 'در حال حاضر غیرفعال است';
								} elseif ( empty( $tg_channels ) ) {
									echo 'فعال — بدون کانال پیش‌فرض';
								} else {
									echo esc_html( count( $tg_channels ) . ' کانال پیکربندی‌شده (' . implode( '، ', array_slice( $tg_channels, 0, 2 ) ) . ')' );
								}
								?>
							</span>
						</div>
					</div>
					<span class="wppr-badge <?php echo $tg_enabled ? 'wppr-badge-success' : 'wppr-badge-muted'; ?>">
						<?php echo $tg_enabled ? 'فعال' : 'غیرفعال'; ?>
					</span>
				</div>
				<div class="wppr-channel-card-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-payamresan&tab=telegram' ) ); ?>" class="wppr-btn wppr-btn-outline">تنظیمات و تست اتصال تلگرام</a>
				</div>
			</div>

			<div class="wppr-channel-card is-bale">
				<div class="wppr-channel-card-top">
					<div class="wppr-channel-identity">
						<span class="wppr-channel-avatar is-bale"><?php echo $this->get_svg_icon( 'bale' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div>
							<h3>پیام‌رسان بله (Bale)</h3>
							<span class="wppr-channel-meta">
								<?php
								if ( ! $bl_enabled ) {
									echo 'در حال حاضر غیرفعال است';
								} elseif ( empty( $bl_channels ) ) {
									echo 'فعال — بدون کانال پیش‌فرض';
								} else {
									echo esc_html( count( $bl_channels ) . ' کانال پیکربندی‌شده (' . implode( '، ', array_slice( $bl_channels, 0, 2 ) ) . ')' );
								}
								?>
							</span>
						</div>
					</div>
					<span class="wppr-badge <?php echo $bl_enabled ? 'wppr-badge-success' : 'wppr-badge-muted'; ?>">
						<?php echo $bl_enabled ? 'فعال' : 'غیرفعال'; ?>
					</span>
				</div>
				<div class="wppr-channel-card-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-payamresan&tab=bale' ) ); ?>" class="wppr-btn wppr-btn-outline">تنظیمات و تست اتصال بله</a>
				</div>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wppr-card">
			<input type="hidden" name="action" value="wp_payamresan_save_settings" />
			<input type="hidden" name="wp_payamresan_tab" value="general" />
			<?php wp_nonce_field( 'wp_payamresan_save_settings', 'wp_payamresan_nonce' ); ?>

			<div class="wppr-card-head">
				<div>
					<h2>تنظیمات عمومی ارسال و زمان‌بندی</h2>
					<p class="wppr-card-desc">پیکربندی نحوه همگام‌سازی متاباکس‌ها، دکمه‌های پیام، محدودیت طول متن و نگهداری داده‌ها.</p>
				</div>
			</div>

			<div class="wppr-form-rows">
				<div class="wppr-form-row">
					<div class="wppr-form-label">
						<label for="wppr-auto-mode">زمان‌بندی ارسال خودکار</label>
						<span class="wppr-field-hint">نحوه انتظار برای ذخیره متاباکس‌های گوتنبرگ و جت‌انجین</span>
					</div>
					<div class="wppr-form-control">
						<select id="wppr-auto-mode" name="wp_payamresan[general][auto_send_mode]" class="wppr-select">
							<option value="smart" <?php selected( $general['auto_send_mode'], 'smart' ); ?>>هوشمند — همگام با متاباکس‌ها، JetEngine و ویرایشگر گوتنبرگ (پیشنهادی)</option>
							<option value="cron" <?php selected( $general['auto_send_mode'], 'cron' ); ?>>زمان‌بندی با تأخیر WP-Cron</option>
							<option value="immediate" <?php selected( $general['auto_send_mode'], 'immediate' ); ?>>فوری هم‌زمان در save_post</option>
						</select>
						<p class="wppr-help-text">حالت «هوشمند» صبر می‌کند تا تمام متادیتاهای جت‌انجین، ACF و متاباکس‌ها (حتی در گوتنبرگ) ذخیره شوند و سپس پیام را ارسال می‌کند.</p>
					</div>
				</div>

				<div class="wppr-form-row">
					<div class="wppr-form-label">
						<label for="wppr-cron-delay">تأخیر WP-Cron (ثانیه)</label>
						<span class="wppr-field-hint">فقط در حالت زمان‌بندی با WP-Cron</span>
					</div>
					<div class="wppr-form-control">
						<input id="wppr-cron-delay" type="number" min="3" max="300" class="wppr-input wppr-input-sm ltr"
							name="wp_payamresan[general][cron_delay]"
							value="<?php echo esc_attr( $general['cron_delay'] ); ?>" />
						<span class="wppr-unit">ثانیه</span>
					</div>
				</div>

				<div class="wppr-form-row">
					<div class="wppr-form-label">
						<span>شناسایی پست تایپ‌های پیشرفته</span>
						<span class="wppr-field-hint">پشتیبانی از CPTهای غیرعمومی جت‌انجین و افزونه‌ها</span>
					</div>
					<div class="wppr-form-control">
						<label class="wppr-check-card">
							<input type="checkbox" name="wp_payamresan[general][include_non_public_cpts]" value="1" <?php checked( ! empty( $general['include_non_public_cpts'] ) ); ?> />
							<span>نمایش پست تایپ‌های غیرعمومی که دارای رابط کاربری مدیریت (<code>show_ui</code>) هستند</span>
						</label>
					</div>
				</div>

				<div class="wppr-form-row">
					<div class="wppr-form-label">
						<span>دکمه شیشه‌ای مشاهده مطلب</span>
						<span class="wppr-field-hint">افزودن دکمه لینک اینلاین زیر پیام</span>
					</div>
					<div class="wppr-form-control">
						<label class="wppr-check-card">
							<input type="checkbox" name="wp_payamresan[general][show_read_more_button]" value="1" <?php checked( ! empty( $general['show_read_more_button'] ) ); ?> />
							<span>افزودن دکمه اینلاین لینک به مطلب در پیام‌ها (تلگرام و بله)</span>
						</label>
						<div class="wppr-inline-subfield">
							<label for="wppr-btn-text">متن روی دکمه:</label>
							<input id="wppr-btn-text" type="text" class="wppr-input wppr-input-md"
								name="wp_payamresan[general][read_more_button_text]"
								value="<?php echo esc_attr( $general['read_more_button_text'] ); ?>" />
						</div>
					</div>
				</div>

				<div class="wppr-form-row">
					<div class="wppr-form-label">
						<label for="wppr-excerpt-len">طول خلاصه و متن کامل</label>
						<span class="wppr-field-hint">تعداد کلمات مجاز در متغیرهای متن</span>
					</div>
					<div class="wppr-form-control wppr-inline-group">
						<div class="wppr-mini-field">
							<label for="wppr-excerpt-len">خلاصه (<code>{post_excerpt}</code>):</label>
							<input id="wppr-excerpt-len" type="number" min="5" max="300" class="wppr-input wppr-input-sm ltr"
								name="wp_payamresan[general][excerpt_length]"
								value="<?php echo esc_attr( $general['excerpt_length'] ); ?>" />
						</div>
						<div class="wppr-mini-field">
							<label for="wppr-content-len">متن کامل (<code>{post_content}</code>):</label>
							<input id="wppr-content-len" type="number" min="20" max="2000" class="wppr-input wppr-input-sm ltr"
								name="wp_payamresan[general][content_length]"
								value="<?php echo esc_attr( $general['content_length'] ); ?>" />
						</div>
					</div>
				</div>

				<div class="wppr-form-row">
					<div class="wppr-form-label">
						<label for="wppr-log-limit">سقف نگهداری گزارش‌ها</label>
						<span class="wppr-field-hint">پاکسازی خودکار رکوردهای قدیمی‌تر</span>
					</div>
					<div class="wppr-form-control">
						<input id="wppr-log-limit" type="number" min="50" max="10000" class="wppr-input wppr-input-sm ltr"
							name="wp_payamresan[general][log_retention_limit]"
							value="<?php echo esc_attr( $general['log_retention_limit'] ); ?>" />
						<span class="wppr-unit">رکورد اخیر</span>
					</div>
				</div>

				<div class="wppr-form-row">
					<div class="wppr-form-label">
						<span>پاکسازی هنگام حذف افزونه</span>
						<span class="wppr-field-hint">حذف کامل جدول دیتابیس و تنظیمات در Uninstall</span>
					</div>
					<div class="wppr-form-control">
						<label class="wppr-check-card">
							<input type="checkbox" name="wp_payamresan[general][delete_data_on_uninstall]" value="1" <?php checked( ! empty( $general['delete_data_on_uninstall'] ) ); ?> />
							<span>حذف کامل تنظیمات، جدول گزارش‌ها و متادیتاهای افزونه هنگام حذف (Uninstall)</span>
						</label>
					</div>
				</div>
			</div>

			<div class="wppr-card-footer">
				<button type="submit" class="wppr-btn wppr-btn-primary">ذخیره تنظیمات عمومی</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Render Telegram Messenger configuration and test tool tab.
	 *
	 * @return void
	 */
	private function render_telegram_tab() {
		$tg = Settings::get( 'telegram', '', array() );
		?>
		<div class="wppr-two-col">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wppr-card wppr-card-telegram">
				<input type="hidden" name="action" value="wp_payamresan_save_settings" />
				<input type="hidden" name="wp_payamresan_tab" value="telegram" />
				<?php wp_nonce_field( 'wp_payamresan_save_settings', 'wp_payamresan_nonce' ); ?>

				<div class="wppr-card-head">
					<div class="wppr-head-with-icon">
						<span class="wppr-channel-avatar is-telegram"><?php echo $this->get_svg_icon( 'telegram' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div>
							<h2>تنظیمات اتصال تلگرام</h2>
							<p class="wppr-card-desc">پیکربندی توکن ربات، کانال‌های مقصد و نحوه ارسال رسانه</p>
						</div>
					</div>
					<label class="wppr-switch">
						<input type="checkbox" name="wp_payamresan[telegram][enabled]" value="1" <?php checked( ! empty( $tg['enabled'] ) ); ?> />
						<span class="wppr-switch-slider"></span>
						<span class="wppr-switch-text">فعال‌سازی تلگرام</span>
					</label>
				</div>

				<div class="wppr-form-rows">
					<div class="wppr-form-row">
						<div class="wppr-form-label">
							<label for="wppr-tg-token">توکن ربات تلگرام</label>
							<span class="wppr-field-hint">دریافتی از <code>@BotFather</code></span>
						</div>
						<div class="wppr-form-control">
							<div class="wppr-token-field">
								<input id="wppr-tg-token" type="password" class="wppr-input ltr" autocomplete="off"
									name="wp_payamresan[telegram][bot_token]"
									value="<?php echo esc_attr( $tg['bot_token'] ); ?>"
									placeholder="123456789:AAH..." />
								<button type="button" class="wppr-btn wppr-btn-outline wppr-toggle-secret" data-target="#wppr-tg-token">نمایش</button>
							</div>
						</div>
					</div>

					<div class="wppr-form-row">
						<div class="wppr-form-label">
							<label for="wppr-tg-channels">شناسه کانال یا گروه تلگرام</label>
							<span class="wppr-field-hint">امکان وارد کردن چند کانال با کاما یا خط جدید</span>
						</div>
						<div class="wppr-form-control">
							<textarea id="wppr-tg-channels" rows="3" class="wppr-textarea ltr"
								name="wp_payamresan[telegram][channel_ids]"
								placeholder="@my_channel یا -1001234567890"><?php echo esc_textarea( $tg['channel_ids'] ); ?></textarea>
							<p class="wppr-help-text">نام کاربری کانال (مانند <code>@channel</code>) یا شناسه عددی (مانند <code>-1001234567890</code>).</p>
						</div>
					</div>

					<div class="wppr-form-row">
						<div class="wppr-form-label">
							<label for="wppr-tg-base">آدرس پایه API تلگرام</label>
							<span class="wppr-field-hint">قابل تغییر به پروکسی یا Cloudflare Worker</span>
						</div>
						<div class="wppr-form-control">
							<input id="wppr-tg-base" type="url" class="wppr-input ltr"
								name="wp_payamresan[telegram][api_base_url]"
								value="<?php echo esc_attr( $tg['api_base_url'] ); ?>" />
							<p class="wppr-help-text">پیش‌فرض: <code>https://api.telegram.org</code></p>
						</div>
					</div>

					<div class="wppr-form-row">
						<div class="wppr-form-label">
							<label for="wppr-tg-parse">فرمت‌دهی پیام</label>
						</div>
						<div class="wppr-form-control">
							<select id="wppr-tg-parse" name="wp_payamresan[telegram][parse_mode]" class="wppr-select">
								<option value="HTML" <?php selected( $tg['parse_mode'], 'HTML' ); ?>>HTML (پیشنهادی — پشتیبانی از بولد، لینک و...)</option>
								<option value="none" <?php selected( $tg['parse_mode'], 'none' ); ?>>متن ساده (Plain Text)</option>
							</select>
						</div>
					</div>

					<div class="wppr-form-row">
						<div class="wppr-form-label">
							<label for="wppr-tg-overflow">مدیریت کپشن طولانی تصویر</label>
							<span class="wppr-field-hint">برای متن‌های بالای ۱۰۲۴ کاراکتر</span>
						</div>
						<div class="wppr-form-control">
							<select id="wppr-tg-overflow" name="wp_payamresan[telegram][caption_overflow_mode]" class="wppr-select">
								<option value="split" <?php selected( $tg['caption_overflow_mode'], 'split' ); ?>>ارسال تصویر با عنوان + ارسال متن کامل در پیام بعدی (پیشنهادی)</option>
								<option value="truncate" <?php selected( $tg['caption_overflow_mode'], 'truncate' ); ?>>برش خودکار متن کپشن تا ۱۰۲۴ کاراکتر</option>
								<option value="text_only_if_long" <?php selected( $tg['caption_overflow_mode'], 'text_only_if_long' ); ?>>ارسال فقط به صورت متنی اگر پیام طولانی بود</option>
							</select>
						</div>
					</div>

					<div class="wppr-form-row">
						<div class="wppr-form-label">
							<span>گزینه‌های رسانه و ارسال</span>
						</div>
						<div class="wppr-form-control wppr-check-stack">
							<label class="wppr-check-card">
								<input type="checkbox" name="wp_payamresan[telegram][upload_local_media]" value="1" <?php checked( ! empty( $tg['upload_local_media'] ) ); ?> />
								<span>آپلود مستقیم فایل تصویر از هاست (مناسب برای سایت‌های پشت فایروال یا کلودفلر)</span>
							</label>
							<label class="wppr-check-card">
								<input type="checkbox" name="wp_payamresan[telegram][fallback_to_text]" value="1" <?php checked( ! empty( $tg['fallback_to_text'] ) ); ?> />
								<span>ارسال متنی در صورت خطای ارسال تصویر</span>
							</label>
							<label class="wppr-check-card">
								<input type="checkbox" name="wp_payamresan[telegram][disable_web_page_preview]" value="1" <?php checked( ! empty( $tg['disable_web_page_preview'] ) ); ?> />
								<span>غیرفعال کردن پیش‌نمایش لینک وب در پیام‌های متنی</span>
							</label>
							<label class="wppr-check-card">
								<input type="checkbox" name="wp_payamresan[telegram][disable_notification]" value="1" <?php checked( ! empty( $tg['disable_notification'] ) ); ?> />
								<span>ارسال بی‌صدا (Silent Notification)</span>
							</label>
						</div>
					</div>
				</div>

				<div class="wppr-card-footer">
					<button type="submit" class="wppr-btn wppr-btn-primary">ذخیره تنظیمات تلگرام</button>
				</div>
			</form>

			<div class="wppr-card wppr-test-box">
				<div class="wppr-card-head">
					<div>
						<h2>تست اتصال و پیام آزمایشی تلگرام</h2>
						<p class="wppr-card-desc">بررسی اعتبار توکن، ادمین بودن ربات در کانال و ارسال پیام تست زنده (حتی قبل از ذخیره).</p>
					</div>
				</div>

				<div class="wppr-test-actions">
					<button type="button"
						class="wppr-btn wppr-btn-outline wppr-btn-block wppr-test-connection-btn"
						data-messenger="telegram"
						data-token-input="#wppr-tg-token"
						data-channels-input="#wppr-tg-channels"
						data-base-input="#wppr-tg-base"
						data-output="#wppr-tg-test-output">
						بررسی اتصال ربات و مجوزهای کانال
					</button>
				</div>

				<div class="wppr-divider"><span>ارسال پیام آزمایشی به کانال</span></div>

				<p class="wppr-field-group">
					<label for="wppr-tg-test-msg"><strong>متن پیام تستی (اختیاری):</strong></label>
					<textarea id="wppr-tg-test-msg" rows="3" class="wppr-textarea" placeholder="در صورت خالی بودن، یک پیام تست پیش‌فرض همراه با تاریخ ارسال می‌شود."></textarea>
				</p>

				<button type="button"
					class="wppr-btn wppr-btn-telegram wppr-btn-block wppr-send-test-btn"
					data-messenger="telegram"
					data-token-input="#wppr-tg-token"
					data-channels-input="#wppr-tg-channels"
					data-base-input="#wppr-tg-base"
					data-message-input="#wppr-tg-test-msg"
					data-output="#wppr-tg-test-output">
					ارسال پیام تستی به کانال تلگرام
				</button>

				<div id="wppr-tg-test-output" class="wppr-test-output" aria-live="polite"></div>

				<div class="wppr-help-box">
					<h4>راهنمای سریع راه‌اندازی ربات تلگرام</h4>
					<ol>
						<li>در تلگرام وارد <code>@BotFather</code> شوید و با دستور <code>/newbot</code> یک ربات بسازید.</li>
						<li>توکن دریافتی را در کادر بالا وارد کنید.</li>
						<li>ربات را به عنوان <strong>Administrator</strong> (با دسترسی Post Messages) به کانال اضافه کنید.</li>
						<li>یوزرنیم کانال (مثل <code>@mychannel</code>) یا آیدی عددی کانال خصوصی را وارد کرده و تست را بزنید.</li>
					</ol>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Bale Messenger configuration and test tool tab.
	 *
	 * @return void
	 */
	private function render_bale_tab() {
		$bale = Settings::get( 'bale', '', array() );
		?>
		<div class="wppr-two-col">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wppr-card wppr-card-bale">
				<input type="hidden" name="action" value="wp_payamresan_save_settings" />
				<input type="hidden" name="wp_payamresan_tab" value="bale" />
				<?php wp_nonce_field( 'wp_payamresan_save_settings', 'wp_payamresan_nonce' ); ?>

				<div class="wppr-card-head">
					<div class="wppr-head-with-icon">
						<span class="wppr-channel-avatar is-bale"><?php echo $this->get_svg_icon( 'bale' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div>
							<h2>تنظیمات اتصال پیام‌رسان بله</h2>
							<p class="wppr-card-desc">پیکربندی توکن بازو، کانال‌های بله و فرمت‌بندی Markdown</p>
						</div>
					</div>
					<label class="wppr-switch">
						<input type="checkbox" name="wp_payamresan[bale][enabled]" value="1" <?php checked( ! empty( $bale['enabled'] ) ); ?> />
						<span class="wppr-switch-slider"></span>
						<span class="wppr-switch-text">فعال‌سازی بله</span>
					</label>
				</div>

				<div class="wppr-form-rows">
					<div class="wppr-form-row">
						<div class="wppr-form-label">
							<label for="wppr-bale-token">توکن بازوی بله</label>
							<span class="wppr-field-hint">دریافتی از <code>@botfather</code> در بله</span>
						</div>
						<div class="wppr-form-control">
							<div class="wppr-token-field">
								<input id="wppr-bale-token" type="password" class="wppr-input ltr" autocomplete="off"
									name="wp_payamresan[bale][bot_token]"
									value="<?php echo esc_attr( $bale['bot_token'] ); ?>"
									placeholder="123456789:abcd..." />
								<button type="button" class="wppr-btn wppr-btn-outline wppr-toggle-secret" data-target="#wppr-bale-token">نمایش</button>
							</div>
						</div>
					</div>

					<div class="wppr-form-row">
						<div class="wppr-form-label">
							<label for="wppr-bale-channels">شناسه کانال یا گفت‌وگوی بله</label>
							<span class="wppr-field-hint">امکان وارد کردن چند مقصد با خط جدید یا کاما</span>
						</div>
						<div class="wppr-form-control">
							<textarea id="wppr-bale-channels" rows="3" class="wppr-textarea ltr"
								name="wp_payamresan[bale][channel_ids]"
								placeholder="@my_bale_channel یا شناسه عددی چت"><?php echo esc_textarea( $bale['channel_ids'] ); ?></textarea>
							<p class="wppr-help-text">نام کاربری کانال بله (مثل <code>@channel</code>) یا شناسه عددی گفتگو.</p>
						</div>
					</div>

					<div class="wppr-form-row">
						<div class="wppr-form-label">
							<label for="wppr-bale-base">آدرس پایه API بله</label>
						</div>
						<div class="wppr-form-control">
							<input id="wppr-bale-base" type="url" class="wppr-input ltr"
								name="wp_payamresan[bale][api_base_url]"
								value="<?php echo esc_attr( $bale['api_base_url'] ); ?>" />
							<p class="wppr-help-text">آدرس رسمی بله: <code>https://tapi.bale.ai</code></p>
						</div>
					</div>

					<div class="wppr-form-row">
						<div class="wppr-form-label">
							<label for="wppr-bale-format">قالب‌بندی متن در بله</label>
						</div>
						<div class="wppr-form-control">
							<select id="wppr-bale-format" name="wp_payamresan[bale][format_mode]" class="wppr-select">
								<option value="markdown" <?php selected( $bale['format_mode'], 'markdown' ); ?>>تبدیل خودکار به Markdown بله (بولد و لینک استاندارد بله)</option>
								<option value="plain" <?php selected( $bale['format_mode'], 'plain' ); ?>>متن ساده بدون نشانه‌گذاری</option>
							</select>
							<p class="wppr-help-text">طبق مستندات بله، پیام‌ها به‌صورت خودکار با سینتکس Markdown بله (<code> *متن* </code> و <code>[عنوان](لینک)</code>) نمایش داده می‌شوند.</p>
						</div>
					</div>

					<div class="wppr-form-row">
						<div class="wppr-form-label">
							<span>گزینه‌های رسانه</span>
						</div>
						<div class="wppr-form-control wppr-check-stack">
							<label class="wppr-check-card">
								<input type="checkbox" name="wp_payamresan[bale][upload_local_media]" value="1" <?php checked( ! empty( $bale['upload_local_media'] ) ); ?> />
								<span>آپلود مستقیم فایل تصویر از هاست (مطمئن‌تر از ارسال لینک تصویر)</span>
							</label>
							<label class="wppr-check-card">
								<input type="checkbox" name="wp_payamresan[bale][fallback_to_text]" value="1" <?php checked( ! empty( $bale['fallback_to_text'] ) ); ?> />
								<span>ارسال پیام متنی در صورت بروز خطا در ارسال تصویر</span>
							</label>
						</div>
					</div>
				</div>

				<div class="wppr-card-footer">
					<button type="submit" class="wppr-btn wppr-btn-primary">ذخیره تنظیمات بله</button>
				</div>
			</form>

			<div class="wppr-card wppr-test-box">
				<div class="wppr-card-head">
					<div>
						<h2>تست اتصال و پیام آزمایشی بله</h2>
						<p class="wppr-card-desc">بررسی اعتبار توکن بازو، وضعیت مدیریت کانال و ارسال پیام تست زنده.</p>
					</div>
				</div>

				<div class="wppr-test-actions">
					<button type="button"
						class="wppr-btn wppr-btn-outline wppr-btn-block wppr-test-connection-btn"
						data-messenger="bale"
						data-token-input="#wppr-bale-token"
						data-channels-input="#wppr-bale-channels"
						data-base-input="#wppr-bale-base"
						data-output="#wppr-bale-test-output">
						بررسی اتصال بازو و مجوزهای کانال بله
					</button>
				</div>

				<div class="wppr-divider"><span>ارسال پیام آزمایشی به کانال بله</span></div>

				<p class="wppr-field-group">
					<label for="wppr-bale-test-msg"><strong>متن پیام تستی (اختیاری):</strong></label>
					<textarea id="wppr-bale-test-msg" rows="3" class="wppr-textarea" placeholder="در صورت خالی بودن، یک پیام تست پیش‌فرض ارسال می‌شود."></textarea>
				</p>

				<button type="button"
					class="wppr-btn wppr-btn-bale wppr-btn-block wppr-send-test-btn"
					data-messenger="bale"
					data-token-input="#wppr-bale-token"
					data-channels-input="#wppr-bale-channels"
					data-base-input="#wppr-bale-base"
					data-message-input="#wppr-bale-test-msg"
					data-output="#wppr-bale-test-output">
					ارسال پیام تستی به کانال بله
				</button>

				<div id="wppr-bale-test-output" class="wppr-test-output" aria-live="polite"></div>

				<div class="wppr-help-box">
					<h4>راهنمای سریع راه‌اندازی بازو در بله</h4>
					<ol>
						<li>در پیام‌رسان بله با <code>@botfather</code> (<code>ble.ir/botfather</code>) گفتگو کنید و بازوی خود را بسازید.</li>
						<li>توکن بازو را در فیلد بالا وارد کنید.</li>
						<li>بازو را به کانال بله خود اضافه کرده و به عنوان <strong>مدیر کانال</strong> با دسترسی ارسال پیام تنظیم کنید.</li>
						<li>نام کاربری کانال (مثلاً <code>@mychannel</code>) یا شناسه عددی را وارد کرده و تست را اجرا کنید.</li>
					</ol>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Post Types, JetEngine/MetaBox discovery, and Template Builder tab.
	 *
	 * @return void
	 */
	private function render_post_types_tab() {
		$available_cpts = Post_Type_Detector::get_available_post_types();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wp_payamresan_save_settings" />
			<input type="hidden" name="wp_payamresan_tab" value="post_types" />
			<?php wp_nonce_field( 'wp_payamresan_save_settings', 'wp_payamresan_nonce' ); ?>

			<div class="wppr-card wppr-pt-intro">
				<div class="wppr-pt-intro-text">
					<h2>پست تایپ‌های شناسایی‌شده و مهندسی الگو</h2>
					<p class="wppr-card-desc">
						تمامی پست تایپ‌های قابل ارسال (نوشته‌ها، برگه‌ها، محصولات ووکامرس و CPTهای جت‌انجین/متاباکس) در این بخش شناسایی شده‌اند.
						برای هر پست تایپ می‌توانید نحوه ارسال، کانال اختصاصی و متغیرهای متادیتا را تنظیم و پیش‌نمایش زنده بگیرید.
					</p>
				</div>
				<div class="wppr-pt-intro-actions">
					<button type="submit" class="wppr-btn wppr-btn-primary">ذخیره تنظیمات پست تایپ‌ها</button>
				</div>
			</div>

			<div class="wppr-pt-toolbar">
				<div class="wppr-search-box">
					<span class="wppr-search-icon"><?php echo $this->get_svg_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<input type="search" id="wppr-cpt-search" class="wppr-input" placeholder="جستجو در نام یا اسلاگ پست تایپ (مثلاً product یا properties)..." />
				</div>
				<div class="wppr-filter-pills">
					<button type="button" class="wppr-filter-pill is-active" data-cpt-filter="all">همه (<?php echo esc_html( count( $available_cpts ) ); ?>)</button>
					<button type="button" class="wppr-filter-pill" data-cpt-filter="enabled">فقط فعال‌ها</button>
				</div>
			</div>

			<div class="wppr-pt-list">
				<?php foreach ( $available_cpts as $slug => $pt_info ) : ?>
					<?php
					$config          = Settings::get_post_type_config( $slug );
					$is_enabled      = ! empty( $config['enabled'] );
					$placeholders    = Template_Engine::get_standard_placeholders( $slug );
					$meta_fields     = Meta_Resolver::get_meta_fields_for_post_type( $slug, false );
					$sample_posts    = get_posts(
						array(
							'post_type'      => $slug,
							'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
							'posts_per_page' => 10,
							'no_found_rows'  => true,
						)
					);
					$open_by_default = $is_enabled || 'post' === $slug;
					$source_class    = 'JetEngine' === $pt_info['source'] ? 'is-jet' : ( 'WooCommerce' === $pt_info['source'] ? 'is-woo' : '' );
					?>
					<div class="wppr-pt-card <?php echo $open_by_default ? 'is-open' : ''; ?> <?php echo $is_enabled ? 'is-enabled-cpt' : ''; ?>"
						data-post-type="<?php echo esc_attr( $slug ); ?>"
						data-cpt-search="<?php echo esc_attr( mb_strtolower( $pt_info['label'] . ' ' . $slug . ' ' . $pt_info['source'] ) ); ?>"
						data-cpt-enabled="<?php echo $is_enabled ? '1' : '0'; ?>">
						<div class="wppr-pt-card-header">
							<div class="wppr-pt-title-group">
								<button type="button" class="wppr-pt-toggle-body" aria-expanded="<?php echo $open_by_default ? 'true' : 'false'; ?>" title="باز/بسته کردن تنظیمات">
									<?php echo $this->get_svg_icon( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</button>
								<strong class="wppr-pt-name"><?php echo esc_html( $pt_info['label'] ); ?></strong>
								<code class="wppr-slug-badge"><?php echo esc_html( $slug ); ?></code>
								<span class="wppr-source-badge <?php echo esc_attr( $source_class ); ?>"><?php echo esc_html( $pt_info['source'] ); ?></span>
								<?php if ( ! empty( $meta_fields ) ) : ?>
									<span class="wppr-meta-count-badge"><?php echo esc_html( count( $meta_fields ) . ' متادیتا' ); ?></span>
								<?php endif; ?>
							</div>

							<label class="wppr-switch">
								<input type="checkbox"
									class="wppr-cpt-enable-toggle"
									name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][enabled]"
									value="1"
									<?php checked( $is_enabled ); ?> />
								<span class="wppr-switch-slider"></span>
								<span class="wppr-switch-text">فعال برای ارسال</span>
							</label>
						</div>

						<div class="wppr-pt-card-body">
							<div class="wppr-pt-grid">
								<div class="wppr-pt-options">
									<div class="wppr-subpanel">
										<h4>تنظیمات ارسال و مقصد</h4>

										<div class="wppr-field-group">
											<label for="wppr-mode-<?php echo esc_attr( $slug ); ?>"><strong>حالت ارسال:</strong></label>
											<select id="wppr-mode-<?php echo esc_attr( $slug ); ?>"
												name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][send_mode]"
												class="wppr-select">
												<option value="auto_and_manual" <?php selected( $config['send_mode'], 'auto_and_manual' ); ?>>اتوماتیک هنگام انتشار + امکان ارسال دستی</option>
												<option value="auto" <?php selected( $config['send_mode'], 'auto' ); ?>>فقط ارسال اتوماتیک هنگام انتشار</option>
												<option value="manual" <?php selected( $config['send_mode'], 'manual' ); ?>>فقط ارسال دستی</option>
											</select>
										</div>

										<label class="wppr-check-card wppr-field-group">
											<input type="checkbox"
												name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][send_on_update]"
												value="1"
												<?php checked( ! empty( $config['send_on_update'] ) ); ?> />
											<span>ارسال مجدد خودکار هنگام ویرایش/به‌روزرسانی</span>
										</label>

										<div class="wppr-field-group">
											<label for="wppr-ms-<?php echo esc_attr( $slug ); ?>"><strong>پیام‌رسان‌های مقصد:</strong></label>
											<select id="wppr-ms-<?php echo esc_attr( $slug ); ?>"
												name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][messengers]"
												class="wppr-select">
												<option value="both" <?php selected( $config['messengers'], 'both' ); ?>>هر دو پیام‌رسان فعال (تلگرام و بله)</option>
												<option value="telegram" <?php selected( $config['messengers'], 'telegram' ); ?>>فقط تلگرام</option>
												<option value="bale" <?php selected( $config['messengers'], 'bale' ); ?>>فقط بله</option>
											</select>
										</div>

										<div class="wppr-field-group">
											<label for="wppr-tgch-<?php echo esc_attr( $slug ); ?>"><strong>کانال اختصاصی تلگرام (اختیاری):</strong></label>
											<input id="wppr-tgch-<?php echo esc_attr( $slug ); ?>" type="text" class="wppr-input ltr"
												name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][telegram_channels]"
												value="<?php echo esc_attr( $config['telegram_channels'] ); ?>"
												placeholder="خالی = استفاده از کانال پیش‌فرض تلگرام" />
										</div>

										<div class="wppr-field-group">
											<label for="wppr-blch-<?php echo esc_attr( $slug ); ?>"><strong>کانال اختصاصی بله (اختیاری):</strong></label>
											<input id="wppr-blch-<?php echo esc_attr( $slug ); ?>" type="text" class="wppr-input ltr"
												name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][bale_channels]"
												value="<?php echo esc_attr( $config['bale_channels'] ); ?>"
												placeholder="خالی = استفاده از کانال پیش‌فرض بله" />
										</div>

										<div class="wppr-field-group">
											<label for="wppr-imgsrc-<?php echo esc_attr( $slug ); ?>"><strong>منبع تصویر پیام:</strong></label>
											<select id="wppr-imgsrc-<?php echo esc_attr( $slug ); ?>"
												name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][image_source]"
												class="wppr-select wppr-img-source-select">
												<option value="featured" <?php selected( $config['image_source'], 'featured' ); ?>>تصویر شاخص نوشته (Featured Image)</option>
												<option value="meta" <?php selected( $config['image_source'], 'meta' ); ?>>فیلد متادیتای تصویر (JetEngine Media / ACF / متاباکس)</option>
												<option value="none" <?php selected( $config['image_source'], 'none' ); ?>>بدون تصویر (فقط متن)</option>
											</select>
										</div>

										<div class="wppr-field-group wppr-img-meta-row" style="<?php echo 'meta' === $config['image_source'] ? '' : 'display:none;'; ?>">
											<label for="wppr-imgmeta-<?php echo esc_attr( $slug ); ?>"><strong>کلید متای تصویر:</strong></label>
											<input id="wppr-imgmeta-<?php echo esc_attr( $slug ); ?>" type="text" class="wppr-input ltr"
												name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][image_meta_key]"
												value="<?php echo esc_attr( $config['image_meta_key'] ); ?>"
												placeholder="مثلاً gallery_cover یا _thumbnail_id" />
											<p class="wppr-help-text">نام فیلد رسانه در جت‌انجین، ACF یا متاباکس.</p>
										</div>
									</div>
								</div>

								<div class="wppr-pt-template-col">
									<div class="wppr-subpanel">
										<h4>طراحی الگوی پیام و درج متادیتاها</h4>
										<textarea id="wppr-tpl-<?php echo esc_attr( $slug ); ?>"
											rows="7"
											class="wppr-textarea wppr-template-textarea"
											name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][template]"><?php echo esc_textarea( $config['template'] ); ?></textarea>
										<p class="wppr-help-text">
											تگ‌های مجاز: <code>&lt;b&gt;</code>، <code>&lt;i&gt;</code>، <code>&lt;a href="{post_url}"&gt;...&lt;/a&gt;</code> و شرطی <code>{if:meta:key}...{/if}</code> (برای بله خودکار به Markdown تبدیل می‌شوند).
										</p>

										<div class="wppr-placeholders-box">
											<div class="wppr-ph-section">
												<span class="wppr-ph-title">متغیرهای پایه و تاکسونومی‌ها (کلیک برای درج):</span>
												<div class="wppr-chips">
													<?php foreach ( $placeholders as $tag => $tag_label ) : ?>
														<button type="button" class="wppr-chip wppr-insert-tag"
															data-target="#wppr-tpl-<?php echo esc_attr( $slug ); ?>"
															data-tag="<?php echo esc_attr( $tag ); ?>"
															title="<?php echo esc_attr( $tag_label ); ?>">
															<?php echo esc_html( $tag ); ?>
														</button>
													<?php endforeach; ?>
												</div>
											</div>

											<div class="wppr-ph-section">
												<div class="wppr-ph-header">
													<span class="wppr-ph-title">متادیتاهای شناسایی‌شده (JetEngine / ACF / MetaBox / WP):</span>
													<button type="button" class="wppr-link-btn wppr-load-hidden-meta"
														data-post-type="<?php echo esc_attr( $slug ); ?>"
														data-target="#wppr-tpl-<?php echo esc_attr( $slug ); ?>">
														نمایش متاهای مخفی (_)
													</button>
												</div>
												<div class="wppr-chips wppr-meta-chips" id="wppr-meta-chips-<?php echo esc_attr( $slug ); ?>">
													<?php if ( empty( $meta_fields ) ) : ?>
														<span class="wppr-help-text">هنوز متادیتایی برای این پست تایپ ثبت نشده است. می‌توانید دستی به شکل <code>{meta:field_name}</code> بنویسید.</span>
													<?php else : ?>
														<?php foreach ( $meta_fields as $meta_key => $meta_info ) : ?>
															<button type="button" class="wppr-chip wppr-insert-tag wppr-meta-chip"
																data-target="#wppr-tpl-<?php echo esc_attr( $slug ); ?>"
																data-tag="<?php echo esc_attr( '{meta:' . $meta_key . '}' ); ?>"
																title="<?php echo esc_attr( $meta_info['label'] . ' (' . $meta_info['source'] . ')' ); ?>">
																<span class="wppr-chip-key"><?php echo esc_html( $meta_key ); ?></span>
																<span class="wppr-chip-src"><?php echo esc_html( $meta_info['source'] ); ?></span>
															</button>
														<?php endforeach; ?>
													<?php endif; ?>
												</div>
											</div>
										</div>

										<div class="wppr-preview-toolbar">
											<select class="wppr-select wppr-preview-post-select" id="wppr-preview-select-<?php echo esc_attr( $slug ); ?>">
												<?php if ( empty( $sample_posts ) ) : ?>
													<option value="0">مطلبی در این پست تایپ یافت نشد</option>
												<?php else : ?>
													<?php foreach ( $sample_posts as $sample_post ) : ?>
														<option value="<?php echo esc_attr( $sample_post->ID ); ?>">
															<?php echo esc_html( '#' . $sample_post->ID . ' — ' . ( $sample_post->post_title ? $sample_post->post_title : '(بدون عنوان)' ) ); ?>
														</option>
													<?php endforeach; ?>
												<?php endif; ?>
											</select>
											<button type="button" class="wppr-btn wppr-btn-outline wppr-preview-btn"
												data-post-select="#wppr-preview-select-<?php echo esc_attr( $slug ); ?>"
												data-template="#wppr-tpl-<?php echo esc_attr( $slug ); ?>"
												data-output="#wppr-preview-out-<?php echo esc_attr( $slug ); ?>">
												پیش‌نمایش پیام و متادیتاها
											</button>
										</div>
										<div id="wppr-preview-out-<?php echo esc_attr( $slug ); ?>" class="wppr-preview-output"></div>
									</div>
								</div>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="wppr-sticky-save">
				<button type="submit" class="wppr-btn wppr-btn-primary">ذخیره تمام تنظیمات پست تایپ‌ها</button>
			</div>
		</form>
		<?php
	}

	/**
	 * Render dedicated Manual Send tab.
	 *
	 * @return void
	 */
	private function render_manual_tab() {
		$available_cpts = Post_Type_Detector::get_available_post_types();
		$tg_enabled     = (bool) Settings::get( 'telegram', 'enabled', false );
		$bl_enabled     = (bool) Settings::get( 'bale', 'enabled', false );
		$first_slug     = ! empty( $available_cpts ) ? key( $available_cpts ) : 'post';
		$initial_posts  = get_posts(
			array(
				'post_type'      => $first_slug,
				'post_status'    => array( 'publish', 'future', 'pending', 'draft', 'private' ),
				'posts_per_page' => 30,
				'no_found_rows'  => true,
			)
		);
		?>
		<div class="wppr-two-col">
			<div class="wppr-card">
				<div class="wppr-card-head">
					<div>
						<h2>ارسال دستی مطلب به پیام‌رسان‌ها</h2>
						<p class="wppr-card-desc">هر پست تایپ و مطلبی را انتخاب کنید، خروجی نهایی را ببینید و فوراً به کانال ارسال نمایید.</p>
					</div>
				</div>

				<?php if ( ! $tg_enabled && ! $bl_enabled ) : ?>
					<div class="wppr-alert wppr-alert-error" style="margin-bottom: 16px;">
						<strong>هر دو پیام‌رسان غیرفعال هستند.</strong> ابتدا حداقل یکی از پیام‌رسان‌های تلگرام یا بله را در تب مربوطه فعال کنید.
					</div>
				<?php endif; ?>

				<div class="wppr-form-rows">
					<div class="wppr-field-group">
						<label for="wppr-manual-pt"><strong>۱. انتخاب پست تایپ</strong></label>
						<select id="wppr-manual-pt" class="wppr-select">
							<?php foreach ( $available_cpts as $slug => $pt_info ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>">
									<?php echo esc_html( $pt_info['label'] . ' (' . $slug . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="wppr-field-group">
						<label for="wppr-manual-post"><strong>۲. انتخاب نوشته یا رکورد</strong></label>
						<select id="wppr-manual-post" class="wppr-select">
							<?php if ( empty( $initial_posts ) ) : ?>
								<option value="0">مطلبی یافت نشد</option>
							<?php else : ?>
								<?php foreach ( $initial_posts as $p ) : ?>
									<option value="<?php echo esc_attr( $p->ID ); ?>">
										<?php echo esc_html( '#' . $p->ID . ' — ' . ( $p->post_title ? $p->post_title : '(بدون عنوان)' ) ); ?>
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<div class="wppr-inline-subfield">
							<label for="wppr-manual-post-id">یا شناسه عددی مستقیم (ID):</label>
							<input id="wppr-manual-post-id" type="number" min="1" class="wppr-input wppr-input-sm ltr" placeholder="ID" />
						</div>
					</div>

					<div class="wppr-field-group">
						<span class="wppr-field-label-inline"><strong>۳. پیام‌رسان‌های هدف</strong></span>
						<div class="wppr-messenger-select-pills">
							<label class="wppr-pill-check <?php echo ! $tg_enabled ? 'is-disabled' : ''; ?>">
								<input type="checkbox" class="wppr-manual-messenger" value="telegram" <?php checked( $tg_enabled ); ?> <?php disabled( ! $tg_enabled ); ?> />
								<span>تلگرام <?php echo ! $tg_enabled ? '(غیرفعال)' : ''; ?></span>
							</label>
							<label class="wppr-pill-check <?php echo ! $bl_enabled ? 'is-disabled' : ''; ?>">
								<input type="checkbox" class="wppr-manual-messenger" value="bale" <?php checked( $bl_enabled ); ?> <?php disabled( ! $bl_enabled ); ?> />
								<span>بله <?php echo ! $bl_enabled ? '(غیرفعال)' : ''; ?></span>
							</label>
						</div>
					</div>

					<div class="wppr-field-group">
						<label for="wppr-manual-tpl"><strong>۴. متن یا الگوی سفارشی (اختیاری)</strong></label>
						<textarea id="wppr-manual-tpl" rows="5" class="wppr-textarea" placeholder="در صورت خالی بودن، از الگوی تنظیم‌شده برای پست تایپ انتخابی استفاده می‌شود."></textarea>
					</div>

					<label class="wppr-check-card wppr-field-group">
						<input type="checkbox" id="wppr-manual-skip-img" value="1" />
						<span>ارسال بدون تصویر (فقط متن)</span>
					</label>
				</div>

				<div class="wppr-manual-buttons">
					<button type="button" id="wppr-manual-preview-btn" class="wppr-btn wppr-btn-outline">پیش‌نمایش پیام و متادیتاها</button>
					<button type="button" id="wppr-manual-send-btn" class="wppr-btn wppr-btn-primary">ارسال فوری به پیام‌رسان‌های انتخاب‌شده</button>
				</div>

				<div id="wppr-manual-result" class="wppr-test-output" aria-live="polite"></div>
			</div>

			<div class="wppr-card">
				<div class="wppr-card-head">
					<div>
						<h2>پیش‌نمایش زنده خروجی و متادیتاها</h2>
						<p class="wppr-card-desc">شبیه‌سازی نمایش پیام در تلگرام و بله به همراه متادیتاهای خوانده‌شده از مطلب</p>
					</div>
				</div>
				<div id="wppr-manual-preview-output" class="wppr-preview-output">
					<div class="wppr-empty-state">
						<div class="wppr-empty-icon"><?php echo $this->get_svg_icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<p>مطلبی را انتخاب کرده و روی <strong>«پیش‌نمایش پیام و متادیتاها»</strong> کلیک کنید تا متن نهایی تلگرام، بله و مقادیر متا نمایش داده شوند.</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Delivery Logs tab.
	 *
	 * @return void
	 */
	private function render_logs_tab() {
		$filter_messenger = isset( $_GET['log_messenger'] ) ? sanitize_key( wp_unslash( $_GET['log_messenger'] ) ) : '';
		$filter_status    = isset( $_GET['log_status'] ) ? sanitize_key( wp_unslash( $_GET['log_status'] ) ) : '';
		$logs             = Logger::get_logs(
			array(
				'messenger' => $filter_messenger,
				'status'    => $filter_status,
				'limit'     => 100,
			)
		);

		$trigger_labels = array(
			'auto'   => 'خودکار',
			'manual' => 'دستی',
			'bulk'   => 'گروهی',
			'test'   => 'تست',
		);
		?>
		<div class="wppr-card">
			<div class="wppr-card-head">
				<div>
					<h2>گزارش ارسال‌ها و تست‌های اتصال</h2>
					<p class="wppr-card-desc">مشاهده تاریخچه دقیق پیام‌های ارسال‌شده به بله و تلگرام به همراه شناسه پیام و جزئیات خطاها</p>
				</div>
				<button type="button" id="wppr-clear-logs-btn" class="wppr-btn wppr-btn-danger-outline">پاک کردن تمام گزارش‌ها</button>
			</div>

			<div class="wppr-logs-toolbar">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wppr-logs-filters">
					<input type="hidden" name="page" value="wp-payamresan" />
					<input type="hidden" name="tab" value="logs" />

					<select name="log_messenger" class="wppr-select wppr-select-inline">
						<option value="">همه پیام‌رسان‌ها</option>
						<option value="telegram" <?php selected( $filter_messenger, 'telegram' ); ?>>تلگرام</option>
						<option value="bale" <?php selected( $filter_messenger, 'bale' ); ?>>بله</option>
					</select>

					<select name="log_status" class="wppr-select wppr-select-inline">
						<option value="">همه وضعیت‌ها</option>
						<option value="success" <?php selected( $filter_status, 'success' ); ?>>موفق</option>
						<option value="error" <?php selected( $filter_status, 'error' ); ?>>خطا</option>
					</select>

					<button type="submit" class="wppr-btn wppr-btn-outline">اعمال فیلتر</button>
				</form>
			</div>

			<div class="wppr-table-scroll">
				<table class="wppr-table">
					<thead>
						<tr>
							<th>زمان</th>
							<th>مطلب</th>
							<th>پست تایپ</th>
							<th>پیام‌رسان</th>
							<th>کانال</th>
							<th>نوع</th>
							<th>وضعیت</th>
							<th>نتیجه / جزئیات</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $logs ) ) : ?>
							<tr>
								<td colspan="8" class="wppr-table-empty">هنوز گزارشی در سیستم ثبت نشده است.</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $logs as $row ) : ?>
								<tr>
									<td class="ltr"><?php echo esc_html( $row['created_at'] ); ?></td>
									<td>
										<?php if ( ! empty( $row['post_id'] ) ) : ?>
											<a href="<?php echo esc_url( get_edit_post_link( (int) $row['post_id'] ) ); ?>" class="wppr-post-link">
												#<?php echo esc_html( $row['post_id'] . ' ' . get_the_title( (int) $row['post_id'] ) ); ?>
											</a>
										<?php else : ?>
											<span>—</span>
										<?php endif; ?>
									</td>
									<td><code><?php echo esc_html( $row['post_type'] ? $row['post_type'] : '-' ); ?></code></td>
									<td>
										<span class="wppr-platform-badge <?php echo 'telegram' === $row['messenger'] ? 'is-telegram' : 'is-bale'; ?>">
											<?php echo 'telegram' === $row['messenger'] ? 'تلگرام' : 'بله'; ?>
										</span>
									</td>
									<td class="ltr"><?php echo esc_html( $row['channel_id'] ? $row['channel_id'] : '-' ); ?></td>
									<td><span class="wppr-trigger-badge"><?php echo esc_html( isset( $trigger_labels[ $row['trigger_type'] ] ) ? $trigger_labels[ $row['trigger_type'] ] : $row['trigger_type'] ); ?></span></td>
									<td>
										<?php if ( 'success' === $row['status'] ) : ?>
											<span class="wppr-badge wppr-badge-success">موفق</span>
										<?php else : ?>
											<span class="wppr-badge wppr-badge-error">خطا</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( 'success' === $row['status'] ) : ?>
											<span>شناسه پیام: <code><?php echo esc_html( $row['message_id'] ? $row['message_id'] : '-' ); ?></code></span>
										<?php else : ?>
											<span class="wppr-error-text"><?php echo esc_html( $row['error_message'] ); ?></span>
										<?php endif; ?>
										<?php if ( ! empty( $row['payload_preview'] ) ) : ?>
											<details class="wppr-log-details">
												<summary>مشاهده متن پیام</summary>
												<pre><?php echo esc_html( $row['payload_preview'] ); ?></pre>
											</details>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Return clean inline SVG icons for dashboard branding and navigation.
	 *
	 * @param string $name Icon identifier.
	 * @return string
	 */
	private function get_svg_icon( $name ) {
		switch ( $name ) {
			case 'brand':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg>';
			case 'telegram':
				return '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>';
			case 'bale':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/><path d="M9 12l2 2 4-4"/></svg>';
			case 'dashboard':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/></svg>';
			case 'layers':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>';
			case 'send':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
			case 'logs':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>';
			case 'check':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
			case 'alert':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
			case 'spark':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8H20l-4.9 3.6 1.9 5.8L12 14.6l-5 3.6 1.9-5.8L4 8.8h6.1z"/></svg>';
			case 'search':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
			case 'chevron-down':
				return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
			default:
				return '';
		}
	}
}
