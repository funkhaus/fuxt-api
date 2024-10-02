<?php
/**
 * Class REST_Settings_Controller
 *
 * @package FuxtApi
 */

namespace FuxtApi;

use \FuxtApi\Utils\Utils;

/**
 * Class REST_Settings_Controller
 *
 * @package FuxtApi
 */
class REST_Settings_Controller {

	const REST_NAMESPACE = 'fuxt/v1';

	const ROUTE = '/settings';

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
			)
		);
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
		$settings = array(
			'title'              => get_option( 'blogname' ),
			'description'        => get_option( 'blogdescription' ),
			'backendUrl'         => get_option( 'siteurl' ),
			'frontendUrl'        => get_option( 'home' ),
			'themeScreenshotUrl' => wp_get_theme()->get_screenshot(),
		);

		$settings = apply_filters( 'fuxt_api_settings_response', $settings );

		return rest_ensure_response( $settings );
	}
}
