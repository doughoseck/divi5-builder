<?php
/**
 * Runs when the plugin is deleted (Plugins > Delete), never on deactivation. The loader does not load this file.
 *
 * Removes every option the plugin stored: d5b_switches, d5b_takeover, d5b_legacy_seen and d5b_db_version, and drops the
 * audit log table (d5b_audit), the change history table (d5b_history), its daily event and its d5b_audit_install_failed and d5b_anon transients, on every site of a network. On a multisite network,
 * the network options and each site's own options (sites read in batches of 100). Also removes the
 * d5b_abilities_upgrade_notice and d5b_abilities_consent_220 options and, per site, the folder wp-content/uploads/d5b-history (the files of permanently
 * deleted media, kept 30 days with their history rows). Only deletes, never writes, so a later reinstall starts with every
 * switch off, as on a new site. Nothing else is touched: the old plugins' files and the media clean-up quarantine folder
 * stay where they are.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

/**
 * Removes the current site's wp-content/uploads/d5b-history: its .htaccess and index.html, and each <history id>/ folder with
 * the files in it. Two levels only, as the plugin makes it; a link is removed, never followed.
 */
$d5b_rm_history_files = function () {
	if ( ! function_exists( 'wp_upload_dir' ) ) { return; }
	$u    = wp_upload_dir( null, false );
	$base = is_array( $u ) && is_string( $u['basedir'] ?? null ) ? rtrim( str_replace( '\\', '/', $u['basedir'] ), '/' ) : '';
	if ( '' === $base ) { return; }
	$root = $base . '/d5b-history';
	if ( is_link( $root ) ) { @unlink( $root ); return; }
	if ( ! is_dir( $root ) ) { return; }
	foreach ( (array) @scandir( $root ) as $f ) {
		if ( ! is_string( $f ) || '.' === $f || '..' === $f ) { continue; }
		$p = $root . '/' . $f;
		if ( is_link( $p ) || is_file( $p ) ) { @unlink( $p ); continue; }
		if ( ! is_dir( $p ) ) { continue; }
		foreach ( (array) @scandir( $p ) as $g ) {
			if ( ! is_string( $g ) || '.' === $g || '..' === $g ) { continue; }
			if ( is_link( $p . '/' . $g ) || is_file( $p . '/' . $g ) ) { @unlink( $p . '/' . $g ); }
		}
		@rmdir( $p );
	}
	@rmdir( $root );
};

$d5b_names = array( 'd5b_switches', 'd5b_takeover', 'd5b_legacy_seen', 'd5b_db_version', 'd5b_abilities_upgrade_notice', 'd5b_abilities_consent_220' );
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
					$d5b_rm_history_files();
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
// The kept media files of a single site (a network's are removed above, site by site, when its tables are).
if ( ! is_multisite() || ! isset( $wpdb ) || ! is_object( $wpdb ) ) { $d5b_rm_history_files(); }
unset( $d5b_names, $d5b_name, $d5b_offset, $d5b_ids, $d5b_id, $d5b_rm_history_files );
