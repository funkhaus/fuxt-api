<?php
/**
 * Class REST_Project_Images_Controller
 *
 * REST endpoint for uploading and listing project images with API key auth and rate limiting.
 *
 * @package FuxtApi
 */

namespace FuxtApi;

/**
 * Class REST_Project_Images_Controller
 *
 * @package FuxtApi
 */
class REST_Project_Images_Controller {

	const REST_NAMESPACE = 'fuxt/v1';

	const ROUTE = '/project-images';

	const OPTION_API_KEY = 'fuxt_project_images_api_key';

	const RATE_LIMIT_TRANSIENT_PREFIX = 'fuxt_project_images_upload_';

	const RATE_LIMIT_MAX_UPLOADS = 20;

	const RATE_LIMIT_WINDOW_SECONDS = 3600; // 1 hour

	const ALLOWED_MIME_TYPES = array(
		'image/jpeg',
		'image/jpg',
		'image/png',
		'image/gif',
		'image/webp',
	);

	const MAX_FILE_SIZE_BYTES = 5242880; // 5 MB

	/** Meta key set on attachments uploaded via POST project-images (for submissions_only filter). */
	const META_PROJECT_SUBMISSION = '_fuxt_project_submission';

	/**
	 * Init function.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_endpoint() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_item' ),
					'permission_callback' => array( $this, 'upload_permissions_check' ),
					'args'                => array(),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Register the API key setting.
	 */
	public function register_setting() {
		register_setting(
			'fuxt_project_images',
			self::OPTION_API_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
	}

	/**
	 * Add settings page for API key.
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Project Images API', 'fuxt-api' ),
			__( 'Project Images API', 'fuxt-api' ),
			'manage_options',
			'fuxt-project-images',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error(
				'fuxt_project_images_messages',
				'fuxt_project_images_message',
				__( 'Settings saved.', 'fuxt-api' ),
				'success'
			);
		}
		settings_errors( 'fuxt_project_images_messages' );
		$api_key = $this->get_api_key();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php settings_fields( 'fuxt_project_images' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( self::OPTION_API_KEY ); ?>"><?php esc_html_e( 'API Key', 'fuxt-api' ); ?></label>
						</th>
						<td>
							<input type="text" id="<?php echo esc_attr( self::OPTION_API_KEY ); ?>"
								name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>"
								value="<?php echo esc_attr( $api_key ); ?>"
								class="regular-text" autocomplete="off" />
							<p class="description">
								<?php esc_html_e( 'Required in the X-Fuxt-Api-Key header when POSTing images. Leave empty to disable uploads.', 'fuxt-api' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'fuxt-api' ) ); ?>
			</form>
			<p>
				<strong><?php esc_html_e( 'Endpoint:', 'fuxt-api' ); ?></strong>
				<code>POST <?php echo esc_html( rest_url( self::REST_NAMESPACE . self::ROUTE ) ); ?></code>
			</p>
			<p>
				<strong><?php esc_html_e( 'List images:', 'fuxt-api' ); ?></strong>
				<code>GET <?php echo esc_html( rest_url( self::REST_NAMESPACE . self::ROUTE ) ); ?></code>
			</p>
		</div>
		<?php
	}

	/**
	 * Get the configured API key (constant overrides option).
	 *
	 * @return string
	 */
	protected function get_api_key() {
		if ( defined( 'FUXT_PROJECT_IMAGES_API_KEY' ) && FUXT_PROJECT_IMAGES_API_KEY !== '' ) {
			return FUXT_PROJECT_IMAGES_API_KEY;
		}
		return (string) get_option( self::OPTION_API_KEY, '' );
	}

	/**
	 * Check API key from request (header X-Fuxt-Api-Key or Authorization: Bearer).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	protected function validate_api_key( $request ) {
		$configured = $this->get_api_key();
		if ( $configured === '' ) {
			return false;
		}
		$header = $request->get_header( 'X-Fuxt-Api-Key' );
		if ( $header !== null && $header !== '' && hash_equals( $configured, $header ) ) {
			return true;
		}
		$auth = $request->get_header( 'Authorization' );
		if ( $auth && preg_match( '/^\s*Bearer\s+(.+)$/i', $auth, $m ) ) {
			return hash_equals( $configured, trim( $m[1] ) );
		}
		return false;
	}

	/**
	 * Rate limit by IP. Returns true if under limit, false if exceeded.
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	protected function check_rate_limit( $ip ) {
		$key    = self::RATE_LIMIT_TRANSIENT_PREFIX . md5( $ip );
		$count  = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX_UPLOADS ) {
			return false;
		}
		if ( $count === 0 ) {
			set_transient( $key, 1, self::RATE_LIMIT_WINDOW_SECONDS );
		} else {
			set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW_SECONDS );
		}
		return true;
	}

	/**
	 * Permission check for upload: valid API key + rate limit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function upload_permissions_check( $request ) {
		if ( ! $this->validate_api_key( $request ) ) {
			return new \WP_Error(
				'fuxt_rest_project_images_unauthorized',
				__( 'Invalid or missing API key. Send X-Fuxt-Api-Key header or Authorization: Bearer &lt;key&gt;.', 'fuxt-api' ),
				array( 'status' => 401 )
			);
		}
		$ip = $this->get_client_ip( $request );
		if ( ! $this->check_rate_limit( $ip ) ) {
			return new \WP_Error(
				'fuxt_rest_project_images_rate_limited',
				__( 'Too many uploads. Please try again later.', 'fuxt-api' ),
				array( 'status' => 429 )
			);
		}
		return true;
	}

	/**
	 * Permission check for listing images (public read; no key required).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true
	 */
	public function get_items_permissions_check( $request ) {
		return true;
	}

	/**
	 * Get client IP from request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	protected function get_client_ip( $request ) {
		$ip = $request->get_header( 'X-Forwarded-For' );
		if ( $ip ) {
			$ip = trim( explode( ',', $ip )[0] );
		}
		if ( empty( $ip ) && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return $ip ? $ip : '0.0.0.0';
	}

	/**
	 * Collection params for GET.
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array(
			'per_page'         => array(
				'description'       => __( 'Maximum number of items to return.', 'fuxt-api' ),
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'page'             => array(
				'description'       => __( 'Page number.', 'fuxt-api' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'submissions_only' => array(
				'description'       => __( 'If true or 1, return only images uploaded via the project-images POST endpoint.', 'fuxt-api' ),
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => function ( $value ) {
					return rest_sanitize_boolean( $value );
				},
			),
		);
	}

	/**
	 * Schema for project image.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'fuxt_project_image',
			'type'       => 'object',
			'properties' => array(
				'id'        => array(
					'description' => __( 'Attachment ID.', 'fuxt-api' ),
					'type'        => 'integer',
				),
				'url'       => array(
					'description' => __( 'Full URL to the image.', 'fuxt-api' ),
					'type'        => 'string',
					'format'      => 'uri',
				),
				'alt'       => array(
					'description' => __( 'Alt text.', 'fuxt-api' ),
					'type'        => 'string',
				),
				'width'     => array(
					'description' => __( 'Image width in pixels.', 'fuxt-api' ),
					'type'        => 'integer',
				),
				'height'    => array(
					'description' => __( 'Image height in pixels.', 'fuxt-api' ),
					'type'        => 'integer',
				),
				'mime_type' => array(
					'description' => __( 'MIME type.', 'fuxt-api' ),
					'type'        => 'string',
				),
			),
		);
	}

	/**
	 * Handle POST: upload one image (form field "image" or "file").
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function upload_item( $request ) {
		$files = $request->get_file_params();
		if ( empty( $files ) && ! empty( $_FILES ) ) {
			$files = $_FILES;
		}
		$file = null;
		$key  = null;
		foreach ( array( 'image', 'file' ) as $field ) {
			if ( ! empty( $files[ $field ] ) && ! empty( $files[ $field ]['tmp_name'] ) && is_uploaded_file( $files[ $field ]['tmp_name'] ) ) {
				$file = $files[ $field ];
				$key  = $field;
				break;
			}
		}
		if ( ! $file ) {
			return new \WP_Error(
				'fuxt_rest_project_images_no_file',
				__( 'No image file provided. Send multipart/form-data with field "image" or "file".', 'fuxt-api' ),
				array( 'status' => 400 )
			);
		}
		// Check real file size on disk -- the client-supplied $file['size'] is untrusted.
		$actual_size = filesize( $file['tmp_name'] );
		if ( false === $actual_size || $actual_size > self::MAX_FILE_SIZE_BYTES ) {
			return new \WP_Error(
				'fuxt_rest_project_images_too_large',
				__( 'File too large. Maximum 5 MB.', 'fuxt-api' ),
				array( 'status' => 400 )
			);
		}

		// Verify the file is actually a readable image of an allowed type --
		// the client-supplied MIME type/extension can be spoofed, so this
		// re-checks the real file contents instead of trusting $file['type'].
		$image_info = @getimagesize( $file['tmp_name'] );
		if ( false === $image_info || empty( $image_info['mime'] ) || ! in_array( $image_info['mime'], self::ALLOWED_MIME_TYPES, true ) ) {
			return new \WP_Error(
				'fuxt_rest_project_images_invalid_type',
				__( 'Invalid file type. Allowed: JPEG, PNG, GIF, WebP.', 'fuxt-api' ),
				array( 'status' => 400 )
			);
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$attachment_id = media_handle_upload( $key, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			return new \WP_Error(
				'fuxt_rest_project_images_upload_failed',
				$attachment_id->get_error_message(),
				array( 'status' => 500 )
			);
		}
		$attachment_id = (int) $attachment_id;
		update_post_meta( $attachment_id, self::META_PROJECT_SUBMISSION, '1' );
		return rest_ensure_response( $this->format_attachment_response( $attachment_id ) );
	}

	/**
	 * Format attachment as response item.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	protected function format_attachment_response( $attachment_id ) {
		$url = wp_get_attachment_image_url( $attachment_id, 'full' );
		$meta = wp_get_attachment_metadata( $attachment_id );
		return array(
			'id'        => $attachment_id,
			'url'       => $url ? $url : wp_get_attachment_url( $attachment_id ),
			'alt'       => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'width'     => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'    => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'mime_type' => get_post_mime_type( $attachment_id ),
		);
	}

	/**
	 * Handle GET: list recent project images (attachments).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$per_page         = $request->get_param( 'per_page' );
		$page             = $request->get_param( 'page' );
		$submissions_only = $request->get_param( 'submissions_only' );
		if ( is_string( $submissions_only ) ) {
			$submissions_only = rest_sanitize_boolean( $submissions_only );
		}
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( $submissions_only ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => self::META_PROJECT_SUBMISSION,
					'compare' => 'EXISTS',
				),
			);
		}
		$query = new \WP_Query( $query_args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->format_attachment_response( (int) $post->ID );
		}
		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );
		return $response;
	}
}
