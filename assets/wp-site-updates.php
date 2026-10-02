<?php
/**
 * Plugin Name: WP Site Updates (REST)
 * Description: For the divi5-builder skill's site-updates.js. Lists what is out of date (plugins, themes, WordPress, translations) and runs ONE update per request with WordPress's own updater, the same code as the Updates screen. Works on any WordPress site.
 * Version: 1.0.1
 * Author: divi5-builder skill
 *
 * INSTALL: copy this file to wp-content/mu-plugins/wp-site-updates.php
 * REMOVE:  delete the file. It stores nothing.
 * Needs PHP 7.0 or newer and WordPress 5.5 or newer.
 *
 * Routes (namespace wp-site-updates/v1). Reading needs update_plugins; each update needs the capability WordPress
 * itself asks for (update_plugins, update_themes, update_core, update_languages):
 *   GET  /info                         versions, whether this site allows updates at all
 *   GET  /status[?refresh=1]           every plugin and theme with its installed and available version, WordPress, translations
 *   POST /update { type, item, dry_run }
 *        type = plugin (item = folder/file.php) | theme (item = folder) | core (item = the version to go to) | translations
 *   POST /clear-caches                 Divi's generated CSS and the page cache (also done after every update)
 *
 * What it does NOT do: keep a copy of the old version (a rollback is a restore from backup), update mu-plugins or
 * drop-ins, or install anything that is not already installed. A premium plugin updates only when its licence gives
 * WordPress a download; without one it is reported as "no download offered".
 * While an update of an ACTIVE plugin or theme runs, WordPress shows visitors its maintenance page (seconds).
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'WPSU_VERSION' ) ) { define( 'WPSU_VERSION', '1.0.1' ); }

if ( ! function_exists( 'wpsu_kind' ) ) :

	// How big a step is it? "major" = the first number changes, "minor" = the second, "patch" = anything after.
	// WordPress itself counts differently (6.8 -> 6.9 is a major release): wpsu_core_kind() below.
	function wpsu_kind( $from, $to ) {
		$a = array_map( 'intval', array_pad( explode( '.', preg_replace( '/[^0-9.].*$/', '', (string) $from ) ), 3, 0 ) );
		$b = array_map( 'intval', array_pad( explode( '.', preg_replace( '/[^0-9.].*$/', '', (string) $to ) ), 3, 0 ) );
		if ( $a[0] !== $b[0] ) { return 'major'; }
		if ( $a[1] !== $b[1] ) { return 'minor'; }
		return 'patch';
	}

	// WordPress: the first TWO numbers are the release (6.8), a third is a maintenance release (6.8.2).
	function wpsu_core_kind( $from, $to ) {
		return 'patch' === wpsu_kind( $from, $to ) ? 'minor' : 'major';
	}

	// Why an offered update cannot run here, or '' when it can.
	function wpsu_blocked( $offer, $php, $wp ) {
		if ( empty( $offer['package'] ) ) { return 'no download offered (a premium item without an active licence, or the vendor is unreachable)'; }
		if ( ! empty( $offer['requires_php'] ) && version_compare( $php, $offer['requires_php'], '<' ) ) { return 'needs PHP ' . $offer['requires_php'] . ', this server has ' . $php; }
		if ( ! empty( $offer['requires'] ) && version_compare( preg_replace( '/[^0-9.].*$/', '', $wp ), $offer['requires'], '<' ) ) { return 'needs WordPress ' . $offer['requires'] . ', this site has ' . $wp; }
		return '';
	}

	// $installed: file => [Name, Version]. $offers: file => [new_version, package, requires, requires_php]. $active: list of files.
	function wpsu_plugin_rows( $installed, $offers, $active, $php, $wp ) {
		$rows = array();
		foreach ( $installed as $file => $p ) {
			$row = array( 'item' => (string) $file, 'name' => isset( $p['Name'] ) ? (string) $p['Name'] : (string) $file, 'version' => isset( $p['Version'] ) ? (string) $p['Version'] : '',
				'active' => in_array( $file, $active, true ), 'new_version' => null, 'kind' => null, 'blocked' => '' );
			$o = isset( $offers[ $file ] ) ? (array) $offers[ $file ] : null;
			if ( $o && ! empty( $o['new_version'] ) && version_compare( $o['new_version'], $row['version'], '>' ) ) {
				$row['new_version'] = (string) $o['new_version'];
				$row['kind']        = wpsu_kind( $row['version'], $row['new_version'] );
				$row['blocked']     = wpsu_blocked( $o, $php, $wp );
			}
			$rows[] = $row;
		}
		usort( $rows, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
		return $rows;
	}

	// $installed: folder => [Name, Version]. $in_use: the active theme's folder and, for a child theme, its parent's.
	function wpsu_theme_rows( $installed, $offers, $in_use, $php, $wp ) {
		return wpsu_plugin_rows( $installed, $offers, $in_use, $php, $wp );
	}

	// $offers: list of [response, version]. The newest version WordPress offers to upgrade to, or null.
	function wpsu_core_row( $current, $offers ) {
		$best = null;
		foreach ( $offers as $o ) {
			$o = (array) $o;
			if ( empty( $o['version'] ) || ! isset( $o['response'] ) || 'upgrade' !== $o['response'] ) { continue; }
			if ( ! version_compare( $o['version'], $current, '>' ) ) { continue; }
			if ( null === $best || version_compare( $o['version'], $best, '>' ) ) { $best = (string) $o['version']; }
		}
		// every version on offer, so a caller can ask for the newest maintenance release of the CURRENT branch
		$all = array();
		foreach ( $offers as $o ) { $o = (array) $o; if ( ! empty( $o['version'] ) && isset( $o['response'] ) && 'upgrade' === $o['response'] && version_compare( $o['version'], $current, '>' ) ) { $all[] = (string) $o['version']; } }
		$all = array_values( array_unique( $all ) );
		usort( $all, 'version_compare' );
		return array( 'version' => (string) $current, 'new_version' => $best, 'kind' => $best ? wpsu_core_kind( $current, $best ) : null, 'offered' => $all );
	}

	// The row for $item, or null. Only something that is installed AND has an update can be named.
	function wpsu_find( $rows, $item ) {
		if ( ! is_string( $item ) || '' === $item ) { return null; }
		foreach ( $rows as $r ) { if ( $r['item'] === $item ) { return $r; } }
		return null;
	}

	function wpsu_error( $code, $message, $status = 400 ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	// ---- everything below talks to WordPress ----

	function wpsu_load_admin() {
		foreach ( array( 'file.php', 'plugin.php', 'theme.php', 'update.php', 'misc.php', 'class-wp-upgrader.php' ) as $f ) {
			if ( file_exists( ABSPATH . 'wp-admin/includes/' . $f ) ) { require_once ABSPATH . 'wp-admin/includes/' . $f; }
		}
	}

	// Why this site refuses updates altogether, or ''.
	function wpsu_site_blocked() {
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'wpsu_update' ) ) { return 'file changes are switched off on this site (DISALLOW_FILE_MODS)'; }
		return '';
	}

	function wpsu_offers( $transient ) {
		$t   = get_site_transient( $transient );
		$out = array();
		if ( is_object( $t ) && ! empty( $t->response ) ) { foreach ( (array) $t->response as $k => $o ) { $out[ $k ] = (array) $o; } }
		return $out;
	}

	// $refresh: ask the update servers. $force: first forget what WordPress remembers, because on its own WordPress
	// asks again only every 12 hours and would answer from memory (1.0.0 did that: a "refreshed" status that was
	// 90 minutes old on its first real run).
	function wpsu_status( $refresh, $force = false ) {
		wpsu_load_admin();
		if ( $refresh && $force && function_exists( 'wp_clean_update_cache' ) ) { wp_clean_update_cache(); }
		if ( $refresh ) { wp_version_check(); wp_update_plugins(); wp_update_themes(); }
		$php = PHP_VERSION; $wp = get_bloginfo( 'version' );

		$installed = array(); $active = array();
		foreach ( get_plugins() as $file => $p ) { $installed[ $file ] = array( 'Name' => $p['Name'], 'Version' => $p['Version'] ); if ( is_plugin_active( $file ) ) { $active[] = $file; } }
		$plugins = wpsu_plugin_rows( $installed, wpsu_offers( 'update_plugins' ), $active, $php, $wp );

		$t_installed = array();
		foreach ( wp_get_themes() as $slug => $theme ) { $t_installed[ $slug ] = array( 'Name' => $theme->get( 'Name' ), 'Version' => $theme->get( 'Version' ) ); }
		$themes = wpsu_theme_rows( $t_installed, wpsu_offers( 'update_themes' ), array_values( array_unique( array( get_stylesheet(), get_template() ) ) ), $php, $wp );

		$core_offers = array();
		foreach ( (array) get_core_updates() as $u ) { $core_offers[] = array( 'response' => isset( $u->response ) ? $u->response : '', 'version' => isset( $u->current ) ? $u->current : '' ); }
		$checked = get_site_transient( 'update_plugins' );

		return array(
			'plugin_version' => WPSU_VERSION,
			'site'           => home_url( '/' ),
			'wordpress'      => wpsu_core_row( $wp, $core_offers ),
			'plugins'        => $plugins,
			'themes'         => $themes,
			'translations'   => count( (array) wp_get_translation_updates() ),
			'last_checked'   => is_object( $checked ) && ! empty( $checked->last_checked ) ? gmdate( 'c', (int) $checked->last_checked ) : null,
			'php'            => $php,
			'multisite'      => is_multisite(),
			'site_blocked'   => wpsu_site_blocked(),
		);
	}

	// After ANY update on a Divi site the generated CSS must be thrown away (owner's rule: stale static CSS after a
	// plugin, theme or WordPress update shows as broken styling). Also empties a page cache so visitors and the
	// checks that follow see the updated site. Returns the names of what was cleared.
	function wpsu_clear_caches() {
		$cleared = array();
		if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
			ET_Core_PageResource::remove_static_resources( 'all', 'all' );
			$cleared[] = 'Divi static CSS';
		}
		if ( isset( $GLOBALS['wp_fastest_cache'] ) && is_object( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
			$GLOBALS['wp_fastest_cache']->deleteCache( true );
			$cleared[] = 'WP Fastest Cache';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); $cleared[] = 'WP Rocket'; }
		if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); $cleared[] = 'W3 Total Cache'; }
		if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); $cleared[] = 'WP Super Cache'; }
		if ( class_exists( 'LiteSpeed\Purge' ) && method_exists( 'LiteSpeed\Purge', 'purge_all' ) ) { LiteSpeed\Purge::purge_all(); $cleared[] = 'LiteSpeed Cache'; }
		if ( function_exists( 'wp_cache_flush' ) ) { wp_cache_flush(); $cleared[] = 'object cache'; }
		return $cleared;
	}

	// Runs one update. Returns an array, or WP_Error for a request that makes no sense.
	function wpsu_update( $type, $item, $dry ) {
		wpsu_load_admin();
		$caps = array( 'plugin' => 'update_plugins', 'theme' => 'update_themes', 'core' => 'update_core', 'translations' => 'update_languages' );
		if ( ! isset( $caps[ $type ] ) ) { return wpsu_error( 'wpsu_type', 'type must be plugin, theme, core or translations' ); }
		if ( ! current_user_can( $caps[ $type ] ) ) { return wpsu_error( 'wpsu_forbidden', 'this user may not update ' . $type, 403 ); }
		$blocked = wpsu_site_blocked();
		if ( '' !== $blocked ) { return wpsu_error( 'wpsu_site_blocked', $blocked, 409 ); }

		$s    = wpsu_status( true ); // always decide on fresh information
		$from = ''; $to = ''; $name = $type;
		if ( 'plugin' === $type || 'theme' === $type ) {
			$row = wpsu_find( 'plugin' === $type ? $s['plugins'] : $s['themes'], $item );
			if ( ! $row ) { return wpsu_error( 'wpsu_unknown', 'no installed ' . $type . ' is called ' . ( is_string( $item ) ? $item : '?' ), 404 ); }
			if ( null === $row['new_version'] ) { return array( 'ok' => true, 'done' => false, 'type' => $type, 'item' => $item, 'name' => $row['name'], 'from' => $row['version'], 'to' => $row['version'], 'note' => 'already up to date' ); }
			if ( '' !== $row['blocked'] ) { return wpsu_error( 'wpsu_blocked', $row['name'] . ': ' . $row['blocked'], 409 ); }
			$from = $row['version']; $to = $row['new_version']; $name = $row['name'];
		} elseif ( 'core' === $type ) {
			$from = $s['wordpress']['version'];
			if ( ! $s['wordpress']['new_version'] ) { return array( 'ok' => true, 'done' => false, 'type' => 'core', 'item' => '', 'name' => 'WordPress', 'from' => $from, 'to' => $from, 'note' => 'already up to date' ); }
			// the caller must name the version it saw, so a newer release that appeared in between is never installed blind
			if ( ! is_string( $item ) || ! in_array( $item, $s['wordpress']['offered'], true ) ) { return wpsu_error( 'wpsu_core_version', 'name the version to go to; offered now: ' . implode( ', ', $s['wordpress']['offered'] ), 409 ); }
			$to = $item; $name = 'WordPress';
		} else {
			if ( 0 === $s['translations'] ) { return array( 'ok' => true, 'done' => false, 'type' => 'translations', 'item' => '', 'name' => 'translations', 'from' => '', 'to' => '', 'note' => 'already up to date' ); }
			$name = 'translations';
		}
		if ( $dry ) { return array( 'ok' => true, 'done' => false, 'dry_run' => true, 'type' => $type, 'item' => (string) $item, 'name' => $name, 'from' => $from, 'to' => $to ); }

		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 600 ); }
		$skin   = new Automatic_Upgrader_Skin();
		$result = null;
		if ( 'plugin' === $type ) {
			$up  = new Plugin_Upgrader( $skin );
			$res = $up->bulk_upgrade( array( $item ) ); // bulk_upgrade leaves an active plugin active
			$result = is_array( $res ) && array_key_exists( $item, $res ) ? $res[ $item ] : $res;
		} elseif ( 'theme' === $type ) {
			$up  = new Theme_Upgrader( $skin );
			$res = $up->bulk_upgrade( array( $item ) );
			$result = is_array( $res ) && array_key_exists( $item, $res ) ? $res[ $item ] : $res;
		} elseif ( 'core' === $type ) {
			$target = null;
			foreach ( (array) get_core_updates() as $u ) { if ( isset( $u->response, $u->current ) && 'upgrade' === $u->response && $u->current === $to ) { $target = $u; break; } }
			if ( ! $target ) { return wpsu_error( 'wpsu_core_version', 'WordPress ' . $to . ' is no longer offered', 409 ); }
			$up     = new Core_Upgrader( $skin );
			$result = $up->upgrade( $target );
		} else {
			$up     = new Language_Pack_Upgrader( $skin );
			$result = $up->bulk_upgrade();
		}

		$messages = method_exists( $skin, 'get_upgrade_messages' ) ? array_values( array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) ) : array();
		$error    = '';
		if ( is_wp_error( $result ) ) { $error = $result->get_error_message(); }
		elseif ( false === $result || null === $result ) { $error = 'the updater reported a failure'; }
		if ( '' === $error && method_exists( $skin, 'get_errors' ) && is_wp_error( $skin->get_errors() ) && $skin->get_errors()->has_errors() ) { $error = $skin->get_errors()->get_error_message(); }

		// what is installed now, read again from disk
		if ( function_exists( 'wp_clean_plugins_cache' ) ) { wp_clean_plugins_cache( true ); }
		if ( function_exists( 'wp_clean_themes_cache' ) ) { wp_clean_themes_cache( true ); }
		$now = '';
		if ( 'plugin' === $type ) { $all = get_plugins(); $now = isset( $all[ $item ] ) ? (string) $all[ $item ]['Version'] : ''; }
		elseif ( 'theme' === $type ) { $th = wp_get_theme( $item ); $now = $th->exists() ? (string) $th->get( 'Version' ) : ''; }
		elseif ( 'core' === $type ) { $now = is_string( $result ) && '' !== $result ? $result : ''; if ( '' === $now && file_exists( ABSPATH . 'wp-includes/version.php' ) ) { $wp_version = ''; include ABSPATH . 'wp-includes/version.php'; $now = (string) $wp_version; } }

		$done = '' === $error && ( 'translations' === $type || ( '' !== $now && $now === $to ) );
		if ( '' === $error && ! $done ) { $error = 'the updater finished without an error, but the installed version is ' . ( '' === $now ? 'unknown' : $now ) . ', not ' . $to; }
		// clear the caches whatever the outcome: a half-finished update leaves stale CSS just the same
		$cleared = wpsu_clear_caches();
		return array( 'ok' => $done, 'done' => $done, 'type' => $type, 'item' => (string) $item, 'name' => $name, 'from' => $from, 'to' => $to, 'now' => $now, 'error' => $error, 'caches_cleared' => $cleared, 'messages' => array_slice( $messages, -12 ) );
	}

endif;

add_action( 'rest_api_init', function () {
	$ns   = 'wp-site-updates/v1';
	$read = function () { return current_user_can( 'update_plugins' ); };
	register_rest_route( $ns, '/info', array(
		'methods'             => 'GET',
		'permission_callback' => $read,
		'callback'            => function () {
			return array( 'plugin_version' => WPSU_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'multisite' => is_multisite(), 'site_blocked' => wpsu_site_blocked() );
		},
	) );
	register_rest_route( $ns, '/status', array(
		'methods'             => 'GET',
		'permission_callback' => $read,
		'callback'            => function ( $req ) { $r = (bool) $req->get_param( 'refresh' ); return wpsu_status( $r, $r ); },
	) );
	register_rest_route( $ns, '/clear-caches', array(
		'methods'             => 'POST',
		'permission_callback' => $read,
		'callback'            => function () { return array( 'ok' => true, 'caches_cleared' => wpsu_clear_caches() ); },
	) );
	register_rest_route( $ns, '/update', array(
		'methods'             => 'POST',
		'permission_callback' => $read, // the capability for the TYPE is checked inside
		'callback'            => function ( $req ) { return wpsu_update( (string) $req->get_param( 'type' ), $req->get_param( 'item' ), (bool) $req->get_param( 'dry_run' ) ); },
	) );
} );
