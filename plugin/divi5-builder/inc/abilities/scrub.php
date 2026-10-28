<?php
/**
 * The secret scrubber the run wrapper applies to every ability result and every WP_Error it returns (#32). This file only
 * defines functions; the key rule itself is d5b_is_secret_key() in secrets.php. The rules are heuristics, so they are
 * built to hide when in doubt: a hidden harmless field costs little, a leaked secret a lot.
 *
 * - By KEY: a value stored under a key that names a secret is removed, wherever it sits in the result.
 * - By NAME BESIDE IT, only inside RAW subtrees (data whose keys and labels other plugins chose: post meta, theme mods,
 *   widget settings, option values, a WP_Error's data; each ability names them in its 'raw_paths'): in any array of at
 *   most 8 entries where a direct entry is a key-like string (no spaces) that names a secret ("smtp_password",
 *   "Authorization"), every other string in that array becomes [redacted]. The name is kept so the reader sees the label.
 *   A name one level down (a small array holding it) counts too. Rows our handlers build field by field (posts, terms,
 *   menus, users ...) are never treated this way, so a post titled "Password reset" keeps its fields.
 * - PARALLEL LISTS, also only inside raw subtrees: two or more array entries with the same keys; where one holds a secret
 *   name at an index, that index is [redacted] in the others (names/values, keys/vals, labels/values, whatever they are called).
 * - FREE TEXT: every string goes through the value shapes and the free-text masker (key=value, key: value, headers,
 *   define(), curl -u ...). Post content (the content keys of the post-like abilities) gets the value shapes only, so it
 *   stays editable.
 * Every string changed this way is counted (d5b_secret_masked_count), and the wrapper adds "masked": true.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_ability_scrub_secret_name' ) ) {
	/** True when $v is a string that looks like a key name (letters, digits, _ . -, no spaces, up to 64) and names a secret. A spaced phrase is prose. */
	function d5b_ability_scrub_secret_name( $v ): bool {
		if ( ! is_string( $v ) ) { return false; }
		$t = trim( $v );
		return 1 === preg_match( '/^[A-Za-z0-9_.\-]{1,64}$/', $t ) && d5b_is_secret_key( $t );
	}

	/** True when $path (keys from the result's root) matches one of the dotted specs; '*' matches any one key, '' the root. */
	function d5b_ability_scrub_path_is_raw( array $path, array $raw_paths ): bool {
		foreach ( $raw_paths as $spec ) {
			$parts = '' === $spec ? array() : explode( '.', (string) $spec );
			if ( count( $parts ) !== count( $path ) ) { continue; }
			$hit = true;
			foreach ( $parts as $i => $p ) { if ( '*' !== $p && (string) $path[ $i ] !== $p ) { $hit = false; break; } }
			if ( $hit ) { return true; }
		}
		return false;
	}

	/** True when $v (an array of at most 2 entries: ['label', 'password'], { n: 'password' }) holds a secret name directly. */
	function d5b_ability_scrub_holds_name( $v ): bool {
		if ( is_object( $v ) ) { $v = get_object_vars( $v ); }
		if ( ! is_array( $v ) || count( $v ) > 2 ) { return false; }
		foreach ( $v as $x ) { if ( d5b_ability_scrub_secret_name( $x ) ) { return true; } }
		return false;
	}

	/** True for a list (keys 0..n-1). */
	function d5b_ability_scrub_is_list( array $a ): bool {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/**
	 * True when $row (at most 8 entries) holds a secret name directly, or, in a keyed row, one level down in a tiny array
	 * ({ name: ['label', 'password'] }). A list of rows is never "named" by what its rows hold, so one secret pair in a
	 * list does not redact its neighbours.
	 */
	function d5b_ability_scrub_is_named( array $row ): bool {
		if ( count( $row ) > 8 ) { return false; }
		$keyed = ! d5b_ability_scrub_is_list( $row );
		foreach ( $row as $v ) {
			if ( d5b_ability_scrub_secret_name( $v ) || ( $keyed && d5b_ability_scrub_holds_name( $v ) ) ) { return true; }
		}
		return false;
	}

	/** Every string in $v becomes [redacted] (the value side of a named row, however deep); numbers and booleans stay. */
	function d5b_ability_scrub_redact_all( $v, int $depth = 0 ) {
		if ( is_object( $v ) ) { $v = get_object_vars( $v ); }
		if ( is_string( $v ) ) { if ( '[redacted]' !== $v ) { d5b_ability_scrub_note(); } return '[redacted]'; }
		if ( ! is_array( $v ) ) { return $v; }
		if ( $depth >= 32 ) { return '[too deep]'; }
		$out = array();
		foreach ( $v as $k => $x ) { if ( ! d5b_is_secret_key( $k ) ) { $out[ $k ] = d5b_ability_scrub_redact_all( $x, $depth + 1 ); } }
		return $out;
	}

	/** Counts one changed string for the "masked" flag. */
	function d5b_ability_scrub_note(): void {
		if ( function_exists( 'd5b_secret_masked_count' ) ) { d5b_secret_masked_count( false, 1 ); }
	}

	/**
	 * Parallel lists: array entries with the same keys; an index holding a secret name in one is [redacted] in the others.
	 * Lists count wherever they are. Small keyed arrays (8 entries or fewer) count only inside a keyed parent
	 * ({ names: { a: .. }, values: { a: .. } }), so a list of records (rows with the same fields) is never cross-redacted.
	 */
	function d5b_ability_scrub_parallel( array $row ): array {
		$arrays = array();
		$parent_is_list = d5b_ability_scrub_is_list( $row );
		foreach ( $row as $k => $v ) {
			if ( ! is_array( $v ) || array() === $v ) { continue; }
			$is_list = d5b_ability_scrub_is_list( $v );
			if ( $is_list || ( ! $parent_is_list && count( $v ) <= 8 ) ) { $arrays[ $k ] = $v; }
		}
		if ( count( $arrays ) < 2 ) { return $row; }
		foreach ( $arrays as $src => $list ) {
			foreach ( $list as $i => $name ) {
				if ( ! d5b_ability_scrub_secret_name( $name ) ) { continue; }
				foreach ( $arrays as $dst => $other ) {
					if ( $dst === $src || array_keys( $other ) !== array_keys( $list ) ) { continue; }
					if ( d5b_ability_scrub_secret_name( $row[ $dst ][ $i ] ) || '[redacted]' === $row[ $dst ][ $i ] ) { continue; }
					$row[ $dst ][ $i ] = '[redacted]';
					d5b_ability_scrub_note();
				}
			}
		}
		return $row;
	}
}

if ( ! function_exists( 'd5b_ability_scrub' ) ) {
	/**
	 * @param mixed $value        an ability result
	 * @param array $content_keys keys whose strings are post content (value shapes only), at any depth
	 * @param bool  $content      this value sits under one of those keys
	 * @param array $raw_paths    dotted paths of the raw third-party subtrees (see d5b_ability_scrub_path_is_raw)
	 * @param bool  $raw          this value sits inside a raw subtree (the row and parallel rules apply)
	 * @param array $path         the keys from the root to this value
	 * @return mixed the same shape without any key that names a secret, with secret values redacted and masked. Objects
	 *               become arrays (an object never passes through unscrubbed); nesting deeper than 32 levels is "[too deep]".
	 */
	function d5b_ability_scrub( $value, int $depth = 0, array $content_keys = array(), bool $content = false, array $raw_paths = array(), bool $raw = false, array $path = array() ) {
		if ( is_object( $value ) ) {
			$value = $value instanceof JsonSerializable ? $value->jsonSerialize() : get_object_vars( $value );
			if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
		}
		if ( is_string( $value ) ) {
			$out = $content ? d5b_secret_mask_values( $value ) : d5b_ability_scrub_text( $value );
			if ( $out !== $value ) { d5b_ability_scrub_note(); }
			return $out;
		}
		if ( ! is_array( $value ) ) { return $value; }
		if ( $depth >= 32 ) { return '[too deep]'; }
		$raw   = $raw || ( array() !== $raw_paths && d5b_ability_scrub_path_is_raw( $path, $raw_paths ) );
		if ( $raw ) { $value = d5b_ability_scrub_parallel( $value ); }
		$named = $raw && d5b_ability_scrub_is_named( $value );
		$out   = array();
		foreach ( $value as $k => $v ) {
			if ( d5b_is_secret_key( $k ) ) { continue; }
			if ( $named && ! d5b_ability_scrub_secret_name( $v ) && ! d5b_ability_scrub_holds_name( $v ) && ( is_string( $v ) || is_array( $v ) || is_object( $v ) ) ) {
				// the value side of a named row: every string in it, however deep (the name itself is kept)
				$out[ $k ] = d5b_ability_scrub_redact_all( $v );
				continue;
			}
			// raw third-party data is never post content: inside it every string gets the full masker
			$is_content = ! $raw && ( $content || ( is_string( $k ) && in_array( strtolower( $k ), $content_keys, true ) ) );
			$out[ $k ] = d5b_ability_scrub( $v, $depth + 1, $content_keys, $is_content, $raw_paths, $raw, array_merge( $path, array( $k ) ) );
		}
		return $out;
	}
}

if ( ! function_exists( 'd5b_ability_scrub_raw' ) ) {
	/** The whole value treated as raw third-party data (the row and parallel rules apply everywhere in it). */
	function d5b_ability_scrub_raw( $value ) {
		return d5b_ability_scrub( $value, 0, array(), false, array(), true );
	}
}

if ( ! function_exists( 'd5b_ability_scrub_text' ) ) {
	/** Free text on its way out: the value shapes, then the free-text masker when it is loaded. */
	function d5b_ability_scrub_text( string $s ): string {
		$s = d5b_secret_mask_values( $s );
		return function_exists( 'd5b_audit_mask_secrets' ) ? d5b_audit_mask_secrets( $s ) : $s;
	}
}

if ( ! function_exists( 'd5b_ability_scrub_error' ) ) {
	/**
	 * A copy of $e: every message through the free-text masker, its data scrubbed in full (which masks every string too).
	 * The data is raw: an error's data can come from core or another plugin, so the row rule applies in it.
	 */
	function d5b_ability_scrub_error( WP_Error $e ): WP_Error {
		$out = new WP_Error();
		foreach ( $e->get_error_codes() as $code ) {
			foreach ( $e->get_error_messages( $code ) as $msg ) { $out->add( $code, d5b_ability_scrub_text( (string) $msg ) ); }
			$all = method_exists( $e, 'get_all_error_data' ) ? $e->get_all_error_data( $code ) : array( $e->get_error_data( $code ) );
			foreach ( $all as $data ) {
				if ( null === $data ) { continue; }
				if ( $data instanceof WP_Error ) { $data = d5b_ability_scrub_error( $data ); }
				$out->add_data( d5b_ability_scrub_raw( $data ), $code );
			}
		}
		return $out;
	}
}
