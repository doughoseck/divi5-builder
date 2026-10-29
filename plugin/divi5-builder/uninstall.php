<?php
/**
 * Runs when the plugin is deleted (Plugins > Delete), never on deactivation. The loader does not load this file.
 *
 * Removes every option the plugin stored: d5b_switches, d5b_takeover, d5b_legacy_seen and d5b_db_version, and drops the
 * audit log table (d5b_audit), the change history table (d5b_history), its daily event and its d5b_audit_install_failed and d5b_anon transients, on every site of a network. On a multisite network,
 * the network options and each site's own options (sites read in batches of 100). Only deletes, never writes,
 * so a later reinstall starts with every switch off, as on a new site. Nothing else is touched: the old plugins'
 * files and the media clean-up quarantine folder stay where they are.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

$d5b_names = array( 'd5b_switches', 'd5b_takeover', 'd5b_legacy_seen', 'd5b_db_version' );
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

// The audit log: its table on every site (a network has one per site) and the daily clean-up event.
global $wpdb;
if ( isset( $wpdb ) && is_object( $wpdb ) ) {
	if ( is_multisite() ) {
		$d5b_offset = 0;
		do {
			$d5b_ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $d5b_offset ) );
			foreach ( $d5b_ids as $d5b_id ) {
				$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->get_blog_prefix( (int) $d5b_id ) . 'd5b_audit`' );
				$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->get_blog_prefix( (int) $d5b_id ) . 'd5b_history`' );
				if ( function_exists( 'switch_to_blog' ) ) {
					switch_to_blog( (int) $d5b_id );
					wp_clear_scheduled_hook( 'd5b_audit_prune' );
					delete_transient( 'd5b_audit_install_failed' );
					delete_transient( 'd5b_anon' );
					restore_current_blog();
				}
			}
			$d5b_offset += 100;
		} while ( 100 === count( $d5b_ids ) );
	} else {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'd5b_audit`' );
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'd5b_history`' );
		wp_clear_scheduled_hook( 'd5b_audit_prune' );
		delete_transient( 'd5b_audit_install_failed' );
		delete_transient( 'd5b_anon' );
	}
}
unset( $d5b_names, $d5b_name, $d5b_offset, $d5b_ids, $d5b_id );
