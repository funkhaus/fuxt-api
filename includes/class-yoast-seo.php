<?php
/**
 * Class Yoast_Seo
 *
 * @package FuxtApi
 */

namespace FuxtApi;

/**
 * Class Yoast_Seo
 *
 * Adds Yoast's `yoast_head_json` to the fuxt `/post` response so the frontend
 * can fetch SEO data in a single request. Fails gracefully when the Yoast SEO
 * plugin is not active: no field is added and the response is left untouched.
 *
 * @package FuxtApi
 */
class Yoast_Seo {

	/**
	 * Init function.
	 */
	public function init() {
		add_filter( 'rest_post_dispatch', array( $this, 'add_yoast_head' ), 10, 3 );
	}

	/**
	 * Add yoast_head_json to the fuxt /post response.
	 *
	 * @param mixed $result WP_REST_Response or WP_Error.
	 * @param WP_REST_Server $server REST server instance.
	 * @param WP_REST_Request $request Current request.
	 * @return mixed
	 */
	public function add_yoast_head( $result, $server, $request ) {
		if ( $request->get_route() !== '/fuxt/v1/post' ) {
			return $result;
		}

		if ( is_wp_error( $result ) || ! ( $result instanceof \WP_REST_Response ) ) {
			return $result;
		}

		$data = $result->get_data();

		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return $result;
		}

		$data['yoast_head_json'] = $this->get_yoast_head_json( (int) $data['id'] );

		$result->set_data( $data );

		return $result;
	}

	/**
	 * Fetch Yoast's yoast_head_json for a post.
	 *
	 * Uses Yoast's own REST field via an in-process request, so the shape always
	 * matches what WP REST returns and no extra HTTP round-trip is made.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Yoast head data, or null when unavailable.
	 */
	private function get_yoast_head_json( $post_id ) {
		if ( ! function_exists( 'YoastSEO' ) ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return null;
		}

		$post_type_object = get_post_type_object( $post->post_type );

		if ( empty( $post_type_object ) ) {
			return null;
		}

		$rest_base = ! empty( $post_type_object->rest_base )
			? $post_type_object->rest_base
			: $post_type_object->name;

		$request = new \WP_REST_Request(
			'GET',
			sprintf( '/wp/v2/%s/%d', $rest_base, $post_id )
		);
		$request->set_query_params( array( '_fields' => 'yoast_head_json' ) );

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return null;
		}

		$data = $response->get_data();

		return ( is_array( $data ) && isset( $data['yoast_head_json'] ) )
			? $data['yoast_head_json']
			: null;
	}
}
