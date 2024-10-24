<?php
/**
 * Class Utils
 *
 * @package FuxtApi
 */

namespace FuxtApi\Utils;

use \FuxtApi\Utils\Acf as AcfUtils;
use DOMDocument as DOMDocument;

/**
 * Class Utils
 *
 * @package FuxtApi
 */
class Utils {

	/**
	 * Get relative url.
	 *
	 * @param string $full_url Full URL.
	 * @return string
	 */
	public static function get_relative_url( $full_url ) {
		return str_replace( home_url(), '', $full_url );
	}

	/**
	 * Checks the post_date_gmt or modified_gmt and prepare any post or
	 * modified date for single post output.
	 *
	 * @param string      $date_gmt GMT publication time.
	 * @param string|null $date     Optional. Local publication time. Default null.
	 * @return string|null ISO8601/RFC3339 formatted datetime.
	 */
	public static function prepare_date_response( $date_gmt, $date = null ) {
		// Use the date if passed.
		if ( isset( $date ) ) {
			return mysql_to_rfc3339( $date );
		}

		// Return null if $date_gmt is empty/zeros.
		if ( '0000-00-00 00:00:00' === $date_gmt ) {
			return null;
		}

		// Return the formatted datetime.
		return mysql_to_rfc3339( $date_gmt );
	}

	/**
	 * Get media data by id.
	 *
	 * @param int $media_id
	 *
	 * @return array|null
	 */
	public static function get_mediadata( $media_id ) {
		if ( empty( $media_id ) ) {
			return null;
		}

		$size  = 'full'; // can be thumbnail|medium|full|array(w,h)
		$image = wp_get_attachment_image_src( $media_id, $size );
		if ( ! $image ) {
			return null;
		}

		$src    = $image[0];
		$width  = $image[1];
		$height = $image[2];

		$image_obj = get_post( $media_id );

		$media_data = array(
			'id'          => $media_id,
			'src'         => $src,
			'width'       => $width,
			'height'      => $height,
			'alt'         => trim( strip_tags( get_post_meta( $media_id, '_wp_attachment_image_alt', true ) ) ),
			'caption'     => $image_obj->post_excerpt,
			'title'       => $image_obj->post_title,
			'description' => apply_filters( 'the_content', $image_obj->post_content ),
		);

		// Add meta data.
		$image_meta = wp_get_attachment_metadata( $media_id );
		if ( is_array( $image_meta ) ) {
			$size_array = array( absint( $width ), absint( $height ) );
			$srcset     = wp_calculate_image_srcset( $size_array, $src, $image_meta, $media_id );
			$sizes      = wp_calculate_image_sizes( $size_array, $src, $image_meta, $media_id );

			if ( $srcset && $sizes ) {
				$media_data['srcset'] = $srcset;
				$media_data['sizes']  = $sizes;
			} else {
				$media_data['srcset'] = $src . ' ' . $width . 'w';
				$media_data['sizes']  = $width . 'px';
			}

			$media_data['meta'] = $image_meta;
		} else {
			$media_data['srcset'] = $src . ' ' . $width . 'w';
			$media_data['sizes']  = $width . 'px';
			$media_data['meta']   = null;
		}

		// Add acf meta data.
		if ( function_exists( 'get_fields' ) ) {
			$media_data['acf'] = AcfUtils::get_data_by_id( $media_id );
		}

		// We can add more media meta fields here.

		return $media_data;
	}

	public static function get_termdata( $term_taxonomy ) {
		if ( empty( $term_taxonomy ) ) {
			return null;
		}

		if ( ! $term_taxonomy instanceof \WP_Term ) {
			$term_taxonomy = get_term_by( 'term_taxonomy_id', $term_taxonomy );

			if ( empty( $term_taxonomy ) ) {
				return null;
			}
		}

		return array(
			'id'     => $term_taxonomy->term_id,
			'name'   => $term_taxonomy->name,
			'slug'   => $term_taxonomy->slug,
			'parent' => $term_taxonomy->parent ? self::get_termdata( $term_taxonomy->parent ) : null,
			'uri'    => self::get_relative_url( get_term_link( $term_taxonomy ) ),
		);
	}

	public static function get_post_types() {
		static $post_types = null;
		if ( empty( $post_types ) ) {
			$post_types = get_post_types(
				array(
					'public'       => true,
					'show_in_rest' => true,
					'_builtin'     => false,
				)
			);
			$post_types = array_merge( array( 'post', 'page' ), $post_types );
		}

		return $post_types;
	}

	/**
	 * Parse query string. Handles param=1&param=2
	 * @param string $str
	 *
	 * @return string
	 */
	public static function cgi_parse_str( $str ) {
		$arr = array();

		$pairs = explode( '&', $str );

		foreach ( $pairs as $i ) {
			$parts = explode( '=', $i, 2 );

			$name  = str_replace( array( '[', ']' ), '', $parts[0] );
			$value = isset( $parts[1] ) ? urldecode( $parts[1] ) : '';

			if ( isset( $arr[ $name ] ) ) {
				if ( is_array( $arr[ $name ] ) ) {
					$arr[ $name ][] = $value;
				} else {
					$arr[ $name ] = array( $arr[ $name ], $value );
				}
			} else {
				$arr[ $name ] = $value;
			}
		}

		return $arr;
	}

	/**
	 * Generate blocks data from block content.
	 *
	 * @param string $blocks_content Block content.
	 *
	 * @return array
	 */
	public static function filter_blocks( $blocks_content ) {
		return array_map( array( self::class, 'extend_block' ), parse_blocks( $blocks_content ) );
	}

	/**
	 * Extend block data.
	 *
	 * @param \WP_Block_Parser_Block $block
	 *
	 * @return array [blockName => '', attrs => [], innterHtml => '', innerBlocks => [], 'embed' => []]
	 *
	 */
	public static function extend_block( $block ) {
		$extended_block = array(
			'blockName' => $block['blockName'],
			'attrs'     => $block['attrs'],
		);

		$extended_block['innerHtml'] = self::get_inner_html( render_block( $block ) );

		// Recursively extend any innerBlocks also.
		if ( ! empty( $block['innerBlocks'] ) ) {
			$extended_block['innerBlocks'] = array_map( array( self::class, 'extend_block' ), $block['innerBlocks'] );
		}

		// Specific block handling.
		switch ( $block['blockName'] ) {
			case 'core/image':
				// Add `embed`
				$extended_block['embed'] = Utils::get_mediadata( $block['attrs']['id'] );
				break;
		}

		return apply_filters( 'fuxt_extend_block', $extended_block );
	}

	public static function get_inner_html( $html ) {
		$dom = new DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML( sprintf( '<body>%s</body>', trim( $html ) ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$body_node   = $dom->getElementsByTagName( 'body' )->item( 0 );
        $first_child = $body_node ? $body_node->firstChild : $dom->documentElement->firstChild; // phpcs:ignore

		$inner_html = '';
		if ( $first_child ) {
            foreach ( $first_child->childNodes as $child ) { // phpcs:ignore
				$inner_html .= $dom->saveHTML( $child );
			}
		}

		return $inner_html;
	}

}

