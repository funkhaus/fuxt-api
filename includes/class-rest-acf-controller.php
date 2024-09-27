<?php
/**
 * Class REST_Acf_Controller
 *
 * @package FuxtApi
 */

namespace FuxtApi;

use \FuxtApi\Utils\Acf as AcfUtils;

/**
 * Class REST_Acf_Controller
 *
 * @package FuxtApi
 */
class REST_Acf_Controller {

	const REST_NAMESPACE = 'fuxt/v1';

	const ROUTE = '/acf-options';

	/**
	 * Init function.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
	}

	/**
	 * Register acf endpoint.
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
			)
		);
	}

	/**
	 * Checks if a given request has access to read acf setting.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! isset( $request['name'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Retrieves the query params for the collection.
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		return array(
			'name' => array(
				'description' => __( 'Menu name', 'fuxt-api' ),
				'type'        => 'string',
			),
		);
	}

	/**
	 * Retrieves menu.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$name = $request['name'];

		return rest_ensure_response( AcfUtils::get_option_by_name( $name ) );
	}

}