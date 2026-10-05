<?php
/*
 * media-quarantine-test.php: assets/wp-media-quarantine.php on a temporary folder, without WordPress.
 *
 *   php scripts/media-quarantine-test.php
 *
 * Moves real files in a temp folder: into quarantine, back, and every way it must refuse.
 * Set WPMQ_PLUGIN_FILE to test another copy of the plugin (used for mutation testing).
 */
$tmp = sys_get_temp_dir() . '/wpmq-test-' . bin2hex( random_bytes( 4 ) );
mkdir( $tmp . '/wp-content/uploads/2019/01', 0777, true );
mkdir( $tmp . '/wp-content/uploads/2020', 0777, true );
define( 'ABSPATH', $tmp . '/' );
define( 'WP_CONTENT_DIR', $tmp . '/wp-content' );
$GLOBALS['routes'] = array(); $GLOBALS['can'] = true; $GLOBALS['caps'] = array( 'manage_options' ); $GLOBALS['multisite'] = false;
function is_multisite() { return $GLOBALS['multisite']; }
// Every quarantine folder in wp-content (1.0.1 names it media-audit-quarantine-<24 hex>).
function qdirs() { $o = array(); foreach ( (array) glob( WP_CONTENT_DIR . '/media-audit-quarantine*' ) as $d ) { if ( is_dir( $d ) ) { $o[] = str_replace( '\\', '/', $d ); } } sort( $o ); return $o; }
// A directory link that needs no admin rights: a symlink where allowed, else a Windows junction.
function dirlink( $target, $link ) {
	if ( @symlink( $target, $link ) ) { return true; }
	if ( '\\' !== DIRECTORY_SEPARATOR ) { return false; }
	exec( 'cmd /c mklink /J "' . str_replace( '/', '\\', $link ) . '" "' . str_replace( '/', '\\', $target ) . '" >NUL 2>&1', $o, $rc );
	clearstatcache(); return 0 === $rc && false !== realpath( $link );
}
function unlink_dirlink( $link ) { if ( '\\' === DIRECTORY_SEPARATOR ) { exec( 'cmd /c rmdir "' . str_replace( '/', '\\', $link ) . '" >NUL 2>&1' ); } else { @unlink( $link ); } clearstatcache(); }
class WP_Error { public $code; public $message; public $data; function __construct( $c, $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; } }
class Req { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( $hook, $fn ) { $fn(); }
function register_rest_route( $ns, $path, $def ) { $GLOBALS['routes'][ $path ] = $def; }
function current_user_can( $cap ) { return $GLOBALS['can'] && in_array( $cap, $GLOBALS['caps'], true ); }
function wp_upload_dir() { return array( 'basedir' => WP_CONTENT_DIR . '/uploads', 'baseurl' => 'https://x.example/wp-content/uploads' ); }
require getenv( 'WPMQ_PLUGIN_FILE' ) ?: __DIR__ . '/../assets/wp-media-quarantine.php';

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }
function call( $path, $params ) { return call_user_func( $GLOBALS['routes'][ $path ]['callback'], new Req( $params ) ); }
$U = $tmp . '/wp-content/uploads'; $Q = $tmp . '/wp-content/media-audit-quarantine';
file_put_contents( "$U/2019/01/a.jpg", 'AAAA' ); touch( "$U/2019/01/a.jpg", 1500000000 );
file_put_contents( "$U/2019/01/b.jpg", 'BBBBBB' );
file_put_contents( "$U/2020/keep.jpg", 'KEEP' );
file_put_contents( "$tmp/wp-content/secret.php", 'SECRET' );
file_put_contents( "$tmp/outside.txt", 'OUT' );

echo "--- who may\n";
$perms = array_map( function ( $r ) { return call_user_func( $r['permission_callback'] ); }, $GLOBALS['routes'] );
check( 'three routes, all for administrators', 3 === count( $perms ) && ! in_array( false, $perms, true ) );
$GLOBALS['can'] = false;
check( 'and refused for anyone else', ! in_array( true, array_map( function ( $r ) { return call_user_func( $r['permission_callback'] ); }, $GLOBALS['routes'] ), true ) );
$GLOBALS['can'] = true;
check( 'move and restore are POST, the list is GET', 'POST' === $GLOBALS['routes']['/move']['methods'] && 'POST' === $GLOBALS['routes']['/restore']['methods'] && 'GET' === $GLOBALS['routes']['/batches']['methods'] );

echo "--- dry run\n";
$r = call( '/move', array( 'batch' => 'b1', 'files' => array( '2019/01/a.jpg', '2019/01/b.jpg' ), 'dry_run' => true ) );
check( 'says what it would move (2 files, 10 bytes)', true === $r['dryRun'] && 2 === count( $r['moved'] ) && 10 === $r['bytes'], $r );
check( 'and moves nothing, creates nothing', file_exists( "$U/2019/01/a.jpg" ) && file_exists( "$U/2019/01/b.jpg" ) && ! qdirs() );

echo "--- what it refuses\n";
foreach ( array( '../secret.php', '2019/../../secret.php', '/etc/passwd', 'C:/x.txt', '2019\\..\\..\\secret.php', '', '2019//01/a.jpg', './2019/01/a.jpg' ) as $bad ) {
	$r = call( '/move', array( 'batch' => 'b1', 'files' => array( $bad ) ) );
	check( 'path refused before anything is looked up: ' . ( '' === $bad ? '(empty)' : $bad ), 0 === count( $r['moved'] ) && 1 === count( $r['skipped'] ) && 'not a plain path inside uploads' === $r['skipped'][0][1], $r );
}
check( 'nothing outside uploads was touched', 'SECRET' === file_get_contents( "$tmp/wp-content/secret.php" ) && 'OUT' === file_get_contents( "$tmp/outside.txt" ) );
$r = call( '/move', array( 'batch' => 'b1', 'files' => array( '2019/01' ) ) );
check( 'a folder is refused (files only)', 0 === count( $r['moved'] ) && is_dir( "$U/2019/01" ), $r );
$r = call( '/move', array( 'batch' => 'b1', 'files' => array( '2019/01/nope.jpg' ) ) );
check( 'a file that does not exist is skipped with a reason', 0 === count( $r['moved'] ) && 'no such file' === $r['skipped'][0][1], $r );
foreach ( array( '../x', 'UPPER', '', 'a/b', str_repeat( 'a', 41 ) ) as $b ) {
	check( 'batch name refused: ' . ( '' === $b ? '(empty)' : substr( $b, 0, 12 ) ), is_wp_error( call( '/move', array( 'batch' => $b, 'files' => array( '2019/01/a.jpg' ) ) ) ) );
}
check( 'no files, or more than 1000, is refused', is_wp_error( call( '/move', array( 'batch' => 'b1', 'files' => array() ) ) ) && is_wp_error( call( '/move', array( 'batch' => 'b1', 'files' => array_fill( 0, 1001, 'x.jpg' ) ) ) ) );
check( 'still nothing moved', file_exists( "$U/2019/01/a.jpg" ) && ! array_filter( qdirs(), function ( $d ) { return file_exists( "$d/b1/files" ); } ) );

echo "--- move\n";
$r = call( '/move', array( 'batch' => 'b1', 'files' => array( '2019/01/a.jpg', '2019/01/b.jpg' ) ) );
$Q = qdirs() ? qdirs()[0] : $Q;
check( 'two files moved', 2 === count( $r['moved'] ) && 10 === $r['bytes'] && ! $r['skipped'], $r );
check( 'they are gone from uploads and in the batch, content intact', ! file_exists( "$U/2019/01/a.jpg" ) && 'AAAA' === file_get_contents( "$Q/b1/files/2019/01/a.jpg" ) && 'BBBBBB' === file_get_contents( "$Q/b1/files/2019/01/b.jpg" ) );
check( 'the file that was not named is untouched', 'KEEP' === file_get_contents( "$U/2020/keep.jpg" ) );
check( 'the quarantine folder refuses web requests', false !== strpos( (string) file_get_contents( "$Q/.htaccess" ), 'Require all denied' ) && file_exists( "$Q/index.php" ) );
$m = json_decode( file_get_contents( "$Q/b1/manifest.json" ), true );
check( 'the manifest lists both with size and original time', 2 === count( $m['files'] ) && 4 === $m['files']['2019/01/a.jpg']['bytes'] && 1500000000 === $m['files']['2019/01/a.jpg']['mtime'] && ! empty( $m['files']['2019/01/a.jpg']['moved'] ), $m );
$r = call( '/move', array( 'batch' => 'b1', 'files' => array( '2019/01/a.jpg' ) ) );
check( 'moving it again: skipped as already in quarantine', 0 === count( $r['moved'] ) && 'already in quarantine' === $r['skipped'][0][1], $r );
file_put_contents( "$U/2019/01/a.jpg", 'NEW' );
$r = call( '/move', array( 'batch' => 'b1', 'files' => array( '2019/01/a.jpg' ) ) );
check( 'a new file of the same name does not overwrite the quarantined one', 0 === count( $r['moved'] ) && 'AAAA' === file_get_contents( "$Q/b1/files/2019/01/a.jpg" ) && 'NEW' === file_get_contents( "$U/2019/01/a.jpg" ), $r );
$b = call( '/batches', array() );
check( 'batches: b1 holds 2 files, 10 bytes', 1 === count( $b['batches'] ) && 'b1' === $b['batches'][0]['batch'] && 2 === $b['batches'][0]['inQuarantine'] && 10 === $b['batches'][0]['bytes'], $b );

echo "--- restore\n";
$r = call( '/restore', array( 'batch' => 'b1', 'files' => array( '2019/01/a.jpg' ) ) );
check( 'restore refuses to overwrite a file that exists in uploads', 0 === count( $r['restored'] ) && 'NEW' === file_get_contents( "$U/2019/01/a.jpg" ) && file_exists( "$Q/b1/files/2019/01/a.jpg" ), $r );
unlink( "$U/2019/01/a.jpg" );
$r = call( '/restore', array( 'batch' => 'b1', 'files' => array( '2019/01/a.jpg' ), 'dry_run' => true ) );
check( 'restore dry run moves nothing', 1 === count( $r['restored'] ) && ! file_exists( "$U/2019/01/a.jpg" ), $r );
$r = call( '/restore', array( 'batch' => 'b1', 'files' => array( '2019/01/a.jpg', '../../secret.php', '2019/01/zzz.jpg' ) ) );
clearstatcache();
check( 'restore one: back in place, same content, original time; bad and unknown paths skipped', 1 === count( $r['restored'] ) && 2 === count( $r['skipped'] ) && 'AAAA' === file_get_contents( "$U/2019/01/a.jpg" ) && 1500000000 === filemtime( "$U/2019/01/a.jpg" ) && ! file_exists( "$Q/b1/files/2019/01/a.jpg" ), $r );
$b = call( '/batches', array() );
check( 'batches: 1 left, 1 restored', 1 === $b['batches'][0]['inQuarantine'] && 1 === $b['batches'][0]['restored'] && 6 === $b['batches'][0]['bytes'], $b );
mkdir( "$U/2021/05", 0777, true ); file_put_contents( "$U/2021/05/deep.jpg", 'DEEP' );
call( '/move', array( 'batch' => 'b2', 'files' => array( '2021/05/deep.jpg' ) ) );
rmdir( "$U/2021/05" ); rmdir( "$U/2021" );
$r = call( '/restore', array( 'batch' => 'b2', 'all' => true ) );
check( 'restore re-creates a folder that was removed meanwhile', 1 === count( $r['restored'] ) && 'DEEP' === file_get_contents( "$U/2021/05/deep.jpg" ), $r );
$r = call( '/restore', array( 'batch' => 'b1', 'all' => true ) );
check( 'restore all: the rest comes back', 1 === count( $r['restored'] ) && 0 === $r['more'] && 'BBBBBB' === file_get_contents( "$U/2019/01/b.jpg" ), $r );
$r = call( '/restore', array( 'batch' => 'b1', 'all' => true ) );
check( 'restore all again: nothing left to do', 0 === count( $r['restored'] ) && 0 === count( $r['skipped'] ), $r );
$r = call( '/restore', array( 'batch' => 'other', 'files' => array( '2019/01/a.jpg' ) ) );
check( 'restoring from a batch that does not hold the file: skipped', 0 === count( $r['restored'] ) && 'not in this batch' === $r['skipped'][0][1], $r );
$r = call( '/move', array( 'batch' => 'b1', 'files' => array( '2019/01/a.jpg' ) ) );
check( 'a restored file can be quarantined again', 1 === count( $r['moved'] ) && ! file_exists( "$U/2019/01/a.jpg" ), $r );
$m = json_decode( file_get_contents( "$Q/b1/manifest.json" ), true );
check( '... and the manifest shows it in quarantine, not restored', empty( $m['files']['2019/01/a.jpg']['restored'] ), $m['files']['2019/01/a.jpg'] );

echo "--- 1.0.1 security\n";
check( 'the quarantine folder name cannot be guessed (media-audit-quarantine-<24 hex>)', (bool) preg_match( '#/media-audit-quarantine-[a-f0-9]{24}$#', $Q ), $Q );
check( 'and it has no listing (index.php) and the Apache deny rule', file_exists( "$Q/index.php" ) && file_exists( "$Q/.htaccess" ) );

// Dot files: uploads/.htaccess is often a "no PHP in uploads" rule; moving it removes the protection.
file_put_contents( "$U/.htaccess", "deny php\n" ); file_put_contents( "$U/2019/.user.ini", "x\n" );
$r = call( '/move', array( 'batch' => 'dots', 'files' => array( '.htaccess', '2019/.user.ini' ) ) );
clearstatcache();
check( 'uploads/.htaccess and 2019/.user.ini are refused and stay where they are', 0 === count( $r['moved'] ) && 2 === count( $r['skipped'] ) && "deny php\n" === @file_get_contents( "$U/.htaccess" ) && file_exists( "$U/2019/.user.ini" ), $r );

// Restore must not pull a file in from outside quarantine through a link in the batch.
mkdir( "$Q/evil/files", 0777, true ); file_put_contents( "$Q/evil/manifest.json", '{"batch":"evil","created":"x","files":{}}' );
$linked = dirlink( "$tmp/wp-content", "$Q/evil/files/2019" );
if ( $linked ) {
	$r = call( '/restore', array( 'batch' => 'evil', 'files' => array( '2019/secret.php' ) ) );
	clearstatcache();
	check( 'restore through a link inside the batch is refused (secret.php stays put, nothing lands in uploads)', 0 === count( $r['restored'] ) && 'SECRET' === @file_get_contents( "$tmp/wp-content/secret.php" ) && ! file_exists( "$U/2019/secret.php" ), $r );
	unlink_dirlink( "$Q/evil/files/2019" );
} else { echo "  SKIP  restore-through-a-link (cannot make a directory link here)\n"; }

// Restore must not write outside uploads through a link in uploads.
mkdir( "$tmp/elsewhere" ); mkdir( "$Q/evil/files/2022", 0777, true ); file_put_contents( "$Q/evil/files/2022/planted.php", 'PLANT' );
$linked = dirlink( "$tmp/elsewhere", "$U/2022" );
if ( $linked ) {
	$r = call( '/restore', array( 'batch' => 'evil', 'files' => array( '2022/planted.php' ) ) );
	clearstatcache();
	check( 'restore into a folder that links outside uploads is refused (nothing lands outside)', 0 === count( $r['restored'] ) && ! file_exists( "$tmp/elsewhere/planted.php" ) && file_exists( "$Q/evil/files/2022/planted.php" ), $r );
	unlink_dirlink( "$U/2022" );
} else { echo "  SKIP  restore-out-through-a-link (cannot make a directory link here)\n"; }

// A 1.0.0 folder: still listed (with a warning) and restorable, never added to.
$L = WP_CONTENT_DIR . '/media-audit-quarantine';
mkdir( "$L/old1/files/2019/01", 0777, true ); file_put_contents( "$L/old1/files/2019/01/legacy.jpg", 'LEG' );
file_put_contents( "$L/old1/manifest.json", json_encode( array( 'batch' => 'old1', 'created' => 'x', 'files' => array( '2019/01/legacy.jpg' => array( 'bytes' => 3, 'mtime' => 1, 'moved' => 'x' ) ) ) ) );
$b = call( '/batches', array() ); $old = null;
foreach ( $b['batches'] as $row ) { if ( 'old1' === $row['batch'] ) { $old = $row; } }
check( 'a batch in the old 1.0.0 folder is listed with a warning', $old && ! empty( $old['legacyFolder'] ) && ! empty( $old['warning'] ), $b );
$r = call( '/move', array( 'batch' => 'old1', 'files' => array( '2020/keep.jpg' ) ) );
check( 'nothing new is moved into the old folder', is_wp_error( $r ) && 'KEEP' === file_get_contents( "$U/2020/keep.jpg" ), $r );
$r = call( '/restore', array( 'batch' => 'old1', 'all' => true ) );
check( 'but its files can be restored', ! is_wp_error( $r ) && 1 === count( $r['restored'] ) && 'LEG' === @file_get_contents( "$U/2019/01/legacy.jpg" ), $r );

// Multisite: the folder is shared by every site, so a subsite admin (manage_options) is not enough.
$GLOBALS['multisite'] = true;
$perm = function () { return array_map( function ( $r ) { return call_user_func( $r['permission_callback'] ); }, $GLOBALS['routes'] ); };
check( 'multisite: a subsite admin is refused on every route', ! in_array( true, $perm(), true ) );
$GLOBALS['caps'] = array( 'manage_options', 'manage_network_options' );
check( 'multisite: a network admin is allowed', ! in_array( false, $perm(), true ) );
$GLOBALS['multisite'] = false; $GLOBALS['caps'] = array( 'manage_options' );

echo "--- the plugin has no way to delete\n";
$src = file_get_contents( getenv( 'WPMQ_PLUGIN_FILE' ) ?: __DIR__ . '/../assets/wp-media-quarantine.php' );
check( 'no unlink, rmdir, wp_delete or DELETE in the code', ! preg_match( '/\b(unlink|rmdir|wp_delete_\w+|wp_trash_\w+)\s*\(|\$wpdb|DELETE/', $src ) );

// clean up the temp folder
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); }
rmdir( $tmp );
echo "\n" . ( $failed ? "FAILED: $failed of $n" : "ALL $n CHECKS PASSED" ) . "\n";
exit( $failed ? 1 : 0 );
