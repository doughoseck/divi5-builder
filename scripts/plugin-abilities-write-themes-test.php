<?php
/**
 * Theme abilities (#32, release 2.4.0, Task 4): themes-status, themes-install, themes-switch, themes-update,
 * themes-rollback, themes-delete-preview and themes-delete.
 *
 *   php -d max_execution_time=60 scripts/plugin-abilities-write-themes-test.php
 *
 * Loads the real inc/ files and parts/updates.php (the update engine: wpsu_status, wpsu_update) with WordPress stand-ins
 * (plugin-test-stubs.php with T_PLUGIN_STANDINS: the theme part reads a real theme root under the system temp folder as
 * core does, keeps core's per-request theme cache until wp_clean_themes_cache, and has switch_theme / delete_theme /
 * themes_api / Theme_Upgrader as core 6.4.1 behaves). The page checks run for real (maint-common.php) against scripted
 * page answers (T_http_fn) that depend on the active theme and its version on disk. Set D5B_PLUGIN_DIR to test another
 * copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: the status read never clears anything and lists the kept theme copies; install takes only a
 * downloads.wordpress.org link as themes_api returned it (refused BEFORE the upgrader; a parent theme the upgrader fetches
 * goes through the same check), never over an installed folder, never switches; a switch to an installed, allowed, working
 * theme is checked and switched back when the pages got worse; the update keeps a copy first and puts it back on
 * rollback_on_failure; a rollback restores byte for byte and keeps what it replaces; the active theme and its parent are
 * never deleted (in_use), nor a theme enabled for the whole network; a delete needs the preview's code.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.4.0' );
define( 'T_PLUGIN_STANDINS', true );
define( 'WP_PLUGIN_DIR', str_replace( '\\', '/', sys_get_temp_dir() ) . '/d5b-wthm-' . getmypid() . '/plugins' );
define( 'D5B_FILE', WP_PLUGIN_DIR . '/divi5-builder/divi5-builder.php' );
require __DIR__ . '/plugin-test-stubs.php';
function wp_json_encode( $v ) { return json_encode( $v ); }

foreach ( array( 'inc/parts', 'inc/switches', 'inc/abilities/categories', 'inc/abilities/register', 'inc/abilities/secrets', 'inc/abilities/audit', 'inc/abilities/scrub', 'inc/abilities/history', 'inc/abilities/read-content', 'inc/abilities/read-site', 'inc/abilities/read-history', 'inc/abilities/write-common', 'inc/abilities/confirm', 'inc/abilities/maint-common', 'inc/abilities/write-plugins', 'inc/abilities/write-themes', 'parts/updates' ) as $f ) { require $dir . '/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 800 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ADMIN = array( 'read', 'manage_options', 'update_themes', 'install_themes', 'switch_themes', 'delete_themes' );
const CHILD = 'childt';   // the active theme, a child of PARENT
const PARENT_T = 'parentt';
const TWENTY = 'twentyx'; // an inactive theme with an update on offer
const XKID = 'xkid';      // an inactive child of TWENTY
const BROKEN = 'brokenkid'; // a child whose parent is not installed
const NEEDS = 'needsphp'; // Requires PHP 99.0

$T_BASE = dirname( WP_PLUGIN_DIR );
$GLOBALS['d5b_maint_seams'] = array(
	'now'   => function () { return $GLOBALS['T_clock']; },
	'sleep' => function ( $s ) { $GLOBALS['T_sleeps'][] = $s; $GLOBALS['T_clock'] += $s; },
	'rename' => function ( $from, $to ) {
		$GLOBALS['T_mrenames'][] = array( basename( $from ), basename( $to ), ! empty( $GLOBALS['T_in_maintenance'] ) );
		if ( '' !== ( $GLOBALS['T_rename_refuse_re'] ?? '' ) && 1 === preg_match( $GLOBALS['T_rename_refuse_re'], str_replace( '\\', '/', $from ) ) ) { return false; }
		return @rename( $from, $to );
	},
);

function t_rm( $d ) {
	if ( is_link( $d ) || is_file( $d ) ) { @unlink( $d ); return; }
	if ( ! file_exists( $d ) ) { return; }
	if ( ! is_dir( $d ) ) { @rmdir( $d ); return; }
	foreach ( array_diff( (array) scandir( $d ), array( '.', '..' ) ) as $f ) {
		$p = $d . '/' . $f;
		if ( is_link( $p ) || ( file_exists( $p ) && ! is_dir( $p ) && ! is_file( $p ) ) || realpath( $p ) !== realpath( $d ) . DIRECTORY_SEPARATOR . $f ) { @unlink( $p ) || @rmdir( $p ); continue; }
		t_rm( $p );
	}
	@rmdir( $d );
}
function t_link_dir( $target, $link ) {
	if ( @symlink( $target, $link ) ) { return true; }
	if ( '\\' !== DIRECTORY_SEPARATOR ) { return false; }
	exec( 'cmd /c mklink /J "' . str_replace( '/', '\\', $link ) . '" "' . str_replace( '/', '\\', $target ) . '" 2>&1', $o, $rc );
	clearstatcache();
	return 0 === $rc && file_exists( $link );
}
register_shutdown_function( function () use ( $T_BASE ) { t_rm( $T_BASE ); } );
function troot() { return dirname( WP_PLUGIN_DIR ) . '/themes'; }
function tree( $path ) {
	$out = array();
	if ( ! is_dir( $path ) ) { return $out; }
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) { $out[ str_replace( '\\', '/', substr( $f->getPathname(), strlen( $path ) + 1 ) ) ] = sha1_file( $f->getPathname() ); }
	ksort( $out );
	return $out;
}
// The version in a theme's style.css as it is on disk now (never through the theme cache).
function disk_version( $s ) { $c = (string) @file_get_contents( troot() . '/' . $s . '/style.css' ); return preg_match( '/^Version:(.*)$/mi', $c, $m ) ? trim( $m[1] ) : null; }
function page( $title = 'Home', $size = 50000 ) { $h = '<html><head><title>' . $title . '</title></head><body>'; return $h . str_repeat( 'x', max( 0, $size - strlen( $h ) - 14 ) ) . '</body></html>'; }
// The home page: core's critical error page when the active theme (stylesheet) is in T_breaks_theme, or when the active
// stylesheet or template is at a version listed in T_theme_breaks_at (read from disk, as WordPress would load it).
function serve_site() {
	$GLOBALS['T_http_fn'] = function ( $url, $args ) {
		if ( 'https://x.example/' !== preg_replace( '/[?&]d5b=[0-9]+$/', '', $url ) ) { return null; }
		$bad = array( 'code' => 500, 'body' => 'There has been a critical error on this website.' );
		if ( in_array( get_stylesheet(), $GLOBALS['T_breaks_theme'], true ) ) { return $bad; }
		foreach ( $GLOBALS['T_theme_breaks_at'] as $s => $v ) { if ( in_array( $s, array( get_stylesheet(), get_template() ), true ) && $v === disk_version( $s ) ) { return $bad; } }
		return array( 'code' => 200, 'body' => page() );
	};
}
function theme_offers( array $o ) { $t = new stdClass(); $t->last_checked = 1700000000; $t->response = array(); foreach ( $o as $s => $v ) { $t->response[ $s ] = array( 'theme' => $s, 'new_version' => $v, 'package' => 'https://downloads.wordpress.org/theme/' . $s . '.' . $v . '.zip' ); } return $t; }
function site( array $caps = ADMIN, bool $multi = false, bool $risky = true ) {
	global $T_BASE;
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( $multi );
	$GLOBALS['T_user_id'] = 3; $GLOBALS['T_user_login'] = 'admin'; $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
	d5b_abilities_set_category( 'themes', true, $risky, 'tester' );
	$GLOBALS['T_clock'] = 1700000000.0; $GLOBALS['T_sleeps'] = array();
	t_rm( $T_BASE ); mkdir( troot(), 0777, true ); mkdir( WP_PLUGIN_DIR, 0777, true ); mkdir( $T_BASE . '/uploads', 0777, true );
	$GLOBALS['T_upload_basedir'] = $T_BASE . '/uploads'; $GLOBALS['T_theme_root'] = troot(); $GLOBALS['T_themes_disk'] = true;
	t_theme_write( PARENT_T, array( 'Name' => 'Parent T', 'Version' => '3.0' ), array( 'functions.php' => '<?php // parent 3.0', 'css/main.css' => 'body{color:red}' ) );
	t_theme_write( CHILD, array( 'Name' => 'Child T', 'Version' => '1.0', 'Template' => PARENT_T ), array( 'functions.php' => '<?php // child 1.0' ) );
	t_theme_write( TWENTY, array( 'Name' => 'Twenty X', 'Version' => '1.0' ), array( 'readme.txt' => 'twenty readme 1.0', 'inc/x.php' => '<?php // x 1.0' ) );
	t_theme_write( XKID, array( 'Name' => 'X Kid', 'Version' => '0.5', 'Template' => TWENTY ) );
	t_theme_write( BROKEN, array( 'Name' => 'Broken Kid', 'Version' => '1.0', 'Template' => 'missingparent' ) );
	t_theme_write( NEEDS, array( 'Name' => 'Needs PHP', 'Version' => '1.0', 'RequiresPHP' => '99.0' ) );
	update_option( 'stylesheet', CHILD ); update_option( 'template', PARENT_T ); update_option( 'current_theme', 'Child T' );
	$GLOBALS['T_plugin_table'] = array();
	$GLOBALS['T_fresh']['update_themes'] = theme_offers( array( TWENTY => '1.1', CHILD => '1.1', PARENT_T => '3.1' ) );
	$GLOBALS['T_breaks_theme'] = array(); $GLOBALS['T_theme_breaks_at'] = array();
	$GLOBALS['T_mrenames'] = array(); $GLOBALS['T_rename_refuse_re'] = '';
	serve_site();
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function edata( $r ) { return is_wp_error( $r ) ? $r->get_error_data() : null; }
function msg( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : $r; }
function hrows() { global $wpdb; return array_values( $wpdb->tables[ d5b_history_table() ] ?? array() ); }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function row( $id ) { return d5b_history_get( (int) $id ); }
function kept_path( $id ) { return (string) ( row( $id )['after']['kept']['path'] ?? '' ); }
// A kept copy as if it had been kept the way it is now (its file list and fingerprint made again from the files).
function reseal( $id ) { $a = row( $id )['after']; $a['kept']['manifest_sha1'] = d5b_maint_manifest_keep( $a['kept']['path'] ); d5b_history_set_after( (int) $id, $a ); }
function traps( $name ) { return count( array_filter( $GLOBALS['T_traps'], function ( $t ) use ( $name ) { return $t === $name; } ) ); }
function dots() { return array_values( preg_grep( '/^\.d5b-/', (array) scandir( troot() ) ) ); }
function store_dirs() { global $T_BASE; return array_values( array_diff( (array) @scandir( $T_BASE . '/uploads/d5b-history' ), array( '.', '..', '.htaccess', 'index.html' ) ) ); }

// ---- registration
echo "registration\n";
site();
$defs = d5b_ability_defs();
$names = array( 'themes-status', 'themes-install', 'themes-switch', 'themes-update', 'themes-rollback', 'themes-delete-preview', 'themes-delete' );
$d = array(); foreach ( $names as $nm ) { $d[ $nm ] = $defs[ 'divi5-builder/' . $nm ] ?? null; }
check( 'all seven exist, in the themes category', ! in_array( null, $d, true ) && array( 'themes' ) === array_values( array_unique( array_map( function ( $x ) { return $x['category'] ?? ''; }, $d ) ) ) );
// A read carries no annotations of its own: the registration gives every definition without them readonly true.
check( 'themes-status: a read (no risky key, no annotations of its own, so readonly true at registration)', ! array_key_exists( 'risky', (array) $d['themes-status'] ) && ! array_key_exists( 'annotations', (array) $d['themes-status'] ) && false === ( $d['themes-update']['annotations']['readonly'] ?? null ), $d['themes-status'] );
check( 'the six writes are risky', array( true, true, true, true, true, true ) === array_map( function ( $nm ) use ( $d ) { return $d[ $nm ]['risky'] ?? null; }, array_slice( $names, 1 ) ) );
check( 'inputs: install slug; switch stylesheet; update stylesheet, rollback_on_failure, without_copy; rollback stylesheet, history_id, force_older; delete(-preview) stylesheet, without_copy (+ confirmation); no keep_data',
	array( 'slug' ) === array_keys( $d['themes-install']['input_schema']['properties'] ) && array( 'stylesheet' ) === array_keys( $d['themes-switch']['input_schema']['properties'] )
	&& array( 'stylesheet', 'rollback_on_failure', 'without_copy' ) === array_keys( $d['themes-update']['input_schema']['properties'] ) && array( 'stylesheet' ) === $d['themes-update']['input_schema']['required']
	&& array( 'stylesheet', 'history_id', 'force_older' ) === array_keys( $d['themes-rollback']['input_schema']['properties'] ) && array( 'stylesheet', 'history_id' ) === $d['themes-rollback']['input_schema']['required']
	&& array( 'stylesheet', 'without_copy' ) === array_keys( $d['themes-delete-preview']['input_schema']['properties'] ) && array( 'stylesheet', 'without_copy', 'confirmation' ) === array_keys( $d['themes-delete']['input_schema']['properties'] ),
	array_map( function ( $x ) { return array_keys( $x['input_schema']['properties'] ?? array() ); }, $d ) );
check( 'delete-preview readonly, delete destructive', true === ( $d['themes-delete-preview']['annotations']['readonly'] ?? null ) && true === ( $d['themes-delete']['annotations']['destructive'] ?? null ) );
check( 'the new codes have their own HTTP status (not 500)', 500 !== d5b_ability_http_status( 'in_use' ) && 500 !== d5b_ability_http_status( 'switch_failed' ) );

// ---- themes-status
echo "themes-status\n";
site();
$GLOBALS['T_site_transients']['update_themes'] = theme_offers( array( TWENTY => '1.1' ) );
$r = call( 'themes-status' );
$tw = null; foreach ( (array) ( $r['themes'] ?? array() ) as $t ) { if ( TWENTY === $t['item'] ) { $tw = $t; } }
$active = array_values( array_map( function ( $t ) { return $t['item']; }, array_filter( (array) ( $r['themes'] ?? array() ), function ( $t ) { return $t['active']; } ) ) );
check( 'status: the stored list (twentyx 1.0 -> 1.1), the active theme and its parent active, kept_copies empty', is_array( $r ) && null !== $tw && '1.0' === $tw['version'] && '1.1' === $tw['new_version'] && array( CHILD, PARENT_T ) === array_values( array_intersect( array( CHILD, PARENT_T ), $active ) ) && 2 === count( $active ) && array() === $r['kept_copies'], $r );
check( 'status: no plugin list (a themes read needs update_themes only), no package url', ! isset( $r['plugins'] ) && false === strpos( json_encode( $r ), 'downloads.wordpress.org' ), array_keys( (array) $r ) );
check( 'status never clears a transient and never asks wordpress.org; no row', array() === $GLOBALS['T_site_transient_deletes'] && array() === $GLOBALS['T_update_checks'] && array() === hrows() );
$k1 = d5b_wc_history_row( 'divi5-builder/themes-update', 'theme', 0, 'update', array( 'stylesheet' => TWENTY, 'version' => '0.9' ), array( 'version' => '1.0', 'kept' => array( 'path' => '/x/twentyx', 'bytes' => 10, 'files' => 1 ) ), 'Updated' );
d5b_wc_history_row( 'divi5-builder/themes-update', 'theme', 0, 'update', array( 'stylesheet' => CHILD, 'version' => '0.9' ), array( 'version' => '1.0', 'kept' => null ), 'Updated without copy' );
d5b_wc_history_row( 'divi5-builder/plugins-update', 'plugin', 0, 'update', array( 'plugin' => 'a/a.php', 'version' => '1' ), array( 'version' => '2', 'kept' => array( 'path' => '/x/a', 'bytes' => 1, 'files' => 1 ) ), 'Plugin' );
d5b_wc_history_row( 'divi5-builder/themes-switch', 'theme', 0, 'switch', array( 'stylesheet' => CHILD, 'template' => PARENT_T ), array( 'kept' => array( 'path' => '/x/no' ) ), 'Switch' );
$r = call( 'themes-status' );
check( 'kept_copies: only theme update/rollback/delete rows with kept, as { stylesheet, version, history_id, kept_at }; never the path', array( array( 'stylesheet' => TWENTY, 'version' => '0.9', 'history_id' => $k1, 'kept_at' => d5b_wc_iso( (string) row( $k1 )['created_at'] ) ) ) === $r['kept_copies'] && false === strpos( json_encode( $r ), '/x/' ), $r['kept_copies'] );
site( array( 'read', 'manage_options', 'update_plugins' ) );
check( 'without update_themes: refused', 'FORBIDDEN' === call( 'themes-status' ) );
site();
check( 'unknown input refused', 'invalid_input' === code( call( 'themes-status', array( 'refresh' => true ) ) ) );

// ---- themes-install
echo "themes-install\n";
function astra( $link = 'https://downloads.wordpress.org/theme/astra.4.6.zip' ) {
	$GLOBALS['T_themes_api']['astra'] = (object) array( 'slug' => 'astra', 'name' => 'Astra', 'version' => '4.6', 'download_link' => $link );
	$GLOBALS['T_theme_install_adds'] = array( 'astra' => array( 'Name' => 'Astra', 'Version' => '4.6' ) );
}
site(); astra();
$r = call( 'themes-install', array( 'slug' => 'astra' ) );
check( 'install: installed, the stylesheet and version, not active (never switches)', is_array( $r ) && true === $r['installed'] && 'astra' === $r['stylesheet'] && '4.6' === $r['version'] && false === $r['active'] && CHILD === get_stylesheet() && array() === $GLOBALS['T_switch_calls'] && '4.6' === disk_version( 'astra' ), $r );
check( '... themes_api asked for theme_information of that slug, THEN install() with the link exactly as returned', array( 'themes_api', 'theme_install' ) === $GLOBALS['T_traps'] && 'theme_information' === $GLOBALS['T_themes_api_calls'][0][0] && 'astra' === ( (object) $GLOBALS['T_themes_api_calls'][0][1] )->slug && array( 'https://downloads.wordpress.org/theme/astra.4.6.zip' ) === $GLOBALS['T_theme_install_calls'], $GLOBALS['T_traps'] );
$h = hlast();
check( '... one history row: theme, install, after { stylesheet, version }; the link in neither the result nor the row', 1 === count( hrows() ) && 'theme' === $h['object_type'] && 'install' === $h['action'] && 'divi5-builder/themes-install' === $h['ability'] && array( 'stylesheet' => 'astra', 'version' => '4.6' ) === $h['after'] && false === strpos( json_encode( array( $r, $h ) ), 'downloads.wordpress.org' ), $h );
foreach ( array(
	'http (not https)'      => 'http://downloads.wordpress.org/theme/astra.4.6.zip',
	'another host'          => 'https://evil.example/astra.zip',
	'a look-alike host'     => 'https://downloads.wordpress.org.evil.example/astra.zip',
	'a user in the url'     => 'https://downloads.wordpress.org@evil.example/x.zip',
	'no link'               => null,
) as $what => $link ) {
	site(); astra( $link );
	$r = call( 'themes-install', array( 'slug' => 'astra' ) );
	check( 'install: non-wordpress.org link refused before install() (trap order): ' . $what, 'bad_source' === code( $r ) && array( 'themes_api' ) === $GLOBALS['T_traps'] && array() === hrows() && ! is_dir( troot() . '/astra' ), array( code( $r ), $GLOBALS['T_traps'] ) );
}
site(); astra();
$r = call( 'themes-install', array( 'slug' => TWENTY ) );
check( 'install: a slug whose folder is installed is refused (exists), wordpress.org not even asked', 'exists' === code( $r ) && array() === $GLOBALS['T_traps'] && array() === hrows(), code( $r ) );
check( '... also the active theme', 'exists' === code( call( 'themes-install', array( 'slug' => CHILD ) ) ) && array() === $GLOBALS['T_traps'] );
mkdir( troot() . '/emptyfolder' );
check( '... and a folder of that name without a theme in it', 'exists' === code( call( 'themes-install', array( 'slug' => 'emptyfolder' ) ) ) && array() === $GLOBALS['T_traps'] );
site();
check( 'install: wordpress.org does not know the slug: not_found', 'not_found' === code( call( 'themes-install', array( 'slug' => 'no-such-theme' ) ) ) && array( 'themes_api' ) === $GLOBALS['T_traps'] && array() === hrows() );
site(); $GLOBALS['T_themes_api']['busy'] = new WP_Error( 'themes_api_failed', 'An unexpected error occurred.' );
check( 'install: wordpress.org unreachable: unavailable', 'unavailable' === code( call( 'themes-install', array( 'slug' => 'busy' ) ) ) );
foreach ( array( '../twentyx', 'Astra', 'a/b', 'x y', str_repeat( 'a', 101 ) ) as $bad ) {
	site(); astra();
	check( 'install: slug ' . json_encode( substr( $bad, 0, 20 ) ) . ' refused as invalid_input, nothing asked', 'invalid_input' === code( call( 'themes-install', array( 'slug' => $bad ) ) ) && array() === $GLOBALS['T_traps'] );
}
site(); astra(); $GLOBALS['T_theme_install_result'] = new WP_Error( 'folder_exists', 'Destination folder already exists.' );
$r = call( 'themes-install', array( 'slug' => 'astra' ) );
check( 'install: the upgrader answers WP_Error: install_failed with its message; the row says failed', 'install_failed' === code( $r ) && false !== strpos( msg( $r ), 'Destination folder already exists' ) && true === ( hlast()['after']['failed'] ?? null ), msg( $r ) );
site(); astra(); $GLOBALS['T_file_mods_off'] = true;
check( 'install: file changes switched off: not_allowed, nothing asked', 'not_allowed' === code( call( 'themes-install', array( 'slug' => 'astra' ) ) ) && array() === $GLOBALS['T_traps'] );
site( ADMIN, false, false ); astra();
check( 'install: the Themes risky switch off: refused', 'FORBIDDEN' === call( 'themes-install', array( 'slug' => 'astra' ) ) && array() === $GLOBALS['T_traps'] );
site( array( 'read', 'manage_options', 'switch_themes' ) ); astra();
check( 'install: without install_themes: refused', 'FORBIDDEN' === call( 'themes-install', array( 'slug' => 'astra' ) ) );
site( ADMIN, true ); astra(); $GLOBALS['T_user_login'] = 'siteadmin';
check( 'install: multisite, not a network administrator: refused', 'FORBIDDEN' === call( 'themes-install', array( 'slug' => 'astra' ) ) && array() === $GLOBALS['T_traps'] );
site( ADMIN, true ); astra();
check( 'install: multisite, a network administrator: installed', is_array( call( 'themes-install', array( 'slug' => 'astra' ) ) ) && 1 === traps( 'theme_install' ) );
// A child theme: core's upgrader fetches its missing parent from themes_api; that download goes through the same check.
function kid( $parent_link ) {
	$GLOBALS['T_themes_api']['kidtheme'] = (object) array( 'slug' => 'kidtheme', 'version' => '1.0', 'download_link' => 'https://downloads.wordpress.org/theme/kidtheme.1.0.zip' );
	$GLOBALS['T_themes_api']['momtheme'] = (object) array( 'slug' => 'momtheme', 'version' => '2.0', 'download_link' => $parent_link );
	$GLOBALS['T_theme_install_adds'] = array( 'kidtheme' => array( 'Name' => 'Kid', 'Version' => '1.0', 'Template' => 'momtheme' ) );
	$GLOBALS['T_theme_parent_adds'] = array( 'momtheme' => array( 'Name' => 'Mom', 'Version' => '2.0' ) );
}
site(); kid( 'https://evil.example/momtheme.zip' );
$r = call( 'themes-install', array( 'slug' => 'kidtheme' ) );
check( 'install a child: the parent link the upgrader fetches is checked too (not on downloads.wordpress.org: not downloaded); the result says the parent is missing', is_array( $r ) && 'kidtheme' === $r['stylesheet'] && 'momtheme' === ( $r['parent'] ?? null ) && false === ( $r['parent_installed'] ?? null ) && ! is_dir( troot() . '/momtheme' ) && array( 'https://downloads.wordpress.org/theme/kidtheme.1.0.zip' ) === $GLOBALS['T_theme_downloads'] && ! empty( $r['warnings'] ), $r );
check( '... the check is removed again after the install (no upgrader_pre_download filter left)', array() === array_values( (array) ( $GLOBALS['T_filters']['upgrader_pre_download'] ?? array() ) ) );
site(); kid( 'https://downloads.wordpress.org/theme/momtheme.2.0.zip' );
$r = call( 'themes-install', array( 'slug' => 'kidtheme' ) );
check( '... with a wordpress.org parent link: the parent is installed too', is_array( $r ) && true === ( $r['parent_installed'] ?? null ) && '2.0' === disk_version( 'momtheme' ) && ! isset( $r['warnings'] ), $r );

// ---- themes-switch
echo "themes-switch\n";
site();
$r = call( 'themes-switch', array( 'stylesheet' => TWENTY ) );
check( 'switch: pages fine -> switched (stylesheet and template twentyx), reverted false, no problems', is_array( $r ) && true === $r['active'] && false === $r['reverted'] && array() === $r['problems'] && TWENTY === get_stylesheet() && TWENTY === get_template() && TWENTY === $r['stylesheet'] && 'Twenty X' === get_option( 'current_theme' ), $r );
check( '... switch_theme( twentyx ) once; the result carries pages (both rounds), previous and human_check', array( TWENTY ) === $GLOBALS['T_switch_calls'] && 1 === count( $r['pages']['before'] ) && 1 === count( $r['pages']['after'] ) && array( 'stylesheet' => CHILD, 'template' => PARENT_T ) === $r['previous'] && d5b_human_check() === $r['human_check'], $r );
$h = hlast();
check( '... one row written first: theme, switch, before { stylesheet, template } of the previous theme; after the theme now, pages, reverted', 1 === count( hrows() ) && 'theme' === $h['object_type'] && 'switch' === $h['action'] && 'divi5-builder/themes-switch' === $h['ability'] && array( 'stylesheet' => CHILD, 'template' => PARENT_T ) === $h['before'] && TWENTY === $h['after']['stylesheet'] && TWENTY === $h['after']['template'] && false === $h['after']['reverted'] && isset( $h['after']['pages']['after'] ), $h );

site(); $GLOBALS['T_breaks_theme'] = array( TWENTY );
$r = call( 'themes-switch', array( 'stylesheet' => TWENTY ) );
check( 'switch: pages worse -> switched back (childt / parentt again), reverted true, the problem named', is_array( $r ) && true === $r['reverted'] && false === $r['active'] && CHILD === get_stylesheet() && PARENT_T === get_template() && 1 === count( $r['problems'] ) && false !== strpos( $r['problems'][0], 'answered 500' ), $r );
check( '... switch_theme( twentyx ), then switch_theme( childt )', array( TWENTY, CHILD ) === $GLOBALS['T_switch_calls'], $GLOBALS['T_switch_calls'] );
$h = hlast();
check( '... the row: reverted true, both rounds, after = the theme back', true === $h['after']['reverted'] && CHILD === $h['after']['stylesheet'] && PARENT_T === $h['after']['template'] && 1 === count( $h['after']['pages']['problems'] ) && d5b_human_check() === $r['human_check'], $h );

site(); $GLOBALS['T_plugin_hooks']['switch_theme'][] = function () { echo 'oops'; throw new RuntimeException( 'theme hook broke' ); }; $level = ob_get_level();
$r = call( 'themes-switch', array( 'stylesheet' => TWENTY ) );
check( 'switch: a switch_theme hook that throws -> switch_failed, the previous theme back, no page round after, buffers closed', 'switch_failed' === code( $r ) && CHILD === get_stylesheet() && PARENT_T === get_template() && true === edata( $r )['reverted'] && 1 === count( $GLOBALS['T_http_calls'] ) && $level === ob_get_level() && false === strpos( msg( $r ), 'theme hook broke' ) && d5b_human_check() === edata( $r )['human_check'], array( code( $r ), msg( $r ), edata( $r ), get_stylesheet() ) );

site(); $GLOBALS['T_http_fn'] = function () { return null; };
$r = call( 'themes-switch', array( 'stylesheet' => TWENTY ) );
check( 'switch: the site cannot fetch its own pages -> stays switched, pages unavailable, a warning', is_array( $r ) && TWENTY === get_stylesheet() && true === $r['pages']['unavailable'] && ! empty( $r['warnings'] ), $r );

site();
check( 'switch: not installed -> not_found, nothing switched, no row', 'not_found' === code( call( 'themes-switch', array( 'stylesheet' => 'nope' ) ) ) && array() === $GLOBALS['T_switch_calls'] && array() === hrows() );
check( 'switch: another case of an installed name (TwentyX) -> not_found (only the name as listed)', 'not_found' === code( call( 'themes-switch', array( 'stylesheet' => 'TwentyX' ) ) ) && array() === $GLOBALS['T_switch_calls'] );
$r = call( 'themes-switch', array( 'stylesheet' => BROKEN ) );
check( 'switch: a broken theme (its parent is missing) -> not_allowed, nothing switched', 'not_allowed' === code( $r ) && array() === $GLOBALS['T_switch_calls'] && CHILD === get_stylesheet(), msg( $r ) );
$r = call( 'themes-switch', array( 'stylesheet' => NEEDS ) );
check( 'switch: requirements not met -> not_allowed BEFORE switch_theme (which would end the request with wp_die)', 'not_allowed' === code( $r ) && array() === $GLOBALS['T_switch_calls'] && array() === $GLOBALS['T_dies'] && false !== strpos( msg( $r ), 'PHP' ), msg( $r ) );
$r = call( 'themes-switch', array( 'stylesheet' => CHILD ) );
check( 'switch: already active -> already_active, nothing called, no row', is_array( $r ) && true === ( $r['already_active'] ?? null ) && array() === $GLOBALS['T_switch_calls'] && array() === hrows(), $r );
foreach ( array( '../twentyx', 'twentyx/style.css', '.d5b-old-x', "twentyx\0", ' twentyx' ) as $bad ) {
	check( 'switch: ' . json_encode( $bad ) . ' is invalid_input', 'invalid_input' === code( call( 'themes-switch', array( 'stylesheet' => $bad ) ) ) && array() === $GLOBALS['T_switch_calls'] );
}
site( ADMIN, false, false );
check( 'switch: the Themes risky switch off: refused', 'FORBIDDEN' === call( 'themes-switch', array( 'stylesheet' => TWENTY ) ) && array() === $GLOBALS['T_switch_calls'] );
site( array( 'read', 'manage_options', 'install_themes' ) );
check( 'switch: without switch_themes: refused', 'FORBIDDEN' === call( 'themes-switch', array( 'stylesheet' => TWENTY ) ) );

echo "multisite\n";
site( ADMIN, true ); $GLOBALS['T_user_login'] = 'siteadmin';
$r = call( 'themes-switch', array( 'stylesheet' => TWENTY ) );
check( 'multisite: a theme not allowed on this site refused (not_allowed), nothing switched', 'not_allowed' === code( $r ) && array() === $GLOBALS['T_switch_calls'] && CHILD === get_stylesheet(), code( $r ) );
update_option( 'allowedthemes', array( TWENTY => true ) );
check( 'multisite: allowed for this site: a site administrator switches it', is_array( call( 'themes-switch', array( 'stylesheet' => TWENTY ) ) ) && TWENTY === get_stylesheet() );
site( ADMIN, true ); $GLOBALS['T_user_login'] = 'siteadmin'; update_site_option( 'allowedthemes', array( TWENTY => true ) );
check( 'multisite: enabled for the network: switched', is_array( call( 'themes-switch', array( 'stylesheet' => TWENTY ) ) ) && TWENTY === get_stylesheet() );

// ---- themes-update
echo "themes-update\n";
site();
$old = tree( troot() . '/' . TWENTY );
$r = call( 'themes-update', array( 'stylesheet' => TWENTY ) );
$hid = (int) ( $r['history_id'] ?? 0 );
check( 'update: updated 1.0 -> 1.1, the version read from the folder', is_array( $r ) && true === $r['updated'] && '1.0' === $r['from'] && '1.1' === $r['to'] && '1.1' === $r['version'] && '1.1' === disk_version( TWENTY ) && TWENTY === $r['stylesheet'], $r );
check( 'update: copy before the upgrader (copy_dir ran before bulk_upgrade); the kept copy holds the OLD files byte for byte', null !== $GLOBALS['T_copies_at_upgrade'] && $GLOBALS['T_copies_at_upgrade'] >= 1 && array( array( TWENTY ) ) === $GLOBALS['T_theme_upgrade_calls'] && $old === tree( kept_path( $hid ) ) && 1 === preg_match( '#/d5b-history/' . $hid . '-[0-9a-f]{32}/theme/' . TWENTY . '$#', kept_path( $hid ) ), kept_path( $hid ) );
$h = row( $hid );
check( '... one row: theme, update, before { stylesheet, version }, after { version, kept, pages, rolled_back }', 1 === count( hrows() ) && 'theme' === $h['object_type'] && 'divi5-builder/themes-update' === $h['ability'] && array( 'stylesheet' => TWENTY, 'version' => '1.0' ) === $h['before'] && '1.1' === $h['after']['version'] && is_array( $h['after']['kept'] ) && false === $h['after']['rolled_back'], $h );
check( '... the result: kept without its path, pages, human_check', array( 'bytes', 'files' ) === array_keys( (array) $r['kept'] ) && 4 === $r['kept']['files'] && false === strpos( json_encode( $r ), 'd5b-history' ) && d5b_human_check() === $r['human_check'], $r );
$st = call( 'themes-status' );
check( '... themes-status lists the kept copy (twentyx 1.0)', array( 'stylesheet' => TWENTY, 'version' => '1.0', 'history_id' => $hid ) === array_intersect_key( (array) ( $st['kept_copies'][0] ?? array() ), array_flip( array( 'stylesheet', 'version', 'history_id' ) ) ), $st['kept_copies'] ?? null );
site();
$r = call( 'themes-update', array( 'stylesheet' => CHILD ) );
check( 'update the active child theme: updated, still active', is_array( $r ) && true === $r['updated'] && '1.1' === disk_version( CHILD ) && CHILD === get_stylesheet() && PARENT_T === get_template(), $r );

site(); $GLOBALS['T_copy_fail'] = true;
$r = call( 'themes-update', array( 'stylesheet' => TWENTY ) );
check( 'update: copy error refuses and removes the row (copy_failed, no upgrade, still 1.0, no store folder left)', 'copy_failed' === code( $r ) && array() === $GLOBALS['T_theme_upgrade_calls'] && '1.0' === disk_version( TWENTY ) && array() === hrows() && array() === store_dirs(), array( code( $r ), store_dirs() ) );
site(); $GLOBALS['T_copy_fail'] = true;
$r = call( 'themes-update', array( 'stylesheet' => TWENTY, 'without_copy' => true ) );
check( 'update: without_copy proceeds with kept null (row and result), copy_error, a warning it cannot be put back', is_array( $r ) && true === $r['updated'] && null === $r['kept'] && '1.1' === disk_version( TWENTY ) && null === hlast()['after']['kept'] && 'copy_failed' === ( $r['copy_error'] ?? null ) && 1 === preg_match( '/cannot put/i', implode( ' ', (array) ( $r['warnings'] ?? array() ) ) ), $r );

site(); $GLOBALS['T_theme_breaks_at'] = array( CHILD => '1.1' );
$old = tree( troot() . '/' . CHILD );
$r = call( 'themes-update', array( 'stylesheet' => CHILD, 'rollback_on_failure' => true ) );
check( 'update: rollback_on_failure restores at once (pages worse -> the old files back byte for byte, rolled_back, still active)', is_array( $r ) && true === $r['rolled_back'] && '1.0' === $r['version'] && $old === tree( troot() . '/' . CHILD ) && 1 === count( $r['problems'] ) && CHILD === get_stylesheet() && array() === dots() && array() === ( $r['pages_after_restore']['problems'] ?? null ), $r );
$rh = row( $r['rollback_history_id'] ?? 0 );
check( '... a rollback row (themes-rollback) keeps the 1.1 files it replaced', 'rollback' === ( $rh['action'] ?? null ) && 'divi5-builder/themes-rollback' === $rh['ability'] && 'theme' === $rh['object_type'] && CHILD === $rh['before']['stylesheet'] && '1.1' === $rh['before']['version'] && false !== strpos( (string) @file_get_contents( $rh['after']['kept']['path'] . '/style.css' ), 'Version: 1.1' ), $rh );
site(); $GLOBALS['T_theme_upgrade_result'] = array( TWENTY => 'wipe' );
$old = tree( troot() . '/' . TWENTY );
$r = call( 'themes-update', array( 'stylesheet' => TWENTY, 'rollback_on_failure' => true ) );
check( 'update: rollback_on_failure after a FAILED update (the folder removed, then failed): update_failed, the old files back', 'update_failed' === code( $r ) && true === ( edata( $r )['rolled_back'] ?? null ) && $old === tree( troot() . '/' . TWENTY ) && array() === dots(), array( code( $r ), edata( $r ) ) );
site(); $GLOBALS['T_theme_breaks_at'] = array( CHILD => '1.1' );
$r = call( 'themes-update', array( 'stylesheet' => CHILD ) );
check( 'update: by default the new version stays (problems reported, rolled_back false)', is_array( $r ) && false === $r['rolled_back'] && '1.1' === disk_version( CHILD ) && 1 === count( $r['problems'] ), $r );
site(); $GLOBALS['T_theme_upgrade_result'] = array( TWENTY => new WP_Error( 'download_failed', 'Download failed.' ) );
$r = call( 'themes-update', array( 'stylesheet' => TWENTY ) );
check( 'update: the upgrader fails -> update_failed with the reason; the row keeps the copy', 'update_failed' === code( $r ) && false !== strpos( msg( $r ), 'Download failed' ) && is_array( hlast()['after']['kept'] ) && d5b_human_check() === ( edata( $r )['human_check'] ?? null ), msg( $r ) );
site();
$r = call( 'themes-update', array( 'stylesheet' => XKID ) );
check( 'update: nothing offered -> already_up_to_date, no row, no copy, no upgrade', is_array( $r ) && true === ( $r['already_up_to_date'] ?? null ) && array() === hrows() && array() === $GLOBALS['T_copy_calls'] && array() === $GLOBALS['T_theme_upgrade_calls'], $r );
check( 'update: not installed -> not_found', 'not_found' === code( call( 'themes-update', array( 'stylesheet' => 'nope' ) ) ) && array() === hrows() );
site( ADMIN, false, false );
check( 'update: the Themes risky switch off: refused', 'FORBIDDEN' === call( 'themes-update', array( 'stylesheet' => TWENTY ) ) && array() === $GLOBALS['T_theme_upgrade_calls'] );
site( array( 'read', 'manage_options', 'switch_themes', 'delete_themes' ) );
check( 'update: without update_themes: refused', 'FORBIDDEN' === call( 'themes-update', array( 'stylesheet' => TWENTY ) ) );
site( ADMIN, true ); $GLOBALS['T_user_login'] = 'siteadmin';
check( 'update: multisite, not a network administrator: refused', 'FORBIDDEN' === call( 'themes-update', array( 'stylesheet' => TWENTY ) ) && array() === $GLOBALS['T_theme_upgrade_calls'] );
site(); $GLOBALS['T_file_mods_off'] = true;
check( 'update: file changes switched off: not_allowed, no row', 'not_allowed' === code( call( 'themes-update', array( 'stylesheet' => TWENTY ) ) ) && array() === hrows() );

// ---- themes-rollback
echo "themes-rollback\n";
site();
$v10 = tree( troot() . '/' . TWENTY );
$u1 = (int) call( 'themes-update', array( 'stylesheet' => TWENTY ) )['history_id'];
$v11 = tree( troot() . '/' . TWENTY ); $nrows = count( hrows() );
check( 'rollback: a row of another theme -> not_found, nothing changed', 'not_found' === code( call( 'themes-rollback', array( 'stylesheet' => XKID, 'history_id' => $u1 ) ) ) && $v11 === tree( troot() . '/' . TWENTY ) && $nrows === count( hrows() ) );
$sw = d5b_wc_history_row( 'divi5-builder/themes-switch', 'theme', 0, 'switch', array( 'stylesheet' => TWENTY ), array( 'kept' => array( 'path' => kept_path( $u1 ) ) ), 'x' );
check( 'rollback: a row that is not an update/rollback/delete -> not_found', 'not_found' === code( call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $sw ) ) ) );
$r = call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $u1 ) );
check( 'rollback: restores byte-identical files (1.0 back)', is_array( $r ) && $v10 === tree( troot() . '/' . TWENTY ) && '1.0' === $r['version'] && '1.1' === $r['replaced_version'] && $u1 === $r['restored_from'] && TWENTY === $r['stylesheet'], $r );
$rh = row( $r['history_id'] ?? 0 );
check( 'rollback: the replaced folder kept in the new row (before 1.1, after.kept = the 1.1 files byte for byte), pages, human_check, no dot folder', 'rollback' === $rh['action'] && array( 'stylesheet' => TWENTY, 'version' => '1.1', 'from_history_id' => $u1 ) === $rh['before'] && $v11 === tree( $rh['after']['kept']['path'] ) && isset( $rh['after']['pages']['after'] ) && d5b_human_check() === $r['human_check'] && false === $r['active'] && array() === dots(), $rh );
check( 'rollback: an older copy than the newest kept for that theme -> newer_copy_exists; force_older puts it back', 'newer_copy_exists' === code( call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $u1 ) ) ) && is_array( call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $u1, 'force_older' => true ) ) ) && $v10 === tree( troot() . '/' . TWENTY ) );
site();
$c0 = tree( troot() . '/' . CHILD );
$uc = (int) call( 'themes-update', array( 'stylesheet' => CHILD ) )['history_id'];
$r = call( 'themes-rollback', array( 'stylesheet' => CHILD, 'history_id' => $uc ) );
check( 'rollback of the active theme: back to 1.0 byte for byte, still active', is_array( $r ) && $c0 === tree( troot() . '/' . CHILD ) && true === $r['active'] && CHILD === get_stylesheet(), $r );
site();
$u1 = (int) call( 'themes-update', array( 'stylesheet' => TWENTY ) )['history_id'];
t_rm( kept_path( $u1 ) );
check( 'rollback: the copy is gone -> copy_missing, nothing changed', 'copy_missing' === code( call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $u1 ) ) ) && '1.1' === disk_version( TWENTY ) && 1 === count( hrows() ) );
site(); $GLOBALS['T_copy_fail'] = true;
$u1 = (int) call( 'themes-update', array( 'stylesheet' => TWENTY, 'without_copy' => true ) )['history_id']; $GLOBALS['T_copy_fail'] = false;
check( 'rollback: an update row that kept no copy -> not_found', 'not_found' === code( call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $u1 ) ) ) );
$tampered = d5b_wc_history_row( 'divi5-builder/themes-update', 'theme', 0, 'update', array( 'stylesheet' => TWENTY, 'version' => '0.1' ), array( 'version' => '1.1', 'kept' => array( 'path' => troot() . '/' . XKID, 'bytes' => 1, 'files' => 1 ) ), 'x' );
check( 'rollback: a row whose kept path is not in the store -> copy_missing', 'copy_missing' === code( call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $tampered, 'force_older' => true ) ) ) && '1.1' === disk_version( TWENTY ) );
site();
$u1 = (int) call( 'themes-update', array( 'stylesheet' => TWENTY ) )['history_id'];
unlink( kept_path( $u1 ) . '/style.css' ); // the kept copy no longer holds the theme's style.css
$now = tree( troot() . '/' . TWENTY );
$r = call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $u1 ) );
check( 'M1 a kept copy changed after it was made (style.css removed) -> copy_tampered, nothing touched', 'copy_tampered' === code( $r ) && 1 === ( edata( $r )['removed'] ?? null ) && $now === tree( troot() . '/' . TWENTY ) && 1 === count( hrows() ), array( code( $r ), edata( $r ) ) );
reseal( $u1 ); // as if it had been kept that way
$r = call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $u1 ) );
check( 'rollback: the theme does not resolve after the swap -> restore_mismatch, the swap undone (1.1 files back)', 'restore_mismatch' === code( $r ) && $now === tree( troot() . '/' . TWENTY ) && '1.1' === disk_version( TWENTY ) && array() === dots(), array( code( $r ), msg( $r ) ) );
$flags = array_map( function ( $h ) { return ! empty( row( $h['id'] )['after']['restore_mismatch'] ); }, array_values( array_filter( hrows(), function ( $h ) { return 'rollback' === $h['action']; } ) ) );
$kc = call( 'themes-status' )['kept_copies'] ?? null;
check( 'M8 themes: both rollback rows flagged; kept_copies offers the update row only; a retry is not newer_copy_exists', array( true, true ) === $flags && is_array( $kc ) && 1 === count( $kc ) && $u1 === (int) $kc[0]['history_id'] && 'restore_mismatch' === code( call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $u1 ) ) ), array( $flags, $kc ) );
// Readable is as WordPress reads the theme (WP_Theme::errors), not "has a version".
site();
@mkdir( troot() . '/noversion', 0777, true ); file_put_contents( troot() . '/noversion/style.css', "/*\nTheme Name: No Version\n*/\n" ); file_put_contents( troot() . '/noversion/index.php', '<?php' );
t_theme_write( 'orphan', array( 'Name' => 'Orphan', 'Version' => '1.0', 'Template' => 'gone' ) );
@mkdir( troot() . '/nostyle', 0777, true ); file_put_contents( troot() . '/nostyle/index.php', '<?php' );
check( 'readable theme check: a style.css without a Version is readable, a missing parent is not the copy\'s fault, no style.css is unreadable, no folder is unreadable', '' === d5b_wthm_unreadable( 'noversion' ) && '' === d5b_wthm_unreadable( 'orphan' ) && '' !== d5b_wthm_unreadable( 'nostyle' ) && '' !== d5b_wthm_unreadable( 'nosuchtheme' ), array( d5b_wthm_unreadable( 'noversion' ), d5b_wthm_unreadable( 'nostyle' ) ) );
$GLOBALS['T_fresh']['update_themes'] = theme_offers( array( 'noversion' => '2.0' ) );
$un = call( 'themes-update', array( 'stylesheet' => 'noversion' ) );
$r  = is_array( $un ) ? call( 'themes-rollback', array( 'stylesheet' => 'noversion', 'history_id' => $un['history_id'] ) ) : $un;
check( '... so a theme without a Version header rolls back (no restore_mismatch): its files are back', is_array( $r ) && null === disk_version( 'noversion' ) && is_file( troot() . '/noversion/style.css' ), array( code( $un ), code( $r ), is_wp_error( $r ) ? msg( $r ) : null ) );
site();
$u1 = (int) call( 'themes-update', array( 'stylesheet' => TWENTY ) )['history_id'];
t_theme_write( TWENTY, array( 'Name' => 'Twenty X', 'Version' => '1.3' ) ); // updated since, outside the abilities (another request:
$GLOBALS['T_theme_cache'] = array();                                         // the per-request theme cache starts empty)
$r = call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $u1 ) );
check( 'rollback: installed newer than the row -> warnings name 1.3, still restored', is_array( $r ) && '1.0' === disk_version( TWENTY ) && 1 === count( preg_grep( '/1\.3/', (array) ( $r['warnings'] ?? array() ) ) ), $r );

// ---- themes-delete
echo "themes-delete\n";
site();
foreach ( array( CHILD => 'the active theme', PARENT_T => 'the parent of the active theme' ) as $s => $what ) {
	$p = call( 'themes-delete-preview', array( 'stylesheet' => $s ) );
	$r = call( 'themes-delete', array( 'stylesheet' => $s, 'confirmation' => 'ABCDEFGHJKLM' ) );
	check( 'delete: ' . $what . ' refused (in_use) on preview and delete; nothing removed, no row', 'in_use' === code( $p ) && 'in_use' === code( $r ) && is_dir( troot() . '/' . $s ) && array() === hrows() && array() === $GLOBALS['T_theme_delete_calls'], array( code( $p ), code( $r ) ) );
}
check( 'delete: another case of the active theme\'s name (ChildT) -> not_found, never deleted', 'not_found' === code( call( 'themes-delete-preview', array( 'stylesheet' => 'ChildT' ) ) ) && is_dir( troot() . '/' . CHILD ) );
$GLOBALS['T_theme_translations'][ TWENTY ] = array( 'de_DE' );
$t0 = tree( troot() . '/' . TWENTY );
$p = call( 'themes-delete-preview', array( 'stylesheet' => TWENTY ) );
check( 'delete: preview: version, size, the child themes that need it, the translations note, a code; nothing removed', is_array( $p ) && '1.0' === $p['version'] && 4 === $p['files'] && array( XKID ) === $p['children'] && 1 === preg_match( '/translation/i', (string) $p['note'] ) && 12 === strlen( (string) $p['confirmation'] ) && is_dir( troot() . '/' . TWENTY ) && array() === hrows(), $p );
$r = call( 'themes-delete', array( 'stylesheet' => TWENTY, 'confirmation' => $p['confirmation'] ) );
check( 'delete: delete_theme( twentyx ) once, the folder gone, its translations removed by WordPress, WordPress no longer lists it', is_array( $r ) && true === $r['deleted'] && array( TWENTY ) === $GLOBALS['T_theme_delete_calls'] && ! file_exists( troot() . '/' . TWENTY ) && array( TWENTY . '-de_DE' ) === $GLOBALS['T_theme_translation_deletes'] && ! isset( wp_get_themes( array( 'errors' => null ) )[ TWENTY ] ), $r );
$h = hlast();
check( '... the row: theme, delete, before { stylesheet, version }, after.kept = the folder byte for byte, taken before the delete', 'delete' === $h['action'] && 'divi5-builder/themes-delete' === $h['ability'] && array( 'stylesheet' => TWENTY, 'version' => '1.0' ) === $h['before'] && $t0 === tree( $h['after']['kept']['path'] ) && true === $h['after']['deleted'] && is_array( $r['kept'] ) && false === strpos( json_encode( $r ), 'd5b-history' ), $h );
$r2 = call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $h['id'] ) );
check( '... and themes-rollback puts a deleted theme back (byte for byte, listed again, not active)', is_array( $r2 ) && $t0 === tree( troot() . '/' . TWENTY ) && isset( wp_get_themes()[ TWENTY ] ) && false === $r2['active'], $r2 );

site();
$p = call( 'themes-delete-preview', array( 'stylesheet' => TWENTY ) );
$r = call( 'themes-delete', array( 'stylesheet' => TWENTY, 'without_copy' => true, 'confirmation' => $p['confirmation'] ) );
check( 'delete: a code taken with without_copy=false refused for without_copy=true (stale), nothing removed', 'confirmation_invalid' === code( $r ) && 'stale' === ( edata( $r )['reason'] ?? null ) && is_dir( troot() . '/' . TWENTY ) && array() === hrows() );
check( 'delete: no code -> confirmation_invalid', 'confirmation_invalid' === code( call( 'themes-delete', array( 'stylesheet' => TWENTY ) ) ) && is_dir( troot() . '/' . TWENTY ) );
$p = call( 'themes-delete-preview', array( 'stylesheet' => TWENTY ) ); $GLOBALS['T_copy_fail'] = true;
$r = call( 'themes-delete', array( 'stylesheet' => TWENTY, 'confirmation' => $p['confirmation'] ) );
check( 'delete: the copy cannot be made -> copy_failed, nothing removed, no row', 'copy_failed' === code( $r ) && is_dir( troot() . '/' . TWENTY ) && array() === hrows() && array() === $GLOBALS['T_theme_delete_calls'] );
$p = call( 'themes-delete-preview', array( 'stylesheet' => TWENTY, 'without_copy' => true ) );
$r = call( 'themes-delete', array( 'stylesheet' => TWENTY, 'without_copy' => true, 'confirmation' => $p['confirmation'] ) );
check( 'delete: without_copy: the copy fails, deleted anyway; kept null in the result and the row; it says it cannot be put back', is_array( $r ) && true === $r['deleted'] && null === $r['kept'] && 'copy_failed' === ( $r['copy_error'] ?? null ) && ! file_exists( troot() . '/' . TWENTY ) && null === hlast()['after']['kept'] && 1 === preg_match( '/cannot put/', implode( ' ', (array) ( $r['warnings'] ?? array() ) ) . ' ' . $r['note'] ), $r );
site(); $GLOBALS['T_file_mods_off'] = true;
check( 'delete: a site that refuses file changes: preview not_allowed (no code)', 'not_allowed' === code( call( 'themes-delete-preview', array( 'stylesheet' => TWENTY ) ) ) );
site( array( 'read', 'manage_options', 'update_themes', 'switch_themes' ) );
check( 'delete: without delete_themes: refused', 'FORBIDDEN' === call( 'themes-delete-preview', array( 'stylesheet' => TWENTY ) ) && 'FORBIDDEN' === call( 'themes-delete', array( 'stylesheet' => TWENTY, 'confirmation' => 'X' ) ) );
site(); $p = call( 'themes-delete-preview', array( 'stylesheet' => TWENTY ) ); d5b_abilities_set_category( 'themes', true, false, 'tester' );
check( 'delete: the Themes risky switch off: refused, nothing removed', 'FORBIDDEN' === call( 'themes-delete', array( 'stylesheet' => TWENTY, 'confirmation' => $p['confirmation'] ) ) && is_dir( troot() . '/' . TWENTY ) );
site( ADMIN, true ); $GLOBALS['T_user_login'] = 'siteadmin';
check( 'delete: multisite, not a network administrator: refused', 'FORBIDDEN' === call( 'themes-delete-preview', array( 'stylesheet' => TWENTY ) ) );
site( ADMIN, true ); update_site_option( 'allowedthemes', array( TWENTY => true ) );
check( 'delete: multisite, a theme enabled for the whole network -> in_use (other sites may use it), nothing removed', 'in_use' === code( call( 'themes-delete-preview', array( 'stylesheet' => TWENTY ) ) ) && is_dir( troot() . '/' . TWENTY ) );
$p = call( 'themes-delete-preview', array( 'stylesheet' => XKID ) );
check( 'delete: multisite, a network administrator deletes a theme that is not network-enabled', is_array( call( 'themes-delete', array( 'stylesheet' => XKID, 'confirmation' => $p['confirmation'] ?? '' ) ) ) && ! is_dir( troot() . '/' . XKID ) );

echo "a linked theme: without_copy never lifts the refusal\n";
site();
// linkt lives outside the theme root; the theme root holds a link (or a junction) to it.
$GLOBALS['T_theme_root'] = $T_BASE . '/outside'; t_theme_write( 'linkt', array( 'Name' => 'Link T', 'Version' => '1.0' ) ); $GLOBALS['T_theme_root'] = troot();
$ok = t_link_dir( $T_BASE . '/outside/linkt', troot() . '/linkt' ); search_theme_directories( true );
$out0 = tree( $T_BASE . '/outside/linkt' );
$GLOBALS['T_fresh']['update_themes'] = theme_offers( array( 'linkt' => '1.1' ) );
$r = call( 'themes-update', array( 'stylesheet' => 'linkt', 'without_copy' => true ) );
check( 'update a theme whose folder is a link, without_copy: linked; no upgrade, no row, what it points to untouched', $ok && 'linked' === code( $r ) && array() === $GLOBALS['T_theme_upgrade_calls'] && array() === hrows() && $out0 === tree( $T_BASE . '/outside/linkt' ), array( $ok, code( $r ) ) );
$p = call( 'themes-delete-preview', array( 'stylesheet' => 'linkt', 'without_copy' => true ) );
$r = call( 'themes-delete', array( 'stylesheet' => 'linkt', 'without_copy' => true, 'confirmation' => $p['confirmation'] ?? '' ) );
check( 'delete it, without_copy: linked; delete_theme never called, the link and what it points to untouched', 'linked' === code( $r ) && array() === $GLOBALS['T_theme_delete_calls'] && array() === hrows() && $out0 === tree( $T_BASE . '/outside/linkt' ), array( code( $p ), code( $r ) ) );

echo "fix wave: rollback of a deleted theme needs install_themes; the parent of the active theme updated\n";
site();
$p = call( 'themes-delete-preview', array( 'stylesheet' => TWENTY ) );
$d = call( 'themes-delete', array( 'stylesheet' => TWENTY, 'confirmation' => $p['confirmation'] ?? '' ) );
t_set_caps( array( 'read', 'manage_options', 'update_themes', 'switch_themes', 'delete_themes' ) );
$nrows = count( hrows() );
$r = call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $d['history_id'] ?? 0 ) );
check( 'restoring a deleted theme is an install: without install_themes it is refused (forbidden), nothing put back, no row', is_array( $d ) && 'forbidden' === code( $r ) && ! file_exists( troot() . '/' . TWENTY ) && $nrows === count( hrows() ), array( code( $d ), code( $r ) ) );
t_set_caps( ADMIN );
check( '... with install_themes it is put back', is_array( call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $d['history_id'] ) ) ) && '1.0' === disk_version( TWENTY ) );
site();
$p0 = tree( troot() . '/' . PARENT_T );
$r = call( 'themes-update', array( 'stylesheet' => PARENT_T ) );
check( 'update the parent of the active child theme: updated (3.0 -> 3.1), the child stays active, the copy holds the old parent, pages checked', is_array( $r ) && true === $r['updated'] && '3.1' === disk_version( PARENT_T ) && CHILD === get_stylesheet() && PARENT_T === get_template() && $p0 === tree( kept_path( $r['history_id'] ) ) && isset( $r['pages']['after'] ), $r );
$r2 = call( 'themes-rollback', array( 'stylesheet' => PARENT_T, 'history_id' => $r['history_id'] ?? 0 ) );
check( '... and rolled back: the parent 3.0 byte for byte, the child still active, active true (the parent is in use)', is_array( $r2 ) && $p0 === tree( troot() . '/' . PARENT_T ) && CHILD === get_stylesheet() && true === $r2['active'], $r2 );

echo "fix wave M6: the installed folder must be the slug asked for\n";
site();
$GLOBALS['T_themes_api']['astra'] = (object) array( 'slug' => 'astra', 'version' => '4.6', 'download_link' => 'https://downloads.wordpress.org/theme/othertheme.1.0.zip' );
$GLOBALS['T_theme_install_adds'] = array( 'othertheme' => array( 'Name' => 'Other', 'Version' => '1.0' ) );
$r = call( 'themes-install', array( 'slug' => 'astra' ) );
check( 'M6 themes_api (a filter) gives another theme\'s zip for the slug: bad_source, what was installed is removed again, the row says so', 'bad_source' === code( $r ) && false !== strpos( msg( $r ), '"othertheme"' ) && ! file_exists( troot() . '/othertheme' ) && ! isset( wp_get_themes()['othertheme'] ) && true === ( hlast()['after']['failed'] ?? null ) && 'othertheme' === ( hlast()['after']['installed_instead'] ?? null ) && true === ( hlast()['after']['removed'] ?? null ), array( code( $r ), msg( $r ), hlast()['after'] ?? null ) );

echo "no secrets\n";
site(); astra();
$u = call( 'themes-update', array( 'stylesheet' => TWENTY ) );
$all = json_encode( array( call( 'themes-install', array( 'slug' => 'astra' ) ), call( 'themes-switch', array( 'stylesheet' => 'astra' ) ), $u, call( 'themes-status' ), call( 'themes-rollback', array( 'stylesheet' => TWENTY, 'history_id' => $u['history_id'] ?? 0 ) ) ) );
check( 'no password, key or token, no store path in the results (an update and a rollback with kept copies among them)', is_array( $u ) && is_array( $u['kept'] ) && 1 !== preg_match( '/password|api_key|token|secret|d5b-history|manifest/i', $all ) && 1 === count( json_decode( $all, true )[3]['kept_copies'] ?? array() ), $all );
$rows = json_encode( hrows() );
check( '... and the rows hold the store path only inside kept (the history reads drop it), never a password, key or token', 1 !== preg_match( '/password|api_key|token|secret/i', $rows ), $rows );

echo "\n" . ( $failed ? "$failed of $n FAILED\n" : "all $n passed\n" );
exit( $failed ? 1 : 0 );
