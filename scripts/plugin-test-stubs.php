<?php
/**
 * Shared WordPress stand-ins for the one-plugin tests (#37).
 *
 *   require __DIR__ . '/plugin-test-stubs.php';
 *
 * A plain include. Everything it records lives in $GLOBALS so a test can read it:
 *   T_routes         'METHOD ns/path' => route args   (every register_rest_route call)
 *   T_writes         list of array( function, option name )   (update_option, add_option, update_site_option, add_site_option, delete_option)
 *   T_settings       list of register_setting calls: array( group, name, args )
 *   T_deactivated    list of deactivate_plugins calls
 *   T_deactivate_calls  list of array( plugins, silent, network ) for each deactivate_plugins call
 *   T_actions        hook => list of callbacks (add_action, never run)
 *   T_filters        hook => list of callbacks (add_filter, never run)
 *   T_priorities     hook => list of priorities, parallel to T_actions
 *   T_activation     list of array( file, callback ) from register_activation_hook
 *   T_post_meta      list of array( post type, meta key ) from register_post_meta
 *   T_autoload       option name => the autoload argument last given to add_option / update_option
 *   T_options        option name => value (site options too, in T_site_options)
 *   T_server_routes  path => anything: what rest_get_server()->get_routes() reports as already registered
 *   T_server_calls   how many times rest_get_server() was called since t_reset()
 * Setters: t_set_caps( array ), t_set_multisite( bool ), t_reset().
 */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', sys_get_temp_dir() . '/d5b-stub-abspath/' ); }

function t_reset() {
	$GLOBALS['T_routes'] = array(); $GLOBALS['T_writes'] = array(); $GLOBALS['T_settings'] = array();
	$GLOBALS['T_deactivated'] = array(); $GLOBALS['T_deactivate_calls'] = array(); $GLOBALS['T_actions'] = array();
	$GLOBALS['T_autoload'] = array(); $GLOBALS['T_priorities'] = array(); $GLOBALS['T_activation'] = array(); $GLOBALS['T_post_meta'] = array(); $GLOBALS['T_filters'] = array(); $GLOBALS['T_server_calls'] = 0;
	$GLOBALS['T_options'] = array(); $GLOBALS['T_site_options'] = array(); $GLOBALS['T_server_routes'] = array();
	unset( $GLOBALS['d5b_stood_aside'], $GLOBALS['d5b_gate_ran'], $GLOBALS['T_server'] );
	if ( function_exists( 't_blogs_reset' ) ) { t_blogs_reset(); }
	if ( function_exists( 't_abilities_reset' ) ) { t_abilities_reset(); }
}
// A new request: WordPress starts a request with the gate not yet run.
function t_new_request() { unset( $GLOBALS['d5b_gate_ran'] ); }
function t_set_caps( array $caps ) { $GLOBALS['T_caps'] = $caps; }
function t_set_multisite( $on ) { $GLOBALS['T_multisite'] = (bool) $on; }
t_reset(); t_set_caps( array() ); t_set_multisite( false );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; public $data; function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } function get_error_message() { return $this->message; } function get_error_code() { return $this->code; } }
}
// $method and $route as WP_REST_Request::get_method() / get_route() give them (only rest_pre_dispatch tests need them).
class T_Request { private $p; private $m; private $r; function __construct( array $p = array(), $method = 'GET', $route = '' ) { $this->p = $p; $this->m = $method; $this->r = $route; } function get_param( $k ) { return $this->p[ $k ] ?? null; } function get_params() { return $this->p; } function get_json_params() { return $this->p; } function get_method() { return $this->m; } function get_route() { return $this->r; } }
// The gate checks for core's class, so the stand-in server is one.
if ( ! class_exists( 'WP_REST_Server' ) ) { class WP_REST_Server {} }
class T_Server extends WP_REST_Server { public $routes = null; function get_routes() { return null === $this->routes ? $GLOBALS['T_server_routes'] : $this->routes; } }
// A second WP_REST_Server in the same request: its own, empty, route table.
function t_new_server() { $s = new T_Server(); $s->routes = array(); $GLOBALS['T_server'] = $s; return $s; }

function is_wp_error( $x ) { return $x instanceof WP_Error; }
function is_multisite() { return $GLOBALS['T_multisite']; }
function current_user_can( $cap, $id = null ) { return in_array( $cap, $GLOBALS['T_caps'], true ); }
function add_action( $h, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['T_actions'][ $h ][] = $cb; $GLOBALS['T_priorities'][ $h ][] = $p; }
function register_activation_hook( $file, $cb ) { $GLOBALS['T_activation'][] = array( $file, $cb ); }
function add_filter( $h, $cb = null, $p = 10, $a = 1 ) { $GLOBALS['T_filters'][ $h ][] = $cb; }
function register_post_meta( $type = '', $key = '', $args = array() ) { $GLOBALS['T_post_meta'][] = array( $type, $key ); }
function register_meta() {}
function rest_get_server() { $GLOBALS['T_server_calls']++; if ( empty( $GLOBALS['T_server'] ) ) { $GLOBALS['T_server'] = new T_Server(); } return $GLOBALS['T_server']; }
function register_rest_route( $ns, $path, $args ) {
	foreach ( ( isset( $args['methods'] ) || isset( $args['callback'] ) ) ? array( $args ) : $args as $a ) {
		foreach ( (array) ( $a['methods'] ?? 'GET' ) as $m ) {
			foreach ( explode( ',', $m ) as $mm ) { $GLOBALS['T_routes'][ trim( strtoupper( $mm ) ) . ' ' . $ns . $path ] = $a; }
		}
	}
}
function register_setting( $group, $name, $args = array() ) { $GLOBALS['T_settings'][] = array( $group, $name, $args ); }
// Set T_NO_DEACTIVATE in the environment to run without deactivate_plugins (as before wp-admin/includes/plugin.php is loaded).
if ( ! getenv( 'T_NO_DEACTIVATE' ) ) {
	function deactivate_plugins( $plugins, $silent = false, $network = null ) { $GLOBALS['T_deactivated'][] = $plugins; $GLOBALS['T_deactivate_calls'][] = array( $plugins, $silent, $network ); }
}

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['T_options'] ) ? $GLOBALS['T_options'][ $n ] : $d; }
function update_option( $n, $v, $autoload = null ) { $GLOBALS['T_writes'][] = array( 'update_option', $n ); $GLOBALS['T_options'][ $n ] = $v; if ( null !== $autoload ) { $GLOBALS['T_autoload'][ $n ] = $autoload; } return true; }
function add_option( $n, $v = '', $dep = '', $autoload = 'yes' ) { $GLOBALS['T_writes'][] = array( 'add_option', $n ); $GLOBALS['T_options'][ $n ] = $v; $GLOBALS['T_autoload'][ $n ] = $autoload; return true; }
function delete_option( $n ) { $GLOBALS['T_writes'][] = array( 'delete_option', $n ); unset( $GLOBALS['T_options'][ $n ] ); return true; }
function get_site_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['T_site_options'] ) ? $GLOBALS['T_site_options'][ $n ] : $d; }
function update_site_option( $n, $v ) { $GLOBALS['T_writes'][] = array( 'update_site_option', $n ); $GLOBALS['T_site_options'][ $n ] = $v; return true; }
function add_site_option( $n, $v ) { $GLOBALS['T_writes'][] = array( 'add_site_option', $n ); $GLOBALS['T_site_options'][ $n ] = $v; return true; }
function delete_site_option( $n ) { $GLOBALS['T_writes'][] = array( 'delete_site_option', $n ); unset( $GLOBALS['T_site_options'][ $n ] ); return true; }

// ---- the other sites of a network (final review). t_reset() clears them.
//   T_blogs          blog id => array( option name => value ): what get_blog_option reads for each site
//   T_blog_id        what get_current_blog_id returns;  T_main_site   what get_main_site_id returns
//   T_get_sites      list of the argument arrays get_sites was called with
function t_blogs_reset() { $GLOBALS['T_blogs'] = array(); $GLOBALS['T_blog_id'] = 1; $GLOBALS['T_main_site'] = 1; $GLOBALS['T_get_sites'] = array(); }
t_blogs_reset();
function get_current_blog_id() { return $GLOBALS['T_blog_id']; }
function get_main_site_id( $network_id = null ) { return $GLOBALS['T_main_site']; }
function is_main_site( $site_id = null, $network_id = null ) { return ( null === $site_id ? $GLOBALS['T_blog_id'] : (int) $site_id ) === $GLOBALS['T_main_site']; }
// fields => ids only, as the plugin asks for; number (WordPress defaults to 100) and offset honoured.
function get_sites( $args = array() ) {
	$GLOBALS['T_get_sites'][] = $args;
	$ids = array_keys( $GLOBALS['T_blogs'] ); sort( $ids );
	return array_slice( $ids, (int) ( $args['offset'] ?? 0 ), (int) ( $args['number'] ?? 100 ) );
}
function get_blog_option( $id, $n, $d = false ) { return isset( $GLOBALS['T_blogs'][ $id ] ) && array_key_exists( $n, $GLOBALS['T_blogs'][ $id ] ) ? $GLOBALS['T_blogs'][ $id ][ $n ] : $d; }
function delete_blog_option( $id, $n ) { $GLOBALS['T_writes'][] = array( 'delete_blog_option', $n, $id ); unset( $GLOBALS['T_blogs'][ $id ][ $n ] ); return true; }
// Relative to WP_PLUGIN_DIR (or WPMU_PLUGIN_DIR) as core's plugin_basename is; otherwise folder/file.
function plugin_basename( $file ) {
	$f = str_replace( '\\', '/', $file );
	foreach ( array( 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ) as $c ) {
		if ( defined( $c ) ) { $d = rtrim( str_replace( '\\', '/', constant( $c ) ), '/' ) . '/'; if ( 0 === strpos( $f, $d ) ) { return substr( $f, strlen( $d ) ); } }
	}
	return basename( dirname( $f ) ) . '/' . basename( $f );
}

function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
function update_post_meta( $id, $k, $v ) { return true; }
function get_post_meta( $id, $k = '', $single = false ) { return ''; }
function is_protected_meta( $k, $t = null ) { return '_' === $k[0]; }
function get_post( $id ) { return null; }
function wp_upload_dir() { return array( 'basedir' => ABSPATH . 'wp-content/uploads', 'baseurl' => 'https://x.example/wp-content/uploads' ); }

// ---- admin page stand-ins (Task 4). t_reset() does not clear these; t_admin_reset() does.
//   T_referer_ok      what check_admin_referer returns (real WordPress dies on failure; the code under test must stop either way)
//   T_referer_calls   list of the actions check_admin_referer was asked about
//   T_dies            list of wp_die messages (the stub does not exit, the code must return after it)
//   T_redirects       list of wp_safe_redirect targets
//   T_menus           list of array( function, args... ) for add_options_page / add_submenu_page
//   T_network_admin   what is_network_admin returns;  T_user_login   what wp_get_current_user()->user_login is
function t_admin_reset() {
	$GLOBALS['T_referer_ok'] = true; $GLOBALS['T_referer_calls'] = array(); $GLOBALS['T_dies'] = array();
	$GLOBALS['T_redirects'] = array(); $GLOBALS['T_menus'] = array(); $GLOBALS['T_network_admin'] = false; $GLOBALS['T_user_login'] = 'tester';
}
t_admin_reset();
function check_admin_referer( $action = -1, $arg = '_wpnonce' ) { $GLOBALS['T_referer_calls'][] = $action; return $GLOBALS['T_referer_ok']; }
function wp_die( $msg = '', $title = '', $args = array() ) { $GLOBALS['T_dies'][] = $msg; }
function wp_safe_redirect( $url, $status = 302 ) { $GLOBALS['T_redirects'][] = $url; return true; }
function wp_get_current_user() { return (object) array( 'user_login' => $GLOBALS['T_user_login'] ); }
function is_network_admin() { return $GLOBALS['T_network_admin']; }
function admin_url( $p = '' ) { return 'https://x.example/wp-admin/' . ltrim( $p, '/' ); }
function network_admin_url( $p = '' ) { return 'https://x.example/wp-admin/network/' . ltrim( $p, '/' ); }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) { $h = '<input type="hidden" name="' . $name . '" value="nonce-for-' . $action . '" />'; if ( $echo ) { echo $h; } return $h; }
function add_options_page( $title, $menu, $cap, $slug, $cb = '' ) { $GLOBALS['T_menus'][] = array( 'add_options_page', $title, $menu, $cap, $slug, $cb ); return 'settings_page_' . $slug; }
function add_submenu_page( $parent, $title, $menu, $cap, $slug, $cb = '' ) { $GLOBALS['T_menus'][] = array( 'add_submenu_page', $parent, $title, $menu, $cap, $slug, $cb ); return $parent . '_page_' . $slug; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v ); }

// ---- Abilities API stand-ins (#32, Task 2). Recorded in:
//   T_abilities           name => args   (wp_register_ability calls that core would accept)
//   T_ability_categories  slug => args   (wp_register_ability_category calls that core would accept)
//   T_ability_errors      list of "register ... refused: reason" strings (what _doing_it_wrong would report)
//   T_doing               hooks currently "running" (set by t_fire)
// t_fire( $hook ) runs every callback added to $hook with doing_action( $hook ) true, as core does.
// Set the environment variable D5B_NO_ABILITIES_API=1 before loading this file to leave wp_register_ability*
// and wp_has_ability* undefined, like WordPress before 6.9.
$GLOBALS['T_abilities'] = array(); $GLOBALS['T_ability_categories'] = array(); $GLOBALS['T_ability_errors'] = array(); $GLOBALS['T_doing'] = array();
function t_abilities_reset() { $GLOBALS['T_abilities'] = array(); $GLOBALS['T_ability_categories'] = array(); $GLOBALS['T_ability_errors'] = array(); $GLOBALS['T_doing'] = array(); }
function doing_action( $h = null ) { return null === $h ? ! empty( $GLOBALS['T_doing'] ) : in_array( $h, $GLOBALS['T_doing'], true ); }
function t_fire( $hook ) { $GLOBALS['T_doing'][] = $hook; try { foreach ( $GLOBALS['T_actions'][ $hook ] ?? array() as $cb ) { call_user_func( $cb ); } } finally { array_pop( $GLOBALS['T_doing'] ); } }
if ( ! getenv( 'D5B_NO_ABILITIES_API' ) ) {
	function wp_has_ability( $n ) { return isset( $GLOBALS['T_abilities'][ $n ] ); }
	function wp_has_ability_category( $s ) { return isset( $GLOBALS['T_ability_categories'][ $s ] ); }
	function wp_register_ability( $name, $args = array() ) {
		$why = '';
		if ( ! doing_action( 'wp_abilities_api_init' ) ) { $why = 'outside wp_abilities_api_init'; }
		elseif ( 1 !== preg_match( '#^[a-z0-9]+(-[a-z0-9]+)*/[a-z0-9]+(-[a-z0-9]+)*$#', (string) $name ) ) { $why = 'bad name'; }
		elseif ( isset( $GLOBALS['T_abilities'][ $name ] ) ) { $why = 'already registered'; }
		elseif ( empty( $args['label'] ) || empty( $args['description'] ) || ! is_callable( $args['execute_callback'] ?? null ) ) { $why = 'missing label, description or execute_callback'; }
		if ( '' !== $why ) { $GLOBALS['T_ability_errors'][] = "ability $name refused: $why"; return null; }
		$GLOBALS['T_abilities'][ $name ] = $args;
		return (object) array( 'name' => $name );
	}
	function wp_register_ability_category( $slug, $args = array() ) {
		$why = '';
		if ( ! doing_action( 'wp_abilities_api_categories_init' ) ) { $why = 'outside wp_abilities_api_categories_init'; }
		elseif ( 1 !== preg_match( '#^[a-z0-9]+(-[a-z0-9]+)*$#', (string) $slug ) ) { $why = 'bad slug'; }
		elseif ( isset( $GLOBALS['T_ability_categories'][ $slug ] ) ) { $why = 'already registered'; }
		elseif ( empty( $args['label'] ) || empty( $args['description'] ) ) { $why = 'missing label or description'; }
		if ( '' !== $why ) { $GLOBALS['T_ability_errors'][] = "category $slug refused: $why"; return null; }
		$GLOBALS['T_ability_categories'][ $slug ] = $args;
		return (object) array( 'slug' => $slug );
	}
}
