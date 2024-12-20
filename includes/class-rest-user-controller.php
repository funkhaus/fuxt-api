<?php
/**
 * Class REST_User_Controller
 *
 * @package FuxtApi
 */

namespace FuxtApi;

use \FuxtApi\Utils\Utils;

/**
 * Class REST_User_Controller
 *
 * @package FuxtApi
 */
class REST_User_Controller {

	const REST_NAMESPACE = 'fuxt/v1';

	const ROUTE = '/user';

	/**
	 * Init function.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
	}

	/**
	 * Register post endpoint.
	 */
	public function register_endpoint() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}
	/**
	 * Item schema
	 *
	 * @return array.
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'fuxt_user',
			'type'       => 'object',
			'properties' => array(
				'id'       => array(
					'description' => __( 'Current User ID.', 'fuxt-api' ),
					'type'        => 'integer',
				),
				'nicename' => array(
					'description' => __( 'User name', 'fuxt-api' ),
					'type'        => 'string',
				),
				'avatar'   => array(
					'description' => __( 'User Avatar', 'fuxt-api' ),
					'type'        => array( 'object', 'null' ),
				),
			),
		);

		return $schema;
	}

	/**
	 * Checks if a given request has access to read posts.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		return true;
	}

	/**
	 * Retrieves the query params for the collection.
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		return array();
	}

	/**
	 * Retrieves menu.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {

		$user_id = apply_filters( 'determine_current_user', false );
		if ( ! empty( $user_id ) ) {
			$user_info = get_userdata( $user_id );
			$avatar    = get_avatar_data( $user_id, array( 'size' => 20 ) );

			$user = array(
				'id'         => $user_id,
				'first_name' => $user_info->user_firstname,
				'last_name'  => $user_info->user_lastname,
				'nicename'   => $user_info->user_nicename,
				'avatar'     => array(
					'url'    => $avatar['url'],
					'width'  => $avatar['width'],
					'height' => $avatar['height'],
				),
			);
		} else {
			$user = array(
				'id'       => 0,
				'nicename' => '',
				'avatar'   => null,
			);
		}

		$user = apply_filters( 'fuxt_api_user_response', $user );

		return rest_ensure_response( $user );
	}
}
