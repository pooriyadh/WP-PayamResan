<?php
/**
 * Post editor meta box for manual sending, custom captions, and auto-send override.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan\Admin;

use WP_PayamResan\Dispatcher;
use WP_PayamResan\Post_Type_Detector;
use WP_PayamResan\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and persists the WP PayamResan meta box on post edit screens.
 */
class Meta_Box {

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
	 * Register meta box hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_meta_box' ), 10, 2 );
	}

	/**
	 * Add meta box to all enabled post types.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		$enabled_post_types = Post_Type_Detector::get_enabled_post_type_slugs();
		if ( empty( $enabled_post_types ) ) {
			return;
		}

		add_meta_box(
			'wp_payamresan_metabox',
			'WP PayamResan — ارسال به تلگرام و بله',
			array( $this, 'render_meta_box' ),
			$enabled_post_types,
			'side',
			'default'
		);
	}

	/**
	 * Render meta box content.
	 *
	 * @param \WP_Post $post Current post object.
	 * @return void
	 */
	public function render_meta_box( \WP_Post $post ) {
		wp_nonce_field( 'wp_payamresan_metabox_save', 'wp_payamresan_metabox_nonce' );

		$disable_auto   = ( '1' === (string) get_post_meta( $post->ID, '_wp_payamresan_disable_auto', true ) );
		$custom_message = (string) get_post_meta( $post->ID, '_wp_payamresan_custom_message', true );
		$sent_telegram  = get_post_meta( $post->ID, '_wp_payamresan_sent_telegram', true );
		$sent_bale      = get_post_meta( $post->ID, '_wp_payamresan_sent_bale', true );

		$tg_enabled = (bool) Settings::get( 'telegram', 'enabled', false );
		$bl_enabled = (bool) Settings::get( 'bale', 'enabled', false );
		$pt_config  = Settings::get_post_type_config( $post->post_type );
		$is_draft   = in_array( $post->post_status, array( 'auto-draft', 'new' ), true );

		?>
		<div class="wppr-metabox">
			<div class="wppr-metabox-status">
				<div class="wppr-status-row">
					<span>تلگرام:</span>
					<?php if ( ! $tg_enabled ) : ?>
						<span class="wppr-badge wppr-badge-muted">غیرفعال</span>
					<?php elseif ( is_array( $sent_telegram ) && ! empty( $sent_telegram['timestamp'] ) ) : ?>
						<span class="wppr-badge wppr-badge-success" title="<?php echo esc_attr( $sent_telegram['timestamp'] ); ?>">ارسال شده</span>
					<?php else : ?>
						<span class="wppr-badge wppr-badge-idle">ارسال نشده</span>
					<?php endif; ?>
				</div>

				<div class="wppr-status-row">
					<span>بله:</span>
					<?php if ( ! $bl_enabled ) : ?>
						<span class="wppr-badge wppr-badge-muted">غیرفعال</span>
					<?php elseif ( is_array( $sent_bale ) && ! empty( $sent_bale['timestamp'] ) ) : ?>
						<span class="wppr-badge wppr-badge-success" title="<?php echo esc_attr( $sent_bale['timestamp'] ); ?>">ارسال شده</span>
					<?php else : ?>
						<span class="wppr-badge wppr-badge-idle">ارسال نشده</span>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( Post_Type_Detector::is_auto_enabled( $post->post_type ) ) : ?>
				<p class="wppr-metabox-field">
					<label>
						<input type="checkbox" name="wp_payamresan_disable_auto" value="1" <?php checked( $disable_auto ); ?> />
						عدم ارسال خودکار هنگام انتشار
					</label>
				</p>
			<?php else : ?>
				<p class="description">ارسال خودکار برای این پست تایپ غیرفعال است (حالت دستی).</p>
			<?php endif; ?>

			<p class="wppr-metabox-field">
				<label for="wppr-custom-message"><strong>الگو یا متن اختصاصی این مطلب (اختیاری):</strong></label>
				<textarea id="wppr-custom-message" name="wp_payamresan_custom_message" rows="4" class="widefat" placeholder="در صورت خالی ماندن، از الگوی تنظیمات پست تایپ استفاده می‌شود."><?php echo esc_textarea( $custom_message ); ?></textarea>
				<span class="description">پشتیبانی از متغیرهایی مثل <code>{post_title}</code> و <code>{meta:key}</code></span>
			</p>

			<hr />

			<div class="wppr-metabox-manual">
				<p><strong>ارسال دستی به پیام‌رسان‌ها:</strong></p>

				<?php if ( ! $tg_enabled && ! $bl_enabled ) : ?>
					<p class="description">هر دو پیام‌رسان در تنظیمات افزونه غیرفعال هستند.</p>
				<?php elseif ( $is_draft ) : ?>
					<p class="description">ابتدا مطلب را ذخیره یا منتشر کنید تا امکان ارسال دستی فعال شود.</p>
				<?php else : ?>
					<div class="wppr-metabox-checks">
						<?php if ( $tg_enabled ) : ?>
							<label>
								<input type="checkbox" class="wppr-mb-messenger" value="telegram" <?php checked( in_array( $pt_config['messengers'], array( 'both', 'telegram' ), true ) ); ?> />
								تلگرام
							</label>
						<?php endif; ?>
						<?php if ( $bl_enabled ) : ?>
							<label>
								<input type="checkbox" class="wppr-mb-messenger" value="bale" <?php checked( in_array( $pt_config['messengers'], array( 'both', 'bale' ), true ) ); ?> />
								بله
							</label>
						<?php endif; ?>
					</div>

					<button type="button"
						class="button button-primary widefat wppr-mb-send-btn"
						data-post-id="<?php echo esc_attr( $post->ID ); ?>">
						ارسال دستی هم‌اکنون
					</button>
					<div class="wppr-mb-feedback" aria-live="polite"></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Persist meta box fields when post is saved (runs before Auto_Sender priority 9999).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta_box( $post_id, $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( empty( $_POST['wp_payamresan_metabox_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_payamresan_metabox_nonce'] ) ), 'wp_payamresan_metabox_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$disable_auto = ! empty( $_POST['wp_payamresan_disable_auto'] ) ? '1' : '0';
		update_post_meta( $post_id, '_wp_payamresan_disable_auto', $disable_auto );

		if ( isset( $_POST['wp_payamresan_custom_message'] ) ) {
			$custom = Settings::sanitize_template( wp_unslash( $_POST['wp_payamresan_custom_message'] ) );
			if ( '' === trim( $custom ) ) {
				delete_post_meta( $post_id, '_wp_payamresan_custom_message' );
			} else {
				update_post_meta( $post_id, '_wp_payamresan_custom_message', $custom );
			}
		}
	}
}
