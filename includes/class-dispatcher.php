<?php
/**
 * Central message dispatcher for Telegram and Bale.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan;

use WP_PayamResan\Messengers\Bale_Client;
use WP_PayamResan\Messengers\Messenger_Interface;
use WP_PayamResan\Messengers\Telegram_Client;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates rendering, channel resolution, delivery, and logging across messengers.
 */
class Dispatcher {

	/**
	 * Registered messenger clients keyed by slug.
	 *
	 * @var array<string, Messenger_Interface>
	 */
	private $clients = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->clients = array(
			'telegram' => new Telegram_Client(),
			'bale'     => new Bale_Client(),
		);
	}

	/**
	 * Retrieve a registered messenger client by slug.
	 *
	 * @param string $id 'telegram' or 'bale'.
	 * @return Messenger_Interface|null
	 */
	public function get_client( $id ) {
		return isset( $this->clients[ $id ] ) ? $this->clients[ $id ] : null;
	}

	/**
	 * Return all registered messenger clients.
	 *
	 * @return array<string, Messenger_Interface>
	 */
	public function get_clients() {
		return $this->clients;
	}

	/**
	 * Return IDs of messengers currently enabled globally.
	 *
	 * @return array
	 */
	public function get_enabled_messenger_ids() {
		$enabled = array();
		foreach ( $this->clients as $id => $client ) {
			if ( $client->is_enabled() ) {
				$enabled[] = $id;
			}
		}
		return $enabled;
	}

	/**
	 * Dispatch a WordPress post to configured messengers and channels.
	 *
	 * @param int|\WP_Post $post Post ID or post object.
	 * @param array        $args Optional dispatch overrides:
	 *                           - messengers (array): subset of ['telegram', 'bale']
	 *                           - trigger_type (string): 'auto'|'manual'|'bulk'
	 *                           - custom_template (string): override template text
	 *                           - custom_channels (array): ['telegram' => string, 'bale' => string]
	 *                           - skip_image (bool): force text-only send
	 *                           - force (bool): send even if post type disabled
	 * @return array
	 */
	public function dispatch_post( $post, array $args = array() ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'success' => false,
				'message' => 'نوشته مورد نظر یافت نشد.',
				'results' => array(),
			);
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return array(
				'success' => false,
				'message' => 'امکان ارسال پیش‌نویس خودکار یا رونوشت (Revision) وجود ندارد.',
				'results' => array(),
			);
		}

		$trigger_type = isset( $args['trigger_type'] ) ? sanitize_key( $args['trigger_type'] ) : 'manual';
		$pt_config    = Settings::get_post_type_config( $post->post_type );
		$force        = ! empty( $args['force'] );

		if ( ! $force && empty( $pt_config['enabled'] ) ) {
			return array(
				'success' => false,
				'message' => 'ارسال برای این پست تایپ در تنظیمات غیرفعال است.',
				'results' => array(),
			);
		}

		$target_messengers = $this->resolve_target_messengers( $pt_config, $args );
		if ( empty( $target_messengers ) ) {
			return array(
				'success' => false,
				'message' => 'هیچ پیام‌رسان فعالی (تلگرام یا بله) برای ارسال این مطلب انتخاب یا فعال نشده است.',
				'results' => array(),
			);
		}

		// Determine template priority: explicit argument > post meta custom text > post type template.
		$template = '';
		if ( isset( $args['custom_template'] ) && '' !== trim( (string) $args['custom_template'] ) ) {
			$template = (string) $args['custom_template'];
		} else {
			$post_custom = get_post_meta( $post->ID, '_wp_payamresan_custom_message', true );
			if ( is_string( $post_custom ) && '' !== trim( $post_custom ) ) {
				$template = $post_custom;
			} else {
				$template = ! empty( $pt_config['template'] ) ? (string) $pt_config['template'] : Settings::get_default_template();
			}
		}

		$image = empty( $args['skip_image'] )
			? Template_Engine::resolve_post_image( $post, $pt_config )
			: array(
				'url'           => '',
				'attachment_id' => 0,
			);

		$options = array(
			'post_url'   => (string) get_permalink( $post ),
			'post_title' => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
		);

		$all_results   = array();
		$any_success   = false;
		$all_succeeded = true;

		foreach ( $target_messengers as $messenger_id ) {
			$client = $this->get_client( $messenger_id );
			if ( ! $client ) {
				continue;
			}

			$channels = $this->resolve_channels_for_post_type( $messenger_id, $pt_config, $args );
			if ( empty( $channels ) ) {
				$error_msg = sprintf( 'هیچ شناسه کانالی برای پیام‌رسان %s تعریف نشده است.', $client->get_label() );
				Logger::log(
					array(
						'post_id'      => $post->ID,
						'post_type'    => $post->post_type,
						'messenger'    => $messenger_id,
						'channel_id'   => '',
						'trigger_type' => $trigger_type,
						'status'       => 'error',
						'error_message'=> $error_msg,
					)
				);
				$all_results[ $messenger_id ] = array(
					array(
						'channel_id' => '',
						'success'    => false,
						'message_id' => '',
						'error'      => $error_msg,
						'warning'    => '',
					),
				);
				$all_succeeded = false;
				continue;
			}

			$rendered_text = $this->render_message_for_messenger( $post, $template, $messenger_id );
			$msg_results   = array();
			$messenger_ok  = false;

			foreach ( $channels as $channel_id ) {
				$send_result = $client->send_post_message( $channel_id, $rendered_text, $image, $options );

				Logger::log(
					array(
						'post_id'          => $post->ID,
						'post_type'        => $post->post_type,
						'messenger'        => $messenger_id,
						'channel_id'       => $channel_id,
						'trigger_type'     => $trigger_type,
						'status'           => $send_result['success'] ? 'success' : 'error',
						'message_id'       => $send_result['message_id'],
						'error_message'    => $send_result['error'],
						'response_excerpt' => $send_result['response_excerpt'],
						'payload_preview'  => $rendered_text,
					)
				);

				if ( $send_result['success'] ) {
					$any_success  = true;
					$messenger_ok = true;
				} else {
					$all_succeeded = false;
				}

				$msg_results[] = array_merge(
					array( 'channel_id' => $channel_id ),
					$send_result
				);
			}

			if ( $messenger_ok ) {
				update_post_meta(
					$post->ID,
					'_wp_payamresan_sent_' . $messenger_id,
					array(
						'timestamp'  => current_time( 'mysql' ),
						'unix'       => time(),
						'trigger'    => $trigger_type,
						'message_id' => isset( $msg_results[0]['message_id'] ) ? $msg_results[0]['message_id'] : '',
					)
				);
			}

			$all_results[ $messenger_id ] = $msg_results;
		}

		update_post_meta(
			$post->ID,
			'_wp_payamresan_last_result',
			array(
				'timestamp' => current_time( 'mysql' ),
				'trigger'   => $trigger_type,
				'success'   => $any_success && $all_succeeded,
				'results'   => $all_results,
			)
		);

		/**
		 * Fires after WP PayamResan completes a dispatch attempt for a post.
		 *
		 * @param int   $post_id     Post ID.
		 * @param array $all_results Results keyed by messenger slug.
		 * @param array $args        Original dispatch arguments.
		 */
		do_action( 'wp_payamresan_after_dispatch', $post->ID, $all_results, $args );

		if ( $any_success && $all_succeeded ) {
			$summary = 'پیام با موفقیت به تمام پیام‌رسان‌های انتخاب‌شده ارسال شد.';
		} elseif ( $any_success ) {
			$summary = 'پیام با موفقیت نسبی ارسال شد (برخی کانال‌ها با خطا مواجه شدند).';
		} else {
			$summary = 'ارسال پیام با خطا مواجه شد. جزئیات را در گزارش ارسال مشاهده کنید.';
		}

		return array(
			'success' => $any_success,
			'all_ok'  => $any_success && $all_succeeded,
			'message' => $summary,
			'results' => $all_results,
		);
	}

	/**
	 * Preview rendered Telegram & Bale messages plus resolved meta values for a post.
	 *
	 * @param int|\WP_Post $post              Post ID or object.
	 * @param string       $template_override Optional template override.
	 * @return array
	 */
	public function preview_post( $post, $template_override = '' ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'success' => false,
				'message' => 'نوشته مورد نظر یافت نشد.',
			);
		}

		$pt_config = Settings::get_post_type_config( $post->post_type );
		$template  = '' !== trim( (string) $template_override )
			? (string) $template_override
			: ( ! empty( $pt_config['template'] ) ? (string) $pt_config['template'] : Settings::get_default_template() );

		$tg_parse_mode = Settings::get( 'telegram', 'parse_mode', 'HTML' );
		$bale_fmt      = Settings::get( 'bale', 'format_mode', 'markdown' );

		$telegram_text = Template_Engine::render_for_telegram( $post, $template, $tg_parse_mode );
		$bale_text     = Template_Engine::render_for_bale( $post, $template, $bale_fmt );
		$image         = Template_Engine::resolve_post_image( $post, $pt_config );

		// Sample discovered meta values for quick debugging/inspection.
		$discovered_meta = Meta_Resolver::get_meta_fields_for_post_type( $post->post_type, false );
		$meta_samples    = array();
		foreach ( $discovered_meta as $key => $field ) {
			$val = Meta_Resolver::resolve_meta_value( $post, $key );
			if ( '' !== $val ) {
				$meta_samples[] = array(
					'key'    => $key,
					'label'  => $field['label'],
					'source' => $field['source'],
					'value'  => Template_Engine::str_sub( $val, 0, 120 ),
				);
			}
			if ( count( $meta_samples ) >= 20 ) {
				break;
			}
		}

		return array(
			'success'       => true,
			'post_id'       => $post->ID,
			'post_title'    => get_the_title( $post ),
			'post_type'     => $post->post_type,
			'telegram_text' => $telegram_text,
			'bale_text'     => $bale_text,
			'image_url'     => $image['url'],
			'meta_samples'  => $meta_samples,
		);
	}

	/**
	 * Determine which messengers should receive this post.
	 *
	 * @param array $pt_config Post type configuration.
	 * @param array $args      Dispatch arguments.
	 * @return array
	 */
	private function resolve_target_messengers( array $pt_config, array $args ) {
		$globally_enabled = $this->get_enabled_messenger_ids();
		if ( empty( $globally_enabled ) ) {
			return array();
		}

		if ( ! empty( $args['messengers'] ) && is_array( $args['messengers'] ) ) {
			$requested = array_map( 'sanitize_key', $args['messengers'] );
			return array_values( array_intersect( $globally_enabled, $requested ) );
		}

		$pt_pref = isset( $pt_config['messengers'] ) ? $pt_config['messengers'] : 'both';
		if ( 'telegram' === $pt_pref ) {
			return in_array( 'telegram', $globally_enabled, true ) ? array( 'telegram' ) : array();
		}
		if ( 'bale' === $pt_pref ) {
			return in_array( 'bale', $globally_enabled, true ) ? array( 'bale' ) : array();
		}

		return $globally_enabled;
	}

	/**
	 * Resolve effective channel IDs for a messenger and post type.
	 *
	 * Priority: explicit dispatch override > post type channel override > global messenger channels.
	 *
	 * @param string $messenger_id 'telegram' or 'bale'.
	 * @param array  $pt_config    Post type config.
	 * @param array  $args         Dispatch arguments.
	 * @return array
	 */
	private function resolve_channels_for_post_type( $messenger_id, array $pt_config, array $args ) {
		if ( ! empty( $args['custom_channels'][ $messenger_id ] ) ) {
			$custom = Settings::parse_channels( (string) $args['custom_channels'][ $messenger_id ] );
			if ( ! empty( $custom ) ) {
				return $custom;
			}
		}

		$pt_key = $messenger_id . '_channels';
		if ( ! empty( $pt_config[ $pt_key ] ) ) {
			$pt_channels = Settings::parse_channels( (string) $pt_config[ $pt_key ] );
			if ( ! empty( $pt_channels ) ) {
				return $pt_channels;
			}
		}

		$global_raw = (string) Settings::get( $messenger_id, 'channel_ids', '' );
		return Settings::parse_channels( $global_raw );
	}

	/**
	 * Render template using the target messenger's configured formatting mode.
	 *
	 * @param \WP_Post $post         Post object.
	 * @param string   $template     Message template.
	 * @param string   $messenger_id 'telegram' or 'bale'.
	 * @return string
	 */
	private function render_message_for_messenger( \WP_Post $post, $template, $messenger_id ) {
		if ( 'telegram' === $messenger_id ) {
			$parse_mode = Settings::get( 'telegram', 'parse_mode', 'HTML' );
			return Template_Engine::render_for_telegram( $post, $template, $parse_mode );
		}

		$format_mode = Settings::get( 'bale', 'format_mode', 'markdown' );
		return Template_Engine::render_for_bale( $post, $template, $format_mode );
	}
}
