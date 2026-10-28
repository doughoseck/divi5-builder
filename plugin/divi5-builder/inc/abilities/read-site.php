<?php
/**
 * Read abilities for the site itself (#32, release 2.1.0, part 3): settings, templates, appearance, widgets, cache, health
 * (site health summary, scheduled events, debug log), users, plugins, themes and the WordPress version.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included. It relies on the
 * helpers of read-content.php (d5b_rc_args, d5b_rc_paging, d5b_rc_def ...), which the loader includes first. Every result goes
 * through d5b_ability_scrub() in the run wrapper; the rules below keep secrets out in the first place.
 *
 * Rules every ability here follows:
 *  - Nothing here changes anything, and nothing here makes the site fetch anything: no update check, no Site Health test,
 *    no HTTP request. Update information is read from the transients WordPress already keeps.
 *  - The handler checks its own capability (d5b_rs_guard), not only the permission callback. On a multisite network the
 *    plugins, themes and core abilities also need the network capability, because they describe the whole network.
 *  - A user is described by id, login, display name, roles and registration date. Never a password hash, an activation key,
 *    a session or an application password: those fields are not read at all.
 *  - Large text (Custom CSS, template content, global styles) is held to 256 KB with a truncated flag.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_rs_guard' ) ) {

	/** The capability each category needs: array( capability, network capability on a multisite network or '' ). */
	function d5b_rs_cap_map(): array {
		$admin = array( 'manage_options', '' );
		return array(
			'settings' => $admin, 'templates' => $admin, 'appearance' => $admin, 'cache' => $admin, 'health' => $admin,
			'widgets'  => array( 'edit_theme_options', '' ),
			'users'    => array( 'list_users', '' ),
			'plugins'  => array( 'manage_options', 'manage_network_plugins' ),
			'themes'   => array( 'manage_options', 'manage_network_themes' ),
			'core'     => array( 'manage_options', 'update_core' ),
		);
	}

	function d5b_rs_ok( string $category ): bool {
		$row = d5b_rs_cap_map()[ $category ] ?? null;
		if ( null === $row || ! current_user_can( $row[0] ) ) { return false; }
		return '' === $row[1] || ! is_multisite() || current_user_can( $row[1] );
	}

	/** The permission callback of a category. */
	function d5b_rs_perm( string $category ): callable {
		return function ( array $in = array() ) use ( $category ) { return d5b_rs_ok( $category ); };
	}

	/** First line of every handler: null when the caller may use the category, else a WP_Error. */
	function d5b_rs_guard( string $category ): ?WP_Error {
		return d5b_rs_ok( $category ) ? null : new WP_Error( 'forbidden', 'You do not have permission to read this.' );
	}

	/** At most $n bytes of $s, never cutting a UTF-8 character in half. */
	function d5b_rs_cut_bytes( string $s, int $n ): string {
		if ( strlen( $s ) <= $n ) { return $s; }
		if ( function_exists( 'mb_strcut' ) ) { return mb_strcut( $s, 0, $n, 'UTF-8' ); }
		return (string) iconv( 'UTF-8', 'UTF-8//IGNORE', substr( $s, 0, $n ) );
	}

	/** Text held to 256 KB. @return array array( text, truncated, total bytes ) */
	function d5b_rs_cap_text( $s ): array {
		$s = (string) $s;
		return array( d5b_rs_cut_bytes( $s, 262144 ), strlen( $s ) > 262144, strlen( $s ) );
	}

	function d5b_rs_json( $v ): string {
		$j = function_exists( 'wp_json_encode' ) ? wp_json_encode( $v ) : json_encode( $v );
		return is_string( $j ) ? $j : '';
	}

	/** One value of an update transient ( response[ key ]->new_version ), or ''. Reads the stored transient only. */
	function d5b_rs_new_version( $transient, string $key ): string {
		$resp = is_object( $transient ) ? ( $transient->response ?? array() ) : ( is_array( $transient ) ? ( $transient['response'] ?? array() ) : array() );
		$r    = is_array( $resp ) ? ( $resp[ $key ] ?? null ) : null;
		$v    = is_object( $r ) ? ( $r->new_version ?? '' ) : ( is_array( $r ) ? ( $r['new_version'] ?? '' ) : '' );
		return is_string( $v ) ? $v : '';
	}

	// ---- settings

	function d5b_rs_settings_keys(): array {
		return array( 'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'WPLANG',
			'posts_per_page', 'show_on_front', 'page_on_front', 'page_for_posts', 'default_comment_status', 'permalink_structure', 'blog_public' );
	}

	function d5b_rs_h_settings_get( array $in ) {
		$e = d5b_rs_guard( 'settings' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		$out = array();
		foreach ( d5b_rs_settings_keys() as $k ) {
			if ( 'admin_email' === $k && ! current_user_can( 'manage_options' ) ) { continue; }
			$v = get_option( $k, '' );
			$out[ $k ] = is_scalar( $v ) ? $v : '';
		}
		return array( 'settings' => $out );
	}

	// ---- templates and global styles

	function d5b_rs_block_theme(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	function d5b_rs_classic_note(): array {
		return array( 'is_block_theme' => false, 'note' => 'The active theme is a classic theme, so it has no block templates and no global styles.' );
	}

	function d5b_rs_template_row( $t ): array {
		return array(
			'id' => (string) ( $t->id ?? '' ), 'slug' => (string) ( $t->slug ?? '' ), 'theme' => (string) ( $t->theme ?? '' ), 'type' => (string) ( $t->type ?? '' ),
			'title' => is_string( $t->title ?? null ) ? $t->title : '', 'source' => (string) ( $t->source ?? '' ), 'status' => (string) ( $t->status ?? '' ),
			'has_theme_file' => ! empty( $t->has_theme_file ), 'is_custom' => ! empty( $t->is_custom ),
		);
	}

	function d5b_rs_h_templates_list( array $in ) {
		$e = d5b_rs_guard( 'templates' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array( 'type' => 'enum:wp_template|wp_template_part', 'per_page' => 'int', 'page' => 'int' ) ); if ( is_wp_error( $a ) ) { return $a; }
		list( $per, $page ) = d5b_rc_paging( $a );
		if ( ! d5b_rs_block_theme() || ! function_exists( 'get_block_templates' ) ) { return d5b_rs_classic_note() + array( 'items' => array(), 'total' => 0, 'page' => $page, 'per_page' => $per ); }
		$all = (array) get_block_templates( array(), $a['type'] ?? 'wp_template' );
		$out = array();
		foreach ( array_slice( array_values( $all ), ( $page - 1 ) * $per, $per ) as $t ) { $out[] = d5b_rs_template_row( $t ); }
		return array( 'is_block_theme' => true, 'items' => $out, 'total' => count( $all ), 'page' => $page, 'per_page' => $per );
	}

	function d5b_rs_h_templates_get( array $in ) {
		$e = d5b_rs_guard( 'templates' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array( 'id' => 'key', 'type' => 'enum:wp_template|wp_template_part' ), array( 'id' ) ); if ( is_wp_error( $a ) ) { return $a; }
		if ( 1 !== preg_match( '#^[A-Za-z0-9_.\-]+//[A-Za-z0-9_.\-]+$#', $a['id'] ) ) { return new WP_Error( 'invalid_input', 'id must look like theme//slug (see templates-list).' ); }
		if ( ! d5b_rs_block_theme() || ! function_exists( 'get_block_template' ) ) { return d5b_rs_classic_note(); }
		$t = get_block_template( $a['id'], $a['type'] ?? 'wp_template' );
		if ( ! is_object( $t ) ) { return new WP_Error( 'not_found', 'No template with that id.' ); }
		list( $content, $trunc, $bytes ) = d5b_rs_cap_text( $t->content ?? '' );
		return d5b_rs_template_row( $t ) + array( 'content' => $content, 'truncated' => $trunc, 'content_bytes' => $bytes, 'hash' => md5( (string) ( $t->content ?? '' ) ) );
	}

	function d5b_rs_h_global_styles_get( array $in ) {
		$e = d5b_rs_guard( 'templates' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! d5b_rs_block_theme() ) { return d5b_rs_classic_note(); }
		$data = null;
		if ( class_exists( 'WP_Theme_JSON_Resolver' ) ) { $data = WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data(); }
		elseif ( function_exists( 'wp_get_global_styles' ) ) { $data = wp_get_global_styles(); }
		list( $content, $trunc, $bytes ) = d5b_rs_cap_text( d5b_rs_json( $data ) );
		return array( 'is_block_theme' => true, 'content' => $content, 'truncated' => $trunc, 'content_bytes' => $bytes );
	}

	// ---- appearance

	function d5b_rs_theme_row( $t ): array {
		$parent = method_exists( $t, 'parent' ) ? $t->parent() : false;
		return array(
			'name' => (string) $t->get( 'Name' ), 'version' => (string) $t->get( 'Version' ), 'stylesheet' => (string) $t->get_stylesheet(), 'template' => (string) $t->get_template(),
			'parent' => is_object( $parent ) ? array( 'name' => (string) $parent->get( 'Name' ), 'version' => (string) $parent->get( 'Version' ), 'stylesheet' => (string) $parent->get_stylesheet() ) : null,
			'block_theme' => method_exists( $t, 'is_block_theme' ) ? (bool) $t->is_block_theme() : false,
		);
	}

	function d5b_rs_h_appearance_get( array $in ) {
		$e = d5b_rs_guard( 'appearance' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		$theme = wp_get_theme();
		$out   = array( 'theme' => d5b_rs_theme_row( $theme ) );
		$mods  = function_exists( 'get_theme_mods' ) ? get_theme_mods() : array();
		$mods  = is_array( $mods ) ? $mods : array();
		if ( strlen( d5b_rs_json( $mods ) ) > 262144 ) {
			$keys = array();
			foreach ( array_keys( $mods ) as $k ) { if ( ! function_exists( 'd5b_ability_scrub_key' ) || ! d5b_ability_scrub_key( $k ) ) { $keys[] = (string) $k; } }
			$out['theme_mods'] = array(); $out['theme_mods_truncated'] = true; $out['theme_mod_names'] = $keys;
		} else {
			$out['theme_mods'] = $mods; $out['theme_mods_truncated'] = false;
		}
		$css = function_exists( 'wp_get_custom_css_post' ) ? wp_get_custom_css_post( $theme->get_stylesheet() ) : null;
		list( $text, $trunc, $bytes ) = d5b_rs_cap_text( is_object( $css ) ? ( $css->post_content ?? '' ) : '' );
		$out['custom_css'] = $text; $out['custom_css_truncated'] = $trunc; $out['custom_css_bytes'] = $bytes;
		return $out;
	}

	// ---- widgets

	function d5b_rs_h_widgets_list( array $in ) {
		$e = d5b_rs_guard( 'widgets' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		$sidebars   = function_exists( 'wp_get_sidebars_widgets' ) ? (array) wp_get_sidebars_widgets() : array();
		$registered = isset( $GLOBALS['wp_registered_sidebars'] ) && is_array( $GLOBALS['wp_registered_sidebars'] ) ? $GLOBALS['wp_registered_sidebars'] : array();
		$rows = array(); $count = 0; $truncated = false; $options = array();
		foreach ( $sidebars as $sid => $ids ) {
			if ( ! is_array( $ids ) ) { continue; }
			$widgets = array();
			foreach ( $ids as $wid ) {
				if ( $count >= 500 ) { $truncated = true; break; }
				$wid = (string) $wid; $base = $wid; $num = 0;
				if ( 1 === preg_match( '/^(.+)-(\d+)$/', $wid, $m ) ) { $base = $m[1]; $num = (int) $m[2]; }
				if ( ! isset( $options[ $base ] ) ) { $options[ $base ] = get_option( 'widget_' . $base, array() ); }
				$settings = is_array( $options[ $base ] ) && isset( $options[ $base ][ $num ] ) ? $options[ $base ][ $num ] : null;
				if ( null !== $settings && strlen( serialize( $settings ) ) > 16384 ) { $settings = array( '_truncated' => true ); }
				$widgets[] = array( 'id' => $wid, 'type' => $base, 'settings' => $settings );
				$count++;
			}
			$rows[] = array( 'id' => (string) $sid, 'name' => (string) ( $registered[ $sid ]['name'] ?? '' ), 'widgets' => $widgets );
		}
		return array( 'sidebars' => $rows, 'widget_count' => $count, 'truncated' => $truncated );
	}

	// ---- cache

	function d5b_rs_h_cache_status( array $in ) {
		$e = d5b_rs_guard( 'cache' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		$found = function_exists( 'wpsu_detect_caches' ) ? wpsu_detect_caches() : array();
		unset( $found['object'] ); // always "present" in WordPress; whether it is a persistent one is reported below
		return array(
			'active' => array_values( $found ),
			'page_cache_constant' => defined( 'WP_CACHE' ) && WP_CACHE,
			'persistent_object_cache' => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
		);
	}

	// ---- health: summary, scheduled events, debug log

	function d5b_rs_h_health_status( array $in ) {
		$e = d5b_rs_guard( 'health' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		// Only the result WordPress stored the last time Site Health ran in a browser or from its schedule. The tests themselves
		// make HTTP requests and can take seconds, so they are never run from here.
		$raw = get_transient( 'health-check-site-status-result' );
		if ( false === $raw || null === $raw || '' === $raw ) { $raw = get_option( 'health-check-site-status-result', false ); }
		$d = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $d ) || ! isset( $d['good'] ) ) {
			return array( 'no_cached_result' => true, 'note' => 'WordPress has no stored Site Health result yet. Open Tools > Site Health once in the admin, then ask again.' );
		}
		$good = (int) $d['good']; $rec = (int) ( $d['recommended'] ?? 0 ); $crit = (int) ( $d['critical'] ?? 0 );
		return array( 'good' => $good, 'recommended' => $rec, 'critical' => $crit, 'total' => $good + $rec + $crit, 'cached' => true );
	}

	function d5b_rs_h_cron_list( array $in ) {
		$e = d5b_rs_guard( 'health' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$cron = is_array( $cron ) ? $cron : array();
		$events = array(); $total = 0;
		foreach ( $cron as $ts => $hooks ) {
			if ( ! is_array( $hooks ) ) { continue; }
			foreach ( $hooks as $hook => $items ) {
				foreach ( (array) $items as $ev ) {
					$total++;
					if ( count( $events ) >= 500 ) { continue; }
					$ev = is_array( $ev ) ? $ev : array();
					// The number of arguments only: they often hold addresses, ids and tokens.
					$events[] = array( 'hook' => (string) $hook, 'schedule' => ! empty( $ev['schedule'] ) ? (string) $ev['schedule'] : 'single',
						'next_run' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts ), 'arg_count' => is_array( $ev['args'] ?? null ) ? count( $ev['args'] ) : 0 );
				}
			}
		}
		return array( 'events' => $events, 'total' => $total, 'truncated' => $total > count( $events ) );
	}

	/** The log file: WP_DEBUG_LOG when it is a path, else debug.log in the content folder. Site configuration, never caller input. */
	function d5b_rs_log_path_for( $wp_debug_log, string $content_dir ): string {
		if ( is_string( $wp_debug_log ) && '' !== $wp_debug_log && ! in_array( strtolower( $wp_debug_log ), array( 'true', 'false', '1', '0' ), true ) ) { return $wp_debug_log; }
		return rtrim( $content_dir, '/\\' ) . '/debug.log';
	}

	function d5b_rs_h_error_log( array $in ) {
		$e = d5b_rs_guard( 'health' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; } // no path input of any kind
		if ( ! function_exists( 'd5b_audit_mask_secrets' ) ) { return new WP_Error( 'unavailable', 'The log cannot be shown safely on this site.' ); }
		$path = d5b_rs_log_path_for( defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) { return array( 'no_debug_log' => true ); }
		$size = (int) filesize( $path );
		$max  = 65536;
		$from = max( 0, $size - $max );
		$h    = fopen( $path, 'rb' );
		if ( false === $h ) { return array( 'no_debug_log' => true ); }
		$buf = '';
		if ( 0 === fseek( $h, $from ) ) { $buf = (string) fread( $h, $max ); }
		fclose( $h );
		if ( $from > 0 ) { // the first line is probably cut in half
			$nl  = strpos( $buf, "\n" );
			$buf = false === $nl ? '' : substr( $buf, $nl + 1 );
		}
		$lines = preg_split( '/\r\n|\n|\r/', $buf );
		if ( is_array( $lines ) && '' === end( $lines ) ) { array_pop( $lines ); }
		$lines = is_array( $lines ) ? $lines : array();
		$more  = count( $lines ) > 200 || $from > 0;
		$out   = array();
		foreach ( array_slice( $lines, -200 ) as $line ) {
			$line = function_exists( 'wp_check_invalid_utf8' ) ? (string) wp_check_invalid_utf8( $line, true ) : (string) iconv( 'UTF-8', 'UTF-8//IGNORE', $line );
			// Free text: key scrubbing cannot protect it, so every line goes through the audit log's masker.
			$out[] = d5b_audit_mask_secrets( d5b_rc_cut( $line, 2000 ) );
		}
		return array( 'lines' => $out, 'line_count' => count( $out ), 'file_bytes' => $size, 'bytes_read' => strlen( $buf ), 'truncated' => $more );
	}

	// ---- users

	function d5b_rs_user_row( $u ): array {
		$row = array(
			'id' => (int) $u->ID, 'login' => (string) $u->user_login, 'display_name' => (string) $u->display_name,
			'roles' => array_values( array_map( 'strval', (array) ( $u->roles ?? array() ) ) ), 'registered_gmt' => (string) $u->user_registered,
		);
		if ( current_user_can( 'list_users' ) ) { $row['email'] = (string) $u->user_email; }
		return $row;
	}

	function d5b_rs_h_users_list( array $in ) {
		$e = d5b_rs_guard( 'users' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array( 'role' => 'slug', 'search' => 'str', 'per_page' => 'int', 'page' => 'int' ) ); if ( is_wp_error( $a ) ) { return $a; }
		list( $per, $page ) = d5b_rc_paging( $a );
		$args = array( 'number' => $per, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'count_total' => true, 'fields' => 'all' );
		if ( isset( $a['role'] ) ) { $args['role'] = $a['role']; }
		if ( isset( $a['search'] ) ) { $args['search'] = '*' . $a['search'] . '*'; $args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' ); }
		$q = new WP_User_Query( $args );
		$items = array();
		foreach ( (array) $q->get_results() as $u ) { if ( is_object( $u ) ) { $items[] = d5b_rs_user_row( $u ); } }
		$total = (int) $q->get_total();
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per, 'total_pages' => max( 1, (int) ceil( $total / $per ) ) );
	}

	function d5b_rs_h_users_get( array $in ) {
		$e = d5b_rs_guard( 'users' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array( 'id' => 'id' ), array( 'id' ) ); if ( is_wp_error( $a ) ) { return $a; }
		$u = get_userdata( $a['id'] );
		if ( ! is_object( $u ) ) { return new WP_Error( 'not_found', 'No user with that id.' ); }
		return d5b_rs_user_row( $u );
	}

	// ---- plugins, themes, core

	function d5b_rs_h_plugins_list( array $in ) {
		$e = d5b_rs_guard( 'plugins' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! function_exists( 'get_plugins' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
		$active  = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$upd     = get_site_transient( 'update_plugins' ); // read what WordPress stored; never ask the update servers
		$rows = array();
		foreach ( (array) get_plugins() as $file => $p ) {
			$new = d5b_rs_new_version( $upd, (string) $file );
			$rows[] = array( 'file' => (string) $file, 'name' => (string) ( $p['Name'] ?? '' ), 'version' => (string) ( $p['Version'] ?? '' ),
				'active' => in_array( $file, $active, true ) || in_array( $file, $network, true ), 'network_active' => in_array( $file, $network, true ),
				'update_available' => '' !== $new, 'new_version' => '' !== $new ? $new : null );
		}
		usort( $rows, function ( $x, $y ) { return strcasecmp( $x['name'], $y['name'] ); } );
		return array( 'items' => $rows, 'total' => count( $rows ) );
	}

	function d5b_rs_h_themes_list( array $in ) {
		$e = d5b_rs_guard( 'themes' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		$upd = get_site_transient( 'update_themes' );
		$cur = (string) get_stylesheet();
		$rows = array();
		foreach ( (array) wp_get_themes() as $t ) {
			$row = d5b_rs_theme_row( $t );
			$new = d5b_rs_new_version( $upd, $row['stylesheet'] );
			$rows[] = $row + array( 'active' => $row['stylesheet'] === $cur, 'update_available' => '' !== $new, 'new_version' => '' !== $new ? $new : null );
		}
		return array( 'items' => $rows, 'total' => count( $rows ) );
	}

	function d5b_rs_h_core_version( array $in ) {
		$e = d5b_rs_guard( 'core' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		$upd = get_site_transient( 'update_core' ); // stored result only, no update check
		$new = '';
		$list = is_object( $upd ) ? ( $upd->updates ?? array() ) : ( is_array( $upd ) ? ( $upd['updates'] ?? array() ) : array() );
		foreach ( is_array( $list ) ? $list : array() as $u ) {
			$resp = is_object( $u ) ? ( $u->response ?? '' ) : ( is_array( $u ) ? ( $u['response'] ?? '' ) : '' );
			$cur  = is_object( $u ) ? ( $u->current ?? '' ) : ( is_array( $u ) ? ( $u['current'] ?? '' ) : '' );
			if ( 'upgrade' === $resp && is_string( $cur ) && '' !== $cur ) { $new = $cur; break; }
		}
		return array( 'version' => (string) get_bloginfo( 'version' ), 'php_version' => PHP_VERSION, 'multisite' => is_multisite(), 'update_available' => '' !== $new, 'new_version' => '' !== $new ? $new : null );
	}

	// ---- the definitions

	$d5b_rs_none = array();
	d5b_rc_def( 'settings-get', 'settings', 'Get site settings',
		'Returns the safe site settings: title, tagline, addresses, time zone, date format, reading and permalink settings. Refuses callers without manage_options, and never returns any setting outside this fixed list.',
		$d5b_rs_none, array(), 'd5b_rs_h_settings_get', d5b_rs_perm( 'settings' ) );

	d5b_rc_def( 'templates-list', 'templates', 'List block templates',
		'Returns one page of the block templates or template parts of the active theme: id, slug, title, source and status. Refuses callers without manage_options. A classic theme returns an empty list with a note.',
		array( 'type' => array( 'type' => 'string', 'enum' => array( 'wp_template', 'wp_template_part' ), 'default' => 'wp_template', 'description' => 'Templates (wp_template) or template parts (wp_template_part).' ) ) + d5b_rc_paging_props(),
		array(), 'd5b_rs_h_templates_list', d5b_rs_perm( 'templates' ) );
	d5b_rc_def( 'templates-get', 'templates', 'Get a block template',
		'Returns one block template or template part with its raw block markup (at most 256 KB, with a truncated flag) and a hash. Refuses callers without manage_options, a bad id, and an id that does not exist.',
		array( 'id' => array( 'type' => 'string', 'description' => 'The template id as templates-list shows it, like twentytwentyfive//home.' ),
			'type' => array( 'type' => 'string', 'enum' => array( 'wp_template', 'wp_template_part' ), 'default' => 'wp_template', 'description' => 'Whether the id is a template or a template part.' ) ),
		array( 'id' ), 'd5b_rs_h_templates_get', d5b_rs_perm( 'templates' ) );
	d5b_rc_def( 'global-styles-get', 'templates', 'Get global styles',
		'Returns the merged global styles (theme.json plus the site owner\'s changes) of a block theme as JSON text, at most 256 KB with a truncated flag. Refuses callers without manage_options. A classic theme returns a note.',
		$d5b_rs_none, array(), 'd5b_rs_h_global_styles_get', d5b_rs_perm( 'templates' ) );

	d5b_rc_def( 'appearance-get', 'appearance', 'Get theme appearance',
		'Returns the active theme and its parent, the theme settings (theme mods, secrets removed) and the Custom CSS of the active theme, at most 256 KB with a truncated flag. Refuses callers without manage_options.',
		$d5b_rs_none, array(), 'd5b_rs_h_appearance_get', d5b_rs_perm( 'appearance' ) );

	d5b_rc_def( 'widgets-list', 'widgets', 'List widgets',
		'Returns every sidebar with its widgets and their settings (secrets removed), up to 500 widgets. Refuses callers without edit_theme_options.',
		$d5b_rs_none, array(), 'd5b_rs_h_widgets_list', d5b_rs_perm( 'widgets' ) );

	d5b_rc_def( 'cache-status', 'cache', 'Get cache status',
		'Returns which known cache plugins are active (Divi, WP Fastest Cache, WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache) and whether a persistent object cache is in use. Refuses callers without manage_options. Changes nothing.',
		$d5b_rs_none, array(), 'd5b_rs_h_cache_status', d5b_rs_perm( 'cache' ) );

	d5b_rc_def( 'health-status', 'health', 'Get Site Health summary',
		'Returns the counts from the last Site Health run that WordPress stored (good, recommended, critical). Does not run the tests. Refuses callers without manage_options. Answers no_cached_result when Site Health has never been opened.',
		$d5b_rs_none, array(), 'd5b_rs_h_health_status', d5b_rs_perm( 'health' ) );
	d5b_rc_def( 'cron-list', 'health', 'List scheduled events',
		'Returns the scheduled events (WP-Cron): hook, schedule name, next run in UTC and the number of arguments, never the argument values; at most 500 events. Refuses callers without manage_options.',
		$d5b_rs_none, array(), 'd5b_rs_h_cron_list', d5b_rs_perm( 'health' ) );
	d5b_rc_def( 'error-log', 'health', 'Read the debug log',
		'Returns the end of the WordPress debug log: the last 200 lines within the last 64 KB, with passwords, tokens and keys masked. Takes no path. Refuses callers without manage_options. Answers no_debug_log when there is no log.',
		$d5b_rs_none, array(), 'd5b_rs_h_error_log', d5b_rs_perm( 'health' ) );

	d5b_rc_def( 'users-list', 'users', 'List users',
		'Returns one page of users: id, login, display name, roles, registration date and, for callers with list_users, the email address. Refuses callers without list_users. Never returns passwords, keys or sessions.',
		array( 'role' => array( 'type' => 'string', 'description' => 'Only users with this role, for example editor.' ),
			'search' => array( 'type' => 'string', 'description' => 'Only users whose login, name or email contains this text.' ) ) + d5b_rc_paging_props(),
		array(), 'd5b_rs_h_users_list', d5b_rs_perm( 'users' ) );
	d5b_rc_def( 'users-get', 'users', 'Get a user',
		'Returns one user: id, login, display name, roles, registration date and, for callers with list_users, the email address. Refuses callers without list_users and ids that do not exist. Never returns passwords, keys or sessions.',
		array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The user id.' ) ),
		array( 'id' ), 'd5b_rs_h_users_get', d5b_rs_perm( 'users' ) );

	d5b_rc_def( 'plugins-list', 'plugins', 'List plugins',
		'Returns the installed plugins: name, version, whether each is active and whether an update is waiting (from the stored update list, without checking). Refuses callers without manage_options, and on a network without manage_network_plugins.',
		$d5b_rs_none, array(), 'd5b_rs_h_plugins_list', d5b_rs_perm( 'plugins' ) );
	d5b_rc_def( 'themes-list', 'themes', 'List themes',
		'Returns the installed themes: name, version, parent, whether each is active and whether an update is waiting (from the stored update list, without checking). Refuses callers without manage_options, and on a network without manage_network_themes.',
		$d5b_rs_none, array(), 'd5b_rs_h_themes_list', d5b_rs_perm( 'themes' ) );
	d5b_rc_def( 'core-version', 'core', 'Get WordPress version',
		'Returns the WordPress version, the PHP version and whether a WordPress update is waiting (from the stored update list, without checking). Refuses callers without manage_options, and on a network without update_core.',
		$d5b_rs_none, array(), 'd5b_rs_h_core_version', d5b_rs_perm( 'core' ) );

	unset( $d5b_rs_none );
}
