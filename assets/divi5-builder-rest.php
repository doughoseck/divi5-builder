<?php
/**
 * Plugin Name: Divi 5 Builder — REST meta bridge
 * Description: Registers Divi's builder/layout post-meta for the WordPress REST API so a page built via REST (e.g. by the divi5-builder skill) can be flipped into "Divi mode" without opening the Visual Builder. v1.2 makes link-canvas attach an et_pb_canvas popup to a page via the real Divi meta (_divi_canvas_parent_post_id + _divi_off_canvas_data), so REST-created Divi 5 popups render. v1.3 adds read/write of Divi's GLOBAL COLOUR palette, which lives in a wp_option rather than in page content and could not be created over REST at all before — so a site can now be themed before its first page is built. v1.5 adds Theme Builder access: list every template and layout, read a header/body/footer layout's raw content, and write one back (hash-checked against concurrent edits, previous content kept for restore), because core REST does not expose those post types. Writes are gated by the normal edit-post capability (manage_options for the palette), so only authenticated editors/admins (incl. Application Passwords) can use them.
 * Version: 1.5.1
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
if ( ! defined( 'D5B_REST_VERSION' ) ) { define( 'D5B_REST_VERSION', '1.5.1' ); }

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

	// GET /divi5-builder/v1/version  → lets the skill check which routes exist.
	register_rest_route( 'divi5-builder/v1', '/version', array(
		'methods'             => 'GET',
		'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
		'callback'            => function () { return array( 'version' => D5B_REST_VERSION ); },
	) );

	/*
	 * ---- Theme Builder layouts (v1.5) ----------------------------------------
	 * Divi's Theme Builder post types are not exposed by core REST, so headers,
	 * footers and body layouts could be neither inspected nor edited remotely.
	 * A header/footer is SITE-WIDE, so the write route is deliberately fussy:
	 *   - layout types only (pages/posts already have core REST);
	 *   - caller must send the md5 of the content it last read (expect_hash), so
	 *     an edit made in the Visual Builder in the meantime is never overwritten;
	 *   - block comments must balance, and the content must look like Divi;
	 *   - the previous content is kept in _d5b_prev_content for tb-layout-restore;
	 *   - the write is read back and auto-reverted if it did not round-trip.
	 * All four routes need edit_theme_options (what Divi's own Theme Builder needs).
	 */
	$tb_layout_types = array( 'et_header_layout', 'et_body_layout', 'et_footer_layout' );
	$tb_all_types    = array_merge( $tb_layout_types, array( 'et_template', 'et_theme_builder' ) );
	$tb_perm         = function () { return current_user_can( 'edit_theme_options' ); };

	$tb_format = function ( $content ) {
		if ( '' === trim( (string) $content ) ) { return 'empty'; }
		$d5 = false !== strpos( $content, '<!-- wp:divi/' );
		$d4 = false !== strpos( $content, '[et_pb_' );
		if ( $d5 && $d4 ) { return 'divi5+legacy-shortcodes'; }
		if ( $d5 ) { return 'divi5'; }
		if ( $d4 ) { return 'divi4'; }
		return 'other';
	};

	// Content reduced to what it MEANS: block names, decoded attributes, nesting, and the text between blocks
	// with whitespace runs removed. Two contents with equal trees render the same.
	$tb_tree = function ( $content ) {
		$walk = function ( $blocks ) use ( &$walk ) {
			$out = array();
			foreach ( $blocks as $b ) {
				$html = preg_replace( '/\s+/', '', (string) $b['innerHTML'] );
				if ( null === $b['blockName'] && '' === $html ) { continue; }
				$out[] = array( $b['blockName'], $b['attrs'], $html, $walk( $b['innerBlocks'] ) );
			}
			return $out;
		};
		return serialize( $walk( parse_blocks( (string) $content ) ) );
	};

	// Shared low-level writer. Returns array on success, WP_Error on failure.
	$tb_write = function ( $id, $content ) use ( &$tb_tree ) {
		$p   = get_post( $id );
		$old = (string) $p->post_content;
		// update_post_meta() and wp_update_post() both UNSLASH their input. Divi 5
		// block JSON is full of < style escapes, so unslashed writes corrupt it.
		update_post_meta( $id, '_d5b_prev_content', wp_slash( $old ) );
		update_post_meta( $id, '_d5b_prev_saved_at', gmdate( 'c' ) );
		$r = wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $content ) ), true );
		if ( is_wp_error( $r ) ) { return $r; }
		clean_post_cache( $id );
		$back = (string) get_post( $id )->post_content;
		// A save re-serialises every block (\" -> ", \\ -> \, -- -> --, wrapper closer
		// appended), so different bytes are not by themselves damage. What must survive is the block tree:
		// same blocks, same order, same nesting, same decoded attributes, same text between them.
		$rewritten = md5( $back ) !== md5( $content );
		if ( $rewritten && $tb_tree( $back ) !== $tb_tree( $content ) ) {
			wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $old ) ), true );
			clean_post_cache( $id );
			return new WP_Error( 'roundtrip_failed', 'content did not survive the save unchanged; previous content was put back', array(
				'status' => 500, 'sent_bytes' => strlen( $content ), 'stored_bytes' => strlen( $back ) ) );
		}
		$cleared = false;
		if ( class_exists( 'ET_Core_PageResource' ) && method_exists( 'ET_Core_PageResource', 'remove_static_resources' ) ) {
			ET_Core_PageResource::remove_static_resources( 'all', 'all' );
			$cleared = true;
		}
		// new_hash is the hash of what is STORED: that is what the next expect_hash has to match.
		return array( 'ok' => true, 'id' => $id, 'old_hash' => md5( $old ), 'new_hash' => md5( $back ),
			'old_bytes' => strlen( $old ), 'new_bytes' => strlen( $back ), 'sent_bytes' => strlen( $content ),
			'reserialised_on_save' => $rewritten, 'static_css_cache_cleared' => $cleared );
	};

	// GET /divi5-builder/v1/tb-list  → every template + layout, with format and links. No content.
	register_rest_route( 'divi5-builder/v1', '/tb-list', array(
		'methods'             => 'GET',
		'permission_callback' => $tb_perm,
		'callback'            => function () use ( $tb_all_types, $tb_format ) {
			$posts = get_posts( array(
				'post_type'        => $tb_all_types,
				'post_status'      => array( 'publish', 'draft', 'private', 'pending', 'future' ),
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			) );
			$out = array();
			foreach ( $posts as $p ) {
				$meta = array();
				foreach ( get_post_meta( $p->ID ) as $k => $vals ) {
					if ( 0 !== strpos( $k, '_et_' ) && 0 !== strpos( $k, '_d5b_' ) ) { continue; }
					$meta[ $k ] = array_map( function ( $v ) {
						return strlen( (string) $v ) > 300 ? '[' . strlen( (string) $v ) . ' bytes]' : $v;
					}, $vals );
				}
				$out[] = array(
					'id'       => $p->ID,
					'type'     => $p->post_type,
					'status'   => $p->post_status,
					'title'    => $p->post_title,
					'modified' => $p->post_modified_gmt,
					'format'   => $tb_format( $p->post_content ),
					'bytes'    => strlen( $p->post_content ),
					'hash'     => md5( $p->post_content ),
					'meta'     => $meta,
				);
			}
			return array( 'count' => count( $out ), 'items' => $out );
		},
	) );

	// GET /divi5-builder/v1/tb-layout?id=123  → raw, UNRENDERED post_content of one layout.
	register_rest_route( 'divi5-builder/v1', '/tb-layout', array(
		'methods'             => 'GET',
		'permission_callback' => $tb_perm,
		'callback'            => function ( $req ) use ( $tb_all_types, $tb_format ) {
			$id = (int) $req->get_param( 'id' );
			$p  = get_post( $id );
			if ( ! $p || ! in_array( $p->post_type, $tb_all_types, true ) ) {
				return new WP_Error( 'not_a_tb_post', 'id is not a Theme Builder template or layout', array( 'status' => 404 ) );
			}
			return array(
				'id'              => $id,
				'type'            => $p->post_type,
				'status'          => $p->post_status,
				'title'           => $p->post_title,
				'modified'        => $p->post_modified_gmt,
				'format'          => $tb_format( $p->post_content ),
				'bytes'           => strlen( $p->post_content ),
				'hash'            => md5( $p->post_content ),
				'has_backup'      => metadata_exists( 'post', $id, '_d5b_prev_content' ),
				'backup_saved_at' => get_post_meta( $id, '_d5b_prev_saved_at', true ),
				'content'         => $p->post_content,
			);
		},
	) );

	// POST /divi5-builder/v1/tb-layout { id, content, expect_hash, mark_divi5?, dry_run? }
	register_rest_route( 'divi5-builder/v1', '/tb-layout', array(
		'methods'             => 'POST',
		'permission_callback' => $tb_perm,
		'callback'            => function ( $req ) use ( $tb_layout_types, $tb_format, $tb_write ) {
			$id      = (int) $req->get_param( 'id' );
			$content = $req->get_param( 'content' );
			$expect  = (string) $req->get_param( 'expect_hash' );
			$p       = get_post( $id );
			if ( ! $p || ! in_array( $p->post_type, $tb_layout_types, true ) ) {
				return new WP_Error( 'not_a_layout', 'id is not a header, body or footer layout', array( 'status' => 404 ) );
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return new WP_Error( 'forbidden', 'cannot edit this layout', array( 'status' => 403 ) );
			}
			// Without unfiltered_html, WordPress runs kses over post_content and mangles block JSON.
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				return new WP_Error( 'needs_unfiltered_html', 'this user lacks unfiltered_html; WordPress would sanitise and corrupt the layout', array( 'status' => 403 ) );
			}
			if ( ! is_string( $content ) || '' === trim( $content ) ) {
				return new WP_Error( 'empty_content', 'refusing to write empty content to a site-wide layout', array( 'status' => 400 ) );
			}
			if ( '' === $expect ) {
				return new WP_Error( 'expect_hash_required', 'send expect_hash = the hash returned by GET tb-layout', array( 'status' => 400 ) );
			}
			$current = md5( (string) $p->post_content );
			if ( ! hash_equals( $current, $expect ) ) {
				return new WP_Error( 'stale', 'layout changed since it was read (edited in the Visual Builder?). Read it again.', array(
					'status' => 409, 'current_hash' => $current, 'modified' => $p->post_modified_gmt ) );
			}
			$fmt = $tb_format( $content );
			if ( 'other' === $fmt ) {
				return new WP_Error( 'not_divi', 'content has neither Divi 5 blocks nor Divi shortcodes', array( 'status' => 400 ) );
			}
			$open  = preg_match_all( '/<!--\s+wp:/', $content );
			$self  = preg_match_all( '/<!--\s+wp:(?:(?!-->).)*?\/-->/s', $content );
			$close = preg_match_all( '/<!--\s+\/wp:/', $content );
			if ( ( $open - $self ) !== $close ) {
				return new WP_Error( 'unbalanced_blocks', 'block comments do not balance', array(
					'status' => 400, 'openers' => $open, 'self_closing' => $self, 'closers' => $close ) );
			}
			if ( $req->get_param( 'dry_run' ) ) {
				return array( 'ok' => true, 'dry_run' => true, 'id' => $id, 'would_write_bytes' => strlen( $content ), 'format' => $fmt, 'current_hash' => $current );
			}
			$r = $tb_write( $id, $content );
			if ( is_wp_error( $r ) ) { return $r; }
			if ( $req->get_param( 'mark_divi5' ) ) {
				update_post_meta( $id, '_et_pb_use_builder', 'on' );
				update_post_meta( $id, '_et_pb_use_divi_5', 'on' );
				$r['marked_divi5'] = true;
			}
			$r['format'] = $fmt;
			return $r;
		},
	) );

	// POST /divi5-builder/v1/tb-layout-restore { id }  → swap content with the kept backup (itself undoable).
	register_rest_route( 'divi5-builder/v1', '/tb-layout-restore', array(
		'methods'             => 'POST',
		'permission_callback' => $tb_perm,
		'callback'            => function ( $req ) use ( $tb_layout_types, $tb_write ) {
			$id = (int) $req->get_param( 'id' );
			$p  = get_post( $id );
			if ( ! $p || ! in_array( $p->post_type, $tb_layout_types, true ) ) {
				return new WP_Error( 'not_a_layout', 'id is not a header, body or footer layout', array( 'status' => 404 ) );
			}
			if ( ! current_user_can( 'edit_post', $id ) || ! current_user_can( 'unfiltered_html' ) ) {
				return new WP_Error( 'forbidden', 'cannot edit this layout', array( 'status' => 403 ) );
			}
			if ( ! metadata_exists( 'post', $id, '_d5b_prev_content' ) ) {
				return new WP_Error( 'no_backup', 'no previous content stored for this layout', array( 'status' => 404 ) );
			}
			$prev = (string) get_post_meta( $id, '_d5b_prev_content', true );
			if ( '' === trim( $prev ) ) {
				return new WP_Error( 'empty_backup', 'stored backup is empty; refusing to restore it', array( 'status' => 409 ) );
			}
			$r = $tb_write( $id, $prev );
			if ( is_wp_error( $r ) ) { return $r; }
			$r['restored'] = true;
			return $r;
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
