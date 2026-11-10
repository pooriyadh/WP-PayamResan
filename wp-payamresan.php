<?php
/**
 * Plugin Name:       WP PayamResan
 * Plugin URI:        https://github.com/pooriyadh/WP-PayamResan
 * Description:       ارسال خودکار و دستی انواع پست تایپ‌های وردپرس (با پشتیبانی کامل از جت‌انجین و متاباکس‌ها) به کانال‌های پیام‌رسان بله و تلگرام.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            پوریا دایی حسینی
 * Author URI:        mailto:pouriadaeihosseini@gmail.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-payamresan
 * Domain Path:       /languages
 *
 * @package WP_PayamResan
 * @author  پوریا دایی حسینی <pouriadaeihosseini@gmail.com>
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_PAYAMRESAN_VERSION', '1.1.0' );
define( 'WP_PAYAMRESAN_DB_VERSION', '1.0.0' );
define( 'WP_PAYAMRESAN_MIN_PHP', '7.4' );
define( 'WP_PAYAMRESAN_FILE', __FILE__ );
define( 'WP_PAYAMRESAN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_PAYAMRESAN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_PAYAMRESAN_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, WP_PAYAMRESAN_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: Required PHP version, 2: Current PHP version */
						__( 'افزونه WP PayamResan برای اجرا به PHP نسخه %1$s یا بالاتر نیاز دارد. نسخه فعلی سرور شما %2$s است.', 'wp-payamresan' ),
						WP_PAYAMRESAN_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

require_once WP_PAYAMRESAN_DIR . 'includes/class-upgrader.php';
require_once WP_PAYAMRESAN_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'WP_PayamResan\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_PayamResan\\Plugin', 'deactivate' ) );

/**
 * Return the main plugin instance.
 *
 * @return \WP_PayamResan\Plugin
 */
function wp_payamresan() {
	return \WP_PayamResan\Plugin::instance();
}

wp_payamresan();
