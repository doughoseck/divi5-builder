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
	'd5b_legacy_seen' => array( 'updates' ), 'd5b_db_version' => '1', 'active_plugins' => array( 'akismet/akismet.php' ), 'd5b_other' => 'kept',
	// 2.4.1: the rate-limit and lock rows (they used to be transients that ran out by themselves)
	'd5b_rate_email_test_1699999200' => '2', 'd5b_rate_reset_user_7_1699999800' => '1', 'd5b_maint_lock_plugin_' . md5( 'akismet' ) => 'aa|1', 'd5b_users_admin_lock' => 'bb|2' );
$GLOBALS['wpdb']->tables = array( 'wp_d5b_audit' => array( array( 'id' => 1 ) ), 'wp_d5b_history' => array( array( 'id' => 1 ) ), 'wp_posts' => array( 'row' ) );
$GLOBALS['T_cron']['d5b_audit_prune'] = 123;
uninstall();
check( 'uninstall drops table and option: the audit table is dropped, other tables stay, the daily event is cleared', array( 'wp_posts' ) === array_keys( $GLOBALS['wpdb']->tables ) && ! isset( $GLOBALS['T_cron']['d5b_audit_prune'] ) && ! array_key_exists( 'd5b_db_version', $GLOBALS['T_options'] ), array_keys( $GLOBALS['wpdb']->tables ) );
check( 'uninstall drops the history table on a single site (and only that one)', ! isset( $GLOBALS['wpdb']->tables['wp_d5b_history'] ) && isset( $GLOBALS['wpdb']->tables['wp_posts'] ), array_keys( $GLOBALS['wpdb']->tables ) );
check( 'all four options are deleted (d5b_db_version too)', array() === array_intersect( $OURS, array_keys( $GLOBALS['T_options'] ) ), array_keys( $GLOBALS['T_options'] ) );
check( '2.4.1: the rate-limit rows (d5b_rate_*), the lock rows (d5b_maint_lock_*) and the administrators lock are deleted', array() === preg_grep( '/^d5b_(rate_|maint_lock_|users_admin_lock)/', array_keys( $GLOBALS['T_options'] ) ), array_keys( $GLOBALS['T_options'] ) );
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
for ( $i = 1; $i <= 250; $i++ ) { $GLOBALS['wpdb']->tables[ $GLOBALS['wpdb']->get_blog_prefix( $i ) . 'd5b_audit' ] = array( array( 'id' => $i ) ); $GLOBALS['wpdb']->tables[ $GLOBALS['wpdb']->get_blog_prefix( $i ) . 'd5b_history' ] = array( array( 'id' => $i ) ); $GLOBALS['wpdb']->tables[ $GLOBALS['wpdb']->get_blog_prefix( $i ) . 'posts' ] = array( 'row' ); }
$GLOBALS['T_blogs'][7]['d5b_switches'] = array( 'updates' => array( 'on' => true ) ); // left from before the site joined a network
$GLOBALS['T_blogs'][12]['d5b_rate_reset_site_1699999200'] = '20'; $GLOBALS['T_blogs'][250]['d5b_users_admin_lock'] = 'cc|3';
$GLOBALS['T_options']['d5b_maint_lock_theme_' . md5( 'divi' )] = 'dd|4'; // a network-wide item lock: the main site's table
$GLOBALS['T_options']['d5b_abilities_consent_240_n1'] = '1'; $GLOBALS['T_options']['d5b_abilities_consent_240_n2'] = '1'; // fix round 1 S3: per-network rows
uninstall();
check( 'S3 the per-network consent rows (d5b_abilities_consent_<n>_n<network>) in the main site\'s table are deleted', array() === preg_grep( '/^d5b_abilities_consent_/', array_keys( $GLOBALS['T_options'] ) ), array_keys( $GLOBALS['T_options'] ) );
check( '2.4.1: the rate-limit and lock rows are deleted on every site and in the main site\'s table (network locks)', ! isset( $GLOBALS['T_blogs'][12]['d5b_rate_reset_site_1699999200'] ) && ! isset( $GLOBALS['T_blogs'][250]['d5b_users_admin_lock'] ) && array() === preg_grep( '/^d5b_maint_lock_/', array_keys( $GLOBALS['T_options'] ) ) );
check( 'network options deleted, others kept', array( 'active_sitewide_plugins' ) === array_keys( $GLOBALS['T_site_options'] ), array_keys( $GLOBALS['T_site_options'] ) );
$left = array(); foreach ( $GLOBALS['T_blogs'] as $id => $o ) { foreach ( $OURS as $name ) { if ( array_key_exists( $name, $o ) ) { $left[] = "$id:$name"; } } }
check( 'every one of the 250 sites lost all four options (more than one batch)', array() === $left, array_slice( $left, 0, 10 ) );
$tabs = array_keys( $GLOBALS['wpdb']->tables ); $audit_left = array_filter( $tabs, function ( $t ) { return false !== strpos( $t, 'd5b_audit' ); } );
check( 'the audit table is dropped on every one of the 250 sites (site 1 has no number in its prefix)', array() === $audit_left, array_slice( array_values( $audit_left ), 0, 5 ) );
$hist_left = array_filter( $tabs, function ( $t ) { return false !== strpos( $t, 'd5b_history' ); } );
check( 'the history table is dropped on every one of the 250 sites (site 1 has no number in its prefix)', array() === $hist_left, array_slice( array_values( $hist_left ), 0, 5 ) );
check( 'the sites\' other tables are kept (250 posts tables)', 250 === count( $tabs ) && in_array( 'wp_12_posts', $tabs, true ) && in_array( 'wp_posts', $tabs, true ), count( $tabs ) );
check( 'their other options are kept', 'https://s250.example' === $GLOBALS['T_blogs'][250]['home'] );
check( 'the current site too', array() === array_intersect( $OURS, array_keys( $GLOBALS['T_options'] ) ), array_keys( $GLOBALS['T_options'] ) );
check( 'sites read in batches of ids, at most 100 at a time', count( $GLOBALS['T_get_sites'] ) >= 3 && array() === array_filter( $GLOBALS['T_get_sites'], function ( $a ) { return 'ids' !== ( $a['fields'] ?? '' ) || ( $a['number'] ?? 0 ) > 100 || ( $a['number'] ?? 0 ) < 1; } ), $GLOBALS['T_get_sites'] );
$tdel = array_values( array_unique( array_map( function ( $d ) { return $d[1]; }, array_filter( $GLOBALS['T_transient_deletes'], function ( $d ) { return 'd5b_audit_install_failed' === $d[0]; } ) ) ) );
check( 'the d5b_audit_install_failed transient is deleted on every one of the 250 sites (switched to each), and the event cleared there too', range( 1, 250 ) === $tdel && range( 1, 250 ) === $GLOBALS['T_cleared_on'] && 1 === $GLOBALS['T_blog_id'], array( count( $tdel ), count( $GLOBALS['T_cleared_on'] ) ) );
$adel = array_values( array_unique( array_map( function ( $d ) { return $d[1]; }, array_filter( $GLOBALS['T_transient_deletes'], function ( $d ) { return 'd5b_anon' === $d[0]; } ) ) ) );
check( 'the d5b_anon transient is deleted on every one of the 250 sites', range( 1, 250 ) === $adel, count( $adel ) );
check( 'only deletes, never a write', array() === array_filter( $GLOBALS['T_writes'], function ( $w ) { return 0 !== strpos( $w[0], 'delete_' ); } ), $GLOBALS['T_writes'] );

echo "final wave A3/A5: the kept media files (uploads/d5b-history) and the upgrade notice option\n";
$up = str_replace( '\\', '/', sys_get_temp_dir() ) . '/d5b-uninstall-test-' . getmypid();
$rmr = function ( $d ) use ( &$rmr ) { if ( is_link( $d ) || is_file( $d ) ) { @unlink( $d ); return; } if ( ! is_dir( $d ) ) { return; } foreach ( array_diff( (array) scandir( $d ), array( '.', '..' ) ) as $f ) { $rmr( "$d/$f" ); } @rmdir( $d ); };
$plant = function ( $base ) {
	@mkdir( "$base/d5b-history/12-0123456789abcdef0123456789abcdef", 0755, true ); @mkdir( "$base/d5b-history/40", 0755, true ); @mkdir( "$base/2026/10", 0755, true );
	file_put_contents( "$base/d5b-history/.htaccess", "Require all denied\n" ); file_put_contents( "$base/d5b-history/index.html", '' );
	file_put_contents( "$base/d5b-history/12-0123456789abcdef0123456789abcdef/photo.jpg", 'a' ); file_put_contents( "$base/d5b-history/12-0123456789abcdef0123456789abcdef/photo-300x200.jpg", 'b' ); file_put_contents( "$base/d5b-history/40/doc.pdf", 'c' );
	file_put_contents( "$base/2026/10/live.jpg", 'live' );
};
$rmr( $up );
t_reset(); t_set_multisite( false ); $GLOBALS['T_upload_basedir'] = $up;
$GLOBALS['T_options'] = array( 'd5b_abilities_upgrade_notice' => 1, 'd5b_other' => 'kept' );
$plant( $up );
uninstall();
check( 'single site: uploads/d5b-history is gone with every kept file; the site\'s own uploads stay', ! file_exists( "$up/d5b-history" ) && 'live' === @file_get_contents( "$up/2026/10/live.jpg" ) );
check( '... and the d5b_abilities_upgrade_notice option is deleted', ! array_key_exists( 'd5b_abilities_upgrade_notice', $GLOBALS['T_options'] ) && 'kept' === $GLOBALS['T_options']['d5b_other'] );
$rmr( $up );
t_reset(); t_set_multisite( true ); $GLOBALS['T_upload_basedir'] = $up;
for ( $i = 1; $i <= 3; $i++ ) { $GLOBALS['T_blogs'][ $i ] = array( 'home' => 'https://s' . $i . '.example' ); }
$plant( $up ); $plant( "$up/sites/3" );
$GLOBALS['T_site_options']['d5b_abilities_consent_220'] = 1; $GLOBALS['T_site_options']['d5b_abilities_upgrade_notice'] = 1;
uninstall();
check( 'network: the d5b_abilities_consent_220 and d5b_abilities_upgrade_notice network options are deleted', ! isset( $GLOBALS['T_site_options']['d5b_abilities_consent_220'] ) && ! isset( $GLOBALS['T_site_options']['d5b_abilities_upgrade_notice'] ) );
check( 'network: every site\'s d5b-history goes (site 1 in uploads, site 3 in uploads/sites/3); their own uploads stay', ! file_exists( "$up/d5b-history" ) && ! file_exists( "$up/sites/3/d5b-history" ) && is_file( "$up/2026/10/live.jpg" ) && is_file( "$up/sites/3/2026/10/live.jpg" ) && 1 === $GLOBALS['T_blog_id'] );
unset( $GLOBALS['T_upload_basedir'] );
$rmr( $up );

echo "fix wave I1: the whole store goes, kept plugin and theme copies at any depth, links unlinked and never followed\n";
// A directory link: a symbolic link where PHP may make one, else a Windows junction (no privilege needed). false when neither.
function t_link_dir( $target, $link ) {
	if ( @symlink( $target, $link ) ) { return true; }
	if ( '\\' !== DIRECTORY_SEPARATOR ) { return false; }
	exec( 'cmd /c mklink /J "' . str_replace( '/', '\\', $link ) . '" "' . str_replace( '/', '\\', $target ) . '" 2>&1', $o, $rc );
	clearstatcache();
	return 0 === $rc && file_exists( $link );
}
// Test clean-up that never follows a link or a junction.
function t_rm( $d ) {
	clearstatcache();
	if ( is_link( $d ) || is_file( $d ) ) { @unlink( $d ) || @rmdir( $d ); return; }
	if ( ! file_exists( $d ) ) { return; }
	if ( ! is_dir( $d ) || realpath( $d ) !== realpath( dirname( $d ) ) . DIRECTORY_SEPARATOR . basename( $d ) ) { @rmdir( $d ) || @unlink( $d ); return; }
	foreach ( array_diff( (array) scandir( $d ), array( '.', '..' ) ) as $f ) { t_rm( "$d/$f" ); }
	@rmdir( $d );
}
$plant24 = function ( $base ) {
	$store = "$base/d5b-history";
	foreach ( array(
		'.htaccess' => "Require all denied\n", 'index.html' => '',
		'5-' . str_repeat( 'a', 32 ) . '/photo.jpg' => 'a',
		'12-' . str_repeat( 'b', 32 ) . '/plugin/akismet/akismet.php' => "<?php // kept\n",
		'12-' . str_repeat( 'b', 32 ) . '/plugin/akismet/sub/deeper/file.php' => "<?php // nested\n",
		'13-' . str_repeat( 'c', 32 ) . '/theme/twentyx/inc/functions.php' => "<?php // kept theme\n",
	) as $rel => $body ) { $p = "$store/$rel"; if ( ! is_dir( dirname( $p ) ) ) { mkdir( dirname( $p ), 0777, true ); } file_put_contents( $p, $body ); }
};
$up = str_replace( '\\', '/', sys_get_temp_dir() ) . '/d5b-uninstall-i1-' . getmypid();
$outside = $up . '-outside';
t_rm( $up ); t_rm( $outside );
@mkdir( "$outside/keep", 0777, true ); file_put_contents( "$outside/keep/target.php", 'must stay' );
t_reset(); t_set_multisite( false ); $GLOBALS['T_upload_basedir'] = $up;
$plant24( $up );
$linked = t_link_dir( "$outside/keep", "$up/d5b-history/12-" . str_repeat( 'b', 32 ) . '/plugin/akismet/sub/alias' );
uninstall();
clearstatcache();
check( 'I1 a nested <id>-<hex>/plugin/<dir>/sub/.../file.php store is removed whole (the store folder itself too)', ! file_exists( "$up/d5b-history" ) && ! is_link( "$up/d5b-history" ) );
check( 'I1 a link inside the store was unlinked, its target outside the store untouched', $linked && 'must stay' === @file_get_contents( "$outside/keep/target.php" ) && is_dir( "$outside/keep" ), array( 'linked' => $linked ) );
// A file that cannot be removed (read-only on Windows; in a read-only folder elsewhere): the store's .htaccess stays.
t_rm( $up ); $plant24( $up );
$held = "$up/d5b-history/12-" . str_repeat( 'b', 32 ) . '/plugin/akismet/sub/deeper/file.php';
$fh = '\\' === DIRECTORY_SEPARATOR;
if ( $fh ) { chmod( $held, 0444 ); } else { chmod( dirname( $held ), 0555 ); }
t_reset(); t_set_multisite( false ); $GLOBALS['T_upload_basedir'] = $up;
uninstall();
clearstatcache();
$held_ok = is_file( $held ); $ht_ok = is_file( "$up/d5b-history/.htaccess" );
if ( $fh ) { chmod( $held, 0666 ); } else { chmod( dirname( $held ), 0777 ); }
check( 'I1 when a kept file cannot be removed the store\'s .htaccess (deny rule) stays: it is removed last', $held_ok && $ht_ok, array( 'held' => $held_ok, 'htaccess' => $ht_ok ) );
t_reset(); t_set_multisite( false ); $GLOBALS['T_upload_basedir'] = $up;
uninstall(); clearstatcache();
check( 'I1 ... and a second uninstall run removes the rest', ! file_exists( "$up/d5b-history" ) );
// The store root itself a link: unlinked, its target untouched.
t_rm( $up ); @mkdir( $up, 0777, true ); @mkdir( "$outside/store", 0777, true ); file_put_contents( "$outside/store/x.php", 'x' );
$rootlinked = t_link_dir( "$outside/store", "$up/d5b-history" );
t_reset(); t_set_multisite( false ); $GLOBALS['T_upload_basedir'] = $up;
uninstall(); clearstatcache();
check( 'I1 a store root that is a link is unlinked, its target untouched', $rootlinked && ! file_exists( "$up/d5b-history" ) && 'x' === @file_get_contents( "$outside/store/x.php" ), array( 'linked' => $rootlinked ) );
unset( $GLOBALS['T_upload_basedir'] );
t_rm( $up ); t_rm( $outside );

echo "without a database object\n";
t_reset(); t_set_multisite( false ); unset( $GLOBALS['wpdb'] ); $GLOBALS['T_options'] = array( 'd5b_switches' => 1, 'd5b_db_version' => '1' );
uninstall();
check( 'no $wpdb: no fatal, the options are still deleted', array() === $GLOBALS['T_options'] );

echo "\n" . ( $failed ? "$failed FAILED of $n" : "all $n passed" ) . "\n";
exit( $failed ? 1 : 0 );
