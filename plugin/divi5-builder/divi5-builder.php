<?php
/**
 * Plugin Name: Divi 5 Builder
 * Description: Build and edit Divi 5 pages over the REST API, plus optional media audit, media clean-up and updates, each behind a switch that is off by default.
 * Version: 2.5.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: divi5-builder skill
 * Update URI: https://github.com/doughoseck/divi5-builder
 * License: MIT
 *
 * INSTALL (pick one):
 *   A) Normal plugin: upload divi5-builder.zip in Plugins > Add New, then activate it.
 *   B) Must-use plugin: copy the folder divi5-builder/ into wp-content/mu-plugins/ and, next to it,
 *      the one file divi5-builder-loader.php (a file of its own in each release, next to the zip; in the
 *      repository it is plugin/mu-loader/divi5-builder-loader.php).
 *
 * uninstall.php (run by WordPress when the plugin is deleted) removes the options this plugin stored.
 *
 * This file only loads the code and adds the hooks. Page building is always on. Media audit, media
 * clean-up and updates each register their routes only while their switch is on (Settings > Divi 5
 * Builder; on a multisite network, Network Admin > Settings), and are off until someone turns them on.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// Loaded twice (a must-use copy and a normal copy, or two folders): the first copy wins, the second does nothing.
if ( defined( 'D5B_VERSION' ) ) { return; }

define( 'D5B_VERSION', '2.5.0' );
define( 'D5B_FILE', __FILE__ );

// An explicit list, not a directory scan: a file left behind by an older copy (copying over the folder by FTP does not
// delete it) is never loaded, and nothing depends on directory functions working on this server.
foreach ( array(
	'inc/parts.php',
	'inc/switches.php',
	'inc/gate.php',
	'inc/takeover.php',
	'inc/blocks-text-fields.php',
	'inc/blocks.php',
	'inc/abilities/categories.php',
	'inc/abilities/register.php',
	'inc/abilities/secrets.php',
	'inc/abilities/audit.php',
	'inc/abilities/scrub.php',
	'inc/abilities/history.php',
	'inc/abilities/confirm.php',
	'inc/abilities/read-content.php',
	'inc/abilities/read-structure.php',
	'inc/abilities/read-site.php',
	'inc/abilities/read-divi.php',
	'inc/abilities/read-history.php',
	'inc/abilities/write-common.php',
	'inc/abilities/write-posts.php',
	'inc/abilities/write-comments.php',
	'inc/abilities/write-media.php',
	'inc/abilities/write-terms.php',
	'inc/abilities/write-menus.php',
	'inc/abilities/write-divi.php',
	'inc/abilities/write-settings.php',
	'inc/abilities/write-appearance.php',
	'inc/abilities/write-widgets.php',
	'inc/abilities/write-site.php',
	'inc/abilities/write-templates.php',
	'inc/abilities/maint-common.php',
	'inc/abilities/write-plugins.php',
	'inc/abilities/write-themes.php',
	'inc/abilities/write-core.php',
	'inc/abilities/write-users.php',
	'inc/abilities/write-email.php',
	'inc/settings.php',
	'inc/updater.php',
	'parts/page-building.php',
	'parts/media-audit.php',
	'parts/media-clean-up.php',
	'parts/updates.php',
) as $d5b_file ) {
	require_once __DIR__ . '/' . $d5b_file;
}
unset( $d5b_file );

// Page building. An old stand-alone copy that is loaded as well already does this on init.
add_action( 'init', function () {
	if ( ! defined( 'D5B_REST_VERSION' ) ) { d5b_init_page_building(); }
} );

// Late, so an old copy's routes are already there to see.
add_action( 'rest_api_init', 'd5b_gate', 99 );

// 2.4.2: every save of a post that holds Divi 5 blocks drops Divi's per-post canvas cache, which only the builder's own
// save cleared before (a popup canvas linked or edited over REST stayed out of the page until then).
add_action( 'save_post', function ( $post_id, $post = null ) {
	if ( function_exists( 'd5b_forget_divi_canvas_cache_on_save' ) ) { d5b_forget_divi_canvas_cache_on_save( $post_id, $post ); }
}, 20, 2 );

// AI abilities (WordPress 6.9+ Abilities API). Both callbacks do nothing without the API, while the master switch is
// off, or for a category whose switch is off.
add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' );
add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );

// The audit log: the table is made on activation or, for a must-use copy that is never activated, on the next admin page;
// a daily event deletes rows older than 30 days (cleared on deactivation, on every site for a network deactivation;
// uninstall.php drops the table). Before WordPress 6.9 no ability is registered, but the empty table and the event still exist.
register_activation_hook( D5B_FILE, 'd5b_audit_activate' );
register_deactivation_hook( D5B_FILE, 'd5b_audit_deactivate' );
add_action( 'admin_init', 'd5b_audit_maybe_install' );
add_action( 'd5b_audit_prune', 'd5b_audit_prune' );

// Taking over from the old plugins. A must-use plugin is never "activated", so it runs on admin_init instead.
register_activation_hook( D5B_FILE, 'd5b_takeover_on_activation' );
add_action( 'admin_init', 'd5b_takeover_mu_admin_init' );
// An old plugin activated again over REST would bring a switched-off feature back; refuse that (wp-admin is not touched).
add_filter( 'rest_pre_dispatch', 'd5b_refuse_legacy_rest_activation', 10, 3 );

// Updates from GitHub Releases (2.5.0): WordPress asks this filter for our plugin because the Update URI header names
// github.com. The answer comes from plugin/update.json on main, checked strictly, at most once per 12 hours; a must-use copy
// never gets an offer (replace its folder instead).
add_filter( 'update_plugins_github.com', 'd5b_updater_filter', 10, 4 );
// Before WordPress unpacks our release asset, its sha256 is checked against the json's (a mismatch aborts the update).
add_filter( 'upgrader_pre_download', 'd5b_updater_pre_download', 10, 4 );
// "View details" for our plugin answered from the json and readme.txt (wordpress.org does not know it).
add_filter( 'plugins_api', 'd5b_updater_plugins_api', 10, 3 );
// The Settings page's Check now (wp-admin only, nonce d5b_update_check; no admin_post_nopriv_* hook, on purpose).
add_action( 'admin_post_d5b_update_check', 'd5b_updater_check_now' );

// The settings page. Only people in wp-admin: no admin_post_nopriv_* hook, on purpose.
add_action( 'admin_menu', 'd5b_settings_menu' );
add_action( 'network_admin_menu', 'd5b_settings_menu' );
add_action( 'admin_post_d5b_save_switches', 'd5b_settings_save' );
add_action( 'network_admin_edit_d5b_save_switches', 'd5b_settings_save' );
add_action( 'admin_notices', 'd5b_admin_notice' );
add_action( 'network_admin_notices', 'd5b_admin_notice' );
