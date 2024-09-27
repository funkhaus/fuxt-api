<?php
/**
 * Class Utils
 *
 * @package FuxtApi
 */

namespace FuxtApi\Utils;

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
	 * Get acf data.
	 *
	 * @param int $object_id Object ID.
	 *
	 * @return array|null
	 */
	public static function get_acf_data( $object_id ) {

		if ( function_exists( 'get_field_objects' ) ) {
			$fields = \get_field_objects( $object_id );

			return self::sanitize_acf_data( $fields );
		}

		return null;
	}

	/**
	 * Convert acf data to response array.
	 *
	 * @param array $fields Fields array.
	 *
	 * @return array|null
	 */
	public static function sanitize_acf_data( $fields ) {
		$data = array();
		if ( ! empty( $fields ) ) {
			foreach ( $fields as $key => $field ) {
				$value = null;
				switch ( $field['type'] ) {
					case 'image':
						if ( 'array' === $field['return_format'] ) {
							$id = $field['value']['id'];
						} elseif ( 'id' === $field['return_format'] ) {
							$id = $field['value'];
						} elseif ( 'url' === $field['return_format'] ) {
							$id = attachment_url_to_postid( $field['value'] );
						}

						if ( $id ) {
							$value = self::get_mediadata( $id );
						}

						break;

					case 'post_object':
						$value = Post::get_postdata( $field['value'] );

						break;

					case 'relationship':
						$value = array_map( array( Post::class, 'get_postdata' ), $field['value'] );

						break;

					case 'taxonomy':
						$value = array_map( array( Post::class, 'get_postdata' ), $field['value'] );

						break;

					case 'page_link':
						if ( $field['multiple'] ) {
							$ids   = array_map( array( Post::class, 'get_post_by_uri' ), $field['value'] );
							$value = array_map( array( Post::class, 'get_postdata' ), $ids );
						} else {
							$value = Post::get_postdata( Post::get_post_by_uri( $field['value'] ) );
						}

						break;

					case 'group':
						$sub_fields = $field['sub_fields'];
						$sub_fields = array_combine( array_column( $sub_fields, 'name' ), array_values( $sub_fields ) );
						foreach ( $sub_fields as $sub_field_name => &$sub_field ) {
							$sub_field['value'] = $field['value'][ $sub_field_name ] ?? null;
						}

						$value = self::sanitize_acf_data( $sub_fields );

						break;

					case 'repeater':
						$sub_fields = $field['sub_fields'];
						$sub_fields = array_combine( array_column( $sub_fields, 'name' ), array_values( $sub_fields ) );

						$value = array();
						foreach ( $field['value'] as $row ) {
							foreach ( $sub_fields as $sub_field_name => &$sub_field ) {
								$sub_field['value'] = $row[ $sub_field_name ] ?? null;
							}
							$value[] = self::sanitize_acf_data( $sub_fields );
						}

						break;
					default:
						$value = $field['value'];
						break;
				}

				$data[ $key ] = $value;
			}
		}

		return $data;
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

		$media_data = array(
			'id'     => $media_id,
			'src'    => $src,
			'width'  => $width,
			'height' => $height,
			'alt'    => trim( strip_tags( get_post_meta( $media_id, '_wp_attachment_image_alt', true ) ) ),
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
		}

		// Add acf meta data.
		if ( function_exists( 'get_fields' ) ) {
			$media_data['acf'] = Utils::get_acf_data( $media_id );
		}

		// We can add more media meta fields here.

		return $media_data;
	}
}
