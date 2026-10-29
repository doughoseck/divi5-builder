<?php
/**
 * The abilities registration gate and the run wrapper (#32, Task 2).
 *
 *   php scripts/plugin-abilities-gate-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: nothing is registered with WordPress unless the master switch and the ability's category
 * switch are on (so a switched-off category cannot be reached through any route), nothing is fatal without the
 * Abilities API, every call is audited, a handler exception never reaches the caller, and a permission refusal is
 * logged. Two child processes (--child) load the real loader file, with and without the Abilities API.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';

// ---- child: no audit writer and no scrubber exist (Tasks 3 and 5 come later)
if ( isset( $argv[1] ) && '--bare' === $argv[1] ) {
	define( 'D5B_VERSION', '2.1.0' );
	require __DIR__ . '/plugin-test-stubs.php';
	require $dir . '/inc/parts.php'; require $dir . '/inc/switches.php'; require $dir . '/inc/abilities/categories.php'; require $dir . '/inc/abilities/register.php';
	if ( function_exists( 'd5b_audit_write' ) || function_exists( 'd5b_ability_scrub' ) ) { echo "BARE_NOT_BARE\n"; exit( 1 ); }
	d5b_abilities_set_master( true, 't' ); $GLOBALS['T_user_id'] = 1;
	// No audit writer: the handler must not run unaudited (fail closed), and nothing is fatal.
	$GLOBALS['T_ran'] = 0;
	d5b_ability_add( array( 'name' => 'divi5-builder/posts-a', 'category' => 'posts', 'label' => 'a', 'description' => 'x', 'handler' => function ( array $i ) { $GLOBALS['T_ran']++; return array( 'user_pass' => 'kept-without-scrubber' ); }, 'permission' => function ( array $i ) { return true; } ) );
	d5b_ability_add( array( 'name' => 'divi5-builder/posts-b', 'category' => 'posts', 'label' => 'b', 'description' => 'x', 'handler' => function ( array $i ) { $GLOBALS['T_ran']++; return 1; }, 'permission' => function ( array $i ) { return false; } ) );
	$r = d5b_ability_run( 'divi5-builder/posts-a', array() );
	$p = d5b_ability_permit( 'divi5-builder/posts-b', array() );
	echo ( is_wp_error( $r ) && 'audit_unavailable' === $r->get_error_code() && 0 === $GLOBALS['T_ran'] && false === $p ) ? "BARE_OK\n" : "BARE_BAD\n";
	exit( 0 );
}

// ---- child: the real loader, with or without the Abilities API (the parent sets D5B_NO_ABILITIES_API)
if ( isset( $argv[1] ) && '--child' === $argv[1] ) {
	require __DIR__ . '/plugin-test-stubs.php';
	define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/d5b-gate-mu-' . getmypid() );
	define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/d5b-gate-plugins-' . getmypid() );
	require $dir . '/divi5-builder.php';
	d5b_ability_registry( array() ); // the shipped definitions are covered elsewhere; this child counts only its own one
	$wired_cat = in_array( 'd5b_abilities_register_categories', $GLOBALS['T_actions']['wp_abilities_api_categories_init'] ?? array(), true );
	$wired_reg = in_array( 'd5b_abilities_register', $GLOBALS['T_actions']['wp_abilities_api_init'] ?? array(), true );
	d5b_ability_add( array( 'name' => 'divi5-builder/posts-a', 'category' => 'posts', 'label' => 'a', 'description' => 'Returns a. Refuses nothing.', 'handler' => function ( array $i ) { return 1; }, 'permission' => function ( array $i ) { return true; } ) );
	d5b_abilities_set_master( true, 'tester' ); // on, so the only thing stopping a registration is the missing API
	t_fire( 'wp_abilities_api_categories_init' );
	t_fire( 'wp_abilities_api_init' );
	echo 'CHILD_OK api=' . ( function_exists( 'wp_register_ability' ) ? 'yes' : 'no' ) . ' wired_cat=' . (int) $wired_cat . ' wired_reg=' . (int) $wired_reg
		. ' abilities=' . count( $GLOBALS['T_abilities'] ) . ' errors=' . count( $GLOBALS['T_ability_errors'] ) . "\n";
	exit( 0 );
}

define( 'D5B_VERSION', '2.1.0' );
require __DIR__ . '/plugin-test-stubs.php';
require $dir . '/inc/parts.php';
require $dir . '/inc/switches.php';
require $dir . '/inc/abilities/categories.php';
require $dir . '/inc/abilities/register.php';
// Group files that later tasks add: loading them adds the real definitions to the registry. scrub.php is left out: this test has its own scrubber double.
foreach ( glob( $dir . '/inc/abilities/*.php' ) as $f ) { if ( ! in_array( basename( $f ), array( 'categories.php', 'register.php', 'audit.php', 'scrub.php' ), true ) ) { require_once $f; } }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

// ---- the real definitions (empty until Tasks 4 to 6): names and descriptions an AI can follow
function lint_result( array $defs ) {
	$names_ok = true; $desc_ok = true; $schema_ok = true; $bad = array();
	foreach ( $defs as $name => $d ) {
		if ( 1 !== preg_match( '#^divi5-builder/[a-z0-9]+(-[a-z0-9]+)*$#', $name ) || $name !== $d['name'] ) { $names_ok = false; $bad[] = "name $name"; }
		$desc = (string) ( $d['description'] ?? '' );
		if ( strlen( $desc ) < 40 || ! preg_match( '/return/i', $desc ) || ! preg_match( '/refus/i', $desc ) ) { $desc_ok = false; $bad[] = "description of $name"; }
		foreach ( (array) ( $d['input_schema']['properties'] ?? array() ) as $prop => $p ) {
			if ( ! is_array( $p ) || '' === trim( (string) ( $p['description'] ?? '' ) ) ) { $schema_ok = false; $bad[] = "$name.$prop has no description"; }
		}
	}
	return array( $names_ok, $desc_ok, $schema_ok, $bad );
}
function lint_defs( array $defs, string $label ) {
	list( $names_ok, $desc_ok, $schema_ok, $bad ) = lint_result( $defs );
	check( "$label: names match ^divi5-builder/[a-z0-9]+(-[a-z0-9]+)*\$", $names_ok, $bad );
	check( "$label: descriptions are 40+ characters and say what they return and what they refuse", $desc_ok, $bad );
	check( "$label: every input_schema property has a description", $schema_ok, $bad );
}
echo "the shipped definitions\n";
lint_defs( d5b_ability_defs(), 'shipped' );

// ---- test definitions
function mk( $name, $cat, array $over = array() ) {
	return array_merge( array(
		'name' => $name, 'category' => $cat, 'label' => 'Test ' . $name,
		'description' => 'Returns a test record for ' . $name . '. Refuses when the caller may not read it.',
		'input_schema' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer', 'description' => 'The record id.' ) ) ),
		'output_schema' => array( 'type' => 'object' ),
		'handler' => function ( array $in ) { return array( 'echo' => $in, 'ok' => true ); },
		'permission' => function ( array $in ) { return true; },
	), $over );
}
function load_test_defs() {
	d5b_ability_registry( array() );
	d5b_ability_add( mk( 'divi5-builder/posts-list', 'posts' ) );
	d5b_ability_add( mk( 'divi5-builder/posts-get', 'posts' ) );
	d5b_ability_add( mk( 'divi5-builder/pages-list', 'pages' ) );
}
function fresh( $master, array $cats_off = array() ) {
	// A logged-in caller (user 1): a caller who is not logged in is refused before any of the gates under test here.
	t_reset(); t_set_caps( array() ); t_set_multisite( false ); $GLOBALS['T_user_id'] = 1;
	load_test_defs();
	if ( $master ) { d5b_abilities_set_master( true, 'tester' ); }
	foreach ( $cats_off as $c ) { d5b_abilities_set_category( $c, false, false, 'tester' ); }
	add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' );
	add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );
}
function fire_both() { t_fire( 'wp_abilities_api_categories_init' ); t_fire( 'wp_abilities_api_init' ); }

// audit and scrub doubles (the real ones arrive in Tasks 3 and 5): the wrapper must call them with the right arguments
// The writer returns the row id (its place in T_audit, from 1) and d5b_audit_update changes that row, as the real ones do.
$GLOBALS['T_audit'] = array(); $GLOBALS['T_scrub'] = array(); $GLOBALS['T_audit_fail'] = false;
if ( true ) { function d5b_audit_write( string $ability, array $args, string $status, int $duration_ms ): int { if ( $GLOBALS['T_audit_fail'] ) { return 0; } $GLOBALS['T_audit'][] = compact( 'ability', 'args', 'status', 'duration_ms' ); return count( $GLOBALS['T_audit'] ); }
function d5b_audit_update( int $id, string $status, int $duration_ms, ?array $args = null ): bool {
	if ( ! isset( $GLOBALS['T_audit'][ $id - 1 ] ) ) { return false; }
	$GLOBALS['T_audit'][ $id - 1 ]['status'] = $status; $GLOBALS['T_audit'][ $id - 1 ]['duration_ms'] = $duration_ms;
	if ( null !== $args ) { $GLOBALS['T_audit'][ $id - 1 ]['args'] = $args; }
	return true;
}
function d5b_ability_scrub( $value ) { $GLOBALS['T_scrub'][] = $value; if ( is_array( $value ) ) { unset( $value['user_pass'] ); } return $value; } }

$GLOBALS['T_real_defs'] = d5b_ability_defs(); // the shipped definitions, for the direct-run checks below

echo "the test definitions\n";
load_test_defs();
lint_defs( d5b_ability_defs(), 'test defs' );
$threw = false; try { d5b_ability_add( mk( 'divi5-builder/posts-list', 'posts' ) ); } catch ( InvalidArgumentException $e ) { $threw = true; }
check( 'a duplicate name is refused', $threw && 3 === count( d5b_ability_defs() ) );
$threw = false; try { d5b_ability_add( mk( 'divi5-builder/Bad_Name', 'posts' ) ); } catch ( InvalidArgumentException $e ) { $threw = true; }
check( 'a bad name is refused', $threw );
$threw = false; try { d5b_ability_add( mk( 'divi5-builder/x-y', 'nonsense' ) ); } catch ( InvalidArgumentException $e ) { $threw = true; }
check( 'an unknown category is refused', $threw );
$lint_bad = array(
	'short description' => mk( 'divi5-builder/short', 'posts', array( 'description' => 'Short.' ) ),
	'no "refuses"' => mk( 'divi5-builder/norefuse', 'posts', array( 'description' => 'Returns a long enough description but says nothing else.' ) ),
	'undescribed property' => mk( 'divi5-builder/noprop', 'posts', array( 'input_schema' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ) ) ) ) ),
	'bad name' => mk( 'divi5-builder/Bad_Name', 'posts' ),
);
$alive = true; $why_dead = '';
foreach ( $lint_bad as $why => $d ) { $r = lint_result( array( $d['name'] => $d ) ); if ( $r[0] && $r[1] && $r[2] ) { $alive = false; $why_dead = $why; } }
check( 'the lint rejects each bad shape (the checks are alive)', $alive, $why_dead );

// ---- master off
echo "master off\n";
fresh( false );
fire_both();
check( 'master off: no ability and no category registered after both hooks fire', array() === $GLOBALS['T_abilities'] && array() === $GLOBALS['T_ability_categories'] && array() === $GLOBALS['T_ability_errors'], array( $GLOBALS['T_abilities'], $GLOBALS['T_ability_categories'] ) );

// ---- master on, all categories on
echo "master on\n";
fresh( true );
fire_both();
check( 'every def registered once', 3 === count( $GLOBALS['T_abilities'] ) && isset( $GLOBALS['T_abilities']['divi5-builder/posts-list'], $GLOBALS['T_abilities']['divi5-builder/posts-get'], $GLOBALS['T_abilities']['divi5-builder/pages-list'] ), array_keys( $GLOBALS['T_abilities'] ) );
$m = $GLOBALS['T_abilities']['divi5-builder/posts-list'] ?? array();
check( 'meta: readonly, not destructive, idempotent, public, show_in_rest', array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) === ( $m['meta']['annotations'] ?? null ) && true === ( $m['meta']['public'] ?? null ) && true === ( $m['meta']['show_in_rest'] ?? null ), $m['meta'] ?? null );
check( 'ability category is the wp_slug', 'divi5-builder-posts' === ( $m['category'] ?? '' ) && 'divi5-builder-pages' === ( $GLOBALS['T_abilities']['divi5-builder/pages-list']['category'] ?? '' ) );
check( 'label, description and schemas carried over; permission and execute are callables', 'Test divi5-builder/posts-list' === $m['label'] && strlen( $m['description'] ) >= 40 && 'object' === $m['input_schema']['type'] && 'object' === $m['output_schema']['type'] && is_callable( $m['execute_callback'] ) && is_callable( $m['permission_callback'] ) );
// Core runs a call with no input only when the input schema has a default (live 2.1.0 check: "input is not of type object").
check( 'object input schema gets an empty default, so a call with no input reaches the ability', array() === ( $m['input_schema']['default'] ?? null ), $m['input_schema'] ?? null );
check( 'only the categories that hold a def are registered (posts, pages), not the other 21', array( 'divi5-builder-pages', 'divi5-builder-posts' ) === ( function () { $k = array_keys( $GLOBALS['T_ability_categories'] ); sort( $k ); return $k; } )(), array_keys( $GLOBALS['T_ability_categories'] ) );
check( 'category label and description carried over', 'Posts' === ( $GLOBALS['T_ability_categories']['divi5-builder-posts']['label'] ?? '' ) && '' !== ( $GLOBALS['T_ability_categories']['divi5-builder-posts']['description'] ?? '' ) );
check( 'core accepted all of it (no refusal recorded)', array() === $GLOBALS['T_ability_errors'], $GLOBALS['T_ability_errors'] );

// the registered permission callback is the real gate
fresh( true );
d5b_ability_add( mk( 'divi5-builder/users-list', 'users', array( 'permission' => function ( array $in ) { return false; } ) ) );
fire_both();
$GLOBALS['T_audit'] = array();
$pc = $GLOBALS['T_abilities']['divi5-builder/users-list']['permission_callback'] ?? null;
check( 'the registered permission_callback refuses when the def refuses, and logs forbidden', is_callable( $pc ) && false === call_user_func( $pc, array() ) && 1 === count( $GLOBALS['T_audit'] ) && 'forbidden' === $GLOBALS['T_audit'][0]['status'], $GLOBALS['T_audit'] );
$pc = $GLOBALS['T_abilities']['divi5-builder/posts-list']['permission_callback'] ?? null;
check( 'the registered permission_callback allows when the def allows', is_callable( $pc ) && true === call_user_func( $pc, array() ) );

// ---- one category off
echo "one category off\n";
fresh( true, array( 'posts' ) );
fire_both();
check( 'posts off: none of its abilities and not its category registered', ! isset( $GLOBALS['T_abilities']['divi5-builder/posts-list'] ) && ! isset( $GLOBALS['T_abilities']['divi5-builder/posts-get'] ) && ! isset( $GLOBALS['T_ability_categories']['divi5-builder-posts'] ), array_keys( $GLOBALS['T_abilities'] ) );
check( 'posts off: pages ability and category intact', isset( $GLOBALS['T_abilities']['divi5-builder/pages-list'] ) && isset( $GLOBALS['T_ability_categories']['divi5-builder-pages'] ) && 1 === count( $GLOBALS['T_abilities'] ) );
check( 'posts off: a call to its permission callback is refused too (defence in depth)', false === d5b_ability_permit( 'divi5-builder/posts-list', array() ) );
fresh( true );
$st = get_option( 'd5b_switches' ); $st['abilities']['categories'] = array( 'posts' => false ); update_option( 'd5b_switches', $st );
fire_both();
check( 'break-it a1: a category stored as a bare false (not an array) registers nothing; others still do', ! isset( $GLOBALS['T_abilities']['divi5-builder/posts-list'] ) && isset( $GLOBALS['T_abilities']['divi5-builder/pages-list'] ), array_keys( $GLOBALS['T_abilities'] ) );

// ---- registration only inside the hooks, and only once
echo "registration timing\n";
fresh( true );
d5b_abilities_register_categories(); d5b_abilities_register();
check( 'called outside the hooks: core refuses every call, nothing is registered', array() === $GLOBALS['T_abilities'] && array() === $GLOBALS['T_ability_categories'] && count( $GLOBALS['T_ability_errors'] ) >= 5, $GLOBALS['T_ability_errors'] );
fresh( true );
fire_both(); fire_both();
check( 'both hooks fired twice: registration happens once, no duplicate error', 3 === count( $GLOBALS['T_abilities'] ) && 2 === count( $GLOBALS['T_ability_categories'] ) && array() === $GLOBALS['T_ability_errors'], $GLOBALS['T_ability_errors'] );
fresh( true );
d5b_ability_registry( array() );
fire_both();
check( 'a category with no defs is not registered (zero defs: nothing at all)', array() === $GLOBALS['T_ability_categories'] && array() === $GLOBALS['T_abilities'] );

// ---- the run wrapper
echo "run wrapper\n";
fresh( true );
$GLOBALS['T_audit'] = array(); $GLOBALS['T_scrub'] = array();
$r = d5b_ability_run( 'divi5-builder/posts-list', array( 'id' => 7 ) );
check( 'ok: the handler result comes back', array( 'echo' => array( 'id' => 7 ), 'ok' => true ) === $r, $r );
check( 'ok: one audit row, ability name, the input, status ok, integer duration', 1 === count( $GLOBALS['T_audit'] ) && 'divi5-builder/posts-list' === $GLOBALS['T_audit'][0]['ability'] && array( 'id' => 7 ) === $GLOBALS['T_audit'][0]['args'] && 'ok' === $GLOBALS['T_audit'][0]['status'] && $GLOBALS['T_audit'][0]['duration_ms'] >= 0, $GLOBALS['T_audit'] );
check( 'ok: the scrubber saw the handler result', 1 === count( $GLOBALS['T_scrub'] ) && true === $GLOBALS['T_scrub'][0]['ok'] );

load_test_defs();
d5b_ability_add( mk( 'divi5-builder/media-list', 'media', array( 'handler' => function ( array $in ) { return array( 'user_pass' => 'hash', 'title' => 'x' ); } ) ) );
$GLOBALS['T_scrub'] = array();
check( 'the scrubbed value is what is returned', array( 'title' => 'x' ) === d5b_ability_run( 'divi5-builder/media-list', array() ) );

d5b_ability_add( mk( 'divi5-builder/menus-list', 'menus', array( 'handler' => function ( array $in ) { usleep( 30000 ); return new WP_Error( 'bad_thing', 'Nope.' ); } ) ) );
$GLOBALS['T_audit'] = array(); $GLOBALS['T_scrub'] = array();
$r = d5b_ability_run( 'divi5-builder/menus-list', array() );
check( 'WP_Error from the handler is passed through, audited as error:<code>, not scrubbed here (no scrub.php in this test)', is_wp_error( $r ) && 'bad_thing' === $r->code && 'error:bad_thing' === $GLOBALS['T_audit'][0]['status'] && array() === $GLOBALS['T_scrub'], $GLOBALS['T_audit'] );
check( 'the duration is measured (a 30 ms handler logs at least 25 ms)', $GLOBALS['T_audit'][0]['duration_ms'] >= 25, $GLOBALS['T_audit'][0]['duration_ms'] );

d5b_ability_add( mk( 'divi5-builder/cache-status', 'cache', array( 'handler' => function ( array $in ) { throw new RuntimeException( 'secret db host db.internal:3306' ); } ) ) );
$GLOBALS['T_audit'] = array();
$r = d5b_ability_run( 'divi5-builder/cache-status', array( 'id' => 1 ) );
check( 'exception: internal_error WP_Error', is_wp_error( $r ) && 'internal_error' === $r->code, $r );
check( 'exception: the message does not leak the exception text or class', is_wp_error( $r ) && false === strpos( $r->message, 'db.internal' ) && false === strpos( $r->message, 'RuntimeException' ) && '' !== $r->message );
check( 'exception: the audit row has status internal_error and records class and message', 1 === count( $GLOBALS['T_audit'] ) && 'internal_error' === $GLOBALS['T_audit'][0]['status'] && false !== strpos( json_encode( $GLOBALS['T_audit'][0]['args'] ), 'RuntimeException: secret db host' ) && 1 === $GLOBALS['T_audit'][0]['args']['id'], $GLOBALS['T_audit'] );

d5b_ability_add( mk( 'divi5-builder/health-check', 'health', array( 'handler' => function ( array $in ) { return 'scalar'; } ) ) );
check( 'null input becomes an empty array for the handler; a scalar result is fine', 'scalar' === d5b_ability_run( 'divi5-builder/health-check', null ) );
$GLOBALS['T_audit'] = array();
$r = d5b_ability_run( 'divi5-builder/nothing-here', array() );
check( 'unknown ability: WP_Error, audited as not_found', is_wp_error( $r ) && 'not_found' === $GLOBALS['T_audit'][0]['status'] );

// execute closure from a real registration goes through the wrapper
fresh( true );
fire_both();
$GLOBALS['T_audit'] = array();
$r = call_user_func( $GLOBALS['T_abilities']['divi5-builder/posts-get']['execute_callback'], array( 'id' => 3 ) );
check( 'the registered execute_callback runs the wrapper (audit row, result)', 1 === count( $GLOBALS['T_audit'] ) && 'divi5-builder/posts-get' === $GLOBALS['T_audit'][0]['ability'] && true === $r['ok'], $GLOBALS['T_audit'] );

// ---- permission
echo "permission\n";
fresh( true );
d5b_ability_add( mk( 'divi5-builder/users-list', 'users', array( 'permission' => function ( array $in ) { return false; } ) ) );
d5b_ability_add( mk( 'divi5-builder/plugins-list', 'plugins', array( 'permission' => function ( array $in ) { return new WP_Error( 'rest_forbidden', 'No.' ); } ) ) );
d5b_ability_add( mk( 'divi5-builder/themes-list', 'themes', array( 'permission' => function ( array $in ) { throw new RuntimeException( 'boom' ); } ) ) );
$GLOBALS['T_audit'] = array();
check( 'allowed: true and no audit row (the run writes it)', true === d5b_ability_permit( 'divi5-builder/posts-list', array() ) && array() === $GLOBALS['T_audit'] );
check( 'refusal: false returned', false === d5b_ability_permit( 'divi5-builder/users-list', array( 'id' => 9 ) ) );
check( 'refusal: audit row with status forbidden and the input', 1 === count( $GLOBALS['T_audit'] ) && 'forbidden' === $GLOBALS['T_audit'][0]['status'] && 'divi5-builder/users-list' === $GLOBALS['T_audit'][0]['ability'] && array( 'id' => 9 ) === $GLOBALS['T_audit'][0]['args'], $GLOBALS['T_audit'] );
$GLOBALS['T_audit'] = array();
$r = d5b_ability_permit( 'divi5-builder/plugins-list', array() );
check( 'a WP_Error refusal is returned as is and audited as forbidden', is_wp_error( $r ) && 'rest_forbidden' === $r->code && 'forbidden' === $GLOBALS['T_audit'][0]['status'] );
$GLOBALS['T_audit'] = array();
check( 'a permission callback that throws is a refusal, not a fatal, and is audited', false === d5b_ability_permit( 'divi5-builder/themes-list', array() ) && 'forbidden' === $GLOBALS['T_audit'][0]['status'] );
$GLOBALS['T_audit'] = array();
check( 'unknown ability: refused and audited', false === d5b_ability_permit( 'divi5-builder/nothing-here', array() ) && 'forbidden' === $GLOBALS['T_audit'][0]['status'] );
check( 'a permission callback that returns a truthy non-true value is a refusal', ( function () { d5b_ability_add( mk( 'divi5-builder/core-env', 'core', array( 'permission' => function ( array $in ) { return 1; } ) ) ); return false === d5b_ability_permit( 'divi5-builder/core-env', array() ); } )() );
fresh( false );
check( 'master off: permit refuses even a def whose permission says yes', false === d5b_ability_permit( 'divi5-builder/posts-list', array() ) );

// ---- the run wrapper gates itself (break-it Important 1): a direct PHP call meets the same switches and capabilities
echo "direct run: the wrapper asks the same gate as the permission callback\n";
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return '6.9'; } }
if ( ! function_exists( 'get_site_transient' ) ) { function get_site_transient( $k ) { return false; } }
const ADMIN_CAPS = array( 'read', 'manage_options', 'edit_theme_options', 'list_users' );
function real_site( bool $master, array $off = array(), array $caps = ADMIN_CAPS ) {
	t_reset(); t_set_multisite( false ); t_set_caps( $caps ); $GLOBALS['T_user_id'] = 1; $GLOBALS['T_audit'] = array(); $GLOBALS['T_scrub'] = array();
	d5b_ability_registry( $GLOBALS['T_real_defs'] );
	if ( $master ) { d5b_abilities_set_master( true, 'tester' ); }
	foreach ( $off as $c ) { d5b_abilities_set_category( $c, false, false, 'tester' ); }
	t_add_term( 'category', 'Secret plans' );
	$GLOBALS['T_menus'][7] = (object) array( 'term_id' => 7, 'name' => 'Main', 'slug' => 'main', 'count' => 1 );
}
function refused_once( $r ): bool {
	return is_wp_error( $r ) && 'forbidden' === $r->get_error_code() && 1 === count( $GLOBALS['T_audit'] ) && 'forbidden' === $GLOBALS['T_audit'][0]['status']
		&& false === strpos( json_encode( $GLOBALS['T_scrub'] ), 'Secret plans' );
}
$direct = array( 'taxonomy-list' => 'taxonomy', 'menus-list' => 'menus', 'core-version' => 'core' );
foreach ( $direct as $short => $cat ) {
	real_site( true );
	$ok = d5b_ability_run( 'divi5-builder/' . $short, array() );
	check( "control: $short runs directly for an admin with everything on (one row, ok)", is_array( $ok ) && 1 === count( $GLOBALS['T_audit'] ) && 'ok' === $GLOBALS['T_audit'][0]['status'], array( $ok, $GLOBALS['T_audit'] ) );
	real_site( false );
	check( "master off: a direct d5b_ability_run of $short is refused (forbidden) and audited once", refused_once( d5b_ability_run( 'divi5-builder/' . $short, array() ) ), $GLOBALS['T_audit'] );
	real_site( true, array( $cat ) );
	check( "category $cat off: a direct run of $short is refused and audited once", refused_once( d5b_ability_run( 'divi5-builder/' . $short, array() ) ), $GLOBALS['T_audit'] );
	real_site( true, array(), array() );
	check( "no capability: a direct run of $short is refused and audited once", refused_once( d5b_ability_run( 'divi5-builder/' . $short, array() ) ), $GLOBALS['T_audit'] );
}
real_site( true, array(), array() );
$r = d5b_ability_run( 'divi5-builder/taxonomy-list', array() );
check( 'the refusal does not carry the data (no term name in the error)', is_wp_error( $r ) && false === strpos( json_encode( array( $r->get_error_message(), $r->get_error_data() ) ), 'Secret plans' ) );

echo "one accepted call, one row\n";
fresh( true ); fire_both(); $GLOBALS['T_audit'] = array();
$pc = $GLOBALS['T_abilities']['divi5-builder/posts-list']['permission_callback'];
$ec = $GLOBALS['T_abilities']['divi5-builder/posts-list']['execute_callback'];
$r  = true === call_user_func( $pc, array( 'id' => 1 ) ) ? call_user_func( $ec, array( 'id' => 1 ) ) : null;
check( 'core order (permission callback, then execute): exactly one audit row, status ok (the double check adds none)', is_array( $r ) && 1 === count( $GLOBALS['T_audit'] ) && 'ok' === $GLOBALS['T_audit'][0]['status'], $GLOBALS['T_audit'] );
fresh( true ); d5b_ability_add( mk( 'divi5-builder/users-list', 'users', array( 'permission' => function ( array $in ) { return false; } ) ) ); fire_both(); $GLOBALS['T_audit'] = array();
call_user_func( $GLOBALS['T_abilities']['divi5-builder/users-list']['permission_callback'], array() );
check( 'a refusal through core\'s permission callback is audited once (core does not call execute after it)', 1 === count( $GLOBALS['T_audit'] ) && 'forbidden' === $GLOBALS['T_audit'][0]['status'] );

echo "write-ahead\n";
fresh( true ); $GLOBALS['T_audit'] = array(); $GLOBALS['T_seen'] = null;
d5b_ability_add( mk( 'divi5-builder/media-get', 'media', array( 'handler' => function ( array $in ) { $GLOBALS['T_seen'] = $GLOBALS['T_audit']; return array( 'ok' => 1 ); } ) ) );
d5b_ability_run( 'divi5-builder/media-get', array( 'id' => 4 ) );
check( 'the row exists with status started before the handler runs, and is then updated to ok (still one row)', is_array( $GLOBALS['T_seen'] ) && 1 === count( $GLOBALS['T_seen'] ) && 'started' === $GLOBALS['T_seen'][0]['status'] && 1 === count( $GLOBALS['T_audit'] ) && 'ok' === $GLOBALS['T_audit'][0]['status'], array( $GLOBALS['T_seen'], $GLOBALS['T_audit'] ) );
$GLOBALS['T_audit'] = array(); $GLOBALS['T_audit_fail'] = true; $GLOBALS['T_ran'] = 0;
d5b_ability_add( mk( 'divi5-builder/media-list', 'media', array( 'handler' => function ( array $in ) { $GLOBALS['T_ran']++; return array( 'ok' => 1 ); } ) ) );
$r = d5b_ability_run( 'divi5-builder/media-list', array() );
$GLOBALS['T_audit_fail'] = false;
check( 'the started row cannot be written: audit_unavailable, the handler is not called', is_wp_error( $r ) && 'audit_unavailable' === $r->get_error_code() && 0 === $GLOBALS['T_ran'], $r );

// The stand-in WP_Error has ->code / ->message / ->data for the tests; core's has not, so the plugin must use the methods.
$uses = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) { if ( 'php' === $f->getExtension() && preg_match( '/->(code|message|data)\b(?!\s*\()/', (string) file_get_contents( (string) $f ) ) ) { $uses[] = basename( (string) $f ); } }
check( 'no plugin file reads ->code, ->message or ->data (only the WP_Error methods, which core has)', array() === $uses, $uses );

// ---- every WP_Error leaving the wrapper carries an HTTP status (core returns the execute callback's error as it is; no status = 500)
echo "HTTP status of every error\n";
function status_of( $r ) { return is_wp_error( $r ) ? ( $r->get_error_data( $r->get_error_code() )['status'] ?? null ) : 'not an error'; }
$want = array( 'invalid_input' => 400, 'page_too_deep' => 400, 'forbidden' => 403, 'not_allowed' => 403, 'not_found' => 404, 'unavailable' => 503, 'internal_error' => 500, 'something_else' => 500 );
foreach ( $want as $code => $status ) {
	fresh( true );
	d5b_ability_add( mk( 'divi5-builder/core-err', 'core', array( 'handler' => function ( array $in ) use ( $code ) { return new WP_Error( $code, 'Message for ' . $code . '.' ); } ) ) );
	$r = d5b_ability_run( 'divi5-builder/core-err', array() );
	check( "a handler's $code answers HTTP $status, code and message kept", $status === status_of( $r ) && $code === $r->get_error_code() && 'Message for ' . $code . '.' === $r->get_error_message(), array( status_of( $r ), $r ) );
}
fresh( true );
d5b_ability_add( mk( 'divi5-builder/core-err', 'core', array( 'handler' => function ( array $in ) { return new WP_Error( 'not_a_tb_post', 'Route error.', array( 'status' => 404, 'id' => 5 ) ); } ) ) );
$r = d5b_ability_run( 'divi5-builder/core-err', array() );
check( 'a status already in the data is kept, and the rest of the data with it', 404 === status_of( $r ) && 5 === ( $r->get_error_data()['id'] ?? null ), $r );
d5b_ability_add( mk( 'divi5-builder/core-err2', 'core', array( 'handler' => function ( array $in ) { return new WP_Error( 'invalid_input', 'Bad.', array( 'field' => 'id' ) ); } ) ) );
$r = d5b_ability_run( 'divi5-builder/core-err2', array() );
check( 'data without a status gets one added, the rest kept', 400 === status_of( $r ) && 'id' === ( $r->get_error_data()['field'] ?? null ), $r );
d5b_ability_add( mk( 'divi5-builder/core-boom', 'core', array( 'handler' => function ( array $in ) { throw new RuntimeException( 'x' ); } ) ) );
check( 'a thrown handler: internal_error, HTTP 500', 500 === status_of( d5b_ability_run( 'divi5-builder/core-boom', array() ) ) );
check( 'an unknown ability: not_found (unified from ability_not_found), HTTP 404', 'not_found' === d5b_ability_run( 'divi5-builder/no-such', array() )->get_error_code() && 404 === status_of( d5b_ability_run( 'divi5-builder/no-such', array() ) ) );
fresh( false );
check( 'the gate refuses (master off): forbidden, HTTP 403', 403 === status_of( $r = d5b_ability_run( 'divi5-builder/posts-list', array() ) ) && 'forbidden' === $r->get_error_code() );
fresh( true );
d5b_ability_add( mk( 'divi5-builder/plugins-list', 'plugins', array( 'permission' => function ( array $in ) { return new WP_Error( 'rest_forbidden', 'No.' ); } ) ) );
check( 'a definition\'s own WP_Error refusal: kept, HTTP 403 on the run path and the permission path', 403 === status_of( $r = d5b_ability_run( 'divi5-builder/plugins-list', array() ) ) && 'rest_forbidden' === $r->get_error_code() && 403 === status_of( d5b_ability_permit( 'divi5-builder/plugins-list', array() ) ) );
fresh( true ); $GLOBALS['T_audit_fail'] = true;
$r = d5b_ability_run( 'divi5-builder/posts-list', array() );
$GLOBALS['T_audit_fail'] = false;
check( 'the audit row cannot be written: audit_unavailable, HTTP 503', 'audit_unavailable' === $r->get_error_code() && 503 === status_of( $r ), $r );
fresh( true ); $GLOBALS['T_user_id'] = 0;
check( 'an anonymous caller: forbidden, HTTP 403 (run and permission paths)', 403 === status_of( $r = d5b_ability_run( 'divi5-builder/posts-list', array() ) ) && 'forbidden' === $r->get_error_code() && 403 === status_of( d5b_ability_permit( 'divi5-builder/posts-list', array() ) ) );
check( 'forbidden_anonymous, should it ever be a code, maps to 403 too', 403 === d5b_ability_http_status( 'forbidden_anonymous' ) );
// The codes the shipped handlers return: every one is in the table (not the 500 fallback), so no refusal answers 500.
// internal_error and write_failed (WordPress itself did not make a write that passed every check) are real 500s.
$codes = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir . '/inc/abilities', FilesystemIterator::SKIP_DOTS ) ) as $f ) {
	if ( preg_match_all( "/new WP_Error\(\s*'([a-z_]+)'/", (string) file_get_contents( (string) $f ), $mm ) ) { foreach ( $mm[1] as $c ) { $codes[ $c ] = true; } }
}
$unmapped = array_values( array_filter( array_keys( $codes ), function ( $c ) { return 500 === d5b_ability_http_status( $c ) && 'internal_error' !== $c && 'write_failed' !== $c; } ) );
check( 'every WP_Error code in inc/abilities has its own status (no odd codes left: invalid_type, invalid_taxonomy, forbidden_status are gone)', array() === $unmapped && ! isset( $codes['invalid_type'] ) && ! isset( $codes['invalid_taxonomy'] ) && ! isset( $codes['forbidden_status'] ) && ! isset( $codes['ability_not_found'] ), array( $unmapped, array_keys( $codes ) ) );

fresh( true );
$threw = ''; try { d5b_ability_set_extra_categories( array( 'posts-list' => array( 'revisons' ) ) ); } catch ( InvalidArgumentException $e ) { $threw = $e->getMessage(); }
check( 'an unknown extra category throws (as d5b_ability_add does) and is not stored', false !== strpos( $threw, 'revisons' ) && ! isset( d5b_ability_defs()['divi5-builder/posts-list']['extra_categories'] ), $threw );
d5b_ability_set_extra_categories( array( 'posts-list' => array( 'revisions' ), 'no-such-ability' => array( 'revisions' ) ) );
check( 'a known extra category is stored; an unknown ability name is still ignored', array( 'revisions' ) === ( d5b_ability_defs()['divi5-builder/posts-list']['extra_categories'] ?? null ) && ! isset( d5b_ability_defs()['divi5-builder/no-such-ability'] ) );

echo "posts-revisions needs the Revisions switch too (strictest switch wins)\n";
real_site( true, array( 'revisions' ) );
add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' ); add_action( 'wp_abilities_api_init', 'd5b_abilities_register' ); fire_both();
check( 'revisions off: posts-revisions is not registered, the other posts abilities are', ! isset( $GLOBALS['T_abilities']['divi5-builder/posts-revisions'] ) && isset( $GLOBALS['T_abilities']['divi5-builder/posts-get'] ), array_keys( $GLOBALS['T_abilities'] ) );
$pid = t_add_post( array( 'post_author' => 1 ) ); t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $pid ) );
t_set_caps( array( 'read', 'edit_posts', 'edit_published_posts', 'edit_others_posts' ) );
check( 'revisions off: a direct run of posts-revisions is refused (forbidden) and audited', 'forbidden' === d5b_ability_run( 'divi5-builder/posts-revisions', array( 'id' => $pid ) )->get_error_code() && 'forbidden' === end( $GLOBALS['T_audit'] )['status'] && false === d5b_ability_permit( 'divi5-builder/posts-revisions', array( 'id' => $pid ) ) );
real_site( true ); t_set_caps( array( 'read', 'edit_posts', 'edit_published_posts', 'edit_others_posts' ) );
$pid = t_add_post( array( 'post_author' => 1 ) ); t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $pid ) );
check( 'control: both on, posts-revisions runs and lists the revision', 1 === count( ( $r = d5b_ability_run( 'divi5-builder/posts-revisions', array( 'id' => $pid ) ) )['items'] ?? array() ), $r );
real_site( true, array( 'posts' ) ); t_set_caps( array( 'read', 'edit_posts', 'edit_published_posts', 'edit_others_posts' ) );
check( 'posts off (revisions on): still refused', is_wp_error( d5b_ability_run( 'divi5-builder/posts-revisions', array( 'id' => $pid ) ) ) );

// ---- audit and scrub absent: the wrapper still works
echo "wrapper without the audit writer and scrubber\n";
$php = escapeshellarg( PHP_BINARY );
$self = escapeshellarg( __FILE__ );
$out = array(); exec( $php . ' ' . $self . ' --bare 2>&1', $out, $code );
$line = implode( ' ', $out );
check( 'audit writer absent: run refuses with audit_unavailable and never calls the handler; permit still refuses; no fatal', 0 === $code && false !== strpos( $line, 'BARE_OK' ), $line );

// ---- children: the real loader
echo "the real loader\n";
$out = array(); exec( $php . ' ' . $self . ' --child 2>&1', $out, $code );
$line = implode( ' ', $out );
check( 'with the API: loader loads, both hooks wired, the one def is registered, no error', 0 === $code && false !== strpos( $line, 'CHILD_OK api=yes wired_cat=1 wired_reg=1 abilities=1 errors=0' ), $line );
$env = ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) ? 'set D5B_NO_ABILITIES_API=1&& ' : 'D5B_NO_ABILITIES_API=1 ';
$out = array(); exec( $env . $php . ' ' . $self . ' --child 2>&1', $out, $code );
$line = implode( ' ', $out );
check( 'no Abilities API (functions missing, one def, master on): loader loads, hooks fire, nothing fatal, nothing registered', 0 === $code && false !== strpos( $line, 'CHILD_OK api=no wired_cat=1 wired_reg=1 abilities=0 errors=0' ), $line );

echo "\n" . ( $n - $failed ) . " / $n passed\n";
exit( $failed ? 1 : 0 );
