<?php
/**
 * Write abilities for taxonomy terms and custom fields (#32, release 2.2.0, Task 6): taxonomy-create, taxonomy-update,
 * taxonomy-assign, the two-step term delete (taxonomy-delete-preview, taxonomy-delete); meta-set and the two-step custom
 * field delete (meta-delete-preview, meta-delete).
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here.
 *
 * Rules every ability here follows (design 2026-10-29, "Taxonomy", "Custom fields", "Delete preview and confirmation",
 * guardrails 8 and 10):
 *  - Terms: only public, non-internal taxonomies (d5b_rc_taxonomy_check, as the reads). Create, update and delete need the
 *    taxonomy's cap->manage_terms; assign needs its cap->assign_terms AND edit_post on the post, and the post's own category
 *    switch must be on as well as Taxonomy (strictest switch wins).
 *  - Custom fields: edit_post on the post (its category on, as well as Custom fields) AND edit_post_meta / delete_post_meta
 *    for the key. Protected keys (is_protected_meta: the _ keys, Divi's _et_* among them) and secret-named keys
 *    (d5b_is_secret_key) are refused with not_allowed. A value is a scalar or an array of scalars and arrays; a PHP object
 *    is refused (it would be stored serialized).
 *  - A term and a custom field have no trash: a delete needs the preview's confirmation code, and the history row is
 *    written BEFORE the delete; when it cannot be written nothing is deleted (history_unavailable). The default category
 *    (and a taxonomy's default term) is never deleted.
 *  - Text holding the scrubber's [redacted] marker where the stored text does not is refused (masked_read).
 *  - Every other successful write writes one change-history row after the write; when that row cannot be stored the
 *    result carries _history_failed and the audit row gets ok_no_history. A refusal writes no history row.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wt_tax' ) ) {

	// ---- shared

	/** The taxonomy object when the abilities may use it. @return object|WP_Error */
	function d5b_wt_tax( $tax ) {
		$name = d5b_rc_taxonomy_check( $tax );
		if ( is_wp_error( $name ) ) { return $name; }
		$obj = get_taxonomy( $name );
		return is_object( $obj ) ? $obj : new WP_Error( 'invalid_input', 'There is no taxonomy "' . $name . '".' );
	}

	/** The taxonomy's own capability ($which: manage_terms, assign_terms); '' (never granted) when it has none. */
	function d5b_wt_cap( $tax_obj, string $which ): string {
		return ( isset( $tax_obj->cap ) && is_object( $tax_obj->cap ) && isset( $tax_obj->cap->$which ) ) ? (string) $tax_obj->cap->$which : '';
	}

	function d5b_wt_has_cap( $tax_obj, string $which ): bool {
		$cap = d5b_wt_cap( $tax_obj, $which );
		return '' !== $cap && current_user_can( $cap );
	}

	/** The term of that taxonomy, or null. */
	function d5b_wt_term( $id, string $tax ) {
		$id = d5b_rc_id_of( $id );
		if ( $id < 1 ) { return null; }
		$t = get_term( $id, $tax );
		return ( is_object( $t ) && ! is_wp_error( $t ) && (string) $t->taxonomy === $tax ) ? $t : null;
	}

	/** True for the default category and a taxonomy's default term: WordPress never deletes them. */
	function d5b_wt_is_default( $term ): bool {
		$tax = (string) $term->taxonomy;
		if ( 'category' === $tax && (int) $term->term_id === (int) get_option( 'default_category' ) ) { return true; }
		$obj = get_taxonomy( $tax );
		return is_object( $obj ) && ! empty( $obj->default_term ) && (int) $term->term_id === (int) get_option( 'default_term_' . $tax );
	}

	function d5b_wt_default_error(): WP_Error {
		return new WP_Error( 'not_allowed', 'This is the default term of its taxonomy (for categories: the one new posts get), and WordPress never deletes it. Make another term the default first (for categories: Settings > Writing).' );
	}

	/**
	 * A parent for a term of $tax: 0, or a term of the same hierarchical taxonomy that is not $self nor one of its
	 * descendants (no loops). @return true|WP_Error
	 */
	function d5b_wt_check_parent( string $tax, int $parent, int $self = 0 ) {
		if ( 0 === $parent ) { return true; }
		if ( ! is_taxonomy_hierarchical( $tax ) ) { return new WP_Error( 'invalid_input', 'The taxonomy "' . $tax . '" has no hierarchy: parent must be 0 or left out.' ); }
		if ( $parent === $self ) { return new WP_Error( 'invalid_input', 'A term cannot be its own parent.' ); }
		$cur = d5b_wt_term( $parent, $tax );
		if ( null === $cur ) { return new WP_Error( 'invalid_input', 'parent is not a term of the taxonomy "' . $tax . '".' ); }
		$seen = array();
		while ( null !== $cur && (int) $cur->parent > 0 && ! isset( $seen[ (int) $cur->term_id ] ) && count( $seen ) < 1000 ) {
			$seen[ (int) $cur->term_id ] = true;
			if ( $self > 0 && (int) $cur->parent === $self ) { return new WP_Error( 'invalid_input', 'parent is below this term in the hierarchy: that would make a loop.' ); }
			$cur = d5b_wt_term( (int) $cur->parent, $tax );
		}
		return true;
	}

	/** "Created category 12 'News'". */
	function d5b_wt_summary( string $verb, $t ): string {
		$name = d5b_rc_cut( preg_replace( '/\s+/', ' ', (string) $t->name ), 80 );
		return $verb . ' ' . (string) $t->taxonomy . ' term ' . (int) $t->term_id . ( '' === $name ? '' : " '" . $name . "'" );
	}

	/** One history row; its id, 0 when it was not stored. */
	function d5b_wt_history( string $ability, string $type, int $id, string $action, ?array $before, array $after, string $summary ): int {
		if ( ! function_exists( 'd5b_history_write' ) ) { return 0; }
		return d5b_history_write( array(
			'ability' => 'divi5-builder/' . $ability, 'object_type' => $type, 'object_id' => $id, 'action' => $action,
			'before' => $before, 'after' => $after, 'revision_id' => 0, 'summary' => $summary,
		) );
	}

	function d5b_wt_history_unavailable(): WP_Error {
		return new WP_Error( 'history_unavailable', 'The change history cannot be written on this site, so the permanent delete was refused: without that record nothing could be put back. Nothing was deleted; run the preview again once the history works.' );
	}

	/** A WP_Error of core's term functions in the plugin's codes (term_exists keeps the existing term id). */
	function d5b_wt_core_error( WP_Error $e ): WP_Error {
		if ( 'term_exists' === $e->get_error_code() ) {
			$data = $e->get_error_data();
			return new WP_Error( 'invalid_input', (string) $e->get_error_message(), is_numeric( $data ) ? array( 'existing_id' => (int) $data ) : null );
		}
		return d5b_wc_core_error( $e );
	}

	// ---- taxonomy-create, taxonomy-update

	/** @return array|WP_Error */
	function d5b_wt_create( array $in ) {
		$a = d5b_rc_args( $in, array( 'taxonomy' => 'slug', 'name' => 'text:200', 'slug' => 'text:200', 'parent' => 'int', 'description' => 'text:10000' ), array( 'taxonomy', 'name' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$t = d5b_wt_tax( $a['taxonomy'] );
		if ( is_wp_error( $t ) ) { return $t; }
		$tax = (string) $t->name;
		if ( ! d5b_wt_has_cap( $t, 'manage_terms' ) ) { return new WP_Error( 'not_allowed', 'You may not manage the terms of the taxonomy "' . $tax . '".' ); }
		$name = trim( $a['name'] );
		if ( '' === $name ) { return new WP_Error( 'invalid_input', 'name is required.' ); }
		$args = array( 'description' => $a['description'] ?? '', 'parent' => (int) ( $a['parent'] ?? 0 ) );
		if ( isset( $a['slug'] ) && '' !== $a['slug'] ) {
			$args['slug'] = sanitize_title( $a['slug'] );
			if ( '' === $args['slug'] ) { return new WP_Error( 'invalid_input', 'slug must hold letters or numbers.' ); }
		}
		$ok = d5b_wt_check_parent( $tax, $args['parent'] );
		if ( true !== $ok ) { return $ok; }
		foreach ( array( $name, $args['description'] ) as $text ) {
			$ok = d5b_wc_check_masked( $text, '' );
			if ( true !== $ok ) { return $ok; }
		}
		// core unslashes the name, the description and the other args.
		$r = wp_insert_term( wp_slash( $name ), $tax, wp_slash( $args ) );
		if ( is_wp_error( $r ) ) { return d5b_wt_core_error( $r ); }
		$term = is_array( $r ) ? d5b_wt_term( $r['term_id'] ?? 0, $tax ) : null;
		if ( null === $term ) { return new WP_Error( 'write_failed', 'WordPress did not create the term.' ); }
		$hid = d5b_wt_history( 'taxonomy-create', 'term', (int) $term->term_id, 'create', null, d5b_rc_term_detail( $term ), d5b_wt_summary( 'Created', $term ) );
		$out = d5b_rc_term_detail( $term );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	/** @return array|WP_Error */
	function d5b_wt_update( array $in ) {
		$a = d5b_rc_args( $in, array( 'taxonomy' => 'slug', 'id' => 'id', 'name' => 'text:200', 'slug' => 'text:200', 'parent' => 'int', 'description' => 'text:10000' ), array( 'taxonomy', 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$t = d5b_wt_tax( $a['taxonomy'] );
		if ( is_wp_error( $t ) ) { return $t; }
		$tax = (string) $t->name;
		if ( ! d5b_wt_has_cap( $t, 'manage_terms' ) ) { return new WP_Error( 'not_allowed', 'You may not manage the terms of the taxonomy "' . $tax . '".' ); }
		$term = d5b_wt_term( $a['id'], $tax );
		if ( null === $term ) { return new WP_Error( 'not_found', 'No term with that id in that taxonomy.' ); }
		$changes = array_intersect_key( $a, array( 'name' => 1, 'slug' => 1, 'parent' => 1, 'description' => 1 ) );
		if ( array() === $changes ) { return new WP_Error( 'invalid_input', 'Nothing to change: give at least one of name, slug, parent, description.' ); }
		if ( isset( $changes['name'] ) ) {
			$changes['name'] = trim( $changes['name'] );
			if ( '' === $changes['name'] ) { return new WP_Error( 'invalid_input', 'name cannot be empty.' ); }
		}
		if ( isset( $changes['slug'] ) ) {
			$changes['slug'] = sanitize_title( $changes['slug'] );
			if ( '' === $changes['slug'] ) { return new WP_Error( 'invalid_input', 'slug must hold letters or numbers.' ); }
		}
		if ( isset( $changes['parent'] ) ) {
			$ok = d5b_wt_check_parent( $tax, (int) $changes['parent'], (int) $term->term_id );
			if ( true !== $ok ) { return $ok; }
		}
		foreach ( array( 'name', 'description' ) as $f ) {
			if ( ! isset( $changes[ $f ] ) ) { continue; }
			$ok = d5b_wc_check_masked( $changes[ $f ], (string) $term->$f );
			if ( true !== $ok ) { return $ok; }
		}
		$before = d5b_rc_term_detail( $term );
		$r = wp_update_term( (int) $term->term_id, $tax, wp_slash( $changes ) );
		if ( is_wp_error( $r ) ) { return d5b_wt_core_error( $r ); }
		$nt = d5b_wt_term( (int) $term->term_id, $tax );
		if ( null === $nt ) { return new WP_Error( 'write_failed', 'WordPress did not save the change.' ); }
		$after = d5b_rc_term_detail( $nt );
		$hid = d5b_wt_history( 'taxonomy-update', 'term', (int) $nt->term_id, 'update', $before, d5b_wc_diff( $before, $after ), d5b_wt_summary( 'Updated', $nt ) );
		if ( $hid < 1 ) { $after['_history_failed'] = true; }
		return $after;
	}

	// ---- taxonomy-assign

	/** The term ids the object holds in $tax, sorted. */
	function d5b_wt_object_term_ids( int $id, string $tax ): array {
		$ids = wp_get_object_terms( $id, $tax, array( 'fields' => 'ids' ) );
		$ids = is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array();
		sort( $ids );
		return $ids;
	}

	/**
	 * The terms input: a list of up to 100 term ids (whole numbers) or names (text); a number is always an id, a text always a
	 * name or slug. An empty list only to replace (clears the taxonomy on the post). @return array|WP_Error
	 */
	function d5b_wt_terms_input( $v, string $mode ) {
		if ( ! is_array( $v ) || ! d5b_rc_is_list( $v ) || count( $v ) > 100 ) { return new WP_Error( 'invalid_input', 'terms must be a list of at most 100 term ids or names.' ); }
		if ( array() === $v && 'replace' !== $mode ) { return new WP_Error( 'invalid_input', 'terms is required (an empty list only with mode replace, to take every term of this taxonomy off the post).' ); }
		foreach ( $v as $one ) {
			$ok = ( is_int( $one ) && $one > 0 ) || ( is_string( $one ) && '' !== trim( $one ) && strlen( $one ) <= 200 && 1 === preg_match( '//u', $one ) );
			if ( ! $ok ) { return new WP_Error( 'invalid_input', 'terms must hold positive whole numbers (term ids) or names.' ); }
		}
		return $v;
	}

	/** @return array|WP_Error */
	function d5b_wt_assign( array $in ) {
		$terms = $in['terms'] ?? null;
		unset( $in['terms'] );
		$a = d5b_rc_args( $in, array( 'post_id' => 'id', 'taxonomy' => 'slug', 'mode' => 'enum:add|replace|remove' ), array( 'post_id', 'taxonomy' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$mode  = $a['mode'] ?? 'add';
		$terms = d5b_wt_terms_input( $terms, $mode );
		if ( is_wp_error( $terms ) ) { return $terms; }
		$t = d5b_wt_tax( $a['taxonomy'] );
		if ( is_wp_error( $t ) ) { return $t; }
		$tax = (string) $t->name;
		// Strictest switch: the post's own category is checked here (d5b_rc_post), the Taxonomy category by the gate.
		$p = d5b_wc_post( $a['post_id'], null, 'edit_post' );
		if ( null === $p ) { return d5b_wc_deny( $a['post_id'], null ); }
		if ( ! d5b_wt_has_cap( $t, 'assign_terms' ) ) { return new WP_Error( 'not_allowed', 'You may not assign terms of the taxonomy "' . $tax . '".' ); }
		if ( ! is_object_in_taxonomy( (string) $p->post_type, $tax ) ) { return new WP_Error( 'invalid_input', 'The taxonomy "' . $tax . '" is not used for ' . (string) $p->post_type . ' items.' ); }
		$ids = array(); $missing = array();
		foreach ( $terms as $one ) {
			$term = is_int( $one ) ? d5b_wt_term( $one, $tax ) : null;
			if ( is_string( $one ) ) {
				$term = get_term_by( 'name', $one, $tax );
				if ( ! is_object( $term ) ) { $term = get_term_by( 'slug', $one, $tax ); }
				$term = is_object( $term ) && ! is_wp_error( $term ) ? $term : null;
			}
			if ( null === $term ) { $missing[] = is_int( $one ) ? (string) $one : d5b_rc_cut( $one, 60 ); continue; }
			$ids[] = (int) $term->term_id;
		}
		if ( array() !== $missing ) {
			return new WP_Error( 'invalid_input', 'These terms do not exist in "' . $tax . '": ' . implode( ', ', array_slice( $missing, 0, 20 ) ) . '. Create them first with divi5-builder/taxonomy-create.', array( 'missing' => array_slice( $missing, 0, 20 ) ) );
		}
		$ids    = array_values( array_unique( $ids ) );
		$before = d5b_wt_object_term_ids( (int) $p->ID, $tax );
		if ( 'remove' === $mode ) {
			$r = wp_remove_object_terms( (int) $p->ID, $ids, $tax );
		} else {
			$r = wp_set_object_terms( (int) $p->ID, $ids, $tax, 'add' === $mode );
		}
		if ( is_wp_error( $r ) ) { return d5b_wt_core_error( $r ); }
		$after = d5b_wt_object_term_ids( (int) $p->ID, $tax );
		$want  = 'remove' === $mode ? array_values( array_diff( $before, $ids ) ) : ( 'add' === $mode ? array_values( array_unique( array_merge( $before, $ids ) ) ) : $ids );
		sort( $want );
		if ( $want !== $after ) {
			// Not what was asked; when the terms changed all the same, the change is recorded and the answer names the row.
			$data = array( 'terms' => $after );
			if ( $after !== $before ) {
				$hid = d5b_wc_history_id( 'divi5-builder/taxonomy-assign', $p, 'assign', array( 'taxonomy' => $tax, 'term_ids' => $before ), array( 'taxonomy' => $tax, 'term_ids' => $after, 'mode' => $mode, 'asked' => $want ), 0, d5b_wc_summary( 'Partly set ' . $tax . ' terms of', (string) $p->post_type, $p ) );
				$data += $hid > 0 ? array( 'history_id' => $hid ) : array( '_history_failed' => true );
			}
			return new WP_Error( 'write_failed', 'WordPress did not set the terms as asked (a plugin may have changed them).' . ( $after !== $before ? ' The terms did change: data.terms holds them now' . ( isset( $data['history_id'] ) ? ', history row ' . $data['history_id'] . ' the previous ones.' : '; the history row could not be written.' ) : '' ), $data );
		}
		$stored = d5b_wc_history( 'divi5-builder/taxonomy-assign', $p, 'assign', array( 'taxonomy' => $tax, 'term_ids' => $before ), array( 'taxonomy' => $tax, 'term_ids' => $after, 'mode' => $mode ), 0, d5b_wc_summary( 'Set ' . $tax . ' terms of', (string) $p->post_type, $p ) );
		$rows = array();
		foreach ( $after as $id ) { $term = d5b_wt_term( $id, $tax ); if ( null !== $term ) { $rows[] = d5b_rc_term_detail( $term ); } }
		$out = array( 'post_id' => (int) $p->ID, 'taxonomy' => $tax, 'mode' => $mode, 'terms' => $rows, 'previous_term_ids' => $before );
		if ( ! $stored ) { $out['_history_failed'] = true; }
		return $out;
	}

	// ---- the two-step term delete

	/** Every child term (id, name, slug). */
	function d5b_wt_children( $term ): array {
		if ( ! is_taxonomy_hierarchical( (string) $term->taxonomy ) ) { return array(); }
		$rows = get_terms( array( 'taxonomy' => (string) $term->taxonomy, 'parent' => (int) $term->term_id, 'hide_empty' => false ) );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $c ) { $out[] = array( 'id' => (int) $c->term_id, 'name' => (string) $c->name, 'slug' => (string) $c->slug ); }
		return $out;
	}

	/** Every object (post, page, item) id the term is assigned to, ascending. */
	function d5b_wt_objects( $term ): array {
		$ids = get_objects_in_term( (int) $term->term_id, (string) $term->taxonomy );
		$ids = is_array( $ids ) ? array_values( array_unique( array_map( 'intval', $ids ) ) ) : array();
		sort( $ids );
		return $ids;
	}

	/** The term the delete abilities may touch. @return object|WP_Error */
	function d5b_wt_delete_target( array $a ) {
		$t = d5b_wt_tax( $a['taxonomy'] );
		if ( is_wp_error( $t ) ) { return $t; }
		if ( ! d5b_wt_has_cap( $t, 'manage_terms' ) ) { return new WP_Error( 'not_allowed', 'You may not manage the terms of the taxonomy "' . (string) $t->name . '".' ); }
		$term = d5b_wt_term( $a['id'], (string) $t->name );
		if ( null === $term ) { return new WP_Error( 'not_found', 'No term with that id in that taxonomy.' ); }
		if ( d5b_wt_is_default( $term ) ) { return d5b_wt_default_error(); }
		// Everything the delete also takes must be kept in full by the history: no secret-named key in the term's own meta
		// nor in the menu items WordPress deletes with it.
		foreach ( d5b_wt_term_meta_raw( (int) $term->term_id ) as $k => $rows ) {
			if ( d5b_is_secret_key( (string) $k ) || ! d5b_wc_secret_free( $rows ) ) { return d5b_wc_secret_error( 'term' ); }
		}
		foreach ( d5b_wt_menu_item_ids( $term ) as $mi ) {
			if ( ! d5b_wc_post_secret_free( $mi ) ) { return d5b_wc_secret_error( 'term has a menu item that' ); }
		}
		return $term;
	}

	/** The term's own meta, every row: key => list of raw values. */
	function d5b_wt_term_meta_raw( int $id ): array {
		$all = get_term_meta( $id );
		$out = array();
		foreach ( is_array( $all ) ? array_keys( $all ) : array() as $k ) { $out[ (string) $k ] = array_values( (array) get_term_meta( $id, (string) $k, false ) ); }
		ksort( $out );
		return $out;
	}

	/** The term's meta as the history keeps it: key => rows (d5b_wc_meta_value). */
	function d5b_wt_term_meta( int $id ): array {
		return array_map( function ( $rows ) { return array_map( 'd5b_wc_meta_value', $rows ); }, d5b_wt_term_meta_raw( $id ) );
	}

	/** The nav menu items linking to the term: WordPress deletes them for good with it (_wp_delete_tax_menu_item). */
	function d5b_wt_menu_item_ids( $term ): array {
		$ids = function_exists( 'wp_get_associated_nav_menu_items' ) ? wp_get_associated_nav_menu_items( (int) $term->term_id, 'taxonomy', (string) $term->taxonomy ) : array();
		$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		sort( $ids );
		return $ids;
	}

	function d5b_wt_menu_items( $term ): array {
		$out = array();
		foreach ( d5b_wt_menu_item_ids( $term ) as $id ) { $p = get_post( $id ); if ( is_object( $p ) ) { $out[] = d5b_wc_nav_item_data( $p, true ); } }
		return $out;
	}

	/** The term WordPress gives an item left with no term of the taxonomy (the default category, a default term), or null. */
	function d5b_wt_default_term( string $tax ) {
		$id = 0;
		if ( 'category' === $tax ) { $id = (int) get_option( 'default_category' ); }
		$obj = get_taxonomy( $tax );
		if ( is_object( $obj ) && ! empty( $obj->default_term ) ) { $id = (int) get_option( 'default_term_' . $tax ); }
		return $id > 0 ? d5b_wt_term( $id, $tax ) : null;
	}

	/** Every item the term is on, with every term id it holds in this taxonomy (so a rebuild can tell a default it already had). */
	function d5b_wt_object_rows( $term ): array {
		$rows = array();
		foreach ( d5b_wt_objects( $term ) as $o ) { $rows[] = array( 'id' => $o, 'term_ids' => d5b_wt_object_term_ids( $o, (string) $term->taxonomy ) ); }
		return $rows;
	}

	/** The items that hold no other term of the taxonomy: they get the default term, when there is one. */
	function d5b_wt_losing_last( $term, array $rows ): array {
		$out = array();
		foreach ( $rows as $r ) { if ( array( (int) $term->term_id ) === $r['term_ids'] ) { $out[] = $r['id']; } }
		return $out;
	}

	/** The confirmation fingerprint: the term (name, slug, parent, count), its meta and the menu items that go with it. */
	function d5b_wt_fp( $term ): string {
		$extra = array( d5b_wt_term_meta( (int) $term->term_id ), d5b_wt_menu_items( $term ) );
		return d5b_fp_term( $term ) . '|' . sha1( (string) json_encode( $extra, JSON_PARTIAL_OUTPUT_ON_ERROR ) );
	}

	/** @return array|WP_Error */
	function d5b_wt_delete_preview( array $in ) {
		$a = d5b_rc_args( $in, array( 'taxonomy' => 'slug', 'id' => 'id' ), array( 'taxonomy', 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$term = d5b_wt_delete_target( $a );
		if ( is_wp_error( $term ) ) { return $term; }
		$children = d5b_wt_children( $term );
		$objects  = d5b_wt_objects( $term );
		$losing   = d5b_wt_losing_last( $term, d5b_wt_object_rows( $term ) );
		$default  = d5b_wt_default_term( (string) $term->taxonomy );
		$code = d5b_confirm_issue( 'divi5-builder/taxonomy-delete', 'term', (int) $term->term_id, d5b_wt_fp( $term ) );
		if ( is_wp_error( $code ) ) { return $code; }
		return array(
			'term' => d5b_rc_term_detail( $term ), 'count' => (int) $term->count,
			'children' => array_slice( $children, 0, 100 ), 'children_total' => count( $children ),
			'posts' => array_slice( $objects, 0, 100 ), 'posts_total' => count( $objects ), 'posts_capped' => count( $objects ) > 100,
			'objects_losing_last_term' => array_slice( $losing, 0, 100 ), 'objects_losing_last_term_total' => count( $losing ),
			'default_term' => null === $default ? null : array( 'id' => (int) $default->term_id, 'name' => (string) $default->name ),
			'menu_items' => d5b_wt_menu_items( $term ), 'term_meta' => d5b_wt_term_meta( (int) $term->term_id ),
			'note' => 'A term has no trash: the delete is permanent. WordPress moves its child terms up to its own parent, takes it off every item it was assigned to (the items in objects_losing_last_term then hold no term of this taxonomy' . ( null === $default ? '' : ' and get the default term "' . (string) $default->name . '"' ) . '), deletes the term\'s own meta (term_meta) and deletes for good every menu item that links to it (menu_items). The change history keeps all of it, so it can be rebuilt by hand.',
		) + $code;
	}

	/** @return array|WP_Error */
	function d5b_wt_delete( array $in ) {
		$a = d5b_rc_args( $in, array( 'taxonomy' => 'slug', 'id' => 'id', 'confirmation' => 'str' ), array( 'taxonomy', 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$term = d5b_wt_delete_target( $a );
		if ( is_wp_error( $term ) ) { return $term; }
		$id  = (int) $term->term_id;
		$tax = (string) $term->taxonomy;
		$ok  = d5b_confirm_check( 'divi5-builder/taxonomy-delete', 'term', $id, d5b_wt_fp( $term ), (string) ( $a['confirmation'] ?? '' ) );
		if ( true !== $ok ) { return $ok; }
		// The history row comes FIRST: once the term is gone it is all there is to rebuild it, its assignments (with the term
		// sets each item held, so a default term WordPress adds can be told apart), its meta and its menu items from.
		$objects = d5b_wt_objects( $term );
		$rows    = d5b_wt_object_rows( $term );
		$default = d5b_wt_default_term( $tax );
		$before  = d5b_rc_term_detail( $term ) + array(
			'children' => d5b_wt_children( $term ), 'object_ids' => $objects, 'objects' => $rows,
			'objects_losing_last_term' => d5b_wt_losing_last( $term, $rows ), 'default_term_id' => null === $default ? 0 : (int) $default->term_id,
			'term_meta' => d5b_wt_term_meta( $id ), 'menu_items' => d5b_wt_menu_items( $term ),
		);
		$hid = d5b_wt_history( 'taxonomy-delete', 'term', $id, 'delete', $before, array( 'deleted' => true ), d5b_wt_summary( 'Permanently deleted', $term ) );
		if ( $hid < 1 ) { return d5b_wt_history_unavailable(); }
		$ok = d5b_wc_delete_record_ok( $hid );
		if ( true !== $ok ) { return $ok; }
		$r = wp_delete_term( $id, $tax );
		if ( true !== $r || null !== d5b_wt_term( $id, $tax ) ) {
			$err = is_wp_error( $r ) ? 'wp_error:' . $r->get_error_code() : ( 0 === $r ? 'default_term' : ( true === $r ? 'still_present' : 'wp_delete_term_failed' ) );
			$fid = d5b_wt_history( 'taxonomy-delete', 'term', $id, 'delete_failed', null, array( 'deleted' => false, 'error' => $err, 'history_id' => $hid ), d5b_wt_summary( 'Failed to delete', $term ) );
			return new WP_Error( 'write_failed', 'WordPress did not delete the term (a plugin may have stopped it). History row ' . $hid . ' holds its full state' . ( $fid > 0 ? '; row ' . $fid . ' records the failure.' : '.' ), array( 'history_id' => $hid ) );
		}
		return array( 'deleted' => true, 'id' => $id, 'taxonomy' => $tax, 'children_moved_to' => (int) $term->parent, 'items_unassigned' => count( $objects ), 'history_id' => $hid );
	}

	// ---- custom fields

	/** True when the value is a scalar (not null), or an array of such values and arrays; no object at any depth. */
	function d5b_wt_plain_value( $v, int $depth = 0 ): bool {
		if ( is_object( $v ) || null === $v ) { return false; }
		if ( is_scalar( $v ) ) { return true; }
		if ( ! is_array( $v ) || $depth >= 8 ) { return false; }
		foreach ( $v as $x ) { if ( ! d5b_wt_plain_value( $x, $depth + 1 ) ) { return false; } }
		return true;
	}

	/** Refuses a key the abilities never write: protected (_) and secret-named. @return true|WP_Error */
	function d5b_wt_key_allowed( string $key ) {
		$ok = d5b_wc_meta_key_ok( $key );
		if ( true !== $ok ) { return $ok; }
		if ( is_protected_meta( $key, 'post' ) ) { return new WP_Error( 'not_allowed', 'This field is protected (its name starts with an underscore): it belongs to WordPress, Divi or a plugin and is not changed here.' ); }
		if ( d5b_is_secret_key( $key ) ) { return new WP_Error( 'not_allowed', 'This field name looks like it holds a secret (a key, token or password), so it is not changed here. Edit it in wp-admin.' ); }
		return true;
	}

	/**
	 * The post whose field may be changed: editable (its category on) and $meta_cap (edit_post_meta, delete_post_meta) for
	 * the key. @return object|WP_Error
	 */
	function d5b_wt_meta_post( $id, string $key, string $meta_cap ) {
		$ok = d5b_wt_key_allowed( $key );
		if ( true !== $ok ) { return $ok; }
		$p = d5b_wc_post( $id, null, 'edit_post' );
		if ( null === $p ) { return d5b_wc_deny( $id, null ); }
		if ( ! current_user_can( $meta_cap, (int) $p->ID, $key ) ) { return new WP_Error( 'not_allowed', 'You may not change this custom field.' ); }
		$ok = d5b_wc_meta_key_case_ok( (int) $p->ID, $key );
		if ( true !== $ok ) { return $ok; }
		return $p;
	}

	/** Every row of the key, as the history keeps it (d5b_wc_meta_value). */
	function d5b_wt_meta_rows( int $id, string $key ): array {
		return array_map( 'd5b_wc_meta_value', array_values( (array) get_post_meta( $id, $key, false ) ) );
	}

	function d5b_wt_meta_summary( string $verb, string $key, $p ): string {
		return d5b_wc_summary( $verb . " custom field '" . d5b_rc_cut( $key, 60 ) . "' on", (string) $p->post_type, $p );
	}

	/** @return array|WP_Error */
	function d5b_wt_meta_set( array $in ) {
		$has   = array_key_exists( 'value', $in );
		$value = $in['value'] ?? null;
		unset( $in['value'] );
		$a = d5b_rc_args( $in, array( 'post_id' => 'id', 'key' => 'key' ), array( 'post_id', 'key' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		if ( ! $has || null === $value ) { return new WP_Error( 'invalid_input', 'value is required (use divi5-builder/meta-delete-preview to remove a field).' ); }
		if ( ! d5b_wt_plain_value( $value ) || ! d5b_wc_json_safe( $value ) ) {
			return new WP_Error( 'invalid_input', 'value must be text, a number, true/false, or a list or object of those (at most 8 levels deep, UTF-8). Other objects are refused.' );
		}
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
		if ( ! is_string( $json ) || strlen( $json ) > 1048576 ) { return new WP_Error( 'invalid_input', 'value is too large: at most 1 MB.' ); }
		$key = $a['key'];
		$p   = d5b_wt_meta_post( $a['post_id'], $key, 'edit_post_meta' );
		if ( is_wp_error( $p ) ) { return $p; }
		$id   = (int) $p->ID;
		$rows = (array) get_post_meta( $id, $key, false );
		if ( ! d5b_wc_secret_free( array_values( $rows ) ) ) { return d5b_wc_secret_error( 'field' ); }
		$stored = json_encode( d5b_rc_meta_clean( array_values( $rows ) ) );
		$ok = d5b_wc_check_masked( $json, is_string( $stored ) ? $stored : '' );
		if ( true !== $ok ) { return $ok; }
		$before = d5b_wt_meta_rows( $id, $key );
		// Every row of the key ends as this one value (a key with several rows is replaced as a whole; the history keeps them all).
		d5b_wc_apply_meta( $id, array( $key => $value ) );
		$now = (array) get_post_meta( $id, $key, false );
		if ( 1 !== count( $now ) ) {
			// Not what was asked, but when the stored rows changed all the same the change is recorded (as for comments): the
			// history row keeps the previous rows and what is stored now, and the answer names it.
			$read = d5b_wt_meta_rows( $id, $key );
			$data = null;
			if ( $read !== $before ) {
				$hid  = d5b_wt_history( 'meta-set', 'post_meta', $id, array() === $before ? 'create' : 'update',
					array( 'field' => $key, 'rows' => $before ), array( 'field' => $key, 'value' => d5b_wc_meta_value( $value ), 'readback' => $read ), d5b_wt_meta_summary( 'Partly set', $key, $p ) );
				$data = $hid > 0 ? array( 'history_id' => $hid ) : array( '_history_failed' => true );
			}
			return new WP_Error( 'write_failed', 'WordPress did not save the custom field as asked (a plugin may have stopped or changed it).' . ( null === $data ? '' : ' The field did change: ' . ( isset( $data['history_id'] ) ? 'history row ' . $data['history_id'] . ' holds the previous rows.' : 'the history row could not be written.' ) ), $data );
		}
		$hid = d5b_wt_history( 'meta-set', 'post_meta', $id, array() === $before ? 'create' : 'update',
			array( 'field' => $key, 'rows' => $before ), array( 'field' => $key, 'value' => d5b_wc_meta_value( $value ) ), d5b_wt_meta_summary( 'Set', $key, $p ) );
		$out = array( 'post_id' => $id, 'field' => $key, 'value' => d5b_rc_meta_clean( $now[0] ), 'replaced_rows' => count( $before ) );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	/** The rows as a preview shows them: each value as the reads show it, one over 64 KB as its size only. */
	function d5b_wt_meta_shown( array $rows ): array {
		$out = array();
		foreach ( array_values( $rows ) as $v ) {
			$v = d5b_rc_meta_clean( $v );
			$size = d5b_rc_json_bytes( $v );
			$out[] = $size > 65536 ? array( '_truncated' => true, 'bytes' => $size ) : $v;
			if ( count( $out ) >= 100 ) { break; }
		}
		return $out;
	}

	/** @return array|WP_Error */
	function d5b_wt_meta_delete_preview( array $in ) {
		$a = d5b_rc_args( $in, array( 'post_id' => 'id', 'key' => 'key' ), array( 'post_id', 'key' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$p = d5b_wt_meta_post( $a['post_id'], $a['key'], 'delete_post_meta' );
		if ( is_wp_error( $p ) ) { return $p; }
		$rows = (array) get_post_meta( (int) $p->ID, $a['key'], false );
		if ( array() === $rows ) { return new WP_Error( 'not_found', 'This item has no custom field with that name.' ); }
		if ( ! d5b_wc_secret_free( array_values( $rows ) ) ) { return d5b_wc_secret_error( 'field' ); }
		$code = d5b_confirm_issue( 'divi5-builder/meta-delete', 'post_meta', (int) $p->ID . '|' . $a['key'], d5b_fp_meta( (int) $p->ID, $a['key'] ) );
		if ( is_wp_error( $code ) ) { return $code; }
		return array(
			'post_id' => (int) $p->ID, 'field' => $a['key'], 'count' => count( $rows ), 'rows' => d5b_wt_meta_shown( $rows ),
			'note' => 'A custom field has no trash: the delete removes every row of this field for good. The change history keeps every value, so it can be set again by hand.',
		) + $code;
	}

	/** @return array|WP_Error */
	function d5b_wt_meta_delete( array $in ) {
		$a = d5b_rc_args( $in, array( 'post_id' => 'id', 'key' => 'key', 'confirmation' => 'str' ), array( 'post_id', 'key' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$key = $a['key'];
		$p   = d5b_wt_meta_post( $a['post_id'], $key, 'delete_post_meta' );
		if ( is_wp_error( $p ) ) { return $p; }
		$id   = (int) $p->ID;
		$rows = d5b_wt_meta_rows( $id, $key );
		if ( array() === $rows ) { return new WP_Error( 'not_found', 'This item has no custom field with that name.' ); }
		if ( ! d5b_wc_secret_free( array_values( (array) get_post_meta( $id, $key, false ) ) ) ) { return d5b_wc_secret_error( 'field' ); }
		$ok = d5b_confirm_check( 'divi5-builder/meta-delete', 'post_meta', $id . '|' . $key, d5b_fp_meta( $id, $key ), (string) ( $a['confirmation'] ?? '' ) );
		if ( true !== $ok ) { return $ok; }
		// The history row comes FIRST, with every row of the key.
		$hid = d5b_wt_history( 'meta-delete', 'post_meta', $id, 'delete', array( 'field' => $key, 'rows' => $rows ), array( 'field' => $key, 'deleted' => true ), d5b_wt_meta_summary( 'Permanently deleted', $key, $p ) );
		if ( $hid < 1 ) { return d5b_wt_history_unavailable(); }
		$ok = d5b_wc_delete_record_ok( $hid );
		if ( true !== $ok ) { return $ok; }
		// core's delete_metadata unslashes the key.
		$r = delete_post_meta( $id, wp_slash( $key ) );
		if ( ! $r || array() !== (array) get_post_meta( $id, $key, false ) ) {
			$fid = d5b_wt_history( 'meta-delete', 'post_meta', $id, 'delete_failed', null, array( 'field' => $key, 'deleted' => false, 'error' => $r ? 'still_present' : 'delete_post_meta_failed', 'history_id' => $hid ), d5b_wt_meta_summary( 'Failed to delete', $key, $p ) );
			return new WP_Error( 'write_failed', 'WordPress did not delete the custom field (a plugin may have stopped it). History row ' . $hid . ' holds every value' . ( $fid > 0 ? '; row ' . $fid . ' records the failure.' : '.' ), array( 'history_id' => $hid ) );
		}
		return array( 'deleted' => true, 'post_id' => $id, 'field' => $key, 'rows_deleted' => count( $rows ), 'history_id' => $hid );
	}

	// ---- permissions (the handler checks again)

	/** Read, and the taxonomy's $which cap. A taxonomy the abilities may not use is refused with its own message. */
	function d5b_wt_can_tax( array $in, string $which ) {
		if ( ! current_user_can( 'read' ) ) { return false; }
		$t = d5b_wt_tax( $in['taxonomy'] ?? null );
		if ( is_wp_error( $t ) ) { return $t; }
		return d5b_wt_has_cap( $t, $which );
	}
	function d5b_wt_can_manage( array $in ) { return d5b_wt_can_tax( $in, 'manage_terms' ); }
	function d5b_wt_can_assign( array $in ) {
		$ok = d5b_wt_can_tax( $in, 'assign_terms' );
		return true === $ok ? null !== d5b_wc_post( $in['post_id'] ?? 0, null, 'edit_post' ) : $ok;
	}
	function d5b_wt_can_meta( array $in, string $cap ): bool {
		if ( ! current_user_can( 'read' ) || ! is_string( $in['key'] ?? null ) ) { return false; }
		$p = d5b_wc_post( $in['post_id'] ?? 0, null, 'edit_post' );
		return null !== $p && current_user_can( $cap, (int) $p->ID, $in['key'] );
	}
	function d5b_wt_can_meta_set( array $in ): bool { return d5b_wt_can_meta( $in, 'edit_post_meta' ); }
	function d5b_wt_can_meta_delete( array $in ): bool { return d5b_wt_can_meta( $in, 'delete_post_meta' ); }

	function d5b_wt_h_create( array $in ) { return d5b_wt_create( $in ); }
	function d5b_wt_h_update( array $in ) { return d5b_wt_update( $in ); }
	function d5b_wt_h_assign( array $in ) { return d5b_wt_assign( $in ); }
	function d5b_wt_h_delete_preview( array $in ) { return d5b_wt_delete_preview( $in ); }
	function d5b_wt_h_delete( array $in ) { return d5b_wt_delete( $in ); }
	function d5b_wt_h_meta_set( array $in ) { return d5b_wt_meta_set( $in ); }
	function d5b_wt_h_meta_delete_preview( array $in ) { return d5b_wt_meta_delete_preview( $in ); }
	function d5b_wt_h_meta_delete( array $in ) { return d5b_wt_meta_delete( $in ); }

	// ---- the definitions

	$d5b_wt_tax_prop = array( 'taxonomy' => array( 'type' => 'string', 'description' => 'The taxonomy name, for example category or post_tag (see taxonomy-list).' ) );
	$d5b_wt_id_prop  = array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The term id.' ) );
	$d5b_wt_fields   = array(
		'name'        => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'The name shown to visitors, at most 200 characters.' ),
		'slug'        => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'The URL name (cleaned as WordPress cleans slugs). Left out on create: made from the name.' ),
		'parent'      => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'The parent term id, 0 for none. Only in a hierarchical taxonomy (categories); never the term itself or one below it.' ),
		'description' => array( 'type' => 'string', 'maxLength' => 10000, 'description' => 'The description, at most 10000 characters.' ),
	);
	$d5b_wt_confirm  = array( 'confirmation' => array( 'type' => 'string', 'description' => 'The confirmation code from the preview, after the user said OK.' ) );
	$d5b_wt_meta_ids = array(
		'post_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the post, page or other item.' ),
		'key'     => array( 'type' => 'string', 'maxLength' => 255, 'description' => 'The custom field name: plain ASCII letters, digits, _ - . : only (anything else is refused with invalid_input). Names starting with an underscore and secret-looking names are refused.' ),
	);

	d5b_wc_def( 'taxonomy-create', 'taxonomy', 'Create a term',
		'Creates one term (a category, tag or other public taxonomy term) and returns it. Needs the taxonomy\'s manage-terms capability. Refuses internal and non-public taxonomies, an empty name, a name that already exists at the same level (invalid_input with data.existing_id), a parent in a taxonomy without hierarchy, and text holding [redacted] (masked_read). The change history records it.',
		$d5b_wt_tax_prop + $d5b_wt_fields, array( 'taxonomy', 'name' ), 'd5b_wt_h_create', 'd5b_wt_can_manage', array() );

	d5b_wc_def( 'taxonomy-update', 'taxonomy', 'Update a term',
		'Changes the name, slug, parent or description of one term (only the fields given) and returns it. Needs the taxonomy\'s manage-terms capability. Refuses a slug another term uses, a parent that would make a loop, and text holding [redacted] (masked_read). The change history keeps the previous values.',
		$d5b_wt_tax_prop + $d5b_wt_id_prop + $d5b_wt_fields, array( 'taxonomy', 'id' ), 'd5b_wt_h_update', 'd5b_wt_can_manage', array( 'idempotent' => true ) );

	d5b_wc_def( 'taxonomy-assign', 'taxonomy', 'Assign terms to an item',
		'Sets the terms of one taxonomy on one post, page or item: mode add (the default) adds them, replace makes them the only ones (an empty list takes them all off), remove takes them off. terms is a list of term ids (numbers) or existing names or slugs (text); a term that does not exist is refused (create it first with taxonomy-create). Returns the item\'s terms in that taxonomy now and the previous term ids. Needs edit rights on the item and the taxonomy\'s assign-terms capability; the item\'s own category (Posts, Pages ...) must be switched on as well as Taxonomies. The change history keeps the previous term ids.',
		array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the post, page or item.' ) ) + $d5b_wt_tax_prop + array(
			'terms' => array( 'type' => 'array', 'maxItems' => 100, 'items' => array( 'type' => array( 'integer', 'string' ) ), 'description' => 'Term ids (numbers) or names (text), at most 100.' ),
			'mode'  => array( 'type' => 'string', 'enum' => array( 'add', 'replace', 'remove' ), 'default' => 'add', 'description' => 'add (default), replace or remove.' ),
		), array( 'post_id', 'taxonomy', 'terms' ), 'd5b_wt_h_assign', 'd5b_wt_can_assign', array() );

	d5b_wc_def( 'taxonomy-delete-preview', 'taxonomy', 'Preview a term delete',
		'Step one of a permanent term delete (a term has no trash). Changes nothing. Returns the term, its count, its child terms (WordPress moves them up to the term\'s own parent; at most 100 shown, children_total counts them all), the first 100 ids of the items it is assigned to (posts_total counts them all), and a confirmation code valid for 10 minutes for this user and this term as it is now. Show the preview to the user and wait for their explicit OK before calling divi5-builder/taxonomy-delete with the code. Also returns what WordPress takes with the term: menu_items (every menu item linking to it, deleted for good), term_meta (every row of its own meta), objects_losing_last_term (items left with no term of this taxonomy) and default_term (the term those items then get, or null). Refuses the default category (and a taxonomy\'s default term) with not_allowed: WordPress never deletes it; and a term whose meta, or a linked menu item\'s custom fields, hold a secret-named key (not_allowed: the history could not keep it; delete it in wp-admin).',
		$d5b_wt_tax_prop + $d5b_wt_id_prop, array( 'taxonomy', 'id' ), 'd5b_wt_h_delete_preview', 'd5b_wt_can_manage', array( 'readonly' => true ) );

	d5b_wc_def( 'taxonomy-delete', 'taxonomy', 'Delete a term for good',
		'Step two of a permanent term delete: deletes the term and returns deleted true with the history row id. Only with the confirmation code from taxonomy-delete-preview, by the same user, for the same term, unchanged since the preview (name, slug, parent and count), used once, within 10 minutes; anything else is refused with confirmation_invalid and data.reason, and nothing is deleted. The change history row is written BEFORE the delete and keeps the term, its children, every item it was assigned to with the term ids each held in this taxonomy, its meta and its menu items in full; when it cannot be written the delete is refused (history_unavailable). Refuses the default category.',
		$d5b_wt_tax_prop + $d5b_wt_id_prop + $d5b_wt_confirm, array( 'taxonomy', 'id', 'confirmation' ), 'd5b_wt_h_delete', 'd5b_wt_can_manage', array( 'destructive' => true ) );

	d5b_wc_def( 'meta-set', 'meta', 'Set a custom field',
		'Sets one custom field (post meta) of one post, page or item to value and returns it. value is text, a number, true/false, or a list or object of those (8 levels at most, 1 MB at most); other objects are refused. When the field holds several rows (WordPress allows a name more than once), they are ALL replaced by this one value, and replaced_rows says how many there were. Needs edit rights on the item and on the field; the item\'s own category must be switched on as well as Custom fields. Refuses protected fields (names starting with an underscore, Divi\'s among them) and secret-looking names (keys, tokens, passwords) with not_allowed, a field whose current value holds a secret-named key (not_allowed: the history could not keep it; change it in wp-admin), and a value holding [redacted] from a masked read (masked_read). The change history keeps every previous row.',
		$d5b_wt_meta_ids + array( 'value' => array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'object' ), 'description' => 'The new value.' ) ),
		array( 'post_id', 'key', 'value' ), 'd5b_wt_h_meta_set', 'd5b_wt_can_meta_set', array( 'idempotent' => true ) );

	d5b_wc_def( 'meta-delete-preview', 'meta', 'Preview a custom field delete',
		'Step one of a permanent custom field delete (a custom field has no trash). Changes nothing. Returns how many rows the field has and their values (a value over 64 KB as its size only), and a confirmation code valid for 10 minutes for this user and this field as it is now. Show the preview to the user and wait for their explicit OK before calling divi5-builder/meta-delete with the code. Refuses protected and secret-looking names, a field whose value holds a secret-named key (not_allowed; delete it in wp-admin), and a field the item does not have (not_found).',
		$d5b_wt_meta_ids, array( 'post_id', 'key' ), 'd5b_wt_h_meta_delete_preview', 'd5b_wt_can_meta_delete', array( 'readonly' => true ) );

	d5b_wc_def( 'meta-delete', 'meta', 'Delete a custom field for good',
		'Step two of a permanent custom field delete: deletes every row of the field and returns deleted true with the history row id. Only with the confirmation code from meta-delete-preview, by the same user, for the same item and field, unchanged since the preview, used once, within 10 minutes; anything else is refused with confirmation_invalid and data.reason, and nothing is deleted. The change history row is written BEFORE the delete and keeps every value; when it cannot be written the delete is refused (history_unavailable).',
		$d5b_wt_meta_ids + $d5b_wt_confirm, array( 'post_id', 'key', 'confirmation' ), 'd5b_wt_h_meta_delete', 'd5b_wt_can_meta_delete', array( 'destructive' => true ) );

	unset( $d5b_wt_tax_prop, $d5b_wt_id_prop, $d5b_wt_fields, $d5b_wt_confirm, $d5b_wt_meta_ids );

	// Raw third-party data: custom field values are shaped by other plugins, so the row rule applies there (as meta-get).
	d5b_ability_set_raw_paths( array( 'meta-set' => array( 'value' ), 'meta-delete-preview' => array( 'rows' ) ) );

	/**
	 * The audit args of the custom field abilities: the input "key" is stored as "field". The audit's key filter drops a key
	 * named key (a secret word) with its value, so the field name would otherwise never reach the audit log.
	 */
	function d5b_wt_meta_audit_args( array $args ): array {
		if ( array_key_exists( 'key', $args ) ) {
			$args = array( 'field' => $args['key'] ) + array_diff_key( $args, array( 'key' => 1 ) );
		}
		return $args;
	}
	d5b_ability_set_audit_args( array( 'meta-set' => 'd5b_wt_meta_audit_args', 'meta-delete-preview' => 'd5b_wt_meta_audit_args', 'meta-delete' => 'd5b_wt_meta_audit_args' ) );
}
