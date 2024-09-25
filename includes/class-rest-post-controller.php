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
		add_action( 'rest_api_init', array( $this, 'register_page_endpoint' ) );
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
	 * Prepares a single post output for response.
	 *
	 * @global WP_Post $post Global post object.
	 *
	 * @param WP_Post         $post    Post object.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $post, $request ) {
		$additional_fields = $this->get_additional_fields_for_response( $request );
		return rest_ensure_response( $this->get_postdata( $post, $additional_fields ) );
	}

	/**
	 * Get post data for post object.
	 *
	 * @param \WP_Post|int $post              Post object.
	 * @param array        $additional_fields Additional post fields to return.
	 *
	 * @return array|null
	 */
	private function get_postdata( $post, $additional_fields = array() ) {
		// In case int value is provided.
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post );

			if ( ! $post instanceof \WP_Post ) {
				return null;
			}
		}

		$data = array(
			'id'        => $post->ID,
			'guid'      => $post->guid,
			'title'     => get_the_title( $post ),
			'content'   => apply_filters( 'the_content', $post->post_content ),
			'excerpt'   => apply_filters( 'the_excerpt', apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ) ),
			'slug'      => $post->post_name,
			'uri'       => Utils::get_relative_url( get_permalink( $post ) ),
			'status'    => $post->post_status,
			'date'      => $this->prepare_date_response( $post->post_date_gmt, $post->post_date ),
			'modified'  => $this->prepare_date_response( $post->post_modified_gmt, $post->post_modified ),
			'type'      => $post->post_type,
			'author_id' => (int) $post->post_author,
			'acf'       => $this->get_acf_data( $post->ID ),
		);

		if ( ! empty( $additional_fields ) && is_array( $additional_fields ) ) {
			if ( in_array( 'media', $additional_fields ) ) {
				$data['media'] = $this->get_mediadata( get_post_thumbnail_id( $post->ID ) );
			}

			if ( in_array( 'siblings', $additional_fields ) ) {
				$data['siblings'] = array_map( array( $this, 'get_postdata' ), $this->get_sibling_posts( $post ) );
			}

			if ( in_array( 'children', $additional_fields ) ) {
				$children = get_children(
					array(
						'post_parent' => $post->ID,
						'post_type'   => $post->post_type,
					)
				);

				$data['children'] = array_map( array( $this, 'get_postdata' ), $children );
			}

			if ( in_array( 'parent', $additional_fields ) ) {
				$parent         = get_post_parent( $post );
				$data['parent'] = $parent ? $this->get_postdata( $parent ) : null;
			}

			if ( in_array( 'ancestors', $additional_fields ) ) {
				$data['ancestors'] = array_map( array( $this, 'get_postdata' ), get_post_ancestors( $post ) );
			}

			if ( in_array( 'next', $additional_fields ) ) {
				$next_post    = $this->get_next_prev_post( $post, true, true );
				$data['next'] = $next_post ? $this->get_postdata( $next_post ) : null;
			}

			if ( in_array( 'prev', $additional_fields ) ) {
				$prev_post    = $this->get_next_prev_post( $post, false, true );
				$data['prev'] = $prev_post ? $this->get_postdata( $prev_post ) : null;
			}
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
	 *
	 * @return \WP_Post|null
	 */
	private function get_next_prev_post( $post, $is_next = true, $loop = true ) {
		// Get all siblings
		$siblings = $this->get_sibling_posts( $post );

		$sibling_ids = array_map(
			function( $sibling ) {
				return $sibling->ID;
			},
			$siblings
		);

		// Find where current posts exists in Siblings
		$index = array_search( $post->ID, $sibling_ids );

		if ( $is_next ) {
			if ( $index == count( $siblings ) - 1 ) {
				return $loop ? $siblings[0] : null;
			}

			return $siblings[ $index + 1 ];
		} else {
			if ( $index == 0 ) {
				return $loop ? end( $siblings ) : null;
			}

			return $siblings[ $index - 1 ];
		}
	}

	/**
	 * Get sibling posts.
	 *
	 * @param \WP_Post $post
	 *
	 * @return \WP_Post[]
	 */
	private function get_sibling_posts( $post ) {
		// Get all siblings pages
		// Yes this is isn't effienct to query all pages,
		// but actually it works well for thousands of pages in practice.
		$args = array(
			'post_type'      => get_post_type( $post ),
			'posts_per_page' => -1,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'post_parent'    => $post->post_parent,
		);

		// Get all siblings
		$siblings = get_posts( $args );

		return array_values(
			array_filter(
				$siblings,
				function( $sibling ) use ( $post ) {
					return $sibling->ID !== $post->ID;
				}
			)
		);
	}

	/**
	 * Get media data by id.
	 *
	 * @param int $media_id
	 *
	 * @return array|null
	 */
	private function get_mediadata( $media_id ) {
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
			$media_data['acf'] = $this->get_acf_data( $media_id );
		}

		// We can add more media meta fields here.

		return $media_data;
	}

	/**
	 * Get acf data.
	 *
	 * @param int    $object_id Object ID.
	 *
	 * @return array|false
	 */
	private function get_acf_data( $object_id ) {

		if ( function_exists( 'get_field_objects' ) ) {
			$fields = \get_field_objects( $object_id );
		}

		$data = array();
		if ( ! empty( $fields ) ) {
			foreach ( $fields as $key => $field ) {
				$data[ $key ] = $field['value'];
			}
		}

		return $data;
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
			'media',
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
