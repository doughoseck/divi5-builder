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
 * Task 3: upgrader_pre_download downloads our verified asset itself (download_url, answered from T_http) and refuses it with
 * d5b_checksum unless its sha256 matches the json's (the temp file deleted); a package of ours with no verified checksum is
 * refused without a download; any other package passes through with no HTTP. plugins_api answers View details for our slug
 * only (plugin_information), from the validated json and readme.txt, every piece of readme text escaped.
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

// ---------------------------------------------------------------- Task 3: the checksum before WordPress unpacks
$ZIP = "PK\x03\x04 a stand-in release asset " . str_repeat( 'divi5-builder ', 40 );
// A site whose cache holds the build's json for $ZIP (fetched once, then the request log cleared). The sha256 is computed
// here at run time from the stand-in body: a digest, never a stored key-shaped string.
function cached( array $over = array() ) { site(); serve( good( array_merge( array( 'sha256' => hash( 'sha256', $GLOBALS['ZIP'] ) ), $over ) ) ); d5b_updater_fetch(); $GLOBALS['T_http_calls'] = array(); }
function asset( $body, int $code = 200, array $headers = array( 'content-type' => 'application/octet-stream' ) ) { $GLOBALS['T_http'][ ASSET ] = array( 'code' => $code, 'headers' => $headers, 'body' => $body ); }
function pre( $package, $reply = false ) { return d5b_updater_pre_download( $reply, $package, null, array( 'plugin' => OURS, 'type' => 'plugin', 'action' => 'update' ) ); }
function urls() { return array_map( function ( $c ) { return $c[0]; }, $GLOBALS['T_http_calls'] ); }
function temps_gone() { clearstatcache(); foreach ( $GLOBALS['T_tempfiles'] as $f ) { if ( file_exists( $f ) ) { return false; } } return array() !== $GLOBALS['T_tempfiles']; }

echo "(i) upgrader_pre_download: our asset is checked against the json's sha256\n";
cached(); asset( $ZIP );
$r = pre( ASSET );
check( '(a) match: the downloaded file path is returned', is_string( $r ) && is_file( $r ) && is_readable( $r ), $r );
check( '(a) match: its bytes are the asset body', is_string( $r ) && $ZIP === @file_get_contents( $r ), $r );
check( '(a) match: one download, of the asset, through download_url with a 300 second timeout (no new json request)', array( array( ASSET, 300 ) ) === $GLOBALS['T_download_calls'] && array( ASSET ) === urls(), array( $GLOBALS['T_download_calls'], urls() ) );
cached(); asset( $ZIP, 200, array( 'content-disposition' => 'attachment; filename=divi5-builder.zip' ) );
$r = pre( ASSET );
check( '(a) match with a Content-Disposition rename (as GitHub sends): the renamed file is returned', is_string( $r ) && 'divi5-builder.zip' === basename( $r ) && $ZIP === @file_get_contents( $r ), $r );
cached(); asset( $ZIP );
add_filter( 'upgrader_pre_download', 'd5b_updater_pre_download', 10, 4 );
$r = apply_filters( 'upgrader_pre_download', false, ASSET, null, array( 'plugin' => OURS ) );
check( '(a) through the filter as WP_Upgrader::download_package applies it: the file path', is_string( $r ) && $ZIP === @file_get_contents( $r ), $r );

$bad = $ZIP; $bad[10] = chr( ord( $bad[10] ) ^ 1 );
cached(); asset( $bad );
$r = pre( ASSET );
check( '(b) one byte changed: WP_Error d5b_checksum', $r instanceof WP_Error && 'd5b_checksum' === $r->get_error_code(), $r );
$msg = $r instanceof WP_Error ? $r->get_error_message() : '';
check( '(b) the message names both hashes by their first 12 characters', false !== strpos( $msg, 'expected ' . substr( hash( 'sha256', $ZIP ), 0, 12 ) . ' ...' ) && false !== strpos( $msg, 'got ' . substr( hash( 'sha256', $bad ), 0, 12 ) . ' ...' ), $msg );
check( '(b) the message says it was not installed', 'The downloaded divi5-builder.zip does not match the checksum published with the release (expected ' . substr( hash( 'sha256', $ZIP ), 0, 12 ) . ' ..., got ' . substr( hash( 'sha256', $bad ), 0, 12 ) . ' ...), so it was not installed.' === $msg, $msg );
check( '(b) the downloaded temp file is gone', 1 === count( $GLOBALS['T_download_calls'] ) && temps_gone(), $GLOBALS['T_tempfiles'] );
cached(); asset( $bad, 200, array( 'content-disposition' => 'attachment; filename=divi5-builder.zip' ) );
$r = pre( ASSET );
check( '(b) mismatch after a Content-Disposition rename: d5b_checksum and the renamed file is gone too', $r instanceof WP_Error && 'd5b_checksum' === $r->get_error_code() && temps_gone(), array( $r, $GLOBALS['T_tempfiles'] ) );
cached(); asset( substr( $ZIP, 0, -1 ) );
check( '(b) a truncated asset: d5b_checksum', 'd5b_checksum' === ( ( $r = pre( ASSET ) ) instanceof WP_Error ? $r->get_error_code() : null ) && temps_gone(), $r );

cached(); asset( $ZIP );
$other = 'https://downloads.wordpress.org/plugin/akismet.5.3.zip';
check( '(c) another package: $reply (false) unchanged', false === pre( $other ) );
check( "(c) another package: another filter's answer passes through untouched", '/tmp/theirs.zip' === pre( $other, '/tmp/theirs.zip' ) );
check( '(c) a package that is not a string passes through', false === pre( array( ASSET ) ) && false === pre( null ) );
check( '(c) another package: no HTTP and no download', array() === $GLOBALS['T_http_calls'] && array() === $GLOBALS['T_download_calls'], array( urls(), $GLOBALS['T_download_calls'] ) );
check( '(c) a github.com asset of another repository passes through', false === pre( 'https://github.com/someone-else/divi5-builder/releases/download/v2026.11.05/divi5-builder.zip' ) && array() === $GLOBALS['T_download_calls'] );

site(); $GLOBALS['T_http'][ JSON_URL ] = new WP_Error( 'http_request_failed', 'down' ); asset( $ZIP );
$r = pre( ASSET );
check( '(d) no json was ever cached and the request fails: our asset is refused with d5b_checksum', $r instanceof WP_Error && 'd5b_checksum' === $r->get_error_code(), $r );
check( '(d) ... after one request for the json and no download', array( JSON_URL ) === urls() && array() === $GLOBALS['T_download_calls'], array( urls(), $GLOBALS['T_download_calls'] ) );
foreach ( array(
	'an older tag of ours'                => 'https://github.com/doughoseck/divi5-builder/releases/download/v2026.10.01/divi5-builder.zip',
	'our repository in other letter case' => 'https://GitHub.com/DougHoseck/Divi5-Builder/releases/download/v2026.11.05/divi5-builder.zip',
	'our asset over http'                 => 'http://github.com/doughoseck/divi5-builder/releases/download/v2026.11.05/divi5-builder.zip',
	'our asset with a query'              => ASSET . '?x=1',
) as $why => $pkg ) {
	cached(); asset( $ZIP ); $GLOBALS['T_http'][ $pkg ] = array( 'code' => 200, 'headers' => array(), 'body' => $ZIP );
	$r = pre( $pkg );
	check( '(d) ours by URL form but not the verified download_url (' . $why . '): d5b_checksum, no download', $r instanceof WP_Error && 'd5b_checksum' === $r->get_error_code() && array() === $GLOBALS['T_download_calls'], $r );
}
cached(); asset( $ZIP ); $GLOBALS['T_site_transients']['d5b_update_json']['json']['sha256'] = 'nope';
$r = pre( ASSET );
check( '(d) a cached json that no longer validates counts as none: refused, no download', $r instanceof WP_Error && 'd5b_checksum' === $r->get_error_code() && array() === $GLOBALS['T_download_calls'], $r );
site(); serve( good( array( 'sha256' => hash( 'sha256', $ZIP ) ) ) ); asset( $ZIP );
$r = pre( ASSET );
check( '(d) a fresh site fetches the json once, then downloads and verifies', is_string( $r ) && $ZIP === @file_get_contents( $r ) && array( JSON_URL, ASSET ) === urls(), array( $r, urls() ) );

cached(); $GLOBALS['T_http'][ ASSET ] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
$r = pre( ASSET );
check( "(e) download_url's WP_Error is returned as it is", $r instanceof WP_Error && 'http_request_failed' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'Operation timed out' ) && temps_gone(), $r );
cached(); asset( 'Not Found', 404 );
$r = pre( ASSET );
check( "(e) a 404 for the asset: download_url's http_404 is returned, nothing kept", $r instanceof WP_Error && 'http_404' === $r->get_error_code() && temps_gone(), $r );

echo "(j) plugins_api: the View details modal\n";
$readme = str_replace( "\r\n", "\n", file_get_contents( $dir . '/readme.txt' ) );
preg_match( '/^== Changelog ==\n+= (\d+\.\d+\.\d+) =\n(.*?)\n+= (\d+\.\d+\.\d+) =\n(.*?)(\n\n|\z)/ms', $readme, $cl );
$newest = array_values( array_filter( array_map( function ( $l ) { return 0 === strpos( $l, '* ' ) ? substr( $l, 2 ) : null; }, explode( "\n", $cl[2] ?? '' ) ) ) );
$older  = array_values( array_filter( array_map( function ( $l ) { return 0 === strpos( $l, '* ' ) ? substr( $l, 2 ) : null; }, explode( "\n", $cl[4] ?? '' ) ) ) );
check( 'the readme has a newest changelog entry with bullets to compare with', array() !== $newest && array() !== $older, $cl );
function info( $args = null, $action = 'plugin_information', $result = false ) { return d5b_updater_plugins_api( $result, $action, $args ?? (object) array( 'slug' => 'divi5-builder' ) ); }
cached();
$o = info();
check( '(f) our slug: an object', is_object( $o ), $o );
$o = (object) $o;
check( '(f) name, slug, author, homepage', 'Divi 5 Builder' === ( $o->name ?? null ) && 'divi5-builder' === ( $o->slug ?? null ) && 'divi5-builder skill' === ( $o->author ?? null ) && 'https://github.com/doughoseck/divi5-builder' === ( $o->homepage ?? null ), $o );
check( "(f) version and download_link are the json's", '2.5.0' === ( $o->version ?? null ) && ASSET === ( $o->download_link ?? null ), $o );
check( "(f) requires, requires_php and tested are the validated json's", '6.0' === ( $o->requires ?? null ) && '7.4' === ( $o->requires_php ?? null ) && '7.1' === ( $o->tested ?? null ), $o );
check( '(f) last_updated is the date of the validated tag (the json\'s own last_updated is not used)', '2026-11-05' === ( $o->last_updated ?? null ), $o );
$cg = (string) ( $o->sections['changelog'] ?? '' );
check( '(f) the changelog is a <ul> of the readme\'s newest entry, every bullet', false !== strpos( $cg, '<ul>' ) && array() === array_filter( $newest, function ( $b ) use ( $cg ) { return false === strpos( $cg, '<li>' . esc_html( $b ) . '</li>' ); } ), $cg );
check( '(f) ... and only the newest entry', array() === array_filter( $older, function ( $b ) use ( $cg ) { return false !== strpos( $cg, esc_html( $b ) ); } ), $cg );
check( '(f) the changelog links the release page of the json\'s tag', false !== strpos( $cg, 'href="https://github.com/doughoseck/divi5-builder/releases/tag/v2026.11.05"' ), $cg );
check( '(f) the description is the readme\'s short description', 0 === strpos( (string) ( $o->sections['description'] ?? '' ), 'Build and edit Divi 5 pages over the REST API' ) && false === strpos( (string) ( $o->sections['description'] ?? '' ), "\n" ), $o->sections ?? null );
check( '(f) the sections are exactly description and changelog', array( 'description', 'changelog' ) === array_keys( (array) ( $o->sections ?? array() ) ), $o->sections ?? null );
check( '(f) args as an array work too', is_object( info( array( 'slug' => 'divi5-builder' ) ) ) && '2.5.0' === info( array( 'slug' => 'divi5-builder' ) )->version );
check( '(f) no HTTP while the cache is fresh', array() === $GLOBALS['T_http_calls'] );
cached( array( 'tested' => '<img src=x onerror=alert(1)>' ) );
$GLOBALS['T_site_transients']['d5b_update_json']['json'] = good( array( 'tested' => '<img src=x onerror=alert(1)>' ) );
$o = info();
check( '(f) a cached json that no longer validates gives none of its values (tested, version, link)', is_object( $o ) && '' === $o->tested && D5B_VERSION === $o->version && '' === $o->download_link && '' === $o->requires_php, $o );
site(); $GLOBALS['T_http'][ JSON_URL ] = new WP_Error( 'http_request_failed', 'down' );
$o = info();
check( '(f) no json at all: this copy\'s version, no download link, the releases page linked', is_object( $o ) && D5B_VERSION === $o->version && '' === $o->download_link && false !== strpos( $o->sections['changelog'], 'href="https://github.com/doughoseck/divi5-builder/releases"' ), $o );
$s = d5b_updater_readme_sections( "=== Divi 5 Builder ===\nStable tag: 1.0.0\n\nShort <b>desc</b> & more\n\n== Description ==\n\nx\n\n== Changelog ==\n\n= 9.9.9 =\n* first <script>alert(1)</script>\n* second & \"quoted\"\n  continued line\n\n= 9.9.8 =\n* old\n", 'v2026.11.05' );
check( '(f) every piece of readme text is escaped', 'Short &lt;b&gt;desc&lt;/b&gt; &amp; more' === $s['description'] && false !== strpos( $s['changelog'], '<li>first &lt;script&gt;alert(1)&lt;/script&gt;</li>' ) && false === strpos( $s['changelog'], '<script>' ), $s );
check( '(f) a bullet\'s continuation line stays with it; an older entry is left out', false !== strpos( $s['changelog'], '<li>second &amp; &quot;quoted&quot; continued line</li>' ) && false === strpos( $s['changelog'], 'old' ), $s );
check( '(f) CRLF line ends read the same', $s === d5b_updater_readme_sections( str_replace( "\n", "\r\n", "=== Divi 5 Builder ===\nStable tag: 1.0.0\n\nShort <b>desc</b> & more\n\n== Description ==\n\nx\n\n== Changelog ==\n\n= 9.9.9 =\n* first <script>alert(1)</script>\n* second & \"quoted\"\n  continued line\n\n= 9.9.8 =\n* old\n" ), 'v2026.11.05' ), $s );
cached(); add_filter( 'plugins_api', 'd5b_updater_plugins_api', 10, 3 );
$o = plugins_api( 'plugin_information', array( 'slug' => 'divi5-builder' ) );
check( '(f) through core\'s plugins_api: our answer, wordpress.org never asked', is_object( $o ) && '2.5.0' === $o->version && ! isset( $GLOBALS['T_plugins_api']['divi5-builder'] ), $o );

cached();
check( '(g) another slug: $result unchanged (false)', false === info( (object) array( 'slug' => 'akismet' ) ) );
$theirs = (object) array( 'name' => 'Theirs' );
check( "(g) another slug: another filter's object passes through untouched", $theirs === info( (object) array( 'slug' => 'divi5-builder-pro' ), 'plugin_information', $theirs ) );
check( '(g) args without a slug pass through', false === info( (object) array() ) && false === info( array() ) && false === info( 'divi5-builder' ) );
check( '(g) through core\'s plugins_api another slug still reaches wordpress.org', ( function () { $GLOBALS['T_plugins_api']['akismet'] = (object) array( 'slug' => 'akismet' ); return 'akismet' === ( plugins_api( 'plugin_information', array( 'slug' => 'akismet' ) )->slug ?? null ); } )() );
check( '(h) action query_plugins with our slug: untouched', false === info( (object) array( 'slug' => 'divi5-builder' ), 'query_plugins' ) );
check( '(h) action hot_tags: untouched', $theirs === info( (object) array( 'slug' => 'divi5-builder' ), 'hot_tags', $theirs ) );
check( '(g)(h) no HTTP for any of them', array() === $GLOBALS['T_http_calls'] );

echo "warnings\n";
check( 'no PHP notice or warning in the whole run (every refusal is a deliberate check)', array() === $PHP_WARNINGS, array_slice( array_unique( $PHP_WARNINGS ), 0, 5 ) );

echo "\n" . ( $failed ? "$failed of $n FAILED" : "all $n passed" ) . "\n";
exit( $failed ? 1 : 0 );
