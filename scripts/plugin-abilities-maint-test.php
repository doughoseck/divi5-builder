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
	$GLOBALS['T_clock'] = 1700000000.5; $GLOBALS['T_sleeps'] = array();
}
// The clock, and the sleep between retries (recorded, moves the clock, never waits).
$GLOBALS['d5b_maint_seams'] = array(
	'now'   => function () { return $GLOBALS['T_clock']; },
	'sleep' => function ( $s ) { $GLOBALS['T_sleeps'][] = $s; $GLOBALS['T_clock'] += $s; },
);
$GLOBALS['T_sleeps'] = array();
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
	// core's column post_modified; the _gmt default is the same for all, so id order would not pass.
	t_add_post( array( 'ID' => 200 + $i, 'post_type' => 'page', 'post_modified' => $d . ' 10:00:00' ) );
}
t_add_post( array( 'ID' => 300, 'post_type' => 'page', 'post_status' => 'draft', 'post_modified' => '2026-10-30 10:00:00' ) );
// The newest post by post_date is 310, not the higher id 311.
t_add_post( array( 'ID' => 310, 'post_type' => 'post', 'post_date' => '2026-10-20 10:00:00' ) ); t_add_post( array( 'ID' => 311, 'post_type' => 'post', 'post_date' => '2026-10-05 10:00:00' ) );
t_add_post( array( 'ID' => 312, 'post_type' => 'post', 'post_status' => 'draft', 'post_date' => '2026-10-30 10:00:00' ) );
$w = d5b_pages_watched();
$plain = array_map( function ( $u ) { return preg_replace( '/[?&]d5b=[0-9]+$/', '', $u ); }, $w );
check( 'watched pages: home + 5 pages + 1 post, at most 7, cache-buster present: home, the five most recently modified published pages, the newest published post',
	array( 'https://x.example/', 'https://x.example/?p=202', 'https://x.example/?p=204', 'https://x.example/?p=206', 'https://x.example/?p=205', 'https://x.example/?p=203', 'https://x.example/?p=310' ) === $plain, $plain );
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
check( '... each fetch: timeout 15, a fresh cache-buster (the clock at that moment, not the one given)', 15.0 === (float) $c0[1]['timeout'] && 'https://x.example/a?d5b=1700000000500' === $c0[0] && 'https://x.example/b?d5b=1700000012500' === $GLOBALS['T_http_calls'][1][0], $GLOBALS['T_http_calls'][1][0] );
check( '... sslverify as the loopback allows (https_local_ssl_verify, false by default)', false === $c0[1]['sslverify'] );
check( '2.4.1 ... a fetch\'s timeout is min( 15, the budget left ): the fifth page, 48 s in, gets 12 s', array( 15, 15, 15, 15, 12 ) === array_map( function ( $c ) { return (int) round( $c[1]['timeout'] ); }, $GLOBALS['T_http_calls'] ), array_map( function ( $c ) { return $c[1]['timeout']; }, $GLOBALS['T_http_calls'] ) );
// Every fetch takes its whole timeout (a page that never answers): the round still ends within its 60 s.
fresh();
$t0 = $GLOBALS['T_clock'];
$GLOBALS['T_http_fn'] = function ( $url, $args ) { $GLOBALS['T_clock'] += (float) $args['timeout']; return null; };
$r = d5b_pages_round( array( 'https://x.example/a', 'https://x.example/b', 'https://x.example/c', 'https://x.example/d', 'https://x.example/e' ), 60, true );
check( '... pages that each take their whole timeout: the round (retries included) never runs past 60 s', $GLOBALS['T_clock'] - $t0 <= 60.0 && true === end( $r )['skipped'], array( $GLOBALS['T_clock'] - $t0, array_column( $r, 'skipped' ), array_map( function ( $c ) { return $c[1]['timeout']; }, $GLOBALS['T_http_calls'] ) ) );
$GLOBALS['T_http_fn'] = null;
// Less than a second of the budget left: the page is skipped, never fetched with a timeout of a fraction of a second.
fresh(); serve( array( 'https://x.example/a' => array( 200, page() ), 'https://x.example/b' => array( 200, page() ), 'https://x.example/c' => array( 200, page() ), 'https://x.example/d' => array( 200, page() ), 'https://x.example/e' => array( 200, page() ) ), 14.9 );
$r = d5b_pages_round( array( 'https://x.example/a', 'https://x.example/b', 'https://x.example/c', 'https://x.example/d', 'https://x.example/e' ), 60 );
check( '... with less than a second left (59.6 s gone) the page is skipped, not fetched', 4 === count( $GLOBALS['T_http_calls'] ) && true === $r[4]['skipped'], array( count( $GLOBALS['T_http_calls'] ), array_column( $r, 'skipped' ) ) );
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
check( 'a page that lost more than half its content (2.4.1: counted in bytes, and the sentence says bytes)', array( '/a: the page shrank from 50000 to 20000 bytes' ) === $p, $p );
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
check( '... the result has exactly before, after, problems, unavailable, skipped', array( 'before', 'after', 'problems', 'unavailable', 'skipped' ) === array_keys( $c ) && array() === $c['skipped'] );

echo "fix wave M7: pages skipped for time are listed and warned about\n";
fresh();
t_add_post( array( 'ID' => 501, 'post_type' => 'page' ) ); t_add_post( array( 'ID' => 502, 'post_type' => 'page' ) );
$slow = 0.0;
serve( array(
	'https://x.example/'       => function () use ( &$slow ) { $GLOBALS['T_clock'] += $slow; return array( 200, page() ); },
	'https://x.example/?p=501' => function () use ( &$slow ) { $GLOBALS['T_clock'] += $slow; return array( 200, page() ); },
	'https://x.example/?p=502' => function () use ( &$slow ) { $GLOBALS['T_clock'] += $slow; return array( 200, page() ); },
) );
// The change makes every fetch take 40 s: the after round fetches the home page, then runs out of its 60 s for the rest.
$c = d5b_pages_check( function () use ( &$slow ) { $slow = 40.0; } );
$sk = $c['skipped'] ?? null;
check( 'M7 the after round out of time: the pages it did not fetch are in pages.skipped (without the cache-buster), no problem blamed', is_array( $sk ) && 1 <= count( $sk ) && array() === preg_grep( '/d5b=/', $sk ) && in_array( 'https://x.example/?p=501', $sk, true ) && ! in_array( 'https://x.example/', $sk, true ) && array() === $c['problems'], $c );
$w = d5b_pages_warnings( $c );
check( 'M7 ... and a warning names how many and which', 1 === count( $w ) && 0 === strpos( $w[0], count( $sk ) . ' page(s) were not re-checked in time: ' ) && false !== strpos( $w[0], 'https://x.example/?p=501' ), $w );
$w2 = d5b_pages_warnings( $c, true );
check( 'M7 ... with rollback_on_failure a second line says it could not judge them', 2 === count( $w2 ) && false !== strpos( $w2[1], 'rollback_on_failure could not judge' ), $w2 );
check( 'M7 nothing skipped: no warning; unavailable: the no-check warning only', array() === d5b_pages_warnings( array( 'skipped' => array(), 'unavailable' => false ), true ) && 1 === count( d5b_pages_warnings( array( 'skipped' => array(), 'unavailable' => true ), true ) ) );
fresh();
t_add_post( array( 'ID' => 501, 'post_type' => 'page' ) );
$broken = false; $order = array();
serve( array(
	'https://x.example/'       => function () use ( &$broken, &$order ) { $order[] = 'fetch'; return $broken ? array( 500, 'There has been a critical error on this website.' ) : array( 200, page() ); },
	'https://x.example/?p=501' => function () { return null; },
) );
$c = d5b_pages_check( function () use ( &$broken, &$order ) { $order[] = 'change'; $broken = true; } );
check( 'a home page that breaks during the change: one problem, not unavailable (one page answered before)', false === $c['unavailable'] && array( 'https://x.example/?d5b=1700000000500: answered 500 (was 200)' ) === $c['problems'] && 2 === count( $c['after'] ), $c['problems'] );
check( '... the change ran between the two rounds; the before round did not retry, the after round asked the broken page 3 times', array( 'fetch', 'change', 'fetch', 'fetch', 'fetch' ) === $order, $order );
check( '... the page that never answered is not blamed', 1 === count( $c['problems'] ) );

echo "pages, the after round retries\n";
// A page answering 503 then 200: asked again after 6 s, not a problem.
fresh(); $seq = array( array( 200, page() ), array( 503, 'busy' ), array( 200, page() ) ); $calls = 0;
serve( array( 'https://x.example/' => function () use ( &$seq, &$calls ) { return $seq[ min( $calls++, count( $seq ) - 1 ) ]; } ) );
$c = d5b_pages_check( function () {} );
check( 'a page answering 503 then 200 on the retry is not a problem (one wait of 6 s)', array() === $c['problems'] && 200 === $c['after'][0]['status'] && array( 6 ) === $GLOBALS['T_sleeps'] && 3 === $calls, array( $c['problems'], $GLOBALS['T_sleeps'], $calls ) );
fresh(); $seq = array( array( 200, page() ), array( 503, 'busy' ), array( 503, 'busy' ), array( 503, 'busy' ), array( 200, page() ) ); $calls = 0;
serve( array( 'https://x.example/' => function () use ( &$seq, &$calls ) { return $seq[ min( $calls++, count( $seq ) - 1 ) ]; } ) );
$c = d5b_pages_check( function () {} );
check( 'a page answering 503 three times is a problem (asked 3 times in the after round, 2 waits of 6 s, never a fourth)', array( 'https://x.example/?d5b=1700000000500: answered 503 (was 200)' ) === $c['problems'] && array( 6, 6 ) === $GLOBALS['T_sleeps'] && 4 === $calls, array( $c['problems'], $GLOBALS['T_sleeps'], $calls ) );
fresh(); serve( array( 'https://x.example/a' => array( 503, 'busy' ) ) );
d5b_pages_round( array( 'https://x.example/a' ) );
check( 'a round without retry (the before round) asks once and never waits', 1 === count( $GLOBALS['T_http_calls'] ) && array() === $GLOBALS['T_sleeps'] );
fresh(); serve( array( 'https://x.example/a' => array( 503, 'busy' ), 'https://x.example/b' => array( 200, page() ) ), 30.0 );
$r = d5b_pages_round( array( 'https://x.example/a', 'https://x.example/b' ), 60, true );
check( 'the retries count against the budget (30 s a fetch: a, wait, a again, then no time for a third try; b skipped)', array( 6 ) === $GLOBALS['T_sleeps'] && 2 === count( $GLOBALS['T_http_calls'] ) && true === $r[1]['skipped'] && 503 === $r[0]['status'], array( $GLOBALS['T_sleeps'], count( $GLOBALS['T_http_calls'] ), $r ) );

echo "keep a copy\n";
fresh();
t_put( WP_PLUGIN_DIR . '/my-folder/plugin-slug.php', '<?php // Plugin Name: Slug' );
t_put( WP_PLUGIN_DIR . '/my-folder/sub/inner.txt', 'inner text' );
$k = d5b_keep_copy( 'plugin', 'my-folder', 41 );
check( 'keep_copy: a plugin whose folder differs from its slug copies the folder', is_array( $k ) && 1 === preg_match( '#^' . preg_quote( store(), '#' ) . '/41-[0-9a-f]{32}/plugin/my-folder$#', $k['path'] ) && is_dir( $k['path'] ), $k );
check( '... byte-identical files, nested ones included', is_array( $k ) && '<?php // Plugin Name: Slug' === @file_get_contents( $k['path'] . '/plugin-slug.php' ) && 'inner text' === @file_get_contents( $k['path'] . '/sub/inner.txt' ) );
check( '... bytes and files counted', is_array( $k ) && 2 === $k['files'] && strlen( '<?php // Plugin Name: Slug' ) + strlen( 'inner text' ) === $k['bytes'] && array( 'path', 'bytes', 'files', 'manifest_sha1' ) === array_keys( $k ), $k );
// M1: the fingerprint is sha1 of the sorted "path\0sha1\n" lines of the files kept, computed here independently.
$want = sha1( "plugin-slug.php\0" . sha1( '<?php // Plugin Name: Slug' ) . "\nsub/inner.txt\0" . sha1( 'inner text' ) . "\n" );
check( 'M1 ... the kept copy carries manifest_sha1: sha1 of the sorted "path\\0sha1\\n" list of its files', is_array( $k ) && $want === $k['manifest_sha1'], array( $k['manifest_sha1'] ?? null, $want ) );
check( 'M1 ... and its file list beside it (<row>-<hex>/manifest.json), outside the copy itself', is_array( $k ) && is_file( dirname( $k['path'], 2 ) . '/manifest.json' ) && ! file_exists( $k['path'] . '/manifest.json' ) && array( 'plugin-slug.php' => sha1( '<?php // Plugin Name: Slug' ), 'sub/inner.txt' => sha1( 'inner text' ) ) === json_decode( (string) file_get_contents( dirname( $k['path'], 2 ) . '/manifest.json' ), true ) );
$k41 = $k;
check( '... made with copy_dir, the file system set up', array( 'copy_dir' ) === array_unique( array_column( $GLOBALS['T_copy_calls'], 0 ) ) );
check( '... the store is closed to the web', is_file( store() . '/.htaccess' ) && is_file( store() . '/index.html' ) );
t_put( WP_PLUGIN_DIR . '/hello.php', '<?php // Plugin Name: Hello' );
$k = d5b_keep_copy( 'plugin', 'hello.php', 42 );
check( 'a single-file plugin copies the file (path is the copy of that file)', is_array( $k ) && 1 === preg_match( '#/42-[0-9a-f]{32}/plugin/hello\.php$#', $k['path'] ) && is_file( $k['path'] ) && '<?php // Plugin Name: Hello' === file_get_contents( $k['path'] ) && 1 === $k['files'], $k );
t_put( $T_BASE . '/themes/my-theme/style.css', '/* Theme Name: Mine */' );
$k = d5b_keep_copy( 'theme', 'my-theme', 43 );
check( 'a theme folder is copied from the theme root', is_array( $k ) && 1 === preg_match( '#/43-[0-9a-f]{32}/theme/my-theme$#', $k['path'] ) && '/* Theme Name: Mine */' === @file_get_contents( $k['path'] . '/style.css' ), $k );
$manifests = 0; foreach ( glob( store() . '/*/manifest.json' ) as $mf ) { $manifests += (int) filesize( $mf ); }
check( 'the store size is the sum of the kept files (and the store\'s own two files, and each copy\'s file list)', 3 === count( glob( store() . '/*/manifest.json' ) ) && d5b_history_store_bytes() === 26 + 10 + 27 + 22 + (int) filesize( store() . '/.htaccess' ) + $manifests, d5b_history_store_bytes() );
// M1: the check against the row's fingerprint.
check( 'M1 manifest check: an untouched copy passes', true === d5b_maint_manifest_check( $k41['path'], $k41 ) );
t_put( $k41['path'] . '/sub/inner.txt', 'inner TEXT' );
$e = d5b_maint_manifest_check( $k41['path'], $k41 );
check( 'M1 ... a changed file: copy_tampered naming 1 changed', 'copy_tampered' === code( $e ) && array( 1, 0, 0 ) === array( $e->get_error_data()['changed'] ?? -1, $e->get_error_data()['added'] ?? -1, $e->get_error_data()['removed'] ?? -1 ) && false !== strpos( $e->get_error_message(), '1 file(s) changed, 0 added, 0 removed' ), is_wp_error( $e ) ? $e->get_error_message() : $e );
t_put( $k41['path'] . '/sub/inner.txt', 'inner text' ); t_put( $k41['path'] . '/planted.php', '<?php // planted' );
$e = d5b_maint_manifest_check( $k41['path'], $k41 );
check( 'M1 ... a file added: copy_tampered naming 1 added', 'copy_tampered' === code( $e ) && 1 === ( $e->get_error_data()['added'] ?? -1 ) && 0 === ( $e->get_error_data()['changed'] ?? -1 ), is_wp_error( $e ) ? $e->get_error_message() : $e );
unlink( $k41['path'] . '/planted.php' ); unlink( $k41['path'] . '/sub/inner.txt' );
$e = d5b_maint_manifest_check( $k41['path'], $k41 );
check( 'M1 ... a file removed: copy_tampered naming 1 removed', 'copy_tampered' === code( $e ) && 1 === ( $e->get_error_data()['removed'] ?? -1 ), is_wp_error( $e ) ? $e->get_error_message() : $e );
t_put( $k41['path'] . '/sub/inner.txt', 'inner text' );
check( 'M1 ... put back as it was: passes again', true === d5b_maint_manifest_check( $k41['path'], $k41 ) );
file_put_contents( dirname( $k41['path'], 2 ) . '/manifest.json', json_encode( array( 'plugin-slug.php' => sha1( '<?php // Plugin Name: Slug' ), 'sub/inner.txt' => sha1( 'inner text' ), 'planted.php' => sha1( 'x' ) ) ) );
t_put( $k41['path'] . '/planted.php', 'x' );
$e = d5b_maint_manifest_check( $k41['path'], $k41 );
check( 'M1 ... a file added AND written into the file list beside it: still refused (the row\'s fingerprint is what counts)', 'copy_tampered' === code( $e ) && false !== strpos( $e->get_error_message(), 'file list was changed' ), is_wp_error( $e ) ? $e->get_error_message() : $e );
check( 'M1 ... a row with no fingerprint: refused', 'copy_tampered' === code( d5b_maint_manifest_check( $k41['path'], array( 'path' => $k41['path'] ) ) ) );
check( 'M1 copy_tampered has its own status (409)', 409 === d5b_ability_http_status( 'copy_tampered' ) && 409 === d5b_ability_http_status( 'busy' ) );

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
$k_none = d5b_keep_copy( 'plugin', 'p150', 45 );   // no history table rows yet: oldest_row_at null
global $wpdb; $wpdb->tables[ d5b_history_table() ] = array(
	array( 'id' => 9, 'created_at' => '2026-10-20 08:00:00' ), array( 'id' => 7, 'created_at' => '2026-10-04 09:30:00' ), array( 'id' => 12, 'created_at' => '2026-10-30 10:00:00' ),
);
$k150 = d5b_keep_copy( 'plugin', 'p150', 45 );
$k50  = d5b_keep_copy( 'plugin', 'p50', 46 );
t_rm( store() . '/7-' . str_repeat( 'a', 32 ) );
check( 'store_full carries the store size and the OLDEST history row\'s date (oldest_row_at), in the data and the message', is_wp_error( $k150 ) && '2026-10-04 09:30:00' === ( $k150->get_error_data()['oldest_row_at'] ?? null ) && false !== strpos( $k150->get_error_message(), '2026-10-04 09:30:00' ), is_wp_error( $k150 ) ? $k150->get_error_data() : $k150 );
check( '... and oldest_row_at null when there is no history row', 'store_full' === code( $k_none ) && array_key_exists( 'oldest_row_at', $k_none->get_error_data() ) && null === $k_none->get_error_data()['oldest_row_at'], is_wp_error( $k_none ) ? $k_none->get_error_data() : $k_none );
check( 'store full -> store_full (the store plus the new copy over 2 GB), nothing written', 'store_full' === code( $k150 ) && array() === row_dirs( 45 ) && ( $k150->get_error_data()['store_bytes'] ?? null ) === $store_seen, is_wp_error( $k150 ) ? $k150->get_error_data() : $k150 );
check( '... a copy that still fits is kept (the store counts nested folders: ' . $store_seen . ' bytes)', is_array( $k50 ) && $store_seen >= 2147483648 - 100, $k50 );

fresh();
t_put( WP_PLUGIN_DIR . '/two/a.php', 'a' ); t_put( WP_PLUGIN_DIR . '/two/b.php', 'b' );
$GLOBALS['T_copy_fail'] = true;
$k = d5b_keep_copy( 'plugin', 'two', 47 );
check( 'copy failure -> copy_failed, the half-made copy removed', 'copy_failed' === code( $k ) && array() === row_dirs( 47 ), array( code( $k ), row_dirs( 47 ) ) );
// 2.4.1: the plugin writes its own files while they are copied (a cache file, a log). Once: the copy is made again.
fresh();
t_put( WP_PLUGIN_DIR . '/busy/a.php', 'a' ); t_put( WP_PLUGIN_DIR . '/busy/b.log', 'log' );
$writes = 0;
$GLOBALS['T_copy_each'] = function ( $src, $dst ) use ( &$writes ) { if ( 'a.php' === basename( $src ) && $writes < 1 ) { $writes++; file_put_contents( dirname( $src ) . '/b.log', 'log line 2', FILE_APPEND ); } };
$k = d5b_keep_copy( 'plugin', 'busy', 60 );
check( 'a source that changed during the copy (once): the copy is made again and kept whole, as the source is now', is_array( $k ) && 'loglog line 2' === @file_get_contents( $k['path'] . '/b.log' ) && 1 + 13 === $k['bytes'] && 2 === count( array_filter( $GLOBALS['T_copy_calls'], function ( $c ) { return 'copy_dir' === $c[0] && 'busy' === basename( $c[1] ); } ) ) && 1 === count( row_dirs( 60 ) ), is_wp_error( $k ) ? $k->get_error_message() : $k );
// Every time: refused, nothing kept.
fresh();
t_put( WP_PLUGIN_DIR . '/busy/a.php', 'a' ); t_put( WP_PLUGIN_DIR . '/busy/b.log', 'log' );
$GLOBALS['T_copy_each'] = function ( $src, $dst ) { if ( 'a.php' === basename( $src ) ) { file_put_contents( dirname( $src ) . '/b.log', '+', FILE_APPEND ); } };
$k = d5b_keep_copy( 'plugin', 'busy', 61 );
check( '... changing during the second copy too: copy_failed "the plugin changed its own files while they were being copied; try again when it is idle", nothing kept', 'copy_failed' === code( $k ) && false !== strpos( $k->get_error_message(), 'the plugin changed its own files while they were being copied; try again when it is idle' ) && array() === row_dirs( 61 ) && 2 === count( array_filter( $GLOBALS['T_copy_calls'], function ( $c ) { return 'copy_dir' === $c[0] && 'busy' === basename( $c[1] ); } ) ), is_wp_error( $k ) ? $k->get_error_message() : $k );
$GLOBALS['T_copy_each'] = null;
fresh(); t_put( WP_PLUGIN_DIR . '/two/a.php', 'a' ); t_put( WP_PLUGIN_DIR . '/two/b.php', 'b' ); // the section above goes on with these
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
check( 'a plugin folder holding a link is refused (linked, its own code: without_copy never lifts it), the link not followed', $linked && 'linked' === code( $k ) && array() === row_dirs( 51 ), array( $linked, code( $k ) ) );
$linked2 = t_link_dir( $T_BASE . '/outside', WP_PLUGIN_DIR . '/whole-link' );
check( '... a plugin folder that IS a link: linked too; 409', $linked2 && 'linked' === code( d5b_keep_copy( 'plugin', 'whole-link', 52 ) ) && 409 === d5b_ability_http_status( 'linked' ) && array() === row_dirs( 52 ) );

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
// M10: another name for this plugin's own folder (a link or junction to it) is self; a link elsewhere is not.
fresh();
t_put( WP_PLUGIN_DIR . '/divi5-builder-copy/divi5-builder.php', '<?php // Plugin Name: Divi 5 Builder' );
t_put( WP_PLUGIN_DIR . '/other/other.php', '<?php // Plugin Name: Other' );
$l1 = t_link_dir( WP_PLUGIN_DIR . '/divi5-builder-copy', WP_PLUGIN_DIR . '/d5b-alias' );
$l2 = t_link_dir( WP_PLUGIN_DIR . '/other', WP_PLUGIN_DIR . '/other-alias' );
check( 'M10 a folder link to this plugin\'s own folder is self (realpath compared), in any case', $l1 && d5b_maint_self( 'd5b-alias/divi5-builder.php' ) && d5b_maint_self( 'D5B-Alias/divi5-builder.php' ), array( $l1 ) );
check( 'M10 ... a link to another plugin\'s folder, and a plain other plugin, are not', $l2 && ! d5b_maint_self( 'other-alias/other.php' ) && ! d5b_maint_self( 'other/other.php' ) && ! d5b_maint_self( 'nope/nope.php' ) && ! d5b_maint_self( 'hello.php' ), array( $l2 ) );

echo "2.4.1: the lock is a row of the options table (its unique option_name index is the mutex)\n";
// Another request: none of this request's memory of the locks it holds.
function other_request( callable $fn ) { $saved = $GLOBALS['d5b_maint_locks'] ?? array(); $GLOBALS['d5b_maint_locks'] = array(); try { return $fn(); } finally { $GLOBALS['d5b_maint_locks'] = $saved; } }
function take_test() { return d5b_maint_lock_take( 'd5b_maint_lock_test', 120, 'The test' ); }
fresh(); $GLOBALS['d5b_maint_locks'] = array(); $now = (int) floor( $GLOBALS['T_clock'] );
$t1 = take_test();
check( 'lock: taken; its row holds "<random>|<expires at>" (now + 120), not autoloaded; no transient written', is_string( $t1 ) && 1 === preg_match( '/^[0-9a-f]{16}\|' . ( $now + 120 ) . '$/D', $t1 ) && $t1 === ( $GLOBALS['T_options']['d5b_maint_lock_test'] ?? null ) && 'no' === ( $GLOBALS['T_autoload']['d5b_maint_lock_test'] ?? null ) && array() === $GLOBALS['T_transients'], array( $t1, $GLOBALS['T_options']['d5b_maint_lock_test'] ?? null ) );
check( '... taken with one INSERT IGNORE, no read first (the INSERT is the mutex)', 1 === preg_match( '/^INSERT IGNORE INTO `wp_options` /', (string) ( $GLOBALS['wpdb']->queries[0] ?? '' ) ) && 1 === count( $GLOBALS['wpdb']->queries ), $GLOBALS['wpdb']->queries );
$t1b = take_test();
check( 'lock: taken again in the same request (nested) -> the same token, counted', $t1 === $t1b );
check( '... the inner release only counts down (the lock stays)', true === d5b_maint_lock_release( 'd5b_maint_lock_test', $t1b ) && $t1 === d5b_maint_lock_fresh( 'd5b_maint_lock_test' ) );
$o = other_request( 'take_test' );
check( 'lock: another request while it is held -> busy (409) naming the work, the row unchanged', 'busy' === code( $o ) && 409 === ( $o->get_error_data()['status'] ?? 0 ) && 0 === strpos( $o->get_error_message(), 'The test is already running' ) && $t1 === d5b_maint_lock_fresh( 'd5b_maint_lock_test' ), is_wp_error( $o ) ? $o->get_error_message() : $o );
check( '... the outer release deletes the row', true === d5b_maint_lock_release( 'd5b_maint_lock_test', $t1 ) && '' === d5b_maint_lock_fresh( 'd5b_maint_lock_test' ) && ! array_key_exists( 'd5b_maint_lock_test', $GLOBALS['T_options'] ) );
// Held by another request (its row in the database, this request has never seen it).
$GLOBALS['T_options']['d5b_maint_lock_test'] = str_repeat( 'a', 16 ) . '|' . ( $now + 60 );
check( 'lock: a row of another request that has not run out -> busy, the row left as it was', 'busy' === code( take_test() ) && str_repeat( 'a', 16 ) . '|' . ( $now + 60 ) === $GLOBALS['T_options']['d5b_maint_lock_test'] );
// Stale: a request that died; taken over with one conditional UPDATE on the stale value.
$GLOBALS['T_options']['d5b_maint_lock_test'] = str_repeat( 'a', 16 ) . '|' . ( $now - 1 );
$t2 = take_test();
check( 'lock: a row past its time is taken over (UPDATE ... WHERE option_value = the stale value)', is_string( $t2 ) && $t2 === $GLOBALS['T_options']['d5b_maint_lock_test'] && (bool) preg_grep( "/^UPDATE `wp_options` SET option_value = '[0-9a-f]{16}\\|\\d+' WHERE option_name = 'd5b_maint_lock_test' AND option_value = '" . str_repeat( 'a', 16 ) . '\|' . ( $now - 1 ) . "'$/", $GLOBALS['wpdb']->queries ), $t2 );
// A token that is not ours: nothing happens, this request still holds its lock.
check( 'lock: a release with another token does nothing (conditional DELETE: no row matched)', false === d5b_maint_lock_release( 'd5b_maint_lock_test', str_repeat( 'f', 16 ) . '|' . ( $now + 999 ) ) && $t2 === $GLOBALS['T_options']['d5b_maint_lock_test'] );
// Another request takes it over (its stale-lock rule) before this one releases: this one leaves it alone.
$GLOBALS['T_options']['d5b_maint_lock_test'] = 'theirs';
check( 'lock: released only while still ours (conditional DELETE)', false === d5b_maint_lock_release( 'd5b_maint_lock_test', $t2 ) && 'theirs' === d5b_maint_lock_fresh( 'd5b_maint_lock_test' ) && array() === ( $GLOBALS['d5b_maint_locks'] ?? array() ) );
// Two takers at a stale lock, in the same instant: both read the stale value; the other one's UPDATE lands first.
fresh(); $GLOBALS['d5b_maint_locks'] = array(); $now = (int) floor( $GLOBALS['T_clock'] );
$GLOBALS['T_options']['d5b_maint_lock_test'] = str_repeat( 'a', 16 ) . '|' . ( $now - 5 );
$theirs = null;
$GLOBALS['T_db_before']['/^UPDATE `wp_options` SET option_value = /'] = function () use ( &$theirs ) { $theirs = other_request( 'take_test' ); };
$mine = take_test();
check( 'lock: two takers at a stale lock -> exactly one wins (the other request), this one is busy', is_string( $theirs ) && 'busy' === code( $mine ) && $theirs === $GLOBALS['T_options']['d5b_maint_lock_test'] && array() === $GLOBALS['d5b_maint_locks'], array( $theirs, is_wp_error( $mine ) ? $mine->get_error_code() : $mine ) );
// The holder releases between this request's failed INSERT and its read: the INSERT is tried once more.
fresh(); $GLOBALS['d5b_maint_locks'] = array(); $now = (int) floor( $GLOBALS['T_clock'] );
$GLOBALS['T_options']['d5b_maint_lock_test'] = str_repeat( 'a', 16 ) . '|' . ( $now + 60 );
$GLOBALS['T_db_before']['/^SELECT option_value FROM `wp_options` /'] = function () { unset( $GLOBALS['T_options']['d5b_maint_lock_test'] ); };
$t3 = take_test();
check( 'lock: released between the failed INSERT and the read -> the INSERT once more takes it', is_string( $t3 ) && $t3 === $GLOBALS['T_options']['d5b_maint_lock_test'], $t3 );
// A persistent object cache changes nothing: the row is read and written in the database.
fresh(); $GLOBALS['d5b_maint_locks'] = array(); $GLOBALS['T_ext_cache'] = true; $now = (int) floor( $GLOBALS['T_clock'] );
$GLOBALS['T_options']['d5b_maint_lock_test'] = str_repeat( 'c', 16 ) . '|' . ( $now + 60 );
check( 'lock: with a persistent object cache the database row still decides (busy)', 'busy' === code( take_test() ) );
$GLOBALS['T_ext_cache'] = false;
// Network-wide: the MAIN site's options table, the same statements, seen from every site.
fresh(); $GLOBALS['d5b_maint_locks'] = array(); t_set_multisite( true ); switch_to_blog( 2 );
$tn = d5b_maint_lock_take( 'd5b_maint_lock_test', 120, 'The test', true );
check( 'network lock: taken from site 2 into the main site\'s table (wp_options), not site 2\'s (wp_2_options)', is_string( $tn ) && $tn === ( $GLOBALS['T_options']['d5b_maint_lock_test'] ?? null ) && ! isset( $GLOBALS['T_blogs'][2]['d5b_maint_lock_test'] ) && (bool) preg_grep( '/^INSERT IGNORE INTO `wp_options` /', $GLOBALS['wpdb']->queries ), $GLOBALS['wpdb']->queries );
switch_to_blog( 3 );
$on3 = other_request( function () { return d5b_maint_lock_take( 'd5b_maint_lock_test', 120, 'The test', true ); } );
$site3 = other_request( function () { return d5b_maint_lock_take( 'd5b_maint_lock_test', 120, 'The test' ); } );
check( 'network lock: visible from a second site (busy there); a per-site lock of the same name on site 3 is its own row', 'busy' === code( $on3 ) && is_string( $site3 ) && $site3 === ( $GLOBALS['T_blogs'][3]['d5b_maint_lock_test'] ?? null ), array( is_wp_error( $on3 ) ? $on3->get_error_code() : $on3, $site3 ) );
restore_current_blog(); restore_current_blog();
check( '... released by its holder: the main site\'s row is gone', true === d5b_maint_lock_release( 'd5b_maint_lock_test', $tn, true ) && ! isset( $GLOBALS['T_options']['d5b_maint_lock_test'] ) );
t_set_multisite( false );
check( 'no lock code uses a transient any more', 0 === preg_match( '/_transient\(\s*\$name/', (string) file_get_contents( $dir . '/inc/abilities/maint-common.php' ) ) );
check( 'lock: one name per plugin or theme folder (kind + md5 of the folder, any case)', d5b_maint_item_lock( 'plugin', 'Akismet' ) === 'd5b_maint_lock_plugin_' . md5( 'akismet' ) && d5b_maint_item_lock( 'theme', 'akismet' ) !== d5b_maint_item_lock( 'plugin', 'akismet' ) );

echo "2.4.1: a move never replaces or removes what is already at the destination\n";
fresh();
t_put( WP_PLUGIN_DIR . '/mv-src/a.php', 'source' ); t_put( WP_PLUGIN_DIR . '/mv-dst/keep.php', 'already here' );
$mv = d5b_maint_move( WP_PLUGIN_DIR . '/mv-src', WP_PLUGIN_DIR . '/mv-dst' );
check( 'd5b_maint_move to a folder that exists: refused (moved false), the destination and the source both left as they were', array( 'moved' => false, 'leftover' => false ) === $mv && 'already here' === @file_get_contents( WP_PLUGIN_DIR . '/mv-dst/keep.php' ) && 'source' === @file_get_contents( WP_PLUGIN_DIR . '/mv-src/a.php' ) && ! file_exists( WP_PLUGIN_DIR . '/mv-dst/a.php' ), $mv );
t_put( WP_PLUGIN_DIR . '/mv-file.php', 'x' );
check( '... to a file that exists: refused too', array( 'moved' => false, 'leftover' => false ) === d5b_maint_move( WP_PLUGIN_DIR . '/mv-src', WP_PLUGIN_DIR . '/mv-file.php' ) && 'x' === @file_get_contents( WP_PLUGIN_DIR . '/mv-file.php' ) && is_dir( WP_PLUGIN_DIR . '/mv-src' ) );
check( '... to a free name: moved', array( 'moved' => true, 'leftover' => false ) === d5b_maint_move( WP_PLUGIN_DIR . '/mv-src', WP_PLUGIN_DIR . '/mv-new' ) && 'source' === @file_get_contents( WP_PLUGIN_DIR . '/mv-new/a.php' ) );
$mc = (string) file_get_contents( $dir . '/inc/abilities/maint-common.php' );
check( 'the staging and aside names: a dot, the folder, 6 random hex characters (no time, no numbered slots)', false !== strpos( $mc, "'/.d5b-new-' . \$dir . '-' . bin2hex( random_bytes( 3 ) )" ) && false !== strpos( $mc, "'/.d5b-old-' . \$dir . '-' . bin2hex( random_bytes( 3 ) )" ) && false === strpos( $mc, '$i < 100' ) );

echo "the is_super_admin stand-in (as core)\n";
fresh();
$GLOBALS['T_users'][5] = (object) array( 'ID' => 5, 'user_login' => 'editor5' ); $GLOBALS['T_users'][6] = (object) array( 'ID' => 6, 'user_login' => 'boss6' );
t_set_caps( array( 'read', 'delete_users' ) ); $GLOBALS['T_user_id'] = 6; $GLOBALS['T_user_caps'][5] = array( 'read' );
check( 'single site: the current user with delete_users is; user 5 (the target, without it) is not, whatever the current user has', true === is_super_admin() && false === is_super_admin( 5 ) && true === is_super_admin( 6 ) );
t_set_caps( array( 'read' ) ); $GLOBALS['T_user_caps'][5] = array( 'read', 'delete_users' );
check( '... and the other way round: the target has it, the current user has not', false === is_super_admin() && true === is_super_admin( 5 ) );
check( '... an unknown user and nobody signed in: false', false === is_super_admin( 99 ) && ( function () { $GLOBALS['T_user_id'] = 0; $r = is_super_admin(); $GLOBALS['T_user_id'] = 6; return false === $r; } )() );
t_set_multisite( true ); update_site_option( 'site_admins', array( 'boss6' ) );
check( 'multisite: by login in site_admins; the target user 5 is not, user 6 is', false === is_super_admin( 5 ) && true === is_super_admin( 6 ) && true === is_super_admin() );
t_set_multisite( false ); unset( $GLOBALS['T_users'][5], $GLOBALS['T_users'][6] );

echo "human check\n";
$h = d5b_human_check();
check( 'the human check is one fixed sentence: browser and phone, the five things, the maintainer\'s words, no em dash',
	$h === d5b_human_check() && false !== stripos( $h, 'browser' ) && false !== stripos( $h, 'phone' ) && false !== stripos( $h, 'layout' ) && false !== stripos( $h, 'images' ) && false !== stripos( $h, 'console' ) && false !== stripos( $h, 'forms' ) && false !== stripos( $h, 'shortcodes' )
	&& false !== stripos( $h, 'there is no substitute for the human check and it only takes a few seconds' ) && false === strpos( $h, "\u{2014}" ), $h );

echo "rate (2.4.1: one option row per key and window; allowed iff one conditional UPDATE changed a row)\n";
fresh(); // T_clock 1700000000.5: the 600 s window started at 1699999800 (windows start at multiples of their length)
$got = array(); for ( $i = 0; $i < 5; $i++ ) { $got[] = d5b_maint_rate( 'reset_user_7', 3, 600 ); }
check( 'rate: limit N in window allows N then refuses', array( true, true, true, false, false ) === $got, $got );
check( '... the count is the row d5b_rate_<key>_<window start>, not autoloaded; a refused attempt is not counted', '3' === ( $GLOBALS['T_options']['d5b_rate_reset_user_7_1699999800'] ?? null ) && 'no' === ( $GLOBALS['T_autoload']['d5b_rate_reset_user_7_1699999800'] ?? null ) && array() === $GLOBALS['T_transients'], $GLOBALS['T_options'] );
check( '... the statement: UPDATE ... SET option_value = option_value + 1 WHERE option_name = ... AND CAST(option_value AS UNSIGNED) < 3', (bool) preg_grep( "/^UPDATE `wp_options` SET option_value = option_value \\+ 1 WHERE option_name = 'd5b_rate_reset_user_7_1699999800' AND CAST\\(option_value AS UNSIGNED\\) < 3$/", $GLOBALS['wpdb']->queries ) && array() === $GLOBALS['wpdb']->unprepared, array_slice( $GLOBALS['wpdb']->queries, 0, 4 ) );
$mark = null; $GLOBALS['T_db_before']['/^UPDATE `wp_options` SET option_value = option_value \+ 1 /'] = function () use ( &$mark ) { $mark = count( $GLOBALS['T_cache_deletes'] ); };
d5b_maint_rate( 'reset_user_7', 3, 600 );
check( '... and the cached copy of the option is dropped AFTER the UPDATE', null !== $mark && in_array( array( 'd5b_rate_reset_user_7_1699999800', 'options' ), array_slice( $GLOBALS['T_cache_deletes'], $mark ), true ), array( $mark, $GLOBALS['T_cache_deletes'] ) );
check( '... another key has its own count', true === d5b_maint_rate( 'email_test', 3, 600 ) && '1' === ( $GLOBALS['T_options']['d5b_rate_email_test_1699999800'] ?? null ) );
$GLOBALS['T_clock'] = 1699999800 + 599.9;
check( '... still refused at the end of the window', false === d5b_maint_rate( 'reset_user_7', 3, 600 ) );
$GLOBALS['T_clock'] = 1700000400.0;
$got = array(); for ( $i = 0; $i < 4; $i++ ) { $got[] = d5b_maint_rate( 'reset_user_7', 3, 600 ); }
check( 'a new window allows again (N, then refused), in its own row; the previous window\'s row is kept', array( true, true, true, false ) === $got && '3' === ( $GLOBALS['T_options']['d5b_rate_reset_user_7_1700000400'] ?? null ) && isset( $GLOBALS['T_options']['d5b_rate_reset_user_7_1699999800'] ), $got );
$GLOBALS['T_options']['d5b_rate_reset_user_77_1699999800'] = '3'; // a longer key that starts the same: not this key's row
$GLOBALS['T_clock'] = 1700001000.0;
d5b_maint_rate( 'reset_user_7', 3, 600 );
check( 'rows of this key older than 2 windows are removed by the call that opens a window; one window back kept; other keys left', ! isset( $GLOBALS['T_options']['d5b_rate_reset_user_7_1699999800'] ) && isset( $GLOBALS['T_options']['d5b_rate_reset_user_7_1700000400'] ) && isset( $GLOBALS['T_options']['d5b_rate_reset_user_7_1700001000'] ) && isset( $GLOBALS['T_options']['d5b_rate_reset_user_77_1699999800'] ) && isset( $GLOBALS['T_options']['d5b_rate_email_test_1699999800'] ), array_keys( $GLOBALS['T_options'] ) );
// Two "simultaneous" callers at limit - 1: one sequential pair, then one interleaved (the other request's UPDATE lands
// between this request's INSERT and its UPDATE).
fresh();
d5b_maint_rate( 'k2', 3, 600 ); d5b_maint_rate( 'k2', 3, 600 );
check( 'two callers at count limit - 1 -> exactly one passes', array( true, false ) === array( d5b_maint_rate( 'k2', 3, 600 ), d5b_maint_rate( 'k2', 3, 600 ) ) );
fresh();
d5b_maint_rate( 'k3', 3, 600 ); d5b_maint_rate( 'k3', 3, 600 );
$theirs = null;
$GLOBALS['T_db_before']['/^UPDATE `wp_options` SET option_value = option_value \+ 1 /'] = function () use ( &$theirs ) { $theirs = d5b_maint_rate( 'k3', 3, 600 ); };
$mine = d5b_maint_rate( 'k3', 3, 600 );
check( '... interleaved: the other request passes, this one is refused; the count is exactly the limit', true === $theirs && false === $mine && '3' === $GLOBALS['T_options']['d5b_rate_k3_1699999800'], array( $theirs, $mine, $GLOBALS['T_options']['d5b_rate_k3_1699999800'] ?? null ) );
// A second request that opens the same window after this one counted: its INSERT leaves the count alone.
fresh();
$GLOBALS['T_db_before']['/^UPDATE `wp_options` SET option_value = option_value \+ 1 /'] = function () { d5b_maint_rate( 'k4', 1, 600 ); };
check( '... a limit of 1 with two first callers in the same instant: one passes (the INSERT never resets a count)', false === d5b_maint_rate( 'k4', 1, 600 ) && '1' === $GLOBALS['T_options']['d5b_rate_k4_1699999800'] );
fresh();
check( 'a limit of 1 in 600 s', true === d5b_maint_rate( 'k', 1, 600 ) && false === d5b_maint_rate( 'k', 1, 600 ) );

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
