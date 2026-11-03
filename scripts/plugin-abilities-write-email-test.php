<?php
/**
 * The email ability (#32, release 2.4.0, Task 7): email-send-test, in the email category.
 *
 *   php -d max_execution_time=60 scripts/plugin-abilities-write-email-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php with T_USER_STANDINS, plus its wp_mail, which honours
 * T_mail_fail and fires wp_mail_failed as core's PHPMailer catch block does). Set D5B_PLUGIN_DIR to test another copy of
 * plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: recipients only me, admin or an existing user (never an address); a fixed subject and body with the
 * note after it; the note plain text, at most 500 characters, secret-free; 5 tries per hour per site, a failed send counted too;
 * the wp_mail_failed message captured in mailer_error; one history row that never holds the address; a normal write (no risky
 * switch) behind the email category, which is off when the stored switches do not name it.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.4.0' );
define( 'T_USER_STANDINS', true );
require __DIR__ . '/plugin-test-stubs.php';
function wp_json_encode( $v ) { return json_encode( $v ); }

foreach ( array( 'inc/parts', 'inc/switches', 'inc/abilities/categories', 'inc/abilities/register', 'inc/abilities/secrets', 'inc/abilities/audit', 'inc/abilities/scrub', 'inc/abilities/history', 'inc/abilities/read-content', 'inc/abilities/read-site', 'inc/abilities/read-history', 'inc/abilities/write-common', 'inc/abilities/confirm', 'inc/abilities/maint-common', 'inc/abilities/write-email' ) as $f ) { require $dir . '/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 800 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

$GLOBALS['d5b_maint_seams'] = array( 'now' => function () { return $GLOBALS['T_clock']; } );

// Users: 1 boss (administrator, the caller), 3 ed (editor).
function site( array $caps = array( 'read', 'manage_options' ), bool $multi = false, bool $cat = true ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( $multi );
	t_add_user( 1, 'boss', array( 'administrator' ) ); t_add_user( 3, 'ed', array( 'editor' ) );
	$GLOBALS['T_user_id'] = 1; $GLOBALS['T_user_login'] = 'boss'; $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	$GLOBALS['T_options']['blogname'] = 'Bob &amp; Sons'; $GLOBALS['T_options']['admin_email'] = 'office@example.net';
	$GLOBALS['T_mails'] = array(); $GLOBALS['T_mail_fail'] = false; $GLOBALS['T_wp_date'] = '2 November 2026 10:15';
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
	if ( $cat ) { d5b_abilities_set_category( 'email', true, false, 'tester' ); }
	$GLOBALS['T_clock'] = 1700000000.0;
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function hrows() { global $wpdb; return array_values( $wpdb->tables[ d5b_history_table() ] ?? array() ); }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function arows() { global $wpdb; return array_values( $wpdb->tables[ d5b_audit_table() ] ?? array() ); }
function mails() { return $GLOBALS['T_mails']; }
// The limit's count: the option row of this hour's window (windows start at multiples of 3600 s).
function rate_count() { return (int) ( $GLOBALS['T_options'][ 'd5b_rate_email_test_' . ( (int) floor( $GLOBALS['T_clock'] / 3600 ) * 3600 ) ] ?? 0 ); }
function send( $in ) { return call( 'email-send-test', $in ); }

// ---- registration and switches
echo "registration and switches\n";
site();
$defs = d5b_ability_defs();
$d    = $defs['divi5-builder/email-send-test'] ?? null;
check( 'email-send-test exists in the email category', is_array( $d ) && 'email' === $d['category'] );
check( 'a normal write: no risky key, not readonly, not destructive', is_array( $d ) && ! array_key_exists( 'risky', $d ) && false === $d['annotations']['readonly'] && false === $d['annotations']['destructive'] );
check( 'inputs: to (required) and note only', is_array( $d ) && array( 'note', 'to' ) === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $d['input_schema']['properties'] ) ) && array( 'to' ) === ( $d['input_schema']['required'] ?? null ) );
$cats = d5b_ability_categories();
check( 'the email category: "Email", "Send a test email to a site user or the admin address."', isset( $cats['email'] ) && 'Email' === $cats['email']['label'] && 'Send a test email to a site user or the admin address.' === $cats['email']['description'] && 'divi5-builder-email' === $cats['email']['wp_slug'] );
check( 'email has no risky box', false === d5b_category_has_risky( 'email' ) );
check( 'too_soon is 429, invalid_input 400, not_found 404 (never 500)', 429 === d5b_ability_http_status( 'too_soon' ) && 400 === d5b_ability_http_status( 'invalid_input' ) && 404 === d5b_ability_http_status( 'not_found' ) );
site( array( 'read', 'manage_options' ), false, false );
check( 'a stored switch set that does not name email (a site upgraded from 2.3.0): email is off, the ability refused', false === d5b_abilities_category_on( 'email' ) && false === d5b_abilities_switches()['categories']['email']['on'] && 'FORBIDDEN' === send( array( 'to' => 'me' ) ) && array() === mails() );
check( '... while a category that was never stored before 2.4.0 still reads on (unchanged)', true === d5b_abilities_category_on( 'comments' ) );
site();
d5b_abilities_set_category( 'email', false, false, 'tester' );
check( 'email switched off: refused', 'FORBIDDEN' === send( array( 'to' => 'me' ) ) && array() === mails() );
site( array( 'read', 'edit_posts' ) );
check( 'without manage_options: refused', 'FORBIDDEN' === send( array( 'to' => 'me' ) ) && array() === mails() );
check( '... and the handler refuses on its own as well', 'forbidden' === code( d5b_wem_send( array( 'to' => 'me' ) ) ) && array() === mails() );

// ---- recipients
echo "recipients\n";
site();
$r = send( array( 'to' => 'me' ) );
$m = mails()[0] ?? array();
check( 'to me: sent to the caller\'s own address', array( 'sent' => true, 'mailer_error' => null ) === $r && 'boss@example.org' === $m[0], array( $r, $m ) );
check( 'the subject: Test email from <site name> (entities decoded)', 'Test email from Bob & Sons' === $m[1], $m );
$lines = explode( "\n", (string) $m[2] );
check( 'the body: site address, time (wp_date), who asked, in that order', false !== strpos( $m[2], 'https://x.example' ) && false !== strpos( $m[2], '2 November 2026 10:15' ) && false !== strpos( $m[2], 'boss' )
	&& strpos( $m[2], 'https://x.example' ) < strpos( $m[2], '2 November 2026 10:15' ) && strpos( $m[2], '2 November 2026 10:15' ) < strpos( $m[2], 'boss' ), $m[2] );
$h = hlast();
check( 'one history row: object_type email, action send, after { to_kind me, sent true, mailer_error null }, no before', 1 === count( hrows() ) && 'email' === $h['object_type'] && 'send' === $h['action'] && in_array( $h['before'], array( null, array() ), true ) && array( 'to_kind' => 'me', 'sent' => true, 'mailer_error' => null ) === $h['after'], $h );
check( 'the address is in no row (history or audit) and not in the result', false === strpos( json_encode( array( hrows(), arows(), $r ) ), 'boss@example.org' ) );
site();
$r = send( array( 'to' => 'admin' ) );
check( 'to admin: sent to admin_email; the row says to_kind admin', array( 'sent' => true, 'mailer_error' => null ) === $r && 'office@example.net' === ( mails()[0][0] ?? '' ) && 'admin' === hlast()['after']['to_kind'] && false === strpos( json_encode( array( hrows(), arows() ) ), 'office@example.net' ) );
site();
$r = send( array( 'to' => 'user:3' ) );
check( 'to user:3: sent to that user\'s address; the row says to_kind user, user_id 3', array( 'sent' => true, 'mailer_error' => null ) === $r && 'ed@example.org' === ( mails()[0][0] ?? '' ) && array( 'to_kind' => 'user', 'user_id' => 3, 'sent' => true, 'mailer_error' => null ) === hlast()['after'] && 3 === (int) hlast()['object_id'] && false === strpos( json_encode( array( hrows(), arows() ) ), 'ed@example.org' ), hlast() );
site();
$bad = array( 'someone@example.com', 'user:', 'user:0', 'user:03', 'user:-1', 'user:3 ', ' me', 'ME', 'admin,me', "me\n", 'user:3,user:1', 'user:abc', '', 'all' );
$ok = true; $seen = array();
foreach ( $bad as $b ) { $c = code( send( array( 'to' => $b ) ) ); $seen[ $b ] = $c; if ( 'invalid_input' !== $c ) { $ok = false; } }
check( 'an arbitrary address and every other form refused with invalid_input, nothing sent', $ok && array() === mails() && array() === hrows(), $seen );
check( 'to as a list or a number: invalid_input', 'invalid_input' === code( send( array( 'to' => array( 'me' ) ) ) ) && 'invalid_input' === code( send( array( 'to' => 3 ) ) ) && array() === mails() );
check( 'a missing user (user:99): not_found, nothing sent', 'not_found' === code( send( array( 'to' => 'user:99' ) ) ) && array() === mails() );
check( 'an unknown input: invalid_input', 'invalid_input' === code( send( array( 'to' => 'me', 'cc' => 'x@example.com' ) ) ) && array() === mails() );
site( array( 'read', 'manage_options' ), true );
$GLOBALS['T_user_blogs'][5] = array( 2 ); $GLOBALS['T_users'][5] = (object) array( 'ID' => 5, 'user_login' => 'elsewhere', 'user_email' => 'e@example.org', 'display_name' => 'E', 'user_url' => '', 'user_registered' => '', 'roles' => array() );
check( 'network: a user of another site is not found here', 'not_found' === code( send( array( 'to' => 'user:5' ) ) ) && array() === mails() );
site();
$GLOBALS['T_options']['admin_email'] = 'not an address';
check( 'admin_email not an address: no_email (409, a state of the site, not a server error), nothing sent', 'no_email' === code( send( array( 'to' => 'admin' ) ) ) && 409 === d5b_ability_http_status( 'no_email' ) && array() === mails() );
$GLOBALS['T_users'][1]->user_email = '';
check( '... the caller without an address (to me): no_email', 'no_email' === code( send( array( 'to' => 'me' ) ) ) && array() === mails() );
$GLOBALS['T_users'][3] = (object) array( 'ID' => 3, 'user_login' => 'noaddr', 'user_email' => 'not-an-address', 'display_name' => 'N', 'user_url' => '', 'user_registered' => '', 'roles' => array( 'editor' ) );
check( '... a user without a valid address: no_email', 'no_email' === code( send( array( 'to' => 'user:3' ) ) ) && array() === mails() );
check( 'the invalid tries spent no slot of the limit', 0 === rate_count() );

// M12: a site name can never add a header line: an entity-encoded CR/LF stays text (WordPress's own decode), raw CR/LF/NUL go.
foreach ( array( "Shop&#13;&#10;Bcc: spy@evil.example", "Shop\r\nBcc: spy@evil.example", "Shop\nBcc: spy@evil.example\0", 'Shop&#x0d;&#x0a;Bcc: spy@evil.example' ) as $i => $bn ) {
	site(); $GLOBALS['T_options']['blogname'] = $bn;
	send( array( 'to' => 'me' ) );
	$subj = (string) ( mails()[0][1] ?? '' );
	check( 'M12 site name #' . $i . ' with a line break (encoded or raw): the subject has no CR, LF or NUL', '' !== $subj && 1 !== preg_match( '/[\r\n\x00]/', $subj ) && 0 === strpos( $subj, 'Test email from Shop' ), json_encode( $subj ) );
	// Decoded as WordPress decodes the site name for its own mail (wp_specialchars_decode): a numeric line break stays text.
	if ( 0 === $i ) { check( 'M12 ... the encoded one stays as text, as in WordPress\'s own emails', 'Test email from Shop&#13;&#10;Bcc: spy@evil.example' === $subj, json_encode( $subj ) ); }
}

// ---- the note
echo "the note\n";
site();
$r = send( array( 'to' => 'me', 'note' => "Checking SMTP after the move.\nThanks" ) );
$body = (string) ( mails()[0][2] ?? '' );
check( 'the note comes after the fixed text', is_array( $r ) && false !== strpos( $body, "Checking SMTP after the move.\nThanks" ) && strpos( $body, 'boss' ) < strpos( $body, 'Checking SMTP' ), $body );
check( 'the note is not in the history row (only to_kind, sent, mailer_error)', false === strpos( json_encode( hrows() ), 'Checking SMTP' ) );
site();
check( '500 characters: sent', is_array( send( array( 'to' => 'me', 'note' => str_repeat( 'é', 500 ) ) ) ) && 1 === count( mails() ) );
check( '501 characters: invalid_input, nothing sent', 'invalid_input' === code( send( array( 'to' => 'me', 'note' => str_repeat( 'a', 501 ) ) ) ) && 1 === count( mails() ) );
check( 'HTML: invalid_input (plain text only)', 'invalid_input' === code( send( array( 'to' => 'me', 'note' => 'Hi <a href="https://evil.example">click</a>' ) ) ) && 1 === count( mails() ) );
check( 'a note with a secret in it: refused with invalid_input, nothing sent', 'invalid_input' === code( send( array( 'to' => 'me', 'note' => 'password=hunter2' ) ) ) && 'invalid_input' === code( send( array( 'to' => 'me', 'note' => 'key sk_' . 'live_51HxYzAbCdEfGhIjKlMnOpQr' ) ) ) && 1 === count( mails() ) );
check( 'a note that is not text: invalid_input', 'invalid_input' === code( send( array( 'to' => 'me', 'note' => array( 'x' ) ) ) ) && 1 === count( mails() ) );

// ---- a failed send
echo "a failed send\n";
site();
$GLOBALS['T_mail_fail'] = 'SMTP Error: Could not authenticate.';
$r = send( array( 'to' => 'admin' ) );
check( 'failed: sent false, mailer_error the wp_mail_failed message (not a WP_Error)', array( 'sent' => false, 'mailer_error' => 'SMTP Error: Could not authenticate.' ) === $r, $r );
check( '... the row says the same', array( 'to_kind' => 'admin', 'sent' => false, 'mailer_error' => 'SMTP Error: Could not authenticate.' ) === hlast()['after'], hlast() );
check( '... the listener is removed afterwards', array() === ( $GLOBALS['T_actions']['wp_mail_failed'] ?? array() ) );
check( '... the attempt counted against the limit', 1 === rate_count() );
$GLOBALS['T_mail_fail'] = false;
$r = send( array( 'to' => 'admin' ) );
check( 'a later good send does not repeat the old error', array( 'sent' => true, 'mailer_error' => null ) === $r, $r );
site();
$GLOBALS['T_mail_fail'] = 'SMTP Error: The following recipients failed: ED@example.org: mailbox full';
$r = send( array( 'to' => 'user:3' ) );
check( 'a mailer error naming the recipient: the address is replaced in the answer and the row', 'SMTP Error: The following recipients failed: [recipient]: mailbox full' === ( $r['mailer_error'] ?? '' ) && false === stripos( json_encode( array( hrows(), arows(), $r ) ), 'ed@example.org' ), $r );
// 2.4.1: a mailer (an API mailer plugin) that echoes its key in the error; split literals, no whole key in the source.
site();
$sgk = 'xo' . 'xb-' . '1234567890-abcdEF'; $awk = 'AS' . 'IA' . str_repeat( 'Z3', 8 );
$GLOBALS['T_mail_fail'] = 'API error: ' . $sgk . ' rejected for id' . $awk;
$r = send( array( 'to' => 'admin' ) );
check( 'a mailer error holding a Slack token and a glued AWS id: masked in the answer and the row', is_array( $r ) && false === strpos( json_encode( array( hrows(), arows(), $r ) ), $sgk ) && false === strpos( json_encode( array( hrows(), arows(), $r ) ), $awk ) && false !== strpos( (string) $r['mailer_error'], 'API error' ), $r );

// ---- the rate limit
echo "the rate limit\n";
site();
$GLOBALS['T_mail_fail'] = 'Could not instantiate mail function.';
$outs = array(); for ( $i = 0; $i < 5; $i++ ) { $outs[] = send( array( 'to' => 'me' ) ); }
check( 'five failed sends in an hour: each tried', 5 === count( mails() ) && 5 === count( array_filter( $outs, function ( $o ) { return is_array( $o ) && false === $o['sent']; } ) ) );
$GLOBALS['T_mail_fail'] = false;
check( 'the sixth in the hour: too_soon, nothing sent (failures count)', 'too_soon' === code( send( array( 'to' => 'me' ) ) ) && 5 === count( mails() ) );
$GLOBALS['T_clock'] += 3601;
check( 'an hour later: sent again', is_array( send( array( 'to' => 'me' ) ) ) && 6 === count( mails() ) );
site();
for ( $i = 0; $i < 5; $i++ ) { send( array( 'to' => 'user:3' ) ); }
check( 'five good sends, then the sixth: too_soon', 'too_soon' === code( send( array( 'to' => 'admin' ) ) ) && 5 === count( mails() ) && 5 === count( hrows() ) );

echo "\n" . ( $failed ? "$failed of $n FAILED\n" : "all $n passed\n" );
exit( $failed ? 1 : 0 );
