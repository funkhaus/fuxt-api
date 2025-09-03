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
	private array $acf_keys = [ 'country_code', 'expertise_links' ];

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
	 *     { "id": 123, "slug": "kenya", "acf": { "country_code": "KE", "expertise_links": [...] } }
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
			// Third arg false = raw (unformatted) to keep payload light; change to true if you need formatting
			$out[ $key ] = get_field( $key, $post_id, false );
		}
		return $out;
	}
}
