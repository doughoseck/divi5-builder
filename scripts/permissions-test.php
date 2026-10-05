<?php
/**
 * Permission test for the mu-plugin's REST routes (security fixes in 1.8.3).
 *
 *   php scripts/permissions-test.php [path-to-divi5-builder-rest.php]
 *
 * Loads the real plugin file with just enough of WordPress stubbed, then calls each
 * route's permission_callback (and callback where it matters) as a Contributor, an
 * Editor and an Administrator. Run it against 1.8.2 and it FAILS (the holes are real);
 * against 1.8.3 every check passes. Exits non-zero on any failure.
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['T_routes'] = array(); $GLOBALS['T_actions'] = array(); $GLOBALS['T_meta_writes'] = array();

class WP_Error { public $code; function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['T_actions'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) {}
function register_post_meta() {}
function register_meta() {}
function register_rest_route( $ns, $path, $args ) {
	foreach ( ( isset( $args['methods'] ) || isset( $args['callback'] ) ) ? array( $args ) : $args as $a ) {
		foreach ( (array) ( $a['methods'] ?? 'GET' ) as $m ) {
			foreach ( explode( ',', $m ) as $mm ) { $GLOBALS['T_routes'][ trim( strtoupper( $mm ) ) . ' ' . $path ] = $a; }
		}
	}
}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['T_meta_writes'][] = array( $id, $k ); return true; }
function get_post_meta( $id, $k = '', $single = false ) {
	if ( '' !== $k ) { return ''; }
	return array( '_et_pb_use_builder' => array( 'on' ), '_divi_canvas_id' => array( 'x' ), '_secret_token' => array( 's3cret' ), 'public_note' => array( 'hi' ) );
}
function is_protected_meta( $k, $t = null ) { return '_' === $k[0]; }

// Site: admin=1, editor=3, contributor=2. Page 10 = homepage (admin's). Post 50 = contributor's
// own draft. Canvas 60 = admin's popup.
$POSTS = array(
	10 => (object) array( 'ID' => 10, 'post_type' => 'page', 'post_author' => 1, 'post_status' => 'publish', 'post_parent' => 0 ),
	50 => (object) array( 'ID' => 50, 'post_type' => 'post', 'post_author' => 2, 'post_status' => 'draft', 'post_parent' => 0 ),
	60 => (object) array( 'ID' => 60, 'post_type' => 'et_pb_canvas', 'post_author' => 1, 'post_status' => 'publish', 'post_parent' => 0 ),
);
function get_post( $id ) { global $POSTS; return $POSTS[ (int) $id ] ?? null; }

$ROLES = array(
	'contributor'   => array( 'id' => 2, 'caps' => array( 'edit_posts' ) ),
	'editor'        => array( 'id' => 3, 'caps' => array( 'edit_posts', 'edit_others_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'unfiltered_html' ) ),
	'administrator' => array( 'id' => 1, 'caps' => array( 'edit_posts', 'edit_others_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'unfiltered_html', 'manage_options', 'edit_theme_options', 'edit_css', 'update_plugins' ) ),
);
$ME = 'contributor';
function current_user_can( $cap, $id = null ) {
	global $ROLES, $ME;
	$u = $ROLES[ $ME ];
	if ( 'edit_post' === $cap ) {
		$p = get_post( $id );
		if ( ! $p ) { return false; }
		if ( in_array( 'edit_others_posts', $u['caps'], true ) ) { return true; }
		return (int) $p->post_author === $u['id'] && 'publish' !== $p->post_status;
	}
	return in_array( $cap, $u['caps'], true );
}

class T_Req { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return $this->p[ $k ] ?? null; } function get_json_params() { return $this->p; } function get_params() { return $this->p; } }
class T_Wpdb { public $options = 'wp_options'; function esc_like( $s ) { return $s; } function prepare( $q ) { return $q; }
	function get_results( $q ) { return array( (object) array( 'option_name' => 'smtp_pass', 'v' => 'hunter2' ) ); } }
$GLOBALS['wpdb'] = new T_Wpdb();

$file = $argv[1] ?? __DIR__ . '/../assets/divi5-builder-rest.php';
require $file;
foreach ( $GLOBALS['T_actions']['rest_api_init'] ?? array() as $cb ) { $cb(); }
echo 'plugin: ' . basename( $file ) . ' ' . ( defined( 'D5B_REST_VERSION' ) ? D5B_REST_VERSION : '?' ) . "\n";

$failed = 0;
$check  = function ( $name, $ok ) use ( &$failed ) { echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . "\n"; if ( ! $ok ) { $failed++; } };
$can    = function ( $role, $route, $params ) {
	global $ME; $ME = $role;
	$r = $GLOBALS['T_routes'][ $route ] ?? null;
	if ( ! $r ) { fwrite( STDERR, "STOP: route $route not registered\n" ); exit( 2 ); }
	return true === ( $r['permission_callback'] )( new T_Req( $params ) );
};
$call   = function ( $role, $route, $params ) { global $ME; $ME = $role; return ( $GLOBALS['T_routes'][ $route ]['callback'] )( new T_Req( $params ) ); };

echo "postinfo ?scan= (reads wp_options values)\n";
$check( 'contributor + own draft + scan=pass  -> DENIED', ! $can( 'contributor', 'GET /postinfo', array( 'id' => 50, 'scan' => 'pass' ) ) );
$check( 'editor + any page + scan=pass        -> DENIED', ! $can( 'editor', 'GET /postinfo', array( 'id' => 10, 'scan' => 'pass' ) ) );
$check( 'administrator + scan=global_presets  -> allowed', $can( 'administrator', 'GET /postinfo', array( 'id' => 10, 'scan' => 'global_presets' ) ) );
$check( 'contributor + own draft, no scan     -> allowed', $can( 'contributor', 'GET /postinfo', array( 'id' => 50 ) ) );
$check( 'contributor + homepage, no scan      -> DENIED', ! $can( 'contributor', 'GET /postinfo', array( 'id' => 10 ) ) );

echo "postinfo meta filtering\n";
$m = $call( 'contributor', 'GET /postinfo', array( 'id' => 50 ) )['meta'];
$check( 'non-admin does not see protected _secret_token', ! isset( $m['_secret_token'] ) );
$check( 'non-admin still sees _et_* / _divi_* and public meta', isset( $m['_et_pb_use_builder'], $m['_divi_canvas_id'], $m['public_note'] ) );
$m = $call( 'administrator', 'GET /postinfo', array( 'id' => 10 ) )['meta'];
$check( 'administrator sees all meta', isset( $m['_secret_token'] ) );

echo "link-canvas (writes builder meta on page_id)\n";
$check( 'contributor: id=own draft, canvas=60, page=homepage -> DENIED', ! $can( 'contributor', 'POST /link-canvas', array( 'id' => 50, 'canvas_id' => 60, 'page_id' => 10 ) ) );
$check( 'contributor: canvas=60, page=own draft             -> DENIED', ! $can( 'contributor', 'POST /link-canvas', array( 'canvas_id' => 60, 'page_id' => 50 ) ) );
$check( 'editor: canvas=60, page=homepage                   -> allowed', $can( 'editor', 'POST /link-canvas', array( 'canvas_id' => 60, 'page_id' => 10 ) ) );
$check( 'editor: missing page_id                            -> DENIED', ! $can( 'editor', 'POST /link-canvas', array( 'canvas_id' => 60 ) ) );

echo "custom-css (site-wide CSS)\n";
$ROLES['site_admin_no_edit_css'] = array( 'id' => 4, 'caps' => array_values( array_diff( $ROLES['administrator']['caps'], array( 'edit_css' ) ) ) );
$check( 'admin WITHOUT edit_css (multisite / DISALLOW_UNFILTERED_HTML) -> DENIED', ! $can( 'site_admin_no_edit_css', 'POST /custom-css', array( 'css' => 'x' ) ) );
$check( 'administrator with edit_css -> allowed', $can( 'administrator', 'POST /custom-css', array( 'css' => 'x' ) ) );
$check( 'editor -> DENIED', ! $can( 'editor', 'POST /custom-css', array( 'css' => 'x' ) ) );

echo "unchanged admin-only routes\n";
foreach ( array( 'GET /option', 'POST /option', 'GET /global-colors', 'POST /global-colors' ) as $rt ) {
	if ( isset( $GLOBALS['T_routes'][ $rt ] ) ) {
		$check( "$rt: editor DENIED, administrator allowed", ! $can( 'editor', $rt, array( 'name' => 'et_global_colors' ) ) && $can( 'administrator', $rt, array( 'name' => 'et_global_colors' ) ) );
	}
}

echo $failed ? "\n$failed FAILED\n" : "\nALL PASSED\n";
exit( $failed ? 1 : 0 );
