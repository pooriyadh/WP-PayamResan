<?php
/**
 * Admin AJAX endpoints for testing, meta inspection, preview, and manual sending.
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
 * Handles all authenticated admin AJAX actions.
 */
class Admin_Ajax {

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
	 * Register WordPress AJAX hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_wp_payamresan_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'wp_ajax_wp_payamresan_send_test_message', array( $this, 'handle_send_test_message' ) );
		add_action( 'wp_ajax_wp_payamresan_get_post_type_meta', array( $this, 'handle_get_post_type_meta' ) );
		add_action( 'wp_ajax_wp_payamresan_get_posts', array( $this, 'handle_get_posts' ) );
		add_action( 'wp_ajax_wp_payamresan_preview_message', array( $this, 'handle_preview_message' ) );
		add_action( 'wp_ajax_wp_payamresan_manual_send', array( $this, 'handle_manual_send' ) );
		add_action( 'wp_ajax_wp_payamresan_clear_logs', array( $this, 'handle_clear_logs' ) );
	}

	/**
	 * Test bot token and channel access for Telegram or Bale.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		check_ajax_referer( 'wp_payamresan_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'شما مجوز دسترسی به این عملیات را ندارید.' ), 403 );
		}

		$messenger = isset( $_POST['messenger'] ) ? sanitize_key( wp_unslash( $_POST['messenger'] ) ) : '';
		$client    = $this->dispatcher->get_client( $messenger );

		if ( ! $client ) {
			wp_send_json_error( array( 'message' => 'پیام‌رسان انتخاب‌شده نامعتبر است.' ), 400 );
		}

		$override = array();
		if ( isset( $_POST['bot_token'] ) ) {
			$override['bot_token'] = trim( sanitize_text_field( wp_unslash( $_POST['bot_token'] ) ) );
		}
		if ( isset( $_POST['channel_ids'] ) ) {
			$override['channel_ids'] = sanitize_textarea_field( wp_unslash( $_POST['channel_ids'] ) );
		}
		if ( isset( $_POST['api_base_url'] ) && '' !== trim( (string) $_POST['api_base_url'] ) ) {
			$override['api_base_url'] = untrailingslashit( esc_url_raw( wp_unslash( $_POST['api_base_url'] ) ) );
		}

		$result = $client->test_connection( $override );

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/**
	 * Send a live test message to Telegram or Bale channel(s).
	 *
	 * @return void
	 */
	public function handle_send_test_message() {
		check_ajax_referer( 'wp_payamresan_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'شما مجوز دسترسی به این عملیات را ندارید.' ), 403 );
		}

		$messenger = isset( $_POST['messenger'] ) ? sanitize_key( wp_unslash( $_POST['messenger'] ) ) : '';
		$client    = $this->dispatcher->get_client( $messenger );

		if ( ! $client ) {
			wp_send_json_error( array( 'message' => 'پیام‌رسان انتخاب‌شده نامعتبر است.' ), 400 );
		}

		$override = array();
		if ( isset( $_POST['bot_token'] ) ) {
			$override['bot_token'] = trim( sanitize_text_field( wp_unslash( $_POST['bot_token'] ) ) );
		}
		if ( isset( $_POST['channel_ids'] ) ) {
			$override['channel_ids'] = sanitize_textarea_field( wp_unslash( $_POST['channel_ids'] ) );
		}
		if ( isset( $_POST['api_base_url'] ) && '' !== trim( (string) $_POST['api_base_url'] ) ) {
			$override['api_base_url'] = untrailingslashit( esc_url_raw( wp_unslash( $_POST['api_base_url'] ) ) );
		}

		$custom_text = isset( $_POST['test_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test_message'] ) ) : '';
		$result      = $client->send_test_message( $custom_text, $override );

		if ( ! empty( $result['results'] ) && is_array( $result['results'] ) ) {
			foreach ( $result['results'] as $item ) {
				Logger::log(
					array(
						'post_id'          => 0,
						'post_type'        => '',
						'messenger'        => $messenger,
						'channel_id'       => isset( $item['channel_id'] ) ? $item['channel_id'] : '',
						'trigger_type'     => 'test',
						'status'           => ! empty( $item['success'] ) ? 'success' : 'error',
						'message_id'       => isset( $item['message_id'] ) ? $item['message_id'] : '',
						'error_message'    => isset( $item['error'] ) ? $item['error'] : '',
						'response_excerpt' => isset( $item['response_excerpt'] ) ? $item['response_excerpt'] : '',
						'payload_preview'  => isset( $item['payload_preview'] ) ? $item['payload_preview'] : $custom_text,
					)
				);
			}
		}

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/**
	 * Fetch discovered meta fields (JetEngine/ACF/MetaBox/DB) and sample posts for a post type.
	 *
	 * @return void
	 */
	public function handle_get_post_type_meta() {
		check_ajax_referer( 'wp_payamresan_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
		}

		$post_type      = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$include_hidden = ! empty( $_POST['include_hidden'] );

		$meta_fields  = Meta_Resolver::get_meta_fields_for_post_type( $post_type, $include_hidden );
		$placeholders = Template_Engine::get_standard_placeholders( $post_type );
		$posts        = $this->fetch_recent_posts_list( $post_type, 25 );

		wp_send_json_success(
			array(
				'post_type'    => $post_type,
				'meta_fields'  => array_values( $meta_fields ),
				'placeholders' => $placeholders,
				'sample_posts' => $posts,
			)
		);
	}

	/**
	 * Fetch recent posts for a selected post type (used in Manual Send & Preview UI).
	 *
	 * @return void
	 */
	public function handle_get_posts() {
		check_ajax_referer( 'wp_payamresan_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$search    = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$posts     = $this->fetch_recent_posts_list( $post_type, 40, $search );

		wp_send_json_success(
			array(
				'posts' => $posts,
			)
		);
	}

	/**
	 * Render a live preview of Telegram & Bale messages and meta fields for a post.
	 *
	 * @return void
	 */
	public function handle_preview_message() {
		check_ajax_referer( 'wp_payamresan_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
		}

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$template = isset( $_POST['template'] ) ? Settings::sanitize_template( wp_unslash( $_POST['template'] ) ) : '';

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'لطفاً یک نوشته معتبر برای پیش‌نمایش انتخاب کنید.' ), 400 );
		}

		$preview = $this->dispatcher->preview_post( $post_id, $template );
		if ( empty( $preview['success'] ) ) {
			wp_send_json_error( $preview, 404 );
		}

		wp_send_json_success( $preview );
	}

	/**
	 * Manually dispatch a post to selected messenger(s) via AJAX.
	 *
	 * @return void
	 */
	public function handle_manual_send() {
		check_ajax_referer( 'wp_payamresan_admin', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'شناسه نوشته نامعتبر است.' ), 400 );
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'شما مجوز ارسال این مطلب را ندارید.' ), 403 );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			wp_send_json_error( array( 'message' => 'مطلب مورد نظر یافت نشد.' ), 404 );
		}

		$messengers = array();
		if ( ! empty( $_POST['messengers'] ) && is_array( $_POST['messengers'] ) ) {
			$messengers = array_map( 'sanitize_key', wp_unslash( $_POST['messengers'] ) );
		}

		$custom_template = isset( $_POST['custom_template'] )
			? Settings::sanitize_template( wp_unslash( $_POST['custom_template'] ) )
			: '';

		$custom_channels = array();
		if ( isset( $_POST['telegram_channels'] ) && '' !== trim( (string) $_POST['telegram_channels'] ) ) {
			$custom_channels['telegram'] = sanitize_textarea_field( wp_unslash( $_POST['telegram_channels'] ) );
		}
		if ( isset( $_POST['bale_channels'] ) && '' !== trim( (string) $_POST['bale_channels'] ) ) {
			$custom_channels['bale'] = sanitize_textarea_field( wp_unslash( $_POST['bale_channels'] ) );
		}

		$force = ! empty( $_POST['force'] ) || Post_Type_Detector::is_manual_enabled( $post->post_type );

		$args = array(
			'trigger_type'    => 'manual',
			'messengers'      => $messengers,
			'custom_template' => $custom_template,
			'custom_channels' => $custom_channels,
			'skip_image'      => ! empty( $_POST['skip_image'] ),
			'force'           => $force,
		);

		$result = $this->dispatcher->dispatch_post( $post, $args );
		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result );
	}

	/**
	 * Clear all plugin delivery logs.
	 *
	 * @return void
	 */
	public function handle_clear_logs() {
		check_ajax_referer( 'wp_payamresan_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 );
		}

		Logger::clear_all();
		wp_send_json_success( array( 'message' => 'تمامی گزارش‌های ارسال با موفقیت پاک شدند.' ) );
	}

	/**
	 * Query recent posts of a given post type for dropdowns.
	 *
	 * @param string $post_type Post type slug.
	 * @param int    $limit     Maximum posts.
	 * @param string $search    Optional search keyword.
	 * @return array
	 */
	private function fetch_recent_posts_list( $post_type, $limit = 30, $search = '' ) {
		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'future', 'pending', 'draft', 'private' ),
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$posts = get_posts( $query_args );
		$list  = array();

		foreach ( $posts as $p ) {
			$status_obj   = get_post_status_object( $p->post_status );
			$status_label = $status_obj ? $status_obj->label : $p->post_status;
			$list[]       = array(
				'id'     => $p->ID,
				'title'  => '' !== trim( $p->post_title ) ? $p->post_title : sprintf( '(بدون عنوان #%d)', $p->ID ),
				'status' => $status_label,
				'date'   => get_the_date( 'Y/m/d', $p ),
			);
		}

		return $list;
	}
}
