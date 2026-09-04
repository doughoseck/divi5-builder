<?php
/**
 * Plugin Name: Divi 5 Builder — REST meta bridge
 * Description: Registers Divi's builder/layout post-meta for the WordPress REST API so a page built via REST (e.g. by the divi5-builder skill) can be flipped into "Divi mode" without opening the Visual Builder. v1.2 makes link-canvas attach an et_pb_canvas popup to a page via the real Divi meta (_divi_canvas_parent_post_id + _divi_off_canvas_data), so REST-created Divi 5 popups render. v1.3 adds read/write of Divi's GLOBAL COLOUR palette, which lives in a wp_option rather than in page content and could not be created over REST at all before — so a site can now be themed before its first page is built. Writes are gated by the normal edit-post capability (manage_options for the palette), so only authenticated editors/admins (incl. Application Passwords) can use them.
 * Version: 1.4.0
 * Author: divi5-builder skill
 *
 * INSTALL (pick one):
 *   A) Must-use plugin (auto-active, no activation step) — RECOMMENDED:
 *      Copy this file to  wp-content/mu-plugins/divi5-builder-rest.php
 *      (create the mu-plugins folder if it doesn't exist). Done.
 *   B) Normal plugin:
 *      Copy this file to  wp-content/plugins/divi5-builder-rest.php
 *      then activate "Divi 5 Builder — REST meta bridge" in Plugins.
 *   C) Code Snippets / WPCode plugin:
 *      Paste everything BELOW the closing docblock (from `add_action(` down)
 *      as a new PHP snippet set to "Run everywhere". Omit the <?php line.
 *
 * After install, the divi5-builder skill's `wp.js set-builder <id>` command
 * (a plain core-REST meta write) will turn the page into a rendered, no-sidebar
 * Divi page. Nothing here touches Divi's own code — it only exposes existing
 * meta keys to REST behind a capability check.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'init', function () {
	$auth = function ( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	};
	// Post types that can host a Divi layout. Add custom types here if needed.
	$post_types = array( 'page', 'post' );
	// The meta Divi uses to mark + lay out a builder page.
	$keys = array(
		'_et_pb_use_builder',       // 'on' = this is a Divi Builder page (the "Divi" badge)
		'_et_pb_use_divi_5',        // 'on' = built with the Divi 5 engine
		'_et_pb_page_layout',       // 'et_no_sidebar' | 'et_full_width_page' | 'et_right_sidebar' ...
		'_et_pb_side_nav',          // 'on' | 'off'
		'_et_builder_version',      // version stamp, e.g. 'VB|Divi|5.9.0'
		'_et_pb_built_for_post_type', // 'page'
	);
	foreach ( $post_types as $pt ) {
		foreach ( $keys as $key ) {
			register_post_meta( $pt, $key, array(
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => $auth,
			) );
		}
	}
} );

/*
 * Custom REST routes for the divi5-builder skill.
 *
 * Divi 5 popups = an et_pb_canvas post whose overlay section is linked to a
 * page, triggered by a button Interaction. Divi appends the canvas to the page
 * at render time based on the page↔canvas link, which the Visual Builder stores
 * as the canvas's post_parent — a field the et_pb_canvas REST controller does
 * NOT accept. These routes let the skill (a) inspect a post's parent/meta to
 * confirm the mechanism, and (b) set the canvas post_parent so a REST-created
 * popup actually renders. Both are gated by edit_post capability.
 */
add_action( 'rest_api_init', function () {
	$perm = function ( $req ) {
		$id = (int) $req->get_param( 'id' );
		if ( ! $id ) { $id = (int) $req->get_param( 'canvas_id' ); }
		return current_user_can( $id ? 'edit_post' : 'edit_posts', $id ?: null );
	};

	// GET /divi5-builder/v1/postinfo?id=123[&scan=canvas]
	// Returns post_parent, post_type, all meta, and (with scan) matching wp_options.
	register_rest_route( 'divi5-builder/v1', '/postinfo', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( $req ) {
			global $wpdb;
			$id = (int) $req->get_param( 'id' );
			$p  = get_post( $id );
			if ( ! $p ) { return new WP_Error( 'not_found', 'no such post', array( 'status' => 404 ) ); }
			$out = array(
				'id'          => $id,
				'post_type'   => $p->post_type,
				'post_parent' => (int) $p->post_parent,
				'post_status' => $p->post_status,
				'meta'        => get_post_meta( $id ),
			);
			if ( $req->get_param( 'scan' ) ) {
				$like = '%' . $wpdb->esc_like( sanitize_text_field( $req->get_param( 'scan' ) ) ) . '%';
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT option_name, LEFT(option_value, 800) AS v FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 40", $like ) );
				$out['options'] = $rows;
			}
			return $out;
		},
	) );

	// POST /divi5-builder/v1/link-canvas { canvas_id, page_id }
	// Attaches an et_pb_canvas (popup/off-canvas) to a page exactly the way the
	// Visual Builder does, so Divi appends it on the front end: it writes the
	// canvas-side identity/parent meta and the page-side off-canvas pointer.
	register_rest_route( 'divi5-builder/v1', '/link-canvas', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( $req ) {
			$canvas_id = (int) $req->get_param( 'canvas_id' );
			$page_id   = (int) $req->get_param( 'page_id' );
			$c = get_post( $canvas_id );
			if ( ! $c || $c->post_type !== 'et_pb_canvas' ) {
				return new WP_Error( 'bad_canvas', 'canvas_id is not an et_pb_canvas', array( 'status' => 400 ) );
			}
			if ( ! get_post( $page_id ) ) {
				return new WP_Error( 'bad_page', 'page_id not found', array( 'status' => 400 ) );
			}
			// Reuse an existing canvas UUID if this canvas already has one.
			$uuid = get_post_meta( $canvas_id, '_divi_canvas_id', true );
			if ( ! $uuid ) { $uuid = wp_generate_uuid4(); }
			// Canvas-side meta (identity + which page it belongs to).
			update_post_meta( $canvas_id, '_divi_canvas_id', $uuid );
			update_post_meta( $canvas_id, '_divi_canvas_parent_post_id', (string) $page_id );
			if ( ! get_post_meta( $canvas_id, '_divi_canvas_created_at', true ) ) {
				update_post_meta( $canvas_id, '_divi_canvas_created_at', gmdate( 'Y-m-d\TH:i:s' ) . '.000Z' );
			}
			// Page-side pointer (builder state) + Divi 5 engine flags.
			$name = $req->get_param( 'canvas_name' ) ? sanitize_text_field( $req->get_param( 'canvas_name' ) ) : 'Main Canvas';
			update_post_meta( $page_id, '_divi_off_canvas_data', array( 'activeCanvasId' => $uuid, 'mainCanvasName' => $name ) );
			update_post_meta( $page_id, '_et_pb_use_divi_5', 'on' );
			update_post_meta( $page_id, '_et_pb_use_builder', 'on' );
			return array( 'ok' => true, 'canvas_id' => $canvas_id, 'page_id' => $page_id, 'canvas_uuid' => $uuid );
		},
	) );

	// ---------------------------------------------------------------------
	// Global colours (v1.3)
	//
	// Divi keeps the site's global palette in a wp_option, NOT in page content
	// — so the skill's `global-colors` command, which scrapes gcid- strings out
	// of existing pages, can only report colours some page already uses. On a
	// fresh site it returns [] whether or not a palette exists, and there was
	// no way to CREATE one over REST at all.
	//
	// These two routes close that gap so a site can be themed BEFORE its first
	// page is built, which is the only order that makes sense: pages should
	// reference gcids from the start rather than be written with literal hex
	// and retro-fitted afterwards.
	//
	// Deliberately restricted to Divi's own palette options — a generic
	// option-writing route behind an application password would be a far
	// larger key than this job needs.
	// ---------------------------------------------------------------------
	// et_global_colors is Divi 4's palette and is IGNORED by Divi 5 — verified on
	// a live Divi 5.9 site, where a section bound to gcid-primary-color rendered
	// Divi's factory #2ea3f2 while that option said a custom hex. Divi 5's
	// real store is et_divi_global_variables. Both are listed so a site on
	// either generation can be themed, and so the difference stays visible.
	$palette_options = array( 'et_global_colors', 'divi_global_colors', 'et_divi_global_variables' );
	$palette_perm    = function () { return current_user_can( 'manage_options' ); };

	// Recursively sanitise an arbitrary decoded-JSON structure before it is
	// written to an option. Divi 5's global-variable format is a nested
	// structure rather than a flat colour map, so the palette route's hex-only
	// cleaning cannot express it — but writing decoded JSON straight into an
	// option unfiltered is not something to do behind an application password.
	$deep_clean = function ( $v ) use ( &$deep_clean ) {
		if ( is_array( $v ) ) {
			$out = array();
			foreach ( $v as $k => $vv ) {
				$key         = is_int( $k ) ? $k : sanitize_text_field( (string) $k );
				$out[ $key ] = $deep_clean( $vv );
			}
			return $out;
		}
		if ( is_string( $v ) ) { return sanitize_text_field( $v ); }
		if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) || null === $v ) { return $v; }
		return null;
	};

	// GET /divi5-builder/v1/option?name=et_divi_global_variables
	// POST /divi5-builder/v1/option { name, value }
	//
	// Deliberately whitelisted to the same palette options. This exists because
	// Divi 5's global-variable format had to be LEARNED from a site where the
	// Visual Builder had defined one — there is no documentation for it — and
	// once learned it must be writable in whatever shape Divi actually uses.
	register_rest_route( 'divi5-builder/v1', '/option', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $palette_perm,
			'callback'            => function ( $req ) use ( $palette_options ) {
				$name = sanitize_key( (string) $req->get_param( 'name' ) );
				if ( ! in_array( $name, $palette_options, true ) ) {
					return new WP_Error( 'bad_option', 'not a permitted option', array( 'status' => 400 ) );
				}
				return array( 'ok' => true, 'name' => $name, 'value' => get_option( $name, null ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $palette_perm,
			'callback'            => function ( $req ) use ( $palette_options, $deep_clean ) {
				$name = sanitize_key( (string) $req->get_param( 'name' ) );
				if ( ! in_array( $name, $palette_options, true ) ) {
					return new WP_Error( 'bad_option', 'not a permitted option', array( 'status' => 400 ) );
				}
				$value = $req->get_param( 'value' );
				if ( null === $value ) {
					return new WP_Error( 'no_value', 'value is required', array( 'status' => 400 ) );
				}
				update_option( $name, $deep_clean( $value ) );
				return array( 'ok' => true, 'name' => $name, 'value' => get_option( $name, null ) );
			},
		),
	) );

	// GET /divi5-builder/v1/global-colors
	// Returns every known palette option so the caller can see which one this
	// Divi version actually uses, rather than guessing.
	register_rest_route( 'divi5-builder/v1', '/global-colors', array(
		'methods'             => 'GET',
		'permission_callback' => $palette_perm,
		'callback'            => function () use ( $palette_options ) {
			$out = array();
			foreach ( $palette_options as $opt ) {
				$val = get_option( $opt, null );
				$out[ $opt ] = ( null === $val ) ? null : $val;
			}
			return array( 'ok' => true, 'options' => $out );
		},
	) );

	// POST /divi5-builder/v1/global-colors { colors: {...}, option?, replace? }
	// Merges by default, so a palette written here cannot silently drop colours
	// added by hand in the Visual Builder. Pass replace=1 to overwrite.
	register_rest_route( 'divi5-builder/v1', '/global-colors', array(
		'methods'             => 'POST',
		'permission_callback' => $palette_perm,
		'callback'            => function ( $req ) use ( $palette_options ) {
			$colors = $req->get_param( 'colors' );
			if ( ! is_array( $colors ) || ! $colors ) {
				return new WP_Error( 'bad_colors', 'colors must be a non-empty object', array( 'status' => 400 ) );
			}
			$opt = $req->get_param( 'option' ) ? sanitize_key( $req->get_param( 'option' ) ) : 'et_global_colors';
			if ( ! in_array( $opt, $palette_options, true ) ) {
				return new WP_Error( 'bad_option', 'option must be one of: ' . implode( ', ', $palette_options ), array( 'status' => 400 ) );
			}

			$clean = array();
			foreach ( $colors as $gcid => $spec ) {
				$gcid = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $gcid ) );
				if ( '' === $gcid ) { continue; }
				$hex = is_array( $spec ) ? ( isset( $spec['color'] ) ? $spec['color'] : '' ) : $spec;
				$hex = sanitize_hex_color( (string) $hex );
				if ( ! $hex ) { continue; }  // drop anything that is not a real colour
				$clean[ $gcid ] = array(
					'color'  => $hex,
					'active' => ( is_array( $spec ) && isset( $spec['active'] ) && 'no' === $spec['active'] ) ? 'no' : 'yes',
				);
			}
			if ( ! $clean ) {
				return new WP_Error( 'no_valid_colors', 'no valid hex colours supplied', array( 'status' => 400 ) );
			}

			$existing = get_option( $opt, array() );
			if ( ! is_array( $existing ) ) { $existing = array(); }
			$final = $req->get_param( 'replace' ) ? $clean : array_merge( $existing, $clean );
			update_option( $opt, $final );

			return array( 'ok' => true, 'option' => $opt, 'written' => array_keys( $clean ), 'total' => count( $final ) );
		},
	) );
} );
