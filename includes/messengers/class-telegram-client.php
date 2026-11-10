<?php
/**
 * Telegram Bot API client.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan\Messengers;

use WP_PayamResan\Settings;
use WP_PayamResan\Template_Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Communicates with the Telegram Bot API using WordPress HTTP API.
 */
class Telegram_Client implements Messenger_Interface {

	/**
	 * Messenger identifier.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'telegram';
	}

	/**
	 * Human-readable Persian label.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'تلگرام';
	}

	/**
	 * Whether Telegram is enabled globally.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) Settings::get( 'telegram', 'enabled', false );
	}

	/**
	 * Test Telegram Bot Token and configured channels.
	 *
	 * @param array $override Optional unsaved settings ['bot_token', 'channel_ids', 'api_base_url'].
	 * @return array
	 */
	public function test_connection( array $override = array() ) {
		$config   = $this->resolve_runtime_config( $override );
		$token    = $config['bot_token'];
		$base_url = $config['api_base_url'];
		$channels = Settings::parse_channels( $config['channel_ids'] );

		if ( '' === $token ) {
			return array(
				'success'  => false,
				'message'  => 'توکن ربات تلگرام وارد نشده است.',
				'bot'      => array(),
				'channels' => array(),
			);
		}

		$me = $this->api_request( $base_url, $token, 'getMe', array(), 'GET' );
		if ( ! $me['success'] ) {
			return array(
				'success'  => false,
				'message'  => 'خطا در احراز هویت ربات تلگرام: ' . $me['error'],
				'bot'      => array(),
				'channels' => array(),
			);
		}

		$bot_data = isset( $me['result'] ) && is_array( $me['result'] ) ? $me['result'] : array();
		$bot_id   = isset( $bot_data['id'] ) ? (string) $bot_data['id'] : '';
		$bot_info = array(
			'id'         => $bot_id,
			'first_name' => isset( $bot_data['first_name'] ) ? (string) $bot_data['first_name'] : '',
			'username'   => isset( $bot_data['username'] ) ? '@' . ltrim( (string) $bot_data['username'], '@' ) : '',
		);

		$channel_reports = array();
		$all_channels_ok = true;

		if ( empty( $channels ) ) {
			return array(
				'success'  => true,
				'message'  => sprintf(
					'ربات تلگرام (%s) معتبر است، اما هنوز شناسه کانالی برای بررسی وارد نشده است.',
					$bot_info['username'] ? $bot_info['username'] : $bot_info['first_name']
				),
				'bot'      => $bot_info,
				'channels' => array(),
			);
		}

		foreach ( $channels as $channel_id ) {
			$report            = $this->check_channel_access( $base_url, $token, $channel_id, $bot_id );
			$channel_reports[] = $report;
			if ( ! $report['success'] ) {
				$all_channels_ok = false;
			}
		}

		$summary = $all_channels_ok
			? sprintf( 'اتصال ربات تلگرام (%s) و دسترسی به تمام کانال‌ها با موفقیت تایید شد.', $bot_info['username'] ? $bot_info['username'] : $bot_info['first_name'] )
			: 'ربات متصل شد، اما در دسترسی به یک یا چند کانال خطا وجود دارد.';

		return array(
			'success'  => $all_channels_ok,
			'message'  => $summary,
			'bot'      => $bot_info,
			'channels' => $channel_reports,
		);
	}

	/**
	 * Send a live test message to configured Telegram channels.
	 *
	 * @param string $custom_text Optional custom test text.
	 * @param array  $override    Optional unsaved credentials.
	 * @return array
	 */
	public function send_test_message( $custom_text = '', array $override = array() ) {
		$config   = $this->resolve_runtime_config( $override );
		$token    = $config['bot_token'];
		$base_url = $config['api_base_url'];
		$channels = Settings::parse_channels( $config['channel_ids'] );

		if ( '' === $token ) {
			return array(
				'success' => false,
				'message' => 'توکن ربات تلگرام وارد نشده است.',
				'results' => array(),
			);
		}

		if ( empty( $channels ) ) {
			return array(
				'success' => false,
				'message' => 'هیچ شناسه کانالی برای تلگرام مشخص نشده است.',
				'results' => array(),
			);
		}

		$text = trim( (string) $custom_text );
		if ( '' === $text ) {
			$site_name = html_entity_decode( wp_strip_all_tags( get_bloginfo( 'name' ) ), ENT_QUOTES, 'UTF-8' );
			$text      = sprintf(
				"✅ پیام تست اتصال افزونه WP PayamResan\n\n🌐 سایت: %s\n📡 پیام‌رسان: تلگرام\n🕒 زمان ارسال: %s\n\nاتصال ربات و کانال با موفقیت برقرار است.",
				$site_name,
				wp_date( 'Y/m/d H:i:s' )
			);
		}

		$results = array();
		$all_ok  = true;

		foreach ( $channels as $channel_id ) {
			$res = $this->api_request(
				$base_url,
				$token,
				'sendMessage',
				array(
					'chat_id' => $channel_id,
					'text'    => $text,
				),
				'POST'
			);

			$message_id = '';
			if ( $res['success'] && isset( $res['result']['message_id'] ) ) {
				$message_id = (string) $res['result']['message_id'];
			} else {
				$all_ok = false;
			}

			$results[] = array(
				'channel_id'       => $channel_id,
				'success'          => $res['success'],
				'message_id'       => $message_id,
				'error'            => $res['error'],
				'response_excerpt' => $res['response_excerpt'],
				'payload_preview'  => $text,
			);
		}

		return array(
			'success' => $all_ok,
			'message' => $all_ok ? 'پیام تستی تلگرام با موفقیت به کانال ارسال شد.' : 'ارسال پیام تستی به یک یا چند کانال تلگرام ناموفق بود.',
			'results' => $results,
		);
	}

	/**
	 * Send formatted post content to a Telegram channel.
	 *
	 * @param string $channel_id Target chat/channel ID.
	 * @param string $text       Formatted message content.
	 * @param array  $image      Optional ['url', 'attachment_id'].
	 * @param array  $options    Additional context ['post_url', 'post_title'].
	 * @return array
	 */
	public function send_post_message( $channel_id, $text, array $image = array(), array $options = array() ) {
		$config     = $this->resolve_runtime_config();
		$token      = $config['bot_token'];
		$base_url   = $config['api_base_url'];
		$parse_mode = ( 'HTML' === $config['parse_mode'] ) ? 'HTML' : '';
		$is_html    = ( 'HTML' === $parse_mode );

		if ( '' === $token || '' === trim( (string) $channel_id ) ) {
			return array(
				'success'          => false,
				'message_id'       => '',
				'error'            => 'توکن ربات یا شناسه کانال تلگرام پیکربندی نشده است.',
				'response_excerpt' => '',
				'warning'          => '',
			);
		}

		$reply_markup = $this->build_reply_markup( $options );
		$has_image    = ! empty( $image['url'] ) || ! empty( $image['attachment_id'] );
		$text         = trim( (string) $text );

		if ( '' === $text && ! $has_image ) {
			return array(
				'success'          => false,
				'message_id'       => '',
				'error'            => 'متن پیام برای ارسال خالی است.',
				'response_excerpt' => '',
				'warning'          => '',
			);
		}

		$overflow_mode = isset( $config['caption_overflow_mode'] ) ? $config['caption_overflow_mode'] : 'split';
		$text_len      = Template_Engine::str_len( $text );
		$warning       = '';

		// Handle image + caption delivery respecting Telegram's 1024-char caption limit.
		if ( $has_image ) {
			if ( $text_len <= 1024 ) {
				$photo_res = $this->send_photo_request(
					$base_url,
					$token,
					$channel_id,
					$image,
					$text,
					$parse_mode,
					$reply_markup,
					$config
				);

				if ( $photo_res['success'] ) {
					return $this->format_success( $photo_res, $warning );
				}

				if ( empty( $config['fallback_to_text'] ) ) {
					return $this->format_failure( $photo_res );
				}

				$warning = 'ارسال تصویر ناموفق بود و پیام به صورت متنی ارسال شد: ' . $photo_res['error'];
			} elseif ( 'truncate' === $overflow_mode ) {
				$caption   = Template_Engine::truncate_message( $text, 1024, $is_html );
				$photo_res = $this->send_photo_request(
					$base_url,
					$token,
					$channel_id,
					$image,
					$caption,
					$parse_mode,
					$reply_markup,
					$config
				);

				if ( $photo_res['success'] ) {
					return $this->format_success( $photo_res, $warning );
				}

				if ( empty( $config['fallback_to_text'] ) ) {
					return $this->format_failure( $photo_res );
				}

				$warning = 'ارسال تصویر ناموفق بود و پیام به صورت متنی ارسال شد: ' . $photo_res['error'];
			} elseif ( 'split' === $overflow_mode ) {
				$short_caption = '';
				if ( ! empty( $options['post_title'] ) ) {
					$title         = (string) $options['post_title'];
					$short_caption = $is_html ? '<b>' . htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ) . '</b>' : $title;
					$short_caption = Template_Engine::truncate_message( $short_caption, 1024, $is_html );
				}

				$photo_res = $this->send_photo_request(
					$base_url,
					$token,
					$channel_id,
					$image,
					$short_caption,
					$parse_mode,
					null,
					$config
				);

				if ( ! $photo_res['success'] ) {
					if ( empty( $config['fallback_to_text'] ) ) {
						return $this->format_failure( $photo_res );
					}
					$warning = 'ارسال تصویر ناموفق بود و متن به تنهایی ارسال شد: ' . $photo_res['error'];
				}
			}
			// For 'text_only_if_long' or after 'split' image/fallback, send full text via sendMessage below.
		}

		// Send text message (splitting into <= 4096-char chunks if needed).
		$chunks    = Template_Engine::split_message( $text, 4096, $is_html );
		$last_res  = null;
		$total     = count( $chunks );

		foreach ( $chunks as $idx => $chunk ) {
			$is_last = ( $idx === $total - 1 );
			$params  = array(
				'chat_id'                  => $channel_id,
				'text'                     => $chunk,
				'disable_web_page_preview' => ! empty( $config['disable_web_page_preview'] ),
				'disable_notification'     => ! empty( $config['disable_notification'] ),
			);

			if ( '' !== $parse_mode ) {
				$params['parse_mode'] = $parse_mode;
			}

			if ( $is_last && null !== $reply_markup ) {
				$params['reply_markup'] = $reply_markup;
			}

			$last_res = $this->api_request( $base_url, $token, 'sendMessage', $params, 'POST' );
			if ( ! $last_res['success'] ) {
				return $this->format_failure( $last_res, $warning );
			}
		}

		return null !== $last_res
			? $this->format_success( $last_res, $warning )
			: array(
				'success'          => false,
				'message_id'       => '',
				'error'            => 'متنی برای ارسال وجود ندارد.',
				'response_excerpt' => '',
				'warning'          => $warning,
			);
	}

	/**
	 * Send a photo via local multipart upload or public URL.
	 *
	 * @param string     $base_url     API base URL.
	 * @param string     $token        Bot token.
	 * @param string     $channel_id   Target channel.
	 * @param array      $image        ['url', 'attachment_id'].
	 * @param string     $caption      Photo caption.
	 * @param string     $parse_mode   'HTML' or ''.
	 * @param array|null $reply_markup Inline keyboard markup array or null.
	 * @param array      $config       Runtime config.
	 * @return array
	 */
	private function send_photo_request( $base_url, $token, $channel_id, array $image, $caption, $parse_mode, $reply_markup, array $config ) {
		$fields = array(
			'chat_id'              => (string) $channel_id,
			'disable_notification' => ! empty( $config['disable_notification'] ) ? 'true' : 'false',
		);

		if ( '' !== $caption ) {
			$fields['caption'] = $caption;
		}
		if ( '' !== $parse_mode ) {
			$fields['parse_mode'] = $parse_mode;
		}

		$local_file = $this->get_uploadable_local_file( $image, $config );
		if ( '' !== $local_file ) {
			if ( null !== $reply_markup ) {
				$fields['reply_markup'] = wp_json_encode( $reply_markup );
			}
			return $this->api_multipart_request( $base_url, $token, 'sendPhoto', $fields, 'photo', $local_file );
		}

		if ( empty( $image['url'] ) ) {
			return array(
				'success'          => false,
				'result'           => array(),
				'error'            => 'آدرس تصویر معتبر یافت نشد.',
				'response_excerpt' => '',
			);
		}

		$fields['photo']                = (string) $image['url'];
		$fields['disable_notification'] = ! empty( $config['disable_notification'] );
		if ( null !== $reply_markup ) {
			$fields['reply_markup'] = $reply_markup;
		}

		return $this->api_request( $base_url, $token, 'sendPhoto', $fields, 'POST' );
	}

	/**
	 * Verify channel reachability and bot administrator permissions.
	 *
	 * @param string $base_url   API base URL.
	 * @param string $token      Bot token.
	 * @param string $channel_id Channel ID or @username.
	 * @param string $bot_id     Bot numeric ID.
	 * @return array
	 */
	private function check_channel_access( $base_url, $token, $channel_id, $bot_id ) {
		$chat = $this->api_request(
			$base_url,
			$token,
			'getChat',
			array( 'chat_id' => $channel_id ),
			'GET'
		);

		if ( ! $chat['success'] ) {
			return array(
				'channel_id' => $channel_id,
				'success'    => false,
				'title'      => '',
				'type'       => '',
				'message'    => 'کانال یافت نشد یا ربات به آن دسترسی ندارد: ' . $chat['error'],
			);
		}

		$chat_info  = isset( $chat['result'] ) && is_array( $chat['result'] ) ? $chat['result'] : array();
		$chat_title = isset( $chat_info['title'] ) ? (string) $chat_info['title'] : ( isset( $chat_info['first_name'] ) ? (string) $chat_info['first_name'] : $channel_id );
		$chat_type  = isset( $chat_info['type'] ) ? (string) $chat_info['type'] : '';

		if ( 'private' === $chat_type || '' === $bot_id ) {
			return array(
				'channel_id' => $channel_id,
				'success'    => true,
				'title'      => $chat_title,
				'type'       => $chat_type,
				'message'    => sprintf( 'دسترسی به گفت‌وگو «%s» تایید شد.', $chat_title ),
			);
		}

		$member = $this->api_request(
			$base_url,
			$token,
			'getChatMember',
			array(
				'chat_id' => $channel_id,
				'user_id' => $bot_id,
			),
			'GET'
		);

		if ( ! $member['success'] ) {
			return array(
				'channel_id' => $channel_id,
				'success'    => false,
				'title'      => $chat_title,
				'type'       => $chat_type,
				'message'    => sprintf( 'امکان بررسی عضویت ربات در «%s» وجود ندارد (%s). مطمئن شوید ربات ادمین است.', $chat_title, $member['error'] ),
			);
		}

		$member_info = isset( $member['result'] ) && is_array( $member['result'] ) ? $member['result'] : array();
		$status      = isset( $member_info['status'] ) ? (string) $member_info['status'] : '';

		if ( ! in_array( $status, array( 'administrator', 'creator' ), true ) ) {
			return array(
				'channel_id' => $channel_id,
				'success'    => false,
				'title'      => $chat_title,
				'type'       => $chat_type,
				'message'    => sprintf( 'ربات در «%s» مدیر (Admin) نیست (وضعیت فعلی: %s).', $chat_title, $status ),
			);
		}

		if ( 'channel' === $chat_type && array_key_exists( 'can_post_messages', $member_info ) && empty( $member_info['can_post_messages'] ) ) {
			return array(
				'channel_id' => $channel_id,
				'success'    => false,
				'title'      => $chat_title,
				'type'       => $chat_type,
				'message'    => sprintf( 'ربات در کانال «%s» ادمین است اما مجوز ارسال پیام (Post Messages) ندارد.', $chat_title ),
			);
		}

		return array(
			'channel_id' => $channel_id,
			'success'    => true,
			'title'      => $chat_title,
			'type'       => $chat_type,
			'message'    => sprintf( 'ربات در «%s» با مجوز ارسال پیام تایید شد.', $chat_title ),
		);
	}

	/**
	 * Build optional inline keyboard button linking to the WordPress post.
	 *
	 * @param array $options Message options.
	 * @return array|null
	 */
	private function build_reply_markup( array $options ) {
		if ( empty( Settings::get( 'general', 'show_read_more_button', true ) ) ) {
			return null;
		}
		if ( empty( $options['post_url'] ) || ! filter_var( $options['post_url'], FILTER_VALIDATE_URL ) ) {
			return null;
		}

		$label = trim( (string) Settings::get( 'general', 'read_more_button_text', 'مشاهده مطلب' ) );
		if ( '' === $label ) {
			$label = 'مشاهده مطلب';
		}

		return array(
			'inline_keyboard' => array(
				array(
					array(
						'text' => $label,
						'url'  => (string) $options['post_url'],
					),
				),
			),
		);
	}

	/**
	 * Locate a local image file on disk within Telegram's 10 MB upload limit.
	 *
	 * @param array $image  Image data ['url', 'attachment_id'].
	 * @param array $config Messenger config.
	 * @return string Absolute local path or empty string.
	 */
	private function get_uploadable_local_file( array $image, array $config ) {
		if ( empty( $config['upload_local_media'] ) || empty( $image['attachment_id'] ) ) {
			return '';
		}

		$attachment_id = absint( $image['attachment_id'] );
		$file_path     = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return '';
		}

		// Prefer 'large' thumbnail size if original exceeds 5 MB or is WebP when JPEG/PNG exists.
		$size_limit = 10 * 1024 * 1024;
		$filesize   = @filesize( $file_path );
		if ( false === $filesize || $filesize > $size_limit ) {
			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( is_array( $meta ) && ! empty( $meta['sizes']['large']['file'] ) ) {
				$large_path = path_join( dirname( $file_path ), $meta['sizes']['large']['file'] );
				if ( file_exists( $large_path ) && is_readable( $large_path ) && @filesize( $large_path ) <= $size_limit ) {
					return $large_path;
				}
			}
			return '';
		}

		return $file_path;
	}

	/**
	 * Execute a JSON or query Telegram Bot API request.
	 *
	 * @param string $base_url    API base URL.
	 * @param string $token       Bot token.
	 * @param string $method      Telegram API method name.
	 * @param array  $params      Request parameters.
	 * @param string $http_method HTTP method ('GET' or 'POST').
	 * @return array
	 */
	private function api_request( $base_url, $token, $method, array $params = array(), $http_method = 'POST' ) {
		$endpoint = untrailingslashit( $base_url ) . '/bot' . rawurlencode( $token ) . '/' . $method;

		if ( 'GET' === strtoupper( $http_method ) ) {
			if ( ! empty( $params ) ) {
				$endpoint = add_query_arg( $params, $endpoint );
			}
			$response = wp_remote_get(
				$endpoint,
				array(
					'timeout' => 20,
				)
			);
		} else {
			$response = wp_remote_post(
				$endpoint,
				array(
					'timeout' => 20,
					'headers' => array(
						'Content-Type' => 'application/json; charset=utf-8',
					),
					'body'    => wp_json_encode( $params ),
				)
			);
		}

		return $this->parse_api_response( $response );
	}

	/**
	 * Execute a multipart/form-data file upload request to Telegram Bot API.
	 *
	 * @param string $base_url   API base URL.
	 * @param string $token      Bot token.
	 * @param string $method     API method.
	 * @param array  $fields     Text form fields.
	 * @param string $file_field Form field name for file ('photo').
	 * @param string $file_path  Local file path.
	 * @return array
	 */
	private function api_multipart_request( $base_url, $token, $method, array $fields, $file_field, $file_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$file_contents = file_get_contents( $file_path );
		if ( false === $file_contents ) {
			return array(
				'success'          => false,
				'result'           => array(),
				'error'            => 'خواندن فایل تصویر از سرور ناموفق بود.',
				'response_excerpt' => '',
			);
		}

		$boundary  = '----WPPayamResanTG' . wp_generate_password( 20, false );
		$filetype  = wp_check_filetype( $file_path );
		$mime_type = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';
		$filename  = wp_basename( $file_path );

		$body = '';
		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\n";
			$body .= 'Content-Disposition: form-data; name="' . $name . "\"\r\n\r\n";
			$body .= $value . "\r\n";
		}

		$body .= "--{$boundary}\r\n";
		$body .= 'Content-Disposition: form-data; name="' . $file_field . '"; filename="' . $filename . "\"\r\n";
		$body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
		$body .= $file_contents . "\r\n";
		$body .= "--{$boundary}--\r\n";

		$endpoint = untrailingslashit( $base_url ) . '/bot' . rawurlencode( $token ) . '/' . $method;
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 35,
				'headers' => array(
					'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		return $this->parse_api_response( $response );
	}

	/**
	 * Parse HTTP & JSON response from Telegram Bot API.
	 *
	 * @param array|\WP_Error $response wp_remote_* response.
	 * @return array
	 */
	private function parse_api_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'success'          => false,
				'result'           => array(),
				'error'            => 'خطای ارتباط شبکه با سرور تلگرام: ' . $response->get_error_message(),
				'response_excerpt' => $response->get_error_message(),
			);
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			return array(
				'success'          => false,
				'result'           => array(),
				'error'            => sprintf( 'پاسخ نامعتبر از سرور تلگرام (کد HTTP: %d).', $code ),
				'response_excerpt' => Template_Engine::str_sub( $raw_body, 0, 500 ),
			);
		}

		if ( empty( $decoded['ok'] ) ) {
			$desc       = isset( $decoded['description'] ) ? (string) $decoded['description'] : 'خطای نامشخص';
			$error_code = isset( $decoded['error_code'] ) ? (int) $decoded['error_code'] : (int) $code;
			return array(
				'success'          => false,
				'result'           => array(),
				'error'            => $this->translate_telegram_error( $error_code, $desc ),
				'response_excerpt' => Template_Engine::str_sub( $raw_body, 0, 500 ),
			);
		}

		return array(
			'success'          => true,
			'result'           => isset( $decoded['result'] ) ? $decoded['result'] : array(),
			'error'            => '',
			'response_excerpt' => Template_Engine::str_sub( $raw_body, 0, 500 ),
		);
	}

	/**
	 * Translate common Telegram API errors to clear Persian explanations.
	 *
	 * @param int    $code        Telegram error code.
	 * @param string $description Raw API description.
	 * @return string
	 */
	private function translate_telegram_error( $code, $description ) {
		if ( 401 === $code || false !== stripos( $description, 'Unauthorized' ) ) {
			return 'توکن ربات تلگرام نامعتبر است (401 Unauthorized).';
		}
		if ( false !== stripos( $description, 'chat not found' ) ) {
			return 'کانال یا چت مورد نظر یافت نشد (Chat not found). شناسه کانال را بررسی کنید.';
		}
		if ( false !== stripos( $description, 'not enough rights' ) || false !== stripos( $description, 'have no rights' ) ) {
			return 'ربات مجوز ارسال پیام در این کانال را ندارد (دسترسی ادمین را بررسی کنید).';
		}
		if ( false !== stripos( $description, 'kicked' ) || false !== stripos( $description, 'not a member' ) ) {
			return 'ربات عضو این کانال نیست یا از آن حذف شده است.';
		}
		return sprintf( '%s (کد: %d)', $description, $code );
	}

	/**
	 * Resolve effective configuration merged with optional runtime overrides.
	 *
	 * @param array $override Optional overrides.
	 * @return array
	 */
	private function resolve_runtime_config( array $override = array() ) {
		$saved = Settings::get( 'telegram', '', array() );
		$merged = wp_parse_args( $override, is_array( $saved ) ? $saved : array() );
		if ( empty( $merged['api_base_url'] ) ) {
			$merged['api_base_url'] = 'https://api.telegram.org';
		}
		return $merged;
	}

	/**
	 * Build standardized success result.
	 *
	 * @param array  $res     Parsed API response.
	 * @param string $warning Optional warning message.
	 * @return array
	 */
	private function format_success( array $res, $warning = '' ) {
		$message_id = isset( $res['result']['message_id'] ) ? (string) $res['result']['message_id'] : '';
		return array(
			'success'          => true,
			'message_id'       => $message_id,
			'error'            => '',
			'response_excerpt' => $res['response_excerpt'],
			'warning'          => $warning,
		);
	}

	/**
	 * Build standardized failure result.
	 *
	 * @param array  $res     Parsed API response.
	 * @param string $warning Optional warning message.
	 * @return array
	 */
	private function format_failure( array $res, $warning = '' ) {
		return array(
			'success'          => false,
			'message_id'       => '',
			'error'            => $res['error'],
			'response_excerpt' => $res['response_excerpt'],
			'warning'          => $warning,
		);
	}
}
