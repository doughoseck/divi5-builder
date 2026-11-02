<?php
/**
 * Write abilities for users (#32, release 2.4.0, Task 6): users-create, users-update, users-password-reset, users-meta-set,
 * users-meta-delete and the two-step delete (users-delete-preview, users-delete), all in the users category.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here.
 *
 * Rules (design 2026-11-02, "Abilities > Users", guardrails 19, 20 and 24):
 *  - Every ability here is risky (the Users risky switch) except users-password-reset, a normal write: it only sends WordPress's
 *    own reset link and changes nothing by itself.
 *  - No password, ever: users-create passes wp_generate_password( 24, true, true ) straight into WordPress and never keeps it
 *    (not in a variable, the result, the audit args or the history row); WordPress then emails the user its own "set your
 *    password" link (wp_new_user_notification( id, null, 'user' )). A reset is retrieve_password(): the answer is { sent: true },
 *    never a key or a link.
 *  - A role is one of get_editable_roles() (so an editor never makes an administrator); a role change also needs promote_users.
 *  - Own account (guardrail 20): the caller never changes their own role or email, never deletes or resets themselves here.
 *  - Another administrator's email is never changed here (an email change plus a reset link would be an account takeover).
 *  - Last administrator (guardrail 19): no role change or delete leaves the site without an administrator, counted fresh at run
 *    time (count_users() queries every time), never from an earlier read.
 *  - User meta: the 2.2.0 key rules (ASCII names, case variants, protected and secret-named keys) plus a deny-list by
 *    construction: capabilities, user_level, session_tokens, any name ending _capabilities or _user_level, use_ssl,
 *    dismissed_wp_pointers (not_allowed).
 *  - A delete reassigns every item of the user to reassign_to (required, an existing user of the site, not the one deleted);
 *    comments stay as they are (WordPress does not reassign them). The history row is written BEFORE the delete with the user's
 *    record and the ids of the items that move; on a network the user is removed from this site (remove_user_from_blog).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wu_record' ) ) {

	// ---- shared

	/** core's admin user functions (wp_delete_user, get_editable_roles) are not loaded outside wp-admin, as the REST API knows. */
	function d5b_wu_admin_includes(): void {
		if ( ! function_exists( 'wp_delete_user' ) || ! function_exists( 'get_editable_roles' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
	}

	/** True when the current user has $cap. */
	function d5b_wu_ok( string $cap ): bool {
		return current_user_can( $cap );
	}

	/** The permission callback for $cap. */
	function d5b_wu_can( string $cap ): callable {
		return function ( array $in = array() ) use ( $cap ) { return d5b_wu_ok( $cap ); };
	}

	/** null when the Users risky switch is on, else the refusal (the gate checks it too). */
	function d5b_wu_risky( string $what ): ?WP_Error {
		return d5b_abilities_risky_on( 'users' ) ? null : new WP_Error( 'not_allowed', $what . ' is a risky action and the Users risky switch is off.' );
	}

	/** The user, when it exists (on a network: a member of this site, as users-get). @return object|WP_Error */
	function d5b_wu_user( $id ) {
		$id = (int) $id;
		$u  = $id > 0 ? get_userdata( $id ) : false;
		if ( ! is_object( $u ) || ( is_multisite() && ! is_user_member_of_blog( $id ) ) ) { return new WP_Error( 'not_found', 'No user with that id.' ); }
		return $u;
	}

	/** True when the user holds the administrator role on this site. */
	function d5b_wu_is_admin( $u ): bool {
		return in_array( 'administrator', array_map( 'strval', (array) ( $u->roles ?? array() ) ), true );
	}

	/** The administrators of this site NOW: count_users() runs its query on every call; never a number from an earlier read. */
	function d5b_wu_admin_count(): int {
		$c = count_users();
		return (int) ( $c['avail_roles']['administrator'] ?? 0 );
	}

	function d5b_wu_last_admin_error( string $what ): WP_Error {
		return new WP_Error( 'last_admin', 'This is the last administrator of the site: ' . $what . ' would leave the site without one. Make another user an administrator first.' );
	}

	function d5b_wu_own_error( string $what ): WP_Error {
		return new WP_Error( 'own_account', 'You cannot ' . $what . ' through the abilities. Use your own profile screen in wp-admin (or ask another administrator).' );
	}

	/**
	 * True for a user whose email only they themselves change here: a super admin, or anyone holding power over users or the
	 * site (promote_users, edit_users, manage_options, delete_users), whatever their role is called. An email change plus a reset
	 * link would hand their account over.
	 */
	function d5b_wu_protected( int $id ): bool {
		if ( is_multisite() && is_super_admin( $id ) ) { return true; }
		foreach ( array( 'promote_users', 'edit_users', 'manage_options', 'delete_users' ) as $cap ) {
			if ( user_can( $id, $cap ) ) { return true; }
		}
		return false;
	}

	/** Gives the user exactly $roles again (WP_User::set_role / add_role). True when the user now holds them. */
	function d5b_wu_restore_roles( int $id, array $roles ): bool {
		$u = get_userdata( $id );
		if ( ! is_object( $u ) || ! method_exists( $u, 'set_role' ) || ! method_exists( $u, 'add_role' ) ) { return false; }
		$u->set_role( '' );
		foreach ( $roles as $r ) { $u->add_role( (string) $r ); }
		$now  = get_userdata( $id );
		$have = is_object( $now ) ? array_map( 'strval', (array) ( $now->roles ?? array() ) ) : array();
		sort( $have ); $want = array_map( 'strval', $roles ); sort( $want );
		return $have === $want;
	}

	/**
	 * The marker of an identity change: a protected user meta (underscore, so users-meta-set/-delete can never touch it) holding
	 * the UTC unix time of the last email or role change made through the abilities. Written BEFORE the change.
	 */
	function d5b_wu_marker_key(): string {
		return '_d5b_identity_changed_at';
	}

	function d5b_wu_now(): int {
		return function_exists( 'd5b_maint_now' ) ? (int) d5b_maint_now() : time();
	}

	/** Writes the marker (now) on the user and reads it back. @return true|WP_Error unavailable when it did not stick */
	function d5b_wu_mark_identity_change( int $id ) {
		$now = d5b_wu_now();
		update_user_meta( $id, d5b_wu_marker_key(), (string) $now );
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( $id, 'user_meta' ); }
		$rows = get_user_meta( $id, d5b_wu_marker_key(), false );
		if ( is_array( $rows ) && 1 === count( $rows ) && (int) $rows[0] === $now ) { return true; }
		return new WP_Error( 'unavailable', 'The plugin could not record this email or role change on the user (its reset-link guard), so nothing was changed. Try again later.' );
	}

	/**
	 * A reset link is refused for 24 hours after the user's email or role was changed through the abilities: a changed address
	 * plus a reset link is an account takeover in two steps. Read fresh from the user's own marker (no history lookup, no row
	 * limit to flood). A marker that cannot be read refuses the reset. @return true|WP_Error
	 */
	function d5b_wu_recently_changed( int $id ) {
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( $id, 'user_meta' ); }
		$rows = get_user_meta( $id, d5b_wu_marker_key(), false );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'unavailable', 'It could not be read whether this user\'s email or role changed recently, so no reset link was sent. Try again later.' );
		}
		foreach ( $rows as $t ) {
			if ( d5b_wu_now() - (int) $t < 86400 ) {
				return new WP_Error( 'recently_changed', 'This user\'s email address or role was changed through the abilities in the last 24 hours, so no reset link is sent through them now. Send it from wp-admin if it is wanted.' );
			}
		}
		return true;
	}

	/** True for a user meta name no ability writes (compared without case, as the database compares names). */
	function d5b_wu_meta_denied( string $key ): bool {
		$k = strtolower( $key );
		if ( in_array( $k, array( 'capabilities', 'user_level', 'session_tokens', 'use_ssl', 'dismissed_wp_pointers' ), true ) ) { return true; }
		return 1 === preg_match( '/(_capabilities|_user_level)$/D', $k );
	}

	/**
	 * The user's record as the history keeps it and the answers show it: login, email, names, url, description, roles,
	 * registration date and every other meta row that is not protected, not secret-named and not on the deny-list (key =>
	 * rows, d5b_wc_meta_value). Never a password, a key or a session.
	 */
	function d5b_wu_record( int $id ): array {
		$u = get_userdata( $id );
		if ( ! is_object( $u ) ) { return array(); }
		$meta = array();
		$all  = get_user_meta( $id );
		foreach ( is_array( $all ) ? array_keys( $all ) : array() as $k ) {
			$k = (string) $k;
			if ( in_array( $k, array( 'first_name', 'last_name', 'description' ), true ) || is_protected_meta( $k, 'user' ) || d5b_is_secret_key( $k ) || d5b_wu_meta_denied( $k ) ) { continue; }
			$meta[ $k ] = array_map( 'd5b_wc_meta_value', array_values( (array) get_user_meta( $id, $k, false ) ) );
		}
		ksort( $meta );
		return array(
			'id' => (int) $u->ID, 'login' => (string) $u->user_login, 'email' => (string) $u->user_email, 'display_name' => (string) $u->display_name,
			'first_name' => (string) get_user_meta( $id, 'first_name', true ), 'last_name' => (string) get_user_meta( $id, 'last_name', true ),
			'url' => (string) ( $u->user_url ?? '' ), 'description' => (string) get_user_meta( $id, 'description', true ),
			'roles' => array_values( array_map( 'strval', (array) ( $u->roles ?? array() ) ) ), 'registered' => (string) ( $u->user_registered ?? '' ),
			'meta' => $meta,
		);
	}

	/** "user 12 'anna'". */
	function d5b_wu_label( $u ): string {
		return 'user ' . (int) $u->ID . " '" . d5b_rc_cut( (string) $u->user_login, 60 ) . "'";
	}

	/** A WP_Error of core's user functions in the plugin's codes: a login or email in use is exists, a database error write_failed. */
	function d5b_wu_core_error( WP_Error $e ): WP_Error {
		if ( in_array( $e->get_error_code(), array( 'existing_user_login', 'existing_user_email' ), true ) ) { return new WP_Error( 'exists', (string) $e->get_error_message() ); }
		return d5b_wc_core_error( $e );
	}

	/** The role, when the caller may give it (one of get_editable_roles()). @return true|WP_Error */
	function d5b_wu_role_ok( string $role ) {
		d5b_wu_admin_includes();
		$roles = (array) get_editable_roles();
		if ( array_key_exists( $role, $roles ) ) { return true; }
		return new WP_Error( 'not_allowed', 'You may not give the role "' . d5b_rc_cut( $role, 60 ) . '". Roles you can give: ' . implode( ', ', array_map( 'strval', array_keys( $roles ) ) ) . '.' );
	}

	/** The masked-read check for every text field given. @return true|WP_Error */
	function d5b_wu_masked( array $fields, array $stored ) {
		foreach ( $fields as $k => $v ) {
			$ok = d5b_wc_check_masked( (string) $v, (string) ( $stored[ $k ] ?? '' ) );
			if ( true !== $ok ) { return $ok; }
		}
		return true;
	}

	// ---- users-create

	/** @return array|WP_Error */
	function d5b_wu_create( array $in ) {
		if ( ! d5b_wu_ok( 'create_users' ) ) { return new WP_Error( 'forbidden', 'You may not create users.' ); }
		$a = d5b_rc_args( $in, array( 'login' => 'key', 'email' => 'key', 'role' => 'slug', 'display_name' => 'text:250', 'first_name' => 'text:250', 'last_name' => 'text:250' ), array( 'login', 'email', 'role' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$e = d5b_wu_risky( 'Creating a user' ); if ( $e ) { return $e; }
		$login = (string) $a['login'];
		if ( sanitize_user( $login, true ) !== $login || '' === trim( $login ) || strlen( $login ) > 60 ) {
			return new WP_Error( 'invalid_input', 'login may hold only letters, digits, spaces and _ . - @ (at most 60), with no space at either end.' );
		}
		if ( is_multisite() && 1 !== preg_match( '/^[a-z0-9]{4,60}$/D', $login ) ) {
			// As core's network sign-up check (wpmu_validate_user_signup): lowercase letters and digits, at least 4.
			return new WP_Error( 'invalid_input', 'On a network a login is lowercase letters (a-z) and digits only, at least 4 of them.' );
		}
		$email = trim( (string) $a['email'] );
		if ( ! is_email( $email ) ) { return new WP_Error( 'invalid_input', 'email is not a valid email address.' ); }
		$role = (string) $a['role'];
		$ok = d5b_wu_role_ok( $role ); if ( true !== $ok ) { return $ok; }
		// As core's edit_user() for a new user: without promote_users the user gets the default role, so any other role needs it.
		$default = (string) get_option( 'default_role', 'subscriber' );
		if ( $role !== $default && ! current_user_can( 'promote_users' ) ) {
			return new WP_Error( 'not_allowed', 'Giving a new user a role other than the default ("' . d5b_rc_cut( $default, 60 ) . '") needs promote_users.' );
		}
		$names = array_intersect_key( $a, array( 'display_name' => 1, 'first_name' => 1, 'last_name' => 1 ) );
		$ok = d5b_wu_masked( $names, array() ); if ( true !== $ok ) { return $ok; }
		if ( username_exists( $login ) ) { return new WP_Error( 'exists', 'A user with that login already exists.' ); }
		if ( email_exists( $email ) ) { return new WP_Error( 'exists', 'A user with that email address already exists.' ); }

		// The password: made and handed to WordPress in the same expression, never kept. Nobody knows it; the user sets their own.
		if ( is_multisite() ) {
			// As core on a network: the user is created for the network, then added to this site with the role.
			$id = wpmu_create_user( $login, wp_generate_password( 24, true, true ), $email );
			if ( ! $id ) { return new WP_Error( 'write_failed', 'WordPress did not create the user.' ); }
			$id = (int) $id;
			$r  = add_user_to_blog( get_current_blog_id(), $id, $role );
			if ( is_wp_error( $r ) ) { return new WP_Error( 'write_failed', 'The user ' . $id . ' was created for the network but not added to this site: ' . d5b_maint_err_text( $r ) . ' Add them in wp-admin (Users > Add New, existing user).' ); }
			if ( array() !== $names ) {
				$r = wp_update_user( wp_slash( array( 'ID' => $id ) + $names ) );
				if ( is_wp_error( $r ) ) { return d5b_wu_core_error( $r ); }
			}
		} else {
			$id = wp_insert_user( wp_slash( array( 'user_login' => $login, 'user_email' => $email, 'role' => $role ) + $names + array( 'user_pass' => wp_generate_password( 24, true, true ) ) ) );
			if ( is_wp_error( $id ) ) { return d5b_wu_core_error( $id ); }
			$id = (int) $id;
		}
		if ( $id < 1 || ! is_object( get_userdata( $id ) ) ) { return new WP_Error( 'write_failed', 'WordPress did not create the user.' ); }
		// WordPress's own email with the link to set a password (the admin is not told: the caller asked for it).
		wp_new_user_notification( $id, null, 'user' );
		$rec = d5b_wu_record( $id );
		$hid = d5b_wc_history_row( 'divi5-builder/users-create', 'user', $id, 'create', null, $rec, 'Created user ' . $id . " '" . d5b_rc_cut( $login, 60 ) . "' (" . $role . ')' );
		$out = $rec + array( 'notified' => true, 'note' => 'WordPress emailed the user its own link to set a password. No password is set or shown here.' );
		if ( $hid > 0 ) { $out['history_id'] = $hid; } else { $out['_history_failed'] = true; }
		return $out;
	}

	// ---- users-update

	/** @return array|WP_Error */
	function d5b_wu_update( array $in ) {
		if ( ! d5b_wu_ok( 'edit_users' ) ) { return new WP_Error( 'forbidden', 'You may not edit users.' ); }
		$a = d5b_rc_args( $in, array( 'id' => 'id', 'display_name' => 'text:250', 'first_name' => 'text:250', 'last_name' => 'text:250', 'url' => 'text:200', 'description' => 'text:10000', 'email' => 'key', 'role' => 'slug' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$e = d5b_wu_risky( 'Changing a user' ); if ( $e ) { return $e; }
		$u = d5b_wu_user( $a['id'] );
		if ( is_wp_error( $u ) ) { return $u; }
		$id = (int) $u->ID;
		if ( ! current_user_can( 'edit_user', $id ) ) { return new WP_Error( 'not_allowed', 'You may not edit this user.' ); }
		$changes = array_intersect_key( $a, array( 'display_name' => 1, 'first_name' => 1, 'last_name' => 1, 'url' => 1, 'description' => 1, 'email' => 1, 'role' => 1 ) );
		if ( array() === $changes ) { return new WP_Error( 'invalid_input', 'Nothing to change: give at least one of display_name, first_name, last_name, url, description, email, role.' ); }
		$self = $id === (int) get_current_user_id();

		if ( isset( $changes['role'] ) ) {
			$role = (string) $changes['role'];
			if ( $self ) { return d5b_wu_own_error( 'change your own role' ); }
			if ( ! current_user_can( 'promote_users' ) || ! current_user_can( 'promote_user', $id ) ) { return new WP_Error( 'not_allowed', 'You may not change the role of users (promote_users).' ); }
			$ok = d5b_wu_role_ok( $role ); if ( true !== $ok ) { return $ok; }
			if ( 'administrator' !== $role && d5b_wu_is_admin( $u ) && d5b_wu_admin_count() <= 1 ) { return d5b_wu_last_admin_error( 'taking the administrator role away' ); }
		}
		if ( isset( $changes['email'] ) ) {
			$email = trim( (string) $changes['email'] );
			if ( ! is_email( $email ) ) { return new WP_Error( 'invalid_input', 'email is not a valid email address.' ); }
			if ( $email === (string) $u->user_email ) {
				unset( $changes['email'] );
			} else {
				if ( $self ) { return d5b_wu_own_error( 'change your own email address' ); }
				if ( d5b_wu_protected( $id ) ) {
					return new WP_Error( 'other_admin_email', 'This user is another administrator or user manager: their email address is changed only by themselves, on their own profile screen.' );
				}
				$owner = email_exists( $email );
				if ( $owner && (int) $owner !== $id ) { return new WP_Error( 'exists', 'Another user already has that email address.' ); }
				$changes['email'] = $email;
			}
		}
		if ( isset( $changes['url'] ) ) {
			$url = esc_url_raw( (string) $changes['url'] );
			// esc_url_raw keeps ftp:, mailto: and other schemes too; a profile website is http or https only.
			if ( '' !== trim( (string) $changes['url'] ) && 1 !== preg_match( '#^https?://#i', $url ) ) { return new WP_Error( 'invalid_input', 'url must be a web address (http or https).' ); }
			$changes['url'] = $url;
		}
		$before = d5b_wu_record( $id );
		$ok = d5b_wu_masked( array_intersect_key( $changes, array( 'display_name' => 1, 'first_name' => 1, 'last_name' => 1, 'description' => 1 ) ), $before );
		if ( true !== $ok ) { return $ok; }

		$map  = array( 'display_name' => 'display_name', 'first_name' => 'first_name', 'last_name' => 'last_name', 'url' => 'user_url', 'description' => 'description', 'email' => 'user_email', 'role' => 'role' );
		$data = array( 'ID' => $id );
		foreach ( $changes as $k => $v ) { $data[ $map[ $k ] ] = $v; }
		// core unslashes; an email change sends core's own notice to the OLD address (wp_update_user), on purpose not suppressed.
		$roles_before = array_values( array_map( 'strval', (array) ( $u->roles ?? array() ) ) );
		// An email or role change marks the user FIRST (the reset-link guard reads it); no marker, no change.
		if ( isset( $changes['email'] ) || isset( $changes['role'] ) ) {
			$ok = d5b_wu_mark_identity_change( $id );
			if ( true !== $ok ) { return $ok; }
		}
		$r = wp_update_user( wp_slash( $data ) );
		if ( is_wp_error( $r ) ) { return d5b_wu_core_error( $r ); }
		// The last-administrator check above ran before the write; another request may have demoted the other administrator in
		// between. Counted again now: a site left with none gets the role back and the change is refused.
		$undo = null; $undone = false;
		if ( isset( $changes['role'] ) && in_array( 'administrator', $roles_before, true ) && 'administrator' !== $changes['role'] && d5b_wu_admin_count() < 1 ) {
			// Putting the role back is a role change too: marked again first. Its failure does not stop the restore (the marker
			// written before the change is there already, and a site without an administrator is the worse outcome).
			d5b_wu_mark_identity_change( $id );
			if ( d5b_wu_restore_roles( $id, $roles_before ) ) { $undone = true; }
			else { $undo = 'The site now has no administrator and the role could not be put back: make a user an administrator in wp-admin or with WP-CLI now.'; }
		}
		if ( $undone ) {
			// The role is back; any other field given in the same call did change, and is recorded.
			$now  = d5b_wu_record( $id );
			$data = null;
			if ( $now !== $before ) {
				$hid  = d5b_wc_history_row( 'divi5-builder/users-update', 'user', $id, 'update', $before, d5b_wc_diff( $before, $now ), 'Updated ' . d5b_wu_label( $u ) . ' (role change undone)' );
				$data = $hid > 0 ? array( 'history_id' => $hid ) : array( '_history_failed' => true );
			}
			$e = d5b_wu_last_admin_error( 'taking the administrator role away (another change made at the same time took the other one; the role was put back)' );
			return null === $data ? $e : new WP_Error( 'last_admin', $e->get_error_message() . ' The other fields given were changed' . ( isset( $data['history_id'] ) ? '; history row ' . $data['history_id'] . ' holds the previous values.' : '; the history row could not be written.' ), $data );
		}
		$after = d5b_wu_record( $id );
		$hid   = d5b_wc_history_row( 'divi5-builder/users-update', 'user', $id, 'update', $before, d5b_wc_diff( $before, $after ), 'Updated ' . d5b_wu_label( $u ) );
		$out   = $after;
		if ( isset( $changes['email'] ) ) { $out['note'] = 'WordPress sent its own notice of the email change to the old address.'; }
		if ( $hid > 0 ) { $out['history_id'] = $hid; } else { $out['_history_failed'] = true; }
		if ( null !== $undo ) { return new WP_Error( 'last_admin', $undo, $out ); }
		return $out;
	}

	// ---- users-password-reset (a normal write)

	/** @return array|WP_Error */
	function d5b_wu_reset( array $in ) {
		if ( ! d5b_wu_ok( 'edit_users' ) ) { return new WP_Error( 'forbidden', 'You may not send password reset links.' ); }
		$a = d5b_rc_args( $in, array( 'id' => 'id' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$u = d5b_wu_user( $a['id'] );
		if ( is_wp_error( $u ) ) { return $u; }
		$id = (int) $u->ID;
		if ( $id === (int) get_current_user_id() ) { return d5b_wu_own_error( 'reset your own password' ); }
		if ( ! current_user_can( 'edit_user', $id ) ) { return new WP_Error( 'not_allowed', 'You may not send this user a password reset link.' ); }
		$recent = d5b_wu_recently_changed( $id );
		if ( true !== $recent ) { return $recent; }
		// The site limit first: a site-wide refusal must not spend this user's slot.
		if ( ! d5b_maint_rate( 'reset_site', 20, 3600 ) ) { return new WP_Error( 'too_soon', 'Twenty reset links went out from this site in the last hour. Try again later.' ); }
		if ( ! d5b_maint_rate( 'reset_user_' . $id, 1, 600 ) ) { return new WP_Error( 'too_soon', 'A reset link went to this user less than 10 minutes ago. Try again later.' ); }
		$login = (string) $u->user_login;
		// core's retrieve_password looks a login holding an @ up as an email first: then the user's own email finds exactly them.
		$r = retrieve_password( wp_slash( strpos( $login, '@' ) ? (string) $u->user_email : $login ) );
		if ( is_wp_error( $r ) ) {
			if ( 'no_password_reset' === $r->get_error_code() ) { return new WP_Error( 'not_allowed', 'WordPress does not allow a password reset for this user.' ); }
			return new WP_Error( 'unavailable', 'WordPress did not send the reset link: ' . d5b_maint_err_text( $r ) );
		}
		$hid = d5b_wc_history_row( 'divi5-builder/users-password-reset', 'user', $id, 'password_reset', null, array( 'sent' => true ), 'Sent a password reset link to ' . d5b_wu_label( $u ) );
		$out = array( 'sent' => true );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	// ---- user meta

	/**
	 * As d5b_wc_meta_key_case_ok, for the user's meta: the database compares meta names without case, so a stored name that
	 * differs from $key only by case (or accents) is refused, naming it; both existing at once: any write to either is refused.
	 * @return true|WP_Error
	 */
	function d5b_wu_meta_key_case_ok( int $user_id, string $key ) {
		$fold = d5b_wc_meta_key_fold( $key );
		$same = false; $others = array();
		foreach ( array_keys( (array) get_user_meta( $user_id ) ) as $k ) {
			$k = (string) $k;
			if ( $k === $key ) { $same = true; continue; }
			if ( d5b_wc_meta_key_fold( $k ) === $fold ) { $others[] = $k; }
		}
		if ( array() === $others ) { return true; }
		$cut = function ( $k ) { return "'" . d5b_rc_cut( $k, 100 ) . "'"; };
		if ( ! $same && 1 === count( $others ) ) {
			return new WP_Error( 'invalid_input', 'a field named ' . $cut( $others[0] ) . ' already exists on this user; use that exact name' );
		}
		$names = $same ? array_merge( array( $key ), $others ) : $others;
		return new WP_Error( 'invalid_input', 'fields named ' . implode( ' and ', array_map( $cut, $names ) ) . ' already exist on this user and differ only by case; the database treats them as one name, so none of them is changed here. Change them in wp-admin.' );
	}

	/** The user whose meta $key may be changed. @return object|WP_Error */
	function d5b_wu_meta_target( $id, string $key ) {
		$ok = d5b_wc_meta_key_ok( $key );
		if ( true !== $ok ) { return $ok; }
		if ( d5b_wu_meta_denied( $key ) ) { return new WP_Error( 'not_allowed', 'This field holds roles, capabilities, sessions or a core flag of the user; it is never changed here (roles: users-update).' ); }
		if ( is_protected_meta( $key, 'user' ) ) { return new WP_Error( 'not_allowed', 'This field is protected (its name starts with an underscore): it belongs to WordPress or a plugin and is not changed here.' ); }
		if ( d5b_is_secret_key( $key ) ) { return new WP_Error( 'not_allowed', 'This field name looks like it holds a secret (a key, token or password), so it is not changed here. Edit it in wp-admin.' ); }
		$u = d5b_wu_user( $id );
		if ( is_wp_error( $u ) ) { return $u; }
		if ( ! current_user_can( 'edit_user', (int) $u->ID ) ) { return new WP_Error( 'not_allowed', 'You may not edit this user.' ); }
		$ok = d5b_wu_meta_key_case_ok( (int) $u->ID, $key );
		if ( true !== $ok ) { return $ok; }
		return $u;
	}

	function d5b_wu_meta_rows( int $id, string $key ): array {
		return array_map( 'd5b_wc_meta_value', array_values( (array) get_user_meta( $id, $key, false ) ) );
	}

	function d5b_wu_meta_summary( string $verb, string $key, $u ): string {
		return $verb . " field '" . d5b_rc_cut( $key, 60 ) . "' of " . d5b_wu_label( $u );
	}

	/** @return array|WP_Error */
	function d5b_wu_meta_set( array $in ) {
		if ( ! d5b_wu_ok( 'edit_users' ) ) { return new WP_Error( 'forbidden', 'You may not edit users.' ); }
		$has   = array_key_exists( 'value', $in );
		$value = $in['value'] ?? null;
		unset( $in['value'] );
		$a = d5b_rc_args( $in, array( 'id' => 'id', 'key' => 'key' ), array( 'id', 'key' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$e = d5b_wu_risky( 'Changing a user field' ); if ( $e ) { return $e; }
		if ( ! $has || null === $value ) { return new WP_Error( 'invalid_input', 'value is required (use divi5-builder/users-meta-delete to remove a field).' ); }
		if ( ! d5b_wt_plain_value( $value ) || ! d5b_wc_json_safe( $value ) ) {
			return new WP_Error( 'invalid_input', 'value must be text, a number, true/false, or a list or object of those (at most 8 levels deep, UTF-8). Other objects are refused.' );
		}
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : json_encode( $value );
		if ( ! is_string( $json ) || strlen( $json ) > 1048576 ) { return new WP_Error( 'invalid_input', 'value is too large: at most 1 MB.' ); }
		$key = (string) $a['key'];
		$u   = d5b_wu_meta_target( $a['id'], $key );
		if ( is_wp_error( $u ) ) { return $u; }
		$id   = (int) $u->ID;
		$rows = (array) get_user_meta( $id, $key, false );
		if ( ! d5b_wc_secret_free( array_values( $rows ) ) ) { return d5b_wc_secret_error( 'field' ); }
		$stored = json_encode( d5b_rc_meta_clean( array_values( $rows ) ) );
		$ok = d5b_wc_check_masked( $json, is_string( $stored ) ? $stored : '' );
		if ( true !== $ok ) { return $ok; }
		$before = d5b_wu_meta_rows( $id, $key );
		// Every row of the name ends as this one value (core's update would set each row of a repeated name to it).
		if ( count( $rows ) > 1 ) {
			delete_user_meta( $id, wp_slash( $key ) );
			add_user_meta( $id, wp_slash( $key ), wp_slash( $value ) );
		} else {
			update_user_meta( $id, wp_slash( $key ), wp_slash( $value ) );
		}
		$now = (array) get_user_meta( $id, $key, false );
		if ( 1 !== count( $now ) || d5b_wc_meta_value( $now[0] ) !== d5b_wc_meta_value( $value ) ) {
			$read = d5b_wu_meta_rows( $id, $key );
			$data = null;
			if ( $read !== $before ) {
				$hid  = d5b_wc_history_row( 'divi5-builder/users-meta-set', 'user_meta', $id, array() === $before ? 'create' : 'update',
					array( 'field' => $key, 'rows' => $before ), array( 'field' => $key, 'value' => d5b_wc_meta_value( $value ), 'readback' => $read ), d5b_wu_meta_summary( 'Partly set', $key, $u ) );
				$data = $hid > 0 ? array( 'history_id' => $hid ) : array( '_history_failed' => true );
			}
			return new WP_Error( 'write_failed', 'WordPress did not save the field as asked (a plugin may have stopped or changed it).' . ( null === $data ? '' : ' The field did change: ' . ( isset( $data['history_id'] ) ? 'history row ' . $data['history_id'] . ' holds the previous rows.' : 'the history row could not be written.' ) ), $data );
		}
		$hid = d5b_wc_history_row( 'divi5-builder/users-meta-set', 'user_meta', $id, array() === $before ? 'create' : 'update',
			array( 'field' => $key, 'rows' => $before ), array( 'field' => $key, 'value' => d5b_wc_meta_value( $value ) ), d5b_wu_meta_summary( 'Set', $key, $u ) );
		$out = array( 'user_id' => $id, 'field' => $key, 'value' => d5b_rc_meta_clean( $now[0] ), 'replaced_rows' => count( $before ) );
		if ( $hid > 0 ) { $out['history_id'] = $hid; } else { $out['_history_failed'] = true; }
		return $out;
	}

	/** @return array|WP_Error */
	function d5b_wu_meta_delete( array $in ) {
		if ( ! d5b_wu_ok( 'edit_users' ) ) { return new WP_Error( 'forbidden', 'You may not edit users.' ); }
		$a = d5b_rc_args( $in, array( 'id' => 'id', 'key' => 'key' ), array( 'id', 'key' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$e = d5b_wu_risky( 'Deleting a user field' ); if ( $e ) { return $e; }
		$key = (string) $a['key'];
		$u   = d5b_wu_meta_target( $a['id'], $key );
		if ( is_wp_error( $u ) ) { return $u; }
		$id   = (int) $u->ID;
		$rows = d5b_wu_meta_rows( $id, $key );
		if ( array() === $rows ) { return new WP_Error( 'not_found', 'This user has no field with that name.' ); }
		if ( ! d5b_wc_secret_free( array_values( (array) get_user_meta( $id, $key, false ) ) ) ) { return d5b_wc_secret_error( 'field' ); }
		// The history row comes FIRST, with every row of the name.
		$hid = d5b_wc_history_row( 'divi5-builder/users-meta-delete', 'user_meta', $id, 'delete', array( 'field' => $key, 'rows' => $rows ), array( 'field' => $key, 'deleted' => true ), d5b_wu_meta_summary( 'Deleted', $key, $u ) );
		if ( $hid < 1 ) { return d5b_wt_history_unavailable(); }
		$ok = d5b_wc_delete_record_ok( $hid );
		if ( true !== $ok ) { return $ok; }
		$r = delete_user_meta( $id, wp_slash( $key ) );
		if ( ! $r || array() !== (array) get_user_meta( $id, $key, false ) ) {
			$fid = d5b_wc_history_row( 'divi5-builder/users-meta-delete', 'user_meta', $id, 'delete_failed', null, array( 'field' => $key, 'deleted' => false, 'error' => $r ? 'still_present' : 'delete_user_meta_failed', 'history_id' => $hid ), d5b_wu_meta_summary( 'Failed to delete', $key, $u ) );
			return new WP_Error( 'write_failed', 'WordPress did not delete the field (a plugin may have stopped it). History row ' . $hid . ' holds every value' . ( $fid > 0 ? '; row ' . $fid . ' records the failure.' : '.' ), array( 'history_id' => $hid ) );
		}
		return array( 'deleted' => true, 'user_id' => $id, 'field' => $key, 'rows_deleted' => count( $rows ), 'history_id' => $hid );
	}

	// ---- the two-step delete

	/** The ids of every item (any type, any status) of the user, ascending: core's own query in wp_delete_user. */
	function d5b_wu_post_ids( int $id ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $id ) );
		$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		sort( $ids );
		return $ids;
	}

	/** The user's items counted by type: type => count. */
	function d5b_wu_post_counts( int $id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_author = %d GROUP BY post_type", $id ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) { $r = (array) $r; $out[ (string) $r['post_type'] ] = (int) $r['n']; }
		ksort( $out );
		return $out;
	}

	/** The comments the user wrote (every status). */
	function d5b_wu_comment_count( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d", $id ) );
	}

	/** The user to delete and the one who gets their items. @return array|WP_Error array( user, reassign_to id ) */
	function d5b_wu_delete_target( array $a ) {
		$e = d5b_wu_risky( 'Deleting a user' ); if ( $e ) { return $e; }
		$u = d5b_wu_user( $a['id'] );
		if ( is_wp_error( $u ) ) { return $u; }
		$id = (int) $u->ID;
		if ( $id === (int) get_current_user_id() ) { return d5b_wu_own_error( 'delete your own account' ); }
		if ( ! current_user_can( 'delete_user', $id ) ) { return new WP_Error( 'not_allowed', 'You may not delete this user.' ); }
		$to = (int) $a['reassign_to'];
		if ( $to === $id ) { return new WP_Error( 'invalid_input', 'reassign_to must be another user: the one who gets the deleted user\'s content.' ); }
		$t = get_userdata( $to );
		if ( ! is_object( $t ) || ( is_multisite() && ! is_user_member_of_blog( $to ) ) ) { return new WP_Error( 'invalid_input', 'reassign_to must be an existing user of this site.' ); }
		if ( d5b_wu_is_admin( $u ) && d5b_wu_admin_count() <= 1 ) { return d5b_wu_last_admin_error( 'deleting them' ); }
		return array( $u, $to );
	}

	/** The confirmation fingerprint: the user, who gets the items, and how many items move. */
	function d5b_wu_fp( int $id, int $to ): string {
		return $id . '|' . $to . '|' . array_sum( d5b_wu_post_counts( $id ) );
	}

	/** @return array|WP_Error */
	function d5b_wu_delete_preview( array $in ) {
		if ( ! d5b_wu_ok( 'delete_users' ) ) { return new WP_Error( 'forbidden', 'You may not delete users.' ); }
		$a = d5b_rc_args( $in, array( 'id' => 'id', 'reassign_to' => 'id' ), array( 'id', 'reassign_to' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$t = d5b_wu_delete_target( $a );
		if ( is_wp_error( $t ) ) { return $t; }
		list( $u, $to ) = $t;
		$id     = (int) $u->ID;
		$counts = d5b_wu_post_counts( $id );
		$code   = d5b_confirm_issue( 'divi5-builder/users-delete', 'user', $id, d5b_wu_fp( $id, $to ) );
		if ( is_wp_error( $code ) ) { return $code; }
		$target = get_userdata( $to );
		return array(
			'user' => d5b_wu_record( $id ), 'posts_by_type' => $counts, 'posts_total' => array_sum( $counts ),
			'comments' => d5b_wu_comment_count( $id ),
			'reassign_to' => array( 'id' => $to, 'login' => (string) $target->user_login, 'display_name' => (string) $target->display_name ),
			'network' => is_multisite(),
			'note' => ( is_multisite()
				? 'On a network the user is removed from this site, not deleted from the network. '
				: 'The user and their profile fields are deleted for good. ' )
				. 'Every item of the user (posts_by_type, every type and status) gets the author reassign_to; nothing is deleted. Their comments are left as they are, with the author name they carry (WordPress does not reassign comments). The change history keeps the user\'s record and the ids of the items that move.',
		) + $code;
	}

	/** @return array|WP_Error */
	function d5b_wu_delete( array $in ) {
		if ( ! d5b_wu_ok( 'delete_users' ) ) { return new WP_Error( 'forbidden', 'You may not delete users.' ); }
		$a = d5b_rc_args( $in, array( 'id' => 'id', 'reassign_to' => 'id', 'confirmation' => 'str' ), array( 'id', 'reassign_to' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$t = d5b_wu_delete_target( $a );
		if ( is_wp_error( $t ) ) { return $t; }
		list( $u, $to ) = $t;
		$id = (int) $u->ID;
		$ok = d5b_confirm_check( 'divi5-builder/users-delete', 'user', $id, d5b_wu_fp( $id, $to ), (string) ( $a['confirmation'] ?? '' ) );
		if ( true !== $ok ) { return $ok; }
		// The ids of the items that move are read BEFORE the delete; the history row comes first and holds them with the record.
		$ids    = d5b_wu_post_ids( $id );
		$before = d5b_wu_record( $id ) + array( 'reassigned_post_ids' => $ids );
		$hid    = d5b_wc_history_row( 'divi5-builder/users-delete', 'user', $id, 'delete', $before, array( 'reassigned_to' => $to, 'posts' => count( $ids ) ),
			( is_multisite() ? 'Removed ' . d5b_wu_label( $u ) . ' from the site' : 'Deleted ' . d5b_wu_label( $u ) ) . ', ' . count( $ids ) . ' items to user ' . $to );
		if ( $hid < 1 ) { return d5b_wt_history_unavailable(); }
		$ok = d5b_wc_delete_record_ok( $hid );
		if ( true !== $ok ) { return $ok; }
		// The last-administrator check again, as late as it can be: right before the delete. A delete cannot be undone the way a
		// role change can (the user row and its meta are gone), so this re-count is the best possible; the window left is the
		// few statements between here and core's delete.
		$fresh = get_userdata( $id );
		if ( is_object( $fresh ) && d5b_wu_is_admin( $fresh ) && d5b_wu_admin_count() <= 1 ) {
			d5b_history_delete( $hid );
			return d5b_wu_last_admin_error( 'deleting them' );
		}
		d5b_wu_admin_includes();
		if ( is_multisite() ) {
			$r    = remove_user_from_blog( $id, get_current_blog_id(), $to );
			$gone = true === $r && ! is_user_member_of_blog( $id );
		} else {
			$r    = wp_delete_user( $id, $to );
			$gone = true === $r && ! is_object( get_userdata( $id ) );
		}
		if ( ! $gone ) {
			$err = is_wp_error( $r ) ? 'wp_error:' . $r->get_error_code() : ( true === $r ? 'still_present' : 'delete_failed' );
			$fid = d5b_wc_history_row( 'divi5-builder/users-delete', 'user', $id, 'delete_failed', null, array( 'deleted' => false, 'error' => $err, 'history_id' => $hid ), 'Failed to delete ' . d5b_wu_label( $u ) );
			return new WP_Error( 'delete_failed', 'WordPress did not delete the user (a plugin may have stopped it). History row ' . $hid . ' holds their record' . ( $fid > 0 ? '; row ' . $fid . ' records the failure.' : '.' ), array( 'history_id' => $hid ) );
		}
		return array( 'deleted' => true, 'id' => $id, 'removed_from_site_only' => is_multisite(), 'reassigned_to' => $to, 'posts_reassigned' => count( $ids ), 'comments_left' => d5b_wu_comment_count( $id ), 'history_id' => $hid );
	}

	function d5b_wu_h_create( array $in ) { return d5b_wu_create( $in ); }
	function d5b_wu_h_update( array $in ) { return d5b_wu_update( $in ); }
	function d5b_wu_h_reset( array $in ) { return d5b_wu_reset( $in ); }
	function d5b_wu_h_meta_set( array $in ) { return d5b_wu_meta_set( $in ); }
	function d5b_wu_h_meta_delete( array $in ) { return d5b_wu_meta_delete( $in ); }
	function d5b_wu_h_delete_preview( array $in ) { return d5b_wu_delete_preview( $in ); }
	function d5b_wu_h_delete( array $in ) { return d5b_wu_delete( $in ); }

	// ---- the definitions

	$d5b_wu_id    = array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The user id (users-list).' ) );
	$d5b_wu_names = array(
		'display_name' => array( 'type' => 'string', 'maxLength' => 250, 'description' => 'The name shown publicly.' ),
		'first_name'   => array( 'type' => 'string', 'maxLength' => 250, 'description' => 'First name.' ),
		'last_name'    => array( 'type' => 'string', 'maxLength' => 250, 'description' => 'Last name.' ),
	);
	$d5b_wu_email = array( 'email' => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'The email address.' ) );
	$d5b_wu_role  = array( 'role' => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'The role, one of the roles you can give (for example editor, author, subscriber).' ) );
	$d5b_wu_key   = array( 'key' => array( 'type' => 'string', 'maxLength' => 255, 'description' => 'The field (user meta) name: plain ASCII letters, digits, _ - . : only. Names starting with an underscore, secret-looking names, roles, capabilities and sessions are refused.' ) );
	$d5b_wu_reassign = array( 'reassign_to' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The user who gets every item of the deleted user (required; an existing user of this site, not the one deleted).' ) );

	d5b_wc_def( 'users-create', 'users', 'Create a user',
		'Creates one user with login, email and role (one of the roles you can give: an editor can never create an administrator), and optionally display_name, first_name, last_name. No password is set or shown: WordPress emails the user its own link to set one. Refuses a login or email already in use (exists), a role you cannot give (not_allowed; a role other than the site\'s default role also needs promote_users), an invalid login or email (invalid_input). On a network the user is created for the network and added to this site. A risky action: needs the Users risky switch and create_users. Returns the user (no password, ever) and history_id.',
		array( 'login' => array( 'type' => 'string', 'maxLength' => 60, 'description' => 'The login name: letters, digits, spaces and _ . - @ (on a network: lowercase letters and digits, at least 4).' ) ) + $d5b_wu_email + $d5b_wu_role + $d5b_wu_names,
		array( 'login', 'email', 'role' ), 'd5b_wu_h_create', d5b_wu_can( 'create_users' ), array(), array( 'risky' => true ) );

	d5b_wc_def( 'users-update', 'users', 'Update a user',
		'Changes display_name, first_name, last_name, url, description, email or role of one user (only the fields given) and returns the user. Refuses: your own role or email (own_account: use your profile screen); the email of another administrator or user manager (a super admin, or anyone with promote_users, edit_users, manage_options or delete_users: other_admin_email, they change it themselves); taking the administrator role from the last administrator (last_admin); a role you cannot give or without promote_users (not_allowed); an email another user has (exists). An email change sends WordPress\'s own notice to the old address. A risky action: needs the Users risky switch and edit_users. The change history keeps the previous values.',
		$d5b_wu_id + $d5b_wu_names + array(
			'url'         => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'The website address (http or https).' ),
			'description' => array( 'type' => 'string', 'maxLength' => 10000, 'description' => 'The biographical info.' ),
		) + $d5b_wu_email + $d5b_wu_role,
		array( 'id' ), 'd5b_wu_h_update', d5b_wu_can( 'edit_users' ), array( 'idempotent' => true ), array( 'risky' => true ) );

	d5b_wc_def( 'users-password-reset', 'users', 'Send a password reset link',
		'Sends WordPress\'s own password reset email to one user (the link goes to their own address and changes nothing by itself). Returns { sent: true } only: never a password, key or link. Refuses your own account (own_account: use the normal screens), callers without edit_users, a user whose email or role was changed through the abilities in the last 24 hours (recently_changed), and more than one link per user in 10 minutes or 20 per site in an hour (too_soon). A normal write (no risky switch needed).',
		$d5b_wu_id, array( 'id' ), 'd5b_wu_h_reset', d5b_wu_can( 'edit_users' ), array() );

	d5b_wc_def( 'users-meta-set', 'users', 'Set a user field',
		'Sets one field (user meta) of one user to value and returns it. value is text, a number, true/false, or a list or object of those (8 levels, 1 MB at most). When the name holds several rows they are ALL replaced by this one value (replaced_rows). Refuses with not_allowed: roles and capabilities (capabilities, user_level, any name ending _capabilities or _user_level), session_tokens, use_ssl, dismissed_wp_pointers, protected names (starting with an underscore) and secret-looking names; with invalid_input a name that is not plain ASCII or differs from a stored one only by case. A risky action: needs the Users risky switch and edit_users. The change history keeps every previous row.',
		$d5b_wu_id + $d5b_wu_key + array( 'value' => array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'object' ), 'description' => 'The new value.' ) ),
		array( 'id', 'key', 'value' ), 'd5b_wu_h_meta_set', d5b_wu_can( 'edit_users' ), array( 'idempotent' => true ), array( 'risky' => true ) );

	d5b_wc_def( 'users-meta-delete', 'users', 'Delete a user field',
		'Deletes every row of one field (user meta) of one user and returns deleted true with the history row id. The same names are refused as for users-meta-set. The change history row is written BEFORE the delete and keeps every value; when it cannot be written nothing is deleted (history_unavailable). A risky action: needs the Users risky switch and edit_users.',
		$d5b_wu_id + $d5b_wu_key, array( 'id', 'key' ), 'd5b_wu_h_meta_delete', d5b_wu_can( 'edit_users' ), array( 'destructive' => true ), array( 'risky' => true ) );

	d5b_wc_def( 'users-delete-preview', 'users', 'Preview a user delete',
		'Step one of a user delete. Changes nothing. Returns the user, how many items of each type will get the author reassign_to (posts_by_type, every type and status; nothing is deleted), how many comments they wrote (left as they are: WordPress does not reassign comments), reassign_to, and a confirmation code valid for 10 minutes for this user, this reassign_to and this item count. Show it to the user and wait for their explicit OK before calling divi5-builder/users-delete with the code. Refuses your own account (own_account), the last administrator (last_admin), and a reassign_to that is not another existing user of the site (invalid_input). On a network the delete removes the user from this site only. A risky action: needs the Users risky switch and delete_users.',
		$d5b_wu_id + $d5b_wu_reassign, array( 'id', 'reassign_to' ), 'd5b_wu_h_delete_preview', d5b_wu_can( 'delete_users' ), array( 'readonly' => true ), array( 'risky' => true ) );

	d5b_wc_def( 'users-delete', 'users', 'Delete a user',
		'Step two of a user delete: every item of the user gets the author reassign_to, then the user is deleted (on a network: removed from this site). Only with the confirmation code from users-delete-preview, by the same user, for the same user and reassign_to, with the same item count, used once, within 10 minutes; anything else is refused with confirmation_invalid and nothing changes. The change history row is written BEFORE the delete with the user\'s record and the ids of the items that move; when it cannot be written nothing is deleted (history_unavailable). The same refusals as the preview, checked again. Returns deleted true, reassigned_to, posts_reassigned, comments_left and history_id. A risky action: needs the Users risky switch and delete_users.',
		$d5b_wu_id + $d5b_wu_reassign + array( 'confirmation' => array( 'type' => 'string', 'description' => 'The confirmation code from the preview, after the user said OK.' ) ),
		array( 'id', 'reassign_to', 'confirmation' ), 'd5b_wu_h_delete', d5b_wu_can( 'delete_users' ), array( 'destructive' => true ), array( 'risky' => true ) );

	unset( $d5b_wu_id, $d5b_wu_names, $d5b_wu_email, $d5b_wu_role, $d5b_wu_key, $d5b_wu_reassign );

	// Raw third-party data: user meta is shaped by other plugins, so the row rule applies there (as meta-set).
	d5b_ability_set_raw_paths( array( 'users-create' => array( 'meta' ), 'users-update' => array( 'meta' ), 'users-meta-set' => array( 'value' ), 'users-delete-preview' => array( 'user.meta' ) ) );
	// The field name reaches the audit log as "field" (the audit drops an input named key with its value).
	if ( function_exists( 'd5b_wt_meta_audit_args' ) ) {
		d5b_ability_set_audit_args( array( 'users-meta-set' => 'd5b_wt_meta_audit_args', 'users-meta-delete' => 'd5b_wt_meta_audit_args' ) );
	}
}
