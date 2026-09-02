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
		$route = '/' . REST_Post_Controller::REST_NAMESPACE . REST_Post_Controller::ROUTE;

		if ( $request->get_route() !== $route ) {
			return $result;
		}

		// Leave the response untouched when Yoast SEO is not active.
		if ( ! function_exists( 'YoastSEO' ) ) {
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
	 * Reads Yoast's Surfaces API, which is the same source its own REST field
	 * serialises: Yoast\WP\SEO\Routes\Yoast_Head_REST_Field resolves the value as
	 * Meta_Surface::for_post()->get_head()->json. Reading the surface directly
	 * returns an identical payload without dispatching an internal /wp/v2 request,
	 * so it also works for previews: a cookie-authenticated preview request carries
	 * no REST nonce, core therefore zeroes the current user, and a /wp/v2 subrequest
	 * would 401 on a draft even though this endpoint authorized it.
	 *
	 * Unlike Yoast's REST field this ignores Yoast's `enable_headless_rest_endpoints`
	 * option, which gates that field. fuxt exists to serve a headless frontend, so
	 * SEO data must not depend on that toggle being switched on.
	 *
	 * The caller has already verified that Yoast SEO is active.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Yoast head data, or null when unavailable.
	 */
	private function get_yoast_head_json( $post_id ) {
		$yoast = \YoastSEO();

		if ( ! isset( $yoast->helpers, $yoast->meta ) ) {
			return null;
		}

		/*
		 * The same gate Yoast's REST field applies before building a head. Without it
		 * revisions, autosaves, auto-drafts and post types excluded from indexing
		 * would get real SEO data where WP REST returns none.
		 */
		if ( ! $yoast->helpers->post->is_post_indexable( $post_id ) ) {
			return null;
		}

		$meta = $yoast->meta->for_post( $post_id );

		// for_post() returns false when the post has no indexable.
		if ( ! $meta || ! method_exists( $meta, 'get_head' ) ) {
			return null;
		}

		$head = $meta->get_head();

		return $head->json ?? null;
	}
}
