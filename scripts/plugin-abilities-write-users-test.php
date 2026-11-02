<?php
/**
 * User abilities (#32, release 2.4.0, Task 6): users-create, users-update, users-password-reset, users-meta-set,
 * users-meta-delete, users-delete-preview and users-delete.
 *
 *   php -d max_execution_time=60 scripts/plugin-abilities-write-users-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php with T_USER_STANDINS: wp_insert_user,
 * wp_update_user, wpmu_create_user, add_user_to_blog, wp_delete_user, remove_user_from_blog, retrieve_password,
 * wp_new_user_notification, count_users, get_editable_roles and the user meta functions behave as core 6.4.1 does at the surface
 * the plugin uses). Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: no password anywhere (the generated one goes into WordPress and never into the result, the audit row
 * or the history row); WordPress's own "set your password" email; roles only from get_editable_roles; own account, another
 * administrator's email and the last administrator (counted fresh at run time) refused; reset rate limits and { sent: true }
 * only; the user meta deny-list and the 2.2.0 key rules; the delete reassigns everything to an existing other user, the row
 * holds the moved ids (30,000 of them fit, no truncation), and on a network the user is only removed from the site.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.4.0' );
define( 'T_USER_STANDINS', true );
require __DIR__ . '/plugin-test-stubs.php';
function wp_json_encode( $v ) { return json_encode( $v ); }

foreach ( array( 'inc/parts', 'inc/switches', 'inc/abilities/categories', 'inc/abilities/register', 'inc/abilities/secrets', 'inc/abilities/audit', 'inc/abilities/scrub', 'inc/abilities/history', 'inc/abilities/read-content', 'inc/abilities/read-site', 'inc/abilities/read-history', 'inc/abilities/write-common', 'inc/abilities/confirm', 'inc/abilities/write-terms', 'inc/abilities/maint-common', 'inc/abilities/write-users' ) as $f ) { require $dir . '/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 800 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

$GLOBALS['d5b_maint_seams'] = array( 'now' => function () { return $GLOBALS['T_clock']; } );

const ADMIN = array( 'read', 'list_users', 'create_users', 'edit_users', 'promote_users', 'delete_users', 'remove_users' );
const NET_ADMIN = array( 'read', 'list_users', 'create_users', 'edit_users', 'promote_users', 'delete_users', 'remove_users', 'manage_network_users' );
// A user manager who is not an administrator (a custom role): the only way the last administrator can be in reach.
const MANAGER = array( 'read', 'list_users', 'edit_users', 'promote_users', 'delete_users' );

// Users: 1 boss (administrator, the caller by default), 2 second (administrator), 3 ed (editor), 4 sub (subscriber), 9 manager.
function site( array $caps = ADMIN, bool $multi = false, bool $risky = true, int $me = 1 ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( $multi );
	t_add_user( 1, 'boss', array( 'administrator' ) ); t_add_user( 2, 'second', array( 'administrator' ) ); t_add_user( 3, 'ed', array( 'editor' ) ); t_add_user( 4, 'sub', array( 'subscriber' ) );
	t_add_user( 9, 'manager', array( 'usermanager' ) );
	$GLOBALS['T_user_id'] = $me; $GLOBALS['T_user_login'] = get_userdata( $me )->user_login; $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	if ( $multi ) { $GLOBALS['T_site_options']['site_admins'] = array( 'boss' ); }
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
	d5b_abilities_set_category( 'users', true, $risky, 'tester' );
	$GLOBALS['T_clock'] = 1700000000.0;
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function msg( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
function hrows() { global $wpdb; return array_values( $wpdb->tables[ d5b_history_table() ] ?? array() ); }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function arows() { global $wpdb; return array_values( $wpdb->tables[ d5b_audit_table() ] ?? array() ); }
function calls( $fn ) { return array_values( array_filter( $GLOBALS['T_user_calls'], function ( $c ) use ( $fn ) { return $c[0] === $fn; } ) ); }
function sorted( array $k ) { sort( $k ); return $k; }

// ---- registration
echo "registration\n";
site();
$defs = d5b_ability_defs();
$names = array( 'users-create', 'users-update', 'users-password-reset', 'users-meta-set', 'users-meta-delete', 'users-delete-preview', 'users-delete' );
check( 'all seven exist, in the users category', 7 === count( array_filter( $names, function ( $x ) use ( $defs ) { return isset( $defs[ 'divi5-builder/' . $x ] ) && 'users' === $defs[ 'divi5-builder/' . $x ]['category']; } ) ) );
check( 'risky: every one except users-password-reset (a normal write)', array( 'users-password-reset' ) === array_values( array_filter( $names, function ( $x ) use ( $defs ) { return true !== ( $defs[ 'divi5-builder/' . $x ]['risky'] ?? null ); } ) ) && ! array_key_exists( 'risky', $defs['divi5-builder/users-password-reset'] ) );
check( 'annotations: the preview readonly, the deletes destructive, create/update/reset/meta-set writes', true === $defs['divi5-builder/users-delete-preview']['annotations']['readonly'] && true === $defs['divi5-builder/users-delete']['annotations']['destructive'] && true === $defs['divi5-builder/users-meta-delete']['annotations']['destructive'] && false === $defs['divi5-builder/users-create']['annotations']['readonly'] && false === $defs['divi5-builder/users-password-reset']['annotations']['readonly'] );
check( 'no input carries a password: users-create takes login, email, role, display_name, first_name, last_name only', array( 'display_name', 'email', 'first_name', 'last_name', 'login', 'role' ) === sorted( array_keys( $defs['divi5-builder/users-create']['input_schema']['properties'] ) ) );
check( 'the new codes are not 500: own_account 403, other_admin_email 403, last_admin 409 (exists 409, too_soon 429)', 403 === d5b_ability_http_status( 'own_account' ) && 403 === d5b_ability_http_status( 'other_admin_email' ) && 409 === d5b_ability_http_status( 'last_admin' ) && 409 === d5b_ability_http_status( 'exists' ) && 429 === d5b_ability_http_status( 'too_soon' ) );
check( 'a password input is refused', 'invalid_input' === code( call( 'users-create', array( 'login' => 'x1', 'email' => 'x1@example.org', 'role' => 'author', 'password' => 'hunter2' ) ) ) && array() === calls( 'wp_insert_user' ) );
site( ADMIN, false, false );
check( 'risky switch off: users-create, -update, -meta-set, -delete-preview refused by the gate; the reset (normal) is not', 'FORBIDDEN' === call( 'users-create', array( 'login' => 'x1', 'email' => 'x1@example.org', 'role' => 'author' ) ) && 'FORBIDDEN' === call( 'users-update', array( 'id' => 3, 'display_name' => 'E' ) ) && 'FORBIDDEN' === call( 'users-meta-set', array( 'id' => 3, 'key' => 'a', 'value' => 'b' ) ) && 'FORBIDDEN' === call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 4 ) ) && array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => 3 ) ) );
check( '... and the handler refuses on its own as well', 'not_allowed' === code( d5b_wu_create( array( 'login' => 'x1', 'email' => 'x1@example.org', 'role' => 'author' ) ) ) && array() === calls( 'wp_insert_user' ) );

// ---- users-create
echo "users-create\n";
site();
$r = call( 'users-create', array( 'login' => 'newbie', 'email' => 'newbie@example.org', 'role' => 'author', 'display_name' => 'New Bie', 'first_name' => 'New' ) );
$pw = $GLOBALS['T_generated'][0] ?? '(none)';
$id = (int) ( $r['id'] ?? 0 );
check( 'create: the user exists with the role, the names and the email', $id > 0 && 'newbie' === $r['login'] && array( 'author' ) === $r['roles'] && 'New Bie' === $r['display_name'] && 'New' === $r['first_name'] && 'newbie@example.org' === get_userdata( $id )->user_email, $r );
check( '... one wp_generate_password( 24, true, true ), and exactly that password went into wp_insert_user', array( array( 'wp_generate_password', 24, true, true ) ) === calls( 'wp_generate_password' ) && 1 === count( calls( 'wp_insert_user' ) ) && $pw === calls( 'wp_insert_user' )[0][1]['user_pass'] && $pw === $GLOBALS['T_user_pass'][ $id ] );
check( '... WordPress\'s own email: wp_new_user_notification( id, null, \'user\' ), the set-password link to the user', array( array( 'wp_new_user_notification', $id, null, 'user' ) ) === calls( 'wp_new_user_notification' ) && array( array( 'newbie@example.org', 'set_password_link' ) ) === $GLOBALS['T_user_mails'] );
check( '... the password is not in the result', is_array( $r ) && false === strpos( json_encode( $r ), $pw ) && ! array_key_exists( 'user_pass', $r ) && ! array_key_exists( 'password', $r ), $r );
check( '... nor in the audit row', 1 === count( arows() ) && false === strpos( json_encode( arows() ), $pw ), arows() );
check( '... nor in the history row', 1 === count( hrows() ) && false === strpos( json_encode( hrows() ), $pw ) && false === strpos( json_encode( hlast() ), $pw ) );
$h = hlast();
check( '... the row: user, create, before null, after the record (login, roles)', 'user' === $h['object_type'] && $id === (int) $h['object_id'] && 'create' === $h['action'] && empty( $h['before'] ) && 'newbie' === $h['after']['login'] && array( 'author' ) === $h['after']['roles'] && (int) ( $r['history_id'] ?? 0 ) === (int) $h['id'], $h );
check( '... the record leaves out roles-as-meta, the session and secrets (wp_capabilities, session_tokens)', ! isset( $r['meta']['wp_capabilities'] ) && ! isset( $r['meta']['session_tokens'] ) && isset( $r['meta']['nickname'] ), $r['meta'] ?? null );
$r2 = call( 'users-get', array( 'id' => 3 ) ); // unrelated read: the record shape stays the reads' own
site(); $GLOBALS['T_editable_roles'] = array( 'editor', 'author', 'contributor', 'subscriber' );
$r = call( 'users-create', array( 'login' => 'sneaky', 'email' => 'sneaky@example.org', 'role' => 'administrator' ) );
check( 'create: a role not in get_editable_roles (administrator for an editor) -> not_allowed, nothing created, no password made', 'not_allowed' === code( $r ) && array() === calls( 'wp_insert_user' ) && array() === calls( 'wp_generate_password' ) && false === username_exists( 'sneaky' ), code( $r ) );
check( 'create: an unknown role -> not_allowed', 'not_allowed' === code( call( 'users-create', array( 'login' => 'sneaky', 'email' => 'sneaky@example.org', 'role' => 'wizard' ) ) ) );
site();
check( 'create: a login in use (any case) -> exists', 'exists' === code( call( 'users-create', array( 'login' => 'Boss', 'email' => 'other@example.org', 'role' => 'author' ) ) ) && array() === calls( 'wp_insert_user' ) );
check( 'create: an email in use (any case) -> exists', 'exists' === code( call( 'users-create', array( 'login' => 'other', 'email' => 'ED@example.org', 'role' => 'author' ) ) ) && array() === calls( 'wp_insert_user' ) );
check( 'create: a login WordPress would change -> invalid_input', 'invalid_input' === code( call( 'users-create', array( 'login' => 'bad<b>name', 'email' => 'b@example.org', 'role' => 'author' ) ) ) );
check( 'create: an invalid email -> invalid_input', 'invalid_input' === code( call( 'users-create', array( 'login' => 'okname', 'email' => 'not-an-email', 'role' => 'author' ) ) ) );
check( 'create: text holding [redacted] -> masked_read', 'masked_read' === code( call( 'users-create', array( 'login' => 'okname', 'email' => 'ok@example.org', 'role' => 'author', 'display_name' => 'A [redacted] B' ) ) ) );
site( array( 'read', 'list_users', 'edit_users' ) );
check( 'create: without create_users -> refused', 'FORBIDDEN' === call( 'users-create', array( 'login' => 'okname', 'email' => 'ok@example.org', 'role' => 'author' ) ) );

echo "users-create on a network\n";
site( NET_ADMIN, true );
$r = call( 'users-create', array( 'login' => 'netuser', 'email' => 'netuser@example.org', 'role' => 'editor', 'last_name' => 'Net' ) );
$pw = $GLOBALS['T_generated'][0] ?? '(none)';
$id = (int) ( $r['id'] ?? 0 );
$w = calls( 'wpmu_create_user' );
check( 'network: wpmu_create_user( login, the generated password, email ), then add_user_to_blog( this site, id, role ); no wp_insert_user of ours', 1 === count( $w ) && 'netuser' === $w[0][1] && $pw === $w[0][2] && 'netuser@example.org' === $w[0][3] && array( array( 'add_user_to_blog', 1, $id, 'editor' ) ) === calls( 'add_user_to_blog' ) && array() === calls( 'wp_insert_user' ), $GLOBALS['T_user_calls'] );
check( '... a member of this site with the role and the names; notification \'user\'', $id > 0 && is_user_member_of_blog( $id, 1 ) && array( 'editor' ) === $r['roles'] && 'Net' === $r['last_name'] && array( array( 'wp_new_user_notification', $id, null, 'user' ) ) === calls( 'wp_new_user_notification' ), $r );
check( '... the password in none of result, audit row, history row', false === strpos( json_encode( array( $r, arows(), hrows() ) ), $pw ) );
check( 'network: a login with capitals (core\'s network rule: a-z and 0-9) -> invalid_input', 'invalid_input' === code( call( 'users-create', array( 'login' => 'NetUser2', 'email' => 'n2@example.org', 'role' => 'editor' ) ) ) );
site( NET_ADMIN, true, true, 3 ); t_set_roles( 3, array( 'administrator' ) );
check( 'network: a site administrator who is not a super admin cannot create users (core: create_users is network-only)', 'FORBIDDEN' === call( 'users-create', array( 'login' => 'netuser3', 'email' => 'n3@example.org', 'role' => 'editor' ) ) );

// ---- users-update
echo "users-update\n";
site();
check( 'update: your own role -> own_account, nothing changed', 'own_account' === code( call( 'users-update', array( 'id' => 1, 'role' => 'editor' ) ) ) && array() === calls( 'wp_update_user' ) && array( 'administrator' ) === get_userdata( 1 )->roles );
check( 'update: your own email -> own_account', 'own_account' === code( call( 'users-update', array( 'id' => 1, 'email' => 'me2@example.org' ) ) ) && 'boss@example.org' === get_userdata( 1 )->user_email );
$r = call( 'users-update', array( 'id' => 1, 'display_name' => 'The Boss' ) );
check( 'update: your own display name is fine', is_array( $r ) && 'The Boss' === $r['display_name'], $r );
check( 'update: your own email given unchanged is not a change (no refusal)', is_array( call( 'users-update', array( 'id' => 1, 'email' => 'boss@example.org', 'first_name' => 'B' ) ) ) );
check( 'update: another administrator\'s email -> other_admin_email', 'other_admin_email' === code( call( 'users-update', array( 'id' => 2, 'email' => 'takeover@example.org' ) ) ) && 'second@example.org' === get_userdata( 2 )->user_email );
check( 'update: an email another user has (any case) -> exists', 'exists' === code( call( 'users-update', array( 'id' => 3, 'email' => 'SUB@example.org' ) ) ) && 'ed@example.org' === get_userdata( 3 )->user_email );
$GLOBALS['T_user_mails'] = array(); $rows = count( hrows() );
$r = call( 'users-update', array( 'id' => 3, 'email' => 'ed.new@example.org', 'url' => 'example.org/ed', 'description' => 'Writes.' ) );
check( 'update: an editor\'s email, url, description: changed; core\'s own notice went to the OLD address', is_array( $r ) && 'ed.new@example.org' === $r['email'] && 'http://example.org/ed' === $r['url'] && 'Writes.' === $r['description'] && array( array( 'ed@example.org', 'email_change_notice' ) ) === $GLOBALS['T_user_mails'] && false !== strpos( (string) ( $r['note'] ?? '' ), 'old address' ), array( $r, $GLOBALS['T_user_mails'] ) );
$h = hlast();
check( '... one row: update, before the old record, after only what changed', $rows + 1 === count( hrows() ) && 'update' === $h['action'] && 'ed@example.org' === $h['before']['email'] && array( 'description', 'email', 'url' ) === sorted( array_keys( $h['after'] ) ), $h );
$r = call( 'users-update', array( 'id' => 3, 'role' => 'author' ) );
check( 'update: role editor -> author by an administrator', is_array( $r ) && array( 'author' ) === $r['roles'] && array( 'author' ) === get_userdata( 3 )->roles, $r );
check( 'update: a role not in get_editable_roles -> not_allowed', ( function () { $GLOBALS['T_editable_roles'] = array( 'author', 'subscriber' ); $c = code( call( 'users-update', array( 'id' => 4, 'role' => 'editor' ) ) ); $GLOBALS['T_editable_roles'] = null; return 'not_allowed' === $c; } )() && array( 'subscriber' ) === get_userdata( 4 )->roles );
site( array( 'read', 'list_users', 'edit_users' ) );
check( 'update: a role change without promote_users -> not_allowed', 'not_allowed' === code( call( 'users-update', array( 'id' => 4, 'role' => 'author' ) ) ) && array( 'subscriber' ) === get_userdata( 4 )->roles );
site();
check( 'update: url that is not a web address -> invalid_input', 'invalid_input' === code( call( 'users-update', array( 'id' => 3, 'url' => 'javascript:alert(1)' ) ) ) );
check( 'update: nothing to change -> invalid_input', 'invalid_input' === code( call( 'users-update', array( 'id' => 3 ) ) ) );
check( 'update: an unknown user -> not_found', 'not_found' === code( call( 'users-update', array( 'id' => 777, 'display_name' => 'x' ) ) ) );

echo "the last administrator\n";
site( MANAGER, false, true, 9 );
$c0 = $GLOBALS['T_count_users'];
$r = call( 'users-update', array( 'id' => 2, 'role' => 'editor' ) );
check( 'last admin: two administrators, the manager demotes one: fine (counted by count_users)', is_array( $r ) && array( 'editor' ) === get_userdata( 2 )->roles && $GLOBALS['T_count_users'] > $c0, $r );
$r = call( 'users-update', array( 'id' => 1, 'role' => 'editor' ) );
check( 'last admin: then the other one -> last_admin, still an administrator', 'last_admin' === code( $r ) && array( 'administrator' ) === get_userdata( 1 )->roles, code( $r ) );
check( 'last admin: giving the last administrator the administrator role again is fine', is_array( call( 'users-update', array( 'id' => 1, 'role' => 'administrator' ) ) ) );
site( MANAGER, false, true, 9 );
$read = call( 'users-list' ); // two administrators in the read
t_set_roles( 2, array( 'subscriber' ) ); // then someone else demotes one, outside the plugin
$r = call( 'users-update', array( 'id' => 1, 'role' => 'editor' ) );
check( 'last admin: counted fresh at run time, not from the earlier read (which showed two)', 'last_admin' === code( $r ) && array( 'administrator' ) === get_userdata( 1 )->roles, code( $r ) );
check( '... users-delete-preview of the last administrator -> last_admin', 'last_admin' === code( call( 'users-delete-preview', array( 'id' => 1, 'reassign_to' => 3 ) ) ) );
check( '... users-delete of the last administrator -> last_admin, nothing deleted', 'last_admin' === code( call( 'users-delete', array( 'id' => 1, 'reassign_to' => 3, 'confirmation' => 'x' ) ) ) && array() === calls( 'wp_delete_user' ) && false !== get_userdata( 1 ) );

// ---- users-password-reset
echo "users-password-reset\n";
site();
check( 'reset: your own account -> own_account, nothing sent', 'own_account' === code( call( 'users-password-reset', array( 'id' => 1 ) ) ) && array() === calls( 'retrieve_password' ) );
$r = call( 'users-password-reset', array( 'id' => 3 ) );
check( 'reset: { sent: true } only: no key, no link', array( 'sent' => true ) === $r, $r );
check( '... retrieve_password with the login, it found user 3; the link went to their address', array( array( 'retrieve_password', 'ed', 3 ) ) === calls( 'retrieve_password' ) && array( array( 'ed@example.org', 'password_reset_link' ) ) === $GLOBALS['T_user_mails'] );
check( '... one row: password_reset with after { sent: true }', 'password_reset' === hlast()['action'] && array( 'sent' => true ) === hlast()['after'] && 3 === (int) hlast()['object_id'] );
check( 'reset: the same user again within 10 minutes -> too_soon, nothing sent', 'too_soon' === code( call( 'users-password-reset', array( 'id' => 3 ) ) ) && 1 === count( calls( 'retrieve_password' ) ) );
$GLOBALS['T_clock'] += 599;
check( '... at 9:59 still too_soon', 'too_soon' === code( call( 'users-password-reset', array( 'id' => 3 ) ) ) );
$GLOBALS['T_clock'] += 2;
check( '... after 10 minutes: sent', array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => 3 ) ) );
site();
for ( $i = 20; $i < 41; $i++ ) { t_add_user( $i, 'u' . $i, array( 'subscriber' ) ); }
$sent = 0; for ( $i = 20; $i < 40; $i++ ) { if ( array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => $i ) ) ) { $sent++; } }
check( 'reset: 20 different users in an hour: all sent', 20 === $sent, $sent );
check( '... the 21st -> too_soon (20 per site per hour)', 'too_soon' === code( call( 'users-password-reset', array( 'id' => 40 ) ) ) && 20 === count( calls( 'retrieve_password' ) ) );
$GLOBALS['T_clock'] += 3601;
check( '... an hour later: sent again', array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => 40 ) ) );
site(); t_add_user( 30, 'mail@x.org', array( 'subscriber' ), array( 'user_email' => 'real30@example.org' ) ); t_add_user( 31, 'other', array( 'subscriber' ), array( 'user_email' => 'mail@x.org' ) );
check( 'reset: a login holding @ (core would look it up as an email first, finding user 31): the user\'s own email is passed, user 30 gets it', array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => 30 ) ) && 30 === calls( 'retrieve_password' )[0][2] && array( array( 'real30@example.org', 'password_reset_link' ) ) === $GLOBALS['T_user_mails'] );
site(); $GLOBALS['T_user_fail']['retrieve_password'] = new WP_Error( 'retrieve_password_email_failure', 'The email could not be sent.' );
$r = call( 'users-password-reset', array( 'id' => 3 ) );
check( 'reset: the email could not be sent -> unavailable (503, not 500), no row', 'unavailable' === code( $r ) && array() === hrows(), code( $r ) );
site( array( 'read', 'list_users' ) );
check( 'reset: without edit_users -> refused', 'FORBIDDEN' === call( 'users-password-reset', array( 'id' => 3 ) ) );

// ---- user meta
echo "users-meta-set / users-meta-delete\n";
site();
$denied = array( 'wp_capabilities', 'capabilities', 'WP_Capabilities', 'wp_user_level', 'user_level', 'wp_2_capabilities', 'wp_2_user_level', 'session_tokens', 'use_ssl', 'dismissed_wp_pointers' );
$bad = array();
foreach ( $denied as $k ) {
	$a = code( call( 'users-meta-set', array( 'id' => 3, 'key' => $k, 'value' => 'x' ) ) ); $b = code( call( 'users-meta-delete', array( 'id' => 3, 'key' => $k ) ) );
	if ( 'not_allowed' !== $a || 'not_allowed' !== $b ) { $bad[] = array( $k, $a, $b ); }
}
check( 'meta: the deny-list (capabilities, user_level, session_tokens, *_capabilities, *_user_level, use_ssl, dismissed_wp_pointers; any case) -> not_allowed for set and delete', array() === $bad && array( array( 'editor' => true ) ) === $GLOBALS['T_umeta'][3]['wp_capabilities'] && isset( $GLOBALS['T_umeta'][3]['session_tokens'] ) && array() === hrows(), $bad );
check( 'meta: a secret-named key -> not_allowed', 'not_allowed' === code( call( 'users-meta-set', array( 'id' => 3, 'key' => 'api_key', 'value' => 'x' ) ) ) && 'not_allowed' === code( call( 'users-meta-delete', array( 'id' => 3, 'key' => 'smtp_password' ) ) ) );
check( 'meta: a protected key (is_protected_meta user) -> not_allowed', 'not_allowed' === code( call( 'users-meta-set', array( 'id' => 3, 'key' => '_private', 'value' => 'x' ) ) ) && 'not_allowed' === code( call( 'users-meta-delete', array( 'id' => 3, 'key' => '_private' ) ) ) );
check( 'meta: a key that is not plain ASCII -> invalid_input', 'invalid_input' === code( call( 'users-meta-set', array( 'id' => 3, 'key' => 'pässword', 'value' => 'x' ) ) ) );
$GLOBALS['T_umeta'][3]['Favourite_Color'] = array( 'blue' );
$r = call( 'users-meta-set', array( 'id' => 3, 'key' => 'favourite_color', 'value' => 'red' ) );
check( 'meta: a case variant of a stored name -> invalid_input naming it, nothing written', 'invalid_input' === code( $r ) && false !== strpos( msg( $r ), 'Favourite_Color' ) && ! isset( $GLOBALS['T_umeta'][3]['favourite_color'] ), msg( $r ) );
check( '... also for the delete', 'invalid_input' === code( call( 'users-meta-delete', array( 'id' => 3, 'key' => 'favourite_color' ) ) ) && array( 'blue' ) === $GLOBALS['T_umeta'][3]['Favourite_Color'] );
$r = call( 'users-meta-set', array( 'id' => 4, 'key' => 'favourite_color', 'value' => 'it\'s "green"\\' ) );
check( 'meta-set: written exactly (slashes kept), the answer has the value and replaced_rows 0', is_array( $r ) && array( 'it\'s "green"\\' ) === $GLOBALS['T_umeta'][4]['favourite_color'] && 0 === $r['replaced_rows'] && 4 === $r['user_id'], $r );
check( '... a create row with the field; the audit row names the field', 'create' === hlast()['action'] && 'user_meta' === hlast()['object_type'] && 'favourite_color' === hlast()['after']['field'] && false !== strpos( json_encode( end( $GLOBALS['wpdb']->tables[ d5b_audit_table() ] ) ), 'favourite_color' ) );
$GLOBALS['T_umeta'][4]['multi'] = array( 'a', 'b' );
$r = call( 'users-meta-set', array( 'id' => 4, 'key' => 'multi', 'value' => array( 'x' => 1 ) ) );
check( 'meta-set: a name with two rows ends as the one value; the row keeps both', is_array( $r ) && array( array( 'x' => 1 ) ) === $GLOBALS['T_umeta'][4]['multi'] && 2 === $r['replaced_rows'] && array( 'a', 'b' ) === hlast()['before']['rows'], $r );
$r = call( 'users-meta-delete', array( 'id' => 4, 'key' => 'favourite_color' ) );
check( 'meta-delete: gone, the row first with every value', is_array( $r ) && true === $r['deleted'] && ! isset( $GLOBALS['T_umeta'][4]['favourite_color'] ) && 'delete' === hlast()['action'] && array( 'it\'s "green"\\' ) === hlast()['before']['rows'], $r );
check( 'meta-delete: a field the user does not have -> not_found', 'not_found' === code( call( 'users-meta-delete', array( 'id' => 4, 'key' => 'nothing_here' ) ) ) );
check( 'meta-set: a value that is an object -> invalid_input', 'invalid_input' === code( d5b_wu_meta_set( array( 'id' => 4, 'key' => 'k1', 'value' => new ArrayObject( array() ) ) ) ) );

// ---- users-delete-preview / users-delete
echo "users-delete\n";
site();
check( 'delete: yourself -> own_account', 'own_account' === code( call( 'users-delete-preview', array( 'id' => 1, 'reassign_to' => 2 ) ) ) && 'own_account' === code( call( 'users-delete', array( 'id' => 1, 'reassign_to' => 2, 'confirmation' => 'x' ) ) ) );
check( 'delete: reassign_to the same user -> invalid_input', 'invalid_input' === code( call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 3 ) ) ) );
check( 'delete: reassign_to a user that does not exist -> invalid_input', 'invalid_input' === code( call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 999 ) ) ) );
check( 'delete: reassign_to missing -> invalid_input', 'invalid_input' === code( call( 'users-delete-preview', array( 'id' => 3 ) ) ) );
$p1 = t_add_post( array( 'post_author' => 3 ) ); $p2 = t_add_post( array( 'post_author' => 3, 'post_status' => 'draft' ) ); $p3 = t_add_post( array( 'post_author' => 3, 'post_type' => 'page' ) ); t_add_post( array( 'post_author' => 4 ) );
t_add_comment( array( 'user_id' => 3, 'comment_post_ID' => $p1 ) );
$pv = call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 4 ) );
check( 'preview: the record, posts by type, the total, the comments (left as they are), reassign_to, a code', is_array( $pv ) && 'ed' === $pv['user']['login'] && array( 'page' => 1, 'post' => 2 ) === $pv['posts_by_type'] && 3 === $pv['posts_total'] && 1 === $pv['comments'] && 4 === $pv['reassign_to']['id'] && '' !== (string) ( $pv['confirmation'] ?? '' ) && false !== stripos( $pv['note'], 'comments are left as they are' ), $pv );
check( '... changes nothing', array() === calls( 'wp_delete_user' ) && false !== get_userdata( 3 ) && array() === hrows() );
check( 'delete: a code for another reassign_to -> confirmation_invalid', 'confirmation_invalid' === code( call( 'users-delete', array( 'id' => 3, 'reassign_to' => 2, 'confirmation' => $pv['confirmation'] ) ) ) && array() === calls( 'wp_delete_user' ) );
$pv = call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 4 ) );
t_add_post( array( 'post_author' => 3 ) ); // one more item since the preview
check( 'delete: the item count changed since the preview -> confirmation_invalid', 'confirmation_invalid' === code( call( 'users-delete', array( 'id' => 3, 'reassign_to' => 4, 'confirmation' => $pv['confirmation'] ) ) ) && false !== get_userdata( 3 ) );
$pv = call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 4 ) );
$ids = t_author_ids( 3 );
$r = call( 'users-delete', array( 'id' => 3, 'reassign_to' => 4, 'confirmation' => $pv['confirmation'] ) );
check( 'delete: done: wp_delete_user( 3, 4 ), the user gone, every item now by user 4', is_array( $r ) && true === $r['deleted'] && array( array( 'wp_delete_user', 3, 4 ) ) === calls( 'wp_delete_user' ) && false === get_userdata( 3 ) && array() === t_author_ids( 3 ) && 4 === count( $ids ) && 4 === $r['posts_reassigned'] && 4 === $r['reassigned_to'], $r );
$h = hlast();
check( '... one row, written before: before = the record + reassigned_post_ids (read before the delete), after { reassigned_to, posts }', 1 === count( hrows() ) && 'delete' === $h['action'] && 'ed' === $h['before']['login'] && $ids === $h['before']['reassigned_post_ids'] && array( 'posts' => 4, 'reassigned_to' => 4 ) === ( function ( $a ) { ksort( $a ); return $a; } )( $h['after'] ) && (int) $r['history_id'] === (int) $h['id'], $h );
check( '... the comment is left as it is (still user 3\'s)', 1 === count( array_filter( $GLOBALS['T_comments'], function ( $c ) { return 3 === (int) $c->user_id; } ) ) && 1 === $r['comments_left'] );
check( 'delete: the code is used once', 'confirmation_invalid' === code( call( 'users-delete', array( 'id' => 4, 'reassign_to' => 2, 'confirmation' => $pv['confirmation'] ) ) ) );
site( array( 'read', 'list_users', 'edit_users' ) );
check( 'delete: without delete_users -> refused', 'FORBIDDEN' === call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 4 ) ) );

echo "a user with 30,000 items\n";
site();
$GLOBALS['T_author_ids'][3] = range( 100000, 129999 );
$pv = call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 4 ) );
check( 'preview: 30,000 items counted', is_array( $pv ) && 30000 === $pv['posts_total'], is_array( $pv ) ? $pv['posts_total'] : $pv );
$r = call( 'users-delete', array( 'id' => 3, 'reassign_to' => 4, 'confirmation' => $pv['confirmation'] ?? '' ) );
$row = hrows()[0] ?? array();
$h = hlast();
check( 'delete: done, the row holds all 30,000 ids', is_array( $r ) && true === $r['deleted'] && 30000 === count( $h['before']['reassigned_post_ids'] ?? array() ) && 100000 === $h['before']['reassigned_post_ids'][0] && 30000 === $h['after']['posts'], is_array( $r ) ? array_keys( $r ) : $r );
check( '... under the 8 MB cap, no truncation flag, no size marker', strlen( json_encode( $row ) ) < d5b_history_limit() && false === d5b_history_last_was_truncated() && false === strpos( json_encode( $row ), '_truncated' ) && false === strpos( json_encode( $row ), 'unencodable' ), strlen( json_encode( $row ) ) );

echo "users-delete on a network\n";
site( NET_ADMIN, true );
t_add_post( array( 'post_author' => 3 ) );
$ids = t_author_ids( 3 );
$pv = call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 4 ) );
check( 'network preview: says the user is only removed from this site', is_array( $pv ) && true === $pv['network'] && false !== stripos( $pv['note'], 'removed from this site' ), $pv );
$r = call( 'users-delete', array( 'id' => 3, 'reassign_to' => 4, 'confirmation' => $pv['confirmation'] ?? '' ) );
check( 'network: remove_user_from_blog( 3, this site, 4 ); never wp_delete_user; the user stays on the network, not a member here; the items by 4', is_array( $r ) && array( array( 'remove_user_from_blog', 3, 1, 4 ) ) === calls( 'remove_user_from_blog' ) && array() === calls( 'wp_delete_user' ) && false !== get_userdata( 3 ) && ! is_user_member_of_blog( 3, 1 ) && array() === t_author_ids( 3 ) && true === $r['removed_from_site_only'] && $ids === hlast()['before']['reassigned_post_ids'], array( $r, $GLOBALS['T_user_calls'] ) );
site( NET_ADMIN, true, true, 2 ); t_set_roles( 2, array( 'administrator' ) );
check( 'network: a site administrator who is not a super admin cannot delete (core: delete_users is network-only)', 'FORBIDDEN' === call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 4 ) ) );
site( NET_ADMIN, true );
$GLOBALS['T_user_blogs'][5] = array( 2 ); $GLOBALS['T_users'][5] = (object) array( 'ID' => 5, 'user_login' => 'elsewhere', 'user_email' => 'e@example.org', 'display_name' => 'E', 'user_url' => '', 'user_registered' => '', 'roles' => array() );
check( 'network: a user of another site is not found here; nor is one a reassign_to', 'not_found' === code( call( 'users-update', array( 'id' => 5, 'display_name' => 'x' ) ) ) && 'invalid_input' === code( call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 5 ) ) ) );

echo "no secrets\n";
site();
$all = json_encode( array( call( 'users-create', array( 'login' => 'last1', 'email' => 'last1@example.org', 'role' => 'subscriber' ) ), call( 'users-password-reset', array( 'id' => 4 ) ), call( 'users-update', array( 'id' => 4, 'first_name' => 'S' ) ), hrows(), arows() ) );
check( 'no generated password, session token or reset key anywhere in the results or the rows', false === strpos( $all, 'mossylantern' ) && false === strpos( $all, 'tokhash' ) && 1 !== preg_match( '/user_pass|activation_key|session_tokens/i', $all ), $all );

echo "\n" . ( $failed ? "$failed of $n FAILED\n" : "all $n passed\n" );
exit( $failed ? 1 : 0 );
