<?php
/**
 * Messenger client contract.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan\Messengers;

defined( 'ABSPATH' ) || exit;

/**
 * Common interface implemented by Telegram and Bale messenger clients.
 */
interface Messenger_Interface {

	/**
	 * Return unique messenger slug ('telegram' or 'bale').
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Return human-readable messenger label.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether this messenger is globally enabled in settings.
	 *
	 * @return bool
	 */
	public function is_enabled();

	/**
	 * Test bot token validity and channel permissions.
	 *
	 * @param array $override Optional unsaved credentials override.
	 * @return array
	 */
	public function test_connection( array $override = array() );

	/**
	 * Send a live test message to configured channels.
	 *
	 * @param string $custom_text Optional custom test text.
	 * @param array  $override    Optional unsaved credentials override.
	 * @return array
	 */
	public function send_test_message( $custom_text = '', array $override = array() );

	/**
	 * Send formatted post content (and optional image) to a target channel.
	 *
	 * @param string $channel_id Channel username or numeric ID.
	 * @param string $text       Formatted message body.
	 * @param array  $image      Optional image data ['url' => string, 'attachment_id' => int].
	 * @param array  $options    Additional options ['post_url' => string, 'post_title' => string].
	 * @return array{success: bool, message_id: string, error: string, response_excerpt: string, warning: string}
	 */
	public function send_post_message( $channel_id, $text, array $image = array(), array $options = array() );
}
