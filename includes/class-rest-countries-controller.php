<?php
/**
 * Class REST_Countries_Controller
 *
 * @package FuxtApi
 */

namespace FuxtApi;

/**
 * Class REST_Countries_Controller
 *
 * @package FuxtApi
 */
class REST_Countries_Controller {
	const REST_NAMESPACE = 'fuxt/v1';
	const ROUTE         = '/countries';

	/** Edit this list to change which ACF keys are returned */
	private array $acf_keys = [ 'country_code', 'areas_of_expertise_links' ];

	/** Fields that contain relationship IDs that should be expanded to full objects */
	private array $relationship_fields = [ 'areas_of_expertise_links' ]; // Relationship to 'area-of-expertise' post type

	/**
	 * Minimal Countries REST endpoint
	 * GET /wp-json/fuxt/v1/countries?per_page=100&page=1
	 *
	 * Returns items shaped like:
	 * {
	 *   "page": 1,
	 *   "per_page": 100,
	 *   "has_more": true,
	 *   "items": [
	 *     { 
	 *       "id": 123, 
	 *       "slug": "kenya", 
	 *       "acf": { 
	 *         "country_code": "KE", 
	 *         "areas_of_expertise_links": [
	 *           { "id": 150, "title": "Agriculture", "slug": "agriculture", "acf": {} },
	 *           { "id": 1587, "title": "Technology", "slug": "technology", "acf": {} }
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
	 * Register countries endpoint.
	 */
	public function register_endpoint() : void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => '__return_true', // public; tighten if needed
					'args'                => $this->get_collection_params(),
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
				'default' => 100,
				'minimum' => 1,
				'maximum' => 200, // cap to keep memory down
			],
		];
	}

	/**
	 * Retrieves a collection of countries.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		$page     = max( 1, (int) ( $request['page'] ?? 1 ) );
		$per_page = min( 50, max( 1, (int) ( $request['per_page'] ?? 25 ) ) );

		$q = new \WP_Query( [
			'post_type'              => 'country',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'no_found_rows'          => true,   // skip total counting (saves memory)
			'update_post_meta_cache' => false,  // don't prefetch all meta
			'update_post_term_cache' => false,  // don't prefetch all terms
			'ignore_sticky_posts'    => true,
			'fields'                 => 'ids',  // only IDs; we'll map each
		] );

		$items = array_map( [ $this, 'map_country_min' ], $q->posts );

		$payload = [
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => count( $items ) === $per_page, // simple forward-only pagination flag
			'items'    => $items,
		];

		return rest_ensure_response( $payload );
	}

	/** Minimal shape: id, slug, acf subset */
	private function map_country_min( $post_id ) : array {
		return [
			'id'   => (int) $post_id,
			'slug' => get_post_field( 'post_name', $post_id ),
			'acf'  => $this->get_acf_subset( $post_id ),
		];
	}

	private function get_acf_subset( int $post_id ) : array {
		$out = [];
		if ( ! function_exists( 'get_field' ) ) {
			return $out;
		}
		foreach ( $this->acf_keys as $key ) {
			$value = get_field( $key, $post_id, false );
			
			// Expand relationship fields to full post objects
			if ( in_array( $key, $this->relationship_fields, true ) && is_array( $value ) ) {
				$out[ $key ] = $this->expand_relationship_field( $value );
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Expand relationship field IDs to full post objects.
	 *
	 * @param array $ids Array of post IDs.
	 * @return array Array of post objects with id, title, slug, and acf data.
	 */
	private function expand_relationship_field( array $ids ) : array {
		if ( empty( $ids ) ) {
			return [];
		}

		$posts = get_posts( [
			'post__in'       => array_map( 'intval', $ids ),
			'post_type'      => 'area-of-expertise', // Specific to the area-of-expertise custom post type
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'post__in', // Maintain the order from the relationship field
		] );

		return array_map( [ $this, 'map_relationship_post' ], $posts );
	}

	/**
	 * Map a relationship post to a minimal object.
	 *
	 * @param \WP_Post $post The post object.
	 * @return array Mapped post data.
	 */
	private function map_relationship_post( \WP_Post $post ) : array {
		return [
			'id'    => $post->ID,
			'title' => $post->post_title,
			'slug'  => $post->post_name,
			'acf'   => $this->get_relationship_post_acf( $post->ID ),
		];
	}

	/**
	 * Get ACF data for a relationship post.
	 * You can customize this to include specific ACF fields for related posts.
	 *
	 * @param int $post_id The post ID.
	 * @return array ACF data.
	 */
	private function get_relationship_post_acf( int $post_id ) : array {
		// Return empty array by default, but you can add specific ACF fields here
		// if you need them for the related posts
		return [];
	}
}
