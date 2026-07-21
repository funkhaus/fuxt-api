<?php
/**
 * Class REST_Ical_Controller
 *
 * @package FuxtApi
 */

namespace FuxtApi;

/**
 * Serves the Events CPT as an iCalendar (.ics) feed.
 *
 * Endpoint: GET /wp-json/fuxt/v1/events.ics
 *
 * Subscribe to this URL in any calendar app (Google Calendar, Apple Calendar,
 * Outlook) or wire it into a WordPress calendar plugin's "ICS feed" field.
 *
 * ACF field group: event_details (group)
 *   Sub-fields (stored directly in postmeta by their own key):
 *     event_start_date  — datetime picker, e.g. "06/06/2026 9:00 am"
 *     event_end_date    — datetime picker, e.g. "06/06/2026 3:00 pm"
 *     name              — venue name
 *     address           — venue street address
 */
class REST_Ical_Controller {

	const REST_NAMESPACE = 'fuxt/v1';
	const ROUTE          = '/events.ics';
	const POST_TYPE      = 'event';

	// ACF postmeta keys — ACF prefixes group sub-fields with the group field name.
	// Group: event_details → stored as event_details_{sub_field_name}.
	const FIELD_START_DATE   = 'event_details_event_start_date'; // "Y-m-d H:i:s"
	const FIELD_END_DATE     = 'event_details_event_end_date';   // "Y-m-d H:i:s"
	const FIELD_VENUE_NAME   = 'event_details_name';
	const FIELD_VENUE_ADDR   = 'event_details_address';

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
	}

	public function register_endpoint() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	public function get_item( $request ) {
		// ?debug=1 dumps raw post/meta data as JSON — admin only.
		if ( ! empty( $request['debug'] ) && current_user_can( 'manage_options' ) ) {
			$this->output_debug();
		}

		$ics = $this->generate_ics();

		// Hook into rest_pre_serve_request instead of calling exit() directly.
		// Priority 15 runs after rest_send_cors_headers (priority 10), so our
		// wildcard header wins even if WordPress narrowed it to a specific origin.
		add_filter(
			'rest_pre_serve_request',
			function () use ( $ics ) {
				header( 'Content-Type: text/calendar; charset=utf-8' );
				header( 'Content-Disposition: inline; filename="pearl-events.ics"' );
				header( 'Cache-Control: no-cache, must-revalidate' );
				header( 'Access-Control-Allow-Origin: *' );
				header( 'Access-Control-Allow-Methods: GET' );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $ics;
				return true; // tells WP_REST_Server we handled the response; skips JSON output.
			},
			15
		);

		return new \WP_REST_Response( null, 200 );
	}

	private function output_debug() {
		$events = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 3,
			)
		);

		$out = array(
			'post_type'    => self::POST_TYPE,
			'event_count'  => count( $events ),
			'field_keys'   => array(
				'start' => self::FIELD_START_DATE,
				'end'   => self::FIELD_END_DATE,
				'venue' => self::FIELD_VENUE_NAME,
				'addr'  => self::FIELD_VENUE_ADDR,
			),
			'events'       => array(),
		);

		foreach ( $events as $event ) {
			$all_meta = get_post_meta( $event->ID );
			// Strip ACF field-key entries (underscore-prefixed) to keep output readable.
			$readable_meta = array();
			foreach ( $all_meta as $key => $values ) {
				if ( strpos( $key, '_' ) !== 0 ) {
					$readable_meta[ $key ] = $values[0];
				}
			}

			$out['events'][] = array(
				'id'             => $event->ID,
				'title'          => $event->post_title,
				'start_raw'      => get_post_meta( $event->ID, self::FIELD_START_DATE, true ),
				'end_raw'        => get_post_meta( $event->ID, self::FIELD_END_DATE, true ),
				'venue_name_raw' => get_post_meta( $event->ID, self::FIELD_VENUE_NAME, true ),
				'addr_raw'       => get_post_meta( $event->ID, self::FIELD_VENUE_ADDR, true ),
				'all_meta_keys'  => $readable_meta,
			);
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	private function generate_ics() {
		$events = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$tz = wp_timezone();

		// wp_timezone_string() returns a UTC offset (e.g. "+00:00") when WordPress is
		// set to a manual offset rather than an IANA city name. RFC 5545 requires an
		// IANA timezone identifier for TZID, so fall back to the PHP DateTimeZone name.
		$tz_str = $tz->getName();
		if ( str_starts_with( $tz_str, '+' ) || str_starts_with( $tz_str, '-' ) ) {
			$tz_str = 'UTC';
		}

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//' . get_bloginfo( 'name' ) . '//Events//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . $this->escape_text( get_bloginfo( 'name' ) . ' Events' ),
			'X-WR-TIMEZONE:' . $tz_str,
		);

		foreach ( $events as $event ) {
			$start_raw   = get_post_meta( $event->ID, self::FIELD_START_DATE, true );
			$end_raw     = get_post_meta( $event->ID, self::FIELD_END_DATE, true );
			$venue_name  = get_post_meta( $event->ID, self::FIELD_VENUE_NAME, true );
			$venue_addr  = get_post_meta( $event->ID, self::FIELD_VENUE_ADDR, true );

			if ( ! $start_raw ) {
				continue;
			}

			$start_dt = $this->parse_datetime( $start_raw, $tz );
			if ( ! $start_dt ) {
				continue;
			}

			$end_dt = $end_raw ? $this->parse_datetime( $end_raw, $tz ) : null;

			// Build LOCATION from venue name + address.
			$location_parts = array_filter( array( $venue_name, $venue_addr ) );
			$location       = implode( ', ', $location_parts );

			$uid     = 'event-' . $event->ID . '@' . $domain;
			$dtstamp = gmdate( 'Ymd\THis\Z' );

			// For UTC, RFC 5545 requires the Z suffix with no TZID parameter.
			$is_utc      = ( 'UTC' === $tz_str );
			$dt_format   = $is_utc ? 'Ymd\THis\Z' : 'Ymd\THis';
			$dtstart_key = $is_utc ? 'DTSTART' : 'DTSTART;TZID=' . $tz_str;
			$dtend_key   = $is_utc ? 'DTEND' : 'DTEND;TZID=' . $tz_str;

			$vevent_lines = array(
				'BEGIN:VEVENT',
				'UID:' . $uid,
				'DTSTAMP:' . $dtstamp,
				$dtstart_key . ':' . $start_dt->format( $dt_format ),
			);

			if ( $end_dt ) {
				$vevent_lines[] = $dtend_key . ':' . $end_dt->format( $dt_format );
			}

			$vevent_lines[] = 'SUMMARY:' . $this->escape_text( $event->post_title );

			if ( $location ) {
				$vevent_lines[] = 'LOCATION:' . $this->escape_text( $location );
			}

			$url = get_permalink( $event->ID );
			if ( $url ) {
				$vevent_lines[] = 'URL:' . $url;
			}

			$vevent_lines[] = 'END:VEVENT';

			$lines = array_merge( $lines, $vevent_lines );
		}

		$lines[] = 'END:VCALENDAR';

		$folded = array_map( array( $this, 'fold_line' ), $lines );

		return implode( "\r\n", $folded ) . "\r\n";
	}

	/**
	 * Parse a datetime string in any common ACF format.
	 * ACF datetime pickers can store/return in various formats depending on field config.
	 */
	private function parse_datetime( $raw, $tz ) {
		$formats = array(
			'm/d/Y g:i a',  // "06/06/2026 9:00 am" — ACF US datetime return format
			'm/d/Y G:i',    // "06/06/2026 9:00"
			'Y-m-d H:i:s',  // "2026-06-06 09:00:00" — ACF default save format
			'Y-m-d H:i',    // "2026-06-06 09:00"
			'Ymd\THis',     // "20260606T090000"
		);

		foreach ( $formats as $format ) {
			$dt = \DateTime::createFromFormat( $format, $raw, $tz );
			if ( $dt !== false ) {
				return $dt;
			}
		}

		// Last resort — let PHP try to parse it (handles ISO 8601 and others).
		$ts = strtotime( $raw );
		if ( $ts !== false ) {
			$dt = new \DateTime( '@' . $ts );
			$dt->setTimezone( $tz );
			return $dt;
		}

		return null;
	}

	/**
	 * Escape special characters per RFC 5545 §3.3.11 (TEXT).
	 */
	private function escape_text( $text ) {
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( ';', '\\;', $text );
		$text = str_replace( ',', '\\,', $text );
		$text = str_replace( "\r\n", '\\n', $text );
		$text = str_replace( "\n", '\\n', $text );
		$text = str_replace( "\r", '', $text );
		return $text;
	}

	/**
	 * Fold a long iCalendar content line at 75 octets per RFC 5545 §3.1.
	 * Continuation lines begin with a single space.
	 */
	private function fold_line( $line ) {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}

		$output = '';
		while ( strlen( $line ) > 75 ) {
			$output .= substr( $line, 0, 75 ) . "\r\n ";
			$line    = substr( $line, 75 );
		}

		return $output . $line;
	}
}
