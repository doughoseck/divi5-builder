<?php
/**
 * Read abilities for Divi (#32, release 2.1.0, part 3): the design system, Theme Builder templates and layouts, canvases
 * (popups, off-canvas menus) and the builder status of a post. Uses the helpers of read-content.php (d5b_rc_*), which is
 * loaded first, and the page-building reads in parts/page-building.php (d5b_ds_overview, d5b_tb_list_data,
 * d5b_tb_layout_data): the REST routes and these abilities call the same functions, so they return the same bytes.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included; nothing is
 * registered with WordPress from here.
 *
 * Rules (the same as read-content.php, plus):
 *  - Capabilities match the existing routes: manage_options for the design system, edit_theme_options for Theme Builder,
 *    edit_post (the one id) for canvases and builder status.
 *  - Theme Builder templates and layouts and canvases are Divi's internal post types: they are reachable only through the
 *    divi category, never through the generic cpt abilities, and no other category switch governs them.
 *  - The builder status of an ordinary post or page follows the switch of that post's own category (strictest switch wins).
 *  - A hash is md5 of the raw post_content, the same as wp.js --expect-hash.
 *  - The design system (colours, variables, presets) is Divi's own structured data, not post content and not third-party
 *    data: it is declared neither as content nor as raw, so every string goes through the STRICT masker.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_dv_h_design_system' ) ) {

	/** The post types that belong to Divi itself: reachable through the divi category only. */
	function d5b_dv_own_types(): array {
		return array( 'et_pb_canvas', 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'et_theme_builder', 'et_pb_layout' );
	}

	/** Strictest switch wins: Divi's own types need only the divi category (already on, or the ability would not run); every other type needs its own. */
	function d5b_dv_type_on( string $type ): bool {
		return in_array( $type, d5b_dv_own_types(), true ) || d5b_rc_type_on( $type );
	}

	/** A WP_Error 'unavailable' when one of the page-building functions is missing (a broken load), else null. */
	function d5b_dv_need( array $fns ): ?WP_Error {
		foreach ( $fns as $f ) {
			if ( ! function_exists( $f ) ) { return new WP_Error( 'unavailable', 'This part of the plugin is not loaded on this site.' ); }
		}
		return null;
	}

	function d5b_dv_forbidden(): WP_Error {
		return new WP_Error( 'forbidden', 'You do not have permission to use this ability.' );
	}

	// ---- design system

	function d5b_dv_h_design_system( array $in ) {
		if ( ! current_user_can( 'manage_options' ) ) { return d5b_dv_forbidden(); }
		$full = false;
		if ( array_key_exists( 'full', $in ) ) {
			$v = $in['full'];
			if ( null !== $v && '' !== $v ) {
				if ( ! is_bool( $v ) ) { return new WP_Error( 'invalid_input', 'full must be true or false.' ); }
				$full = $v;
			}
			unset( $in['full'] );
		}
		$a = d5b_rc_args( $in, array( 'full' => 'key' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$need = d5b_dv_need( array( 'd5b_ds_overview' ) );
		return null !== $need ? $need : d5b_ds_overview( $full );
	}

	// ---- Theme Builder

	function d5b_dv_h_tb_list( array $in ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) { return d5b_dv_forbidden(); }
		$a = d5b_rc_args( $in, array() );
		if ( is_wp_error( $a ) ) { return $a; }
		$need = d5b_dv_need( array( 'd5b_tb_list_data' ) );
		return null !== $need ? $need : d5b_tb_list_data();
	}

	function d5b_dv_h_tb_get( array $in ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) { return d5b_dv_forbidden(); }
		$a = d5b_rc_args( $in, array( 'id' => 'id' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$need = d5b_dv_need( array( 'd5b_tb_layout_data' ) );
		return null !== $need ? $need : d5b_tb_layout_data( $a['id'] );
	}

	// ---- canvases

	/** A canvas the caller may edit, or null (missing, another kind of post, trashed and not editable all look the same). */
	function d5b_dv_canvas( $id ) {
		$id = d5b_rc_id_of( $id );
		if ( $id < 1 ) { return null; }
		$p = get_post( $id );
		if ( ! is_object( $p ) || 'et_pb_canvas' !== (string) $p->post_type || in_array( (string) $p->post_status, array( 'auto-draft', 'trash' ), true ) ) { return null; }
		return current_user_can( 'edit_post', $p->ID ) ? $p : null;
	}

	function d5b_dv_canvas_row( $p ): array {
		return array(
			'id'             => (int) $p->ID,
			'title'          => (string) $p->post_title,
			'status'         => (string) $p->post_status,
			'modified'       => (string) $p->post_modified_gmt,
			'parent_page_id' => (int) get_post_meta( $p->ID, '_divi_canvas_parent_post_id', true ),
			'canvas_uuid'    => (string) get_post_meta( $p->ID, '_divi_canvas_id', true ),
		);
	}

	function d5b_dv_h_canvases_list( array $in ) {
		if ( ! current_user_can( 'edit_posts' ) ) { return d5b_dv_forbidden(); }
		$a = d5b_rc_args( $in, array( 'per_page' => 'int', 'page' => 'int' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		list( $per, $page ) = d5b_rc_paging( $a );
		$q = new WP_Query( array( 'post_type' => 'et_pb_canvas', 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'posts_per_page' => $per, 'paged' => $page, 'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true ) );
		$items = array(); $dropped = false;
		foreach ( (array) $q->posts as $p ) {
			if ( ! is_object( $p ) || null === d5b_dv_canvas( $p->ID ) ) { $dropped = true; continue; }
			$items[] = d5b_dv_canvas_row( $p );
		}
		$out = array( 'items' => $items, 'page' => $page, 'per_page' => $per );
		if ( ! $dropped ) {
			$out['total']       = (int) $q->found_posts;
			$out['total_pages'] = max( 1, (int) ceil( $out['total'] / $per ) );
		}
		return $out;
	}

	function d5b_dv_h_canvas_get( array $in ) {
		$a = d5b_rc_args( $in, array( 'id' => 'id' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$p = d5b_dv_canvas( $a['id'] );
		if ( null === $p ) { return new WP_Error( 'not_found', 'No canvas with that id that you may read.' ); }
		$need = d5b_dv_need( array( 'd5b_tb_format' ) );
		if ( null !== $need ) { return $need; }
		return d5b_dv_canvas_row( $p ) + array(
			'format'  => d5b_tb_format( $p->post_content ),
			'bytes'   => strlen( (string) $p->post_content ),
			'hash'    => d5b_rc_hash( $p ),
			'content' => (string) $p->post_content,
		);
	}

	// ---- builder status

	/** A post the caller may edit, of any type. $strict also needs the switch of the post's own category (Divi's own types need none). */
	function d5b_dv_status_post( $id, bool $strict = true ) {
		$id = d5b_rc_id_of( $id );
		if ( $id < 1 ) { return null; }
		$p = get_post( $id );
		if ( ! is_object( $p ) || ! isset( $p->post_type ) || in_array( (string) $p->post_status, array( 'auto-draft', 'trash' ), true ) ) { return null; }
		if ( ! current_user_can( 'edit_post', $p->ID ) ) { return null; }
		if ( $strict && ! d5b_dv_type_on( (string) $p->post_type ) ) { return null; }
		return $p;
	}

	function d5b_dv_h_builder_status( array $in ) {
		$a = d5b_rc_args( $in, array( 'post_id' => 'id' ), array( 'post_id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$p = d5b_dv_status_post( $a['post_id'], false );
		if ( null === $p ) { return new WP_Error( 'not_found', 'No item with that id that you may read.' ); }
		if ( ! d5b_dv_type_on( (string) $p->post_type ) ) {
			return new WP_Error( 'not_allowed', 'The ability category for this kind of content is switched off.' );
		}
		$need = d5b_dv_need( array( 'd5b_tb_format' ) );
		if ( null !== $need ) { return $need; }
		$meta  = function ( $key ) use ( $p ) { return (string) get_post_meta( $p->ID, $key, true ); };
		$d5    = 'on' === $meta( '_et_pb_use_divi_5' );
		$use   = 'on' === $meta( '_et_pb_use_builder' );
		$off   = get_post_meta( $p->ID, '_divi_off_canvas_data', true );
		return array(
			'post_id'             => (int) $p->ID,
			'type'                => (string) $p->post_type,
			'status'              => (string) $p->post_status,
			'builder_mode'        => $use ? ( $d5 ? 'divi5' : 'divi4' ) : 'off',
			'uses_builder'        => $use,
			'uses_divi_5'         => $d5,
			'builder_version'     => $meta( '_et_builder_version' ),
			'page_layout'         => $meta( '_et_pb_page_layout' ),
			'side_nav'            => $meta( '_et_pb_side_nav' ),
			'built_for_post_type' => $meta( '_et_pb_built_for_post_type' ),
			'content_format'      => d5b_tb_format( $p->post_content ),
			'off_canvas'          => is_array( $off ) ? array( 'active_canvas_id' => (string) ( $off['activeCanvasId'] ?? '' ), 'main_canvas_name' => (string) ( $off['mainCanvasName'] ?? '' ) ) : null,
		);
	}

	// ---- the definitions

	$d5b_dv_id_prop = function ( string $what ) { return array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the ' . $what . ' to read.' ) ); };

	d5b_rc_def( 'divi-design-system', 'divi', 'Get the Divi design system',
		'Returns the site\'s Divi 5 design system: the presets (id, name, module or option group, which one is the default), the design variables and the global colours. Refuses a caller who is not an administrator (manage_options). Reads only, changes nothing.',
		array( 'full' => array( 'type' => 'boolean', 'default' => false, 'description' => 'true also returns the settings of every preset. The result can be very large: leave it out unless you need the settings.' ) ),
		array(), 'd5b_dv_h_design_system', function ( array $in ) { return current_user_can( 'manage_options' ); } );
	d5b_rc_def( 'divi-tb-list', 'divi', 'List Theme Builder templates',
		'Returns every Theme Builder template and header, body and footer layout: id, type, status, title, format, size and a hash of the content, without the content itself. Refuses a caller who may not edit theme options.',
		array(), array(), 'd5b_dv_h_tb_list', function ( array $in ) { return current_user_can( 'edit_theme_options' ); } );
	d5b_rc_def( 'divi-tb-get', 'divi', 'Get a Theme Builder layout',
		'Returns one Theme Builder template or layout with its raw, unrendered content and a hash of that content (md5 of the raw content, the stale check for writes). Refuses a caller who may not edit theme options, and an id that is not a Theme Builder item.',
		$d5b_dv_id_prop( 'Theme Builder template or layout' ), array( 'id' ), 'd5b_dv_h_tb_get', function ( array $in ) { return current_user_can( 'edit_theme_options' ); } );
	d5b_rc_def( 'divi-canvases-list', 'divi', 'List Divi canvases',
		'Returns one page of Divi canvases (popups and off-canvas areas): id, title, status, the page each is linked to and its canvas id. Refuses a caller who may not edit posts, and leaves out canvases the caller may not edit.',
		d5b_rc_paging_props(), array(), 'd5b_dv_h_canvases_list', function ( array $in ) { return current_user_can( 'edit_posts' ); } );
	d5b_rc_def( 'divi-canvas-get', 'divi', 'Get a Divi canvas',
		'Returns one Divi canvas with its raw, unrendered content and a hash of that content (md5 of the raw content, the stale check for writes). Refuses a canvas the caller may not edit, and an id that is not a canvas.',
		$d5b_dv_id_prop( 'canvas' ), array( 'id' ), 'd5b_dv_h_canvas_get', function ( array $in ) { return current_user_can( 'edit_posts' ) && null !== d5b_dv_canvas( $in['id'] ?? 0 ); } );
	d5b_rc_def( 'divi-builder-status', 'divi', 'Get the Divi builder status of a post',
		'Returns how Divi treats one post or page: whether it uses the builder, Divi 5 or older Divi, the builder version, page layout and the format of its content. Refuses a post the caller may not edit, and a post whose own category (posts, pages or custom post types) is switched off.',
		array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the post, page or other item to check.' ) ),
		array( 'post_id' ), 'd5b_dv_h_builder_status', function ( array $in ) { return null !== d5b_dv_status_post( $in['post_id'] ?? 0, true ); } );

	unset( $d5b_dv_id_prop );

	// Theme Builder and canvas content is post content: value-shape masking only, so it stays editable and its hash still
	// matches the raw content. The design system is Divi's own structured data: neither content nor raw, STRICT masking.
	d5b_ability_set_content_keys( array( 'divi-tb-get', 'divi-canvas-get' ) );
}
