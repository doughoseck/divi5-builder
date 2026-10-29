<?php
/**
 * Confirmation codes for permanent deletes (#32, 2.2.0). The preview of a delete issues a code; the delete presents it.
 * This file only defines functions.
 *
 * A code is 12 characters from A-HJ-NP-Z2-9, shown once and stored only as wp_hash( code ), compared with hash_equals.
 * Transients (600 s):
 *   d5b_confirm_<sha1(user|ability|type|id)>       { code_hash, fingerprint, issued }   one outstanding code per user and object
 *   d5b_confirm_obj_<sha1(ability|type|id)>        the user id who last issued for the object (to report wrong_user)
 *   d5b_confirm_uat_<sha1(user|ability|type)>      the object ids that user has codes for (to report wrong_object)
 *   d5b_confirm_used_<sha1(user|ability|type|id)>  marker left by a successful check (to report used, not expired)
 * wrong_user and wrong_object are only reported when the presented code really is the other user's / the other object's
 * code, so a guess learns nothing. A code that matches nothing is "missing" when a code is outstanding for the object
 * and "expired" when none is.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const D5B_CONFIRM_TTL      = 600;
const D5B_CONFIRM_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function d5b_confirm_key( $user, $ability, $type, $id ) { return 'd5b_confirm_' . sha1( (int) $user . '|' . $ability . '|' . $type . '|' . $id ); }
function d5b_confirm_obj_key( $ability, $type, $id ) { return 'd5b_confirm_obj_' . sha1( $ability . '|' . $type . '|' . $id ); }
function d5b_confirm_uat_key( $user, $ability, $type ) { return 'd5b_confirm_uat_' . sha1( (int) $user . '|' . $ability . '|' . $type ); }
function d5b_confirm_used_key( $user, $ability, $type, $id ) { return 'd5b_confirm_used_' . sha1( (int) $user . '|' . $ability . '|' . $type . '|' . $id ); }

function d5b_confirm_new_code() {
	$out = ''; $max = strlen( D5B_CONFIRM_ALPHABET ) - 1;
	for ( $i = 0; $i < 12; $i++ ) { $out .= D5B_CONFIRM_ALPHABET[ random_int( 0, $max ) ]; }
	return $out;
}

/** Issue a code for one object in its current state; a second issue for the same user and object replaces the first. */
function d5b_confirm_issue( $ability, $object_type, $object_id, $fingerprint ) {
	$user = (int) get_current_user_id();
	$code = d5b_confirm_new_code();
	set_transient( d5b_confirm_key( $user, $ability, $object_type, $object_id ), array( 'code_hash' => wp_hash( $code ), 'fingerprint' => (string) $fingerprint, 'issued' => time() ), D5B_CONFIRM_TTL );
	delete_transient( d5b_confirm_used_key( $user, $ability, $object_type, $object_id ) );
	set_transient( d5b_confirm_obj_key( $ability, $object_type, $object_id ), $user, D5B_CONFIRM_TTL );
	$ids = get_transient( d5b_confirm_uat_key( $user, $ability, $object_type ) );
	$ids = is_array( $ids ) ? $ids : array();
	$ids = array_slice( array_values( array_unique( array_merge( $ids, array( (string) $object_id ) ) ) ), -20 );
	set_transient( d5b_confirm_uat_key( $user, $ability, $object_type ), $ids, D5B_CONFIRM_TTL );
	return array(
		'confirmation' => $code,
		'expires_at'   => gmdate( 'c', time() + D5B_CONFIRM_TTL ),
		'message'      => 'Show this preview to the user and wait for their explicit OK before calling the delete.',
	);
}

function d5b_confirm_error( $reason ) {
	$text = array(
		'missing'      => 'No confirmation code was given, or it is not the code from the preview. Run the preview and show it to the user first.',
		'expired'      => 'The confirmation code has expired or was never issued. Run the preview again.',
		'used'         => 'The confirmation code was already used. Run the preview again.',
		'stale'        => 'The object changed since the preview. Run the preview again and show it to the user.',
		'wrong_object' => 'That confirmation code was issued for a different object.',
		'wrong_user'   => 'That confirmation code was issued to a different user.',
	);
	return new WP_Error( 'confirmation_invalid', $text[ $reason ], array( 'reason' => $reason ) );
}

/** True (and the code is consumed), or a confirmation_invalid WP_Error whose data.reason says why. Nothing is deleted here. */
function d5b_confirm_check( $ability, $object_type, $object_id, $fingerprint, $code ) {
	$code = trim( (string) $code );
	if ( '' === $code ) { return d5b_confirm_error( 'missing' ); }
	$user = (int) get_current_user_id();
	$key  = d5b_confirm_key( $user, $ability, $object_type, $object_id );
	$row  = get_transient( $key );
	$hash = wp_hash( $code );
	if ( is_array( $row ) && isset( $row['code_hash'], $row['fingerprint'] ) ) {
		if ( hash_equals( (string) $row['code_hash'], $hash ) ) {
			if ( ! hash_equals( (string) $row['fingerprint'], (string) $fingerprint ) ) { return d5b_confirm_error( 'stale' ); }
			delete_transient( $key );
			set_transient( d5b_confirm_used_key( $user, $ability, $object_type, $object_id ), 1, D5B_CONFIRM_TTL );
			return true;
		}
		// A code is outstanding for this object, but this is not it: is it this user's code for another object?
		return d5b_confirm_other_object( $user, $ability, $object_type, $object_id, $hash ) ? d5b_confirm_error( 'wrong_object' ) : d5b_confirm_error( 'missing' );
	}
	if ( false !== get_transient( d5b_confirm_used_key( $user, $ability, $object_type, $object_id ) ) ) { return d5b_confirm_error( 'used' ); }
	$issuer = get_transient( d5b_confirm_obj_key( $ability, $object_type, $object_id ) );
	if ( false !== $issuer && (int) $issuer !== $user ) {
		$theirs = get_transient( d5b_confirm_key( (int) $issuer, $ability, $object_type, $object_id ) );
		if ( is_array( $theirs ) && isset( $theirs['code_hash'] ) && hash_equals( (string) $theirs['code_hash'], $hash ) ) { return d5b_confirm_error( 'wrong_user' ); }
	}
	if ( d5b_confirm_other_object( $user, $ability, $object_type, $object_id, $hash ) ) { return d5b_confirm_error( 'wrong_object' ); }
	return d5b_confirm_error( 'expired' );
}

/** Does this hash match this user's outstanding code for another object of the same ability and type? */
function d5b_confirm_other_object( $user, $ability, $type, $id, $hash ) {
	$ids = get_transient( d5b_confirm_uat_key( $user, $ability, $type ) );
	if ( ! is_array( $ids ) ) { return false; }
	foreach ( $ids as $other ) {
		if ( (string) $other === (string) $id ) { continue; }
		$row = get_transient( d5b_confirm_key( $user, $ability, $type, $other ) );
		if ( is_array( $row ) && isset( $row['code_hash'] ) && hash_equals( (string) $row['code_hash'], $hash ) ) { return true; }
	}
	return false;
}

// ---- Fingerprints: a string that changes when the object does.
function d5b_fp_term( $term ) { return $term->name . '|' . $term->slug . '|' . $term->parent . '|' . $term->count; }
function d5b_fp_menu( $menu_id ) {
	$items = wp_get_nav_menu_items( (int) $menu_id, array( 'post_status' => 'any' ) );
	$rows  = array();
	foreach ( is_array( $items ) ? $items : array() as $it ) { $rows[] = (int) $it->ID . '@' . $it->post_modified_gmt; }
	sort( $rows, SORT_STRING );
	return implode( ',', $rows );
}
function d5b_fp_meta( $post_id, $key ) { return sha1( serialize( get_post_meta( (int) $post_id, $key, true ) ) ); }
function d5b_fp_media( $id ) {
	$p = get_post( (int) $id );
	return ( $p ? $p->post_modified_gmt : '' ) . '|' . get_attached_file( (int) $id );
}
