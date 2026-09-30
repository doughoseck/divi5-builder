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
$GLOBALS['routes'] = array(); $GLOBALS['can'] = true;
class WP_Error { public $code; public $message; public $data; function __construct( $c, $m = '', $d = array() ) { $this->code = $c; $this->message = $m; $this->data = $d; } }
class Req { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( $hook, $fn ) { $fn(); }
function register_rest_route( $ns, $path, $def ) { $GLOBALS['routes'][ $path ] = $def; }
function current_user_can( $cap ) { return $GLOBALS['can'] && 'manage_options' === $cap; }
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
check( 'and moves nothing, creates nothing', file_exists( "$U/2019/01/a.jpg" ) && file_exists( "$U/2019/01/b.jpg" ) && ! file_exists( $Q ) );

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
check( 'still nothing moved', file_exists( "$U/2019/01/a.jpg" ) && ! file_exists( "$Q/b1/files" ) );

echo "--- move\n";
$r = call( '/move', array( 'batch' => 'b1', 'files' => array( '2019/01/a.jpg', '2019/01/b.jpg' ) ) );
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

echo "--- the plugin has no way to delete\n";
$src = file_get_contents( getenv( 'WPMQ_PLUGIN_FILE' ) ?: __DIR__ . '/../assets/wp-media-quarantine.php' );
check( 'no unlink, rmdir, wp_delete or DELETE in the code', ! preg_match( '/\b(unlink|rmdir|wp_delete_\w+|wp_trash_\w+)\s*\(|\$wpdb|DELETE/', $src ) );

// clean up the temp folder
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); }
rmdir( $tmp );
echo "\n" . ( $failed ? "FAILED: $failed of $n" : "ALL $n CHECKS PASSED" ) . "\n";
exit( $failed ? 1 : 0 );
