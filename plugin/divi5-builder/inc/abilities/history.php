<?php
/**
 * The change history: one row for every change a write ability makes, kept 30 days. This file only defines functions; the
 * loader wires the daily prune (d5b_audit_prune also prunes this table) and the install (d5b_audit_maybe_install creates
 * the table, together with the audit table).
 *
 * The table is {prefix}d5b_history, one per site. A row holds what the object looked like before and after the change
 * (JSON, with every secret-named key removed and nothing else altered, so the content can be restored), the audit row of the
 * call that made it, and a short summary. A side over 8 MB is not stored; the row says how big it was. Nothing here throws.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_history_table' ) ) {
	function d5b_history_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'd5b_history';
	}
}

if ( ! function_exists( 'd5b_history_table_exists' ) ) {
	function d5b_history_table_exists(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return false; }
		$table = d5b_history_table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}

if ( ! function_exists( 'd5b_history_limit' ) ) {
	/** The size cap of one side of a row, and of the content field on its own: 8 MB of JSON. */
	function d5b_history_limit(): int {
		return 8 * 1024 * 1024;
	}
}

if ( ! function_exists( 'd5b_history_flags' ) ) {
	/**
	 * What the LAST d5b_history_write() had to do to its sides: truncated (a side, or one of its fields, was replaced by a
	 * {"too_large"} marker, or a side could not be encoded at all) and substituted (text that is not valid UTF-8 was stored
	 * with U+FFFD in its place). A row with either flag is not an exact copy. $set replaces the flags (the writer only).
	 */
	function d5b_history_flags( ?array $set = null ): array {
		static $flags = array( 'truncated' => false, 'substituted' => false );
		if ( null !== $set ) { $flags = array( 'truncated' => ! empty( $set['truncated'] ), 'substituted' => ! empty( $set['substituted'] ) ); }
		return $flags;
	}
}

if ( ! function_exists( 'd5b_history_last_was_truncated' ) ) {
	/** True when the last row written had a side or a field replaced by its size marker (or a side it could not encode). */
	function d5b_history_last_was_truncated(): bool {
		return d5b_history_flags()['truncated'];
	}
}

if ( ! function_exists( 'd5b_history_last_was_substituted' ) ) {
	/** True when the last row written holds text whose invalid UTF-8 bytes were replaced (the copy is not byte-exact). */
	function d5b_history_last_was_substituted(): bool {
		return d5b_history_flags()['substituted'];
	}
}

if ( ! function_exists( 'd5b_history_encode' ) ) {
	/**
	 * JSON of $v; text that is not valid UTF-8 is encoded with U+FFFD in its place and $substituted set. null when it cannot
	 * be encoded at all (a value nested too deep, a float that is not finite).
	 */
	function d5b_history_encode( $v, bool &$substituted = false ): ?string {
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		$json  = json_encode( $v, $flags );
		if ( is_string( $json ) ) { return $json; }
		if ( JSON_ERROR_UTF8 !== json_last_error() && JSON_ERROR_UTF16 !== json_last_error() ) { return null; }
		$json = json_encode( $v, $flags | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! is_string( $json ) ) { return null; }
		$substituted = true;
		return $json;
	}
}

if ( ! function_exists( 'd5b_history_marker' ) ) {
	function d5b_history_marker( int $bytes ): array {
		return array( 'too_large' => true, 'bytes' => $bytes );
	}
}

if ( ! function_exists( 'd5b_history_fit' ) ) {
	/**
	 * A side over the cap, made to fit field by field: "content" is kept whole (it is checked on its own, up to the cap), and
	 * the other fields are replaced one at a time, the largest first, by {"too_large":true,"bytes":N} until the rest of the
	 * side is within the cap. A field is a top-level value, or one row of a key => rows map (a custom field's rows: one
	 * oversized meta value goes, its siblings stay). null when it cannot fit (content over the cap, or not an object).
	 */
	function d5b_history_fit( $side ): ?array {
		$limit = d5b_history_limit();
		if ( ! is_array( $side ) || array() === $side || array_keys( $side ) === range( 0, count( $side ) - 1 ) ) { return null; }
		$sub = false;
		if ( array_key_exists( 'content', $side ) ) {
			$c = d5b_history_encode( $side['content'], $sub );
			if ( null === $c || strlen( $c ) > $limit ) { return null; }
		}
		$units = array(); // array( bytes, top key, row key or null, row index or null )
		foreach ( $side as $k => $v ) {
			if ( 'content' === $k ) { continue; }
			$rows = is_array( $v ) && array() !== $v;
			if ( $rows ) { foreach ( $v as $x ) { if ( ! is_array( $x ) || array() === $x || array_keys( $x ) !== range( 0, count( $x ) - 1 ) ) { $rows = false; break; } } }
			if ( $rows ) {
				foreach ( $v as $rk => $list ) { foreach ( $list as $i => $one ) { $units[] = array( strlen( (string) d5b_history_encode( $one, $sub ) ), $k, $rk, $i ); } }
			} else {
				$units[] = array( strlen( (string) d5b_history_encode( $v, $sub ) ), $k, null, null );
			}
		}
		usort( $units, function ( $a, $b ) { return $b[0] <=> $a[0]; } );
		$rest = $side;
		unset( $rest['content'] );
		$size = strlen( (string) d5b_history_encode( $rest, $sub ) );
		foreach ( $units as $u ) {
			if ( $size <= $limit ) { break; }
			$mark = d5b_history_marker( $u[0] );
			if ( null === $u[2] ) { $side[ $u[1] ] = $mark; } else { $side[ $u[1] ][ $u[2] ][ $u[3] ] = $mark; }
			// The value's JSON is replaced by the marker's in place: the rest of the text does not change.
			$size += strlen( (string) json_encode( $mark ) ) - $u[0];
		}
		$rest = $side;
		unset( $rest['content'] );
		$json = d5b_history_encode( $rest, $sub );
		return ( null === $json || strlen( $json ) > $limit ) ? null : $side;
	}
}

if ( ! function_exists( 'd5b_history_side_json' ) ) {
	/**
	 * One side (before or after) as JSON: secret-named keys removed (no text masking). Over 8 MB, the fields are replaced one at
	 * a time by their size marker (d5b_history_fit: the content is kept whole); when that cannot make it fit, the whole side is
	 * {"too_large":true,"bytes":N}; {"unencodable":true} when it cannot be encoded at all. Text that is not valid UTF-8 is kept
	 * with U+FFFD in its place and the side gets "_utf8_substituted": true. $flags reports what was done (d5b_history_flags).
	 */
	function d5b_history_side_json( $side, ?array &$flags = null ): string {
		$flags = array( 'truncated' => false, 'substituted' => false );
		$clean = function_exists( 'd5b_ability_scrub_keys' ) ? d5b_ability_scrub_keys( $side ) : array();
		$sub   = false;
		$json  = d5b_history_encode( $clean, $sub );
		if ( null === $json ) { $flags['truncated'] = true; return '{"unencodable":true}'; }
		if ( strlen( $json ) > d5b_history_limit() ) {
			$flags['truncated'] = true;
			$fit = d5b_history_fit( $clean );
			if ( null === $fit ) { return '{"too_large":true,"bytes":' . strlen( $json ) . '}'; }
			$clean = $fit;
			$json  = (string) d5b_history_encode( $clean, $sub );
		}
		if ( $sub ) {
			$flags['substituted'] = true;
			if ( is_array( $clean ) && ( array() === $clean || array_keys( $clean ) !== range( 0, count( $clean ) - 1 ) ) ) {
				$clean['_utf8_substituted'] = true;
				$json = (string) d5b_history_encode( $clean, $sub );
			}
		}
		return $json;
	}
}

if ( ! function_exists( 'd5b_history_delete' ) ) {
	/** Removes one row (a row that turned out not to be a record: a permanent delete it was written for was refused). */
	function d5b_history_delete( int $id ): bool {
		global $wpdb;
		if ( $id < 1 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return false; }
		$quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$n     = $wpdb->delete( d5b_history_table(), array( 'id' => $id ), array( '%d' ) );
		if ( null !== $quiet ) { $wpdb->suppress_errors( $quiet ); }
		return is_int( $n ) && $n > 0;
	}
}

if ( ! function_exists( 'd5b_history_write' ) ) {
	/**
	 * One history row. $row keys: ability, object_type, object_id, action, before (array), after (array), revision_id, summary.
	 * The user, the time and the audit row of the running call are filled in here. Never throws.
	 * @return int the new row's id; 0 when nothing was written (no table, no database, a database error)
	 */
	function d5b_history_write( array $row ): int {
		d5b_history_flags( array() );
		try {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return 0; }
			$rev  = isset( $row['revision_id'] ) ? (int) $row['revision_id'] : 0;
			$fb   = null; $fa = null;
			$bj   = d5b_history_side_json( $row['before'] ?? array(), $fb );
			$aj   = d5b_history_side_json( $row['after'] ?? array(), $fa );
			d5b_history_flags( array( 'truncated' => $fb['truncated'] || $fa['truncated'], 'substituted' => $fb['substituted'] || $fa['substituted'] ) );
			$data = array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'ability'     => d5b_audit_str_cut( d5b_audit_utf8( (string) ( $row['ability'] ?? '' ) ), 191 ),
				'audit_id'    => function_exists( 'd5b_ability_audit_current_id' ) ? d5b_ability_audit_current_id() : 0,
				'object_type' => d5b_audit_str_cut( d5b_audit_utf8( (string) ( $row['object_type'] ?? '' ) ), 64 ),
				'object_id'   => max( 0, (int) ( $row['object_id'] ?? 0 ) ),
				'action'      => d5b_audit_str_cut( d5b_audit_utf8( (string) ( $row['action'] ?? '' ) ), 16 ),
				'before'      => $bj,
				'after'       => $aj,
				'revision_id' => $rev > 0 ? $rev : null,
				'summary'     => d5b_audit_str_cut( d5b_audit_utf8( (string) ( $row['summary'] ?? '' ) ), 191 ),
			);
			$fmt   = array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' );
			$quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			try {
				$done = $wpdb->insert( d5b_history_table(), $data, $fmt );
				if ( ( false === $done || 0 === $done ) && d5b_audit_install_allowed() && ! d5b_history_table_exists() ) {
					// The table is gone: make it again and try once more (bounded like the audit table's repair).
					delete_option( 'd5b_db_version' );
					d5b_audit_maybe_install();
					$done = $wpdb->insert( d5b_history_table(), $data, $fmt );
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

if ( ! function_exists( 'd5b_history_list' ) ) {
	/**
	 * Newest first. Filters (all optional): ability (exact name), user_id, since ('Y-m-d H:i:s' UTC), object_type, object_id.
	 * Rows come without before and after (d5b_history_get has them).
	 * @return array<int, array<string, mixed>>
	 */
	function d5b_history_list( array $filters, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return array(); }
		$where = array();
		$vals  = array();
		if ( isset( $filters['ability'] ) && is_string( $filters['ability'] ) && '' !== $filters['ability'] ) { $where[] = 'ability = %s'; $vals[] = $filters['ability']; }
		if ( isset( $filters['user_id'] ) && (int) $filters['user_id'] > 0 ) { $where[] = 'user_id = %d'; $vals[] = (int) $filters['user_id']; }
		if ( isset( $filters['since'] ) && is_string( $filters['since'] ) && '' !== $filters['since'] ) { $where[] = 'created_at >= %s'; $vals[] = $filters['since']; }
		if ( isset( $filters['object_type'] ) && is_string( $filters['object_type'] ) && '' !== $filters['object_type'] ) { $where[] = 'object_type = %s'; $vals[] = $filters['object_type']; }
		if ( isset( $filters['object_id'] ) && (int) $filters['object_id'] > 0 ) { $where[] = 'object_id = %d'; $vals[] = (int) $filters['object_id']; }
		$sql    = 'SELECT id, created_at, user_id, ability, audit_id, object_type, object_id, action, revision_id, summary FROM ' . d5b_history_table()
			. ( array() === $where ? '' : ' WHERE ' . implode( ' AND ', $where ) ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$vals[] = max( 1, min( 500, $limit ) );
		$vals[] = max( 0, $offset );
		$quiet  = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );
		if ( null !== $quiet ) { $wpdb->suppress_errors( $quiet ); }
		if ( ! is_array( $rows ) ) { return array(); }
		foreach ( $rows as &$r ) { $r = d5b_history_ints( $r ); }
		unset( $r );
		return $rows;
	}
}

if ( ! function_exists( 'd5b_history_ints' ) ) {
	/** The numeric columns of a row as integers (revision_id stays null when it is). */
	function d5b_history_ints( array $r ): array {
		foreach ( array( 'id', 'user_id', 'audit_id', 'object_id' ) as $k ) { if ( isset( $r[ $k ] ) ) { $r[ $k ] = (int) $r[ $k ]; } }
		if ( array_key_exists( 'revision_id', $r ) && null !== $r['revision_id'] ) { $r['revision_id'] = (int) $r['revision_id']; }
		return $r;
	}
}

if ( ! function_exists( 'd5b_history_get' ) ) {
	/** One full row with before and after decoded (arrays; an over-size side comes back as its {too_large,bytes} marker), or null. */
	function d5b_history_get( int $id ): ?array {
		global $wpdb;
		if ( $id < 1 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return null; }
		$quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$r     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . d5b_history_table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( null !== $quiet ) { $wpdb->suppress_errors( $quiet ); }
		if ( ! is_array( $r ) ) { return null; }
		$r = d5b_history_ints( $r );
		foreach ( array( 'before', 'after' ) as $side ) {
			$d = json_decode( (string) ( $r[ $side ] ?? '' ), true );
			$r[ $side ] = is_array( $d ) ? $d : array();
		}
		return $r;
	}
}

if ( ! function_exists( 'd5b_history_prune' ) ) {
	/** Deletes rows older than 30 days. @return int rows deleted */
	function d5b_history_prune(): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return 0; }
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 );
		$quiet  = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$n      = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . d5b_history_table() . ' WHERE created_at < %s', $cutoff ) );
		if ( null !== $quiet ) { $wpdb->suppress_errors( $quiet ); }
		return is_int( $n ) ? $n : 0;
	}
}

if ( ! function_exists( 'd5b_history_notice_needed' ) ) {
	/** True when a call finished with status ok_no_history (its change was made, its history row was not) in the last 24 hours. */
	function d5b_history_notice_needed(): bool {
		if ( ! function_exists( 'd5b_audit_list' ) ) { return false; }
		return array() !== d5b_audit_list( array( 'status' => 'ok_no_history', 'since' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ), 1 );
	}
}
