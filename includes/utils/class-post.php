<?php
/**
 * Class Post
 *
 * @package FuxtApi
 */

namespace FuxtApi\Utils;

use FuxtApi\Utils\Utils as Utils;
use FuxtApi\Utils\Acf as AcfUtils;

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
	 * @param array        $params            Additional parameters.
	 *
	 * @return array|null
	 */
	public static function get_postdata( $post, $additional_fields = array(), $params = array() ) {
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
			'content'        => apply_filters( 'the_content', $post->post_content ),
			'blocks'         => parse_blocks( $post->post_content ),
			'excerpt'        => apply_filters( 'the_excerpt', apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ) ),
			'slug'           => $post->post_name,
			'url'            => $url,
			'uri'            => Utils::get_relative_url( $url ),
			'status'         => $post->post_status,
			'date'           => Utils::prepare_date_response( $post->post_date_gmt, $post->post_date ),
			'modified'       => Utils::prepare_date_response( $post->post_modified_gmt, $post->post_modified ),
			'type'           => $post->post_type,
			'author_id'      => (int) $post->post_author,
			'featured_media' => Utils::get_mediadata( get_post_thumbnail_id( $post->ID ) ) ?? null,
		);

		if ( ! empty( $additional_fields ) && is_array( $additional_fields ) ) {
			if ( in_array( 'acf', $additional_fields ) ) {
				$data['acf'] = AcfUtils::get_data_by_id( $post->ID );
			}

			if ( in_array( 'terms', $additional_fields ) ) {
				$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
				foreach ( $taxonomies as $taxonomy ) {
					$terms                      = get_the_terms( $post->ID, $taxonomy );
					$data['terms'][ $taxonomy ] = $terms ? array_map( array( Utils::class, 'get_termdata' ), $terms ) : null;
				}
			}

			// Inherit additional fields for siblings, parent, children, next, prev post.
			$inherit_fields = array_intersect(
				$additional_fields,
				array(
					'acf',
					'terms',
				)
			);

			if ( in_array( 'siblings', $additional_fields ) ) {
				$data['siblings'] = array();
				$sibling_posts    = self::get_sibling_posts( $post );

				foreach ( $sibling_posts as $sibling_post ) {
					$data['siblings'][] = self::get_postdata( $sibling_post, $inherit_fields );
				}
			}

			if ( in_array( 'children', $additional_fields ) ) {
				$query_params = array(
					'post_parent' => $post->ID,
					'post_type'   => $post->post_type,
				);

				// Default order is menu_order for hierarchical post types such as page.
				if ( is_post_type_hierarchical( $post->post_type ) ) {
					$query_params['orderby'] = 'menu_order';
					$query_params['order']   = 'ASC';
				}

				if ( isset( $params['per_page'] ) ) {
					$query_params['posts_per_page'] = $params['per_page'];
				}

				if ( isset( $params['page'] ) ) {
					$query_params['paged'] = $params['page'];
				}

				$posts_query = new \WP_Query();
				$children    = $posts_query->query( $query_params );

				$children_data = array();
				if ( $children ) {
					$depth = isset( $params['depth'] ) ? (int) $params['depth'] : 1;

					// inherit some of the additional fields.
					$child_additional_fields = $inherit_fields;

					if ( $depth > 1 ) {
						$child_additional_fields[] = 'children';
					}

					$depth -= 1;

					foreach ( $children as $child ) {
						$children_data[] = self::get_postdata(
							$child,
							$child_additional_fields,
							array(
								'per_page' => 1,
								'depth'    => $depth,
							)
						);
					}
				}

				$data['children'] = array(
					'total'       => $posts_query->found_posts,
					'total_pages' => (int) ceil( $posts_query->found_posts / (int) $posts_query->query_vars['posts_per_page'] ),
					'list'        => $children_data,
				);
			}

			if ( in_array( 'parent', $additional_fields ) ) {
				$parent         = get_post_parent( $post );
				$data['parent'] = $parent ? self::get_postdata( $parent, $inherit_fields ) : null;
			}

			if ( in_array( 'ancestors', $additional_fields ) ) {
				$data['ancestors'] = array();
				$ancestor_posts    = get_post_ancestors( $post );

				foreach ( $ancestor_posts as $ancestor_post ) {
					$data['ancestors'][] = self::get_postdata( $ancestor_post, $inherit_fields );
				}
			}

			if ( in_array( 'next', $additional_fields ) ) {
				$next_post    = self::get_next_prev_post( $post, true, true );
				$data['next'] = $next_post ? self::get_postdata( $next_post, $inherit_fields ) : null;
			}

			if ( in_array( 'prev', $additional_fields ) ) {
				$prev_post    = self::get_next_prev_post( $post, false, true );
				$data['prev'] = $prev_post ? self::get_postdata( $prev_post, $inherit_fields ) : null;
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

		if ( empty( $siblings ) ) {
			return null;
		}

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

	/**
	 * Get post object by uri.
	 *
	 * @param string $uri Post URL.
	 *
	 * @return \WP_Post|null
	 */
	public static function get_post_by_uri( $uri ) {
		$uri = Utils::get_relative_url( $uri );

		// homepage check.
		if ( empty( trim( $uri, '/' ) ) ) {
			$front_page_id = get_option( 'page_on_front' );
			return get_post( $front_page_id );
		}

		$post = get_page_by_path( $uri, OBJECT, Utils::get_post_types() );

		return $post;
	}

	/**
	 * Get posts.
	 *
	 * @param \WP_REST_Request $params Parameters
	 *
	 * @return array
	 */
	public static function get_posts( $params, $additional_fields ) {
		$query_params = array();

		if ( isset( $params['post_parent_uri'] ) ) {
			$parent_post = self::get_post_by_uri( $params['post_parent_uri'] );

			if ( empty( $parent_post ) ) {
				return null;
			}

			$query_params['post_parent'] = $parent_post->ID;
			$query_params['post_type']   = $parent_post->post_type;

			// Default order is menu_order for hierarchical post types such as page.
			if ( is_post_type_hierarchical( $parent_post->post_type ) ) {
				$query_params['orderby'] = 'menu_order';
				$query_params['order']   = 'ASC';
			}
		}

		if ( isset( $params['term_slug'] ) ) {
			$terms = get_terms(
				array(
					'taxonomy' => get_taxonomies(),
					'slug'     => $params['term_slug'],
				)
			);

			if ( ! empty( $terms ) ) {
				$query_params['tax_query'] = array(
					array(
						'taxonomy' => $terms[0]->taxonomy,
						'field'    => 'slug',
						'terms'    => $terms[0]->slug,
					),
				);

				$taxonomy                  = get_taxonomy( $terms[0]->taxonomy );
				$query_params['post_type'] = $taxonomy->object_type;

				// Default order is menu_order for hierarchical post types such as page.
				if ( is_post_type_hierarchical( $parent_post->post_type ) ) {
					$query_params['orderby'] = 'menu_order';
					$query_params['order']   = 'ASC';
				}
			} else {
				return null;
			}
		}

		if ( ! isset( $query_params['post_type'] ) ) {
			if ( isset( $params['post_type'] ) ) {
				$query_params['post_type'] = $params['post_type'];
			} else {
				$query_params['post_type'] = 'post';
			}
		}

		if ( isset( $params['per_page'] ) ) {
			$query_params['posts_per_page'] = $params['per_page'];
		}

		if ( isset( $params['page'] ) ) {
			$query_params['paged'] = $params['page'];
		}

		if ( isset( $params['orderby'] ) ) {
			$query_params['orderby'] = $params['orderby'];
		}

		if ( isset( $params['order'] ) ) {
			$query_params['order'] = $params['order'];
		}

		$posts_query = new \WP_Query();
		$posts       = $posts_query->query( $query_params );

		$post_list = array();

		foreach ( $posts as $post ) {
			$post_data = self::get_postdata( $post, $additional_fields );
			if ( isset( $post_data['children'] ) ) {
				$children              = $post_data['children'];
				$post_data['children'] = $children['list'];
			}

			$post_list[] = $post_data;
		}

		return array(
			'total'       => $posts_query->found_posts,
			'total_pages' => (int) ceil( $posts_query->found_posts / (int) $posts_query->query_vars['posts_per_page'] ),
			'list'        => $post_list,
		);
	}
}
