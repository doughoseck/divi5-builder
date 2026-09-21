<?php
// Local self-test of a GENERATED profiler, no WordPress needed. Exits non-zero on failure.
//   node scripts/hook-profiler.js gen <dir>   then   php scripts/hook-profiler-test.php <dir>
// Checks the wrapper against a fake hook table: the filter chain still returns the right value, a PHP built-in gets no extra
// arguments, nothing is wrapped twice, a deliberately slow callback is measured, and the report is one well-formed HTML comment.
$DIR = rtrim( $argv[1] ?? getcwd(), '/\\' );
if ( ! is_file( "$DIR/d5b-profiler.php" ) || ! is_file( "$DIR/d5b-profiler.key" ) ) { fwrite( STDERR, "usage: php hook-profiler-test.php <dir holding d5b-profiler.php and d5b-profiler.key>\n" ); exit( 2 ); }
define( 'ABSPATH', $DIR ); define( 'WP_CONTENT_DIR', $DIR );
$_GET['d5bprof'] = trim( file_get_contents( "$DIR/d5b-profiler.key" ) );
$GLOBALS['__actions'] = array();
function add_action( $h, $cb, $p = 10 ) { $GLOBALS['__actions'][] = array( $h, $cb, $p ); }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); }
class FakeHook { public $callbacks = array(); }
class Thing { public static function slow( $x ) { usleep( 30000 ); return $x . '!'; } }
$h = new FakeHook();
$h->callbacks[10]['a'] = array( 'function' => 'trim', 'accepted_args' => 1 ); // PHP built-in: must not get extra args
$h->callbacks[10]['b'] = array( 'function' => array( 'Thing', 'slow' ), 'accepted_args' => 1 );
$h->callbacks[20]['c'] = array( 'function' => function ( $x ) { return strtoupper( $x ); }, 'accepted_args' => 1 );
$GLOBALS['wp_filter'] = array( 'the_content' => $h );
require "$DIR/d5b-profiler.php";
d5bprof_wrap( 'the_content' ); d5bprof_wrap( 'the_content' ); // twice: must not double-wrap
$v = '  hi  '; foreach ( $GLOBALS['wp_filter']['the_content']->callbacks as $cbs ) { foreach ( $cbs as $cb ) { $v = call_user_func_array( $cb['function'], array( $v ) ); } }
$rows = $GLOBALS['d5bprof']['rows']; $fail = 0;
$check = function ( $name, $ok ) use ( &$fail ) { echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . "\n"; if ( ! $ok ) { $fail++; } };
$check( 'filter chain still returns the right value ("HI!")', 'HI!' === $v );
$check( 'one timing row per callback, not doubled, with a files-loaded count', 3 === count( $rows ) && isset( $rows[0][5] ) );
$check( 'names resolved', 'trim' === $rows[0][2] && 'Thing::slow' === $rows[1][2] && 0 === strpos( $rows[2][2], 'closure ' ) );
$check( 'the 30ms callback measured as >= 25ms, the others as < 5ms', $rows[1][3] >= 0.025 && $rows[0][3] < 0.005 && $rows[2][3] < 0.005 );
ob_start(); foreach ( $GLOBALS['__actions'] as $a ) { if ( 'shutdown' === $a[0] ) { call_user_func( $a[1] ); } } $out = ob_get_clean();
$check( 'report is ONE HTML comment naming the slow callback', 1 === preg_match( '/^\s*<!-- D5BPROF .*Thing::slow.*D5BPROF -->\s*$/s', $out ) && false === strpos( substr( trim( $out ), 4, -3 ), '-->' ) );
$check( 'the key never appears in the report', false === strpos( $out, $_GET['d5bprof'] ) );
// the gate: a separate PHP process, because the file's functions can only be declared once per process
// (run from a temp FILE, not `php -r`: shell quoting of PHP code differs between Windows and POSIX and silently breaks the check)
$gate = function ( $query ) use ( $DIR ) { $tmp = tempnam( sys_get_temp_dir(), 'd5bgate' );
	file_put_contents( $tmp, '<?php define("ABSPATH","x");$n=0;function add_action($h,$c,$p=10){global $n;$n++;}' . $query . 'include ' . var_export( "$DIR/d5b-profiler.php", true ) . ';echo "N=".$n;' );
	$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tmp ) ); unlink( $tmp ); return trim( $out ); };
$check( 'with NO key the file registers nothing', 'N=0' === $gate( '' ) );
$check( 'with a WRONG key the file registers nothing', 'N=0' === $gate( '$_GET["d5bprof"]="wrong";' ) );
$check( 'control: with the RIGHT key it DOES register hooks (so the two checks above can fail)', 1 === preg_match( '/^N=[1-9]/', $gate( '$_GET["d5bprof"]=' . var_export( $_GET['d5bprof'], true ) . ';' ) ) );
echo $fail ? "\n$fail FAILED\n" : "\nall checks passed\n"; exit( $fail ? 1 : 0 );
