<?php
/**
 * Class REST_Ical_Controller
 *
 * @package FuxtApi
 */

namespace FuxtApi;

/**
 * Serves date-bearing post types as iCalendar (.ics) feeds.
 *
 * Endpoints:
 *   GET /wp-json/fuxt/v1/calendar.ics — generic feed. Params below.
 *   GET /wp-json/fuxt/v1/events.ics   — back-compat alias; pins post_type=event.
 *
 * Query params (same vocabulary as /wp-json/fuxt/v1/posts, deliberately):
 *   post_type      — must be in the allowlist (see get_allowed_post_types()). Default "event".
 *   taxonomy       — taxonomy to filter by. Required whenever term_slug is present.
 *   term_slug      — comma-separated term slugs, e.g. "live-music" or "live-music,pearl-markets".
 *   term_operator  — IN (any, default) or AND (all).
 *   debug          — admin-only JSON dump of raw post/meta data.
 *
 * Subscribe to the URL in any calendar app (Google Calendar, Apple Calendar,
 * Outlook) or wire it into a WordPress calendar plugin's "ICS feed" field.
 *
 * ACF field group: event_details (group)
 *   Sub-fields (stored in postmeta as event_details_{sub_field_name}):
 *     event_start_date     — datetime picker, stored "Y-m-d H:i:s"
 *     event_end_date       — datetime picker, stored "Y-m-d H:i:s"
 *     name                 — venue name
 *     address              — venue street address
 *     recurring            — true/false; gates the two fields below
 *     recurring_frequency  — select: weekly | biweekly | monthly_date | monthly_weekday
 *     recurring_until      — date picker; when the series stops
 *
 * Note that the datetime values read here are RAW postmeta ("Y-m-d H:i:s"), not
 * ACF's formatted return value ("d/m/Y g:i a"). Do not "fix" the parser to lead
 * with a d/m or m/d format on the assumption that it sees display strings.
 */
class REST_Ical_Controller {

	const REST_NAMESPACE = 'fuxt/v1';
	const ROUTE_CALENDAR = '/calendar.ics';
	const ROUTE_EVENTS   = '/events.ics';

	const DEFAULT_POST_TYPE = 'event';

	// ACF postmeta keys — ACF prefixes group sub-fields with the group field name.
	// Group: event_details → stored as event_details_{sub_field_name}.
	// Retained as constants because other code may reference them; the canonical
	// lookup is now get_field_map(), which is built from these.
	const FIELD_START_DATE = 'event_details_event_start_date'; // "Y-m-d H:i:s"
	const FIELD_END_DATE   = 'event_details_event_end_date';   // "Y-m-d H:i:s"
	const FIELD_VENUE_NAME = 'event_details_name';
	const FIELD_VENUE_ADDR = 'event_details_address';
	const FIELD_RECURRING  = 'event_details_recurring';
	const FIELD_RRULE_FREQ = 'event_details_recurring_frequency';
	const FIELD_RRULE_TILL = 'event_details_recurring_until';

	// Recurrence frequencies understood by build_rrule(). Anything else — including
	// an empty value on a post flagged `recurring` — yields no RRULE at all, i.e.
	// the event falls back to a single occurrence.
	const FREQ_WEEKLY          = 'weekly';
	const FREQ_BIWEEKLY        = 'biweekly';
	const FREQ_MONTHLY_DATE    = 'monthly_date';
	const FREQ_MONTHLY_WEEKDAY = 'monthly_weekday';

	// An unbounded RRULE propagates permanently into every subscriber's calendar,
	// and ical.js caps expansion at 1000 iterations counted forward from DTSTART —
	// so far-future months on an infinite rule render empty rather than erroring.
	// Always bound the series; this is the fallback when recurring_until is blank.
	const DEFAULT_RRULE_HORIZON = '+1 year';

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
	}

	public function register_endpoint() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CALENDAR,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => $this->get_route_args(),
				),
			)
		);

		// Back-compat: this URL is already live in the frontend and in any calendar
		// app someone has subscribed with. It keeps working, filtering params and
		// all, but post_type is pinned to the default.
		$events_args = $this->get_route_args();
		unset( $events_args['post_type'] );

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_EVENTS,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_events_item' ),
					'permission_callback' => '__return_true',
					'args'                => $events_args,
				),
			)
		);
	}

	/**
	 * Shared param declarations for both routes.
	 */
	private function get_route_args() {
		return array(
			'post_type'     => array(
				'description'       => 'Post type to build the feed from. Must be allowlisted.',
				'type'              => 'string',
				'default'           => self::DEFAULT_POST_TYPE,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => array( $this, 'validate_post_type' ),
			),
			'taxonomy'      => array(
				'description'       => 'Taxonomy to filter by. Required when term_slug is present.',
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => array( $this, 'validate_taxonomy' ),
			),
			'term_slug'     => array(
				'description'       => 'Comma-separated term slugs to filter by.',
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_term_slug' ),
			),
			// No 'enum' here on purpose: schema enums are checked against the raw
			// value before sanitisation, which would reject a lowercase "in". The
			// sanitiser normalises case and falls back to IN for anything unknown.
			'term_operator' => array(
				'description'       => 'IN to match any term (default), AND to match all.',
				'type'              => 'string',
				'default'           => 'IN',
				'sanitize_callback' => array( $this, 'sanitize_term_operator' ),
			),
		);
	}

	/**
	 * Post types this endpoint will serve.
	 *
	 * Deliberately an allowlist rather than "any public post type" — without it the
	 * endpoint becomes a way to enumerate arbitrary CPTs. Add a post type here (or
	 * via the filter) only once it actually has the datetime fields in get_field_map().
	 */
	private function get_allowed_post_types() {
		$post_types = apply_filters( 'fuxt_ical_post_types', array( self::DEFAULT_POST_TYPE ) );

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $post_types ) ) ) );
	}

	public function validate_post_type( $value, $request = null, $param = null ) {
		$post_type = sanitize_key( $value );
		$allowed   = $this->get_allowed_post_types();

		if ( ! in_array( $post_type, $allowed, true ) ) {
			return new \WP_Error(
				'fuxt_ical_invalid_post_type',
				sprintf(
					/* translators: 1: requested post type, 2: comma-separated list of allowed post types */
					'Unsupported post_type "%1$s". Allowed: %2$s.',
					$post_type,
					implode( ', ', $allowed )
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	public function validate_taxonomy( $value, $request = null, $param = null ) {
		if ( '' === $value || null === $value ) {
			return true;
		}

		$taxonomy = sanitize_key( $value );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error(
				'fuxt_ical_invalid_taxonomy',
				sprintf( 'Unknown taxonomy "%s".', $taxonomy ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	public function sanitize_term_slug( $value ) {
		$slugs = array_map( 'sanitize_title', explode( ',', (string) $value ) );

		return implode( ',', array_values( array_filter( $slugs ) ) );
	}

	public function sanitize_term_operator( $value ) {
		return ( 'AND' === strtoupper( (string) $value ) ) ? 'AND' : 'IN';
	}

	/**
	 * Callback for /events.ics — same behaviour, post_type pinned.
	 */
	public function get_events_item( $request ) {
		$request->set_param( 'post_type', self::DEFAULT_POST_TYPE );

		return $this->get_item( $request );
	}

	public function get_item( $request ) {
		$post_type   = $request->get_param( 'post_type' );
		$post_type   = $post_type ? sanitize_key( $post_type ) : self::DEFAULT_POST_TYPE;
		$taxonomy    = (string) $request->get_param( 'taxonomy' );
		$term_slug   = (string) $request->get_param( 'term_slug' );
		$operator    = $this->sanitize_term_operator( $request->get_param( 'term_operator' ) );
		$term_slugs  = $term_slug ? array_values( array_filter( explode( ',', $term_slug ) ) ) : array();

		// Term slugs are not unique across taxonomies on this install (the price-*
		// slugs collide), so an unscoped term filter would silently match the wrong
		// taxonomy. Refuse rather than guess.
		if ( ! empty( $term_slugs ) && '' === $taxonomy ) {
			return new \WP_Error(
				'fuxt_ical_missing_taxonomy',
				'term_slug requires taxonomy — term slugs are not unique across taxonomies.',
				array( 'status' => 400 )
			);
		}

		// ?debug=1 dumps raw post/meta data as JSON — admin only.
		if ( ! empty( $request['debug'] ) && current_user_can( 'manage_options' ) ) {
			$this->output_debug( $post_type, $taxonomy, $term_slugs, $operator );
		}

		$ics      = $this->generate_ics( $post_type, $taxonomy, $term_slugs, $operator );
		$filename = 'pearl-' . $post_type . '.ics';

		// Hook into rest_pre_serve_request instead of calling exit() directly.
		// Priority 15 runs after rest_send_cors_headers (priority 10), so our
		// wildcard header wins even if WordPress narrowed it to a specific origin.
		add_filter(
			'rest_pre_serve_request',
			function () use ( $ics, $filename ) {
				header( 'Content-Type: text/calendar; charset=utf-8' );
				header( 'Content-Disposition: inline; filename="' . $filename . '"' );
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

	/**
	 * Datetime + venue postmeta keys per post type.
	 *
	 * Filterable so a new CPT can join the feed without editing this class — pair it
	 * with the fuxt_ical_post_types filter to allowlist the type.
	 */
	private function get_field_map( $post_type ) {
		$maps = apply_filters(
			'fuxt_ical_field_map',
			array(
				self::DEFAULT_POST_TYPE => array(
					'start'       => self::FIELD_START_DATE,
					'end'         => self::FIELD_END_DATE,
					'venue'       => self::FIELD_VENUE_NAME,
					'address'     => self::FIELD_VENUE_ADDR,
					'recurring'   => self::FIELD_RECURRING,
					'rrule_freq'  => self::FIELD_RRULE_FREQ,
					'rrule_until' => self::FIELD_RRULE_TILL,
				),
			),
			$post_type
		);

		return isset( $maps[ $post_type ] ) ? $maps[ $post_type ] : $maps[ self::DEFAULT_POST_TYPE ];
	}

	/**
	 * Fetch the posts for a feed.
	 *
	 * Uses a plain WP_Query tax_query rather than Utils\Post::get_posts() — that
	 * helper's term handling is geared to the /posts controller's response shape,
	 * and a core tax_query is the smaller dependency here.
	 */
	private function get_feed_posts( $post_type, $taxonomy, $term_slugs, $operator ) {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
		);

		if ( $taxonomy && ! empty( $term_slugs ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $term_slugs,
					'operator' => $operator,
				),
			);
		}

		return get_posts( $args );
	}

	/**
	 * Human-readable calendar name, e.g. "Pearl Events — Live Music".
	 *
	 * A subscriber who adds the music feed should not see a calendar called
	 * "Pearl Events" sitting in their sidebar.
	 */
	private function get_calendar_name( $post_type, $taxonomy, $term_slugs ) {
		$post_type_obj = get_post_type_object( $post_type );
		$label         = ( $post_type_obj && ! empty( $post_type_obj->labels->name ) )
			? $post_type_obj->labels->name
			: ucfirst( $post_type );

		$name = trim( get_bloginfo( 'name' ) . ' ' . $label );

		if ( $taxonomy && ! empty( $term_slugs ) ) {
			$term_names = array();

			foreach ( $term_slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$term_names[] = $term->name;
				}
			}

			if ( ! empty( $term_names ) ) {
				$name .= ' — ' . implode( ', ', $term_names );
			}
		}

		return $name;
	}

	private function output_debug( $post_type, $taxonomy, $term_slugs, $operator ) {
		$fields = $this->get_field_map( $post_type );
		$posts  = $this->get_feed_posts( $post_type, $taxonomy, $term_slugs, $operator );

		$out = array(
			'post_type'  => $post_type,
			'taxonomy'   => $taxonomy,
			'term_slugs' => $term_slugs,
			'operator'   => $operator,
			'post_count' => count( $posts ),
			'field_keys' => $fields,
			'posts'      => array(),
		);

		foreach ( array_slice( $posts, 0, 5 ) as $post ) {
			$all_meta = get_post_meta( $post->ID );

			// Strip ACF field-key entries (underscore-prefixed) to keep output readable.
			$readable_meta = array();
			foreach ( $all_meta as $key => $values ) {
				if ( strpos( $key, '_' ) !== 0 ) {
					$readable_meta[ $key ] = $values[0];
				}
			}

			$out['posts'][] = array(
				'id'            => $post->ID,
				'title'         => $post->post_title,
				'start_raw'     => get_post_meta( $post->ID, $fields['start'], true ),
				'end_raw'       => get_post_meta( $post->ID, $fields['end'], true ),
				'recurring_raw' => get_post_meta( $post->ID, $fields['recurring'], true ),
				'freq_raw'      => get_post_meta( $post->ID, $fields['rrule_freq'], true ),
				'until_raw'     => get_post_meta( $post->ID, $fields['rrule_until'], true ),
				'all_meta_keys' => $readable_meta,
			);
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	private function generate_ics( $post_type, $taxonomy, $term_slugs, $operator ) {
		$posts  = $this->get_feed_posts( $post_type, $taxonomy, $term_slugs, $operator );
		$fields = $this->get_field_map( $post_type );

		$tz = wp_timezone();

		// wp_timezone_string() returns a UTC offset (e.g. "+00:00") when WordPress is
		// set to a manual offset rather than an IANA city name. RFC 5545 requires an
		// IANA timezone identifier for TZID, so fall back to the PHP DateTimeZone name.
		$tz_str = $tz->getName();
		if ( str_starts_with( $tz_str, '+' ) || str_starts_with( $tz_str, '-' ) ) {
			$tz_str = 'UTC';
		}

		$domain    = wp_parse_url( home_url(), PHP_URL_HOST );
		$cal_name  = $this->get_calendar_name( $post_type, $taxonomy, $term_slugs );
		$taxonomies = get_object_taxonomies( $post_type );

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//' . $this->escape_text( get_bloginfo( 'name' ) ) . '//' . $this->escape_text( $cal_name ) . '//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . $this->escape_text( $cal_name ),
			'X-WR-TIMEZONE:' . $tz_str,
		);

		foreach ( $posts as $post ) {
			$vevent = $this->build_vevent( $post, $post_type, $fields, $taxonomies, $tz, $tz_str, $domain );

			if ( ! empty( $vevent ) ) {
				$lines = array_merge( $lines, $vevent );
			}
		}

		$lines[] = 'END:VCALENDAR';

		$folded = array_map( array( $this, 'fold_line' ), $lines );

		return implode( "\r\n", $folded ) . "\r\n";
	}

	/**
	 * Build one VEVENT. Returns an empty array when the post has no usable start date.
	 */
	private function build_vevent( $post, $post_type, $fields, $taxonomies, $tz, $tz_str, $domain ) {
		$start_raw = get_post_meta( $post->ID, $fields['start'], true );

		if ( ! $start_raw ) {
			return array();
		}

		$start_dt = $this->parse_datetime( $start_raw, $tz );

		if ( ! $start_dt ) {
			return array();
		}

		$end_raw = get_post_meta( $post->ID, $fields['end'], true );
		$end_dt  = $end_raw ? $this->parse_datetime( $end_raw, $tz ) : null;

		// Build LOCATION from venue name + address.
		$venue_name     = get_post_meta( $post->ID, $fields['venue'], true );
		$venue_addr     = get_post_meta( $post->ID, $fields['address'], true );
		$location_parts = array_filter( array( $venue_name, $venue_addr ) );
		$location       = implode( ', ', $location_parts );

		// Keeps the UID byte-identical to the pre-generalisation format for events
		// ("event-590@domain"), so existing subscriptions don't duplicate entries.
		$uid     = $post_type . '-' . $post->ID . '@' . $domain;
		$dtstamp = gmdate( 'Ymd\THis\Z' );

		// For UTC, RFC 5545 requires the Z suffix with no TZID parameter.
		$is_utc      = ( 'UTC' === $tz_str );
		$dt_format   = $is_utc ? 'Ymd\THis\Z' : 'Ymd\THis';
		$dtstart_key = $is_utc ? 'DTSTART' : 'DTSTART;TZID=' . $tz_str;
		$dtend_key   = $is_utc ? 'DTEND' : 'DTEND;TZID=' . $tz_str;

		$lines = array(
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . $dtstamp,
			$dtstart_key . ':' . $start_dt->format( $dt_format ),
		);

		// DTEND describes the FIRST occurrence only — for a recurring event the
		// duration is carried to each instance by the RRULE. Do not extend it to
		// the end of the series.
		if ( $end_dt ) {
			$lines[] = $dtend_key . ':' . $end_dt->format( $dt_format );
		}

		$rrule = $this->build_rrule( $post->ID, $fields, $start_dt, $tz );
		if ( $rrule ) {
			$lines[] = $rrule;
		}

		$lines[] = 'SUMMARY:' . $this->escape_text( $post->post_title );

		if ( $location ) {
			$lines[] = 'LOCATION:' . $this->escape_text( $location );
		}

		$description = $this->get_description( $post );
		if ( $description ) {
			$lines[] = 'DESCRIPTION:' . $this->escape_text( $description );
		}

		// Note: @fullcalendar/icalendar drops CATEGORIES (its extendedProps allowlist
		// is location/organizer/description only), so this is for subscribed calendar
		// apps — on-site scoping happens via the term_slug param instead.
		$categories = $this->get_categories( $post, $taxonomies );
		if ( ! empty( $categories ) ) {
			$lines[] = 'CATEGORIES:' . implode( ',', array_map( array( $this, 'escape_text' ), $categories ) );
		}

		$url = get_permalink( $post->ID );
		if ( $url ) {
			$lines[] = 'URL:' . $url;
		}

		$lines[] = 'END:VEVENT';

		return $lines;
	}

	/**
	 * Build the RRULE line for a recurring post, or '' for a single occurrence.
	 *
	 * A post flagged `recurring` with no frequency set yields '' — i.e. exactly the
	 * pre-recurrence behaviour. That keeps the feed valid while the new ACF fields
	 * are still being filled in.
	 */
	private function build_rrule( $post_id, $fields, $start_dt, $tz ) {
		if ( empty( $fields['recurring'] ) || empty( $fields['rrule_freq'] ) ) {
			return '';
		}

		$recurring = get_post_meta( $post_id, $fields['recurring'], true );

		if ( ! $recurring || '0' === $recurring ) {
			return '';
		}

		$freq = get_post_meta( $post_id, $fields['rrule_freq'], true );

		if ( ! $freq ) {
			return '';
		}

		// "Mon" → "MO", "Tue" → "TU", … the two-letter weekday codes RFC 5545 uses.
		$weekday = strtoupper( substr( $start_dt->format( 'D' ), 0, 2 ) );
		$day     = (int) $start_dt->format( 'j' );

		switch ( $freq ) {
			case self::FREQ_WEEKLY:
				$parts = array( 'FREQ=WEEKLY', 'BYDAY=' . $weekday );
				break;

			case self::FREQ_BIWEEKLY:
				$parts = array( 'FREQ=WEEKLY', 'INTERVAL=2', 'BYDAY=' . $weekday );
				break;

			case self::FREQ_MONTHLY_DATE:
				$parts = array( 'FREQ=MONTHLY', 'BYMONTHDAY=' . $day );
				break;

			case self::FREQ_MONTHLY_WEEKDAY:
				// Which occurrence of this weekday within the month, e.g. "2nd Saturday".
				$nth = intdiv( $day - 1, 7 ) + 1;

				// A 5th weekday doesn't exist in every month, so a literal 5 would
				// silently skip those months. -1 means "last", which always resolves.
				if ( $nth >= 5 ) {
					$nth = -1;
				}

				$parts = array( 'FREQ=MONTHLY', 'BYDAY=' . $nth . $weekday );
				break;

			default:
				return '';
		}

		$until_raw = get_post_meta( $post_id, $fields['rrule_until'], true );
		$until_dt  = $until_raw ? $this->parse_datetime( $until_raw, $tz ) : null;

		if ( ! $until_dt ) {
			$until_dt = ( clone $start_dt )->modify( self::DEFAULT_RRULE_HORIZON );
		}

		// RFC 5545 §3.3.10: when DTSTART is a local time with a TZID reference — as
		// ours is — UNTIL MUST be given as UTC. A local-time UNTIL here is invalid
		// and parsers disagree about how to read it.
		$until_utc = ( clone $until_dt )->setTime( 23, 59, 59 )->setTimezone( new \DateTimeZone( 'UTC' ) );

		$parts[] = 'UNTIL=' . $until_utc->format( 'Ymd\THis\Z' );

		return 'RRULE:' . implode( ';', $parts );
	}

	/**
	 * Term names across every taxonomy attached to the post type.
	 */
	private function get_categories( $post, $taxonomies ) {
		if ( empty( $taxonomies ) ) {
			return array();
		}

		$terms = wp_get_object_terms( $post->ID, $taxonomies, array( 'fields' => 'names' ) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		return array_values( array_unique( $terms ) );
	}

	/**
	 * DESCRIPTION source — the one text field @fullcalendar/icalendar forwards to
	 * extendedProps, and what external calendar apps show in the event detail.
	 */
	private function get_description( $post ) {
		$raw = $post->post_excerpt ? $post->post_excerpt : $post->post_content;

		if ( ! $raw ) {
			return '';
		}

		return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $raw ) ), 55, '…' );
	}

	/**
	 * Parse a datetime string in any common ACF format.
	 *
	 * Values seen here come from raw postmeta, so "Y-m-d H:i:s" (ACF's datetime save
	 * format) and "Ymd" (ACF's date-picker save format) are the realistic cases and
	 * are tried first. The d/m and m/d display formats are ambiguous with each other
	 * — "15/07/2026" is valid under one reading and nonsense under the other — so
	 * they are last, and any value with trailing junk is rejected outright rather
	 * than silently truncated to a wrong date.
	 */
	private function parse_datetime( $raw, $tz ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return null;
		}

		$formats = array(
			'Y-m-d H:i:s',  // "2026-06-06 09:00:00" — ACF datetime save format
			'Y-m-d H:i',    // "2026-06-06 09:00"
			'!Ymd',         // "20261231"            — ACF date-picker save format
			'Ymd\THis',     // "20260606T090000"
			'd/m/Y g:i a',  // "15/07/2026 5:00 pm"  — ACF d/m display format
			'm/d/Y g:i a',  // "06/06/2026 9:00 am"  — ACF m/d display format
			'!d/m/Y',       // "15/07/2026"
		);

		foreach ( $formats as $format ) {
			$dt = \DateTime::createFromFormat( $format, $raw, $tz );

			if ( false === $dt ) {
				continue;
			}

			// createFromFormat returns an object (not false) for input with trailing
			// data or out-of-range parts, flagging it only via getLastErrors(). Without
			// this check "2026-07-15 17:00:00" would match '!Ymd' and lose its time.
			$errors = \DateTime::getLastErrors();

			if ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) {
				continue;
			}

			return $dt;
		}

		// Last resort — let PHP try to parse it (handles ISO 8601 and others).
		$ts = strtotime( $raw );

		if ( false !== $ts ) {
			$dt = new \DateTime( '@' . $ts );
			$dt->setTimezone( $tz );
			return $dt;
		}

		return null;
	}

	/**
	 * Prepare and escape a value for an iCalendar TEXT field.
	 *
	 * Post titles arrive HTML-encoded from WordPress ("Fife &amp; Farro"). An ICS
	 * feed is not HTML — FullCalendar renders the title as a text node and external
	 * calendar apps never touch Vue at all — so entities must be decoded here or
	 * they display literally. Escaping then follows RFC 5545 §3.3.11.
	 */
	private function escape_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

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
	 *
	 * Splits on character boundaries, not byte boundaries — the feed carries
	 * multi-byte UTF-8 (curly apostrophes in titles, long addresses) and cutting
	 * mid-sequence corrupts the character. The leading space on a continuation line
	 * counts toward its 75 octets, so those lines get a 74-octet content budget.
	 */
	private function fold_line( $line ) {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}

		$chars = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );

		// Invalid UTF-8 — return it untouched rather than mangling it further.
		if ( false === $chars || null === $chars ) {
			return $line;
		}

		$output  = '';
		$current = '';
		$limit   = 75;

		foreach ( $chars as $char ) {
			if ( strlen( $current ) + strlen( $char ) > $limit ) {
				$output .= $current . "\r\n ";
				$current = '';
				$limit   = 74;
			}

			$current .= $char;
		}

		return $output . $current;
	}
}
