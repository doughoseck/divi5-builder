<?php
/**
 * Write abilities for media (#32, release 2.2.0, Task 5).
 *
 *   php scripts/plugin-abilities-write-media-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php: wp_remote_get answers only from T_http, DNS only
 * from T_dns, so nothing reaches the network; media_handle_sideload, wp_check_filetype_and_ext and wp_delete_attachment behave
 * as core where the plugin depends on it). Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (mutation testing).
 * A subprocess runs the MEDIA_TRASH-on half (a constant cannot be undefined).
 *
 * The promises under test: a URL upload never requests a private, loopback, link-local or non-http address, re-checks every
 * redirect before following it and stops after 3; size and type caps hold for both forms, and the type is read from the bytes;
 * a good upload returns id, url, sizes and hash and writes a history row; media-update changes metadata only; trash only with
 * MEDIA_TRASH; the two-step delete needs a fresh, unused code of the same user for the unchanged item, writes its history row
 * first and deletes nothing when it cannot; the strictest switch wins (media category, and the parent's own category).
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
if ( getenv( 'T_MEDIA_TRASH' ) ) { define( 'MEDIA_TRASH', true ); }
define( 'D5B_VERSION', '2.2.0' );
require __DIR__ . '/plugin-test-stubs.php';
foreach ( array( 'parts', 'switches', 'abilities/categories', 'abilities/register', 'abilities/secrets', 'abilities/audit', 'abilities/scrub', 'abilities/history', 'abilities/confirm', 'abilities/read-content', 'abilities/read-structure', 'abilities/write-common', 'abilities/write-posts', 'abilities/write-media' ) as $f ) { require $dir . '/inc/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ED = array( 'read', 'upload_files', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'read_private_posts', 'edit_private_posts', 'delete_private_posts' );
const READER = array( 'read' );
define( 'PNG', base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) );
define( 'JPG', "\xff\xd8\xff\xe0\x00\x10JFIF\x00" . str_repeat( "\x01", 40 ) );

function site( array $caps = ED, int $uid = 3 ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( false );
	$GLOBALS['T_user_id'] = $uid; $GLOBALS['T_users'] = array( 3 => (object) array( 'user_login' => 'ann' ), 4 => (object) array( 'user_login' => 'cat' ), 5 => (object) array( 'user_login' => 'bob' ) );
	d5b_ability_audit_current_state( 0 );
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
	$GLOBALS['T_dns'] = array( 'good.example' => array( '93.184.216.34' ), 'cdn.example' => array( '93.184.216.35', '2606:2800:220:1::1' ) );
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function handler( $name, $in = array() ) { return call_user_func( d5b_ability_defs()[ 'divi5-builder/' . $name ]['handler'], $in ); }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function msg( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
function reason( $r ) { return is_wp_error( $r ) ? ( $r->get_error_data()['reason'] ?? null ) : null; }
function hrows() { global $wpdb; return $wpdb->tables[ d5b_history_table() ] ?? array(); }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function post( $id ) { return $GLOBALS['T_posts'][ (int) $id ] ?? null; }
function atts() { return array_values( array_filter( $GLOBALS['T_posts'], function ( $p ) { return 'attachment' === $p->post_type; } ) ); }
function http( $url, $code = 200, $body = '', array $headers = array() ) { $GLOBALS['T_http'][ $url ] = array( 'code' => $code, 'body' => $body, 'headers' => $headers ); }
function hcalls() { return $GLOBALS['T_http_calls']; }
function temps_left() { return array_values( array_filter( $GLOBALS['T_tempfiles'], 'file_exists' ) ); }
/** An attachment with a file, metadata and one size, owned by user 3. */
function att( array $f = array(), $file = '2026/10/photo.jpg' ) {
	$id = t_add_post( array_merge( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_author' => 3, 'post_title' => 'Photo', 'post_mime_type' => 'image/jpeg', 'post_content' => 'A description', 'post_excerpt' => 'A caption' ), $f ) );
	t_add_meta( $id, '_wp_attached_file', $file );
	$stem = preg_replace( '/\.[^.]+$/', '', basename( $file ) );
	t_add_meta( $id, '_wp_attachment_metadata', array( 'width' => 800, 'height' => 600, 'file' => $file, 'sizes' => array( 'medium' => array( 'file' => $stem . '-300x200.jpg', 'width' => 300, 'height' => 200, 'mime-type' => 'image/jpeg' ) ) ) );
	t_add_meta( $id, '_wp_attachment_image_alt', 'Old alt' );
	$GLOBALS['T_files'][ $file ] = JPG; $GLOBALS['T_files'][ dirname( $file ) . '/' . $stem . '-300x200.jpg' ] = JPG;
	return $id;
}

$names = array( 'upload', 'update', 'trash', 'restore', 'delete-preview', 'delete' );

// ---- the subprocess: a site with a media trash (MEDIA_TRASH on)
if ( getenv( 'T_MEDIA_TRASH' ) ) {
	site();
	$a = att();
	$h0 = count( hrows() );
	$tr = call( 'media-trash', array( 'id' => $a ) );
	$ht = hlast();
	$st_trash = post( $a )->post_status;
	$pv = call( 'media-delete-preview', array( 'id' => $a ) );
	$dl = call( 'media-delete', array( 'id' => $a, 'confirmation' => 'ABCDEFGHJKLM' ) );
	$rs = call( 'media-restore', array( 'id' => $a ) );
	$hr = hlast();
	$rs2 = call( 'media-restore', array( 'id' => $a ) );
	echo 'SUB:' . json_encode( array(
		'trash_ok' => is_array( $tr ) && 'trash' === $tr['status'] && 'divi5-builder/media-restore' === ( $tr['restore_with'] ?? '' ),
		'st_trash' => $st_trash, 'trash_row' => array( $ht['action'] ?? null, $ht['before']['status'] ?? null, $ht['after']['status'] ?? null, $ht['before']['url'] ?? null, $ht['summary'] ?? null ),
		'preview' => array( code( $pv ), is_wp_error( $pv ) ? ( $pv->get_error_data()['use'] ?? null ) : null ), 'delete' => code( $dl ),
		'restore_status' => is_array( $rs ) ? $rs['status'] : code( $rs ), 'restore_row' => array( $hr['action'] ?? null, $hr['before']['status'] ?? null, $hr['after']['status'] ?? null ),
		'restore_again' => code( $rs2 ), 'exists' => null !== post( $a ), 'deleted' => $GLOBALS['T_deleted'], 'rows' => count( hrows() ) - $h0,
		'transients' => count( array_filter( array_keys( $GLOBALS['T_transients'] ), function ( $k ) { return 0 === strpos( $k, 'd5b_confirm_' ); } ) ),
	) ) . "\n";
	exit( 0 );
}

echo "registration\n";
$defs = d5b_ability_defs();
$bad = array();
$want = array( 'upload' => array( false, false, false ), 'update' => array( false, false, true ), 'trash' => array( false, true, false ), 'restore' => array( false, false, false ), 'delete-preview' => array( true, false, false ), 'delete' => array( false, true, false ) );
foreach ( $names as $x ) {
	$d = $defs[ 'divi5-builder/media-' . $x ] ?? null;
	if ( null === $d ) { $bad[] = "$x missing"; continue; }
	if ( 'media' !== $d['category'] ) { $bad[] = "$x category"; }
	$an = $d['annotations'] ?? array();
	if ( array( $an['readonly'] ?? null, $an['destructive'] ?? null, $an['idempotent'] ?? null ) !== $want[ $x ] ) { $bad[] = "$x annotations " . json_encode( $an ); }
	if ( ! is_callable( $d['handler'] ) || ! is_callable( $d['permission'] ) ) { $bad[] = "$x callables"; }
}
check( 'the 6 media writes are defined in the media category with the agreed annotations (preview readonly; trash and delete destructive; update idempotent)', array() === $bad, $bad );
check( 'upload takes url, base64, filename, title, alt, caption, description, parent; delete requires id and confirmation',
	array( 'url', 'base64', 'filename', 'title', 'alt', 'caption', 'description', 'parent' ) === array_keys( $defs['divi5-builder/media-upload']['input_schema']['properties'] )
	&& array( 'id', 'confirmation' ) === $defs['divi5-builder/media-delete']['input_schema']['required'] );
check( 'the descriptions say what matters: upload names the address rules, the redirect limit and the DNS rebinding limit; delete says the file itself is not kept',
	1 === preg_match( '/private, loopback, link-local/', $defs['divi5-builder/media-upload']['description'] ) && false !== strpos( $defs['divi5-builder/media-upload']['description'], 'at most 3 redirects' ) && false !== stripos( $defs['divi5-builder/media-upload']['description'], 'rebinding' )
	&& false !== strpos( $defs['divi5-builder/media-delete']['description'], 'NOT the file itself' ) && false !== strpos( $defs['divi5-builder/media-trash']['description'], 'media-delete-preview' ) );

echo "URL safety: refused before any request\n";
site();
$GLOBALS['T_dns']['inside.example'] = array( '10.1.2.3' );
$GLOBALS['T_dns']['mixed.example'] = array( '93.184.216.34', '192.168.0.7' );
$GLOBALS['T_dns']['v6ll.example'] = array( '93.184.216.34', 'fe80::1' );
$GLOBALS['T_dns']['ula.example'] = array( 'fd12:3456::1' );
$GLOBALS['T_dns']['meta.example'] = array( '169.254.169.254' );
// A resolver that answers "public" for names the HTTP client reads differently (numeric forms, localhost): the name rules
// must refuse them by themselves.
foreach ( array( '127.1', '2130706433', '0x7f000001', '0x7f.0.0.1', 'localhost', 'app.localhost' ) as $h ) { $GLOBALS['T_dns'][ $h ] = array( '93.184.216.34' ); }
$refused = array(
	'http://10.0.0.5/a.png', 'http://127.0.0.1/a.png', 'http://127.255.0.1/a.png', 'http://169.254.169.254/latest/meta-data/', 'http://192.168.1.1/a.png', 'http://172.16.0.1/a.png', 'http://172.31.255.255/a.png',
	'http://0.0.0.0/a.png', 'http://100.64.0.1/a.png', 'http://[::1]/a.png', 'http://[::]/a.png', 'http://[fe80::1]/a.png', 'http://[fc00::1]/a.png', 'http://[fd00::5]/a.png', 'http://[::ffff:127.0.0.1]/a.png', 'http://[::ffff:a9fe:a9fe]/a.png', 'http://[64:ff9b::a00:1]/a.png', 'http://[2002:7f00:1::]/a.png',
	'ftp://good.example/a.png', 'file:///etc/passwd', 'gopher://good.example/', 'javascript:alert(1)', '//good.example/a.png', 'good.example/a.png',
	'http://user:pass@good.example/a.png', 'http://good.example@127.0.0.1/a.png', 'http://good.example:8080/a.png', 'http://good.example:22/a.png', 'http://good.example\\@127.0.0.1/a.png', 'http://good .example/a.png',
	'http://127.1/a.png', 'http://2130706433/a.png', 'http://0x7f000001/a.png', 'http://0x7f.0.0.1/a.png', 'http://localhost/a.png', 'http://LOCALHOST./a.png', 'http://app.localhost/a.png',
	'http://inside.example/a.png', 'http://mixed.example/a.png', 'http://v6ll.example/a.png', 'http://ula.example/a.png', 'http://meta.example/a.png', 'http://nowhere.example/a.png',
);
$leaks = array();
foreach ( $refused as $u ) {
	$GLOBALS['T_http_calls'] = array();
	$r = call( 'media-upload', array( 'url' => $u ) );
	if ( 'invalid_input' !== code( $r ) || array() !== hcalls() ) { $leaks[] = $u . ' => ' . ( code( $r ) ?? 'ok' ) . ' / ' . count( hcalls() ) . ' requests'; }
}
check( 'private, loopback, link-local, reserved and IPv4-in-IPv6 addresses, non-http schemes, user:pass@, other ports, numeric host tricks, localhost, hosts resolving (even partly) to such addresses, and unresolvable hosts: all invalid_input with no request made (' . count( $refused ) . ' URLs)', array() === $leaks, $leaks );
check( '... nothing was stored and no temporary file was made', array() === atts() && array() === $GLOBALS['T_tempfiles'] && array() === hrows() );
$ok = array( 'http://good.example/a.png', 'https://good.example/a.png', 'https://good.example:443/a.png', 'http://good.example:80/a.png?x=1', 'http://93.184.216.34/a.png', 'http://[2606:2800:220:1::1]/a.png', 'https://cdn.example/x/y.png', 'HTTPS://Good.Example/a.png' );
$bad = array();
foreach ( $ok as $u ) { if ( true !== d5b_wm_safe_url( $u ) ) { $bad[] = $u . ' => ' . msg( d5b_wm_safe_url( $u ) ); } }
check( 'public hosts and public IP literals on http/https with the standard ports pass the check', array() === $bad, $bad );
check( 'every resolved address counts: cdn.example (public v4 and v6) passes, and the lookup asked for the host itself', true === d5b_wm_safe_url( 'https://cdn.example/x' ) && in_array( 'cdn.example', $GLOBALS['T_dns_calls'], true ) );

echo "URL fetch: redirects\n";
site();
http( 'http://good.example/r1', 302, '', array( 'Location' => 'http://10.0.0.1/secret.png' ) );
$r = call( 'media-upload', array( 'url' => 'http://good.example/r1' ) );
check( 'a redirect to a private address is refused at that hop: invalid_input naming the redirect, only the first URL was requested', 'invalid_input' === code( $r ) && false !== strpos( msg( $r ), 'Redirect 1' ) && array( 'http://good.example/r1' ) === array_column( hcalls(), 0 ), array( $r, hcalls() ) );
$GLOBALS['T_dns']['evil.example'] = array( '127.0.0.1' );
$GLOBALS['T_http_calls'] = array();
http( 'http://good.example/r2', 301, '', array( 'Location' => 'https://good.example/step' ) );
http( 'https://good.example/step', 307, '', array( 'location' => '//evil.example/x.png' ) );
$r = call( 'media-upload', array( 'url' => 'http://good.example/r2' ) );
check( 'a second-hop redirect to a host that resolves to loopback is refused before it is requested (scheme-relative Location resolved against the hop)', 'invalid_input' === code( $r ) && false !== strpos( msg( $r ), 'Redirect 2' ) && 2 === count( hcalls() ) && in_array( 'evil.example', $GLOBALS['T_dns_calls'], true ), array( $r, hcalls() ) );
$GLOBALS['T_http_calls'] = array();
http( 'http://good.example/r3', 302, '', array( 'Location' => 'ftp://good.example/a.png' ) );
check( 'a redirect to another scheme is refused', 'invalid_input' === code( call( 'media-upload', array( 'url' => 'http://good.example/r3' ) ) ) && 1 === count( hcalls() ) );
site();
http( 'http://good.example/1', 302, '', array( 'Location' => '/2' ) );
http( 'http://good.example/2', 302, '', array( 'Location' => '3' ) );
http( 'http://good.example/3', 303, '', array( 'Location' => 'https://cdn.example/4' ) );
http( 'https://cdn.example/4', 308, '', array( 'Location' => 'https://cdn.example/5.png' ) );
http( 'https://cdn.example/5.png', 200, PNG );
$r = call( 'media-upload', array( 'url' => 'http://good.example/1' ) );
check( 'more than 3 redirects: refused after the 4th request, the 5th URL never requested, nothing stored', 'invalid_input' === code( $r ) && false !== strpos( msg( $r ), 'More than 3 redirects' ) && 4 === count( hcalls() ) && array() === atts(), array( $r, array_column( hcalls(), 0 ) ) );
check( '... and no temporary file is left behind', array() === temps_left() );
site();
http( 'http://good.example/2', 302, '', array( 'Location' => '3' ) );
http( 'http://good.example/3', 303, '', array( 'Location' => 'https://cdn.example/4' ) );
http( 'https://cdn.example/4', 308, '', array( 'Location' => 'https://cdn.example/dir/5.png?sig=SECRET' ) );
http( 'https://cdn.example/dir/5.png?sig=SECRET', 200, PNG );
$r = call( 'media-upload', array( 'url' => 'http://good.example/2' ) );
$h = hlast();
check( 'exactly 3 redirects (relative, absolute, cross-host) are followed: 4 requests, the file stored under the final URL\'s name', is_array( $r ) && 4 === count( hcalls() ) && 'https://cdn.example/dir/5.png?sig=SECRET' === hcalls()[3][0] && '5.png' === basename( (string) get_post_meta( $r['id'], '_wp_attached_file', true ) ), array( $r, array_column( hcalls(), 0 ) ) );
$args = hcalls()[0][1];
check( 'every request: redirection 0, timeout 20, stream to a temporary file, limit_response_size just over the upload limit, reject_unsafe_urls on', 0 === $args['redirection'] && 20 === $args['timeout'] && true === $args['stream'] && is_string( $args['filename'] ) && 100001 === $args['limit_response_size'] && true === $args['reject_unsafe_urls'] );
check( 'the history row keeps the source URL without its query (a signed URL\'s token is not stored)', 'https://cdn.example/dir/5.png' === ( $h['after']['source_url'] ?? null ) && false === strpos( json_encode( $h ), 'SECRET' ), $h['after']['source_url'] ?? null );
site();
http( 'http://good.example/gone.png', 404, 'nope' );
http( 'http://good.example/noloc', 302, '' );
http( 'http://good.example/err', 500, '' );
check( 'HTTP 404 and 500 are refused with the status in the message; a redirect with no Location is refused', false !== strpos( msg( call( 'media-upload', array( 'url' => 'http://good.example/gone.png' ) ) ), '404' ) && false !== strpos( msg( call( 'media-upload', array( 'url' => 'http://good.example/err' ) ) ), '500' ) && false !== strpos( msg( call( 'media-upload', array( 'url' => 'http://good.example/noloc' ) ) ), 'Location' ) && array() === atts() && array() === temps_left() );
check( 'a request that fails (unscripted host = unreachable) is invalid_input, nothing stored', 'invalid_input' === code( call( 'media-upload', array( 'url' => 'http://cdn.example/none.png' ) ) ) && array() === atts() );

echo "size and type caps\n";
site();
$GLOBALS['T_max_upload'] = 1000;
http( 'http://good.example/big.png', 200, PNG . str_repeat( "\0", 2000 ) );
$r = call( 'media-upload', array( 'url' => 'http://good.example/big.png' ) );
check( 'a download over wp_max_upload_size is refused (no Content-Length given): invalid_input, never sideloaded, nothing stored, no temp file left', 'invalid_input' === code( $r ) && false !== strpos( msg( $r ), 'upload limit' ) && array() === $GLOBALS['T_sideloads'] && array() === atts() && array() === temps_left(), $r );
http( 'http://good.example/exact.png', 200, PNG . str_repeat( "\0", 1000 - strlen( PNG ) ) );
check( 'a file of exactly the limit is accepted', is_array( call( 'media-upload', array( 'url' => 'http://good.example/exact.png' ) ) ) );
http( 'http://good.example/cl.png', 200, PNG, array( 'Content-Length' => '5000000' ) );
$GLOBALS['T_sideloads'] = array();
check( 'a Content-Length over the limit is refused too', 'invalid_input' === code( call( 'media-upload', array( 'url' => 'http://good.example/cl.png' ) ) ) && array() === $GLOBALS['T_sideloads'] );
site();
http( 'http://good.example/tool.exe', 200, "MZ\x90\x00\x03\x00binary", array( 'Content-Type' => 'image/png' ) );
http( 'http://good.example/fake.png', 200, '<html><script>alert(document.cookie)</script></html>', array( 'Content-Type' => 'image/png' ) );
http( 'http://good.example/shell.jpg', 200, '<?php /* not an image */ echo 1; ?>' /* (a real web-shell line is quarantined by Windows Defender when written to Temp) */, array( 'Content-Type' => 'image/jpeg' ) );
// (core lets a zip named .pdf through: application/zip is one of its "nonspecific" types; plain text is not.)
http( 'http://good.example/doc.pdf', 200, 'plain text pretending to be a pdf', array( 'Content-Type' => 'application/pdf' ) );
$bad = array();
foreach ( array( 'tool.exe', 'fake.png', 'shell.jpg', 'doc.pdf' ) as $f ) { $r = call( 'media-upload', array( 'url' => 'http://good.example/' . $f ) ); if ( 'invalid_input' !== code( $r ) ) { $bad[] = $f; } }
check( 'a type the site does not allow (exe) and files whose bytes are not what their name and Content-Type say (html as png, php as jpg, text as pdf) are refused BEFORE media_handle_sideload, nothing stored', array() === $bad && array() === $GLOBALS['T_sideloads'] && array() === atts() && array() === temps_left(), $bad );
http( 'http://good.example/really-jpeg.png', 200, JPG, array( 'Content-Type' => 'image/png' ) );
$r = call( 'media-upload', array( 'url' => 'http://good.example/really-jpeg.png' ) );
check( 'a JPEG named .png and served as image/png is stored as what it is: really-jpeg.jpg, image/jpeg (the bytes decide, not the header)', is_array( $r ) && 'image/jpeg' === $r['mime'] && '.jpg' === substr( (string) get_post_meta( $r['id'], '_wp_attached_file', true ), -4 ), $r );

echo "no SVG through the abilities\n";
site();
$GLOBALS['T_mimes']['svg|svgz'] = 'image/svg+xml';
$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
http( 'http://good.example/pic.svg', 200, $svg, array( 'Content-Type' => 'image/svg+xml' ) );
http( 'http://good.example/pic', 200, $svg, array( 'Content-Disposition' => 'attachment; filename="pic.svg"' ) );
http( 'http://good.example/other.png', 200, PNG );
$bad = array();
foreach ( array(
	'url .svg' => array( 'url' => 'http://good.example/pic.svg' ),
	'url, svg name from Content-Disposition' => array( 'url' => 'http://good.example/pic' ),
	'url, filename .SVG' => array( 'url' => 'http://good.example/other.png', 'filename' => 'x.SVG' ),
	'base64 .svg' => array( 'base64' => base64_encode( $svg ), 'filename' => 'a.svg' ),
	'base64 .svgz' => array( 'base64' => base64_encode( $svg ), 'filename' => 'a.svgz' ),
) as $what => $in ) { $r = call( 'media-upload', $in ); if ( 'not_allowed' !== code( $r ) || false === strpos( msg( $r ), 'wp-admin' ) ) { $bad[] = $what . ' => ' . code( $r ); } }
check( 'SVG from a URL or base64 is refused with not_allowed (upload it by hand in wp-admin) even where the site allows svg; nothing stored, no temp file left', array() === $bad && array() === $GLOBALS['T_sideloads'] && array() === atts() && array() === temps_left(), $bad );
unset( $GLOBALS['T_mimes']['svg|svgz'] );
check( '... on a site that does not allow svg the answer is the same not_allowed (not a type error)', 'not_allowed' === code( call( 'media-upload', array( 'url' => 'http://good.example/pic.svg' ) ) ) && 'not_allowed' === code( call( 'media-upload', array( 'url' => 'http://good.example/pic' ) ) ) );
$GLOBALS['T_http_calls'] = array_slice( $GLOBALS['T_http_calls'], 0, 2 );
check( '... a filename ending .svg is refused before anything is fetched', 'not_allowed' === code( call( 'media-upload', array( 'url' => 'http://good.example/other.png', 'filename' => 'y.svg' ) ) ) && 2 === count( hcalls() ), array_column( hcalls(), 0 ) );

echo "the request is pinned to the checked address\n";
site();
$GLOBALS['T_rebind']['good.example'] = '169.254.169.254';
http( 'http://good.example/a.png', 200, PNG );
$r = call( 'media-upload', array( 'url' => 'http://good.example/a.png' ) );
check( 'a host that rebinds to 169.254.169.254 after the check: curl connects to the checked 93.184.216.34 (CURLOPT_RESOLVE good.example:80:93.184.216.34); the result says transport curl',
	is_array( $r ) && 'curl' === ( $r['transport'] ?? null ) && array( '93.184.216.34' ) === $GLOBALS['T_connects'] && array( array( 1, array( 'good.example:80:93.184.216.34' ) ) ) === $GLOBALS['T_curl_pins'], array( $r, $GLOBALS['T_connects'], $GLOBALS['T_curl_pins'] ) );
check( '... the one-shot http_api_curl action is gone after the request', array() === ( $GLOBALS['T_actions']['http_api_curl'] ?? array() ) );
site();
$GLOBALS['T_dns']['six.example'] = array( '2606:2800:220:1::9' );
http( 'https://six.example/a.png', 200, PNG );
http( 'http://good.example/hop', 302, '', array( 'Location' => 'https://cdn.example/b.png' ) );
http( 'https://cdn.example/b.png', 200, PNG );
$r1 = call( 'media-upload', array( 'url' => 'https://six.example/a.png' ) );
$r2 = call( 'media-upload', array( 'url' => 'http://good.example/hop' ) );
check( 'an IPv6-only host is pinned in brackets on 443; each redirect hop is pinned to its own checked address (IPv4 preferred)', is_array( $r1 ) && is_array( $r2 ) && array( array( 'six.example:443:[2606:2800:220:1::9]' ), array( 'good.example:80:93.184.216.34' ), array( 'cdn.example:443:93.184.216.35' ) ) === array_column( $GLOBALS['T_curl_pins'], 1 ), $GLOBALS['T_curl_pins'] );
site();
http( 'http://93.184.216.34/a.png', 200, PNG );
check( 'an IP literal needs no pin (nothing is resolved) and is fetched', is_array( call( 'media-upload', array( 'url' => 'http://93.184.216.34/a.png' ) ) ) && array() === $GLOBALS['T_curl_pins'] );
site();
$GLOBALS['T_http_transport'] = 'streams';
http( 'http://good.example/a.png', 200, PNG );
$r = call( 'media-upload', array( 'url' => 'http://good.example/a.png' ) );
check( 'a transport that is not curl (http_api_curl never runs): refused unavailable, the answer discarded, nothing stored, no temp file left', 'unavailable' === code( $r ) && false !== strpos( msg( $r ), 'pin' ) && array() === atts() && array() === $GLOBALS['T_sideloads'] && array() === temps_left(), $r );
check( '... base64 still works on such a site', is_array( call( 'media-upload', array( 'base64' => base64_encode( PNG ), 'filename' => 'b.png' ) ) ) );
site();
$GLOBALS['T_curl_pin_fail'] = true;
http( 'http://good.example/a.png', 200, PNG );
check( 'a pin that cannot be set: unavailable, nothing stored', 'unavailable' === code( call( 'media-upload', array( 'url' => 'http://good.example/a.png' ) ) ) && array() === atts() );

echo "audit args\n";
site();
http( 'http://good.example/a.png?sig=SECRETSIG', 200, PNG );
call( 'media-upload', array( 'url' => 'http://good.example/a.png?sig=SECRETSIG#frag' ) );
call( 'media-upload', array( 'base64' => base64_encode( PNG ), 'filename' => 'c.png' ) );
call( 'media-upload', array( 'base64' => 'not*base64', 'filename' => 'c.png' ) );
$GLOBALS['T_user_id'] = 0; $GLOBALS['T_transients'] = array();
call( 'media-upload', array( 'url' => 'http://good.example/a.png?sig=SECRETSIG' ) );
$GLOBALS['T_user_id'] = 3;
t_set_caps( READER );
call( 'media-upload', array( 'url' => 'http://good.example/a.png?sig=SECRETSIG', 'base64' => base64_encode( PNG ) ) );
$rows = $GLOBALS['wpdb']->tables['wp_d5b_audit'];
$all = json_encode( array_column( $rows, 'args' ) );
$b64 = json_decode( $rows[1]['args'], true );
check( 'the audit rows (ok, refused, anonymous, forbidden) store the URL without its query or fragment and a base64 file only as its length', 5 <= count( $rows ) && false === strpos( $all, 'SECRETSIG' ) && false === strpos( $all, 'frag' ) && false === strpos( $all, base64_encode( PNG ) ) && 'http://good.example/a.png' === json_decode( $rows[0]['args'], true )['url'] && strlen( base64_encode( PNG ) ) === ( $b64['base64']['base64_bytes'] ?? null ) && 'c.png' === $b64['filename'], array_column( $rows, 'args', 'status' ) );
check( 'other abilities keep their args as they are', ( function () { call( 'media-update', array( 'id' => 999, 'title' => 'x?y=1' ) ); $r = end( $GLOBALS['wpdb']->tables['wp_d5b_audit'] ); return false !== strpos( $r['args'], 'x?y=1' ); } )() );

echo "a good upload\n";
site();
$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 3 ) );
http( 'http://good.example/files/download?id=7', 200, PNG, array( 'Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="Team Photo.png"' ) );
$r = call( 'media-upload', array( 'url' => 'http://good.example/files/download?id=7', 'title' => 'Our team', 'alt' => 'Five people <b>smiling</b>', 'caption' => 'The team', 'description' => 'Taken in 2026', 'parent' => $pg ) );
$h = hlast(); $id = is_array( $r ) ? $r['id'] : 0;
check( 'returns id, url, mime, alt, caption, description, every size (full and the generated thumbnail) and hash', is_array( $r ) && $id > 0 && isset( $r['url'], $r['file_url'], $r['sizes']['full'], $r['sizes']['thumbnail'], $r['hash'] ) && 'image/png' === $r['mime'] && 1 === $r['sizes']['full']['width'] && md5( 'Taken in 2026' ) === $r['hash'], $r );
check( 'the file name comes from Content-Disposition, cleaned (Team-Photo.png); title, caption, description, parent and the alt text (tags stripped) are set', 'Team-Photo.png' === basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ) && 'Our team' === post( $id )->post_title && 'The team' === post( $id )->post_excerpt && 'Taken in 2026' === post( $id )->post_content && $pg === (int) post( $id )->post_parent && 'Five people smiling' === get_post_meta( $id, '_wp_attachment_image_alt', true ), post( $id ) );
check( 'a history row: action upload, before empty, after holds the url, file, metadata and source', 'upload' === $h['action'] && 'attachment' === $h['object_type'] && $id === (int) $h['object_id'] && array() === $h['before'] && $r['file_url'] === $h['after']['url'] && 'url' === $h['after']['source'] && 'http://good.example/files/download' === $h['after']['source_url'] && isset( $h['after']['metadata']['sizes']['thumbnail'] ), $h );
check( 'the summary: "Uploaded media N \'Team-Photo.png\'"', "Uploaded media $id 'Team-Photo.png'" === $h['summary'], $h['summary'] );
check( 'the audit row says ok; no temporary file is left', 'ok' === end( $GLOBALS['wpdb']->tables['wp_d5b_audit'] )['status'] && array() === temps_left() );
http( 'http://good.example/noext', 200, PNG );
check( 'a URL with no file name in it needs filename; with filename it works', 'invalid_input' === code( call( 'media-upload', array( 'url' => 'http://good.example/noext' ) ) ) && is_array( call( 'media-upload', array( 'url' => 'http://good.example/noext', 'filename' => 'logo.png' ) ) ) );
http( 'http://good.example/a.png', 200, PNG );
check( 'filename with a path is reduced to its base name (no ../ in the stored path)', is_array( $r2 = call( 'media-upload', array( 'url' => 'http://good.example/a.png', 'filename' => '../../evil/x.png' ) ) ) && 'x.png' === basename( (string) get_post_meta( $r2['id'], '_wp_attached_file', true ) ) && false === strpos( (string) get_post_meta( $r2['id'], '_wp_attached_file', true ), '..' ) );

echo "base64\n";
site();
$r = call( 'media-upload', array( 'base64' => base64_encode( PNG ), 'filename' => 'dot.png', 'alt' => 'A dot' ) );
$h = hlast();
check( 'a base64 upload works the same: id, sizes, hash; history source base64; no request made', is_array( $r ) && isset( $r['sizes']['thumbnail'] ) && 'base64' === $h['after']['source'] && ! isset( $h['after']['source_url'] ) && array() === hcalls() && 'A dot' === get_post_meta( $r['id'], '_wp_attachment_image_alt', true ), $r );
check( 'a data: prefix is accepted', is_array( call( 'media-upload', array( 'base64' => 'data:image/png;base64,' . base64_encode( PNG ), 'filename' => 'dot2.png' ) ) ) );
$GLOBALS['T_sideloads'] = array(); $c0 = count( atts() );
$GLOBALS['T_max_upload'] = 1000;
$over = call( 'media-upload', array( 'base64' => base64_encode( PNG . str_repeat( "\0", 1001 - strlen( PNG ) ) ), 'filename' => 'big.png' ) );
$long = call( 'media-upload', array( 'base64' => str_repeat( 'A', 5000 ), 'filename' => 'big.png' ) );
check( 'base64 is capped the same way: one byte over the limit is refused, and text too long for the limit is refused before decoding; nothing sideloaded', 'invalid_input' === code( $over ) && false !== strpos( msg( $over ), 'upload limit' ) && false !== strpos( msg( $long ), 'upload limit' ) && array() === $GLOBALS['T_sideloads'] && $c0 === count( atts() ), array( $over, $long ) );
check( 'base64 of exactly the limit is accepted', is_array( call( 'media-upload', array( 'base64' => base64_encode( PNG . str_repeat( "\0", 1000 - strlen( PNG ) ) ), 'filename' => 'ok.png' ) ) ) );
$GLOBALS['T_sideloads'] = array();
$bad = array();
foreach ( array(
	'no filename' => array( 'base64' => base64_encode( PNG ) ),
	'not base64' => array( 'base64' => 'not*base64!', 'filename' => 'a.png' ),
	'exe' => array( 'base64' => base64_encode( "MZ\x90\x00binary" ), 'filename' => 'a.exe' ),
	'html as png' => array( 'base64' => base64_encode( '<html>x</html>' ), 'filename' => 'a.png' ),
	'both' => array( 'base64' => base64_encode( PNG ), 'filename' => 'a.png', 'url' => 'http://good.example/a.png' ),
	'neither' => array( 'filename' => 'a.png' ),
	'url not text' => array( 'url' => array( 'http://good.example/a.png' ) ),
) as $what => $in ) { if ( 'invalid_input' !== code( call( 'media-upload', $in ) ) ) { $bad[] = $what; } }
check( 'base64 without filename, invalid base64, a disallowed or disguised type, both or neither of url and base64: invalid_input, nothing sideloaded', array() === $bad && array() === $GLOBALS['T_sideloads'] && array() === hcalls(), $bad );

echo "who may upload, and where\n";
site( READER );
http( 'http://good.example/a.png', 200, PNG );
check( 'without upload_files: refused by the gate and by the handler; no request', 'FORBIDDEN' === call( 'media-upload', array( 'url' => 'http://good.example/a.png' ) ) && 'not_allowed' === code( handler( 'media-upload', array( 'url' => 'http://good.example/a.png' ) ) ) && array() === hcalls() );
site();
http( 'http://good.example/a.png', 200, PNG );
d5b_abilities_set_category( 'media', false, false, 'tester' );
$bad = array();
foreach ( $names as $x ) { if ( 'FORBIDDEN' !== call( 'media-' . $x, array( 'id' => 1, 'url' => 'http://good.example/a.png' ) ) ) { $bad[] = $x; } }
check( 'Media category off: all 6 refused by the gate; the upload handler refuses too; no request', array() === $bad && 'not_allowed' === code( handler( 'media-upload', array( 'url' => 'http://good.example/a.png' ) ) ) && array() === hcalls(), $bad );
site();
http( 'http://good.example/a.png', 200, PNG );
$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 3 ) );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
$r = call( 'media-upload', array( 'url' => 'http://good.example/a.png', 'parent' => $pg ) );
check( 'strictest switch: a parent page while Pages is off is refused (not_allowed) before anything is fetched', 'not_allowed' === code( $r ) && array() === hcalls() && array() === atts(), $r );
d5b_abilities_set_category( 'pages', true, false, 'tester' );
$other = t_add_post( array( 'post_type' => 'page', 'post_author' => 5, 'post_status' => 'draft' ) );
$a1 = att();
site( array_values( array_diff( ED, array( 'edit_others_posts' ) ) ) );
http( 'http://good.example/a.png', 200, PNG );
$other = t_add_post( array( 'post_type' => 'page', 'post_author' => 5, 'post_status' => 'publish' ) );
$a1 = att();
$bad = array();
foreach ( array( 'not editable' => $other, 'an attachment' => $a1, 'missing' => 99999 ) as $what => $pid ) { if ( 'invalid_input' !== code( call( 'media-upload', array( 'url' => 'http://good.example/a.png', 'parent' => $pid ) ) ) ) { $bad[] = $what; } }
check( 'a parent you may not edit, an attachment as parent, or a missing parent: invalid_input, nothing fetched', array() === $bad && array() === hcalls(), $bad );
check( 'text holding [redacted] (a masked read) is refused before anything is fetched', 'masked_read' === code( call( 'media-upload', array( 'url' => 'http://good.example/a.png', 'description' => 'key [redacted]' ) ) ) && array() === hcalls() );

echo "media-update\n";
site();
$a = att();
$before_file = get_post_meta( $a, '_wp_attached_file', true ); $before_meta = get_post_meta( $a, '_wp_attachment_metadata', true );
$r = call( 'media-update', array( 'id' => $a, 'title' => 'New title', 'alt' => 'New alt', 'caption' => 'New caption', 'description' => 'New description' ) );
$h = hlast();
check( 'changes title, alt, caption and description and returns the item', is_array( $r ) && 'New title' === $r['title'] && 'New alt' === $r['alt'] && 'New caption' === $r['caption'] && 'New description' === $r['description'], $r );
check( 'only metadata: the file, its metadata, mime, status and parent are unchanged', $before_file === get_post_meta( $a, '_wp_attached_file', true ) && $before_meta === get_post_meta( $a, '_wp_attachment_metadata', true ) && 'image/jpeg' === post( $a )->post_mime_type && 'inherit' === post( $a )->post_status && 0 === (int) post( $a )->post_parent );
check( 'history: before holds the old title, alt, caption and description; after the changed ones; idempotent annotation', 'update' === $h['action'] && array( 'title' => 'Photo', 'alt' => 'Old alt', 'caption' => 'A caption', 'description' => 'A description' ) === $h['before'] && array( 'title' => 'New title', 'alt' => 'New alt', 'caption' => 'New caption', 'description' => 'New description' ) === $h['after'] && "Updated media $a 'photo.jpg'" === $h['summary'], $h );
$GLOBALS['T_updates'] = array();
$r = call( 'media-update', array( 'id' => $a, 'alt' => 'Only alt' ) );
check( 'alt only: no post update at all, just the alt; before/after carry only what changed in after', is_array( $r ) && array() === $GLOBALS['T_updates'] && 'Only alt' === get_post_meta( $a, '_wp_attachment_image_alt', true ) && array( 'alt' => 'Only alt' ) === hlast()['after'] );
$h0 = count( hrows() );
check( 'nothing to change, an unknown field, or masked text: refused, no history row', 'invalid_input' === code( call( 'media-update', array( 'id' => $a ) ) ) && 'invalid_input' === code( call( 'media-update', array( 'id' => $a, 'file' => 'x' ) ) ) && 'masked_read' === code( call( 'media-update', array( 'id' => $a, 'caption' => 'x [redacted]' ) ) ) && $h0 === count( hrows() ) );
$theirs = att( array( 'post_author' => 5 ), '2026/10/theirs.jpg' );
site( array_values( array_diff( ED, array( 'edit_others_posts' ) ) ) );
$theirs = att( array( 'post_author' => 5 ), '2026/10/theirs.jpg' );
check( 'someone else\'s item without edit_others_posts: refused by the gate and by the handler; unchanged', 'FORBIDDEN' === call( 'media-update', array( 'id' => $theirs, 'title' => 'x' ) ) && 'not_allowed' === code( handler( 'media-update', array( 'id' => $theirs, 'title' => 'x' ) ) ) && 'Photo' === post( $theirs )->post_title );
$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 3 ) );
check( 'an id that is not media is not found', 'FORBIDDEN' === call( 'media-update', array( 'id' => $pg, 'title' => 'x' ) ) && 'not_found' === code( handler( 'media-update', array( 'id' => $pg, 'title' => 'x' ) ) ) );

echo "trash with MEDIA_TRASH off\n";
site();
$a = att();
$r = call( 'media-trash', array( 'id' => $a ) );
check( 'media-trash answers cannot_trash, data.use names media-delete-preview; the item is unchanged, no history row', 'cannot_trash' === code( $r ) && 'divi5-builder/media-delete-preview' === ( $r->get_error_data()['use'] ?? null ) && 'inherit' === post( $a )->post_status && array() === hrows() && array() === $GLOBALS['T_deleted'], $r );
check( 'media-restore of an item that is not in the trash is not_found', 'not_found' === code( call( 'media-restore', array( 'id' => $a ) ) ) );

echo "trash with MEDIA_TRASH on (subprocess)\n";
putenv( 'T_MEDIA_TRASH=1' );
$sub = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d max_execution_time=30 ' . escapeshellarg( __FILE__ ) . ' 2>&1' );
putenv( 'T_MEDIA_TRASH' );
$s = preg_match( '/^SUB:(.*)$/m', $sub, $m ) ? json_decode( $m[1], true ) : null;
check( 'MEDIA_TRASH on: media-trash trashes, returns restore_with, history row trash (before inherit with its URL, after trash)', is_array( $s ) && true === $s['trash_ok'] && 'trash' === $s['st_trash'] && 'trash' === $s['trash_row'][0] && 'inherit' === $s['trash_row'][1] && 'trash' === $s['trash_row'][2] && 'https://x.example/wp-content/uploads/2026/10/photo.jpg' === $s['trash_row'][3] && 0 === strpos( (string) $s['trash_row'][4], 'Trashed media ' ), $sub );
check( '... the preview and the delete are refused with not_allowed (data.use names media-trash), no code is issued, nothing deleted', is_array( $s ) && array( 'not_allowed', 'divi5-builder/media-trash' ) === $s['preview'] && 'not_allowed' === $s['delete'] && 0 === $s['transients'] && array() === $s['deleted'] && true === $s['exists'] );
check( '... media-restore gives back inherit with a restore row; a second restore is not_found; 2 history rows in all', is_array( $s ) && 'inherit' === $s['restore_status'] && array( 'restore', 'trash', 'inherit' ) === $s['restore_row'] && 'not_found' === $s['restore_again'] && 2 === $s['rows'] );

echo "the two-step delete\n";
site( array_values( array_diff( ED, array( 'read_private_posts' ) ) ) );
$a = att();
$feat = t_add_post( array( 'post_author' => 3, 'post_title' => 'Uses it as featured' ) ); t_add_meta( $feat, '_thumbnail_id', (string) $a );
$inc = t_add_post( array( 'post_type' => 'page', 'post_author' => 3, 'post_content' => '<img src="https://x.example/wp-content/uploads/2026/10/photo-300x200.jpg">' ) );
$divi = t_add_post( array( 'post_type' => 'page', 'post_author' => 3, 'post_content' => '<!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"https:\/\/x.example\/wp-content\/uploads\/2026\/10\/photo.jpg"}}}}} /-->' ) );
$priv = t_add_post( array( 'post_author' => 5, 'post_status' => 'private', 'post_content' => 'see 2026/10/photo.jpg' ) );
$rev = t_add_post( array( 'post_type' => 'revision', 'post_parent' => $inc, 'post_status' => 'inherit', 'post_content' => '2026/10/photo.jpg' ) );
$none = t_add_post( array( 'post_author' => 3, 'post_content' => 'nothing to see; 2026/10/other.jpg' ) );
$pv = call( 'media-delete-preview', array( 'id' => $a ) );
$ids = is_array( $pv ) ? array_column( $pv['used_by'], 'id' ) : array();
check( 'the preview returns the item and used_by: the featured-image post, the page with a resized copy in its content and the Divi page with the JSON-escaped URL; not the revision, not the unrelated post', is_array( $pv ) && array( $feat, $inc, $divi ) === $ids && $a === $pv['item']['id'] && 'featured_image' === $pv['used_by'][0]['how'] && 'content' === $pv['used_by'][1]['how'], $pv );
check( '... a post the caller may not read is only counted (used_by_hidden 1), its title is not shown', is_array( $pv ) && 1 === $pv['used_by_hidden'] && ! in_array( $priv, $ids, true ) );
check( '... a 12-character code from A-HJ-NP-Z2-9, expires_at and the message to wait for the user\'s OK', is_array( $pv ) && 1 === preg_match( '/^[A-HJ-NP-Z2-9]{12}$/', $pv['confirmation'] ) && isset( $pv['expires_at'] ) && false !== stripos( $pv['message'], 'explicit OK' ) );
check( '... and it changed nothing: no history row, the item and its files are there, its audit row is ok', array() === hrows() && null !== post( $a ) && isset( $GLOBALS['T_files']['2026/10/photo.jpg'] ) && 'ok' === end( $GLOBALS['wpdb']->tables['wp_d5b_audit'] )['status'] );
$r = call( 'media-delete', array( 'id' => $a ) );
check( 'delete without a code: confirmation_invalid (missing), nothing deleted', 'confirmation_invalid' === code( $r ) && 'missing' === reason( $r ) && null !== post( $a ) && array() === hrows(), $r );
$r = call( 'media-delete', array( 'id' => $a, 'confirmation' => 'ABCDEFGHJKLM' ) );
check( 'delete with a wrong code: confirmation_invalid, nothing deleted', 'confirmation_invalid' === code( $r ) && null !== post( $a ) );
$GLOBALS['T_user_id'] = 4;
$r = call( 'media-delete', array( 'id' => $a, 'confirmation' => $pv['confirmation'] ) );
check( 'another user presenting the code: confirmation_invalid (wrong_user), nothing deleted', 'confirmation_invalid' === code( $r ) && 'wrong_user' === reason( $r ) && null !== post( $a ), $r );
$GLOBALS['T_user_id'] = 3;
$b = att( array(), '2026/10/second.jpg' );
$pvb = call( 'media-delete-preview', array( 'id' => $b ) );
$r = call( 'media-delete', array( 'id' => $a, 'confirmation' => $pvb['confirmation'] ) );
check( 'the code of another item: confirmation_invalid (wrong_object), nothing deleted', 'confirmation_invalid' === code( $r ) && 'wrong_object' === reason( $r ) && null !== post( $a ) && null !== post( $b ), $r );
$pv = call( 'media-delete-preview', array( 'id' => $a ) );
$GLOBALS['T_posts'][ $a ]->post_modified_gmt = '2026-10-29 12:00:00';
$r = call( 'media-delete', array( 'id' => $a, 'confirmation' => $pv['confirmation'] ) );
check( 'a stale code (the item was modified after the preview): confirmation_invalid (stale), nothing deleted', 'confirmation_invalid' === code( $r ) && 'stale' === reason( $r ) && null !== post( $a ), $r );
$pv = call( 'media-delete-preview', array( 'id' => $a ) );
$GLOBALS['T_meta'][ $a ]['_wp_attached_file'] = array( '2026/10/photo-replaced.jpg' );
$r = call( 'media-delete', array( 'id' => $a, 'confirmation' => $pv['confirmation'] ) );
check( 'a stale code (the file was replaced after the preview): stale, nothing deleted', 'stale' === reason( $r ) && null !== post( $a ), $r );
$GLOBALS['T_meta'][ $a ]['_wp_attached_file'] = array( '2026/10/photo.jpg' );
$pv = call( 'media-delete-preview', array( 'id' => $a ) );
$GLOBALS['T_attach_delete_blocked'] = true;
$r1 = call( 'media-delete', array( 'id' => $a, 'confirmation' => $pv['confirmation'] ) );
$GLOBALS['T_attach_delete_blocked'] = false;
$r2 = call( 'media-delete', array( 'id' => $a, 'confirmation' => $pv['confirmation'] ) );
check( 'when WordPress does not delete, write_failed and the item is there; the code was used, so presenting it again is confirmation_invalid (used)', 'write_failed' === code( $r1 ) && 'confirmation_invalid' === code( $r2 ) && 'used' === reason( $r2 ) && null !== post( $a ), array( $r1, $r2 ) );
$hr = hrows(); $full = d5b_history_get( (int) $hr[ count( $hr ) - 2 ]['id'] ); $fail = hlast();
check( '... the message says it may be partly deleted and names the history row with the full state; that row is kept and a second row (delete_failed, after deleted false + error) records the failure',
	false !== strpos( msg( $r1 ), 'may be partly deleted' ) && false !== strpos( msg( $r1 ), 'History row ' . $full['id'] . ' holds its full state' ) && 'delete' === $full['action'] && isset( $full['before']['url'] )
	&& 'delete_failed' === $fail['action'] && false === $fail['after']['deleted'] && 'wp_delete_attachment_failed' === $fail['after']['error'] && (int) $full['id'] === $fail['after']['history_id'] && $a === (int) $fail['object_id'], array( msg( $r1 ), $full['action'] ?? null, $fail ) );

echo "the history row comes first\n";
$h0 = count( hrows() );
$pv = call( 'media-delete-preview', array( 'id' => $a ) );
unset( $GLOBALS['wpdb']->tables['wp_d5b_history'] ); $GLOBALS['T_dbdelta_fail'] = true; set_transient( 'd5b_audit_install_failed', 1, 3600 );
$r = call( 'media-delete', array( 'id' => $a, 'confirmation' => $pv['confirmation'] ) );
check( 'when the history row cannot be written the delete is refused history_unavailable and the attachment and its files are still there', 'history_unavailable' === code( $r ) && null !== post( $a ) && isset( $GLOBALS['T_files']['2026/10/photo.jpg'] ) && array() === $GLOBALS['T_files_deleted'] && ! in_array( $a, $GLOBALS['T_deleted'], true ), $r );
check( '... the audit row records the refusal', 'ok' !== end( $GLOBALS['wpdb']->tables['wp_d5b_audit'] )['status'] );
$GLOBALS['T_dbdelta_fail'] = false; $GLOBALS['wpdb']->tables['wp_d5b_history'] = array();
$pv = call( 'media-delete-preview', array( 'id' => $a ) );
$url = $pv['item']['file_url'];
$r = call( 'media-delete', array( 'id' => $a, 'confirmation' => $pv['confirmation'] ) );
$h = hlast();
check( 'a valid delete removes the attachment, its file and its sizes, and the featured image that pointed at it', is_array( $r ) && true === $r['deleted'] && null === post( $a ) && ! isset( $GLOBALS['T_files']['2026/10/photo.jpg'] ) && ! isset( $GLOBALS['T_files']['2026/10/photo-300x200.jpg'] ) && array() === get_post_meta( $feat, '_thumbnail_id' ), $r );
check( 'the history row (id returned) holds the metadata, URL, sizes, file, the posts that used it, file_copied false; action delete', is_array( $r ) && (int) $h['id'] === $r['history_id'] && 'delete' === $h['action'] && $a === (int) $h['object_id'] && $url === $h['before']['url'] && '2026/10/photo.jpg' === $h['before']['file'] && 'photo-300x200.jpg' === $h['before']['metadata']['sizes']['medium']['file'] && isset( $h['before']['sizes']['medium'] ) && false === $h['before']['file_copied'] && in_array( $feat, array_column( $h['before']['used_by'], 'id' ), true ) && 'Old alt' === $h['before']['alt'] && 'A description' === $h['before']['description'], $h );
check( 'the summary: "Permanently deleted media N \'photo.jpg\'"', "Permanently deleted media $a 'photo.jpg'" === $h['summary'], $h['summary'] );
check( 'deleting again is not_found', 'FORBIDDEN' === call( 'media-delete', array( 'id' => $a, 'confirmation' => $pv['confirmation'] ) ) && 'not_found' === code( handler( 'media-delete', array( 'id' => $a, 'confirmation' => $pv['confirmation'] ) ) ) );
site( READER );
$a = att();
check( 'without delete_post: preview and delete refused by the gate, no code issued', 'FORBIDDEN' === call( 'media-delete-preview', array( 'id' => $a ) ) && 'FORBIDDEN' === call( 'media-delete', array( 'id' => $a, 'confirmation' => 'X' ) ) && array() === array_filter( array_keys( $GLOBALS['T_transients'] ), function ( $k ) { return 0 === strpos( $k, 'd5b_confirm_' ); } ) && null !== post( $a ) );
site();
$a = att();
$GLOBALS['T_transient_fail'] = true;
$r = call( 'media-delete-preview', array( 'id' => $a ) );
$GLOBALS['T_transient_fail'] = false;
check( 'when the code cannot be stored the preview passes on unavailable (no code is shown)', 'unavailable' === code( $r ), $r );

// ===== Task 11 fix wave (break-it reproducers a2, a3)
if ( ! getenv( 'T_MEDIA_TRASH' ) ) {
	echo "Task 11 M1: a post that starts using the item after the preview makes the code stale (a2)\n";
	site();
	$a  = att( array(), '2026/10/b.jpg' );
	$pv = call( 'media-delete-preview', array( 'id' => $a ) );
	$user = t_add_post( array( 'post_author' => 3 ) ); t_add_meta( $user, '_thumbnail_id', (string) $a );
	$r = call( 'media-delete', array( 'id' => $a, 'confirmation' => $pv['confirmation'] ) );
	check( 'a post set it as featured image after the preview (preview used_by []): stale, the item is kept', array() === $pv['used_by'] && 'stale' === reason( $r ) && null !== post( $a ), $r );
	echo "Task 11 I1: a media delete whose copy would be cut is refused (a3)\n";
	site();
	$a  = att( array(), '2026/10/c.jpg' );
	t_add_meta( $a, '_plugin_exif_dump', str_repeat( 'e', 9 * 1024 * 1024 ) );
	$pv = call( 'media-delete-preview', array( 'id' => $a ) );
	$r  = call( 'media-delete', array( 'id' => $a, 'confirmation' => is_array( $pv ) ? $pv['confirmation'] : '' ) );
	check( 'media-delete of an item with a 9 MB custom field: history_unavailable ("over the size limit"), the item and its file are kept, no row left', 'history_unavailable' === code( $r ) && false !== strpos( msg( $r ), 'over the size limit' ) && null !== post( $a ) && isset( $GLOBALS['T_files']['2026/10/c.jpg'] ) && array() === hrows() && array() === $GLOBALS['T_deleted'], array( code( $pv ), code( $r ), msg( $r ) ) );
}

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
