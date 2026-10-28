<?php
/**
 * The secret scrubber the run wrapper applies to every ability result and every WP_Error it returns (#32). This file only
 * defines functions; the rule itself is d5b_is_secret_key() in secrets.php.
 *
 * - By KEY: a value stored under a key that names a secret is removed, wherever it sits in the result.
 * - By PAIR: in an array with a key, name, option or meta_key field that names a secret, the value / meta_value beside it
 *   is removed too ({ "name": "smtp_password", "value": "..." }).
 * - By SHAPE: every string is checked for the few values that are a secret wherever they appear (a private key, a Stripe
 *   secret key, a JSON Web Token, a password hash, a bearer token); only that part becomes [redacted].
 * Free text under an ordinary key ("password=..." in a sentence) is not caught here; the debug log masks its own lines.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_ability_scrub_pair' ) ) {
	/** True when $row holds a key/name/option/meta_key field whose value names a secret. */
	function d5b_ability_scrub_pair( array $row ): bool {
		foreach ( array( 'key', 'name', 'option', 'meta_key' ) as $f ) {
			if ( isset( $row[ $f ] ) && ( is_string( $row[ $f ] ) || is_int( $row[ $f ] ) ) && d5b_is_secret_key( $row[ $f ] ) ) { return true; }
		}
		return false;
	}
}

if ( ! function_exists( 'd5b_ability_scrub' ) ) {
	/**
	 * @param mixed $value an ability result
	 * @return mixed the same shape without any key that names a secret and with secret-shaped values masked. Objects become
	 *               arrays (an object never passes through unscrubbed); nesting deeper than 32 levels is replaced by "[too deep]".
	 */
	function d5b_ability_scrub( $value, int $depth = 0 ) {
		if ( is_object( $value ) ) {
			$value = $value instanceof JsonSerializable ? $value->jsonSerialize() : get_object_vars( $value );
			if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
		}
		if ( is_string( $value ) ) { return d5b_secret_mask_values( $value ); }
		if ( ! is_array( $value ) ) { return $value; }
		if ( $depth >= 32 ) { return '[too deep]'; }
		$pair = d5b_ability_scrub_pair( $value );
		$out  = array();
		foreach ( $value as $k => $v ) {
			if ( d5b_is_secret_key( $k ) ) { continue; }
			if ( $pair && ( 'value' === $k || 'meta_value' === $k ) ) { continue; }
			$out[ $k ] = d5b_ability_scrub( $v, $depth + 1 );
		}
		return $out;
	}
}

if ( ! function_exists( 'd5b_ability_scrub_error' ) ) {
	/** A copy of $e with every message masked by shape and all of its data scrubbed in full. */
	function d5b_ability_scrub_error( WP_Error $e ): WP_Error {
		$out = new WP_Error();
		foreach ( $e->get_error_codes() as $code ) {
			foreach ( $e->get_error_messages( $code ) as $msg ) { $out->add( $code, d5b_secret_mask_values( (string) $msg ) ); }
			$all = method_exists( $e, 'get_all_error_data' ) ? $e->get_all_error_data( $code ) : array( $e->get_error_data( $code ) );
			foreach ( $all as $data ) { if ( null !== $data ) { $out->add_data( d5b_ability_scrub( $data ), $code ); } }
		}
		return $out;
	}
}
