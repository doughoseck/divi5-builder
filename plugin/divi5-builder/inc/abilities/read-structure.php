<?php
/**
 * Read abilities for structure (#32, release 2.1.0, part 2): taxonomies, comments, menus and revisions. Uses the
 * helpers of read-content.php (d5b_rc_*), which is loaded first. Same rules as part 1: the handler checks its own input,
 * lists ask for 'read' and drop what the caller may not read, a get checks the one object, and a refusal because of the
 * object is the same answer whether it is missing or not readable.
 *
 * This file only defines functions and adds its definitions to the registry when it is included.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_rc_h_taxonomy_list' ) ) {

	// ---- taxonomies

	/** Taxonomies that are internal bookkeeping, never offered. */
	function d5b_rc_internal_taxonomies(): array {
		return array( 'nav_menu', 'link_category', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category', 'layout_category', 'layout_tag', 'layout_type', 'module_width', 'scope', 'et_tb_item_type' );
	}

	/** @return string|WP_Error the taxonomy name when the abilities may use it */
	function d5b_rc_taxonomy_check( $tax ) {
		if ( ! is_string( $tax ) || '' === $tax ) { return new WP_Error( 'invalid_input', 'taxonomy is required.' ); }
		$obj = get_taxonomy( $tax );
		if ( ! is_object( $obj ) ) { return new WP_Error( 'invalid_taxonomy', 'There is no taxonomy "' . $tax . '".' ); }
		if ( in_array( $tax, d5b_rc_internal_taxonomies(), true ) || ( empty( $obj->public ) && empty( $obj->show_in_rest ) ) ) {
			return new WP_Error( 'not_allowed', 'The taxonomy "' . $tax . '" is not public, so it is not available here.' );
		}
		return $tax;
	}

	function d5b_rc_term_detail( $t ): array {
		return array( 'id' => (int) $t->term_id, 'name' => (string) $t->name, 'slug' => (string) $t->slug, 'description' => (string) $t->description, 'parent' => (int) $t->parent, 'count' => (int) $t->count, 'taxonomy' => (string) $t->taxonomy );
	}

	function d5b_rc_h_taxonomy_list( array $in ) {
		$a = d5b_rc_args( $in, array( 'taxonomy' => 'slug', 'per_page' => 'int', 'page' => 'int' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$tax = d5b_rc_taxonomy_check( $a['taxonomy'] ?? 'category' );
		if ( is_wp_error( $tax ) ) { return $tax; }
		list( $per, $page ) = d5b_rc_paging( $a );
		$rows = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => $per, 'offset' => ( $page - 1 ) * $per ) );
		$n    = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'count' ) );
		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $t ) { $items[] = d5b_rc_term_detail( $t ); }
		$total = is_numeric( $n ) ? (int) $n : count( $items );
		return array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per, 'total_pages' => max( 1, (int) ceil( $total / $per ) ) );
	}

	function d5b_rc_h_taxonomy_get( array $in ) {
		$a = d5b_rc_args( $in, array( 'taxonomy' => 'slug', 'id' => 'id' ), array( 'taxonomy', 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$tax = d5b_rc_taxonomy_check( $a['taxonomy'] );
		if ( is_wp_error( $tax ) ) { return $tax; }
		$t = get_term( $a['id'], $tax );
		if ( ! is_object( $t ) || is_wp_error( $t ) ) { return new WP_Error( 'not_found', 'No term with that id in that taxonomy.' ); }
		return d5b_rc_term_detail( $t );
	}

	// ---- comments

	function d5b_rc_comment_row( $c, bool $moderator ): array {
		$row = array(
			'id' => (int) $c->comment_ID, 'post' => (int) $c->comment_post_ID, 'author' => (string) $c->comment_author, 'date_gmt' => (string) $c->comment_date_gmt,
			'content' => (string) $c->comment_content, 'status' => d5b_rc_comment_status( $c ), 'parent' => (int) $c->comment_parent, 'user_id' => (int) $c->user_id, 'type' => (string) $c->comment_type,
		);
		// The address of a visitor goes only to a moderator; the IP address is never returned.
		if ( $moderator ) { $row['author_email'] = (string) $c->comment_author_email; }
		return $row;
	}

	function d5b_rc_comment_status( $c ): string {
		$map = array( '1' => 'approved', '0' => 'hold', 'spam' => 'spam', 'trash' => 'trash' );
		return $map[ (string) $c->comment_approved ] ?? 'other';
	}

	/** The comment when the caller may read it (approved, or any status for a moderator; and the post is readable), else null. */
	function d5b_rc_comment( $id ) {
		$id = d5b_rc_id_of( $id );
		$c  = $id > 0 ? get_comment( $id ) : null;
		if ( ! is_object( $c ) ) { return null; }
		// Real comments only: block notes, order notes and other private comment types stay out.
		if ( ! in_array( (string) $c->comment_type, array( '', 'comment', 'pingback', 'trackback' ), true ) ) { return null; }
		if ( '1' !== (string) $c->comment_approved && ! current_user_can( 'moderate_comments' ) ) { return null; }
		return null === d5b_rc_post( $c->comment_post_ID, null ) ? null : $c;
	}

	function d5b_rc_h_comments_list( array $in ) {
		$a = d5b_rc_args( $in, array( 'post' => 'id', 'status' => 'enum:approve|hold|spam|trash|all', 'per_page' => 'int', 'page' => 'int' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$status = $a['status'] ?? 'approve';
		$mod    = current_user_can( 'moderate_comments' );
		if ( 'approve' !== $status && ! $mod ) { return new WP_Error( 'forbidden_status', 'Only a moderator may list comments that are not approved.' ); }
		if ( isset( $a['post'] ) && null === d5b_rc_post( $a['post'], null ) ) { return d5b_rc_deny( $a['post'], null ); }
		list( $per, $page ) = d5b_rc_paging( $a );
		$args = array( 'status' => $status, 'type__in' => array( 'comment', 'pingback', 'trackback' ), 'orderby' => 'comment_date_gmt', 'order' => 'DESC' );
		if ( isset( $a['post'] ) ) { $args['post_id'] = $a['post']; }
		$rows  = get_comments( $args + array( 'number' => $per, 'offset' => ( $page - 1 ) * $per ) );
		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $c ) {
			if ( null !== d5b_rc_comment( $c->comment_ID ) ) { $items[] = d5b_rc_comment_row( $c, $mod ); }
		}
		$out = array( 'items' => $items, 'page' => $page, 'per_page' => $per );
		// A site-wide total would count comments on posts the caller may not read, so totals come only with a readable post.
		if ( isset( $a['post'] ) ) {
			$out['total'] = (int) get_comments( $args + array( 'count' => true ) );
			$out['total_pages'] = max( 1, (int) ceil( $out['total'] / $per ) );
		}
		return $out;
	}

	function d5b_rc_h_comments_get( array $in ) {
		$a = d5b_rc_args( $in, array( 'id' => 'id' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$c = d5b_rc_comment( $a['id'] );
		return null === $c ? new WP_Error( 'not_found', 'No readable comment with that id.' ) : d5b_rc_comment_row( $c, current_user_can( 'moderate_comments' ) );
	}

	// ---- menus

	/** Menus are theme structure: edit_theme_options, as in core's REST API. */
	function d5b_rc_can_menus( array $in = array() ): bool {
		return current_user_can( 'edit_theme_options' );
	}

	function d5b_rc_menus_denied() {
		return d5b_rc_can_menus() ? null : new WP_Error( 'forbidden', 'Reading menus needs the right to edit theme options.' );
	}

	function d5b_rc_h_menus_list( array $in ) {
		if ( $denied = d5b_rc_menus_denied() ) { return $denied; }
		$a = d5b_rc_args( $in, array() );
		if ( is_wp_error( $a ) ) { return $a; }
		$items = array();
		foreach ( (array) wp_get_nav_menus() as $m ) { $items[] = array( 'id' => (int) $m->term_id, 'name' => (string) $m->name, 'slug' => (string) $m->slug, 'count' => (int) $m->count ); }
		return array( 'items' => $items, 'total' => count( $items ) );
	}

	function d5b_rc_h_menus_items( array $in ) {
		if ( $denied = d5b_rc_menus_denied() ) { return $denied; }
		$a = d5b_rc_args( $in, array( 'menu' => 'menu' ), array( 'menu' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$menu = wp_get_nav_menu_object( $a['menu'] );
		if ( ! is_object( $menu ) ) { return new WP_Error( 'not_found', 'No menu with that id, slug or name.' ); }
		$items = array();
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $i ) {
			// A link to a page or post the caller may not read does not show its title either.
			if ( 'post_type' === ( $i->type ?? '' ) ) {
				$linked = d5b_rc_post( $i->object_id, null, false );
				if ( null === $linked ) { continue; }
				// The linked content belongs to a category that is switched off: keep the slot, hide everything it points at.
				if ( ! d5b_rc_type_on( (string) $linked->post_type ) ) { $items[] = array( 'id' => (int) $i->ID, 'type' => 'hidden', 'order' => (int) $i->menu_order ); continue; }
			}
			$items[] = array( 'id' => (int) $i->ID, 'title' => (string) $i->title, 'url' => (string) $i->url, 'type' => (string) $i->type, 'object' => (string) $i->object, 'object_id' => (int) $i->object_id, 'parent' => (int) $i->menu_item_parent, 'order' => (int) $i->menu_order );
		}
		return array( 'menu' => array( 'id' => (int) $menu->term_id, 'name' => (string) $menu->name, 'slug' => (string) $menu->slug ), 'items' => $items, 'total' => count( $items ) );
	}

	function d5b_rc_h_menus_locations( array $in ) {
		if ( $denied = d5b_rc_menus_denied() ) { return $denied; }
		$a = d5b_rc_args( $in, array() );
		if ( is_wp_error( $a ) ) { return $a; }
		$assigned = (array) get_nav_menu_locations();
		$items    = array();
		foreach ( (array) get_registered_nav_menus() as $slug => $label ) { $items[] = array( 'location' => (string) $slug, 'label' => (string) $label, 'menu_id' => (int) ( $assigned[ $slug ] ?? 0 ) ); }
		return array( 'items' => $items, 'total' => count( $items ) );
	}

	// ---- revisions (the history of a post: it can hold text that was never published, so editing rights are needed)

	/** The live post of one of $types (null = any non-internal) when the caller may read AND edit it, else null. */
	function d5b_rc_editable( $id, ?array $types = null ) {
		$p = d5b_rc_post( $id, $types );
		return ( null !== $p && current_user_can( 'edit_post', $p->ID ) ) ? $p : null;
	}

	/** A revision whose live post the caller may edit, or null. */
	function d5b_rc_revision( $id ) {
		$id = d5b_rc_id_of( $id );
		$r  = $id > 0 ? get_post( $id ) : null;
		if ( ! is_object( $r ) || 'revision' !== $r->post_type ) { return null; }
		return null === d5b_rc_editable( $r->post_parent, null ) ? null : $r;
	}

	function d5b_rc_revision_row( $r ): array {
		return array( 'id' => (int) $r->ID, 'parent' => (int) $r->post_parent, 'title' => (string) $r->post_title, 'date_gmt' => (string) $r->post_modified_gmt, 'author' => (int) $r->post_author );
	}

	function d5b_rc_revision_detail( $r ): array {
		return d5b_rc_revision_row( $r ) + array( 'content' => (string) $r->post_content, 'excerpt' => (string) $r->post_excerpt, 'hash' => d5b_rc_hash( $r ) );
	}

	function d5b_rc_revisions_for( array $in, string $key, ?array $types ) {
		$a = d5b_rc_args( $in, array( $key => 'id', 'per_page' => 'int', 'page' => 'int' ), array( $key ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$p = d5b_rc_editable( $a[ $key ], $types );
		if ( null === $p ) { return d5b_rc_deny( $a[ $key ], $types ); }
		list( $per, $page ) = d5b_rc_paging( $a );
		// Only the ids of this page come from the database; the revisions themselves are loaded one page at a time.
		$q = new WP_Query( array( 'post_type' => 'revision', 'post_parent' => $p->ID, 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => $per, 'paged' => $page, 'orderby' => 'date ID', 'order' => 'DESC', 'ignore_sticky_posts' => true ) );
		$items = array();
		foreach ( (array) $q->posts as $rid ) {
			$r = get_post( (int) $rid );
			if ( is_object( $r ) && 'revision' === $r->post_type ) { $items[] = d5b_rc_revision_row( $r ); }
		}
		$total = (int) $q->found_posts;
		return array( 'post_id' => (int) $p->ID, 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per, 'total_pages' => max( 1, (int) ceil( $total / $per ) ) );
	}

	function d5b_rc_h_posts_revisions( array $in ) { return d5b_rc_revisions_for( $in, 'id', array( 'post' ) ); }
	function d5b_rc_h_revisions_list( array $in ) { return d5b_rc_revisions_for( $in, 'post_id', null ); }

	function d5b_rc_h_revisions_get( array $in ) {
		$a = d5b_rc_args( $in, array( 'id' => 'id' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$r = d5b_rc_revision( $a['id'] );
		if ( null === $r ) {
			$raw = d5b_rc_id_of( $a['id'] ) > 0 ? get_post( d5b_rc_id_of( $a['id'] ) ) : null;
			return ( is_object( $raw ) && 'revision' === $raw->post_type ) ? d5b_rc_deny( $raw->post_parent, null ) : new WP_Error( 'not_found', 'No readable revision with that id.' );
		}
		return d5b_rc_revision_detail( $r );
	}

	/** One side of a comparison: a revision, or the live post itself. @return array|null the detail row */
	function d5b_rc_side( $id ) {
		$id = d5b_rc_id_of( $id );
		$r  = d5b_rc_revision( $id );
		if ( null !== $r ) { return d5b_rc_revision_detail( $r ); }
		$p = d5b_rc_editable( $id, null );
		return null === $p ? null : array_merge( d5b_rc_revision_row( $p ), array( 'parent' => (int) $p->ID, 'content' => (string) $p->post_content, 'excerpt' => (string) $p->post_excerpt, 'hash' => d5b_rc_hash( $p ) ) );
	}

	function d5b_rc_h_revisions_compare( array $in ) {
		$a = d5b_rc_args( $in, array( 'from' => 'id', 'to' => 'id' ), array( 'from', 'to' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$from = d5b_rc_side( $a['from'] );
		$to   = d5b_rc_side( $a['to'] );
		if ( null === $from || null === $to ) {
			foreach ( array( $a['from'], $a['to'] ) as $one ) {
				$raw = get_post( (int) $one );
				$e   = d5b_rc_deny( is_object( $raw ) && 'revision' === $raw->post_type ? $raw->post_parent : $one, null );
				if ( 'not_allowed' === $e->get_error_code() ) { return $e; }
			}
			return new WP_Error( 'not_found', 'No editable revision or post with that id.' );
		}
		if ( $from['parent'] !== $to['parent'] ) { return new WP_Error( 'invalid_input', 'The two ids must belong to the same post.' ); }
		return array( 'from' => $from, 'to' => $to, 'identical' => $from['hash'] === $to['hash'] );
	}

	// ---- the definitions

	d5b_rc_def( 'taxonomy-list', 'taxonomy', 'List terms',
		'Returns one page of terms of a public taxonomy (categories by default): id, name, slug, parent and how many items use it. Refuses internal and non-public taxonomies.',
		array( 'taxonomy' => array( 'type' => 'string', 'default' => 'category', 'description' => 'The taxonomy name, for example category or post_tag.' ) ) + d5b_rc_paging_props(),
		array(), 'd5b_rc_h_taxonomy_list', 'd5b_rc_can_list' );
	d5b_rc_def( 'taxonomy-get', 'taxonomy', 'Get a term',
		'Returns one term with its name, slug, description, parent and count. Refuses internal and non-public taxonomies, and a term that is not in the taxonomy named.',
		array( 'taxonomy' => array( 'type' => 'string', 'description' => 'The taxonomy name, for example category or post_tag.' ), 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The term id.' ) ),
		array( 'taxonomy', 'id' ), 'd5b_rc_h_taxonomy_get', 'd5b_rc_can_list' );

	d5b_rc_def( 'comments-list', 'comments', 'List comments',
		'Returns one page of comments, newest first. Refuses comments on posts the caller may not read, and any status other than approved unless the caller can moderate comments. Visitor email addresses go to moderators only.',
		array( 'post' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Only comments on this post id.' ), 'status' => array( 'type' => 'string', 'enum' => array( 'approve', 'hold', 'spam', 'trash', 'all' ), 'default' => 'approve', 'description' => 'Which comments. Anything other than approve needs moderation rights.' ) ) + d5b_rc_paging_props(),
		array(), 'd5b_rc_h_comments_list', function ( array $in ) {
			if ( ! current_user_can( 'read' ) ) { return false; }
			if ( 'approve' !== ( $in['status'] ?? 'approve' ) && ! current_user_can( 'moderate_comments' ) ) { return false; }
			return ! isset( $in['post'] ) || null !== d5b_rc_post( $in['post'], null );
		} );
	d5b_rc_def( 'comments-get', 'comments', 'Get a comment',
		'Returns one comment with its text, author name, date and status. Refuses a comment on a post the caller may not read, and an unapproved comment unless the caller can moderate comments.',
		array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The comment id.' ) ),
		array( 'id' ), 'd5b_rc_h_comments_get', function ( array $in ) { return current_user_can( 'read' ) && null !== d5b_rc_comment( $in['id'] ?? 0 ); } );

	d5b_rc_def( 'menus-list', 'menus', 'List menus',
		'Returns the navigation menus: id, name, slug and how many items each holds. Refuses callers without edit_theme_options.',
		array(), array(), 'd5b_rc_h_menus_list', 'd5b_rc_can_menus' );
	d5b_rc_def( 'menus-items', 'menus', 'List menu items',
		'Returns the items of one menu in order (title, link, type, parent). Refuses callers without edit_theme_options and an unknown menu, leaves out links to pages and posts the caller may not read, and shows a link to content whose category is switched off as type hidden (id and order only).',
		array( 'menu' => array( 'type' => array( 'integer', 'string' ), 'description' => 'The menu id, slug or name (see menus-list).' ) ),
		array( 'menu' ), 'd5b_rc_h_menus_items', 'd5b_rc_can_menus' );
	d5b_rc_def( 'menus-locations', 'menus', 'List menu locations',
		'Returns the menu locations the theme registers and which menu id each one shows (0 when none). Refuses callers without edit_theme_options.',
		array(), array(), 'd5b_rc_h_menus_locations', 'd5b_rc_can_menus' );

	d5b_rc_def( 'posts-revisions', 'posts', 'List revisions of a post',
		'Returns the saved revisions of one blog post, newest first (id, date, author, title). Refuses a post the caller may not edit, because revisions can hold text that was never published.',
		array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the post.' ) ) + d5b_rc_paging_props(),
		array( 'id' ), 'd5b_rc_h_posts_revisions', function ( array $in ) { return current_user_can( 'read' ) && null !== d5b_rc_editable( $in['id'] ?? 0, array( 'post' ) ); } );
	d5b_rc_def( 'revisions-list', 'revisions', 'List revisions',
		'Returns the saved revisions of one post, page or public custom item, newest first. Refuses an item the caller may not edit, and internal post types, because revisions can hold text that was never published.',
		array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the post or page.' ) ) + d5b_rc_paging_props(),
		array( 'post_id' ), 'd5b_rc_h_revisions_list', function ( array $in ) { return current_user_can( 'read' ) && null !== d5b_rc_editable( $in['post_id'] ?? 0, null ); } );
	d5b_rc_def( 'revisions-get', 'revisions', 'Get a revision',
		'Returns one revision with its raw content and a hash of that content (md5 of the raw content). Refuses a revision of a post the caller may not edit, and an id that is not a revision.',
		array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The revision id.' ) ),
		array( 'id' ), 'd5b_rc_h_revisions_get', function ( array $in ) { return current_user_can( 'read' ) && null !== d5b_rc_revision( $in['id'] ?? 0 ); } );
	d5b_rc_def( 'revisions-compare', 'revisions', 'Compare two revisions',
		'Returns both versions (content and hash) and whether they are identical. Either id may be a revision or the live post. Refuses ids of different posts, and posts the caller may not edit.',
		array( 'from' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The older revision id, or the post id.' ), 'to' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The newer revision id, or the post id.' ) ),
		array( 'from', 'to' ), 'd5b_rc_h_revisions_compare', function ( array $in ) { return current_user_can( 'read' ) && null !== d5b_rc_side( $in['from'] ?? 0 ) && null !== d5b_rc_side( $in['to'] ?? 0 ); } );
}
