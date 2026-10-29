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

if ( ! function_exists( 'd5b_history_side_json' ) ) {
	/**
	 * One side (before or after) as JSON: secret-named keys removed (no text masking), or {"too_large":true,"bytes":N} when the
	 * encoded text is over 8 MB, or {"unencodable":true} when it cannot be encoded at all.
	 */
	function d5b_history_side_json( $side ): string {
		$clean = function_exists( 'd5b_ability_scrub_keys' ) ? d5b_ability_scrub_keys( $side ) : array();
		$json  = json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( ! is_string( $json ) ) { return '{"unencodable":true}'; }
		if ( strlen( $json ) > 8 * 1024 * 1024 ) { return '{"too_large":true,"bytes":' . strlen( $json ) . '}'; }
		return $json;
	}
}

if ( ! function_exists( 'd5b_history_write' ) ) {
	/**
	 * One history row. $row keys: ability, object_type, object_id, action, before (array), after (array), revision_id, summary.
	 * The user, the time and the audit row of the running call are filled in here. Never throws.
	 * @return int the new row's id; 0 when nothing was written (no table, no database, a database error)
	 */
	function d5b_history_write( array $row ): int {
		try {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return 0; }
			$rev  = isset( $row['revision_id'] ) ? (int) $row['revision_id'] : 0;
			$data = array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'ability'     => d5b_audit_str_cut( d5b_audit_utf8( (string) ( $row['ability'] ?? '' ) ), 191 ),
				'audit_id'    => function_exists( 'd5b_ability_audit_current_id' ) ? d5b_ability_audit_current_id() : 0,
				'object_type' => d5b_audit_str_cut( d5b_audit_utf8( (string) ( $row['object_type'] ?? '' ) ), 64 ),
				'object_id'   => max( 0, (int) ( $row['object_id'] ?? 0 ) ),
				'action'      => d5b_audit_str_cut( d5b_audit_utf8( (string) ( $row['action'] ?? '' ) ), 16 ),
				'before'      => d5b_history_side_json( $row['before'] ?? array() ),
				'after'       => d5b_history_side_json( $row['after'] ?? array() ),
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
