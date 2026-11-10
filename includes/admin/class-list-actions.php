<?php
/**
 * Posts list row actions and bulk actions for manual sending.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan\Admin;

use WP_PayamResan\Dispatcher;
use WP_PayamResan\Post_Type_Detector;

defined( 'ABSPATH' ) || exit;

/**
 * Adds row send links and bulk send actions on WordPress post list tables.
 */
class List_Actions {

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
	 * Register list table hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'post_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'register_bulk_actions' ), 25 );
		add_action( 'admin_action_wp_payamresan_row_send', array( $this, 'handle_row_send' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	/**
	 * Register bulk actions for all enabled post types.
	 *
	 * @return void
	 */
	public function register_bulk_actions() {
		$post_types = Post_Type_Detector::get_enabled_post_type_slugs();
		foreach ( $post_types as $post_type ) {
			if ( ! Post_Type_Detector::is_manual_enabled( $post_type ) ) {
				continue;
			}
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'add_bulk_action' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk_action' ), 10, 3 );
		}
	}

	/**
	 * Append quick manual send action link to post row actions.
	 *
	 * @param array    $actions Existing row actions.
	 * @param \WP_Post $post    Current post object.
	 * @return array
	 */
	public function add_row_action( array $actions, \WP_Post $post ) {
		if ( empty( $this->dispatcher->get_enabled_messenger_ids() ) ) {
			return $actions;
		}

		if ( ! Post_Type_Detector::is_manual_enabled( $post->post_type ) ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		if ( 'trash' === $post->post_status ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'wp_payamresan_row_send',
					'post_id' => $post->ID,
				),
				admin_url( 'admin.php' )
			),
			'wp_payamresan_row_send_' . $post->ID
		);

		$actions['wp_payamresan_send'] = sprintf(
			'<a href="%s" title="%s">%s</a>',
			esc_url( $url ),
			esc_attr( 'ارسال فوری این مطلب به کانال‌های تلگرام/بله' ),
			esc_html( 'ارسال به پیام‌رسان' )
		);

		return $actions;
	}

	/**
	 * Add bulk send action to edit table dropdown.
	 *
	 * @param array $bulk_actions Existing bulk actions.
	 * @return array
	 */
	public function add_bulk_action( array $bulk_actions ) {
		if ( ! empty( $this->dispatcher->get_enabled_messenger_ids() ) ) {
			$bulk_actions['wp_payamresan_bulk_send'] = 'ارسال به پیام‌رسان‌ها (WP PayamResan)';
		}
		return $bulk_actions;
	}

	/**
	 * Handle single row manual send click.
	 *
	 * @return void
	 */
	public function handle_row_send() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_die( 'شناسه مطلب نامعتبر است.' );
		}

		check_admin_referer( 'wp_payamresan_row_send_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'شما مجوز ارسال این مطلب را ندارید.' );
		}

		$result = $this->dispatcher->dispatch_post(
			$post_id,
			array(
				'trigger_type' => 'manual',
				'force'        => true,
			)
		);

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$post     = get_post( $post_id );
			$pt       = $post ? $post->post_type : 'post';
			$redirect = admin_url( 'edit.php?post_type=' . rawurlencode( $pt ) );
		}

		$redirect = add_query_arg(
			array(
				'wppr_sent'    => ! empty( $result['success'] ) ? 1 : 0,
				'wppr_failed'  => empty( $result['success'] ) ? 1 : 0,
				'wppr_message' => rawurlencode( (string) $result['message'] ),
			),
			$redirect
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle bulk send action for selected posts.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction    Action name.
	 * @param array  $post_ids    Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $doaction, array $post_ids ) {
		if ( 'wp_payamresan_bulk_send' !== $doaction ) {
			return $redirect_to;
		}

		$sent_count   = 0;
		$failed_count = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = absint( $post_id );
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				++$failed_count;
				continue;
			}

			$res = $this->dispatcher->dispatch_post(
				$post_id,
				array(
					'trigger_type' => 'bulk',
					'force'        => true,
				)
			);

			if ( ! empty( $res['success'] ) ) {
				++$sent_count;
			} else {
				++$failed_count;
			}
		}

		return add_query_arg(
			array(
				'wppr_sent'   => $sent_count,
				'wppr_failed' => $failed_count,
			),
			$redirect_to
		);
	}

	/**
	 * Show summary admin notice after row/bulk sends.
	 *
	 * @return void
	 */
	public function render_admin_notices() {
		if ( ! isset( $_GET['wppr_sent'] ) && ! isset( $_GET['wppr_failed'] ) ) {
			return;
		}

		$sent   = isset( $_GET['wppr_sent'] ) ? absint( $_GET['wppr_sent'] ) : 0;
		$failed = isset( $_GET['wppr_failed'] ) ? absint( $_GET['wppr_failed'] ) : 0;
		$msg    = isset( $_GET['wppr_message'] ) ? sanitize_text_field( wp_unslash( $_GET['wppr_message'] ) ) : '';

		if ( '' !== $msg ) {
			$class = $sent > 0 ? 'notice-success' : 'notice-error';
			printf(
				'<div class="notice %1$s is-dismissible"><p><strong>WP PayamResan:</strong> %2$s</p></div>',
				esc_attr( $class ),
				esc_html( $msg )
			);
			return;
		}

		$class = ( $failed > 0 && 0 === $sent ) ? 'notice-error' : ( $failed > 0 ? 'notice-warning' : 'notice-success' );
		printf(
			'<div class="notice %1$s is-dismissible"><p><strong>WP PayamResan:</strong> %2$d مطلب با موفقیت ارسال شد%3$s.</p></div>',
			esc_attr( $class ),
			$sent,
			$failed > 0 ? sprintf( ' و ارسال %d مطلب با خطا مواجه شد (جزئیات در گزارش ارسال)', $failed ) : ''
		);
	}
}
