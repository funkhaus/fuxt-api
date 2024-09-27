<?php
/**
 * Class Acf
 *
 * @package FuxtApi
 */

namespace FuxtApi\Utils;

use FuxtApi\Utils\Utils;
use FuxtApi\Utils\Post as PostUtils;

/**
 * Class Acf
 *
 * @package FuxtApi
 */
class Acf {

	/**
	 * Get acf data.
	 *
	 * @param int $object_id Object ID.
	 *
	 * @return array|null
	 */
	public static function get_data_by_id( $object_id ) {

		if ( function_exists( 'get_field_objects' ) ) {
			$fields = \get_field_objects( $object_id );

			return self::get_data_by_fields( $fields );
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
	public static function get_data_by_fields( $fields ) {
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
							$value = Utils::get_mediadata( $id );
						}

						break;

					case 'post_object':
						$value = PostUtils::get_postdata( $field['value'] );

						break;

					case 'relationship':
						$value = array_map( array( PostUtils::class, 'get_postdata' ), $field['value'] );

						break;

					case 'taxonomy':
						$value = array_map( array( PostUtils::class, 'get_postdata' ), $field['value'] );

						break;

					case 'page_link':
						if ( $field['multiple'] ) {
							$ids   = array_map( array( PostUtils::class, 'get_post_by_uri' ), $field['value'] );
							$value = array_map( array( PostUtils::class, 'get_postdata' ), $ids );
						} else {
							$value = PostUtils::get_postdata( PostUtils::get_post_by_uri( $field['value'] ) );
						}

						break;

					case 'group':
						$sub_fields = $field['sub_fields'];
						$sub_fields = array_combine( array_column( $sub_fields, 'name' ), array_values( $sub_fields ) );
						foreach ( $sub_fields as $sub_field_name => &$sub_field ) {
							$sub_field['value'] = $field['value'][ $sub_field_name ] ?? null;
						}

						$value = self::get_data_by_fields( $sub_fields );

						break;

					case 'repeater':
						$sub_fields = $field['sub_fields'];
						$sub_fields = array_combine( array_column( $sub_fields, 'name' ), array_values( $sub_fields ) );

						$value = array();
						foreach ( $field['value'] as $row ) {
							foreach ( $sub_fields as $sub_field_name => &$sub_field ) {
								$sub_field['value'] = $row[ $sub_field_name ] ?? null;
							}
							$value[] = self::get_data_by_fields( $sub_fields );
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
	 * Get acf option field by title.
	 *
	 * @param string $title Group title.
	 *
	 * @return array|null Returns option value.
	 */
	public static function get_option_by_name( $title ) {
		$posts = get_posts(
			array(
				'post_type' => 'acf-field-group',
				'title'     => $title,
			)
		);

		if ( empty( $posts ) ) {
			return null;
		}

		$field_group_id = $posts[0]->ID;
		$fields         = \acf_get_fields( $field_group_id );

		$data = array();
		foreach ( $fields as $field ) {
			$field['value']         = \get_field( $field['name'], 'option' );
			$data[ $field['name'] ] = $field;
		}

		return self::get_data_by_fields( $data );
	}
}
