<?php
/**
 * Class Post
 *
 * @package FuxtApi
 */

namespace FuxtApi\Utils;

use FuxtApi\Utils\Block as BlockUtils;
use FuxtApi\Utils\Utils;
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
		$to  = Utils::get_relative_url( $url );

		$data = array(
			'id'             => $post->ID,
			'guid'           => $post->guid,
			'title'          => get_the_title( $post ),
			'content'        => apply_filters( 'the_content', $post->post_content ),
			'excerpt'        => apply_filters( 'the_excerpt', apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ) ),
			'slug'           => $post->post_name,
			'url'            => $url,
			'uri'            => $to,
			'to'             => $to,
			'status'         => $post->post_status,
			'date'           => Utils::prepare_date_response( $post->post_date_gmt, $post->post_date ),
			'modified'       => Utils::prepare_date_response( $post->post_modified_gmt, $post->post_modified ),
			'type'           => $post->post_type,
			'author_id'      => (int) $post->post_author,
			'featured_media' => Utils::get_imagedata( get_post_thumbnail_id( $post->ID ) ) ?? null,
		);

		if ( ! empty( $additional_fields ) && is_array( $additional_fields ) ) {
			if ( in_array( 'blocks', $additional_fields ) ) {
				$data['blocks'] = BlockUtils::filter_blocks( $post );
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

			if ( in_array( 'acf', $additional_fields ) ) {
				if ( $params['acf_depth'] > 0 ) {
					$acf_utils   = new AcfUtils( $inherit_fields, array( 'acf_depth' => $params['acf_depth'] - 1 ) );
					$data['acf'] = $acf_utils->get_data_by_id( $post->ID );
				}
			}

			if ( in_array( 'siblings', $additional_fields ) ) {
				$data['siblings'] = array();
				$sibling_posts    = self::get_sibling_posts( $post );

				// filter current post.
				$sibling_posts = array_values(
					array_filter(
						$sibling_posts,
						function ( $sibling ) use ( $post ) {
							return $sibling->ID !== $post->ID;
						}
					)
				);

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
								'per_page' => $params['per_page'],
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
	private static function get_next_prev_post( $post, $is_next = true, $loop = true ) {
		// Get all siblings
		$siblings = self::get_sibling_posts( $post );

		if ( empty( $siblings ) ) {
			return null;
		}

		$sibling_ids = array_map(
			function ( $sibling ) {
				return $sibling->ID;
			},
			$siblings
		);

		// Find where current posts exists in Siblings
		$index = array_search( $post->ID, $sibling_ids );

		if ( $is_next ) {
			if ( $index === count( $siblings ) - 1 ) {
				return $loop ? $siblings[0] : null;
			}

			return $siblings[ $index + 1 ];
		} else {
			if ( $index === 0 ) {
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
	private static function get_sibling_posts( $post ) {
		$post_type = get_post_type( $post );

		$orderby = is_post_type_hierarchical( $post_type ) ? 'menu_order' : 'date';
		// Get all siblings pages
		// Yes this isn't effienct to query all pages,
		// but actually it works well for thousands of pages in practice.
		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => -1,
			'orderby'        => $orderby,
			'order'          => 'ASC',
			'post_parent'    => $post->post_parent,
		);

		// Get all siblings
		return get_posts( $args );
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

		$post_id = self::url_to_postid( $uri );

		if ( ! empty( $post_id ) ) {
			return get_post( $post_id );
		}

		return null;
	}

	/**
	 * Check if a user can read post.
	 *
	 * @param int $user_id User ID.
	 * @param int $post_id Post ID.
	 *
	 * @return boolean
	 */
	public static function can_user_read_post( $user_id, $post_id ) {
		// Get the post object
		$post = get_post( $post_id );

		if ( empty( $post ) ) {
			return false;
		}

		// Check if the post exists and is a draft
		if ( $post->post_status === 'draft' ) {
			// Check if the user is the author of the post or have permission.
			return $post->post_author === $user_id || user_can( $user_id, 'read_private_posts' );
		}

		// Post is not a draft or doesn't exist
		return $post->post_status === 'publish';
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

		if ( ! empty( $params['term_slug'] ) ) {
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
				if ( ! in_array( $params['post_type'], Utils::get_post_types() ) ) {
					return null;
				}
				$query_params['post_type'] = $params['post_type'];
			} else {
				$query_params['post_type'] = 'post';
			}
		}

		if ( isset( $params['per_page'] ) ) {
			$query_params['posts_per_page'] = (int) $params['per_page'];
		}

		if ( isset( $params['page'] ) ) {
			$query_params['paged'] = (int) $params['page'];
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

	/**
	 * Examines a URL and try to determine the post ID it represents.
	 *
	 * Checks are supposedly from the hosted site blog.
	 *
	 * @since 1.0.0
	 *
	 * @global WP_Rewrite $wp_rewrite WordPress rewrite component.
	 * @global WP         $wp         Current WordPress environment instance.
	 *
	 * @param string $url Permalink to check.
	 * @return int Post ID, or 0 on failure.
	 */
	private static function url_to_postid( $url ) {
		global $wp_rewrite;

		// First, check to see if there is a 'p=N' or 'page_id=N' to match against.
		if ( preg_match( '#[?&](p|page_id|attachment_id)=(\d+)#', $url, $values ) ) {
			$id = absint( $values[2] );
			if ( $id ) {
				return $id;
			}
		}

		// Get rid of the #anchor.
		$url_split = explode( '#', $url );
		$url       = $url_split[0];

		// Get rid of URL ?query=string.
		$url_split = explode( '?', $url );
		$url       = $url_split[0];

		// Trim leading and lagging slashes.
		$url = trim( $url, '/' );

		$post_type_query_vars = array();

		foreach ( get_post_types( array(), 'objects' ) as $post_type => $t ) {
			if ( ! empty( $t->query_var ) ) {
				$post_type_query_vars[ $t->query_var ] = $post_type;
			}
		}

		// Check to see if we are using rewrite rules.
		$rewrite = $wp_rewrite->wp_rewrite_rules();

		// Not using rewrite rules, and 'p=N' and 'page_id=N' methods failed, so we're out of options.
		if ( empty( $rewrite ) ) {
			return 0;
		}

		// Look for matches.
		$request_match = $url;
		foreach ( (array) $rewrite as $match => $query ) {

			if ( preg_match( "#^$match#", $request_match, $matches ) ) {

				if ( $wp_rewrite->use_verbose_page_rules && preg_match( '/pagename=\$matches\[([0-9]+)\]/', $query, $varmatch ) ) {
					// This is a verbose page match, let's check to be sure about it.
					$page = get_page_by_path( $matches[ $varmatch[1] ] );
					if ( ! $page ) {
						continue;
					}

					$post_status_obj = get_post_status_object( $page->post_status );
					if ( $page->post_status !== 'draft' && ! $post_status_obj->public && ! $post_status_obj->protected
						&& ! $post_status_obj->private && $post_status_obj->exclude_from_search ) {
						continue;
					}
				}

				/*
				* Got a match.
				* Trim the query of everything up to the '?'.
				*/
				$query = preg_replace( '!^.+\?!', '', $query );

				// Substitute the substring matches into the query.
				$query = addslashes( \WP_MatchesMapRegex::apply( $query, $matches ) );

				// Filter out non-public query vars.
				global $wp;
				parse_str( $query, $query_vars );
				$query = array();
				foreach ( (array) $query_vars as $key => $value ) {
					if ( in_array( (string) $key, $wp->public_query_vars, true ) ) {
						$query[ $key ] = $value;
						if ( isset( $post_type_query_vars[ $key ] ) ) {
							$query['post_type'] = $post_type_query_vars[ $key ];
							$query['name']      = $value;
						}
					}
				}

				// Resolve conflicts between posts with numeric slugs and date archive queries.
				$query = wp_resolve_numeric_slug_conflicts( $query );

				$query['post_status'] = array(
					'publish',
					'draft',
				);

				// Do the query.
				$query = new \WP_Query( $query );
				if ( ! empty( $query->posts ) && $query->is_singular ) {
					return $query->post->ID;
				} else {
					return 0;
				}
			}
		}
		return 0;
	}
}
