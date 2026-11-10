<?php
/**
 * Metadata discovery and value resolver (WordPress, JetEngine, ACF, Meta Box, Pods, Custom).
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers and formats post metadata from core and third-party meta box plugins.
 */
class Meta_Resolver {

	/**
	 * Internal WordPress/plugin meta keys that are noisy and not useful as message placeholders.
	 *
	 * @var array
	 */
	private static $ignored_meta_keys = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_encloseme',
		'_pingme',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_desired_post_slug',
		'_wp_page_template',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_thumbnail_id',
		'_wp_payamresan_disable_auto',
		'_wp_payamresan_custom_message',
		'_wp_payamresan_sent_telegram',
		'_wp_payamresan_sent_bale',
		'_wp_payamresan_last_result',
	);

	/**
	 * Curated useful underscore-prefixed meta keys (e.g. WooCommerce, Yoast, RankMath).
	 *
	 * @var array
	 */
	private static $useful_private_keys = array(
		'_price'                  => 'قیمت فعلی (WooCommerce)',
		'_regular_price'          => 'قیمت عادی (WooCommerce)',
		'_sale_price'             => 'قیمت فروش ویژه (WooCommerce)',
		'_sku'                    => 'شناسه محصول / SKU',
		'_stock_status'           => 'وضعیت موجودی انبار',
		'_stock'                  => 'تعداد موجودی انبار',
		'_weight'                 => 'وزن محصول',
		'_yoast_wpseo_title'      => 'عنوان سئو (Yoast)',
		'_yoast_wpseo_metadesc'   => 'توضیحات متا سئو (Yoast)',
		'rank_math_title'         => 'عنوان سئو (Rank Math)',
		'rank_math_description'   => 'توضیحات سئو (Rank Math)',
		'rank_math_focus_keyword' => 'کلمه کلیدی کانونی (Rank Math)',
	);

	/**
	 * Discover all available meta fields for a post type across supported plugins & database.
	 *
	 * @param string $post_type      Post type slug.
	 * @param bool   $include_hidden Whether to also include hidden underscore DB meta keys.
	 * @return array Associative array keyed by meta_key.
	 */
	public static function get_meta_fields_for_post_type( $post_type, $include_hidden = false ) {
		$fields = array();

		// 1. JetEngine CPT fields & Meta Boxes.
		$fields = self::merge_fields( $fields, self::discover_jetengine_fields( $post_type ) );

		// 2. Advanced Custom Fields (ACF).
		$fields = self::merge_fields( $fields, self::discover_acf_fields( $post_type ) );

		// 3. Meta Box (rwmb).
		$fields = self::merge_fields( $fields, self::discover_metabox_io_fields( $post_type ) );

		// 4. Pods.
		$fields = self::merge_fields( $fields, self::discover_pods_fields( $post_type ) );

		// 5. WordPress register_post_meta() keys.
		$fields = self::merge_fields( $fields, self::discover_registered_meta_keys( $post_type ) );

		// 6. Existing wp_postmeta database keys (catches custom add_meta_box(), WooCommerce, etc.).
		$fields = self::merge_fields( $fields, self::discover_database_meta_keys( $post_type, $include_hidden ) );

		/**
		 * Filter discovered meta fields for a post type.
		 *
		 * @param array  $fields    Discovered fields keyed by meta key.
		 * @param string $post_type Post type slug.
		 */
		return (array) apply_filters( 'wp_payamresan_discovered_meta_fields', $fields, $post_type );
	}

	/**
	 * Resolve a meta field value for a specific post in human-readable form.
	 *
	 * @param int|\WP_Post $post     Post ID or object.
	 * @param string       $meta_key Meta key.
	 * @param bool         $raw_url  If true and field is media, return URL string.
	 * @return string
	 */
	public static function resolve_meta_value( $post, $meta_key, $raw_url = false ) {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || '' === trim( (string) $meta_key ) ) {
			return '';
		}

		$meta_key = trim( (string) $meta_key );
		$fields   = self::get_meta_fields_for_post_type( $post->post_type, true );
		$field    = isset( $fields[ $meta_key ] ) ? $fields[ $meta_key ] : array();
		$raw      = self::fetch_raw_meta_value( $post, $meta_key, $field );

		if ( self::is_empty_meta( $raw ) ) {
			return '';
		}

		return self::format_meta_value( $raw, $field, $meta_key, $raw_url );
	}

	/**
	 * Resolve an image URL from a meta field (e.g., JetEngine Media, ACF Image, Meta Box image).
	 *
	 * @param int|\WP_Post $post     Post ID or object.
	 * @param string       $meta_key Meta key.
	 * @return array{url: string, attachment_id: int}
	 */
	public static function resolve_image_from_meta( $post, $meta_key ) {
		$result = array(
			'url'           => '',
			'attachment_id' => 0,
		);

		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || '' === trim( (string) $meta_key ) ) {
			return $result;
		}

		$fields = self::get_meta_fields_for_post_type( $post->post_type, true );
		$field  = isset( $fields[ $meta_key ] ) ? $fields[ $meta_key ] : array();
		$raw    = self::fetch_raw_meta_value( $post, $meta_key, $field );

		if ( is_string( $raw ) && ( 0 === strpos( trim( $raw ), '{' ) || 0 === strpos( trim( $raw ), '[' ) ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}

		if ( is_numeric( $raw ) ) {
			$attachment_id = absint( $raw );
			$url           = wp_get_attachment_image_url( $attachment_id, 'large' );
			if ( ! $url ) {
				$url = wp_get_attachment_url( $attachment_id );
			}
			if ( $url ) {
				$result['attachment_id'] = $attachment_id;
				$result['url']           = (string) $url;
			}
			return $result;
		}

		if ( is_array( $raw ) ) {
			if ( ! empty( $raw['id'] ) && is_numeric( $raw['id'] ) ) {
				$attachment_id = absint( $raw['id'] );
				$url           = wp_get_attachment_image_url( $attachment_id, 'large' );
				$result['attachment_id'] = $attachment_id;
				$result['url']           = $url ? (string) $url : ( ! empty( $raw['url'] ) ? (string) $raw['url'] : '' );
				return $result;
			}
			if ( ! empty( $raw['ID'] ) && is_numeric( $raw['ID'] ) ) {
				$attachment_id = absint( $raw['ID'] );
				$url           = wp_get_attachment_image_url( $attachment_id, 'large' );
				$result['attachment_id'] = $attachment_id;
				$result['url']           = $url ? (string) $url : ( ! empty( $raw['url'] ) ? (string) $raw['url'] : '' );
				return $result;
			}
			if ( ! empty( $raw['url'] ) && is_string( $raw['url'] ) ) {
				$result['url'] = esc_url_raw( $raw['url'] );
				return $result;
			}
			// Gallery / list of items: grab the first item.
			$first = reset( $raw );
			if ( is_numeric( $first ) || is_array( $first ) || is_string( $first ) ) {
				if ( is_numeric( $first ) ) {
					$attachment_id = absint( $first );
					$url           = wp_get_attachment_image_url( $attachment_id, 'large' );
					if ( $url ) {
						$result['attachment_id'] = $attachment_id;
						$result['url']           = (string) $url;
					}
				} elseif ( is_array( $first ) && ! empty( $first['url'] ) ) {
					$result['url']           = esc_url_raw( $first['url'] );
					$result['attachment_id'] = ! empty( $first['id'] ) ? absint( $first['id'] ) : 0;
				} elseif ( is_string( $first ) && filter_var( $first, FILTER_VALIDATE_URL ) ) {
					$result['url'] = esc_url_raw( $first );
				}
				return $result;
			}
		}

		if ( is_string( $raw ) ) {
			// Comma-separated attachment IDs (JetEngine Gallery).
			if ( preg_match( '/^\d+(,\s*\d+)+$/', trim( $raw ) ) ) {
				$ids           = array_map( 'absint', explode( ',', $raw ) );
				$attachment_id = reset( $ids );
				$url           = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'large' ) : '';
				if ( $url ) {
					$result['attachment_id'] = $attachment_id;
					$result['url']           = (string) $url;
				}
				return $result;
			}

			if ( filter_var( trim( $raw ), FILTER_VALIDATE_URL ) ) {
				$result['url']           = esc_url_raw( trim( $raw ) );
				$result['attachment_id'] = (int) attachment_url_to_postid( $result['url'] );
				return $result;
			}
		}

		return $result;
	}

	/**
	 * Fetch raw meta value using WordPress core plus JetEngine/ACF/MetaBox APIs.
	 *
	 * @param \WP_Post $post     Post object.
	 * @param string   $meta_key Meta key.
	 * @param array    $field    Optional discovered field schema.
	 * @return mixed
	 */
	private static function fetch_raw_meta_value( \WP_Post $post, $meta_key, array $field = array() ) {
		$value = get_post_meta( $post->ID, $meta_key, true );

		// JetEngine (including Custom Meta Storage tables & special contexts).
		if ( self::is_empty_meta( $value ) && function_exists( 'jet_engine' ) ) {
			if ( isset( jet_engine()->listings ) && isset( jet_engine()->listings->data ) && method_exists( jet_engine()->listings->data, 'get_meta' ) ) {
				$jet_val = jet_engine()->listings->data->get_meta( $meta_key, $post );
				if ( ! self::is_empty_meta( $jet_val ) ) {
					$value = $jet_val;
				}
			}
		}

		// ACF fallback (note: get_field can return formatted array/object; raw meta works too).
		if ( self::is_empty_meta( $value ) && function_exists( 'get_field' ) ) {
			$acf_val = get_field( $meta_key, $post->ID, false );
			if ( ! self::is_empty_meta( $acf_val ) ) {
				$value = $acf_val;
			}
		}

		// Meta Box (rwmb) fallback.
		if ( self::is_empty_meta( $value ) && function_exists( 'rwmb_meta' ) ) {
			$rwmb_val = rwmb_meta( $meta_key, array(), $post->ID );
			if ( ! self::is_empty_meta( $rwmb_val ) ) {
				$value = $rwmb_val;
			}
		}

		// JetEngine repeater with "Save as separate fields" or multi-value meta fallback.
		if ( self::is_empty_meta( $value ) && ! empty( $field['type'] ) && 'repeater' === $field['type'] ) {
			$multi = get_post_meta( $post->ID, $meta_key, false );
			if ( is_array( $multi ) && ! empty( $multi ) ) {
				$value = $multi;
			}
		}

		/**
		 * Filter raw meta value resolved for a post before formatting.
		 *
		 * @param mixed    $value    Raw value.
		 * @param string   $meta_key Meta key.
		 * @param \WP_Post $post     Post object.
		 * @param array    $field    Field schema.
		 */
		return apply_filters( 'wp_payamresan_raw_meta_value', $value, $meta_key, $post, $field );
	}

	/**
	 * Format a raw meta value according to its field schema and data structure.
	 *
	 * @param mixed  $raw      Raw value.
	 * @param array  $field    Field definition.
	 * @param string $meta_key Meta key.
	 * @param bool   $raw_url  Prefer plain URL for media fields.
	 * @return string
	 */
	private static function format_meta_value( $raw, array $field, $meta_key, $raw_url = false ) {
		$type    = isset( $field['type'] ) ? strtolower( (string) $field['type'] ) : '';
		$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

		// Decode JSON string if applicable (e.g. JetEngine Repeaters or JSON arrays).
		if ( is_string( $raw ) ) {
			$trimmed = trim( $raw );
			if ( ( 0 === strpos( $trimmed, '{' ) || 0 === strpos( $trimmed, '[' ) ) && strlen( $trimmed ) > 2 ) {
				$decoded = json_decode( $trimmed, true );
				if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
					$raw = $decoded;
				}
			}
		}

		// Switcher / boolean fields.
		if ( 'switcher' === $type || 'true_false' === $type ) {
			$is_true = ( true === $raw || 1 === $raw || '1' === $raw || 'true' === strtolower( (string) $raw ) || 'yes' === strtolower( (string) $raw ) );
			$labels  = (array) apply_filters(
				'wp_payamresan_switcher_labels',
				array(
					'true'  => 'بله',
					'false' => 'خیر',
				),
				$meta_key,
				$field
			);
			return $is_true ? (string) $labels['true'] : (string) $labels['false'];
		}

		// Date / Datetime / Time fields.
		if ( in_array( $type, array( 'date', 'datetime-local', 'datetime', 'time', 'date_picker', 'date_time_picker' ), true ) ) {
			if ( is_numeric( $raw ) && (int) $raw > 0 ) {
				$fmt = ( false !== strpos( $type, 'time' ) )
					? get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
					: get_option( 'date_format' );
				return (string) wp_date( $fmt, (int) $raw );
			}
			return self::clean_scalar_text( $raw );
		}

		// Media / Image / File fields.
		if ( in_array( $type, array( 'media', 'image', 'file' ), true ) ) {
			return self::format_media_value( $raw, $raw_url );
		}

		// Gallery fields.
		if ( 'gallery' === $type ) {
			$urls = self::format_gallery_value( $raw );
			return implode( "\n", $urls );
		}

		// Post / Relationship fields.
		if ( in_array( $type, array( 'posts', 'post_object', 'relationship', 'post' ), true ) ) {
			return self::format_related_posts( $raw );
		}

		// JetEngine / ACF Repeater fields.
		if ( 'repeater' === $type && is_array( $raw ) ) {
			return self::format_repeater_value( $raw, $field );
		}

		// Checkbox fields (handles JetEngine associative ['opt' => 'true'] AND indexed ['opt']).
		if ( 'checkbox' === $type && is_array( $raw ) ) {
			return self::format_checkbox_value( $raw, $options );
		}

		// Select / Radio / Choice fields with option label map.
		if ( ! empty( $options ) && ( is_scalar( $raw ) || is_array( $raw ) ) ) {
			return self::map_options_to_labels( $raw, $options );
		}

		// WooCommerce stock status friendly labels.
		if ( '_stock_status' === $meta_key && is_string( $raw ) ) {
			$stock_map = array(
				'instock'     => 'موجود',
				'outofstock'  => 'ناموجود',
				'onbackorder' => 'در انتظار تامین',
			);
			if ( isset( $stock_map[ $raw ] ) ) {
				return $stock_map[ $raw ];
			}
		}

		// Generic array fallback (handles associative boolean maps & multi-selects cleanly).
		if ( is_array( $raw ) ) {
			if ( isset( $raw['url'] ) && is_string( $raw['url'] ) ) {
				return esc_url_raw( $raw['url'] );
			}
			if ( self::looks_like_jetengine_checkbox_map( $raw ) ) {
				return self::format_checkbox_value( $raw, $options );
			}
			if ( self::looks_like_repeater_rows( $raw ) ) {
				return self::format_repeater_value( $raw, $field );
			}
			return self::flatten_array( $raw );
		}

		return self::clean_scalar_text( $raw );
	}

	/**
	 * Discover meta fields registered via Crocoblock JetEngine.
	 *
	 * @param string $post_type Post type slug.
	 * @return array
	 */
	private static function discover_jetengine_fields( $post_type ) {
		$result = array();

		if ( ! function_exists( 'jet_engine' ) || ! isset( jet_engine()->meta_boxes ) ) {
			return $result;
		}

		if ( ! method_exists( jet_engine()->meta_boxes, 'get_fields_for_context' ) ) {
			return $result;
		}

		$jet_fields = jet_engine()->meta_boxes->get_fields_for_context( 'post_type', $post_type );
		if ( ! is_array( $jet_fields ) ) {
			return $result;
		}

		foreach ( $jet_fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}

			$object_type = isset( $field['object_type'] ) ? $field['object_type'] : 'field';
			$type        = isset( $field['type'] ) ? $field['type'] : 'text';

			if ( in_array( $object_type, array( 'tab', 'accordion', 'endpoint' ), true ) || 'html' === $type ) {
				continue;
			}

			$key     = (string) $field['name'];
			$label   = ! empty( $field['title'] ) ? (string) $field['title'] : ( ! empty( $field['label'] ) ? (string) $field['label'] : $key );
			$options = self::normalize_jetengine_options( $field );

			$subfields = array();
			if ( ! empty( $field['repeater-fields'] ) && is_array( $field['repeater-fields'] ) ) {
				foreach ( $field['repeater-fields'] as $sub ) {
					if ( ! empty( $sub['name'] ) ) {
						$subfields[ $sub['name'] ] = ! empty( $sub['title'] ) ? (string) $sub['title'] : (string) $sub['name'];
					}
				}
			}

			$result[ $key ] = array(
				'key'        => $key,
				'label'      => $label,
				'type'       => $type,
				'source'     => 'JetEngine',
				'options'    => $options,
				'subfields'  => $subfields,
				'is_private' => 0 === strpos( $key, '_' ),
			);
		}

		return $result;
	}

	/**
	 * Extract option key => label pairs from a JetEngine field (including Glossaries when available).
	 *
	 * @param array $field JetEngine field config.
	 * @return array
	 */
	private static function normalize_jetengine_options( array $field ) {
		$map = array();

		$raw_options = isset( $field['options'] ) ? $field['options'] : array();

		// Support JetEngine Glossaries if configured on the field.
		if ( empty( $raw_options ) && ! empty( $field['options_source'] ) && 'glossary' === $field['options_source'] && ! empty( $field['glossary_id'] ) ) {
			if ( function_exists( 'jet_engine' ) && isset( jet_engine()->glossaries ) && isset( jet_engine()->glossaries->data ) && method_exists( jet_engine()->glossaries->data, 'get_item_for_edit' ) ) {
				$glossary = jet_engine()->glossaries->data->get_item_for_edit( absint( $field['glossary_id'] ) );
				if ( is_array( $glossary ) && ! empty( $glossary['fields'] ) && is_array( $glossary['fields'] ) ) {
					$raw_options = $glossary['fields'];
				}
			}
		}

		if ( ! is_array( $raw_options ) ) {
			return $map;
		}

		foreach ( $raw_options as $opt_key => $opt ) {
			if ( is_array( $opt ) ) {
				if ( isset( $opt['key'] ) ) {
					$k         = (string) $opt['key'];
					$map[ $k ] = isset( $opt['value'] ) ? (string) $opt['value'] : $k;
				} elseif ( isset( $opt['value'] ) ) {
					$k         = (string) $opt['value'];
					$map[ $k ] = isset( $opt['label'] ) ? (string) $opt['label'] : $k;
				}
			} elseif ( is_scalar( $opt ) ) {
				$map[ (string) $opt_key ] = (string) $opt;
			}
		}

		return $map;
	}

	/**
	 * Discover meta fields registered via Advanced Custom Fields (ACF).
	 *
	 * @param string $post_type Post type slug.
	 * @return array
	 */
	private static function discover_acf_fields( $post_type ) {
		$result = array();

		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return $result;
		}

		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
		if ( ! is_array( $groups ) ) {
			return $result;
		}

		foreach ( $groups as $group ) {
			if ( empty( $group['key'] ) ) {
				continue;
			}
			$acf_fields = acf_get_fields( $group['key'] );
			if ( ! is_array( $acf_fields ) ) {
				continue;
			}
			foreach ( $acf_fields as $field ) {
				if ( empty( $field['name'] ) ) {
					continue;
				}
				$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';
				if ( in_array( $type, array( 'tab', 'accordion', 'message', 'group' ), true ) ) {
					continue;
				}

				$key            = (string) $field['name'];
				$result[ $key ] = array(
					'key'        => $key,
					'label'      => ! empty( $field['label'] ) ? (string) $field['label'] : $key,
					'type'       => $type,
					'source'     => 'ACF',
					'options'    => ( isset( $field['choices'] ) && is_array( $field['choices'] ) ) ? $field['choices'] : array(),
					'subfields'  => array(),
					'is_private' => 0 === strpos( $key, '_' ),
				);
			}
		}

		return $result;
	}

	/**
	 * Discover meta fields registered via Meta Box (rwmb).
	 *
	 * @param string $post_type Post type slug.
	 * @return array
	 */
	private static function discover_metabox_io_fields( $post_type ) {
		$result = array();

		if ( ! function_exists( 'rwmb_get_object_fields' ) ) {
			return $result;
		}

		$fields = rwmb_get_object_fields( $post_type );
		if ( ! is_array( $fields ) ) {
			return $result;
		}

		foreach ( $fields as $field ) {
			if ( empty( $field['id'] ) ) {
				continue;
			}
			$key            = (string) $field['id'];
			$result[ $key ] = array(
				'key'        => $key,
				'label'      => ! empty( $field['name'] ) ? (string) $field['name'] : $key,
				'type'       => ! empty( $field['type'] ) ? (string) $field['type'] : 'text',
				'source'     => 'Meta Box',
				'options'    => ( isset( $field['options'] ) && is_array( $field['options'] ) ) ? $field['options'] : array(),
				'subfields'  => array(),
				'is_private' => 0 === strpos( $key, '_' ),
			);
		}

		return $result;
	}

	/**
	 * Discover fields registered via Pods Framework.
	 *
	 * @param string $post_type Post type slug.
	 * @return array
	 */
	private static function discover_pods_fields( $post_type ) {
		$result = array();

		if ( ! function_exists( 'pods_api' ) ) {
			return $result;
		}

		$api = pods_api();
		if ( ! is_object( $api ) || ! method_exists( $api, 'load_pod' ) ) {
			return $result;
		}

		$pod = $api->load_pod(
			array(
				'name'       => $post_type,
				'table_info' => false,
			),
			false
		);

		if ( ! is_array( $pod ) || empty( $pod['fields'] ) || ! is_array( $pod['fields'] ) ) {
			return $result;
		}

		foreach ( $pod['fields'] as $field_name => $field ) {
			$key            = (string) $field_name;
			$result[ $key ] = array(
				'key'        => $key,
				'label'      => ! empty( $field['label'] ) ? (string) $field['label'] : $key,
				'type'       => ! empty( $field['type'] ) ? (string) $field['type'] : 'text',
				'source'     => 'Pods',
				'options'    => array(),
				'subfields'  => array(),
				'is_private' => 0 === strpos( $key, '_' ),
			);
		}

		return $result;
	}

	/**
	 * Discover meta keys registered via WordPress register_post_meta().
	 *
	 * @param string $post_type Post type slug.
	 * @return array
	 */
	private static function discover_registered_meta_keys( $post_type ) {
		$result = array();

		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return $result;
		}

		$keys = array_merge(
			get_registered_meta_keys( 'post', '' ),
			get_registered_meta_keys( 'post', $post_type )
		);

		foreach ( $keys as $key => $args ) {
			if ( in_array( $key, self::$ignored_meta_keys, true ) ) {
				continue;
			}
			$is_private = 0 === strpos( $key, '_' );
			if ( $is_private && ! isset( self::$useful_private_keys[ $key ] ) ) {
				continue;
			}

			$label          = ! empty( $args['description'] ) ? (string) $args['description'] : ( isset( self::$useful_private_keys[ $key ] ) ? self::$useful_private_keys[ $key ] : $key );
			$result[ $key ] = array(
				'key'        => $key,
				'label'      => $label,
				'type'       => ! empty( $args['type'] ) ? (string) $args['type'] : 'text',
				'source'     => 'WordPress',
				'options'    => array(),
				'subfields'  => array(),
				'is_private' => $is_private,
			);
		}

		return $result;
	}

	/**
	 * Discover meta keys saved in wp_postmeta for posts of a specific post type.
	 *
	 * Catches native Custom Fields and custom add_meta_box() plugins automatically.
	 *
	 * @param string $post_type      Post type slug.
	 * @param bool   $include_hidden Whether to include underscore-prefixed keys.
	 * @return array
	 */
	private static function discover_database_meta_keys( $post_type, $include_hidden = false ) {
		global $wpdb;

		$result = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND pm.meta_key <> ''
				ORDER BY pm.meta_key ASC
				LIMIT 250",
				$post_type
			)
		);

		if ( ! is_array( $keys ) ) {
			return $result;
		}

		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( in_array( $key, self::$ignored_meta_keys, true ) ) {
				continue;
			}
			if ( 0 === strpos( $key, '_oembed_' ) || 0 === strpos( $key, '_transient_' ) || 0 === strpos( $key, '_elementor_' ) ) {
				continue;
			}

			$is_private = 0 === strpos( $key, '_' );
			if ( $is_private && ! $include_hidden && ! isset( self::$useful_private_keys[ $key ] ) ) {
				continue;
			}

			$label = isset( self::$useful_private_keys[ $key ] ) ? self::$useful_private_keys[ $key ] : $key;

			$result[ $key ] = array(
				'key'        => $key,
				'label'      => $label,
				'type'       => 'text',
				'source'     => isset( self::$useful_private_keys[ $key ] ) ? 'Plugin / Meta' : 'Custom Field',
				'options'    => array(),
				'subfields'  => array(),
				'is_private' => $is_private,
			);
		}

		return $result;
	}

	/**
	 * Merge field lists while preserving richer schema definitions (e.g. JetEngine/ACF over raw DB).
	 *
	 * @param array $existing Existing field map.
	 * @param array $incoming New field map.
	 * @return array
	 */
	private static function merge_fields( array $existing, array $incoming ) {
		foreach ( $incoming as $key => $field ) {
			if ( ! isset( $existing[ $key ] ) ) {
				$existing[ $key ] = $field;
			}
		}
		return $existing;
	}

	/**
	 * Check whether a meta value should be treated as empty.
	 *
	 * Note: '0' and 0 are valid non-empty values (e.g. price = 0 or count = 0).
	 *
	 * @param mixed $value Meta value.
	 * @return bool
	 */
	private static function is_empty_meta( $value ) {
		if ( null === $value || false === $value || '' === $value ) {
			return true;
		}
		if ( is_array( $value ) && empty( $value ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Format media value to clean URL or filename/URL.
	 *
	 * @param mixed $raw     Attachment ID, URL, or array.
	 * @param bool  $raw_url Force raw URL output.
	 * @return string
	 */
	private static function format_media_value( $raw, $raw_url = false ) {
		if ( is_numeric( $raw ) ) {
			$url = wp_get_attachment_url( absint( $raw ) );
			return $url ? (string) $url : '';
		}
		if ( is_array( $raw ) ) {
			if ( ! empty( $raw['url'] ) ) {
				return (string) $raw['url'];
			}
			if ( ! empty( $raw['id'] ) ) {
				$url = wp_get_attachment_url( absint( $raw['id'] ) );
				return $url ? (string) $url : '';
			}
		}
		if ( is_string( $raw ) ) {
			return trim( $raw );
		}
		return '';
	}

	/**
	 * Format gallery IDs/URLs into array of URLs.
	 *
	 * @param mixed $raw Gallery value.
	 * @return array
	 */
	private static function format_gallery_value( $raw ) {
		$items = array();
		if ( is_string( $raw ) ) {
			$items = array_map( 'trim', explode( ',', $raw ) );
		} elseif ( is_array( $raw ) ) {
			$items = $raw;
		}

		$urls = array();
		foreach ( $items as $item ) {
			$url = self::format_media_value( $item, true );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Format related post IDs into post titles.
	 *
	 * @param mixed $raw Post ID or array of IDs/WP_Post objects.
	 * @return string
	 */
	private static function format_related_posts( $raw ) {
		$ids = is_array( $raw ) ? $raw : array( $raw );
		if ( is_string( $raw ) && false !== strpos( $raw, ',' ) ) {
			$ids = array_map( 'trim', explode( ',', $raw ) );
		}

		$titles = array();
		foreach ( $ids as $item ) {
			if ( $item instanceof \WP_Post ) {
				$titles[] = get_the_title( $item );
			} elseif ( is_numeric( $item ) ) {
				$title = get_the_title( absint( $item ) );
				if ( '' !== $title ) {
					$titles[] = $title;
				}
			} elseif ( is_array( $item ) && ! empty( $item['ID'] ) ) {
				$title = get_the_title( absint( $item['ID'] ) );
				if ( '' !== $title ) {
					$titles[] = $title;
				}
			}
		}

		return implode( '، ', array_filter( $titles ) );
	}

	/**
	 * Format JetEngine/ACF checkbox arrays cleanly.
	 *
	 * @param array $raw     Checkbox array.
	 * @param array $options Option key => label map.
	 * @return string
	 */
	private static function format_checkbox_value( array $raw, array $options ) {
		$selected = array();

		if ( self::looks_like_jetengine_checkbox_map( $raw ) ) {
			foreach ( $raw as $k => $checked ) {
				if ( true === $checked || 1 === $checked || '1' === $checked || 'true' === strtolower( (string) $checked ) ) {
					$selected[] = isset( $options[ $k ] ) ? $options[ $k ] : (string) $k;
				}
			}
		} else {
			foreach ( $raw as $val ) {
				if ( is_scalar( $val ) && '' !== (string) $val ) {
					$str_val    = (string) $val;
					$selected[] = isset( $options[ $str_val ] ) ? $options[ $str_val ] : $str_val;
				}
			}
		}

		return implode( '، ', $selected );
	}

	/**
	 * Map scalar or array of option keys to labels.
	 *
	 * @param mixed $raw     Stored key(s).
	 * @param array $options Option key => label map.
	 * @return string
	 */
	private static function map_options_to_labels( $raw, array $options ) {
		if ( is_array( $raw ) ) {
			$labels = array();
			foreach ( $raw as $item ) {
				if ( is_scalar( $item ) ) {
					$key      = (string) $item;
					$labels[] = isset( $options[ $key ] ) ? $options[ $key ] : $key;
				}
			}
			return implode( '، ', $labels );
		}

		$key = (string) $raw;
		return isset( $options[ $key ] ) ? $options[ $key ] : self::clean_scalar_text( $key );
	}

	/**
	 * Format repeater rows into readable multi-line text.
	 *
	 * @param array $rows  Repeater array.
	 * @param array $field Field schema.
	 * @return string
	 */
	private static function format_repeater_value( array $rows, array $field ) {
		$subfields = isset( $field['subfields'] ) && is_array( $field['subfields'] ) ? $field['subfields'] : array();
		$lines     = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				if ( is_scalar( $row ) && '' !== trim( (string) $row ) ) {
					$lines[] = '• ' . self::clean_scalar_text( $row );
				}
				continue;
			}

			$parts = array();
			foreach ( $row as $sub_key => $sub_val ) {
				if ( self::is_empty_meta( $sub_val ) ) {
					continue;
				}
				$label = isset( $subfields[ $sub_key ] ) ? $subfields[ $sub_key ] : $sub_key;
				$text  = is_array( $sub_val ) ? self::flatten_array( $sub_val ) : self::clean_scalar_text( $sub_val );
				if ( '' !== $text ) {
					$parts[] = $label . ': ' . $text;
				}
			}

			if ( ! empty( $parts ) ) {
				$lines[] = '• ' . implode( ' | ', $parts );
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Detect JetEngine checkbox storage format ['opt1' => 'true', 'opt2' => 'false'].
	 *
	 * @param array $arr Input array.
	 * @return bool
	 */
	private static function looks_like_jetengine_checkbox_map( array $arr ) {
		if ( empty( $arr ) ) {
			return false;
		}
		foreach ( $arr as $k => $v ) {
			if ( ! is_string( $k ) || ( 'true' !== $v && 'false' !== $v && true !== $v && false !== $v ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Detect whether an array resembles repeater item rows.
	 *
	 * @param array $arr Input array.
	 * @return bool
	 */
	private static function looks_like_repeater_rows( array $arr ) {
		if ( empty( $arr ) ) {
			return false;
		}
		$first = reset( $arr );
		return is_array( $first );
	}

	/**
	 * Safely flatten nested arrays to text.
	 *
	 * @param array $arr Input array.
	 * @return string
	 */
	private static function flatten_array( array $arr ) {
		$items = array();
		foreach ( $arr as $value ) {
			if ( is_array( $value ) ) {
				$nested = self::flatten_array( $value );
				if ( '' !== $nested ) {
					$items[] = $nested;
				}
			} elseif ( is_scalar( $value ) ) {
				$clean = self::clean_scalar_text( $value );
				if ( '' !== $clean ) {
					$items[] = $clean;
				}
			}
		}
		return implode( '، ', $items );
	}

	/**
	 * Clean HTML/entities out of scalar meta values cleanly.
	 *
	 * @param mixed $value Scalar value.
	 * @return string
	 */
	private static function clean_scalar_text( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = (string) $value;
		$text = preg_replace( '#<\s*(?:br|p|li|div)\b[^>]*>#i', "\n", $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( (string) $text );
	}
}
