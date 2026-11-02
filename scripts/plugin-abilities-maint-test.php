<?php
/**
 * The shared helpers of the maintenance abilities (#32, release 2.4.0, Task 1): the page checks, the kept copy, the store
 * size and its caps, the recursive removal of a row's files, the self rule, the human check sentence and the rate limit.
 *
 *   php -d max_execution_time=60 scripts/plugin-abilities-maint-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php). The file system is real: a folder under the
 * system temp folder holds the plugins, the themes and the uploads (with the d5b-history store); it is made here and removed
 * at the end. The size caps (512 MB a copy, 2 GB the store) are tested with files made to that size with ftruncate (no data
 * written; removed at once). PHP's clock cannot be replaced, so maint-common.php reads $GLOBALS['d5b_maint_seams']['now'],
 * which this test fills. Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The page check rules are scripts/site-care/site-updates-test.js's, ported case by case.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
$T_BASE = str_replace( '\\', '/', sys_get_temp_dir() ) . '/d5b-maint-' . getmypid();
define( 'WP_PLUGIN_DIR', $T_BASE . '/plugins' );
define( 'D5B_VERSION', '2.4.0' );
define( 'D5B_FILE', WP_PLUGIN_DIR . '/divi5-builder-copy/divi5-builder.php' );
require __DIR__ . '/plugin-test-stubs.php';
foreach ( array( 'inc/parts', 'inc/switches', 'inc/takeover', 'inc/abilities/categories', 'inc/abilities/register', 'inc/abilities/secrets', 'inc/abilities/audit', 'inc/abilities/scrub', 'inc/abilities/history', 'inc/abilities/maint-common' ) as $f ) { require $dir . '/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 800 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

// ---- the fake file system and the clock
function t_rm( $d ) {
	if ( is_link( $d ) || is_file( $d ) ) { @unlink( $d ); return; }
	if ( ! file_exists( $d ) ) { return; }
	// A Windows junction: neither a link nor a folder to PHP, removed with rmdir (its target is not touched).
	if ( ! is_dir( $d ) ) { @rmdir( $d ); return; }
	foreach ( array_diff( (array) scandir( $d ), array( '.', '..' ) ) as $f ) {
		$p = $d . '/' . $f;
		if ( is_link( $p ) || ( file_exists( $p ) && ! is_dir( $p ) && ! is_file( $p ) ) || realpath( $p ) !== realpath( $d ) . DIRECTORY_SEPARATOR . $f ) { @unlink( $p ) || @rmdir( $p ); continue; }
		t_rm( $p );
	}
	@rmdir( $d );
}
register_shutdown_function( function () use ( $T_BASE ) { t_rm( $T_BASE ); } );
function t_put( $path, $body ) { if ( ! is_dir( dirname( $path ) ) ) { mkdir( dirname( $path ), 0777, true ); } file_put_contents( $path, $body ); }
function t_big( $path, $bytes ) { if ( ! is_dir( dirname( $path ) ) ) { mkdir( dirname( $path ), 0777, true ); } $h = fopen( $path, 'w' ); ftruncate( $h, $bytes ); fclose( $h ); clearstatcache(); }
// A directory link: a symbolic link where PHP may make one, else a Windows junction (no privilege needed). false when neither.
function t_link_dir( $target, $link ) {
	if ( @symlink( $target, $link ) ) { return true; }
	if ( '\\' !== DIRECTORY_SEPARATOR ) { return false; }
	exec( 'cmd /c mklink /J "' . str_replace( '/', '\\', $link ) . '" "' . str_replace( '/', '\\', $target ) . '" 2>&1', $o, $rc );
	clearstatcache();
	return 0 === $rc && file_exists( $link );
}
function fresh() {
	global $T_BASE;
	t_reset(); t_set_caps( array( 'read', 'manage_options' ) ); t_set_multisite( false ); $GLOBALS['T_user_id'] = 1;
	t_rm( $T_BASE ); mkdir( $T_BASE . '/plugins', 0777, true ); mkdir( $T_BASE . '/themes', 0777, true ); mkdir( $T_BASE . '/uploads', 0777, true );
	$GLOBALS['T_upload_basedir'] = $T_BASE . '/uploads'; $GLOBALS['T_theme_root'] = $T_BASE . '/themes';
	$GLOBALS['T_clock'] = 1700000000.5;
}
$GLOBALS['d5b_maint_seams'] = array( 'now' => function () { return $GLOBALS['T_clock']; } );
function store() { return $GLOBALS['T_BASE'] . '/uploads/d5b-history'; }
function row_dirs( $id ) { return array_values( array_filter( (array) @scandir( store() ), function ( $f ) use ( $id ) { return 1 === preg_match( '/^' . $id . '(-[0-9a-f]{32})?$/D', (string) $f ); } ) ); }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : null; }
$GLOBALS['T_BASE'] = $T_BASE;

// ---- the page answers: the path of a url (without the cache-buster) => array( code, body ), or null for no answer
function page( $title = 'T', $size = 50000 ) { $h = '<html><head><title>' . $title . '</title></head><body>'; return $h . str_repeat( 'x', max( 0, $size - strlen( $h ) - 14 ) ) . '</body></html>'; }
function serve( array $answers, float $step = 0.0 ) {
	$GLOBALS['T_http_fn'] = function ( $url, $args ) use ( $answers, $step ) {
		$GLOBALS['T_clock'] += $step;
		$base = preg_replace( '/[?&]d5b=[0-9]+$/', '', $url );
		$a = $answers[ $base ] ?? null;
		if ( is_callable( $a ) ) { $a = $a(); }
		return null === $a ? null : array( 'code' => $a[0], 'body' => $a[1] );
	};
}
function ok( $u, $bytes = 50000 ) { return array( 'url' => $u, 'status' => 200, 'bytes' => $bytes, 'closed' => true, 'fatal' => '', 'title' => 'T', 'skipped' => false ); }

echo "watched pages\n";
fresh();
foreach ( array( 1 => '2026-10-01', 2 => '2026-10-09', 3 => '2026-10-03', 4 => '2026-10-08', 5 => '2026-10-05', 6 => '2026-10-07', 7 => '2026-10-02' ) as $i => $d ) {
	t_add_post( array( 'ID' => 200 + $i, 'post_type' => 'page', 'post_modified_gmt' => $d . ' 10:00:00' ) );
}
t_add_post( array( 'ID' => 300, 'post_type' => 'page', 'post_status' => 'draft', 'post_modified_gmt' => '2026-10-30 10:00:00' ) );
t_add_post( array( 'ID' => 310, 'post_type' => 'post' ) ); t_add_post( array( 'ID' => 311, 'post_type' => 'post' ) ); t_add_post( array( 'ID' => 312, 'post_type' => 'post', 'post_status' => 'draft' ) );
$w = d5b_pages_watched();
$plain = array_map( function ( $u ) { return preg_replace( '/[?&]d5b=[0-9]+$/', '', $u ); }, $w );
check( 'watched pages: home + 5 pages + 1 post, at most 7, cache-buster present: home, the five most recently modified published pages, the newest published post',
	array( 'https://x.example/', 'https://x.example/?p=202', 'https://x.example/?p=204', 'https://x.example/?p=206', 'https://x.example/?p=205', 'https://x.example/?p=203', 'https://x.example/?p=311' ) === $plain, $plain );
check( '... every url carries d5b=<time> (the clock, in milliseconds)', 7 === count( array_filter( $w, function ( $u ) { return 1 === preg_match( '/[?&]d5b=1700000000500$/', $u ); } ) ), $w );
check( '... the home page gets ?d5b=', 'https://x.example/?d5b=1700000000500' === $w[0], $w[0] );
fresh();
t_add_post( array( 'ID' => 401, 'post_type' => 'page' ) );
$w = d5b_pages_watched();
check( 'a site with one page and no post: home and that page only', 2 === count( $w ) && false !== strpos( $w[1], '?p=401&d5b=' ), $w );

echo "pages, one round\n";
fresh();
serve( array(
	'https://x.example/a' => array( 200, page( '  Home  ' ) ),
	'https://x.example/b' => array( 500, '<p>There has been a critical error on this website.</p>' ),
	'https://x.example/c' => array( 200, '<html><b>Fatal error</b>: Uncaught Error</html>' ),
	'https://x.example/d' => array( 200, '<html><head><title>Cut</title></head><body>half' ),
	'https://x.example/e' => null,
	'https://x.example/f' => array( 200, page() ),
), 12.0 );
$urls = array( 'https://x.example/a?d5b=1', 'https://x.example/b?d5b=1', 'https://x.example/c?d5b=1', 'https://x.example/d?d5b=1', 'https://x.example/e?d5b=1', 'https://x.example/f?d5b=1' );
$r = d5b_pages_round( $urls );
$by = array(); foreach ( $r as $p ) { $by[ substr( preg_replace( '/\?.*$/', '', $p['url'] ), -1 ) ] = $p; }
check( 'round: one entry per url, each with url, status, bytes, closed, fatal, title, skipped', 6 === count( $r ) && array( 'url', 'status', 'bytes', 'closed', 'fatal', 'title', 'skipped' ) === array_keys( $r[0] ), $r[0] );
check( '... a healthy page: 200, closed, no fatal, the title trimmed, bytes counted', 200 === $by['a']['status'] && true === $by['a']['closed'] && '' === $by['a']['fatal'] && 'Home' === $by['a']['title'] && strlen( page( '  Home  ' ) ) === $by['a']['bytes'], $by['a'] );
check( '... a page answering 500 with WordPress\'s critical error: status 500, the fatal text', 500 === $by['b']['status'] && 0 === strpos( $by['b']['fatal'], 'There has been a critical error' ), $by['b'] );
check( '... a PHP fatal printed into a 200 page is a fatal', 200 === $by['c']['status'] && 'Fatal error</b>' === $by['c']['fatal'], $by['c'] );
check( '... a cut-off page (no </html>) is not closed', false === $by['d']['closed'] && 'Cut' === $by['d']['title'], $by['d'] );
check( '... a page that does not answer: status 0, not skipped', 0 === $by['e']['status'] && false === $by['e']['skipped'] && 0 === $by['e']['bytes'], $by['e'] );
check( '... a skipped page past the budget (fake clock, 12 s a page, 60 s): the sixth page is not fetched', true === $by['f']['skipped'] && 0 === $by['f']['status'] && 5 === count( $GLOBALS['T_http_calls'] ), array( $by['f'], count( $GLOBALS['T_http_calls'] ) ) );
check( '... the url in the result is the url given', 'https://x.example/a?d5b=1' === $r[0]['url'] );
$c0 = $GLOBALS['T_http_calls'][0];
check( '... each fetch: timeout 15, a fresh cache-buster (the clock at that moment, not the one given)', 15 === $c0[1]['timeout'] && 'https://x.example/a?d5b=1700000000500' === $c0[0] && 'https://x.example/b?d5b=1700000012500' === $GLOBALS['T_http_calls'][1][0], $GLOBALS['T_http_calls'][1][0] );
check( '... sslverify as the loopback allows (https_local_ssl_verify, false by default)', false === $c0[1]['sslverify'] );
fresh(); serve( array( 'https://x.example/a' => array( 200, page() ) ), 0.0 );
check( 'a round inside its budget skips nothing', array( false ) === array_column( d5b_pages_round( array( 'https://x.example/a' ), 60 ), 'skipped' ) );
$h = d5b_maint_page_health( '/x', 200, '<html><p>How to fix a fatal error in PHP</p></html>' );
check( 'the words "fatal error" in normal text are not a fatal', '' === $h['fatal'] );
check( 'the maintenance page is recognised', '' !== d5b_maint_page_health( '/x', 503, 'Briefly unavailable for scheduled maintenance. Check back in a minute.' )['fatal'] );
check( 'a parse error and a database error are recognised', '' !== d5b_maint_page_health( '/x', 200, '<b>Parse error</b>: syntax' )['fatal'] && '' !== d5b_maint_page_health( '/x', 500, 'Error establishing a database connection' )['fatal'] );

echo "pages, compare (site-updates-test.js, ported)\n";
check( 'compare: same before and after: no problems', array() === d5b_pages_compare( array( ok( '/a' ), ok( '/b' ) ), array( ok( '/a' ), ok( '/b', 49000 ) ) ) );
$p = d5b_pages_compare( array( ok( '/a' ) ), array( array_merge( ok( '/a' ), array( 'status' => 500 ) ) ) );
check( 'a page that now answers 500 (the script\'s sentence)', array( '/a: answered 500 (was 200)' ) === $p, $p );
$p = d5b_pages_compare( array( ok( '/a' ) ), array( array_merge( ok( '/a' ), array( 'status' => 0, 'bytes' => 0, 'closed' => false ) ) ) );
check( 'a page that no longer answers at all', array( '/a: answered nothing (was 200)' ) === $p, $p );
$p = d5b_pages_compare( array( ok( '/a' ) ), array( array_merge( ok( '/a' ), array( 'fatal' => 'Fatal error</b>' ) ) ) );
check( 'a page that shows a fatal error with status 200', array( '/a: shows "Fatal error</b>"' ) === $p, $p );
$p = d5b_pages_compare( array( ok( '/a' ) ), array( array_merge( ok( '/a' ), array( 'closed' => false ) ) ) );
check( 'a page that is cut off', array( '/a: the page is cut off (no closing </html>)' ) === $p, $p );
$p = d5b_pages_compare( array( ok( '/a', 50000 ) ), array( ok( '/a', 20000 ) ) );
check( 'a page that lost more than half its content', array( '/a: the page shrank from 50000 to 20000 characters' ) === $p, $p );
check( '... but a small change in size is fine', array() === d5b_pages_compare( array( ok( '/a', 50000 ) ), array( ok( '/a', 30000 ) ) ) );
check( 'shrink below half only over 2000 bytes: 2000 -> 100 is not blamed, 2001 -> 1000 is', array() === d5b_pages_compare( array( ok( '/a', 2000 ) ), array( ok( '/a', 100 ) ) ) && 1 === count( d5b_pages_compare( array( ok( '/a', 2001 ) ), array( ok( '/a', 1000 ) ) ) ) );
check( 'a page that was already broken before is not blamed (bad-before not blamed)',
	array() === d5b_pages_compare( array( array_merge( ok( '/a' ), array( 'status' => 404 ) ) ), array( array_merge( ok( '/a' ), array( 'status' => 404 ) ) ) )
	&& array() === d5b_pages_compare( array( array_merge( ok( '/a' ), array( 'fatal' => 'x' ) ) ), array( array_merge( ok( '/a' ), array( 'status' => 500 ) ) ) )
	&& array() === d5b_pages_compare( array( array_merge( ok( '/a' ), array( 'closed' => false ) ) ), array( array_merge( ok( '/a' ), array( 'status' => 500 ) ) ) ) );
check( 'each bad page is reported once', 1 === count( d5b_pages_compare( array( ok( '/a' ), ok( '/b' ) ), array( array_merge( ok( '/a' ), array( 'status' => 500, 'fatal' => 'x', 'closed' => false ) ), ok( '/b' ) ) ) ) );
check( 'a page skipped in the after round (out of time) is not blamed', array() === d5b_pages_compare( array( ok( '/a' ) ), array( array_merge( ok( '/a' ), array( 'status' => 0, 'skipped' => true ) ) ) ) );
check( 'a page only in the after round is not compared', array() === d5b_pages_compare( array( ok( '/a' ) ), array( array_merge( ok( '/z' ), array( 'status' => 500 ) ) ) ) );

echo "pages, the check around a change\n";
fresh(); serve( array() );
$ran = 0;
$c = d5b_pages_check( function () use ( &$ran ) { $ran++; } );
check( 'check: before all failed -> unavailable true, change still ran, after empty', true === $c['unavailable'] && 1 === $ran && array() === $c['after'] && array() === $c['problems'] && 1 === count( $c['before'] ) && 1 === count( $GLOBALS['T_http_calls'] ), array( $c, $ran ) );
check( '... the result has exactly before, after, problems, unavailable', array( 'before', 'after', 'problems', 'unavailable' ) === array_keys( $c ) );
fresh();
t_add_post( array( 'ID' => 501, 'post_type' => 'page' ) );
$broken = false; $order = array();
serve( array(
	'https://x.example/'       => function () use ( &$broken, &$order ) { $order[] = 'fetch'; return $broken ? array( 500, 'There has been a critical error on this website.' ) : array( 200, page() ); },
	'https://x.example/?p=501' => function () { return null; },
) );
$c = d5b_pages_check( function () use ( &$broken, &$order ) { $order[] = 'change'; $broken = true; } );
check( 'a home page that breaks during the change: one problem, not unavailable (one page answered before)', false === $c['unavailable'] && array( 'https://x.example/?d5b=1700000000500: answered 500 (was 200)' ) === $c['problems'] && 2 === count( $c['after'] ), $c['problems'] );
check( '... the change ran between the two rounds', array( 'fetch', 'change', 'fetch' ) === $order, $order );
check( '... the page that never answered is not blamed', 1 === count( $c['problems'] ) );

echo "keep a copy\n";
fresh();
t_put( WP_PLUGIN_DIR . '/my-folder/plugin-slug.php', '<?php // Plugin Name: Slug' );
t_put( WP_PLUGIN_DIR . '/my-folder/sub/inner.txt', 'inner text' );
$k = d5b_keep_copy( 'plugin', 'my-folder', 41 );
check( 'keep_copy: a plugin whose folder differs from its slug copies the folder', is_array( $k ) && 1 === preg_match( '#^' . preg_quote( store(), '#' ) . '/41-[0-9a-f]{32}/plugin/my-folder$#', $k['path'] ) && is_dir( $k['path'] ), $k );
check( '... byte-identical files, nested ones included', is_array( $k ) && '<?php // Plugin Name: Slug' === @file_get_contents( $k['path'] . '/plugin-slug.php' ) && 'inner text' === @file_get_contents( $k['path'] . '/sub/inner.txt' ) );
check( '... bytes and files counted', is_array( $k ) && 2 === $k['files'] && strlen( '<?php // Plugin Name: Slug' ) + strlen( 'inner text' ) === $k['bytes'] && array( 'path', 'bytes', 'files' ) === array_keys( $k ), $k );
check( '... made with copy_dir, the file system set up', array( 'copy_dir' ) === array_unique( array_column( $GLOBALS['T_copy_calls'], 0 ) ) );
check( '... the store is closed to the web', is_file( store() . '/.htaccess' ) && is_file( store() . '/index.html' ) );
t_put( WP_PLUGIN_DIR . '/hello.php', '<?php // Plugin Name: Hello' );
$k = d5b_keep_copy( 'plugin', 'hello.php', 42 );
check( 'a single-file plugin copies the file (path is the copy of that file)', is_array( $k ) && 1 === preg_match( '#/42-[0-9a-f]{32}/plugin/hello\.php$#', $k['path'] ) && is_file( $k['path'] ) && '<?php // Plugin Name: Hello' === file_get_contents( $k['path'] ) && 1 === $k['files'], $k );
t_put( $T_BASE . '/themes/my-theme/style.css', '/* Theme Name: Mine */' );
$k = d5b_keep_copy( 'theme', 'my-theme', 43 );
check( 'a theme folder is copied from the theme root', is_array( $k ) && 1 === preg_match( '#/43-[0-9a-f]{32}/theme/my-theme$#', $k['path'] ) && '/* Theme Name: Mine */' === @file_get_contents( $k['path'] . '/style.css' ), $k );
check( 'the store size is the sum of the kept files (and the store\'s own two files)', d5b_history_store_bytes() === 26 + 10 + 27 + 22 + (int) filesize( store() . '/.htaccess' ), d5b_history_store_bytes() );

fresh();
t_big( WP_PLUGIN_DIR . '/huge/huge.bin', 536870913 );
$GLOBALS['T_copy_calls'] = array();
$k = d5b_keep_copy( 'plugin', 'huge', 44 );
t_rm( WP_PLUGIN_DIR . '/huge' );
check( 'over cap -> copy_too_big and nothing written', 'copy_too_big' === code( $k ) && array() === row_dirs( 44 ) && array() === $GLOBALS['T_copy_calls'] && 536870913 === ( $k->get_error_data()['bytes'] ?? null ), is_wp_error( $k ) ? $k->get_error_data() : $k );

fresh();
mkdir( store(), 0777, true );
t_big( store() . '/7-' . str_repeat( 'a', 32 ) . '/plugin/old/big.bin', 2147483648 - 100 );
t_put( WP_PLUGIN_DIR . '/p150/p.php', str_repeat( 'x', 150 ) );
t_put( WP_PLUGIN_DIR . '/p50/p.php', str_repeat( 'x', 50 ) );
$store_seen = d5b_history_store_bytes();
$k150 = d5b_keep_copy( 'plugin', 'p150', 45 );
$k50  = d5b_keep_copy( 'plugin', 'p50', 46 );
t_rm( store() . '/7-' . str_repeat( 'a', 32 ) );
check( 'store full -> store_full (the store plus the new copy over 2 GB), nothing written', 'store_full' === code( $k150 ) && array() === row_dirs( 45 ) && ( $k150->get_error_data()['store_bytes'] ?? null ) === $store_seen, is_wp_error( $k150 ) ? $k150->get_error_data() : $k150 );
check( '... a copy that still fits is kept (the store counts nested folders: ' . $store_seen . ' bytes)', is_array( $k50 ) && $store_seen >= 2147483648 - 100, $k50 );

fresh();
t_put( WP_PLUGIN_DIR . '/two/a.php', 'a' ); t_put( WP_PLUGIN_DIR . '/two/b.php', 'b' );
$GLOBALS['T_copy_fail'] = true;
$k = d5b_keep_copy( 'plugin', 'two', 47 );
check( 'copy failure -> copy_failed, the half-made copy removed', 'copy_failed' === code( $k ) && array() === row_dirs( 47 ), array( code( $k ), row_dirs( 47 ) ) );
$GLOBALS['T_copy_fail'] = false; $GLOBALS['T_fs_fail'] = true; unset( $GLOBALS['wp_filesystem'] );
$k = d5b_keep_copy( 'plugin', 'two', 48 );
check( 'no file system access (WP_Filesystem fails) -> copy_failed', 'copy_failed' === code( $k ) && array() === row_dirs( 48 ), code( $k ) );
$GLOBALS['T_fs_fail'] = false;
t_put( WP_PLUGIN_DIR . '/solo.php', 'x' ); $GLOBALS['T_fs_copy_fail'] = true;
$k = d5b_keep_copy( 'plugin', 'solo.php', 49 );
check( 'a single file that cannot be copied -> copy_failed, nothing left', 'copy_failed' === code( $k ) && array() === row_dirs( 49 ), code( $k ) );
$GLOBALS['T_fs_copy_fail'] = false;
check( 'a missing plugin, a name with a slash, a theme that is a file, a row id of 0: copy_failed',
	'copy_failed' === code( d5b_keep_copy( 'plugin', 'nope', 50 ) ) && 'copy_failed' === code( d5b_keep_copy( 'plugin', '../plugins', 50 ) )
	&& 'copy_failed' === code( d5b_keep_copy( 'theme', 'x.php', 50 ) ) && 'copy_failed' === code( d5b_keep_copy( 'plugin', 'two', 0 ) ) && 'copy_failed' === code( d5b_keep_copy( 'widget', 'two', 50 ) ) );
mkdir( $T_BASE . '/outside', 0777, true ); t_put( $T_BASE . '/outside/secret.txt', 'outside' );
$linked = t_link_dir( $T_BASE . '/outside', WP_PLUGIN_DIR . '/two/linked' );
$k = d5b_keep_copy( 'plugin', 'two', 51 );
check( 'a plugin folder holding a link is refused (copy_failed), the link not followed', $linked && 'copy_failed' === code( $k ) && array() === row_dirs( 51 ), array( $linked, code( $k ) ) );

check( 'the three copy errors have their HTTP status (413, 507, 503), the same in the table and in the error data',
	413 === d5b_ability_http_status( 'copy_too_big' ) && 507 === d5b_ability_http_status( 'store_full' ) && 503 === d5b_ability_http_status( 'copy_failed' )
	&& is_wp_error( $k150 ) && 507 === ( $k150->get_error_data()['status'] ?? null ) && 503 === ( d5b_keep_copy( 'plugin', 'nope', 52 )->get_error_data()['status'] ?? null ) );

echo "files_remove (recursive)\n";
fresh();
$row = store() . '/60-' . str_repeat( 'b', 32 );
t_put( $row . '/plugin/slug/sub/file.txt', 'nested' ); t_put( $row . '/plugin/slug/top.php', 'top' );
mkdir( $T_BASE . '/target', 0777, true ); t_put( $T_BASE . '/target/keep.txt', 'keep me' );
$linked = t_link_dir( $T_BASE . '/target', $row . '/plugin/slug/sub/link' );
t_put( store() . '/600-' . str_repeat( 'c', 32 ) . '/x.txt', 'another row' );
t_put( store() . '/60/flat.txt', 'flat' );
$ok = d5b_history_files_remove( 60 );
clearstatcache();
check( 'files_remove: a nested <kind>/<slug>/sub/file is removed', true === $ok && ! file_exists( $row ) && array() === row_dirs( 60 ), array( $ok, row_dirs( 60 ) ) );
check( '... a symlink inside is unlinked, never followed (target untouched)', $linked && 'keep me' === @file_get_contents( $T_BASE . '/target/keep.txt' ), $linked );
check( '... another row (600) is untouched', 'another row' === @file_get_contents( store() . '/600-' . str_repeat( 'c', 32 ) . '/x.txt' ) );
t_put( store() . '/61/a.txt', 'a' ); t_put( store() . '/61-' . str_repeat( 'd', 32 ) . '/b.txt', 'b' );
check( 'flat files: removed as before', true === d5b_history_files_remove( 61 ) && array() === row_dirs( 61 ) );
t_put( store() . '/62-' . str_repeat( 'e', 32 ) . '/a.txt', 'a' ); t_put( store() . '/62-' . str_repeat( 'f', 32 ) . '/b.txt', 'b' );
d5b_history_files_remove( 62, '62-' . str_repeat( 'e', 32 ) );
check( 'only = one folder: that folder goes, the row\'s other folder stays', array( '62-' . str_repeat( 'f', 32 ) ) === row_dirs( 62 ), row_dirs( 62 ) );
$linked2 = t_link_dir( $T_BASE . '/target', store() . '/63-' . str_repeat( 'a', 32 ) );
check( 'a row folder that is itself a link: the link goes, the target stays', $linked2 && true === d5b_history_files_remove( 63 ) && array() === row_dirs( 63 ) && 'keep me' === @file_get_contents( $T_BASE . '/target/keep.txt' ) );
fresh();
check( 'the store size of a site with no store is 0', 0 === d5b_history_store_bytes() );
mkdir( store() . '/64-' . str_repeat( 'a', 32 ), 0777, true ); t_put( store() . '/64-' . str_repeat( 'a', 32 ) . '/f.txt', '12345' );
mkdir( $T_BASE . '/target', 0777, true ); t_put( $T_BASE . '/target/keep.txt', str_repeat( 'k', 1000 ) );
t_link_dir( $T_BASE . '/target', store() . '/64-' . str_repeat( 'a', 32 ) . '/link' );
check( 'the store size does not follow a link', 5 === d5b_history_store_bytes(), d5b_history_store_bytes() );

echo "self\n";
check( 'self: divi5-builder/divi5-builder.php and each legacy file true; others false',
	d5b_maint_self( 'divi5-builder/divi5-builder.php' )
	&& d5b_maint_self( 'divi5-builder-rest/divi5-builder-rest.php' ) && d5b_maint_self( 'divi5-builder-rest.php' )
	&& d5b_maint_self( 'wp-media-audit/wp-media-audit.php' ) && d5b_maint_self( 'wp-media-audit.php' )
	&& d5b_maint_self( 'wp-media-quarantine/wp-media-quarantine.php' ) && d5b_maint_self( 'wp-media-quarantine.php' )
	&& d5b_maint_self( 'wp-site-updates/wp-site-updates.php' ) && d5b_maint_self( 'wp-site-updates.php' )
	&& ! d5b_maint_self( 'hello.php' ) && ! d5b_maint_self( 'akismet/akismet.php' ) && ! d5b_maint_self( 'divi5-builder/other.php' ) && ! d5b_maint_self( '' ) );
check( '... this copy where it is installed (a renamed folder) is self too', d5b_maint_self( 'divi5-builder-copy/divi5-builder.php' ) );
check( '... a different case or a backslash is still self', d5b_maint_self( 'Divi5-Builder\\divi5-builder.php' ) && d5b_maint_self( '/divi5-builder/divi5-builder.php' ) );

echo "human check\n";
$h = d5b_human_check();
check( 'the human check is one fixed sentence: browser and phone, the five things, the maintainer\'s words, no em dash',
	$h === d5b_human_check() && false !== stripos( $h, 'browser' ) && false !== stripos( $h, 'phone' ) && false !== stripos( $h, 'layout' ) && false !== stripos( $h, 'images' ) && false !== stripos( $h, 'console' ) && false !== stripos( $h, 'forms' ) && false !== stripos( $h, 'shortcodes' )
	&& false !== stripos( $h, 'there is no substitute for the human check and it only takes a few seconds' ) && false === strpos( $h, "\u{2014}" ), $h );

echo "rate\n";
fresh();
$got = array(); for ( $i = 0; $i < 5; $i++ ) { $got[] = d5b_maint_rate( 'reset_user_7', 3, 600 ); }
check( 'rate: limit N in window allows N then refuses', array( true, true, true, false, false ) === $got, $got );
check( '... another key has its own count', true === d5b_maint_rate( 'email_test', 3, 600 ) );
$GLOBALS['T_clock'] += 599;
check( '... still refused one second before the window ends', false === d5b_maint_rate( 'reset_user_7', 3, 600 ) );
$GLOBALS['T_clock'] += 1;
$got = array(); for ( $i = 0; $i < 4; $i++ ) { $got[] = d5b_maint_rate( 'reset_user_7', 3, 600 ); }
check( 'a new window allows again (N, then refused)', array( true, true, true, false ) === $got, $got );
fresh();
check( 'a limit of 1 in 600 s', true === d5b_maint_rate( 'k', 1, 600 ) && false === d5b_maint_rate( 'k', 1, 600 ) );

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
