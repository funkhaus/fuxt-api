<?php
/**
 * Class REST_Posts_Controller
 *
 * @package FuxtApi
 */

namespace FuxtApi;

use FuxtApi\Utils\Post as PostUtils;
use FuxtApi\Utils\Utils;

/**
 * Class REST_Posts_Controller
 *
 * @package FuxtApi
 */
class REST_Posts_Controller {

	const REST_NAMESPACE = 'fuxt/v1';

	const ROUTE = '/posts';

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

	public function get_item_schema() {
		$schema = array(
			'$schema' => 'http://json-schema.org/draft-04/schema#',
			'type'    => 'array',
			'title'   => 'fuxt_posts',
			'items'   => array(
				'type'       => 'object',
				'properties' => ( REST_Post_Controller::get_item_schema() )['properties'],
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
		return array(
			'post_parent_uri' => array(
				'description' => __( 'Parent post slug', 'fuxt-api' ),
				'type'        => 'string',
			),
			'term_slug'       => array(
				'description' => __( 'Terms slug', 'fuxt-api' ),
				'type'        => 'string',
			),
			'orderby'         => array(
				'description' => __( 'orderby', 'fuxt-api' ),
				'type'        => 'string',
				'default'     => 'date',
				'enum'        => array(
					'author',
					'date',
					'id',
					'include',
					'modified',
					'parent',
					'relevance',
					'slug',
					'include_slugs',
					'title',
					'menu_order',
				),
			),
			'order'           => array(
				'description' => __( 'order', 'fuxt-api' ),
				'type'        => 'string',
				'default'     => 'desc',
				'enum'        => array( 'asc', 'desc' ),
			),
			'per_page'        => array(
				'description' => __( 'Per page', 'fuxt-api' ),
				'type'        => 'integer',
				'default'     => 10,
				'minimum'     => 1,
				'maximum'     => 100,
			),
			'page'            => array(
				'description' => __( 'Page number', 'fuxt-api' ),
				'type'        => 'integer',
			),
			'post_type'       => array(
				'description' => __( 'Post type', 'fuxt-api' ),
				'type'        => 'string',
				'enum'        => Utils::get_post_types(),
			),
			'fields'          => array(
				'description' => __( 'Additional fields to return. Comma separated string of fields.', 'fuxt-api' ),
				'type'        => 'string',
				'items'       => array(
					'type' => 'string',
					'enum' => array(
						'acf',
						'terms',
						'siblings',
						'children',
						'parent',
						'ancestors',
						'next',
						'prev',
					),
				),
			),
		);
	}

	/**
	 * Retrieves a collection of posts.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$additional_fields = $this->get_additional_fields_for_response( $request );
		$posts             = PostUtils::get_posts( $request, $additional_fields );

		if ( empty( $posts ) ) {
			$posts = array(
				'list'        => array(),
				'total'       => 0,
				'total_pages' => 0,
			);
		}

		$response = rest_ensure_response( $posts['list'] );

		$response->header( 'X-WP-Total', (int) $posts['total'] );
		$response->header( 'X-WP-TotalPages', (int) $posts['total_pages'] );

		return $response;
	}

	/**
	 * Gets an array of additional fields to be included on the response.
	 *
	 * Included fields are based on item schema and `fields=` request argument.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return string[] Fields to be included in the response.
	 */
	public function get_additional_fields_for_response( $request ) {

		if ( ! isset( $request['fields'] ) ) {
			return array();
		}

		$requested_fields = wp_parse_list( $request['fields'] );
		if ( 0 === count( $requested_fields ) ) {
			return array();
		}

		$fields = array(
			'acf',
			'terms',
			'siblings',
			'children',
			'parent',
			'ancestors',
			'next',
			'prev',
		);

		// Trim off outside whitespace from the comma delimited list.
		$requested_fields = array_map( 'trim', $requested_fields );

		// Always persist 'id'.
		$requested_fields[] = 'id';

		// Return the list of all requested fields which appear in the schema.
		return array_reduce(
			$requested_fields,
			static function ( $response_fields, $field ) use ( $fields ) {
				if ( in_array( $field, $fields, true ) ) {
					$response_fields[] = $field;
					return $response_fields;
				}
				// Check for nested fields if $field is not a direct match.
				$nested_fields = explode( '.', $field );
				/*
				 * A nested field is included so long as its top-level property
				 * is present in the schema.
				 */
				if ( in_array( $nested_fields[0], $fields, true ) ) {
					$response_fields[] = $field;
				}
				return $response_fields;
			},
			array()
		);
	}

	/**
	 * Checks if a post can be read.
	 *
	 * Correctly handles posts with the inherit status.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool Whether the post can be read.
	 */
	public function check_read_permission( $post ) {
		$post_type = get_post_type_object( $post->post_type );
		if ( ! $this->check_is_post_type_allowed( $post_type ) ) {
			return false;
		}

		// Is the post readable?
		if ( 'publish' === $post->post_status || current_user_can( 'read_post', $post->ID ) ) {
			return true;
		}

		$post_status_obj = get_post_status_object( $post->post_status );
		if ( $post_status_obj && $post_status_obj->public ) {
			return true;
		}

		// Can we read the parent if we're inheriting?
		if ( 'inherit' === $post->post_status && $post->post_parent > 0 ) {
			$parent = get_post( $post->post_parent );
			if ( $parent ) {
				return $this->check_read_permission( $parent );
			}
		}

		/*
		 * If there isn't a parent, but the status is set to inherit, assume
		 * it's published (as per get_post_status()).
		 */
		if ( 'inherit' === $post->post_status ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if a given post type can be viewed or managed.
	 *
	 * @param WP_Post_Type|string $post_type Post type name or object.
	 * @return bool Whether the post type is allowed in REST.
	 */
	protected function check_is_post_type_allowed( $post_type ) {
		if ( ! is_object( $post_type ) ) {
			$post_type = get_post_type_object( $post_type );
		}

		if ( ! empty( $post_type ) && ! empty( $post_type->show_in_rest ) ) {
			return true;
		}

		return false;
	}
}
