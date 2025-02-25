<?php
/**
 * Class Utils
 *
 * @package FuxtApi
 */

namespace FuxtApi\Utils;

use FuxtApi\Utils\Acf as AcfUtils;

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
	 * Get image data by id.
	 *
	 * @param int $image_id
	 *
	 * @return array|null
	 */
	public static function get_imagedata( $image_id ) {
		if ( empty( $image_id ) ) {
			return null;
		}

		$size  = 'full'; // can be thumbnail|medium|full|array(w,h)
		$image = wp_get_attachment_image_src( $image_id, $size );
		if ( ! $image ) {
			return null;
		}

		$src    = $image[0];
		$width  = $image[1];
		$height = $image[2];

		$image_obj = get_post( $image_id );

		$image_data = array(
			'id'          => $image_id,
			'src'         => $src,
			'width'       => $width,
			'height'      => $height,
			'alt'         => trim( strip_tags( get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) ),
			'caption'     => $image_obj->post_excerpt,
			'title'       => $image_obj->post_title,
			'description' => apply_filters( 'the_content', $image_obj->post_content ),
			'mime_type'   => get_post_mime_type( $image_id ),
			'html'        => wp_get_attachment_image( $image_id, 'full' ),
		);

		// Check if svg.
		$svg = self::encode_svg( $image_id );
		if ( $svg ) {
			$image_data['encoded_content'] = $svg;
		}

		// Add meta data.
		$image_meta = wp_get_attachment_metadata( $image_id );
		if ( is_array( $image_meta ) ) {
			$size_array = array( absint( $width ), absint( $height ) );
			$srcset     = wp_calculate_image_srcset( $size_array, $src, $image_meta, $image_id );
			$sizes      = wp_calculate_image_sizes( $size_array, $src, $image_meta, $image_id );

			if ( $srcset && $sizes ) {
				$image_data['srcset'] = $srcset;
				$image_data['sizes']  = $sizes;
			} else {
				$image_data['srcset'] = $src . ' ' . $width . 'w';
				$image_data['sizes']  = $width . 'px';
			}

			$image_data['meta'] = $image_meta;
		} else {
			$image_data['srcset'] = $src . ' ' . $width . 'w';
			$image_data['sizes']  = $width . 'px';
			$image_data['meta']   = null;
		}

		// Add acf meta data.
		if ( function_exists( 'get_fields' ) ) {
			$image_data['acf'] = ( new AcfUtils() )->get_data_by_id( $image_id );
		}

		return $image_data;
	}

	/**
	 * Encode SVG file by id.
	 *
	 * @param int $image_id Attachement id.
	 * @return string|false
	 */
	private static function encode_svg( $image_id ) {
		$file_path = get_attached_file( $image_id );
		$file_type = wp_check_filetype( $file_path );
		if ( $file_type['ext'] === 'svg' ) {
			$svg_content = file_get_contents( $file_path );
			if ( $svg_content ) {
				return base64_encode( $svg_content );
			}
		}

		return false;
	}

	/**
	 * Get video data by id.
	 *
	 * @param int $video_id
	 *
	 * @return array|null
	 */
	public static function get_videodata( $video_id ) {
		if ( empty( $video_id ) ) {
			return null;
		}

		$video_post = get_post( $video_id );
		if ( ! $video_post ) {
			return null;
		}

		$video_data = array(
			'id'          => $video_id,
			'src'         => wp_get_attachment_url( $video_id ),
			'title'       => $video_post->post_title,
			'description' => $video_post->post_excerpt,
		);

		$metadata = wp_get_attachment_metadata( $video_id );
		if ( ! empty( $metadata ) && is_array( $metadata ) ) {
			$video_data['width']  = $metadata['width'];
			$video_data['height'] = $metadata['height'];
			$video_data['length'] = $metadata['length'];
		}

		// Add acf meta data.
		if ( function_exists( 'get_fields' ) ) {
			$video_data['acf'] = ( new AcfUtils() )->get_data_by_id( $video_id );
		}

		return $video_data;
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

		$to = self::get_relative_url( get_term_link( $term_taxonomy ) );

		return array(
			'id'     => $term_taxonomy->term_id,
			'name'   => $term_taxonomy->name,
			'slug'   => $term_taxonomy->slug,
			'parent' => $term_taxonomy->parent ? self::get_termdata( $term_taxonomy->parent ) : null,
			'uri'    => $to,
			'to'     => $to,
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
	 * Convert camelCase to snake_case.
	 *
	 * @param string $str.
	 *
	 * @return string
	 */
	public static function decamelize( $str ) {
		return strtolower( preg_replace( array( '/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/' ), '$1_$2', $str ) );
	}
}
