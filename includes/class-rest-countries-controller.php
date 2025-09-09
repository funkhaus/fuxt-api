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
	 * Countries REST endpoint
	 * GET /wp-json/fuxt/v1/countries?per_page=100&page=1
	 *
	 * Returns items shaped like:
	 * {
	 *   "page": 1,
	 *   "per_page": 100,
	 *   "has_more": true,
	 *   "items": [
	 *     { 
	 *       "id": 3191,
	 *       "guid": "https://jhpiego.netlify.app/?post_type=country&#038;p=3191",
	 *       "title": "United States of America",
	 *       "content": "",
	 *       "excerpt": "",
	 *       "excerpt_raw": "",
	 *       "slug": "united-states-of-america",
	 *       "url": "https://jhpiego.netlify.app/where-we-work/united-states-of-america/",
	 *       "uri": "/where-we-work/united-states-of-america/",
	 *       "to": "/where-we-work/united-states-of-america/",
	 *       "status": "publish",
	 *       "date": "2025-08-05T19:56:25",
	 *       "modified": "2025-08-27T18:08:33",
	 *       "type": "country",
	 *       "author_id": 4,
	 *       "featured_media": {
	 *         "id": 123,
	 *         "src": "https://example.com/image.jpg",
	 *         "width": 1920,
	 *         "height": 1080,
	 *         "alt": "Image description",
	 *         "caption": "Image caption",
	 *         "title": "Image title",
	 *         "description": "Image description",
	 *         "srcset": "https://example.com/image-300x200.jpg 300w, https://example.com/image-600x400.jpg 600w",
	 *         "sizes": "(max-width: 300px) 100vw, (max-width: 600px) 50vw, 25vw",
	 *         "meta": { "width": 1920, "height": 1080, "file": "2023/01/image.jpg" },
	 *         "acf": { "custom_field": "value" }
	 *       },
	 *       "terms": {
	 *         "category": [
	 *           {
	 *             "id": 22,
	 *             "name": "Americas",
	 *             "slug": "the-americas",
	 *             "parent": null,
	 *             "uri": "/our-stories/c/the-americas/",
	 *             "to": "/our-stories/c/the-americas/"
	 *           }
	 *         ]
	 *       },
	 *       "acf": { 
	 *         "country_code": "US", 
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

	/** Full shape: id, guid, title, content, excerpt, slug, url, uri, to, status, date, modified, type, author_id, featured_media, terms, acf subset */
	private function map_country_min( $post_id ) : array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		// Get the post URL and URI
		$url = get_permalink( $post_id );
		$uri = str_replace( home_url(), '', $url );

		return [
			'id'             => (int) $post_id,
			'guid'           => $post->guid,
			'title'          => $post->post_title,
			'content'        => $post->post_content,
			'excerpt'        => get_the_excerpt( $post_id ),
			'excerpt_raw'    => $post->post_excerpt,
			'slug'           => $post->post_name,
			'url'            => $url,
			'uri'            => $uri,
			'to'             => $uri,
			'status'         => $post->post_status,
			'date'           => $post->post_date,
			'modified'       => $post->post_modified,
			'type'           => $post->post_type,
			'author_id'      => (int) $post->post_author,
			'featured_media' => $this->get_featured_media( $post_id ),
			'terms'          => $this->get_post_terms( $post_id ),
			'acf'            => $this->get_acf_subset( $post_id ),
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

	/**
	 * Get featured media for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return array|null Featured media data or null if no featured media.
	 */
	private function get_featured_media( int $post_id ) : ?array {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return null;
		}

		$attachment = get_post( $thumbnail_id );
		if ( ! $attachment ) {
			return null;
		}

		// Get image metadata
		$image_meta = wp_get_attachment_metadata( $thumbnail_id );
		$full_size_url = wp_get_attachment_url( $thumbnail_id );
		
		// Get responsive image data
		$srcset = wp_get_attachment_image_srcset( $thumbnail_id );
		$sizes = wp_get_attachment_image_sizes( $thumbnail_id );

		return [
			'id'          => $thumbnail_id,
			'src'         => $full_size_url,
			'width'       => isset( $image_meta['width'] ) ? (int) $image_meta['width'] : null,
			'height'      => isset( $image_meta['height'] ) ? (int) $image_meta['height'] : null,
			'alt'         => get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ),
			'caption'     => $attachment->post_excerpt,
			'title'       => $attachment->post_title,
			'description' => $attachment->post_content,
			'srcset'      => $srcset ?: null,
			'sizes'       => $sizes ?: null,
			'meta'        => $image_meta,
			'acf'         => $this->get_featured_media_acf( $thumbnail_id ),
		];
	}

	/**
	 * Get ACF data for featured media.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return array|null ACF data or null if no ACF data.
	 */
	private function get_featured_media_acf( int $attachment_id ) : ?array {
		if ( ! function_exists( 'get_field' ) ) {
			return null;
		}

		// Get all ACF fields for the attachment
		$acf_fields = get_fields( $attachment_id );
		return $acf_fields ?: null;
	}

	/**
	 * Get all terms (categories, tags, etc.) for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return array Terms organized by taxonomy.
	 */
	private function get_post_terms( int $post_id ) : array {
		$terms = [];
		
		// Get all taxonomies for the post type
		$taxonomies = get_object_taxonomies( get_post_type( $post_id ), 'objects' );
		
		foreach ( $taxonomies as $taxonomy ) {
			$post_terms = get_the_terms( $post_id, $taxonomy->name );
			if ( ! is_wp_error( $post_terms ) && ! empty( $post_terms ) ) {
				$terms[ $taxonomy->name ] = array_map( [ $this, 'map_term' ], $post_terms );
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
