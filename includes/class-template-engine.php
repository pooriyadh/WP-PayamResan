<?php
/**
 * Message template renderer for Telegram and Bale.
 *
 * @package WP_PayamResan
 */

namespace WP_PayamResan;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves placeholders, conditional blocks, images, and platform-specific formatting.
 */
class Template_Engine {

	/**
	 * Telegram-supported HTML tags.
	 *
	 * @var array
	 */
	private static $telegram_allowed_html = array(
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

	/**
	 * Render a template for Telegram.
	 *
	 * @param \WP_Post $post       Post object.
	 * @param string   $template   Raw message template.
	 * @param string   $parse_mode 'HTML' or 'none'.
	 * @return string
	 */
	public static function render_for_telegram( \WP_Post $post, $template, $parse_mode = 'HTML' ) {
		$template = self::normalize_newlines( (string) $template );
		$escape   = ( 'HTML' === $parse_mode );
		$rendered = self::replace_placeholders( $post, $template, $escape );

		if ( 'HTML' === $parse_mode ) {
			$rendered = wp_kses( $rendered, self::$telegram_allowed_html );
			$rendered = force_balance_tags( $rendered );
		} else {
			$rendered = self::html_to_plain_text( $rendered );
		}

		return self::clean_whitespace( $rendered );
	}

	/**
	 * Render a template for Bale.
	 *
	 * @param \WP_Post $post        Post object.
	 * @param string   $template    Raw message template.
	 * @param string   $format_mode 'markdown' or 'plain'.
	 * @return string
	 */
	public static function render_for_bale( \WP_Post $post, $template, $format_mode = 'markdown' ) {
		$template = self::normalize_newlines( (string) $template );
		$rendered = self::replace_placeholders( $post, $template, false );

		if ( 'markdown' === $format_mode ) {
			$rendered = self::html_to_bale_markdown( $rendered );
		} else {
			$rendered = self::html_to_plain_text( $rendered );
		}

		return self::clean_whitespace( $rendered );
	}

	/**
	 * Resolve target image (featured image or custom meta field) for a post.
	 *
	 * @param \WP_Post $post   Post object.
	 * @param array    $config Post type configuration.
	 * @return array{url: string, attachment_id: int}
	 */
	public static function resolve_post_image( \WP_Post $post, array $config ) {
		$source = isset( $config['image_source'] ) ? $config['image_source'] : 'featured';

		if ( 'none' === $source ) {
			return array(
				'url'           => '',
				'attachment_id' => 0,
			);
		}

		if ( 'meta' === $source && ! empty( $config['image_meta_key'] ) ) {
			$meta_img = Meta_Resolver::resolve_image_from_meta( $post, $config['image_meta_key'] );
			if ( ! empty( $meta_img['url'] ) ) {
				return $meta_img;
			}
		}

		$thumb_id = get_post_thumbnail_id( $post->ID );
		if ( $thumb_id ) {
			$url = wp_get_attachment_image_url( $thumb_id, 'large' );
			if ( ! $url ) {
				$url = wp_get_attachment_url( $thumb_id );
			}
			if ( $url ) {
				return array(
					'url'           => (string) $url,
					'attachment_id' => (int) $thumb_id,
				);
			}
		}

		return array(
			'url'           => '',
			'attachment_id' => 0,
		);
	}

	/**
	 * Return list of standard and taxonomy placeholders for a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array
	 */
	public static function get_standard_placeholders( $post_type ) {
		$items = array(
			'{post_title}'   => 'عنوان مطلب',
			'{post_excerpt}' => 'خلاصه مطلب',
			'{post_content}' => 'متن کامل (محدودشده)',
			'{post_url}'     => 'لینک کامل مطلب',
			'{short_url}'    => 'لینک کوتاه وردپرس',
			'{post_author}'  => 'نام نویسنده',
			'{post_date}'    => 'تاریخ انتشار',
			'{post_type}'    => 'نام پست تایپ',
			'{categories}'   => 'دسته‌بندی‌ها (هشتگ)',
			'{tags}'         => 'برچسب‌ها (هشتگ)',
			'{site_name}'    => 'نام وب‌سایت',
			'{site_url}'     => 'آدرس وب‌سایت',
		);

		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		if ( is_array( $taxonomies ) ) {
			foreach ( $taxonomies as $tax ) {
				if ( empty( $tax->public ) && empty( $tax->show_ui ) ) {
					continue;
				}
				if ( 'post_format' === $tax->name ) {
					continue;
				}
				$items[ '{tax:' . $tax->name . '}' ] = 'تاکسونومی: ' . $tax->label;
			}
		}

		return $items;
	}

	/**
	 * Safely calculate UTF-8 string length.
	 *
	 * @param string $text Input text.
	 * @return int
	 */
	public static function str_len( $text ) {
		$text = (string) $text;
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}

	/**
	 * Safely extract UTF-8 substring.
	 *
	 * @param string $text   Input text.
	 * @param int    $start  Start offset.
	 * @param int    $length Length.
	 * @return string
	 */
	public static function str_sub( $text, $start, $length ) {
		$text = (string) $text;
		return function_exists( 'mb_substr' ) ? mb_substr( $text, $start, $length, 'UTF-8' ) : substr( $text, $start, $length );
	}

	/**
	 * Truncate text cleanly (balancing HTML tags when HTML mode is used).
	 *
	 * @param string $text       Input text.
	 * @param int    $max_length Maximum character limit.
	 * @param bool   $is_html    Whether text contains HTML tags.
	 * @return string
	 */
	public static function truncate_message( $text, $max_length, $is_html = false ) {
		$text = (string) $text;
		if ( self::str_len( $text ) <= $max_length ) {
			return $text;
		}

		$cut = self::str_sub( $text, 0, max( 1, $max_length - 10 ) );

		// Avoid cutting inside an incomplete HTML tag or entity.
		if ( $is_html ) {
			$last_lt = strrpos( $cut, '<' );
			$last_gt = strrpos( $cut, '>' );
			if ( false !== $last_lt && ( false === $last_gt || $last_lt > $last_gt ) ) {
				$cut = substr( $cut, 0, $last_lt );
			}
		}

		// Trim at last whitespace boundary if reasonable.
		$last_space = function_exists( 'mb_strrpos' ) ? mb_strrpos( $cut, ' ', 0, 'UTF-8' ) : strrpos( $cut, ' ' );
		if ( false !== $last_space && $last_space > (int) ( self::str_len( $cut ) * 0.6 ) ) {
			$cut = self::str_sub( $cut, 0, $last_space );
		}

		$cut = rtrim( $cut ) . '…';

		if ( $is_html ) {
			$cut = force_balance_tags( $cut );
		}

		return $cut;
	}

	/**
	 * Split a long plain/HTML message into chunks within $max_length.
	 *
	 * @param string $text       Message text.
	 * @param int    $max_length Maximum length per chunk.
	 * @param bool   $is_html    Whether text contains HTML tags.
	 * @return array
	 */
	public static function split_message( $text, $max_length, $is_html = false ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return array();
		}

		if ( self::str_len( $text ) <= $max_length ) {
			return array( $text );
		}

		// For HTML mode, strip/balance carefully per chunk or convert overflow cleanly.
		if ( $is_html ) {
			$chunks = array();
			$rest   = $text;
			while ( self::str_len( $rest ) > $max_length ) {
				$slice = self::str_sub( $rest, 0, $max_length - 20 );
				$last_lt = strrpos( $slice, '<' );
				$last_gt = strrpos( $slice, '>' );
				if ( false !== $last_lt && ( false === $last_gt || $last_lt > $last_gt ) ) {
					$slice = substr( $slice, 0, $last_lt );
				}
				$split_pos = strrpos( $slice, "\n" );
				if ( false === $split_pos || $split_pos < (int) ( strlen( $slice ) * 0.4 ) ) {
					$split_pos = strrpos( $slice, ' ' );
				}
				if ( false !== $split_pos && $split_pos > 0 ) {
					$slice = substr( $slice, 0, $split_pos );
				}

				$consumed_len = self::str_len( $slice );
				$chunks[]     = force_balance_tags( trim( $slice ) );
				$rest         = ltrim( self::str_sub( $rest, $consumed_len, self::str_len( $rest ) ) );
				// Avoid orphan closing tags at the beginning of next chunk.
				$rest = preg_replace( '#^(?:\s*</[a-z]+>)+#i', '', $rest );
			}
			if ( '' !== trim( $rest ) ) {
				$chunks[] = force_balance_tags( trim( $rest ) );
			}
			return array_values( array_filter( $chunks ) );
		}

		$chunks = array();
		$rest   = $text;
		while ( self::str_len( $rest ) > $max_length ) {
			$slice     = self::str_sub( $rest, 0, $max_length );
			$split_pos = function_exists( 'mb_strrpos' ) ? mb_strrpos( $slice, "\n", 0, 'UTF-8' ) : strrpos( $slice, "\n" );
			if ( false === $split_pos || $split_pos < (int) ( self::str_len( $slice ) * 0.4 ) ) {
				$split_pos = function_exists( 'mb_strrpos' ) ? mb_strrpos( $slice, ' ', 0, 'UTF-8' ) : strrpos( $slice, ' ' );
			}
			if ( false !== $split_pos && $split_pos > 0 ) {
				$slice = self::str_sub( $slice, 0, $split_pos );
			}
			$chunks[] = trim( $slice );
			$rest     = ltrim( self::str_sub( $rest, self::str_len( $slice ), self::str_len( $rest ) ) );
		}
		if ( '' !== trim( $rest ) ) {
			$chunks[] = trim( $rest );
		}

		return array_values( array_filter( $chunks ) );
	}

	/**
	 * Resolve conditional blocks and replace all template placeholders.
	 *
	 * @param \WP_Post $post        Post object.
	 * @param string   $template    Template text.
	 * @param bool     $escape_html Escape placeholder values for Telegram HTML mode.
	 * @return string
	 */
	private static function replace_placeholders( \WP_Post $post, $template, $escape_html = false ) {
		$values_cache = array();

		$resolver = static function ( $tag ) use ( $post, &$values_cache ) {
			if ( array_key_exists( $tag, $values_cache ) ) {
				return $values_cache[ $tag ];
			}
			$values_cache[ $tag ] = self::resolve_tag( $post, $tag );
			return $values_cache[ $tag ];
		};

		// Process conditional blocks: {if:tag_name}...{/if}
		$template = preg_replace_callback(
			'/\{if:([A-Za-z0-9_:\-\.]+)\}(.*?)\{\/if\}/s',
			static function ( $matches ) use ( $resolver ) {
				$val = $resolver( $matches[1] );
				return '' !== trim( (string) $val ) ? $matches[2] : '';
			},
			$template
		);

		// Replace all {tag} placeholders.
		$rendered = preg_replace_callback(
			'/\{([A-Za-z0-9_:\-\.]+)\}/',
			static function ( $matches ) use ( $resolver, $escape_html ) {
				$tag = $matches[1];
				$val = (string) $resolver( $tag );
				if ( '' === $val ) {
					return '';
				}
				return $escape_html ? htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' ) : $val;
			},
			(string) $template
		);

		return (string) $rendered;
	}

	/**
	 * Resolve a single placeholder key (without braces) for a post.
	 *
	 * @param \WP_Post $post Post object.
	 * @param string   $tag  Tag identifier.
	 * @return string
	 */
	private static function resolve_tag( \WP_Post $post, $tag ) {
		if ( 0 === strpos( $tag, 'meta:' ) ) {
			$meta_key = substr( $tag, 5 );
			return Meta_Resolver::resolve_meta_value( $post, $meta_key );
		}

		if ( 0 === strpos( $tag, 'tax:' ) ) {
			$taxonomy = sanitize_key( substr( $tag, 4 ) );
			return self::format_taxonomy_terms( $post->ID, $taxonomy, true );
		}

		$excerpt_words = max( 5, absint( Settings::get( 'general', 'excerpt_length', 35 ) ) );
		$content_words = max( 20, absint( Settings::get( 'general', 'content_length', 250 ) ) );

		switch ( $tag ) {
			case 'post_id':
				return (string) $post->ID;

			case 'post_title':
				return html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );

			case 'post_excerpt':
				$raw_excerpt = trim( (string) $post->post_excerpt );
				if ( '' !== $raw_excerpt ) {
					$clean = self::clean_post_body( $raw_excerpt );
					return wp_trim_words( $clean, $excerpt_words, '…' );
				}
				$clean_body = self::clean_post_body( $post->post_content );
				return wp_trim_words( $clean_body, $excerpt_words, '…' );

			case 'post_content':
				$clean_body = self::clean_post_body( $post->post_content );
				return wp_trim_words( $clean_body, $content_words, '…' );

			case 'post_url':
				return (string) get_permalink( $post );

			case 'short_url':
				$short = wp_get_shortlink( $post->ID );
				return $short ? (string) $short : (string) get_permalink( $post );

			case 'post_author':
				return (string) get_the_author_meta( 'display_name', (int) $post->post_author );

			case 'post_date':
				return (string) get_the_date( '', $post );

			case 'post_type':
				$pt_obj = get_post_type_object( $post->post_type );
				return ( $pt_obj && ! empty( $pt_obj->labels->singular_name ) ) ? (string) $pt_obj->labels->singular_name : $post->post_type;

			case 'categories':
				return self::resolve_primary_taxonomy_terms( $post, true, true );

			case 'tags':
				return self::resolve_primary_taxonomy_terms( $post, false, true );

			case 'site_name':
				return html_entity_decode( wp_strip_all_tags( get_bloginfo( 'name' ) ), ENT_QUOTES, 'UTF-8' );

			case 'site_url':
				return (string) home_url( '/' );
		}

		/**
		 * Allow third-party code to resolve custom template placeholders.
		 *
		 * @param string   $value Resolved value (empty by default).
		 * @param string   $tag   Placeholder name without braces.
		 * @param \WP_Post $post  Post object.
		 */
		return (string) apply_filters( 'wp_payamresan_resolve_placeholder', '', $tag, $post );
	}

	/**
	 * Resolve hierarchical (categories) or non-hierarchical (tags) taxonomy terms for any post type.
	 *
	 * @param \WP_Post $post         Post object.
	 * @param bool     $hierarchical True for category-like, false for tag-like.
	 * @param bool     $as_hashtag   Whether to prefix terms with #.
	 * @return string
	 */
	private static function resolve_primary_taxonomy_terms( \WP_Post $post, $hierarchical, $as_hashtag ) {
		if ( 'post' === $post->post_type ) {
			return self::format_taxonomy_terms( $post->ID, $hierarchical ? 'category' : 'post_tag', $as_hashtag );
		}

		if ( 'product' === $post->post_type ) {
			$woo_tax = $hierarchical ? 'product_cat' : 'product_tag';
			if ( taxonomy_exists( $woo_tax ) ) {
				return self::format_taxonomy_terms( $post->ID, $woo_tax, $as_hashtag );
			}
		}

		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		if ( ! is_array( $taxonomies ) ) {
			return '';
		}

		$chunks = array();
		foreach ( $taxonomies as $tax ) {
			if ( empty( $tax->public ) && empty( $tax->show_ui ) ) {
				continue;
			}
			if ( 'post_format' === $tax->name ) {
				continue;
			}
			if ( (bool) $tax->hierarchical === (bool) $hierarchical ) {
				$formatted = self::format_taxonomy_terms( $post->ID, $tax->name, $as_hashtag );
				if ( '' !== $formatted ) {
					$chunks[] = $formatted;
				}
			}
		}

		return implode( ' ', $chunks );
	}

	/**
	 * Format taxonomy terms attached to a post.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param bool   $as_hashtag Format as hashtags or comma-separated names.
	 * @return string
	 */
	private static function format_taxonomy_terms( $post_id, $taxonomy, $as_hashtag = true ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		$terms = get_the_terms( $post_id, $taxonomy );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		$output = array();
		foreach ( $terms as $term ) {
			$name = trim( html_entity_decode( wp_strip_all_tags( $term->name ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $name ) {
				continue;
			}
			if ( $as_hashtag ) {
				$hashtag  = preg_replace( '/[^\p{L}\p{N}_]+/u', '_', $name );
				$hashtag  = trim( (string) $hashtag, '_' );
				if ( '' !== $hashtag ) {
					$output[] = '#' . $hashtag;
				}
			} else {
				$output[] = $name;
			}
		}

		return implode( $as_hashtag ? ' ' : '، ', array_unique( $output ) );
	}

	/**
	 * Clean WordPress post content/excerpt into readable plain text.
	 *
	 * @param string $text Raw post content.
	 * @return string
	 */
	private static function clean_post_body( $text ) {
		$text = strip_shortcodes( (string) $text );
		// Strip Gutenberg block comments.
		$text = preg_replace( '/<!--\s*\/?wp:.*?-->/s', '', $text );
		$text = preg_replace( '#<\s*(?:br|p|li|h[1-6]|div)\b[^>]*>#i', "\n", (string) $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Convert lightweight HTML template markup to Bale-compatible Markdown.
	 *
	 * Per docs.bale.ai, Bale parses messages as Markdown automatically:
	 * bold uses *text* with spaces around delimiters, italic uses _text_,
	 * and links use [label](url).
	 *
	 * @param string $html Rendered HTML text.
	 * @return string
	 */
	private static function html_to_bale_markdown( $html ) {
		// Convert <a href="url">label</a> -> [label](url) or url if label matches url.
		$text = preg_replace_callback(
			'#<a\s+[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			static function ( $m ) {
				$url   = html_entity_decode( trim( $m[2] ), ENT_QUOTES, 'UTF-8' );
				$label = trim( wp_strip_all_tags( $m[3] ) );
				if ( '' === $label || $label === $url ) {
					return $url;
				}
				return '[' . $label . '](' . $url . ')';
			},
			(string) $html
		);

		$text = preg_replace_callback(
			'#<(b|strong)\b[^>]*>(.*?)</\1>#is',
			static function ( $m ) {
				$inner = trim( wp_strip_all_tags( $m[2] ) );
				return '' === $inner ? '' : ' *' . $inner . '* ';
			},
			(string) $text
		);

		$text = preg_replace_callback(
			'#<(i|em)\b[^>]*>(.*?)</\1>#is',
			static function ( $m ) {
				$inner = trim( wp_strip_all_tags( $m[2] ) );
				return '' === $inner ? '' : ' _' . $inner . '_ ';
			},
			(string) $text
		);

		$text = preg_replace_callback(
			'#<(code|pre)\b[^>]*>(.*?)</\1>#is',
			static function ( $m ) {
				$inner = trim( wp_strip_all_tags( $m[2] ) );
				if ( '' === $inner ) {
					return '';
				}
				return 'pre' === strtolower( $m[1] ) ? "\n```\n" . $inner . "\n```\n" : ' `' . $inner . '` ';
			},
			(string) $text
		);

		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		// Clean accidental leading spaces on lines created by ' *bold* '.
		$text = preg_replace( '/^[ \t]+/m', '', $text );
		$text = preg_replace( '/[ \t]{2,}/', ' ', (string) $text );

		return (string) $text;
	}

	/**
	 * Convert HTML template markup to clean plain text.
	 *
	 * @param string $html Rendered HTML text.
	 * @return string
	 */
	private static function html_to_plain_text( $html ) {
		$text = preg_replace_callback(
			'#<a\s+[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			static function ( $m ) {
				$url   = html_entity_decode( trim( $m[2] ), ENT_QUOTES, 'UTF-8' );
				$label = trim( wp_strip_all_tags( $m[3] ) );
				if ( '' === $label || $label === $url ) {
					return $url;
				}
				return $label . ': ' . $url;
			},
			(string) $html
		);

		$text = wp_strip_all_tags( (string) $text );
		return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Normalize CRLF/CR to LF.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	private static function normalize_newlines( $text ) {
		return str_replace( array( "\r\n", "\r" ), "\n", $text );
	}

	/**
	 * Collapse excessive blank lines while preserving intentional layout.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	private static function clean_whitespace( $text ) {
		$text = self::normalize_newlines( (string) $text );
		$text = preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
		return trim( (string) $text );
	}
}
