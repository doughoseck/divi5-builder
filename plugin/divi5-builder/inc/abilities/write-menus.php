<?php
/**
 * Write abilities for navigation menus (#32, release 2.2.0, Task 6): menus-create, menus-item-add, menus-item-update,
 * menus-item-remove, menus-item-restore, menus-reorder, menus-assign-location, and the two-step menu delete
 * (menus-delete-preview, menus-delete).
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here.
 *
 * Rules every ability here follows (design 2026-10-29, "Menus", "Delete preview and confirmation", guardrails 8-10):
 *  - Every ability needs edit_theme_options (as core's REST API and the Menus screen). A menu is given by id, slug or name
 *    and is resolved to its term id first; everything after (fingerprints, history, writes) uses that id.
 *  - A new item that links to content must link to something the caller may read, of an exposed type or taxonomy, whose
 *    own category is on (strictest switch: a page item needs Pages on, a term item needs Taxonomies on).
 *  - wp_update_nav_menu_item does NOT merge: every field it is not given is reset. Every update here passes the item's full
 *    data with the changes on top.
 *  - A menu item is a nav_menu_item post: remove trashes it (one step), restore takes it out of the trash, publishes it and
 *    puts it back in its menu, parent and position (kept on the trashed post; the caller may pass the history row's values
 *    as a fallback). A whole menu has no trash: its delete needs the preview's confirmation code, and the history row (the
 *    menu, every item in full, its locations) is written BEFORE the delete; when it cannot be written nothing is deleted.
 *  - Text holding the scrubber's [redacted] marker where the stored text does not is refused (masked_read).
 *  - Every other successful write writes one change-history row after the write; when that row cannot be stored the
 *    result carries _history_failed and the audit row gets ok_no_history. A refusal writes no history row.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wn_menu' ) ) {

	// ---- shared

	function d5b_wn_denied(): ?WP_Error {
		return current_user_can( 'edit_theme_options' ) ? null : new WP_Error( 'not_allowed', 'Changing menus needs the right to edit theme options.' );
	}

	/** The menu (a nav_menu term) for an id, slug or name, or null. */
	function d5b_wn_menu( $v ) {
		$m = wp_get_nav_menu_object( $v );
		return ( is_object( $m ) && ! is_wp_error( $m ) && (int) ( $m->term_id ?? 0 ) > 0 ) ? $m : null;
	}

	function d5b_wn_no_menu(): WP_Error {
		return new WP_Error( 'not_found', 'No menu with that id, slug or name.' );
	}

	/** The menus (term ids) a menu item belongs to. */
	function d5b_wn_item_menus( int $id ): array {
		$ids = wp_get_object_terms( $id, 'nav_menu', array( 'fields' => 'ids' ) );
		return is_array( $ids ) ? array_values( array_map( 'intval', $ids ) ) : array();
	}

	/** The nav_menu_item post of that id in that menu (any status, trash too), or null. */
	function d5b_wn_item( $menu, $id ) {
		$id = d5b_rc_id_of( $id );
		$p  = $id > 0 ? get_post( $id ) : null;
		if ( ! is_object( $p ) || 'nav_menu_item' !== ( $p->post_type ?? '' ) ) { return null; }
		return in_array( (int) $menu->term_id, d5b_wn_item_menus( $id ), true ) ? $p : null;
	}

	/** Everything that makes a menu item, as stored (the history keeps this; restore and update rebuild from it). */
	function d5b_wn_item_data( $p, bool $all_meta = false ): array {
		return d5b_wc_nav_item_data( $p, $all_meta );
	}

	/**
	 * Strictest switch for an existing item: one that links to content whose category is off (a page while Pages is off, a
	 * term while Taxonomies is off) is not changed, removed or restored. @return true|WP_Error
	 */
	function d5b_wn_item_gate( array $d ) {
		if ( 'post_type' === $d['type'] ) {
			$linked = get_post( (int) $d['object_id'] );
			$type   = is_object( $linked ) ? (string) $linked->post_type : (string) $d['object'];
			if ( '' !== $type && ! d5b_rc_type_on( $type ) ) { return new WP_Error( 'not_allowed', 'This item links to content whose ability category is switched off.' ); }
		} elseif ( 'taxonomy' === $d['type'] && ! d5b_abilities_category_on( 'taxonomy' ) ) {
			return new WP_Error( 'not_allowed', 'This item links to a term, and the ability category for taxonomies is switched off.' );
		}
		return true;
	}

	/** wp_update_nav_menu_item's data from an item's data (every field: core resets what it is not given). Slashed: core unslashes. */
	function d5b_wn_core_data( array $d ): array {
		return wp_slash( array(
			'menu-item-object-id' => (int) $d['object_id'], 'menu-item-object' => (string) $d['object'], 'menu-item-parent-id' => (int) $d['parent'],
			'menu-item-position' => (int) $d['position'], 'menu-item-type' => (string) $d['type'], 'menu-item-title' => (string) $d['title'],
			'menu-item-url' => (string) $d['url'], 'menu-item-description' => (string) $d['description'], 'menu-item-attr-title' => (string) $d['attr_title'],
			'menu-item-target' => (string) $d['target'], 'menu-item-classes' => implode( ' ', (array) $d['classes'] ), 'menu-item-xfn' => (string) $d['xfn'],
			'menu-item-status' => 'draft' === $d['status'] ? 'draft' : 'publish',
		) );
	}

	/**
	 * A position (menu_order) of at least 1. wp_update_nav_menu_item takes 0 as "after the last item" and would move an
	 * existing item with menu_order 0 (core gives the first item of an empty menu 0) to the end on any update: such an item
	 * keeps its place as WordPress shows it; a new one goes after the last item (drafts counted).
	 */
	function d5b_wn_fix_position( array $d, int $mid ): array {
		if ( (int) $d['position'] > 0 ) { return $d; }
		$max = 0;
		foreach ( (array) wp_get_nav_menu_items( $mid, array( 'post_status' => 'publish,draft' ) ) as $it ) {
			if ( (int) $d['id'] > 0 && (int) $it->ID === (int) $d['id'] ) { $d['position'] = (int) $it->menu_order; return $d; }
			$p = get_post( (int) $it->ID );
			if ( is_object( $p ) ) { $max = max( $max, (int) $p->menu_order, (int) $it->menu_order ); }
		}
		$d['position'] = $max + 1;
		return $d;
	}

	/** The locations (slugs) that show the menu. */
	function d5b_wn_locations_of( int $menu_id ): array {
		$out = array();
		foreach ( (array) get_nav_menu_locations() as $loc => $id ) { if ( (int) $id === $menu_id ) { $out[] = (string) $loc; } }
		return $out;
	}

	/** The whole menu as the history keeps it: its fields, EVERY item it holds (trashed and draft ones too) in full, its locations. */
	function d5b_wn_menu_state( $m ): array {
		$mid   = (int) $m->term_id;
		$items = array();
		foreach ( (array) get_objects_in_term( $mid, 'nav_menu' ) as $id ) {
			$p = get_post( (int) $id );
			if ( is_object( $p ) && 'nav_menu_item' === ( $p->post_type ?? '' ) ) { $items[] = d5b_wn_item_data( $p, true ); }
		}
		usort( $items, function ( $a, $b ) { return array( $a['position'], $a['id'] ) <=> array( $b['position'], $b['id'] ); } );
		$opts = get_option( 'nav_menu_options' );
		$auto = is_array( $opts ) && isset( $opts['auto_add'] ) && in_array( $mid, array_map( 'intval', (array) $opts['auto_add'] ), true );
		return array( 'id' => $mid, 'name' => (string) $m->name, 'slug' => (string) $m->slug, 'description' => (string) ( $m->description ?? '' ), 'items' => $items, 'locations' => d5b_wn_locations_of( $mid ), 'auto_add' => $auto );
	}

	/** True when every item of the menu can be kept in full by the history (no secret-named custom field anywhere). */
	function d5b_wn_menu_secret_free( int $mid ): bool {
		foreach ( (array) get_objects_in_term( $mid, 'nav_menu' ) as $id ) {
			if ( ! d5b_wc_post_secret_free( (int) $id ) ) { return false; }
		}
		return true;
	}

	function d5b_wn_menu_row( $m ): array {
		return array( 'id' => (int) $m->term_id, 'name' => (string) $m->name, 'slug' => (string) $m->slug );
	}

	function d5b_wn_menu_summary( string $verb, $m ): string {
		$name = d5b_rc_cut( preg_replace( '/\s+/', ' ', (string) $m->name ), 80 );
		return $verb . ' menu ' . (int) $m->term_id . ( '' === $name ? '' : " '" . $name . "'" );
	}

	function d5b_wn_item_summary( string $verb, array $d ): string {
		$title = d5b_rc_cut( preg_replace( '/\s+/', ' ', (string) $d['title'] ), 60 );
		return $verb . ' menu item ' . (int) $d['id'] . ( '' === $title ? '' : " '" . $title . "'" ) . ' in menu ' . (int) $d['menu'];
	}

	/** One history row; its id, 0 when it was not stored. */
	function d5b_wn_history( string $ability, string $type, int $id, string $action, ?array $before, array $after, string $summary ): int {
		if ( ! function_exists( 'd5b_history_write' ) ) { return 0; }
		return d5b_history_write( array(
			'ability' => 'divi5-builder/' . $ability, 'object_type' => $type, 'object_id' => $id, 'action' => $action,
			'before' => $before, 'after' => $after, 'revision_id' => 0, 'summary' => $summary,
		) );
	}

	function d5b_wn_out( array $out, int $hid ): array {
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	/** A custom link: http, https, mailto or tel, or a link on the site itself (/, #, ?). @return true|WP_Error */
	function d5b_wn_check_url( $url ) {
		$bad = new WP_Error( 'invalid_input', 'url must be an http, https, mailto or tel link, or a link on the site starting with /, # or ? (at most 2048 characters, no spaces).' );
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2048 || 1 !== preg_match( '//u', $url ) || 1 === preg_match( '/[\s\x00-\x1f\x7f]/', $url ) ) { return $bad; }
		if ( 1 === preg_match( '#^(/(?!/)|\#|\?)#', $url ) || '/' === $url ) { return true; }
		return 1 === preg_match( '#^(https?://[^/?\#]+|mailto:[^/]|tel:[+0-9])#i', $url ) ? true : $bad;
	}

	/** classes: names of letters, digits, - and _, separated by spaces. @return string[]|WP_Error */
	function d5b_wn_classes( string $s ) {
		$s = trim( $s );
		if ( '' === $s ) { return array(); }
		$out = preg_split( '/\s+/', $s );
		foreach ( $out as $c ) { if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $c ) ) { return new WP_Error( 'invalid_input', 'classes must be CSS class names (letters, digits, - and _) separated by spaces.' ); } }
		return array_values( array_unique( $out ) );
	}

	/** A parent for $self in $menu: 0, or a live (not trashed) item of the same menu that is not $self nor below it. @return true|WP_Error */
	function d5b_wn_check_parent( $menu, int $parent, int $self = 0 ) {
		if ( 0 === $parent ) { return true; }
		if ( $parent === $self ) { return new WP_Error( 'invalid_input', 'An item cannot be its own parent.' ); }
		$p = d5b_wn_item( $menu, $parent );
		if ( null === $p || 'trash' === $p->post_status ) { return new WP_Error( 'invalid_input', 'parent must be an item of the same menu (not in the trash), or 0.' ); }
		$seen = array();
		while ( null !== $p && ! isset( $seen[ (int) $p->ID ] ) && count( $seen ) < 1000 ) {
			$seen[ (int) $p->ID ] = true;
			$up = (int) get_post_meta( (int) $p->ID, '_menu_item_menu_item_parent', true );
			if ( $self > 0 && $up === $self ) { return new WP_Error( 'invalid_input', 'parent is below this item in the menu: that would make a loop.' ); }
			$p = $up > 0 ? d5b_wn_item( $menu, $up ) : null;
		}
		return true;
	}

	// ---- menus-create

	/** @return array|WP_Error */
	function d5b_wn_create( array $in ) {
		if ( $denied = d5b_wn_denied() ) { return $denied; }
		$a = d5b_rc_args( $in, array( 'name' => 'text:200' ), array( 'name' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$name = trim( $a['name'] );
		if ( '' === $name ) { return new WP_Error( 'invalid_input', 'name is required.' ); }
		$ok = d5b_wc_check_masked( $name, '' );
		if ( true !== $ok ) { return $ok; }
		$r = wp_create_nav_menu( wp_slash( $name ) );
		if ( is_wp_error( $r ) ) {
			if ( 'menu_exists' === $r->get_error_code() ) { return new WP_Error( 'invalid_input', wp_strip_all_tags( (string) $r->get_error_message() ) ); }
			return d5b_wc_core_error( $r );
		}
		$m = d5b_wn_menu( (int) $r );
		if ( null === $m ) { return new WP_Error( 'write_failed', 'WordPress did not create the menu.' ); }
		$hid = d5b_wn_history( 'menus-create', 'nav_menu', (int) $m->term_id, 'create', null, d5b_wn_menu_row( $m ), d5b_wn_menu_summary( 'Created', $m ) );
		return d5b_wn_out( d5b_wn_menu_row( $m ) + array( 'count' => 0 ), $hid );
	}

	// ---- items

	/** @return array|WP_Error */
	function d5b_wn_item_add( array $in ) {
		if ( $denied = d5b_wn_denied() ) { return $denied; }
		$object = $in['object'] ?? null;
		unset( $in['object'] );
		$a = d5b_rc_args( $in, array( 'menu' => 'menu', 'type' => 'enum:post_type|taxonomy|custom', 'title' => 'text:500', 'parent' => 'int', 'position' => 'int' ), array( 'menu', 'type' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		if ( null === $object || '' === $object ) { return new WP_Error( 'invalid_input', 'object is required: the id of the page, post or term, or the url of a custom link.' ); }
		$m = d5b_wn_menu( $a['menu'] );
		if ( null === $m ) { return d5b_wn_no_menu(); }
		$title = trim( (string) ( $a['title'] ?? '' ) );
		$d = array( 'id' => 0, 'menu' => (int) $m->term_id, 'status' => 'publish', 'parent' => (int) ( $a['parent'] ?? 0 ), 'position' => (int) ( $a['position'] ?? 0 ),
			'title' => $title, 'url' => '', 'type' => $a['type'], 'object' => '', 'object_id' => 0, 'classes' => array(), 'target' => '', 'attr_title' => '', 'description' => '', 'xfn' => '' );
		if ( 'custom' === $a['type'] ) {
			$ok = d5b_wn_check_url( $object );
			if ( true !== $ok ) { return $ok; }
			if ( '' === $title ) { return new WP_Error( 'invalid_input', 'title is required for a custom link.' ); }
			$d['url'] = $object; $d['object'] = 'custom';
		} else {
			$oid = d5b_rc_id_of( $object );
			if ( $oid < 1 ) { return new WP_Error( 'invalid_input', 'object must be the id of the ' . ( 'post_type' === $a['type'] ? 'page, post or item' : 'term' ) . '.' ); }
			if ( 'post_type' === $a['type'] ) {
				// Strictest switch: the linked content's own category must be on (a page needs Pages), and the caller may read it.
				$linked = d5b_rc_post( $oid, null );
				if ( null === $linked ) { return d5b_rc_deny( $oid, null ); }
				if ( in_array( (string) $linked->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) { return new WP_Error( 'invalid_input', 'That item is in the trash or cannot be linked from a menu.' ); }
				$d['object'] = (string) $linked->post_type;
			} else {
				$term = get_term( $oid );
				if ( ! is_object( $term ) || is_wp_error( $term ) ) { return new WP_Error( 'not_found', 'No term with that id.' ); }
				$tax = d5b_rc_taxonomy_check( (string) $term->taxonomy );
				if ( is_wp_error( $tax ) ) { return $tax; }
				if ( ! d5b_abilities_category_on( 'taxonomy' ) ) { return new WP_Error( 'not_allowed', 'The ability category for taxonomies is switched off.' ); }
				$d['object'] = $tax;
			}
			$d['object_id'] = $oid;
		}
		$ok = d5b_wn_check_parent( $m, $d['parent'] );
		if ( true !== $ok ) { return $ok; }
		$ok = d5b_wc_check_masked( $title, '' );
		if ( true !== $ok ) { return $ok; }
		$r = wp_update_nav_menu_item( (int) $m->term_id, 0, d5b_wn_core_data( d5b_wn_fix_position( $d, (int) $m->term_id ) ) );
		if ( is_wp_error( $r ) ) { return d5b_wc_core_error( $r ); }
		$p = d5b_wn_item( $m, (int) $r );
		if ( null === $p ) { return new WP_Error( 'write_failed', 'WordPress did not add the menu item.' ); }
		$after = d5b_wn_item_data( $p );
		$hid = d5b_wn_history( 'menus-item-add', 'nav_menu_item', (int) $p->ID, 'create', null, $after, d5b_wn_item_summary( 'Added', $after ) );
		return d5b_wn_out( $after, $hid );
	}

	/** @return array|WP_Error */
	function d5b_wn_item_update( array $in ) {
		if ( $denied = d5b_wn_denied() ) { return $denied; }
		$a = d5b_rc_args( $in, array( 'menu' => 'menu', 'item' => 'id', 'title' => 'text:500', 'url' => 'text:2048', 'parent' => 'int', 'position' => 'int', 'classes' => 'text:1000', 'target' => 'text:20' ), array( 'menu', 'item' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$m = d5b_wn_menu( $a['menu'] );
		if ( null === $m ) { return d5b_wn_no_menu(); }
		$p = d5b_wn_item( $m, $a['item'] );
		if ( null === $p ) { return new WP_Error( 'not_found', 'No item with that id in that menu.' ); }
		if ( 'trash' === $p->post_status ) { return new WP_Error( 'invalid_input', 'This menu item is in the trash: restore it first (divi5-builder/menus-item-restore).' ); }
		$changes = array_intersect_key( $a, array( 'title' => 1, 'url' => 1, 'parent' => 1, 'position' => 1, 'classes' => 1, 'target' => 1 ) );
		if ( array() === $changes ) { return new WP_Error( 'invalid_input', 'Nothing to change: give at least one of title, url, parent, position, classes, target.' ); }
		$before = d5b_wn_item_data( $p );
		$gate = d5b_wn_item_gate( $before );
		if ( true !== $gate ) { return $gate; }
		$new    = $before;
		if ( array_key_exists( 'title', $changes ) ) {
			$ok = d5b_wc_check_masked( $changes['title'], $before['title'] );
			if ( true !== $ok ) { return $ok; }
			$new['title'] = trim( $changes['title'] );
		}
		if ( array_key_exists( 'url', $changes ) ) {
			if ( 'custom' !== $before['type'] ) { return new WP_Error( 'invalid_input', 'Only a custom link has its own url; this item links to ' . $before['object'] . ' ' . $before['object_id'] . '.' ); }
			$ok = d5b_wn_check_url( $changes['url'] );
			if ( true !== $ok ) { return $ok; }
			$new['url'] = $changes['url'];
		}
		if ( array_key_exists( 'parent', $changes ) ) {
			$ok = d5b_wn_check_parent( $m, (int) $changes['parent'], (int) $p->ID );
			if ( true !== $ok ) { return $ok; }
			$new['parent'] = (int) $changes['parent'];
		}
		if ( array_key_exists( 'position', $changes ) ) {
			if ( (int) $changes['position'] < 1 ) { return new WP_Error( 'invalid_input', 'position starts at 1.' ); }
			$new['position'] = (int) $changes['position'];
		}
		if ( array_key_exists( 'classes', $changes ) ) {
			$cls = d5b_wn_classes( $changes['classes'] );
			if ( is_wp_error( $cls ) ) { return $cls; }
			$new['classes'] = $cls;
		}
		if ( array_key_exists( 'target', $changes ) ) {
			if ( ! in_array( $changes['target'], array( '', '_blank' ), true ) ) { return new WP_Error( 'invalid_input', 'target must be _blank (a new tab) or empty (the same tab).' ); }
			$new['target'] = $changes['target'];
		}
		$r = wp_update_nav_menu_item( (int) $m->term_id, (int) $p->ID, d5b_wn_core_data( d5b_wn_fix_position( $new, (int) $m->term_id ) ) );
		if ( is_wp_error( $r ) ) { return d5b_wc_core_error( $r ); }
		$np = d5b_wn_item( $m, (int) $p->ID );
		if ( null === $np ) { return new WP_Error( 'write_failed', 'WordPress did not save the change.' ); }
		$after = d5b_wn_item_data( $np );
		$hid = d5b_wn_history( 'menus-item-update', 'nav_menu_item', (int) $np->ID, 'update', $before, d5b_wc_diff( $before, $after ), d5b_wn_item_summary( 'Updated', $after ) );
		return d5b_wn_out( $after, $hid );
	}

	/** @return array|WP_Error */
	function d5b_wn_item_remove( array $in ) {
		if ( $denied = d5b_wn_denied() ) { return $denied; }
		$a = d5b_rc_args( $in, array( 'menu' => 'menu', 'item' => 'id' ), array( 'menu', 'item' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$m = d5b_wn_menu( $a['menu'] );
		if ( null === $m ) { return d5b_wn_no_menu(); }
		$p = d5b_wn_item( $m, $a['item'] );
		if ( null === $p ) { return new WP_Error( 'not_found', 'No item with that id in that menu.' ); }
		if ( 'trash' === $p->post_status ) { return new WP_Error( 'cannot_trash', 'This menu item is already in the trash.' ); }
		if ( ! d5b_wc_trash_available() ) { return new WP_Error( 'cannot_trash', 'This site has no trash (EMPTY_TRASH_DAYS is 0), so WordPress would delete the menu item for good. It is not removed.' ); }
		$before = d5b_wn_item_data( $p );
		$gate = d5b_wn_item_gate( $before );
		if ( true !== $gate ) { return $gate; }
		$r  = wp_trash_post( (int) $p->ID );
		$np = get_post( (int) $p->ID );
		if ( ! $r || ! is_object( $np ) || 'trash' !== $np->post_status ) { return new WP_Error( 'cannot_trash', 'WordPress did not move the menu item to the trash (a plugin may have stopped it).' ); }
		$after = d5b_wn_item_data( $np );
		$hid = d5b_wn_history( 'menus-item-remove', 'nav_menu_item', (int) $np->ID, 'trash', $before, d5b_wc_diff( $before, $after ), d5b_wn_item_summary( 'Removed', $before ) );
		return d5b_wn_out( $after + array( 'restore_with' => 'divi5-builder/menus-item-restore' ), $hid );
	}

	/** @return array|WP_Error */
	function d5b_wn_item_restore( array $in ) {
		if ( $denied = d5b_wn_denied() ) { return $denied; }
		$a = d5b_rc_args( $in, array( 'menu' => 'menu', 'item' => 'id', 'parent' => 'int', 'position' => 'int' ), array( 'menu', 'item' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$m = d5b_wn_menu( $a['menu'] );
		if ( null === $m ) { return d5b_wn_no_menu(); }
		$mid = (int) $m->term_id;
		$p   = get_post( (int) $a['item'] );
		if ( ! is_object( $p ) || 'nav_menu_item' !== ( $p->post_type ?? '' ) ) { return new WP_Error( 'not_found', 'No menu item with that id.' ); }
		$menus = d5b_wn_item_menus( (int) $p->ID );
		// The menu relation survives the trash; only when it is gone is the menu given here used (the history row's value).
		if ( array() !== $menus && ! in_array( $mid, $menus, true ) ) { return new WP_Error( 'not_found', 'No item with that id in that menu.' ); }
		if ( 'trash' !== $p->post_status ) { return new WP_Error( 'not_found', 'This menu item is not in the trash.' ); }
		$before = d5b_wn_item_data( $p );
		$gate = d5b_wn_item_gate( $before );
		if ( true !== $gate ) { return $gate; }
		$d      = $before;
		$d['status'] = 'publish';
		// Parent: the one kept on the item, else the fallback given; one that is gone (deleted or in the trash) becomes 0.
		$raw   = get_post_meta( (int) $p->ID, '_menu_item_menu_item_parent', false );
		$cands = array();
		if ( array() !== (array) $raw ) { $cands[] = (int) $before['parent']; }
		if ( isset( $a['parent'] ) ) { $cands[] = (int) $a['parent']; }
		$d['parent'] = 0;
		$reset = array() !== $cands;
		foreach ( $cands as $c ) {
			if ( true === d5b_wn_check_parent( $m, $c, (int) $p->ID ) ) { $d['parent'] = $c; $reset = false; break; }
		}
		// Position: the one kept on the item, else the fallback given, else after the last item.
		$d['position'] = (int) $p->menu_order > 0 ? (int) $p->menu_order : (int) ( $a['position'] ?? 0 );
		if ( $d['position'] < 1 ) { $d['position'] = d5b_wn_fix_position( array( 'id' => 0 ) + $d, $mid )['position']; }
		$r = wp_untrash_post( (int) $p->ID );
		$np = get_post( (int) $p->ID );
		if ( ! $r || ! is_object( $np ) || 'trash' === $np->post_status ) { return new WP_Error( 'write_failed', 'WordPress did not take the menu item out of the trash.' ); }
		$r = wp_update_nav_menu_item( $mid, (int) $p->ID, d5b_wn_core_data( $d ) );
		$np = d5b_wn_item( $m, (int) $p->ID );
		if ( is_wp_error( $r ) || null === $np || 'publish' !== $np->post_status ) {
			return new WP_Error( 'write_failed', 'The menu item is out of the trash, but WordPress did not publish it back into its menu (it may be a draft now). Set it again with divi5-builder/menus-item-update or in Appearance > Menus.' );
		}
		$after = d5b_wn_item_data( $np );
		$hid = d5b_wn_history( 'menus-item-restore', 'nav_menu_item', (int) $np->ID, 'restore', $before, d5b_wc_diff( $before, $after ), d5b_wn_item_summary( 'Restored', $after ) );
		return d5b_wn_out( $after + ( $reset ? array( 'parent_reset' => true ) : array() ), $hid );
	}

	// ---- menus-reorder

	/** The order input: a list of { id, parent }, each id once. @return array|WP_Error list of array( id, parent ) */
	function d5b_wn_order_input( $v ) {
		$bad = new WP_Error( 'invalid_input', 'items must be the list of every item of the menu in the new order, each as { "id": item id, "parent": parent item id or 0 } (at most 500).' );
		if ( ! is_array( $v ) || array() === $v || ! d5b_rc_is_list( $v ) || count( $v ) > 500 ) { return $bad; }
		$out = array();
		foreach ( $v as $row ) {
			if ( ! is_array( $row ) || array() !== array_diff( array_keys( $row ), array( 'id', 'parent' ) ) ) { return $bad; }
			$id  = d5b_rc_id_of( $row['id'] ?? null );
			$raw = $row['parent'] ?? 0;
			$pa  = ( 0 === $raw || '0' === $raw ) ? 0 : d5b_rc_id_of( $raw );
			if ( $id < 1 || ( 0 !== $raw && '0' !== $raw && $pa < 1 ) ) { return $bad; }
			if ( isset( $out[ $id ] ) ) { return new WP_Error( 'invalid_input', 'items lists item ' . $id . ' twice.' ); }
			$out[ $id ] = array( 'id' => $id, 'parent' => $pa );
		}
		return array_values( $out );
	}

	/** @return array|WP_Error */
	function d5b_wn_reorder( array $in ) {
		if ( $denied = d5b_wn_denied() ) { return $denied; }
		$items = $in['items'] ?? null;
		unset( $in['items'] );
		$a = d5b_rc_args( $in, array( 'menu' => 'menu' ), array( 'menu' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$order = d5b_wn_order_input( $items );
		if ( is_wp_error( $order ) ) { return $order; }
		$m = d5b_wn_menu( $a['menu'] );
		if ( null === $m ) { return d5b_wn_no_menu(); }
		$mid  = (int) $m->term_id;
		$live = array();
		foreach ( (array) wp_get_nav_menu_items( $mid ) as $it ) {
			$p = get_post( (int) $it->ID );
			if ( is_object( $p ) ) { $live[ (int) $p->ID ] = $p; }
		}
		// The whole menu, nothing more and nothing less: a partial list would leave the others where they were.
		$given = array_column( $order, 'id' );
		$missing = array_values( array_diff( array_keys( $live ), $given ) );
		$extra   = array_values( array_diff( $given, array_keys( $live ) ) );
		if ( array() !== $missing || array() !== $extra ) {
			return new WP_Error( 'invalid_input', 'items must hold every published item of the menu exactly once (see menus-items).', array( 'missing' => array_slice( $missing, 0, 50 ), 'not_in_menu' => array_slice( $extra, 0, 50 ) ) );
		}
		$parent = array();
		foreach ( $order as $row ) { $parent[ $row['id'] ] = $row['parent']; }
		foreach ( $order as $row ) {
			if ( 0 !== $row['parent'] && ( $row['parent'] === $row['id'] || ! isset( $parent[ $row['parent'] ] ) ) ) {
				return new WP_Error( 'invalid_input', 'The parent of item ' . $row['id'] . ' must be another item of the list, or 0.' );
			}
			$seen = array(); $up = $row['parent'];
			while ( 0 !== $up ) {
				if ( $up === $row['id'] || isset( $seen[ $up ] ) ) { return new WP_Error( 'invalid_input', 'The parents of item ' . $row['id'] . ' make a loop.' ); }
				$seen[ $up ] = true; $up = $parent[ $up ];
			}
		}
		// Strictest switch, before anything moves: an item that changes position or parent must pass the same gate as
		// menus-item-update (a link to a page while Pages is off is not moved). Items that stay where they are are not checked.
		foreach ( $order as $n => $row ) {
			$d = d5b_wn_item_data( $live[ $row['id'] ] );
			if ( $d['parent'] === $row['parent'] && $d['position'] === $n + 1 ) { continue; }
			$g = d5b_wn_item_gate( $d );
			if ( true !== $g ) { return new WP_Error( 'not_allowed', 'Item ' . $row['id'] . ' would move, and it links to content whose ability category is switched off. Nothing was moved.' ); }
		}
		$before = array();
		foreach ( $live as $id => $p ) { $before[] = array( 'id' => $id, 'parent' => (int) get_post_meta( $id, '_menu_item_menu_item_parent', true ), 'position' => (int) $p->menu_order ); }
		$after = array();
		foreach ( $order as $n => $row ) {
			$p = $live[ $row['id'] ];
			$d = d5b_wn_item_data( $p );
			$want = array( 'id' => $row['id'], 'parent' => $row['parent'], 'position' => $n + 1 );
			if ( $d['parent'] !== $want['parent'] || $d['position'] !== $want['position'] ) {
				$d['parent'] = $want['parent']; $d['position'] = $want['position'];
				$r = wp_update_nav_menu_item( $mid, $row['id'], d5b_wn_core_data( $d ) );
				if ( is_wp_error( $r ) ) {
					$hid = d5b_wn_history( 'menus-reorder', 'nav_menu', $mid, 'reorder', array( 'items' => $before ), array( 'items' => $after, 'stopped_at' => $row['id'] ), d5b_wn_menu_summary( 'Partly reordered', $m ) );
					return new WP_Error( 'write_failed', 'WordPress did not move item ' . $row['id'] . '; the items before it in the list were already moved.' . ( $hid > 0 ? ' History row ' . $hid . ' holds the old order.' : '' ) );
				}
			}
			$after[] = $want;
		}
		$hid = d5b_wn_history( 'menus-reorder', 'nav_menu', $mid, 'reorder', array( 'items' => $before ), array( 'items' => $after ), d5b_wn_menu_summary( 'Reordered', $m ) );
		return d5b_wn_out( array( 'menu' => d5b_wn_menu_row( $m ), 'items' => $after ), $hid );
	}

	// ---- menus-assign-location

	/** @return array|WP_Error */
	function d5b_wn_assign_location( array $in ) {
		if ( $denied = d5b_wn_denied() ) { return $denied; }
		$menu_in = $in['menu'] ?? null;
		unset( $in['menu'] );
		$a = d5b_rc_args( $in, array( 'location' => 'slug' ), array( 'location' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$registered = (array) get_registered_nav_menus();
		if ( ! isset( $registered[ $a['location'] ] ) ) {
			return new WP_Error( 'invalid_input', 'The theme has no menu location "' . $a['location'] . '". Locations: ' . implode( ', ', array_map( 'strval', array_keys( $registered ) ) ) . '.' );
		}
		$m = null;
		if ( 0 !== $menu_in && '0' !== $menu_in ) {
			$mm = d5b_rc_args( array( 'menu' => $menu_in ), array( 'menu' => 'menu' ), array( 'menu' ) );
			if ( is_wp_error( $mm ) ) { return new WP_Error( 'invalid_input', 'menu is required: a menu id, slug or name, or 0 to clear the location.' ); }
			$m = d5b_wn_menu( $mm['menu'] );
			if ( null === $m ) { return d5b_wn_no_menu(); }
		}
		$new  = null === $m ? 0 : (int) $m->term_id;
		$locs = (array) get_nav_menu_locations();
		$old  = (int) ( $locs[ $a['location'] ] ?? 0 );
		$locs[ $a['location'] ] = $new;
		set_theme_mod( 'nav_menu_locations', $locs );
		$now = (array) get_nav_menu_locations();
		if ( (int) ( $now[ $a['location'] ] ?? 0 ) !== $new ) { return new WP_Error( 'write_failed', 'WordPress did not save the menu location.' ); }
		$summary = null === $m ? 'Cleared menu location ' . $a['location'] : d5b_wn_menu_summary( 'Assigned', $m ) . ' to location ' . $a['location'];
		$hid = d5b_wn_history( 'menus-assign-location', 'nav_menu', 0 === $new ? $old : $new, 'assign', array( 'location' => $a['location'], 'menu_id' => $old ), array( 'location' => $a['location'], 'menu_id' => $new ), $summary );
		return d5b_wn_out( array( 'location' => $a['location'], 'menu_id' => $new, 'previous_menu_id' => $old ), $hid );
	}

	// ---- the two-step menu delete

	/** @return array|WP_Error */
	function d5b_wn_delete_preview( array $in ) {
		if ( $denied = d5b_wn_denied() ) { return $denied; }
		$a = d5b_rc_args( $in, array( 'menu' => 'menu' ), array( 'menu' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$m = d5b_wn_menu( $a['menu'] );
		if ( null === $m ) { return d5b_wn_no_menu(); }
		$mid   = (int) $m->term_id;
		if ( ! d5b_wn_menu_secret_free( $mid ) ) { return d5b_wc_secret_error( "menu has an item that" ); }
		$state = d5b_wn_menu_state( $m );
		$shown = array();
		foreach ( $state['items'] as $d ) {
			// As menus-items: a link to content the caller may not read, or whose category is off, keeps its slot only.
			if ( 'post_type' === $d['type'] ) {
				$linked = d5b_rc_post( $d['object_id'], null, false );
				if ( null === $linked || ! d5b_rc_type_on( (string) $linked->post_type ) ) {
					$shown[] = array( 'id' => $d['id'], 'type' => 'hidden', 'status' => $d['status'], 'parent' => $d['parent'], 'position' => $d['position'] );
					continue;
				}
			}
			$shown[] = $d;
		}
		$code = d5b_confirm_issue( 'divi5-builder/menus-delete', 'nav_menu', $mid, d5b_fp_menu( $mid ) );
		if ( is_wp_error( $code ) ) { return $code; }
		return array(
			'menu' => d5b_wn_menu_row( $m ), 'items' => $shown, 'items_total' => count( $shown ), 'locations' => $state['locations'],
			'note' => 'A menu has no trash: the delete removes the menu and every item in it (items already in the trash too) for good, and takes it off its locations. The change history keeps the menu, every item in full and its locations, so it can be rebuilt by hand.',
		) + $code;
	}

	/** @return array|WP_Error */
	function d5b_wn_delete( array $in ) {
		if ( $denied = d5b_wn_denied() ) { return $denied; }
		$a = d5b_rc_args( $in, array( 'menu' => 'menu', 'confirmation' => 'str' ), array( 'menu' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$m = d5b_wn_menu( $a['menu'] );
		if ( null === $m ) { return d5b_wn_no_menu(); }
		// The menu as a term id, never the slug or name given: the fingerprint reads the items of exactly this menu.
		$mid = (int) $m->term_id;
		if ( ! d5b_wn_menu_secret_free( $mid ) ) { return d5b_wc_secret_error( "menu has an item that" ); }
		$ok  = d5b_confirm_check( 'divi5-builder/menus-delete', 'nav_menu', $mid, d5b_fp_menu( $mid ), (string) ( $a['confirmation'] ?? '' ) );
		if ( true !== $ok ) { return $ok; }
		// The history row comes FIRST: once the menu is gone it is all there is to rebuild it from.
		$state = d5b_wn_menu_state( $m );
		$hid = d5b_wn_history( 'menus-delete', 'nav_menu', $mid, 'delete', $state, array( 'deleted' => true ), d5b_wn_menu_summary( 'Permanently deleted', $m ) );
		if ( $hid < 1 ) {
			return new WP_Error( 'history_unavailable', 'The change history cannot be written on this site, so the permanent delete was refused: without that record nothing could be put back. Nothing was deleted; run the preview again once the history works.' );
		}
		$exact = d5b_wc_delete_record_ok( $hid );
		if ( true !== $exact ) { return $exact; }
		$r    = wp_delete_nav_menu( $mid );
		$left = array();
		foreach ( $state['items'] as $d ) { if ( null !== get_post( $d['id'] ) ) { $left[] = $d['id']; } }
		if ( true !== $r || null !== d5b_wn_menu( $mid ) || array() !== $left ) {
			$err = is_wp_error( $r ) ? 'wp_error:' . $r->get_error_code() : ( true === $r ? 'still_present' : 'wp_delete_nav_menu_failed' );
			$fid = d5b_wn_history( 'menus-delete', 'nav_menu', $mid, 'delete_failed', null, array( 'deleted' => false, 'error' => $err, 'items_left' => $left, 'history_id' => $hid ), d5b_wn_menu_summary( 'Failed to delete', $m ) );
			return new WP_Error( 'write_failed', 'WordPress did not finish the delete (a plugin may have stopped it): the menu may be partly deleted (some of its items may be gone). History row ' . $hid . ' holds its full state' . ( $fid > 0 ? '; row ' . $fid . ' records the failure.' : '.' ), array( 'history_id' => $hid ) );
		}
		return array( 'deleted' => true, 'id' => $mid, 'items_deleted' => count( $state['items'] ), 'locations_cleared' => $state['locations'], 'history_id' => $hid );
	}

	// ---- permissions (the handler checks again)

	function d5b_wn_can( array $in ): bool {
		return current_user_can( 'read' ) && d5b_rc_can_menus( $in );
	}

	function d5b_wn_h_create( array $in ) { return d5b_wn_create( $in ); }
	function d5b_wn_h_item_add( array $in ) { return d5b_wn_item_add( $in ); }
	function d5b_wn_h_item_update( array $in ) { return d5b_wn_item_update( $in ); }
	function d5b_wn_h_item_remove( array $in ) { return d5b_wn_item_remove( $in ); }
	function d5b_wn_h_item_restore( array $in ) { return d5b_wn_item_restore( $in ); }
	function d5b_wn_h_reorder( array $in ) { return d5b_wn_reorder( $in ); }
	function d5b_wn_h_assign_location( array $in ) { return d5b_wn_assign_location( $in ); }
	function d5b_wn_h_delete_preview( array $in ) { return d5b_wn_delete_preview( $in ); }
	function d5b_wn_h_delete( array $in ) { return d5b_wn_delete( $in ); }

	// ---- the definitions

	$d5b_wn_menu = array( 'menu' => array( 'type' => array( 'integer', 'string' ), 'description' => 'The menu id, slug or name (see menus-list).' ) );
	$d5b_wn_item = array( 'item' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The menu item id (see menus-items).' ) );
	$d5b_wn_pos  = array(
		'parent'   => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'The id of the parent item in the same menu, 0 for the top level.' ),
		'position' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The menu order (1 = first). Other items are not moved: to set the whole order use menus-reorder.' ),
	);

	d5b_wc_def( 'menus-create', 'menus', 'Create a menu',
		'Creates an empty navigation menu and returns its id, name and slug. Refuses a name another menu has, and callers without edit_theme_options. The change history records it.',
		array( 'name' => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'The menu name, at most 200 characters.' ) ),
		array( 'name' ), 'd5b_wn_h_create', 'd5b_wn_can', array() );

	d5b_wc_def( 'menus-item-add', 'menus', 'Add a menu item',
		'Adds one published item to a menu and returns it: type post_type links to a page, post or item (object = its id; it must be one you may read, and its category must be on, so a page needs Pages on), taxonomy links to a term (object = its id; Taxonomies must be on), custom is a link (object = the url: http, https, mailto, tel, or a link on the site starting with /, # or ?; title required). title is optional for links to content (empty = the content\'s own title). parent (an item of the same menu) and position (the menu order; other items are not moved) are optional; without position the item goes last. Refuses an unknown menu, a parent outside the menu, and a title holding [redacted] (masked_read). Needs edit_theme_options. The change history records it.',
		$d5b_wn_menu + array(
			'type' => array( 'type' => 'string', 'enum' => array( 'post_type', 'taxonomy', 'custom' ), 'description' => 'post_type, taxonomy or custom.' ),
			'object' => array( 'type' => array( 'integer', 'string' ), 'description' => 'The id of the page, post, item or term, or the url of a custom link.' ),
			'title' => array( 'type' => 'string', 'maxLength' => 500, 'description' => 'The text of the link.' ),
		) + $d5b_wn_pos, array( 'menu', 'type', 'object' ), 'd5b_wn_h_item_add', 'd5b_wn_can', array() );

	d5b_wc_def( 'menus-item-update', 'menus', 'Update a menu item',
		'Changes the title, url (custom links only), parent, position, classes or target of one menu item (only the fields given; WordPress would reset the others, so the item\'s full data is sent with the change) and returns it. An empty title on a link to content shows the content\'s own title. target is _blank (new tab) or empty. Refuses an item in the trash, an item linking to content whose category is switched off (a page while Pages is off, a term while Taxonomies is off), and text holding [redacted] (masked_read). Needs edit_theme_options. The change history keeps the previous values.',
		$d5b_wn_menu + $d5b_wn_item + array(
			'title' => array( 'type' => 'string', 'maxLength' => 500, 'description' => 'The text of the link.' ),
			'url' => array( 'type' => 'string', 'maxLength' => 2048, 'description' => 'The link of a custom item.' ),
		) + $d5b_wn_pos + array(
			'classes' => array( 'type' => 'string', 'maxLength' => 1000, 'description' => 'CSS classes separated by spaces; empty clears them.' ),
			'target' => array( 'type' => 'string', 'enum' => array( '', '_blank' ), 'description' => '_blank to open in a new tab, empty for the same tab.' ),
		), array( 'menu', 'item' ), 'd5b_wn_h_item_update', 'd5b_wn_can', array( 'idempotent' => true ) );

	d5b_wc_def( 'menus-item-remove', 'menus', 'Remove a menu item',
		'Moves one menu item to the trash (a menu item is a post) and returns it with restore_with, the ability that puts it back. Items below it keep it as their parent until it is restored. Refuses an item already in the trash, an item linking to content whose category is switched off, and a site without a trash (EMPTY_TRASH_DAYS 0). Needs edit_theme_options. The change history keeps the item in full (menu, parent, position, title, url, type, object, classes, target).',
		$d5b_wn_menu + $d5b_wn_item, array( 'menu', 'item' ), 'd5b_wn_h_item_remove', 'd5b_wn_can', array( 'destructive' => true ) );

	d5b_wc_def( 'menus-item-restore', 'menus', 'Restore a menu item',
		'Takes one removed menu item out of the trash and puts it back published, in its menu, parent and position (kept on the trashed item). parent and position are optional fallbacks (the values in the history row of the remove), used only when the item lost its own; a parent that is gone makes it a top-level item (parent_reset true). Returns the item. Refuses an item that is not in the trash and an item linking to content whose category is switched off. Needs edit_theme_options.',
		$d5b_wn_menu + $d5b_wn_item + array(
			'parent' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Fallback parent item id.' ),
			'position' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Fallback position (menu order).' ),
		), array( 'menu', 'item' ), 'd5b_wn_h_item_restore', 'd5b_wn_can', array() );

	d5b_wc_def( 'menus-reorder', 'menus', 'Reorder a menu',
		'Sets the whole order of a menu: items is the list of EVERY published item of the menu (as menus-items lists them), in the new order, each with its parent ({ "id": 12, "parent": 0 }); the first is position 1. A list that misses an item or holds one from elsewhere is refused (data.missing, data.not_in_menu), so are a parent outside the list and parents that make a loop. Draft items are not touched. Returns the new order (id, parent, position). Needs edit_theme_options. The change history keeps the previous order and parents.',
		$d5b_wn_menu + array( 'items' => array( 'type' => 'array', 'maxItems' => 500, 'items' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ), 'parent' => array( 'type' => 'integer', 'minimum' => 0 ) ), 'required' => array( 'id' ) ), 'description' => 'Every item of the menu in the new order, each with its parent (0 = top level).' ) ),
		array( 'menu', 'items' ), 'd5b_wn_h_reorder', 'd5b_wn_can', array( 'idempotent' => true ) );

	d5b_wc_def( 'menus-assign-location', 'menus', 'Assign a menu to a location',
		'Shows a menu in one of the theme\'s menu locations (see menus-locations), or with menu 0 clears the location. Returns the location, the menu id now and the previous one. Refuses a location the theme does not register. Needs edit_theme_options. The change history keeps the previous menu.',
		array( 'location' => array( 'type' => 'string', 'description' => 'The location slug, for example primary-menu.' ), 'menu' => array( 'type' => array( 'integer', 'string' ), 'description' => 'The menu id, slug or name, or 0 to clear the location.' ) ),
		array( 'location', 'menu' ), 'd5b_wn_h_assign_location', 'd5b_wn_can', array( 'idempotent' => true ) );

	d5b_wc_def( 'menus-delete-preview', 'menus', 'Preview a menu delete',
		'Step one of a permanent menu delete (a menu has no trash). Changes nothing. Returns the menu, every item in it (a link to content you may not read, or whose category is off, shows as type hidden), the locations that show it, and a confirmation code valid for 10 minutes for this user and this menu as it is now (its items and their last change). Refuses a menu with an item whose custom fields hold a secret-named key (not_allowed: the history could not keep it; delete it in wp-admin). Show the preview to the user and wait for their explicit OK before calling divi5-builder/menus-delete with the code. Refuses an unknown menu. Needs edit_theme_options.',
		$d5b_wn_menu, array( 'menu' ), 'd5b_wn_h_delete_preview', 'd5b_wn_can', array( 'readonly' => true ) );

	d5b_wc_def( 'menus-delete', 'menus', 'Delete a menu for good',
		'Step two of a permanent menu delete: deletes the menu and every item in it, takes it off its locations, and returns deleted true with the history row id. Only with the confirmation code from menus-delete-preview, by the same user, for the same menu, unchanged since the preview (no item added, removed or changed), used once, within 10 minutes; anything else is refused with confirmation_invalid and data.reason, and nothing is deleted. The change history row is written BEFORE the delete and keeps the menu, every item in full (with its other custom fields), its locations and whether new pages are added to it automatically (auto_add); when it cannot be written the delete is refused (history_unavailable). Needs edit_theme_options.',
		$d5b_wn_menu + array( 'confirmation' => array( 'type' => 'string', 'description' => 'The confirmation code from menus-delete-preview, after the user said OK.' ) ),
		array( 'menu', 'confirmation' ), 'd5b_wn_h_delete', 'd5b_wn_can', array( 'destructive' => true ) );

	unset( $d5b_wn_menu, $d5b_wn_item, $d5b_wn_pos );
}
