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
 * exception text are masked before they are stored; the args column is valid JSON of at most 8 KB (1 KB for a refused
 * call); the row is written (status started) before the handler runs, so a fatal leaves it; a table that is missing is
 * repaired, and one that cannot be made refuses the call (audit_unavailable); rows older than
 * 30 days (and only those) are pruned; the audit writer never throws; every SQL value goes through prepare();
 * the admin list needs the settings capability and escapes every field. (Uninstall is in plugin-uninstall-test.php.)
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.2.0' );
require __DIR__ . '/plugin-test-stubs.php';
require $dir . '/inc/parts.php';
require $dir . '/inc/switches.php';
require $dir . '/inc/abilities/categories.php';
require $dir . '/inc/abilities/register.php';
require $dir . '/inc/abilities/secrets.php';
require $dir . '/inc/abilities/audit.php';
require $dir . '/inc/abilities/scrub.php';
require $dir . '/inc/abilities/history.php';
require $dir . '/inc/gate.php';
require $dir . '/inc/takeover.php';
require $dir . '/inc/settings.php';

// ---- child: a handler that dies for real (memory exhausted, or exit). The shutdown function prints what the table holds.
if ( isset( $argv[1] ) && in_array( $argv[1], array( '--fatal', '--exit' ), true ) ) {
	ini_set( 'memory_limit', '64M' );
	d5b_audit_maybe_install(); d5b_abilities_set_master( true, 't' ); $GLOBALS['T_user_id'] = 1;
	$die = '--fatal' === $argv[1] ? function () { $a = array(); while ( true ) { $a[] = str_repeat( 'x', 1 << 20 ); } } : function () { exit( 0 ); };
	d5b_ability_add( array( 'name' => 'divi5-builder/posts-die', 'category' => 'posts', 'label' => 'd', 'description' => 'x', 'handler' => $die, 'permission' => function () { return true; } ) );
	register_shutdown_function( function () { $r = $GLOBALS['wpdb']->tables[ d5b_audit_table() ] ?? array(); echo 'ROWS=' . count( $r ) . ' STATUS=' . implode( ',', array_column( $r, 'status' ) ) . "\n"; } );
	d5b_ability_run( 'divi5-builder/posts-die', array( 'q' => 1 ) );
	echo "NOT REACHED\n";
	exit( 0 );
}

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

// A logged-in caller (user 1) by default: a caller who is not logged in is refused before any permission callback runs.
function fresh() { t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( array() ); t_set_multisite( false ); $GLOBALS['T_user_id'] = 1; $_GET = array(); $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; unset( $_SERVER['HTTP_X_FORWARDED_FOR'] ); d5b_ability_registry( array() ); }
function installed() { fresh(); d5b_audit_maybe_install(); }
function rows() { global $wpdb; return $wpdb->tables[ d5b_audit_table() ] ?? array(); }
function last_args() { $r = rows(); return json_decode( $r[ count( $r ) - 1 ]['args'], true ); }
function stored_row_at( $secs_ago, $ability = 'divi5-builder/posts-list' ) {
	global $wpdb; $wpdb->tables[ d5b_audit_table() ][] = array( 'id' => 900 + count( $wpdb->tables[ d5b_audit_table() ] ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $secs_ago ), 'user_id' => 1, 'ability' => $ability, 'args' => '{}', 'status' => 'ok', 'duration_ms' => 1, 'ip' => '' );
}
function html( $fn ) { ob_start(); $fn(); return ob_get_clean(); }

echo "install\n";
fresh();
check( 'before install there is no table and no version', array() === $wpdb->tables && false === get_option( 'd5b_db_version' ) && '2' === D5B_DB_VERSION );
d5b_audit_maybe_install();
check( 'install creates the table once and sets d5b_db_version', array_key_exists( 'wp_d5b_audit', $wpdb->tables ) && '2' === get_option( 'd5b_db_version' ) && 1 === count( $GLOBALS['T_dbdelta'] ), array( array_keys( $wpdb->tables ), $GLOBALS['T_dbdelta'] ) );
d5b_audit_maybe_install();
check( 'a second call does nothing (dbDelta not run again)', 1 === count( $GLOBALS['T_dbdelta'] ) );
$sql = $GLOBALS['T_dbdelta'][0];
check( 'the table has the eight columns, both indexes and two spaces after PRIMARY KEY (dbDelta needs them)', 1 === preg_match( '/\bid bigint\(20\) unsigned NOT NULL AUTO_INCREMENT,\s+created_at datetime NOT NULL,\s+user_id bigint\(20\) unsigned.*ability varchar\(191\).*args longtext.*status varchar\(64\).*duration_ms int.*ip varchar\(45\).*PRIMARY KEY  \(id\).*KEY created_at \(created_at\).*KEY ability \(ability\)/s', $sql ), $sql );
check( 'the daily prune event is scheduled', isset( $GLOBALS['T_cron']['d5b_audit_prune'] ) );
d5b_audit_deactivate();
check( 'deactivation clears the event (the table and the log stay)', ! isset( $GLOBALS['T_cron']['d5b_audit_prune'] ) && isset( $wpdb->tables['wp_d5b_audit'] ) );
fresh(); update_option( 'd5b_db_version', '0' ); d5b_audit_maybe_install();
check( 'an older stored version installs again', 1 === count( $GLOBALS['T_dbdelta'] ) && '2' === get_option( 'd5b_db_version' ) );

echo "write\n";
installed();
$GLOBALS['T_user_id'] = 7;
$ok = d5b_audit_write( 'divi5-builder/posts-list', array( 'search' => 'hello', 'password' => 'hunter2', 'application_password' => 'abcd efgh', 'token' => 't0k', 'api_key' => 'k', 'Authorization' => 'x', 'cookie' => 'c', 'nonce' => 'n', 'secret' => 's', 'nested' => array( 'user_pass' => 'p', 'keep' => 'me', 'deeper' => array( 'access_token' => 'zzz', 'ok' => 1 ) ) ), 'ok', 12 );
$a = last_args(); $r = rows()[0];
check( 'write returns the new row id (an int) and stores one row', is_int( $ok ) && $ok > 0 && 1 === count( rows() ) && $ok === rows()[0]['id'], $ok );
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
check( 'write with no table that cannot be made: 0, no exception, nothing stored, version not set', 0 === $ok && ! $threw && array() === $wpdb->tables && false === get_option( 'd5b_db_version' ) );
$wpdb->fail_insert = true;
check( 'a database that throws on insert: write returns 0, never throws', 0 === d5b_audit_write( 'a/b', array(), 'ok', 1 ) );
$wpdb->fail_insert = false; $GLOBALS['T_dbdelta_fail'] = false;
delete_transient( 'd5b_audit_install_failed' ); d5b_audit_install_state( 'reset' ); // the back-off has passed, a later request
check( 'write with no table: the table is made on the spot and the row stored', d5b_audit_write( 'a/b', array(), 'ok', 1 ) > 0 && 1 === count( rows() ) && '2' === get_option( 'd5b_db_version' ) );
echo "self-repair: table dropped, version still says installed\n";
installed();
unset( $wpdb->tables['wp_d5b_audit'] ); d5b_audit_install_state( 'reset' ); // a later request
d5b_audit_maybe_install();
check( 'maybe_install alone does not notice (version matches), as designed', ! isset( $wpdb->tables['wp_d5b_audit'] ) );
$ok = d5b_audit_write( 'a/b', array( 'x' => 1 ), 'ok', 1 );
check( 'the next write repairs the table and stores the row', $ok > 0 && 1 === count( rows() ) && '2' === get_option( 'd5b_db_version' ), array( $ok, $wpdb->tables ) );
check( 'the repair retried the insert only once (no loop) and used prepared SHOW TABLES with an escaped name', array() === $wpdb->unprepared && false !== strpos( implode( "\n", $wpdb->queries ), 'wp' . str_repeat( chr( 92 ), 2 ) . '_d5b' ), $wpdb->queries );
echo "subsite: switches are network-wide, no admin_init ever ran there\n";
installed(); t_set_multisite( true );
$wpdb->prefix = 'wp_2_'; unset( $GLOBALS['T_options']['d5b_db_version'] ); // this site's own option: never installed
d5b_abilities_set_master( true, 'net' );
d5b_ability_add( array( 'name' => 'divi5-builder/posts-a', 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'handler' => function ( array $i ) { return array( 'v' => 1 ); }, 'permission' => function ( array $i ) { return true; } ) );
add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' ); add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );
t_fire( 'wp_abilities_api_categories_init' ); t_fire( 'wp_abilities_api_init' );
check( 'registering abilities on a subsite creates that site\'s audit table', isset( $wpdb->tables['wp_2_d5b_audit'] ) && '2' === get_option( 'd5b_db_version' ), array_keys( $wpdb->tables ) );
$res = d5b_ability_run( 'divi5-builder/posts-a', array( 'id' => 1 ) );
check( 'one ability run on the subsite makes exactly one row in that subsite\'s table, none on the main site', array( 'v' => 1 ) === $res && 1 === count( $wpdb->tables['wp_2_d5b_audit'] ) && 0 === count( $wpdb->tables['wp_d5b_audit'] ), array_map( 'count', $wpdb->tables ) );
t_set_multisite( false );
echo "install bounded: CREATE denied\n";
fresh(); $GLOBALS['T_dbdelta_fail'] = true;
for ( $i = 0; $i < 5; $i++ ) { $r5 = d5b_audit_write( 'a/b', array(), 'ok', 1 ); }
check( 'CREATE denied: 5 writes in one request run dbDelta exactly once, all return 0', 1 === count( $GLOBALS['T_dbdelta'] ) && 0 === $r5 && array() === $wpdb->tables, count( $GLOBALS['T_dbdelta'] ) );
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
check( 'a successful install clears the transient and stores the version', isset( $wpdb->tables['wp_d5b_audit'] ) && ! isset( $GLOBALS['T_transients']['d5b_audit_install_failed'] ) && '2' === get_option( 'd5b_db_version' ) );
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
check( 'maybe_install throwing inside the repair: write returns 0, never throws', 0 === $r );
check( 'suppress_errors is back to its prior value afterwards (not left suppressed)', false === $wpdb->suppress_now && array( true, false ) === array_slice( $wpdb->suppress_log, -2 ), $wpdb->suppress_log );

echo "utf-8\n";
installed();
d5b_audit_write( "ab\xB1c", array(), "st\xB1atus", 1 );
check( 'invalid UTF-8 in ability and status is replaced (mb_scrub: ?) before insert, the rest kept (a real wpdb would drop the row)', 1 === count( rows() ) && 'ab?c' === rows()[0]['ability'] && 'st?atus' === rows()[0]['status'], rows() );
fresh(); unset( $GLOBALS['wpdb'] );
check( 'no $wpdb at all: write 0, list empty, prune 0, install quiet', 0 === d5b_audit_write( 'a/b', array(), 'ok', 1 ) && array() === d5b_audit_list( array() ) && 0 === d5b_audit_prune() && null === d5b_audit_maybe_install() );
t_wpdb_reset();
fresh(); $wpdb = $GLOBALS['wpdb'];
$wpdb->tables = array(); update_option( 'd5b_db_version', '2' ); // version says installed, table is gone (dropped by hand)
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

echo "write-ahead and fail closed (break-it Important 4)\n";
installed(); d5b_abilities_set_master( true, 'tester' ); $GLOBALS['T_user_id'] = 3; $GLOBALS['T_seen'] = null;
d5b_ability_add( array( 'name' => 'divi5-builder/posts-peek', 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'permission' => function ( array $i ) { return true; },
	'handler' => function ( array $i ) { $GLOBALS['T_seen'] = rows(); throw new Error( 'simulated fatal' ); } ) );
$res = d5b_ability_run( 'divi5-builder/posts-peek', array( 'id' => 2 ) );
check( 'inside the handler the row already exists, status started, args stored (so a fatal leaves it)', is_array( $GLOBALS['T_seen'] ) && 1 === count( $GLOBALS['T_seen'] ) && 'started' === $GLOBALS['T_seen'][0]['status'] && '{"id":2}' === $GLOBALS['T_seen'][0]['args'], $GLOBALS['T_seen'] );
check( 'a handler that throws (an Error too): the same row becomes internal_error with the _exception, still one row', 1 === count( rows() ) && 'internal_error' === rows()[0]['status'] && 0 === strpos( last_args()['_exception'], 'Error: simulated fatal' ) && is_wp_error( $res ), rows() );
$php = escapeshellarg( PHP_BINARY ); $self = escapeshellarg( __FILE__ );
foreach ( array( '--fatal' => 'memory exhausted', '--exit' => 'exit()' ) as $flag => $what ) {
	$out = array(); exec( $php . ' -d max_execution_time=30 ' . $self . ' ' . $flag . ' 2>&1', $out ); $line = implode( ' ', $out );
	check( "a handler that ends the request ($what) leaves one row with status started", false !== strpos( $line, 'ROWS=1 STATUS=started' ) && false === strpos( $line, 'NOT REACHED' ), $line );
}
fresh(); $GLOBALS['T_dbdelta_fail'] = true; d5b_abilities_set_master( true, 'tester' ); $GLOBALS['T_ran'] = 0;
d5b_ability_add( array( 'name' => 'divi5-builder/posts-served', 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'permission' => function ( array $i ) { return true; }, 'handler' => function ( array $i ) { $GLOBALS['T_ran']++; return array( 'data' => 'served' ); } ) );
$r1 = d5b_ability_run( 'divi5-builder/posts-served', array() ); $r2 = d5b_ability_run( 'divi5-builder/posts-served', array() );
check( 'CREATE denied (no table, repair impossible): every call is refused with audit_unavailable and the handler never runs', is_wp_error( $r1 ) && 'audit_unavailable' === $r1->get_error_code() && is_wp_error( $r2 ) && 'audit_unavailable' === $r2->get_error_code() && 0 === $GLOBALS['T_ran'] && array() === $wpdb->tables, array( $r1, $r2 ) );
$GLOBALS['T_dbdelta_fail'] = false;
installed();
$id = d5b_audit_write( 'a/b', array( 'x' => 1 ), 'started', 0 );
check( 'd5b_audit_update sets status and duration on that row and leaves the args when none are given', true === d5b_audit_update( $id, 'ok', 42 ) && 'ok' === rows()[0]['status'] && 42 === rows()[0]['duration_ms'] && '{"x":1}' === rows()[0]['args'] );
check( 'd5b_audit_update on an id that does not exist, or id 0: false', false === d5b_audit_update( 999, 'ok', 1 ) && false === d5b_audit_update( 0, 'ok', 1 ) );
$wpdb->fail_update = true; $threw = false;
try { $u = d5b_audit_update( $id, 'x', 1 ); } catch ( Throwable $t ) { $threw = true; }
$wpdb->fail_update = false;
check( 'd5b_audit_update never throws (a database that throws: false)', ! $threw && false === $u );
fresh();
check( 'd5b_audit_update with no table: false, no exception', false === d5b_audit_update( 1, 'ok', 1 ) );

echo "flood cap: refused calls store at most 1 KB of args\n";
installed(); d5b_abilities_set_master( true, 'tester' );
$pad = array(); for ( $i = 1; $i <= 7; $i++ ) { $pad[ 'p' . $i ] = str_repeat( 'z', 1000 ); }
d5b_ability_add( array( 'name' => 'divi5-builder/posts-nope', 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'permission' => function ( array $i ) { return false; }, 'handler' => function ( array $i ) { return 1; } ) );
d5b_ability_add( array( 'name' => 'divi5-builder/posts-bad', 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'permission' => function ( array $i ) { return true; }, 'handler' => function ( array $i ) { return new WP_Error( 'invalid_input', 'no' ); } ) );
d5b_ability_add( array( 'name' => 'divi5-builder/posts-fine', 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'permission' => function ( array $i ) { return true; }, 'handler' => function ( array $i ) { return 1; } ) );
for ( $i = 0; $i < 50; $i++ ) { d5b_ability_permit( 'divi5-builder/posts-nope', $pad ); }
$sizes = array_map( function ( $r ) { return strlen( $r['args'] ); }, rows() );
check( '50 refused permission checks with 7 KB of input each: every row holds a placeholder of at most 1 KB', 50 === count( rows() ) && max( $sizes ) <= 1024 && true === last_args()['_truncated'], max( $sizes ) );
d5b_ability_run( 'divi5-builder/posts-nope', $pad );
check( 'the same through a direct run: forbidden, at most 1 KB', 'forbidden' === rows()[50]['status'] && strlen( rows()[50]['args'] ) <= 1024 );
d5b_ability_run( 'divi5-builder/posts-bad', $pad );
check( 'bad input (invalid_input from the handler): the started row is rewritten to at most 1 KB', 'error:invalid_input' === rows()[51]['status'] && strlen( rows()[51]['args'] ) <= 1024, rows()[51] );
d5b_ability_run( 'divi5-builder/posts-fine', $pad );
check( 'a successful call keeps the 8 KB cap (the same 7 KB input is stored whole)', 'ok' === rows()[52]['status'] && strlen( rows()[52]['args'] ) > 7000 && 7 === count( last_args() ), strlen( rows()[52]['args'] ) );
$small = array( 'q' => 'short' );
d5b_ability_permit( 'divi5-builder/posts-nope', $small );
check( 'a small refused input is stored as it is', array( 'q' => 'short' ) === last_args() );

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
// The login column names a missing user "deleted user": that text is not a control, so it is left out of the scan.
check( 'read-only: no delete, clear or purge control, and no POST form', 1 !== preg_match( '/delete|clear|purge|remove/i', str_replace( '<td>deleted user</td>', '', $out ) ) && false === stripos( $out, 'method="post"' ), $out );
check( 'the login column: user 4 does not exist, so "deleted user"', false !== strpos( $out, '<th>Login</th>' ) && false !== strpos( $out, '<td>4</td><td>deleted user</td>' ), $out );
check( 'the legend under the list, word for word', false !== strpos( $out, esc_html( 'started = the call began and did not finish (or is the current one); forbidden = refused; ok_no_history = it worked but its change history could not be saved; error:<code> = the ability answered with an error' ) ), $out );
$GLOBALS['T_users'][4] = (object) array( 'ID' => 4, 'user_login' => 'ed<i>' );
$GLOBALS['T_user_id'] = 0; d5b_audit_write( 'anon/x', array(), 'forbidden_anonymous', 1 ); $GLOBALS['T_user_id'] = 4;
$out = html( 'd5b_settings_render_audit' );
unset( $GLOBALS['T_users'][4] );
check( 'the login column: a real user shows the login (escaped), user 0 shows "anonymous"', false !== strpos( $out, '<td>4</td><td>ed&lt;i&gt;</td>' ) && false !== strpos( $out, '<td>0</td><td>anonymous</td>' ), $out );
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

echo "free-text masker: every shape the break-it (a4 part 3) and the Task 5 review (I-1) got through\n";
// array( text, what must be gone, what must stay ). Where the reproducer's literal was not the real shape of that secret
// (an AWS key id, a JWT with a 3-letter signature still IS caught), a realistic value is used; see the report.
$mask_cases = array(
	'define DB_PASSWORD'          => array( "define('DB_PASSWORD', 'S3CR3T-VALUE');", 'S3CR3T', array( "define('DB_PASSWORD', " ) ),
	'define( AUTH_KEY )'          => array( "define( 'AUTH_KEY', 'S3CR3T-VALUE' );", 'S3CR3T', array( 'AUTH_KEY' ) ),
	'Cookie: header'              => array( 'Cookie: wordpress_logged_in_abc=admin|1700000000|S3CR3T-VALUE', 'S3CR3T', array( 'Cookie: [redacted]' ) ),
	'Cookie: header, many cookies' => array( 'Cookie: theme=dark; wordpress_logged_in_abc=admin|S3CR3T-VALUE', 'S3CR3T', array() ),
	'Set-Cookie: header'          => array( "x\nSet-Cookie: sid=S3CR3T-VALUE; path=/\ny", 'S3CR3T', array( "x\n", "\ny" ) ),
	'auth='                       => array( 'auth=S3CR3T-VALUE', 'S3CR3T', array( 'auth=' ) ),
	'nonce='                      => array( 'nonce=S3CR3T-VALUE', 'S3CR3T', array() ),
	'salt='                       => array( 'salt=S3CR3T-VALUE', 'S3CR3T', array() ),
	'token <space> value'         => array( 'token S3CR3T-VALUE', 'S3CR3T', array( 'token ' ) ),
	'API key sk_live_ (word key)' => array( 'Invalid API key sk_live_S3CR3T-VALUE', 'S3CR3T', array( 'Invalid API key' ) ),
	'bearer:'                     => array( 'bearer:S3CR3T-VALUE', 'S3CR3T', array() ),
	'password => value'           => array( 'password => S3CR3T-VALUE', 'S3CR3T', array() ),
	"'password' => 'value'"       => array( "'password' => 'S3CR3T-VALUE'", 'S3CR3T', array( "'password' => " ) ),
	'password is value'           => array( 'password is S3CR3T-VALUE', 'S3CR3T', array() ),
	'pass word=value'             => array( 'pass word=S3CR3T-VALUE', 'S3CR3T', array() ),
	'URL password holding an @'   => array( 'smtp://u:pa@S3CR3T@mail.example/x', 'S3CR3T', array( 'mail.example/x' ) ),
	'session='                    => array( 'session=S3CR3T-VALUE', 'S3CR3T', array() ),
	'PHPSESSID='                  => array( 'PHPSESSID=S3CR3T-VALUE', 'S3CR3T', array() ),
	'JWT, short signature'        => array( 'eyJhbGciOiJIUzI1NiJ9.S3CR3T-VALUE.sig', 'S3CR3T', array() ),
	'AWS access key id'           => array( 'aws said AKIAIOSFODNN7EXAMPLE is invalid', 'IOSFODNN7', array( 'aws said ', ' is invalid' ) ),
	'PEM without END'             => array( '-----BEGIN PRIVATE KEY-----S3CR3T-VALUE', 'S3CR3T', array() ),
	'print_r [user_pass] =>'      => array( "Array\n(\n    [user_login] => alice\n    [user_pass] => hunter2-printr\n)", 'hunter2', array( '[user_login] => alice', '[user_pass] => [redacted]' ) ),
	'print_r [pwd] =>'            => array( '    [pwd] => hunter2-pwd', 'hunter2', array( '[pwd] => ' ) ),
	'print_r [api_key] =>'        => array( '    [api_key] => sk_live_PRINTR', 'PRINTR', array() ),
	'print_r on one line'         => array( 'stdClass Object ( [access_token] => ya29.OBJTOKEN [ok] => 1 )', 'OBJTOKEN', array( '[ok] => 1' ) ),
	'var_export array'            => array( "array ( 'password' => 'hunter2-varexport', 'stripe_secret' => 'sk_live_VAREXP', 'title' => 'Hi', )", 'hunter2', array( "'title' => 'Hi'" ) ),
	'var_export stripe_secret'    => array( "array ( 'stripe_secret' => 'sk_live_VAREXP', )", 'VAREXP', array() ),
	'SQL SET user_pass = $P$'     => array( "UPDATE wp_users SET user_pass = '\$P\$Bhashhash' WHERE ID = 1", 'Bhashhash', array( 'WHERE ID = 1' ) ),
	'SQL INSERT VALUES $wp$'      => array( "INSERT INTO wp_users (user_login, user_pass) VALUES ('bob', '\$wp\$2y\$10\$INSERTHASH')", 'INSERTHASH', array( "VALUES ('bob', '" ) ),
	'with password <value>'       => array( 'smtp auth failed for user bob with password hunter2-plain', 'hunter2', array( 'smtp auth failed for user bob with password ' ) ),
);
foreach ( $mask_cases as $label => $c ) {
	$o = d5b_audit_mask_secrets( $c[0] );
	$ok = false === strpos( $o, $c[1] );
	foreach ( $c[2] as $keep ) { if ( false === strpos( $o, $keep ) ) { $ok = false; } }
	check( "masker: $label", $ok, $o );
}
$ordinary = "PHP Notice: Undefined index: color in /var/www/x.php on line 5\nPHP Warning: Division by zero in /var/www/y.php:12";
check( 'masker: ordinary log lines come through unchanged', $ordinary === d5b_audit_mask_secrets( $ordinary ), d5b_audit_mask_secrets( $ordinary ) );
check( 'masker: the same rule as the scrubber decides (a key it calls secret is masked; one it does not is kept)', '2fa_backup_codes=[redacted] title=ok' === d5b_audit_mask_secrets( '2fa_backup_codes=abc title=ok' ) );
$o = $cap2( "RuntimeException: define('DB_PASSWORD', 'S3CR3T-VALUE');" );
check( 'the _exception of a thrown handler goes through it (break-it a4 "throws w/ secret")', false === strpos( $o, 'S3CR3T' ) && 0 === strpos( $o, 'RuntimeException: define(' ), $o );
check( 'input args: secret value shapes are masked in stored strings, ordinary ones are not', ( function () { d5b_audit_write( 'a/b', array( 'q' => 'see sk_live_ABCDEFGH12345 now', 'r' => 'plain' ), 'ok', 1 ); $a = last_args(); return false === strpos( $a['q'], 'ABCDEFGH' ) && 'plain' === $a['r']; } )() );

echo "round 2: ordinary debug.log lines come through unchanged (re-review N-1, probe3)\n";
$keep_lines = array(
	'PHP Warning:  Undefined array key "title" in /x/Divi/includes/builder/module/Blurb.php on line 12',
	'PHP Notice:  Undefined index: key in /x/y.php on line 3',
	'get_key is deprecated since version 5.0! Use et_builder_get_key instead.',
	"WordPress database error Duplicate entry '5' for key 'PRIMARY' for query ...",
	'meta key _price not found',
	'Invalid nonce for action save_post',
	'The password reset link has expired',
	'Token expired at 2026-10-28',
	'The key to success',
	'[28-Oct-2026 10:00:00 UTC] PHP Notice:  Undefined index: key in /home/site/public_html/wp-content/plugins/foo/foo.php on line 42',
	'[28-Oct-2026 10:00:00 UTC] PHP Warning:  Undefined array key "title" in /var/www/wp-content/themes/Divi/includes/builder/module/Blurb.php on line 210',
	'[28-Oct-2026 10:00:00 UTC] PHP Deprecated:  Function ET_Builder_Element::get_key is deprecated since version 5.0.0! Use et_builder_get_key instead. in /var/www/wp-includes/functions.php on line 6114',
	'[28-Oct-2026 10:00:00 UTC] PHP Deprecated:  Creation of dynamic property ET_Core_API_Email_Mailchimp::$token_url is deprecated in /x/core/components/api/email/MailChimp.php on line 50',
	'[28-Oct-2026 10:00:00 UTC] PHP Fatal error:  Uncaught TypeError: array_key_exists(): Argument #2 ($array) must be of type array, null given in /x/a.php:12',
	'[28-Oct-2026 10:00:00 UTC] PHP Warning:  Cannot modify header information - headers already sent by (output started at /x/wp-config.php:1) in /x/wp-includes/pluggable.php on line 1450',
	'Cron reschedule event error for hook: wp_privacy_delete_old_export_files, Error code: invalid_schedule, Error message: Event schedule does not exist., Data: {"schedule":"hourly","args":[],"interval":3600}',
	'The key to success is a primary key on the table.',
	'Test passed. Authentication failed for user bob.',
	"WordPress database error Duplicate entry '5-foo' for key 'PRIMARY' for query INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (5, 'foo', 'bar') made by do_action",
	'PHP Notice:  Function _load_textdomain_just_in_time was called incorrectly. Translation loading for the divi domain was triggered too early.',
	'Sorting by meta key _price failed',
	'https://example.com/wp-json/wp/v2/posts?per_page=10&page=2',
	'The password reset link has expired.',
	'Token expired at 10:00',
);
foreach ( $keep_lines as $l ) { $o = d5b_audit_mask_secrets( $l ); check( 'unchanged: ' . substr( $l, 0, 70 ), $o === $l, $o ); }

echo "round 2: still masked\n";
$r2_mask = array(
	'secret sk_live_'          => array( 'Stripe error: secret sk_' . 'live_4eC39HqLyjWDarjtT1zdp7dc', '4eC39HqLy' ),
	'define()'                 => array( "define( 'DB_PASSWORD', 'hunter2-define' );", 'hunter2' ),
	'JSON "password":'         => array( '{"user":"bob","password":"hunter2-json"}', 'hunter2' ),
	'curl -u user:pass'        => array( 'curl -u admin:hunter2-curl https://x', 'hunter2' ),
	'--user user:pass'         => array( 'wget --user=admin:hunter2-wget https://x', 'hunter2' ),
	'api key: x'               => array( 'api key: abcd1234efgh', 'abcd1234' ),
	'API key is <value>'       => array( 'Mailchimp API key is 0123456789abcdef0123456789abcdef-' . 'us21', '0123456789abcdef' ),
	'API key is abc123def456'  => array( 'Mailchimp API key is abc123def456', 'abc123def456' ),
	'JWT'                      => array( 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc', 'eyJzdWIiOiIxIn0' ),
	'Set-Cookie'               => array( 'Set-Cookie: wordpress_logged_in_abc=admin%7C1700000000%7Ctoken; path=/', '1700000000' ),
	'[PHP_AUTH_PW] = x'        => array( '$_SERVER[PHP_AUTH_PW] = hunter2-server', 'hunter2' ),
	'password hunter2'         => array( 'login failed with password hunter2', 'hunter2' ),
	'print_r'                  => array( '    [user_pass] => hunter2-printr', 'hunter2' ),
	'var_export'               => array( "array ( 'password' => 'hunter2-varexport', )", 'hunter2' ),
	'INSERT hash'              => array( "INSERT INTO wp_users (user_login, user_pass) VALUES ('bob', '\$wp\$2y\$10\$INSERTHASH')", 'INSERTHASH' ),
	'smtp_pw=x (token pw)'     => array( 'smtp_pw=hunter2-pw', 'hunter2' ),
	'Bearer with digits'       => array( 'sent Bearer abc123def456 to x', 'abc123def456' ),
);
foreach ( $r2_mask as $label => $c ) { $o = d5b_audit_mask_secrets( $c[0] ); check( "masked: $label", false === strpos( $o, $c[1] ), $o ); }
check( 'Bearer followed by an ordinary word is prose, not a token', 'Bearer instrumentalisation of bonds' === d5b_audit_mask_secrets( 'Bearer instrumentalisation of bonds' ) );
$t = microtime( true );
d5b_audit_mask_secrets( str_repeat( 'key key ', 250 ) ); d5b_audit_mask_secrets( str_repeat( '[a] => ', 285 ) ); d5b_audit_mask_secrets( str_repeat( 'password=', 222 ) );
for ( $i = 0; $i < 200; $i++ ) { d5b_audit_mask_secrets( str_repeat( 'token is x ', 181 ) ); }
check( 'hostile 2000-character lines (200 of them) mask in well under 2 seconds', microtime( true ) - $t < 2.0, round( ( microtime( true ) - $t ) * 1000 ) . ' ms' );

echo "round 2: audit status of a handler's own error (break-it Minor 7)\n";
installed(); d5b_abilities_set_master( true, 'tester' );
foreach ( array( 'ok', 'started', 'forbidden', str_repeat( 'x', 100 ) ) as $i => $code ) {
	d5b_ability_add( array( 'name' => 'divi5-builder/posts-code' . $i, 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'permission' => function ( array $in ) { return true; }, 'handler' => function ( array $in ) use ( $code ) { return new WP_Error( $code, 'forged' ); } ) );
	d5b_ability_run( 'divi5-builder/posts-code' . $i, array() );
}
$sts = array_column( rows(), 'status' );
check( 'a handler WP_Error is stored as error:<code>: code ok -> error:ok, started -> error:started, forbidden -> error:forbidden', array( 'error:ok', 'error:started', 'error:forbidden' ) === array_slice( $sts, 0, 3 ), $sts );
check( '... capped at 64 characters (in the wrapper, not only by the column cut)', 64 === strlen( $sts[3] ) && 0 === strpos( $sts[3], 'error:xxx' ) && 64 === strlen( d5b_ability_error_status( str_repeat( 'y', 100 ) ) ), $sts[3] );

echo "round 3: masker cost is bounded (break-it a11 and a10 section 7)\n";
$units = array( 'kv' => 'password=', 'kvx' => 'token=x ', 'colon' => 'key:', 'arrow' => '[password] => ', 'word' => 'password ', 'keyis' => 'key is ', 'dash_u' => '-u a:',
	'plain' => 'lorem ipsum dolor sit amet ', 'log' => "[28-Oct-2026 10:00:00 UTC] PHP Notice:  Undefined index: key in /x/foo.php on line 42\n", 'define' => "define('A_KEY','x');", 'qesc' => '\\"', 'url' => 'a:' );
$slow = array();
foreach ( array( 65536, 262144 ) as $size ) {
	foreach ( $units as $label => $unit ) {
		$s = substr( str_repeat( $unit, (int) ceil( $size / strlen( $unit ) ) ), 0, $size );
		if ( function_exists( 'memory_reset_peak_usage' ) ) { memory_reset_peak_usage(); }
		$base = memory_get_usage(); $t = microtime( true );
		$o = d5b_audit_mask_secrets( $s );
		$ms = ( microtime( true ) - $t ) * 1000; $mb = ( memory_get_peak_usage() - $base ) / 1048576;
		if ( $ms > 500 || $mb > 32 ) { $slow[] = sprintf( '%s %d KB: %d ms, +%.1f MB', $label, $size / 1024, $ms, $mb ); }
		unset( $o, $s );
	}
}
check( 'a11: every input at 64 KB and 256 KB masks in under 500 ms and under 32 MB of peak memory', array() === $slow, $slow );
$K = 65536;
$redos = array( 'password ' => str_repeat( 'password ', 7281 ), 'key is ' => str_repeat( 'key is ', 9362 ), 'token + long' => 'token ' . str_repeat( 'a', $K ), 'long key run' => str_repeat( 'a', $K ) . ' key x',
	'-u run' => str_repeat( '-u a:', 13107 ), '-u no colon' => '-u ' . str_repeat( 'a', $K ), '--user=' => '--user=' . str_repeat( 'a', $K ) . ':',
	'PEM lines' => '-----BEGIN PRIVATE KEY-----' . str_repeat( "\n" . str_repeat( 'A', 64 ), 1008 ), 'PEM many BEGIN' => str_repeat( "-----BEGIN PRIVATE KEY-----\nAAAA\n", 1985 ),
	'Bearer near miss' => 'Bearer ' . str_repeat( 'a', $K ), 'Bearer many' => str_repeat( 'Bearer aaaaaaaaaaaaaaaaaaaa ', 2340 ), '[k] => run' => str_repeat( '[password] => ', 4681 ), 'quoted unterminated' => 'password="' . str_repeat( '\\"', 32768 ) );
$slow = array();
foreach ( $redos as $label => $s ) { $t = microtime( true ); d5b_audit_mask_secrets( $s ); $ms = ( microtime( true ) - $t ) * 1000; if ( $ms > 500 ) { $slow[] = "$label: " . round( $ms ) . ' ms'; } }
check( 'a10 section 7: the 64 KB ReDoS inputs each mask in under 500 ms', array() === $slow, $slow );
$long = 'password=' . str_repeat( 'S', 2000 ) . ' tail';
check( 'a long secret value (2000 characters, past the 512 capture) is masked whole', 'password=[redacted] tail' === d5b_audit_mask_secrets( $long ), substr( d5b_audit_mask_secrets( $long ), 0, 80 ) );
check( 'a long quoted value is masked to its closing quote', "x 'password' => [redacted], 'b' => 1" === d5b_audit_mask_secrets( "x 'password' => '" . str_repeat( 'q', 3000 ) . "', 'b' => 1" ) );
$big = str_repeat( 'lorem ipsum ', 30000 ) . 'password=hunter2';
$o = d5b_audit_mask_secrets( $big );
check( 'over 256 KB: the first 256 KB are masked, the rest is cut and marked, never passed through', 262144 + strlen( '…[truncated]' ) === strlen( $o ) && '…[truncated]' === substr( $o, -strlen( '…[truncated]' ) ) && false === strpos( $o, 'hunter2' ), strlen( $o ) );

echo "anonymous callers: refused before any handler, at most one row per IP per 10 minutes\n";
function anon_site() {
	installed(); d5b_abilities_set_master( true, 'tester' );
	$GLOBALS['T_ran'] = 0; $GLOBALS['T_perm_ran'] = 0;
	// A permission callback that says yes to anyone and a handler that counts: neither may run for an anonymous caller.
	d5b_ability_add( array( 'name' => 'divi5-builder/health-open', 'category' => 'health', 'label' => 'a', 'description' => 'x', 'handler' => function ( array $i ) { $GLOBALS['T_ran']++; return array( 'v' => 1 ); }, 'permission' => function ( array $i ) { $GLOBALS['T_perm_ran']++; return true; } ) );
	d5b_ability_add( array( 'name' => 'divi5-builder/health-shut', 'category' => 'health', 'label' => 'a', 'description' => 'x', 'handler' => function ( array $i ) { return 1; }, 'permission' => function ( array $i ) { return false; } ) );
	$GLOBALS['T_user_id'] = 0; $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
}
anon_site();
$codes = array(); $statuses = array();
for ( $i = 0; $i < 1000; $i++ ) {
	$p = d5b_ability_permit( 'divi5-builder/health-open', array( 'i' => $i ) );
	$r = d5b_ability_run( 'divi5-builder/health-open', array( 'i' => $i ) );
	$codes[ ( is_wp_error( $p ) ? $p->get_error_code() : 'p:' . var_export( $p, true ) ) . '/' . ( is_wp_error( $r ) ? $r->get_error_code() : 'r:ran' ) ] = true;
	if ( is_wp_error( $r ) ) { $statuses[ (string) ( $r->get_error_data()['status'] ?? 'none' ) ] = true; }
}
check( '1000 anonymous permit + run pairs from one IP: every one refused with forbidden (403), and neither the permission callback nor the handler ever ran', array( 'forbidden/forbidden' => true ) === $codes && array( '403' => true ) === $statuses && 0 === $GLOBALS['T_ran'] && 0 === $GLOBALS['T_perm_ran'], array( $codes, $statuses, $GLOBALS['T_ran'], $GLOBALS['T_perm_ran'] ) );
check( '...and they wrote exactly 1 row, status forbidden_anonymous, user 0, with the IP', 1 === count( rows() ) && 'forbidden_anonymous' === rows()[0]['status'] && 0 === rows()[0]['user_id'] && '198.51.100.7' === rows()[0]['ip'], rows() );
// Rotating addresses (residual A): ONE site-wide key, so 1000 calls from 1000 different IPs still make 1 row and 1 transient.
anon_site();
for ( $i = 0; $i < 1000; $i++ ) { $_SERVER['REMOTE_ADDR'] = '10.' . intdiv( $i, 250 ) . '.' . ( $i % 250 ) . '.1'; d5b_ability_permit( 'divi5-builder/health-open', array() ); d5b_ability_run( 'divi5-builder/health-open', array() ); }
$anon_keys = array_values( array_filter( array_keys( $GLOBALS['T_transients'] ), function ( $k ) { return 0 === strpos( $k, 'd5b_anon' ); } ) );
check( '1000 anonymous calls from 1000 different IPs: exactly 1 row (with the first caller\'s IP) and 1 transient, d5b_anon, for 10 minutes', 1 === count( rows() ) && '10.0.0.1' === rows()[0]['ip'] && array( 'd5b_anon' ) === $anon_keys && 600 === $GLOBALS['T_transients']['d5b_anon'][2], array( count( rows() ), $anon_keys ) );
$GLOBALS['T_transients']['d5b_anon'][1] = time() - 1; // ten minutes later
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
d5b_ability_run( 'divi5-builder/health-open', array() ); $_SERVER['REMOTE_ADDR'] = '198.51.100.10'; d5b_ability_run( 'divi5-builder/health-open', array() );
check( 'after the 10 minutes one more row (the next caller\'s IP), then quiet again whatever the address', 2 === count( rows() ) && '198.51.100.9' === rows()[1]['ip'], array_column( rows(), 'ip' ) );
$GLOBALS['T_user_id'] = 5;
for ( $i = 0; $i < 30; $i++ ) { d5b_ability_permit( 'divi5-builder/health-shut', array() ); }
check( 'a logged-in refusal is still logged every time (30 refusals, 30 forbidden rows)', 32 === count( rows() ) &&30 === count( array_filter( rows(), function ( $r ) { return 'forbidden' === $r['status'] && 5 === $r['user_id']; } ) ), count( rows() ) );
$r = d5b_ability_run( 'divi5-builder/health-open', array() );
check( 'a logged-in caller is not affected: the handler runs', array( 'v' => 1 ) === $r && 1 === $GLOBALS['T_ran'], $r );
anon_site(); d5b_abilities_set_master( false, 'tester' );
check( 'master off: an anonymous call is still refused (forbidden), never run', is_wp_error( $r = d5b_ability_run( 'divi5-builder/health-open', array() ) ) && 'forbidden' === $r->get_error_code() && 0 === $GLOBALS['T_ran'] );
check( 'an anonymous refusal stores at most 1 KB of arguments', ( function () { anon_site(); d5b_ability_run( 'divi5-builder/health-open', array( 'big' => str_repeat( 'x', 7000 ) ) ); return strlen( rows()[0]['args'] ) <= 1024; } )() );

echo "the audit table notice\n";
$notice = 'The audit log table could not be created, so every AI call is refused. Ask your host to allow CREATE TABLE for the WordPress database user.';
function abilities_html() { ob_start(); d5b_settings_render_abilities(); return ob_get_clean(); }
installed(); t_set_caps( array( 'manage_options' ) ); d5b_abilities_set_master( true, 'tester' );
check( 'table present, master on: no notice', false === strpos( abilities_html(), esc_html( $notice ) ) );
set_transient( 'd5b_audit_install_failed', 1, 3600 );
check( 'the install-failed transient is set: the notice, word for word, in an error box', false !== strpos( $h = abilities_html(), '<div class="notice notice-error inline"><p>' . esc_html( $notice ) . '</p></div>' ), $h );
fresh(); t_set_caps( array( 'manage_options' ) ); d5b_abilities_set_master( true, 'tester' ); // no table, no transient
check( 'master on and the table missing (no transient): the notice', false !== strpos( abilities_html(), esc_html( $notice ) ) );
fresh(); t_set_caps( array( 'manage_options' ) );
check( 'master off and the table missing (nothing tried yet): no notice', false === strpos( abilities_html(), esc_html( $notice ) ) );

echo "activation, deactivation and uninstall of the back-off transient and the event\n";
fresh(); $GLOBALS['T_dbdelta_fail'] = false;
set_transient( 'd5b_audit_install_failed', 1, 3600 );
d5b_audit_activate();
check( 'activation deletes the one-hour back-off first, so it really tries: the table is made', d5b_audit_table_exists() && 1 === count( $GLOBALS['T_dbdelta'] ) && false === get_transient( 'd5b_audit_install_failed' ), $GLOBALS['T_dbdelta'] );
t_set_multisite( true );
$GLOBALS['T_blogs'] = array( 1 => array(), 2 => array(), 3 => array() ); $GLOBALS['T_get_sites'] = array(); $GLOBALS['T_cleared_on'] = array();
d5b_audit_deactivate( true );
check( 'network deactivation clears the prune event on every site (switching to each one and back)', array( 1, 2, 3 ) === $GLOBALS['T_cleared_on'] && 1 === get_current_blog_id(), array( $GLOBALS['T_cleared_on'], get_current_blog_id() ) );
check( 'network deactivation reads site ids in batches of 100', 100 === ( $GLOBALS['T_get_sites'][0]['number'] ?? null ) && 'ids' === ( $GLOBALS['T_get_sites'][0]['fields'] ?? null ), $GLOBALS['T_get_sites'] );
$GLOBALS['T_blogs'] = array(); for ( $b = 1; $b <= 1250; $b++ ) { $GLOBALS['T_blogs'][ $b ] = array(); }
$GLOBALS['T_get_sites'] = array(); $GLOBALS['T_cleared_on'] = array();
d5b_audit_deactivate( true );
check( 'network deactivation on 1250 sites: every one cleared (no 1000-site ceiling), 13 batches of at most 100, back on blog 1', range( 1, 1250 ) === $GLOBALS['T_cleared_on'] && 13 === count( $GLOBALS['T_get_sites'] ) && array() === array_filter( $GLOBALS['T_get_sites'], function ( $a ) { return 100 !== ( $a['number'] ?? 0 ); } ) && 1 === get_current_blog_id(), array( count( $GLOBALS['T_cleared_on'] ), count( $GLOBALS['T_get_sites'] ) ) );
$GLOBALS['T_blogs'] = array( 1 => array(), 2 => array(), 3 => array() );
$GLOBALS['T_cleared_on'] = array();
d5b_audit_deactivate( false );
check( 'deactivation on one site of a network clears only that site', array( 1 ) === $GLOBALS['T_cleared_on'], $GLOBALS['T_cleared_on'] );
t_set_multisite( false );

echo "source checks\n";
$src = file_get_contents( $dir . '/inc/abilities/audit.php' );
check( 'audit.php defines functions only: no add_action / add_filter / register_* / wp_schedule_event at load time', 0 === preg_match( '/^(?!\s*(\/\/|\*|\/\*)).*\b(add_action|add_filter|register_[a-z_]+_hook)\s*\(/m', $src ) );
check( 'D5B_DB_VERSION is defined guarded', 1 === preg_match( '/if \( ! defined\( \'D5B_DB_VERSION\' \) \) \{ define\( \'D5B_DB_VERSION\', \'2\' \); \}/', $src ) );
check( 'no direct use of $_GET / $_POST / $_REQUEST in audit.php (the table name comes from $wpdb->prefix)', 0 === preg_match( '/\$_(GET|POST|REQUEST)\b/', $src ) && false !== strpos( $src, "\$wpdb->prefix . 'd5b_audit'" ) );

echo "the change history list on the settings page (2.2.0)\n";
function put_change( $ability, $user, $secs_ago, $extra = array() ) {
	global $wpdb; $t = d5b_history_table();
	$wpdb->tables[ $t ][] = array_merge( array( 'id' => 3000 + count( $wpdb->tables[ $t ] ?? array() ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $secs_ago ), 'user_id' => $user, 'ability' => $ability, 'audit_id' => 0, 'object_type' => 'post', 'object_id' => 7, 'action' => 'update', 'before' => '{"title":"Old"}', 'after' => '{"title":"New"}', 'revision_id' => null, 'summary' => 'Changed the title' ), $extra );
}
installed(); t_set_caps( array() ); $GLOBALS['T_user_id'] = 1;
put_change( '<script>alert(1)</script>', 4, 60, array( 'object_type' => '<b>post</b>', 'action' => '<i>x</i>', 'summary' => '"quoted" & <img src=x onerror=alert(2)>', 'revision_id' => 88, 'before' => '{"body":"api_key=SECRETVALUE99 end","note":"<script>alert(5)</script>"}', 'after' => '{"title":"</pre><script>alert(4)</script>"}' ) );
put_change( 'divi5-builder/pages-update', 2, 20 * 86400, array( 'object_id' => 9, 'object_type' => 'page' ) );
put_change( 'divi5-builder/posts-update', 2, 120 );
check( 'the history list needs the settings capability: without it nothing is printed', '' === html( 'd5b_settings_render_history' ) );
t_set_caps( array( 'manage_options' ) );
$out = html( 'd5b_settings_render_history' );
check( 'with the capability the section, its table and its three filters are shown', false !== strpos( $out, 'Change history' ) && false !== strpos( $out, '<table' ) && false !== strpos( $out, 'name="d5b_hist_ability"' ) && false !== strpos( $out, 'name="d5b_hist_user"' ) && false !== strpos( $out, 'name="d5b_hist_since"' ), $out );
check( 'the columns are time, login, ability, action, summary, object, revision and the copies', false !== strpos( $out, '<th>Time (UTC)</th><th>Login</th><th>Ability</th><th>Action</th><th>Summary</th><th>Object</th><th>Revision</th>' ), $out );
check( 'escapes every field: no raw tag from a stored value, including inside the copies', false === strpos( $out, '<script>' ) && false === strpos( $out, '<img' ) && false === strpos( $out, '<b>post' ) && false === strpos( $out, '<i>x' ) && false !== strpos( $out, '&lt;script&gt;alert(1)&lt;/script&gt;' ) && false !== strpos( $out, '&quot;quoted&quot;' ) && false === strpos( $out, '</pre><script>' ), $out );
check( 'the object shows as type #id, escaped', false !== strpos( $out, '&lt;b&gt;post&lt;/b&gt; #7' ) && false !== strpos( $out, 'page #9' ), $out );
check( 'a revision links to wp-admin revision.php?revision=<id>; a change without one has no link', 1 === substr_count( $out, 'revision.php?revision=88' ) && 1 === substr_count( $out, 'revision.php' ), $out );
check( 'a row opens (details) to show before and after as JSON in pre blocks', 3 === substr_count( $out, '<details>' ) && 6 === substr_count( $out, '<pre' ) && false !== strpos( $out, '&quot;title&quot;: &quot;Old&quot;' ), $out );
check( 'the copies are masked with the STRICT masker on display: a secret-shaped value in a stored copy does not show', false === strpos( $out, 'SECRETVALUE99' ) && false !== strpos( $out, 'api_key' ), $out );
check( 'read-only: no delete, clear or purge control, and no POST form', 1 !== preg_match( '/delete|clear|purge|remove/i', str_replace( '<td>deleted user</td>', '', $out ) ) && false === stripos( $out, 'method="post"' ), $out );
check( 'the login column: a missing user shows "deleted user"', false !== strpos( $out, '<td>deleted user</td>' ), $out );
$_GET = array( 'd5b_hist_ability' => 'divi5-builder/posts-update' ); $out = html( 'd5b_settings_render_history' );
check( 'filter by ability: only that ability, the input keeps the value', 1 === substr_count( $out, '<details>' ) && false !== strpos( $out, 'name="d5b_hist_ability" value="divi5-builder/posts-update"' ), $out );
$_GET = array( 'd5b_hist_user' => '2abc' ); $out = html( 'd5b_settings_render_history' );
check( 'filter by user is absint (2abc -> 2): the two rows of user 2', 2 === substr_count( $out, '<details>' ) && false !== strpos( $out, 'name="d5b_hist_user" value="2"' ), $out );
$day = gmdate( 'Y-m-d', time() - 5 * 86400 );
$_GET = array( 'd5b_hist_since' => $day ); $out = html( 'd5b_settings_render_history' );
check( 'filter by day: the 20-day-old row is left out, the input keeps the value', 2 === substr_count( $out, '<details>' ) && false === strpos( $out, 'page #9' ) && false !== strpos( $out, 'name="d5b_hist_since" value="' . $day . '"' ), $out );
foreach ( array( '2026-13-45', 'yesterday', '"><script>x</script>', '2026-02-30' ) as $bad ) {
	$_GET = array( 'd5b_hist_since' => $bad ); $out = html( 'd5b_settings_render_history' );
	check( "a bad day is ignored and never echoed: $bad", 3 === substr_count( $out, '<details>' ) && false === strpos( $out, '<script>' ) && false !== strpos( $out, 'name="d5b_hist_since" value=""' ), $out );
}
$_GET = array( 'd5b_hist_ability' => array( 'x' ), 'd5b_hist_user' => array( '1' ), 'd5b_hist_since' => array( '2026-01-01' ) );
check( 'array-valued query parameters do not break the page', 3 === substr_count( html( 'd5b_settings_render_history' ), '<details>' ) );
$_GET = array( 'd5b_hist_ability' => 'nothing/here' );
check( 'no match shows the empty line', false !== strpos( html( 'd5b_settings_render_history' ), 'No changes recorded' ) );
$_GET = array();
for ( $i = 0; $i < 70; $i++ ) { put_change( 'bulk/' . $i, 1, 10 + $i ); }
check( 'the screen shows the newest 50 changes only', 50 === substr_count( html( 'd5b_settings_render_history' ), '<details>' ) );
t_set_multisite( true ); t_set_caps( array( 'manage_options' ) );
check( 'multisite: manage_options is not enough, network capability required', '' === html( 'd5b_settings_render_history' ) );
t_set_caps( array( 'manage_network_options' ) );
check( 'multisite: the network capability sees it', false !== strpos( html( 'd5b_settings_render_history' ), 'Change history' ) );
t_set_multisite( false );
t_set_caps( array( 'manage_options' ) );
check( 'the full settings page shows the section, below the audit log', ( function () { $o = html( 'd5b_settings_render' ); $a = strpos( $o, 'Audit log' ); $h = strpos( $o, 'Change history' ); return false !== $a && false !== $h && $h > $a; } )() );

echo "the ok_no_history notice\n";
installed(); t_set_caps( array( 'manage_options' ) );
$msg = 'A change was made in the last 24 hours that could not be recorded in the change history. Check the audit log rows marked ok_no_history.';
check( 'no ok_no_history row: no notice on the settings page', false === strpos( html( 'd5b_settings_render' ), $msg ) );
stored_row_at( 3 * 86400, 'divi5-builder/posts-update' ); $r = $GLOBALS['wpdb']->tables[ d5b_audit_table() ]; $GLOBALS['wpdb']->tables[ d5b_audit_table() ][ count( $r ) - 1 ]['status'] = 'ok_no_history';
check( 'an ok_no_history row older than 24 hours: no notice', false === strpos( html( 'd5b_settings_render' ), $msg ) );
stored_row_at( 3600, 'divi5-builder/posts-update' ); $r = $GLOBALS['wpdb']->tables[ d5b_audit_table() ]; $GLOBALS['wpdb']->tables[ d5b_audit_table() ][ count( $r ) - 1 ]['status'] = 'ok_no_history';
$out = html( 'd5b_settings_render' );
check( 'an ok_no_history row from the last 24 hours: the notice shows, once, in a warning box, word for word', 1 === substr_count( $out, '<div class="notice notice-warning"><p>' . esc_html( $msg ) . '</p></div>' ), $out );
t_set_caps( array() );
check( 'a user without the capability sees no page and so no notice', '' === html( 'd5b_settings_render' ) );


echo "\n$n checks, $failed failed\n";
exit( $failed ? 1 : 0 );
