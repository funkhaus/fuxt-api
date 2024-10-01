<?php
/**
 * Class REST_Menu_Controller
 *
 * @package FuxtApi
 */

namespace FuxtApi;

use \FuxtApi\Utils\Utils;

/**
 * Class REST_Menu_Controller
 *
 * @package FuxtApi
 */
class REST_Menu_Controller {

	const REST_NAMESPACE = 'fuxt/v1';

	const ROUTE = '/menus';

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

		$menu_items = wp_get_nav_menu_items( $name, array( 'update_post_term_cache' => false ) );

		if ( false === $menu_items ) {
			return new \WP_Error(
				'rest_post_invalid_uri',
				__( 'Invalid menu name.', 'fuxt-api' ),
				array( 'status' => 404 )
			);
		}

		$all_menu_items = array();
		foreach ( (array) $menu_items as $menu_item ) {
			$all_menu_items[ $menu_item->ID ] = $this->get_menu_data( $menu_item );
		}

		$sorted_menu_items = array_combine( array_column( $all_menu_items, 'menu_order' ), array_values( $all_menu_items ) );
		$sorted_menu_items = array_reverse( array_values( $sorted_menu_items ) );

		foreach ( $sorted_menu_items as $menu_item ) {
			$parent_id = $menu_item['parent_id'];

			if ( $parent_id == 0 ) {
				continue;
			}

			if ( $all_menu_items[ $parent_id ] ) {
				// Add item to parent's children list.
				array_unshift( $all_menu_items[ $parent_id ]['children'], $all_menu_items[ $menu_item['id'] ] );

				// Unset item from parent array.
				unset( $all_menu_items[ $menu_item['id'] ] );
			}
		}

		return rest_ensure_response( array_values( $all_menu_items ) );
	}

	/**
	 * Get menu data from nav_menu_item WP_Post object.
	 *
	 * @param \WP_Post $menu_item Nav menu item post object.
	 *
	 * @return array
	 */
	private function get_menu_data( $menu_item ) {
		if ( (string) $menu_item->menu_item_parent === (string) $menu_item->ID ) {
			$menu_item->menu_item_parent = 0;
		}

		$menu_data = array(
			'id'          => $menu_item->ID,
			'title'       => $menu_item->title,
			'slug'        => $menu_item->post_name,
			'menu_order'  => $menu_item->menu_order,
			'url'         => $menu_item->url,
			'target'      => $menu_item->target,
			'attr_title'  => $menu_item->attr_title,
			'description' => $menu_item->description,
			'classes'     => $menu_item->classes,
			'xfn'         => $menu_item->xfn,
			'parent_id'   => $menu_item->menu_item_parent,
			'type'        => $menu_item->type,
		);

		if ( 'custom' !== $menu_item->type ) {
			$menu_data['uri'] = Utils::get_relative_url( $menu_item->url );
		}

		$menu_data['children'] = array();

		return $menu_data;
	}

}
