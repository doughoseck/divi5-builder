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
site( array( 'read', 'list_users', 'create_users' ) );
$r = call( 'users-create', array( 'login' => 'plainsub', 'email' => 'plainsub@example.org', 'role' => 'subscriber' ) );
check( 'create without promote_users: the default role (subscriber) is fine', is_array( $r ) && array( 'subscriber' ) === $r['roles'], $r );
$r = call( 'users-create', array( 'login' => 'wanted', 'email' => 'wanted@example.org', 'role' => 'editor' ) );
check( 'create without promote_users: any other role (editor) -> not_allowed, nothing created (core gives default_role then)', 'not_allowed' === code( $r ) && false === username_exists( 'wanted' ) && 1 === count( calls( 'wp_insert_user' ) ), code( $r ) );
$GLOBALS['T_options']['default_role'] = 'author';
check( '... the site\'s default_role is what counts: author fine, subscriber now refused', is_array( call( 'users-create', array( 'login' => 'auth1', 'email' => 'auth1@example.org', 'role' => 'author' ) ) ) && 'not_allowed' === code( call( 'users-create', array( 'login' => 'sub2', 'email' => 'sub2@example.org', 'role' => 'subscriber' ) ) ) );
site();
$bad = array();
foreach ( array( 'Editor', 'EDITOR', ' editor', 'editor ', "editor\n", 'edi tor' ) as $v ) {
	$c1 = code( call( 'users-create', array( 'login' => 'var' . strlen( $v ) . ord( $v[0] ), 'email' => 'v' . strlen( $v ) . ord( $v[0] ) . '@example.org', 'role' => $v ) ) );
	$c2 = code( call( 'users-update', array( 'id' => 4, 'role' => $v ) ) );
	if ( ! in_array( $c1, array( 'not_allowed', 'invalid_input' ), true ) || ! in_array( $c2, array( 'not_allowed', 'invalid_input' ), true ) ) { $bad[] = array( $v, $c1, $c2 ); }
}
check( 'role: case and whitespace variants (Editor, EDITOR, " editor", "editor ", "edi tor") refused by create and update', array() === $bad && array() === calls( 'wp_insert_user' ) && array( 'subscriber' ) === get_userdata( 4 )->roles, $bad );
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
echo "fix wave I2: a network create that fails after the network user exists answers ok with warnings and a row\n";
site( NET_ADMIN, true ); $GLOBALS['T_user_fail']['add_user_to_blog'] = new WP_Error( 'add_fail', 'could not add' );
$r = call( 'users-create', array( 'login' => 'halfmade', 'email' => 'halfmade@example.org', 'role' => 'author', 'last_name' => 'Half' ) );
$hid = (int) username_exists( 'halfmade' ); $pw = $GLOBALS['T_generated'][0] ?? '(none)';
check( 'I2 add_user_to_blog fails: no error, the answer names the user and warns it was not added to this site', is_array( $r ) && $hid > 0 && $hid === (int) ( $r['id'] ?? 0 ) && 1 === count( (array) ( $r['warnings'] ?? array() ) ) && false !== stripos( implode( ' ', (array) $r['warnings'] ), 'not added to this site' ), array( code( $r ), $r ) );
check( 'I2 ... a create row with the record as after, named in the answer', 1 === count( hrows() ) && 'create' === hlast()['action'] && 'halfmade' === ( hlast()['after']['login'] ?? '' ) && $hid === (int) hlast()['object_id'] && (int) ( $r['history_id'] ?? 0 ) === (int) hlast()['id'], hrows() );
check( 'I2 ... the names were still set, the set-password email went, no password anywhere', 'Half' === get_user_meta( $hid, 'last_name', true ) && 1 === count( calls( 'wp_new_user_notification' ) ) && false === strpos( json_encode( array( $r, arows(), hrows() ) ), $pw ) );
site( NET_ADMIN, true ); $GLOBALS['T_user_fail']['wp_update_user'] = new WP_Error( 'db', 'names failed' );
$r = call( 'users-create', array( 'login' => 'halfnames', 'email' => 'halfnames@example.org', 'role' => 'author', 'display_name' => 'Half' ) );
$hid = (int) username_exists( 'halfnames' );
check( 'I2 the names update fails after the user was made and added: no error, a warning that the names were not set', is_array( $r ) && $hid > 0 && is_user_member_of_blog( $hid, 1 ) && array( 'author' ) === ( $r['roles'] ?? null ) && 1 === count( (array) ( $r['warnings'] ?? array() ) ) && false !== stripos( implode( ' ', (array) $r['warnings'] ), 'names' ), array( code( $r ), $r ) );
check( 'I2 ... and a create row', 1 === count( hrows() ) && 'create' === hlast()['action'] && $hid === (int) hlast()['object_id'] && (int) ( $r['history_id'] ?? 0 ) === (int) hlast()['id'], hrows() );
site( NET_ADMIN, true ); $GLOBALS['T_user_fail']['wpmu_create_user'] = false;
$r = call( 'users-create', array( 'login' => 'nevermade', 'email' => 'nevermade@example.org', 'role' => 'author' ) );
check( 'I2 wpmu_create_user itself fails: still an error, no row', 'write_failed' === code( $r ) && array() === hrows() );
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
check( 'update: url with a scheme esc_url_raw keeps but that is not http(s) (ftp:, mailto:, tel:) -> invalid_input, nothing changed', 'invalid_input' === code( call( 'users-update', array( 'id' => 3, 'url' => 'ftp://example.org/x' ) ) ) && 'invalid_input' === code( call( 'users-update', array( 'id' => 3, 'url' => 'mailto:ed@example.org' ) ) ) && 'invalid_input' === code( call( 'users-update', array( 'id' => 3, 'url' => 'tel:12345' ) ) ) && '' === get_userdata( 3 )->user_url && 'mailto:ed@example.org' === esc_url_raw( 'mailto:ed@example.org' ) );
check( 'update: https url kept; an empty url clears it', is_array( call( 'users-update', array( 'id' => 3, 'url' => 'https://ed.example.org/' ) ) ) && 'https://ed.example.org/' === get_userdata( 3 )->user_url && is_array( call( 'users-update', array( 'id' => 3, 'url' => '' ) ) ) && '' === get_userdata( 3 )->user_url );

echo "the account-takeover guard\n";
site();
$GLOBALS['T_roles']['usermanager'] = array( 'name' => 'User manager', 'capabilities' => array( 'read' => true, 'list_users' => true, 'edit_users' => true ) );
check( 'protected: a custom role holding edit_users (not called administrator) -> other_admin_email', 'other_admin_email' === code( call( 'users-update', array( 'id' => 9, 'email' => 'grab@example.org' ) ) ) && 'manager@example.org' === get_userdata( 9 )->user_email );
$GLOBALS['T_user_caps'][4] = array( 'manage_options' );
check( 'protected: a subscriber given manage_options -> other_admin_email', 'other_admin_email' === code( call( 'users-update', array( 'id' => 4, 'email' => 'grab@example.org' ) ) ) );
$GLOBALS['T_user_caps'][4] = array();
site( NET_ADMIN, true ); $GLOBALS['T_site_options']['site_admins'] = array( 'boss', 'sub' );
check( 'protected: a super admin who is a subscriber on this site -> other_admin_email', 'other_admin_email' === code( call( 'users-update', array( 'id' => 4, 'email' => 'grab@example.org' ) ) ) );
site();
$r1 = call( 'users-update', array( 'id' => 2, 'role' => 'subscriber' ) );
$r2 = call( 'users-update', array( 'id' => 2, 'email' => 'attacker@example.org' ) );
$r3 = call( 'users-password-reset', array( 'id' => 2 ) );
check( 'chain: demote an administrator, then change their (now unprotected) email: both pass ...', is_array( $r1 ) && is_array( $r2 ) && 'attacker@example.org' === get_userdata( 2 )->user_email, array( code( $r1 ), code( $r2 ) ) );
check( '... the reset is refused: recently_changed (409), nothing sent', 'recently_changed' === code( $r3 ) && 409 === d5b_ability_http_status( 'recently_changed' ) && array() === calls( 'retrieve_password' ), code( $r3 ) );
site();
call( 'users-update', array( 'id' => 4, 'role' => 'author' ) );
check( 'a role change alone also blocks the reset', 'recently_changed' === code( call( 'users-password-reset', array( 'id' => 4 ) ) ) );
site();
call( 'users-update', array( 'id' => 4, 'email' => 'sub.new@example.org' ) );
check( 'a plain subscriber: email changed, then a reset -> recently_changed', 'recently_changed' === code( call( 'users-password-reset', array( 'id' => 4 ) ) ) && array() === calls( 'retrieve_password' ) );
$GLOBALS['T_clock'] += 86399;
check( '... still refused one second before 24 hours', 'recently_changed' === code( call( 'users-password-reset', array( 'id' => 4 ) ) ) );
$GLOBALS['T_clock'] += 2;
check( '... 24 hours later: sent', array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => 4 ) ) );
site();
$GLOBALS['T_user_calls'] = array();
call( 'users-update', array( 'id' => 4, 'email' => 'sub.flood@example.org' ) );
$order = array_values( array_filter( array_map( function ( $c ) { return 'wp_update_user' === $c[0] ? 'wp_update_user' : ( 'update_user_meta' === $c[0] && '_d5b_identity_changed_at' === $c[2] ? 'marker' : null ); }, $GLOBALS['T_user_calls'] ) ) );
check( 'marker: written on the user BEFORE the change (update_user_meta _d5b_identity_changed_at, then wp_update_user), holding the time', array( 'marker', 'wp_update_user' ) === $order && (string) (int) $GLOBALS['T_clock'] === get_user_meta( 4, '_d5b_identity_changed_at', true ), $order );
for ( $i = 0; $i < 501; $i++ ) { call( 'users-update', array( 'id' => 4, 'display_name' => 0 === $i % 2 ? 'Flood A' : 'Flood B' ) ); }
check( 'marker: 501 cheap updates afterwards (more history rows than any lookup reads) do not evade the block', count( hrows() ) > 501 && 'recently_changed' === code( call( 'users-password-reset', array( 'id' => 4 ) ) ) && array() === calls( 'retrieve_password' ) );
check( 'marker: not in the record, the answers or the history (a protected key)', false === strpos( json_encode( array( call( 'users-update', array( 'id' => 4, 'first_name' => 'S' ) ), hrows() ) ), '_d5b_identity_changed_at' ) );
check( 'marker: users-meta-set and users-meta-delete refuse it (protected), it stays', 'not_allowed' === code( call( 'users-meta-set', array( 'id' => 4, 'key' => '_d5b_identity_changed_at', 'value' => '0' ) ) ) && 'not_allowed' === code( call( 'users-meta-delete', array( 'id' => 4, 'key' => '_d5b_identity_changed_at' ) ) ) && '' !== get_user_meta( 4, '_d5b_identity_changed_at', true ) );
site(); $GLOBALS['T_user_fail']['update_user_meta:_d5b_identity_changed_at'] = true;
$r = call( 'users-update', array( 'id' => 4, 'email' => 'sub.x@example.org' ) );
check( 'marker: its write fails -> unavailable, the email unchanged, no wp_update_user, no notice', 'unavailable' === code( $r ) && 'sub@example.org' === get_userdata( 4 )->user_email && array() === calls( 'wp_update_user' ) && array() === $GLOBALS['T_user_mails'] && 503 === d5b_ability_http_status( 'unavailable' ), code( $r ) );
check( '... a role change likewise', 'unavailable' === code( call( 'users-update', array( 'id' => 4, 'role' => 'author' ) ) ) && array( 'subscriber' ) === get_userdata( 4 )->roles );
check( '... a change of other fields needs no marker and goes through', is_array( call( 'users-update', array( 'id' => 4, 'display_name' => 'Plain' ) ) ) );
site();
check( 'marker: a refused email change (protected target) writes none', 'other_admin_email' === code( call( 'users-update', array( 'id' => 2, 'email' => 'grab@example.org' ) ) ) && ! isset( $GLOBALS['T_umeta'][2]['_d5b_identity_changed_at'] ) );
check( '... nor a refused own role change, nor an email in use', 'own_account' === code( call( 'users-update', array( 'id' => 1, 'role' => 'editor' ) ) ) && 'exists' === code( call( 'users-update', array( 'id' => 3, 'email' => 'sub@example.org' ) ) ) && ! isset( $GLOBALS['T_umeta'][1]['_d5b_identity_changed_at'] ) && ! isset( $GLOBALS['T_umeta'][3]['_d5b_identity_changed_at'] ) );
check( 'marker: a reset with no marker goes through', ! isset( $GLOBALS['T_umeta'][3]['_d5b_identity_changed_at'] ) && array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => 3 ) ) );
site(); $GLOBALS['T_user_fail']['get_user_meta:_d5b_identity_changed_at'] = true;
check( 'marker: it cannot be read (a database error) -> unavailable, nothing sent', 'unavailable' === code( call( 'users-password-reset', array( 'id' => 3 ) ) ) && array() === calls( 'retrieve_password' ) );
site();
call( 'users-update', array( 'id' => 3, 'display_name' => 'Ed Itor', 'description' => 'x' ) );
check( 'a reset after a change of other fields only (no email, no role) goes through', array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => 3 ) ) );
check( 'a reset with no recent change at all goes through', array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => 4 ) ) );
site();
check( 'an email change of another user does not block this one', is_array( call( 'users-update', array( 'id' => 3, 'email' => 'ed2@example.org' ) ) ) && array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => 4 ) ) );
site(); call( 'users-update', array( 'id' => 4, 'email' => 'sub.h@example.org' ) ); $GLOBALS['wpdb']->tables = array_diff_key( $GLOBALS['wpdb']->tables, array( d5b_history_table() => 1 ) );
check( 'no history dependency: with the history table gone the marker still blocks (4) and its absence lets through (3)', 'recently_changed' === code( d5b_wu_reset( array( 'id' => 4 ) ) ) && true === ( d5b_wu_reset( array( 'id' => 3 ) )['sent'] ?? null ) && 1 === count( calls( 'retrieve_password' ) ) );
// 2.4.1: a marker that is not a number (written by something else, or broken) counts as recent: fail closed.
$bad = array();
foreach ( array( 'garbage', '', '17e8', '1700000000 ', '-5', 'x1700000000' ) as $v ) {
	site(); update_user_meta( 4, '_d5b_identity_changed_at', $v );
	if ( 'recently_changed' !== code( call( 'users-password-reset', array( 'id' => 4 ) ) ) || array() !== calls( 'retrieve_password' ) ) { $bad[] = $v; }
}
check( '2.4.1 a non-numeric marker counts as a recent change (recently_changed, nothing sent)', array() === $bad, $bad );
site(); update_user_meta( 4, '_d5b_identity_changed_at', (string) ( (int) $GLOBALS['T_clock'] - 90000 ) );
check( '... a numeric marker older than 24 hours still lets the reset through', array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => 4 ) ) );
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

site( MANAGER, false, true, 9 );
// Another request demotes administrator 2 right after our check counted two administrators.
$GLOBALS['T_after_count'] = function ( $n ) { if ( 1 === $n ) { t_set_roles( 2, array( 'editor' ) ); } };
$r = call( 'users-update', array( 'id' => 1, 'role' => 'editor' ) );
check( 'check-then-act: the count changes between the check and the write -> re-counted after, role put back, last_admin', 'last_admin' === code( $r ) && array( 'administrator' ) === get_userdata( 1 )->roles && 1 === count_users()['avail_roles']['administrator'] && false !== strpos( msg( $r ), 'put back' ), array( code( $r ), get_userdata( 1 )->roles ) );
check( '... no history row for a change that was undone', array() === hrows() );
check( '... the undo path leaves the marker (written before the change and again before the role was put back)', 2 === count( array_filter( calls( 'update_user_meta' ), function ( $c ) { return 1 === $c[1] && '_d5b_identity_changed_at' === $c[2]; } ) ) && '' !== get_user_meta( 1, '_d5b_identity_changed_at', true ) && 'recently_changed' === code( d5b_wu_reset( array( 'id' => 1 ) ) ) );
site( MANAGER, false, true, 9 );
$GLOBALS['T_after_count'] = function ( $n ) { if ( 1 === $n ) { t_set_roles( 2, array( 'editor' ) ); } };
$r = call( 'users-update', array( 'id' => 1, 'role' => 'editor', 'display_name' => 'Still Boss' ) );
check( '... with another field in the same call: role back, the other field recorded in a row named in the refusal', 'last_admin' === code( $r ) && array( 'administrator' ) === get_userdata( 1 )->roles && 'Still Boss' === get_userdata( 1 )->display_name && 1 === count( hrows() ) && ! isset( hlast()['after']['roles'] ) && (int) ( $r->get_error_data()['history_id'] ?? 0 ) === (int) hlast()['id'], array( code( $r ), hrows() ) );
site( MANAGER, false, true, 9 );
$pv = call( 'users-delete-preview', array( 'id' => 1, 'reassign_to' => 3 ) ); // two administrators: fine
$GLOBALS['T_after_count'] = function ( $n ) { if ( 2 === $n ) { t_set_roles( 2, array( 'editor' ) ); } };
$r = call( 'users-delete', array( 'id' => 1, 'reassign_to' => 3, 'confirmation' => $pv['confirmation'] ?? '' ) );
check( 'check-then-act, delete: the other administrator demoted after the first check -> counted again right before the delete, last_admin, nothing deleted, no row left', 'last_admin' === code( $r ) && array() === calls( 'wp_delete_user' ) && false !== get_userdata( 1 ) && array() === hrows(), array( code( $r ), hrows() ) );
site( array_merge( MANAGER, array( 'manage_network_users' ) ), true, true, 9 ); $GLOBALS['T_site_options']['site_admins'] = array( 'manager' );
t_add_user( 5, 'otherblog', array( 'administrator' ) ); $GLOBALS['T_user_blogs'][5] = array( 2 ); // an administrator of blog 2 only
check( 'network: two administrators here; one demoted fine', is_array( call( 'users-update', array( 'id' => 2, 'role' => 'editor' ) ) ) );
$r = call( 'users-update', array( 'id' => 1, 'role' => 'editor' ) );
check( 'network: the last administrator of THIS site -> last_admin (blog 2\'s administrator does not count)', 'last_admin' === code( $r ) && array( 'administrator' ) === get_userdata( 1 )->roles, code( $r ) );

// ---- users-password-reset
echo "users-password-reset\n";
site();
check( 'reset: your own account -> own_account, nothing sent', 'own_account' === code( call( 'users-password-reset', array( 'id' => 1 ) ) ) && array() === calls( 'retrieve_password' ) );
$r = call( 'users-password-reset', array( 'id' => 3 ) );
check( 'reset: { sent: true } only: no key, no link', array( 'sent' => true ) === $r, $r );
check( '... retrieve_password with the login, it found user 3; the link went to their address', array( array( 'retrieve_password', 'ed', 3 ) ) === calls( 'retrieve_password' ) && array( array( 'ed@example.org', 'password_reset_link' ) ) === $GLOBALS['T_user_mails'] );
check( '... one row: password_reset with after { sent: true }', 'password_reset' === hlast()['action'] && array( 'sent' => true ) === hlast()['after'] && 3 === (int) hlast()['object_id'] );
check( 'reset: the same user again within 10 minutes -> too_soon, nothing sent', 'too_soon' === code( call( 'users-password-reset', array( 'id' => 3 ) ) ) && 1 === count( calls( 'retrieve_password' ) ) );
// 2.4.1: fixed windows that start at multiples of their length (the 10-minute window of 1700000000 is 1699999800 to 1700000400).
$GLOBALS['T_clock'] = 1700000399.0;
check( '... at the end of the 10-minute window still too_soon', 'too_soon' === code( call( 'users-password-reset', array( 'id' => 3 ) ) ) );
$GLOBALS['T_clock'] = 1700000400.0;
check( '... in the next 10-minute window: sent', array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => 3 ) ) );
site();
for ( $i = 20; $i < 41; $i++ ) { t_add_user( $i, 'u' . $i, array( 'subscriber' ) ); }
$sent = 0; for ( $i = 20; $i < 40; $i++ ) { if ( array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => $i ) ) ) { $sent++; } }
check( 'reset: 20 different users in an hour: all sent', 20 === $sent, $sent );
check( '... the 21st -> too_soon (20 per site per hour)', 'too_soon' === code( call( 'users-password-reset', array( 'id' => 40 ) ) ) && 20 === count( calls( 'retrieve_password' ) ) );
$GLOBALS['T_clock'] = 1700002799.0; // the hour's window of 1700000000 is 1699999200 to 1700002800
check( '... still too_soon just before the hour\'s window is over', 'too_soon' === code( call( 'users-password-reset', array( 'id' => 40 ) ) ) );
$GLOBALS['T_clock'] = 1700002800.0;
check( '... in the next hour\'s window: sent again (the site-wide refusals a second ago did not spend user 40\'s 10-minute slot)', array( 'sent' => true ) === call( 'users-password-reset', array( 'id' => 40 ) ) );
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

echo "fix wave M3: the fields of another administrator or user manager\n";
site();
update_user_meta( 2, 'googleauthenticator_enabled', 'enabled' );
$r1 = call( 'users-meta-set', array( 'id' => 2, 'key' => 'googleauthenticator_enabled', 'value' => 'disabled' ) );
$r2 = call( 'users-meta-delete', array( 'id' => 2, 'key' => 'googleauthenticator_enabled' ) );
check( 'M3 another administrator\'s 2FA-style field: users-meta-set and -delete refused not_allowed, the field unchanged, no row', 'not_allowed' === code( $r1 ) && 'not_allowed' === code( $r2 ) && 'enabled' === get_user_meta( 2, 'googleauthenticator_enabled', true ) && array() === hrows(), array( code( $r1 ), code( $r2 ) ) );
$GLOBALS['T_roles']['usermanager'] = array( 'name' => 'User manager', 'capabilities' => array( 'read' => true, 'list_users' => true, 'edit_users' => true ) );
check( 'M3 ... a user manager without the administrator role (user 9: edit_users etc.) is protected too', 'not_allowed' === code( call( 'users-meta-set', array( 'id' => 9, 'key' => 'wfls-2fa', 'value' => '0' ) ) ) );
check( 'M3 ... the caller\'s own fields and an editor\'s are still writable', is_array( call( 'users-meta-set', array( 'id' => 1, 'key' => 'favourite_color', 'value' => 'red' ) ) ) && is_array( call( 'users-meta-set', array( 'id' => 3, 'key' => 'favourite_color', 'value' => 'red' ) ) ) );
site( NET_ADMIN, true ); $GLOBALS['T_site_options']['site_admins'] = array( 'boss', 'sub' );
check( 'M3 ... on a network a super admin is protected whatever their role here', 'not_allowed' === code( call( 'users-meta-set', array( 'id' => 4, 'key' => 'favourite_color', 'value' => 'x' ) ) ) );

echo "fix wave M5: a powerful role needs promote_users even when it is the default\n";
site( array( 'read', 'list_users', 'create_users' ) );
$GLOBALS['T_options']['default_role'] = 'administrator';
$r = call( 'users-create', array( 'login' => 'sneaky', 'email' => 'sneaky@example.org', 'role' => 'administrator' ) );
check( 'M5 default_role administrator, a caller with create_users only: not_allowed, no user made', 'not_allowed' === code( $r ) && false === username_exists( 'sneaky' ) && array() === calls( 'wp_insert_user' ), code( $r ) );
$GLOBALS['T_roles']['usermanager'] = array( 'name' => 'User manager', 'capabilities' => array( 'read' => true, 'list_users' => true, 'edit_users' => true ) );
$GLOBALS['T_options']['default_role'] = 'usermanager';
check( 'M5 ... nor a default role that holds edit_users (a custom role)', 'not_allowed' === code( call( 'users-create', array( 'login' => 'sneaky2', 'email' => 'sneaky2@example.org', 'role' => 'usermanager' ) ) ) && false === username_exists( 'sneaky2' ) );
$GLOBALS['T_options']['default_role'] = 'subscriber';
check( 'M5 ... the plain default role is still given without promote_users', is_array( call( 'users-create', array( 'login' => 'plainone', 'email' => 'plainone@example.org', 'role' => 'subscriber' ) ) ) );
site();
$GLOBALS['T_options']['default_role'] = 'subscriber';
check( 'M5 ... and with promote_users an administrator can be created', is_array( call( 'users-create', array( 'login' => 'newadmin', 'email' => 'newadmin@example.org', 'role' => 'administrator' ) ) ) );

echo "fix wave M4: one administrator-count change at a time\n";
// A delete of administrator 2 while another request (right after our final count) tries to demote administrator 1.
site( MANAGER, false, true, 9 );
t_add_user( 5, 'heir', array( 'author' ) );
$p = call( 'users-delete-preview', array( 'id' => 2, 'reassign_to' => 5 ) );
$other = null;
$GLOBALS['T_after_count'] = function ( $k ) use ( &$other ) {
	if ( $k < 2 || null !== $other ) { return; }
	$other = 'running'; // once (the other request counts too)
	$saved = $GLOBALS['d5b_maint_locks'] ?? array(); $GLOBALS['d5b_maint_locks'] = array(); // another request
	$other = call( 'users-update', array( 'id' => 1, 'role' => 'editor' ) );
	$GLOBALS['d5b_maint_locks'] = $saved;
};
$GLOBALS['T_count_users'] = 0;
$r = call( 'users-delete', array( 'id' => 2, 'reassign_to' => 5, 'confirmation' => $p['confirmation'] ?? '' ) );
$GLOBALS['T_after_count'] = null;
check( 'M4 a demote while a delete holds the administrators lock: busy (409), the site keeps administrator 1', 'busy' === code( $other ) && array( 'administrator' ) === get_userdata( 1 )->roles && is_array( $r ) && false === get_userdata( 2 ), array( code( $other ), code( $r ) ) );
check( 'M4 ... the lock is released after the delete', '' === d5b_maint_lock_fresh( 'd5b_users_admin_lock' ) );
// The other way round: a delete while a role change holds it.
site( MANAGER, false, true, 9 );
t_add_user( 5, 'heir', array( 'author' ) );
$p = call( 'users-delete-preview', array( 'id' => 1, 'reassign_to' => 5 ) );
$other = null;
$GLOBALS['T_after_count'] = function ( $k ) use ( &$other, $p ) {
	if ( null !== $other ) { return; }
	$other = 'running'; // once (the other request counts too)
	$saved = $GLOBALS['d5b_maint_locks'] ?? array(); $GLOBALS['d5b_maint_locks'] = array();
	$other = call( 'users-delete', array( 'id' => 1, 'reassign_to' => 5, 'confirmation' => $p['confirmation'] ?? '' ) );
	$GLOBALS['d5b_maint_locks'] = $saved;
};
$r = call( 'users-update', array( 'id' => 2, 'role' => 'editor' ) );
$GLOBALS['T_after_count'] = null;
check( 'M4 a delete while a role change holds the lock: busy, nothing deleted; the role change went through', 'busy' === code( $other ) && false !== get_userdata( 1 ) && is_array( $r ) && array( 'editor' ) === get_userdata( 2 )->roles && array() === calls( 'wp_delete_user' ), array( code( $other ), code( $r ) ) );
site();
$GLOBALS['T_options']['d5b_users_admin_lock'] = str_repeat( 'f', 16 ) . '|' . ( (int) $GLOBALS['T_clock'] + 20 ); // another request's row
check( 'M4 the lock held by another request: a role change and a delete are busy; a change without a role is not held up', 'busy' === code( call( 'users-update', array( 'id' => 3, 'role' => 'author' ) ) ) && 'busy' === code( call( 'users-delete', array( 'id' => 3, 'reassign_to' => 1, 'confirmation' => 'x' ) ) ) && is_array( call( 'users-update', array( 'id' => 3, 'display_name' => 'Ed' ) ) ) && array( 'editor' ) === get_userdata( 3 )->roles );
$GLOBALS['T_clock'] += 21;
// F2: on a network the administrators lock stays per site (each site counts its own administrators): this site's options table.
site( NET_ADMIN, true ); switch_to_blog( 2 );
$token = d5b_wu_admin_lock();
check( 'F2 network: the administrators lock is a row of this site\'s options table (wp_2_options), not the main site\'s', is_string( $token ) && $token === ( $GLOBALS['T_blogs'][2]['d5b_users_admin_lock'] ?? null ) && ! isset( $GLOBALS['T_options']['d5b_users_admin_lock'] ) && $token === d5b_maint_lock_fresh( 'd5b_users_admin_lock' ), $token );
d5b_maint_lock_release( 'd5b_users_admin_lock', $token ); restore_current_blog();
site();
$GLOBALS['T_options']['d5b_users_admin_lock'] = str_repeat( 'f', 16 ) . '|' . ( (int) $GLOBALS['T_clock'] + 20 );
$GLOBALS['T_clock'] += 21;
check( 'M4 ... past its 30 s it is taken over', is_array( call( 'users-update', array( 'id' => 3, 'role' => 'author' ) ) ) && array( 'author' ) === get_userdata( 3 )->roles );
check( 'meta-set: a value that is an object -> invalid_input', 'invalid_input' === code( d5b_wu_meta_set( array( 'id' => 4, 'key' => 'k1', 'value' => new ArrayObject( array() ) ) ) ) );
// Fix round 1 S4: a vendor-shaped key in an ordinary user meta value (split literal: no whole key in the source).
site();
$ghk = 'gh' . 'p_' . str_repeat( 'A', 36 );
update_user_meta( 3, 'deploy_note', 'ci uses x=' . $ghk . ' today' );
$pv = call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 4 ) );
check( 'S4 users-delete-preview: a GitHub token in a user meta value is masked in the record, the field and the rest of its text shown', is_array( $pv ) && false === strpos( json_encode( $pv ), $ghk ) && array( 'ci uses x=[redacted] today' ) === ( $pv['user']['meta']['deploy_note'] ?? null ), $pv['user']['meta'] ?? $pv );
$ms = call( 'users-meta-set', array( 'id' => 4, 'key' => 'deploy_note', 'value' => 'token ' . $ghk ) );
d5b_abilities_set_category( 'history', true, false, 'tester' ); t_set_caps( array_merge( ADMIN, array( 'manage_options' ) ) ); // the history reads need manage_options
$hg =is_array( $ms ) ? call( 'history-change-get', array( 'id' => (int) ( $ms['history_id'] ?? 0 ) ) ) : null;
check( 'S4 users-meta-set with a GitHub token: the answer and the history read (history-change-get) mask it (the row keeps the value as given, for the undo)', is_array( $ms ) && false === strpos( json_encode( $ms ), $ghk ) && is_array( $hg ) && false === strpos( json_encode( $hg ), $ghk ) && false !== strpos( json_encode( $hg ), 'deploy_note' ), array( $ms, $hg ) );

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
update_user_meta( 3, '_wpseo_profile_flag', 'PROTECTED-VALUE' ); update_user_meta( 3, 'smtp_password', 'S3CR3T-PW' ); update_user_meta( 3, 'favourite_colour', 'teal' );
$pv = call( 'users-delete-preview', array( 'id' => 3, 'reassign_to' => 4 ) );
$ids = t_author_ids( 3 );
$r = call( 'users-delete', array( 'id' => 3, 'reassign_to' => 4, 'confirmation' => $pv['confirmation'] ) );
$hw = d5b_history_get( (int) ( $r['history_id'] ?? 0 ) );
check( '2.4.1 delete: the row names the protected and secret-named meta keys it could not keep (names only, sorted), never their values', is_array( $hw ) && array( '_wpseo_profile_flag', 'session_tokens', 'smtp_password' ) === ( $hw['before']['withheld_meta_keys'] ?? null ) && false === strpos( json_encode( hrows() ), 'S3CR3T-PW' ) && false === strpos( json_encode( hrows() ), 'PROTECTED-VALUE' ) && array( 'teal' ) === ( $hw['before']['meta']['favourite_colour'] ?? null ), $hw['before'] ?? null );
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
