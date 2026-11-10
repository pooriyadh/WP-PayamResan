<?php
/**
 * Settings repository and sanitizer for WP PayamResan.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan;

defined( 'ABSPATH' ) || exit;

/**
 * Manages plugin configuration, defaults, and sanitization.
 */
class Settings {

	/**
	 * WordPress option key.
	 */
	const OPTION_KEY = 'wp_payamresan_settings';

	/**
	 * Cached settings array for the current request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default message template shared as baseline for post types.
	 *
	 * @return string
	 */
	public static function get_default_template() {
		return "<b>{post_title}</b>\n\n{post_excerpt}\n\n{if:tags}🏷 {tags}\n{/if}🔗 <a href=\"{post_url}\">مطالعه ادامه مطلب</a>";
	}

	/**
	 * Return full default configuration structure.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'general'    => array(
				'auto_send_mode'           => 'smart',
				'cron_delay'               => 10,
				'include_non_public_cpts'  => false,
				'show_read_more_button'    => true,
				'read_more_button_text'    => 'مشاهده مطلب',
				'excerpt_length'           => 35,
				'content_length'           => 250,
				'log_retention_limit'      => 500,
				'delete_data_on_uninstall' => false,
			),
			'telegram'   => array(
				'enabled'                  => false,
				'bot_token'                => '',
				'channel_ids'              => '',
				'api_base_url'             => 'https://api.telegram.org',
				'parse_mode'               => 'HTML',
				'upload_local_media'       => true,
				'caption_overflow_mode'    => 'split',
				'disable_web_page_preview' => false,
				'disable_notification'     => false,
				'fallback_to_text'         => true,
			),
			'bale'       => array(
				'enabled'            => false,
				'bot_token'          => '',
				'channel_ids'        => '',
				'api_base_url'       => 'https://tapi.bale.ai',
				'format_mode'        => 'markdown',
				'upload_local_media' => true,
				'fallback_to_text'   => true,
			),
			'post_types' => array(
				'post' => self::get_default_post_type_config( true ),
			),
		);
	}

	/**
	 * Default configuration for an individual post type.
	 *
	 * @param bool $enabled Whether the post type is enabled initially.
	 * @return array
	 */
	public static function get_default_post_type_config( $enabled = false ) {
		return array(
			'enabled'           => (bool) $enabled,
			'send_mode'         => 'auto_and_manual',
			'send_on_update'    => false,
			'messengers'        => 'both',
			'telegram_channels' => '',
			'bale_channels'     => '',
			'image_source'      => 'featured',
			'image_meta_key'    => '',
			'template'          => self::get_default_template(),
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		self::$cache = self::merge_with_defaults( $saved );
		return self::$cache;
	}

	/**
	 * Merge arbitrary settings array with defaults safely.
	 *
	 * @param array $saved Stored settings.
	 * @return array
	 */
	public static function merge_with_defaults( array $saved ) {
		$defaults = self::get_defaults();

		$merged = array(
			'general'    => wp_parse_args( isset( $saved['general'] ) && is_array( $saved['general'] ) ? $saved['general'] : array(), $defaults['general'] ),
			'telegram'   => wp_parse_args( isset( $saved['telegram'] ) && is_array( $saved['telegram'] ) ? $saved['telegram'] : array(), $defaults['telegram'] ),
			'bale'       => wp_parse_args( isset( $saved['bale'] ) && is_array( $saved['bale'] ) ? $saved['bale'] : array(), $defaults['bale'] ),
			'post_types' => array(),
		);

		if ( isset( $saved['post_types'] ) && is_array( $saved['post_types'] ) ) {
			foreach ( $saved['post_types'] as $post_type => $config ) {
				$clean_pt = sanitize_key( $post_type );
				if ( '' === $clean_pt || ! is_array( $config ) ) {
					continue;
				}
				$merged['post_types'][ $clean_pt ] = wp_parse_args( $config, self::get_default_post_type_config( false ) );
			}
		} elseif ( ! isset( $saved['post_types'] ) ) {
			$merged['post_types'] = $defaults['post_types'];
		}

		return $merged;
	}

	/**
	 * Retrieve a specific settings section or nested key.
	 *
	 * @param string $section Section name ('general', 'telegram', 'bale', 'post_types').
	 * @param string $key     Optional key within section.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( $section, $key = '', $default = null ) {
		$all = self::get_all();
		if ( ! isset( $all[ $section ] ) ) {
			return $default;
		}
		if ( '' === $key ) {
			return $all[ $section ];
		}
		return array_key_exists( $key, $all[ $section ] ) ? $all[ $section ][ $key ] : $default;
	}

	/**
	 * Get configuration for a specific post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array
	 */
	public static function get_post_type_config( $post_type ) {
		$post_types = self::get( 'post_types', '', array() );
		if ( isset( $post_types[ $post_type ] ) && is_array( $post_types[ $post_type ] ) ) {
			return wp_parse_args( $post_types[ $post_type ], self::get_default_post_type_config( false ) );
		}
		return self::get_default_post_type_config( false );
	}

	/**
	 * Update and persist settings after sanitization.
	 *
	 * @param array $raw_input Raw settings input (can be partial by section).
	 * @return array Saved sanitized settings.
	 */
	public static function update( array $raw_input ) {
		$current = self::get_all();
		$updated = $current;

		if ( isset( $raw_input['general'] ) && is_array( $raw_input['general'] ) ) {
			$updated['general'] = self::sanitize_general( $raw_input['general'], $current['general'] );
		}

		if ( isset( $raw_input['telegram'] ) && is_array( $raw_input['telegram'] ) ) {
			$updated['telegram'] = self::sanitize_telegram( $raw_input['telegram'], $current['telegram'] );
		}

		if ( isset( $raw_input['bale'] ) && is_array( $raw_input['bale'] ) ) {
			$updated['bale'] = self::sanitize_bale( $raw_input['bale'], $current['bale'] );
		}

		if ( isset( $raw_input['post_types'] ) && is_array( $raw_input['post_types'] ) ) {
			$sanitized_pts         = self::sanitize_post_types( $raw_input['post_types'] );
			$updated['post_types'] = array_merge( $current['post_types'], $sanitized_pts );
		}

		update_option( self::OPTION_KEY, $updated );
		self::$cache = $updated;

		return $updated;
	}

	/**
	 * Reset in-memory cache.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		self::$cache = null;
	}

	/**
	 * Parse a comma/newline-separated string of channel IDs or URLs into normalized array.
	 *
	 * @param string $raw Raw channel string.
	 * @return array List of normalized chat IDs.
	 */
	public static function parse_channels( $raw ) {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}

		$parts = preg_split( '/[\r\n,]+/', $raw );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$channels = array();
		foreach ( $parts as $part ) {
			$normalized = self::normalize_channel_id( $part );
			if ( '' !== $normalized && ! in_array( $normalized, $channels, true ) ) {
				$channels[] = $normalized;
			}
		}

		return $channels;
	}

	/**
	 * Normalize channel usernames, URLs (t.me/foo, ble.ir/foo), or numeric chat IDs.
	 *
	 * @param string $value Raw channel input.
	 * @return string
	 */
	public static function normalize_channel_id( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '#^https?://(?:t\.me|telegram\.me|ble\.ir)/([A-Za-z0-9_]+)(?:/)?$#i', $value, $matches ) ) {
			return '@' . $matches[1];
		}

		if ( preg_match( '/^-?\d+$/', $value ) ) {
			return $value;
		}

		if ( 0 !== strpos( $value, '@' ) && preg_match( '/^[A-Za-z0-9_]{3,}$/', $value ) ) {
			return '@' . $value;
		}

		return $value;
	}

	/**
	 * Sanitize general settings.
	 *
	 * @param array $input    Submitted data.
	 * @param array $fallback Previous/default data.
	 * @return array
	 */
	private static function sanitize_general( array $input, array $fallback ) {
		$allowed_modes = array( 'smart', 'cron', 'immediate' );
		$auto_mode     = isset( $input['auto_send_mode'] ) ? sanitize_key( $input['auto_send_mode'] ) : $fallback['auto_send_mode'];
		if ( ! in_array( $auto_mode, $allowed_modes, true ) ) {
			$auto_mode = 'smart';
		}

		$cron_delay = isset( $input['cron_delay'] ) ? absint( $input['cron_delay'] ) : (int) $fallback['cron_delay'];
		$cron_delay = max( 3, min( 300, $cron_delay ) );

		$excerpt_len = isset( $input['excerpt_length'] ) ? absint( $input['excerpt_length'] ) : (int) $fallback['excerpt_length'];
		$excerpt_len = max( 5, min( 300, $excerpt_len ) );

		$content_len = isset( $input['content_length'] ) ? absint( $input['content_length'] ) : (int) $fallback['content_length'];
		$content_len = max( 20, min( 2000, $content_len ) );

		$log_limit = isset( $input['log_retention_limit'] ) ? absint( $input['log_retention_limit'] ) : (int) $fallback['log_retention_limit'];
		$log_limit = max( 50, min( 10000, $log_limit ) );

		return array(
			'auto_send_mode'           => $auto_mode,
			'cron_delay'               => $cron_delay,
			'include_non_public_cpts'  => ! empty( $input['include_non_public_cpts'] ),
			'show_read_more_button'    => ! empty( $input['show_read_more_button'] ),
			'read_more_button_text'    => isset( $input['read_more_button_text'] ) && '' !== trim( (string) $input['read_more_button_text'] )
				? sanitize_text_field( wp_unslash( $input['read_more_button_text'] ) )
				: 'مشاهده مطلب',
			'excerpt_length'           => $excerpt_len,
			'content_length'           => $content_len,
			'log_retention_limit'      => $log_limit,
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
		);
	}

	/**
	 * Sanitize Telegram messenger settings.
	 *
	 * @param array $input    Submitted data.
	 * @param array $fallback Previous/default data.
	 * @return array
	 */
	private static function sanitize_telegram( array $input, array $fallback ) {
		$parse_mode = isset( $input['parse_mode'] ) ? (string) $input['parse_mode'] : $fallback['parse_mode'];
		if ( ! in_array( $parse_mode, array( 'HTML', 'none' ), true ) ) {
			$parse_mode = 'HTML';
		}

		$overflow = isset( $input['caption_overflow_mode'] ) ? sanitize_key( $input['caption_overflow_mode'] ) : $fallback['caption_overflow_mode'];
		if ( ! in_array( $overflow, array( 'split', 'truncate', 'text_only_if_long' ), true ) ) {
			$overflow = 'split';
		}

		$base_url = isset( $input['api_base_url'] ) ? trim( esc_url_raw( wp_unslash( $input['api_base_url'] ) ) ) : $fallback['api_base_url'];
		$base_url = '' !== $base_url ? untrailingslashit( $base_url ) : 'https://api.telegram.org';

		return array(
			'enabled'                  => ! empty( $input['enabled'] ),
			'bot_token'                => isset( $input['bot_token'] ) ? trim( sanitize_text_field( wp_unslash( $input['bot_token'] ) ) ) : '',
			'channel_ids'              => isset( $input['channel_ids'] ) ? sanitize_textarea_field( wp_unslash( $input['channel_ids'] ) ) : '',
			'api_base_url'             => $base_url,
			'parse_mode'               => $parse_mode,
			'upload_local_media'       => ! empty( $input['upload_local_media'] ),
			'caption_overflow_mode'    => $overflow,
			'disable_web_page_preview' => ! empty( $input['disable_web_page_preview'] ),
			'disable_notification'     => ! empty( $input['disable_notification'] ),
			'fallback_to_text'         => ! empty( $input['fallback_to_text'] ),
		);
	}

	/**
	 * Sanitize Bale messenger settings.
	 *
	 * @param array $input    Submitted data.
	 * @param array $fallback Previous/default data.
	 * @return array
	 */
	private static function sanitize_bale( array $input, array $fallback ) {
		$format_mode = isset( $input['format_mode'] ) ? sanitize_key( $input['format_mode'] ) : $fallback['format_mode'];
		if ( ! in_array( $format_mode, array( 'markdown', 'plain' ), true ) ) {
			$format_mode = 'markdown';
		}

		$base_url = isset( $input['api_base_url'] ) ? trim( esc_url_raw( wp_unslash( $input['api_base_url'] ) ) ) : $fallback['api_base_url'];
		$base_url = '' !== $base_url ? untrailingslashit( $base_url ) : 'https://tapi.bale.ai';

		return array(
			'enabled'            => ! empty( $input['enabled'] ),
			'bot_token'          => isset( $input['bot_token'] ) ? trim( sanitize_text_field( wp_unslash( $input['bot_token'] ) ) ) : '',
			'channel_ids'        => isset( $input['channel_ids'] ) ? sanitize_textarea_field( wp_unslash( $input['channel_ids'] ) ) : '',
			'api_base_url'       => $base_url,
			'format_mode'        => $format_mode,
			'upload_local_media' => ! empty( $input['upload_local_media'] ),
			'fallback_to_text'   => ! empty( $input['fallback_to_text'] ),
		);
	}

	/**
	 * Sanitize per-post-type configuration map.
	 *
	 * @param array $input Raw post_types array.
	 * @return array
	 */
	private static function sanitize_post_types( array $input ) {
		$clean = array();

		foreach ( $input as $post_type => $config ) {
			$slug = sanitize_key( $post_type );
			if ( '' === $slug || ! is_array( $config ) ) {
				continue;
			}

			$send_mode = isset( $config['send_mode'] ) ? sanitize_key( $config['send_mode'] ) : 'auto_and_manual';
			if ( ! in_array( $send_mode, array( 'auto_and_manual', 'auto', 'manual' ), true ) ) {
				$send_mode = 'auto_and_manual';
			}

			$messengers = isset( $config['messengers'] ) ? sanitize_key( $config['messengers'] ) : 'both';
			if ( ! in_array( $messengers, array( 'both', 'telegram', 'bale' ), true ) ) {
				$messengers = 'both';
			}

			$image_source = isset( $config['image_source'] ) ? sanitize_key( $config['image_source'] ) : 'featured';
			if ( ! in_array( $image_source, array( 'featured', 'meta', 'none' ), true ) ) {
				$image_source = 'featured';
			}

			$template = isset( $config['template'] ) ? self::sanitize_template( wp_unslash( $config['template'] ) ) : self::get_default_template();

			$clean[ $slug ] = array(
				'enabled'           => ! empty( $config['enabled'] ),
				'send_mode'         => $send_mode,
				'send_on_update'    => ! empty( $config['send_on_update'] ),
				'messengers'        => $messengers,
				'telegram_channels' => isset( $config['telegram_channels'] ) ? sanitize_textarea_field( wp_unslash( $config['telegram_channels'] ) ) : '',
				'bale_channels'     => isset( $config['bale_channels'] ) ? sanitize_textarea_field( wp_unslash( $config['bale_channels'] ) ) : '',
				'image_source'      => $image_source,
				'image_meta_key'    => isset( $config['image_meta_key'] ) ? sanitize_text_field( wp_unslash( $config['image_meta_key'] ) ) : '',
				'template'          => $template,
			);
		}

		return $clean;
	}

	/**
	 * Sanitize message template while preserving Telegram-safe HTML tags and placeholders.
	 *
	 * @param string $template Raw template string.
	 * @return string
	 */
	public static function sanitize_template( $template ) {
		$allowed_html = array(
			'b'          => array(),
			'strong'     => array(),
			'i'          => array(),
			'em'         => array(),
			'u'          => array(),
			'ins'        => array(),
			's'          => array(),
			'strike'     => array(),
			'del'        => array(),
			'code'       => array( 'class' => true ),
			'pre'        => array(),
			'blockquote' => array(),
			'a'          => array( 'href' => true ),
		);

		// Allow placeholders like href="{post_url}" without wp_kses stripping non-URL scheme placeholders.
		$placeholder_token = '__WP_PAYAMRESAN_URL_PLACEHOLDER__';
		$template          = str_replace(
			array( 'href="{post_url}"', 'href="{short_url}"', 'href="{site_url}"' ),
			array( 'href="https://' . $placeholder_token . '/post_url"', 'href="https://' . $placeholder_token . '/short_url"', 'href="https://' . $placeholder_token . '/site_url"' ),
			(string) $template
		);

		$cleaned = wp_kses( $template, $allowed_html );

		return str_replace(
			array( 'https://' . $placeholder_token . '/post_url', 'https://' . $placeholder_token . '/short_url', 'https://' . $placeholder_token . '/site_url' ),
			array( '{post_url}', '{short_url}', '{site_url}' ),
			$cleaned
		);
	}
}
