<?php
/**
 * Runs when the plugin is deleted (Plugins > Delete), never on deactivation. The loader does not load this file.
 *
 * Removes every option the plugin stored: d5b_switches, d5b_takeover and d5b_legacy_seen. On a multisite network,
 * the network options and each site's own options (sites read in batches of 100). Only deletes, never writes,
 * so a later reinstall starts with every switch off, as on a new site. Nothing else is touched: the old plugins'
 * files and the media clean-up quarantine folder stay where they are.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

$d5b_names = array( 'd5b_switches', 'd5b_takeover', 'd5b_legacy_seen' );
foreach ( $d5b_names as $d5b_name ) { delete_option( $d5b_name ); }

if ( is_multisite() ) {
	foreach ( $d5b_names as $d5b_name ) { delete_site_option( $d5b_name ); }
	$d5b_offset = 0;
	do {
		$d5b_ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $d5b_offset ) );
		foreach ( $d5b_ids as $d5b_id ) {
			foreach ( $d5b_names as $d5b_name ) { delete_blog_option( (int) $d5b_id, $d5b_name ); }
		}
		$d5b_offset += 100;
	} while ( 100 === count( $d5b_ids ) );
}
unset( $d5b_names, $d5b_name, $d5b_offset, $d5b_ids, $d5b_id );
