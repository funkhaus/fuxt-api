<?php
/**
 * Class REST_Email_Controller
 *
 * @package FuxtApi
 */

namespace FuxtApi;

/**
 * Class REST_Email_Controller
 *
 * Public POST /fuxt/v1/email endpoint for frontend contact forms.
 *
 * Because the endpoint is unauthenticated, the request may only choose the
 * recipient (from an allowlist), subject, message, and a reply-to address.
 * Sender, CC/BCC, headers, and attachments are never taken from the request —
 * otherwise anyone could use the site as a mail relay or attach server files.
 *
 * Opt-in per site via filters:
 * - fuxt_api_email_allowed_recipients  (array)  Required. Empty = endpoint disabled.
 * - fuxt_api_email_recaptcha_secret    (string) Optional. When set, a valid
 *   reCAPTCHA v2 token is required (`recaptchaToken`). Also read from the
 *   FUXT_API_RECAPTCHA_SECRET constant.
 * - fuxt_api_email_rate_limit          (array)  Optional. Per-IP limit,
 *   default 5 requests per 10 minutes.
 *
 * @package FuxtApi
 */
class REST_Email_Controller {

	const REST_NAMESPACE = 'fuxt/v1';

	const ROUTE = '/email';

	const MAX_MESSAGE_LENGTH = 10000;

	/**
	 * Init function.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
	}

	/**
	 * Register email endpoint.
	 */
	public function register_endpoint() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_email' ),
				'permission_callback' => array( $this, 'send_email_permissions_check' ),
				'args'                => $this->get_collection_params(),
				'schema'              => array( $this, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Checks if a given request has access to send emails.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|\WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function send_email_permissions_check( $request ) {
		// Allow public access by default, but can be restricted via filter.
		$allowed = apply_filters( 'fuxt_api_email_permissions', true, $request );

		if ( ! $allowed ) {
			return new \WP_Error(
				'rest_email_forbidden',
				__( 'Sorry, you are not allowed to send emails.', 'fuxt-api' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Retrieves the query params for the email request.
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		return array(
			'to'              => array(
				'description' => __( 'Recipient. Must be on the allowed recipients list.', 'fuxt-api' ),
				'type'        => 'string',
				'required'    => true,
				'format'      => 'email',
			),
			'subject'         => array(
				'description' => __( 'Email subject.', 'fuxt-api' ),
				'type'        => 'string',
				'required'    => true,
			),
			'message'         => array(
				'description' => __( 'Plain-text email body.', 'fuxt-api' ),
				'type'        => 'string',
				'required'    => true,
			),
			'reply_to'        => array(
				'description' => __( 'Reply-To address, typically the submitter\'s email.', 'fuxt-api' ),
				'type'        => 'string',
				'format'      => 'email',
			),
			'trap'            => array(
				'description' => __( 'Spam trap. Must equal clientRequestId.', 'fuxt-api' ),
				'type'        => 'string',
				'required'    => true,
			),
			'clientRequestId' => array(
				'description' => __( 'Client-generated request ID used with the spam trap.', 'fuxt-api' ),
				'type'        => 'string',
				'required'    => true,
			),
			'recaptchaToken'  => array(
				'description' => __( 'reCAPTCHA v2 response token. Required when a reCAPTCHA secret is configured.', 'fuxt-api' ),
				'type'        => 'string',
			),
		);
	}

	/**
	 * Retrieves the email response schema.
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'email',
			'type'       => 'object',
			'properties' => array(
				'success' => array(
					'description' => __( 'Whether the email was sent successfully.', 'fuxt-api' ),
					'type'        => 'boolean',
				),
				'message' => array(
					'description' => __( 'Response message.', 'fuxt-api' ),
					'type'        => 'string',
				),
				'data'    => array(
					'description' => __( 'Additional response data.', 'fuxt-api' ),
					'type'        => 'object',
				),
			),
		);
	}

	/**
	 * Send email.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|\WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function send_email( $request ) {
		$to                = sanitize_email( $request['to'] );
		$subject           = sanitize_text_field( $request['subject'] );
		$message           = sanitize_textarea_field( (string) $request['message'] );
		$reply_to          = sanitize_email( (string) $request['reply_to'] );
		$trap              = sanitize_text_field( $request['trap'] );
		$client_request_id = sanitize_text_field( $request['clientRequestId'] );

		if ( empty( $to ) || empty( $subject ) || empty( $message ) ) {
			return new \WP_Error(
				'rest_email_missing_fields',
				__( 'Missing required fields: to, subject, and message are required.', 'fuxt-api' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
			return new \WP_Error(
				'rest_email_message_too_long',
				__( 'Message is too long.', 'fuxt-api' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $trap ) || $trap !== $client_request_id ) {
			return new \WP_Error(
				'rest_email_spam_trap_failed',
				__( 'Spam trap validation failed. Email not sent.', 'fuxt-api' ),
				array( 'status' => 400 )
			);
		}

		// Recipient allowlist. Empty by default — an empty allowlist disables the
		// endpoint, so the base plugin ships safe (no config = no sending).
		$allowed_recipients = array_filter(
			array_map(
				'sanitize_email',
				(array) apply_filters( 'fuxt_api_email_allowed_recipients', array() )
			)
		);

		if ( empty( $allowed_recipients ) ) {
			return new \WP_Error(
				'rest_email_not_configured',
				__( 'Email sending is not configured.', 'fuxt-api' ),
				array( 'status' => 503 )
			);
		}

		if ( ! in_array( $to, $allowed_recipients, true ) ) {
			return new \WP_Error(
				'rest_email_recipient_not_allowed',
				__( 'This recipient is not permitted.', 'fuxt-api' ),
				array( 'status' => 403 )
			);
		}

		$rate_limited = $this->check_rate_limit();
		if ( is_wp_error( $rate_limited ) ) {
			return $rate_limited;
		}

		$recaptcha = $this->verify_recaptcha( (string) $request['recaptchaToken'] );
		if ( is_wp_error( $recaptcha ) ) {
			return $recaptcha;
		}

		// Sender is left to WordPress (wp_mail_from / wp_mail_from_name) so it
		// always comes from the site's own domain.
		$email_headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( ! empty( $reply_to ) ) {
			$email_headers[] = 'Reply-To: ' . $reply_to;
		}

		// Allow filtering of email data before sending.
		$email_data = apply_filters(
			'fuxt_api_email_data',
			array(
				'to'      => $to,
				'subject' => $subject,
				'message' => $message,
				'headers' => $email_headers,
			),
			$request
		);

		$sent = wp_mail(
			$email_data['to'],
			$email_data['subject'],
			$email_data['message'],
			$email_data['headers']
		);

		if ( $sent ) {
			$response_data = array(
				'success' => true,
				'message' => __( 'Email sent successfully.', 'fuxt-api' ),
				'data'    => array(),
			);
		} else {
			$response_data = array(
				'success' => false,
				'message' => __( 'Failed to send email.', 'fuxt-api' ),
				'data'    => array(),
			);
		}

		// Allow filtering of response.
		$response_data = apply_filters( 'fuxt_api_email_response', $response_data, $request, $sent );

		return rest_ensure_response( $response_data );
	}

	/**
	 * Per-IP rate limit, stored in a transient.
	 *
	 * @return true|\WP_Error
	 */
	private function check_rate_limit() {
		$limit = wp_parse_args(
			(array) apply_filters( 'fuxt_api_email_rate_limit', array() ),
			array(
				'max'    => 5,
				'window' => 10 * MINUTE_IN_SECONDS,
			)
		);

		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'fuxt_api_email_' . md5( $ip );

		$count = (int) get_transient( $key );
		if ( $count >= (int) $limit['max'] ) {
			return new \WP_Error(
				'rest_email_rate_limited',
				__( 'Too many requests. Please try again later.', 'fuxt-api' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, (int) $limit['window'] );

		return true;
	}

	/**
	 * Verify a reCAPTCHA v2 token when a secret is configured.
	 *
	 * @param string $token Response token from the frontend widget.
	 * @return true|\WP_Error
	 */
	private function verify_recaptcha( $token ) {
		$secret = defined( 'FUXT_API_RECAPTCHA_SECRET' ) ? FUXT_API_RECAPTCHA_SECRET : '';
		$secret = (string) apply_filters( 'fuxt_api_email_recaptcha_secret', $secret );

		if ( '' === $secret ) {
			return true;
		}

		$error = new \WP_Error(
			'rest_email_recaptcha_failed',
			__( 'reCAPTCHA verification failed. Please try again.', 'fuxt-api' ),
			array( 'status' => 400 )
		);

		if ( '' === $token ) {
			return $error;
		}

		$response = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $error;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return ! empty( $body['success'] ) ? true : $error;
	}
}
