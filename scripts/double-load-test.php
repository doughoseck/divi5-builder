<?php
/**
 * double-load-test.php: every plugin file must survive being loaded TWICE.
 *
 *   php scripts/double-load-test.php [plugin.php ...]
 *
 * With no arguments it covers the old single-file plugins in assets/legacy/ and the folder plugin
 * plugin/divi5-builder/ (from two copies of the folder, and with the must-use loader in
 * plugin/mu-loader/ next to one of them).
 *
 * The plugins can be installed as a must-use plugin (wp-content/mu-plugins, by FTP) or as a
 * normal plugin (zip uploaded in Plugins > Add New). A site that has both copies loads the file
 * twice; that must not crash the site (a fatal "cannot redeclare" takes the whole site down).
 * Each case is loaded in its own PHP process, from two different paths, as WordPress would.
 * Exits non-zero on any failure.
 */
if ( isset( $argv[1] ) && '--child' === $argv[1] ) {
	// Child: stub WordPress just enough for the files to load, then require both copies.
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
	foreach ( array( 'add_action', 'add_filter', 'register_activation_hook', 'register_deactivation_hook', 'register_post_meta', 'register_meta', 'register_rest_route', 'add_options_page', 'add_submenu_page', 'is_multisite' ) as $f ) {
		eval( "function $f() { return true; }" );
	}
	$GLOBALS['d5b_loads'] = 0;
	require $argv[2];
	require $argv[3];
	echo "LOADED_TWICE_OK\n";
	if ( defined( 'D5B_FILE' ) ) { echo 'D5B_FILE=' . str_replace( '\\', '/', D5B_FILE ) . "\n"; }
	exit( 0 );
}

function dbl_copy_tree( $from, $to ) {
	mkdir( $to, 0777, true );
	foreach ( scandir( $from ) as $f ) {
		if ( '.' === $f || '..' === $f ) { continue; }
		is_dir( "$from/$f" ) ? dbl_copy_tree( "$from/$f", "$to/$f" ) : copy( "$from/$f", "$to/$f" );
	}
}
function dbl_remove_tree( $d ) {
	foreach ( scandir( $d ) as $f ) { if ( '.' !== $f && '..' !== $f ) { is_dir( "$d/$f" ) ? dbl_remove_tree( "$d/$f" ) : unlink( "$d/$f" ); } }
	rmdir( $d );
}

// A case: label, then what to put where, and which two files to require, in order.
// kind 'file' = a single-file plugin copied to mu/x.php and plugins/x.php.
// kind 'folder' = the folder plugin copied to mu/divi5-builder/ and plugins/divi5-builder/.
// kind 'mu-loader' = the must-use loader next to a copy of the folder, then the normal plugin copy.
$root  = __DIR__ . '/..';
$plug  = getenv( 'D5B_PLUGIN_ROOT' ) ?: "$root/plugin"; // the override is for the mutation test
$cases = array();
foreach ( array_slice( $argv, 1 ) as $p ) { $cases[] = array( basename( $p ), 'file', $p ); }
if ( ! $cases ) {
	foreach ( glob( "$root/assets/legacy/*.php" ) as $p ) { $cases[] = array( basename( $p ), 'file', $p ); }
	$cases[] = array( 'divi5-builder/ (mu copy + plugin copy)', 'folder', "$plug/divi5-builder" );
	$cases[] = array( 'mu-loader + divi5-builder/ (mu) + plugin copy', 'mu-loader', "$plug" );
}
$failed = 0;
foreach ( $cases as $case ) {
	list( $label, $kind, $p ) = $case;
	$dir = sys_get_temp_dir() . '/d5b-dbl-' . bin2hex( random_bytes( 4 ) );
	mkdir( "$dir/mu", 0777, true ); mkdir( "$dir/plugins", 0777, true );
	if ( 'file' === $kind ) {
		if ( false === strpos( file_get_contents( $p ), 'Plugin Name:' ) ) { rmdir( "$dir/mu" ); rmdir( "$dir/plugins" ); rmdir( $dir ); continue; }
		// Two physical copies, like mu-plugins/x.php and plugins/x/x.php.
		copy( $p, "$dir/mu/" . basename( $p ) ); copy( $p, "$dir/plugins/" . basename( $p ) );
		$first = "$dir/mu/" . basename( $p ); $second = "$dir/plugins/" . basename( $p );
	} elseif ( 'folder' === $kind ) {
		dbl_copy_tree( $p, "$dir/mu/divi5-builder" ); dbl_copy_tree( $p, "$dir/plugins/divi5-builder" );
		$first = "$dir/mu/divi5-builder/divi5-builder.php"; $second = "$dir/plugins/divi5-builder/divi5-builder.php";
	} else {
		dbl_copy_tree( "$p/divi5-builder", "$dir/mu/divi5-builder" ); dbl_copy_tree( "$p/divi5-builder", "$dir/plugins/divi5-builder" );
		copy( "$p/mu-loader/divi5-builder-loader.php", "$dir/mu/divi5-builder-loader.php" );
		$first = "$dir/mu/divi5-builder-loader.php"; $second = "$dir/plugins/divi5-builder/divi5-builder.php";
	}
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child ' . escapeshellarg( $first ) . ' ' . escapeshellarg( $second ) . ' 2>&1';
	$out = (string) shell_exec( $cmd );
	$ok  = false !== strpos( $out, 'LOADED_TWICE_OK' );
	// The folder plugin must also keep the FIRST copy: the second returns early instead of redefining anything.
	if ( $ok && 'file' !== $kind ) {
		$want = str_replace( '\\', '/', 'mu-loader' === $kind ? "$dir/mu/divi5-builder/divi5-builder.php" : $first );
		$ok   = false !== strpos( str_replace( '\\', '/', $out ), 'D5B_FILE=' . $want ) && false === stripos( $out, 'warning' ) && false === stripos( $out, 'notice' );
	}
	echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label . ' loads twice without a fatal error' . ( $ok ? '' : "\n          " . trim( preg_replace( '/\s+/', ' ', $out ) ) ) . "\n";
	if ( ! $ok ) { $failed++; }
	dbl_remove_tree( $dir );
}
echo $failed ? "\n$failed FAILED\n" : "\nALL PASSED\n";
exit( $failed ? 1 : 0 );
