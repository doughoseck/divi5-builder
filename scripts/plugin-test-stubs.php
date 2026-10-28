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
	if ( function_exists( 't_wpdb_reset' ) ) { t_wpdb_reset(); }
	if ( function_exists( 't_content_reset' ) ) { t_content_reset(); }
}
// A new request: WordPress starts a request with the gate not yet run.
function t_new_request() { unset( $GLOBALS['d5b_gate_ran'] ); }
function t_set_caps( array $caps ) { $GLOBALS['T_caps'] = $caps; }
function t_set_multisite( $on ) { $GLOBALS['T_multisite'] = (bool) $on; }
t_reset(); t_set_caps( array() ); t_set_multisite( false );

// WP_Error as core has it (class-wp-error.php): several codes, several messages per code, data per code (older data kept
// in additional_data). The plugin may use only these methods. The read-only ->code, ->message and ->data are a test
// convenience (the first code, its first message, its data) that core does NOT have; a source check in the gate test
// makes sure no plugin file uses them.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array(); public $error_data = array(); protected $additional_data = array();
		function __construct( $code = '', $message = '', $data = '' ) { if ( empty( $code ) ) { return; } $this->add( $code, $message, $data ); }
		function get_error_codes() { return $this->has_errors() ? array_keys( $this->errors ) : array(); }
		function get_error_code() { $c = $this->get_error_codes(); return empty( $c ) ? '' : $c[0]; }
		function get_error_messages( $code = '' ) {
			if ( empty( $code ) ) { $all = array(); foreach ( (array) $this->errors as $m ) { $all = array_merge( $all, $m ); } return $all; }
			return $this->errors[ $code ] ?? array();
		}
		function get_error_message( $code = '' ) { if ( empty( $code ) ) { $code = $this->get_error_code(); } $m = $this->get_error_messages( $code ); return empty( $m ) ? '' : $m[0]; }
		function get_error_data( $code = '' ) { if ( empty( $code ) ) { $code = $this->get_error_code(); } return $this->error_data[ $code ] ?? null; }
		function has_errors() { return ! empty( $this->errors ); }
		function add( $code, $message, $data = '' ) { $this->errors[ $code ][] = $message; if ( ! empty( $data ) ) { $this->add_data( $data, $code ); } }
		function add_data( $data, $code = '' ) { if ( empty( $code ) ) { $code = $this->get_error_code(); } if ( isset( $this->error_data[ $code ] ) ) { $this->additional_data[ $code ][] = $this->error_data[ $code ]; } $this->error_data[ $code ] = $data; }
		function get_all_error_data( $code = '' ) { if ( empty( $code ) ) { $code = $this->get_error_code(); } $d = $this->additional_data[ $code ] ?? array(); if ( isset( $this->error_data[ $code ] ) ) { $d[] = $this->error_data[ $code ]; } return $d; }
		function __get( $k ) { if ( 'code' === $k ) { return $this->get_error_code(); } if ( 'message' === $k ) { return $this->get_error_message(); } if ( 'data' === $k ) { return $this->get_error_data(); } return null; }
	}
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
// current_user_can: defined in the content stand-ins at the end of this file (it understands read_post and edit_post).
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
// get_post_meta: see the content stand-ins at the end of this file.
function is_protected_meta( $k, $t = null ) { return '_' === $k[0]; }
// get_post: see the content stand-ins at the end of this file.
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

// ---- database, cron and user stand-ins for the audit log (#32, Task 3). t_reset() clears them.
//   $wpdb              T_Wpdb: tables kept in memory (name => list of rows). Understands exactly the statements the audit code
//                      sends: insert(), DELETE ... WHERE created_at < 'x', SELECT ... WHERE ability/user_id/status/created_at ...
//                      ORDER BY id DESC LIMIT n OFFSET m, SHOW TABLES LIKE 'x', DROP TABLE IF EXISTS `x`.
//   $wpdb->queries     every SQL string sent to query / get_results / get_var, in order
//   $wpdb->unprepared  those of them that did not come out of $wpdb->prepare() (the audit code must leave this empty)
//   $wpdb->tables      table name => rows;  $wpdb->fail_insert / fail_update  true = insert() / update() throws
//   insert() returns 1 and sets insert_id; update() returns the number of rows whose values changed, or false (no table)
//   T_dbdelta          list of the SQL strings given to dbDelta (which creates an empty table named in its CREATE TABLE)
//   T_cron             hook => next timestamp   (wp_schedule_event / wp_next_scheduled / wp_clear_scheduled_hook)
//   T_user_id          what get_current_user_id returns
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
class T_Wpdb {
	public $prefix = 'wp_'; public $tables = array(); public $queries = array(); public $unprepared = array(); public $fail_insert = false; public $fail_update = false; public $insert_id = 0; public $suppress_now = false; public $suppress_log = array();
	private $prepared = array(); private $next_id = 1;
	function get_blog_prefix( $id = null ) { return 'wp_' . ( ( null === $id || 1 === (int) $id ) ? '' : (int) $id . '_' ); }
	function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
	function suppress_errors( $on = true ) { $prev = $this->suppress_now; $this->suppress_now = $on; $this->suppress_log[] = $on; return $prev; }
	function esc_like( $t ) { return addcslashes( $t, '_%' . chr( 92 ) ); }
	function prepare( $q, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		$out = preg_replace_callback( '/%([sd%])/', function ( $m ) use ( &$i, $args ) {
			if ( '%' === $m[1] ) { return '%'; }
			$v = $args[ $i++ ] ?? '';
			return 'd' === $m[1] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
		}, $q );
		$this->prepared[ $out ] = true;
		return $out;
	}
	private function note( $sql ) { $this->queries[] = $sql; if ( ! isset( $this->prepared[ $sql ] ) ) { $this->unprepared[] = $sql; } }
	function insert( $table, $data, $formats = null ) {
		if ( $this->fail_insert ) { throw new RuntimeException( 'insert exploded' ); }
		if ( ! isset( $this->tables[ $table ] ) ) { return false; }
		$data['id'] = $this->next_id++; $this->insert_id = $data['id'];
		$this->tables[ $table ][] = $data;
		return 1;
	}
	// Like wpdb::update: false on an error (no such table, empty data or where), else the number of rows MySQL reports as
	// affected, which counts only rows whose values really changed (wpdb does not set CLIENT_FOUND_ROWS).
	function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( $this->fail_update ) { throw new RuntimeException( 'update exploded' ); }
		if ( ! isset( $this->tables[ $table ] ) || ! is_array( $data ) || ! is_array( $where ) || array() === $data || array() === $where ) { return false; }
		$changed = 0;
		foreach ( $this->tables[ $table ] as &$row ) {
			foreach ( $where as $k => $v ) { if ( ! array_key_exists( $k, $row ) || (string) $row[ $k ] !== (string) $v ) { continue 2; } }
			$before = $row;
			foreach ( $data as $k => $v ) { $row[ $k ] = $v; }
			if ( $before != $row ) { $changed++; }
		}
		unset( $row );
		return $changed;
	}
	function get_var( $sql ) {
		$this->note( $sql );
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/", $sql, $m ) ) { $t = stripslashes( stripslashes( $m[1] ) ); return isset( $this->tables[ $t ] ) ? $t : null; }
		return null;
	}
	function query( $sql ) {
		$this->note( $sql );
		if ( preg_match( '/^DROP TABLE IF EXISTS `(.+)`$/', $sql, $m ) ) { unset( $this->tables[ $m[1] ] ); return true; }
		if ( preg_match( "/^DELETE FROM (\S+) WHERE created_at < '((?:[^'\\\\]|\\\\.)*)'$/", $sql, $m ) ) {
			if ( ! isset( $this->tables[ $m[1] ] ) ) { return false; }
			$cut = stripslashes( $m[2] ); $keep = array();
			foreach ( $this->tables[ $m[1] ] as $r ) { if ( strcmp( $r['created_at'], $cut ) >= 0 ) { $keep[] = $r; } }
			$n = count( $this->tables[ $m[1] ] ) - count( $keep ); $this->tables[ $m[1] ] = $keep;
			return $n;
		}
		return false;
	}
	function get_results( $sql, $output = 'OBJECT' ) {
		$this->note( $sql );
		if ( ! preg_match( '/^SELECT .+ FROM (\S+)(?: WHERE (.+?))? ORDER BY id DESC LIMIT (\d+) OFFSET (\d+)$/', $sql, $m ) ) { return null; }
		if ( ! isset( $this->tables[ $m[1] ] ) ) { return null; }
		$rows = array_reverse( $this->tables[ $m[1] ] );
		$w = $m[2] ?? '';
		$q = "'((?:[^'\\\\]|\\\\.)*)'";
		if ( preg_match( "/ability = $q/", $w, $x ) ) { $v = stripslashes( $x[1] ); $rows = array_filter( $rows, function ( $r ) use ( $v ) { return $r['ability'] === $v; } ); }
		if ( preg_match( "/status = $q/", $w, $x ) ) { $v = stripslashes( $x[1] ); $rows = array_filter( $rows, function ( $r ) use ( $v ) { return $r['status'] === $v; } ); }
		if ( preg_match( '/user_id = (\d+)/', $w, $x ) ) { $v = (int) $x[1]; $rows = array_filter( $rows, function ( $r ) use ( $v ) { return (int) $r['user_id'] === $v; } ); }
		if ( preg_match( "/created_at >= $q/", $w, $x ) ) { $v = stripslashes( $x[1] ); $rows = array_filter( $rows, function ( $r ) use ( $v ) { return strcmp( $r['created_at'], $v ) >= 0; } ); }
		return array_values( array_slice( array_values( $rows ), (int) $m[4], (int) $m[3] ) );
	}
}
function t_wpdb_reset() { $GLOBALS['wpdb'] = new T_Wpdb(); $GLOBALS['T_dbdelta'] = array(); $GLOBALS['T_cron'] = array(); $GLOBALS['T_user_id'] = 0; $GLOBALS['T_dbdelta_fail'] = false; $GLOBALS['T_transients'] = array(); }
t_wpdb_reset();
function dbDelta( $sql ) { $GLOBALS['T_dbdelta'][] = $sql; if ( ! empty( $GLOBALS['T_dbdelta_throw'] ) ) { throw new RuntimeException( 'dbDelta exploded' ); } if ( preg_match( '/CREATE TABLE (\S+)/', $sql, $m ) && empty( $GLOBALS['T_dbdelta_fail'] ) && ! isset( $GLOBALS['wpdb']->tables[ $m[1] ] ) ) { $GLOBALS['wpdb']->tables[ $m[1] ] = array(); } return array(); }
function wp_next_scheduled( $hook ) { return $GLOBALS['T_cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $recurrence, $hook ) { $GLOBALS['T_cron'][ $hook ] = $ts; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['T_cron'][ $hook ] ); return 1; }
function register_deactivation_hook( $file, $cb ) { $GLOBALS['T_deactivation'][] = array( $file, $cb ); }
function get_current_user_id() { return $GLOBALS['T_user_id']; }
function wp_check_invalid_utf8( $s, $strip = false ) { return preg_match( '//u', (string) $s ) ? $s : ( $strip ? (string) iconv( 'UTF-8', 'UTF-8//IGNORE', (string) $s ) : '' ); }
// T_transients: name => array( value, expires at, ttl ); a test expires one by lowering its expires-at.
function set_transient( $n, $v, $ttl = 0 ) { $GLOBALS['T_transients'][ $n ] = array( $v, time() + $ttl, $ttl ); return true; }
function get_transient( $n ) { $t = $GLOBALS['T_transients'][ $n ] ?? null; return ( null === $t || $t[1] < time() ) ? false : $t[0]; }
function delete_transient( $n ) { unset( $GLOBALS['T_transients'][ $n ] ); return true; }
function absint( $n ) { return abs( (int) $n ); }
function switch_to_blog( $id ) { $GLOBALS['T_blog_stack'][] = $GLOBALS['T_blog_id']; $GLOBALS['T_blog_id'] = (int) $id; return true; }
function restore_current_blog() { $GLOBALS['T_blog_id'] = array_pop( $GLOBALS['T_blog_stack'] ); return true; }

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

// ---- content stand-ins for the read abilities (#32, Task 4). t_reset() clears them.
//   T_posts         id => post object (posts, pages, attachments, revisions, blocks ...); add with t_add_post( array )
//   T_meta          id => key => list of values; add with t_add_meta( id, key, value )
//   T_post_types    name => type object;  t_add_type( name, public, show_in_rest, hierarchical )
//   T_taxonomies    name => taxonomy object;  t_add_taxonomy( name, public, show_in_rest )
//   T_terms         id => term object;  t_add_term( taxonomy, name, parent );  T_object_terms  post id => taxonomy => term ids
//   T_comments      id => comment object;  t_add_comment( array )
//   T_menus         id => menu object;  T_menu_items  menu id => list of items;  T_menu_locations  slug => menu id;  T_registered_menus  slug => label
//   T_queries       every argument array WP_Query was built with
// The signed-in user is T_user_id (0 = nobody) and the capabilities are T_caps, as before. current_user_can understands
// read_post and edit_post for a post id the way core's map_meta_cap does, in a simplified form: the author needs
// edit_posts, other people's posts need edit_others_posts, a private post needs read_private_posts from a stranger,
// a published post needs 'read', an inherited one (attachment, revision) follows its parent.
function t_content_reset() {
	$GLOBALS['T_posts'] = array(); $GLOBALS['T_meta'] = array(); $GLOBALS['T_terms'] = array(); $GLOBALS['T_object_terms'] = array(); $GLOBALS['T_comments'] = array(); $GLOBALS['T_next_id'] = 100; $GLOBALS['T_queries'] = array();
	$GLOBALS['T_menus'] = array(); $GLOBALS['T_menu_items'] = array(); $GLOBALS['T_menu_locations'] = array(); $GLOBALS['T_registered_menus'] = array();
	$GLOBALS['T_post_types'] = array(); $GLOBALS['T_taxonomies'] = array();
	foreach ( array( 'post' => array( 1, 1, 0 ), 'page' => array( 1, 1, 1 ), 'attachment' => array( 1, 0, 0 ), 'revision' => array( 0, 0, 0 ), 'nav_menu_item' => array( 0, 0, 0 ), 'wp_block' => array( 0, 1, 0 ), 'wp_template' => array( 0, 1, 0 ), 'et_pb_layout' => array( 1, 1, 0 ) ) as $n => $f ) { t_add_type( $n, (bool) $f[0], (bool) $f[1], (bool) $f[2] ); }
	foreach ( array( 'category' => array( 1, 1 ), 'post_tag' => array( 1, 1 ), 'nav_menu' => array( 0, 1 ), 'wp_theme' => array( 0, 0 ) ) as $n => $f ) { t_add_taxonomy( $n, (bool) $f[0], (bool) $f[1] ); }
}
function t_add_type( $name, $public = true, $rest = true, $hier = false ) { $GLOBALS['T_post_types'][ $name ] = (object) array( 'name' => $name, 'label' => ucfirst( $name ), 'public' => $public, 'show_in_rest' => $rest, 'hierarchical' => $hier ); }
function t_add_taxonomy( $name, $public = true, $rest = true ) { $GLOBALS['T_taxonomies'][ $name ] = (object) array( 'name' => $name, 'label' => ucfirst( $name ), 'public' => $public, 'show_in_rest' => $rest, 'hierarchical' => 'category' === $name ); }
function t_add_post( array $f = array() ) {
	$id = isset( $f['ID'] ) ? (int) $f['ID'] : $GLOBALS['T_next_id']++;
	$GLOBALS['T_posts'][ $id ] = (object) array_merge( array(
		'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => 1, 'post_title' => 'Title ' . $id, 'post_name' => 'title-' . $id,
		'post_content' => 'Content ' . $id, 'post_excerpt' => '', 'post_date_gmt' => '2026-10-01 10:00:00', 'post_modified_gmt' => '2026-10-02 10:00:00',
		'post_parent' => 0, 'post_password' => '', 'post_mime_type' => '', 'menu_order' => 0,
	), $f, array( 'ID' => $id ) );
	return $id;
}
function t_add_meta( $id, $k, $v ) { $GLOBALS['T_meta'][ (int) $id ][ $k ][] = $v; }
function t_add_term( $tax, $name, $parent = 0 ) { $id = $GLOBALS['T_next_id']++; $GLOBALS['T_terms'][ $id ] = (object) array( 'term_id' => $id, 'name' => $name, 'slug' => strtolower( $name ), 'description' => 'About ' . $name, 'parent' => $parent, 'count' => 0, 'taxonomy' => $tax ); return $id; }
function t_add_comment( array $f = array() ) {
	$id = $GLOBALS['T_next_id']++;
	$GLOBALS['T_comments'][ $id ] = (object) array_merge( array( 'comment_ID' => $id, 'comment_post_ID' => 0, 'comment_author' => 'Visitor', 'comment_author_email' => 'visitor@example.org', 'comment_author_url' => '', 'comment_author_IP' => '203.0.113.9',
		'comment_date_gmt' => '2026-10-03 10:00:00', 'comment_content' => 'Nice.', 'comment_approved' => '1', 'comment_parent' => 0, 'user_id' => 0, 'comment_type' => 'comment' ), $f, array( 'comment_ID' => $id ) );
	return $id;
}
t_content_reset();

function t_has_cap( $c ) { return in_array( $c, $GLOBALS['T_caps'], true ); }
function t_post_cap( $cap, $id ) {
	$p = $GLOBALS['T_posts'][ $id ] ?? null;
	if ( null === $p ) { return false; }
	$own = (int) $p->post_author === (int) $GLOBALS['T_user_id'];
	if ( 'edit_post' === $cap ) {
		if ( 'revision' === $p->post_type ) { return t_post_cap( 'edit_post', (int) $p->post_parent ); }
		if ( ! $own ) { return t_has_cap( 'edit_others_posts' ); }
		return t_has_cap( 'edit_posts' ) && ( 'publish' !== $p->post_status || t_has_cap( 'edit_published_posts' ) );
	}
	if ( 'publish' === $p->post_status ) { return t_has_cap( 'read' ); }
	if ( 'private' === $p->post_status ) { return $own ? t_has_cap( 'read' ) : t_has_cap( 'read_private_posts' ); }
	if ( 'inherit' === $p->post_status ) { return $p->post_parent && isset( $GLOBALS['T_posts'][ (int) $p->post_parent ] ) ? t_post_cap( 'read_post', (int) $p->post_parent ) : t_has_cap( 'read' ); }
	return t_post_cap( 'edit_post', $id );
}
function current_user_can( $cap, $id = null ) {
	if ( null !== $id && ( 'read_post' === $cap || 'edit_post' === $cap ) ) { return t_post_cap( $cap, (int) $id ); }
	return t_has_cap( $cap );
}
function get_post( $id = null ) { return is_object( $id ) ? $id : ( $GLOBALS['T_posts'][ (int) $id ] ?? null ); }
function get_post_meta( $id, $k = '', $single = false ) {
	$all = $GLOBALS['T_meta'][ (int) $id ] ?? array();
	if ( '' === $k ) { return $all; }
	$vals = $all[ $k ] ?? array();
	return $single ? ( $vals[0] ?? '' ) : $vals;
}
function get_permalink( $id = 0 ) { return 'https://x.example/?p=' . (int) ( is_object( $id ) ? $id->ID : $id ); }
function wp_get_attachment_url( $id ) { $f = get_post_meta( $id, '_wp_attached_file', true ); return '' === $f ? false : 'https://x.example/wp-content/uploads/' . $f; }
function wp_get_attachment_metadata( $id ) { $m = get_post_meta( $id, '_wp_attachment_metadata', true ); return '' === $m ? false : $m; }
function get_post_type_object( $t ) { return $GLOBALS['T_post_types'][ $t ] ?? null; }
function get_post_types( $args = array(), $output = 'names' ) { return 'objects' === $output ? $GLOBALS['T_post_types'] : array_combine( array_keys( $GLOBALS['T_post_types'] ), array_keys( $GLOBALS['T_post_types'] ) ); }
function get_taxonomy( $t ) { return $GLOBALS['T_taxonomies'][ $t ] ?? false; }
// 'the_content' wraps its input, so a hash taken from filtered content cannot equal one taken from the raw content.
function apply_filters( $tag, $value, ...$args ) { return 'the_content' === $tag ? '<div class="filtered">' . $value . '</div>' : $value; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function get_the_terms( $id, $tax ) {
	$ids = $GLOBALS['T_object_terms'][ (int) $id ][ $tax ] ?? array();
	return array() === $ids ? false : array_values( array_map( function ( $t ) { return $GLOBALS['T_terms'][ $t ]; }, $ids ) );
}
function get_term( $id, $tax = '' ) { $t = $GLOBALS['T_terms'][ (int) $id ] ?? null; return ( null !== $t && ( '' === $tax || $t->taxonomy === $tax ) ) ? $t : null; }
function get_terms( $args = array() ) {
	$rows = array_values( array_filter( $GLOBALS['T_terms'], function ( $t ) use ( $args ) {
		return $t->taxonomy === ( $args['taxonomy'] ?? '' ) && ( empty( $args['search'] ) || false !== stripos( $t->name, $args['search'] ) );
	} ) );
	if ( 'count' === ( $args['fields'] ?? '' ) ) { return (string) count( $rows ); }
	return array_slice( $rows, (int) ( $args['offset'] ?? 0 ), isset( $args['number'] ) ? (int) $args['number'] : null );
}
function get_comment( $id ) { return $GLOBALS['T_comments'][ (int) $id ] ?? null; }
function get_comments( $args = array() ) {
	$map = array( 'approve' => '1', 'hold' => '0', 'spam' => 'spam', 'trash' => 'trash' );
	$st = $args['status'] ?? 'approve';
	$rows = array_values( array_filter( $GLOBALS['T_comments'], function ( $c ) use ( $args, $st, $map ) {
		if ( ! empty( $args['post_id'] ) && (int) $c->comment_post_ID !== (int) $args['post_id'] ) { return false; }
		if ( ! empty( $args['type__in'] ) && ! in_array( '' === $c->comment_type ? 'comment' : $c->comment_type, $args['type__in'], true ) ) { return false; }
		return 'all' === $st ? in_array( (string) $c->comment_approved, array( '0', '1' ), true ) : (string) $c->comment_approved === ( $map[ $st ] ?? $st );
	} ) );
	if ( ! empty( $args['count'] ) ) { return count( $rows ); }
	return array_slice( $rows, (int) ( $args['offset'] ?? 0 ), isset( $args['number'] ) ? (int) $args['number'] : null );
}
function wp_get_nav_menus() { return array_values( $GLOBALS['T_menus'] ); }
function wp_get_nav_menu_object( $m ) { foreach ( $GLOBALS['T_menus'] as $o ) { if ( (string) $o->term_id === (string) $m || $o->slug === $m || $o->name === $m ) { return $o; } } return false; }
function wp_get_nav_menu_items( $m ) { $o = wp_get_nav_menu_object( $m ); return $o ? ( $GLOBALS['T_menu_items'][ $o->term_id ] ?? array() ) : false; }
function get_nav_menu_locations() { return $GLOBALS['T_menu_locations']; }
function get_registered_nav_menus() { return $GLOBALS['T_registered_menus']; }
function wp_get_post_revisions( $id, $args = array() ) {
	$rows = array_filter( $GLOBALS['T_posts'], function ( $p ) use ( $id ) { return 'revision' === $p->post_type && (int) $p->post_parent === (int) $id; } );
	krsort( $rows );
	return $rows;
}
// WordPress's query, reduced to what the read abilities send: type, status, search, author, parent, category, tag, mime, paging,
// fields=ids, has_password, perm. It copies core's blind spots on purpose (class-wp-query.php): perm=readable narrows ONLY the
// private status (to the caller's own posts unless they hold read_private_posts); post_status 'any' skips perm altogether;
// draft, pending and future rows are never narrowed by perm; has_password=false drops protected rows; the search matches
// protected and private text for any logged-in caller. Everything else comes back whoever is asking.
class WP_Query {
	public $posts = array(); public $found_posts = 0; public $max_num_pages = 0; public $query_args = array();
	function __construct( $a = array() ) {
		$GLOBALS['T_queries'][] = $a; $this->query_args = $a;
		$types = (array) ( $a['post_type'] ?? 'post' ); $st = $a['post_status'] ?? 'publish'; $rows = array();
		foreach ( $GLOBALS['T_posts'] as $p ) {
			if ( ! in_array( $p->post_type, $types, true ) || 'auto-draft' === $p->post_status ) { continue; }
			if ( 'any' === $st ? 'trash' === $p->post_status : ! in_array( $p->post_status, (array) $st, true ) ) { continue; }
			if ( 'any' !== $st && 'readable' === ( $a['perm'] ?? '' ) && 'private' === $p->post_status && ! t_has_cap( 'read_private_posts' ) && (int) $p->post_author !== (int) $GLOBALS['T_user_id'] ) { continue; }
			if ( array_key_exists( 'has_password', $a ) && false === $a['has_password'] && '' !== (string) $p->post_password ) { continue; }
			if ( isset( $a['s'] ) && '' !== $a['s'] && false === stripos( $p->post_title . ' ' . $p->post_content, $a['s'] ) ) { continue; }
			if ( ! empty( $a['author'] ) && (int) $p->post_author !== (int) $a['author'] ) { continue; }
			if ( isset( $a['post_parent'] ) && (int) $p->post_parent !== (int) $a['post_parent'] ) { continue; }
			if ( ! empty( $a['cat'] ) && ! in_array( (int) $a['cat'], $GLOBALS['T_object_terms'][ $p->ID ]['category'] ?? array(), true ) ) { continue; }
			if ( ! empty( $a['tag_id'] ) && ! in_array( (int) $a['tag_id'], $GLOBALS['T_object_terms'][ $p->ID ]['post_tag'] ?? array(), true ) ) { continue; }
			if ( ! empty( $a['post_mime_type'] ) && 0 !== strpos( $p->post_mime_type, $a['post_mime_type'] ) ) { continue; }
			$rows[] = $p;
		}
		usort( $rows, function ( $x, $y ) { return $y->ID <=> $x->ID; } );
		$this->found_posts = count( $rows );
		$per = max( 1, (int) ( $a['posts_per_page'] ?? 10 ) ); $this->max_num_pages = (int) ceil( $this->found_posts / $per );
		$this->posts = array_slice( $rows, ( max( 1, (int) ( $a['paged'] ?? 1 ) ) - 1 ) * $per, $per );
		if ( 'ids' === ( $a['fields'] ?? '' ) ) { $this->posts = array_map( function ( $p ) { return $p->ID; }, $this->posts ); }
	}
}
