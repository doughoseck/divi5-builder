<?php
/**
 * Write abilities for the cache and scheduled tasks (#32, release 2.3.0, Task 5): cache-flush and cron-run.
 *
 *   php scripts/plugin-abilities-write-site-test.php
 *
 * Loads the real inc/ files and parts/updates.php (the cache detection and flush the Updates part shares) with WordPress
 * stand-ins (plugin-test-stubs.php: options, transients, the cron array, core's reschedule / unschedule and the hook
 * runner, all recording in order). PHP's own microtime, set_time_limit and register_shutdown_function cannot be replaced, so
 * write-site.php reaches them through $GLOBALS['d5b_wsite_seams'] (now, set_time_limit, register_shutdown), which this test
 * fills. Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: cache-flush empties every detected cache once, needs no Updates switch, writes one history row
 * whose before is the detection; cron-run runs only an event that is scheduled, identified exactly (hook + timestamp, the
 * schedule when given, the key when several share hook + timestamp), never a d5b_ hook; it takes core's doing_cron lock and
 * refuses while WP-Cron holds a fresh one; it reschedules (recurring) and unschedules BEFORE the hook runs, as wp-cron.php
 * does; the lock is released even when the hook throws; the duration is measured and over 20 s is over_budget; one
 * shutdown handler marks the audit and history rows fatal when the request dies inside the hook.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.3.0' );
require __DIR__ . '/plugin-test-stubs.php';

// ---- stand-ins this test needs: two caches (WP Fastest Cache and the object cache), each counting its flushes
class T_Wpfc { public $n = 0; function deleteCache( $all = false ) { $this->n++; } }
$GLOBALS['T_object_flushes'] = 0;
function wp_cache_flush() { $GLOBALS['T_object_flushes']++; return true; }
function wp_json_encode( $v ) { return json_encode( $v ); }

foreach ( array( 'inc/parts', 'inc/switches', 'inc/abilities/categories', 'inc/abilities/register', 'inc/abilities/secrets', 'inc/abilities/audit', 'inc/abilities/scrub', 'inc/abilities/history', 'inc/abilities/read-content', 'inc/abilities/read-site', 'inc/abilities/write-common', 'inc/abilities/write-site', 'parts/updates' ) as $f ) { require $dir . '/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 800 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ADMIN = array( 'read', 'manage_options' );

// The seams: a fake clock, set_time_limit and register_shutdown_function recorded.
$GLOBALS['T_clock'] = 1700000000.0; $GLOBALS['T_time_limits'] = array(); $GLOBALS['T_shutdowns'] = array();
$GLOBALS['d5b_wsite_seams'] = array(
	'now'               => function () { return $GLOBALS['T_clock']; },
	'set_time_limit'    => function ( $s ) { $GLOBALS['T_time_limits'][] = $s; return true; },
	'register_shutdown' => function ( $cb ) { $GLOBALS['T_shutdowns'][] = $cb; },
);

function site( bool $risky = true, array $caps = ADMIN, bool $multi = false ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( $multi );
	$GLOBALS['T_user_id'] = 3; $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
	if ( $risky ) { d5b_abilities_set_category( 'health', true, true, 'tester' ); }
	$GLOBALS['wp_fastest_cache'] = new T_Wpfc(); $GLOBALS['T_object_flushes'] = 0;
	$GLOBALS['T_time_limits'] = array();
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function edata( $r ) { return is_wp_error( $r ) ? $r->get_error_data() : null; }
function hrows() { global $wpdb; return $wpdb->tables[ d5b_history_table() ] ?? array(); }
function arows() { global $wpdb; return array_values( $wpdb->tables[ d5b_audit_table() ] ?? array() ); }
function alast() { $a = arows(); return $a ? $a[ count( $a ) - 1 ] : null; }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function ops( $kind = null ) { return array_values( array_filter( $GLOBALS['T_cron_ops'], function ( $o ) use ( $kind ) { return null === $kind || $o[0] === $kind; } ) ); }
function lock() { return get_transient( 'doing_cron' ); }

// ---- registration
echo "registration\n";
site();
$defs = d5b_ability_defs();
$cf = $defs['divi5-builder/cache-flush'] ?? null; $cr = $defs['divi5-builder/cron-run'] ?? null;
check( 'cache-flush (category cache) and cron-run (category health) exist', null !== $cf && null !== $cr && 'cache' === $cf['category'] && 'health' === $cr['category'] );
check( 'cache-flush: normal; readonly false, destructive false, idempotent true', ! array_key_exists( 'risky', (array) $cf ) && array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) === array_intersect_key( $cf['annotations'] ?? array(), array_flip( array( 'readonly', 'destructive', 'idempotent' ) ) ), $cf['annotations'] ?? null );
check( 'cron-run: risky; readonly false, destructive false, idempotent false', true === ( $cr['risky'] ?? null ) && false === $cr['annotations']['readonly'] && false === $cr['annotations']['destructive'] && false === $cr['annotations']['idempotent'], $cr['annotations'] ?? null );
check( 'cron-run: hook and timestamp required; schedule and args_key optional; nothing else', array( 'hook', 'timestamp' ) === $cr['input_schema']['required'] && array( 'hook', 'timestamp', 'schedule', 'args_key' ) === array_keys( $cr['input_schema']['properties'] ) && false === $cr['input_schema']['additionalProperties'] );

// ---- cache-flush
echo "cache-flush\n";
site();
check( 'the Updates switch is off', false === d5b_switch_is_on( 'updates' ) );
$r = call( 'cache-flush' );
check( 'cache-flush answers what was flushed (WP Fastest Cache, object cache)', is_array( $r ) && array( 'WP Fastest Cache', 'object cache' ) === $r['flushed'], $r );
check( 'wpsu_clear_caches ran once: each cache flushed exactly once', 1 === $GLOBALS['wp_fastest_cache']->n && 1 === $GLOBALS['T_object_flushes'], array( $GLOBALS['wp_fastest_cache']->n, $GLOBALS['T_object_flushes'] ) );
$h = hlast();
check( 'one history row: object_type cache, object_id 0, action flush, ability cache-flush', 1 === count( hrows() ) && 'cache' === $h['object_type'] && 0 === (int) $h['object_id'] && 'flush' === $h['action'] && 'divi5-builder/cache-flush' === $h['ability'], $h );
check( 'its before is the detection result; its after the flushed list', array( 'wpfc' => 'WP Fastest Cache', 'object' => 'object cache' ) === $h['before'] && array( 'flushed' => array( 'WP Fastest Cache', 'object cache' ) ) === $h['after'], $h );
check( 'its summary names what was flushed', 'Flushed caches: WP Fastest Cache, object cache' === $h['summary'], $h['summary'] );
check( 'one audit row, status ok', 1 === count( arows() ) && 'ok' === arows()[0]['status'], arows() );
site( true, array( 'read' ) );
check( 'without manage_options: refused, nothing flushed', 'FORBIDDEN' === call( 'cache-flush' ) && 0 === $GLOBALS['wp_fastest_cache']->n );
site( true, ADMIN, true );
check( 'multisite without manage_network: refused', 'FORBIDDEN' === call( 'cache-flush' ) && 0 === $GLOBALS['wp_fastest_cache']->n );
site( true, array( 'read', 'manage_options', 'manage_network' ), true );
check( 'multisite with manage_network: runs', is_array( call( 'cache-flush' ) ) && 1 === $GLOBALS['wp_fastest_cache']->n );
site();
check( 'unknown input refused', 'invalid_input' === code( call( 'cache-flush', array( 'all' => true ) ) ) && 0 === $GLOBALS['wp_fastest_cache']->n );

// ---- cron-list
echo "cron-list\n";
site();
$k1 = t_cron_add( 1700000100, 'daily_hook', 'daily', array( 'secret-arg@example.org' ) );
$k2 = t_cron_add( 1700000200, 'once_hook' );
$r = call( 'cron-list' );
check( 'cron-list returns timestamp and args_key per event (and keeps hook, schedule, next_run, arg_count)', is_array( $r ) && 1700000100 === $r['events'][0]['timestamp'] && $k1 === $r['events'][0]['args_key'] && 1700000200 === $r['events'][1]['timestamp'] && $k2 === $r['events'][1]['args_key'] && 'daily' === $r['events'][0]['schedule'] && 1 === $r['events'][0]['arg_count'] && isset( $r['events'][0]['next_run'] ), $r );
check( 'cron-list never shows an argument value', false === strpos( json_encode( $r ), 'secret-arg' ) );

// ---- cron-run: identifying the event
echo "cron-run: which event\n";
site();
$GLOBALS['T_cron_hooks']['real_hook'][] = function () { $GLOBALS['T_ran'][] = 'real_hook'; };
$GLOBALS['T_ran'] = array();
t_cron_add( 1700000100, 'real_hook', 'hourly' );
$r = call( 'cron-run', array( 'hook' => 'not_scheduled_hook', 'timestamp' => 1700000100 ) );
check( 'a hook that is not scheduled: not_found, nothing runs', 'not_found' === code( $r ) && array() === ops() , array( code( $r ), ops() ) );
$r = call( 'cron-run', array( 'hook' => 'real_hook', 'timestamp' => 1700000999 ) );
check( 'the right hook at another timestamp: not_found', 'not_found' === code( $r ) && array() === ops() );
t_cron_add( 1700000100, 'd5b_audit_prune', 'daily' );
$r = call( 'cron-run', array( 'hook' => 'd5b_audit_prune', 'timestamp' => 1700000100 ) );
check( 'a d5b_ hook is refused not_allowed, even when scheduled; nothing runs', 'not_allowed' === code( $r ) && array() === ops(), array( code( $r ), ops() ) );
$r = call( 'cron-run', array( 'hook' => 'bad hook!', 'timestamp' => 1700000100 ) );
check( 'a hook name with other characters: invalid_input', 'invalid_input' === code( $r ) );
$ka = t_cron_add( 1700000300, 'twin_hook', false, array( 1 ) );
$kb = t_cron_add( 1700000300, 'twin_hook', false, array( 2 ) );
$GLOBALS['T_cron_hooks']['twin_hook'][] = function ( $x ) { $GLOBALS['T_ran'][] = 'twin:' . $x; };
$r = call( 'cron-run', array( 'hook' => 'twin_hook', 'timestamp' => 1700000300 ) );
$d = edata( $r );
check( 'two events sharing hook + timestamp and no key: ambiguous, with both keys; nothing runs', 'ambiguous' === code( $r ) && is_array( $d ) && array( $ka, $kb ) === $d['keys'] && array() === ops(), array( code( $r ), $d, ops() ) );
$r = call( 'cron-run', array( 'hook' => 'twin_hook', 'timestamp' => 1700000300, 'args_key' => $kb ) );
check( 'with the key: exactly that event runs, with its own args', is_array( $r ) && true === $r['ran'] && array( 'twin:2' ) === $GLOBALS['T_ran'], array( $r, $GLOBALS['T_ran'] ) );
check( 'the other twin is still scheduled', isset( $GLOBALS['T_cron_array'][1700000300]['twin_hook'][ $ka ] ) && ! isset( $GLOBALS['T_cron_array'][1700000300]['twin_hook'][ $kb ] ) );
$r = call( 'cron-run', array( 'hook' => 'twin_hook', 'timestamp' => 1700000300, 'args_key' => 'ffffffffffffffffffffffffffffffff' ) );
check( 'a key that does not match: not_found', 'not_found' === code( $r ) );
$GLOBALS['T_cron_ops'] = array(); $GLOBALS['T_ran'] = array();
$r = call( 'cron-run', array( 'hook' => 'real_hook', 'timestamp' => 1700000100, 'schedule' => 'daily' ) );
check( 'a schedule that does not match the event\'s (hourly): not_found, nothing runs', 'not_found' === code( $r ) && array() === ops() && array() === $GLOBALS['T_ran'], array( code( $r ), ops() ) );
$r = call( 'cron-run', array( 'hook' => 'twin_hook', 'timestamp' => 1700000300, 'schedule' => 'hourly' ) );
check( 'a single event given a recurring schedule: not_found', 'not_found' === code( $r ) && array() === ops() );
$r = call( 'cron-run', array( 'hook' => 'twin_hook', 'timestamp' => 1700000300, 'schedule' => 'single' ) );
check( 'a single event with schedule "single" (as cron-list lists it) runs', is_array( $r ) && true === $r['ran'] && array( 'twin:1' ) === $GLOBALS['T_ran'], $r );

// ---- cron-run: the order wp-cron.php keeps
echo "cron-run: order\n";
site();
$GLOBALS['T_ran'] = array();
$GLOBALS['T_cron_hooks']['rec_hook'][] = function () { $GLOBALS['T_cron_ops'][] = array( 'hook body' ); $GLOBALS['T_seen_lock'] = lock(); };
$kr = t_cron_add( 1700000100, 'rec_hook', 'hourly', array() );
$r = call( 'cron-run', array( 'hook' => 'rec_hook', 'timestamp' => 1700000100, 'schedule' => 'hourly', 'args_key' => $kr ) );
$seq = array_map( function ( $o ) { return $o[0]; }, ops() );
check( 'recurring: rescheduled, then unscheduled, BEFORE the hook runs', array( 'reschedule', 'unschedule', 'run', 'hook body' ) === $seq, $seq );
check( 'the response: ran, hook, duration_ms, rescheduled true, unscheduled true, over_budget false', is_array( $r ) && true === $r['ran'] && 'rec_hook' === $r['hook'] && 0 === $r['duration_ms'] && true === $r['rescheduled'] && true === $r['unscheduled'] && false === $r['over_budget'], $r );
check( 'the event moved to its next time slot', ! isset( $GLOBALS['T_cron_array'][1700000100]['rec_hook'] ) && 1 === count( array_filter( $GLOBALS['T_cron_array'], function ( $h ) { return isset( $h['rec_hook'] ); } ) ) );
check( 'the doing_cron lock was held while the hook ran, and released after', is_string( $GLOBALS['T_seen_lock'] ) && 1 === preg_match( '/^1700000000\.0+$/', $GLOBALS['T_seen_lock'] ) && false === lock(), array( $GLOBALS['T_seen_lock'], lock() ) );
check( 'set_time_limit( 60 ) was asked for', array( 60 ) === $GLOBALS['T_time_limits'], $GLOBALS['T_time_limits'] );
$h = hlast();
check( 'one history row: object_type cron_event, object_id 0, action run, summary "Ran cron event rec_hook"', 1 === count( hrows() ) && 'cron_event' === $h['object_type'] && 0 === (int) $h['object_id'] && 'run' === $h['action'] && 'Ran cron event rec_hook' === $h['summary'], $h );
check( 'its before is the event as cron-list lists it (no argument values)', is_array( $h['before'] ) && 'rec_hook' === $h['before']['hook'] && 'hourly' === $h['before']['schedule'] && 1700000100 === $h['before']['timestamp'] && $kr === $h['before']['args_key'] && 0 === $h['before']['arg_count'] && array( 'hook', 'schedule', 'next_run', 'arg_count', 'timestamp', 'args_key' ) === array_keys( $h['before'] ), $h['before'] );
check( 'its after records the run', is_array( $h['after'] ) && true === $h['after']['ran'] && true === $h['after']['rescheduled'], $h['after'] );
check( 'audit row ok', 'ok' === alast()['status'] );
site();
$GLOBALS['T_cron_hooks']['one_hook'][] = function ( $a, $b ) { $GLOBALS['T_cron_ops'][] = array( 'hook body', $a, $b ); };
t_cron_add( 1700000100, 'one_hook', false, array( 'x', 7 ) );
$r = call( 'cron-run', array( 'hook' => 'one_hook', 'timestamp' => 1700000100 ) );
$seq = array_map( function ( $o ) { return $o[0]; }, ops() );
check( 'single: unscheduled BEFORE the hook runs, never rescheduled', array( 'unschedule', 'run', 'hook body' ) === $seq, $seq );
check( 'the hook got the event\'s args', array( 'hook body', 'x', 7 ) === end( $GLOBALS['T_cron_ops'] ) );
check( 'single response: rescheduled false, unscheduled true; the event is gone', is_array( $r ) && false === $r['rescheduled'] && true === $r['unscheduled'] && array() === $GLOBALS['T_cron_array'], $r );
site();
$GLOBALS['T_cron_fail']['reschedule'] = true;
t_cron_add( 1700000100, 'rec_hook', 'hourly' );
$r = call( 'cron-run', array( 'hook' => 'rec_hook', 'timestamp' => 1700000100 ) );
check( 'a reschedule WordPress refuses: the run goes on as wp-cron.php does, rescheduled false and a warning', is_array( $r ) && true === $r['ran'] && false === $r['rescheduled'] && true === $r['unscheduled'] && ! empty( $r['warnings'] ), $r );

// ---- cron-run: the lock
echo "cron-run: lock\n";
site();
$GLOBALS['T_ran'] = array();
$GLOBALS['T_cron_hooks']['real_hook'][] = function () { $GLOBALS['T_ran'][] = 'real_hook'; };
t_cron_add( 1700000100, 'real_hook', 'hourly' );
set_transient( 'doing_cron', sprintf( '%.22F', $GLOBALS['T_clock'] - 10 ) );
$r = call( 'cron-run', array( 'hook' => 'real_hook', 'timestamp' => 1700000100 ) );
check( 'WP-Cron holds a fresh lock (10 s old): unavailable, nothing rescheduled or run', 'unavailable' === code( $r ) && array() === ops() && array() === $GLOBALS['T_ran'] && 503 === ( edata( $r )['status'] ?? null ), array( code( $r ), ops() ) );
check( 'the other request\'s lock is left alone', sprintf( '%.22F', $GLOBALS['T_clock'] - 10 ) === lock() );
check( 'no history row for a refusal', 0 === count( hrows() ) );
set_transient( 'doing_cron', sprintf( '%.22F', $GLOBALS['T_clock'] - 61 ) );
$r = call( 'cron-run', array( 'hook' => 'real_hook', 'timestamp' => 1700000100 ) );
check( 'a stale lock (61 s, over WP_CRON_LOCK_TIMEOUT): runs', is_array( $r ) && true === $r['ran'] && array( 'real_hook' ) === $GLOBALS['T_ran'], $r );
check( 'and the lock is released after', false === lock() );
site();
$GLOBALS['T_cron_hooks']['boom_hook'][] = function () { $GLOBALS['T_cron_ops'][] = array( 'hook body' ); throw new RuntimeException( 'kaboom secret-detail' ); };
t_cron_add( 1700000100, 'boom_hook', 'daily' );
$r = call( 'cron-run', array( 'hook' => 'boom_hook', 'timestamp' => 1700000100 ) );
check( 'a hook that throws: internal_error with schedule_adjusted true', 'internal_error' === code( $r ) && true === ( edata( $r )['schedule_adjusted'] ?? null ), array( code( $r ), edata( $r ) ) );
check( 'the exception message does not reach the caller', false === strpos( json_encode( array( is_wp_error( $r ) ? $r->get_error_message() : '', edata( $r ) ) ), 'kaboom' ) );
check( 'the lock is released after a throwing hook', false === lock() );
check( 'the schedule was adjusted before the hook threw', array( 'reschedule', 'unschedule', 'run', 'hook body' ) === array_map( function ( $o ) { return $o[0]; }, ops() ) );
$h = hlast();
check( 'the history row after says it failed and the schedule was adjusted', is_array( $h['after'] ) && true === ( $h['after']['schedule_adjusted'] ?? null ) && true === ( $h['after']['failed'] ?? null ), $h['after'] ?? null );
check( 'the running marker is cleared after a throw', null === d5b_wsite_running() );

// ---- cron-run: time budget and the shutdown handler
echo "cron-run: budget and fatal\n";
site();
$GLOBALS['T_cron_hooks']['slow_hook'][] = function () { $GLOBALS['T_clock'] += 25.5; };
t_cron_add( 1700000100, 'slow_hook', 'daily' );
$r = call( 'cron-run', array( 'hook' => 'slow_hook', 'timestamp' => 1700000100 ) );
check( 'a slow hook (25.5 s on the fake clock): duration_ms 25500 and over_budget true', is_array( $r ) && 25500 === $r['duration_ms'] && true === $r['over_budget'], $r );
site();
$GLOBALS['T_cron_hooks']['ok_hook'][] = function () { $GLOBALS['T_clock'] += 19.9; };
t_cron_add( 1700000100, 'ok_hook', 'daily' );
$r = call( 'cron-run', array( 'hook' => 'ok_hook', 'timestamp' => 1700000100 ) );
check( '19.9 s is within the budget', is_array( $r ) && 19900 === $r['duration_ms'] && false === $r['over_budget'], $r );
check( 'ONE shutdown handler for the whole request, however many runs', 1 === count( $GLOBALS['T_shutdowns'] ), count( $GLOBALS['T_shutdowns'] ) );
check( 'the shutdown handler does nothing when no run is in progress', ( function () { $before = arows(); call_user_func( $GLOBALS['T_shutdowns'][0] ); return $before === arows() && 'ok' === end( $before )['status']; } )() );

// A fatal inside the hook: the hook calls the shutdown handler itself (as PHP would on the way down), then the test checks.
site();
$GLOBALS['T_fatal_seen'] = null;
$GLOBALS['T_cron_hooks']['fatal_hook'][] = function () {
	$GLOBALS['T_clock'] += 2;
	call_user_func( $GLOBALS['T_shutdowns'][0] );
	$GLOBALS['T_fatal_seen'] = array( 'audit' => alast()['status'], 'history' => hlast()['after'] );
	throw new RuntimeException( 'stand-in for the request ending here' );
};
t_cron_add( 1700000100, 'fatal_hook', 'daily' );
call( 'cron-run', array( 'hook' => 'fatal_hook', 'timestamp' => 1700000100 ) );
check( 'the shutdown handler marks the audit row fatal when the request dies inside the hook', 'fatal' === ( $GLOBALS['T_fatal_seen']['audit'] ?? null ), $GLOBALS['T_fatal_seen'] );
check( 'and the history row\'s after { fatal: true }', array( 'fatal' => true ) === ( $GLOBALS['T_fatal_seen']['history'] ?? null ), $GLOBALS['T_fatal_seen'] );

// ---- cron-run: switches and capabilities
echo "cron-run: switches and capabilities\n";
site( false );
t_cron_add( 1700000100, 'real_hook', 'hourly' );
check( 'the Site health risky switch off: refused, nothing runs', 'FORBIDDEN' === call( 'cron-run', array( 'hook' => 'real_hook', 'timestamp' => 1700000100 ) ) && array() === ops() );
$r = call_user_func( d5b_ability_registry()['divi5-builder/cron-run']['handler'], array( 'hook' => 'real_hook', 'timestamp' => 1700000100 ) );
check( 'the handler itself re-checks the risky switch (not_allowed)', 'not_allowed' === code( $r ) && array() === ops(), code( $r ) );
site( true, array( 'read' ) );
t_cron_add( 1700000100, 'real_hook', 'hourly' );
check( 'without manage_options: refused', 'FORBIDDEN' === call( 'cron-run', array( 'hook' => 'real_hook', 'timestamp' => 1700000100 ) ) && array() === ops() );
site( true, ADMIN, true );
t_cron_add( 1700000100, 'real_hook', 'hourly' );
check( 'multisite without manage_network: refused', 'FORBIDDEN' === call( 'cron-run', array( 'hook' => 'real_hook', 'timestamp' => 1700000100 ) ) && array() === ops() );
site( true, array( 'read', 'manage_options', 'manage_network' ), true );
t_cron_add( 1700000100, 'real_hook', 'hourly' );
$r = call( 'cron-run', array( 'hook' => 'real_hook', 'timestamp' => 1700000100 ) );
check( 'multisite with manage_network: runs', is_array( $r ) && true === $r['ran'], $r );

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
