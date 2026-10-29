<?php
/**
 * Write abilities for post-like content (#32, release 2.2.0): posts, pages, custom post types and reusable blocks, each
 * with four shapes: create, update, trash and restore.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here.
 *
 * Rules every ability here follows (design 2026-10-29):
 *  - The handler validates its own input (d5b_rc_args) and checks the object again: a direct call to the run wrapper skips
 *    core's schema check.
 *  - Strictest switch wins: the object is reached through d5b_rc_post, so its own type's category must be on.
 *  - Draft by default; publish, future and private need the type's publish_posts.
 *  - A content change needs expect_hash; a stale hash, masked text or Divi blocks without unfiltered_html write nothing.
 *  - Trash, never delete: a site without a trash (EMPTY_TRASH_DAYS 0) refuses the trash, because WordPress would delete.
 *  - Restore puts back the status the item had before it was trashed (core 5.6+ would give draft): the restore adds a
 *    wp_untrash_post_status filter for the one call, so no extra update (and no extra revision) is made.
 *  - Every successful write writes one change-history row after the write; when that row cannot be stored the result
 *    carries _history_failed and the audit row gets ok_no_history. A refusal writes no history row.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wp_kinds' ) ) {

	/** The four kinds: their post type (null = from the input), category, the word for one item, and what they accept. */
	function d5b_wp_kinds(): array {
		return array(
			'posts'  => array( 'type' => 'post', 'what' => 'post', 'terms' => 'cats', 'parent' => false, 'extras' => true ),
			'pages'  => array( 'type' => 'page', 'what' => 'page', 'terms' => '', 'parent' => true, 'extras' => true ),
			'cpt'    => array( 'type' => null, 'what' => '', 'terms' => 'map', 'parent' => true, 'extras' => true ),
			'blocks' => array( 'type' => 'wp_block', 'what' => 'reusable block', 'terms' => '', 'parent' => false, 'extras' => false ),
		);
	}

	/** The d5b_rc_args spec of one ability. */
	function d5b_wp_spec( string $kind, string $shape ): array {
		$k    = d5b_wp_kinds()[ $kind ];
		$spec = 'cpt' === $kind ? array( 'type' => 'slug' ) : array();
		if ( 'trash' === $shape || 'restore' === $shape ) { return $spec + array( 'id' => 'id' ); }
		if ( 'update' === $shape ) { $spec += array( 'id' => 'id', 'expect_hash' => 'str' ); }
		$spec += array( 'title' => 'text:1000', 'content' => 'text', 'status' => 'enum:draft|pending|publish|future|private', 'slug' => 'str', 'author' => 'id', 'meta' => 'map' );
		if ( $k['extras'] ) { $spec += array( 'excerpt' => 'text:10000', 'date' => 'date', 'featured_image' => 'int', 'divi' => 'bool' ); }
		if ( $k['parent'] ) { $spec['parent'] = 'int'; }
		if ( 'cats' === $k['terms'] ) { $spec += array( 'categories' => 'ids', 'tags' => 'ids' ); }
		if ( 'map' === $k['terms'] ) { $spec['terms'] = 'idmap'; }
		return $spec;
	}

	/** The post type of a call: fixed for posts, pages and blocks; the checked input type for cpt. @return string|WP_Error */
	function d5b_wp_type( string $kind, array $a ) {
		$t = d5b_wp_kinds()[ $kind ]['type'];
		return null === $t ? d5b_rc_type_check( $a['type'] ?? null ) : $t;
	}

	function d5b_wp_what( string $kind, string $type ): string {
		$w = d5b_wp_kinds()[ $kind ]['what'];
		return '' === $w ? $type : $w;
	}

	/**
	 * The checks of the related inputs: author, parent, terms, meta, featured image. $p is the post being updated (null on create).
	 * @return array|WP_Error array( 'terms' => taxonomy => ids ) to apply after the write
	 */
	function d5b_wp_related( string $kind, string $type, array $a, $p ) {
		$pto = get_post_type_object( $type );
		if ( isset( $a['author'] ) && (int) $a['author'] !== (int) get_current_user_id() ) {
			if ( ! current_user_can( d5b_rc_type_cap( $type, 'edit_others_posts' ) ) ) { return new WP_Error( 'not_allowed', 'You may not set another user as the author.' ); }
			if ( false === get_userdata( (int) $a['author'] ) ) { return new WP_Error( 'invalid_input', 'author is not a user of this site.' ); }
		}
		if ( isset( $a['parent'] ) && 0 !== $a['parent'] ) {
			if ( ! is_object( $pto ) || empty( $pto->hierarchical ) ) { return new WP_Error( 'invalid_input', 'This kind of content has no parent.' ); }
			if ( null === d5b_wc_post( $a['parent'], array( $type ), 'edit_post' ) ) { return new WP_Error( 'invalid_input', 'parent must be an existing item of the same kind that you may edit.' ); }
			if ( is_object( $p ) ) {
				// No loops: the item may not become its own ancestor.
				$up = (int) $a['parent'];
				for ( $i = 0; $up > 0 && $i < 100; $i++ ) {
					if ( $up === (int) $p->ID ) { return new WP_Error( 'invalid_input', 'parent may not be the item itself or one of its children.' ); }
					$q  = get_post( $up );
					$up = is_object( $q ) ? (int) $q->post_parent : 0;
				}
			}
		}
		$terms = array();
		if ( isset( $a['categories'] ) ) { $terms['category'] = $a['categories']; }
		if ( isset( $a['tags'] ) ) { $terms['post_tag'] = $a['tags']; }
		if ( isset( $a['terms'] ) ) { $terms = $a['terms']; }
		// Strictest switch wins: assigning terms is also a Taxonomy write.
		if ( $terms && ! d5b_abilities_category_on( 'taxonomy' ) ) { return new WP_Error( 'not_allowed', 'The Taxonomy ability category is switched off, so terms cannot be set. Leave out categories, tags and terms.' ); }
		foreach ( $terms as $tax => $ids ) {
			$tx = get_taxonomy( (string) $tax );
			if ( ! is_object( $tx ) || ! is_object_in_taxonomy( $type, (string) $tax ) ) { return new WP_Error( 'invalid_input', 'The taxonomy "' . $tax . '" is not registered for this kind of content.' ); }
			$assign = isset( $tx->cap->assign_terms ) ? (string) $tx->cap->assign_terms : 'edit_posts';
			if ( ! current_user_can( $assign ) ) { return new WP_Error( 'not_allowed', 'You may not assign terms of the taxonomy "' . $tax . '".' ); }
			foreach ( $ids as $tid ) {
				$t = get_term( (int) $tid, (string) $tax );
				if ( ! is_object( $t ) || is_wp_error( $t ) ) { return new WP_Error( 'invalid_input', 'There is no term ' . (int) $tid . ' in "' . $tax . '".' ); }
			}
		}
		foreach ( (array) ( $a['meta'] ?? array() ) as $key => $v ) {
			$key = (string) $key;
			if ( is_protected_meta( $key, 'post' ) || d5b_is_secret_key( $key ) ) {
				return new WP_Error( 'not_allowed', 'The custom field "' . substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $key ), 0, 100 ) . '" is protected (its name starts with _) or names a secret, so it cannot be written here.' );
			}
			// As core's REST API: edit_post_meta for the key (it runs the auth_post_meta_{key} filters). On create there is no id
			// yet, so the type's edit capability stands in.
			$may = is_object( $p ) ? current_user_can( 'edit_post_meta', (int) $p->ID, $key ) : current_user_can( d5b_rc_type_cap( $type, 'edit_posts' ) );
			if ( ! $may ) { return new WP_Error( 'not_allowed', 'You may not change the custom field "' . substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $key ), 0, 100 ) . '".' ); }
		}
		if ( isset( $a['featured_image'] ) && 0 !== $a['featured_image'] ) {
			if ( ! post_type_supports( $type, 'thumbnail' ) ) { return new WP_Error( 'invalid_input', 'This kind of content has no featured image.' ); }
			$img = d5b_rc_post( $a['featured_image'], array( 'attachment' ), false );
			if ( null === $img || 0 !== strpos( (string) $img->post_mime_type, 'image/' ) ) { return new WP_Error( 'invalid_input', 'featured_image must be the id of an image in the media library that you may read.' ); }
		}
		return array( 'terms' => $terms );
	}

	/** Sets the custom fields, the terms and the featured image after the post itself is written. @return string[] warnings */
	function d5b_wp_apply_related( int $id, array $a, array $rel ): array {
		$warn = array();
		if ( isset( $a['meta'] ) ) { d5b_wc_apply_meta( $id, $a['meta'] ); }
		foreach ( $rel['terms'] as $tax => $ids ) {
			$r = wp_set_post_terms( $id, array_map( 'intval', $ids ), (string) $tax, false );
			if ( is_wp_error( $r ) || false === $r ) { $warn[] = 'The terms of "' . $tax . '" could not be set.'; }
		}
		if ( isset( $a['featured_image'] ) ) {
			if ( 0 === $a['featured_image'] ) { delete_post_thumbnail( $id ); }
			elseif ( ! set_post_thumbnail( $id, (int) $a['featured_image'] ) && (int) get_post_thumbnail_id( $id ) !== (int) $a['featured_image'] ) { $warn[] = 'The featured image could not be set.'; }
		}
		return $warn;
	}

	/**
	 * The masked-read check over every text input against its stored counterpart. On create ($p null) there is nothing
	 * stored, so any [redacted] is refused.
	 * @return true|WP_Error
	 */
	function d5b_wp_check_masked_all( array $a, $p ) {
		$pairs = array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt' );
		foreach ( $pairs as $in => $field ) {
			if ( ! isset( $a[ $in ] ) ) { continue; }
			$r = d5b_wc_check_masked( (string) $a[ $in ], is_object( $p ) ? (string) $p->$field : '' );
			if ( true !== $r ) { return $r; }
		}
		foreach ( (array) ( $a['meta'] ?? array() ) as $k => $v ) {
			if ( ! is_string( $v ) ) { continue; }
			$stored = is_object( $p ) ? get_post_meta( (int) $p->ID, (string) $k, true ) : '';
			$r = d5b_wc_check_masked( $v, is_string( $stored ) ? $stored : '' );
			if ( true !== $r ) { return $r; }
		}
		return true;
	}

	/** The result with the history flag and any warnings. */
	function d5b_wp_out( $p, bool $stored, array $warn, array $extra = array() ): array {
		$out = d5b_wc_result( $p ) + $extra;
		if ( $warn ) { $out['warnings'] = $warn; }
		if ( ! $stored ) { $out['_history_failed'] = true; }
		return $out;
	}

	// ---- the four shapes

	/** @return array|WP_Error */
	function d5b_wp_create( string $kind, array $in ) {
		$a = d5b_rc_args( $in, d5b_wp_spec( $kind, 'create' ), 'cpt' === $kind ? array( 'type' ) : array() );
		if ( is_wp_error( $a ) ) { return $a; }
		$type = d5b_wp_type( $kind, $a );
		if ( is_wp_error( $type ) ) { return $type; }
		if ( ! d5b_rc_type_on( $type ) ) { return new WP_Error( 'not_allowed', 'The ability category for this kind of content is switched off.' ); }
		if ( ! current_user_can( d5b_rc_type_cap( $type, 'create_posts' ) ) ) { return new WP_Error( 'not_allowed', 'You may not create this kind of content.' ); }
		$status = $a['status'] ?? 'draft';
		$ok = d5b_wc_status_allowed( $type, $status );
		if ( true !== $ok ) { return $ok; }
		if ( 'future' === $status && ! isset( $a['date'] ) ) { return new WP_Error( 'invalid_input', 'status future needs a date.' ); }
		if ( isset( $a['content'] ) ) {
			$ok = d5b_wc_check_divi_html( $a['content'] );
			if ( true !== $ok ) { return $ok; }
		}
		$ok = d5b_wp_check_masked_all( $a, null );
		if ( true !== $ok ) { return $ok; }
		$rel = d5b_wp_related( $kind, $type, $a, null );
		if ( is_wp_error( $rel ) ) { return $rel; }
		$arr = d5b_wc_apply_fields( array( 'status' => $status ) + $a, null );
		$arr['post_type'] = $type;
		$id = wp_insert_post( wp_slash( $arr ), true );
		if ( is_wp_error( $id ) ) { return d5b_wc_core_error( $id ); }
		if ( (int) $id < 1 ) { return new WP_Error( 'write_failed', 'WordPress did not create the item.' ); }
		$warn = d5b_wp_apply_related( (int) $id, $a, $rel );
		$p    = get_post( (int) $id );
		if ( ! is_object( $p ) ) { return new WP_Error( 'write_failed', 'WordPress did not create the item.' ); }
		$stored = d5b_wc_history( 'divi5-builder/' . $kind . '-create', $p, 'create', null, d5b_wc_state( $p ), 0, d5b_wc_summary( 'Created', d5b_wp_what( $kind, $type ), $p ) );
		return d5b_wp_out( $p, $stored, $warn );
	}

	/** @return array|WP_Error */
	function d5b_wp_update( string $kind, array $in ) {
		$a = d5b_rc_args( $in, d5b_wp_spec( $kind, 'update' ), 'cpt' === $kind ? array( 'type', 'id' ) : array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$type = d5b_wp_type( $kind, $a );
		if ( is_wp_error( $type ) ) { return $type; }
		$p = d5b_wc_post( $a['id'], array( $type ), 'edit_post' );
		if ( null === $p ) { return d5b_wc_deny( $a['id'], array( $type ) ); }
		if ( 'trash' === $p->post_status ) { return new WP_Error( 'invalid_input', 'This item is in the trash: restore it first (divi5-builder/' . $kind . '-restore).' ); }
		$changes = array_diff_key( $a, array( 'type' => 1, 'id' => 1, 'expect_hash' => 1 ) );
		if ( array() === $changes ) { return new WP_Error( 'invalid_input', 'Nothing to change: give at least one field.' ); }
		if ( array_key_exists( 'content', $a ) ) {
			$ok = d5b_wc_check_hash( $p, $a['expect_hash'] ?? null );
			if ( true !== $ok ) { return $ok; }
			$ok = d5b_wc_check_divi_html( $a['content'] );
			if ( true !== $ok ) { return $ok; }
		}
		$ok = d5b_wp_check_masked_all( $a, $p );
		if ( true !== $ok ) { return $ok; }
		if ( isset( $a['status'] ) ) {
			$ok = d5b_wc_status_allowed( $type, $a['status'] );
			if ( true !== $ok ) { return $ok; }
			if ( 'future' === $a['status'] && ! isset( $a['date'] ) && 'future' !== $p->post_status ) { return new WP_Error( 'invalid_input', 'status future needs a date.' ); }
		}
		$rel = d5b_wp_related( $kind, $type, $a, $p );
		if ( is_wp_error( $rel ) ) { return $rel; }
		// As core: title, content and excerpt may not all end up empty (core would refuse with empty_content).
		if ( d5b_wc_would_be_empty( $type, (string) ( $a['title'] ?? $p->post_title ), (string) ( $a['content'] ?? $p->post_content ), (string) ( $a['excerpt'] ?? $p->post_excerpt ) ) ) {
			return new WP_Error( 'invalid_input', 'Title, content and excerpt would all be empty: WordPress does not allow that. Keep at least one.' );
		}
		// The "before" and the revision that holds the old content are taken BEFORE the write, against the old content.
		$old_content = (string) $p->post_content;
		$rev    = d5b_wc_matching_revision( $p );
		$before = d5b_wc_post_before( $p );
		$old    = d5b_wc_state( $p );
		$r = wp_update_post( wp_slash( d5b_wc_apply_fields( $a, $p ) ), true );
		if ( is_wp_error( $r ) ) { return d5b_wc_core_error( $r ); }
		if ( (int) $r < 1 ) { return new WP_Error( 'write_failed', 'WordPress did not save the change.' ); }
		$warn = d5b_wp_apply_related( (int) $p->ID, $a, $rel );
		$np   = get_post( (int) $p->ID );
		if ( ! is_object( $np ) ) { return new WP_Error( 'write_failed', 'WordPress did not save the change.' ); }
		// The revision WordPress made on this update can push the matching one out (WP_POST_REVISIONS): then keep the content.
		if ( $rev > 0 ) {
			$rp = get_post( $rev );
			if ( ! is_object( $rp ) || 'revision' !== $rp->post_type || (string) $rp->post_content !== $old_content ) { $rev = 0; }
		}
		if ( 0 === $rev ) { $before['content'] = $old_content; }
		$stored = d5b_wc_history( 'divi5-builder/' . $kind . '-update', $np, 'update', $before, d5b_wc_diff( $old, d5b_wc_state( $np ) ), $rev, d5b_wc_summary( 'Updated', d5b_wp_what( $kind, $type ), $np ) );
		return d5b_wp_out( $np, $stored, $warn );
	}

	/** @return array|WP_Error */
	function d5b_wp_trash( string $kind, array $in ) {
		$a = d5b_rc_args( $in, d5b_wp_spec( $kind, 'trash' ), 'cpt' === $kind ? array( 'type', 'id' ) : array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$type = d5b_wp_type( $kind, $a );
		if ( is_wp_error( $type ) ) { return $type; }
		$p = d5b_wc_post( $a['id'], array( $type ), 'delete_post' );
		if ( null === $p ) { return d5b_wc_deny( $a['id'], array( $type ) ); }
		if ( 'trash' === $p->post_status ) { return new WP_Error( 'cannot_trash', 'This item is already in the trash.' ); }
		if ( ! d5b_wc_trash_available() ) { return new WP_Error( 'cannot_trash', 'This site has no trash (EMPTY_TRASH_DAYS is 0), so WordPress would delete the item for good. It is not trashed.' ); }
		$rev    = d5b_wc_matching_revision( $p );
		// Always the content: emptying the trash deletes the revisions together with the post.
		$before = d5b_wc_post_before( $p, true );
		$old    = d5b_wc_state( $p );
		$r = wp_trash_post( (int) $p->ID );
		$np = get_post( (int) $p->ID );
		if ( ! $r || ! is_object( $np ) || 'trash' !== $np->post_status ) { return new WP_Error( 'cannot_trash', 'WordPress did not move the item to the trash (a plugin may have stopped it).' ); }
		$stored = d5b_wc_history( 'divi5-builder/' . $kind . '-trash', $np, 'trash', $before, d5b_wc_diff( $old, d5b_wc_state( $np ) ), $rev, d5b_wc_summary( 'Trashed', d5b_wp_what( $kind, $type ), $np ) );
		return d5b_wp_out( $np, $stored, array(), array( 'restore_with' => 'divi5-builder/' . $kind . '-restore' ) );
	}

	/** @return array|WP_Error */
	function d5b_wp_restore( string $kind, array $in ) {
		$a = d5b_rc_args( $in, d5b_wp_spec( $kind, 'restore' ), 'cpt' === $kind ? array( 'type', 'id' ) : array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$type = d5b_wp_type( $kind, $a );
		if ( is_wp_error( $type ) ) { return $type; }
		$p = d5b_wc_post( $a['id'], array( $type ), 'delete_post' );
		if ( null === $p ) { return d5b_wc_deny( $a['id'], array( $type ) ); }
		if ( 'trash' !== $p->post_status ) { return new WP_Error( 'not_found', 'This item is not in the trash.' ); }
		// Back to the status it had (kept by WordPress in _wp_trash_meta_status), when the caller may give it that status.
		$target = (string) get_post_meta( (int) $p->ID, '_wp_trash_meta_status', true );
		if ( ! in_array( $target, array( 'draft', 'pending', 'publish', 'future', 'private' ), true ) || true !== d5b_wc_status_allowed( $type, $target ) ) { $target = 'draft'; }
		$rev    = d5b_wc_matching_revision( $p );
		$before = d5b_wc_post_before( $p );
		$old    = d5b_wc_state( $p );
		$pid    = (int) $p->ID;
		$filter = function ( $new_status, $post_id = 0, $previous = '' ) use ( $target, $pid ) { return (int) $post_id === $pid ? $target : $new_status; };
		add_filter( 'wp_untrash_post_status', $filter, PHP_INT_MAX, 3 );
		try {
			$r = wp_untrash_post( $pid );
		} finally {
			remove_filter( 'wp_untrash_post_status', $filter, PHP_INT_MAX );
		}
		$np = get_post( $pid );
		if ( ! $r || ! is_object( $np ) || 'trash' === $np->post_status ) { return new WP_Error( 'write_failed', 'WordPress did not restore the item.' ); }
		$stored = d5b_wc_history( 'divi5-builder/' . $kind . '-restore', $np, 'restore', $before, d5b_wc_diff( $old, d5b_wc_state( $np ) ), $rev, d5b_wc_summary( 'Restored', d5b_wp_what( $kind, $type ), $np ) );
		return d5b_wp_out( $np, $stored, array() );
	}

	// ---- permissions (the handler checks again)

	/** A bad cpt type passes here so the handler can answer not_allowed or invalid_input, as the 2.1.0 reads do. */
	function d5b_wp_bad_cpt( string $kind, array $in ): bool {
		return 'cpt' === $kind && is_wp_error( d5b_rc_type_check( $in['type'] ?? null ) );
	}

	function d5b_wp_can_create( string $kind, array $in ): bool {
		if ( ! current_user_can( 'read' ) ) { return false; }
		if ( d5b_wp_bad_cpt( $kind, $in ) ) { return true; }
		$type = 'cpt' === $kind ? (string) $in['type'] : (string) d5b_wp_kinds()[ $kind ]['type'];
		return d5b_rc_type_on( $type ) && current_user_can( d5b_rc_type_cap( $type, 'create_posts' ) );
	}

	function d5b_wp_can_object( string $kind, array $in, string $cap ): bool {
		if ( ! current_user_can( 'read' ) ) { return false; }
		if ( d5b_wp_bad_cpt( $kind, $in ) ) { return true; }
		$type = 'cpt' === $kind ? (string) $in['type'] : (string) d5b_wp_kinds()[ $kind ]['type'];
		return null !== d5b_wc_post( $in['id'] ?? 0, array( $type ), $cap );
	}

	function d5b_wp_can_update( string $kind, array $in ): bool { return d5b_wp_can_object( $kind, $in, 'edit_post' ); }
	function d5b_wp_can_trash( string $kind, array $in ): bool { return d5b_wp_can_object( $kind, $in, 'delete_post' ); }
	function d5b_wp_can_restore( string $kind, array $in ): bool { return d5b_wp_can_object( $kind, $in, 'delete_post' ); }

	// ---- handlers, one per ability

	function d5b_wp_h_posts_create( array $in ) { return d5b_wp_create( 'posts', $in ); }
	function d5b_wp_h_posts_update( array $in ) { return d5b_wp_update( 'posts', $in ); }
	function d5b_wp_h_posts_trash( array $in ) { return d5b_wp_trash( 'posts', $in ); }
	function d5b_wp_h_posts_restore( array $in ) { return d5b_wp_restore( 'posts', $in ); }
	function d5b_wp_h_pages_create( array $in ) { return d5b_wp_create( 'pages', $in ); }
	function d5b_wp_h_pages_update( array $in ) { return d5b_wp_update( 'pages', $in ); }
	function d5b_wp_h_pages_trash( array $in ) { return d5b_wp_trash( 'pages', $in ); }
	function d5b_wp_h_pages_restore( array $in ) { return d5b_wp_restore( 'pages', $in ); }
	function d5b_wp_h_cpt_create( array $in ) { return d5b_wp_create( 'cpt', $in ); }
	function d5b_wp_h_cpt_update( array $in ) { return d5b_wp_update( 'cpt', $in ); }
	function d5b_wp_h_cpt_trash( array $in ) { return d5b_wp_trash( 'cpt', $in ); }
	function d5b_wp_h_cpt_restore( array $in ) { return d5b_wp_restore( 'cpt', $in ); }
	function d5b_wp_h_blocks_create( array $in ) { return d5b_wp_create( 'blocks', $in ); }
	function d5b_wp_h_blocks_update( array $in ) { return d5b_wp_update( 'blocks', $in ); }
	function d5b_wp_h_blocks_trash( array $in ) { return d5b_wp_trash( 'blocks', $in ); }
	function d5b_wp_h_blocks_restore( array $in ) { return d5b_wp_restore( 'blocks', $in ); }

	// ---- the input schemas

	function d5b_wp_props( string $kind, string $shape ): array {
		$what  = 'cpt' === $kind ? 'item' : d5b_wp_kinds()[ $kind ]['what'];
		$all = array(
			'type'           => array( 'type' => 'string', 'description' => 'The post type name, for example product or event (see cpt-types).' ),
			'id'             => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the ' . $what . '.' ),
			'expect_hash'    => array( 'type' => 'string', 'description' => 'The hash from the last read of this ' . $what . ' (its get). Required when content is given; a hash that no longer matches is refused with stale.' ),
			'title'          => array( 'type' => 'string', 'maxLength' => 1000, 'description' => 'The title, at most 1000 characters.' ),
			'content'        => array( 'type' => 'string', 'description' => 'The full content (raw block markup), at most 1 MB. Replaces the whole content.' ),
			'status'         => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'publish', 'future', 'private' ), 'description' => 'draft (the default on create), pending, publish, future (needs date) or private. publish, future and private need the right to publish.' ),
			'excerpt'        => array( 'type' => 'string', 'maxLength' => 10000, 'description' => 'The excerpt, at most 10000 characters.' ),
			'slug'           => array( 'type' => 'string', 'description' => 'The URL slug; it is cleaned the way WordPress cleans slugs.' ),
			'parent'         => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'The id of the parent (an existing ' . $what . ' you may edit), or 0 for none.' ),
			'author'         => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The user id of the author. Another user than yourself needs the right to edit other people\'s content.' ),
			'date'           => array( 'type' => 'string', 'description' => 'The publish date and time in ISO 8601: 2026-11-02T09:00:00 is site time, with Z or +02:00 it is that zone.' ),
			'categories'     => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Category ids (see taxonomy-list). Replaces the post\'s categories.' ),
			'tags'           => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => 'Tag ids (see taxonomy-list). Replaces the post\'s tags; [] removes them all.' ),
			'terms'          => array( 'type' => 'object', 'additionalProperties' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ), 'description' => 'Term ids by taxonomy, for example {"genre": [4, 9]}. Each list replaces the item\'s terms in that taxonomy; only taxonomies registered for the type.' ),
			'meta'           => array( 'type' => 'object', 'additionalProperties' => array( 'type' => array( 'string', 'number', 'integer', 'boolean' ) ), 'description' => 'Custom fields to set, name to value (text, number or true/false). Names starting with _ and secret-looking names are refused.' ),
			'featured_image' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'The id of an image in the media library, or 0 to remove the featured image.' ),
			'divi'           => array( 'type' => 'boolean', 'description' => 'true turns the Divi builder on for this item (_et_pb_use_builder and _et_pb_use_divi_5 = on). Without it, Divi blocks are saved but do not render as Divi until divi-builder-mode is on.' ),
		);
		$out = array();
		foreach ( array_keys( d5b_wp_spec( $kind, $shape ) ) as $k ) { $out[ $k ] = $all[ $k ]; }
		return $out;
	}

	// ---- the definitions

	foreach ( array(
		'posts'  => array( 'posts', 'post', 'a blog post' ),
		'pages'  => array( 'pages', 'page', 'a page' ),
		'cpt'    => array( 'cpt', 'custom post type item', 'an item of a custom post type (see cpt-types); internal types and post, page, attachment and wp_block are refused with not_allowed' ),
		'blocks' => array( 'blocks', 'reusable block', 'a reusable block (synced pattern)' ),
	) as $d5b_kind => $d5b_w ) {
		list( $d5b_cat, $d5b_word, $d5b_long ) = $d5b_w;
		$d5b_req = 'cpt' === $d5b_kind ? array( 'type' ) : array();
		$d5b_div = 'blocks' === $d5b_kind ? '' : ' With divi true the Divi builder is switched on for it.';
		d5b_wc_def( $d5b_kind . '-create', $d5b_cat, 'Create ' . ( 'cpt' === $d5b_kind ? 'a custom post type item' : 'a ' . $d5b_word ),
			'Creates ' . $d5b_long . ' and returns its id, link, status and the hash of its content. It is a draft unless status says otherwise; publish, future (with a date) and private are refused without the right to publish. Refuses Divi blocks in the content from a user without unfiltered_html (needs_unfiltered_html), text holding [redacted] (masked_read: it came from a masked read), protected or secret-named custom fields, terms of taxonomies not registered for it, terms while the Taxonomy category is off, and an item with no title, content and excerpt at all.' . $d5b_div . ' Every change is kept in the change history.',
			d5b_wp_props( $d5b_kind, 'create' ), $d5b_req, 'd5b_wp_h_' . $d5b_kind . '_create',
			function ( array $in ) use ( $d5b_kind ) { return d5b_wp_can_create( $d5b_kind, $in ); },
			array() );
		d5b_wc_def( $d5b_kind . '-update', $d5b_cat, 'Update ' . ( 'cpt' === $d5b_kind ? 'a custom post type item' : 'a ' . $d5b_word ),
			'Changes ' . $d5b_long . ': only the fields given. Returns the item with its new hash. Content needs expect_hash from the last read; a stale hash is refused (stale, with last_saved and the current hash) and nothing is written. Title-only or status-only changes need no hash. Refuses text holding [redacted] from a masked read (masked_read), Divi blocks from a user without unfiltered_html, terms while the Taxonomy category is off, a change that would leave title, content and excerpt all empty, and an item in the trash. On a Divi page prefer divi-section-edit or divi-text-edit over replacing the whole content. WordPress makes a revision as usual, and the change history keeps everything needed to put it back.',
			d5b_wp_props( $d5b_kind, 'update' ), array_merge( $d5b_req, array( 'id' ) ), 'd5b_wp_h_' . $d5b_kind . '_update',
			function ( array $in ) use ( $d5b_kind ) { return d5b_wp_can_update( $d5b_kind, $in ); },
			array() );
		d5b_wc_def( $d5b_kind . '-trash', $d5b_cat, 'Trash ' . ( 'cpt' === $d5b_kind ? 'a custom post type item' : 'a ' . $d5b_word ),
			'Moves ' . $d5b_long . ' to the trash (never deletes it) and returns it with restore_with, the ability that puts it back. Refuses an item already in the trash, an item you may not delete, and a site without a trash (EMPTY_TRASH_DAYS 0, where WordPress would delete it for good). The change history keeps a full copy (content included); a password is not kept, so after a manual restore from the history a password must be set again by hand.',
			d5b_wp_props( $d5b_kind, 'trash' ), array_merge( $d5b_req, array( 'id' ) ), 'd5b_wp_h_' . $d5b_kind . '_trash',
			function ( array $in ) use ( $d5b_kind ) { return d5b_wp_can_trash( $d5b_kind, $in ); },
			array( 'destructive' => true ) );
		d5b_wc_def( $d5b_kind . '-restore', $d5b_cat, 'Restore ' . ( 'cpt' === $d5b_kind ? 'a custom post type item' : 'a ' . $d5b_word ),
			'Takes ' . $d5b_long . ' out of the trash, back to the status it had before (a published item is published again; a caller without the right to publish gets it back as a draft), and returns it. Refuses an item that is not in the trash.',
			d5b_wp_props( $d5b_kind, 'restore' ), array_merge( $d5b_req, array( 'id' ) ), 'd5b_wp_h_' . $d5b_kind . '_restore',
			function ( array $in ) use ( $d5b_kind ) { return d5b_wp_can_restore( $d5b_kind, $in ); },
			array() );
	}
	unset( $d5b_kind, $d5b_w, $d5b_cat, $d5b_word, $d5b_long, $d5b_req, $d5b_div );

	// ---- revisions-restore

	/** The revision $rid when it is a revision of post $p, else null. */
	function d5b_wp_revision_of( $p, $rid ) {
		$rid = d5b_rc_id_of( $rid );
		$r   = $rid > 0 ? get_post( $rid ) : null;
		return ( is_object( $r ) && 'revision' === $r->post_type && (int) $r->post_parent === (int) $p->ID ) ? $r : null;
	}

	/**
	 * Puts a revision's title, content and excerpt back on its post (core's wp_restore_post_revision). Needs edit_post on the
	 * post, the post's own category on (the ability's category, Revisions, is checked by the gate), the hash of the CURRENT
	 * content, and, for a revision that holds Divi blocks, unfiltered_html.
	 *
	 * What core does: wp_restore_post_revision() updates the post with wp_update_post(), and the revision that update makes holds
	 * the NEW (restored) content. The content that is replaced is in the revision made at its own last save, when there is one:
	 * its id goes in the history row (revision_id), and the row keeps the content as well, because revisions can be pruned.
	 * @return array|WP_Error
	 */
	function d5b_wp_revisions_restore( array $in ) {
		$a = d5b_rc_args( $in, array( 'post_id' => 'id', 'revision_id' => 'id', 'expect_hash' => 'str' ), array( 'post_id', 'revision_id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$p = d5b_wc_post( $a['post_id'], null, 'edit_post' );
		if ( null === $p ) { return d5b_wc_deny( $a['post_id'], null ); }
		if ( 'trash' === $p->post_status ) { return new WP_Error( 'invalid_input', 'This item is in the trash: restore it first.' ); }
		$rv = d5b_wp_revision_of( $p, $a['revision_id'] );
		if ( null === $rv ) { return new WP_Error( 'not_found', 'No revision with that id belongs to this item.' ); }
		$ok = d5b_wc_check_hash( $p, $a['expect_hash'] ?? null );
		if ( true !== $ok ) { return $ok; }
		$ok = d5b_wc_check_divi_html( (string) $rv->post_content );
		if ( true !== $ok ) { return $ok; }
		if ( (string) $rv->post_title === (string) $p->post_title && (string) $rv->post_content === (string) $p->post_content && (string) $rv->post_excerpt === (string) $p->post_excerpt ) {
			return d5b_wp_out( $p, true, array(), array( 'changed' => false ) );
		}
		$old_content = (string) $p->post_content;
		$rev    = d5b_wc_matching_revision( $p );
		$before = d5b_wc_post_before( $p, true );
		$old    = d5b_wc_state( $p );
		$r = wp_restore_post_revision( (int) $rv->ID );
		if ( is_wp_error( $r ) ) { return d5b_wc_core_error( $r ); }
		if ( ! $r ) { return new WP_Error( 'write_failed', 'WordPress did not restore the revision.' ); }
		$np = get_post( (int) $p->ID );
		if ( ! is_object( $np ) || (string) $np->post_content !== (string) $rv->post_content ) { return new WP_Error( 'write_failed', 'WordPress did not restore the revision.' ); }
		// The new revision can push the old one out (WP_POST_REVISIONS): then the row's own copy of the content is all there is.
		if ( $rev > 0 ) {
			$rp = get_post( $rev );
			if ( ! is_object( $rp ) || 'revision' !== $rp->post_type || (string) $rp->post_content !== $old_content ) { $rev = 0; }
		}
		$stored = d5b_wc_history( 'divi5-builder/revisions-restore', $np, 'update', $before, d5b_wc_diff( $old, d5b_wc_state( $np ) ), $rev, d5b_wc_summary( 'Restored revision ' . (int) $rv->ID . ' of', (string) $np->post_type, $np ) );
		return d5b_wp_out( $np, $stored, array(), array( 'changed' => true, 'restored_revision' => (int) $rv->ID, 'previous_revision' => $rev ) );
	}

	function d5b_wp_h_revisions_restore( array $in ) { return d5b_wp_revisions_restore( $in ); }

	function d5b_wp_can_revisions_restore( array $in ): bool {
		if ( ! current_user_can( 'read' ) ) { return false; }
		$p = d5b_wc_post( $in['post_id'] ?? 0, null, 'edit_post' );
		return null !== $p && null !== d5b_wp_revision_of( $p, $in['revision_id'] ?? 0 );
	}

	d5b_wc_def( 'revisions-restore', 'revisions', 'Restore a revision',
		'Puts the title, content and excerpt of one saved revision back on its post, page or public custom item, and returns the item with its new hash. expect_hash is the hash of the item\'s CURRENT content (its get): a stale hash is refused (stale) and nothing is written. WordPress makes a new revision of the restored content as it saves; the revision that holds the content being replaced (made at its last save) is recorded in the change history, which also keeps the content itself. Refuses a revision that does not belong to the item, an item you may not edit or whose category is switched off, an item in the trash, and a revision holding Divi blocks from a user without unfiltered_html (needs_unfiltered_html). Needs the Revisions category switched on as well as the item\'s own.',
		array(
			'post_id'     => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the post, page or custom item.' ),
			'revision_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the revision to restore (see revisions-list). It must be a revision of that item.' ),
			'expect_hash' => array( 'type' => 'string', 'description' => 'The hash of the item\'s current content, from its last read. A hash that no longer matches is refused with stale.' ),
		), array( 'post_id', 'revision_id', 'expect_hash' ), 'd5b_wp_h_revisions_restore', 'd5b_wp_can_revisions_restore', array() );

	// Post-like results: their content fields get value-shape masking only, as the reads.
	d5b_ability_set_content_keys( array(
		'revisions-restore',
		'posts-create', 'posts-update', 'posts-trash', 'posts-restore', 'pages-create', 'pages-update', 'pages-trash', 'pages-restore',
		'cpt-create', 'cpt-update', 'cpt-trash', 'cpt-restore', 'blocks-create', 'blocks-update', 'blocks-trash', 'blocks-restore',
	) );
}
