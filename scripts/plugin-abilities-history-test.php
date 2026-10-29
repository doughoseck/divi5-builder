<?php
/**
 * The change history store (#32, release 2.2.0, Task 1).
 *
 *   php scripts/plugin-abilities-history-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php, which keeps the tables in memory).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: the history table is made by the same install path as the audit table (version '2'; a site
 * already at '1' gets only the new table); a row keeps who, when, which audit row, what and a summary; before and after are
 * JSON with secret-named keys removed and nothing else changed; a side over 8 MB is replaced by a marker and the row is
 * still written; list/get/prune behave; a missing table never throws; the run wrapper turns a failed history write into the
 * audit status ok_no_history and never lets the marker key reach the caller; every new error code has its HTTP status.
 * (Uninstall of the table is in plugin-uninstall-test.php.)
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

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

function fresh() { t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( array() ); t_set_multisite( false ); $GLOBALS['T_user_id'] = 1; $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_registry( array() ); d5b_ability_audit_current_state( 0 ); }
function installed() { fresh(); d5b_audit_maybe_install(); }
function hrows() { global $wpdb; return $wpdb->tables[ d5b_history_table() ] ?? array(); }
function arows() { global $wpdb; return $wpdb->tables[ d5b_audit_table() ] ?? array(); }
function hist( $o = array() ) { return array_merge( array( 'ability' => 'divi5-builder/posts-update', 'object_type' => 'post', 'object_id' => 12, 'action' => 'update', 'before' => array( 'title' => 'Old' ), 'after' => array( 'title' => 'New' ), 'revision_id' => 99, 'summary' => 'Changed the title' ), $o ); }
function stored_at( $table, $secs_ago, $extra = array() ) {
	global $wpdb;
	$base = array( 'id' => 900 + count( $wpdb->tables[ $table ] ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $secs_ago ), 'user_id' => 1, 'ability' => 'x/y' );
	$wpdb->tables[ $table ][] = array_merge( $base, 'wp_d5b_audit' === $table ? array( 'args' => '{}', 'status' => 'ok', 'duration_ms' => 1, 'ip' => '' ) : array( 'audit_id' => 0, 'object_type' => 'post', 'object_id' => 1, 'action' => 'update', 'before' => '{}', 'after' => '{}', 'revision_id' => null, 'summary' => '' ), $extra );
}
function add_ability( $short, $handler ) { d5b_ability_add( array( 'name' => 'divi5-builder/' . $short, 'category' => 'posts', 'label' => $short, 'description' => 'x', 'handler' => $handler, 'permission' => function () { return true; } ) ); }

echo "install\n";
fresh();
check( 'the schema version is 2', '2' === D5B_DB_VERSION );
d5b_audit_maybe_install();
check( 'install creates both tables once; d5b_db_version becomes 2', isset( $wpdb->tables['wp_d5b_audit'], $wpdb->tables['wp_d5b_history'] ) && '2' === get_option( 'd5b_db_version' ) && 1 === count( $GLOBALS['T_dbdelta'] ), array( array_keys( $wpdb->tables ), $GLOBALS['T_dbdelta'] ) );
d5b_audit_maybe_install();
check( 'a second call does nothing (dbDelta not run again)', 1 === count( $GLOBALS['T_dbdelta'] ) );
$sql = $GLOBALS['T_dbdelta'][0];
check( 'the history table has its twelve columns, the indexes, and two spaces after PRIMARY KEY (dbDelta needs them)',
	1 === preg_match( '/CREATE TABLE wp_d5b_history \(\s+id bigint\(20\) unsigned NOT NULL AUTO_INCREMENT,\s+created_at datetime NOT NULL,\s+user_id bigint\(20\) unsigned.*ability varchar\(191\).*audit_id bigint\(20\) unsigned.*object_type varchar\(64\).*object_id bigint\(20\) unsigned.*action varchar\(16\).*`before` longtext.*`after` longtext.*revision_id bigint\(20\) unsigned NULL.*summary varchar\(191\).*PRIMARY KEY  \(id\).*KEY created_at \(created_at\).*KEY ability \(ability\).*KEY object \(object_type,object_id\)/s', $sql ), $sql );
// A site that is already at version 1 has the audit table (and rows in it) but not the history table.
fresh(); $wpdb->tables['wp_d5b_audit'] = array( array( 'id' => 5, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'user_id' => 1, 'ability' => 'a/b', 'args' => '{}', 'status' => 'ok', 'duration_ms' => 1, 'ip' => '' ) );
update_option( 'd5b_db_version', '1' );
d5b_audit_maybe_install();
check( 'upgrading from 1 creates the history table, keeps the audit table and its rows, and flips the version to 2', isset( $wpdb->tables['wp_d5b_history'] ) && 1 === count( $wpdb->tables['wp_d5b_audit'] ) && '2' === get_option( 'd5b_db_version' ), array_keys( $wpdb->tables ) );
fresh(); $GLOBALS['T_dbdelta_fail'] = true; update_option( 'd5b_db_version', '1' );
$wpdb->tables['wp_d5b_audit'] = array();
d5b_audit_maybe_install();
check( 'when the history table cannot be made the version stays 1 and the one-hour back-off is set', '1' === get_option( 'd5b_db_version' ) && false !== get_transient( 'd5b_audit_install_failed' ) && ! isset( $wpdb->tables['wp_d5b_history'] ) );
d5b_audit_maybe_install();
check( '... and a second attempt in the same request runs no more dbDelta', 1 === count( $GLOBALS['T_dbdelta'] ) );

echo "write\n";
installed();
$GLOBALS['T_user_id'] = 7;
$before = array( 'title' => 'Old', 'password' => 'hunter2', 'nested' => array( 'api_key' => 'k-123', 'keep' => 'me' ), 'content' => 'See password=hunter2 and Bearer abc123def456ghi' );
$after  = array( 'title' => 'New', 'user_pass' => 'p', 'meta' => array( 'access_token' => 'zzz', 'ok' => 1 ) );
$id = d5b_history_write( hist( array( 'before' => $before, 'after' => $after ) ) );
$r  = hrows()[0] ?? array();
check( 'write returns the new row id (an int) and stores one row', is_int( $id ) && $id > 0 && 1 === count( hrows() ) && $id === $r['id'], $id );
check( 'the row has the user, UTC time, ability, object, action, revision and summary', 7 === $r['user_id'] && 'divi5-builder/posts-update' === $r['ability'] && 'post' === $r['object_type'] && 12 === $r['object_id'] && 'update' === $r['action'] && 99 === $r['revision_id'] && 'Changed the title' === $r['summary'] && abs( strtotime( $r['created_at'] . ' UTC' ) - time() ) < 5, $r );
check( 'outside an ability call the audit id is 0', 0 === $r['audit_id'] );
$b = json_decode( $r['before'], true ); $a = json_decode( $r['after'], true );
check( 'before is JSON with the secret-named keys removed (password, nested api_key), the rest kept', 'Old' === $b['title'] && ! array_key_exists( 'password', $b ) && ! array_key_exists( 'api_key', $b['nested'] ) && 'me' === $b['nested']['keep'], $b );
check( 'after is JSON with the secret-named keys removed (user_pass, nested access_token), the rest kept', 'New' === $a['title'] && ! array_key_exists( 'user_pass', $a ) && ! array_key_exists( 'access_token', $a['meta'] ) && 1 === $a['meta']['ok'], $a );
check( 'the removed keys values are nowhere in the stored text', false === strpos( $r['before'], 'k-123' ) && false === strpos( $r['after'], 'zzz' ) && false === strpos( $r['after'], 'user_pass' ) );
check( 'text is NOT masked: content that merely looks like a secret is stored as it was (restore needs it)', 'See password=hunter2 and Bearer abc123def456ghi' === $b['content'], $b['content'] );
check( 'no revision: revision_id is null', ( function () { d5b_history_write( hist( array( 'revision_id' => null ) ) ); return null === hrows()[1]['revision_id']; } )() );
d5b_history_write( hist( array( 'ability' => str_repeat( 'a', 300 ), 'object_type' => str_repeat( 'o', 100 ), 'action' => str_repeat( 'c', 40 ), 'summary' => str_repeat( 's', 400 ) ) ) );
$r = hrows()[2];
check( 'ability is cut to 191, object_type to 64, action to 16, summary to 191', 191 === strlen( $r['ability'] ) && 64 === strlen( $r['object_type'] ) && 16 === strlen( $r['action'] ) && 191 === strlen( $r['summary'] ) );
check( 'an object as a side becomes an array (never passes through unscrubbed)', ( function () { d5b_history_write( hist( array( 'before' => (object) array( 'title' => 'T', 'password' => 'x' ) ) ) ); $j = json_decode( hrows()[3]['before'], true ); return array( 'title' => 'T' ) === $j; } )() );
check( 'd5b_ability_scrub_keys on its own: removes keys at any depth, leaves strings alone, bounded depth', array( 'a' => array( 'b' => 'x password=y' ) ) === d5b_ability_scrub_keys( array( 'a' => array( 'b' => 'x password=y', 'secret' => 's' ), 'token' => 't' ) ) && 'plain' === d5b_ability_scrub_keys( 'plain' ) && ( function () { $d = 'leaf'; for ( $i = 0; $i < 40; $i++ ) { $d = array( 'k' => $d ); } return false !== strpos( json_encode( d5b_ability_scrub_keys( $d ) ), 'too deep' ); } )() );

echo "audit id\n";
installed(); d5b_abilities_set_master( true, 't' );
$seen = array();
add_ability( 'posts-hist', function ( array $i ) use ( &$seen ) { $seen['inside'] = d5b_ability_audit_current_id(); $seen['hid'] = d5b_history_write( hist() ); return array( 'ok' => 1 ); } );
check( 'outside any call the current audit id is 0', 0 === d5b_ability_audit_current_id() );
d5b_ability_run( 'divi5-builder/posts-hist', array() );
$arow = arows()[0];
check( 'inside a call, d5b_ability_audit_current_id() is that call\'s audit row and the history row carries it', $seen['inside'] === $arow['id'] && $arow['id'] > 0 && hrows()[0]['audit_id'] === $arow['id'], array( $seen, $arow['id'], hrows() ) );
check( 'after the call the id is cleared again', 0 === d5b_ability_audit_current_id() );
$log = array();
add_ability( 'posts-inner', function ( array $i ) use ( &$log ) { $log['inner'] = d5b_ability_audit_current_id(); return array( 'in' => 1 ); } );
add_ability( 'posts-outer', function ( array $i ) use ( &$log ) { $log['before'] = d5b_ability_audit_current_id(); d5b_ability_run( 'divi5-builder/posts-inner', array() ); $log['after'] = d5b_ability_audit_current_id(); return array( 'out' => 1 ); } );
d5b_ability_run( 'divi5-builder/posts-outer', array() );
$ids = array_column( arows(), 'id', 'ability' );
check( 'a nested call sees its own id and the outer id is restored afterwards', $log['inner'] === $ids['divi5-builder/posts-inner'] && $log['before'] === $ids['divi5-builder/posts-outer'] && $log['after'] === $log['before'] && $log['inner'] !== $log['before'], array( $log, $ids ) );
add_ability( 'posts-boom', function ( array $i ) { throw new RuntimeException( 'boom' ); } );
d5b_ability_run( 'divi5-builder/posts-boom', array() );
check( 'a handler that throws still clears the id', 0 === d5b_ability_audit_current_id() );

echo "8 MB limit\n";
installed();
// Task 11 I1: the cap is per FIELD. The content is kept whole (up to 8 MB on its own); the other fields over the cap are
// replaced one by one, largest first, by {too_large, bytes}; a side whose content alone is over 8 MB is one marker.
$big = array( 'content' => str_repeat( 'x', 8 * 1024 * 1024 + 10 ) );
$id  = d5b_history_write( hist( array( 'before' => $big ) ) );
$r   = hrows()[0] ?? array();
$bj  = json_decode( $r['before'] ?? '', true );
check( 'a side whose content alone is over 8 MB is stored as too_large with its byte count, and the row is still written', $id > 0 && true === ( $bj['too_large'] ?? null ) && is_int( $bj['bytes'] ) && $bj['bytes'] > 8 * 1024 * 1024 && strlen( $r['before'] ) < 100 && 'New' === json_decode( $r['after'], true )['title'] && d5b_history_last_was_truncated(), array( $id, substr( $r['before'] ?? '', 0, 100 ) ) );
$near = array( 'blob' => str_repeat( 'y', 8 * 1024 * 1024 - 100 ) );
d5b_history_write( hist( array( 'after' => $near ) ) );
check( 'a side just under 8 MB is stored whole (and the row is not marked truncated)', strlen( hrows()[1]['after'] ) > 8 * 1024 * 1024 - 200 && ! isset( json_decode( hrows()[1]['after'], true )['too_large'] ) && ! d5b_history_last_was_truncated() );
d5b_history_write( hist( array( 'before' => array( 'bad' => "\xB1\x31", 'ok' => 'fine' ) ) ) );
$sb = json_decode( hrows()[2]['before'], true );
check( 'invalid UTF-8 in a side still stores valid JSON: the bad bytes as U+FFFD, the side marked _utf8_substituted (Task 11 M2)', is_array( $sb ) && "\u{FFFD}1" === $sb['bad'] && 'fine' === $sb['ok'] && true === $sb['_utf8_substituted'] && d5b_history_last_was_substituted() && ! d5b_history_last_was_truncated(), $sb );
d5b_history_write( hist( array( 'before' => array( 'ok' => 'fine' ) ) ) );
check( '... the flags are reset by the next row', ! d5b_history_last_was_substituted() && ! d5b_history_last_was_truncated() && ! isset( json_decode( hrows()[3]['before'], true )['_utf8_substituted'] ) );
$post = array( 'title' => 'T', 'content' => 'the only copy of v1', 'meta' => array( 'note' => array( 'a', str_repeat( 'n', 5 * 1024 * 1024 ) ) ), 'protected_meta' => array( '_cache' => array( str_repeat( 'c', 9 * 1024 * 1024 ) ), '_small' => array( 's' ) ) );
d5b_history_write( hist( array( 'before' => $post ) ) );
$pb = json_decode( hrows()[4]['before'], true );
check( 'per field: a 9 MB protected meta value is replaced by its marker, the content, the title, the small fields and the 5 MB value (the rest fits) stay whole',
	'the only copy of v1' === $pb['content'] && 'T' === $pb['title'] && array( 's' ) === $pb['protected_meta']['_small'] && true === $pb['protected_meta']['_cache'][0]['too_large'] && $pb['protected_meta']['_cache'][0]['bytes'] > 9 * 1024 * 1024
	&& 'a' === $pb['meta']['note'][0] && 5 * 1024 * 1024 === strlen( $pb['meta']['note'][1] ) && d5b_history_last_was_truncated(), substr( hrows()[4]['before'], 0, 300 ) );
$two = array( 'content' => str_repeat( 'k', 7 * 1024 * 1024 ), 'meta' => array( 'a' => array( str_repeat( 'a', 5 * 1024 * 1024 ) ), 'b' => array( str_repeat( 'b', 6 * 1024 * 1024 ) ) ) );
d5b_history_write( hist( array( 'before' => $two ) ) );
$tb = json_decode( hrows()[5]['before'], true );
check( '... largest first, only as many as needed: the 6 MB value goes, the 5 MB one stays, the 7 MB content stays whole', 7 * 1024 * 1024 === strlen( $tb['content'] ) && 5 * 1024 * 1024 === strlen( $tb['meta']['a'][0] ) && true === $tb['meta']['b'][0]['too_large'] );
$did = d5b_history_write( hist( array( 'before' => array( 'x' => 1 ) ) ) );
check( 'd5b_history_delete removes exactly that row', d5b_history_delete( $did ) && null === d5b_history_get( $did ) && 6 === count( hrows() ) && ! d5b_history_delete( $did ) );

echo "list\n";
installed();
foreach ( array( array( 'a/one', 1, 'post', 10, 0 ), array( 'a/two', 2, 'term', 20, 0 ), array( 'a/one', 2, 'post', 30, 0 ), array( 'a/three', 1, 'post', 10, 0 ) ) as $x ) {
	$GLOBALS['T_user_id'] = $x[1]; d5b_history_write( hist( array( 'ability' => $x[0], 'object_type' => $x[2], 'object_id' => $x[3], 'summary' => 's-' . $x[0] ) ) );
}
// the row for the since filter: an old one
stored_at( 'wp_d5b_history', 5 * 86400, array( 'ability' => 'a/old', 'object_type' => 'post', 'object_id' => 10 ) );
check( 'list is newest first (by id)', array( 'a/old', 'a/three', 'a/one', 'a/two', 'a/one' ) === array_column( d5b_history_list( array() ), 'ability' ) );
check( 'filter by ability', 2 === count( d5b_history_list( array( 'ability' => 'a/one' ) ) ) );
check( 'filter by user', array( 'a/old', 'a/three', 'a/one' ) === array_column( d5b_history_list( array( 'user_id' => 1 ) ), 'ability' ) );
check( 'filter by object_type', array( 'a/two' ) === array_column( d5b_history_list( array( 'object_type' => 'term' ) ), 'ability' ) );
check( 'filter by object_id', array( 'a/old', 'a/three', 'a/one' ) === array_column( d5b_history_list( array( 'object_id' => 10 ) ), 'ability' ) );
check( 'filter by object_type and object_id together', array( 'a/two' ) === array_column( d5b_history_list( array( 'object_type' => 'term', 'object_id' => 20 ) ), 'ability' ) && array() === d5b_history_list( array( 'object_type' => 'term', 'object_id' => 10 ) ) );
check( 'filter by since', 4 === count( d5b_history_list( array( 'since' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ) ) ) );
check( 'filter by ability and user together', 1 === count( d5b_history_list( array( 'ability' => 'a/one', 'user_id' => 2 ) ) ) );
check( 'limit and offset', array( 'a/three', 'a/one' ) === array_column( d5b_history_list( array(), 2, 1 ), 'ability' ) );
$row0 = d5b_history_list( array() )[1];
check( 'rows come without before and after, with integer ids', ! array_key_exists( 'before', $row0 ) && ! array_key_exists( 'after', $row0 ) && is_int( $row0['id'] ) && is_int( $row0['user_id'] ) && is_int( $row0['object_id'] ) && is_int( $row0['audit_id'] ) && 'post' === $row0['object_type'] && 's-a/three' === $row0['summary'], $row0 );
check( 'a hostile filter value matches nothing (it is a value, not SQL)', array() === d5b_history_list( array( 'ability' => "x' OR '1'='1", 'object_type' => "p' OR '1'='1" ) ) );
check( 'every SQL string the history code sent went through prepare()', array() === $wpdb->unprepared && count( $wpdb->queries ) > 3, $wpdb->unprepared );

echo "get\n";
installed();
$id = d5b_history_write( hist( array( 'before' => array( 'a' => array( 1, 2 ), 'password' => 'x' ), 'after' => array( 'b' => 'é' ) ) ) );
$g  = d5b_history_get( $id );
check( 'get decodes before and after into arrays and returns the whole row', array( 'a' => array( 1, 2 ) ) === $g['before'] && array( 'b' => 'é' ) === $g['after'] && $id === $g['id'] && 12 === $g['object_id'] && 99 === $g['revision_id'] && 'Changed the title' === $g['summary'] && 'update' === $g['action'], $g );
check( 'get of an unknown id is null; of 0 is null', null === d5b_history_get( $id + 50 ) && null === d5b_history_get( 0 ) );
$id2 = d5b_history_write( hist( array( 'before' => $big ) ) );
$g2 = d5b_history_get( $id2 );
check( 'get of a too_large side returns the marker array', true === ( $g2['before']['too_large'] ?? null ) && is_int( $g2['before']['bytes'] ) );
check( 'get goes through prepare()', array() === $wpdb->unprepared );
fresh();
check( 'get and list with no table: null and an empty array', null === d5b_history_get( 1 ) && array() === d5b_history_list( array() ) );

echo "prune\n";
installed();
stored_at( 'wp_d5b_history', 31 * 86400, array( 'ability' => 'old/31d' ) ); stored_at( 'wp_d5b_history', 45 * 86400, array( 'ability' => 'old/45d' ) ); stored_at( 'wp_d5b_history', 29 * 86400, array( 'ability' => 'new/29d' ) ); stored_at( 'wp_d5b_history', 60, array( 'ability' => 'new/now' ) );
stored_at( 'wp_d5b_audit', 31 * 86400, array( 'ability' => 'aold/31d' ) ); stored_at( 'wp_d5b_audit', 29 * 86400, array( 'ability' => 'anew/29d' ) );
$gone = d5b_audit_prune();
check( 'prune removes rows older than 30 days only, from both tables', array( 'new/29d', 'new/now' ) === array_column( hrows(), 'ability' ) && array( 'anew/29d' ) === array_column( arows(), 'ability' ), array( array_column( hrows(), 'ability' ), array_column( arows(), 'ability' ) ) );
check( 'the audit prune still returns the number of AUDIT rows deleted', 1 === $gone, $gone );
stored_at( 'wp_d5b_history', 40 * 86400, array( 'ability' => 'old/40d' ) );
check( 'd5b_history_prune on its own returns the count and a second one deletes nothing', 1 === d5b_history_prune() && 0 === d5b_history_prune() );
$wpdb->unprepared = array(); d5b_history_prune();
check( 'history prune goes through prepare() too', array() === $wpdb->unprepared );
fresh();
check( 'prune with no tables returns 0, no fatal', 0 === d5b_audit_prune() && 0 === d5b_history_prune() );

echo "no table\n";
fresh(); $GLOBALS['T_dbdelta_fail'] = true;
$ok = true; $threw = false;
try { $ok = d5b_history_write( hist() ); } catch ( Throwable $t ) { $threw = true; }
check( 'write with no table (and none can be made): 0, no exception, nothing stored', 0 === $ok && ! $threw && array() === $wpdb->tables );
$wpdb->fail_insert = true;
$ok = true; $threw = false;
try { $ok = d5b_history_write( hist() ); } catch ( Throwable $t ) { $threw = true; }
check( 'a database that throws on insert: write returns 0, never throws', 0 === $ok && ! $threw );
$wpdb->fail_insert = false; $GLOBALS['T_dbdelta_fail'] = false; delete_transient( 'd5b_audit_install_failed' ); d5b_audit_install_state( 'reset' );
check( 'write when the table is gone but the version says installed: the tables are made on the spot and the row stored', d5b_history_write( hist() ) > 0 && 1 === count( hrows() ) && '2' === get_option( 'd5b_db_version' ) );
installed(); unset( $wpdb->tables['wp_d5b_history'] ); d5b_audit_install_state( 'reset' ); $before_dd = count( $GLOBALS['T_dbdelta'] );
$a1 = d5b_history_write( hist() ); $a2 = d5b_history_write( hist() );
check( 'the repair is bounded: once per request (two writes after a dropped table run dbDelta once)', $a1 > 0 && $a2 > 0 && 1 === count( $GLOBALS['T_dbdelta'] ) - $before_dd );
check( 'nothing it sent was unprepared', array() === $wpdb->unprepared, $wpdb->unprepared );
$wpdb2 = $GLOBALS['wpdb']; unset( $GLOBALS['wpdb'] );
check( 'no $wpdb at all: write 0, list empty, get null, prune 0, table_exists false', 0 === d5b_history_write( hist() ) && array() === d5b_history_list( array() ) && null === d5b_history_get( 1 ) && 0 === d5b_history_prune() && false === d5b_history_table_exists() );
$GLOBALS['wpdb'] = $wpdb2;

echo "the run wrapper: ok_no_history\n";
installed(); d5b_abilities_set_master( true, 't' );
add_ability( 'posts-failh', function ( array $i ) { return array( 'id' => 5, 'changed' => true, '_history_failed' => true ); } );
add_ability( 'posts-goodh', function ( array $i ) { return array( 'id' => 6, 'history_id' => d5b_history_write( hist() ) ); } );
add_ability( 'posts-nofalse', function ( array $i ) { return array( 'id' => 7, '_history_failed' => false ); } );
add_ability( 'posts-errh', function ( array $i ) { return new WP_Error( 'stale', 'changed since you read it' ); } );
$res = d5b_ability_run( 'divi5-builder/posts-failh', array() );
check( 'a handler that reports a failed history write: the audit row ends ok_no_history', 'ok_no_history' === arows()[0]['status'], arows()[0]['status'] );
check( '... and _history_failed is stripped from the response, the rest is kept', array( 'id' => 5, 'changed' => true ) === $res, $res );
check( '... and the finished row is not rewritten with args (like ok)', '{}' === arows()[0]['args'] || array() === json_decode( arows()[0]['args'], true ), arows()[0]['args'] );
$res = d5b_ability_run( 'divi5-builder/posts-goodh', array() );
check( 'a handler whose history write worked: status ok, history_id in the response', 'ok' === arows()[1]['status'] && $res['history_id'] > 0 );
$res = d5b_ability_run( 'divi5-builder/posts-nofalse', array() );
check( '_history_failed => false: the key is still stripped and the status is ok', 'ok' === arows()[2]['status'] && array( 'id' => 7 ) === $res, array( arows()[2]['status'], $res ) );
$res = d5b_ability_run( 'divi5-builder/posts-errh', array() );
check( 'a WP_Error keeps its error: status', 'error:stale' === arows()[3]['status'] && is_wp_error( $res ), arows()[3]['status'] );
check( 'a stale error answers with HTTP 409', 409 === $res->get_error_data( 'stale' )['status'], $res->get_error_data( 'stale' ) );

echo "d5b_history_notice_needed\n";
installed();
check( 'no ok_no_history row: no notice', false === d5b_history_notice_needed() );
stored_at( 'wp_d5b_audit', 3600, array( 'status' => 'ok' ) );
check( 'only ok rows: no notice', false === d5b_history_notice_needed() );
stored_at( 'wp_d5b_audit', 2 * 86400, array( 'status' => 'ok_no_history' ) );
check( 'an ok_no_history row older than 24 hours: no notice', false === d5b_history_notice_needed() );
stored_at( 'wp_d5b_audit', 3600, array( 'status' => 'ok_no_history' ) );
check( 'an ok_no_history row from the last 24 hours: notice', true === d5b_history_notice_needed() );
fresh();
check( 'no audit table: no notice, no fatal', false === d5b_history_notice_needed() );

echo "HTTP statuses\n";
foreach ( array( 'stale' => 409, 'masked_read' => 409, 'confirmation_invalid' => 409, 'crosses_markup' => 409, 'ambiguous' => 409, 'cannot_trash' => 409, 'history_unavailable' => 503, 'needs_unfiltered_html' => 403, 'backup_not_found' => 404, 'not_found' => 404 ) as $code => $http ) {
	check( "$code answers with $http", $http === d5b_ability_http_status( $code ), d5b_ability_http_status( $code ) );
}
check( 'the older codes keep their status', 400 === d5b_ability_http_status( 'invalid_input' ) && 403 === d5b_ability_http_status( 'forbidden' ) && 503 === d5b_ability_http_status( 'audit_unavailable' ) && 500 === d5b_ability_http_status( 'something_else' ) );

echo "source checks\n";
$src = file_get_contents( $dir . '/inc/abilities/history.php' );
check( 'history.php defines functions only: no add_action / add_filter / register_* at load time', 0 === preg_match( '/^(?!\s*(\/\/|\*|\/\*)).*\b(add_action|add_filter|register_[a-z_]+_hook)\s*\(/m', $src ) );
check( 'history.php has the ABSPATH guard and no $_GET / $_POST / $_REQUEST; the table name comes from $wpdb->prefix', false !== strpos( $src, "defined( 'ABSPATH' )" ) && 0 === preg_match( '/\$_(GET|POST|REQUEST)\b/', $src ) && false !== strpos( $src, "\$wpdb->prefix . 'd5b_history'" ) );
$loader = file_get_contents( $dir . '/divi5-builder.php' );
check( 'the loader lists history.php', false !== strpos( $loader, "'inc/abilities/history.php'" ) );
$stale = file_get_contents( $dir . '/inc/settings.php' );
check( 'the settings legend lists ok_no_history', false !== strpos( $stale, 'ok_no_history' ) );

echo "\n$n checks, $failed failed\n";
exit( $failed ? 1 : 0 );
