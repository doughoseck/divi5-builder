<?php
/**
 * Write abilities for the appearance (#32, release 2.3.0): appearance-theme-mod-set, appearance-theme-mod-delete and
 * appearance-custom-css-set.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here.
 *
 * Rules (design 2026-10-30, "Appearance" and "Guardrails"):
 *  - All three are risky (the Appearance risky switch) and need their capability, as core's Customizer does:
 *    edit_theme_options for theme mods, edit_theme_options AND edit_css for Custom CSS (checked by the permission callback
 *    and again by the handler). A stale Custom CSS answer carries the current hash only for a caller who may read the
 *    stylesheet (appearance-get: manage_options).
 *  - Theme mod names: plain ASCII only, never nav_menu_locations, custom_css_post_id or sidebars_widgets (other features
 *    own those), never a secret-named name, and never a name that differs from an existing one only by case. A mod whose
 *    current value holds a secret-named key cannot be set or deleted (the history could not keep it in full).
 *  - A theme mod value is a scalar or a JSON-safe array of at most 64 KB encoded. Set through set_theme_mod, deleted
 *    through remove_theme_mod. The history row (object_type theme_mod, id 0) keeps the mod's name and theme (the active
 *    stylesheet) on both sides, with the old value, or absent.
 *  - Custom CSS carries expect_hash (md5 of the stored stylesheet, the custom_css post's content as stored, unfiltered;
 *    stale otherwise), at most 256 KB, no </style, no [redacted] (a masked read is never written back). @import and
 *    expression() are stored as given, as core stores them. Written through wp_update_custom_css_post; the row (object_type
 *    custom_css, id of the custom_css post) keeps the whole previous stylesheet within the history's 8 MB.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wa_mod_name_ok' ) ) {

	/** Names other features own: never written or deleted here. */
	function d5b_wa_reserved_mods(): array {
		return array( 'nav_menu_locations', 'custom_css_post_id', 'sidebars_widgets' );
	}

	/**
	 * A theme mod name any write accepts. @return true|WP_Error
	 */
	function d5b_wa_mod_name_ok( string $name ) {
		$ok = d5b_wc_meta_key_ok( $name );
		if ( true !== $ok ) { return new WP_Error( 'invalid_input', 'theme mod names must be plain ASCII letters, digits, _ - . :' ); }
		if ( in_array( strtolower( $name ), d5b_wa_reserved_mods(), true ) ) {
			return new WP_Error( 'not_allowed', 'The theme mod "' . $name . '" belongs to another feature (menus, Custom CSS or widgets) and is not changed here. Use the menu, Custom CSS or widget abilities, or wp-admin.' );
		}
		if ( function_exists( 'd5b_is_secret_key' ) && d5b_is_secret_key( $name ) ) {
			return new WP_Error( 'not_allowed', 'The theme mod "' . $name . '" has a secret-looking name and is not changed here. Change it in wp-admin.' );
		}
		$mods = function_exists( 'get_theme_mods' ) ? get_theme_mods() : array();
		$same = array();
		foreach ( array_keys( is_array( $mods ) ? $mods : array() ) as $k ) {
			$k = (string) $k;
			if ( $k !== $name && 0 === strcasecmp( $k, $name ) ) { $same[] = $k; }
		}
		if ( $same ) {
			return new WP_Error( 'invalid_input', 'a theme mod named \'' . d5b_rc_cut( $same[0], 100 ) . '\' already exists and differs from "' . $name . '" only by case; use that exact name' );
		}
		return true;
	}

	/**
	 * Custom CSS text a write accepts: valid UTF-8, at most 256 KB, no closing style tag (it would break out of the style
	 * element the theme prints it in), and no [redacted] marker unless the stored stylesheet $stored holds it too.
	 * @return true|WP_Error
	 */
	function d5b_wa_css_check( string $css, string $stored = '' ) {
		if ( 1 !== preg_match( '//u', $css ) ) { return new WP_Error( 'invalid_input', 'css must be text (UTF-8).' ); }
		if ( strlen( $css ) > 262144 ) { return new WP_Error( 'invalid_input', 'css is too long: at most 256 KB.' ); }
		if ( false !== stripos( $css, '</style' ) ) { return new WP_Error( 'invalid_input', 'css must not contain a closing style tag (</style).' ); }
		return d5b_wc_check_masked( $css, $stored );
	}

	/** The refusal for a call while the Appearance risky switch is off (the registry hides the abilities too). */
	function d5b_wa_gate(): ?WP_Error {
		return d5b_abilities_risky_on( 'appearance' ) ? null : new WP_Error( 'not_allowed', 'Changing the appearance is a risky action and the Appearance risky switch is off.' );
	}

	function d5b_wa_unknown( array $in, array $allowed ): ?WP_Error {
		foreach ( array_keys( $in ) as $k ) {
			if ( ! in_array( $k, $allowed, true ) ) {
				return new WP_Error( 'invalid_input', 'Unknown input "' . substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ), 0, 100 ) . '". Allowed: ' . implode( ', ', $allowed ) . '.' );
			}
		}
		return null;
	}

	function d5b_wa_json( $v ): string {
		$j = function_exists( 'wp_json_encode' ) ? wp_json_encode( $v ) : json_encode( $v );
		return is_string( $j ) ? $j : '';
	}

	/** Theme mods need edit_theme_options, as core's Customizer settings do. */
	function d5b_wa_can_mods( array $in = array() ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	/** The history side of a theme mod: its name and theme, then the value or absent. */
	function d5b_wa_mod_side( string $name, bool $has, $value = null ): array {
		$side = array( 'name' => $name, 'theme' => (string) get_stylesheet() );
		return $side + ( $has ? array( 'value' => $value ) : array( 'absent' => true ) );
	}

	/** The mod name from the input, checked, with the stored mods. @return array|WP_Error array( name, mods ) */
	function d5b_wa_mod_start( array $in, array $allowed ) {
		if ( ! d5b_wa_can_mods() ) { return new WP_Error( 'forbidden', 'You do not have permission to change the theme settings (edit_theme_options).' ); }
		$e = d5b_wa_gate(); if ( $e ) { return $e; }
		$e = d5b_wa_unknown( $in, $allowed ); if ( $e ) { return $e; }
		$name = $in['name'] ?? null;
		if ( ! is_string( $name ) || '' === $name ) { return new WP_Error( 'invalid_input', 'name is required: the theme mod name.' ); }
		$ok = d5b_wa_mod_name_ok( $name );
		if ( true !== $ok ) { return $ok; }
		$mods = get_theme_mods();
		return array( $name, is_array( $mods ) ? $mods : array() );
	}

	function d5b_wa_h_mod_set( array $in ) {
		$st = d5b_wa_mod_start( $in, array( 'name', 'value' ) );
		if ( is_wp_error( $st ) ) { return $st; }
		list( $name, $mods ) = $st;
		if ( ! array_key_exists( 'value', $in ) || null === $in['value'] ) { return new WP_Error( 'invalid_input', 'value is required: text, a number, true/false, or a list/object of those.' ); }
		$value = d5b_wc_unobject( $in['value'] );
		if ( ! d5b_wc_json_safe( $value ) ) { return new WP_Error( 'invalid_input', 'value must be text, a number, true/false, or a list/object of those (valid UTF-8, at most 8 levels deep).' ); }
		$enc = d5b_wa_json( $value );
		if ( '' === $enc || strlen( $enc ) > 65536 ) { return new WP_Error( 'invalid_input', 'value is too large: at most 64 KB once encoded.' ); }
		if ( ! d5b_wc_secret_free( $value ) ) { return new WP_Error( 'invalid_input', 'value holds a secret-named key; secrets are not written by an ability. Change it in wp-admin.' ); }
		$has = array_key_exists( $name, $mods );
		$old = $has ? $mods[ $name ] : null;
		if ( $has && ! d5b_wc_secret_free( $old ) ) { return d5b_wc_secret_error( 'theme mod' ); }
		$masked = d5b_wc_check_masked( $enc, $has ? d5b_wa_json( $old ) : '' );
		if ( true !== $masked ) { return $masked; }
		if ( $has && d5b_wc_unobject( $old ) === $value ) { return array( 'name' => $name, 'value' => $value, 'changed' => false ); }
		$before = d5b_wa_mod_side( $name, $has, $has ? d5b_wc_meta_value( $old ) : null );
		$big = d5b_wc_check_content_size( d5b_wa_json( $before ) ); if ( true !== $big ) { return $big; }
		set_theme_mod( $name, $value );
		$now   = get_theme_mods();
		$has2  = is_array( $now ) && array_key_exists( $name, $now );
		$after = $has2 ? d5b_wc_unobject( $now[ $name ] ) : null;
		if ( ! $has2 || ( $has && d5b_wa_json( $after ) === d5b_wa_json( d5b_wc_unobject( $old ) ) && d5b_wa_json( $after ) !== $enc ) ) {
			return new WP_Error( 'write_failed', 'WordPress did not store the theme mod (another plugin may block it).' );
		}
		$out = array( 'name' => $name, 'value' => $after, 'changed' => true );
		if ( d5b_wa_json( $after ) !== $enc ) { $out['warnings'] = array( 'WordPress stored the theme mod differently from what was given (another plugin may change it); the stored value is in value.' ); }
		$hid = d5b_wc_history_row( 'divi5-builder/appearance-theme-mod-set', 'theme_mod', 0, 'update', $before, d5b_wa_mod_side( $name, true, $after ), 'Set theme mod ' . $name );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	function d5b_wa_h_mod_delete( array $in ) {
		$st = d5b_wa_mod_start( $in, array( 'name' ) );
		if ( is_wp_error( $st ) ) { return $st; }
		list( $name, $mods ) = $st;
		if ( ! array_key_exists( $name, $mods ) ) { return new WP_Error( 'not_found', 'There is no theme mod named "' . $name . '".' ); }
		$old = $mods[ $name ];
		if ( ! d5b_wc_secret_free( $old ) ) { return d5b_wc_secret_error( 'theme mod' ); }
		$before = d5b_wa_mod_side( $name, true, d5b_wc_meta_value( $old ) );
		$big = d5b_wc_check_content_size( d5b_wa_json( $before ) ); if ( true !== $big ) { return $big; }
		remove_theme_mod( $name );
		$now = get_theme_mods();
		if ( is_array( $now ) && array_key_exists( $name, $now ) ) { return new WP_Error( 'write_failed', 'WordPress did not remove the theme mod (another plugin may block it).' ); }
		$out = array( 'name' => $name, 'deleted' => true );
		$hid = d5b_wc_history_row( 'divi5-builder/appearance-theme-mod-delete', 'theme_mod', 0, 'delete', $before, d5b_wa_mod_side( $name, false ), 'Deleted theme mod ' . $name );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	// ---- Custom CSS

	/**
	 * The Custom CSS of the active theme as stored: the custom_css post's content, unfiltered (wp_get_custom_css runs the
	 * wp_get_custom_css filter, which another plugin may use to add rules that are not stored). appearance-get hashes the same.
	 */
	function d5b_wa_current_css(): string {
		$p = function_exists( 'wp_get_custom_css_post' ) ? wp_get_custom_css_post( get_stylesheet() ) : null;
		return is_object( $p ) ? (string) ( $p->post_content ?? '' ) : '';
	}

	/** Custom CSS needs edit_theme_options AND edit_css, as core's Customizer does (custom_css setting). */
	function d5b_wa_can_css( array $in = array() ): bool {
		return current_user_can( 'edit_theme_options' ) && current_user_can( 'edit_css' );
	}

	function d5b_wa_h_css_set( array $in ) {
		if ( ! d5b_wa_can_css() ) { return new WP_Error( 'forbidden', 'You do not have permission to change the Custom CSS (edit_theme_options and edit_css).' ); }
		$e = d5b_wa_gate(); if ( $e ) { return $e; }
		$e = d5b_wa_unknown( $in, array( 'css', 'expect_hash' ) ); if ( $e ) { return $e; }
		if ( ! isset( $in['css'] ) || ! is_string( $in['css'] ) ) { return new WP_Error( 'invalid_input', 'css is required: the whole new stylesheet as text ("" empties it).' ); }
		$expect = $in['expect_hash'] ?? null;
		if ( ! is_string( $expect ) || '' === $expect ) { return new WP_Error( 'invalid_input', 'expect_hash is required: read the Custom CSS first (appearance-get custom_css_hash) and pass its hash.' ); }
		$css = $in['css'];
		$cur = d5b_wa_current_css();
		$ok  = d5b_wa_css_check( $css, $cur );
		if ( true !== $ok ) { return $ok; }
		$now = md5( $cur );
		if ( ! hash_equals( $now, strtolower( $expect ) ) ) {
			// The current hash only for a caller who may read the stylesheet: anyone else could otherwise skip the read.
			$can_read = d5b_rs_ok( 'appearance' );
			$msg      = 'The Custom CSS changed since it was read (the hash does not match). Read it again' . ( $can_read ? '' : ' (appearance-get, which needs manage_options)' ) . ', apply the change to the new stylesheet and pass the new hash.';
			return $can_read ? new WP_Error( 'stale', $msg, array( 'hash' => $now ) ) : new WP_Error( 'stale', $msg );
		}
		$post = function_exists( 'wp_get_custom_css_post' ) ? wp_get_custom_css_post( get_stylesheet() ) : null;
		if ( $cur === $css ) { return array( 'id' => is_object( $post ) ? (int) $post->ID : 0, 'hash' => $now, 'bytes' => strlen( $css ), 'changed' => false ); }
		$big = d5b_wc_check_content_size( $cur ); if ( true !== $big ) { return $big; }
		$r = wp_update_custom_css_post( $css );
		if ( is_wp_error( $r ) ) { return d5b_wc_core_error( $r ); }
		$id    = is_object( $r ) ? (int) $r->ID : 0;
		$after = d5b_wa_current_css();
		$out   = array( 'id' => $id, 'hash' => md5( $after ), 'bytes' => strlen( $after ), 'changed' => true );
		if ( $after !== $css ) { $out['warnings'] = array( 'WordPress stored the stylesheet differently from what was given (another plugin may change it); read it again.' ); }
		$hid = d5b_wc_history_row( 'divi5-builder/appearance-custom-css-set', 'custom_css', $id, 'update', array( 'css' => $cur ), array( 'css' => $after ), 'Updated Custom CSS (' . strlen( $css ) . ' bytes)' );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	// ---- the definitions

	$d5b_wa_name = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'The theme mod name: plain ASCII letters, digits, _ - . : only.' );

	d5b_wc_def( 'appearance-theme-mod-set', 'appearance', 'Set a theme setting',
		'Sets one theme mod (a theme setting such as a colour or a layout choice) of the active theme. value is text, a number, true/false, or a list/object of those, at most 64 KB once encoded. A risky action: needs the Appearance risky switch and edit_theme_options (as the Customizer). Refuses names that are not plain ASCII, nav_menu_locations, custom_css_post_id and sidebars_widgets (other features own them), secret-looking names, a name that differs from an existing theme mod only by case, a value or current value that holds a secret-named key, and text holding [redacted] (masked_read). Returns the stored value and whether it changed. One change-history row keeps the name, the theme and the old value (or that there was none).',
		array(
			'name'  => $d5b_wa_name,
			'value' => array( 'type' => array( 'string', 'integer', 'number', 'boolean', 'array', 'object' ), 'description' => 'The new value: text, a number, true/false, or a list/object of those.' ),
		), array( 'name', 'value' ), 'd5b_wa_h_mod_set', 'd5b_wa_can_mods',
		array( 'idempotent' => true ), array( 'risky' => true ) );

	d5b_wc_def( 'appearance-theme-mod-delete', 'appearance', 'Delete a theme setting',
		'Removes one theme mod of the active theme, so the theme uses its default again. A risky action: needs the Appearance risky switch and edit_theme_options. The change-history row keeps the name, the theme and the whole old value, so it can be put back. Returns the name and deleted true. Refuses a theme mod that does not exist (not_found), the same names as appearance-theme-mod-set and a theme mod whose value holds a secret-named key.',
		array( 'name' => $d5b_wa_name ), array( 'name' ), 'd5b_wa_h_mod_delete', 'd5b_wa_can_mods',
		array( 'idempotent' => true ), array( 'risky' => true ) );

	d5b_wc_def( 'appearance-custom-css-set', 'appearance', 'Set the Custom CSS',
		'Replaces the Custom CSS of the active theme (Appearance > Customize > Additional CSS) with css, the whole new stylesheet; "" empties it. Needs edit_theme_options and edit_css (as the Customizer) and the Appearance risky switch. A wrong rule can hide the whole site until it is put back: the change-history row keeps the whole previous stylesheet. expect_hash is required: the custom_css_hash that appearance-get returned for the stylesheet you started from (md5 of the stored stylesheet); if the stylesheet changed since, the answer is stale (with the current hash when you may read it with appearance-get). Refuses css over 256 KB, css holding </style, and css holding [redacted] (masked_read); anything else, @import and expression() included, is stored as given, as WordPress stores it. Returns id, hash (pass it as the next expect_hash), bytes and changed.',
		array(
			'css'         => array( 'type' => 'string', 'maxLength' => 262144, 'description' => 'The whole new stylesheet, at most 256 KB. "" empties it.' ),
			'expect_hash' => array( 'type' => 'string', 'description' => 'custom_css_hash from appearance-get (md5 of the current stylesheet).' ),
		), array( 'css', 'expect_hash' ), 'd5b_wa_h_css_set', 'd5b_wa_can_css',
		array( 'idempotent' => true ), array( 'risky' => true ) );

	d5b_ability_set_content_keys( array( 'appearance-custom-css-set' ) );
	unset( $d5b_wa_name );
}
