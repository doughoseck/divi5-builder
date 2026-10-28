<?php
/**
 * uninstall.php: what WordPress runs when the plugin is deleted (#37, final review).
 *
 *   php scripts/plugin-uninstall-test.php
 *
 * Loads plugin/divi5-builder/uninstall.php with WordPress stand-ins (plugin-test-stubs.php).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promise under test: deleting the plugin removes every option it stored (d5b_switches, d5b_takeover,
 * d5b_legacy_seen), on a single site and on every site of a network plus the network options, so a later
 * reinstall starts with every switch off. And the file does nothing at all unless WordPress is uninstalling.
 */
require __DIR__ . '/plugin-test-stubs.php';
$dir  = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
$file = $dir . '/uninstall.php';

// Subprocess mode: loaded without WP_UNINSTALL_PLUGIN (someone opens the file's URL). It must do nothing.
if ( getenv( 'T_UNINSTALL_NO_CONSTANT' ) ) {
	$GLOBALS['T_options'] = array( 'd5b_switches' => array( 'updates' => array( 'on' => true ) ) );
	register_shutdown_function( function () { echo 'SUBPROCESS-END ' . json_encode( array( $GLOBALS['T_writes'], array_keys( $GLOBALS['T_options'] ) ) ) . "\n"; } );
	include $file;
	echo "SUBPROCESS-CONTINUED\n";
	exit( 0 );
}

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }
$OURS = array( 'd5b_switches', 'd5b_takeover', 'd5b_legacy_seen', 'd5b_db_version' );
function uninstall() { include $GLOBALS['file']; }

check( 'the file exists', is_file( $file ), $file );

echo "without WP_UNINSTALL_PLUGIN it does nothing\n";
$php = escapeshellarg( PHP_BINARY ); $self = escapeshellarg( __FILE__ );
$orig_dir = getenv( 'D5B_PLUGIN_DIR' );
putenv( 'T_UNINSTALL_NO_CONSTANT=1' ); putenv( 'D5B_PLUGIN_DIR=' . $dir );
$out = (string) shell_exec( $php . ' ' . $self . ' 2>&1' );
putenv( 'T_UNINSTALL_NO_CONSTANT' ); putenv( false === $orig_dir ? 'D5B_PLUGIN_DIR' : 'D5B_PLUGIN_DIR=' . $orig_dir );
check( 'it stops the request and deletes nothing', false !== strpos( $out, 'SUBPROCESS-END [[],["d5b_switches"]]' ) && false === strpos( $out, 'SUBPROCESS-CONTINUED' ), $out );

define( 'WP_UNINSTALL_PLUGIN', 'divi5-builder/divi5-builder.php' );

echo "single site\n";
t_reset(); t_set_multisite( false );
$GLOBALS['T_options'] = array( 'd5b_switches' => array( 'updates' => array( 'on' => true ) ), 'd5b_takeover' => array( 'deactivated' => array() ),
	'd5b_legacy_seen' => array( 'updates' ), 'd5b_db_version' => '1', 'active_plugins' => array( 'akismet/akismet.php' ), 'd5b_other' => 'kept' );
$GLOBALS['wpdb']->tables = array( 'wp_d5b_audit' => array( array( 'id' => 1 ) ), 'wp_posts' => array( 'row' ) );
$GLOBALS['T_cron']['d5b_audit_prune'] = 123;
uninstall();
check( 'uninstall drops table and option: the audit table is dropped, other tables stay, the daily event is cleared', array( 'wp_posts' ) === array_keys( $GLOBALS['wpdb']->tables ) && ! isset( $GLOBALS['T_cron']['d5b_audit_prune'] ) && ! array_key_exists( 'd5b_db_version', $GLOBALS['T_options'] ), array_keys( $GLOBALS['wpdb']->tables ) );
check( 'all four options are deleted (d5b_db_version too)', array() === array_intersect( $OURS, array_keys( $GLOBALS['T_options'] ) ), array_keys( $GLOBALS['T_options'] ) );
check( 'nothing else is touched', array( 'active_plugins', 'd5b_other' ) === array_keys( $GLOBALS['T_options'] ) && array() === array_filter( $GLOBALS['T_writes'], function ( $w ) { return 'delete_option' !== $w[0]; } ), $GLOBALS['T_writes'] );
check( 'no other site is read on a single site', array() === $GLOBALS['T_get_sites'] );
check( 'the d5b_audit_install_failed transient is deleted', in_array( array( 'd5b_audit_install_failed', 1 ), $GLOBALS['T_transient_deletes'], true ), $GLOBALS['T_transient_deletes'] );
check( 'the d5b_anon transient (anonymous refusal limit) is deleted', in_array( array( 'd5b_anon', 1 ), $GLOBALS['T_transient_deletes'], true ), $GLOBALS['T_transient_deletes'] );

echo "multisite: the network options and every site's own\n";
t_reset(); t_set_multisite( true );
$GLOBALS['T_site_options'] = array( 'd5b_switches' => array( 'updates' => array( 'on' => true ) ), 'd5b_takeover' => array( 'deactivated' => array() ), 'active_sitewide_plugins' => array() );
$GLOBALS['T_options']      = array( 'd5b_legacy_seen' => array( 'updates' ), 'd5b_takeover' => array() ); // the current site
for ( $i = 1; $i <= 250; $i++ ) {
	$GLOBALS['T_blogs'][ $i ] = array( 'd5b_legacy_seen' => array( 'media_audit' ), 'd5b_takeover' => array(), 'home' => 'https://s' . $i . '.example' );
}
$GLOBALS['T_blogs'][7]['d5b_db_version'] = '1';
$GLOBALS['wpdb']->tables = array( 'wp_posts' => array( 'row' ) );
for ( $i = 1; $i <= 250; $i++ ) { $GLOBALS['wpdb']->tables[ $GLOBALS['wpdb']->get_blog_prefix( $i ) . 'd5b_audit' ] = array( array( 'id' => $i ) ); $GLOBALS['wpdb']->tables[ $GLOBALS['wpdb']->get_blog_prefix( $i ) . 'posts' ] = array( 'row' ); }
$GLOBALS['T_blogs'][7]['d5b_switches'] = array( 'updates' => array( 'on' => true ) ); // left from before the site joined a network
uninstall();
check( 'network options deleted, others kept', array( 'active_sitewide_plugins' ) === array_keys( $GLOBALS['T_site_options'] ), array_keys( $GLOBALS['T_site_options'] ) );
$left = array(); foreach ( $GLOBALS['T_blogs'] as $id => $o ) { foreach ( $OURS as $name ) { if ( array_key_exists( $name, $o ) ) { $left[] = "$id:$name"; } } }
check( 'every one of the 250 sites lost all four options (more than one batch)', array() === $left, array_slice( $left, 0, 10 ) );
$tabs = array_keys( $GLOBALS['wpdb']->tables ); $audit_left = array_filter( $tabs, function ( $t ) { return false !== strpos( $t, 'd5b_audit' ); } );
check( 'the audit table is dropped on every one of the 250 sites (site 1 has no number in its prefix)', array() === $audit_left, array_slice( array_values( $audit_left ), 0, 5 ) );
check( 'the sites\' other tables are kept (250 posts tables)', 250 === count( $tabs ) && in_array( 'wp_12_posts', $tabs, true ) && in_array( 'wp_posts', $tabs, true ), count( $tabs ) );
check( 'their other options are kept', 'https://s250.example' === $GLOBALS['T_blogs'][250]['home'] );
check( 'the current site too', array() === array_intersect( $OURS, array_keys( $GLOBALS['T_options'] ) ), array_keys( $GLOBALS['T_options'] ) );
check( 'sites read in batches of ids, at most 100 at a time', count( $GLOBALS['T_get_sites'] ) >= 3 && array() === array_filter( $GLOBALS['T_get_sites'], function ( $a ) { return 'ids' !== ( $a['fields'] ?? '' ) || ( $a['number'] ?? 0 ) > 100 || ( $a['number'] ?? 0 ) < 1; } ), $GLOBALS['T_get_sites'] );
$tdel = array_values( array_unique( array_map( function ( $d ) { return $d[1]; }, array_filter( $GLOBALS['T_transient_deletes'], function ( $d ) { return 'd5b_audit_install_failed' === $d[0]; } ) ) ) );
check( 'the d5b_audit_install_failed transient is deleted on every one of the 250 sites (switched to each), and the event cleared there too', range( 1, 250 ) === $tdel && range( 1, 250 ) === $GLOBALS['T_cleared_on'] && 1 === $GLOBALS['T_blog_id'], array( count( $tdel ), count( $GLOBALS['T_cleared_on'] ) ) );
$adel = array_values( array_unique( array_map( function ( $d ) { return $d[1]; }, array_filter( $GLOBALS['T_transient_deletes'], function ( $d ) { return 'd5b_anon' === $d[0]; } ) ) ) );
check( 'the d5b_anon transient is deleted on every one of the 250 sites', range( 1, 250 ) === $adel, count( $adel ) );
check( 'only deletes, never a write', array() === array_filter( $GLOBALS['T_writes'], function ( $w ) { return 0 !== strpos( $w[0], 'delete_' ); } ), $GLOBALS['T_writes'] );

echo "without a database object\n";
t_reset(); t_set_multisite( false ); unset( $GLOBALS['wpdb'] ); $GLOBALS['T_options'] = array( 'd5b_switches' => 1, 'd5b_db_version' => '1' );
uninstall();
check( 'no $wpdb: no fatal, the options are still deleted', array() === $GLOBALS['T_options'] );

echo "\n" . ( $failed ? "$failed FAILED of $n" : "all $n passed" ) . "\n";
exit( $failed ? 1 : 0 );
