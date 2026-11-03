<?php
/**
 * Write ability for email (#32, release 2.4.0, Task 7): email-send-test, the only ability of the email category.
 *
 * This file only defines functions. The definition is added to the registry when the file is included (register.php
 * collects it); nothing is registered with WordPress from here.
 *
 * Rules (design 2026-11-02, "Abilities > Email", guardrails 24 and 25):
 *  - A normal write (no risky switch: the category switch is the gate), manage_options.
 *  - Recipients only people the site already knows: to is exactly "me" (the caller's own address), "admin" (admin_email) or
 *    "user:<id>" (an existing user of this site). Any other text, an address included, is invalid_input.
 *  - The subject and body are fixed: "Test email from <site name>"; the site address, the time (wp_date) and who asked, then
 *    the optional note (plain text, at most 500 characters, no secret: refused when the secret filter would change it).
 *  - wp_mail sends it; the wp_mail_failed message (message only) is caught for the answer { sent, mailer_error }.
 *  - Five tries per hour per site, a failed send counted too (the limit is taken before wp_mail runs).
 *  - One history row: object_type email, action send, after { to_kind, user_id (for user:), sent, mailer_error }. Never the
 *    address itself, never the note.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wem_send' ) ) {

	/** The permission callback: manage_options. */
	function d5b_wem_ok( array $in = array() ): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * The recipient: array( kind, address, user_id ) or a WP_Error. Only the three forms; a user must be a user of this site
	 * (on a network: a member of it, as users-get).
	 */
	function d5b_wem_recipient( $to ) {
		if ( ! is_string( $to ) || 1 !== preg_match( '/^(me|admin|user:[1-9][0-9]*)$/D', $to ) ) {
			return new WP_Error( 'invalid_input', 'to must be me, admin or user:<id> (an existing user). Email goes only to people the site knows.' );
		}
		if ( 'me' === $to ) {
			$u = get_userdata( (int) get_current_user_id() );
			$addr = is_object( $u ) ? (string) $u->user_email : '';
			return '' !== $addr && is_email( $addr ) ? array( 'me', $addr, 0 ) : new WP_Error( 'no_email', 'Your account has no valid email address.' );
		}
		if ( 'admin' === $to ) {
			$addr = (string) get_option( 'admin_email', '' );
			return '' !== $addr && is_email( $addr ) ? array( 'admin', $addr, 0 ) : new WP_Error( 'no_email', 'The site\'s admin email is not a valid address.' );
		}
		$id = (int) substr( $to, 5 );
		$u  = $id > 0 && strlen( $to ) <= 25 ? get_userdata( $id ) : false;
		if ( ! is_object( $u ) || ( is_multisite() && ! is_user_member_of_blog( $id ) ) ) { return new WP_Error( 'not_found', 'No user with that id.' ); }
		$addr = (string) $u->user_email;
		return '' !== $addr && is_email( $addr ) ? array( 'user', $addr, $id ) : new WP_Error( 'no_email', 'That user has no valid email address.' );
	}

	/** The note: '' when not given; plain text, at most 500 characters, no secret. @return string|WP_Error */
	function d5b_wem_note( $note ) {
		if ( null === $note || '' === $note ) { return ''; }
		if ( ! is_string( $note ) || 1 !== preg_match( '//u', $note ) ) { return new WP_Error( 'invalid_input', 'note must be text.' ); }
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $note, 'UTF-8' ) : strlen( $note );
		if ( $len > 500 ) { return new WP_Error( 'invalid_input', 'note must be at most 500 characters.' ); }
		if ( strip_tags( $note ) !== $note || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note ) ) {
			return new WP_Error( 'invalid_input', 'note must be plain text (no HTML, no control characters).' );
		}
		$clean = function_exists( 'd5b_ability_scrub_text_strict' ) ? d5b_ability_scrub_text_strict( $note ) : $note;
		if ( ! d5b_wc_secret_free( $note ) || $clean !== $note ) {
			return new WP_Error( 'invalid_input', 'note looks like it holds a secret (a password, key or token); secrets are never sent by an ability.' );
		}
		return $note;
	}

	/** @return array|WP_Error { sent, mailer_error } */
	function d5b_wem_send( array $in ) {
		if ( ! d5b_wem_ok() ) { return new WP_Error( 'forbidden', 'You may not send a test email.' ); }
		foreach ( array_keys( $in ) as $k ) {
			if ( ! in_array( $k, array( 'to', 'note' ), true ) ) { return new WP_Error( 'invalid_input', 'Unknown input "' . substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ), 0, 100 ) . '". Allowed: to, note.' ); }
		}
		$rcpt = d5b_wem_recipient( $in['to'] ?? null );
		if ( is_wp_error( $rcpt ) ) { return $rcpt; }
		$note = d5b_wem_note( $in['note'] ?? null );
		if ( is_wp_error( $note ) ) { return $note; }
		// Taken before the send: a failed send counts too, so a broken mailer cannot be hammered.
		if ( ! d5b_maint_rate( 'email_test', 5, 3600 ) ) { return new WP_Error( 'too_soon', 'Five test emails were tried from this site in the last hour. Try again later.' ); }

		list( $kind, $addr, $uid ) = $rcpt;
		// The site name as WordPress decodes it for its own mail (only &amp;, &lt;, &gt; and quotes; never &#13;&#10;), and with
		// no CR, LF or NUL left: a site name can never add a header line, whatever mailer replaces wp_mail.
		$site = trim( (string) preg_replace( '/[\r\n\x00]+/', ' ', wp_specialchars_decode( (string) get_option( 'blogname', '' ), ENT_QUOTES ) ) );
		$me   = get_userdata( (int) get_current_user_id() );
		$who  = is_object( $me ) ? (string) $me->user_login : '';
		$body = 'This is a test email from ' . ( '' !== $site ? $site : 'your WordPress site' ) . ".\n\n"
			. 'Site: ' . home_url( '/' ) . "\n"
			. 'Time: ' . wp_date( 'j F Y H:i T' ) . "\n"
			. 'Asked for by: ' . $who . " (through the divi5-builder AI abilities)\n";
		if ( '' !== $note ) { $body .= "\nNote:\n" . $note . "\n"; }

		$error    = null;
		$listener = function ( $e ) use ( &$error ) {
			if ( is_wp_error( $e ) ) { $error = d5b_rc_cut( (string) $e->get_error_message(), 500 ); }
		};
		add_action( 'wp_mail_failed', $listener );
		try {
			$sent = (bool) wp_mail( $addr, 'Test email from ' . $site, $body );
		} finally {
			remove_action( 'wp_mail_failed', $listener );
		}
		if ( $sent ) { $error = null; }
		elseif ( null === $error ) { $error = 'wp_mail returned false without a reason.'; }
		// A mailer names the recipient in its errors ("The following recipients failed: ..."): the row never holds the address.
		if ( null !== $error ) { $error = str_ireplace( $addr, '[recipient]', $error ); }
		// An API mailer can echo its own key in the error; the history row keeps text as given, so the shapes go here.
		if ( null !== $error ) { $error = d5b_secret_mask_values( $error ); }

		$after = array( 'to_kind' => $kind );
		if ( 'user' === $kind ) { $after['user_id'] = $uid; }
		$after += array( 'sent' => $sent, 'mailer_error' => $error );
		$hid = d5b_wc_history_row( 'divi5-builder/email-send-test', 'email', $uid, 'send', null, $after,
			( $sent ? 'Sent a test email to ' : 'A test email failed to ' ) . ( 'user' === $kind ? 'user ' . $uid : ( 'me' === $kind ? 'the caller' : 'the admin address' ) ) );
		$out = array( 'sent' => $sent, 'mailer_error' => $error );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	function d5b_wem_h_send( array $in ) { return d5b_wem_send( $in ); }

	// ---- the definition

	d5b_wc_def( 'email-send-test', 'email', 'Send a test email',
		'Sends a fixed test email (subject "Test email from <site name>"; the site address, the time and who asked, then the optional note) to someone the site already knows: to is "me" (your own address), "admin" (the site\'s admin email) or "user:<id>" (an existing user of this site). Never to an address given here: anything else is refused with invalid_input; an unknown user is not_found; a recipient without a valid address is no_email (409); callers without manage_options are refused. note is optional plain text, at most 500 characters, with no secret in it. Returns sent (true or false) and mailer_error (the mailer\'s message when the send failed, else null): a failed send is an answer, not an error, so pass mailer_error on to the user. At most 5 tries per hour per site, failed ones included (too_soon). A normal write: needs the Email category and manage_options, no risky switch. One change-history row records who it went to (me, admin or the user id, never the address) and the outcome.',
		array(
			'to'   => array( 'type' => 'string', 'maxLength' => 25, 'description' => 'me, admin or user:<id>.' ),
			'note' => array( 'type' => 'string', 'maxLength' => 500, 'description' => 'Optional plain text added after the fixed text.' ),
		), array( 'to' ), 'd5b_wem_h_send', 'd5b_wem_ok', array() );
}
