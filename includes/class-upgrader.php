<?php
/**
 * Plugin upgrader and database schema manager.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin installation, semantic version upgrades, and schema migrations.
 */
class Upgrader {

	/**
	 * Run installation or upgrade tasks when versions change.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$stored_version    = get_option( 'wp_payamresan_version', '' );
		$stored_db_version = get_option( 'wp_payamresan_db_version', '' );

		if ( WP_PAYAMRESAN_DB_VERSION !== $stored_db_version ) {
			self::create_tables();
			update_option( 'wp_payamresan_db_version', WP_PAYAMRESAN_DB_VERSION );
		}

		if ( WP_PAYAMRESAN_VERSION !== $stored_version ) {
			self::migrate_settings( $stored_version );
			update_option( 'wp_payamresan_version', WP_PAYAMRESAN_VERSION );
		}

		if ( ! wp_next_scheduled( 'wp_payamresan_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wp_payamresan_daily_cleanup' );
		}
	}

	/**
	 * Create or update custom database tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'payamresan_logs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type varchar(64) NOT NULL DEFAULT '',
			messenger varchar(32) NOT NULL DEFAULT '',
			channel_id varchar(191) NOT NULL DEFAULT '',
			trigger_type varchar(32) NOT NULL DEFAULT 'auto',
			status varchar(20) NOT NULL DEFAULT 'success',
			message_id varchar(64) NOT NULL DEFAULT '',
			error_message text NULL,
			response_excerpt text NULL,
			payload_preview text NULL,
			PRIMARY KEY  (id),
			KEY messenger_status (messenger, status),
			KEY post_id (post_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Ensure default settings exist and migrate older versions if needed.
	 *
	 * @param string $from_version Previously installed plugin version.
	 * @return void
	 */
	private static function migrate_settings( $from_version ) {
		$existing = get_option( 'wp_payamresan_settings', null );

		if ( ! is_array( $existing ) ) {
			update_option( 'wp_payamresan_settings', Settings::get_defaults() );
			return;
		}

		$merged = Settings::merge_with_defaults( $existing );
		update_option( 'wp_payamresan_settings', $merged );

		/**
		 * Fires after WP PayamResan completes a version migration.
		 *
		 * @param string $from_version Previous version string (empty on fresh install).
		 * @param string $to_version   Current version string.
		 */
		do_action( 'wp_payamresan_upgraded', $from_version, WP_PAYAMRESAN_VERSION );
	}
}
