<?php
/**
 * double-load-test.php: every plugin in assets/ must survive being loaded TWICE.
 *
 *   php scripts/double-load-test.php [plugin.php ...]
 *
 * The plugins can be installed as a must-use plugin (wp-content/mu-plugins, by FTP) or as a
 * normal plugin (zip uploaded in Plugins > Add New). A site that has both copies loads the file
 * twice; that must not crash the site (a fatal "cannot redeclare" takes the whole site down).
 * Each plugin is loaded in its own PHP process, from two different paths, as WordPress would.
 * Exits non-zero on any failure.
 */
if ( isset( $argv[1] ) && '--child' === $argv[1] ) {
	// Child: stub WordPress just enough for the files to load, then require both copies.
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() );
	foreach ( array( 'add_action', 'add_filter', 'register_activation_hook', 'register_deactivation_hook', 'register_post_meta', 'register_meta', 'register_rest_route' ) as $f ) {
		eval( "function $f() { return true; }" );
	}
	$GLOBALS['d5b_loads'] = 0;
	require $argv[2];
	require $argv[3];
	echo "LOADED_TWICE_OK\n";
	exit( 0 );
}

$plugins = array_slice( $argv, 1 );
if ( ! $plugins ) { $plugins = glob( __DIR__ . '/../assets/legacy/*.php' ); }
$failed = 0;
foreach ( $plugins as $p ) {
	$src = file_get_contents( $p );
	if ( false === strpos( $src, 'Plugin Name:' ) ) { continue; }
	// Two physical copies, like mu-plugins/x.php and plugins/x/x.php.
	$dir = sys_get_temp_dir() . '/d5b-dbl-' . bin2hex( random_bytes( 4 ) );
	mkdir( "$dir/mu", 0777, true ); mkdir( "$dir/plugins", 0777, true );
	copy( $p, "$dir/mu/" . basename( $p ) ); copy( $p, "$dir/plugins/" . basename( $p ) );
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' --child ' . escapeshellarg( "$dir/mu/" . basename( $p ) ) . ' ' . escapeshellarg( "$dir/plugins/" . basename( $p ) ) . ' 2>&1';
	$out = (string) shell_exec( $cmd );
	$ok  = false !== strpos( $out, 'LOADED_TWICE_OK' );
	echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . basename( $p ) . ' loads twice without a fatal error' . ( $ok ? '' : "\n          " . trim( preg_replace( '/\s+/', ' ', $out ) ) ) . "\n";
	if ( ! $ok ) { $failed++; }
	foreach ( array( "$dir/mu", "$dir/plugins" ) as $d ) { foreach ( glob( "$d/*" ) as $f ) { unlink( $f ); } rmdir( $d ); }
	rmdir( $dir );
}
echo $failed ? "\n$failed FAILED\n" : "\nALL PASSED\n";
exit( $failed ? 1 : 0 );
