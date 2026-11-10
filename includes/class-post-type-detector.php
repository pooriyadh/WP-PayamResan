<?php
/**
 * Dynamic post type detector.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan;

defined( 'ABSPATH' ) || exit;

/**
 * Identifies all sendable WordPress built-in and custom post types.
 */
class Post_Type_Detector {

	/**
	 * Built-in and plugin utility post types that do not represent publishable content.
	 *
	 * @return array
	 */
	public static function get_excluded_post_types() {
		$excluded = array(
			'attachment',
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'wp_font_family',
			'wp_font_face',
			'elementor_library',
			'e-floating-buttons',
			'acf-field-group',
			'acf-field',
			'acf-post-type',
			'acf-taxonomy',
			'acf-ui-options-page',
			'jet-engine',
			'jet-smart-filters',
			'jet-theme-core',
			'jet-menu',
			'jet-popup',
			'jet-form-builder',
			'jet-fb-records',
			'wpcf7_contact_form',
			'wpforms',
			'nf_sub',
			'shop_order',
			'shop_order_placehold',
			'shop_coupon',
			'shop_webhook',
			'scheduled-action',
			'mailpoet_page',
			'mc4wp-form',
		);

		/**
		 * Filter post types excluded from WP PayamResan discovery.
		 *
		 * @param array $excluded List of post type slugs to ignore.
		 */
		return (array) apply_filters( 'wp_payamresan_excluded_post_types', $excluded );
	}

	/**
	 * Discover all sendable post types registered on the site.
	 *
	 * @param bool|null $include_non_public Optional override for non-public show_ui CPTs.
	 * @return array Associative array keyed by post type slug with metadata.
	 */
	public static function get_available_post_types( $include_non_public = null ) {
		if ( null === $include_non_public ) {
			$include_non_public = (bool) Settings::get( 'general', 'include_non_public_cpts', false );
		}

		$excluded   = self::get_excluded_post_types();
		$post_types = get_post_types( array(), 'objects' );
		$available  = array();

		if ( ! is_array( $post_types ) ) {
			return array();
		}

		foreach ( $post_types as $slug => $pt_object ) {
			if ( ! $pt_object instanceof \WP_Post_Type ) {
				continue;
			}

			if ( in_array( $slug, $excluded, true ) ) {
				continue;
			}

			$is_public_type = ! empty( $pt_object->public ) || ! empty( $pt_object->publicly_queryable );
			$has_ui         = ! empty( $pt_object->show_ui );

			if ( ! $is_public_type && ! ( $include_non_public && $has_ui ) ) {
				continue;
			}

			$label = ! empty( $pt_object->labels->name ) ? $pt_object->labels->name : $pt_object->label;
			if ( '' === trim( (string) $label ) ) {
				$label = $slug;
			}

			$available[ $slug ] = array(
				'slug'          => $slug,
				'label'         => $label,
				'singular_name' => ! empty( $pt_object->labels->singular_name ) ? $pt_object->labels->singular_name : $label,
				'builtin'       => ! empty( $pt_object->_builtin ),
				'public'        => $is_public_type,
				'show_ui'       => $has_ui,
				'taxonomies'    => get_object_taxonomies( $slug, 'objects' ),
				'source'        => self::guess_post_type_source( $slug, $pt_object ),
			);
		}

		// Keep built-in 'post' and 'page' first, then sort others cleanly by label.
		uksort(
			$available,
			static function ( $a, $b ) use ( $available ) {
				$priority = array(
					'post' => 0,
					'page' => 1,
				);
				$pa = isset( $priority[ $a ] ) ? $priority[ $a ] : 10;
				$pb = isset( $priority[ $b ] ) ? $priority[ $b ] : 10;
				if ( $pa !== $pb ) {
					return $pa - $pb;
				}
				return strcmp( $available[ $a ]['label'], $available[ $b ]['label'] );
			}
		);

		/**
		 * Filter available sendable post types discovered by WP PayamResan.
		 *
		 * @param array $available Associative array of discovered post types.
		 */
		return (array) apply_filters( 'wp_payamresan_available_post_types', $available );
	}

	/**
	 * Get post type slugs enabled for the plugin (either auto or manual).
	 *
	 * @return array
	 */
	public static function get_enabled_post_type_slugs() {
		$available = self::get_available_post_types();
		$enabled   = array();

		foreach ( array_keys( $available ) as $slug ) {
			$config = Settings::get_post_type_config( $slug );
			if ( ! empty( $config['enabled'] ) ) {
				$enabled[] = $slug;
			}
		}

		return $enabled;
	}

	/**
	 * Determine whether a post type is enabled for manual sending.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function is_manual_enabled( $post_type ) {
		$available = self::get_available_post_types();
		if ( ! isset( $available[ $post_type ] ) ) {
			return false;
		}

		$config = Settings::get_post_type_config( $post_type );
		if ( empty( $config['enabled'] ) ) {
			return false;
		}

		return in_array( $config['send_mode'], array( 'auto_and_manual', 'manual' ), true );
	}

	/**
	 * Determine whether a post type is enabled for automatic sending.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function is_auto_enabled( $post_type ) {
		$available = self::get_available_post_types();
		if ( ! isset( $available[ $post_type ] ) ) {
			return false;
		}

		$config = Settings::get_post_type_config( $post_type );
		if ( empty( $config['enabled'] ) ) {
			return false;
		}

		return in_array( $config['send_mode'], array( 'auto_and_manual', 'auto' ), true );
	}

	/**
	 * Provide a helpful human-readable origin label for a post type.
	 *
	 * @param string        $slug      Post type slug.
	 * @param \WP_Post_Type $pt_object Post type object.
	 * @return string
	 */
	private static function guess_post_type_source( $slug, \WP_Post_Type $pt_object ) {
		if ( ! empty( $pt_object->_builtin ) ) {
			return 'WordPress';
		}

		if ( 'product' === $slug && class_exists( 'WooCommerce' ) ) {
			return 'WooCommerce';
		}

		if ( function_exists( 'jet_engine' ) && isset( jet_engine()->cpt ) && method_exists( jet_engine()->cpt, 'get_items' ) ) {
			$jet_cpts = jet_engine()->cpt->get_items();
			if ( is_array( $jet_cpts ) ) {
				foreach ( $jet_cpts as $item ) {
					if ( isset( $item['slug'] ) && $item['slug'] === $slug ) {
						return 'JetEngine';
					}
				}
			}
		}

		return 'Custom Post Type';
	}
}
