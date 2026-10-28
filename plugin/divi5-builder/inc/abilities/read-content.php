<?php
/**
 * Read abilities for content (#32, release 2.1.0, part 1): posts, pages, custom post types, media, custom fields,
 * reusable blocks and site search. Part 2 (read-structure.php) holds taxonomies, comments, menus and revisions and
 * uses the helpers defined here.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (that is how
 * register.php collects them); nothing is registered with WordPress from here.
 *
 * Rules every ability here follows:
 *  - The handler validates its own input. A direct call to the run wrapper skips core's schema check, so ids are cast,
 *    per_page is held to 1..100, status values come from a list, and an unknown input name is an error.
 *  - A list asks for 'read' and then drops every item the caller may not read (current_user_can( 'read_post', id )).
 *    A get asks for read_post on that one id, in the permission callback and again in the handler.
 *  - A refusal because of the object (missing, wrong kind, not readable) is the same answer for all three, so an ability
 *    cannot be used to find out which ids exist. The permission callback says no; a direct handler call says not_found.
 *  - The hash of a post-like object is md5 of the raw post_content, the same as wp.js --expect-hash.
 *  - A password-protected post is read only by someone who can edit it. post_password is never returned.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_rc_def' ) ) {

	/** Post types the generic abilities refuse: they have their own guarded abilities (design, "Categories" notes). */
	function d5b_rc_internal_types(): array {
		return array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'custom_css', 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'et_pb_layout', 'et_theme_builder', 'et_pb_canvas' );
	}

	/** The hash a content write needs: md5 of the raw, unfiltered post_content (never the_content output). */
	function d5b_rc_hash( $post ): string {
		return md5( (string) $post->post_content );
	}

	/** A positive id from an int or a string of digits, else 0. */
	function d5b_rc_id_of( $v ): int {
		if ( is_int( $v ) ) { return $v > 0 ? $v : 0; }
		if ( is_string( $v ) && '' !== $v && ctype_digit( $v ) ) { return absint( $v ); }
		return 0;
	}

	/**
	 * Checks and cleans an input array against $spec (key => kind). Returns the cleaned values of the keys that were
	 * given, or a WP_Error 'invalid_input'. null and '' count as not given. Unknown keys are an error.
	 * Kinds: id (positive whole number), int (zero or more), str (text up to 200 characters), strs (list of text),
	 * slug (a post type or taxonomy name), key (a meta key), mime, menu (an id, slug or name), enum:a|b|c.
	 *
	 * @return array|WP_Error
	 */
	function d5b_rc_args( $in, array $spec, array $required = array() ) {
		$in = is_array( $in ) ? $in : array();
		foreach ( array_keys( $in ) as $k ) {
			if ( ! isset( $spec[ $k ] ) ) {
				return new WP_Error( 'invalid_input', 'Unknown input "' . substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ), 0, 100 ) . '". Allowed: ' . implode( ', ', array_keys( $spec ) ) . '.' );
			}
		}
		$out = array();
		foreach ( $spec as $key => $kind ) {
			$v = $in[ $key ] ?? null;
			if ( null === $v || '' === $v || array() === $v ) {
				if ( in_array( $key, $required, true ) ) { return new WP_Error( 'invalid_input', $key . ' is required.' ); }
				continue;
			}
			$bad = new WP_Error( 'invalid_input', $key . ' is not valid.' );
			if ( 'id' === $kind ) {
				$n = d5b_rc_id_of( $v );
				if ( $n < 1 ) { return new WP_Error( 'invalid_input', $key . ' must be a positive whole number.' ); }
				$out[ $key ] = $n;
			} elseif ( 'int' === $kind ) {
				if ( ! ( is_int( $v ) && $v >= 0 ) && ! ( is_string( $v ) && ctype_digit( $v ) ) ) { return new WP_Error( 'invalid_input', $key . ' must be a whole number, zero or more.' ); }
				$out[ $key ] = absint( $v );
			} elseif ( 'str' === $kind ) {
				if ( ! is_string( $v ) || strlen( $v ) > 200 ) { return new WP_Error( 'invalid_input', $key . ' must be text of at most 200 characters.' ); }
				$s = trim( sanitize_text_field( $v ) );
				if ( '' !== $s ) { $out[ $key ] = $s; }
			} elseif ( 'key' === $kind ) {
				if ( ! is_string( $v ) || strlen( $v ) > 255 ) { return new WP_Error( 'invalid_input', $key . ' must be text of at most 255 characters.' ); }
				$out[ $key ] = $v;
			} elseif ( 'slug' === $kind ) {
				if ( ! is_string( $v ) || 1 !== preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $v ) ) { return new WP_Error( 'invalid_input', $key . ' must be a name of letters, numbers, - and _.' ); }
				$out[ $key ] = $v;
			} elseif ( 'mime' === $kind ) {
				if ( ! is_string( $v ) || 1 !== preg_match( '#^[a-z0-9.+\-]+(/[a-z0-9.+*\-]+)?$#i', $v ) ) { return new WP_Error( 'invalid_input', $key . ' must be like image or image/png.' ); }
				$out[ $key ] = strtolower( $v );
			} elseif ( 'menu' === $kind ) {
				if ( is_int( $v ) && $v > 0 ) { $out[ $key ] = (string) $v; }
				elseif ( is_string( $v ) && strlen( $v ) <= 200 && '' !== trim( $v ) ) { $out[ $key ] = trim( $v ); }
				else { return $bad; }
			} elseif ( 'strs' === $kind ) {
				if ( ! is_array( $v ) || count( $v ) > 20 ) { return new WP_Error( 'invalid_input', $key . ' must be a list of at most 20 names.' ); }
				$list = array();
				foreach ( $v as $one ) {
					if ( ! is_string( $one ) || strlen( $one ) > 100 ) { return new WP_Error( 'invalid_input', $key . ' must be a list of names.' ); }
					$list[] = $one;
				}
				$out[ $key ] = array_values( $list );
			} elseif ( 0 === strpos( $kind, 'enum:' ) ) {
				$allowed = explode( '|', substr( $kind, 5 ) );
				if ( ! is_string( $v ) || ! in_array( $v, $allowed, true ) ) { return new WP_Error( 'invalid_input', $key . ' must be one of: ' . implode( ', ', $allowed ) . '.' ); }
				$out[ $key ] = $v;
			} else {
				return $bad;
			}
		}
		if ( isset( $spec['page'] ) ) {
			$deep = d5b_rc_page_too_deep( $out );
			if ( null !== $deep ) { return $deep; }
		}
		return $out;
	}

	/** per_page held to 1..100 (default 20), page to 1 or more. @return int[] array( per_page, page ) */
	function d5b_rc_paging( array $a ): array {
		$per = isset( $a['per_page'] ) ? min( 100, max( 1, (int) $a['per_page'] ) ) : 20;
		return array( $per, min( 100000, max( 1, (int) ( $a['page'] ?? 1 ) ) ) );
	}

	/** Deep paging makes the database skip rows it still has to read: more than 10,000 items in is refused. */
	function d5b_rc_page_too_deep( array $a ): ?WP_Error {
		list( $per, $page ) = d5b_rc_paging( $a );
		if ( ( $page - 1 ) * $per <= 10000 ) { return null; }
		return new WP_Error( 'page_too_deep', 'That page starts more than 10,000 items into the list: use search or filters to narrow the list.' );
	}

	/** The category (switch) that governs a post type: post, page, attachment and wp_block have their own, the rest is cpt. */
	function d5b_rc_type_category( string $type ): string {
		$map = array( 'post' => 'posts', 'page' => 'pages', 'attachment' => 'media', 'wp_block' => 'blocks' );
		return $map[ $type ] ?? 'cpt';
	}

	/** Strictest switch wins: an object is reachable only while the category of its type is on. */
	function d5b_rc_type_on( string $type ): bool {
		return d5b_abilities_category_on( d5b_rc_type_category( $type ) );
	}

	/**
	 * Why a post cannot be used: not_allowed when the caller could read it but the switch of its type is off,
	 * else not_found (missing, unreadable or the wrong kind look the same).
	 */
	function d5b_rc_deny( $id, ?array $types = null ): WP_Error {
		$p = d5b_rc_post( $id, $types, false );
		if ( null !== $p && ! d5b_rc_type_on( (string) $p->post_type ) ) {
			return new WP_Error( 'not_allowed', 'The ability category for this kind of content is switched off.' );
		}
		return new WP_Error( 'not_found', 'No readable item with that id.' );
	}

	/** A post the caller may read, of one of $types (null = any exposed type), or null. $strict_cat false skips the switch check of its type. */
	function d5b_rc_post( $id, ?array $types = null, bool $strict_cat = true ) {
		$id = d5b_rc_id_of( $id );
		if ( $id < 1 ) { return null; }
		$post = get_post( $id );
		if ( ! is_object( $post ) || ! isset( $post->post_type ) ) { return null; }
		$type = (string) $post->post_type;
		if ( null === $types ) {
			// Only the exposed types (public or REST, not internal) plus reusable blocks: user_request and the like stay out.
			if ( ! isset( d5b_rc_exposed_types()[ $type ] ) && 'wp_block' !== $type ) { return null; }
		} elseif ( ! in_array( $type, $types, true ) ) {
			return null;
		}
		if ( 'auto-draft' === $post->post_status ) { return null; }
		if ( $strict_cat && ! d5b_rc_type_on( $type ) ) { return null; }
		if ( ! current_user_can( 'read_post', $post->ID ) ) { return null; }
		if ( '' !== (string) $post->post_password && ! current_user_can( 'edit_post', $post->ID ) ) { return null; }
		return $post;
	}

	/**
	 * The post types the generic abilities may touch: public or in REST, and not internal. With $custom_only the types that
	 * have their own category (posts, pages, media, blocks) are left out too, so switching that category off cannot be
	 * undone by asking the cpt abilities for the same items.
	 *
	 * @return array<string, object>
	 */
	function d5b_rc_exposed_types( bool $custom_only = false ): array {
		$out  = array();
		$skip = array_merge( array( 'revision', 'nav_menu_item', 'wp_block' ), $custom_only ? array( 'post', 'page', 'attachment' ) : array() );
		foreach ( get_post_types( array(), 'objects' ) as $name => $obj ) {
			if ( in_array( $name, d5b_rc_internal_types(), true ) || in_array( $name, $skip, true ) ) { continue; }
			if ( ! empty( $obj->public ) || ! empty( $obj->show_in_rest ) ) { $out[ $name ] = $obj; }
		}
		return $out;
	}

	/** @return string|WP_Error the type name when cpt abilities may use it */
	function d5b_rc_type_check( $type ) {
		if ( ! is_string( $type ) || '' === $type ) { return new WP_Error( 'invalid_input', 'type is required.' ); }
		if ( in_array( $type, d5b_rc_internal_types(), true ) ) {
			return new WP_Error( 'not_allowed', 'The post type "' . $type . '" has its own guarded abilities and is not available here.' );
		}
		if ( null === get_post_type_object( $type ) ) { return new WP_Error( 'invalid_type', 'There is no post type "' . $type . '". divi5-builder/cpt-types lists them.' ); }
		if ( ! isset( d5b_rc_exposed_types( true )[ $type ] ) ) { return new WP_Error( 'not_allowed', 'The post type "' . $type . '" is not a public custom type (posts, pages, media and blocks have their own abilities), so it is not available here.' ); }
		return $type;
	}

	function d5b_rc_summary( $p ): array {
		return array(
			'id' => (int) $p->ID, 'type' => (string) $p->post_type, 'title' => (string) $p->post_title, 'slug' => (string) $p->post_name,
			'status' => (string) $p->post_status, 'date_gmt' => (string) $p->post_date_gmt, 'modified_gmt' => (string) $p->post_modified_gmt,
			'author' => (int) $p->post_author, 'parent' => (int) $p->post_parent, 'url' => (string) get_permalink( $p->ID ),
		);
	}

	/** The summary plus the raw content and its hash. Never post_password. */
	function d5b_rc_detail( $p ): array {
		return d5b_rc_summary( $p ) + array( 'content' => (string) $p->post_content, 'excerpt' => (string) $p->post_excerpt, 'menu_order' => (int) $p->menu_order, 'hash' => d5b_rc_hash( $p ) );
	}

	function d5b_rc_cut( string $s, int $n ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n ) : substr( $s, 0, $n );
	}

	function d5b_rc_term_rows( $terms ): array {
		$out = array();
		if ( is_array( $terms ) ) { foreach ( $terms as $t ) { $out[] = array( 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug ); } }
		return $out;
	}

	function d5b_rc_type_cap( string $type, string $which ): string {
		$o = get_post_type_object( $type );
		return (string) ( $o->cap->$which ?? $which );
	}

	/** True when the caller holds the capability $which (edit_others_posts, read_private_posts) for every one of the types. */
	function d5b_rc_can_all( array $types, string $which ): bool {
		foreach ( $types as $t ) { if ( ! current_user_can( d5b_rc_type_cap( (string) $t, $which ) ) ) { return false; } }
		return true;
	}

	/**
	 * The status part of a list query, built so the database returns only rows the caller may read (the count must not
	 * count hidden rows). Core's perm=readable narrows only an explicit private status, and 'any' skips it, so:
	 * 'any' is never passed on (an explicit list instead); drafts, pending and future posts of other people need
	 * edit_others_posts, otherwise the query is limited to the caller's own; password-protected posts need edit_others_posts.
	 */
	function d5b_rc_scope( array $types, string $status ): array {
		$others = d5b_rc_can_all( $types, 'edit_others_posts' );
		if ( 'any' === $status ) { $list = $others ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : array( 'publish', 'private' ); }
		else { $list = array( $status ); }
		$q = array( 'post_status' => $list );
		if ( ! $others ) {
			$q['has_password'] = false;
			if ( array_intersect( $list, array( 'draft', 'pending', 'future' ) ) ) { $q['author'] = get_current_user_id(); }
		}
		return $q;
	}

	/** Adds an author filter the caller asked for. Returns false when it contradicts the author limit (nothing can match). */
	function d5b_rc_with_author( array &$q, ?int $author ): bool {
		if ( null === $author ) { return true; }
		if ( isset( $q['author'] ) && (int) $q['author'] !== $author ) { return false; }
		$q['author'] = $author;
		return true;
	}

	function d5b_rc_empty_page( array $a ): array {
		list( $per, $page ) = d5b_rc_paging( $a );
		return array( 'items' => array(), 'total' => 0, 'page' => $page, 'per_page' => $per, 'total_pages' => 1 );
	}

	/**
	 * One page of posts of the given types: the query arguments in $query, only the items the caller may read.
	 * The total is left out when the PHP check had to drop anything (or $totals is false), so it can never count hidden rows.
	 * @return array|WP_Error
	 */
	function d5b_rc_list( array $query, array $types, array $a, ?callable $format = null, bool $totals = true ) {
		list( $per, $page ) = d5b_rc_paging( $a );
		$q = new WP_Query( array_merge( $query, array( 'post_type' => $types, 'posts_per_page' => $per, 'paged' => $page, 'perm' => 'readable', 'ignore_sticky_posts' => true, 'orderby' => 'date', 'order' => 'DESC' ) ) );
		$items = array(); $dropped = false;
		foreach ( (array) $q->posts as $p ) {
			if ( ! is_object( $p ) || null === d5b_rc_post( $p->ID, $types ) ) { $dropped = true; continue; }
			$items[] = $format ? call_user_func( $format, $p ) : d5b_rc_summary( $p );
		}
		$out = array( 'items' => $items, 'page' => $page, 'per_page' => $per );
		if ( $totals && ! $dropped ) {
			$out['total'] = (int) $q->found_posts;
			$out['total_pages'] = max( 1, (int) ceil( $out['total'] / $per ) );
		}
		return $out;
	}

	/** One post of the given types by id, as the detail row (or the formatter's). @return array|WP_Error */
	function d5b_rc_get( array $in, array $types, ?callable $format = null ) {
		$a = d5b_rc_args( $in, array( 'id' => 'id' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$p = d5b_rc_post( $a['id'], $types );
		if ( null === $p ) { return new WP_Error( 'not_found', 'No readable item with that id.' ); }
		return $format ? call_user_func( $format, $p ) : d5b_rc_detail( $p );
	}

	/** Permission for a get ability: read_post on the id in the input, for a post of one of $types. */
	function d5b_rc_can_get( array $in, array $types ): bool {
		return current_user_can( 'read' ) && null !== d5b_rc_post( $in['id'] ?? 0, $types );
	}

	function d5b_rc_can_list( array $in ): bool {
		return current_user_can( 'read' );
	}

	function d5b_rc_schema( array $props, array $required = array() ): array {
		$s = array( 'type' => 'object', 'additionalProperties' => false );
		if ( $props ) { $s['properties'] = $props; }
		if ( $required ) { $s['required'] = $required; }
		return $s;
	}

	function d5b_rc_paging_props(): array {
		return array(
			'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20, 'description' => 'Items per page, 1 to 100. Larger numbers are lowered to 100.' ),
			'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'Which page of the results to return, starting at 1. A page that starts more than 10,000 items in is refused (page_too_deep): use search or filters instead.' ),
		);
	}

	/** Adds one read-only definition. The default annotations (read-only, not destructive, idempotent) apply. */
	function d5b_rc_def( string $name, string $category, string $label, string $description, array $props, array $required, string $handler, $permission ): void {
		d5b_ability_add( array(
			'name' => 'divi5-builder/' . $name, 'category' => $category, 'label' => $label, 'description' => $description,
			'input_schema' => d5b_rc_schema( $props, $required ), 'output_schema' => array( 'type' => 'object' ),
			'handler' => $handler, 'permission' => $permission,
		) );
	}

	// ---- posts and pages

	function d5b_rc_status_prop(): array {
		return array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private', 'future', 'any' ), 'default' => 'publish', 'description' => 'Only items with this status. Drafts and private items of other people are left out unless the caller may read them.' );
	}

	function d5b_rc_h_posts_list( array $in ) {
		$a = d5b_rc_args( $in, array( 'status' => 'enum:publish|draft|pending|private|future|any', 'search' => 'str', 'category' => 'id', 'tag' => 'id', 'author' => 'id', 'per_page' => 'int', 'page' => 'int' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$q = d5b_rc_scope( array( 'post' ), $a['status'] ?? 'publish' );
		if ( ! d5b_rc_with_author( $q, $a['author'] ?? null ) ) { return d5b_rc_empty_page( $a ); }
		if ( isset( $a['search'] ) ) { $q['s'] = $a['search']; }
		if ( isset( $a['category'] ) ) { $q['cat'] = $a['category']; }
		if ( isset( $a['tag'] ) ) { $q['tag_id'] = $a['tag']; }
		return d5b_rc_list( $q, array( 'post' ), $a );
	}

	function d5b_rc_h_posts_get( array $in ) {
		return d5b_rc_get( $in, array( 'post' ), function ( $p ) {
			return d5b_rc_detail( $p ) + array( 'categories' => d5b_rc_term_rows( get_the_terms( $p->ID, 'category' ) ), 'tags' => d5b_rc_term_rows( get_the_terms( $p->ID, 'post_tag' ) ) );
		} );
	}

	function d5b_rc_h_pages_list( array $in ) {
		$a = d5b_rc_args( $in, array( 'status' => 'enum:publish|draft|pending|private|future|any', 'parent' => 'int', 'search' => 'str', 'per_page' => 'int', 'page' => 'int' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$q = d5b_rc_scope( array( 'page' ), $a['status'] ?? 'publish' );
		if ( isset( $a['parent'] ) ) { $q['post_parent'] = $a['parent']; }
		if ( isset( $a['search'] ) ) { $q['s'] = $a['search']; }
		return d5b_rc_list( $q, array( 'page' ), $a );
	}

	function d5b_rc_h_pages_get( array $in ) {
		return d5b_rc_get( $in, array( 'page' ) );
	}

	// ---- custom post types

	function d5b_rc_h_cpt_types( array $in ) {
		$a = d5b_rc_args( $in, array() );
		if ( is_wp_error( $a ) ) { return $a; }
		$items = array();
		foreach ( d5b_rc_exposed_types( true ) as $name => $obj ) {
			$items[] = array( 'name' => (string) $name, 'label' => (string) $obj->label, 'hierarchical' => ! empty( $obj->hierarchical ), 'public' => ! empty( $obj->public ), 'show_in_rest' => ! empty( $obj->show_in_rest ) );
		}
		return array( 'items' => $items, 'total' => count( $items ) );
	}

	function d5b_rc_h_cpt_list( array $in ) {
		$a = d5b_rc_args( $in, array( 'type' => 'slug', 'status' => 'enum:publish|draft|pending|private|future|any', 'per_page' => 'int', 'page' => 'int' ), array( 'type' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$type = d5b_rc_type_check( $a['type'] );
		if ( is_wp_error( $type ) ) { return $type; }
		return d5b_rc_list( d5b_rc_scope( array( $type ), $a['status'] ?? 'publish' ), array( $type ), $a );
	}

	function d5b_rc_h_cpt_get( array $in ) {
		$a = d5b_rc_args( $in, array( 'type' => 'slug', 'id' => 'id' ), array( 'type', 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$type = d5b_rc_type_check( $a['type'] );
		if ( is_wp_error( $type ) ) { return $type; }
		return d5b_rc_get( array( 'id' => $a['id'] ), array( $type ) );
	}

	/** A bad type passes here so the handler can answer not_allowed or invalid_type; a real type is checked per object. */
	function d5b_rc_can_cpt_get( array $in ): bool {
		if ( ! current_user_can( 'read' ) ) { return false; }
		if ( is_wp_error( d5b_rc_type_check( $in['type'] ?? null ) ) ) { return true; }
		return null !== d5b_rc_post( $in['id'] ?? 0, array( $in['type'] ) );
	}

	// ---- media

	function d5b_rc_media_summary( $p ): array {
		return d5b_rc_summary( $p ) + array( 'mime' => (string) $p->post_mime_type, 'file_url' => (string) wp_get_attachment_url( $p->ID ) );
	}

	function d5b_rc_media_detail( $p ): array {
		$meta  = wp_get_attachment_metadata( $p->ID );
		$meta  = is_array( $meta ) ? $meta : array();
		$url   = (string) wp_get_attachment_url( $p->ID );
		$base  = false === strrpos( $url, '/' ) ? '' : substr( $url, 0, strrpos( $url, '/' ) + 1 );
		$sizes = array( 'full' => array( 'url' => $url, 'width' => (int) ( $meta['width'] ?? 0 ), 'height' => (int) ( $meta['height'] ?? 0 ), 'mime' => (string) $p->post_mime_type ) );
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $name => $s ) {
			if ( ! is_array( $s ) || ! isset( $s['file'] ) ) { continue; }
			$sizes[ (string) $name ] = array( 'url' => $base . $s['file'], 'width' => (int) ( $s['width'] ?? 0 ), 'height' => (int) ( $s['height'] ?? 0 ), 'mime' => (string) ( $s['mime-type'] ?? '' ) );
		}
		return d5b_rc_media_summary( $p ) + array(
			'alt' => (string) get_post_meta( $p->ID, '_wp_attachment_image_alt', true ), 'caption' => (string) $p->post_excerpt, 'description' => (string) $p->post_content,
			'sizes' => $sizes, 'hash' => d5b_rc_hash( $p ),
		);
	}

	function d5b_rc_h_media_list( array $in ) {
		$a = d5b_rc_args( $in, array( 'mime' => 'mime', 'search' => 'str', 'per_page' => 'int', 'page' => 'int' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$q = array( 'post_status' => 'inherit' );
		if ( isset( $a['mime'] ) ) { $q['post_mime_type'] = $a['mime']; }
		if ( isset( $a['search'] ) ) { $q['s'] = $a['search']; }
		// Whether a file may be read depends on its parent post, which the query cannot see: so no total is ever reported.
		return d5b_rc_list( $q, array( 'attachment' ), $a, 'd5b_rc_media_summary', false );
	}

	function d5b_rc_h_media_get( array $in ) {
		return d5b_rc_get( $in, array( 'attachment' ), 'd5b_rc_media_detail' );
	}

	// ---- custom fields

	/** Scalars and arrays pass through; any object (also an unserialized-class stand-in) becomes the text [object]. */
	function d5b_rc_meta_clean( $v, int $depth = 0 ) {
		if ( is_object( $v ) ) { return '[object]'; }
		if ( ! is_array( $v ) ) { return $v; }
		if ( $depth > 8 ) { return '[nested]'; }
		foreach ( $v as $k => $x ) { $v[ $k ] = d5b_rc_meta_clean( $x, $depth + 1 ); }
		return $v;
	}

	/** The post whose custom fields the caller may read: readable AND editable (edit_post, as the wp-admin Custom Fields box). */
	function d5b_rc_meta_post( $id ) {
		$p = d5b_rc_post( $id, null );
		return ( null !== $p && current_user_can( 'edit_post', $p->ID ) ) ? $p : null;
	}

	function d5b_rc_h_meta_get( array $in ) {
		$a = d5b_rc_args( $in, array( 'post_id' => 'id', 'key' => 'key' ), array( 'post_id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$p = d5b_rc_meta_post( $a['post_id'] );
		if ( null === $p ) { return d5b_rc_deny( $a['post_id'], null ); }
		$can_edit = current_user_can( 'edit_post', $p->ID );
		$all      = (array) get_post_meta( $p->ID );
		if ( isset( $a['key'] ) ) { $all = array_key_exists( $a['key'], $all ) ? array( $a['key'] => $all[ $a['key'] ] ) : array(); }
		$meta = array(); $omitted = 0; $bytes = 0; $truncated = false;
		foreach ( $all as $k => $vals ) {
			$k = (string) $k;
			if ( is_protected_meta( $k, 'post' ) && ! $can_edit ) { $omitted++; continue; }
			$vals = d5b_rc_meta_clean( (array) $vals );
			// An object keyed by meta key, never a list of key/value pairs: the secret scrubber works by key.
			$v    = 1 === count( $vals ) ? $vals[0] : $vals;
			$size = strlen( serialize( $v ) );
			if ( $size > 65536 ) { $v = array( '_truncated' => true, 'bytes' => $size ); $size = 40; }
			if ( $bytes + $size > 524288 ) { $truncated = true; break; }
			$bytes += $size;
			$meta[ $k ] = $v;
		}
		return array( 'post_id' => (int) $p->ID, 'meta' => $meta, 'omitted_protected' => $omitted, 'truncated' => $truncated );
	}

	// ---- reusable blocks

	function d5b_rc_h_blocks_list( array $in ) {
		$a = d5b_rc_args( $in, array( 'status' => 'enum:publish|draft|pending|private|future|any', 'per_page' => 'int', 'page' => 'int' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		return d5b_rc_list( d5b_rc_scope( array( 'wp_block' ), $a['status'] ?? 'publish' ), array( 'wp_block' ), $a );
	}

	function d5b_rc_h_blocks_get( array $in ) {
		return d5b_rc_get( $in, array( 'wp_block' ) );
	}

	// ---- site search

	function d5b_rc_h_search( array $in ) {
		$a = d5b_rc_args( $in, array( 'query' => 'str', 'types' => 'strs', 'per_page' => 'int', 'page' => 'int' ), array( 'query' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$exposed = d5b_rc_exposed_types();
		$types   = $a['types'] ?? array_values( array_diff( array_keys( $exposed ), array( 'attachment' ) ) );
		foreach ( $types as $t ) {
			if ( ! isset( $exposed[ $t ] ) ) { return new WP_Error( 'not_allowed', 'Search does not cover the type "' . preg_replace( '/[^A-Za-z0-9_\-]/', '', $t ) . '". divi5-builder/cpt-types lists the types.' ); }
		}
		// Strictest switch wins: a type whose category is off is skipped.
		$types = array_values( array_filter( $types, 'd5b_rc_type_on' ) );
		if ( ! $types ) { return d5b_rc_empty_page( $a ); }
		// Like core's REST search: published items, plus private ones only for a caller who may read private items of every type
		// searched. No drafts. Password-protected items only for someone who can edit other people's items.
		$q = array( 'post_status' => d5b_rc_can_all( $types, 'read_private_posts' ) ? array( 'publish', 'private' ) : array( 'publish' ), 's' => $a['query'] );
		if ( in_array( 'attachment', $types, true ) ) { $q['post_status'][] = 'inherit'; }
		if ( ! d5b_rc_can_all( $types, 'edit_others_posts' ) ) { $q['has_password'] = false; }
		return d5b_rc_list( $q, $types, $a, function ( $p ) {
			return d5b_rc_summary( $p ) + array( 'snippet' => d5b_rc_cut( wp_strip_all_tags( (string) $p->post_content ), 160 ) );
		}, ! in_array( 'attachment', $types, true ) );
	}

	// ---- the definitions

	$d5b_rc_get_perm = function ( array $types ) { return function ( array $in ) use ( $types ) { return d5b_rc_can_get( $in, $types ); }; };
	$d5b_rc_id_prop  = function ( string $what ) { return array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the ' . $what . ' to read.' ) ); };

	d5b_rc_def( 'posts-list', 'posts', 'List posts',
		'Returns one page of blog posts (id, title, slug, status, dates, author, link), newest first. Refuses posts the caller may not read, so drafts and private posts of other people are left out.',
		array( 'status' => d5b_rc_status_prop(), 'search' => array( 'type' => 'string', 'description' => 'Only posts whose title or text contains this.' ), 'category' => array( 'type' => 'integer', 'description' => 'Only posts in this category id (see taxonomy-list).' ), 'tag' => array( 'type' => 'integer', 'description' => 'Only posts with this tag id (see taxonomy-list).' ), 'author' => array( 'type' => 'integer', 'description' => 'Only posts by this user id.' ) ) + d5b_rc_paging_props(),
		array(), 'd5b_rc_h_posts_list', 'd5b_rc_can_list' );
	d5b_rc_def( 'posts-get', 'posts', 'Get a post',
		'Returns one post with its raw content, excerpt, categories, tags and a hash of the content (md5 of the raw content, the stale check for writes). Refuses a post the caller may not read, or one that is not a blog post.',
		$d5b_rc_id_prop( 'post' ), array( 'id' ), 'd5b_rc_h_posts_get', $d5b_rc_get_perm( array( 'post' ) ) );
	d5b_rc_def( 'pages-list', 'pages', 'List pages',
		'Returns one page of pages (id, title, slug, status, parent, dates, link). Refuses pages the caller may not read, so drafts and private pages of other people are left out.',
		array( 'status' => d5b_rc_status_prop(), 'parent' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Only the direct children of this page id. Use 0 for top-level pages.' ), 'search' => array( 'type' => 'string', 'description' => 'Only pages whose title or text contains this.' ) ) + d5b_rc_paging_props(),
		array(), 'd5b_rc_h_pages_list', 'd5b_rc_can_list' );
	d5b_rc_def( 'pages-get', 'pages', 'Get a page',
		'Returns one page with its raw content, excerpt, parent and a hash of the content (md5 of the raw content, the stale check for writes). Refuses a page the caller may not read, or an id that is not a page.',
		$d5b_rc_id_prop( 'page' ), array( 'id' ), 'd5b_rc_h_pages_get', $d5b_rc_get_perm( array( 'page' ) ) );

	d5b_rc_def( 'cpt-types', 'cpt', 'List custom post types',
		'Returns the post types that are public or available in the REST API, with their name and label. Refuses to list the internal types (templates, Divi layouts and the like), which have their own abilities.',
		array(), array(), 'd5b_rc_h_cpt_types', 'd5b_rc_can_list' );
	d5b_rc_def( 'cpt-list', 'cpt', 'List custom post type items',
		'Returns one page of items of a custom post type (see cpt-types). Refuses internal post types with the code not_allowed, unknown types, and items the caller may not read.',
		array( 'type' => array( 'type' => 'string', 'description' => 'The post type name, for example product or event.' ), 'status' => d5b_rc_status_prop() ) + d5b_rc_paging_props(),
		array( 'type' ), 'd5b_rc_h_cpt_list', 'd5b_rc_can_list' );
	d5b_rc_def( 'cpt-get', 'cpt', 'Get a custom post type item',
		'Returns one item of a custom post type with its raw content and a hash of the content. Refuses internal post types with the code not_allowed, an item of another type, and an item the caller may not read.',
		array( 'type' => array( 'type' => 'string', 'description' => 'The post type name, for example product or event.' ), 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the item to read.' ) ),
		array( 'type', 'id' ), 'd5b_rc_h_cpt_get', 'd5b_rc_can_cpt_get' );

	d5b_rc_def( 'media-list', 'media', 'List media',
		'Returns one page of media library items (id, title, type, file link). Refuses items the caller may not read, such as files attached to a private post.',
		array( 'mime' => array( 'type' => 'string', 'description' => 'Only files of this type, for example image or image/png.' ), 'search' => array( 'type' => 'string', 'description' => 'Only items whose title or description contains this.' ) ) + d5b_rc_paging_props(),
		array(), 'd5b_rc_h_media_list', 'd5b_rc_can_list' );
	d5b_rc_def( 'media-get', 'media', 'Get a media item',
		'Returns one media item with its alt text, caption, description, file link, every image size and a hash of the description. Refuses an item the caller may not read, or an id that is not media.',
		$d5b_rc_id_prop( 'media item' ), array( 'id' ), 'd5b_rc_h_media_get', $d5b_rc_get_perm( array( 'attachment' ) ) );

	d5b_rc_def( 'meta-get', 'meta', 'Get custom fields',
		'Returns the custom fields (post meta) of one item as an object keyed by field name, including the fields whose name starts with an underscore (Divi keeps its layout data there); values under secret-named keys are removed. Refuses an item the caller may not edit (edit_post, the same rule as the Custom Fields box in wp-admin).',
		array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the post, page or other item.' ), 'key' => array( 'type' => 'string', 'description' => 'Only this one field. Leave out for all fields.' ) ),
		array( 'post_id' ), 'd5b_rc_h_meta_get', function ( array $in ) { return current_user_can( 'read' ) && null !== d5b_rc_meta_post( $in['post_id'] ?? 0 ); } );

	d5b_rc_def( 'blocks-list', 'blocks', 'List reusable blocks',
		'Returns one page of reusable blocks (synced patterns): id, title, status and dates. Refuses blocks the caller may not read.',
		array( 'status' => d5b_rc_status_prop() ) + d5b_rc_paging_props(), array(), 'd5b_rc_h_blocks_list', 'd5b_rc_can_list' );
	d5b_rc_def( 'blocks-get', 'blocks', 'Get a reusable block',
		'Returns one reusable block with its raw block markup and a hash of the content. Refuses a block the caller may not read, or an id that is not a reusable block.',
		$d5b_rc_id_prop( 'reusable block' ), array( 'id' ), 'd5b_rc_h_blocks_get', $d5b_rc_get_perm( array( 'wp_block' ) ) );

	d5b_rc_def( 'search', 'search', 'Search the site',
		'Returns one page of items whose title or text contains the query, each with a short snippet. Refuses items the caller may not read, and post types that are internal or not public.',
		array( 'query' => array( 'type' => 'string', 'description' => 'The words to look for.' ), 'types' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Only these post types. Leave out for posts, pages and the public custom types.' ) ) + d5b_rc_paging_props(),
		array( 'query' ), 'd5b_rc_h_search', 'd5b_rc_can_list' );

	unset( $d5b_rc_get_perm, $d5b_rc_id_prop );

	// Post-like results: their content fields get value-shape masking only (see d5b_ability_scrub_result).
	d5b_ability_set_content_keys( array( 'posts-list', 'posts-get', 'pages-list', 'pages-get', 'cpt-list', 'cpt-get', 'blocks-list', 'blocks-get' ) );
}
