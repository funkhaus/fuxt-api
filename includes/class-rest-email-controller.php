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
 * @package FuxtApi
 */
class REST_Email_Controller {

	const REST_NAMESPACE = 'fuxt/v1';

	const ROUTE = '/email';

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
				'schema' => array( $this, 'get_item_schema' ),
			)
		);
	}


	/**
	 * Checks if a given request has access to send emails.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function send_email_permissions_check( $request ) {
		// Allow public access by default, but can be restricted via filter
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
			'to'          => array(
				'description' => __( 'Email address to send to.', 'fuxt-api' ),
				'type'        => 'string',
				'required'    => true,
				'format'      => 'email',
			),
			'subject'     => array(
				'description' => __( 'Email subject.', 'fuxt-api' ),
				'type'        => 'string',
				'required'    => true,
			),
			'message'     => array(
				'description' => __( 'Email message content.', 'fuxt-api' ),
				'type'        => 'string',
				'required'    => true,
			),
			'from_name'   => array(
				'description' => __( 'Sender name.', 'fuxt-api' ),
				'type'        => 'string',
				'default'     => get_option( 'blogname' ),
			),
			'from_email'  => array(
				'description' => __( 'Sender email address.', 'fuxt-api' ),
				'type'        => 'string',
				'format'      => 'email',
				'default'     => get_option( 'admin_email' ),
			),
			'reply_to'    => array(
				'description' => __( 'Reply-to email address.', 'fuxt-api' ),
				'type'        => 'string',
				'format'      => 'email',
			),
			'cc'          => array(
				'description' => __( 'CC email addresses (comma separated).', 'fuxt-api' ),
				'type'        => 'string',
			),
			'bcc'         => array(
				'description' => __( 'BCC email addresses (comma separated).', 'fuxt-api' ),
				'type'        => 'string',
			),
			'headers'     => array(
				'description' => __( 'Additional email headers as key-value pairs.', 'fuxt-api' ),
				'type'        => 'object',
			),
			'attachments' => array(
				'description' => __( 'File attachments (array of file paths or URLs).', 'fuxt-api' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
				),
			),
			'is_html'     => array(
				'description' => __( 'Whether the message is HTML content.', 'fuxt-api' ),
				'type'        => 'boolean',
				'default'     => false,
			),
			'trap'        => array(
				'description' => __( 'Crude anti-spam measure. This must equal the clientRequestId, otherwise the email will not be sent.', 'fuxt-api' ),
				'type'        => 'string',
				'required'    => true,
			),
			'clientRequestId' => array(
				'description' => __( 'Client request ID for anti-spam verification.', 'fuxt-api' ),
				'type'        => 'string',
				'required'    => true,
			),
		);
	}

	/**
	 * Email response schema.
	 *
	 * @return array Schema definition.
	 */
	public function get_item_schema() {
		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'fuxt_email_response',
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

		return $schema;
	}

	/**
	 * Send email.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function send_email( $request ) {
		$to          = sanitize_email( $request['to'] );
		$subject     = sanitize_text_field( $request['subject'] );
		$message     = $request['message'];
		$from_name   = sanitize_text_field( $request['from_name'] );
		$from_email  = sanitize_email( $request['from_email'] );
		$reply_to    = ! empty( $request['reply_to'] ) ? sanitize_email( $request['reply_to'] ) : $from_email;
		$cc          = $request['cc'];
		$bcc         = $request['bcc'];
		$headers     = $request['headers'];
		$attachments = $request['attachments'];
		$is_html     = (bool) $request['is_html'];
		$trap        = sanitize_text_field( $request['trap'] );
		$client_request_id = sanitize_text_field( $request['clientRequestId'] );

		// Validate required fields
		if ( empty( $to ) || empty( $subject ) || empty( $message ) ) {
			return new \WP_Error(
				'rest_email_missing_fields',
				__( 'Missing required fields: to, subject, and message are required.', 'fuxt-api' ),
				array( 'status' => 400 )
			);
		}

		// Validate spam trap
		if ( empty( $trap ) || empty( $client_request_id ) ) {
			return new \WP_Error(
				'rest_email_missing_spam_fields',
				__( 'Missing required fields: trap and clientRequestId are required for spam protection.', 'fuxt-api' ),
				array( 'status' => 400 )
			);
		}

		// Check spam trap
		if ( $trap !== $client_request_id ) {
			return new \WP_Error(
				'rest_email_spam_trap_failed',
				__( 'Spam trap validation failed. Email not sent.', 'fuxt-api' ),
				array( 'status' => 400 )
			);
		}

		// Recipient allowlist. Empty by default — an empty allowlist disables the
		// endpoint, so the base plugin ships safe (no config = no sending, and no
		// open relay). Projects opt in by adding recipients via the
		// 'fuxt_api_email_allowed_recipients' filter (e.g. from a settings field).
		$allowed_recipients = array_filter( array_map(
			'sanitize_email',
			(array) apply_filters( 'fuxt_api_email_allowed_recipients', array() )
		) );

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

		// Prepare email headers
		$email_headers = array();

		// From header
		$email_headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';

		// Reply-To header
		if ( ! empty( $reply_to ) ) {
			$email_headers[] = 'Reply-To: ' . $reply_to;
		}

		// CC header
		if ( ! empty( $cc ) ) {
			$cc_emails = array_map( 'trim', explode( ',', $cc ) );
			$cc_emails = array_map( 'sanitize_email', $cc_emails );
			$cc_emails = array_filter( $cc_emails );
			if ( ! empty( $cc_emails ) ) {
				$email_headers[] = 'Cc: ' . implode( ', ', $cc_emails );
			}
		}

		// BCC header
		if ( ! empty( $bcc ) ) {
			$bcc_emails = array_map( 'trim', explode( ',', $bcc ) );
			$bcc_emails = array_map( 'sanitize_email', $bcc_emails );
			$bcc_emails = array_filter( $bcc_emails );
			if ( ! empty( $bcc_emails ) ) {
				$email_headers[] = 'Bcc: ' . implode( ', ', $bcc_emails );
			}
		}

		// Content-Type header
		if ( $is_html ) {
			$email_headers[] = 'Content-Type: text/html; charset=UTF-8';
		} else {
			$email_headers[] = 'Content-Type: text/plain; charset=UTF-8';
		}

		// Additional custom headers
		if ( ! empty( $headers ) && is_array( $headers ) ) {
			foreach ( $headers as $key => $value ) {
				$email_headers[] = sanitize_text_field( $key ) . ': ' . sanitize_text_field( $value );
			}
		}

		// Process attachments
		$processed_attachments = array();
		if ( ! empty( $attachments ) && is_array( $attachments ) ) {
			foreach ( $attachments as $attachment ) {
				$attachment = sanitize_text_field( $attachment );
				if ( ! empty( $attachment ) ) {
					// Check if it's a URL or file path
					if ( filter_var( $attachment, FILTER_VALIDATE_URL ) ) {
						// Download URL to temporary file
						$temp_file = download_url( $attachment );
						if ( ! is_wp_error( $temp_file ) ) {
							$processed_attachments[] = $temp_file;
						}
					} elseif ( file_exists( $attachment ) ) {
						$processed_attachments[] = $attachment;
					}
				}
			}
		}

		// Allow filtering of email data before sending
		$email_data = apply_filters( 'fuxt_api_email_data', array(
			'to'          => $to,
			'subject'     => $subject,
			'message'     => $message,
			'headers'     => $email_headers,
			'attachments' => $processed_attachments,
		), $request );

		// Send the email
		$sent = wp_mail(
			$email_data['to'],
			$email_data['subject'],
			$email_data['message'],
			$email_data['headers'],
			$email_data['attachments']
		);

		// Clean up temporary files
		foreach ( $processed_attachments as $temp_file ) {
			if ( strpos( $temp_file, sys_get_temp_dir() ) === 0 ) {
				unlink( $temp_file );
			}
		}

		if ( $sent ) {
			$response_data = array(
				'success' => true,
				'message' => __( 'Email sent successfully.', 'fuxt-api' ),
				'data'    => array(
					'to'      => $to,
					'subject' => $subject,
				),
			);
		} else {
			$response_data = array(
				'success' => false,
				'message' => __( 'Failed to send email.', 'fuxt-api' ),
				'data'    => array(),
			);
		}

		// Allow filtering of response
		$response_data = apply_filters( 'fuxt_api_email_response', $response_data, $request, $sent );

		return rest_ensure_response( $response_data );
	}
}
