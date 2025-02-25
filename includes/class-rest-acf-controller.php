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
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	public function get_item_schema() {
		$schema = array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'title'   => 'fuxt_acf_options',
			'type'    => 'object',
		);

		return $schema;
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
				'description' => __( 'ACF option name', 'fuxt-api' ),
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
		$option = ( new AcfUtils() )->get_option_by_name( $request['name'] );

		if ( is_null( $option ) ) {
			return new \WP_Error(
				'rest_post_invalid_acf_setting_name',
				__( 'Invalid ACF Setting Name.', 'fuxt-api' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $option );
	}

}
