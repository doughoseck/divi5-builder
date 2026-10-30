<?php
/**
 * Write abilities for comments (#32, release 2.2.0): approve, unapprove, spam, unspam, trash, restore and reply.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here. It uses the helpers of read-content.php, read-structure.php
 * (d5b_rc_comment_status, d5b_rc_comment_row) and write-common.php.
 *
 * Rules (design 2026-10-29):
 *  - Moderation needs moderate_comments AND a post of the comment that the caller may read with its own category on
 *    (strictest switch wins: a comment on a page is out of reach while Pages is off). Only real comments are reachable
 *    (type comment, pingback, trackback or empty): order notes, block notes and other private comment types are not.
 *  - Only the status changes. It goes through WordPress's own functions, so the trash metadata (_wp_trash_meta_status) is
 *    kept and a restore or an unspam gives back the status the comment had before; with no trash on the site
 *    (EMPTY_TRASH_DAYS 0) wp_trash_comment would delete for good, so the trash is refused there.
 *  - A reply is posted as the calling user through wp_new_comment(), which decides approved or held as for any logged-in user:
 *    the post's author and anyone with moderate_comments are approved at once, others follow the site's moderation settings.
 *  - Every successful write writes one change-history row (object_type comment) after the write; the "before" of a status
 *    change is the whole comment, so a wrong moderation can be put back by hand. A refusal writes no row; a row that could not
 *    be stored sets _history_failed on the result.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_cm_comment' ) ) {

	/** The comment types that are real comments. */
	function d5b_cm_types(): array {
		return array( '', 'comment', 'pingback', 'trackback' );
	}

	/** The comment when it is a real comment (any status), else null. */
	function d5b_cm_raw( $id ) {
		$id = d5b_rc_id_of( $id );
		$c  = $id > 0 ? get_comment( $id ) : null;
		return ( is_object( $c ) && in_array( (string) $c->comment_type, d5b_cm_types(), true ) ) ? $c : null;
	}

	/**
	 * The comment the caller may moderate: a real comment, moderate_comments, and a post of it that the caller may read
	 * whose own category is on. Else null.
	 */
	function d5b_cm_comment( $id ) {
		if ( ! current_user_can( 'moderate_comments' ) ) { return null; }
		$c = d5b_cm_raw( $id );
		if ( null === $c ) { return null; }
		return null === d5b_rc_post( $c->comment_post_ID, null ) ? null : $c;
	}

	/** Why d5b_cm_comment said no: not_allowed without the right (nothing is said about the comment), else as for a post. */
	function d5b_cm_deny( $id ): WP_Error {
		if ( ! current_user_can( 'moderate_comments' ) ) { return new WP_Error( 'not_allowed', 'You may not moderate comments.' ); }
		$c = d5b_cm_raw( $id );
		if ( null === $c ) { return new WP_Error( 'not_found', 'No comment with that id.' ); }
		$e = d5b_rc_deny( $c->comment_post_ID, null );
		return 'not_allowed' === $e->get_error_code() ? $e : new WP_Error( 'not_found', 'No comment with that id.' );
	}

	/** The whole comment as the history keeps it (no IP address; the address of a visitor is what a moderator sees anyway). */
	function d5b_cm_before( $c ): array {
		return d5b_rc_comment_row( $c, true ) + array( 'author_url' => (string) ( $c->comment_author_url ?? '' ), 'date' => (string) ( $c->comment_date ?? '' ) );
	}

	/** One history row for a comment; true when it was stored. */
	function d5b_cm_history( string $ability, $c, string $action, ?array $before, array $after, string $summary ): bool {
		if ( ! function_exists( 'd5b_history_write' ) ) { return false; }
		return d5b_history_write( array(
			'ability' => $ability, 'object_type' => 'comment', 'object_id' => (int) $c->comment_ID, 'action' => $action,
			'before' => $before, 'after' => $after, 'revision_id' => 0, 'summary' => $summary,
		) ) > 0;
	}

	/** The result: the comment row (a moderator's view) and what changed. */
	function d5b_cm_out( $c, bool $changed, bool $stored ): array {
		$out = d5b_rc_comment_row( $c, true ) + array( 'changed' => $changed );
		if ( $changed && ! $stored ) { $out['_history_failed'] = true; }
		return $out;
	}

	/** The five status actions: what each may start from, the WordPress call and the status that must result. */
	function d5b_cm_actions(): array {
		return array(
			'approve'   => array( 'from' => array( 'hold' ), 'same' => 'approved', 'want' => array( 'approved' ), 'verb' => 'Approved', 'action' => 'update' ),
			'unapprove' => array( 'from' => array( 'approved' ), 'same' => 'hold', 'want' => array( 'hold' ), 'verb' => 'Unapproved', 'action' => 'update' ),
			'spam'      => array( 'from' => array( 'approved', 'hold' ), 'same' => 'spam', 'want' => array( 'spam' ), 'verb' => 'Spammed', 'action' => 'update' ),
			'unspam'    => array( 'from' => array( 'spam' ), 'same' => null, 'want' => array( 'approved', 'hold' ), 'verb' => 'Unspammed', 'action' => 'update' ),
			'trash'     => array( 'from' => array( 'approved', 'hold', 'spam' ), 'same' => null, 'want' => array( 'trash' ), 'verb' => 'Trashed', 'action' => 'trash' ),
			'restore'   => array( 'from' => array( 'trash' ), 'same' => null, 'want' => array( 'approved', 'hold' ), 'verb' => 'Restored', 'action' => 'restore' ),
		);
	}

	/** @return array|WP_Error */
	function d5b_cm_moderate( string $what, array $in ) {
		$a = d5b_rc_args( $in, array( 'id' => 'id' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$c = d5b_cm_comment( $a['id'] );
		if ( null === $c ) { return d5b_cm_deny( $a['id'] ); }
		$plan = d5b_cm_actions()[ $what ];
		$st   = d5b_rc_comment_status( $c );
		// Already where the action leads: nothing to do (approve, unapprove, spam), or an unspam of a comment that is not spam.
		if ( $st === $plan['same'] || ( 'unspam' === $what && in_array( $st, array( 'approved', 'hold' ), true ) ) ) { return d5b_cm_out( $c, false, true ); }
		if ( ! in_array( $st, $plan['from'], true ) ) {
			if ( 'trash' === $what && 'trash' === $st ) { return new WP_Error( 'cannot_trash', 'This comment is already in the trash.' ); }
			if ( 'restore' === $what ) { return new WP_Error( 'not_found', 'This comment is not in the trash.' ); }
			$hint = 'trash' === $st ? ' It is in the trash: restore it first (divi5-builder/comments-restore).' : ( 'spam' === $st ? ' It is spam: mark it as not spam first (divi5-builder/comments-unspam).' : '' );
			return new WP_Error( 'invalid_input', 'This comment cannot be ' . strtolower( $plan['verb'] ) . ' from its present status (' . $st . ').' . $hint );
		}
		if ( 'trash' === $what && ! d5b_wc_trash_available() ) {
			return new WP_Error( 'cannot_trash', 'This site has no trash (EMPTY_TRASH_DAYS is 0), so WordPress would delete the comment for good. It is not trashed.' );
		}
		$before = d5b_cm_before( $c );
		$id     = (int) $c->comment_ID;
		switch ( $what ) {
			case 'approve':   $ok = wp_set_comment_status( $id, 'approve' ); break;
			case 'unapprove': $ok = wp_set_comment_status( $id, 'hold' ); break;
			case 'spam':      $ok = wp_spam_comment( $id ); break;
			case 'unspam':    $ok = wp_unspam_comment( $id ); break;
			case 'trash':     $ok = wp_trash_comment( $id ); break;
			default:          $ok = wp_untrash_comment( $id ); break;
		}
		$np = get_comment( $id );
		// Whatever WordPress did, a status that changed is recorded and reported as it is (a restore of a comment that was spam before
		// it was trashed goes back to spam); only a comment whose status did not change is a failure.
		if ( ! is_object( $np ) || d5b_rc_comment_status( $np ) === $st ) {
			return new WP_Error( 'write_failed', 'WordPress did not change the comment (a plugin may have stopped it).' );
		}
		$new   = d5b_rc_comment_status( $np );
		$where  = ' comment ' . $id . ' on post ' . (int) $np->comment_post_ID;
		$summary = 'spam' === $what ? 'Marked' . $where . ' as spam' : ( 'unspam' === $what ? 'Marked' . $where . ' as not spam' : $plan['verb'] . $where );
		// The summary is of the action asked for; when WordPress led somewhere else (a restore back to spam), it says where.
		if ( ! in_array( $new, $plan['want'], true ) ) { $summary .= ' (now ' . $new . ')'; }
		$stored =d5b_cm_history( 'divi5-builder/comments-' . $what, $np, $plan['action'], $before, array( 'status' => $new ), $summary );
		return d5b_cm_out( $np, true, $stored );
	}

	/** @return array|WP_Error */
	function d5b_cm_reply( array $in ) {
		$a = d5b_rc_args( $in, array( 'post_id' => 'id', 'parent' => 'id', 'content' => 'text:65525' ), array( 'post_id', 'content' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		if ( '' === trim( $a['content'] ) ) { return new WP_Error( 'invalid_input', 'content is required.' ); }
		$uid = (int) get_current_user_id();
		if ( $uid < 1 ) { return new WP_Error( 'not_allowed', 'You must be signed in to comment.' ); }
		// Strictest switch wins: the post's own category must be on.
		$p = d5b_rc_post( $a['post_id'], null );
		if ( null === $p ) { return d5b_rc_deny( $a['post_id'], null ); }
		$mod = current_user_can( 'moderate_comments' );
		if ( 'trash' === $p->post_status ) { return new WP_Error( 'invalid_input', 'This item is in the trash: comments cannot be added.' ); }
		// As core: a draft or other unpublished item takes comments only from someone who can edit it.
		if ( ! in_array( (string) $p->post_status, array( 'publish', 'private', 'inherit' ), true ) && ! current_user_can( 'edit_post', (int) $p->ID ) ) {
			return new WP_Error( 'not_allowed', 'Comments cannot be added to an item that is not published.' );
		}
		if ( ! $mod && ! comments_open( $p ) ) { return new WP_Error( 'not_allowed', 'Comments are closed on this item.' ); }
		$parent = 0;
		$pc     = null;
		if ( isset( $a['parent'] ) ) {
			$pc = d5b_cm_raw( $a['parent'] );
			$ps = null === $pc ? '' : d5b_rc_comment_status( $pc );
			if ( null === $pc || (int) $pc->comment_post_ID !== (int) $p->ID || ! ( 'approved' === $ps || ( 'hold' === $ps && $mod ) ) ) {
				return new WP_Error( 'invalid_input', 'parent must be an approved comment on the same post.' );
			}
			$parent = (int) $pc->comment_ID;
		}
		// The masked-read rule: text from a masked read holds [redacted]; quoting a parent that holds it itself is fine.
		$ok = d5b_wc_check_masked( $a['content'], null === $pc ? '' : (string) $pc->comment_content );
		if ( true !== $ok ) { return $ok; }
		$u = get_userdata( $uid );
		$data = array(
			'comment_post_ID' => (int) $p->ID, 'comment_content' => $a['content'], 'comment_parent' => $parent, 'user_id' => $uid,
			'comment_author' => (string) ( $u->display_name ?? '' ) !== '' ? (string) $u->display_name : (string) ( $u->user_login ?? '' ),
			'comment_author_email' => (string) ( $u->user_email ?? '' ), 'comment_author_url' => (string) ( $u->user_url ?? '' ),
		);
		$id = wp_new_comment( wp_slash( $data ), true );
		if ( is_wp_error( $id ) ) { return d5b_wc_core_error( $id ); }
		$c = (int) $id > 0 ? get_comment( (int) $id ) : null;
		if ( ! is_object( $c ) ) { return new WP_Error( 'write_failed', 'WordPress did not save the comment.' ); }
		$summary = ( $parent ? 'Replied to comment ' . $parent : 'Commented' ) . ' on post ' . (int) $p->ID . ' (comment ' . (int) $c->comment_ID . ')';
		// A reply WordPress (or an anti-spam plugin) put straight into spam or the trash says so.
		$cs = d5b_rc_comment_status( $c );
		if ( in_array( $cs, array( 'spam', 'trash' ), true ) ) { $summary .= ' (now ' . $cs . ')'; }
		$stored  = d5b_cm_history( 'divi5-builder/comments-reply', $c, 'create', null, d5b_cm_before( $c ), $summary );
		return d5b_cm_out( $c, true, $stored );
	}

	// ---- permissions (the handler checks again)

	function d5b_cm_can_moderate( array $in ): bool {
		return current_user_can( 'read' ) && null !== d5b_cm_comment( $in['id'] ?? 0 );
	}

	function d5b_cm_can_reply( array $in ): bool {
		if ( ! current_user_can( 'read' ) ) { return false; }
		$p = d5b_rc_post( $in['post_id'] ?? 0, null );
		return null !== $p && ( current_user_can( 'moderate_comments' ) || comments_open( $p ) );
	}

	// ---- handlers

	function d5b_cm_h_approve( array $in ) { return d5b_cm_moderate( 'approve', $in ); }
	function d5b_cm_h_unapprove( array $in ) { return d5b_cm_moderate( 'unapprove', $in ); }
	function d5b_cm_h_spam( array $in ) { return d5b_cm_moderate( 'spam', $in ); }
	function d5b_cm_h_unspam( array $in ) { return d5b_cm_moderate( 'unspam', $in ); }
	function d5b_cm_h_trash( array $in ) { return d5b_cm_moderate( 'trash', $in ); }
	function d5b_cm_h_restore( array $in ) { return d5b_cm_moderate( 'restore', $in ); }
	function d5b_cm_h_reply( array $in ) { return d5b_cm_reply( $in ); }

	// ---- the definitions

	$d5b_cm_id = array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The comment id (see comments-list).' ) );
	$d5b_cm_defs = array(
		'approve'   => array( 'Approve a comment', 'Approves a comment that is waiting (status hold) and returns it. A comment that is already approved is left as it is (changed false). Refuses spam and trashed comments (use comments-unspam or comments-restore), comments of other kinds than real comments, comments on posts you may not read or whose category is switched off, and callers without moderate_comments. Only the status changes.', array() ),
		'unapprove' => array( 'Unapprove a comment', 'Puts an approved comment back on hold (status hold) and returns it. A comment already on hold is left as it is (changed false). Refuses spam and trashed comments, other kinds than real comments, posts you may not read or whose category is switched off, and callers without moderate_comments. Only the status changes.', array() ),
		'spam'      => array( 'Mark a comment as spam', 'Marks an approved or waiting comment as spam and returns it; WordPress keeps the old status so comments-unspam gives it back. A comment already spam is left as it is (changed false). Refuses trashed comments, other kinds than real comments, posts you may not read or whose category is switched off, and callers without moderate_comments.', array() ),
		'unspam'    => array( 'Mark a comment as not spam', 'Takes a spam comment out of spam, back to the status it had before (approved or waiting, as WordPress remembers; waiting when it does not), and returns it. A comment that is not spam is left as it is (changed false); a trashed one is refused (use comments-restore). Refuses other kinds than real comments, posts you may not read or whose category is switched off, and callers without moderate_comments.', array() ),
		'trash'     => array( 'Trash a comment', 'Moves a comment to the trash (never deletes it) and returns it; comments-restore puts it back. Refuses a comment already in the trash, a site without a trash (EMPTY_TRASH_DAYS 0, where WordPress would delete it for good), other kinds than real comments, posts you may not read or whose category is switched off, and callers without moderate_comments. The change history keeps the whole comment.', array( 'destructive' => true ) ),
		'restore'   => array( 'Restore a comment', 'Takes a comment out of the trash, back to the status it had before it was trashed (approved or waiting, as WordPress remembers; waiting when it does not), and returns it. Refuses a comment that is not in the trash, other kinds than real comments, posts you may not read or whose category is switched off, and callers without moderate_comments.', array() ),
	);
	foreach ( $d5b_cm_defs as $d5b_cm_k => $d5b_cm_d ) {
		d5b_wc_def( 'comments-' . $d5b_cm_k, 'comments', $d5b_cm_d[0], $d5b_cm_d[1], $d5b_cm_id, array( 'id' ), 'd5b_cm_h_' . $d5b_cm_k, 'd5b_cm_can_moderate',
			array_merge( array( 'idempotent' => in_array( $d5b_cm_k, array( 'approve', 'unapprove' ), true ) ), $d5b_cm_d[2] ) );
	}
	d5b_wc_def( 'comments-reply', 'comments', 'Reply to a post or comment',
		'Posts a comment as the calling user, on a post or as a reply to an approved comment (parent) on the same post, and returns it. WordPress decides whether it is approved or held, as for any signed-in user: the post\'s author and moderators are approved at once, others follow the site\'s moderation settings. Refuses an empty text or one over 65525 characters, text holding [redacted] from a masked read (masked_read), a post you may not read, whose category is switched off, that is in the trash or not published (unless you can edit it), a post closed for comments (unless you can moderate comments), a parent that is not an approved comment on the same post, and a duplicate of a comment already posted.',
		array(
			'post_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the post, page or other item to comment on.' ),
			'parent'  => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of an approved comment on the same post to reply to. Leave out for a new top-level comment.' ),
			'content' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 65525, 'description' => 'The text of the comment, 1 to 65525 characters.' ),
		), array( 'post_id', 'content' ), 'd5b_cm_h_reply', 'd5b_cm_can_reply', array() );
	unset( $d5b_cm_id, $d5b_cm_defs, $d5b_cm_k, $d5b_cm_d );
}
