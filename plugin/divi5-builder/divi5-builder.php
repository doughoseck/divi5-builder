<?php
/**
 * Plugin Name: Divi 5 Builder
 * Description: Build and edit Divi 5 pages over the REST API, plus optional media audit, media clean-up and updates, each behind a switch that is off by default.
 * Version: 2.0.0
 * Author: divi5-builder skill
 * Update URI: https://github.com/doughoseck/divi5-builder
 * License: MIT
 *
 * INSTALL (pick one):
 *   A) Normal plugin: upload divi5-builder.zip in Plugins > Add New, then activate it.
 *   B) Must-use plugin: copy the folder divi5-builder/ into wp-content/mu-plugins/ and, next to it,
 *      the one file divi5-builder-loader.php (it is in plugin/mu-loader/ in the repository).
 *
 * This file only loads the code and adds the hooks. Page building is always on. Media audit, media
 * clean-up and updates each register their routes only while their switch is on (Settings > Divi 5
 * Builder; on a multisite network, Network Admin > Settings), and are off until someone turns them on.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Loaded twice (a must-use copy and a normal copy, or two folders): the first copy wins, the second does nothing.
if ( defined( 'D5B_VERSION' ) ) { return; }

define( 'D5B_VERSION', '2.0.0' );
define( 'D5B_FILE', __FILE__ );

foreach ( array( 'inc', 'parts' ) as $d5b_dir ) {
	$d5b_files = glob( __DIR__ . '/' . $d5b_dir . '/*.php' );
	sort( $d5b_files );
	foreach ( $d5b_files as $d5b_file ) { require_once $d5b_file; }
}
unset( $d5b_dir, $d5b_files, $d5b_file );

// Page building. An old stand-alone copy that is loaded as well already does this on init.
add_action( 'init', function () {
	if ( ! defined( 'D5B_REST_VERSION' ) ) { d5b_init_page_building(); }
} );

// Late, so an old copy's routes are already there to see.
add_action( 'rest_api_init', 'd5b_gate', 99 );

// Taking over from the old plugins. A must-use plugin is never "activated", so it runs on admin_init instead.
register_activation_hook( D5B_FILE, 'd5b_takeover_on_activation' );
add_action( 'admin_init', 'd5b_takeover_mu_admin_init' );

// The settings page. Only people in wp-admin: no admin_post_nopriv_* hook, on purpose.
add_action( 'admin_menu', 'd5b_settings_menu' );
add_action( 'network_admin_menu', 'd5b_settings_menu' );
add_action( 'admin_post_d5b_save_switches', 'd5b_settings_save' );
add_action( 'network_admin_edit_d5b_save_switches', 'd5b_settings_save' );
add_action( 'admin_notices', 'd5b_admin_notice' );
add_action( 'network_admin_notices', 'd5b_admin_notice' );
