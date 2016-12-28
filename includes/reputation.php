<?php
/**
 * AnsPress reputation functions.
 *
 * @package   WordPress/AnsPress
 * @author    Rahul Aryan <support@anspress.io>
 * @license   GPL-3.0+
 * @link      https://anspress.io
 * @copyright 2014 Rahul Aryan
 * @since 4.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Insert reputation.
 *
 * @param string          $event Event type.
 * @param integer         $ref_id Reference ID (post or comment ID).
 * @param integer|boolean $user_id User ID.
 * @return boolean
 * @since 4.0.0
 */
function ap_insert_reputation( $event, $ref_id, $user_id = false ) {
	global $wpdb;

	if ( false === $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( empty( $user_id ) || empty( $event ) ) {
		return false;
	}

	$exists = ap_get_reputation( $event, $ref_id, $user_id );

	// Check if same record already exists.
	if ( ! empty( $exists ) ) {
		return false;
	}

	$insert = $wpdb->insert( $wpdb->ap_reputations, [ 'rep_user_id' => $user_id, 'rep_event' => sanitize_text_field( $event ), 'rep_ref_id' => $ref_id, 'rep_date' => current_time( 'mysql' ) ], [ '%d', '%s', '%d', '%s' ] ); // WPCS: db call okay.

	if ( false === $insert ) {
		return false;
	}

	/**
	 * Trigger action after inserting a reputation.
	 */
	do_action( 'ap_insert_reputation', $wpdb->insert_id );

	return $wpdb->insert_id;
}

/**
 * Get reputation.
 *
 * @param string          $event Event type.
 * @param integer         $ref_id Reference ID (post or comment ID).
 * @param integer|boolean $user_id User ID.
 * @return array
 */
function ap_get_reputation( $event, $ref_id, $user_id = false ) {
	global $wpdb;

	if ( false === $user_id ) {
		$user_id = get_current_user_id();
	}

	$key = $event . '_' . $ref_id . '_' . $user_id;
	$cache = wp_cache_get( $key, 'ap_reputation' );

	if ( false !== $cache ) {
		return $cache;
	}

	$reputation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $wpdb->ap_reputations WHERE rep_user_id = %d AND rep_ref_id = %d AND rep_event = %s", $user_id, $ref_id, $event ) ); // WPCS: db call okay.

	wp_cache_set( $key, $reputation, 'ap_reputation' );

	return $reputation;
}

/**
 * Delete reputation by user_id and event.
 *
 * @param  string          $event Reputation event.
 * @param  integer         $ref_id Reference ID.
 * @param  integer|boolean $user_id User ID.
 * @return boolean|integer
 * @since 4.0.0
 */
function ap_delete_reputation( $event, $ref_id, $user_id = false ) {
	global $wpdb;

	if ( false === $user_id ) {
		$user_id = get_current_user_id();
	}

	$delete = $wpdb->delete( $wpdb->ap_reputations, [ 'rep_user_id' => $user_id, 'rep_event' => sanitize_text_field( $event ), 'rep_ref_id' => $ref_id ], [ '%d', '%s', '%d' ] ); // WPCS: db call okay, db cache okay.

	if ( false === $delete ) {
		return false;
	}

	/**
	 * Trigger action after deleteing a reputation.
	 */
	do_action( 'ap_delete_reputation', $user_id, $event, $delete );

	return $delete;
}

/**
 * Register reputation event.
 *
 * @param string  $event_slug Event slug.
 * @param integer $points Points to award for this reputation.
 * @param string  $label Event label.
 * @param string  $description Event description.
 * @since 4.0.0
 */
function ap_register_reputation_event( $event_slug, $points, $label, $description ) {
	$event_slug = sanitize_text_field( $event_slug );
	$label = esc_attr( $label );
	$description = esc_attr( $description );

	$custom_points = get_option( 'anspress_reputation_events' );
	$points = isset( $custom_points[ $event_slug ] ) ? (int) $custom_points[ $event_slug ] : (int) $points;
	anspress()->reputation_events[ $event_slug ] = [ 'points' => $points, 'label' => $label, 'description' => $description ];
}

/**
 * Get all reputation events.
 *
 * @since 4.0.0
 */
function ap_get_reputation_events() {
	if ( ! empty( anspress()->reputation_events ) ) {
		do_action( 'ap_reputation_events' );
	}

	return anspress()->reputation_events;
}

/**
 * Get reputation event points.
 *
 * @param string $event event slug.
 * @return integer
 * @since 4.0.0
 */
function ap_get_reputation_event_points( $event ) {
	$events = ap_get_reputation_events();

	if ( isset( $events[ $event ] ) ) {
		return (int) $events[ $event ]['points'];
	}

	return 0;
}
