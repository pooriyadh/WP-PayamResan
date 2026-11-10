<?php
/**
 * Automatic publishing listener compatible with Gutenberg, Classic Editor, JetEngine, ACF, and Meta Box.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan;

defined( 'ABSPATH' ) || exit;

/**
 * Listens for post publish transitions and executes automatic sends safely
 * after third-party meta boxes have persisted their custom fields.
 */
class Auto_Sender {

	/**
	 * Dispatcher instance.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Post IDs queued during the current PHP request for shutdown fallback.
	 *
	 * @var array<int, bool>
	 */
	private $request_pending_ids = array();

	/**
	 * Constructor.
	 *
	 * @param Dispatcher $dispatcher Dispatcher instance.
	 */
	public function __construct( Dispatcher $dispatcher ) {
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Register WordPress lifecycle hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_late_save_post' ), 9999, 2 );
		add_action( 'shutdown', array( $this, 'on_shutdown' ) );
		add_action( 'wp_payamresan_cron_send_post', array( $this, 'on_cron_send_post' ), 10, 1 );
	}

	/**
	 * Detect transitions into 'publish' status.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Previous post status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( 'publish' !== $new_status ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( empty( $this->dispatcher->get_enabled_messenger_ids() ) ) {
			return;
		}

		if ( ! Post_Type_Detector::is_auto_enabled( $post->post_type ) ) {
			return;
		}

		$is_update = ( 'publish' === $old_status );
		$pt_config = Settings::get_post_type_config( $post->post_type );

		if ( $is_update && empty( $pt_config['send_on_update'] ) ) {
			return;
		}

		// Record pending marker for late save_post / Gutenberg meta-box-loader / WP-Cron.
		set_transient(
			$this->pending_transient_key( $post->ID ),
			array(
				'is_update' => $is_update,
				'queued_at' => time(),
			),
			180
		);

		$mode = Settings::get( 'general', 'auto_send_mode', 'smart' );

		if ( 'cron' === $mode ) {
			$delay = max( 3, absint( Settings::get( 'general', 'cron_delay', 10 ) ) );
			$this->schedule_cron_send( $post->ID, $delay );
			return;
		}

		if ( 'smart' === $mode && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			// Gutenberg saves post via REST first, then triggers post.php?meta-box-loader=1.
			// Schedule a short fallback cron in case the CPT has no classic meta boxes.
			$this->schedule_cron_send( $post->ID, 8 );
			return;
		}

		$this->request_pending_ids[ $post->ID ] = true;
	}

	/**
	 * Run after standard meta box handlers (JetEngine, ACF, Meta Box, WooCommerce) on save_post.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function on_late_save_post( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$mode = Settings::get( 'general', 'auto_send_mode', 'smart' );
		if ( 'cron' === $mode ) {
			return;
		}

		// In smart mode, wait for meta-box-loader or fallback cron during REST publish requests.
		if ( 'smart' === $mode && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$pending = get_transient( $this->pending_transient_key( $post_id ) );
		if ( ! is_array( $pending ) ) {
			return;
		}

		unset( $this->request_pending_ids[ $post_id ] );
		$this->process_pending_post( $post_id );
	}

	/**
	 * Shutdown fallback for non-REST transitions that did not trigger save_post.
	 *
	 * @return void
	 */
	public function on_shutdown() {
		if ( empty( $this->request_pending_ids ) ) {
			return;
		}

		foreach ( array_keys( $this->request_pending_ids ) as $post_id ) {
			$pending = get_transient( $this->pending_transient_key( $post_id ) );
			if ( is_array( $pending ) ) {
				$this->process_pending_post( $post_id );
			}
		}

		$this->request_pending_ids = array();
	}

	/**
	 * WP-Cron callback for delayed or Gutenberg-fallback automatic sends.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_cron_send_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}
		$this->process_pending_post( $post_id );
	}

	/**
	 * Acquire deduplication lock, re-check opt-out meta, and dispatch post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function process_pending_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}

		$lock_key = 'wp_payamresan_lock_' . $post_id;
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 30 );

		$pending = get_transient( $this->pending_transient_key( $post_id ) );
		delete_transient( $this->pending_transient_key( $post_id ) );
		$this->unschedule_cron_send( $post_id );

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			delete_transient( $lock_key );
			return;
		}

		if ( ! Post_Type_Detector::is_auto_enabled( $post->post_type ) ) {
			delete_transient( $lock_key );
			return;
		}

		// Re-check per-post opt-out saved by the editor meta box.
		if ( '1' === (string) get_post_meta( $post_id, '_wp_payamresan_disable_auto', true ) ) {
			delete_transient( $lock_key );
			return;
		}

		$is_update = is_array( $pending ) && ! empty( $pending['is_update'] );
		$pt_config = Settings::get_post_type_config( $post->post_type );

		if ( $is_update && empty( $pt_config['send_on_update'] ) ) {
			delete_transient( $lock_key );
			return;
		}

		// Guard against duplicate auto-sends on first publish if another process already sent it.
		if ( ! $is_update ) {
			$target_messengers = $this->resolve_pending_messengers( $post_id, $pt_config );
			if ( empty( $target_messengers ) ) {
				delete_transient( $lock_key );
				return;
			}
			$this->dispatcher->dispatch_post(
				$post,
				array(
					'trigger_type' => 'auto',
					'messengers'   => $target_messengers,
				)
			);
		} else {
			$this->dispatcher->dispatch_post(
				$post,
				array(
					'trigger_type' => 'auto',
				)
			);
		}

		delete_transient( $lock_key );
	}

	/**
	 * Determine which enabled messengers have not yet received the initial auto-publish for this post.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $pt_config Post type configuration.
	 * @return array
	 */
	private function resolve_pending_messengers( $post_id, array $pt_config ) {
		$enabled = $this->dispatcher->get_enabled_messenger_ids();
		$pref    = isset( $pt_config['messengers'] ) ? $pt_config['messengers'] : 'both';
		$targets = array();

		foreach ( $enabled as $messenger_id ) {
			if ( 'both' !== $pref && $pref !== $messenger_id ) {
				continue;
			}
			$already_sent = get_post_meta( $post_id, '_wp_payamresan_sent_' . $messenger_id, true );
			if ( empty( $already_sent ) ) {
				$targets[] = $messenger_id;
			}
		}

		return $targets;
	}

	/**
	 * Schedule a single WP-Cron auto-send event if not already scheduled.
	 *
	 * @param int $post_id Post ID.
	 * @param int $delay   Delay in seconds.
	 * @return void
	 */
	private function schedule_cron_send( $post_id, $delay ) {
		if ( ! wp_next_scheduled( 'wp_payamresan_cron_send_post', array( $post_id ) ) ) {
			wp_schedule_single_event( time() + $delay, 'wp_payamresan_cron_send_post', array( $post_id ) );
		}
	}

	/**
	 * Clear any pending scheduled auto-send event for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function unschedule_cron_send( $post_id ) {
		wp_clear_scheduled_hook( 'wp_payamresan_cron_send_post', array( $post_id ) );
	}

	/**
	 * Transient key storing pending auto-send context.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function pending_transient_key( $post_id ) {
		return 'wp_payamresan_pending_' . absint( $post_id );
	}
}
