<?php
/**
 * Util functions to parse block.
 *
 * @package FuxtApi
 */

namespace FuxtApi\Utils;

use FuxtApi\Block_Parser;

/**
 * Class Block utils
 *
 * @package FuxtApi
 */
class Block {

	/**
	 * Get block array data from post_content.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @param array
	 */
	public static function get_block_data( $post ) {

		$post_content = $post->post_content;
		if ( empty( $post_content ) || ! has_blocks( $post_content ) ) {
			return array();
		}

		$parser_result = self::get_block_parser()->parse( $post_content, $post->ID );

		if ( is_wp_error( $parser_result ) ) {
			$error_data    = $parser_result->get_error_data();
			$wp_error_data = '';

			// Forward HTTP status if present in WP_Error.
			if ( isset( $error_data['status'] ) ) {
				$wp_error_data = [ 'status' => intval( $error_data['status'] ) ];
			}

			return new \WP_Error( $parser_result->get_error_code(), $parser_result->get_error_message(), $wp_error_data );
		}

		return $parser_result;
		// return $blocks;
	}

	public static function get_block_parser() {
		static $parser = null;

		if ( $parser === null ) {
			if ( ! class_exists( 'WPCOMVIP\BlockDataApi\ContentParser' ) ) {
				require_once fuxt_api_get_plugin_instance()->dir_path . '/vendor/autoload.php';
				require_once fuxt_api_get_plugin_instance()->dir_path . '/includes/libs/content-parser.php';
			}

			$parser = new Block_Parser();
		}

		return $parser;
	}

}
