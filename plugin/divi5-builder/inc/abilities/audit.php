<?php
/**
 * The audit log: one row for every ability call, kept 30 days. This file only defines functions; the loader wires
 * the hooks (admin_init -> d5b_audit_maybe_install, the daily d5b_audit_prune event, activation and deactivation).
 *
 * The table is {prefix}d5b_audit, one per site. Arguments are stored redacted (secret-looking keys, long strings,
 * a thrown handler's exception text) and capped at 8 KB. Nothing here throws: a log that cannot be written must
 * never break the call it describes.
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
		if ( ! function_exists( 'dbDelta' ) ) { return; }
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

if ( ! function_exists( 'd5b_audit_mask_secrets' ) ) {
	/** Masks secret-looking substrings of free text (an exception message) with [redacted]. */
	function d5b_audit_mask_secrets( string $s ): string {
		// user:pass@host in a URL
		$s = (string) preg_replace( '#(\b[a-z][a-z0-9+.\-]*://)[^/\s:@]*:[^@\s/]*@#i', '$1[redacted]@', $s );
		// Authorization: <anything to the end of the line>, and a bare Bearer token
		$s = (string) preg_replace( '/\bAuthorization\s*:[^\r\n]*/i', 'Authorization: [redacted]', $s );
		$s = (string) preg_replace( '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [redacted]', $s );
		$s = (string) preg_replace( '/\bBasic\s+[A-Za-z0-9+\/]{8,}=*/', 'Basic [redacted]', $s );
		// password=..., pass=..., token=..., secret=..., key=... (also api_key, a colon, %3D, or a quoted value that holds spaces)
		$s = (string) preg_replace( '/((?:pass(?:word|wd)?|pwd|token|secret|[a-z_\-]*key)["\']?\s*(?:=|:|%3D)\s*)(?:"[^"]*"|\'[^\']*\'|[^\s&;,"\']+)/i', '$1[redacted]', $s );
		return $s;
	}
}

if ( ! function_exists( 'd5b_audit_redact' ) ) {
	/**
	 * @param array $args the call's input (plus _exception on a thrown handler)
	 * @return array the same shape with secrets removed: values under keys matching pass|secret|token|key|auth|cookie|nonce
	 *               become "[redacted]"; strings over 1024 characters are cut and end with "…[truncated]"; _exception is
	 *               masked and held to 300 characters in all; nested arrays are walked (to 12 levels).
	 */
	function d5b_audit_redact( array $args, int $depth = 0 ): array {
		$out = array();
		foreach ( $args as $k => $v ) {
			if ( is_string( $k ) && 1 === preg_match( '/pass|secret|token|key|auth|cookie|nonce/i', $k ) ) { $out[ $k ] = '[redacted]'; continue; }
			if ( '_exception' === $k && is_string( $v ) ) {
				$m = d5b_audit_mask_secrets( d5b_audit_str_cut( $v, 4000 ) );
				if ( d5b_audit_str_len( $m ) > 300 ) { $m = d5b_audit_str_cut( $m, 300 - d5b_audit_str_len( '…[truncated]' ) ) . '…[truncated]'; }
				$out[ $k ] = $m;
				continue;
			}
			if ( is_array( $v ) ) { $out[ $k ] = $depth >= 12 ? '[too deep]' : d5b_audit_redact( $v, $depth + 1 ); continue; }
			if ( is_string( $v ) ) {
				$out[ $k ] = d5b_audit_str_len( $v ) > 1024 ? d5b_audit_str_cut( $v, 1024 ) . '…[truncated]' : $v;
				continue;
			}
			$out[ $k ] = ( null === $v || is_scalar( $v ) ) ? $v : '[' . gettype( $v ) . ']';
		}
		return $out;
	}
}

if ( ! function_exists( 'd5b_audit_write' ) ) {
	/** One row. Never throws; false when nothing was written (no table, no database, a database error). */
	function d5b_audit_write( string $ability, array $args, string $status, int $duration_ms ): bool {
		try {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return false; }
			$json = json_encode( d5b_audit_redact( $args ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
			if ( ! is_string( $json ) ) { $json = '{"_unencodable":true}'; }
			if ( strlen( $json ) > 8192 ) { $json = '{"_truncated":true,"bytes":' . strlen( $json ) . '}'; }
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) { $ip = ''; }
			// A real wpdb::insert drops the whole row on invalid UTF-8; the args are JSON already, so only these two need it.
			$ability = function_exists( 'wp_check_invalid_utf8' ) ? (string) wp_check_invalid_utf8( $ability, true ) : $ability;
			$status  = function_exists( 'wp_check_invalid_utf8' ) ? (string) wp_check_invalid_utf8( $status, true ) : $status;
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
			$done  = $wpdb->insert( d5b_audit_table(), $row, $fmt );
			if ( ( false === $done || 0 === $done ) && ! d5b_audit_table_exists() ) {
				// The table is gone (dropped by hand, or this site never ran admin_init): make it again and try once more.
				delete_option( 'd5b_db_version' );
				d5b_audit_maybe_install();
				$done = $wpdb->insert( d5b_audit_table(), $row, $fmt );
			}
			if ( null !== $quiet ) { $wpdb->suppress_errors( $quiet ); }
			return false !== $done && 0 !== $done;
		} catch ( Throwable $e ) {
			return false;
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
