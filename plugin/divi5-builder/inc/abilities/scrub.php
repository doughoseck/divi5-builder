<?php
/**
 * The secret scrubber the run wrapper applies to every ability result (#32). This file only defines functions.
 *
 * It works by KEY: a value stored under a key that names a secret is removed, wherever it sits in the result. It cannot
 * see a secret inside free text, so abilities that return free text (the debug log) mask it themselves.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_ability_scrub_key' ) ) {
	/** True when a key names a secret. */
	function d5b_ability_scrub_key( $key ): bool {
		if ( ! is_string( $key ) ) { return false; }
		if ( in_array( strtolower( $key ), array( 'user_pass', 'user_activation_key', 'session_tokens', '_application_passwords' ), true ) ) { return true; }
		return 1 === preg_match( '/pass(word)?$|secret|api_?key|token|salt|auth_key|nonce/i', $key );
	}
}

if ( ! function_exists( 'd5b_ability_scrub' ) ) {
	/**
	 * @param mixed $value an ability result
	 * @return mixed the same shape without any key that names a secret. Objects become arrays (an object never passes
	 *               through unscrubbed); nesting deeper than 32 levels is replaced by "[too deep]".
	 */
	function d5b_ability_scrub( $value, int $depth = 0 ) {
		if ( is_object( $value ) ) {
			$value = $value instanceof JsonSerializable ? $value->jsonSerialize() : get_object_vars( $value );
			if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
		}
		if ( ! is_array( $value ) ) { return $value; }
		if ( $depth >= 32 ) { return '[too deep]'; }
		$out = array();
		foreach ( $value as $k => $v ) {
			if ( d5b_ability_scrub_key( $k ) ) { continue; }
			$out[ $k ] = d5b_ability_scrub( $v, $depth + 1 );
		}
		return $out;
	}
}
