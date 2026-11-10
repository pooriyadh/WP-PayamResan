<?php
/**
 * Activity and API delivery logger.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan;

defined( 'ABSPATH' ) || exit;

/**
 * Persists and retrieves delivery logs in the custom database table.
 */
class Logger {

	/**
	 * Insert a log record.
	 *
	 * @param array $entry Log entry fields.
	 * @return int Inserted row ID or 0 on failure.
	 */
	public static function log( array $entry ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'payamresan_logs';

		$data = array(
			'created_at'       => current_time( 'mysql' ),
			'post_id'          => isset( $entry['post_id'] ) ? absint( $entry['post_id'] ) : 0,
			'post_type'        => isset( $entry['post_type'] ) ? sanitize_key( $entry['post_type'] ) : '',
			'messenger'        => isset( $entry['messenger'] ) ? sanitize_key( $entry['messenger'] ) : '',
			'channel_id'       => isset( $entry['channel_id'] ) ? self::truncate_text( sanitize_text_field( (string) $entry['channel_id'] ), 191 ) : '',
			'trigger_type'     => isset( $entry['trigger_type'] ) ? sanitize_key( $entry['trigger_type'] ) : 'auto',
			'status'           => ( isset( $entry['status'] ) && 'success' === $entry['status'] ) ? 'success' : 'error',
			'message_id'       => isset( $entry['message_id'] ) ? self::truncate_text( sanitize_text_field( (string) $entry['message_id'] ), 64 ) : '',
			'error_message'    => isset( $entry['error_message'] ) ? sanitize_textarea_field( (string) $entry['error_message'] ) : '',
			'response_excerpt' => isset( $entry['response_excerpt'] ) ? self::truncate_text( sanitize_textarea_field( (string) $entry['response_excerpt'] ), 1000 ) : '',
			'payload_preview'  => isset( $entry['payload_preview'] ) ? self::truncate_text( sanitize_textarea_field( (string) $entry['payload_preview'] ), 1000 ) : '',
		);

		$formats = array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $table_name, $data, $formats );
		if ( ! $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Query recent log records with optional filters.
	 *
	 * @param array $args Filter arguments (messenger, status, limit, offset).
	 * @return array
	 */
	public static function get_logs( array $args = array() ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'payamresan_logs';
		$messenger  = isset( $args['messenger'] ) ? sanitize_key( $args['messenger'] ) : '';
		$status     = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
		$limit      = isset( $args['limit'] ) ? max( 1, min( 200, absint( $args['limit'] ) ) ) : 50;
		$offset     = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

		$where  = array( '1=1' );
		$params = array();

		if ( in_array( $messenger, array( 'telegram', 'bale' ), true ) ) {
			$where[]  = 'messenger = %s';
			$params[] = $messenger;
		}

		if ( in_array( $status, array( 'success', 'error' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$where_sql = implode( ' AND ', $where );
		$params[]  = $limit;
		$params[]  = $offset;

		$sql = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count total logs matching optional filters.
	 *
	 * @param array $args Filter arguments (messenger, status).
	 * @return int
	 */
	public static function count_logs( array $args = array() ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'payamresan_logs';
		$messenger  = isset( $args['messenger'] ) ? sanitize_key( $args['messenger'] ) : '';
		$status     = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';

		$where  = array( '1=1' );
		$params = array();

		if ( in_array( $messenger, array( 'telegram', 'bale' ), true ) ) {
			$where[]  = 'messenger = %s';
			$params[] = $messenger;
		}

		if ( in_array( $status, array( 'success', 'error' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";

		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Remove all rows from the log table.
	 *
	 * @return bool
	 */
	public static function clear_all() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'payamresan_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->query( "TRUNCATE TABLE {$table_name}" );
	}

	/**
	 * Keep only the configured maximum number of latest log entries.
	 *
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		$limit      = absint( Settings::get( 'general', 'log_retention_limit', 500 ) );
		$limit      = max( 50, $limit );
		$table_name = $wpdb->prefix . 'payamresan_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cutoff_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} ORDER BY id DESC LIMIT 1 OFFSET %d",
				$limit
			)
		);

		if ( $cutoff_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table_name} WHERE id <= %d",
					(int) $cutoff_id
				)
			);
		}
	}

	/**
	 * Safely truncate UTF-8 string.
	 *
	 * @param string $text   Input text.
	 * @param int    $length Max characters.
	 * @return string
	 */
	private static function truncate_text( $text, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $length, 'UTF-8' );
		}
		return substr( $text, 0, $length );
	}
}
