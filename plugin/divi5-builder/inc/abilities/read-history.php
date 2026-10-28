<?php
/**
 * Read ability for this plugin's own activity log (#32, release 2.1.0, part 4): history-audit-list. Uses the helpers of
 * read-content.php (d5b_rc_*) and the audit reader d5b_audit_list() of audit.php. Only the administrator sees it
 * (manage_options). The rows were redacted when they were written; the run wrapper masks them again on the way out.
 *
 * This file only defines functions. The definition is added to the registry when the file is included.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_hs_h_audit_list' ) ) {

	/** True for a real calendar day written YYYY-MM-DD. */
	function d5b_hs_is_day( $v ): bool {
		return is_string( $v ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	function d5b_hs_h_audit_list( array $in ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new WP_Error( 'forbidden', 'You do not have permission to use this ability.' ); }
		if ( isset( $in['ability'] ) && is_string( $in['ability'] ) && strlen( $in['ability'] ) > 191 ) { return new WP_Error( 'invalid_input', 'ability must be text of at most 191 characters.' ); }
		$a = d5b_rc_args( $in, array( 'ability' => 'str', 'user' => 'id', 'since' => 'str', 'per_page' => 'int', 'page' => 'int' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		if ( isset( $a['since'] ) && ! d5b_hs_is_day( $a['since'] ) ) { return new WP_Error( 'invalid_input', 'since must be a day written YYYY-MM-DD (UTC).' ); }
		if ( ! function_exists( 'd5b_audit_list' ) ) { return new WP_Error( 'unavailable', 'The activity log is not loaded on this site.' ); }
		list( $per, $page ) = d5b_rc_paging( $a );
		$filters = array();
		if ( isset( $a['ability'] ) ) { $filters['ability'] = $a['ability']; }
		if ( isset( $a['user'] ) ) { $filters['user_id'] = $a['user']; }
		if ( isset( $a['since'] ) ) { $filters['since'] = $a['since'] . ' 00:00:00'; }
		// One row more than asked for tells whether there is a next page, without a COUNT over the log.
		$rows  = d5b_audit_list( $filters, $per + 1, ( $page - 1 ) * $per );
		$more  = count( $rows ) > $per;
		$items = array();
		foreach ( array_slice( $rows, 0, $per ) as $r ) {
			$items[] = array(
				'id'          => (int) $r['id'],
				'time_utc'    => (string) $r['created_at'],
				'user_id'     => (int) $r['user_id'],
				'ability'     => (string) $r['ability'],
				'args'        => (string) $r['args'],
				'status'      => (string) $r['status'],
				'duration_ms' => (int) $r['duration_ms'],
				'ip'          => (string) $r['ip'],
			);
		}
		return array( 'items' => $items, 'page' => $page, 'per_page' => $per, 'has_more' => $more );
	}

	d5b_rc_def( 'history-audit-list', 'history', 'List the activity log',
		'Returns one page of this plugin\'s activity log, newest first: when, which user, which ability, the arguments it was given (secrets already removed), the outcome and how long it took. On a network it reads the current site\'s log only. Refuses a caller who is not an administrator (manage_options).',
		array(
			'ability' => array( 'type' => 'string', 'maxLength' => 191, 'description' => 'Only rows of this ability, by its full name, for example divi5-builder/posts-get.' ),
			'user'    => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Only rows of this user id.' ),
			'since'   => array( 'type' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$', 'description' => 'Only rows from the start of this day on, written YYYY-MM-DD, in UTC.' ),
		) + d5b_rc_paging_props(),
		array(), 'd5b_hs_h_audit_list', function ( array $in ) { return current_user_can( 'manage_options' ); } );
}
