<?php
/**
 * The audit log (#32, Task 3).
 *
 *   php scripts/plugin-abilities-audit-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php, which keeps the tables in memory).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: every ability call writes exactly one row; secrets, long strings and a thrown handler's
 * exception text are masked before they are stored; the args column is valid JSON of at most 8 KB; rows older than
 * 30 days (and only those) are pruned; a missing table never breaks a call; every SQL value goes through prepare();
 * the admin list needs the settings capability and escapes every field. (Uninstall is in plugin-uninstall-test.php.)
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.1.0' );
require __DIR__ . '/plugin-test-stubs.php';
require $dir . '/inc/parts.php';
require $dir . '/inc/switches.php';
require $dir . '/inc/abilities/categories.php';
require $dir . '/inc/abilities/register.php';
require $dir . '/inc/abilities/audit.php';
require $dir . '/inc/settings.php';

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

function fresh() { t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( array() ); t_set_multisite( false ); $_GET = array(); $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; unset( $_SERVER['HTTP_X_FORWARDED_FOR'] ); d5b_ability_registry( array() ); }
function installed() { fresh(); d5b_audit_maybe_install(); }
function rows() { global $wpdb; return $wpdb->tables[ d5b_audit_table() ] ?? array(); }
function last_args() { $r = rows(); return json_decode( $r[ count( $r ) - 1 ]['args'], true ); }
function stored_row_at( $secs_ago, $ability = 'divi5-builder/posts-list' ) {
	global $wpdb; $wpdb->tables[ d5b_audit_table() ][] = array( 'id' => 900 + count( $wpdb->tables[ d5b_audit_table() ] ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $secs_ago ), 'user_id' => 1, 'ability' => $ability, 'args' => '{}', 'status' => 'ok', 'duration_ms' => 1, 'ip' => '' );
}
function html( $fn ) { ob_start(); $fn(); return ob_get_clean(); }

echo "install\n";
fresh();
check( 'before install there is no table and no version', array() === $wpdb->tables && false === get_option( 'd5b_db_version' ) && '1' === D5B_DB_VERSION );
d5b_audit_maybe_install();
check( 'install creates the table once and sets d5b_db_version', array_key_exists( 'wp_d5b_audit', $wpdb->tables ) && '1' === get_option( 'd5b_db_version' ) && 1 === count( $GLOBALS['T_dbdelta'] ), array( array_keys( $wpdb->tables ), $GLOBALS['T_dbdelta'] ) );
d5b_audit_maybe_install();
check( 'a second call does nothing (dbDelta not run again)', 1 === count( $GLOBALS['T_dbdelta'] ) );
$sql = $GLOBALS['T_dbdelta'][0];
check( 'the table has the eight columns, both indexes and two spaces after PRIMARY KEY (dbDelta needs them)', 1 === preg_match( '/\bid bigint\(20\) unsigned NOT NULL AUTO_INCREMENT,\s+created_at datetime NOT NULL,\s+user_id bigint\(20\) unsigned.*ability varchar\(191\).*args longtext.*status varchar\(64\).*duration_ms int.*ip varchar\(45\).*PRIMARY KEY  \(id\).*KEY created_at \(created_at\).*KEY ability \(ability\)/s', $sql ), $sql );
check( 'the daily prune event is scheduled', isset( $GLOBALS['T_cron']['d5b_audit_prune'] ) );
d5b_audit_deactivate();
check( 'deactivation clears the event (the table and the log stay)', ! isset( $GLOBALS['T_cron']['d5b_audit_prune'] ) && isset( $wpdb->tables['wp_d5b_audit'] ) );
fresh(); update_option( 'd5b_db_version', '0' ); d5b_audit_maybe_install();
check( 'an older stored version installs again', 1 === count( $GLOBALS['T_dbdelta'] ) && '1' === get_option( 'd5b_db_version' ) );

echo "write\n";
installed();
$GLOBALS['T_user_id'] = 7;
$ok = d5b_audit_write( 'divi5-builder/posts-list', array( 'search' => 'hello', 'password' => 'hunter2', 'application_password' => 'abcd efgh', 'token' => 't0k', 'api_key' => 'k', 'Authorization' => 'x', 'cookie' => 'c', 'nonce' => 'n', 'secret' => 's', 'nested' => array( 'user_pass' => 'p', 'keep' => 'me', 'deeper' => array( 'access_token' => 'zzz', 'ok' => 1 ) ) ), 'ok', 12 );
$a = last_args(); $r = rows()[0];
check( 'write returns true and stores one row', true === $ok && 1 === count( rows() ) );
check( 'row fields: ability, user, status, duration, UTC time, ip', 'divi5-builder/posts-list' === $r['ability'] && 7 === $r['user_id'] && 'ok' === $r['status'] && 12 === $r['duration_ms'] && '203.0.113.9' === $r['ip'] && 1 === preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $r['created_at'] ) && abs( strtotime( $r['created_at'] . ' UTC' ) - time() ) < 5, $r );
check( 'write stores redacted args: password, application_password, token and the other secret keys show [redacted]', '[redacted]' === $a['password'] && '[redacted]' === $a['application_password'] && '[redacted]' === $a['token'] && '[redacted]' === $a['api_key'] && '[redacted]' === $a['Authorization'] && '[redacted]' === $a['cookie'] && '[redacted]' === $a['nonce'] && '[redacted]' === $a['secret'], $a );
check( 'other values are kept', 'hello' === $a['search'] && 'me' === $a['nested']['keep'] && 1 === $a['nested']['deeper']['ok'] );
check( 'redaction recurses into nested arrays', '[redacted]' === $a['nested']['user_pass'] && '[redacted]' === $a['nested']['deeper']['access_token'], $a );
check( 'no secret value is anywhere in the stored text', ! preg_match( '/hunter2|abcd efgh|t0k/', $r['args'] ) );
check( 'redact() on its own: same result, input untouched', array( 'a_token' => '[redacted]', 'b' => 'x' ) === d5b_audit_redact( array( 'a_token' => 'q', 'b' => 'x' ) ) );
$_SERVER['REMOTE_ADDR'] = '198.51.100.4'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4'; $_SERVER['HTTP_CLIENT_IP'] = '5.6.7.8';
d5b_audit_write( 'x/y', array(), 'ok', 1 );
check( 'the ip is REMOTE_ADDR only (a forwarded header is ignored)', '198.51.100.4' === rows()[1]['ip'], rows()[1]['ip'] );
$_SERVER['REMOTE_ADDR'] = 'not an ip<script>'; d5b_audit_write( 'x/y', array(), 'ok', 1 );
check( 'a REMOTE_ADDR that is not an address is stored empty', '' === rows()[2]['ip'] );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP'] ); $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
d5b_audit_write( str_repeat( 'a', 300 ), array(), str_repeat( 's', 100 ), -5 );
check( 'ability is cut to 191, status to 64, a negative duration to 0', 191 === strlen( rows()[3]['ability'] ) && 64 === strlen( rows()[3]['status'] ) && 0 === rows()[3]['duration_ms'] );

echo "long strings\n";
installed();
d5b_audit_write( 'a/b', array( 'long' => str_repeat( 'x', 5000 ), 'exact' => str_repeat( 'y', 1024 ), 'multi' => str_repeat( 'é', 2000 ), 'nested' => array( 'deep' => str_repeat( 'z', 1500 ) ) ), 'ok', 1 );
$a = last_args(); $suffix = '…[truncated]';
check( 'long arg truncated to 1024 chars (then the marker)', str_repeat( 'x', 1024 ) . $suffix === $a['long'], strlen( $a['long'] ) );
check( 'a string of exactly 1024 characters is kept whole', str_repeat( 'y', 1024 ) === $a['exact'] );
check( 'truncation counts characters, not bytes (no broken UTF-8)', str_repeat( 'é', 1024 ) . $suffix === $a['multi'] );
check( 'nested long strings are truncated too', str_repeat( 'z', 1024 ) . $suffix === $a['nested']['deep'] );

echo "_exception\n";
installed();
$cap = function ( $s ) { d5b_audit_write( 'a/b', array( '_exception' => $s ), 'internal_error', 1 ); return last_args()['_exception']; };
$e = $cap( 'RuntimeException: ' . str_repeat( 'boom ', 200 ) );
check( '_exception is capped at 300 characters in all', mb_strlen( $e, 'UTF-8' ) <= 300 && 'RuntimeException: boom' === substr( $e, 0, 22 ) && '…[truncated]' === mb_substr( $e, -12, null, 'UTF-8' ), array( mb_strlen( $e, 'UTF-8' ), $e ) );
check( 'a short _exception is kept as it is', 'PDOException: no such table' === $cap( 'PDOException: no such table' ) );
check( 'a secret past the cut is not left half-written', false === strpos( $cap( str_repeat( 'a', 290 ) . ' password=SECRETVALUE12345' ), 'SECRET' ) );
foreach ( array(
	'password=...'      => array( 'Error connecting: password=hunter2 failed', 'hunter2', 'password=[redacted]' ),
	'pass=...'          => array( 'bad request ?user=bob&pass=letmein&x=1', 'letmein', 'pass=[redacted]' ),
	'token=...'         => array( 'GET /feed?token=abc123def456 failed', 'abc123def456', 'token=[redacted]' ),
	'secret=...'        => array( 'config secret=s3cr3tvalue missing', 's3cr3tvalue', 'secret=[redacted]' ),
	'key=...'           => array( 'call to https://api.example/v1?key=AIzaSyDEADBEEF failed', 'AIzaSyDEADBEEF', 'key=[redacted]' ),
	'api_key: (colon)'  => array( 'headers: api_key: sk-live-999', 'sk-live-999', 'api_key: [redacted]' ),
	'Authorization:'    => array( "cURL error\nAuthorization: Basic dXNlcjpwYXNz\nnext line", 'dXNlcjpwYXNz', 'Authorization: [redacted]' ),
	'Bearer'            => array( 'sent Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig to server', 'eyJhbGciOiJIUzI1NiJ9', 'Bearer [redacted]' ),
	'user:pass@ in URL' => array( 'Could not reach https://admin:p4ssw0rd@db.example.com/x', 'p4ssw0rd', 'https://[redacted]@db.example.com/x' ),
) as $label => $c ) {
	$out = $cap( $c[0] );
	check( "_exception masks $label", false === strpos( $out, $c[1] ) && false !== strpos( $out, $c[2] ), $out );
}
check( '_exception keeps the rest of the message readable', false !== strpos( $cap( 'RuntimeException: Table x is missing; password=abc' ), 'RuntimeException: Table x is missing;' ) );
check( 'a secret-looking pattern in another string is NOT rewritten (only _exception is masked, other keys keep the 1024 rule)', 'see password=abc' === ( function () { d5b_audit_write( 'a/b', array( 'note' => 'see password=abc' ), 'ok', 1 ); return last_args()['note']; } )() );
d5b_audit_write( 'a/b', array( 'n' => array( '_exception' => 'E: password=zzz9 ' . str_repeat( 'q', 2000 ) ) ), 'x', 1 ); $ne = last_args()['n']['_exception'];
check( '_exception inside a nested array gets the same mask and cap', mb_strlen( $ne, 'UTF-8' ) <= 300 && false === strpos( $ne, 'zzz9' ), $ne );

echo "8 KB limit\n";
installed();
$many = array(); for ( $i = 0; $i < 400; $i++ ) { $many[ 'field_' . $i ] = str_repeat( 'v', 100 ); }
d5b_audit_write( 'a/b', $many, 'ok', 1 );
$raw = rows()[0]['args']; $j = json_decode( $raw, true );
check( 'args over 8192 bytes store a valid JSON placeholder, not cut JSON', null !== $j && true === $j['_truncated'] && is_int( $j['bytes'] ) && $j['bytes'] > 8192 && strlen( $raw ) < 100, $raw );
$fit = array(); for ( $i = 0; $i < 70; $i++ ) { $fit[ 'f' . $i ] = str_repeat( 'v', 100 ); }
d5b_audit_write( 'a/b', $fit, 'ok', 1 );
$raw = rows()[1]['args'];
check( 'args that fit are stored whole', strlen( $raw ) > 7000 && strlen( $raw ) <= 8192 && 70 === count( json_decode( $raw, true ) ), strlen( $raw ) );
d5b_audit_write( 'a/b', array( 'bad' => "\xB1\x31" ), 'ok', 1 );
check( 'invalid UTF-8 in args still stores valid JSON', null !== json_decode( rows()[2]['args'], true ) || '{"_unencodable":true}' === rows()[2]['args'], rows()[2]['args'] );

echo "prune\n";
installed();
stored_row_at( 31 * 86400, 'old/31d' ); stored_row_at( 45 * 86400, 'old/45d' ); stored_row_at( 29 * 86400, 'new/29d' ); stored_row_at( 10 * 86400, 'new/10d' ); stored_row_at( 60, 'new/now' );
$gone = d5b_audit_prune();
check( 'prune removes rows older than 30 days only, and returns the count', 2 === $gone && array( 'new/29d', 'new/10d', 'new/now' ) === array_column( rows(), 'ability' ), array( $gone, array_column( rows(), 'ability' ) ) );
check( 'a second prune deletes nothing', 0 === d5b_audit_prune() );
fresh();
check( 'prune with no table returns 0, no fatal', 0 === d5b_audit_prune() );

echo "list\n";
installed();
foreach ( array( array( 'a/one', 1, 'ok' ), array( 'a/two', 2, 'forbidden' ), array( 'a/one', 2, 'internal_error' ), array( 'a/three', 1, 'ok' ) ) as $x ) { $GLOBALS['T_user_id'] = $x[1]; d5b_audit_write( $x[0], array( 'i' => $x[2] ), $x[2], 1 ); }
check( 'list is newest first', array( 'a/three', 'a/one', 'a/two', 'a/one' ) === array_column( d5b_audit_list( array() ), 'ability' ) );
check( 'filter by ability', 2 === count( d5b_audit_list( array( 'ability' => 'a/one' ) ) ) );
check( 'filter by user', array( 'a/three', 'a/one' ) === array_column( d5b_audit_list( array( 'user_id' => 1 ) ), 'ability' ) );
check( 'filter by ability and user together', 1 === count( d5b_audit_list( array( 'ability' => 'a/one', 'user_id' => 2 ) ) ) );
check( 'filter by status', array( 'a/two' ) === array_column( d5b_audit_list( array( 'status' => 'forbidden' ) ), 'ability' ) );
check( 'limit and offset', array( 'a/one', 'a/two' ) === array_column( d5b_audit_list( array(), 2, 1 ), 'ability' ) );
check( 'ids and numbers come back as integers', is_int( d5b_audit_list( array() )[0]['id'] ) && is_int( d5b_audit_list( array() )[0]['user_id'] ) );
$evil = "x' OR '1'='1";
check( 'a hostile filter value matches nothing (it is a value, not SQL)', array() === d5b_audit_list( array( 'ability' => $evil ) ) );
check( 'every SQL string the audit code sent went through prepare()', array() === $wpdb->unprepared && count( $wpdb->queries ) > 3, $wpdb->unprepared );
check( 'the hostile value was escaped in the SQL that was sent', false !== strpos( implode( "\n", $wpdb->queries ), "x\\' OR \\'1\\'=\\'1" ) );
$wpdb->unprepared = array(); d5b_audit_prune();
check( 'prune goes through prepare() too', array() === $wpdb->unprepared );

echo "missing table\n";
fresh();
check( 'list with no table: empty array', array() === d5b_audit_list( array() ) );
$GLOBALS['T_dbdelta_fail'] = true; // the table cannot be made at all
$ok = true; $threw = false;
try { $ok = d5b_audit_write( 'a/b', array( 'x' => 1 ), 'ok', 1 ); } catch ( Throwable $t ) { $threw = true; }
check( 'write with no table that cannot be made: false, no exception, nothing stored, version not set', false === $ok && ! $threw && array() === $wpdb->tables && false === get_option( 'd5b_db_version' ) );
$wpdb->fail_insert = true;
check( 'a database that throws on insert: write returns false, never throws', false === d5b_audit_write( 'a/b', array(), 'ok', 1 ) );
$wpdb->fail_insert = false; $GLOBALS['T_dbdelta_fail'] = false;
delete_transient( 'd5b_audit_install_failed' ); d5b_audit_install_state( 'reset' ); // the back-off has passed, a later request
check( 'write with no table: the table is made on the spot and the row stored', true === d5b_audit_write( 'a/b', array(), 'ok', 1 ) && 1 === count( rows() ) && '1' === get_option( 'd5b_db_version' ) );
echo "self-repair: table dropped, version still says installed\n";
installed();
unset( $wpdb->tables['wp_d5b_audit'] ); d5b_audit_install_state( 'reset' ); // a later request
d5b_audit_maybe_install();
check( 'maybe_install alone does not notice (version matches), as designed', array() === $wpdb->tables );
$ok = d5b_audit_write( 'a/b', array( 'x' => 1 ), 'ok', 1 );
check( 'the next write repairs the table and stores the row', true === $ok && 1 === count( rows() ) && '1' === get_option( 'd5b_db_version' ), array( $ok, $wpdb->tables ) );
check( 'the repair retried the insert only once (no loop) and used prepared SHOW TABLES with an escaped name', array() === $wpdb->unprepared && false !== strpos( implode( "\n", $wpdb->queries ), 'wp' . str_repeat( chr( 92 ), 2 ) . '_d5b' ), $wpdb->queries );
echo "subsite: switches are network-wide, no admin_init ever ran there\n";
installed(); t_set_multisite( true );
$wpdb->prefix = 'wp_2_'; unset( $GLOBALS['T_options']['d5b_db_version'] ); // this site's own option: never installed
d5b_abilities_set_master( true, 'net' );
d5b_ability_add( array( 'name' => 'divi5-builder/posts-a', 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'handler' => function ( array $i ) { return array( 'v' => 1 ); }, 'permission' => function ( array $i ) { return true; } ) );
add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' ); add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );
t_fire( 'wp_abilities_api_categories_init' ); t_fire( 'wp_abilities_api_init' );
check( 'registering abilities on a subsite creates that site\'s audit table', isset( $wpdb->tables['wp_2_d5b_audit'] ) && '1' === get_option( 'd5b_db_version' ), array_keys( $wpdb->tables ) );
$res = d5b_ability_run( 'divi5-builder/posts-a', array( 'id' => 1 ) );
check( 'one ability run on the subsite makes exactly one row in that subsite\'s table, none on the main site', array( 'v' => 1 ) === $res && 1 === count( $wpdb->tables['wp_2_d5b_audit'] ) && 0 === count( $wpdb->tables['wp_d5b_audit'] ), array_map( 'count', $wpdb->tables ) );
t_set_multisite( false );
echo "install bounded: CREATE denied\n";
fresh(); $GLOBALS['T_dbdelta_fail'] = true;
for ( $i = 0; $i < 5; $i++ ) { $r5 = d5b_audit_write( 'a/b', array(), 'ok', 1 ); }
check( 'CREATE denied: 5 writes in one request run dbDelta exactly once, all return false', 1 === count( $GLOBALS['T_dbdelta'] ) && false === $r5 && array() === $wpdb->tables, count( $GLOBALS['T_dbdelta'] ) );
d5b_audit_maybe_install(); d5b_audit_maybe_install();
check( '... and maybe_install in the same request adds none', 1 === count( $GLOBALS['T_dbdelta'] ) );
$saved_t = $GLOBALS['T_transients']; $GLOBALS['T_transients'] = array(); // transient storage that does not persist: the per-request flag alone must hold
d5b_audit_write( 'a/b', array(), 'ok', 1 ); d5b_audit_maybe_install();
check( 'the per-request flag holds on its own (transient missing): still one dbDelta', 1 === count( $GLOBALS['T_dbdelta'] ) );
$GLOBALS['T_transients'] = $saved_t;
check( 'the failure left the 1-hour transient d5b_audit_install_failed', false !== get_transient( 'd5b_audit_install_failed' ) && 3600 === $GLOBALS['T_transients']['d5b_audit_install_failed'][2] );
$before_queries = count( $wpdb->queries );
d5b_audit_install_state( 'reset' ); // a second request within the hour
d5b_audit_write( 'a/b', array(), 'ok', 1 ); d5b_audit_write( 'a/b', array(), 'ok', 1 ); d5b_audit_maybe_install();
check( 'a second request within the hour: dbDelta runs 0 more times, and no repair SHOW TABLES is sent', 1 === count( $GLOBALS['T_dbdelta'] ) && $before_queries === count( $wpdb->queries ), count( $GLOBALS['T_dbdelta'] ) );
$GLOBALS['T_transients']['d5b_audit_install_failed'][1] = time() - 1; d5b_audit_install_state( 'reset' ); // the hour has passed
d5b_audit_maybe_install();
check( 'once the transient has expired the next attempt runs dbDelta again', 2 === count( $GLOBALS['T_dbdelta'] ) );
$GLOBALS['T_dbdelta_fail'] = false; $GLOBALS['T_transients']['d5b_audit_install_failed'][1] = time() - 1; d5b_audit_install_state( 'reset' );
d5b_audit_maybe_install();
check( 'a successful install clears the transient and stores the version', isset( $wpdb->tables['wp_d5b_audit'] ) && ! isset( $GLOBALS['T_transients']['d5b_audit_install_failed'] ) && '1' === get_option( 'd5b_db_version' ) );
fresh(); $GLOBALS['T_dbdelta_fail'] = true; d5b_audit_write( 'a/b', array(), 'ok', 1 );
$GLOBALS['T_dbdelta_fail'] = false; delete_transient( 'd5b_audit_install_failed' );
$wpdb->prefix = 'wp_3_'; delete_option( 'd5b_db_version' ); d5b_audit_maybe_install();
check( 'a subsite switch in the same request still installs its own table (the flag is per site)', isset( $wpdb->tables['wp_3_d5b_audit'] ), array_keys( $wpdb->tables ) );
$wpdb->prefix = 'wp_4_'; delete_option( 'd5b_db_version' ); d5b_audit_maybe_install(); d5b_audit_maybe_install();
check( 'each site installs once: two calls on wp_4_ make one more dbDelta', isset( $wpdb->tables['wp_4_d5b_audit'] ) && 3 === count( $GLOBALS['T_dbdelta'] ), count( $GLOBALS['T_dbdelta'] ) );

echo "errors restored when the repair throws\n";
installed(); unset( $wpdb->tables['wp_d5b_audit'] ); d5b_audit_install_state( 'reset' );
$GLOBALS['T_dbdelta_throw'] = true;
$r = d5b_audit_write( 'a/b', array(), 'ok', 1 );
$GLOBALS['T_dbdelta_throw'] = false;
check( 'maybe_install throwing inside the repair: write returns false, never throws', false === $r );
check( 'suppress_errors is back to its prior value afterwards (not left suppressed)', false === $wpdb->suppress_now && array( true, false ) === array_slice( $wpdb->suppress_log, -2 ), $wpdb->suppress_log );

echo "utf-8\n";
installed();
d5b_audit_write( "ab\xB1c", array(), "st\xB1atus", 1 );
check( 'invalid UTF-8 in ability and status is stripped before insert (a real wpdb would drop the row)', 1 === count( rows() ) && 'abc' === rows()[0]['ability'] && 'status' === rows()[0]['status'], rows() );
fresh(); unset( $GLOBALS['wpdb'] );
check( 'no $wpdb at all: write false, list empty, prune 0, install quiet', false === d5b_audit_write( 'a/b', array(), 'ok', 1 ) && array() === d5b_audit_list( array() ) && 0 === d5b_audit_prune() && null === d5b_audit_maybe_install() );
t_wpdb_reset();
fresh(); $wpdb = $GLOBALS['wpdb'];
$wpdb->tables = array(); update_option( 'd5b_db_version', '1' ); // version says installed, table is gone (dropped by hand)
check( 'run through the wrapper still returns when the table is missing', ( function () {
	d5b_abilities_set_master( true, 't' );
	d5b_ability_add( array( 'name' => 'divi5-builder/posts-a', 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'handler' => function ( array $i ) { return array( 'v' => 1 ); }, 'permission' => function ( array $i ) { return true; } ) );
	return array( 'v' => 1 ) === d5b_ability_run( 'divi5-builder/posts-a', array() );
} )() );

echo "the run wrapper writes exactly one row\n";
installed();
d5b_abilities_set_master( true, 'tester' );
$GLOBALS['T_user_id'] = 3;
d5b_ability_add( array( 'name' => 'divi5-builder/posts-ok', 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'handler' => function ( array $i ) { return array( 'v' => 1 ); }, 'permission' => function ( array $i ) { return true; } ) );
d5b_ability_add( array( 'name' => 'divi5-builder/posts-no', 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'handler' => function ( array $i ) { return 1; }, 'permission' => function ( array $i ) { return false; } ) );
d5b_ability_add( array( 'name' => 'divi5-builder/posts-boom', 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'handler' => function ( array $i ) { throw new RuntimeException( 'cannot reach https://svc:topsecret@db.local/ with token=abc999 ' . str_repeat( 'long ', 100 ) ); }, 'permission' => function ( array $i ) { return true; } ) );
d5b_ability_run( 'divi5-builder/posts-ok', array( 'id' => 5, 'password' => 'nope' ) );
check( 'success: one row, status ok, user and ability recorded, secret redacted', 1 === count( rows() ) && 'ok' === rows()[0]['status'] && 'divi5-builder/posts-ok' === rows()[0]['ability'] && 3 === rows()[0]['user_id'] && 5 === last_args()['id'] && '[redacted]' === last_args()['password'], rows() );
$perm = d5b_ability_permit( 'divi5-builder/posts-no', array( 'id' => 9 ) );
check( 'forbidden refusal: one more row, status forbidden', false === $perm && 2 === count( rows() ) && 'forbidden' === rows()[1]['status'] && 'divi5-builder/posts-no' === rows()[1]['ability'], rows() );
$res = d5b_ability_run( 'divi5-builder/posts-boom', array( 'id' => 1 ) );
$row = rows()[2] ?? array(); $ex = json_decode( $row['args'] ?? '{}', true )['_exception'] ?? '';
check( 'throwing handler: one more row, status internal_error, caller gets the fixed message', 3 === count( rows() ) && 'internal_error' === ( $row['status'] ?? '' ) && is_wp_error( $res ) && 'internal_error' === $res->get_error_code(), rows() );
check( 'that row holds the exception class, capped at 300 and with the URL password and token masked', 0 === strpos( $ex, 'RuntimeException: cannot reach' ) && mb_strlen( $ex, 'UTF-8' ) <= 300 && false === strpos( $ex, 'topsecret' ) && false === strpos( $ex, 'abc999' ) && false === strpos( $res->get_error_message(), 'topsecret' ), $ex );
d5b_ability_run( 'divi5-builder/nothing-here', array() );
check( 'an unknown ability is logged once too (not_found)', 4 === count( rows() ) && 'not_found' === rows()[3]['status'] );

echo "the admin list\n";
installed();
$GLOBALS['T_user_id'] = 4;
d5b_audit_write( '<script>alert(1)</script>', array( 'html' => '<img src=x onerror=alert(2)>', 'q' => '"quoted" & more' ), '<b>ok</b>', 5 );
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
t_set_caps( array() );
check( 'audit screen requires d5b_settings_cap(): without it, nothing at all is printed', '' === html( 'd5b_settings_render_audit' ) );
t_set_caps( array( 'manage_options' ) );
$out = html( 'd5b_settings_render_audit' );
check( 'with the capability the list is shown', false !== strpos( $out, 'Audit log' ) && false !== strpos( $out, '<table' ) );
check( 'escapes every field: no raw tag from a stored value is in the output', false === strpos( $out, '<script>' ) && false === strpos( $out, '<img' ) && false === strpos( $out, '<b>ok' ) && false !== strpos( $out, '&lt;script&gt;alert(1)&lt;/script&gt;' ) && false !== strpos( $out, '&lt;b&gt;ok&lt;/b&gt;' ), $out );
check( 'stored quotes in args are escaped', false === strpos( $out, '"quoted"' ) && false !== strpos( $out, '&quot;quoted' ), $out );
check( 'read-only: no delete, clear or purge control, and no POST form', 1 !== preg_match( '/delete|clear|purge|remove/i', $out ) && false === stripos( $out, 'method="post"' ), $out );
$_GET = array( 'd5b_audit_ability' => '"><script>alert(3)</script>', 'd5b_audit_user' => '4abc' );
$out = html( 'd5b_settings_render_audit' );
check( 'the filter values come back escaped in the inputs (no injected tag or broken attribute)', false === strpos( $out, '<script>' ) && false === strpos( $out, 'value=""><' ), $out );
check( 'user filter is absint (4abc -> 4); a hostile ability filter matches nothing', false !== strpos( $out, 'name="d5b_audit_user" value="4"' ) && false !== strpos( $out, 'No calls recorded' ) );
$_GET = array( 'd5b_audit_ability' => '<script>alert(1)</script>', 'd5b_audit_user' => '4' );
check( 'the ability filter is sanitize_text_field-ed (tags stripped): the list is then unfiltered by it', false === strpos( html( 'd5b_settings_render_audit' ), '<script>' ) );
$_GET = array( 'd5b_audit_ability' => array( 'x' ), 'd5b_audit_user' => array( '1' ) );
check( 'array-valued query parameters do not break the page', '' !== html( 'd5b_settings_render_audit' ) );
$_GET = array();
for ( $i = 0; $i < 70; $i++ ) { d5b_audit_write( 'bulk/' . $i, array(), 'ok', 1 ); }
check( 'the screen shows the newest 50 rows only', 50 === substr_count( html( 'd5b_settings_render_audit' ), '<td>bulk/' ) || 50 === preg_match_all( '#<tr><td>\d{4}-#', html( 'd5b_settings_render_audit' ) ) );
t_set_multisite( true ); t_set_caps( array( 'manage_options' ) );
check( 'multisite: manage_options is not enough, network capability required', '' === html( 'd5b_settings_render_audit' ) );
t_set_caps( array( 'manage_network_options' ) );
check( 'multisite: the network capability sees it', false !== strpos( html( 'd5b_settings_render_audit' ), 'Audit log' ) );
t_set_multisite( false );

echo "date filter\n";
installed(); t_set_caps( array( 'manage_options' ) );
stored_row_at( 20 * 86400, 'old/twenty' ); stored_row_at( 60, 'new/now' );
$day = gmdate( 'Y-m-d', time() - 5 * 86400 );
$_GET = array( 'd5b_audit_since' => $day ); $out = html( 'd5b_settings_render_audit' );
check( 'since date: shows only rows from that day on; the input keeps the value', false !== strpos( $out, 'new/now' ) && false === strpos( $out, 'old/twenty' ) && false !== strpos( $out, 'name="d5b_audit_since" value="' . $day . '"' ) && false !== strpos( $out, 'type="date"' ), $out );
foreach ( array( '2026-13-45', '2026-1-1', 'yesterday', '"><script>x</script>', '2026-02-30', '2026-01-01 00:00:00' ) as $bad ) {
	$_GET = array( 'd5b_audit_since' => $bad ); $out = html( 'd5b_settings_render_audit' );
	check( "a date that is not a real YYYY-MM-DD is ignored and never echoed: $bad", false !== strpos( $out, 'old/twenty' ) && false === strpos( $out, '<script>' ) && false !== strpos( $out, 'name="d5b_audit_since" value=""' ), $out );
}
$_GET = array( 'd5b_audit_since' => array( '2026-01-01' ) ); check( 'an array-valued date does not break the page', false !== strpos( html( 'd5b_settings_render_audit' ), 'old/twenty' ) );
$_GET = array();

echo "more free-text masking\n";
installed();
$cap2 = function ( $s ) { d5b_audit_write( 'a/b', array( '_exception' => $s ), 'x', 1 ); return last_args()['_exception']; };
$o = $cap2( 'login failed: password="my secret phrase" for user' );
check( '_exception masks a quoted value that holds spaces, up to the closing quote', false === strpos( $o, 'secret phrase' ) && false === strpos( $o, 'phrase' ) && false !== strpos( $o, 'for user' ), $o );
$o = $cap2( "bad: token='a b c d' end" );
check( '_exception masks a single-quoted value with spaces', false === strpos( $o, 'a b c d' ) && false !== strpos( $o, ' end' ), $o );
$o = $cap2( 'GET /x?a=1%26token%3Dabc123def%26b=2' );
check( '_exception masks token%3Dvalue (URL-encoded equals)', false === strpos( $o, 'abc123def' ), $o );
$o = $cap2( 'sent Basic dXNlcjpwYXNzd29yZA== to proxy' );
check( '_exception masks a bare Basic credential', false === strpos( $o, 'dXNlcjpwYXNz' ) && false !== strpos( $o, 'Basic [redacted]' ), $o );
$o = $cap2( 'json {"password":"p w d","ok":1}' );
check( '_exception masks a JSON "password":"..." value', false === strpos( $o, 'p w d' ) && false !== strpos( $o, '"ok":1' ), $o );

echo "source checks\n";
$src = file_get_contents( $dir . '/inc/abilities/audit.php' );
check( 'audit.php defines functions only: no add_action / add_filter / register_* / wp_schedule_event at load time', 0 === preg_match( '/^(?!\s*(\/\/|\*|\/\*)).*\b(add_action|add_filter|register_[a-z_]+_hook)\s*\(/m', $src ) );
check( 'D5B_DB_VERSION is defined guarded', 1 === preg_match( '/if \( ! defined\( \'D5B_DB_VERSION\' \) \) \{ define\( \'D5B_DB_VERSION\', \'1\' \); \}/', $src ) );
check( 'no direct use of $_GET / $_POST / $_REQUEST in audit.php (the table name comes from $wpdb->prefix)', 0 === preg_match( '/\$_(GET|POST|REQUEST)\b/', $src ) && false !== strpos( $src, "\$wpdb->prefix . 'd5b_audit'" ) );

echo "\n$n checks, $failed failed\n";
exit( $failed ? 1 : 0 );
