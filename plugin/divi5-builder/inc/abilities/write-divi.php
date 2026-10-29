<?php
/**
 * Write abilities for Divi (#32, release 2.2.0): builder mode, the canvas link, the design-system writes and backups, the
 * Theme Builder layout write and restore, and the two content edits (divi-section-edit: one block; divi-text-edit: one piece
 * of visible text), which use the blocks engine (inc/blocks.php). Uses read-content.php (d5b_rc_*), read-divi.php (d5b_dv_*) and
 * write-common.php (d5b_wc_*), all loaded first, and the page-building functions in parts/page-building.php
 * (d5b_ds_*_upsert / _archive / _read / _restore, d5b_tb_write, d5b_tb_restore, d5b_link_canvas): the REST routes and these
 * abilities call the same functions, so they refuse and answer the same way.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included; nothing is
 * registered with WordPress from here.
 *
 * Rules (design 2026-10-29, "Divi"):
 *  - The ability adds the switch gate, the audit row, the change-history row and the masked-read check; behaviour and
 *    refusals are the routes' own.
 *  - The design-system writes never take dry_run: an AI client writes or it does not.
 *  - A masked read is never written back: a layout or design-system body holding [redacted] (where the stored data does
 *    not) is refused with masked_read.
 *  - Every write keeps the full "before" in the change history: both builder flags, the canvas link meta of both posts,
 *    the whole design-system store, the whole previous layout with its safety copy.
 *  - d5b_wd_target() is the one answer to "may a Divi content edit reach this id" (Task 9 uses it too).
 *  - The legacy /global-colors (whole-store replace) and /option routes have no ability.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wd_target' ) ) {

	/** Divi's Theme Builder layout types (the same list as page-building.php's d5b_tb_layout_types). */
	function d5b_wd_tb_types(): array {
		return function_exists( 'd5b_tb_layout_types' ) ? d5b_tb_layout_types() : array( 'et_header_layout', 'et_body_layout', 'et_footer_layout' );
	}

	/**
	 * Which content a Divi edit may reach, and how (the spec's target table). $html false leaves out the unfiltered_html
	 * check (for writes that change meta, not content).
	 *  - post, page, public custom items: their own category on (strictest switch wins) and Divi; edit_post on the item.
	 *  - Theme Builder layouts (header, body, footer): Divi; edit_theme_options; via 'tb' (d5b_tb_write).
	 *  - canvases (et_pb_canvas) and Divi Library items (et_pb_layout): Divi; edit_post on the item.
	 *  - templates (et_template, et_theme_builder) and everything else: not_allowed.
	 * @return array|WP_Error array( 'post' => WP_Post, 'via' => 'post'|'tb', 'type' => post type )
	 */
	function d5b_wd_reach( int $post_id, bool $html ) {
		$p = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! is_object( $p ) || ! isset( $p->post_type ) || 'auto-draft' === (string) $p->post_status ) {
			return new WP_Error( 'not_found', 'No item with that id.' );
		}
		$type = (string) $p->post_type;
		$id   = (int) $p->ID;
		if ( in_array( $type, d5b_wd_tb_types(), true ) ) {
			$via = 'tb';
		} elseif ( in_array( $type, array( 'et_pb_canvas', 'et_pb_layout' ), true ) ) {
			$via = 'post';
		} elseif ( 'post' === $type || 'page' === $type || isset( d5b_rc_exposed_types( true )[ $type ] ) ) {
			$via = 'post';
		} else {
			return new WP_Error( 'not_allowed', 'Divi edits cannot reach this kind of item (' . $type . '): only posts, pages, custom items, Theme Builder header, body and footer layouts, canvases and Divi Library items.' );
		}
		$own = in_array( $type, d5b_dv_own_types(), true );
		if ( ! d5b_abilities_category_on( 'divi' ) || ( ! $own && ! d5b_rc_type_on( $type ) ) ) {
			return new WP_Error( 'not_allowed', 'The ability category for this kind of content is switched off.' );
		}
		if ( 'trash' === (string) $p->post_status ) { return new WP_Error( 'invalid_input', 'This item is in the trash: restore it first.' ); }
		if ( 'tb' === $via ) {
			if ( ! current_user_can( 'edit_theme_options' ) ) { return new WP_Error( 'not_allowed', 'Theme Builder layouts need the right to edit theme options.' ); }
		} elseif ( ! current_user_can( 'edit_post', $id ) ) {
			// An item the caller may read is not_allowed; one they may not even read looks missing.
			return ( ! $own && current_user_can( 'read_post', $id ) ) ? new WP_Error( 'not_allowed', 'You may not change this item.' ) : new WP_Error( 'not_found', 'No item with that id.' );
		}
		if ( $html && ! current_user_can( 'unfiltered_html' ) ) {
			return new WP_Error( 'needs_unfiltered_html', 'Divi content needs the unfiltered_html capability (an administrator or editor on a single site). Without it WordPress would strip parts of the block data.' );
		}
		return array( 'post' => $p, 'via' => $via, 'type' => $type );
	}

	/** d5b_wd_reach with the unfiltered_html check: may a Divi content edit reach this id. @return array|WP_Error */
	function d5b_wd_target( int $post_id ) {
		return d5b_wd_reach( $post_id, true );
	}

	/** The stored rows of these meta keys, as the history keeps them (key => list of values; [] = the key is absent). */
	function d5b_wd_meta_rows( int $id, array $keys ): array {
		$out = array();
		foreach ( $keys as $k ) { $out[ $k ] = array_map( 'd5b_wc_meta_value', array_values( (array) get_post_meta( $id, $k, false ) ) ); }
		return $out;
	}

	/** The words for a Theme Builder layout in a summary. */
	function d5b_wd_tb_what( string $type ): string {
		$map = array( 'et_header_layout' => 'Theme Builder header layout', 'et_body_layout' => 'Theme Builder body layout', 'et_footer_layout' => 'Theme Builder footer layout' );
		return $map[ $type ] ?? $type;
	}

	/** The result of a write with the history flag. */
	function d5b_wd_out( array $out, bool $stored ): array {
		if ( ! $stored ) { $out['_history_failed'] = true; }
		return $out;
	}

	// ---- builder mode

	/** @return array|WP_Error */
	function d5b_wd_h_builder_mode( array $in ) {
		$a = d5b_rc_args( $in, array( 'post_id' => 'id', 'on' => 'bool' ), array( 'post_id', 'on' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$t = d5b_wd_target( $a['post_id'] );
		if ( is_wp_error( $t ) ) { return $t; }
		$p  = $t['post'];
		$id = (int) $p->ID;
		if ( ! current_user_can( 'edit_post', $id ) ) { return new WP_Error( 'not_allowed', 'You may not change this item.' ); }
		$keys   = array( '_et_pb_use_builder', '_et_pb_use_divi_5' );
		$before = d5b_wd_meta_rows( $id, $keys );
		if ( $a['on'] ) {
			update_post_meta( $id, '_et_pb_use_builder', 'on' );
			update_post_meta( $id, '_et_pb_use_divi_5', 'on' );
		} else {
			// Off keeps the Divi 5 flag: switching back on brings the page back as it was.
			update_post_meta( $id, '_et_pb_use_builder', 'off' );
		}
		$after  = d5b_wd_meta_rows( $id, $keys );
		$stored = d5b_wc_history( 'divi5-builder/divi-builder-mode', $p, 'update', $before, $after, 0, d5b_wc_summary( 'Turned the Divi builder ' . ( $a['on'] ? 'on' : 'off' ) . ' for', (string) $p->post_type, $p ) );
		return d5b_wd_out( array(
			'post_id' => $id, 'type' => (string) $p->post_type, 'builder' => $a['on'] ? 'on' : 'off',
			'uses_divi_5' => 'on' === (string) get_post_meta( $id, '_et_pb_use_divi_5', true ), 'changed' => $before !== $after,
		), $stored );
	}

	function d5b_wd_can_builder_mode( array $in ): bool {
		if ( ! current_user_can( 'read' ) ) { return false; }
		$id = d5b_rc_id_of( $in['post_id'] ?? 0 );
		return $id > 0 && ! is_wp_error( d5b_wd_target( $id ) ) && current_user_can( 'edit_post', $id );
	}

	// ---- canvas link

	/** The meta /link-canvas writes, on both posts: the full "before" (and "after") of a link. */
	function d5b_wd_link_state( int $page_id, int $canvas_id ): array {
		return array(
			'page'   => array( 'id' => $page_id, 'meta' => d5b_wd_meta_rows( $page_id, array( '_divi_off_canvas_data', '_et_pb_use_divi_5', '_et_pb_use_builder' ) ) ),
			'canvas' => array( 'id' => $canvas_id, 'meta' => d5b_wd_meta_rows( $canvas_id, array( '_divi_canvas_id', '_divi_canvas_parent_post_id', '_divi_canvas_created_at' ) ) ),
		);
	}

	/** The page a canvas is linked to: a post, page or custom item, its category on, editable. @return WP_Post|WP_Error */
	function d5b_wd_link_page( $post_id ) {
		$id = d5b_rc_id_of( $post_id );
		$t  = d5b_wd_reach( $id, false );
		if ( is_wp_error( $t ) ) { return $t; }
		if ( 'post' !== $t['via'] || in_array( $t['type'], d5b_dv_own_types(), true ) ) {
			return new WP_Error( 'not_allowed', 'A canvas is linked to a post, page or custom item, not to a ' . $t['type'] . '.' );
		}
		return $t['post'];
	}

	/** The canvas side: a post the caller may edit (the route's permission); its type is checked by d5b_link_canvas. */
	function d5b_wd_link_canvas_ok( $canvas_id ): bool {
		$id = d5b_rc_id_of( $canvas_id );
		return $id > 0 && is_object( get_post( $id ) ) && current_user_can( 'edit_post', $id );
	}

	/** @return array|WP_Error */
	function d5b_wd_h_link_canvas( array $in ) {
		$a = d5b_rc_args( $in, array( 'post_id' => 'id', 'canvas_id' => 'id' ), array( 'post_id', 'canvas_id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$need = d5b_dv_need( array( 'd5b_link_canvas' ) );
		if ( null !== $need ) { return $need; }
		$p = d5b_wd_link_page( $a['post_id'] );
		if ( is_wp_error( $p ) ) { return $p; }
		if ( ! d5b_wd_link_canvas_ok( $a['canvas_id'] ) ) { return new WP_Error( 'not_found', 'No canvas with that id that you may edit.' ); }
		$before = d5b_wd_link_state( (int) $p->ID, $a['canvas_id'] );
		$r = d5b_link_canvas( (int) $p->ID, $a['canvas_id'] );
		if ( is_wp_error( $r ) ) { return $r; }
		$np     = get_post( (int) $p->ID );
		$stored = d5b_wc_history( 'divi5-builder/divi-link-canvas', is_object( $np ) ? $np : $p, 'update', $before, d5b_wd_link_state( (int) $p->ID, $a['canvas_id'] ), 0, d5b_wc_summary( 'Linked canvas ' . $a['canvas_id'] . ' to', (string) $p->post_type, $p ) );
		return d5b_wd_out( $r, $stored );
	}

	function d5b_wd_can_link_canvas( array $in ): bool {
		if ( ! current_user_can( 'read' ) ) { return false; }
		return ! is_wp_error( d5b_wd_link_page( $in['post_id'] ?? 0 ) ) && d5b_wd_link_canvas_ok( $in['canvas_id'] ?? 0 );
	}

	// ---- design system

	/**
	 * The writes: ability short name => array( named function, store, input properties ). The properties are the fields the
	 * function reads from its body; there is no dry_run.
	 */
	function d5b_wd_ds_writes(): array {
		$s = function ( string $d, array $extra = array() ) { return array_merge( array( 'type' => 'string', 'description' => $d ), $extra ); };
		return array(
			'divi-ds-color-set' => array( 'd5b_ds_color_upsert', 'colors', array(
				'label'  => $s( 'The colour\'s name as the builder shows it. A colour with this label (or with id) is updated, else one is created.' ),
				'color'  => $s( 'The colour: a hex (#2b5c7a), rgb()/rgba(), hsl()/hsla(), or a $variable(...)$ token pointing at another global colour.' ),
				'id'     => $s( 'Optional: the colour id to create or update, gcid- followed by lowercase letters, digits or hyphens. The five Customizer ids (gcid-primary-color and the like) are written to the theme options.' ),
				'status' => $s( 'active (the default) or inactive.', array( 'enum' => array( 'active', 'inactive' ) ) ),
			) ),
			'divi-ds-variable-set' => array( 'd5b_ds_variable_upsert', 'variables', array(
				'type'  => $s( 'The kind of variable: numbers, strings, images, links, fonts or gradients (colours are divi-ds-color-set).', array( 'enum' => array( 'numbers', 'strings', 'images', 'links', 'fonts', 'gradients' ) ) ),
				'label' => $s( 'The variable\'s name. An active variable of this type with this label (or the variable with id) is updated, else one is created.' ),
				'value' => array( 'type' => array( 'string', 'number' ), 'description' => 'The value, at most 1 MB. A number or text may reference other variables (var(--gvid-...) or a $variable(...)$ token): each must exist, be active, be of the same type and not lead back to this one.' ),
				'id'    => $s( 'Optional: the variable id, gvid- followed by lowercase letters, digits or hyphens. An archived variable given by id is brought back.' ),
			) ),
			'divi-ds-variable-archive' => array( 'd5b_ds_variable_archive', 'variables', array(
				'id' => $s( 'The id of the variable to archive (gvid-...; see divi-design-system).' ),
			) ),
			'divi-ds-preset-set' => array( 'd5b_ds_preset_upsert', 'presets', array(
				'moduleName'      => $s( 'The module the preset is for, like divi/button.' ),
				'name'            => $s( 'The preset\'s name. A preset of this module (or group) with this name, or the preset with id, is updated, else one is created.' ),
				'attrs'           => array( 'type' => 'object', 'description' => 'The module settings the preset holds, in Divi 5 block attribute form. Content (texts, links, image sources, the admin label) is left out; Divi splits the rest into its style and render parts.' ),
				'kind'            => $s( 'module (the default) or group (an option group preset).', array( 'enum' => array( 'module', 'group' ) ) ),
				'id'              => $s( 'Optional: the preset id, 6 to 32 lowercase letters or digits.' ),
				'groupName'       => $s( 'For a group preset: the option group, like divi/font.' ),
				'groupId'         => $s( 'For a group preset: the path of the group in attrs, like title.decoration.font.' ),
				'primaryAttrName' => $s( 'For a group preset: Divi\'s primary attribute name of the group.' ),
				'groupPresets'    => array( 'type' => 'object', 'description' => 'For a module preset: the option group presets it uses, as Divi stores them.' ),
				'priority'        => array( 'type' => 'integer', 'description' => 'Optional: the preset\'s priority, as Divi stores it.' ),
				'setDefault'      => array( 'type' => 'boolean', 'description' => 'true makes this preset the default of its module or group.' ),
			) ),
		);
	}

	/** The body's fields checked against their properties; unknown fields (dry_run included) are refused. @return true|WP_Error */
	function d5b_wd_ds_check( array $in, array $props ) {
		foreach ( $in as $k => $v ) {
			$k = (string) $k;
			if ( ! isset( $props[ $k ] ) ) {
				return new WP_Error( 'invalid_input', 'Unknown input "' . substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $k ), 0, 100 ) . '". Allowed: ' . implode( ', ', array_keys( $props ) ) . '.' );
			}
			$ok = false;
			foreach ( (array) $props[ $k ]['type'] as $type ) {
				if ( ( 'string' === $type && is_string( $v ) ) || ( 'number' === $type && ( is_int( $v ) || is_float( $v ) ) ) || ( 'integer' === $type && is_int( $v ) )
					|| ( 'boolean' === $type && null !== d5b_rc_bool( $v ) ) || ( 'object' === $type && is_array( $v ) && ( array() === $v || ! d5b_rc_is_list( $v ) ) ) ) { $ok = true; }
			}
			if ( $ok && isset( $props[ $k ]['enum'] ) && ! in_array( $v, $props[ $k ]['enum'], true ) ) { $ok = false; }
			if ( ! $ok ) { return new WP_Error( 'invalid_input', $k . ' is not valid.' ); }
		}
		return true;
	}

	/**
	 * One design-system write: the body checked, the whole store read (the history "before"), the named function called
	 * with the body and $dry = false, the history row written.
	 * @return array|WP_Error
	 */
	function d5b_wd_ds_write( string $short, array $in ) {
		if ( ! function_exists( 'd5b_ds_can' ) || ! d5b_ds_can() ) { return d5b_dv_forbidden(); }
		list( $fn, $store, $props ) = d5b_wd_ds_writes()[ $short ];
		$need = d5b_dv_need( array( $fn, 'd5b_ds_read' ) );
		if ( null !== $need ) { return $need; }
		$ok = d5b_wd_ds_check( $in, $props );
		if ( true !== $ok ) { return $ok; }
		// "true" and "1" from a query string are what core's schema check takes for a boolean: the function gets a real one.
		if ( array_key_exists( 'setDefault', $in ) ) { $in['setDefault'] = (bool) d5b_rc_bool( $in['setDefault'] ); }
		$before = d5b_ds_read( $store );
		if ( is_wp_error( $before ) ) { return $before; }
		// A value read through divi-design-system can come back masked: [redacted] is never written over the real value.
		$ok = d5b_wc_check_masked( (string) wp_json_encode( $in ), (string) wp_json_encode( $before ) );
		if ( true !== $ok ) { return $ok; }
		$r = call_user_func( $fn, $in, false );
		if ( is_wp_error( $r ) || ! is_array( $r ) ) { return is_wp_error( $r ) ? $r : new WP_Error( 'write_failed', 'The design system was not changed.' ); }
		// Already archived: nothing was written, so there is nothing to record.
		if ( 'unchanged' === ( $r['action'] ?? '' ) ) { return $r; }
		$archive = 'divi-ds-variable-archive' === $short;
		$action  = $archive ? 'trash' : ( 'created' === ( $r['action'] ?? '' ) ? 'create' : 'update' );
		$label   = (string) ( $r['label'] ?? ( $r['item']['label'] ?? ( $r['item']['name'] ?? '' ) ) );
		$verb    = $archive ? 'Archived' : ( 'create' === $action ? 'Created' : 'Updated' );
		$word    = array( 'colors' => 'colour', 'variables' => 'variable', 'presets' => 'preset' )[ $store ];
		$stored  = function_exists( 'd5b_history_write' ) && d5b_history_write( array(
			'ability' => 'divi5-builder/' . $short, 'object_type' => 'divi_' . $store, 'object_id' => 0, 'action' => $action,
			'before' => $before, 'after' => $r, 'revision_id' => 0,
			'summary' => $verb . ' the Divi ' . $word . ' ' . (string) ( $r['id'] ?? '' ) . ( '' === $label ? '' : " '" . d5b_rc_cut( $label, 80 ) . "'" ),
		) ) > 0;
		return d5b_wd_out( $r, $stored );
	}

	function d5b_wd_h_ds_color_set( array $in ) { return d5b_wd_ds_write( 'divi-ds-color-set', $in ); }
	function d5b_wd_h_ds_variable_set( array $in ) { return d5b_wd_ds_write( 'divi-ds-variable-set', $in ); }
	function d5b_wd_h_ds_variable_archive( array $in ) { return d5b_wd_ds_write( 'divi-ds-variable-archive', $in ); }
	function d5b_wd_h_ds_preset_set( array $in ) { return d5b_wd_ds_write( 'divi-ds-preset-set', $in ); }

	function d5b_wd_can_ds( array $in ): bool {
		return function_exists( 'd5b_ds_can' ) && (bool) d5b_ds_can();
	}

	/** The kept backups of one store, oldest index last: list of [ index, entry ]. */
	function d5b_wd_ds_backups( string $store ): array {
		$all  = get_option( 'd5b_ds_backups', array() );
		$list = ( is_array( $all ) && isset( $all[ $store ] ) && is_array( $all[ $store ] ) ) ? $all[ $store ] : array();
		$out  = array();
		foreach ( $list as $i => $b ) { if ( is_array( $b ) ) { $out[] = array( (int) $i, $b ); } }
		return $out;
	}

	/** The backups route's list, with time as the id (the index shifts with every write). @return array|WP_Error */
	function d5b_wd_h_ds_backups_list( array $in ) {
		if ( ! d5b_wd_can_ds( $in ) ) { return d5b_dv_forbidden(); }
		$a = d5b_rc_args( $in, array( 'store' => 'enum:presets|variables|colors' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$rows = array();
		foreach ( isset( $a['store'] ) ? array( $a['store'] ) : array( 'presets', 'variables', 'colors' ) as $s ) {
			foreach ( d5b_wd_ds_backups( $s ) as $pair ) {
				$b = $pair[1];
				$rows[] = array( 'store' => $s, 'time' => (string) ( $b['time'] ?? '' ), 'firstOfDay' => ! empty( $b['pinned'] ), 'bytes' => strlen( (string) wp_json_encode( $b['value'] ?? null ) ) );
			}
		}
		return array( 'ok' => true, 'backups' => $rows );
	}

	/** Restores the backup of $store whose time is $time, resolved to its index now. @return array|WP_Error */
	function d5b_wd_h_ds_restore( array $in ) {
		if ( ! d5b_wd_can_ds( $in ) ) { return d5b_dv_forbidden(); }
		$a = d5b_rc_args( $in, array( 'store' => 'enum:presets|variables|colors', 'time' => 'str' ), array( 'store', 'time' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$need = d5b_dv_need( array( 'd5b_ds_restore', 'd5b_ds_read' ) );
		if ( null !== $need ) { return $need; }
		$hits = array();
		foreach ( d5b_wd_ds_backups( $a['store'] ) as $pair ) { if ( (string) ( $pair[1]['time'] ?? '' ) === $a['time'] ) { $hits[] = $pair[0]; } }
		if ( array() === $hits ) { return new WP_Error( 'backup_not_found', 'No ' . $a['store'] . ' backup has that time (see divi-ds-backups-list). Nothing was restored.' ); }
		if ( count( $hits ) > 1 ) { return new WP_Error( 'ambiguous', 'More than one ' . $a['store'] . ' backup has that time (two writes in the same second), so which one is meant cannot be told. Nothing was restored; use wp-admin or the REST route by index.' ); }
		$before = d5b_ds_read( $a['store'] );
		if ( is_wp_error( $before ) ) { return $before; }
		$r = d5b_ds_restore( $a['store'], $hits[0] );
		if ( is_wp_error( $r ) ) { return $r; }
		$stored = function_exists( 'd5b_history_write' ) && d5b_history_write( array(
			'ability' => 'divi5-builder/divi-ds-restore', 'object_type' => 'divi_' . $a['store'], 'object_id' => 0, 'action' => 'restore',
			'before' => $before, 'after' => $r, 'revision_id' => 0, 'summary' => 'Restored the Divi ' . $a['store'] . ' from the backup of ' . $a['time'],
		) ) > 0;
		return d5b_wd_out( $r, $stored );
	}

	// ---- Theme Builder

	/** The history "before" of a layout write: the whole content and the safety copy it had (the write replaces it). */
	function d5b_wd_tb_before( $p ): array {
		return array( 'content' => (string) $p->post_content, 'safety_copy' => d5b_wd_meta_rows( (int) $p->ID, array( '_d5b_prev_content', '_d5b_prev_saved_at' ) ) );
	}

	/** A Theme Builder layout (header, body, footer), or null. */
	function d5b_wd_tb_layout( int $id ) {
		$p = $id > 0 ? get_post( $id ) : null;
		return ( is_object( $p ) && in_array( (string) $p->post_type, d5b_wd_tb_types(), true ) ) ? $p : null;
	}

	/** The capabilities both Theme Builder writes need: a WP_Error, or null. */
	function d5b_wd_tb_caps(): ?WP_Error {
		if ( ! current_user_can( 'edit_theme_options' ) ) { return d5b_dv_forbidden(); }
		if ( ! current_user_can( 'unfiltered_html' ) ) { return new WP_Error( 'needs_unfiltered_html', 'Theme Builder layouts hold Divi blocks, and saving them needs the unfiltered_html capability. Without it WordPress would strip parts of the block data.' ); }
		return null;
	}

	/** @return array|WP_Error */
	function d5b_wd_h_tb_write( array $in ) {
		$caps = d5b_wd_tb_caps();
		if ( null !== $caps ) { return $caps; }
		$a = d5b_rc_args( $in, array( 'id' => 'id', 'content' => 'text', 'expect_hash' => 'str' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$need = d5b_dv_need( array( 'd5b_tb_write' ) );
		if ( null !== $need ) { return $need; }
		$content = (string) ( $a['content'] ?? '' );
		$p       = d5b_wd_tb_layout( $a['id'] );
		$before  = null;
		if ( null !== $p ) {
			$ok = d5b_wc_check_masked( $content, (string) $p->post_content );
			if ( true !== $ok ) { return $ok; }
			$ok = d5b_wc_check_content_size( (string) $p->post_content );
			if ( true !== $ok ) { return $ok; }
			$before = d5b_wd_tb_before( $p );
		}
		// The route's own checks and order: not a layout, edit_post, unfiltered_html, empty content, the hash, Divi format,
		// balanced blocks. Then the safety copy, the write, and the put-back when the save did not keep the block tree.
		$r = d5b_tb_write( $a['id'], $content, $a['expect_hash'] ?? null );
		if ( is_wp_error( $r ) ) { return $r; }
		$np     = get_post( $a['id'] );
		$stored = d5b_wc_history( 'divi5-builder/divi-tb-write', $np, 'update', $before, $r, 0, d5b_wc_summary( 'Updated', d5b_wd_tb_what( (string) $np->post_type ), $np ) );
		return d5b_wd_out( $r, $stored );
	}

	/** @return array|WP_Error */
	function d5b_wd_h_tb_restore( array $in ) {
		$caps = d5b_wd_tb_caps();
		if ( null !== $caps ) { return $caps; }
		$a = d5b_rc_args( $in, array( 'id' => 'id' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$need = d5b_dv_need( array( 'd5b_tb_restore' ) );
		if ( null !== $need ) { return $need; }
		$p      = d5b_wd_tb_layout( $a['id'] );
		if ( null !== $p ) {
			$ok = d5b_wc_check_content_size( (string) $p->post_content );
			if ( true !== $ok ) { return $ok; }
		}
		$before = null !== $p ? d5b_wd_tb_before( $p ) : null;
		$r = d5b_tb_restore( $a['id'] );
		if ( is_wp_error( $r ) ) { return $r; }
		$np     = get_post( $a['id'] );
		$stored = d5b_wc_history( 'divi5-builder/divi-tb-restore', $np, 'restore', $before, $r, 0, d5b_wc_summary( 'Restored the previous content of', d5b_wd_tb_what( (string) $np->post_type ), $np ) );
		return d5b_wd_out( $r, $stored );
	}

	function d5b_wd_can_tb( array $in ): bool {
		return current_user_can( 'edit_theme_options' ) && current_user_can( 'unfiltered_html' );
	}

	// ---- the two content edits: divi-section-edit and divi-text-edit

	/** The words for an item in a summary ("page", "Divi canvas", "Theme Builder footer layout"). */
	function d5b_wd_what( string $type ): string {
		if ( in_array( $type, d5b_wd_tb_types(), true ) ) { return d5b_wd_tb_what( $type ); }
		$map = array( 'et_pb_canvas' => 'Divi canvas', 'et_pb_layout' => 'Divi Library item' );
		return $map[ $type ] ?? $type;
	}

	/**
	 * Writes new content into the item d5b_wd_target() reached, with the bookkeeping of the write it goes through:
	 *  - via post: wp_update_post( wp_slash() ) as posts-update does; the history keeps every field and the previous content
	 *    (or revision_id when a revision holds it byte for byte).
	 *  - via tb: d5b_tb_write(), so the safety copy, the hash check and the put-back on a failed round trip apply; the history
	 *    keeps the whole previous content and safety copy, as divi-tb-write.
	 * The hash was checked by the caller; the TB path checks it again (the route's own check).
	 * @return array|WP_Error array( 'post' => the item as stored now, 'stored' => history row written, 'warnings' => list )
	 */
	function d5b_wd_content_write( string $ability, array $t, string $new, string $expect, string $summary ) {
		$p    = $t['post'];
		$id   = (int) $p->ID;
		$warn = array();
		$ok   = d5b_wc_check_content_size( (string) $p->post_content );
		if ( true !== $ok ) { return $ok; }
		if ( 'tb' === $t['via'] ) {
			$need = d5b_dv_need( array( 'd5b_tb_write' ) );
			if ( null !== $need ) { return $need; }
			$before = d5b_wd_tb_before( $p );
			$r      = d5b_tb_write( $id, $new, $expect );
			if ( is_wp_error( $r ) ) { return $r; }
			$np = get_post( $id );
			if ( ! is_object( $np ) ) { return new WP_Error( 'write_failed', 'WordPress did not save the change.' ); }
			if ( ! empty( $r['reserialised_on_save'] ) ) { $warn[] = 'WordPress re-serialised the layout as it saved it (same blocks, different bytes): use the new hash for the next edit.'; }
			$stored = d5b_wc_history( $ability, $np, 'update', $before, $r, 0, $summary );
			return array( 'post' => $np, 'stored' => $stored, 'warnings' => $warn );
		}
		$old_content = (string) $p->post_content;
		$rev         = d5b_wc_matching_revision( $p );
		$before      = d5b_wc_post_before( $p );
		$old         = d5b_wc_state( $p );
		$r = wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $new ) ), true );
		if ( is_wp_error( $r ) ) { return d5b_wc_core_error( $r ); }
		if ( (int) $r < 1 ) { return new WP_Error( 'write_failed', 'WordPress did not save the change.' ); }
		$np = get_post( $id );
		if ( ! is_object( $np ) ) { return new WP_Error( 'write_failed', 'WordPress did not save the change.' ); }
		if ( (string) $np->post_content !== $new ) { $warn[] = 'WordPress changed the content as it saved it (a plugin or filter): read the item again before the next edit.'; }
		// The revision WordPress made on this update can push the matching one out (WP_POST_REVISIONS): then keep the content.
		if ( $rev > 0 ) {
			$rp = get_post( $rev );
			if ( ! is_object( $rp ) || 'revision' !== $rp->post_type || (string) $rp->post_content !== $old_content ) { $rev = 0; }
		}
		if ( 0 === $rev ) { $before['content'] = $old_content; }
		$stored = d5b_wc_history( $ability, $np, 'update', $before, d5b_wc_diff( $old, d5b_wc_state( $np ) ), $rev, $summary );
		return array( 'post' => $np, 'stored' => $stored, 'warnings' => $warn );
	}

	/**
	 * The response of both edits: the item (new hash), then the edit's own fields, warnings and the history flag. The edit's
	 * fields win: divi-text-edit's excerpt is the text around the change (an edit never changes the post excerpt).
	 */
	function d5b_wd_edit_out( array $w, array $extra ): array {
		$out = array_merge( d5b_wc_result( $w['post'] ), array( 'changed' => true ), $extra );
		// The page itself is not returned (it can be 1 MB on a phone): the hash, the address and the tree or excerpt are enough.
		unset( $out['content'] );
		if ( $w['warnings'] ) { $out['warnings'] = $w['warnings']; }
		return d5b_wd_out( $out, $w['stored'] );
	}

	/**
	 * The answer to an edit that changes nothing (the new content is byte for byte the old): nothing is written, no
	 * revision, no history row, and on a layout the safety copy (the only restore point) is not overwritten.
	 */
	function d5b_wd_noop_out( $p, array $extra ): array {
		$out = array_merge( d5b_wc_result( $p ), array( 'changed' => false ), $extra );
		unset( $out['content'] );
		return $out;
	}

	/** The checks both edits make before they look at the content: target, hash, masked read, Divi html. @return array|WP_Error the target */
	function d5b_wd_edit_gate( int $post_id, ?string $expect, string $incoming ) {
		$t = d5b_wd_target( $post_id );
		if ( is_wp_error( $t ) ) { return $t; }
		$ok = d5b_wc_check_hash( $t['post'], $expect );
		if ( true !== $ok ) { return $ok; }
		$ok = d5b_wc_check_masked( $incoming, (string) $t['post']->post_content );
		if ( true !== $ok ) { return $ok; }
		$ok = d5b_wc_check_divi_html( $incoming );
		if ( true !== $ok ) { return $ok; }
		return $t;
	}

	/** The stored page as a block tree, or the refusal for a page that does not parse. @return array|WP_Error */
	function d5b_wd_parse_page( string $content ) {
		$root = d5b_blocks_parse( $content );
		if ( ! is_wp_error( $root ) ) { return $root; }
		$d = $root->get_error_data();
		return new WP_Error( 'invalid_input', 'The stored page does not parse as Divi blocks, so it is not edited: ' . $root->get_error_message() . '. Fix it in the Visual Builder, or replace the whole content with pages-update (or divi-tb-write).', is_array( $d ) ? $d : null );
	}

	// -- divi-section-edit

	/** @return array|WP_Error */
	function d5b_wd_h_section_edit( array $in ) {
		$a = d5b_rc_args( $in, array( 'post_id' => 'id', 'expect_hash' => 'str', 'op' => 'enum:replace|before|after|append|remove', 'at' => 'key', 'with' => 'text' ), array( 'post_id', 'expect_hash', 'op', 'at' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$op   = $a['op'];
		$with = $a['with'] ?? null;
		if ( 'remove' === $op && null !== $with ) { return new WP_Error( 'invalid_input', 'op remove takes no with: leave it out.' ); }
		if ( 'remove' !== $op && ( null === $with || '' === trim( $with ) ) ) { return new WP_Error( 'invalid_input', 'op ' . $op . ' needs with: the Divi block markup to put ' . ( 'replace' === $op ? 'in place of the block' : ( 'append' === $op ? 'inside the block' : $op . ' the block' ) ) . '.' ); }
		$t = d5b_wd_edit_gate( $a['post_id'], $a['expect_hash'], (string) $with );
		if ( is_wp_error( $t ) ) { return $t; }
		$content = (string) $t['post']->post_content;
		if ( null !== $with ) {
			$wr = d5b_blocks_parse( $with );
			if ( is_wp_error( $wr ) ) { return new WP_Error( 'invalid_input', 'with does not parse as Divi blocks, so nothing was written: ' . $wr->get_error_message() . '.', $wr->get_error_data() ); }
			if ( ! d5b_blocks_divi_children( $wr ) ) { return new WP_Error( 'invalid_input', 'with holds no Divi blocks (<!-- wp:divi/... -->), so nothing was written.' ); }
		}
		$root = d5b_wd_parse_page( $content );
		if ( is_wp_error( $root ) ) { return $root; }
		$tgt = d5b_blocks_resolve( $root, $a['at'] );
		if ( is_wp_error( $tgt ) ) { return $tgt; }
		// Removing the last top-level block would leave an empty page (an empty footer on every page of the site).
		if ( 'remove' === $op && false === strpos( $tgt['addr'], '.' ) && 1 === count( d5b_blocks_divi_children( $root ) ) ) {
			return new WP_Error( 'invalid_input', 'This is the only ' . d5b_blocks_kind( $tgt['name'] ) . ' of the ' . d5b_wd_what( $t['type'] ) . ', and removing it would leave it empty. Replace it instead (op replace), or change the whole content with pages-update, posts-update, cpt-update or divi-tb-write.' );
		}
		// The edit itself: the kind check of with, the splice, the parse of the result (d5b_blocks_edit).
		$out = d5b_blocks_edit( $content, $op, $tgt['addr'], $with );
		if ( is_wp_error( $out ) ) { return $out; }
		if ( $out === $content ) {
			return d5b_wd_noop_out( $t['post'], array( 'op' => $op, 'address' => $tgt['addr'], 'tree' => d5b_blocks_tree( $content ) ) );
		}
		// Parse the result again and find the edited block in it: the address the response gives must hold what was written.
		$nr = d5b_blocks_parse( $out );
		if ( is_wp_error( $nr ) ) { return new WP_Error( 'invalid_input', 'The edited page would not parse; nothing was written: ' . $nr->get_error_message() ); }
		$addr = $tgt['addr'];
		if ( 'remove' !== $op ) {
			$parts = explode( '.', $addr );
			if ( 'after' === $op ) { $parts[ count( $parts ) - 1 ] = (string) ( (int) end( $parts ) + 1 ); $addr = implode( '.', $parts ); }
			if ( 'append' === $op ) { $addr = $addr . '.' . ( count( d5b_blocks_divi_children( $tgt ) ) + 1 ); }
			$first = d5b_blocks_divi_children( $wr )[0];
			$nb    = d5b_blocks_resolve( $nr, $addr );
			if ( is_wp_error( $nb ) || $nb['name'] !== $first['name'] || substr( $out, $nb['start'], $nb['end'] - $nb['start'] ) !== substr( $with, $first['start'], $first['end'] - $first['start'] ) ) {
				return new WP_Error( 'invalid_input', 'internal: the edited page does not hold the new block at ' . $addr . '; nothing was written.' );
			}
		}
		$kind = d5b_blocks_kind( $tgt['name'] );
		$verb = array( 'replace' => 'Edited ' . $kind . ' ' . $tgt['addr'] . ' of', 'remove' => 'Removed ' . $kind . ' ' . $tgt['addr'] . ' from', 'before' => 'Added before ' . $kind . ' ' . $tgt['addr'] . ' of', 'after' => 'Added after ' . $kind . ' ' . $tgt['addr'] . ' of', 'append' => 'Added inside ' . $kind . ' ' . $tgt['addr'] . ' of' )[ $op ];
		$w = d5b_wd_content_write( 'divi5-builder/divi-section-edit', $t, $out, (string) $a['expect_hash'], d5b_wc_summary( $verb, d5b_wd_what( $t['type'] ), $t['post'] ) );
		if ( is_wp_error( $w ) ) { return $w; }
		return d5b_wd_edit_out( $w, array( 'op' => $op, 'address' => $addr, 'tree' => d5b_blocks_tree( (string) $w['post']->post_content ) ) );
	}

	function d5b_wd_can_edit( array $in ): bool {
		if ( ! current_user_can( 'read' ) ) { return false; }
		$id = d5b_rc_id_of( $in['post_id'] ?? 0 );
		return $id > 0 && ! is_wp_error( d5b_wd_target( $id ) );
	}

	// -- divi-text-edit: the visible text of a field and where each part of it comes from

	/** A Divi dynamic-content token, $variable({...})$, as Divi reads it: up to the first ")$". */
	define( 'D5B_WD_VAR_RE', '/\$variable\(.*?\)\$/s' );

	/**
	 * The text a visitor reads in a field's HTML: Divi's $variable(...)$ tokens removed (they are markup: Divi puts the
	 * variable's value there, and their JSON is never text to edit), tags removed, entities decoded.
	 */
	function d5b_wd_visible( string $html ): string {
		return html_entity_decode( d5b_wd_strip_all_tags( d5b_wd_strip_vars( $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** The largest text field divi-text-edit searches: 256 KB. */
	function d5b_wd_field_cap(): int {
		return 262144;
	}

	/**
	 * Where the closing markers of one text are, found once, sorted: every ")$" (the end of a $variable(...)$ token), every
	 * ">", and every "</script>" and "</style>" (any case). The start of a token or of a script or style element is then
	 * resolved with a binary search (d5b_wd_var_end, d5b_wd_script_end) instead of a regex run from that start to the end
	 * of the text, which made a field of many unclosed "$variable(" or "<script" quadratic.
	 */
	function d5b_wd_index( string $html ): array {
		$idx = array( 'var' => array(), 'gt' => array(), 'script' => array(), 'style' => array() );
		for ( $o = strpos( $html, ')$' ); false !== $o; $o = strpos( $html, ')$', $o + 2 ) ) { $idx['var'][] = $o; }
		for ( $o = strpos( $html, '>' ); false !== $o; $o = strpos( $html, '>', $o + 1 ) ) { $idx['gt'][] = $o; }
		if ( preg_match_all( '@</(script|style)>@i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $k => $name ) { $idx[ strtolower( $name[0] ) ][] = $m[0][ $k ][1]; }
		}
		return $idx;
	}

	/** The first value in the sorted list that is $x or more, or null. */
	function d5b_wd_first_at( array $sorted, int $x ): ?int {
		$lo = 0; $hi = count( $sorted );
		while ( $lo < $hi ) { $mid = ( $lo + $hi ) >> 1; if ( $sorted[ $mid ] < $x ) { $lo = $mid + 1; } else { $hi = $mid; } }
		return $lo < count( $sorted ) ? $sorted[ $lo ] : null;
	}

	/** The end of the $variable(...)$ token starting at $i (as D5B_WD_VAR_RE anchored there: up to the first ")$"), or null. */
	function d5b_wd_var_end( string $html, array $idx, int $i ): ?int {
		if ( 0 !== substr_compare( $html, '$variable(', $i, 10 ) ) { return null; }
		$p = d5b_wd_first_at( $idx['var'], $i + 10 );
		return null === $p ? null : $p + 2;
	}

	/**
	 * The end of the script or style element starting at $i, as wp_strip_all_tags' '@<(script|style)[^>]*?>.*?</\1>@si'
	 * anchored there (the first ">" after the name, then the first closing tag of the same name), or null.
	 */
	function d5b_wd_script_end( string $html, array $idx, int $i ): ?int {
		$name = 0 === substr_compare( $html, '<script', $i, 7, true ) ? 'script' : ( 0 === substr_compare( $html, '<style', $i, 6, true ) ? 'style' : '' );
		if ( '' === $name ) { return null; }
		$g = d5b_wd_first_at( $idx['gt'], $i + 1 + strlen( $name ) );
		if ( null === $g ) { return null; }
		$c = d5b_wd_first_at( $idx[ $name ], $g + 1 );
		return null === $c ? null : $c + strlen( $name ) + 3;
	}

	/** preg_replace( D5B_WD_VAR_RE, '', $html ) in one pass: every $variable(...)$ token removed, left to right. */
	function d5b_wd_strip_vars( string $html ): string {
		if ( false === strpos( $html, '$variable(' ) ) { return $html; }
		$idx = d5b_wd_index( $html );
		$out = ''; $pos = 0;
		for ( $s = strpos( $html, '$variable(' ); false !== $s; $s = strpos( $html, '$variable(', $s ) ) {
			$e = d5b_wd_var_end( $html, $idx, $s );
			// No ")$" after this start means none after any later start either.
			if ( null === $e ) { break; }
			$out .= substr( $html, $pos, $s - $pos );
			$pos = $s = $e;
		}
		return $out . substr( $html, $pos );
	}

	/**
	 * wp_strip_all_tags( $s ) (no line-break removal) in one pass: script and style elements removed as core's regex does,
	 * then strip_tags, then trim. Core's regex runs from every "<script" to the end of the text when it is not closed.
	 */
	function d5b_wd_strip_all_tags( string $s ): string {
		if ( false !== stripos( $s, '<script' ) || false !== stripos( $s, '<style' ) ) {
			$idx = d5b_wd_index( $s );
			$out = ''; $pos = 0;
			for ( $i = strpos( $s, '<' ); false !== $i; $i = strpos( $s, '<', $i ) ) {
				$e = d5b_wd_script_end( $s, $idx, $i );
				if ( null === $e ) { $i++; continue; }
				$out .= substr( $s, $pos, $i - $pos );
				$pos = $i = $e;
			}
			$s = $out . substr( $s, $pos );
		}
		return trim( strip_tags( $s ) );
	}

	/** True when the text holds a $variable( token start (refused as new in text mode; a source span holding one is markup). */
	function d5b_wd_has_var( string $s ): bool {
		return false !== strpos( $s, '$variable(' );
	}

	/**
	 * The visible text with a position map, by walking the source once: a run of text maps byte for byte, an entity maps to
	 * its whole source span, and tags, comments and script or style elements map to nothing. The order of the steps is
	 * wp_strip_all_tags': script and style removed, tags stripped, the result trimmed (raw whitespace only: an entity is
	 * not yet decoded when WordPress trims), then the entities decoded.
	 * A $variable(...)$ token in the text maps to nothing, like a tag; with $show_vars it reads "{variable}" (kind v), for
	 * excerpts only.
	 * @return array array( visible, tokens ): each token array( kind t|e|v, vis start, vis end, src start, src end ).
	 *               visible equals d5b_wd_visible() whenever the walk agrees with PHP's strip_tags; the caller checks.
	 */
	function d5b_wd_vis_map( string $html, bool $show_vars = false ): array {
		$n    = strlen( $html );
		$idx  = d5b_wd_index( $html ); // every closing marker, found once: no regex runs from a start to the end of the field
		$raw  = array();   // array( kind, src start, src end, text )
		$i    = 0;
		$run  = -1;        // index in $raw of the text run being extended
		$text = function ( int $s, int $e ) use ( &$raw, &$run, $html ) {
			if ( $run >= 0 && $raw[ $run ][2] === $s ) { $raw[ $run ][2] = $e; $raw[ $run ][3] .= substr( $html, $s, $e - $s ); return; }
			$raw[] = array( 't', $s, $e, substr( $html, $s, $e - $s ) ); $run = count( $raw ) - 1;
		};
		while ( $i < $n ) {
			$c = $html[ $i ];
			if ( '<' === $c ) {
				$se = d5b_wd_script_end( $html, $idx, $i );
				if ( null !== $se ) { $i = $se; $run = -1; continue; }
				$nx = $i + 1 < $n ? $html[ $i + 1 ] : '';
				// PHP's strip_tags keeps a "<" that a space follows ("1 < 2")
				if ( '' !== $nx && false !== strpos( " \t\n\r\x0B\f", $nx ) ) { $text( $i, $i + 1 ); $i++; continue; }
				if ( '<!--' === substr( $html, $i, 4 ) ) { $e = strpos( $html, '-->', $i + 4 ); $i = false === $e ? $n : $e + 3; $run = -1; continue; }
				$q = ''; $depth = 0; $j = $i + 1;
				for ( ; $j < $n; $j++ ) {
					$d = $html[ $j ];
					if ( '' !== $q ) { if ( $d === $q ) { $q = ''; } continue; }
					if ( '"' === $d || "'" === $d ) { $q = $d; continue; }
					if ( '<' === $d ) { $depth++; continue; }
					if ( '>' === $d ) { if ( $depth > 0 ) { $depth--; continue; } break; }
				}
				$i = $j < $n ? $j + 1 : $n; $run = -1;
				continue;
			}
			if ( '$' === $c && null !== ( $ve = d5b_wd_var_end( $html, $idx, $i ) ) ) {
				if ( $show_vars ) { $raw[] = array( 'v', $i, $ve, '{variable}' ); }
				$run = -1; $i = $ve; continue;
			}
			if ( '&' === $c && 1 === preg_match( '/&(?:#[0-9]+|#[xX][0-9a-fA-F]+|[A-Za-z][A-Za-z0-9]*);/A', $html, $m, 0, $i ) ) {
				$dec = html_entity_decode( $m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( $dec !== $m[0] ) { $raw[] = array( 'e', $i, $i + strlen( $m[0] ), $dec ); $run = -1; $i += strlen( $m[0] ); continue; }
			}
			$b = ord( $c ); $len = $b < 0x80 ? 1 : ( $b >= 0xF0 ? 4 : ( $b >= 0xE0 ? 3 : ( $b >= 0xC0 ? 2 : 1 ) ) );
			$len = min( $len, $n - $i );
			$text( $i, $i + $len ); $i += $len;
		}
		// trim, as wp_strip_all_tags does, before the entities are decoded: raw whitespace text only
		$ws = " \t\n\r\0\x0B";
		while ( $raw && 't' === $raw[0][0] ) {
			$l = ltrim( $raw[0][3], $ws ); $cut = strlen( $raw[0][3] ) - strlen( $l );
			$raw[0][1] += $cut; $raw[0][3] = $l;
			if ( '' !== $l ) { break; }
			array_shift( $raw );
		}
		while ( $raw && 't' === $raw[ count( $raw ) - 1 ][0] ) {
			$k = count( $raw ) - 1;
			$r = rtrim( $raw[ $k ][3], $ws ); $cut = strlen( $raw[ $k ][3] ) - strlen( $r );
			$raw[ $k ][2] -= $cut; $raw[ $k ][3] = $r;
			if ( '' !== $r ) { break; }
			array_pop( $raw );
		}
		$vis = ''; $tok = array();
		foreach ( $raw as $x ) { $v = strlen( $vis ); $vis .= $x[3]; $tok[] = array( $x[0], $v, strlen( $vis ), $x[1], $x[2] ); }
		return array( $vis, $tok );
	}

	/** Source span of the visible bytes [a, b), or null when an end falls inside one decoded entity. @return array|null */
	function d5b_wd_vis_span( array $tok, int $a, int $b ) {
		$sa = null; $sb = null;
		foreach ( $tok as $x ) {
			list( $k, $vs, $ve, $ss, $se ) = $x;
			if ( null === $sa && $a >= $vs && $a < $ve ) { $sa = 't' === $k ? $ss + ( $a - $vs ) : ( $a === $vs ? $ss : -1 ); }
			if ( null === $sb && $b > $vs && $b <= $ve ) { $sb = 't' === $k ? $ss + ( $b - $vs ) : ( $b === $ve ? $se : -1 ); }
		}
		return ( null === $sa || null === $sb || $sa < 0 || $sb < 0 ) ? null : array( $sa, $sb );
	}

	/** The visible bytes before source offset $s (a token cut by $s counts only when it is text). */
	function d5b_wd_vis_before( array $tok, int $s ): int {
		$v = 0;
		foreach ( $tok as $x ) {
			if ( $x[4] <= $s ) { $v = $x[2]; continue; }
			if ( 't' === $x[0] && $x[3] < $s ) { $v = $x[1] + ( $s - $x[3] ); }
			break;
		}
		return $v;
	}

	/** Every start of $needle in $hay, overlapping ones too ("aa" is twice in "aaa"): a match must be unique. */
	function d5b_wd_offsets( string $hay, string $needle, int $max = 50 ): array {
		$out = array(); $o = 0;
		while ( count( $out ) < $max && false !== ( $p = strpos( $hay, $needle, $o ) ) ) { $out[] = $p; $o = $p + 1; }
		return $out;
	}

	/** Where a tag stood in the near-match text: a private-use character, read as a space by the near-match search. */
	define( 'D5B_WD_TAG_MARK', "\u{E000}" );

	/**
	 * The text near matches are looked for in: every tag read as a space ("5pm<br>Wed" reads "5pm Wed"), tokens left out.
	 * Each tag is kept as D5B_WD_TAG_MARK, so a window that spans one can be told from one that does not.
	 */
	function d5b_wd_near_text( string $html ): string {
		$h = str_replace( D5B_WD_TAG_MARK, '', $html );
		return d5b_wd_visible( (string) preg_replace( '/<[^>]*>/', D5B_WD_TAG_MARK, d5b_wd_strip_vars( $h ) ) );
	}

	/**
	 * Up to 5 windows of the haystacks equal to $old ignoring case, runs of whitespace, curly or straight quotes, and hyphens
	 * or dashes: list of { address, field, text }. A window that spans a tag can never be matched as text, so it is not
	 * offered as old: it is { address, field, reads_as, crosses_markup: true, html (the field as stored), hint }.
	 */
	function d5b_wd_near( array $hays, string $old ): array {
		$single = array( "\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "\u{2032}" );
		$double = array( "\u{201C}", "\u{201D}", "\u{201E}", "\u{2033}" );
		$dash   = array( "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}" );
		$fold   = str_replace( $dash, '-', str_replace( $double, '"', str_replace( $single, "'", str_replace( D5B_WD_TAG_MARK, ' ', $old ) ) ) );
		$words  = preg_split( '/[\s\x{00A0}]+/u', trim( $fold ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) { return array(); }
		$cls = array( "'" => "['" . implode( '', $single ) . ']', '"' => '["' . implode( '', $double ) . ']', '\\-' => '[\\-' . implode( '', $dash ) . ']' );
		$re  = '/' . implode( '[\s\x{00A0}\x{E000}]+', array_map( function ( $w ) use ( $cls ) { return strtr( preg_quote( $w, '/' ), $cls ); }, $words ) ) . '/iu';
		$out = array();
		foreach ( $hays as $h ) {
			if ( ! preg_match_all( $re, $h['text'], $m ) ) { continue; }
			foreach ( $m[0] as $hit ) {
				if ( false === strpos( $hit, D5B_WD_TAG_MARK ) ) {
					$out[] = array( 'address' => $h['address'], 'field' => $h['field'], 'text' => $hit );
				} else {
					$out[] = array( 'address' => $h['address'], 'field' => $h['field'], 'reads_as' => (string) preg_replace( '/[\s\x{00A0}]*\x{E000}[\s\x{00A0}\x{E000}]*/u', ' ', $hit ), 'crosses_markup' => true, 'html' => (string) ( $h['html'] ?? '' ), 'hint' => 'this text spans a tag; shorten old to one side of it, or use html: true' );
				}
				if ( count( $out ) >= 5 ) { return $out; }
			}
		}
		return $out;
	}

	/** 120 characters of $vis centred on the visible bytes [a, b) (from a when the change itself is longer). */
	function d5b_wd_excerpt( string $vis, int $a, int $b ): string {
		$ca = d5b_rc_chars( substr( $vis, 0, $a ) ); $cb = d5b_rc_chars( substr( $vis, 0, max( $a, $b ) ) ); $all = d5b_rc_chars( $vis );
		$start = $cb - $ca >= 120 ? $ca : max( 0, min( $ca - intdiv( 120 - ( $cb - $ca ), 2 ), $all - 120 ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $vis, $start, 120, 'UTF-8' ) : substr( $vis, $start, 120 );
	}

	/** Per tag name, opened minus closed (void elements and self-closed tags left out); names with 0 are left out. */
	function d5b_wd_tag_balance( string $html ): array {
		$void = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );
		$c    = array();
		preg_match_all( '#<(/?)([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*?(/?)>#', preg_replace( '/<!--.*?-->/s', '', $html ), $m, PREG_SET_ORDER );
		foreach ( $m as $t ) {
			$n = strtolower( $t[2] );
			if ( in_array( $n, $void, true ) || ( '' === $t[1] && '/' === $t[3] ) ) { continue; }
			$c[ $n ] = ( $c[ $n ] ?? 0 ) + ( '/' === $t[1] ? -1 : 1 );
		}
		$c = array_filter( $c );
		ksort( $c );
		return $c;
	}

	/**
	 * html: true writes new as given, so the result is checked: the visible text before and after the edited span is what
	 * it was, and every tag name opens and closes as often as before. true, or the reason.
	 */
	function d5b_wd_html_ok( string $old_html, int $sa, int $sb, string $new_html, int $repl_len ) {
		list( $ov, $ot ) = d5b_wd_vis_map( $old_html );
		list( $nv, $nt ) = d5b_wd_vis_map( $new_html );
		// A field the walk cannot map (malformed markup) is checked by its tags only.
		if ( $ov === d5b_wd_visible( $old_html ) && $nv === d5b_wd_visible( $new_html ) ) {
			$pre_o = substr( $ov, 0, d5b_wd_vis_before( $ot, $sa ) ); $suf_o = (string) substr( $ov, d5b_wd_vis_before( $ot, $sb ) );
			$pre_n = substr( $nv, 0, d5b_wd_vis_before( $nt, $sa ) ); $suf_n = (string) substr( $nv, d5b_wd_vis_before( $nt, $sa + $repl_len ) );
			if ( $pre_o !== $pre_n || $suf_o !== $suf_n ) { return 'the text a visitor reads outside the part you replaced would change'; }
		}
		if ( d5b_wd_tag_balance( $old_html ) !== d5b_wd_tag_balance( $new_html ) ) { return 'the HTML tags would no longer open and close as they did'; }
		return true;
	}

	/** Where a text field path points: element, breakpoint, state (title.innerContent.tablet.hover -> title, tablet, hover). */
	function d5b_wd_field_parts( string $field ): array {
		$p = explode( '.', $field );
		return array( 'element' => $p[0], 'breakpoint' => $p[2] ?? '', 'state' => $p[3] ?? '', 'text' => isset( $p[4] ) );
	}

	/** @return array|WP_Error */
	function d5b_wd_h_text_edit( array $in ) {
		$a = d5b_rc_args( $in, array( 'post_id' => 'id', 'expect_hash' => 'str', 'old' => 'text:2000', 'new' => 'text:2000', 'at' => 'key', 'html' => 'bool' ), array( 'post_id', 'expect_hash', 'old', 'new' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$old = $a['old']; $new = $a['new']; $html = ! empty( $a['html'] );
		if ( '' === $old ) { return new WP_Error( 'invalid_input', 'old must not be empty: give the exact text to change (1 to 2000 characters).' ); }
		if ( ! $html && d5b_wd_has_var( $new ) ) { return new WP_Error( 'invalid_input', 'new holds a $variable(...)$ token (Divi dynamic content). As text it would be written literally; use html: true to write variable tokens.' ); }
		$t = d5b_wd_edit_gate( $a['post_id'], $a['expect_hash'], $new );
		if ( is_wp_error( $t ) ) { return $t; }
		$content = (string) $t['post']->post_content;
		$root    = d5b_wd_parse_page( $content );
		if ( is_wp_error( $root ) ) { return $root; }
		if ( isset( $a['at'] ) ) {
			$top = d5b_blocks_resolve( $root, $a['at'] );
			if ( is_wp_error( $top ) ) { return $top; }
			$nodes = array_merge( array( $top ), d5b_blocks_walk( $top ) );
		} else {
			$nodes = d5b_blocks_walk( $root );
		}
		// Only the text-bearing fields of each module are searched (inc/blocks-text-fields.php plus the hand list): never an
		// attribute name, an option value, a class or an id. One field stored for several breakpoints or states with the SAME
		// text is one match (a unit), and every copy is changed; copies with different text are separate matches.
		$units = array(); $hays = array(); $total = 0;
		foreach ( $nodes as $nd ) {
			foreach ( d5b_blocks_text_fields( $nd ) as $field => $fh ) {
				if ( strlen( $fh ) > d5b_wd_field_cap() ) {
					return new WP_Error( 'invalid_input', 'The ' . $field . ' field of the block at ' . $nd['addr'] . ' is over 256 KB, more than divi-text-edit searches. Use at to search another block, or change this one with divi-section-edit.' );
				}
				$hay    = $html ? $fh : d5b_wd_visible( $fh );
				$hays[] = array( 'address' => $nd['addr'], 'field' => $field, 'text' => $html ? $fh : d5b_wd_near_text( $fh ), 'html' => $fh );
				$offs   = d5b_wd_offsets( $hay, $old );
				if ( ! $offs ) { continue; }
				$fp  = d5b_wd_field_parts( $field );
				$key = $nd['addr'] . '|' . $fp['element'] . '|' . ( $fp['text'] ? 'text' : '' ) . '|' . md5( $hay );
				if ( ! isset( $units[ $key ] ) ) { $units[ $key ] = array( 'node' => $nd, 'hay' => $hay, 'at' => $offs, 'copies' => array() ); $total += count( $offs ); }
				$units[ $key ]['copies'][ $field ] = $fh;
			}
		}
		$where = isset( $a['at'] ) ? 'in the block at ' . $a['at'] : 'on the page';
		if ( 0 === $total ) {
			$near = d5b_wd_near( $hays, $old );
			return new WP_Error( 'not_found', 'old is not in the ' . ( $html ? 'stored HTML' : 'text a visitor reads' ) . ' ' . $where . ' (it must match exactly, case and spaces included). ' . ( $near ? 'Near matches are in data.near_matches: repeat with old set to the text of one of them (an entry with crosses_markup spans a tag and has no text to use: shorten old to one side of the tag, or use html: true with its html).' : 'Read the item again to see its text.' ), array( 'near_matches' => $near ) );
		}
		if ( $total > 1 ) {
			$list = array();
			foreach ( $units as $u ) {
				$fields = array_keys( $u['copies'] );
				$fp     = d5b_wd_field_parts( $fields[0] );
				$e      = array( 'address' => $u['node']['addr'], 'field' => $fields[0], 'breakpoint' => $fp['breakpoint'], 'state' => $fp['state'], 'count' => count( $u['at'] ) );
				if ( count( $fields ) > 1 ) { $e['same_text_in'] = array_slice( $fields, 1 ); }
				$list[] = $e;
			}
			return new WP_Error( 'ambiguous', 'old occurs ' . $total . ' times ' . $where . ', so which one is meant cannot be told. Repeat with at set to one of the addresses in data.matches, or make old longer so it occurs once (two matches in one block are in different breakpoints or states, or twice in one text: data.matches names them).', array( 'matches' => $list ) );
		}
		$u     = array_values( $units )[0];
		$addr  = $u['node']['addr'];
		$at0   = $u['at'][0];
		// Every copy is mapped on its own: the same visible text can be stored as different HTML per breakpoint.
		$plans = array();
		foreach ( $u['copies'] as $field => $fh ) {
			$cross = new WP_Error( 'crosses_markup', 'The text ' . $where . ' runs across HTML tags or a $variable(...)$ token in the field (data.html is the field as stored), so it is not replaced as text. Either shorten old to the part on one side of the tag, or repeat with html: true and old and new as HTML, matched against data.html exactly.', array( 'address' => $addr, 'field' => $field, 'html' => $fh ) );
			if ( $html ) {
				$sa = $at0; $sb = $sa + strlen( $old ); $repl = $new;
			} else {
				list( $vis, $tok ) = d5b_wd_vis_map( $fh );
				$span = $vis === $u['hay'] ? d5b_wd_vis_span( $tok, $at0, $at0 + strlen( $old ) ) : null;
				if ( null === $span ) { return $cross; }
				list( $sa, $sb ) = $span;
				$piece = substr( $fh, $sa, $sb - $sa );
				if ( false !== strpos( $piece, '<' ) || d5b_wd_has_var( $piece ) ) { return $cross; }
				// new is text: written as HTML text, so it reads back exactly as given
				$repl = str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $new );
			}
			$new_html = substr( $fh, 0, $sa ) . $repl . substr( $fh, $sb );
			if ( $html ) {
				$ok = d5b_wd_html_ok( $fh, $sa, $sb, $new_html, strlen( $repl ) );
				if ( true !== $ok ) { return new WP_Error( 'invalid_input', 'With new in place, ' . $ok . ' (field ' . $field . ' at ' . $addr . '), so nothing was written. Replace whole tags, or change only the text inside them.', array( 'address' => $addr, 'field' => $field, 'html' => $fh ) ); }
			}
			$plans[ $field ] = array( 'old_html' => $fh, 'new_html' => $new_html, 'sa' => $sa, 'len' => strlen( $repl ) );
		}
		// One copy at a time, each on a fresh parse of the page as it is after the previous one.
		$out = $content;
		foreach ( $plans as $field => $pl ) {
			$pr = d5b_blocks_parse( $out );
			$nd = is_wp_error( $pr ) ? $pr : d5b_blocks_resolve( $pr, $addr );
			if ( is_wp_error( $nd ) ) { return new WP_Error( 'invalid_input', 'internal: the block at ' . $addr . ' could not be read again; nothing was written.' ); }
			$out = d5b_blocks_set_text_field( $out, $nd, $field, $pl['new_html'] );
			if ( is_wp_error( $out ) ) { return $out; }
		}
		// Parse the result again and read every copy back: it must hold its new HTML, and (as text) read as the old text with
		// old replaced by new. Nothing is written otherwise.
		$nr = d5b_blocks_parse( $out );
		$nb = is_wp_error( $nr ) ? $nr : d5b_blocks_resolve( $nr, $addr );
		$fields_back = is_wp_error( $nb ) ? array() : d5b_blocks_text_fields( $nb );
		foreach ( $plans as $field => $pl ) {
			$back = $fields_back[ $field ] ?? null;
			if ( $back !== $pl['new_html'] ) { return new WP_Error( 'invalid_input', 'internal: the edited page does not read back as intended; nothing was written.' ); }
			if ( ! $html ) {
				$want = trim( substr( $u['hay'], 0, $at0 ) . $new . substr( $u['hay'], $at0 + strlen( $old ) ), " \t\n\r\0\x0B" );
				if ( d5b_wd_visible( $back ) !== $want ) {
					return new WP_Error( 'crosses_markup', 'With new in place the field would not read as intended (new joins the text around it into different HTML), so nothing was written. Repeat with html: true and old and new as HTML (data.html is the field as stored).', array( 'address' => $addr, 'field' => $field, 'html' => $pl['old_html'] ) );
				}
			}
		}
		$field = array_keys( $plans )[0];
		$pl    = $plans[ $field ];
		// The excerpt reads tokens as {variable}; a field the walk cannot map gets the start of its visible text.
		list( $xv, $xt ) = d5b_wd_vis_map( $pl['new_html'], true );
		$ex = d5b_wd_vis_map( $pl['new_html'] )[0] === d5b_wd_visible( $pl['new_html'] ) ? d5b_wd_excerpt( $xv, d5b_wd_vis_before( $xt, $pl['sa'] ), d5b_wd_vis_before( $xt, $pl['sa'] + $pl['len'] ) ) : d5b_wd_excerpt( d5b_wd_visible( $pl['new_html'] ), 0, 0 );
		$extra = array( 'address' => $addr, 'field' => $field, 'excerpt' => $ex );
		if ( count( $plans ) > 1 ) { $extra['also_changed'] = array_slice( array_keys( $plans ), 1 ); }
		if ( $out === $content ) { return d5b_wd_noop_out( $t['post'], $extra ); }
		$el = d5b_wd_field_parts( $field )['element'];
		$w  = d5b_wd_content_write( 'divi5-builder/divi-text-edit', $t, $out, (string) $a['expect_hash'], d5b_wc_summary( 'Changed text in', d5b_wd_what( $t['type'] ), $t['post'] ) . ' (' . $addr . ' ' . $el . ')' );
		if ( is_wp_error( $w ) ) { return $w; }
		return d5b_wd_edit_out( $w, $extra );
	}

	// ---- audit args

	/** Every top-level value whose JSON is over 1 KB is kept as its size only. */
	function d5b_wd_audit_cap( array $args ): array {
		foreach ( $args as $k => $v ) {
			$j = wp_json_encode( $v );
			$n = is_string( $j ) ? strlen( $j ) : 0;
			if ( $n > 1024 ) { $args[ $k ] = array( 'capped' => true, 'bytes' => $n ); }
		}
		return $args;
	}

	/** A layout's content as its size and md5 (it can be 1 MB; the history row has the whole thing). */
	function d5b_wd_audit_tb( array $args ): array {
		if ( isset( $args['content'] ) && is_string( $args['content'] ) ) { $args['content'] = array( 'bytes' => strlen( $args['content'] ), 'md5' => md5( $args['content'] ) ); }
		return $args;
	}

	// ---- the definitions

	d5b_wc_def( 'divi-builder-mode', 'divi', 'Switch the Divi builder on or off',
		'Turns the Divi builder on or off for one post, page, custom item, canvas, Divi Library item or Theme Builder layout, and returns the new state. on true sets _et_pb_use_builder and _et_pb_use_divi_5 to on; on false sets _et_pb_use_builder to off and keeps the Divi 5 flag, so switching it on again brings the page back (a page holding Divi blocks then shows its raw markup until it is switched on). Refuses an item you may not edit, a caller without unfiltered_html, an item whose own category (posts, pages or custom post types) is switched off, templates and every other kind of item (not_allowed). Calling it twice changes nothing more. The change history keeps both flags as they were.',
		array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the post, page or other item.' ),
			'on'      => array( 'type' => 'boolean', 'description' => 'true switches the Divi builder on (Divi 5), false switches it off.' ),
		), array( 'post_id', 'on' ), 'd5b_wd_h_builder_mode', 'd5b_wd_can_builder_mode', array( 'idempotent' => true ) );

	d5b_wc_def( 'divi-link-canvas', 'divi', 'Link a Divi canvas to a page',
		'Links a Divi canvas (popup or off-canvas area) to a post, page or custom item the way the Visual Builder does, so Divi shows it there: the canvas gets its canvas id and parent, the page points at the canvas and gets the Divi 5 builder flags. Returns the canvas id, the page id and the canvas uuid. Refuses a canvas or a page you may not edit, a page whose own category is switched off, an id that is not a canvas (bad_canvas) and a target that is not a post, page or custom item. The change history keeps the link meta of both as it was.',
		array(
			'post_id'   => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the post, page or custom item the canvas belongs to.' ),
			'canvas_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the canvas (see divi-canvases-list).' ),
		), array( 'post_id', 'canvas_id' ), 'd5b_wd_h_link_canvas', 'd5b_wd_can_link_canvas', array() );

	$d5b_wd_ds_text = array(
		'divi-ds-color-set'        => array( 'Set a Divi global colour', 'Creates or updates one Divi 5 global colour (by id, or by label) and returns it with action created or updated. The rest of the palette is untouched; the palette as it was is kept as a backup (divi-ds-backups-list, divi-ds-restore). Refuses a colour that is not a hex, rgb(), hsl() or colour token (bad_color), a bad id, a colour Divi\'s sanitiser would change, a site without Divi 5 and a caller who is not an administrator who may edit theme options.' ),
		'divi-ds-variable-set'     => array( 'Set a Divi design variable', 'Creates or updates one Divi 5 design variable (number, text, image, link, font or gradient) by id or by label, and returns it with action created or updated. References to other variables are checked: refuses a missing, inactive or other-type reference or a loop (bad_references), a value too convoluted to check (value_too_complex) or over 1 MB (value_too_large), a bad type or id, and a caller who is not an administrator who may edit theme options. The store as it was is kept as a backup.' ),
		'divi-ds-variable-archive' => array( 'Archive a Divi design variable', 'Archives one Divi 5 design variable, the way the builder deletes one (Divi keeps it in the store with status archived; divi-ds-variable-set with its id brings it back), and returns its id, type, label and action. Modules that use it fall back to no value. Refuses a variable another active variable still uses (variable_in_use, 409), an unknown id (not_found) and a caller who is not an administrator who may edit theme options. The store as it was is kept as a backup and in the change history.' ),
		'divi-ds-preset-set'       => array( 'Set a Divi preset', 'Creates or updates one Divi 5 module preset or option group preset (by id, or by name) and returns it with what was left out (content, settings outside the group) and warnings. Divi itself splits the settings into their style and render parts. Refuses a bad module or group name, settings that are empty once content is removed, a preset Divi\'s own preparation drops, a site without Divi 5 and a caller who is not an administrator who may edit theme options. The store as it was is kept as a backup.' ),
	);
	foreach ( d5b_wd_ds_writes() as $d5b_short => $d5b_w ) {
		$d5b_req = array( 'divi-ds-color-set' => array( 'label', 'color' ), 'divi-ds-variable-set' => array( 'type', 'label', 'value' ), 'divi-ds-variable-archive' => array( 'id' ), 'divi-ds-preset-set' => array( 'moduleName', 'name', 'attrs' ) )[ $d5b_short ];
		d5b_wc_def( $d5b_short, 'divi', $d5b_wd_ds_text[ $d5b_short ][0], $d5b_wd_ds_text[ $d5b_short ][1] . ' A body holding [redacted] from a masked read is refused (masked_read).', $d5b_w[2], $d5b_req,
			'd5b_wd_h_' . str_replace( '-', '_', substr( $d5b_short, 5 ) ), 'd5b_wd_can_ds',
			'divi-ds-variable-archive' === $d5b_short ? array( 'destructive' => true ) : array() );
	}
	unset( $d5b_wd_ds_text, $d5b_short, $d5b_w, $d5b_req );

	d5b_wc_def( 'divi-ds-backups-list', 'divi', 'List the Divi design-system backups',
		'Returns the backups the plugin keeps of the Divi design system (the newest 10 per store, plus the first of each of the last 5 days): store, time, whether it is the first of its day, and its size. The time is the id divi-ds-restore takes. Refuses a caller who is not an administrator who may edit theme options. Reads only, changes nothing.',
		array( 'store' => array( 'type' => 'string', 'enum' => array( 'presets', 'variables', 'colors' ), 'description' => 'Only the backups of this store: presets, variables or colors. Leave out for all three.' ) ),
		array(), 'd5b_wd_h_ds_backups_list', 'd5b_wd_can_ds', array( 'readonly' => true, 'idempotent' => true ) );

	d5b_wc_def( 'divi-ds-restore', 'divi', 'Restore a Divi design-system backup',
		'Puts one store of the Divi design system (presets, variables or colors) back as it was in a backup, chosen by its time from divi-ds-backups-list, and returns which backup was restored. The store as it is now becomes a backup first, so a restore can be undone the same way. On Divi 5.15 and later, variables made since that backup are kept, archived. Refuses a time no backup of that store has (backup_not_found), a time two backups share (ambiguous), a restore that would archive a variable another active variable uses (variable_in_use) and a caller who is not an administrator who may edit theme options.',
		array(
			'store' => array( 'type' => 'string', 'enum' => array( 'presets', 'variables', 'colors' ), 'description' => 'The store to restore: presets, variables or colors.' ),
			'time'  => array( 'type' => 'string', 'description' => 'The time of the backup to restore, exactly as divi-ds-backups-list gives it (like 2026-10-29T10:15:00+00:00).' ),
		), array( 'store', 'time' ), 'd5b_wd_h_ds_restore', 'd5b_wd_can_ds', array() );

	d5b_wc_def( 'divi-tb-write', 'divi', 'Write a Theme Builder layout',
		'Replaces the content of one Theme Builder header, body or footer layout (site-wide) and returns the old and new hash and sizes. expect_hash is the hash from divi-tb-get: a layout changed since (in the Visual Builder) is refused with stale and nothing is written. The previous content is kept as the layout\'s safety copy (divi-tb-restore puts it back) and in the change history; a save that does not keep the block tree is put back at once (roundtrip_failed). Refuses a caller who may not edit theme options or lacks unfiltered_html, an id that is not a header, body or footer layout (not_a_layout: pages and templates included), empty content, content that is not Divi, unbalanced block comments, and text holding [redacted] from a masked read (masked_read).',
		array(
			'id'          => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the header, body or footer layout (see divi-tb-list).' ),
			'content'     => array( 'type' => 'string', 'description' => 'The whole new content (Divi 5 block markup), at most 1 MB.' ),
			'expect_hash' => array( 'type' => 'string', 'description' => 'The hash from the last divi-tb-get of this layout. A hash that no longer matches is refused with stale.' ),
		), array( 'id', 'content', 'expect_hash' ), 'd5b_wd_h_tb_write', 'd5b_wd_can_tb', array() );

	d5b_wc_def( 'divi-tb-restore', 'divi', 'Restore a Theme Builder layout',
		'Swaps a Theme Builder header, body or footer layout with its safety copy (the content before the last write), and returns the old and new hash. The content it replaces becomes the new safety copy, so a restore can itself be undone, and is kept in the change history. Refuses a caller who may not edit theme options or lacks unfiltered_html, an id that is not a layout, a layout with no safety copy (no_backup) or an empty one (empty_backup).',
		array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the header, body or footer layout.' ) ),
		array( 'id' ), 'd5b_wd_h_tb_restore', 'd5b_wd_can_tb', array() );

	$d5b_wd_hash_from = 'expect_hash is the hash from posts-get, pages-get, cpt-get, divi-canvas-get, divi-library-get or divi-tb-get, or from the previous edit\'s answer. An edit that would change nothing writes nothing and answers changed false. The page itself is not returned (read it again if you need it). On a Theme Builder layout the write goes through the layout write, which can also refuse with empty_content, not_divi, unbalanced_blocks or roundtrip_failed (the layout is put back as it was)';
	d5b_wc_def( 'divi-section-edit', 'divi', 'Edit one block of a Divi page',
		'Changes one block (section, row, column or module) of a Divi 5 page, post, custom item, canvas, Divi Library item or Theme Builder header, body or footer layout, and leaves every byte outside that block as it was. op replace puts with in place of the block at at; before and after insert with next to it; append adds with as its last child; remove takes the block out. Returns the item (id, title, status, url, without its content) with its new hash (the expect_hash of the next edit), changed, tree (every block\'s address, block name, label and css id, as the page is now) and address (the edited block; for before, after and append the first new block; for remove the address the block had). '
		. $d5b_wd_hash_from . '. Refuses, writing nothing: stale when the item changed since that read (data.hash is the current hash: read the item again, look at it, then repeat); invalid_input when with does not parse on its own, holds no Divi block, or is the wrong kind for its place (a section beside a section, a row beside a row, a module beside a module, for append the kind a child of at has), when with is missing (or given for remove), when remove would take out the only top-level block (replace it instead), or when the stored page does not parse; not_found when nothing is at at; ambiguous when a label: or # address matches more than one block (data.candidates: repeat with one of those numeric addresses); masked_read when with holds [redacted] from a masked read; needs_unfiltered_html for a caller without that capability; not_allowed for templates and other kinds of item, an item you may not edit, or a switched-off category (the item\'s own, and Divi). Posts and pages get a WordPress revision; the change history keeps the previous content. To change words only, use divi-text-edit.',
		array(
			'post_id'     => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the page, post, custom item, canvas, Divi Library item or Theme Builder header, body or footer layout.' ),
			'expect_hash' => array( 'type' => 'string', 'description' => 'The hash of the content you read (from the get, or from the previous edit). An item changed since is refused with stale.' ),
			'op'          => array( 'type' => 'string', 'enum' => array( 'replace', 'before', 'after', 'append', 'remove' ), 'description' => 'replace the block at at with with; insert with before or after it; append with inside it as the last child; remove the block (no with).' ),
			'at'          => array( 'type' => 'string', 'description' => 'The block: a numeric address from the tree (3 = the third section; 3.2.1.4 = section 3, row 2, column 1, module 4; the placeholder wrapper is not counted), label:<admin label>, or #<css id>.' ),
			'with'        => array( 'type' => 'string', 'description' => 'Divi 5 block markup (<!-- wp:divi/... -->), at most 1 MB, for every op except remove. It must parse on its own; a whole compiled page is taken as its top-level blocks.' ),
		), array( 'post_id', 'expect_hash', 'op', 'at' ), 'd5b_wd_h_section_edit', 'd5b_wd_can_edit', array( 'destructive' => true ) );

	d5b_wc_def( 'divi-text-edit', 'divi', 'Change text on a Divi page',
		'Changes words inside one existing module of a Divi 5 page, post, custom item, canvas, Divi Library item or Theme Builder header, body or footer layout (a footer\'s opening hours, a heading, a button label), without touching the layout. It searches only the text a visitor reads in the modules\' text fields (heading, text, button, blurb, and the other text fields Divi has), with tags and Divi $variable(...)$ tokens (dynamic content) removed and entities decoded, never attribute names, settings, classes, ids or token contents; old must occur exactly once, and is replaced in the field\'s stored HTML, keeping the tags around it. A field stored with the same text for several breakpoints or states (desktop, tablet, hover) counts once and every copy is changed. Returns the item (id, title, status, url, without its content) with its new hash (the expect_hash of the next edit), changed, address and field (where the change was made; also_changed lists the other breakpoint or state copies) and excerpt (120 characters of the field\'s new text around the change, tokens shown as {variable}; not the post excerpt). '
		. $d5b_wd_hash_from . '. Refuses, writing nothing: not_found when old is not there exactly (data.near_matches lists up to 5 places that differ only in case, spacing, curly or straight quotes, or dashes: repeat with old set to the text of one of them; an entry marked crosses_markup spans a tag, has reads_as and html instead of text, and needs a shorter old or html true); ambiguous when old occurs more than once (data.matches lists address, field, breakpoint, state and count: repeat with at set to one of those addresses, or a longer old; two matches in one block differ by breakpoint or state, so make old longer); crosses_markup when the match runs across a tag or a $variable token, as old "Mon to Fri 9 to 5" against <strong>Mon to Fri</strong> 9 to 5 (data.html is the field as stored: shorten old to one side of the tag, or repeat with html true and old and new as HTML taken from data.html); invalid_input when new holds a $variable( token without html true, when a text field searched is over 256 KB (use at to search other blocks), or when an html true edit would change the text outside the replaced part or leave tags unbalanced; stale when the item changed since that read (read it again, then repeat); masked_read when new holds [redacted]; needs_unfiltered_html; not_allowed for templates and other kinds of item, an item you may not edit, or a switched-off category; not_found or ambiguous for an at that matches no block or several. To add, move or remove blocks, use divi-section-edit.',
		array(
			'post_id'     => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the page, post, custom item, canvas, Divi Library item or Theme Builder header, body or footer layout.' ),
			'expect_hash' => array( 'type' => 'string', 'description' => 'The hash of the content you read (from the get, or from the previous edit). An item changed since is refused with stale.' ),
			'old'         => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000, 'description' => 'The exact text to change, as a visitor reads it (case and spaces count), 1 to 2000 characters. With html true: the exact HTML as stored.' ),
			'new'         => array( 'type' => 'string', 'maxLength' => 2000, 'description' => 'The text to put in its place, 0 to 2000 characters, as a visitor should read it (& < > are written as text; no $variable tokens). With html true: HTML, written as given (tokens allowed).' ),
			'at'          => array( 'type' => 'string', 'description' => 'Optional: search only this block and the blocks inside it: a numeric address (3.2.1.4), label:<admin label> or #<css id>. Use it when old occurs more than once.' ),
			'html'        => array( 'type' => 'boolean', 'default' => false, 'description' => 'true matches old against the stored HTML of the fields instead of the visible text, and writes new as HTML. Use it after crosses_markup.' ),
		), array( 'post_id', 'expect_hash', 'old', 'new' ), 'd5b_wd_h_text_edit', 'd5b_wd_can_edit', array() );
	unset( $d5b_wd_hash_from );

	// Layout and page results carry post content keys (value-shape masking only). The design-system results are Divi's own
	// data, not third-party data: neither content nor raw, STRICT masking, as divi-design-system.
	d5b_ability_set_content_keys( array( 'divi-tb-write', 'divi-tb-restore', 'divi-section-edit', 'divi-text-edit' ) );
	d5b_ability_set_audit_args( array(
		'divi-tb-write' => 'd5b_wd_audit_tb', 'divi-ds-color-set' => 'd5b_wd_audit_cap', 'divi-ds-variable-set' => 'd5b_wd_audit_cap',
		'divi-ds-variable-archive' => 'd5b_wd_audit_cap', 'divi-ds-preset-set' => 'd5b_wd_audit_cap',
		'divi-section-edit' => 'd5b_wd_audit_cap', 'divi-text-edit' => 'd5b_wd_audit_cap',
	) );
}
