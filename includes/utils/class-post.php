<?php
/**
 * Class Post
 *
 * @package FuxtApi
 */

namespace FuxtApi\Utils;

/**
 * Class Post
 *
 * @package FuxtApi
 */
class Post {

	/**
	 * Get post data for post object.
	 *
	 * @param \WP_Post|int $post              Post object.
	 * @param array        $additional_fields Additional post fields to return.
	 *
	 * @return array|null
	 */
	public static function get_postdata( $post, $additional_fields = array() ) {
		// In case int value is provided.
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post );

			if ( ! $post instanceof \WP_Post ) {
				return null;
			}
		}

		$url = get_permalink( $post );

		$data = array(
			'id'             => $post->ID,
			'guid'           => $post->guid,
			'title'          => get_the_title( $post ),
			'post_type'      => $post->post_type,
			'content'        => apply_filters( 'the_content', $post->post_content ),
			'excerpt'        => apply_filters( 'the_excerpt', apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ) ),
			'slug'           => $post->post_name,
			'url'            => $url,
			'uri'            => Utils::get_relative_url( $url ),
			'status'         => $post->post_status,
			'date'           => Utils::prepare_date_response( $post->post_date_gmt, $post->post_date ),
			'modified'       => Utils::prepare_date_response( $post->post_modified_gmt, $post->post_modified ),
			'type'           => $post->post_type,
			'author_id'      => (int) $post->post_author,
			'featured_image' => Utils::get_mediadata( get_post_thumbnail_id( $post->ID ) ) ?? array(),
		);

		if ( ! empty( $additional_fields ) && is_array( $additional_fields ) ) {
			if ( in_array( 'acf', $additional_fields ) ) {
				$data['acf'] = Utils::get_acf_data( $post->ID );
			}

			if ( in_array( 'siblings', $additional_fields ) ) {
				$data['siblings'] = array_map( array( self::class, 'get_postdata' ), self::get_sibling_posts( $post ) );
			}

			if ( in_array( 'children', $additional_fields ) ) {
				$children = get_children(
					array(
						'post_parent' => $post->ID,
						'post_type'   => $post->post_type,
					)
				);

				$data['children'] = array_map( array( self::class, 'get_postdata' ), $children );
			}

			if ( in_array( 'parent', $additional_fields ) ) {
				$parent         = get_post_parent( $post );
				$data['parent'] = $parent ? self::get_postdata( $parent ) : null;
			}

			if ( in_array( 'ancestors', $additional_fields ) ) {
				$data['ancestors'] = array_map( array( self::class, 'get_postdata' ), get_post_ancestors( $post ) );
			}

			if ( in_array( 'next', $additional_fields ) ) {
				$next_post    = self::get_next_prev_post( $post, true, true );
				$data['next'] = $next_post ? self::get_postdata( $next_post ) : null;
			}

			if ( in_array( 'prev', $additional_fields ) ) {
				$prev_post    = self::get_next_prev_post( $post, false, true );
				$data['prev'] = $prev_post ? self::get_postdata( $prev_post ) : null;
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
	public static function get_next_prev_post( $post, $is_next = true, $loop = true ) {
		// Get all siblings
		$siblings = self::get_sibling_posts( $post );

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
	public static function get_sibling_posts( $post ) {
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

}
