<?php
/**
 * Main plugin orchestrator class.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan;

use WP_PayamResan\Admin\Admin_Ajax;
use WP_PayamResan\Admin\Admin_Menu;
use WP_PayamResan\Admin\List_Actions;
use WP_PayamResan\Admin\Meta_Box;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton plugin container wiring core services and WordPress hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Message dispatcher.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Automatic sender listener.
	 *
	 * @var Auto_Sender
	 */
	private $auto_sender;

	/**
	 * Return singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->load_dependencies();

		$this->dispatcher  = new Dispatcher();
		$this->auto_sender = new Auto_Sender( $this->dispatcher );

		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );
		add_action( 'wp_payamresan_daily_cleanup', array( Logger::class, 'prune' ) );

		$this->auto_sender->register_hooks();

		if ( is_admin() ) {
			( new Admin_Menu( $this->dispatcher ) )->register_hooks();
			( new Admin_Ajax( $this->dispatcher ) )->register_hooks();
			( new Meta_Box( $this->dispatcher ) )->register_hooks();
			( new List_Actions( $this->dispatcher ) )->register_hooks();
		}
	}

	/**
	 * Activation hook handler.
	 *
	 * @return void
	 */
	public static function activate() {
		require_once WP_PAYAMRESAN_DIR . 'includes/class-settings.php';
		require_once WP_PAYAMRESAN_DIR . 'includes/class-upgrader.php';
		Upgrader::maybe_upgrade();
	}

	/**
	 * Deactivation hook handler.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'wp_payamresan_daily_cleanup' );
	}

	/**
	 * Run on plugins_loaded: check upgrades and load textdomain.
	 *
	 * @return void
	 */
	public function on_plugins_loaded() {
		load_plugin_textdomain(
			'wp-payamresan',
			false,
			dirname( WP_PAYAMRESAN_BASENAME ) . '/languages'
		);

		Upgrader::maybe_upgrade();
	}

	/**
	 * Expose dispatcher service for developers and integrations.
	 *
	 * @return Dispatcher
	 */
	public function dispatcher() {
		return $this->dispatcher;
	}

	/**
	 * Load all plugin class files.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		require_once WP_PAYAMRESAN_DIR . 'includes/class-settings.php';
		require_once WP_PAYAMRESAN_DIR . 'includes/class-logger.php';
		require_once WP_PAYAMRESAN_DIR . 'includes/class-post-type-detector.php';
		require_once WP_PAYAMRESAN_DIR . 'includes/class-meta-resolver.php';
		require_once WP_PAYAMRESAN_DIR . 'includes/class-template-engine.php';

		require_once WP_PAYAMRESAN_DIR . 'includes/messengers/interface-messenger.php';
		require_once WP_PAYAMRESAN_DIR . 'includes/messengers/class-telegram-client.php';
		require_once WP_PAYAMRESAN_DIR . 'includes/messengers/class-bale-client.php';

		require_once WP_PAYAMRESAN_DIR . 'includes/class-dispatcher.php';
		require_once WP_PAYAMRESAN_DIR . 'includes/class-auto-sender.php';

		if ( is_admin() ) {
			require_once WP_PAYAMRESAN_DIR . 'includes/admin/class-admin-menu.php';
			require_once WP_PAYAMRESAN_DIR . 'includes/admin/class-admin-ajax.php';
			require_once WP_PAYAMRESAN_DIR . 'includes/admin/class-meta-box.php';
			require_once WP_PAYAMRESAN_DIR . 'includes/admin/class-list-actions.php';
		}
	}
}
