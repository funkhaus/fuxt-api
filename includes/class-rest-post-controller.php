<?php
/**
 * Class Plugin
 *
 * @package FuxtApi
 */

namespace FuxtApi;

/**
 * Class Plugin
 *
 * @package FuxtApi
 */
class REST_Post_Controller {

	const REST_NAMESPACE = 'fuxt/v1';

	/**
	 * Init function.
	 */
	public function init() {
		//
	}

	public function register_page_endpoint() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/page',
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
		return array(
			'uri' => array(
				'description' => __( 'Page slug', 'fuxt-api' ),
				'type'        => 'string',
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
		$uri       = $request['uri'] ?? '';
		$post_type = 'page';

		$post = get_page_by_path( $uri, OBJECT, $post_type );
		if ( empty( $post ) ) {
			return new \WP_Error(
				'rest_post_invalid_uri',
				__( 'Invalid page URI.', 'fuxt-api' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->check_read_permission( $post ) ) {
			return new \WP_Error(
				'rest_forbidden_context',
				__( 'Sorry, you are not allowed to access this page.', 'fuxt-api' ),
				array( 'status' => 404 )
			);
		}

		return $this->prepare_item_for_response( $post, $request );
	}

	/**
	 * Get post data for post object.
	 *
	 * @param \WP_Post $post
	 *
	 * @return array
	 */
	private function get_postdata( $post, $additional_fields ) {
		$data = array(
			'id'             => $post->ID,
			'guid'           => $post->guid,
			'title'          => get_the_title( $post ),
			'content'        => apply_filters( 'the_content', $post->post_content ),
			'excerpt'        => apply_filters( 'the_excerpt', apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ) ),
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'date'           => $this->prepare_date_response( $post->post_date_gmt, $post->post_date ),
			'modified'       => $this->prepare_date_response( $post->post_modified_gmt, $post->post_modified ),
			'type'           => $post->post_type,
			'author_id'      => (int) $post->post_author,
			'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
		);

		if ( isset( $additional_fields['media'] ) ) {
			$data['media'] = $this->get_mediadata( get_post_thumbnail_id( $post->ID ) );
		}

		if ( isset( $additional_fields['siblings'] ) ) {
			$data['siblings'] = array();
		}

		if ( isset( $additional_fields['children'] ) ) {
			$data['children'] = array();
		}

		if ( isset( $additional_fields['parent'] ) ) {
			$data['parent'] = array();
		}

		if ( isset( $additional_fields['ancestors'] ) ) {
			$data['ancestors'] = array();
		}

		if ( isset( $additional_fields['next'] ) ) {
			$data['next'] = array();
		}

		if ( isset( $additional_fields['prev'] ) ) {
			$data['prev'] = array();
		}

		return $data;
	}

	/**
	 * Get the next/previous hierarchical post type (eg: Pages)
	 *
	 * @param \WP_Post $post    Post object.
	 * @param bool     $is_next Next or Prev.
	 * @param bool     $loop    Allow loop or not. In loop, the next item for last post will be the first one.
	 *                          The prev item for first post will be the last one.
	 */
	private function get_next_prev_post( $post, $is_next = true, $loop = true ) {
		// Get all siblings pages
		// Yes this is isn't effienct to query all pages,
		// but actually it works well for thousands of pages in practice.
		$args = array(
			'post_type'      => get_post_type( $post ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'post_parent'    => $post->post_parent,
		);

		// Get all siblings
		$siblings = get_posts( $args );

		// Find where current posts exists in Siblings
		$index = array_search( $post->ID, $siblings );

		if ( $is_next ) {
			if ( $index == count( $siblings ) - 1 ) {
				return $loop ? $siblings[0] : null;
			}

			// Get next
			return $siblings[ $index + 1 ];

		} else {
			$on_first = $index == 0;

			// If on first, then return last, or null if not looping
			if ( $loop && $on_first ) {
				return end( $siblings );
			} elseif ( ! $loop && $on_first ) {
				return null;
			}

			// Get previous
			return $siblings[ $index - 1 ];
		}

	}

	/**
	 * Get media data by id.
	 *
	 * @param int $media_id
	 *
	 * @return array|false
	 */
	private function get_mediadata( $media_id ) {
		if ( empty( $media_id ) ) {
			return false;
		}

		$size  = 'full'; // can be thumbnail|medium|full|array(w,h)
		$image = wp_get_attachment_image_src( $media_id, $size );
		if ( ! $image ) {
			return false;
		}

		$media_data = array(
			'id'     => $media_id,
			'src'    => $image[0],
			'width'  => $image[1],
			'height' => $image[2],
		);

		// We can add more media meta fields here.

		return $media_data;
	}

	/**
	 * Prepares a single post output for response.
	 *
	 * @global WP_Post $post Global post object.
	 *
	 * @param WP_Post         $item    Post object.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $item, $request ) {
		// Restores the more descriptive, specific name for use within this method.
		$post = $item;

		$GLOBALS['post'] = $post;

		setup_postdata( $post );

		// Base data for every post.
		$data = array(
			'id'             => $post->ID,
			'guid'           => $post->guid,
			'title'          => get_the_title( $post ),
			'content'        => apply_filters( 'the_content', $post->post_content ),
			'excerpt'        => apply_filters( 'the_excerpt', apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ) ),
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'date'           => $this->prepare_date_response( $post->post_date_gmt, $post->post_date ),
			'modified'       => $this->prepare_date_response( $post->post_modified_gmt, $post->post_modified ),
			'type'           => $post->post_type,
			'author_id'      => (int) $post->post_author,
			'featured_media' => (int) get_post_thumbnail_id( $post->ID ),
			'parent'         => $post->post_parent,
		);

		$fields = $this->get_fields_for_response( $request );

		if ( rest_is_field_included( 'media', $fields ) ) {

		}

		$response = rest_ensure_response( $data );

		return $response;
	}

	/**
	 * Gets an array of fields to be included on the response.
	 *
	 * Included fields are based on item schema and `_fields=` request argument.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return string[] Fields to be included in the response.
	 */
	public function get_fields_for_response( $request ) {

		$fields = array(
			'media',
			'siblings',
			'children',
			'parent',
			'ancestors',
			'next',
			'prev',
		);

		if ( ! isset( $request['_fields'] ) ) {
			return $fields;
		}

		$requested_fields = wp_parse_list( $request['_fields'] );
		if ( 0 === count( $requested_fields ) ) {
			return $fields;
		}
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


	/**
	 * Checks the post_date_gmt or modified_gmt and prepare any post or
	 * modified date for single post output.
	 *
	 * @param string      $date_gmt GMT publication time.
	 * @param string|null $date     Optional. Local publication time. Default null.
	 * @return string|null ISO8601/RFC3339 formatted datetime.
	 */
	protected function prepare_date_response( $date_gmt, $date = null ) {
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
}
