<?php
/**
 * Shared helpers for the write abilities (#32, release 2.2.0): the stale check, the masked-read check, the Divi-content
 * check, the status rule, the change-history "before" of a post-like object and the write response.
 *
 * This file only defines functions. Every later write task reuses them.
 *
 * The rules they carry (design 2026-10-29, "The one rule" and "Guardrails"):
 *  - No stale overwrite: a content write carries expect_hash, md5 of the raw post_content (d5b_rc_hash).
 *  - A masked read is never written back: incoming text holding the scrubber's [redacted] marker, where the stored text
 *    does not, is refused.
 *  - Divi block markup needs unfiltered_html, because kses would corrupt the block JSON.
 *  - Draft by default; publish, future and private need the type's publish_posts.
 *  - The history keeps the full "before": every field, and the content itself unless a revision with byte-identical
 *    content exists (then revision_id points at it).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wc_check_hash' ) ) {

	/** post_modified_gmt as ISO 8601 UTC ('' when the post has none). */
	function d5b_wc_iso( string $gmt ): string {
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) { return ''; }
		$t = strtotime( $gmt . ' UTC' );
		return false === $t ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $t );
	}

	/**
	 * The stale check. $expect null (not given) is invalid_input; a hash that does not match the stored content is 'stale'
	 * with data.last_saved (post_modified_gmt, ISO 8601) and data.hash (the current hash), so the client can read again.
	 * @return true|WP_Error
	 */
	function d5b_wc_check_hash( $p, ?string $expect ) {
		if ( null === $expect || '' === $expect ) {
			return new WP_Error( 'invalid_input', 'expect_hash is required when content is changed: read the item first and pass its hash.' );
		}
		$now = d5b_rc_hash( $p );
		if ( hash_equals( $now, strtolower( $expect ) ) ) { return true; }
		return new WP_Error( 'stale', 'The content changed since it was read (the hash does not match). Read it again, apply the change to the new content and pass the new hash.', array( 'last_saved' => d5b_wc_iso( (string) $p->post_modified_gmt ), 'hash' => $now ) );
	}

	/**
	 * The masked-read check: text that holds the scrubber's [redacted] marker came from a masked read unless the stored
	 * text holds it too. The client cannot be trusted to say where its text came from, so this is the check.
	 * @return true|WP_Error
	 */
	function d5b_wc_check_masked( string $incoming, string $stored ) {
		if ( false === strpos( $incoming, '[redacted]' ) || false !== strpos( $stored, '[redacted]' ) ) { return true; }
		return new WP_Error( 'masked_read', 'This text holds [redacted], which the plugin puts where it hid a secret on reading. Writing it back would overwrite the hidden value, so it is refused. Change only the parts you need without the masked text, or edit it in wp-admin.' );
	}

	/** True when the text holds a Divi 5 block (<!-- wp:divi/...). */
	function d5b_wc_has_divi( string $content ): bool {
		return 1 === preg_match( '#<!--\s*wp:divi/#i', $content );
	}

	/**
	 * Divi block markup from a caller without unfiltered_html is refused: kses would corrupt the block JSON on save and the
	 * corrupted page would become the next "before". Plain content is not affected.
	 * @return true|WP_Error
	 */
	function d5b_wc_check_divi_html( string $content ) {
		if ( ! d5b_wc_has_divi( $content ) || current_user_can( 'unfiltered_html' ) ) { return true; }
		return new WP_Error( 'needs_unfiltered_html', 'This content holds Divi blocks, and saving them needs the unfiltered_html capability (an administrator or editor on a single site). Without it WordPress would strip parts of the block data.' );
	}

	/**
	 * Draft and pending are always allowed; publish, future and private need the type's publish_posts capability.
	 * @return true|WP_Error
	 */
	function d5b_wc_status_allowed( string $type, string $status ) {
		if ( in_array( $status, array( 'draft', 'pending' ), true ) ) { return true; }
		if ( ! in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			return new WP_Error( 'invalid_input', 'status must be one of: draft, pending, publish, future, private.' );
		}
		if ( current_user_can( d5b_rc_type_cap( $type, 'publish_posts' ) ) ) { return true; }
		return new WP_Error( 'not_allowed', 'You may not publish this kind of content (status ' . $status . ' needs the publish capability). Save it as draft or pending instead.' );
	}

	/** The term ids of the post in every taxonomy registered for its type, sorted: taxonomy => ids. */
	function d5b_wc_terms_of( $p ): array {
		$out = array();
		foreach ( (array) get_object_taxonomies( (string) $p->post_type ) as $tax ) {
			$tax   = is_object( $tax ) ? (string) $tax->name : (string) $tax;
			$terms = get_the_terms( (int) $p->ID, $tax );
			$ids   = array();
			if ( is_array( $terms ) ) { foreach ( $terms as $t ) { $ids[] = (int) $t->term_id; } }
			sort( $ids );
			$out[ $tax ] = $ids;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * True when the value survives JSON exactly: scalars (a string must be valid UTF-8, a float finite), arrays up to 8 levels
	 * deep with UTF-8 keys, and stdClass (stored as its array, as the reads do). Any other object is not.
	 */
	function d5b_wc_json_safe( $v, int $depth = 0 ): bool {
		if ( is_string( $v ) ) { return 1 === preg_match( '//u', $v ); }
		if ( is_float( $v ) ) { return is_finite( $v ); }
		if ( null === $v || is_bool( $v ) || is_int( $v ) ) { return true; }
		if ( $v instanceof stdClass ) { $v = get_object_vars( $v ); }
		if ( ! is_array( $v ) || $depth >= 8 ) { return false; }
		foreach ( $v as $k => $x ) {
			if ( is_string( $k ) && 1 !== preg_match( '//u', $k ) ) { return false; }
			if ( ! d5b_wc_json_safe( $x, $depth + 1 ) ) { return false; }
		}
		return true;
	}

	/** stdClass as an array, every level. */
	function d5b_wc_unobject( $v ) {
		if ( $v instanceof stdClass ) { $v = get_object_vars( $v ); }
		if ( is_array( $v ) ) { foreach ( $v as $k => $x ) { $v[ $k ] = d5b_wc_unobject( $x ); } }
		return $v;
	}

	/**
	 * The value with every object as an array of its properties (private and protected ones too, their names without
	 * PHP's \0Class\0 prefix), every level: what a key check must see of a value that would be stored serialized.
	 */
	function d5b_wc_plain( $v, int $depth = 0 ) {
		if ( is_object( $v ) ) {
			$a = array();
			foreach ( (array) $v as $k => $x ) { $a[ is_string( $k ) ? preg_replace( '/^\0[^\0]*\0/', '', $k ) : $k ] = $x; }
			$v = $a;
		}
		if ( ! is_array( $v ) || $depth >= 32 ) { return $v; }
		foreach ( $v as $k => $x ) { $v[ $k ] = d5b_wc_plain( $x, $depth + 1 ); }
		return $v;
	}

	/**
	 * True when the history's key filter would keep the whole value: no secret-named key at any depth (objects included).
	 * A value that fails it cannot be kept in full, so a write that would overwrite or delete it is refused (the one rule:
	 * nothing is lost that the history cannot put back).
	 */
	function d5b_wc_secret_free( $v ): bool {
		if ( ! function_exists( 'd5b_ability_scrub_keys' ) ) { return true; }
		$p = d5b_wc_plain( $v );
		return d5b_ability_scrub_keys( $p ) === $p;
	}

	/** Refusal for a value the history could not keep in full. */
	function d5b_wc_secret_error( string $what ): WP_Error {
		return new WP_Error( 'not_allowed', 'This ' . $what . ' holds a value under a secret-named key; change or delete it in wp-admin.' );
	}

	/**
	 * Everything that makes a nav menu item, as stored. With $all_meta also every other custom field of the item (protected
	 * ones too: menu plugins keep icons and flags there), as key => rows; secret-named keys are left out (a delete checks
	 * d5b_wc_post_secret_free first and refuses).
	 */
	function d5b_wc_nav_item_data( $p, bool $all_meta = false ): array {
		$id    = (int) $p->ID;
		$menus = wp_get_object_terms( $id, 'nav_menu', array( 'fields' => 'ids' ) );
		$menus = is_array( $menus ) ? array_values( array_map( 'intval', $menus ) ) : array();
		$cls   = get_post_meta( $id, '_menu_item_classes', true );
		$cls   = is_array( $cls ) ? array_values( array_filter( array_map( 'strval', $cls ), 'strlen' ) ) : array();
		$out   = array(
			'id' => $id, 'menu' => (int) ( $menus[0] ?? 0 ), 'status' => (string) $p->post_status,
			'parent' => (int) get_post_meta( $id, '_menu_item_menu_item_parent', true ), 'position' => (int) $p->menu_order,
			'title' => (string) $p->post_title, 'url' => (string) get_post_meta( $id, '_menu_item_url', true ),
			'type' => (string) get_post_meta( $id, '_menu_item_type', true ), 'object' => (string) get_post_meta( $id, '_menu_item_object', true ),
			'object_id' => (int) get_post_meta( $id, '_menu_item_object_id', true ),
			'classes' => $cls, 'target' => (string) get_post_meta( $id, '_menu_item_target', true ),
			'attr_title' => (string) $p->post_excerpt, 'description' => (string) $p->post_content, 'xfn' => (string) get_post_meta( $id, '_menu_item_xfn', true ),
		);
		if ( $all_meta ) {
			$known = array( '_menu_item_type', '_menu_item_menu_item_parent', '_menu_item_object_id', '_menu_item_object', '_menu_item_target', '_menu_item_classes', '_menu_item_xfn', '_menu_item_url' );
			$meta  = array();
			foreach ( array_keys( (array) get_post_meta( $id ) ) as $k ) {
				$k = (string) $k;
				if ( in_array( $k, $known, true ) || d5b_is_secret_key( $k ) ) { continue; }
				$meta[ $k ] = array_map( 'd5b_wc_meta_value', array_values( (array) get_post_meta( $id, $k, false ) ) );
			}
			ksort( $meta );
			$out['meta'] = $meta;
		}
		return $out;
	}

	/** True when no custom field of the post has a secret-named key or a value holding one (all of it can be kept). */
	function d5b_wc_post_secret_free( int $id ): bool {
		foreach ( array_keys( (array) get_post_meta( $id ) ) as $k ) {
			if ( d5b_is_secret_key( (string) $k ) || ! d5b_wc_secret_free( array_values( (array) get_post_meta( $id, (string) $k, false ) ) ) ) { return false; }
		}
		return true;
	}

	/**
	 * One stored meta value as the history keeps it, so it can be put back exactly: a JSON-safe value as it is (stdClass as an
	 * array); anything else (another object, nesting deeper than 8, invalid UTF-8) as { "serialized": base64 of
	 * maybe_serialize( value ) }, the form WordPress itself stores it in (put back with maybe_unserialize( base64_decode() )).
	 */
	function d5b_wc_meta_value( $v ) {
		// A secret under a secret-named key never reaches the history, also not inside the serialized (base64) form.
		if ( ! d5b_wc_secret_free( $v ) ) { return array( 'withheld' => 'secret_named_key' ); }
		if ( d5b_wc_json_safe( $v ) ) { return d5b_wc_unobject( $v ); }
		$s = function_exists( 'maybe_serialize' ) ? maybe_serialize( $v ) : serialize( $v );
		return array( 'serialized' => base64_encode( is_string( $s ) ? $s : serialize( $v ) ) );
	}

	/** Custom fields of the post: key => list of values (d5b_wc_meta_value). $protected picks the _ keys or the others; secret-named keys never. */
	function d5b_wc_meta_rows( $p, bool $protected ): array {
		$out = array();
		foreach ( array_keys( (array) get_post_meta( (int) $p->ID ) ) as $k ) {
			$k = (string) $k;
			if ( $protected !== is_protected_meta( $k, 'post' ) || d5b_is_secret_key( $k ) ) { continue; }
			$out[ $k ] = array_map( 'd5b_wc_meta_value', array_values( (array) get_post_meta( (int) $p->ID, $k, false ) ) );
		}
		ksort( $out );
		return $out;
	}

	/** The custom fields a write may touch (not protected, not secret-named): key => list of values. */
	function d5b_wc_meta_of( $p ): array {
		return d5b_wc_meta_rows( $p, false );
	}

	/** Every field of a post-like object other than its content, as the history keeps it (key names follow the inputs). */
	function d5b_wc_snapshot( $p ): array {
		$id = (int) $p->ID;
		return array(
			'type' => (string) $p->post_type, 'title' => (string) $p->post_title, 'slug' => (string) $p->post_name, 'status' => (string) $p->post_status,
			'excerpt' => (string) $p->post_excerpt, 'author' => (int) $p->post_author, 'parent' => (int) $p->post_parent,
			'date' => (string) ( $p->post_date ?? '' ), 'date_gmt' => (string) ( $p->post_date_gmt ?? '' ), 'menu_order' => (int) ( $p->menu_order ?? 0 ),
			'comment_status' => (string) ( $p->comment_status ?? '' ), 'ping_status' => (string) ( $p->ping_status ?? '' ),
			'terms' => d5b_wc_terms_of( $p ), 'meta' => d5b_wc_meta_of( $p ), 'featured_image' => (int) get_post_thumbnail_id( $id ),
			// Divi's own flags are protected meta, but divi: true writes them, so the history keeps them.
			'divi_flags' => array( '_et_pb_use_builder' => (string) get_post_meta( $id, '_et_pb_use_builder', true ), '_et_pb_use_divi_5' => (string) get_post_meta( $id, '_et_pb_use_divi_5', true ) ),
			'template' => (string) get_post_meta( $id, '_wp_page_template', true ),
			'sticky' => is_sticky( $id ),
			// The password itself is never stored: only whether there was one (it is set again by hand after a manual restore).
			// Not called has_password: the history's secret filter drops every key that names a password, with its value.
			'access_gate' => '' !== (string) ( $p->post_password ?? '' ),
			// Every protected custom field that is not secret-named (Divi's _et_pb_* page settings, the template, the thumbnail ...).
			'protected_meta' => d5b_wc_meta_rows( $p, true ),
		);
	}

	/** The snapshot plus the content: what the "after" of a write is compared against. */
	function d5b_wc_state( $p ): array {
		return d5b_wc_snapshot( $p ) + array( 'content' => (string) $p->post_content );
	}

	/**
	 * The latest revision (autosaves left out: they are overwritten) whose post_content is byte-identical to the post's,
	 * else 0. wp_get_post_revisions returns nothing when revisions are off for the type.
	 */
	function d5b_wc_matching_revision( $p ): int {
		$content = (string) $p->post_content;
		foreach ( (array) wp_get_post_revisions( (int) $p->ID ) as $r ) {
			if ( ! is_object( $r ) || 'revision' !== ( $r->post_type ?? '' ) || (int) $r->post_parent !== (int) $p->ID ) { continue; }
			if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $r ) ) { continue; }
			// A revision's content never changes once made, so any identical one holds the old content; newest first.
			if ( (string) $r->post_content === $content ) { return (int) $r->ID; }
		}
		return 0;
	}

	/**
	 * The history "before": every field, and the content only when no matching revision holds it. $with_content true always
	 * keeps the content (a trash: emptying the trash deletes the revisions with the post).
	 */
	function d5b_wc_post_before( $p, bool $with_content = false ): array {
		$before = d5b_wc_snapshot( $p );
		if ( $with_content || 0 === d5b_wc_matching_revision( $p ) ) { $before['content'] = (string) $p->post_content; }
		return $before;
	}

	/**
	 * True when title, content and excerpt would all be empty, as core's wp_insert_post judges it (the title trimmed, as the
	 * title_save_pre filter does; falsy values; only for a type that supports all three; never an attachment). Core then
	 * refuses with empty_content.
	 */
	function d5b_wc_would_be_empty( string $type, string $title, string $content, string $excerpt ): bool {
		if ( 'attachment' === $type || trim( $title ) || $content || $excerpt ) { return false; }
		return post_type_supports( $type, 'editor' ) && post_type_supports( $type, 'title' ) && post_type_supports( $type, 'excerpt' );
	}

	/**
	 * A WP_Error from wp_insert_post / wp_update_post in the plugin's codes: empty_content and anything else WordPress
	 * refused become invalid_input (400) with WordPress's message; a database error (db_*_error) becomes write_failed (500).
	 */
	function d5b_wc_core_error( WP_Error $e ): WP_Error {
		$code = (string) $e->get_error_code();
		$msg  = (string) $e->get_error_message();
		if ( 1 === preg_match( '/^db_[a-z_]*error$/', $code ) ) { return new WP_Error( 'write_failed', 'WordPress could not save it to the database' . ( '' === $msg ? '.' : ': ' . $msg ) ); }
		return new WP_Error( 'invalid_input', '' === $msg ? 'WordPress refused the change (' . $code . ').' : $msg );
	}

	/**
	 * Sets custom fields after the post is written, so a key that holds several rows ends with exactly the one value given
	 * (core's update_post_meta would set every row of the key to it). Values are slashed: core unslashes.
	 */
	function d5b_wc_apply_meta( int $id, array $meta ): void {
		foreach ( $meta as $k => $v ) {
			$k = (string) $k;
			if ( count( (array) get_post_meta( $id, $k, false ) ) > 1 ) {
				// core's delete_metadata unslashes the key too: a key with a backslash would miss its own rows unslashed.
				delete_post_meta( $id, wp_slash( $k ) );
				add_post_meta( $id, wp_slash( $k ), wp_slash( $v ) );
			} else {
				update_post_meta( $id, wp_slash( $k ), wp_slash( $v ) );
			}
		}
	}

	/** The fields of $new that differ from $old (the "after" of a write). */
	function d5b_wc_diff( array $old, array $new ): array {
		$out = array();
		foreach ( $new as $k => $v ) { if ( ! array_key_exists( $k, $old ) || $old[ $k ] !== $v ) { $out[ $k ] = $v; } }
		return $out;
	}

	/**
	 * The wp_insert_post / wp_update_post array from accepted input (title, content, excerpt, status, slug, parent, author,
	 * date, divi). Terms, custom fields (d5b_wc_apply_meta) and the featured image are set separately, after the post is
	 * written. The caller slashes it (wp_slash): core unslashes.
	 */
	function d5b_wc_apply_fields( array $a, $existing ): array {
		$arr = array();
		if ( is_object( $existing ) ) { $arr['ID'] = (int) $existing->ID; }
		$map = array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status', 'parent' => 'post_parent', 'author' => 'post_author' );
		foreach ( $map as $in => $field ) { if ( array_key_exists( $in, $a ) ) { $arr[ $field ] = $a[ $in ]; } }
		if ( isset( $a['slug'] ) ) { $arr['post_name'] = sanitize_title( (string) $a['slug'] ); }
		if ( isset( $a['date'] ) ) {
			$arr['post_date_gmt'] = (string) $a['date'];
			$arr['post_date']     = (string) get_date_from_gmt( (string) $a['date'], 'Y-m-d H:i:s' );
			$arr['edit_date']     = true;
		}
		if ( ! empty( $a['divi'] ) ) { $arr['meta_input'] = array( '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on' ); }
		return $arr;
	}

	/** The response of every post-like write: the detail row (id, url, hash, status and the rest). */
	function d5b_wc_result( $p ): array {
		return d5b_rc_detail( $p );
	}

	/**
	 * A post of one of $types the caller may read (switch of its type on) AND holds $cap on (edit_post, delete_post), or null.
	 */
	function d5b_wc_post( $id, ?array $types, string $cap = 'edit_post' ) {
		$p = d5b_rc_post( $id, $types );
		return ( null !== $p && current_user_can( $cap, (int) $p->ID ) ) ? $p : null;
	}

	/** Why d5b_wc_post said no: not_allowed for an item the caller can read but not change, else as d5b_rc_deny. */
	function d5b_wc_deny( $id, ?array $types ): WP_Error {
		if ( null !== d5b_rc_post( $id, $types ) ) { return new WP_Error( 'not_allowed', 'You may not change this item.' ); }
		return d5b_rc_deny( $id, $types );
	}

	/** False when the site has no trash (EMPTY_TRASH_DAYS is 0): wp_trash_post would then delete permanently. */
	function d5b_wc_trash_available(): bool {
		return ! defined( 'EMPTY_TRASH_DAYS' ) || (bool) constant( 'EMPTY_TRASH_DAYS' );
	}

	/** One history row; true when it was stored. */
	function d5b_wc_history( string $ability, $p, string $action, ?array $before, array $after, int $revision_id, string $summary ): bool {
		if ( ! function_exists( 'd5b_history_write' ) ) { return false; }
		return d5b_history_write( array(
			'ability' => $ability, 'object_type' => (string) $p->post_type, 'object_id' => (int) $p->ID, 'action' => $action,
			'before' => $before, 'after' => $after, 'revision_id' => $revision_id, 'summary' => $summary,
		) ) > 0;
	}

	/** "Updated page 42 'Contact us'". */
	function d5b_wc_summary( string $verb, string $what, $p ): string {
		$title = d5b_rc_cut( preg_replace( '/\s+/', ' ', (string) $p->post_title ), 80 );
		return $verb . ' ' . $what . ' ' . (int) $p->ID . ( '' === $title ? '' : " '" . $title . "'" );
	}

	/** Adds one write definition with its annotations (readonly false; destructive only for a trash or a delete). */
	function d5b_wc_def( string $name, string $category, string $label, string $description, array $props, array $required, $handler, $permission, array $annotations ): void {
		d5b_ability_add( array(
			'name' => 'divi5-builder/' . $name, 'category' => $category, 'label' => $label, 'description' => $description,
			'input_schema' => d5b_rc_schema( $props, $required ), 'output_schema' => array( 'type' => 'object' ),
			'handler' => $handler, 'permission' => $permission,
			'annotations' => array_merge( array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ), $annotations ),
		) );
	}
}
