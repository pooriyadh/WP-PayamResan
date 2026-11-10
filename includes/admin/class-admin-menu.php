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
					'testing'         => 'در حال بررسی اتصال...',
					'sendingTest'     => 'در حال ارسال پیام تستی...',
					'sendingPost'     => 'در حال ارسال پیام...',
					'loadingPreview'  => 'در حال آماده‌سازی پیش‌نمایش...',
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
	 * Render the main plugin admin page with tabs.
	 *
	 * @param string $forced_tab Optional tab override.
	 * @return void
	 */
	public function render_page( $forced_tab = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = array(
			'general'    => 'تنظیمات عمومی',
			'telegram'   => 'تلگرام',
			'bale'       => 'بله',
			'post_types' => 'پست تایپ‌ها و متادیتا',
			'manual'     => 'ارسال دستی',
			'logs'       => 'گزارش ارسال',
		);

		$active_tab = '' !== $forced_tab
			? $forced_tab
			: ( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general' );

		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'general';
		}

		$tg_enabled = (bool) Settings::get( 'telegram', 'enabled', false );
		$bl_enabled = (bool) Settings::get( 'bale', 'enabled', false );
		$updated    = ! empty( $_GET['settings-updated'] );

		?>
		<div class="wrap wppr-wrap" dir="rtl">
			<div class="wppr-header">
				<div class="wppr-header-title">
					<h1>
						<span class="dashicons dashicons-megaphone"></span>
						WP PayamResan
						<span class="wppr-version">v<?php echo esc_html( WP_PAYAMRESAN_VERSION ); ?></span>
					</h1>
					<p class="wppr-subtitle">ارسال خودکار و دستی مطالب و پست تایپ‌های سفارشی به کانال‌های تلگرام و بله</p>
				</div>
				<div class="wppr-header-badges">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-payamresan&tab=telegram' ) ); ?>" class="wppr-status-pill <?php echo $tg_enabled ? 'is-active' : 'is-inactive'; ?>">
						<span class="wppr-dot"></span>
						تلگرام: <?php echo $tg_enabled ? 'فعال' : 'غیرفعال'; ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-payamresan&tab=bale' ) ); ?>" class="wppr-status-pill <?php echo $bl_enabled ? 'is-active' : 'is-inactive'; ?>">
						<span class="wppr-dot"></span>
						بله: <?php echo $bl_enabled ? 'فعال' : 'غیرفعال'; ?>
					</a>
				</div>
			</div>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible wppr-notice">
					<p>تنظیمات افزونه WP PayamResan با موفقیت ذخیره شد.</p>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper wppr-tabs" aria-label="بخش‌های WP PayamResan">
				<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-payamresan&tab=' . $tab_slug ) ); ?>"
						class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="wppr-tab-content">
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
			</div>

			<div class="wppr-footer">
				<span><strong>WP PayamResan</strong> نسخه <?php echo esc_html( WP_PAYAMRESAN_VERSION ); ?></span>
				<span>•</span>
				<span>توسعه‌دهنده: <strong>پوریا دایی حسینی</strong> (<a href="mailto:pouriadaeihosseini@gmail.com">pouriadaeihosseini@gmail.com</a>)</span>
				<?php if ( function_exists( 'jet_engine' ) ) : ?>
					<span>•</span>
					<span class="wppr-jet-badge">سازگار با JetEngine (فعال)</span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render General Settings & Status Overview tab.
	 *
	 * @return void
	 */
	private function render_general_tab() {
		$general       = Settings::get( 'general', '', array() );
		$tg_enabled    = (bool) Settings::get( 'telegram', 'enabled', false );
		$bl_enabled    = (bool) Settings::get( 'bale', 'enabled', false );
		$all_cpts      = Post_Type_Detector::get_available_post_types();
		$enabled_cpts  = Post_Type_Detector::get_enabled_post_type_slugs();
		$success_count = Logger::count_logs( array( 'status' => 'success' ) );
		$error_count   = Logger::count_logs( array( 'status' => 'error' ) );
		?>
		<div class="wppr-cards-grid">
			<div class="wppr-stat-card">
				<span class="wppr-stat-label">وضعیت پیام‌رسان‌ها</span>
				<strong class="wppr-stat-value">
					<?php
					if ( $tg_enabled && $bl_enabled ) {
						echo 'تلگرام و بله فعال';
					} elseif ( $tg_enabled ) {
						echo 'فقط تلگرام فعال';
					} elseif ( $bl_enabled ) {
						echo 'فقط بله فعال';
					} else {
						echo 'هر دو غیرفعال';
					}
					?>
				</strong>
				<span class="wppr-stat-hint">امکان فعال‌سازی مستقل هر پیام‌رسان در تب مربوطه</span>
			</div>

			<div class="wppr-stat-card">
				<span class="wppr-stat-label">پست تایپ‌های شناسایی‌شده</span>
				<strong class="wppr-stat-value"><?php echo esc_html( count( $enabled_cpts ) . ' فعال از ' . count( $all_cpts ) ); ?></strong>
				<span class="wppr-stat-hint">شامل پست‌ها، برگه، محصولات و CPTهای جت‌انجین/متاباکس</span>
			</div>

			<div class="wppr-stat-card">
				<span class="wppr-stat-label">آمار گزارش ارسال</span>
				<strong class="wppr-stat-value"><?php echo esc_html( $success_count . ' موفق / ' . $error_count . ' خطا' ); ?></strong>
				<span class="wppr-stat-hint"><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-payamresan&tab=logs' ) ); ?>">مشاهده تاریخچه و جزئیات ارسال‌ها</a></span>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wppr-card">
			<input type="hidden" name="action" value="wp_payamresan_save_settings" />
			<input type="hidden" name="wp_payamresan_tab" value="general" />
			<?php wp_nonce_field( 'wp_payamresan_save_settings', 'wp_payamresan_nonce' ); ?>

			<h2>تنظیمات عمومی ارسال و زمان‌بندی</h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wppr-auto-mode">زمان‌بندی ارسال خودکار</label></th>
					<td>
						<select id="wppr-auto-mode" name="wp_payamresan[general][auto_send_mode]">
							<option value="smart" <?php selected( $general['auto_send_mode'], 'smart' ); ?>>هوشمند — همگام با متاباکس‌ها، JetEngine و ویرایشگر گوتنبرگ (پیشنهادی)</option>
							<option value="cron" <?php selected( $general['auto_send_mode'], 'cron' ); ?>>زمان‌بندی با تأخیر WP-Cron</option>
							<option value="immediate" <?php selected( $general['auto_send_mode'], 'immediate' ); ?>>فوری هم‌زمان در save_post</option>
						</select>
						<p class="description">حالت «هوشمند» صبر می‌کند تا تمام متادیتاهای جت‌انجین، ACF و متاباکس‌ها (حتی در گوتنبرگ) ذخیره شوند و سپس پیام را ارسال می‌کند.</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="wppr-cron-delay">تأخیر WP-Cron (ثانیه)</label></th>
					<td>
						<input id="wppr-cron-delay" type="number" min="3" max="300" class="small-text"
							name="wp_payamresan[general][cron_delay]"
							value="<?php echo esc_attr( $general['cron_delay'] ); ?>" />
						<p class="description">در حالت زمان‌بندی با WP-Cron، ارسال پس از این تعداد ثانیه انجام می‌شود.</p>
					</td>
				</tr>

				<tr>
					<th scope="row">شناسایی پست تایپ‌های پیشرفته</th>
					<td>
						<label>
							<input type="checkbox" name="wp_payamresan[general][include_non_public_cpts]" value="1" <?php checked( ! empty( $general['include_non_public_cpts'] ) ); ?> />
							نمایش پست تایپ‌های غیرعمومی که دارای رابط کاربری مدیریت (<code>show_ui</code>) هستند
						</label>
						<p class="description">اگر در جت‌انجین یا افزونه‌ای پست تایپی با وضعیت غیرعمومی ساخته‌اید، با فعال کردن این گزینه در لیست پست تایپ‌ها ظاهر می‌شود.</p>
					</td>
				</tr>

				<tr>
					<th scope="row">دکمه شیشه‌ای مشاهده مطلب</th>
					<td>
						<label>
							<input type="checkbox" name="wp_payamresan[general][show_read_more_button]" value="1" <?php checked( ! empty( $general['show_read_more_button'] ) ); ?> />
							افزودن دکمه اینلاین لینک به مطلب در پیام‌ها (تلگرام و بله)
						</label>
						<div class="wppr-inline-subfield">
							<label for="wppr-btn-text">متن دکمه:</label>
							<input id="wppr-btn-text" type="text" class="regular-text"
								name="wp_payamresan[general][read_more_button_text]"
								value="<?php echo esc_attr( $general['read_more_button_text'] ); ?>" />
						</div>
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="wppr-excerpt-len">طول خلاصه و متن کامل (تعداد کلمه)</label></th>
					<td>
						<label for="wppr-excerpt-len">خلاصه (<code>{post_excerpt}</code>):</label>
						<input id="wppr-excerpt-len" type="number" min="5" max="300" class="small-text"
							name="wp_payamresan[general][excerpt_length]"
							value="<?php echo esc_attr( $general['excerpt_length'] ); ?>" />
						&nbsp;&nbsp;
						<label for="wppr-content-len">متن (<code>{post_content}</code>):</label>
						<input id="wppr-content-len" type="number" min="20" max="2000" class="small-text"
							name="wp_payamresan[general][content_length]"
							value="<?php echo esc_attr( $general['content_length'] ); ?>" />
					</td>
				</tr>

				<tr>
					<th scope="row"><label for="wppr-log-limit">نگهداری گزارش‌ها</label></th>
					<td>
						<input id="wppr-log-limit" type="number" min="50" max="10000" class="small-text"
							name="wp_payamresan[general][log_retention_limit]"
							value="<?php echo esc_attr( $general['log_retention_limit'] ); ?>" />
						<span>رکورد اخیر در جدول گزارش‌ها نگهداری شود.</span>
					</td>
				</tr>

				<tr>
					<th scope="row">پاکسازی هنگام حذف افزونه</th>
					<td>
						<label>
							<input type="checkbox" name="wp_payamresan[general][delete_data_on_uninstall]" value="1" <?php checked( ! empty( $general['delete_data_on_uninstall'] ) ); ?> />
							حذف کامل تنظیمات، جدول گزارش‌ها و متادیتاهای افزونه هنگام حذف (Uninstall)
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button( 'ذخیره تنظیمات عمومی' ); ?>
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
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wppr-card">
				<input type="hidden" name="action" value="wp_payamresan_save_settings" />
				<input type="hidden" name="wp_payamresan_tab" value="telegram" />
				<?php wp_nonce_field( 'wp_payamresan_save_settings', 'wp_payamresan_nonce' ); ?>

				<div class="wppr-card-head">
					<h2>تنظیمات اتصال تلگرام</h2>
					<label class="wppr-switch">
						<input type="checkbox" name="wp_payamresan[telegram][enabled]" value="1" <?php checked( ! empty( $tg['enabled'] ) ); ?> />
						<span class="wppr-switch-slider"></span>
						<span class="wppr-switch-text">فعال‌سازی تلگرام</span>
					</label>
				</div>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wppr-tg-token">توکن ربات تلگرام</label></th>
						<td>
							<div class="wppr-token-field">
								<input id="wppr-tg-token" type="password" class="regular-text ltr" autocomplete="off"
									name="wp_payamresan[telegram][bot_token]"
									value="<?php echo esc_attr( $tg['bot_token'] ); ?>"
									placeholder="123456789:AAH..." />
								<button type="button" class="button wppr-toggle-secret" data-target="#wppr-tg-token">نمایش</button>
							</div>
							<p class="description">توکن دریافت‌شده از ربات <code>@BotFather</code> در تلگرام.</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wppr-tg-channels">شناسه کانال یا گروه تلگرام</label></th>
						<td>
							<textarea id="wppr-tg-channels" rows="3" class="large-text ltr"
								name="wp_payamresan[telegram][channel_ids]"
								placeholder="@my_channel یا -1001234567890"><?php echo esc_textarea( $tg['channel_ids'] ); ?></textarea>
							<p class="description">نام کاربری کانال (مانند <code>@channel</code>) یا شناسه عددی (مانند <code>-1001234567890</code>). برای چند کانال با کاما یا خط جدید جدا کنید.</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wppr-tg-base">آدرس پایه API تلگرام</label></th>
						<td>
							<input id="wppr-tg-base" type="url" class="regular-text ltr"
								name="wp_payamresan[telegram][api_base_url]"
								value="<?php echo esc_attr( $tg['api_base_url'] ); ?>" />
							<p class="description">پیش‌فرض: <code>https://api.telegram.org</code> — در سرورهای داخل ایران می‌توانید آدرس پروکسی یا Cloudflare Worker خود را وارد کنید.</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wppr-tg-parse">فرمت‌دهی پیام</label></th>
						<td>
							<select id="wppr-tg-parse" name="wp_payamresan[telegram][parse_mode]">
								<option value="HTML" <?php selected( $tg['parse_mode'], 'HTML' ); ?>>HTML (پیشنهادی — پشتیبانی از بولد، لینک و...)</option>
								<option value="none" <?php selected( $tg['parse_mode'], 'none' ); ?>>متن ساده (Plain Text)</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wppr-tg-overflow">مدیریت کپشن طولانی تصویر (بالای ۱۰۲۴ کاراکتر)</label></th>
						<td>
							<select id="wppr-tg-overflow" name="wp_payamresan[telegram][caption_overflow_mode]">
								<option value="split" <?php selected( $tg['caption_overflow_mode'], 'split' ); ?>>ارسال تصویر با عنوان + ارسال متن کامل در پیام بعدی (پیشنهادی)</option>
								<option value="truncate" <?php selected( $tg['caption_overflow_mode'], 'truncate' ); ?>>برش خودکار متن کپشن تا ۱۰۲۴ کاراکتر</option>
								<option value="text_only_if_long" <?php selected( $tg['caption_overflow_mode'], 'text_only_if_long' ); ?>>ارسال فقط به صورت متنی اگر پیام طولانی بود</option>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">گزینه‌های رسانه و ارسال</th>
						<td>
							<p>
								<label>
									<input type="checkbox" name="wp_payamresan[telegram][upload_local_media]" value="1" <?php checked( ! empty( $tg['upload_local_media'] ) ); ?> />
									آپلود مستقیم فایل تصویر از هاست (مناسب برای سایت‌های پشت فایروال یا کلودفلر)
								</label>
							</p>
							<p>
								<label>
									<input type="checkbox" name="wp_payamresan[telegram][fallback_to_text]" value="1" <?php checked( ! empty( $tg['fallback_to_text'] ) ); ?> />
									ارسال متنی در صورت خطای ارسال تصویر
								</label>
							</p>
							<p>
								<label>
									<input type="checkbox" name="wp_payamresan[telegram][disable_web_page_preview]" value="1" <?php checked( ! empty( $tg['disable_web_page_preview'] ) ); ?> />
									غیرفعال کردن پیش‌نمایش لینک وب در پیام‌های متنی
								</label>
							</p>
							<p>
								<label>
									<input type="checkbox" name="wp_payamresan[telegram][disable_notification]" value="1" <?php checked( ! empty( $tg['disable_notification'] ) ); ?> />
									ارسال بی‌صدا (Silent Notification)
								</label>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'ذخیره تنظیمات تلگرام' ); ?>
			</form>

			<div class="wppr-card wppr-test-box">
				<h2>تست اتصال و پیام آزمایشی تلگرام</h2>
				<p class="description">بررسی صحت توکن ربات، دسترسی ادمین در کانال و ارسال پیام تست زنده (حتی قبل از ذخیره فرم).</p>

				<div class="wppr-test-actions">
					<button type="button"
						class="button button-secondary wppr-test-connection-btn"
						data-messenger="telegram"
						data-token-input="#wppr-tg-token"
						data-channels-input="#wppr-tg-channels"
						data-base-input="#wppr-tg-base"
						data-output="#wppr-tg-test-output">
						بررسی اتصال ربات و کانال
					</button>
				</div>

				<hr />

				<p>
					<label for="wppr-tg-test-msg"><strong>متن پیام تستی (اختیاری):</strong></label>
					<textarea id="wppr-tg-test-msg" rows="3" class="widefat" placeholder="در صورت خالی بودن، یک پیام تست پیش‌فرض ارسال می‌شود."></textarea>
				</p>

				<button type="button"
					class="button button-primary wppr-send-test-btn"
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
						<li>یوزرنیم کانال (مثل <code>@mychannel</code>) یا آیدی عددی کانال خصوصی را وارد کرده و دکمه تست را بزنید.</li>
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
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wppr-card">
				<input type="hidden" name="action" value="wp_payamresan_save_settings" />
				<input type="hidden" name="wp_payamresan_tab" value="bale" />
				<?php wp_nonce_field( 'wp_payamresan_save_settings', 'wp_payamresan_nonce' ); ?>

				<div class="wppr-card-head">
					<h2>تنظیمات اتصال پیام‌رسان بله</h2>
					<label class="wppr-switch">
						<input type="checkbox" name="wp_payamresan[bale][enabled]" value="1" <?php checked( ! empty( $bale['enabled'] ) ); ?> />
						<span class="wppr-switch-slider"></span>
						<span class="wppr-switch-text">فعال‌سازی بله</span>
					</label>
				</div>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wppr-bale-token">توکن بازوی بله</label></th>
						<td>
							<div class="wppr-token-field">
								<input id="wppr-bale-token" type="password" class="regular-text ltr" autocomplete="off"
									name="wp_payamresan[bale][bot_token]"
									value="<?php echo esc_attr( $bale['bot_token'] ); ?>"
									placeholder="123456789:abcd..." />
								<button type="button" class="button wppr-toggle-secret" data-target="#wppr-bale-token">نمایش</button>
							</div>
							<p class="description">توکن دریافت‌شده از بازوی پدر (<code>@botfather</code>) در بله.</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wppr-bale-channels">شناسه کانال یا گفت‌وگوی بله</label></th>
						<td>
							<textarea id="wppr-bale-channels" rows="3" class="large-text ltr"
								name="wp_payamresan[bale][channel_ids]"
								placeholder="@my_bale_channel یا شناسه عددی چت"><?php echo esc_textarea( $bale['channel_ids'] ); ?></textarea>
							<p class="description">نام کاربری کانال بله (مثل <code>@channel</code>) یا شناسه عددی گفتگو. برای چند مقصد با خط جدید یا کاما جدا کنید.</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wppr-bale-base">آدرس پایه API بله</label></th>
						<td>
							<input id="wppr-bale-base" type="url" class="regular-text ltr"
								name="wp_payamresan[bale][api_base_url]"
								value="<?php echo esc_attr( $bale['api_base_url'] ); ?>" />
							<p class="description">آدرس رسمی بله: <code>https://tapi.bale.ai</code></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wppr-bale-format">قالب‌بندی متن در بله</label></th>
						<td>
							<select id="wppr-bale-format" name="wp_payamresan[bale][format_mode]">
								<option value="markdown" <?php selected( $bale['format_mode'], 'markdown' ); ?>>تبدیل خودکار به Markdown بله (بولد و لینک استاندارد بله)</option>
								<option value="plain" <?php selected( $bale['format_mode'], 'plain' ); ?>>متن ساده بدون نشانه‌گذاری</option>
							</select>
							<p class="description">طبق مستندات بله، پیام‌ها به‌صورت خودکار با سینتکس Markdown بله (<code> *متن* </code> و <code>[عنوان](لینک)</code>) نمایش داده می‌شوند.</p>
						</td>
					</tr>

					<tr>
						<th scope="row">گزینه‌های رسانه</th>
						<td>
							<p>
								<label>
									<input type="checkbox" name="wp_payamresan[bale][upload_local_media]" value="1" <?php checked( ! empty( $bale['upload_local_media'] ) ); ?> />
									آپلود مستقیم فایل تصویر از هاست (مطمئن‌تر از ارسال لینک تصویر)
								</label>
							</p>
							<p>
								<label>
									<input type="checkbox" name="wp_payamresan[bale][fallback_to_text]" value="1" <?php checked( ! empty( $bale['fallback_to_text'] ) ); ?> />
									ارسال پیام متنی در صورت بروز خطا در ارسال تصویر
								</label>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'ذخیره تنظیمات بله' ); ?>
			</form>

			<div class="wppr-card wppr-test-box">
				<h2>تست اتصال و پیام آزمایشی بله</h2>
				<p class="description">تست اعتبار توکن بازوی بله، وضعیت مدیریت کانال و ارسال پیام تست زنده.</p>

				<div class="wppr-test-actions">
					<button type="button"
						class="button button-secondary wppr-test-connection-btn"
						data-messenger="bale"
						data-token-input="#wppr-bale-token"
						data-channels-input="#wppr-bale-channels"
						data-base-input="#wppr-bale-base"
						data-output="#wppr-bale-test-output">
						بررسی اتصال بازو و کانال بله
					</button>
				</div>

				<hr />

				<p>
					<label for="wppr-bale-test-msg"><strong>متن پیام تستی (اختیاری):</strong></label>
					<textarea id="wppr-bale-test-msg" rows="3" class="widefat" placeholder="در صورت خالی بودن، یک پیام تست پیش‌فرض ارسال می‌شود."></textarea>
				</p>

				<button type="button"
					class="button button-primary wppr-send-test-btn"
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
				<div>
					<h2>پست تایپ‌های شناسایی‌شده و الگوهای پیام</h2>
					<p class="description">
						تمامی پست تایپ‌های قابل ارسال سایت (نوشته‌ها، برگه‌ها، محصولات، پست تایپ‌های جت‌انجین و سایر افزونه‌ها) در زیر لیست شده‌اند.
						می‌توانید برای هر پست تایپ وضعیت ارسال (اتوماتیک / دستی)، کانال اختصاصی و الگوی پیام با متادیتاها را تنظیم کنید.
					</p>
				</div>
				<div>
					<?php submit_button( 'ذخیره تنظیمات پست تایپ‌ها', 'primary', 'submit', false ); ?>
				</div>
			</div>

			<div class="wppr-pt-list">
				<?php foreach ( $available_cpts as $slug => $pt_info ) : ?>
					<?php
					$config        = Settings::get_post_type_config( $slug );
					$is_enabled    = ! empty( $config['enabled'] );
					$placeholders  = Template_Engine::get_standard_placeholders( $slug );
					$meta_fields   = Meta_Resolver::get_meta_fields_for_post_type( $slug, false );
					$sample_posts  = get_posts(
						array(
							'post_type'      => $slug,
							'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
							'posts_per_page' => 10,
							'no_found_rows'  => true,
						)
					);
					$open_by_default = $is_enabled || 'post' === $slug;
					?>
					<div class="wppr-pt-card <?php echo $open_by_default ? 'is-open' : ''; ?>" data-post-type="<?php echo esc_attr( $slug ); ?>">
						<div class="wppr-pt-card-header">
							<div class="wppr-pt-title-group">
								<button type="button" class="wppr-pt-toggle-body" aria-expanded="<?php echo $open_by_default ? 'true' : 'false'; ?>">
									<span class="dashicons dashicons-arrow-down-alt2"></span>
								</button>
								<strong><?php echo esc_html( $pt_info['label'] ); ?></strong>
								<code class="wppr-slug-badge"><?php echo esc_html( $slug ); ?></code>
								<span class="wppr-source-badge"><?php echo esc_html( $pt_info['source'] ); ?></span>
								<?php if ( ! empty( $meta_fields ) ) : ?>
									<span class="wppr-meta-count-badge"><?php echo esc_html( count( $meta_fields ) . ' متادیتا' ); ?></span>
								<?php endif; ?>
							</div>

							<label class="wppr-switch">
								<input type="checkbox"
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
									<p>
										<label for="wppr-mode-<?php echo esc_attr( $slug ); ?>"><strong>حالت ارسال:</strong></label>
										<select id="wppr-mode-<?php echo esc_attr( $slug ); ?>"
											name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][send_mode]"
											class="widefat">
											<option value="auto_and_manual" <?php selected( $config['send_mode'], 'auto_and_manual' ); ?>>اتوماتیک هنگام انتشار + امکان ارسال دستی</option>
											<option value="auto" <?php selected( $config['send_mode'], 'auto' ); ?>>فقط ارسال اتوماتیک هنگام انتشار</option>
											<option value="manual" <?php selected( $config['send_mode'], 'manual' ); ?>>فقط ارسال دستی</option>
										</select>
									</p>

									<p>
										<label>
											<input type="checkbox"
												name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][send_on_update]"
												value="1"
												<?php checked( ! empty( $config['send_on_update'] ) ); ?> />
											ارسال مجدد خودکار هنگام ویرایش/به‌روزرسانی مطلب منتشرشده
										</label>
									</p>

									<p>
										<label for="wppr-ms-<?php echo esc_attr( $slug ); ?>"><strong>پیام‌رسان‌های مقصد:</strong></label>
										<select id="wppr-ms-<?php echo esc_attr( $slug ); ?>"
											name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][messengers]"
											class="widefat">
											<option value="both" <?php selected( $config['messengers'], 'both' ); ?>>هر دو پیام‌رسان فعال (تلگرام و بله)</option>
											<option value="telegram" <?php selected( $config['messengers'], 'telegram' ); ?>>فقط تلگرام</option>
											<option value="bale" <?php selected( $config['messengers'], 'bale' ); ?>>فقط بله</option>
										</select>
									</p>

									<p>
										<label for="wppr-tgch-<?php echo esc_attr( $slug ); ?>"><strong>کانال اختصاصی تلگرام (اختیاری):</strong></label>
										<input id="wppr-tgch-<?php echo esc_attr( $slug ); ?>" type="text" class="widefat ltr"
											name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][telegram_channels]"
											value="<?php echo esc_attr( $config['telegram_channels'] ); ?>"
											placeholder="خالی = استفاده از کانال پیش‌فرض تلگرام" />
									</p>

									<p>
										<label for="wppr-blch-<?php echo esc_attr( $slug ); ?>"><strong>کانال اختصاصی بله (اختیاری):</strong></label>
										<input id="wppr-blch-<?php echo esc_attr( $slug ); ?>" type="text" class="widefat ltr"
											name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][bale_channels]"
											value="<?php echo esc_attr( $config['bale_channels'] ); ?>"
											placeholder="خالی = استفاده از کانال پیش‌فرض بله" />
									</p>

									<p>
										<label for="wppr-imgsrc-<?php echo esc_attr( $slug ); ?>"><strong>منبع تصویر پیام:</strong></label>
										<select id="wppr-imgsrc-<?php echo esc_attr( $slug ); ?>"
											name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][image_source]"
											class="widefat wppr-img-source-select">
											<option value="featured" <?php selected( $config['image_source'], 'featured' ); ?>>تصویر شاخص نوشته (Featured Image)</option>
											<option value="meta" <?php selected( $config['image_source'], 'meta' ); ?>>فیلد متادیتای تصویر (JetEngine Media / ACF / متاباکس)</option>
											<option value="none" <?php selected( $config['image_source'], 'none' ); ?>>بدون تصویر (فقط متن)</option>
										</select>
									</p>

									<p class="wppr-img-meta-row" style="<?php echo 'meta' === $config['image_source'] ? '' : 'display:none;'; ?>">
										<label for="wppr-imgmeta-<?php echo esc_attr( $slug ); ?>"><strong>کلید متای تصویر:</strong></label>
										<input id="wppr-imgmeta-<?php echo esc_attr( $slug ); ?>" type="text" class="widefat ltr"
											name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][image_meta_key]"
											value="<?php echo esc_attr( $config['image_meta_key'] ); ?>"
											placeholder="مثلاً gallery_cover یا _thumbnail_id" />
										<span class="description">نام فیلد رسانه در جت‌انجین، ACF یا متاباکس. در صورت خالی بودن به تصویر شاخص برمی‌گردد.</span>
									</p>
								</div>

								<div class="wppr-pt-template-col">
									<label for="wppr-tpl-<?php echo esc_attr( $slug ); ?>"><strong>الگوی پیام ارسالی:</strong></label>
									<textarea id="wppr-tpl-<?php echo esc_attr( $slug ); ?>"
										rows="8"
										class="widefat wppr-template-textarea"
										name="wp_payamresan[post_types][<?php echo esc_attr( $slug ); ?>][template]"><?php echo esc_textarea( $config['template'] ); ?></textarea>
									<p class="description">
										تگ‌های مجاز: <code>&lt;b&gt;</code>، <code>&lt;i&gt;</code>، <code>&lt;a href="{post_url}"&gt;...&lt;/a&gt;</code> و شرطی <code>{if:meta:key}...{/if}</code> (برای بله خودکار به Markdown تبدیل می‌شوند).
									</p>

									<div class="wppr-placeholders-box">
										<div class="wppr-ph-section">
											<span class="wppr-ph-title">متغیرهای پایه و تاکسونومی‌ها (کلیک برای درج):</span>
											<div class="wppr-chips">
												<?php foreach ( $placeholders as $tag => $tag_label ) : ?>
													<button type="button" class="button button-small wppr-insert-tag"
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
												<button type="button" class="button-link wppr-load-hidden-meta"
													data-post-type="<?php echo esc_attr( $slug ); ?>"
													data-target="#wppr-tpl-<?php echo esc_attr( $slug ); ?>">
													نمایش متاهای مخفی (_)
												</button>
											</div>
											<div class="wppr-chips wppr-meta-chips" id="wppr-meta-chips-<?php echo esc_attr( $slug ); ?>">
												<?php if ( empty( $meta_fields ) ) : ?>
													<span class="description">هنوز متادیتایی برای این پست تایپ ثبت نشده است. می‌توانید دستی به شکل <code>{meta:field_name}</code> بنویسید.</span>
												<?php else : ?>
													<?php foreach ( $meta_fields as $meta_key => $meta_info ) : ?>
														<button type="button" class="button button-small wppr-insert-tag wppr-meta-chip"
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
										<select class="wppr-preview-post-select" id="wppr-preview-select-<?php echo esc_attr( $slug ); ?>">
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
										<button type="button" class="button button-secondary wppr-preview-btn"
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
				<?php endforeach; ?>
			</div>

			<p class="submit">
				<?php submit_button( 'ذخیره تمام تنظیمات پست تایپ‌ها', 'primary', 'submit', false ); ?>
			</p>
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
				<h2>ارسال دستی مطلب به پیام‌رسان‌ها</h2>
				<p class="description">هر پست تایپ و مطلبی را انتخاب کنید، خروجی نهایی (همراه با متادیتاهای جت‌انجین و متاباکس‌ها) را ببینید و فوراً ارسال کنید.</p>

				<?php if ( ! $tg_enabled && ! $bl_enabled ) : ?>
					<div class="notice notice-warning inline">
						<p>در حال حاضر هر دو پیام‌رسان تلگرام و بله غیرفعال هستند. ابتدا حداقل یکی را در تب مربوطه فعال کنید.</p>
					</div>
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wppr-manual-pt">انتخاب پست تایپ</label></th>
						<td>
							<select id="wppr-manual-pt" class="regular-text">
								<?php foreach ( $available_cpts as $slug => $pt_info ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>">
										<?php echo esc_html( $pt_info['label'] . ' (' . $slug . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wppr-manual-post">انتخاب نوشته / رکورد</label></th>
						<td>
							<select id="wppr-manual-post" class="regular-text">
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
							<p class="description">یا شناسه عددی نوشته را مستقیماً وارد کنید:</p>
							<input id="wppr-manual-post-id" type="number" min="1" class="small-text" placeholder="ID" />
						</td>
					</tr>

					<tr>
						<th scope="row">پیام‌رسان‌های هدف</th>
						<td>
							<label style="margin-left: 16px;">
								<input type="checkbox" class="wppr-manual-messenger" value="telegram" <?php checked( $tg_enabled ); ?> <?php disabled( ! $tg_enabled ); ?> />
								تلگرام <?php echo ! $tg_enabled ? '(غیرفعال)' : ''; ?>
							</label>
							<label>
								<input type="checkbox" class="wppr-manual-messenger" value="bale" <?php checked( $bl_enabled ); ?> <?php disabled( ! $bl_enabled ); ?> />
								بله <?php echo ! $bl_enabled ? '(غیرفعال)' : ''; ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="wppr-manual-tpl">متن یا الگوی سفارشی (اختیاری)</label></th>
						<td>
							<textarea id="wppr-manual-tpl" rows="5" class="large-text" placeholder="در صورت خالی بودن، از الگوی تنظیم‌شده برای پست تایپ انتخابی استفاده می‌شود."></textarea>
						</td>
					</tr>

					<tr>
						<th scope="row">تنظیمات ارسال دستی</th>
						<td>
							<label>
								<input type="checkbox" id="wppr-manual-skip-img" value="1" />
								ارسال بدون تصویر (فقط متن)
							</label>
						</td>
					</tr>
				</table>

				<p class="wppr-manual-buttons">
					<button type="button" id="wppr-manual-preview-btn" class="button button-secondary">پیش‌نمایش پیام و متادیتاها</button>
					<button type="button" id="wppr-manual-send-btn" class="button button-primary">ارسال فوری به پیام‌رسان‌های انتخاب‌شده</button>
				</p>

				<div id="wppr-manual-result" class="wppr-test-output" aria-live="polite"></div>
			</div>

			<div class="wppr-card">
				<h2>پیش‌نمایش زنده خروجی و متادیتاها</h2>
				<div id="wppr-manual-preview-output" class="wppr-preview-output">
					<p class="description">برای مشاهده نحوه رندر شدن پیام در تلگرام و بله به همراه مقادیر متادیتاهای خوانده‌شده، روی «پیش‌نمایش پیام و متادیتاها» کلیک کنید.</p>
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
			<div class="wppr-logs-toolbar">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wppr-logs-filters">
					<input type="hidden" name="page" value="wp-payamresan" />
					<input type="hidden" name="tab" value="logs" />

					<select name="log_messenger">
						<option value="">همه پیام‌رسان‌ها</option>
						<option value="telegram" <?php selected( $filter_messenger, 'telegram' ); ?>>تلگرام</option>
						<option value="bale" <?php selected( $filter_messenger, 'bale' ); ?>>بله</option>
					</select>

					<select name="log_status">
						<option value="">همه وضعیت‌ها</option>
						<option value="success" <?php selected( $filter_status, 'success' ); ?>>موفق</option>
						<option value="error" <?php selected( $filter_status, 'error' ); ?>>خطا</option>
					</select>

					<button type="submit" class="button">فیلتر</button>
				</form>

				<button type="button" id="wppr-clear-logs-btn" class="button button-link-delete">پاک کردن تمام گزارش‌ها</button>
			</div>

			<div class="wppr-table-scroll">
				<table class="widefat striped wppr-logs-table">
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
								<td colspan="8">هنوز گزارشی ثبت نشده است.</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $logs as $row ) : ?>
								<tr>
									<td class="ltr"><?php echo esc_html( $row['created_at'] ); ?></td>
									<td>
										<?php if ( ! empty( $row['post_id'] ) ) : ?>
											<a href="<?php echo esc_url( get_edit_post_link( (int) $row['post_id'] ) ); ?>">
												#<?php echo esc_html( $row['post_id'] . ' ' . get_the_title( (int) $row['post_id'] ) ); ?>
											</a>
										<?php else : ?>
											<span>—</span>
										<?php endif; ?>
									</td>
									<td><code><?php echo esc_html( $row['post_type'] ? $row['post_type'] : '-' ); ?></code></td>
									<td><?php echo 'telegram' === $row['messenger'] ? 'تلگرام' : 'بله'; ?></td>
									<td class="ltr"><?php echo esc_html( $row['channel_id'] ? $row['channel_id'] : '-' ); ?></td>
									<td><?php echo esc_html( isset( $trigger_labels[ $row['trigger_type'] ] ) ? $trigger_labels[ $row['trigger_type'] ] : $row['trigger_type'] ); ?></td>
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
												<summary>متن پیام</summary>
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
}
