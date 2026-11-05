<?php
/**
 * The GitHub updater (#61, release 2.5.0, Task 2): inc/updater.php answers WordPress's update_plugins_github.com filter
 * from plugin/update.json on the repository's main branch.
 *
 *   php -d max_execution_time=60 scripts/plugin-updater-test.php
 *
 * Loads the real inc/updater.php with WordPress stand-ins (plugin-test-stubs.php with T_PLUGIN_STANDINS: the site transients
 * keep core's expiration; T_http answers every fetch, nothing reaches the network). The must-use cases need a different
 * D5B_FILE / WPMU_PLUGIN_DIR (constants), so the suite runs itself again in a child process for each (--mu, --mu-win,
 * --prefix) and counts the child's checks as its own.
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: only our own plugin file, and never a must-use copy, gets an answer (and nothing is fetched
 * otherwise); the answer is what core 6.4.1's update.php needs (version, package) and only for a NEWER version; the json
 * is refused unless every field is exactly the expected shape (host github.com, our repository's release-asset path for its
 * own tag, a 64-hex sha256); one request per 12 hours (1 hour after a failure), and a failure keeps the last good answer.
 */
$MODE = $argv[1] ?? 'main';
$dir  = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.4.3' );
define( 'T_PLUGIN_STANDINS', true );
$base = str_replace( '\\', '/', sys_get_temp_dir() ) . '/d5b-upd-' . getmypid();
if ( '--mu' === $MODE ) {
	define( 'WP_PLUGIN_DIR', $base . '/plugins' ); define( 'WPMU_PLUGIN_DIR', $base . '/mu-plugins' );
	define( 'D5B_FILE', WPMU_PLUGIN_DIR . '/divi5-builder/divi5-builder.php' );
} elseif ( '--mu-win' === $MODE ) {
	// Windows spelling: backslashes, a doubled separator, a lower-case drive letter in the constant.
	define( 'WP_PLUGIN_DIR', 'C:/site/wp-content/plugins' ); define( 'WPMU_PLUGIN_DIR', 'c:\\site\\wp-content\\mu-plugins\\' );
	define( 'D5B_FILE', 'C:\\site\\wp-content\\\\mu-plugins\\divi5-builder\\divi5-builder.php' );
} elseif ( '--prefix' === $MODE ) {
	// A must-use folder whose name is the start of our folder's path: we are NOT inside it.
	define( 'WP_PLUGIN_DIR', $base . '/plugins' ); define( 'WPMU_PLUGIN_DIR', $base . '/plugins/divi5' );
	define( 'D5B_FILE', WP_PLUGIN_DIR . '/divi5-builder/divi5-builder.php' );
} else {
	define( 'WP_PLUGIN_DIR', $base . '/plugins' ); define( 'WPMU_PLUGIN_DIR', $base . '/mu-plugins' );
	define( 'D5B_FILE', WP_PLUGIN_DIR . '/divi5-builder/divi5-builder.php' );
}
// Every PHP notice or warning is recorded: a refusal that only works by tripping over a missing key is not a check.
$PHP_WARNINGS = array();
set_error_handler( function ( $no, $str, $file = '', $line = 0 ) { if ( ! ( error_reporting() & $no ) ) { return true; } /* silenced with @ (the stand-ins' own clean-up) */ $GLOBALS['PHP_WARNINGS'][] = $str . ' (' . basename( $file ) . ':' . $line . ')'; return true; } );
require __DIR__ . '/plugin-test-stubs.php';
require $dir . '/inc/updater.php';

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 800 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const JSON_URL = 'https://raw.githubusercontent.com/doughoseck/divi5-builder/main/plugin/update.json';
const ASSET    = 'https://github.com/doughoseck/divi5-builder/releases/download/v2026.11.05/divi5-builder.zip';
const OURS     = 'divi5-builder/divi5-builder.php';
const HEADERS  = array( 'Version' => '2.4.3', 'UpdateURI' => 'https://github.com/doughoseck/divi5-builder' );

// The json the build writes (a fake sha256: a hex digest, not a key).
function good( array $over = array() ) {
	return array_merge( array(
		'slug' => 'divi5-builder', 'version' => '2.5.0', 'tag' => 'v2026.11.05', 'download_url' => ASSET,
		'sha256' => str_repeat( '0123456789abcdef', 4 ), 'requires' => '6.0', 'requires_php' => '7.4', 'tested' => '7.1',
		'last_updated' => '2026-11-05', 'changelog_url' => 'https://github.com/doughoseck/divi5-builder/releases/tag/v2026.11.05',
	), $over );
}
function serve( $json, int $code = 200 ) { $GLOBALS['T_http'][ JSON_URL ] = array( 'code' => $code, 'headers' => array( 'content-type' => 'text/plain' ), 'body' => is_string( $json ) ? $json : json_encode( $json ) ); }
function site() { t_reset(); }
function calls() { return count( $GLOBALS['T_http_calls'] ); }
function ask( $file = OURS, $update = false ) { return d5b_updater_filter( $update, HEADERS, $file, array( 'en_US' ) ); }
// Make the cached answer older by $secs (as if that much time had passed since it was fetched).
function age( int $secs ) { $GLOBALS['T_site_transients']['d5b_update_json']['checked'] -= $secs; }
// As core 6.4.1 wp_update_plugins() (update.php) handles one plugin with an Update URI host: the filter's answer is cast to
// an object, needs a version, gets id and plugin set by core, new_version from version, and goes to response only when newer.
function core_handles( $file ) {
	$updates = (object) array( 'response' => array(), 'no_update' => array() );
	$update = apply_update_filter( $file );
	if ( ! $update ) { return $updates; }
	$update = (object) $update;
	if ( ! isset( $update->version ) ) { return $updates; }
	$update->id = HEADERS['UpdateURI']; $update->plugin = $file;
	if ( ! isset( $update->new_version ) ) { $update->new_version = $update->version; }
	if ( version_compare( $update->new_version, HEADERS['Version'], '>' ) ) { $updates->response[ $file ] = $update; } else { $updates->no_update[ $file ] = $update; }
	return $updates;
}
function apply_update_filter( $file ) { return d5b_updater_filter( false, HEADERS, $file, array( 'en_US' ) ); }

// ---------------------------------------------------------------- the must-use runs (child processes)
if ( '--mu' === $MODE || '--mu-win' === $MODE ) {
	site(); serve( good() );
	check( 'd5b_updater_is_mu() is true', true === d5b_updater_is_mu() );
	check( 'a must-use copy gets no offer', false === ask( d5b_updater_our_file() ) && false === ask( OURS ) );
	check( 'a must-use copy fetches nothing', 0 === calls() );
	exit( $failed ? 1 : 0 );
}
if ( '--prefix' === $MODE ) {
	site(); serve( good() );
	check( 'a folder merely starting with the must-use path is not must-use', false === d5b_updater_is_mu() );
	$r = ask();
	check( 'so it still gets the offer', is_array( $r ) && '2.5.0' === $r['version'], $r );
	exit( $failed ? 1 : 0 );
}

echo "our file and the request\n";
site();
check( 'd5b_updater_our_file() is the plugin_basename of D5B_FILE', OURS === d5b_updater_our_file() );
check( 'a normal copy is not must-use', false === d5b_updater_is_mu() );
serve( good() );
$r = ask();
$c = $GLOBALS['T_http_calls'][0] ?? array( '', array() );
check( 'one request, to the update.json on main', 1 === calls() && JSON_URL === $c[0], $GLOBALS['T_http_calls'] );
check( 'the request goes through wp_safe_remote_get (reject_unsafe_urls)', true === ( $c[1]['reject_unsafe_urls'] ?? null ), $c[1] );
check( 'the request has a 5 second timeout', 5 === ( $c[1]['timeout'] ?? null ), $c[1] );
check( 'the request asks for JSON', 'application/json' === ( $c[1]['headers']['Accept'] ?? null ), $c[1] );
check( 'the User-Agent names the plugin, its version and the site as core does', 'divi5-builder/2.4.3; https://x.example/' === ( $c[1]['headers']['User-Agent'] ?? null ), $c[1] );

echo "(a) a newer version is offered\n";
check( 'the answer is an array', is_array( $r ), $r );
$r = (array) $r;
check( 'package is the download_url', ASSET === ( $r['package'] ?? null ), $r );
check( 'version is the json version', '2.5.0' === ( $r['version'] ?? null ), $r );
check( 'slug is divi5-builder', 'divi5-builder' === ( $r['slug'] ?? null ), $r );
check( 'id is the Update URI', 'https://github.com/doughoseck/divi5-builder' === ( $r['id'] ?? null ), $r );
check( 'requires and requires_php come from the json', '6.0' === ( $r['requires'] ?? null ) && '7.4' === ( $r['requires_php'] ?? null ), $r );
check( 'tested comes from the json, url is the release page, icons empty', '7.1' === ( $r['tested'] ?? null ) && good()['changelog_url'] === ( $r['url'] ?? null ) && array() === ( $r['icons'] ?? null ), $r );
site(); serve( good( array( 'changelog_url' => 'https://github.com/doughoseck/divi5-builder/../../evil/repo' ) ) ); $r2 = ask();
check( 'refused/ignored: .. in changelog_url (the offer\'s url is built from the tag, the field is never used)', 'https://github.com/doughoseck/divi5-builder/releases/tag/v2026.11.05' === ( $r2['url'] ?? null ), $r2 );
site(); serve( good() );
$u = core_handles( OURS );
check( "WordPress's contract: core puts the answer in response with new_version and package", isset( $u->response[ OURS ] ) && '2.5.0' === $u->response[ OURS ]->new_version && ASSET === $u->response[ OURS ]->package && OURS === $u->response[ OURS ]->plugin, $u );
site(); serve( good( array( 'version' => '2.4.10' ) ) );
check( '2.4.10 is newer than 2.4.3 (version_compare, not string order)', is_array( ask() ) );

echo "(b) the same or a lower version is not\n";
site(); serve( good( array( 'version' => '2.4.3' ) ) );
check( 'the same version: false', false === ask() );
site(); serve( good( array( 'version' => '2.4.2' ) ) );
check( 'a lower version: false', false === ask() );
site(); serve( good( array( 'version' => '1.9.9' ) ) );
check( 'an older major: false (never a downgrade)', false === ask() );

echo "(c) validation: every guard refuses on its own\n";
site();
check( 'the build\'s json is valid', true === d5b_updater_valid( good() ) );
check( 'a tag with a same-day suffix is valid', true === d5b_updater_valid( good( array( 'tag' => 'v2026.11.05.2', 'download_url' => 'https://github.com/doughoseck/divi5-builder/releases/download/v2026.11.05.2/divi5-builder.zip' ) ) ) );
$bad = array(
	'wrong host'                       => good( array( 'download_url' => 'https://evil.example/doughoseck/divi5-builder/releases/download/v2026.11.05/divi5-builder.zip' ) ),
	'host only ending in github.com'   => good( array( 'download_url' => 'https://notgithub.com/doughoseck/divi5-builder/releases/download/v2026.11.05/divi5-builder.zip' ) ),
	'host github.com as a subdomain'   => good( array( 'download_url' => 'https://github.com.evil.example/doughoseck/divi5-builder/releases/download/v2026.11.05/divi5-builder.zip' ) ),
	'wrong repository path'            => good( array( 'download_url' => 'https://github.com/someone-else/divi5-builder/releases/download/v2026.11.05/divi5-builder.zip' ) ),
	'wrong asset name'                 => good( array( 'download_url' => 'https://github.com/doughoseck/divi5-builder/releases/download/v2026.11.05/other.zip' ) ),
	'http scheme'                      => good( array( 'download_url' => 'http://github.com/doughoseck/divi5-builder/releases/download/v2026.11.05/divi5-builder.zip' ) ),
	'a .. segment'                     => good( array( 'download_url' => 'https://github.com/doughoseck/divi5-builder/releases/download/v2026.11.05/../../../../someone-else/x/releases/download/v2026.11.05/divi5-builder.zip' ) ),
	'user and password'                => good( array( 'download_url' => 'https://u:p@github.com/doughoseck/divi5-builder/releases/download/v2026.11.05/divi5-builder.zip' ) ),
	'a port'                           => good( array( 'download_url' => 'https://github.com:8443/doughoseck/divi5-builder/releases/download/v2026.11.05/divi5-builder.zip' ) ),
	'a query'                          => good( array( 'download_url' => ASSET . '?x=1' ) ),
	'a fragment'                       => good( array( 'download_url' => ASSET . '#x' ) ),
	'download_url not a string'        => good( array( 'download_url' => array( ASSET ) ) ),
	'sha256 of 63 characters'          => good( array( 'sha256' => substr( good()['sha256'], 1 ) ) ),
	'sha256 upper case'                => good( array( 'sha256' => strtoupper( good()['sha256'] ) ) ),
	'sha256 missing'                   => array_diff_key( good(), array( 'sha256' => 1 ) ),
	'slug divi5-builder-pro'           => good( array( 'slug' => 'divi5-builder-pro' ) ),
	'tag not matching the url'         => good( array( 'tag' => 'v2026.11.06' ) ),
	'tag not in the release form'      => good( array( 'tag' => 'latest', 'download_url' => 'https://github.com/doughoseck/divi5-builder/releases/download/latest/divi5-builder.zip' ) ),
	'version with two parts'           => good( array( 'version' => '2.5' ) ),
	'version with a trailing newline'  => good( array( 'version' => "2.5.0\n" ) ),
	'version not a string'             => good( array( 'version' => 250 ) ),
	'requires not a version'           => good( array( 'requires' => 'six' ) ),
	'requires_php not a version'       => good( array( 'requires_php' => '7.4; x' ) ),
	'tested as markup <img src=x onerror=alert(1)>' => good( array( 'tested' => '<img src=x onerror=alert(1)>' ) ),
	'tested missing'                   => array_diff_key( good(), array( 'tested' => 1 ) ),
	'not an array'                     => 'divi5-builder',
);
foreach ( $bad as $why => $json ) {
	site(); serve( $json );
	$v = d5b_updater_valid( $json );
	$a = ask();
	check( 'refused: ' . $why . ' (valid false, no offer)', false === $v && false === $a, array( $v, $a ) );
}

echo "(d) the cache: one request per 12 hours\n";
site(); serve( good() );
$f1 = d5b_updater_fetch();
check( 'a first fetch is not from the cache and is ok', true === $f1['ok'] && false === $f1['from_cache'] && 1 === calls(), $f1 );
check( 'the fetch answers the documented keys', array( 'checked', 'error', 'from_cache', 'json', 'ok' ) === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $f1 ) ), $f1 );
$t = $GLOBALS['T_site_transients']['d5b_update_json'] ?? null;
check( 'the site transient d5b_update_json holds json, checked, ok, error', is_array( $t ) && good() === $t['json'] && true === $t['ok'] && '' === $t['error'] && is_int( $t['checked'] ) && abs( time() - $t['checked'] ) < 5, $t );
check( 'it is stored to outlive the 12 hour window (the last good answer must survive to the next check)', ( $GLOBALS['T_site_transient_ttls']['d5b_update_json'] ?? 0 ) > 12 * HOUR_IN_SECONDS, $GLOBALS['T_site_transient_ttls'] );
ask(); ask();
check( 'more checks within 12 hours make no request', 1 === calls() );
check( 'and they are answered from the cache', true === d5b_updater_fetch()['from_cache'] );
age( 11 * HOUR_IN_SECONDS );
ask();
check( 'still none at 11 hours', 1 === calls() );
d5b_updater_fetch( true );
check( '$force makes one more request', 2 === calls() );
age( 12 * HOUR_IN_SECONDS + 1 );
ask();
check( 'after 12 hours the next check asks again', 3 === calls() );
site(); serve( good() ); ask();
$GLOBALS['T_site_transients']['d5b_update_json']['json']['download_url'] = 'https://evil.example/divi5-builder.zip';
check( 'a cached json is validated again before it is offered', false === ask() && 1 === calls() );

echo "(e) failures: an hour's pause, the last good answer kept\n";
site(); serve( good() ); ask();
age( 12 * HOUR_IN_SECONDS + 1 );
serve( 'Server Error', 500 );
$f = d5b_updater_fetch();
check( 'HTTP 500: ok false', false === $f['ok'] && 2 === calls(), $f );
check( 'HTTP 500: the error names the status', false !== strpos( $f['error'], 'HTTP 500' ), $f );
check( 'HTTP 500: the previous good json is kept', good() === $f['json'] && good() === $GLOBALS['T_site_transients']['d5b_update_json']['json'], $f );
$r = ask();
check( 'and it still answers the filter', is_array( $r ) && '2.5.0' === $r['version'] && ASSET === $r['package'], $r );
check( 'a failure is cached: no request within the hour', 2 === calls() );
age( HOUR_IN_SECONDS - 60 ); ask();
check( 'still none at 59 minutes', 2 === calls() );
age( 61 ); ask();
check( 'after 1 hour the next check asks again', 3 === calls() );

site(); serve( good() ); ask(); age( 12 * HOUR_IN_SECONDS + 1 );
$GLOBALS['T_http'][ JSON_URL ] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$f = d5b_updater_fetch();
check( 'WP_Error: ok false with its message, last good json kept', false === $f['ok'] && false !== strpos( $f['error'], 'Operation timed out' ) && good() === $f['json'], $f );

site(); serve( good() ); ask(); age( 12 * HOUR_IN_SECONDS + 1 );
serve( good( array( 'version' => '2.6.0', 'sha256' => 'nope' ) ) );
$f = d5b_updater_fetch();
check( 'an invalid json after a good one: ok false, the good one kept and offered', false === $f['ok'] && good() === $f['json'] && '2.5.0' === ( ask()['version'] ?? null ), $f );

site(); $GLOBALS['T_http'][ JSON_URL ] = new WP_Error( 'http_request_failed', 'down' );
$f = d5b_updater_fetch();
check( 'a failure with nothing good before: json null, no offer', false === $f['ok'] && null === $f['json'] && false === ask(), $f );

echo "(f) another plugin\n";
site(); serve( good() );
check( "another plugin's file: false", false === ask( 'akismet/akismet.php' ) );
$theirs = array( 'version' => '9.9', 'package' => 'https://example.org/x.zip' );
check( "another plugin's answer passes through untouched", $theirs === ask( 'other/other.php', $theirs ) );
check( 'and nothing is fetched for it', 0 === calls() );

echo "(g) must-use copies (child runs)\n";
foreach ( array( '--mu', '--mu-win', '--prefix' ) as $m ) {
	$p = proc_open( array( PHP_BINARY, '-d', 'max_execution_time=60', __FILE__, $m ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	$out = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); $code = proc_close( $p );
	preg_match_all( '/^  (PASS|FAIL)  (.+)$/m', $out, $mm, PREG_SET_ORDER );
	foreach ( $mm as $x ) { check( $m . ': ' . $x[2], 'PASS' === $x[1] ); }
	check( $m . ': the child ran its checks and exited 0', 0 === $code && count( $mm ) >= 2, $out );
}

echo "(h) a body that is not JSON\n";
site(); serve( '<html>rate limited</html>' );
$f = d5b_updater_fetch();
check( 'invalid JSON body: ok false with an error', false === $f['ok'] && '' !== $f['error'] && null === $f['json'], $f );
check( 'and no offer', false === ask() );

echo "warnings\n";
check( 'no PHP notice or warning in the whole run (every refusal is a deliberate check)', array() === $PHP_WARNINGS, array_slice( array_unique( $PHP_WARNINGS ), 0, 5 ) );

echo "\n" . ( $failed ? "$failed of $n FAILED" : "all $n passed" ) . "\n";
exit( $failed ? 1 : 0 );
