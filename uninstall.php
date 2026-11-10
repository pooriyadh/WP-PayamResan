<?php
/**
 * Uninstall handler for WP PayamResan.
 *
 * Removes plugin options, scheduled events, and log table if the user enabled
 * data cleanup on uninstall.
 *
 * @package WP_PayamResan
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'wp_payamresan_settings', array() );
$delete_data = is_array( $settings ) && ! empty( $settings['general']['delete_data_on_uninstall'] );

wp_clear_scheduled_hook( 'wp_payamresan_cron_send_post' );
wp_clear_scheduled_hook( 'wp_payamresan_daily_cleanup' );

if ( $delete_data ) {
	global $wpdb;

	delete_option( 'wp_payamresan_settings' );
	delete_option( 'wp_payamresan_version' );
	delete_option( 'wp_payamresan_db_version' );

	$table_name = $wpdb->prefix . 'payamresan_logs';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

	$meta_keys = array(
		'_wp_payamresan_disable_auto',
		'_wp_payamresan_custom_message',
		'_wp_payamresan_sent_telegram',
		'_wp_payamresan_sent_bale',
		'_wp_payamresan_last_result',
	);

	foreach ( $meta_keys as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}
}
