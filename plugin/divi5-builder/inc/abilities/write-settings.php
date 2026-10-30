<?php
/**
 * Write abilities for site settings (#32, release 2.3.0): settings-set and settings-set-homepage.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here.
 *
 * Rules (design 2026-10-30, "Settings" and "Guardrails"):
 *  - An allow-list by construction: siteurl, home, admin_email and every other option are not in it, so they cannot be
 *    written. Unknown keys refuse the whole call (invalid_input, the allow-list in the message).
 *  - settings-set is ONE normal definition. Its risky keys (time zone, language, permalinks, search engine visibility) are
 *    refused inside the handler, the whole call, with not_allowed while the Settings risky switch is off.
 *  - Every value is checked first by the plugin's own rule for the key and then by core's sanitize_option(), as wp-admin
 *    does; an error core reports (add_settings_error) answers invalid_input with core's message. Nothing is written until
 *    every key passed.
 *  - The one rule: one change-history row per call (object_type option, object_id 0) whose before holds the old value of
 *    every key given. A call that changes nothing writes nothing and no row.
 *  - permalink_structure is saved the way the Permalinks screen saves it (WP_Rewrite::set_permalink_structure) and the
 *    rewrite rules are flushed hard afterwards; whether .htaccess / web.config could be written is reported, never a failure.
 *  - settings-set-homepage is risky and also needs the Pages category: the pages must be published pages the caller may
 *    edit, and differ.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_ws_allowed' ) ) {

	// ---- the per-key checks: each returns the value to hand to sanitize_option, or a WP_Error

	function d5b_ws_bad( string $key, string $why ): WP_Error {
		return new WP_Error( 'invalid_input', $key . ' ' . $why );
	}

	/** Text of at most $max characters (valid UTF-8). */
	function d5b_ws_v_text( $v, string $key, int $max ) {
		if ( ! is_string( $v ) || 1 !== preg_match( '//u', $v ) ) { return d5b_ws_bad( $key, 'must be text (UTF-8).' ); }
		if ( d5b_rc_chars( $v ) > $max ) { return d5b_ws_bad( $key, 'is too long: at most ' . $max . ' characters.' ); }
		return $v;
	}

	/** A whole number from an int or a string of digits (an optional minus), within $min..$max. */
	function d5b_ws_v_int( $v, string $key, int $min, int $max ) {
		if ( is_string( $v ) && 1 === preg_match( '/^-?\d{1,9}$/D', $v ) ) { $v = (int) $v; }
		if ( ! is_int( $v ) || $v < $min || $v > $max ) { return d5b_ws_bad( $key, 'must be a whole number from ' . $min . ' to ' . $max . '.' ); }
		return $v;
	}

	function d5b_ws_v_blogname( $v ) { return d5b_ws_v_text( $v, 'blogname', 1000 ); }
	function d5b_ws_v_blogdescription( $v ) { return d5b_ws_v_text( $v, 'blogdescription', 1000 ); }
	function d5b_ws_v_date_format( $v ) { return d5b_ws_v_text( $v, 'date_format', 50 ); }
	function d5b_ws_v_time_format( $v ) { return d5b_ws_v_text( $v, 'time_format', 50 ); }
	function d5b_ws_v_start_of_week( $v ) { return d5b_ws_v_int( $v, 'start_of_week', 0, 6 ); }

	/**
	 * Core accepts any whole number (and turns "abc" into 1); the plugin takes -1 (show all, as core allows) or 1..500, so a
	 * typo (0, a huge number) cannot hide or overload the site.
	 */
	function d5b_ws_v_posts_per_page( $v ) {
		if ( -1 === $v || '-1' === $v ) { return -1; }
		$ok = d5b_ws_v_int( $v, 'posts_per_page', 1, 500 );
		return is_wp_error( $ok ) ? d5b_ws_bad( 'posts_per_page', 'must be a whole number from 1 to 500, or -1 to show all.' ) : $ok;
	}

	function d5b_ws_v_default_comment_status( $v ) {
		return in_array( $v, array( 'open', 'closed' ), true ) ? $v : d5b_ws_bad( 'default_comment_status', 'must be open or closed.' );
	}

	function d5b_ws_v_timezone_string( $v ) {
		if ( '' === $v ) { return ''; }
		// ALL_WITH_BC, as core's wp_timezone_choice lists them: US/Eastern and the other backward-compatible names are valid.
		if ( ! is_string( $v ) || ! in_array( $v, timezone_identifiers_list( DateTimeZone::ALL_WITH_BC ), true ) ) { return d5b_ws_bad( 'timezone_string', 'must be a time zone name such as Africa/Johannesburg, or "" to use gmt_offset.' ); }
		return $v;
	}

	/** Hours from UTC, -12 to 14, in steps such as 5.5 or 5.75: an int, a float or a plain decimal string (no exponent). */
	function d5b_ws_v_gmt_offset( $v ) {
		if ( is_int( $v ) || ( is_float( $v ) && is_finite( $v ) ) ) { $v = (string) $v; }
		if ( ! is_string( $v ) || 1 !== preg_match( '/^-?\d{1,2}(\.\d{1,2})?$/D', $v ) || (float) $v < -12 || (float) $v > 14 ) {
			return d5b_ws_bad( 'gmt_offset', 'must be a number of hours from -12 to 14, such as 2 or 5.5.' );
		}
		return $v;
	}

	/** '' (English, United States) or a language installed on the site; core would silently keep the old value. */
	function d5b_ws_v_WPLANG( $v ) {
		if ( '' === $v ) { return ''; }
		$have = function_exists( 'get_available_languages' ) ? array_map( 'strval', (array) get_available_languages() ) : array();
		if ( ! is_string( $v ) || ! in_array( $v, $have, true ) ) {
			return d5b_ws_bad( 'WPLANG', 'must be "" (English, United States) or an installed language: ' . ( $have ? implode( ', ', array_slice( $have, 0, 50 ) ) : 'none are installed' ) . '. Install a language in wp-admin first.' );
		}
		return $v;
	}

	/**
	 * '' (plain links) or a structure with a tag such as /%postname%/, prepared as the Permalinks screen prepares it: no #,
	 * one leading slash, no double slashes, /index.php in front without URL rewriting, /blog in front on the main site of a
	 * sub-folder network that has it. core's sanitize_option then refuses a structure without a tag.
	 */
	function d5b_ws_v_permalink_structure( $v ) {
		$v = d5b_ws_v_text( $v, 'permalink_structure', 255 );
		if ( is_wp_error( $v ) || '' === $v ) { return $v; }
		d5b_ws_admin_includes();
		$index = function_exists( 'got_url_rewrite' ) && ! got_url_rewrite() ? '/index.php' : '';
		$old   = (string) get_option( 'permalink_structure', '' );
		$blog  = ( is_multisite() && function_exists( 'is_subdomain_install' ) && ! is_subdomain_install() && is_main_site() && 0 === strpos( $old, '/blog/' ) ) ? '/blog' : '';
		$v     = preg_replace( '#/+#', '/', '/' . str_replace( '#', '', $v ) );
		return ( $index && $blog ) ? $index . preg_replace( '#^/?index\.php#', '', $v ) : $blog . $v;
	}

	/** 1 (search engines may index the site) or 0 (asked not to): 0, 1, "0", "1", false or true. */
	function d5b_ws_v_blog_public( $v ) {
		if ( is_bool( $v ) ) { return (int) $v; }
		if ( 0 === $v || 1 === $v || '0' === $v || '1' === $v ) { return (int) $v; }
		return d5b_ws_bad( 'blog_public', 'must be 1 (visible to search engines) or 0 (ask them not to index the site).' );
	}

	/** key => array( normal|risky, check callable ). The ONLY options settings-set writes. */
	function d5b_ws_allowed(): array {
		$out = array();
		foreach ( array( 'blogname', 'blogdescription', 'date_format', 'time_format', 'start_of_week', 'posts_per_page', 'default_comment_status' ) as $k ) { $out[ $k ] = array( 'normal', 'd5b_ws_v_' . $k ); }
		foreach ( array( 'timezone_string', 'gmt_offset', 'WPLANG', 'permalink_structure', 'blog_public' ) as $k ) { $out[ $k ] = array( 'risky', 'd5b_ws_v_' . $k ); }
		return $out;
	}

	function d5b_ws_keys( string $kind ): array {
		return array_keys( array_filter( d5b_ws_allowed(), function ( $row ) use ( $kind ) { return $kind === $row[0]; } ) );
	}

	/** The allow-list as the messages and the description give it. */
	function d5b_ws_allowed_text(): string {
		return 'normal: ' . implode( ', ', d5b_ws_keys( 'normal' ) ) . '; risky (needs the Settings risky switch): ' . implode( ', ', d5b_ws_keys( 'risky' ) );
	}

	/**
	 * The wp-admin files that hold what this needs on a REST request, where they are not loaded: misc.php (got_mod_rewrite,
	 * save_mod_rewrite_rules, used by a hard flush), file.php (get_home_path), template.php (add_settings_error, through
	 * which sanitize_option reports a bad value).
	 */
	function d5b_ws_admin_includes(): void {
		$need = array( 'misc.php' => 'save_mod_rewrite_rules', 'file.php' => 'get_home_path', 'template.php' => 'add_settings_error' );
		foreach ( $need as $file => $fn ) {
			$path = ABSPATH . 'wp-admin/includes/' . $file;
			if ( ! function_exists( $fn ) && is_readable( $path ) ) { require_once $path; }
		}
	}

	/** A stored option as a comparable string (false or a non-scalar as ''). */
	function d5b_ws_str( $v ): string {
		return is_scalar( $v ) ? (string) $v : '';
	}

	/**
	 * $v through core's sanitize_option( $key ). A value core rejects (it calls add_settings_error and keeps the old value)
	 * is invalid_input with core's message. @return mixed|WP_Error
	 */
	function d5b_ws_core_check( string $key, $v ) {
		d5b_ws_admin_includes();
		if ( ! function_exists( 'get_settings_errors' ) || ! function_exists( 'sanitize_option' ) ) {
			return new WP_Error( 'unavailable', 'WordPress\'s settings checks could not be loaded, so settings are not changed here. Change them in wp-admin.' );
		}
		$had   = count( (array) get_settings_errors( $key ) );
		$clean = sanitize_option( $key, $v );
		$errs  = array_values( (array) get_settings_errors( $key ) );
		if ( count( $errs ) > $had ) {
			$e   = end( $errs );
			$msg = trim( preg_replace( '/\s+/', ' ', strip_tags( (string) ( $e['message'] ?? '' ) ) ) );
			return new WP_Error( 'invalid_input', $key . ': ' . ( '' === $msg ? 'WordPress refused this value.' : $msg ) );
		}
		return $clean;
	}

	/** After a permalink change: true when the rewrite file of this server (web.config on IIS, .htaccess on Apache) exists and is writable. Never throws. */
	function d5b_ws_rewrite_file_written(): bool {
		try {
			// core writes no rewrite file on a network.
			if ( is_multisite() ) { return false; }
			d5b_ws_admin_includes();
			if ( ! function_exists( 'get_home_path' ) ) { return false; }
			$home = (string) get_home_path();
			if ( function_exists( 'iis7_supports_permalinks' ) && iis7_supports_permalinks() ) { $f = $home . 'web.config'; }
			elseif ( function_exists( 'got_mod_rewrite' ) && got_mod_rewrite() ) { $f = $home . '.htaccess'; }
			else { return false; }
			return is_file( $f ) && is_writable( $f );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Checks every pair, then writes the ones that change, with one history row. @return array|WP_Error
	 *  settings: key => the stored value after the call; changed: the keys written; rewrite_file_written when the permalink
	 *  structure changed; _history_failed when the row could not be stored.
	 */
	function d5b_ws_apply( array $pairs ) {
		$allowed = d5b_ws_allowed();
		if ( array() === $pairs ) { return new WP_Error( 'invalid_input', 'settings needs at least one key. Allowed: ' . d5b_ws_allowed_text() . '.' ); }
		foreach ( array_keys( $pairs ) as $k ) {
			if ( ! is_string( $k ) || ! isset( $allowed[ $k ] ) ) {
				return new WP_Error( 'invalid_input', 'The setting "' . substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ), 0, 100 ) . '" cannot be changed here. Allowed: ' . d5b_ws_allowed_text() . '. siteurl, home and admin_email are never changed by an ability.' );
			}
		}
		$risky = array();
		foreach ( array_keys( $pairs ) as $k ) { if ( 'risky' === $allowed[ $k ][0] ) { $risky[] = $k; } }
		if ( $risky && ! d5b_abilities_risky_on( 'settings' ) ) {
			return new WP_Error( 'not_allowed', 'These settings are risky and the Settings risky switch is off, so nothing was changed: ' . implode( ', ', $risky ) . '. An administrator can allow risky actions under Settings in the plugin\'s settings page, or leave these keys out.' );
		}
		$new = array(); $old = array();
		foreach ( $pairs as $k => $v ) {
			$v = call_user_func( $allowed[ $k ][1], $v );
			if ( is_wp_error( $v ) ) { return $v; }
			$stored = get_option( $k, '' );
			if ( is_string( $v ) ) {
				$ok = d5b_wc_check_masked( $v, d5b_ws_str( $stored ) );
				if ( true !== $ok ) { return $ok; }
			}
			$v = d5b_ws_core_check( $k, $v );
			if ( is_wp_error( $v ) ) { return $v; }
			$old[ $k ] = is_scalar( $stored ) ? $stored : '';
			$new[ $k ] = $v;
		}
		$changed = array();
		foreach ( $new as $k => $v ) { if ( d5b_ws_str( $old[ $k ] ) !== d5b_ws_str( $v ) ) { $changed[] = $k; } }
		$out = array( 'settings' => array(), 'changed' => $changed );
		if ( array() === $changed ) {
			foreach ( $new as $k => $v ) { $out['settings'][ $k ] = get_option( $k, '' ); }
			return $out;
		}
		global $wp_rewrite;
		$warn = array();
		foreach ( $changed as $k ) {
			if ( 'permalink_structure' === $k && is_object( $wp_rewrite ) && method_exists( $wp_rewrite, 'set_permalink_structure' ) ) {
				// As the Permalinks screen: the option and the rewrite object's own copy, so the flush below uses the new structure.
				$wp_rewrite->set_permalink_structure( $new[ $k ] );
			} else {
				update_option( $k, $new[ $k ] );
			}
			if ( d5b_ws_str( get_option( $k, '' ) ) !== d5b_ws_str( $new[ $k ] ) ) { $warn[] = 'WordPress did not store ' . $k . ' as given (another plugin may change or block it); its stored value is in settings.'; }
		}
		if ( in_array( 'permalink_structure', $changed, true ) ) {
			d5b_ws_admin_includes();
			flush_rewrite_rules( true );
			$out['rewrite_file_written'] = d5b_ws_rewrite_file_written();
		}
		$before = array(); $after = array();
		foreach ( $new as $k => $v ) {
			$before[ $k ] = $old[ $k ];
			$after[ $k ]  = get_option( $k, '' );
			$out['settings'][ $k ] = $after[ $k ];
		}
		$hid = d5b_wc_history_row( 'divi5-builder/settings-set', 'option', 0, 'update', $before, $after, 'Changed settings: ' . implode( ', ', $changed ) );
		if ( $warn ) { $out['warnings'] = $warn; }
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	/** First line of the write handlers: the settings capability, with a message about changing (not reading) them. */
	function d5b_ws_guard(): ?WP_Error {
		return d5b_rs_ok( 'settings' ) ? null : new WP_Error( 'forbidden', 'You do not have permission to change the site settings (manage_options).' );
	}

	function d5b_ws_h_settings_set( array $in ) {
		$e = d5b_ws_guard(); if ( $e ) { return $e; }
		foreach ( array_keys( $in ) as $k ) {
			if ( 'settings' !== $k ) { return new WP_Error( 'invalid_input', 'Unknown input "' . substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ), 0, 100 ) . '". Allowed: settings.' ); }
		}
		$s = $in['settings'] ?? null;
		if ( $s instanceof stdClass ) { $s = get_object_vars( $s ); }
		if ( ! is_array( $s ) || ( array() !== $s && d5b_rc_is_list( $s ) ) ) { return new WP_Error( 'invalid_input', 'settings must be an object of setting name => value.' ); }
		return d5b_ws_apply( $s );
	}

	// ---- the homepage

	/** A published page the caller may edit, or a WP_Error naming $key. Pages switched off is not_allowed. */
	function d5b_ws_page( string $key, int $id ) {
		if ( ! d5b_abilities_category_on( 'pages' ) ) { return new WP_Error( 'not_allowed', 'The Pages ability category is switched off, so the homepage cannot be set.' ); }
		$p = d5b_wc_post( $id, array( 'page' ), 'edit_post' );
		if ( null === $p || 'publish' !== (string) $p->post_status ) { return new WP_Error( 'invalid_input', $key . ' must be the id of a published page that you may edit.' ); }
		return $p;
	}

	function d5b_ws_h_homepage( array $in ) {
		$e = d5b_ws_guard(); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array( 'show_on_front' => 'enum:posts|page', 'page_on_front' => 'int', 'page_for_posts' => 'int' ), array( 'show_on_front' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		if ( ! d5b_abilities_risky_on( 'settings' ) ) { return new WP_Error( 'not_allowed', 'Setting the homepage is a risky action and the Settings risky switch is off.' ); }
		$front = (int) ( $a['page_on_front'] ?? 0 ); $blog = (int) ( $a['page_for_posts'] ?? 0 );
		$fp = null;
		if ( 'posts' === $a['show_on_front'] ) {
			if ( 0 !== $front || 0 !== $blog ) { return new WP_Error( 'invalid_input', 'With show_on_front posts the homepage shows the latest posts: leave out page_on_front and page_for_posts (both become 0).' ); }
			if ( ! d5b_abilities_category_on( 'pages' ) ) { return new WP_Error( 'not_allowed', 'The Pages ability category is switched off, so the homepage cannot be set.' ); }
		} else {
			if ( $front < 1 ) { return new WP_Error( 'invalid_input', 'page_on_front is required with show_on_front page: the id of a published page.' ); }
			// page_for_posts left out keeps the stored posts page (an explicit 0 clears it); a kept page is not changed, so it
			// is not checked again, but it may not be the new homepage.
			$kept = ! array_key_exists( 'page_for_posts', $a );
			if ( $kept ) {
				$cur  = get_option( 'page_for_posts', 0 );
				$blog = is_scalar( $cur ) ? (int) $cur : 0;
				if ( $blog === $front ) { return new WP_Error( 'invalid_input', 'page ' . $front . ' is the site\'s posts page now; pass page_for_posts (another page, or 0 for none) to make it the homepage.' ); }
			}
			if ( $front === $blog ) { return new WP_Error( 'invalid_input', 'page_on_front and page_for_posts must be different pages.' ); }
			$fp = d5b_ws_page( 'page_on_front', $front );
			if ( is_wp_error( $fp ) ) { return $fp; }
			if ( 0 !== $blog && ! $kept ) {
				$bp = d5b_ws_page( 'page_for_posts', $blog );
				if ( is_wp_error( $bp ) ) { return $bp; }
			}
		}
		$keys = array( 'show_on_front', 'page_on_front', 'page_for_posts' );
		$want = array( 'show_on_front' => (string) $a['show_on_front'], 'page_on_front' => $front, 'page_for_posts' => $blog );
		$before = array();
		foreach ( $keys as $k ) { $v = get_option( $k, '' ); $before[ $k ] = is_scalar( $v ) ? $v : ''; }
		$changed = array();
		foreach ( $keys as $k ) { if ( d5b_ws_str( $before[ $k ] ) !== d5b_ws_str( $want[ $k ] ) ) { $changed[] = $k; } }
		$out = $want + array( 'changed' => $changed );
		if ( array() === $changed ) { return $out; }
		foreach ( $changed as $k ) { update_option( $k, $want[ $k ] ); }
		$after = array();
		foreach ( $keys as $k ) { $after[ $k ] = get_option( $k, '' ); }
		$summary = null === $fp ? 'Set the homepage to the latest posts' : d5b_wc_summary( 'Set the homepage to', 'page', $fp );
		$hid = d5b_wc_history_row( 'divi5-builder/settings-set-homepage', 'option', 0, 'update', $before, $after, $summary );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	function d5b_ws_can_homepage( array $in = array() ): bool {
		return d5b_rs_ok( 'settings' );
	}

	// ---- the definitions

	$d5b_ws_value = array( 'type' => array( 'string', 'integer', 'number', 'boolean' ) );
	d5b_wc_def( 'settings-set', 'settings', 'Change site settings',
		'Changes site settings: settings is an object of setting name => value, at least one. Normal keys: ' . implode( ', ', d5b_ws_keys( 'normal' ) ) . '. Risky keys: ' . implode( ', ', d5b_ws_keys( 'risky' ) ) . '; while the Settings risky switch is off, a call with any risky key is refused whole (not_allowed) and nothing is written. Values are checked as wp-admin checks them (core\'s sanitize_option): posts_per_page 1 to 500 or -1 (show all), start_of_week 0 (Sunday) to 6, default_comment_status open or closed, date_format and time_format at most 50 characters, timezone_string a zone name (the backward-compatible names such as US/Eastern too) or "", gmt_offset -12 to 14, WPLANG "" or an installed language, permalink_structure "" or a structure with a tag such as /%postname%/ (the rewrite rules are flushed and rewrite_file_written says whether .htaccess or web.config could be written), blog_public 1 or 0. Refuses unknown keys and siteurl, home and admin_email (invalid_input, with the allow-list), any value WordPress rejects (invalid_input with its message), and text holding [redacted] (masked_read). Returns the stored values and the keys that changed. One change-history row keeps the old value of every key given.',
		array( 'settings' => array( 'type' => 'object', 'minProperties' => 1, 'additionalProperties' => $d5b_ws_value, 'description' => 'Setting name => new value, for example {"blogdescription": "Fresh bread daily", "posts_per_page": 12}. Allowed: ' . d5b_ws_allowed_text() . '.' ) ),
		array( 'settings' ), 'd5b_ws_h_settings_set', d5b_rs_perm( 'settings' ),
		array( 'idempotent' => true ) );

	d5b_wc_def( 'settings-set-homepage', 'settings', 'Set the homepage',
		'Sets what the homepage shows (Settings > Reading). show_on_front posts shows the latest posts (page_on_front and page_for_posts become 0). show_on_front page shows page_on_front, a published page you may edit, and page_for_posts is the page that lists the posts (0 for none; left out, the current posts page stays); the two must differ. A risky action: needs the Settings risky switch on, and the Pages category on as well. Refuses a missing, unpublished or non-page id and the same page twice. Returns the three values and the ones that changed. One change-history row keeps the three old values.',
		array(
			'show_on_front'  => array( 'type' => 'string', 'enum' => array( 'posts', 'page' ), 'description' => 'posts (the latest posts) or page (a static page).' ),
			'page_on_front'  => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'With page: the id of the published page to show as the homepage.' ),
			'page_for_posts' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'With page: the id of the published page that lists the posts, or 0 for none; left out, the current posts page stays. Must differ from page_on_front.' ),
		), array( 'show_on_front' ), 'd5b_ws_h_homepage', 'd5b_ws_can_homepage',
		array( 'idempotent' => true ), array( 'risky' => true ) );

	d5b_ability_set_extra_categories( array( 'settings-set-homepage' => array( 'pages' ) ) );
	unset( $d5b_ws_value );
}
