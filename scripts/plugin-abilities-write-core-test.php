<?php
/**
 * Core and translation abilities (#32, release 2.4.0, Task 5): core-status, core-update and translations-update.
 *
 *   php -d max_execution_time=60 scripts/plugin-abilities-write-core-test.php
 *
 * Loads the real inc/ files and parts/updates.php (the update engine: wpsu_status, wpsu_update, wpsu_core_kind) with WordPress
 * stand-ins (plugin-test-stubs.php with T_PLUGIN_STANDINS: get_core_updates reads the update_core transient, Core_Upgrader and
 * Language_Pack_Upgrader behave as core 6.4.1 does at the surface the engine uses: the new version string, or an error). The page
 * checks run for real (maint-common.php) against scripted page answers (T_http_fn) that depend on the WordPress version.
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: core-status reads the stored list and returns only version, offered, kind, blocked and
 * update_available; core-update takes only a version that is on offer (refused before anything runs otherwise), keeps NO copy
 * (a trap on copy_dir and no store folder), runs the update inside the page checks and never puts anything back, records the
 * versions only and says "none, restore a backup"; translations-update is a normal write with a count in the row, no copy and no
 * page check.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.4.0' );
define( 'T_PLUGIN_STANDINS', true );
define( 'WP_PLUGIN_DIR', str_replace( '\\', '/', sys_get_temp_dir() ) . '/d5b-wcore-' . getmypid() . '/plugins' );
define( 'D5B_FILE', WP_PLUGIN_DIR . '/divi5-builder/divi5-builder.php' );
require __DIR__ . '/plugin-test-stubs.php';
function wp_json_encode( $v ) { return json_encode( $v ); }

foreach ( array( 'inc/parts', 'inc/switches', 'inc/abilities/categories', 'inc/abilities/register', 'inc/abilities/secrets', 'inc/abilities/audit', 'inc/abilities/scrub', 'inc/abilities/history', 'inc/abilities/read-content', 'inc/abilities/read-site', 'inc/abilities/read-history', 'inc/abilities/write-common', 'inc/abilities/confirm', 'inc/abilities/maint-common', 'inc/abilities/write-plugins', 'inc/abilities/write-themes', 'inc/abilities/write-core', 'parts/updates' ) as $f ) { require $dir . '/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 800 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ADMIN = array( 'read', 'manage_options', 'update_core', 'update_languages' );

$T_BASE = dirname( WP_PLUGIN_DIR );
function t_rm( $d ) {
	if ( is_link( $d ) || is_file( $d ) ) { @unlink( $d ); return; }
	if ( ! is_dir( $d ) ) { return; }
	foreach ( array_diff( (array) scandir( $d ), array( '.', '..' ) ) as $f ) { t_rm( $d . '/' . $f ); }
	@rmdir( $d );
}
register_shutdown_function( function () use ( $T_BASE ) { t_rm( $T_BASE ); } );

function page( $title = 'Home', $size = 50000 ) { $h = '<html><head><title>' . $title . '</title></head><body>'; return $h . str_repeat( 'x', max( 0, $size - strlen( $h ) - 14 ) ) . '</body></html>'; }
// The home page: fine, unless the WordPress version now installed is one in T_breaks_at (then core's critical error page).
function serve_site() {
	$GLOBALS['T_http_fn'] = function ( $url, $args ) {
		if ( 'https://x.example/' !== preg_replace( '/[?&]d5b=[0-9]+$/', '', $url ) ) { return null; }
		if ( in_array( get_bloginfo( 'version' ), $GLOBALS['T_breaks_at'], true ) ) { return array( 'code' => 500, 'body' => 'There has been a critical error on this website.' ); }
		return array( 'code' => 200, 'body' => page() );
	};
}
// The update_core transient: the versions on offer (core's 'upgrade' responses) and $packs language packs.
function core_offers( array $versions, int $packs = 0 ) {
	$t = new stdClass(); $t->last_checked = 1700000000; $t->updates = array(); $t->translations = array();
	foreach ( $versions as $v ) { $t->updates[] = (object) array( 'response' => 'upgrade', 'current' => $v, 'download' => 'https://downloads.wordpress.org/release/wordpress-' . $v . '.zip' ); }
	for ( $i = 0; $i < $packs; $i++ ) { $t->translations[] = array( 'type' => 'core', 'slug' => 'default', 'language' => 'de_DE' . $i, 'version' => '6.9' ); }
	return $t;
}
function offer( array $versions, int $packs = 0 ) { $GLOBALS['T_site_transients']['update_core'] = core_offers( $versions, $packs ); $GLOBALS['T_fresh']['update_core'] = core_offers( $versions, $packs ); }
function site( array $caps = ADMIN, bool $multi = false, bool $risky = true ) {
	global $T_BASE;
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( $multi );
	$GLOBALS['T_user_id'] = 3; $GLOBALS['T_user_login'] = 'admin'; $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
	d5b_abilities_set_category( 'core', true, $risky, 'tester' );
	$GLOBALS['T_clock'] = 1700000000.0;
	t_rm( $T_BASE ); mkdir( $T_BASE . '/uploads', 0777, true );
	$GLOBALS['T_upload_basedir'] = $T_BASE . '/uploads';
	// The file system ready, as it is on an admin request: a copy_dir (the one thing that must NOT happen) is then recorded in T_copy_calls instead of stopping the run.
	WP_Filesystem();
	unset( $GLOBALS['T_core_upgrade_hook'] );
	set_disk_version( '6.9' );
	$GLOBALS['T_plugin_table'] = array();
	$GLOBALS['T_breaks_at'] = array();
	offer( array( '6.9.1' ) );
	serve_site();
}
// wp-includes/version.php as the engine reads it back after an update (the file, never get_bloginfo).
function set_disk_version( $v ) { @mkdir( ABSPATH . 'wp-includes', 0777, true ); file_put_contents( ABSPATH . 'wp-includes/version.php', '<?php' . "\n" . '$wp_version = \'' . $v . '\';' . "\n" ); }
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function edata( $r ) { return is_wp_error( $r ) ? $r->get_error_data() : null; }
function msg( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : $r; }
function hrows() { global $wpdb; return array_values( $wpdb->tables[ d5b_history_table() ] ?? array() ); }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function traps( $name ) { return count( array_filter( $GLOBALS['T_traps'], function ( $t ) use ( $name ) { return $t === $name; } ) ); }
function store_dirs() { global $T_BASE; return array_values( array_diff( (array) ( @scandir( $T_BASE . '/uploads/d5b-history' ) ?: array() ), array( '.', '..', '.htaccess', 'index.html' ) ) ); }

// ---- registration
echo "registration\n";
site();
$defs = d5b_ability_defs();
$st = $defs['divi5-builder/core-status'] ?? null; $up = $defs['divi5-builder/core-update'] ?? null; $tr = $defs['divi5-builder/translations-update'] ?? null;
check( 'all three exist, in the core category', null !== $st && null !== $up && null !== $tr && array( 'core' ) === array_values( array_unique( array( $st['category'], $up['category'], $tr['category'] ) ) ) );
check( 'core-status: a read (no risky key, read-only as every read)', ! array_key_exists( 'risky', (array) $st ) && false !== ( $st['annotations']['readonly'] ?? true ) && array() === array_keys( $st['input_schema']['properties'] ?? array() ) );
check( 'core-update: risky, version required and the only input (no copy options)', true === ( $up['risky'] ?? null ) && array( 'version' ) === $up['input_schema']['required'] && array( 'version' ) === array_keys( $up['input_schema']['properties'] ) && false === ( $up['annotations']['readonly'] ?? null ) );
check( 'translations-update: a normal write (no risky key), no inputs', ! array_key_exists( 'risky', (array) $tr ) && false === ( $tr['annotations']['readonly'] ?? null ) && array() === array_keys( $tr['input_schema']['properties'] ?? array() ) );
check( 'the descriptions say what the user needs to know', false !== stripos( $up['description'], 'no copy' ) && false !== stripos( $up['description'], 'restore a backup' ) && false !== stripos( $tr['description'], 'no page check' ), $up['description'] );
check( 'update_failed and invalid_input are not 500', 500 !== d5b_ability_http_status( 'update_failed' ) && 400 === d5b_ability_http_status( 'invalid_input' ) );

// ---- core-status
echo "core-status\n";
site();
$r = call( 'core-status' );
check( 'status: 6.9 -> 6.9.1: version, offered, kind minor (a maintenance release), no block, an update waits', is_array( $r ) && '6.9' === $r['version'] && array( '6.9.1' ) === $r['offered'] && 'minor' === $r['kind'] && '' === $r['blocked'] && true === $r['update_available'], $r );
check( 'status: only those five fields, never the raw status (no plugins, themes, site, php, last_checked, translations)', array( 'blocked', 'kind', 'offered', 'update_available', 'version' ) === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( (array) $r ) ), array_keys( (array) $r ) );
check( 'status never clears a transient and never asks wordpress.org; no row', array() === $GLOBALS['T_site_transient_deletes'] && array() === $GLOBALS['T_update_checks'] && array() === hrows(), array( $GLOBALS['T_site_transient_deletes'], $GLOBALS['T_update_checks'] ) );
offer( array( '6.10' ) ); $r = call( 'core-status' );
check( 'status: 6.9 -> 6.10 is major', 'major' === $r['kind'] && array( '6.10' ) === $r['offered'], $r );
offer( array( '7.0' ) ); $r = call( 'core-status' );
check( 'status: 6.9 -> 7.0 is major', 'major' === $r['kind'], $r );
offer( array( '6.9.1', '7.0' ) ); $r = call( 'core-status' );
check( 'status: two on offer: both listed (oldest first), kind of the newest', array( '6.9.1', '7.0' ) === $r['offered'] && 'major' === $r['kind'], $r );
offer( array() ); $r = call( 'core-status' );
check( 'status: nothing on offer: offered empty, kind null, no update waiting', array() === $r['offered'] && null === $r['kind'] && false === $r['update_available'], $r );
$GLOBALS['T_file_mods_off'] = true; $r = call( 'core-status' );
check( 'status: file changes switched off: blocked says why', false !== strpos( (string) $r['blocked'], 'DISALLOW_FILE_MODS' ), $r );
site( array( 'read', 'manage_options', 'update_plugins' ) );
check( 'status: without update_core: refused', 'FORBIDDEN' === call( 'core-status' ) );
site();
check( 'status: unknown input refused', 'invalid_input' === code( call( 'core-status', array( 'refresh' => true ) ) ) );

// ---- core-update
echo "core-update\n";
site();
$r = call( 'core-update', array( 'version' => '6.9.1' ) );
$hid = (int) ( $r['history_id'] ?? 0 );
check( 'update: 6.9 -> 6.9.1 done: updated, from, to, version now, kind minor', is_array( $r ) && true === $r['updated'] && '6.9' === $r['from'] && '6.9.1' === $r['to'] && '6.9.1' === $r['version'] && 'minor' === $r['kind'] && '6.9.1' === get_bloginfo( 'version' ), $r );
check( '... Core_Upgrader::upgrade once, with the 6.9.1 offer; no other upgrader', 1 === traps( 'core_upgrade' ) && 1 === count( $GLOBALS['T_core_upgrade_calls'] ) && '6.9.1' === $GLOBALS['T_core_upgrade_calls'][0]->current && 0 === traps( 'lang_upgrade' ) && 0 === traps( 'bulk_upgrade' ), $GLOBALS['T_traps'] );
check( '... the answer says rollback "none, restore a backup", carries the human check and the pages (both rounds)', 'none, restore a backup' === $r['rollback'] && d5b_human_check() === $r['human_check'] && 1 === count( $r['pages']['before'] ) && 1 === count( $r['pages']['after'] ) && array() === $r['problems'], $r );
check( '... NO copy: no copy_dir / filesystem copy, no store folder, no kept in the result', array() === $GLOBALS['T_copy_calls'] && array() === store_dirs() && ! array_key_exists( 'kept', $r ), $GLOBALS['T_copy_calls'] );
$h = hlast();
check( '... one row: core, update, ability core-update, before { version }, after { version, pages } and nothing else (no kept)', 1 === count( hrows() ) && 'core' === $h['object_type'] && 'update' === $h['action'] && 'divi5-builder/core-update' === $h['ability'] && array( 'version' => '6.9' ) === $h['before'] && array( 'pages', 'version' ) === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $h['after'] ) ) && '6.9.1' === $h['after']['version'] && isset( $h['after']['pages']['after'] ), $h );
check( '... a warning about the database update; none about the pages', ! empty( $r['warnings'] ) && 1 === preg_match( '/database update/i', implode( ' ', $r['warnings'] ) ) && false === stripos( implode( ' ', $r['warnings'] ), 'got worse' ), $r['warnings'] ?? null );

site(); offer( array( '6.9.1', '6.10' ) );
$r = call( 'core-update', array( 'version' => '6.10' ) );
check( 'update: two on offer, the one named is installed (6.10, kind major)', is_array( $r ) && '6.10' === $r['version'] && 'major' === $r['kind'] && '6.10' === $GLOBALS['T_core_upgrade_calls'][0]->current, $r );

// A version that is not on offer: refused before anything runs.
site();
$r = call( 'core-update', array( 'version' => '6.9.2' ) );
check( 'update: a version not on offer is refused (invalid_input, 409), the offered versions named', 'invalid_input' === code( $r ) && 409 === ( edata( $r )['status'] ?? null ) && false !== strpos( msg( $r ), '6.9.1' ), array( code( $r ), edata( $r ), msg( $r ) ) );
check( '... nothing ran: no upgrader, no row, no page round, no copy, version unchanged', array() === $GLOBALS['T_core_upgrade_calls'] && 0 === traps( 'core_upgrade' ) && array() === hrows() && array() === $GLOBALS['T_copy_calls'] && '6.9' === get_bloginfo( 'version' ) && array() === array_filter( $GLOBALS['T_http_calls'], function ( $c ) { return false !== strpos( $c[0], 'd5b=' ); } ) );
site();
foreach ( array( '7.0', '6.9', '6.8.9', 'latest' ) as $bad ) {
	$r = call( 'core-update', array( 'version' => $bad ) );
	check( 'update: ' . json_encode( $bad ) . ' (not on offer / not a version) refused, nothing ran', is_wp_error( $r ) && 'invalid_input' === code( $r ) && array() === $GLOBALS['T_core_upgrade_calls'] && array() === hrows(), array( code( $r ), msg( $r ) ) );
}
foreach ( array( '6.9.1; DROP', '../6.9.1', '6.9.1 6.9.2', '' ) as $bad ) {
	check( 'update: ' . json_encode( $bad ) . ' is invalid_input', 'invalid_input' === code( call( 'core-update', array( 'version' => $bad ) ) ) && array() === $GLOBALS['T_core_upgrade_calls'] && array() === hrows() );
}
site();
check( 'update: version missing / unknown input refused', 'invalid_input' === code( call( 'core-update', array() ) ) && 'invalid_input' === code( call( 'core-update', array( 'version' => '6.9.1', 'without_copy' => true ) ) ) && array() === $GLOBALS['T_core_upgrade_calls'] );
// The offer vanished between the status and the update: a version that is no longer offered is refused too.
site(); offer( array() );
$r = call( 'core-update', array( 'version' => '6.9.1' ) );
check( 'update: nothing on offer at all -> already_up_to_date, nothing changed, no row', is_array( $r ) && true === ( $r['already_up_to_date'] ?? null ) && false === $r['updated'] && array() === $GLOBALS['T_core_upgrade_calls'] && array() === hrows(), $r );

// Pages worse: reported, never undone (there is nothing to put back).
site(); $GLOBALS['T_breaks_at'] = array( '6.9.1' );
$r = call( 'core-update', array( 'version' => '6.9.1' ) );
check( 'update: pages worse -> updated, problems named, warning "restore a backup", NOT undone (one upgrader call, version stays)', is_array( $r ) && true === $r['updated'] && 1 === count( $r['problems'] ) && false !== strpos( $r['problems'][0], 'answered 500' ) && 1 === count( $GLOBALS['T_core_upgrade_calls'] ) && '6.9.1' === get_bloginfo( 'version' ) && 1 === preg_match( '/restore a backup/', implode( ' ', $r['warnings'] ) ) && 'none, restore a backup' === $r['rollback'], $r );
check( '... the row holds the pages and the new version; still no copy', 1 === count( $GLOBALS['T_core_upgrade_calls'] ) && 1 === count( hlast()['after']['pages']['problems'] ) && '6.9.1' === hlast()['after']['version'] && array() === $GLOBALS['T_copy_calls'] );

// The upgrader fails.
site(); $GLOBALS['T_core_upgrade_result'] = new WP_Error( 'download_failed', 'Download failed.' );
$r = call( 'core-update', array( 'version' => '6.9.1' ) );
check( 'update: the upgrader fails -> update_failed (502) with the reason, rollback text and human check in the data, version unchanged', 'update_failed' === code( $r ) && 502 === d5b_ability_http_status( 'update_failed' ) && false !== strpos( msg( $r ), 'Download failed' ) && 'none, restore a backup' === ( edata( $r )['rollback'] ?? null ) && d5b_human_check() === ( edata( $r )['human_check'] ?? null ) && false === edata( $r )['updated'] && '6.9' === get_bloginfo( 'version' ), array( code( $r ), msg( $r ) ) );
check( '... the row says failed and keeps the pages; the error says restore a backup', false !== strpos( (string) ( hlast()['after']['failed'] ?? '' ), 'Download failed' ) && isset( hlast()['after']['pages'] ) && false !== strpos( msg( $r ), 'restore a backup' ) );
site(); $GLOBALS['T_core_upgrade_result'] = false;
check( 'update: the upgrader answers false -> update_failed', 'update_failed' === code( call( 'core-update', array( 'version' => '6.9.1' ) ) ) );
// Fix round 1: the interim row, the version read from the file, the race between the dry run and the run.
site(); $GLOBALS['T_core_upgrade_hook'] = function () { $GLOBALS['T_interim'] = hlast(); };
call( 'core-update', array( 'version' => '6.9.1' ) );
check( 'update: the interim row (read inside the upgrader) is { version: from, to, running: true }, before { version }', array( 'version' => '6.9', 'to' => '6.9.1', 'running' => true ) === $GLOBALS['T_interim']['after'] && array( 'version' => '6.9' ) === $GLOBALS['T_interim']['before'], $GLOBALS['T_interim'] ?? null );
site(); set_disk_version( '6.9.1' ); $GLOBALS['T_core_upgrade_result'] = new RuntimeException( 'boom' );
$r = call( 'core-update', array( 'version' => '6.9.1' ) );
check( 'update: the updater throws after the files changed (version.php says 6.9.1) -> update_failed, the result and the row say 6.9.1, not the stale 6.9', 'update_failed' === code( $r ) && '6.9.1' === ( edata( $r )['version'] ?? null ) && '6.9.1' === hlast()['after']['version'] && '6.9' === get_bloginfo( 'version' ), array( edata( $r )['version'] ?? null, hlast()['after'] ?? null ) );
site(); set_disk_version( '6.9.1' ); $GLOBALS['T_core_upgrade_result'] = new WP_Error( 'copy_failed', 'Could not copy file.' );
$r = call( 'core-update', array( 'version' => '6.9.1' ) );
check( 'update: the updater answers a WP_Error after the files changed -> the file version in the result and the row', '6.9.1' === ( edata( $r )['version'] ?? null ) && '6.9.1' === hlast()['after']['version'] );
site(); $GLOBALS['T_core_upgrade_result'] = new WP_Error( 'copy_failed', 'Could not copy file.' );
$r = call( 'core-update', array( 'version' => '6.9.1' ) );
check( 'update: the updater fails and the files did not change -> version 6.9 (from the file)', '6.9' === ( edata( $r )['version'] ?? null ) && '6.9' === hlast()['after']['version'] );
// The offer changes between the dry run and the run: the page round before the run is the moment in between.
site(); $first = true; $base = $GLOBALS['T_http_fn'];
$GLOBALS['T_http_fn'] = function ( $url, $args ) use ( &$first, $base ) { if ( $first ) { $first = false; offer( array( '6.9.2' ) ); } return $base( $url, $args ); };
$GLOBALS['T_fresh']['update_core'] = core_offers( array( '6.9.1' ) );
$r = call( 'core-update', array( 'version' => '6.9.1' ) );
check( 'update: the offer flipped between the dry run and the run -> invalid_input, status 409 (as d5b_ability_run returns it), the row deleted, no upgrade call', 'invalid_input' === code( $r ) && 409 === ( edata( $r )['status'] ?? null ) && array() === hrows() && array() === $GLOBALS['T_core_upgrade_calls'] && '6.9' === get_bloginfo( 'version' ), array( code( $r ), edata( $r ), count( hrows() ) ) );
site(); $first = true; $base = $GLOBALS['T_http_fn'];
$GLOBALS['T_http_fn'] = function ( $url, $args ) use ( &$first, $base ) { if ( $first ) { $first = false; offer( array() ); } return $base( $url, $args ); };
$r = call( 'core-update', array( 'version' => '6.9.1' ) );
check( 'update: the offer vanished between the dry run and the run -> already_up_to_date, row deleted, nothing upgraded', is_array( $r ) && true === ( $r['already_up_to_date'] ?? null ) && array() === hrows() && array() === $GLOBALS['T_core_upgrade_calls'], $r );
site(); $GLOBALS['T_core_upgrade_result'] = new RuntimeException( 'secret: sk_' . 'live_abc' ); $level = ob_get_level();
$r = call( 'core-update', array( 'version' => '6.9.1' ) );
check( 'update: an upgrader that prints and throws -> update_failed, output swallowed, buffers closed, the exception message not shown', 'update_failed' === code( $r ) && $level === ob_get_level() && false === strpos( json_encode( array( msg( $r ), edata( $r ) ) ), 'printed by the upgrader' ) && false === strpos( json_encode( array( msg( $r ), edata( $r ) ) ), 'sk_live' ), array( code( $r ), msg( $r ) ) );
site(); $GLOBALS['T_http_fn'] = function () { return null; };
$r = call( 'core-update', array( 'version' => '6.9.1' ) );
check( 'update: the site cannot fetch its own pages -> still updated, pages unavailable, a warning', is_array( $r ) && true === $r['updated'] && true === $r['pages']['unavailable'] && 1 === preg_match( '/no page check/', implode( ' ', $r['warnings'] ) ), $r );

// Permissions and switches.
site( ADMIN, false, false );
check( 'update: the core risky switch off: refused, nothing ran', 'FORBIDDEN' === call( 'core-update', array( 'version' => '6.9.1' ) ) && array() === $GLOBALS['T_core_upgrade_calls'] );
site( array( 'read', 'manage_options', 'update_plugins', 'update_languages' ) );
check( 'update: without update_core: refused', 'FORBIDDEN' === call( 'core-update', array( 'version' => '6.9.1' ) ) && array() === $GLOBALS['T_core_upgrade_calls'] );
site(); $GLOBALS['T_file_mods_off'] = true;
$r = call( 'core-update', array( 'version' => '6.9.1' ) );
check( 'update: file changes switched off -> not_allowed, nothing ran, no row', 'not_allowed' === code( $r ) && array() === $GLOBALS['T_core_upgrade_calls'] && array() === hrows(), code( $r ) );
site( ADMIN, true ); $GLOBALS['T_user_login'] = 'siteadmin';
check( 'update: multisite, not a network administrator: refused', 'FORBIDDEN' === call( 'core-update', array( 'version' => '6.9.1' ) ) && array() === $GLOBALS['T_core_upgrade_calls'] );
site( ADMIN, true );
check( 'update: multisite, a network administrator: updated', is_array( call( 'core-update', array( 'version' => '6.9.1' ) ) ) && 1 === count( $GLOBALS['T_core_upgrade_calls'] ) );

// ---- translations-update
echo "translations-update\n";
site(); offer( array(), 2 );
$r = call( 'translations-update' );
check( 'translations: 2 packs on offer -> updated, count 2, the language upgrader once, no core upgrader', is_array( $r ) && true === $r['updated'] && 2 === $r['count'] && 1 === traps( 'lang_upgrade' ) && 0 === traps( 'core_upgrade' ) && 0 === count( wp_get_translation_updates() ), $r );
$h = hlast();
check( '... one row: translations, update, ability translations-update, before null, after { count } only', 1 === count( hrows() ) && 'translations' === $h['object_type'] && 'update' === $h['action'] && 'divi5-builder/translations-update' === $h['ability'] && empty( $h['before'] ) && array( 'count' => 2 ) === $h['after'], $h );
check( '... no copy (copy_dir never called, no store folder), no page check (no page fetched)', array() === $GLOBALS['T_copy_calls'] && array() === store_dirs() && array() === $GLOBALS['T_http_calls'] && ! array_key_exists( 'pages', $r ) && ! array_key_exists( 'kept', $r ), $GLOBALS['T_http_calls'] );
site( ADMIN, false, false ); offer( array(), 1 );
$r = call( 'translations-update' );
check( 'translations: a normal write: works with the risky switch off', is_array( $r ) && true === $r['updated'] && 1 === $r['count'], $r );
site(); offer( array(), 0 );
$r = call( 'translations-update' );
check( 'translations: none on offer -> already_up_to_date, no upgrader, no row', is_array( $r ) && true === ( $r['already_up_to_date'] ?? null ) && 0 === traps( 'lang_upgrade' ) && array() === hrows(), $r );
site(); offer( array(), 2 ); $GLOBALS['T_lang_upgrade_result'] = new WP_Error( 'copy_failed', 'Could not copy file.' );
$r = call( 'translations-update' );
check( 'translations: the upgrader fails -> update_failed with the reason, the row says failed', 'update_failed' === code( $r ) && false !== strpos( msg( $r ), 'Could not copy file' ) && false !== strpos( (string) ( hlast()['after']['failed'] ?? '' ), 'Could not copy file' ) && 2 === hlast()['after']['count'], array( code( $r ), msg( $r ) ) );
site(); offer( array(), 2 ); $GLOBALS['T_file_mods_off'] = true;
$r = call( 'translations-update' );
check( 'translations: file changes switched off -> not_allowed, nothing ran', 'not_allowed' === code( $r ) && 0 === traps( 'lang_upgrade' ) && array() === hrows(), code( $r ) );
site( array( 'read', 'manage_options', 'update_plugins' ) ); offer( array(), 1 );
check( 'translations: without update_core: refused', 'FORBIDDEN' === call( 'translations-update' ) && 0 === traps( 'lang_upgrade' ) );
site(); offer( array(), 1 );
check( 'translations: unknown input refused', 'invalid_input' === code( call( 'translations-update', array( 'locale' => 'de_DE' ) ) ) && 0 === traps( 'lang_upgrade' ) );

echo "no secrets\n";
site(); offer( array( '6.9.1' ), 1 );
$all = json_encode( array( call( 'core-status' ), call( 'core-update', array( 'version' => '6.9.1' ) ), call( 'translations-update' ), hrows() ) );
check( 'no download link, password, key or token, no store path in the results or the rows', 1 !== preg_match( '/downloads\.wordpress\.org|password|api_key|token|secret|d5b-history/i', $all ), $all );

echo "\n" . ( $failed ? "$failed of $n FAILED\n" : "all $n passed\n" );
exit( $failed ? 1 : 0 );
