<?php
/**
 * The secret scrubber the run wrapper applies to every ability result and every WP_Error it returns (#32). This file only
 * defines functions; the rule itself is d5b_is_secret_key() in secrets.php.
 *
 * - By KEY: a value stored under a key that names a secret is removed, wherever it sits in the result.
 * - By PAIR: in an array whose name field (key, name, option, option_name, meta_key, id, label, field, setting, title, in any
 *   letter case) names a secret, every value field (value, values, val, data, option_value, meta_value) is removed too.
 *   A two-item list [ name, value ] loses its value, and parallel names/values (or keys/values) lists lose the value at
 *   each index whose name is a secret.
 * - By SHAPE: every string is checked for the few values that are a secret wherever they appear (a private key, a Stripe
 *   secret key, a JSON Web Token, a password hash, a bearer token); only that part becomes [redacted].
 * Free text under an ordinary key ("password=..." in a sentence) is not caught in results; a WP_Error's messages and data
 * go through the free-text masker as well (d5b_ability_scrub_error).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_ability_scrub_pair' ) ) {
	function d5b_ability_scrub_name_fields(): array { return array( 'key', 'name', 'option', 'option_name', 'meta_key', 'id', 'label', 'field', 'setting', 'title' ); }
	function d5b_ability_scrub_value_fields(): array { return array( 'value', 'values', 'val', 'data', 'option_value', 'meta_value' ); }

	/** A name field's value as text: a string, or the first string in an array (one level). */
	function d5b_ability_scrub_name_of( $v ): ?string {
		if ( is_string( $v ) || is_int( $v ) ) { return (string) $v; }
		if ( is_array( $v ) ) { foreach ( $v as $x ) { if ( is_string( $x ) ) { return $x; } } }
		return null;
	}

	/** True when $row holds a name field (any letter case) whose value names a secret. */
	function d5b_ability_scrub_pair( array $row ): bool {
		foreach ( $row as $k => $v ) {
			if ( ! is_string( $k ) || ! in_array( strtolower( $k ), d5b_ability_scrub_name_fields(), true ) ) { continue; }
			$name = d5b_ability_scrub_name_of( $v );
			if ( null !== $name && d5b_is_secret_key( $name ) ) { return true; }
		}
		return false;
	}

	/** Parallel names/values (or keys/values) lists: the value at each index whose name is a secret is dropped. */
	function d5b_ability_scrub_parallel( array $row ): array {
		$names = null; $vkey = null;
		foreach ( $row as $k => $v ) {
			if ( ! is_string( $k ) || ! is_array( $v ) ) { continue; }
			$l = strtolower( $k );
			if ( 'names' === $l || 'keys' === $l ) { $names = $v; }
			if ( 'values' === $l ) { $vkey = $k; }
		}
		if ( null === $names || null === $vkey ) { return $row; }
		foreach ( $names as $i => $n ) {
			if ( is_string( $n ) && d5b_is_secret_key( $n ) ) { unset( $row[ $vkey ][ $i ] ); }
		}
		return $row;
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
		$value = d5b_ability_scrub_parallel( $value );
		$pair  = d5b_ability_scrub_pair( $value );
		// [ name, value ]: a two-item list whose first item names a secret
		$list2 = array( 0, 1 ) === array_keys( $value ) && is_string( $value[0] ) && d5b_is_secret_key( $value[0] );
		$out   = array();
		foreach ( $value as $k => $v ) {
			if ( d5b_is_secret_key( $k ) ) { continue; }
			if ( $pair && is_string( $k ) && in_array( strtolower( $k ), d5b_ability_scrub_value_fields(), true ) ) { continue; }
			if ( $list2 && 1 === $k ) { continue; }
			$out[ $k ] = d5b_ability_scrub( $v, $depth + 1 );
		}
		return $out;
	}
}

if ( ! function_exists( 'd5b_ability_scrub_text' ) ) {
	/** Free text on its way out: the value shapes, then the free-text masker when it is loaded. */
	function d5b_ability_scrub_text( string $s ): string {
		$s = d5b_secret_mask_values( $s );
		return function_exists( 'd5b_audit_mask_secrets' ) ? d5b_audit_mask_secrets( $s ) : $s;
	}

	/** Every string in $v (already key-scrubbed) through d5b_ability_scrub_text, recursively. */
	function d5b_ability_scrub_text_deep( $v, int $depth = 0 ) {
		if ( is_string( $v ) ) { return d5b_ability_scrub_text( $v ); }
		if ( ! is_array( $v ) || $depth >= 32 ) { return $v; }
		foreach ( $v as $k => $x ) { $v[ $k ] = d5b_ability_scrub_text_deep( $x, $depth + 1 ); }
		return $v;
	}
}

if ( ! function_exists( 'd5b_ability_scrub_error' ) ) {
	/** A copy of $e: every message and every string in its data through the free-text masker, the data scrubbed in full. */
	function d5b_ability_scrub_error( WP_Error $e ): WP_Error {
		$out = new WP_Error();
		foreach ( $e->get_error_codes() as $code ) {
			foreach ( $e->get_error_messages( $code ) as $msg ) { $out->add( $code, d5b_ability_scrub_text( (string) $msg ) ); }
			$all = method_exists( $e, 'get_all_error_data' ) ? $e->get_all_error_data( $code ) : array( $e->get_error_data( $code ) );
			foreach ( $all as $data ) {
				if ( null === $data ) { continue; }
				if ( $data instanceof WP_Error ) { $data = d5b_ability_scrub_error( $data ); }
				$out->add_data( d5b_ability_scrub_text_deep( d5b_ability_scrub( $data ) ), $code );
			}
		}
		return $out;
	}
}
