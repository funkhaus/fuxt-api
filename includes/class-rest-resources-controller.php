<?php
/**
 * Class REST_Resources_Controller
 *
 * @package FuxtApi
 */

namespace FuxtApi;

/**
 * Class REST_Resources_Controller
 *
 * @package FuxtApi
 */
class REST_Resources_Controller {
	const REST_NAMESPACE = 'fuxt/v1';
	const ROUTE          = '/resources';
	const ROUTE_CATEGORY = '/resources/c/(?P<category_slug>[a-zA-Z0-9-]+)';

	/** Edit this list to change which ACF keys are returned */
	private array $acf_keys = [ 'file', 'areas_of_expertise_links' ];

	/** Fields that contain relationship IDs that should return titles only */
	private array $relationship_fields = [ 'areas_of_expertise_links' ];

	/** Custom taxonomies to return for resources */
	private array $taxonomies = [ 'program' ];

	/**
	 * Resources REST endpoint
	 * GET /wp-json/fuxt/v1/resources?per_page=300&page=1
	 * GET /wp-json/fuxt/v1/resources/c/health?per_page=300&page=1
	 *
	 * Returns items shaped like:
	 * {
	 *   "page": 1,
	 *   "per_page": 300,
	 *   "has_more": true,
	 *   "items": [
	 *     { 
	 *       "id": 3191,
	 *       "guid": "https://example.com/?post_type=resource&#038;p=3191",
	 *       "title": "Resource Title",
	 *       "content": "",
	 *       "excerpt": "",
	 *       "excerpt_raw": "",
	 *       "slug": "resource-slug",
	 *       "url": "https://example.com/resources/resource-slug/",
	 *       "uri": "/resources/resource-slug/",
	 *       "to": "/resources/resource-slug/",
	 *       "status": "publish",
	 *       "date": "2025-08-05T19:56:25",
	 *       "modified": "2025-08-27T18:08:33",
	 *       "type": "resource",
	 *       "author_id": 4,
	 *       "terms": {
	 *         "program": [
	 *           {
	 *             "id": 45,
	 *             "name": "Program Name",
	 *             "slug": "program-name",
	 *             "parent": null,
	 *             "uri": "/program/program-name/",
	 *             "to": "/program/program-name/"
	 *           }
	 *         ]
	 *       },
	 *       "acf": { 
	 *         "file": "https://example.com/wp-content/uploads/2025/01/document.pdf",
	 *         "areas_of_expertise_links": [
	 *           { "id": 150, "title": "Agriculture" },
	 *           { "id": 1587, "title": "Technology" }
	 *         ]
	 *       } 
	 *     }
	 *   ]
	 * }
	 */

	/**
	 * Init function.
	 */
	public function init() : void {
		add_action( 'rest_api_init', [ $this, 'register_endpoint' ] );
	}

	/**
	 * Register resources endpoint.
	 */
	public function register_endpoint() : void {
		// Main route: /resources
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => '__return_true',
					'args'                => $this->get_collection_params(),
				],
			]
		);

		// Category filter route: /resources/c/{category_slug}
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CATEGORY,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => '__return_true',
					'args'                => array_merge(
						$this->get_collection_params(),
						[
							'category_slug' => [
								'type'        => 'string',
								'required'    => true,
								'description' => 'Category slug to filter by',
							],
						]
					),
				],
			]
		);
	}

	private function get_collection_params() : array {
		return [
			'page'     => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page' => [
				'type'    => 'integer',
				'default' => 300,
				'minimum' => 1,
				'maximum' => 500,
			],
		];
	}

	/**
	 * Retrieves a collection of resources.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$page     = max( 1, (int) ( $request['page'] ?? 1 ) );
		$per_page = min( 500, max( 1, (int) ( $request['per_page'] ?? 300 ) ) );

		$query_args = [
			'post_type'              => 'resource',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
			'fields'                 => 'ids',
		];

		// Filter by program taxonomy if slug is provided
		$category_slug = $request['category_slug'] ?? null;
		if ( $category_slug ) {
			$query_args['tax_query'] = [
				[
					'taxonomy' => 'program',
					'field'    => 'slug',
					'terms'    => sanitize_title( $category_slug ),
				],
			];
		}

		$q = new \WP_Query( $query_args );

		$items = array_map( [ $this, 'map_resource' ], $q->posts );

		$payload = [
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => count( $items ) === $per_page,
			'items'    => $items,
		];

		// Add program info if filtering
		if ( $category_slug ) {
			$term = get_term_by( 'slug', $category_slug, 'program' );
			if ( $term && ! is_wp_error( $term ) ) {
				$payload['program'] = $this->map_term( $term );
			}
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * Map a resource post to the response format.
	 *
	 * @param int $post_id The post ID.
	 * @return array Mapped post data.
	 */
	private function map_resource( $post_id ) : array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$url = get_permalink( $post_id );
		$uri = str_replace( home_url(), '', $url );

		return [
			'id'          => (int) $post_id,
			'guid'        => $post->guid,
			'title'       => $post->post_title,
			'content'     => $post->post_content,
			'excerpt'     => get_the_excerpt( $post_id ),
			'excerpt_raw' => $post->post_excerpt,
			'slug'        => $post->post_name,
			'url'         => $url,
			'uri'         => $uri,
			'to'          => $uri,
			'status'      => $post->post_status,
			'date'        => $post->post_date,
			'modified'    => $post->post_modified,
			'type'        => $post->post_type,
			'author_id'   => (int) $post->post_author,
			'terms'       => $this->get_post_terms( $post_id ),
			'acf'         => $this->get_acf_subset( $post_id ),
		];
	}

	/**
	 * Get ACF fields subset for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return array ACF data.
	 */
	private function get_acf_subset( int $post_id ) : array {
		$out = [];
		if ( ! function_exists( 'get_field' ) ) {
			return $out;
		}

		foreach ( $this->acf_keys as $key ) {
			$value = get_field( $key, $post_id, false );

			// For relationship fields, just get titles
			if ( in_array( $key, $this->relationship_fields, true ) && is_array( $value ) ) {
				$out[ $key ] = $this->get_relationship_titles( $value );
			} else {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Get relationship field as array of id/title pairs.
	 *
	 * @param array $ids Array of post IDs.
	 * @return array Array with id and title only.
	 */
	private function get_relationship_titles( array $ids ) : array {
		if ( empty( $ids ) ) {
			return [];
		}

		$posts = get_posts( [
			'post__in'       => array_map( 'intval', $ids ),
			'post_type'      => 'any',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'post__in',
		] );

		return array_map( function( $post ) {
			return [
				'id'    => $post->ID,
				'title' => $post->post_title,
			];
		}, $posts );
	}

	/**
	 * Get terms for a post from configured taxonomies.
	 *
	 * @param int $post_id The post ID.
	 * @return array Terms organized by taxonomy.
	 */
	private function get_post_terms( int $post_id ) : array {
		$terms = [];

		foreach ( $this->taxonomies as $taxonomy ) {
			$post_terms = get_the_terms( $post_id, $taxonomy );
			if ( ! is_wp_error( $post_terms ) && ! empty( $post_terms ) ) {
				$terms[ $taxonomy ] = array_map( [ $this, 'map_term' ], $post_terms );
			}
		}

		return $terms;
	}

	/**
	 * Map a term to a structured array.
	 *
	 * @param \WP_Term $term The term object.
	 * @return array Mapped term data.
	 */
	private function map_term( \WP_Term $term ) : array {
		$term_url = get_term_link( $term );
		$term_uri = is_wp_error( $term_url ) ? '' : str_replace( home_url(), '', $term_url );

		return [
			'id'     => $term->term_id,
			'name'   => $term->name,
			'slug'   => $term->slug,
			'parent' => $term->parent ? (int) $term->parent : null,
			'uri'    => $term_uri,
			'to'     => $term_uri,
		];
	}
}
