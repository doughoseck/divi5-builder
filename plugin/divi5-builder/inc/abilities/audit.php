<?php
/**
 * The audit log: one row for every ability call, kept 30 days. This file only defines functions; the loader wires
 * the hooks (admin_init -> d5b_audit_maybe_install, the daily d5b_audit_prune event, activation and deactivation).
 *
 * The table is {prefix}d5b_audit, one per site. Arguments are stored redacted (secret-looking keys, long strings,
 * a thrown handler's exception text) and capped at 8 KB (1 KB for a refused call). Nothing here throws. The run
 * wrapper writes a row with status 'started' before a handler runs and updates it afterwards; when that first row
 * cannot be written the wrapper refuses the call (fail closed), so no ability runs unaudited.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'D5B_DB_VERSION' ) ) { define( 'D5B_DB_VERSION', '1' ); }

if ( ! function_exists( 'd5b_audit_table' ) ) {
	function d5b_audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'd5b_audit';
	}
}

if ( ! function_exists( 'd5b_audit_table_exists' ) ) {
	function d5b_audit_table_exists(): bool {
		global $wpdb;
		$table = d5b_audit_table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}

if ( ! function_exists( 'd5b_audit_install_state' ) ) {
	/**
	 * Which sites (by table prefix) already tried to create their table during this request. 'tried' records the
	 * current site, 'is_tried' asks, 'reset' forgets all (tests only: a new PHP request starts empty anyway).
	 */
	function d5b_audit_install_state( string $op ): bool {
		static $tried = array();
		global $wpdb;
		$key = is_object( $wpdb ) ? (string) $wpdb->prefix : '';
		if ( 'reset' === $op ) { $tried = array(); return true; }
		if ( 'tried' === $op ) { $tried[ $key ] = true; return true; }
		return isset( $tried[ $key ] );
	}
}

if ( ! function_exists( 'd5b_audit_install_allowed' ) ) {
	/** False when this site already tried in this request, or a failed attempt less than an hour ago left the transient. */
	function d5b_audit_install_allowed(): bool {
		if ( d5b_audit_install_state( 'is_tried' ) ) { return false; }
		return ! ( function_exists( 'get_transient' ) && false !== get_transient( 'd5b_audit_install_failed' ) );
	}
}

if ( ! function_exists( 'd5b_audit_schedule' ) ) {
	/** The daily clean-up event, once per site. */
	function d5b_audit_schedule(): void {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( 'd5b_audit_prune' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'd5b_audit_prune' );
		}
	}
}

if ( ! function_exists( 'd5b_audit_maybe_install' ) ) {
	/** Creates the table when the stored schema version is not the current one, then stores the version. Per site. */
	function d5b_audit_maybe_install(): void {
		d5b_audit_schedule();
		if ( get_option( 'd5b_db_version' ) === D5B_DB_VERSION ) { return; }
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return; }
		if ( ! function_exists( 'dbDelta' ) ) {
			$file = ABSPATH . 'wp-admin/includes/upgrade.php';
			if ( is_readable( $file ) ) { require_once $file; }
		}
		if ( ! function_exists( 'dbDelta' ) || ! d5b_audit_install_allowed() ) { return; }
		d5b_audit_install_state( 'tried' );
		$table   = d5b_audit_table();
		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		dbDelta( "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ability varchar(191) NOT NULL DEFAULT '',
  args longtext NOT NULL,
  status varchar(64) NOT NULL DEFAULT '',
  duration_ms int(11) NOT NULL DEFAULT 0,
  ip varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY ability (ability)
) {$charset};" );
		// Only remember the version when the table is really there, so a failed create is tried again.
		if ( d5b_audit_table_exists() ) {
			update_option( 'd5b_db_version', D5B_DB_VERSION, false );
			if ( function_exists( 'delete_transient' ) ) { delete_transient( 'd5b_audit_install_failed' ); }
		} elseif ( function_exists( 'set_transient' ) ) {
			set_transient( 'd5b_audit_install_failed', 1, 3600 ); // CREATE denied: do not try again for an hour
		}
	}
}

if ( ! function_exists( 'd5b_audit_activate' ) ) {
	function d5b_audit_activate(): void { d5b_audit_maybe_install(); }
}

if ( ! function_exists( 'd5b_audit_deactivate' ) ) {
	function d5b_audit_deactivate(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) { wp_clear_scheduled_hook( 'd5b_audit_prune' ); }
	}
}

if ( ! function_exists( 'd5b_audit_str_len' ) ) {
	function d5b_audit_str_len( string $s ): int { return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s ); }
}
if ( ! function_exists( 'd5b_audit_str_cut' ) ) {
	function d5b_audit_str_cut( string $s, int $n ): string { return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n, 'UTF-8' ) : substr( $s, 0, $n ); }
}

if ( ! function_exists( 'd5b_audit_utf8' ) ) {
	/** $s as valid UTF-8: bad bytes replaced (mb_scrub) or dropped (iconv), never the whole string emptied. */
	function d5b_audit_utf8( string $s ): string {
		if ( 1 === preg_match( '//u', $s ) ) { return $s; }
		if ( function_exists( 'mb_scrub' ) ) { return (string) mb_scrub( $s, 'UTF-8' ); }
		$t = function_exists( 'iconv' ) ? @iconv( 'UTF-8', 'UTF-8//IGNORE', $s ) : false;
		return is_string( $t ) ? $t : (string) preg_replace( '/[\x80-\xFF]/', '?', $s );
	}
}

if ( ! function_exists( 'd5b_audit_mask_after_keys' ) ) {
	/**
	 * Replaces with [redacted] the value that follows each key $pattern finds, when $is_secret says the key names a secret.
	 * $pattern captures the key as (?<k>...) and the start of the value as (?<v>...) inside a lookahead, bounded to at most
	 * 512 characters; d5b_audit_value_end() then finds where a secret value really ends. One match at a time from a moving
	 * offset (never preg_match_all over rest-of-text captures), so memory stays flat and time linear in the input. A value
	 * is never consumed by a non-secret key, so a key hidden inside another key's value is still found.
	 *
	 * @param string $kind 'plain' (a quoted or unquoted value) or 'line' (a print_r value: to the end of the line or the next [key] =>)
	 */
	function d5b_audit_mask_after_keys( string $s, string $pattern, callable $is_secret, ?callable $value_ok = null, string $kind = 'plain' ): string {
		$out = ''; $pos = 0; $off = 0; $n = strlen( $s );
		while ( $off < $n && 1 === preg_match( $pattern, $s, $m, PREG_OFFSET_CAPTURE, $off ) ) {
			$mend = $m[0][1] + strlen( $m[0][0] );
			$v    = $m['v'] ?? null;
			if ( is_array( $v ) && $v[1] >= 0 && '' !== $v[0] && call_user_func( $is_secret, $m['k'][0] ) && ( null === $value_ok || call_user_func( $value_ok, $v[0] ) ) ) {
				$vend = d5b_audit_value_end( $s, $v[1], $kind );
				if ( $v[1] >= $pos ) { $out .= substr( $s, $pos, $v[1] - $pos ) . '[redacted]'; $pos = $vend; }
				$off = max( $vend, $mend, $off + 1 );
			} else {
				$off = max( $mend, $off + 1 );
			}
		}
		return 0 === $pos ? $s : $out . substr( $s, $pos );
	}
}

if ( ! function_exists( 'd5b_audit_value_end' ) ) {
	/** Where the value starting at $start ends: a quoted value at its closing quote (or the line end), else at a separator. */
	function d5b_audit_value_end( string $s, int $start, string $kind ): int {
		$n = strlen( $s );
		if ( 'line' === $kind ) {
			// the first line end or next "[key] =>" after the value's first character: the search stops at whichever comes first
			if ( 1 === preg_match( '/[ \t]+\[[^\]\r\n]{1,100}\][ \t]*=>|[\r\n]/', $s, $mm, PREG_OFFSET_CAPTURE, $start + 1 ) ) { return $mm[0][1]; }
			return $n;
		}
		$q = $s[ $start ];
		if ( '"' === $q || "'" === $q ) {
			for ( $i = $start + 1; $i < $n; $i++ ) {
				$c = $s[ $i ];
				if ( '\\' === $c ) { $i++; continue; }
				if ( $c === $q ) { return $i + 1; }
				if ( "\r" === $c || "\n" === $c ) { return $i; }
			}
			return $n;
		}
		return $start + strcspn( $s, " \t\r\n\f\v&;,\"'", $start );
	}
}

if ( ! function_exists( 'd5b_audit_is_secret' ) ) {
	/** The shared rule (secrets.php); when it is missing every key counts as a secret (fail closed). */
	function d5b_audit_is_secret( $key ): bool {
		return function_exists( 'd5b_is_secret_key' ) ? d5b_is_secret_key( $key ) : true;
	}
}

if ( ! function_exists( 'd5b_audit_is_secret_word' ) ) {
	/** For "word value" with only a space between: the word's LAST token is one of these nouns (password hunter2, API key x, get_key). */
	function d5b_audit_is_secret_word( $word ): bool {
		$tokens = function_exists( 'd5b_secret_key_tokens' ) ? d5b_secret_key_tokens( $word ) : preg_split( '/[^a-z0-9]+/', strtolower( (string) $word ), -1, PREG_SPLIT_NO_EMPTY );
		$last   = is_array( $tokens ) && $tokens ? end( $tokens ) : '';
		// No "bearer": "Bearer <token>" has its own rule, which needs a digit or punctuation in the token.
		return in_array( $last, array( 'pass', 'passwd', 'password', 'passphrase', 'pwd', 'pw', 'secret', 'token', 'tokens', 'key', 'apikey', 'credential', 'credentials', 'creds', 'salt', 'nonce', 'jwt' ), true );
	}
}

if ( ! function_exists( 'd5b_audit_looks_secret' ) ) {
	/**
	 * For the "word value" form only: does the value look like a secret? 6+ characters of [A-Za-z0-9._~+/=!@#$%^&*-]
	 * (so a quoted identifier such as "title" or 'PRIMARY', or meta key _price, never does) and not an ordinary word
	 * ("Token expired", "Invalid nonce for", "password reset", "get_key is deprecated ... instead").
	 */
	function d5b_audit_looks_secret( string $v ): bool {
		if ( 1 !== preg_match( '/^[A-Za-z0-9._~+\/=!@#$%^&*\-]{6,}$/', $v ) ) { return false; }
		// a snake_case identifier (_price, save_post): a name in a message, not a secret
		if ( false !== strpos( $v, '_' ) && 1 === preg_match( '/^_*[a-z]+(?:_+[a-z]+)*_*$/', $v ) ) { return false; }
		$word = strtolower( rtrim( $v, '.,;:!?' ) );
		return ! in_array( $word, array( 'in', 'is', 'to', 'for', 'at', 'on', 'of', 'has', 'was', 'reset', 'expired', 'deprecated', 'instead', 'failed', 'not', 'required',
			'missing', 'invalid', 'link', 'field', 'value', 'should', 'cannot', 'changed', 'updated', 'created', 'removed', 'provided', 'mismatch', 'received',
			'verification', 'validation', 'generated', 'expires', 'length', 'format', 'string', 'number', 'please', 'before', 'after', 'during', 'without',
			'because', 'header', 'parameter', 'argument', 'manager', 'storage', 'option', 'settings', 'exists', 'already', 'rejected', 'accepted', 'refreshed' ), true );
	}
}

if ( ! function_exists( 'd5b_audit_mask_secrets' ) ) {
	/**
	 * Masks secrets in free text (an exception message, a debug log line) with [redacted]. Over-masking is accepted: a
	 * harmless word after "key" or "token" may go too. Order matters: whole-line headers first, then value shapes, then
	 * the key forms (define(), print_r, var_export / JSON / key=value / key: value, and "password hunter2").
	 */
	function d5b_audit_mask_secrets( string $s ): string {
		// At most 256 KB is masked; the rest is cut off and marked, never passed through unmasked.
		$cut = strlen( $s ) > 262144;
		if ( $cut ) { $s = function_exists( 'mb_strcut' ) ? mb_strcut( $s, 0, 262144, 'UTF-8' ) : substr( $s, 0, 262144 ); }
		$s = d5b_audit_mask_text( $s );
		return $cut ? $s . '…[truncated]' : $s;
	}
}

if ( ! function_exists( 'd5b_audit_mask_strict' ) ) {
	/**
	 * The STRICT profile, for the strings our own handlers return (titles, snippets, descriptions, comments, names). Only
	 * the unambiguous forms: key=value (also %3D and =>) where the key is secret by the token and compound rule,
	 * Authorization / Proxy-Authorization header values, Bearer and Basic credentials, URL user:pass@, define('NAME', ...),
	 * -u user:pass, and the value shapes. Never "word value" or "key: value", so prose stays as written. Capped like the
	 * FULL masker (d5b_audit_mask_secrets), which raw data, error messages, the audit log and the debug log keep using.
	 */
	function d5b_audit_mask_strict( string $s ): string {
		$cut = strlen( $s ) > 262144;
		if ( $cut ) { $s = function_exists( 'mb_strcut' ) ? mb_strcut( $s, 0, 262144, 'UTF-8' ) : substr( $s, 0, 262144 ); }
		$value = '(?<v>"(?:[^"\\\r\n]|\\.){0,512}|\'(?:[^\'\\\r\n]|\\.){0,512}|[^\s&;,"\']{1,512})';
		$key   = '(?<![A-Za-z0-9_.\-])(?<k>[A-Za-z0-9_.\-]{1,100})';
		$s = (string) preg_replace( '#(\b[a-z][a-z0-9+.\-]*://)[^/\s:@]*:[^\s/]*@#i', '$1[redacted]@', $s );
		$s = (string) preg_replace( '/\b((?:Proxy-)?Authorization)\s*:[^\r\n]*/i', '$1: [redacted]', $s );
		$s = (string) preg_replace( '/\bBearer\s+(?=[A-Za-z0-9\-._~+\/]*[0-9._\-~+\/])[A-Za-z0-9\-._~+\/]{8,}=*/i', 'Bearer [redacted]', $s );
		$s = (string) preg_replace( '/\bBasic\s+[A-Za-z0-9+\/]{8,}=*/', 'Basic [redacted]', $s );
		$s = (string) preg_replace( '/(?<![A-Za-z0-9_\-])(?:-u|--user)(?:\s+|=)[^\s:]+:\K[^\s]+/', '[redacted]', $s );
		if ( function_exists( 'd5b_secret_mask_values' ) ) { $s = d5b_secret_mask_values( $s ); }
		$s = d5b_audit_mask_after_keys( $s, '/\bdefine\s*\(\s*(["\'])(?<k>[A-Za-z0-9_]{1,100})\1\s*,\s*(?=' . $value . ')/i', 'd5b_audit_is_secret' );
		$s = d5b_audit_mask_after_keys( $s, '/[\["\']?' . $key . '["\'\]]?\s*(?:=>|=|%3D)\s*(?=' . $value . ')/i', 'd5b_audit_is_strict_key' );
		return $cut ? $s . '…[truncated]' : $s;
	}
}

if ( ! function_exists( 'd5b_audit_is_strict_key' ) ) {
	/** The STRICT key test (secrets.php); fails closed to the full rule when it is missing. */
	function d5b_audit_is_strict_key( $key ): bool {
		return function_exists( 'd5b_secret_is_strict_key' ) ? d5b_secret_is_strict_key( $key ) : d5b_audit_is_secret( $key );
	}
}
if ( ! function_exists( 'd5b_audit_mask_text' ) ) {
	/** The masking itself (d5b_audit_mask_secrets caps the size first). Every value capture is bounded to 512 characters. */
	function d5b_audit_mask_text( string $s ): string {
		$value = '(?<v>"(?:[^"\\\\\r\n]|\\\\.){0,512}|\'(?:[^\'\\\\\r\n]|\\\\.){0,512}|[^\s&;,"\']{1,512})';
		$key   = '(?<![A-Za-z0-9_.\-])(?<k>[A-Za-z0-9_.\-]{1,100})';
		// scheme://user:pass@host: everything up to the LAST @ before the path (a password may hold an @)
		$s = (string) preg_replace( '#(\b[a-z][a-z0-9+.\-]*://)[^/\s:@]*:[^\s/]*@#i', '$1[redacted]@', $s );
		// whole header lines
		$s = (string) preg_replace( '/\b(Set-Cookie|Cookie)\s*:[^\r\n]*/i', '$1: [redacted]', $s );
		$s = (string) preg_replace( '/\bAuthorization\s*:[^\r\n]*/i', 'Authorization: [redacted]', $s );
		$s = (string) preg_replace( '/\bBearer\s+(?=[A-Za-z0-9\-._~+\/]*[0-9._\-~+\/])[A-Za-z0-9\-._~+\/]{8,}=*/i', 'Bearer [redacted]', $s );
		$s = (string) preg_replace( '/\bBasic\s+[A-Za-z0-9+\/]{8,}=*/', 'Basic [redacted]', $s );
		// curl -u user:pass, --user user:pass
		$s = (string) preg_replace( '/(?<![A-Za-z0-9_\-])(?:-u|--user)(?:\s+|=)[^\s:]+:\K[^\s]+/', '[redacted]', $s );
		// value shapes: the shared ones, a JWT with any signature, an AWS access key id
		if ( function_exists( 'd5b_secret_mask_values' ) ) { $s = d5b_secret_mask_values( $s ); }
		$s = (string) preg_replace( '/(?<![A-Za-z0-9_-])eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}(?:\.[A-Za-z0-9_-]*)?/', '[redacted]', $s );
		$s = (string) preg_replace( '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/', '[redacted]', $s );
		// define( 'DB_PASSWORD', '...' )
		$s = d5b_audit_mask_after_keys( $s, '/\bdefine\s*\(\s*(["\'])(?<k>[A-Za-z0-9_]{1,100})\1\s*,\s*(?=' . $value . ')/i', 'd5b_audit_is_secret' );
		// print_r: [user_pass] => value (to the end of the line or the next [key] =>)
		$s = d5b_audit_mask_after_keys( $s, '/\[(?<k>[^\]\r\n]{1,100})\]\s*=>[ \t]*(?=(?<v>[^\r\n]{1,512}))/', 'd5b_audit_is_secret', null, 'line' );
		// key=value, key: value, key%3Dvalue, 'key' => 'value', "key": "value", key -> value, [PHP_AUTH_PW] = value: any value
		$s = d5b_audit_mask_after_keys( $s, '/[\["\']?' . $key . '["\'\]]?\s*(?:=>|->|=|:|%3D)\s*(?=' . $value . ')/i', 'd5b_audit_is_secret' );
		// password hunter2, token abc, password is hunter2, API key is x: only when the value looks like a secret
		$s = d5b_audit_mask_after_keys( $s, '/' . $key . '(?:\s+(?:is|was))?\s+(?![=:>\-])(?=' . $value . ')/i', 'd5b_audit_is_secret_word', 'd5b_audit_looks_secret' );
		return $s;
	}
}

if ( ! function_exists( 'd5b_audit_redact' ) ) {
	/**
	 * @param array $args the call's input (plus _exception on a thrown handler)
	 * @return array the same shape with secrets removed: values under keys that d5b_is_secret_key() names become
	 *               "[redacted]" (the same rule the result scrubber uses); secret value shapes in strings are masked;
	 *               strings over 1024 characters are cut and end with "…[truncated]"; _exception is masked and held to
	 *               300 characters in all; nested arrays are walked (to 12 levels).
	 */
	function d5b_audit_redact( array $args, int $depth = 0 ): array {
		$out = array();
		foreach ( $args as $k => $v ) {
			if ( '_exception' !== $k && d5b_audit_is_secret( $k ) ) { $out[ $k ] = '[redacted]'; continue; }
			if ( '_exception' === $k && is_string( $v ) ) {
				$m = d5b_audit_mask_secrets( d5b_audit_str_cut( $v, 4000 ) );
				if ( d5b_audit_str_len( $m ) > 300 ) { $m = d5b_audit_str_cut( $m, 300 - d5b_audit_str_len( '…[truncated]' ) ) . '…[truncated]'; }
				$out[ $k ] = $m;
				continue;
			}
			if ( is_array( $v ) ) { $out[ $k ] = $depth >= 12 ? '[too deep]' : d5b_audit_redact( $v, $depth + 1 ); continue; }
			if ( is_string( $v ) ) {
				if ( function_exists( 'd5b_secret_mask_values' ) ) { $v = d5b_secret_mask_values( $v ); }
				$out[ $k ] = d5b_audit_str_len( $v ) > 1024 ? d5b_audit_str_cut( $v, 1024 ) . '…[truncated]' : $v;
				continue;
			}
			$out[ $k ] = ( null === $v || is_scalar( $v ) ) ? $v : '[' . gettype( $v ) . ']';
		}
		return $out;
	}
}

if ( ! function_exists( 'd5b_audit_args_cap' ) ) {
	/** Bytes of encoded args a row may hold: 1 KB for a refused call (forbidden, bad input), which damps flooding; else 8 KB. */
	function d5b_audit_args_cap( string $status ): int {
		return in_array( $status, array( 'forbidden', 'invalid_input', 'error:invalid_input' ), true ) ? 1024 : 8192;
	}
}

if ( ! function_exists( 'd5b_audit_args_json' ) ) {
	/** The args column: redacted JSON, or a small valid JSON placeholder when it is over the cap for $status. */
	function d5b_audit_args_json( array $args, string $status ): string {
		$json = json_encode( d5b_audit_redact( $args ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( ! is_string( $json ) ) { $json = '{"_unencodable":true}'; }
		if ( strlen( $json ) > d5b_audit_args_cap( $status ) ) { $json = '{"_truncated":true,"bytes":' . strlen( $json ) . '}'; }
		return $json;
	}
}

if ( ! function_exists( 'd5b_audit_update' ) ) {
	/**
	 * Puts the final status and duration on a row written earlier (status 'started'). With $args the args column is
	 * written again too (a thrown handler's _exception, or the smaller cap of a refusal). Never throws.
	 * @return bool true when the row was changed
	 */
	function d5b_audit_update( int $id, string $status, int $duration_ms, ?array $args = null ): bool {
		try {
			global $wpdb;
			if ( $id < 1 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return false; }
			$status = d5b_audit_str_cut( d5b_audit_utf8( $status ), 64 );
			$data   = array( 'status' => $status, 'duration_ms' => max( 0, $duration_ms ) );
			$fmt    = array( '%s', '%d' );
			if ( null !== $args ) { $data['args'] = d5b_audit_args_json( $args, $status ); $fmt[] = '%s'; }
			$quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			try {
				$n = $wpdb->update( d5b_audit_table(), $data, array( 'id' => $id ), $fmt, array( '%d' ) );
			} finally {
				if ( null !== $quiet ) { $wpdb->suppress_errors( $quiet ); }
			}
			return is_int( $n ) && $n > 0;
		} catch ( Throwable $e ) {
			return false;
		}
	}
}

if ( ! function_exists( 'd5b_audit_write' ) ) {
	/**
	 * One row. Never throws.
	 * @return int the new row's id; 0 when nothing was written (no table, no database, a database error)
	 */
	function d5b_audit_write( string $ability, array $args, string $status, int $duration_ms ): int {
		try {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return 0; }
			$json = d5b_audit_args_json( $args, $status );
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) { $ip = ''; }
			// A real wpdb::insert drops the whole row on invalid UTF-8; the args are JSON already, so only these two need it.
			$ability = d5b_audit_utf8( $ability );
			$status  = d5b_audit_utf8( $status );
			$row = array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'ability'     => d5b_audit_str_cut( $ability, 191 ),
				'args'        => $json,
				'status'      => d5b_audit_str_cut( $status, 64 ),
				'duration_ms' => max( 0, $duration_ms ),
				'ip'          => substr( $ip, 0, 45 ),
			);
			$quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			$fmt   = array( '%s', '%d', '%s', '%s', '%s', '%d', '%s' );
			try {
				$done = $wpdb->insert( d5b_audit_table(), $row, $fmt );
				if ( ( false === $done || 0 === $done ) && d5b_audit_install_allowed() && ! d5b_audit_table_exists() ) {
					// The table is gone (dropped by hand, or this site never ran admin_init): make it again and try once more.
					// Bounded: once per request and site, and not at all for an hour after a failed attempt.
					delete_option( 'd5b_db_version' );
					d5b_audit_maybe_install();
					$done = $wpdb->insert( d5b_audit_table(), $row, $fmt );
				}
			} finally {
				if ( null !== $quiet ) { $wpdb->suppress_errors( $quiet ); }
			}
			if ( false === $done || 0 === $done ) { return 0; }
			return max( 0, (int) $wpdb->insert_id );
		} catch ( Throwable $e ) {
			return 0;
		}
	}
}

if ( ! function_exists( 'd5b_audit_prune' ) ) {
	/** Deletes rows older than 30 days. @return int rows deleted */
	function d5b_audit_prune(): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return 0; }
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 );
		$quiet  = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$n      = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . d5b_audit_table() . ' WHERE created_at < %s', $cutoff ) );
		if ( null !== $quiet ) { $wpdb->suppress_errors( $quiet ); }
		return is_int( $n ) ? $n : 0;
	}
}

if ( ! function_exists( 'd5b_audit_list' ) ) {
	/**
	 * Newest first. Filters (all optional): ability (exact name), user_id, status, since ('Y-m-d H:i:s' UTC).
	 * @return array<int, array{id:int,created_at:string,user_id:int,ability:string,args:string,status:string,duration_ms:int,ip:string}>
	 *         args is the stored JSON text.
	 */
	function d5b_audit_list( array $filters, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return array(); }
		$where = array();
		$vals  = array();
		if ( isset( $filters['ability'] ) && is_string( $filters['ability'] ) && '' !== $filters['ability'] ) { $where[] = 'ability = %s'; $vals[] = $filters['ability']; }
		if ( isset( $filters['user_id'] ) && (int) $filters['user_id'] > 0 ) { $where[] = 'user_id = %d'; $vals[] = (int) $filters['user_id']; }
		if ( isset( $filters['status'] ) && is_string( $filters['status'] ) && '' !== $filters['status'] ) { $where[] = 'status = %s'; $vals[] = $filters['status']; }
		if ( isset( $filters['since'] ) && is_string( $filters['since'] ) && '' !== $filters['since'] ) { $where[] = 'created_at >= %s'; $vals[] = $filters['since']; }
		$sql    = 'SELECT id, created_at, user_id, ability, args, status, duration_ms, ip FROM ' . d5b_audit_table()
			. ( array() === $where ? '' : ' WHERE ' . implode( ' AND ', $where ) ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$vals[] = max( 1, min( 500, $limit ) );
		$vals[] = max( 0, $offset );
		$quiet  = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );
		if ( null !== $quiet ) { $wpdb->suppress_errors( $quiet ); }
		if ( ! is_array( $rows ) ) { return array(); }
		foreach ( $rows as &$r ) {
			$r['id'] = (int) $r['id']; $r['user_id'] = (int) $r['user_id']; $r['duration_ms'] = (int) $r['duration_ms'];
		}
		unset( $r );
		return $rows;
	}
}
