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
	if ( function_exists( 't_media_reset' ) ) { t_media_reset(); }
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
//   T_user_blogs     user id => list of blog ids the user is a member of (is_user_member_of_blog); no entry = member of none
function t_blogs_reset() { $GLOBALS['T_blogs'] = array(); $GLOBALS['T_blog_id'] = 1; $GLOBALS['T_main_site'] = 1; $GLOBALS['T_get_sites'] = array(); $GLOBALS['T_user_blogs'] = array(); }
t_blogs_reset();
// As core: 0 means the current user / the current blog; a user with no role on that blog is not a member.
function is_user_member_of_blog( $user_id = 0, $blog_id = 0 ) {
	$u = (int) $user_id ?: (int) ( $GLOBALS['T_user_id'] ?? 0 ); $b = (int) $blog_id ?: (int) $GLOBALS['T_blog_id'];
	return $u > 0 && in_array( $b, $GLOBALS['T_user_blogs'][ $u ] ?? array(), true );
}
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
// As core (meta.php): the key and the value are UNSLASHED, every existing value of the key is replaced. Kept in T_meta.
// With no previous value core updates EVERY row of the key (a key with 2 rows keeps 2 rows, both with the new value).
function update_post_meta( $id, $k, $v, $prev = '' ) { $k = wp_unslash( $k ); $n = max( 1, count( $GLOBALS['T_meta'][ (int) $id ][ $k ] ?? array() ) ); $GLOBALS['T_meta'][ (int) $id ][ $k ] = array_fill( 0, $n, wp_unslash( $v ) ); return true; }
function add_post_meta( $id, $k, $v, $unique = false ) { $k = wp_unslash( $k ); if ( $unique && ! empty( $GLOBALS['T_meta'][ (int) $id ][ $k ] ) ) { return false; } $GLOBALS['T_meta'][ (int) $id ][ $k ][] = wp_unslash( $v ); return true; }
// As core's delete_metadata: the key and the value are UNSLASHED; with a value only the rows equal to it go, without one every
// row of the key. False when nothing matched. T_meta_delete_blocked: a plugin stopped it (the delete_post_metadata filter).
function delete_post_meta( $id, $k, $v = '' ) {
	$k = wp_unslash( $k );
	if ( ! empty( $GLOBALS['T_meta_delete_blocked'] ) || ! isset( $GLOBALS['T_meta'][ (int) $id ][ $k ] ) ) { return false; }
	if ( '' === $v || null === $v || false === $v ) { unset( $GLOBALS['T_meta'][ (int) $id ][ $k ] ); return true; }
	$v = wp_unslash( $v ); $rows = $GLOBALS['T_meta'][ (int) $id ][ $k ]; $keep = array();
	foreach ( $rows as $row ) { if ( maybe_serialize( $row ) !== maybe_serialize( $v ) ) { $keep[] = $row; } }
	if ( count( $keep ) === count( $rows ) ) { return false; }
	if ( array() === $keep ) { unset( $GLOBALS['T_meta'][ (int) $id ][ $k ] ); } else { $GLOBALS['T_meta'][ (int) $id ][ $k ] = $keep; }
	return true;
}
// get_post_meta: see the content stand-ins at the end of this file.
// As core: control characters are dropped from the key first; protected = starts with _ (core then runs the is_protected_meta filter).
function is_protected_meta( $k, $t = null ) { $s = preg_replace( "/[^\x20-\x7E\p{L}]/u", '', (string) $k ); return strlen( (string) $s ) > 0 && '_' === $s[0]; }
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
// As core: strings only (map_deep with stripslashes_from_strings_only); numbers and booleans stay what they are.
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function wp_slash( $v ) { if ( is_array( $v ) ) { return array_map( 'wp_slash', $v ); } return is_string( $v ) ? addslashes( $v ) : $v; }

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
	public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta';
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
	// get_col understands the two lookups of the media delete preview, answered from T_meta and T_posts:
	//   SELECT post_id FROM wp_postmeta WHERE meta_key = 'k' AND meta_value = 'v' LIMIT n
	//   SELECT ID FROM wp_posts WHERE post_type NOT IN ('a','b') AND post_status <> 'auto-draft' AND (post_content LIKE 'p' OR ...) ORDER BY ID ASC LIMIT n
	// LIKE as MySQL: % any run, _ one character, a backslash escapes the next character.
	function get_col( $sql ) {
		$this->note( $sql );
		$q = "'((?:[^'\\\\]|\\\\.)*)'";
		if ( preg_match( "/^SELECT post_id FROM wp_postmeta WHERE meta_key = $q AND meta_value = $q LIMIT (\\d+)$/", $sql, $m ) ) {
			$k = stripslashes( $m[1] ); $v = stripslashes( $m[2] ); $out = array();
			foreach ( $GLOBALS['T_meta'] as $id => $keys ) { foreach ( $keys[ $k ] ?? array() as $x ) { if ( (string) $x === $v ) { $out[] = (string) $id; break; } } }
			sort( $out ); return array_slice( $out, 0, (int) $m[3] );
		}
		if ( preg_match( "/^SELECT ID FROM wp_posts WHERE post_type NOT IN \\(([^)]*)\\) AND post_status <> 'auto-draft' AND \\((.+)\\) ORDER BY ID ASC LIMIT (\\d+)$/", $sql, $m ) ) {
			$skip = array_map( function ( $s ) { return trim( $s, " '" ); }, explode( ',', $m[1] ) );
			preg_match_all( "/post_content LIKE $q/", $m[2], $likes );
			$res = array_map( function ( $l ) {
				$l = stripslashes( $l ); $re = ''; $n = strlen( $l );
				for ( $i = 0; $i < $n; $i++ ) { $c = $l[ $i ]; if ( '\\' === $c && $i + 1 < $n ) { $re .= preg_quote( $l[ ++$i ], '/' ); } elseif ( '%' === $c ) { $re .= '.*'; } elseif ( '_' === $c ) { $re .= '.'; } else { $re .= preg_quote( $c, '/' ); } }
				return '/^' . $re . '$/s';
			}, $likes[1] );
			$out = array();
			foreach ( $GLOBALS['T_posts'] as $id => $p ) {
				if ( in_array( $p->post_type, $skip, true ) || 'auto-draft' === $p->post_status ) { continue; }
				foreach ( $res as $re ) { if ( preg_match( $re, (string) $p->post_content ) ) { $out[] = (string) $id; break; } }
			}
			sort( $out ); return array_slice( $out, 0, (int) $m[3] );
		}
		return array();
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
	function get_row( $sql, $output = 'OBJECT' ) {
		$this->note( $sql );
		if ( ! preg_match( '/^SELECT \* FROM (\S+) WHERE id = (\d+)$/', $sql, $m ) || ! isset( $this->tables[ $m[1] ] ) ) { return null; }
		foreach ( $this->tables[ $m[1] ] as $r ) { if ( (int) $r['id'] === (int) $m[2] ) { return $r; } }
		return null;
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
		if ( preg_match( "/object_type = $q/", $w, $x ) ) { $v = stripslashes( $x[1] ); $rows = array_filter( $rows, function ( $r ) use ( $v ) { return $r['object_type'] === $v; } ); }
		if ( preg_match( '/object_id = (\d+)/', $w, $x ) ) { $v = (int) $x[1]; $rows = array_filter( $rows, function ( $r ) use ( $v ) { return (int) $r['object_id'] === $v; } ); }
		if ( preg_match( '/user_id = (\d+)/', $w, $x ) ) { $v = (int) $x[1]; $rows = array_filter( $rows, function ( $r ) use ( $v ) { return (int) $r['user_id'] === $v; } ); }
		if ( preg_match( "/created_at >= $q/", $w, $x ) ) { $v = stripslashes( $x[1] ); $rows = array_filter( $rows, function ( $r ) use ( $v ) { return strcmp( $r['created_at'], $v ) >= 0; } ); }
		$out = array_values( array_slice( array_values( $rows ), (int) $m[4], (int) $m[3] ) );
		// the column list, as SQL returns only what was asked for
		if ( preg_match( '/^SELECT (.+?) FROM /', $sql, $cols ) && '*' !== trim( $cols[1] ) ) { $want = array_flip( array_map( 'trim', explode( ',', $cols[1] ) ) ); $out = array_map( function ( $r ) use ( $want ) { return array_intersect_key( $r, $want ); }, $out ); }
		return $out;
	}
}
// T_cleared_on: the blog id current at each wp_clear_scheduled_hook call; T_transient_deletes: array( name, blog id ) per delete_transient
// (cron events and transients are per site in WordPress; here they are one store, so these record which site each call was made on).
function t_wpdb_reset() { $GLOBALS['wpdb'] = new T_Wpdb(); $GLOBALS['T_dbdelta'] = array(); $GLOBALS['T_cron'] = array(); $GLOBALS['T_user_id'] = 0; $GLOBALS['T_dbdelta_fail'] = false; $GLOBALS['T_transients'] = array(); $GLOBALS['T_cleared_on'] = array(); $GLOBALS['T_transient_deletes'] = array(); }
t_wpdb_reset();
function dbDelta( $sql ) { $GLOBALS['T_dbdelta'][] = $sql; if ( ! empty( $GLOBALS['T_dbdelta_throw'] ) ) { throw new RuntimeException( 'dbDelta exploded' ); } if ( empty( $GLOBALS['T_dbdelta_fail'] ) && preg_match_all( '/CREATE TABLE (\S+)/', $sql, $mm ) ) { foreach ( $mm[1] as $t ) { if ( ! isset( $GLOBALS['wpdb']->tables[ $t ] ) ) { $GLOBALS['wpdb']->tables[ $t ] = array(); } } } return array(); }
function wp_next_scheduled( $hook ) { return $GLOBALS['T_cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $recurrence, $hook ) { $GLOBALS['T_cron'][ $hook ] = $ts; return true; }
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['T_cleared_on'][] = $GLOBALS['T_blog_id'] ?? 1; unset( $GLOBALS['T_cron'][ $hook ] ); return 1; }
function register_deactivation_hook( $file, $cb ) { $GLOBALS['T_deactivation'][] = array( $file, $cb ); }
function get_current_user_id() { return $GLOBALS['T_user_id']; }
function wp_hash( $data ) { return hash_hmac( "sha256", $data, "t-salt" ); }
function get_attached_file( $id ) { $f = get_post_meta( $id, "_wp_attached_file", true ); return "" === $f ? false : "/var/www/uploads/" . $f; }
// As core's formatting.php has it (up to 6.8 at least; 6.9 may differ, not checked): strip=true calls iconv('utf-8', 'utf-8')
// WITHOUT //IGNORE, which fails on a bad byte, so a line with one bad byte comes back false (empty), not cleaned.
function wp_check_invalid_utf8( $s, $strip = false ) { $s = (string) $s; if ( '' === $s || 1 === @preg_match( '/^./us', $s ) ) { return $s; } return $strip && function_exists( 'iconv' ) ? @iconv( 'utf-8', 'utf-8', $s ) : ''; }
// T_transients: name => array( value, expires at, ttl ); a test expires one by lowering its expires-at.
function set_transient( $n, $v, $ttl = 0 ) { if ( ! empty( $GLOBALS['T_transient_fail'] ) ) { return false; } $GLOBALS['T_transients'][ $n ] = array( $v, time() + $ttl, $ttl ); return true; }
// T_on_get: name => callable run once right after that transient is read (a concurrent request acting between a read and a write).
function get_transient( $n ) { $t = $GLOBALS['T_transients'][ $n ] ?? null; $v = ( null === $t || $t[1] < time() ) ? false : $t[0]; if ( isset( $GLOBALS['T_on_get'][ $n ] ) ) { $cb = $GLOBALS['T_on_get'][ $n ]; unset( $GLOBALS['T_on_get'][ $n ] ); $cb(); } return $v; }
function delete_transient( $n ) { $GLOBALS['T_transient_deletes'][] = array( $n, $GLOBALS['T_blog_id'] ?? 1 ); $had = false !== get_transient( $n ); unset( $GLOBALS['T_transients'][ $n ] ); return $had; }
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
		return new T_Ability( $name, $args );
	}
	// core's wp_get_ability: the registered ability, or null.
	function wp_get_ability( $name ) { return isset( $GLOBALS['T_abilities'][ $name ] ) ? new T_Ability( $name, $GLOBALS['T_abilities'][ $name ] ) : null; }
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
	$GLOBALS['T_inserts'] = array(); $GLOBALS['T_updates'] = array(); $GLOBALS['T_deleted'] = array(); $GLOBALS['T_revisions_keep'] = -1; $GLOBALS['T_insert_fail'] = false; $GLOBALS['T_update_fail'] = false; $GLOBALS['T_trash_blocked'] = false;
	$GLOBALS['T_meta_auth_denied'] = array(); $GLOBALS['T_untrash_cascade'] = array(); $GLOBALS['T_insert_error'] = null; $GLOBALS['T_save_content'] = null;
	$GLOBALS['T_meta_delete_blocked'] = false; $GLOBALS['T_term_fail'] = false; $GLOBALS['T_term_delete_fail'] = false; $GLOBALS['T_menu_delete_fail'] = false; $GLOBALS['T_theme_mods'] = array(); $GLOBALS['T_term_meta'] = array();
	$GLOBALS['T_cmeta'] = array(); $GLOBALS['T_comment_calls'] = array(); $GLOBALS['T_comment_blocked'] = false; $GLOBALS['T_comments_deleted'] = array(); $GLOBALS['T_new_comment_error'] = null;
	foreach ( array( 'post' => array( 1, 1, 0 ), 'page' => array( 1, 1, 1 ), 'attachment' => array( 1, 0, 0 ), 'revision' => array( 0, 0, 0 ), 'nav_menu_item' => array( 0, 0, 0 ), 'wp_block' => array( 0, 1, 0 ), 'wp_template' => array( 0, 1, 0 ), 'et_pb_layout' => array( 1, 1, 0 ) ) as $n => $f ) { t_add_type( $n, (bool) $f[0], (bool) $f[1], (bool) $f[2] ); }
	foreach ( array( 'category' => array( 1, 1 ), 'post_tag' => array( 1, 1 ), 'nav_menu' => array( 0, 1 ), 'wp_theme' => array( 0, 0 ) ) as $n => $f ) { t_add_taxonomy( $n, (bool) $f[0], (bool) $f[1] ); }
}
// cap: core's capability_type 'post' mapping (create_posts = edit_posts); every stand-in type uses the *_posts names, so a
// type cap is the same name the 2.1.0 tests grant. $caps overrides single entries (a test can give page the *_pages names).
// supports: post and page as core registers them (revisions, thumbnail); wp_block has revisions; other types title + editor.
function t_add_type( $name, $public = true, $rest = true, $hier = false, $supports = null, array $caps = array() ) {
	$cap = array( 'edit_post' => 'edit_post', 'read_post' => 'read_post', 'delete_post' => 'delete_post', 'edit_posts' => 'edit_posts', 'edit_others_posts' => 'edit_others_posts',
		'delete_posts' => 'delete_posts', 'publish_posts' => 'publish_posts', 'read_private_posts' => 'read_private_posts', 'delete_private_posts' => 'delete_private_posts',
		'delete_published_posts' => 'delete_published_posts', 'delete_others_posts' => 'delete_others_posts', 'edit_private_posts' => 'edit_private_posts', 'edit_published_posts' => 'edit_published_posts', 'create_posts' => 'edit_posts' );
	// core registers wp_block with create_posts = publish_posts (a contributor cannot make a synced pattern).
	if ( 'wp_block' === $name ) { $cap['create_posts'] = 'publish_posts'; }
	if ( null === $supports ) {
		$supports = 'post' === $name ? array( 'title', 'editor', 'excerpt', 'revisions', 'thumbnail' ) : ( 'page' === $name ? array( 'title', 'editor', 'revisions', 'thumbnail', 'page-attributes' ) : ( 'wp_block' === $name ? array( 'title', 'editor', 'revisions' ) : array( 'title', 'editor' ) ) );
	}
	$GLOBALS['T_post_types'][ $name ] = (object) array( 'name' => $name, 'label' => ucfirst( $name ), 'public' => $public, 'show_in_rest' => $rest, 'hierarchical' => $hier, 'supports' => $supports, 'cap' => (object) array_merge( $cap, $caps ) );
}
// object_type: the post types the taxonomy is registered for (category and post_tag: post, as core). cap->assign_terms = edit_posts.
function t_add_taxonomy( $name, $public = true, $rest = true, $types = null ) {
	if ( null === $types ) { $types = in_array( $name, array( 'category', 'post_tag' ), true ) ? array( 'post' ) : array(); }
	$GLOBALS['T_taxonomies'][ $name ] = (object) array( 'name' => $name, 'label' => ucfirst( $name ), 'public' => $public, 'show_in_rest' => $rest, 'hierarchical' => 'category' === $name, 'object_type' => $types, 'cap' => (object) array( 'assign_terms' => 'edit_posts', 'manage_terms' => 'manage_categories' ) );
}
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

// Users by id (T_users: id => object with user_login ...), as get_userdata returns them; false for an unknown id.
if ( ! isset( $GLOBALS['T_users'] ) ) { $GLOBALS['T_users'] = array(); }
function get_userdata( $id ) { return $GLOBALS['T_users'][ (int) $id ] ?? false; }
// Core's _prime_post_caches( ids, update_term_cache, update_meta_cache ) loads posts in one query; T_primes records each call.
$GLOBALS['T_primes'] = array();
function _prime_post_caches( $ids, $terms = true, $meta = true ) { $GLOBALS['T_primes'][] = array_values( $ids ); }

function t_has_cap( $c ) { return in_array( $c, $GLOBALS['T_caps'], true ); }
function t_post_cap( $cap, $id ) {
	$p = $GLOBALS['T_posts'][ $id ] ?? null;
	if ( null === $p ) { return false; }
	$own = (int) $p->post_author === (int) $GLOBALS['T_user_id'];
	if ( 'delete_post' === $cap ) {
		// map_meta_cap, simplified: a published (or future, or trashed-while-published) item needs delete_published_posts;
		// another user's item needs delete_others_posts (and delete_private_posts when private).
		if ( 'revision' === $p->post_type ) { return t_post_cap( 'delete_post', (int) $p->post_parent ); }
		$st = 'trash' === $p->post_status ? (string) get_post_meta( $p->ID, '_wp_trash_meta_status', true ) : (string) $p->post_status;
		$need = array( $own ? 'delete_posts' : 'delete_others_posts' );
		if ( in_array( $st, array( 'publish', 'future' ), true ) ) { $need[] = 'delete_published_posts'; }
		if ( ! $own && 'private' === $st ) { $need[] = 'delete_private_posts'; }
		foreach ( $need as $c ) { if ( ! t_has_cap( $c ) ) { return false; } }
		return true;
	}
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
// edit_post_meta( id, key ): as map_meta_cap, edit_post on the post, a key that is not protected, and the
// auth_post_meta_{key} filter (here: the key is not in T_meta_auth_denied).
function current_user_can( $cap, $id = null, $key = null ) {
	// core maps add_post_meta and delete_post_meta exactly as edit_post_meta.
	if ( 'edit_post_meta' === $cap || 'delete_post_meta' === $cap || 'add_post_meta' === $cap ) { return null !== $id && t_post_cap( 'edit_post', (int) $id ) && ! is_protected_meta( (string) $key, 'post' ) && ! in_array( (string) $key, $GLOBALS['T_meta_auth_denied'] ?? array(), true ); }
	if ( null !== $id && ( 'read_post' === $cap || 'edit_post' === $cap || 'delete_post' === $cap ) ) { return t_post_cap( $cap, (int) $id ); }
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
function apply_filters( $tag, $value, ...$args ) {
	// d5b_ds_class: T_ds_classes (short name => class) stands Divi's design-system classes in, as ds-write-test.php does.
	if ( 'd5b_ds_class' === $tag ) { return $GLOBALS['T_ds_classes'][ $args[0] ] ?? $value; }
	return 'the_content' === $tag ? '<div class="filtered">' . $value . '</div>' : $value;
}
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function get_the_terms( $id, $tax ) {
	$ids = $GLOBALS['T_object_terms'][ (int) $id ][ $tax ] ?? array();
	return array() === $ids ? false : array_values( array_map( function ( $t ) { return $GLOBALS['T_terms'][ $t ]; }, $ids ) );
}
function get_term( $id, $tax = '' ) { $t = $GLOBALS['T_terms'][ (int) $id ] ?? null; return ( null !== $t && ( '' === $tax || $t->taxonomy === $tax ) ) ? $t : null; }
function get_terms( $args = array() ) {
	$rows = array_values( array_filter( $GLOBALS['T_terms'], function ( $t ) use ( $args ) {
		return $t->taxonomy === ( $args['taxonomy'] ?? '' ) && ( empty( $args['search'] ) || false !== stripos( $t->name, $args['search'] ) )
			&& ( ! isset( $args['parent'] ) || '' === $args['parent'] || (int) $t->parent === (int) $args['parent'] );
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
// A menu whose items a test planted in T_menu_items (plain objects) answers from there, as in 2.1.0; otherwise the items are the
// nav_menu_item posts in the menu (T_object_terms[ item ]['nav_menu']), decorated and ordered as core's wp_get_nav_menu_items
// does (see the menu stand-ins at the end of this file).
function wp_get_nav_menu_items( $m, $args = array() ) {
	$o = wp_get_nav_menu_object( $m );
	if ( ! $o ) { return false; }
	if ( isset( $GLOBALS['T_menu_items'][ $o->term_id ] ) ) { return $GLOBALS['T_menu_items'][ $o->term_id ]; }
	return t_nav_items( (int) $o->term_id, $args['post_status'] ?? 'publish' );
}
// As core: the locations are the theme mod nav_menu_locations (kept in T_menu_locations).
function get_nav_menu_locations() { $l = get_theme_mod( 'nav_menu_locations' ); return is_array( $l ) ? $l : array(); }
function get_registered_nav_menus() { return $GLOBALS['T_registered_menus']; }
// As core (revision.php): newest first (order ASC on request); with check_enabled (the default) nothing when revisions are
// off for the post (its type does not support them, or WP_POST_REVISIONS is 0, here T_revisions_keep = 0).
function wp_get_post_revisions( $id, $args = array() ) {
	$parent = $GLOBALS['T_posts'][ (int) ( is_object( $id ) ? $id->ID : $id ) ] ?? null;
	if ( ( $args['check_enabled'] ?? true ) && null !== $parent && ! wp_revisions_enabled( $parent ) ) { return array(); }
	$rows = array_filter( $GLOBALS['T_posts'], function ( $p ) use ( $id ) { return 'revision' === $p->post_type && (int) $p->post_parent === (int) ( is_object( $id ) ? $id->ID : $id ); } );
	if ( 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ) { ksort( $rows ); } else { krsort( $rows ); }
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
		// As core: more than one post type asks for read_private_multiple_post_types, a capability no role has (map_meta_cap
		// does not map it), so a query over several types narrows private rows to the caller's own, even for an administrator.
		$private_cap = count( $types ) > 1 ? 'read_private_multiple_post_types' : 'read_private_posts';
		foreach ( $GLOBALS['T_posts'] as $p ) {
			if ( ! in_array( $p->post_type, $types, true ) || 'auto-draft' === $p->post_status ) { continue; }
			if ( 'any' === $st ? 'trash' === $p->post_status : ! in_array( $p->post_status, (array) $st, true ) ) { continue; }
			if ( 'any' !== $st && 'readable' === ( $a['perm'] ?? '' ) && 'private' === $p->post_status && ! t_has_cap( $private_cap ) && (int) $p->post_author !== (int) $GLOBALS['T_user_id'] ) { continue; }
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

// ---- Abilities API execution (#32, 2.2.0): what core's WP_Ability does with an input before the callbacks run
// (class-wp-ability.php, 6.9). normalize_input: a null input becomes the schema's 'default' when the schema has one.
// validate_input: with no schema only null passes (ability_missing_input_schema otherwise); with one, the value is checked
// as rest_validate_value_from_schema does, REDUCED here to: type object (an array; null is not an object), required
// properties, additionalProperties false. Property types, enums and lengths are NOT checked by this stand-in.
class T_Ability {
	public $name; public $args;
	function __construct( $name, array $args ) { $this->name = $name; $this->args = $args; }
	function get_name() { return $this->name; }
	function get_input_schema() { return $this->args['input_schema'] ?? array(); }
	function normalize_input( $input = null ) {
		if ( null !== $input ) { return $input; }
		$s = $this->get_input_schema();
		return ( ! empty( $s ) && array_key_exists( 'default', $s ) ) ? $s['default'] : null;
	}
	function validate_input( $input = null ) {
		$s = $this->get_input_schema();
		if ( empty( $s ) ) { return null === $input ? true : new WP_Error( 'ability_missing_input_schema', 'Ability "' . $this->name . '" does not define an input schema required to validate the provided input.' ); }
		$why = '';
		if ( 'object' === ( $s['type'] ?? '' ) ) {
			// rest_is_object: '' is an empty object, as are arrays and stdClass.
			if ( ! is_array( $input ) && '' !== $input && ! ( $input instanceof stdClass ) ) { $why = 'input is not of type object.'; }
			else {
				$in = '' === $input ? array() : (array) $input;
				foreach ( (array) ( $s['required'] ?? array() ) as $r ) { if ( ! array_key_exists( $r, $in ) ) { $why = $r . ' is a required property of input.'; break; } }
				if ( '' === $why && false === ( $s['additionalProperties'] ?? true ) ) {
					foreach ( array_keys( $in ) as $k ) { if ( ! isset( $s['properties'][ $k ] ) ) { $why = $k . ' is not a valid property of Object.'; break; } }
				}
			}
		}
		return '' === $why ? true : new WP_Error( 'ability_invalid_input', 'Ability "' . $this->name . '" has invalid input. Reason: ' . $why );
	}
	function check_permissions( $input = null ) { return call_user_func( $this->args['permission_callback'], $input ); }
	function execute( $input = null ) {
		$input = $this->normalize_input( $input );
		$ok = $this->validate_input( $input );
		if ( is_wp_error( $ok ) ) { return $ok; }
		$perm = $this->check_permissions( $input );
		if ( true !== $perm ) { return is_wp_error( $perm ) ? $perm : new WP_Error( 'ability_invalid_permissions', 'Ability "' . $this->name . '" does not have necessary permission.' ); }
		return call_user_func( $this->args['execute_callback'], $input );
	}
}

// ---- content WRITE stand-ins (#32, 2.2.0, Task 3). Behave as core where the plugin depends on it:
//   wp_insert_post / wp_update_post UNSLASH the post fields (the caller must wp_slash), meta_input goes through
//   update_post_meta (which unslashes once more, so it is taken from the still-slashed array, as core does); a post with
//   title, content and excerpt all empty is refused (empty_content); publish with a date a minute or more ahead becomes
//   future and future with a past date becomes publish; a new post gets the default category; wp_update_post replaces the
//   post object (an object read before the update keeps the old values, as a WP_Post from the cache does) and then makes a
//   revision as core's post_updated hook does.
//   wp_save_post_revision: only when the type supports revisions and they are on (T_revisions_keep: -1 all, 0 off, N keep
//   N, pruning the oldest non-autosave ones), and only when title, content or excerpt differ from the latest revision.
//   wp_trash_post: refuses a trashed post; with EMPTY_TRASH_DAYS 0 it deletes for good (core); otherwise stores
//   _wp_trash_meta_status, _wp_trash_meta_time and _wp_desired_post_slug and adds __trashed to the slug.
//   wp_untrash_post: draft (inherit for an attachment) by default, then every wp_untrash_post_status filter added with
//   add_filter (new status, id, previous status); clears the trash meta and puts the slug back.
//   set_post_thumbnail: an image attachment sets _thumbnail_id, any other attachment removes it (core: wp_get_attachment_image).
//   wp_set_post_terms: replaces (or appends) the term ids; an unknown taxonomy is invalid_taxonomy.
// Failures on demand: T_insert_fail, T_update_fail (a WP_Error, as the database would), T_trash_blocked (pre_trash_post).
//   T_inserts / T_updates   every array given to wp_insert_post / wp_update_post (still slashed)
//   T_deleted               ids deleted for good by wp_delete_post
function wp_revisions_to_keep( $post ) {
	$p = is_object( $post ) ? $post : get_post( $post );
	if ( ! $p || ! post_type_supports( $p->post_type, 'revisions' ) ) { return 0; }
	return (int) ( $GLOBALS['T_revisions_keep'] ?? -1 );
}
function wp_revisions_enabled( $post ) { return 0 !== wp_revisions_to_keep( $post ); }
function wp_is_post_autosave( $post ) { $p = is_object( $post ) ? $post : get_post( $post ); return ( $p && 'revision' === $p->post_type && false !== strpos( (string) $p->post_name, '-autosave-v' ) ) ? (int) $p->post_parent : false; }
function post_type_supports( $type, $feature ) { $o = $GLOBALS['T_post_types'][ $type ] ?? null; return null !== $o && in_array( $feature, (array) ( $o->supports ?? array() ), true ); }
function get_object_taxonomies( $type, $output = 'names' ) {
	$type = is_object( $type ) ? $type->post_type : $type; $out = array();
	foreach ( $GLOBALS['T_taxonomies'] as $n => $t ) { if ( in_array( $type, (array) ( $t->object_type ?? array() ), true ) ) { $out[ $n ] = 'objects' === $output ? $t : $n; } }
	return 'objects' === $output ? $out : array_values( $out );
}
function is_object_in_taxonomy( $type, $tax ) { return in_array( $tax, get_object_taxonomies( $type ), true ); }
// Close to core's sanitize_title_with_dashes for plain ASCII input.
function sanitize_title( $s ) { $s = strtolower( strip_tags( (string) $s ) ); $s = preg_replace( '/[^a-z0-9 _\-]/', '', $s ); $s = preg_replace( '/[\s\-]+/', '-', trim( $s ) ); return trim( $s, '-' ); }
// The stand-in site runs on UTC.
function get_gmt_from_date( $s, $f = 'Y-m-d H:i:s' ) { return gmdate( $f, strtotime( $s . ' UTC' ) ); }
function get_date_from_gmt( $s, $f = 'Y-m-d H:i:s' ) { return gmdate( $f, strtotime( $s . ' UTC' ) ); }
function remove_filter( $h, $cb, $p = 10 ) { foreach ( $GLOBALS['T_filters'][ $h ] ?? array() as $i => $c ) { if ( $c === $cb ) { unset( $GLOBALS['T_filters'][ $h ][ $i ] ); $GLOBALS['T_filters'][ $h ] = array_values( $GLOBALS['T_filters'][ $h ] ); return true; } } return false; }
function t_post_fields() { return array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'post_author', 'post_date', 'post_date_gmt', 'menu_order', 'comment_status', 'ping_status', 'post_password', 'post_mime_type' ); }
function t_status_by_date( $status, $gmt ) {
	if ( 'publish' === $status && '' !== (string) $gmt && strtotime( $gmt . ' UTC' ) - time() >= 60 ) { return 'future'; }
	if ( 'future' === $status && '' !== (string) $gmt && strtotime( $gmt . ' UTC' ) - time() < 60 ) { return 'publish'; }
	return $status;
}
// core's wp_insert_post empty check: the title is trimmed (title_save_pre), content and excerpt are not; falsy counts as
// empty ('0' too); only for a type that supports title, editor and excerpt, never an attachment.
function t_empty_post( $type, $title, $content, $excerpt ) {
	return 'attachment' !== $type && ! trim( $title ) && ! $content && ! $excerpt && post_type_supports( $type, 'editor' ) && post_type_supports( $type, 'title' ) && post_type_supports( $type, 'excerpt' );
}
// T_insert_error: a WP_Error wp_insert_post returns as it is (another refusal of core's, or of a plugin's filter).
function wp_insert_post( $arr, $wp_error = false, $fire_after_hooks = true ) {
	$GLOBALS['T_inserts'][] = $arr;
	if ( ! empty( $arr['ID'] ) ) { return wp_update_post( $arr, $wp_error ); }
	if ( ! empty( $GLOBALS['T_insert_fail'] ) ) { return $wp_error ? new WP_Error( 'db_insert_error', 'Could not insert post into the database.' ) : 0; }
	$f = wp_unslash( $arr );
	$type = $f['post_type'] ?? 'post';
	if ( $GLOBALS['T_insert_error'] instanceof WP_Error ) { return $wp_error ? $GLOBALS['T_insert_error'] : 0; }
	if ( t_empty_post( $type, (string) ( $f['post_title'] ?? '' ), (string) ( $f['post_content'] ?? '' ), (string) ( $f['post_excerpt'] ?? '' ) ) ) {
		return $wp_error ? new WP_Error( 'empty_content', 'Content, title, and excerpt are empty.' ) : 0;
	}
	$now = gmdate( 'Y-m-d H:i:s' );
	$gmt = (string) ( $f['post_date_gmt'] ?? '' ); $local = (string) ( $f['post_date'] ?? '' );
	if ( '' === $local ) { $local = '' !== $gmt ? $gmt : $now; } if ( '' === $gmt ) { $gmt = $local; }
	$fields = array( 'post_type' => $type, 'post_status' => t_status_by_date( $f['post_status'] ?? 'draft', $gmt ), 'post_author' => (int) ( $f['post_author'] ?? $GLOBALS['T_user_id'] ),
		'post_title' => (string) ( $f['post_title'] ?? '' ), 'post_content' => (string) ( $f['post_content'] ?? '' ), 'post_excerpt' => (string) ( $f['post_excerpt'] ?? '' ),
		'post_name' => (string) ( $f['post_name'] ?? '' ), 'post_parent' => (int) ( $f['post_parent'] ?? 0 ), 'post_date' => $local, 'post_date_gmt' => $gmt,
		'post_modified' => $now, 'post_modified_gmt' => $now, 'comment_status' => 'open', 'ping_status' => 'open', 'menu_order' => (int) ( $f['menu_order'] ?? 0 ) );
	$id = t_add_post( $fields );
	$dc = (int) get_option( 'default_category', 0 );
	if ( 'post' === $type && isset( $GLOBALS['T_terms'][ $dc ] ) ) { $GLOBALS['T_object_terms'][ $id ]['category'] = array( $dc ); }
	foreach ( (array) ( $arr['meta_input'] ?? array() ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
	return $id;
}
function wp_update_post( $arr = array(), $wp_error = false, $fire_after_hooks = true ) {
	if ( is_object( $arr ) ) { $arr = wp_slash( get_object_vars( $arr ) ); }
	$GLOBALS['T_updates'][] = $arr;
	$id = (int) ( $arr['ID'] ?? 0 ); $old = $GLOBALS['T_posts'][ $id ] ?? null;
	if ( null === $old ) { return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post ID.' ) : 0; }
	if ( ! empty( $GLOBALS['T_update_fail'] ) ) { return $wp_error ? new WP_Error( 'db_update_error', 'Could not update post in the database.' ) : 0; }
	$f = wp_unslash( $arr );
	$new = clone $old;
	foreach ( t_post_fields() as $k ) { if ( array_key_exists( $k, $f ) ) { $new->$k = $f[ $k ]; } }
	// T_save_content: a callable that changes the content as it is saved (a site that re-serialises or damages it).
	if ( array_key_exists( 'post_content', $f ) && is_callable( $GLOBALS['T_save_content'] ?? null ) ) { $new->post_content = call_user_func( $GLOBALS['T_save_content'], (string) $new->post_content ); }
	if ( array_key_exists( 'post_date_gmt', $f ) && ! array_key_exists( 'post_date', $f ) ) { $new->post_date = $f['post_date_gmt']; }
	// core: wp_update_post goes through wp_insert_post, so the merged post meets the same empty check.
	if ( t_empty_post( (string) $new->post_type, (string) $new->post_title, (string) $new->post_content, (string) $new->post_excerpt ) ) { return $wp_error ? new WP_Error( 'empty_content', 'Content, title, and excerpt are empty.' ) : 0; }
	$new->post_status = t_status_by_date( (string) $new->post_status, (string) ( $new->post_date_gmt ?? '' ) );
	$new->post_modified = $new->post_modified_gmt = gmdate( 'Y-m-d H:i:s' );
	$GLOBALS['T_posts'][ $id ] = $new;
	foreach ( (array) ( $arr['meta_input'] ?? array() ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
	if ( 'revision' !== $new->post_type ) { wp_save_post_revision( $id ); }
	return $id;
}
function wp_save_post_revision( $id ) {
	$p = get_post( $id );
	if ( ! $p || 'auto-draft' === $p->post_status || 'revision' === $p->post_type || ! wp_revisions_enabled( $p ) ) { return null; }
	foreach ( wp_get_post_revisions( $p->ID ) as $r ) {
		if ( wp_is_post_autosave( $r ) ) { continue; }
		if ( $r->post_title === $p->post_title && $r->post_content === $p->post_content && $r->post_excerpt === $p->post_excerpt ) { return null; }
		break;
	}
	$rid = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => (int) $p->ID, 'post_name' => $p->ID . '-revision-v1', 'post_author' => (int) $GLOBALS['T_user_id'],
		'post_title' => $p->post_title, 'post_content' => $p->post_content, 'post_excerpt' => $p->post_excerpt, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
	$keep = wp_revisions_to_keep( $p );
	if ( $keep >= 0 ) {
		$all = array_values( array_filter( wp_get_post_revisions( $p->ID, array( 'order' => 'ASC' ) ), function ( $r ) { return ! wp_is_post_autosave( $r ); } ) );
		for ( $i = 0; $i < count( $all ) - $keep; $i++ ) { unset( $GLOBALS['T_posts'][ $all[ $i ]->ID ] ); }
	}
	return $rid;
}
// As core: the post goes with its meta and its term relationships (wp_delete_object_term_relationships, then the counts).
function wp_delete_post( $id, $force = false ) {
	$p = $GLOBALS['T_posts'][ (int) $id ] ?? null; if ( ! $p ) { return null; }
	$had = $GLOBALS['T_object_terms'][ (int) $id ] ?? array();
	unset( $GLOBALS['T_posts'][ (int) $id ], $GLOBALS['T_meta'][ (int) $id ], $GLOBALS['T_object_terms'][ (int) $id ] ); $GLOBALS['T_deleted'][] = (int) $id;
	foreach ( $had as $ids ) { foreach ( $ids as $t ) { t_recount( (int) $t ); } }
	return $p;
}
function wp_trash_post( $id = 0 ) {
	$p = $GLOBALS['T_posts'][ (int) $id ] ?? null;
	if ( ! $p || 'trash' === $p->post_status ) { return false; }
	if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) { return wp_delete_post( $id, true ); }
	if ( ! empty( $GLOBALS['T_trash_blocked'] ) ) { return null; }
	add_post_meta( $p->ID, '_wp_trash_meta_status', $p->post_status );
	add_post_meta( $p->ID, '_wp_trash_meta_time', time() );
	$new = clone $p; $new->post_status = 'trash';
	if ( '' !== (string) $p->post_name ) { add_post_meta( $p->ID, '_wp_desired_post_slug', $p->post_name ); $new->post_name = $p->post_name . '__trashed'; }
	$GLOBALS['T_posts'][ $p->ID ] = $new;
	return $new;
}
function wp_untrash_post( $id = 0 ) {
	$p = $GLOBALS['T_posts'][ (int) $id ] ?? null;
	if ( ! $p || 'trash' !== $p->post_status ) { return false; }
	$prev = (string) get_post_meta( $p->ID, '_wp_trash_meta_status', true );
	$status = 'attachment' === $p->post_type ? 'inherit' : 'draft';
	foreach ( $GLOBALS['T_filters']['wp_untrash_post_status'] ?? array() as $cb ) { $status = call_user_func( $cb, $status, (int) $p->ID, $prev ); }
	delete_post_meta( $p->ID, '_wp_trash_meta_status' ); delete_post_meta( $p->ID, '_wp_trash_meta_time' );
	$new = clone $p; $new->post_status = $status;
	$slug = (string) get_post_meta( $p->ID, '_wp_desired_post_slug', true );
	if ( '' !== $slug ) { $new->post_name = $slug; delete_post_meta( $p->ID, '_wp_desired_post_slug' ); }
	$GLOBALS['T_posts'][ $p->ID ] = $new;
	// T_untrash_cascade: id => ids another plugin untrashes from its untrashed_post hook (children, attachments), while
	// every wp_untrash_post_status filter of the outer call is still added.
	foreach ( $GLOBALS['T_untrash_cascade'][ (int) $p->ID ] ?? array() as $child ) { wp_untrash_post( $child ); }
	return $new;
}
function is_sticky( $post_id = 0 ) { return in_array( (int) $post_id, array_map( 'intval', (array) get_option( 'sticky_posts', array() ) ), true ); }
// As core (functions.php), reduced: arrays and objects are serialized; a string that already looks serialized is serialized
// again (so maybe_unserialize gives it back); everything else is returned as it is.
function is_serialized( $data, $strict = true ) {
	if ( ! is_string( $data ) ) { return false; }
	$d = trim( $data );
	if ( 'N;' === $d ) { return true; }
	return 1 === preg_match( '/^[aOsbidC]:/', $d ) && ( 'b:0;' === $d || false !== @unserialize( $d ) );
}
function maybe_serialize( $data ) {
	if ( is_array( $data ) || is_object( $data ) ) { return serialize( $data ); }
	if ( is_serialized( $data, false ) ) { return serialize( $data ); }
	return $data;
}
function get_post_thumbnail_id( $post = null ) { $p = get_post( $post ); return $p ? (int) get_post_meta( $p->ID, '_thumbnail_id', true ) : false; }
function set_post_thumbnail( $post, $thumbnail_id ) {
	$p = get_post( $post ); $t = get_post( $thumbnail_id );
	if ( ! $p || ! $t || 'attachment' !== $t->post_type ) { return false; }
	if ( 0 === strpos( (string) $t->post_mime_type, 'image/' ) ) { return update_post_meta( $p->ID, '_thumbnail_id', (int) $thumbnail_id ); }
	return delete_post_meta( $p->ID, '_thumbnail_id' );
}
function delete_post_thumbnail( $post ) { $p = get_post( $post ); return $p ? delete_post_meta( $p->ID, '_thumbnail_id' ) : false; }
function wp_set_post_terms( $post_id = 0, $terms = '', $taxonomy = 'post_tag', $append = false ) {
	if ( ! get_taxonomy( $taxonomy ) ) { return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ); }
	$ids = array_values( array_unique( array_map( 'intval', (array) $terms ) ) );
	$had = $GLOBALS['T_object_terms'][ (int) $post_id ][ $taxonomy ] ?? array();
	$GLOBALS['T_object_terms'][ (int) $post_id ][ $taxonomy ] = $append ? array_values( array_unique( array_merge( $had, $ids ) ) ) : $ids;
	return array_map( 'strval', $ids );
}

// ---- comments (Task 4). Behave as core's comment.php: wp_set_comment_status changes only comment_approved ('approve' and '1' = '1',
// 'hold' and '0' = '0', 'spam', 'trash'); wp_trash_comment / wp_spam_comment first keep the old status in the comment meta
// _wp_trash_meta_status (wp_trash_comment also _wp_trash_meta_time) and, with EMPTY_TRASH_DAYS 0, wp_trash_comment DELETES the
// comment for good; wp_untrash_comment / wp_unspam_comment read the meta back (empty = '0', held) and delete it.
// T_cmeta: comment id => key => list of values; T_comment_calls: every function call as array( name, id ...);
// T_comment_blocked: the status functions return false (a plugin stopped it); T_comments_deleted: ids deleted for good;
// T_new_comment_error: a WP_Error wp_new_comment returns.
function get_comment_meta( $id, $k = '', $single = false ) {
	$all = $GLOBALS['T_cmeta'][ (int) $id ] ?? array();
	if ( '' === $k ) { return $all; }
	$vals = $all[ $k ] ?? array();
	return $single ? ( $vals[0] ?? '' ) : $vals;
}
function add_comment_meta( $id, $k, $v, $unique = false ) { if ( $unique && ! empty( $GLOBALS['T_cmeta'][ (int) $id ][ $k ] ) ) { return false; } $GLOBALS['T_cmeta'][ (int) $id ][ $k ][] = $v; return true; }
function delete_comment_meta( $id, $k, $v = '' ) { $had = isset( $GLOBALS['T_cmeta'][ (int) $id ][ $k ] ); unset( $GLOBALS['T_cmeta'][ (int) $id ][ $k ] ); return $had; }
function wp_set_comment_status( $id, $status, $wp_error = false ) {
	$GLOBALS['T_comment_calls'][] = array( 'wp_set_comment_status', (int) ( is_object( $id ) ? $id->comment_ID : $id ), $status );
	$c = get_comment( is_object( $id ) ? $id->comment_ID : $id );
	if ( ! $c ) { return $wp_error ? new WP_Error( 'invalid_comment', 'Invalid comment ID.' ) : false; }
	if ( ! empty( $GLOBALS['T_comment_blocked'] ) ) { return false; }
	$map = array( 'approve' => '1', '1' => '1', 'hold' => '0', '0' => '0', 'spam' => 'spam', 'trash' => 'trash' );
	if ( ! isset( $map[ (string) $status ] ) ) { return $wp_error ? new WP_Error( 'invalid_comment_status', 'Invalid comment status.' ) : false; }
	$GLOBALS['T_comments'][ (int) $c->comment_ID ]->comment_approved = $map[ (string) $status ];
	return true;
}
function wp_trash_comment( $id ) {
	$c = get_comment( is_object( $id ) ? $id->comment_ID : $id );
	if ( ! $c ) { return false; }
	$GLOBALS['T_comment_calls'][] = array( 'wp_trash_comment', (int) $c->comment_ID );
	if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) { unset( $GLOBALS['T_comments'][ (int) $c->comment_ID ] ); $GLOBALS['T_comments_deleted'][] = (int) $c->comment_ID; return true; }
	// As core: the old keys go first, so a comment that was spam keeps 'spam' as its status to go back to.
	delete_comment_meta( $c->comment_ID, '_wp_trash_meta_status' ); delete_comment_meta( $c->comment_ID, '_wp_trash_meta_time' );
	add_comment_meta( $c->comment_ID, '_wp_trash_meta_status', $c->comment_approved );
	add_comment_meta( $c->comment_ID, '_wp_trash_meta_time', time() );
	if ( wp_set_comment_status( $c, 'trash' ) ) { return true; }
	delete_comment_meta( $c->comment_ID, '_wp_trash_meta_status' ); delete_comment_meta( $c->comment_ID, '_wp_trash_meta_time' );
	return false;
}
function wp_untrash_comment( $id ) {
	$c = get_comment( is_object( $id ) ? $id->comment_ID : $id );
	if ( ! $c ) { return false; }
	$GLOBALS['T_comment_calls'][] = array( 'wp_untrash_comment', (int) $c->comment_ID );
	$st = (string) get_comment_meta( $c->comment_ID, '_wp_trash_meta_status', true );
	if ( '' === $st ) { $st = '0'; }
	if ( wp_set_comment_status( $c, $st ) ) { delete_comment_meta( $c->comment_ID, '_wp_trash_meta_time' ); delete_comment_meta( $c->comment_ID, '_wp_trash_meta_status' ); return true; }
	return false;
}
function wp_spam_comment( $id ) {
	$c = get_comment( is_object( $id ) ? $id->comment_ID : $id );
	if ( ! $c ) { return false; }
	$GLOBALS['T_comment_calls'][] = array( 'wp_spam_comment', (int) $c->comment_ID );
	add_comment_meta( $c->comment_ID, '_wp_trash_meta_status', $c->comment_approved );
	if ( wp_set_comment_status( $c, 'spam' ) ) { return true; }
	delete_comment_meta( $c->comment_ID, '_wp_trash_meta_status' );
	return false;
}
function wp_unspam_comment( $id ) {
	$c = get_comment( is_object( $id ) ? $id->comment_ID : $id );
	if ( ! $c ) { return false; }
	$GLOBALS['T_comment_calls'][] = array( 'wp_unspam_comment', (int) $c->comment_ID );
	$st = (string) get_comment_meta( $c->comment_ID, '_wp_trash_meta_status', true );
	if ( '' === $st ) { $st = '0'; }
	if ( wp_set_comment_status( $c, $st ) ) { delete_comment_meta( $c->comment_ID, '_wp_trash_meta_status' ); return true; }
	return false;
}
// comments_open: the post's comment_status is 'open' (a stand-in post without the field is open).
function comments_open( $post = null ) { $p = get_post( $post ); return $p && 'open' === ( $p->comment_status ?? 'open' ); }
// wp_new_comment with wp_allow_comment as core, for a logged-in user: the post's author and anyone with moderate_comments are
// approved; everyone else follows the site's moderation setting (option comment_moderation: held when set); an identical comment
// on the same post and parent is a comment_duplicate WP_Error (409). Slashed data in (core unslashes), the comment id out;
// a parent that is not approved or held is reset to 0, as core.
function wp_new_comment( $data, $wp_error = false ) {
	$data = wp_unslash( $data );
	$GLOBALS['T_comment_calls'][] = array( 'wp_new_comment', $data );
	if ( ! empty( $GLOBALS['T_new_comment_error'] ) ) { return $wp_error ? $GLOBALS['T_new_comment_error'] : false; }
	$parent = (int) ( $data['comment_parent'] ?? 0 );
	$pc = $parent ? get_comment( $parent ) : null;
	if ( ! $pc || ! in_array( (string) $pc->comment_approved, array( '0', '1' ), true ) ) { $parent = 0; }
	foreach ( $GLOBALS['T_comments'] as $c ) {
		if ( (int) $c->comment_post_ID === (int) $data['comment_post_ID'] && (int) $c->comment_parent === $parent && 'trash' !== (string) $c->comment_approved
			&& $c->comment_author === ( $data['comment_author'] ?? '' ) && $c->comment_content === $data['comment_content'] ) {
			return $wp_error ? new WP_Error( 'comment_duplicate', 'Duplicate comment detected; it looks as though you have already said that!', 409 ) : false;
		}
	}
	$uid = (int) ( $data['user_id'] ?? 0 );
	$post = get_post( $data['comment_post_ID'] );
	if ( $uid && ( ( $post && (int) $post->post_author === $uid ) || t_has_cap( 'moderate_comments' ) ) ) {
		$approved = '1';
	} else {
		// check_comment: manual moderation holds everything; comment_previously_approved needs an earlier approved comment of the
		// same author (name and email); then the disallowed list (option disallowed_keys, one word per line) makes it 'trash'
		// (EMPTY_TRASH_DAYS is 30 on a real site) or, with no trash, 'spam'.
		$ok = ! get_option( 'comment_moderation' );
		if ( $ok && get_option( 'comment_previously_approved' ) ) {
			$ok = false;
			foreach ( $GLOBALS['T_comments'] as $c ) { if ( '1' === (string) $c->comment_approved && $c->comment_author === ( $data['comment_author'] ?? '' ) && $c->comment_author_email === ( $data['comment_author_email'] ?? '' ) ) { $ok = true; } }
		}
		$approved = $ok ? '1' : '0';
		foreach ( preg_split( '/\R/', trim( (string) get_option( 'disallowed_keys', '' ) ) ) as $w ) {
			if ( '' !== trim( $w ) && false !== stripos( ( $data['comment_content'] ?? '' ) . ' ' . ( $data['comment_author'] ?? '' ), trim( $w ) ) ) { $approved = ( ! defined( 'EMPTY_TRASH_DAYS' ) || EMPTY_TRASH_DAYS ) ? 'trash' : 'spam'; }
		}
	}
	return t_add_comment( array( 'comment_post_ID' => (int) $data['comment_post_ID'], 'comment_content' => $data['comment_content'], 'comment_parent' => $parent, 'user_id' => $uid,
		'comment_author' => $data['comment_author'] ?? '', 'comment_author_email' => $data['comment_author_email'] ?? '', 'comment_author_url' => $data['comment_author_url'] ?? '',
		'comment_author_IP' => $_SERVER['REMOTE_ADDR'] ?? '', 'comment_approved' => $approved, 'comment_type' => 'comment' ) );
}
// wp_restore_post_revision as core: the revision's title, content and excerpt go to the parent through wp_update_post, so
// wp_save_post_revision runs AFTER the update, on the restored content: it does NOT keep the pre-restore content (the revision
// made at the previous save is the one that holds that, when there is one). Returns the parent id; null for a missing revision.
function wp_restore_post_revision( $revision, $fields = null ) {
	$r = get_post( is_object( $revision ) ? $revision->ID : $revision );
	if ( ! $r || 'revision' !== $r->post_type ) { return null; }
	$GLOBALS['T_comment_calls'][] = array( 'wp_restore_post_revision', (int) $r->ID );
	$id = wp_update_post( wp_slash( array( 'ID' => (int) $r->post_parent, 'post_title' => $r->post_title, 'post_content' => $r->post_content, 'post_excerpt' => $r->post_excerpt ) ) );
	if ( ! $id || is_wp_error( $id ) ) { return $id; }
	update_post_meta( $id, '_edit_last', get_current_user_id() );
	return $id;
}

// ---- media WRITE stand-ins (#32, 2.2.0, Task 5). t_reset() clears them (t_media_reset). Nothing here touches the network:
// every fetch is answered from T_http and every DNS question from T_dns.
//   T_http          url => array( code, headers (name => value), body ) or a WP_Error; a url not listed answers a WP_Error
//                   (http_request_failed), as an unreachable host would
//   T_http_calls    every array( url, args ) given to wp_remote_get (it also adds 'wp_remote_get' to T_traps)
//   T_dns           host => list of addresses d5b_wm_dns_lookup returns (the plugin's own lookup, plugged here so the test
//                   scripts DNS); a host not listed does not resolve.  T_dns_calls  every host asked
//   T_max_upload    what wp_max_upload_size returns;  T_mimes  what get_allowed_mime_types returns (core's defaults: no svg)
//   T_files         uploads-relative path => bytes, the files media_handle_sideload stored;  T_files_deleted  paths removed
//   T_sideloads     every array( file_array, post_id, desc, post_data ) given to media_handle_sideload
//   T_tempfiles     every temporary file wp_tempnam made (a test checks none is left behind)
//   T_attach_delete_blocked  wp_delete_attachment returns false and deletes nothing (a plugin stopped it)
// As core: wp_remote_get with stream + filename writes the body to that file, cut at limit_response_size; media_handle_sideload
// runs the checks of wp_handle_sideload (empty file, wp_check_filetype_and_ext, allowed type), moves the file under a unique
// sanitized name, inserts the attachment (post fields UNSLASHED: the caller slashes) and stores its metadata;
// wp_delete_attachment removes the post, its meta, its files and every _thumbnail_id pointing at it.
function t_media_reset() {
	$GLOBALS['T_http'] = array(); $GLOBALS['T_http_calls'] = array(); $GLOBALS['T_dns'] = array(); $GLOBALS['T_dns_calls'] = array();
	$GLOBALS['T_max_upload'] = 100000; $GLOBALS['T_files'] = array(); $GLOBALS['T_files_deleted'] = array(); $GLOBALS['T_sideloads'] = array();
	$GLOBALS['T_attach_delete_blocked'] = false; $GLOBALS['T_curl_pins'] = array(); $GLOBALS['T_curl_pin_fail'] = false; $GLOBALS['T_http_transport'] = 'curl'; $GLOBALS['T_rebind'] = array(); $GLOBALS['T_connects'] = array();
	if ( isset( $GLOBALS['T_actions']['http_api_curl'] ) ) { unset( $GLOBALS['T_actions']['http_api_curl'], $GLOBALS['T_priorities']['http_api_curl'] ); }
	$GLOBALS['T_mimes'] = array( 'jpg|jpeg|jpe' => 'image/jpeg', 'gif' => 'image/gif', 'png' => 'image/png', 'pdf' => 'application/pdf', 'txt|asc|c|cc|h|srt' => 'text/plain' );
	foreach ( $GLOBALS['T_tempfiles'] ?? array() as $f ) { if ( file_exists( $f ) ) { @unlink( $f ); } }
	$GLOBALS['T_tempfiles'] = array();
}
if ( ! function_exists( 'd5b_wm_dns_lookup' ) ) {
	function d5b_wm_dns_lookup( string $host ): array { $GLOBALS['T_dns_calls'][] = $host; return array_values( array_map( 'strval', (array) ( $GLOBALS['T_dns'][ strtolower( $host ) ] ?? array() ) ) ); }
}
// The pin: the plugin's CURLOPT_RESOLVE setter, plugged here so the test sees what was pinned on the stand-in handle.
//   T_curl_pins      every array( handle id, resolve entries );  T_curl_pin_fail  true = the setter fails (returns false)
if ( ! function_exists( 'd5b_wm_curl_pin' ) ) {
	function d5b_wm_curl_pin( $handle, array $resolve ): bool {
		if ( ! empty( $GLOBALS['T_curl_pin_fail'] ) || ! $handle instanceof T_CurlHandle ) { return false; }
		$handle->resolve = $resolve; $GLOBALS['T_curl_pins'][] = array( $handle->id, $resolve );
		return true;
	}
}
class T_CurlHandle { public $id; public $resolve = array(); function __construct( $id ) { $this->id = $id; } }
function remove_action( $h, $cb, $p = 10 ) {
	foreach ( $GLOBALS['T_actions'][ $h ] ?? array() as $i => $c ) {
		if ( $c === $cb ) { unset( $GLOBALS['T_actions'][ $h ][ $i ], $GLOBALS['T_priorities'][ $h ][ $i ] ); $GLOBALS['T_actions'][ $h ] = array_values( $GLOBALS['T_actions'][ $h ] ); $GLOBALS['T_priorities'][ $h ] = array_values( $GLOBALS['T_priorities'][ $h ] ?? array() ); return true; }
	}
	return false;
}
// As WordPress's curl transport: http_api_curl runs (callbacks by priority, as do_action_ref_array( &$handle, $args, $url ))
// once the handle is built and before it connects; then curl "connects": to the pinned address when CURLOPT_RESOLVE names
// the host, else to what DNS says at connect time: T_rebind host => address (a rebinding host), else T_dns's first answer.
//   T_http_transport  'curl' (default) or 'streams' (http_api_curl never runs);  T_connects  every address connected to
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['T_traps'][] = 'wp_remote_get';
	$GLOBALS['T_http_calls'][] = array( $url, $args );
	$host = strtolower( trim( (string) parse_url( $url, PHP_URL_HOST ), '[]' ) );
	$port = (int) ( parse_url( $url, PHP_URL_PORT ) ?: ( 'https' === strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ) ? 443 : 80 ) );
	$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : ( $GLOBALS['T_rebind'][ $host ] ?? ( $GLOBALS['T_dns'][ $host ][0] ?? '' ) );
	if ( 'curl' === ( $GLOBALS['T_http_transport'] ?? 'curl' ) ) {
		$handle = new T_CurlHandle( count( $GLOBALS['T_http_calls'] ) );
		$cbs = $GLOBALS['T_actions']['http_api_curl'] ?? array(); $pri = $GLOBALS['T_priorities']['http_api_curl'] ?? array();
		$order = array_keys( $cbs ); usort( $order, function ( $a, $b ) use ( $pri ) { return ( $pri[ $a ] ?? 10 ) <=> ( $pri[ $b ] ?? 10 ); } );
		foreach ( $order as $i ) { call_user_func( $cbs[ $i ], $handle, $args, $url ); }
		foreach ( $handle->resolve as $e ) { if ( preg_match( '/^(.+):(\d+):\[?([^\]]+)\]?$/', $e, $m ) && strtolower( $m[1] ) === $host && (int) $m[2] === $port ) { $ip = $m[3]; } }
	}
	$GLOBALS['T_connects'][] = $ip;
	$r = $GLOBALS['T_http'][ $url ] ?? new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host (stand-in: no scripted answer).' );
	if ( $r instanceof WP_Error ) { return $r; }
	$body = (string) ( $r['body'] ?? '' );
	if ( isset( $args['limit_response_size'] ) ) { $body = substr( $body, 0, (int) $args['limit_response_size'] ); }
	if ( ! empty( $args['stream'] ) ) { file_put_contents( $args['filename'], $body ); $body = ''; }
	return array( 'headers' => array_change_key_case( (array) ( $r['headers'] ?? array() ), CASE_LOWER ), 'body' => $body, 'response' => array( 'code' => (int) ( $r['code'] ?? 200 ), 'message' => '' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
}
function wp_remote_retrieve_response_code( $r ) { return ( is_wp_error( $r ) || ! isset( $r['response']['code'] ) ) ? '' : (int) $r['response']['code']; }
function wp_remote_retrieve_headers( $r ) { return ( is_wp_error( $r ) || ! isset( $r['headers'] ) ) ? array() : $r['headers']; }
function wp_remote_retrieve_header( $r, $h ) { $all = wp_remote_retrieve_headers( $r ); return $all[ strtolower( $h ) ] ?? ''; }
function wp_tempnam( $filename = '', $dir = '' ) { $t = tempnam( sys_get_temp_dir(), 'd5bt' ); $GLOBALS['T_tempfiles'][] = $t; return $t; }
function wp_max_upload_size() { return (int) $GLOBALS['T_max_upload']; }
function get_allowed_mime_types( $user = null ) { return $GLOBALS['T_mimes']; }
// As core (formatting.php), reduced: the special characters dropped, whitespace runs to '-', trimmed of . - _ at the ends.
function sanitize_file_name( $f ) {
	$special = array( '?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', chr( 0 ) );
	$f = str_replace( $special, '', (string) $f );
	$f = preg_replace( '/[\r\n\t -]+/', '-', $f );
	return trim( $f, '.-_' );
}
function wp_check_filetype( $filename, $mimes = null ) {
	foreach ( null === $mimes ? get_allowed_mime_types() : $mimes as $exts => $type ) {
		if ( preg_match( '!\.(' . $exts . ')$!i', (string) $filename, $m ) ) { return array( 'ext' => strtolower( $m[1] ), 'type' => $type ); }
	}
	return array( 'ext' => false, 'type' => false );
}
// The real type of some bytes, by magic numbers (as getimagesize / finfo would say).
function t_magic_mime( $b ) {
	if ( 0 === strncmp( $b, "\x89PNG\r\n\x1a\n", 8 ) ) { return 'image/png'; }
	if ( 0 === strncmp( $b, "\xff\xd8\xff", 3 ) ) { return 'image/jpeg'; }
	if ( 0 === strncmp( $b, 'GIF87a', 6 ) || 0 === strncmp( $b, 'GIF89a', 6 ) ) { return 'image/gif'; }
	if ( 0 === strncmp( $b, '%PDF-', 5 ) ) { return 'application/pdf'; }
	if ( false !== stripos( $b, '<svg' ) ) { return 'image/svg+xml'; }
	if ( false !== stripos( $b, '<?php' ) ) { return 'text/x-php'; }
	if ( false !== stripos( $b, '<html' ) || false !== stripos( $b, '<script' ) ) { return 'text/html'; }
	return 1 === preg_match( '/^[\x09\x0a\x0d\x20-\x7e]*$/', $b ) ? 'text/plain' : 'application/octet-stream';
}
// As core (functions.php): the type from the extension, then checked against the bytes: an image extension on another kind of
// image is renamed (proper_filename), on anything else refused; other types must match the bytes (octet-stream only for
// application/audio/video types, text/plain only for text types); finally the type must be allowed.
function wp_check_filetype_and_ext( $file, $filename, $mimes = null ) {
	$wft = wp_check_filetype( $filename, $mimes ); $ext = $wft['ext']; $type = $wft['type']; $proper = false;
	if ( $type && file_exists( $file ) ) {
		$real = t_magic_mime( (string) file_get_contents( $file, false, null, 0, 1024 ) );
		if ( 0 === strpos( $type, 'image/' ) ) {
			if ( $real !== $type ) {
				$map = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif' );
				if ( isset( $map[ $real ] ) ) {
					$proper = preg_replace( '/\.[^.]+$/', '', (string) $filename ) . '.' . $map[ $real ];
					$w2 = wp_check_filetype( $proper, $mimes ); $ext = $w2['ext']; $type = $w2['type'];
				} else { $ext = false; $type = false; }
			}
		} elseif ( 'application/octet-stream' === $real ) {
			if ( ! in_array( strtok( $type, '/' ), array( 'application', 'video', 'audio' ), true ) ) { $ext = false; $type = false; }
		} elseif ( 'text/plain' === $real ) {
			if ( ! in_array( $type, array( 'text/plain', 'text/csv', 'text/richtext', 'text/tsv', 'text/vtt' ), true ) ) { $ext = false; $type = false; }
		} elseif ( $real !== $type ) { $ext = false; $type = false; }
	}
	if ( $type && ! in_array( $type, null === $mimes ? get_allowed_mime_types() : $mimes, true ) ) { $ext = false; $type = false; }
	return array( 'ext' => $ext, 'type' => $type, 'proper_filename' => $proper );
}
function wp_insert_attachment( $args, $file = false, $parent_post_id = 0, $wp_error = false, $fire_after_hooks = true ) {
	if ( ! empty( $GLOBALS['T_insert_fail'] ) ) { return $wp_error ? new WP_Error( 'db_insert_error', 'Could not insert post into the database.' ) : 0; }
	$f = wp_unslash( $args ); $now = gmdate( 'Y-m-d H:i:s' );
	$id = t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_author' => (int) $GLOBALS['T_user_id'], 'post_parent' => (int) ( $parent_post_id ?: ( $f['post_parent'] ?? 0 ) ),
		'post_title' => (string) ( $f['post_title'] ?? '' ), 'post_content' => (string) ( $f['post_content'] ?? '' ), 'post_excerpt' => (string) ( $f['post_excerpt'] ?? '' ),
		'post_name' => sanitize_title( (string) ( $f['post_title'] ?? '' ) ), 'post_mime_type' => (string) ( $f['post_mime_type'] ?? '' ), 'post_date_gmt' => $now, 'post_modified_gmt' => $now ) );
	if ( $file ) { update_post_meta( $id, '_wp_attached_file', $file ); }
	return $id;
}
function wp_generate_attachment_metadata( $id, $file ) {
	$rel = (string) get_post_meta( $id, '_wp_attached_file', true ); $b = $GLOBALS['T_files'][ $rel ] ?? '';
	$info = '' === $b ? false : @getimagesizefromstring( $b );
	if ( ! $info ) { return array( 'filesize' => strlen( $b ) ); }
	$stem = preg_replace( '/\.[^.]+$/', '', basename( $rel ) ); $ext = pathinfo( $rel, PATHINFO_EXTENSION );
	return array( 'width' => (int) $info[0], 'height' => (int) $info[1], 'file' => $rel, 'filesize' => strlen( $b ),
		'sizes' => array( 'thumbnail' => array( 'file' => $stem . '-150x150.' . $ext, 'width' => 150, 'height' => 150, 'mime-type' => (string) $info['mime'] ) ), 'image_meta' => array( 'title' => '', 'caption' => '' ) );
}
function wp_update_attachment_metadata( $id, $data ) { return update_post_meta( $id, '_wp_attachment_metadata', $data ); }
function media_handle_sideload( $file_array, $post_id = 0, $desc = null, $post_data = array() ) {
	$GLOBALS['T_sideloads'][] = array( $file_array, $post_id, $desc, $post_data );
	$tmp = (string) $file_array['tmp_name']; $name = (string) $file_array['name'];
	if ( ! is_file( $tmp ) || 0 === filesize( $tmp ) ) { return new WP_Error( 'upload_error', 'File is empty. Please upload something more substantial.' ); }
	$chk = wp_check_filetype_and_ext( $tmp, $name );
	if ( ! $chk['type'] || ! $chk['ext'] ) { return new WP_Error( 'upload_error', 'Sorry, you are not allowed to upload this file type.' ); }
	if ( $chk['proper_filename'] ) { $name = $chk['proper_filename']; }
	$name = sanitize_file_name( $name ); $rel = gmdate( 'Y/m' ) . '/' . $name;
	for ( $i = 1; isset( $GLOBALS['T_files'][ $rel ] ); $i++ ) { $rel = gmdate( 'Y/m' ) . '/' . preg_replace( '/(\.[^.]+)$/', '-' . $i . '$1', $name ); }
	$GLOBALS['T_files'][ $rel ] = file_get_contents( $tmp ); @unlink( $tmp );
	$att = array_merge( array( 'post_mime_type' => $chk['type'], 'post_parent' => $post_id, 'post_title' => preg_replace( '/\.[^.]+$/', '', basename( $name ) ), 'post_content' => '' ), $post_data );
	$id = wp_insert_attachment( $att, $rel, $post_id, true );
	if ( is_wp_error( $id ) ) { unset( $GLOBALS['T_files'][ $rel ] ); return $id; }
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $rel ) );
	return $id;
}
function wp_delete_attachment( $id, $force_delete = false ) {
	$p = $GLOBALS['T_posts'][ (int) $id ] ?? null;
	if ( ! $p || 'attachment' !== $p->post_type || ! empty( $GLOBALS['T_attach_delete_blocked'] ) ) { return false; }
	if ( ! $force_delete && defined( 'MEDIA_TRASH' ) && MEDIA_TRASH && 'trash' !== $p->post_status ) { return wp_trash_post( $id ); }
	$rel = (string) get_post_meta( $id, '_wp_attached_file', true ); $meta = wp_get_attachment_metadata( $id );
	foreach ( array_merge( array( $rel ), array_map( function ( $s ) use ( $rel ) { return dirname( $rel ) . '/' . $s['file']; }, (array) ( $meta['sizes'] ?? array() ) ) ) as $f ) {
		if ( '' !== $f ) { unset( $GLOBALS['T_files'][ $f ] ); $GLOBALS['T_files_deleted'][] = $f; }
	}
	foreach ( $GLOBALS['T_meta'] as $pid => $keys ) { if ( isset( $keys['_thumbnail_id'] ) && in_array( (string) $id, array_map( 'strval', $keys['_thumbnail_id'] ), true ) ) { unset( $GLOBALS['T_meta'][ $pid ]['_thumbnail_id'] ); } }
	unset( $GLOBALS['T_posts'][ (int) $id ], $GLOBALS['T_meta'][ (int) $id ] ); $GLOBALS['T_deleted'][] = (int) $id;
	return $p;
}
t_media_reset();

// ---- terms and menus (Task 6). As core's taxonomy.php and nav-menu.php (6.4) where the plugin depends on it.
// Terms live in T_terms (id => object), menus in T_menus (a menu is a nav_menu term); relationships in T_object_terms
// (object id => taxonomy => term ids). count = the objects that hold the term (core counts published posts only).
// wp_insert_term / wp_update_term UNSLASH name and description, as core. wp_update_term merges the given args over the term's
// own (core: array_merge of the slashed term data). wp_delete_term: the default category (and a taxonomy's default term) is
// never deleted (returns 0); children move to the deleted term's parent; objects lose the term, and in category an object
// left with no category gets the default one. Failure switches: T_term_fail (insert/update: a database error),
// T_term_delete_fail (wp_delete_term returns false and changes nothing), T_menu_delete_fail (wp_delete_nav_menu deletes the
// items but not the menu term, and returns false), T_theme_mod_fail (set_theme_mod changes nothing).
function t_term_obj( int $id ) { return $GLOBALS['T_terms'][ $id ] ?? ( $GLOBALS['T_menus'][ $id ] ?? null ); }
function t_term_tax( $o ): string { return (string) ( $o->taxonomy ?? 'nav_menu' ); }
function t_recount( int $id ): void {
	$o = t_term_obj( $id ); if ( ! $o ) { return; }
	$n = 0;
	foreach ( $GLOBALS['T_object_terms'] as $taxes ) { foreach ( $taxes as $ids ) { if ( in_array( $id, array_map( 'intval', (array) $ids ), true ) ) { $n++; } } }
	$o->count = $n;
}
function taxonomy_exists( $t ) { return false !== get_taxonomy( $t ); }
function is_taxonomy_hierarchical( $t ) { $o = get_taxonomy( $t ); return $o && ! empty( $o->hierarchical ); }
function t_terms_of( string $tax ): array {
	$pool = 'nav_menu' === $tax ? $GLOBALS['T_menus'] : $GLOBALS['T_terms'];
	return array_filter( $pool, function ( $o ) use ( $tax ) { return t_term_tax( $o ) === $tax; } );
}
function term_exists( $term, $tax = '', $parent = null ) {
	if ( is_int( $term ) ) {
		$o = t_term_obj( $term );
		return ( $o && ( '' === $tax || t_term_tax( $o ) === $tax ) ) ? array( 'term_id' => (int) $o->term_id, 'term_taxonomy_id' => (int) $o->term_id ) : null;
	}
	$term = trim( wp_unslash( (string) $term ) );
	foreach ( array( 'slug', 'name' ) as $f ) {
		foreach ( t_terms_of( (string) $tax ) as $o ) {
			if ( null !== $parent && (int) $o->parent !== (int) $parent ) { continue; }
			if ( 'slug' === $f ? $o->slug === sanitize_title( $term ) : 0 === strcasecmp( $o->name, $term ) ) { return array( 'term_id' => (int) $o->term_id, 'term_taxonomy_id' => (int) $o->term_id ); }
		}
	}
	return null;
}
function get_term_by( $field, $value, $tax = '' ) {
	if ( 'id' === $field || 'term_id' === $field ) { $o = t_term_obj( (int) $value ); return ( $o && t_term_tax( $o ) === $tax ) ? $o : false; }
	foreach ( t_terms_of( (string) $tax ) as $o ) {
		if ( 'slug' === $field ? $o->slug === sanitize_title( (string) $value ) : ( 'name' === $field && 0 === strcasecmp( $o->name, trim( (string) $value ) ) ) ) { return $o; }
	}
	return false;
}
function t_unique_slug( string $slug, string $tax, int $except = 0 ): string {
	$taken = function ( $s ) use ( $tax, $except ) { foreach ( t_terms_of( $tax ) as $o ) { if ( $o->slug === $s && (int) $o->term_id !== $except ) { return true; } } return false; };
	if ( ! $taken( $slug ) ) { return $slug; }
	for ( $n = 2; $taken( $slug . '-' . $n ); $n++ ) {}
	return $slug . '-' . $n;
}
function wp_insert_term( $term, $tax, $args = array() ) {
	if ( ! get_taxonomy( $tax ) ) { return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ); }
	$name = trim( wp_unslash( (string) $term ) );
	if ( '' === $name ) { return new WP_Error( 'empty_term_name', 'A name is required for this term.' ); }
	if ( ! empty( $GLOBALS['T_term_fail'] ) ) { return new WP_Error( 'db_insert_error', 'Could not insert term into the database.' ); }
	$parent = (int) ( $args['parent'] ?? 0 );
	if ( $parent > 0 && ! term_exists( $parent, $tax ) ) { return new WP_Error( 'missing_parent', 'Parent term does not exist.' ); }
	$provided = '' !== (string) ( $args['slug'] ?? '' );
	$slug = sanitize_title( $provided ? (string) $args['slug'] : $name );
	$hier = is_taxonomy_hierarchical( $tax );
	$name_match = get_term_by( 'name', $name, $tax );
	if ( $name_match && ( ! $provided || $name_match->slug === $slug || get_term_by( 'slug', $slug, $tax ) ) ) {
		if ( ! $hier ) { return new WP_Error( 'term_exists', 'A term with the name provided already exists in this taxonomy.', (int) $name_match->term_id ); }
		foreach ( t_terms_of( $tax ) as $o ) { if ( (int) $o->parent === $parent && 0 === strcasecmp( $o->name, $name ) ) { return new WP_Error( 'term_exists', 'A term with the name provided already exists with this parent.', (int) $o->term_id ); } }
	}
	$id = $GLOBALS['T_next_id']++;
	$o  = (object) array( 'term_id' => $id, 'name' => $name, 'slug' => t_unique_slug( '' === $slug ? (string) $id : $slug, $tax ), 'description' => wp_unslash( (string) ( $args['description'] ?? '' ) ), 'parent' => $parent, 'count' => 0, 'taxonomy' => $tax, 'term_taxonomy_id' => $id );
	if ( 'nav_menu' === $tax ) { $GLOBALS['T_menus'][ $id ] = $o; } else { $GLOBALS['T_terms'][ $id ] = $o; }
	return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
}
function wp_update_term( $term_id, $tax, $args = array() ) {
	if ( ! get_taxonomy( $tax ) ) { return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ); }
	$o = t_term_obj( (int) $term_id );
	if ( ! $o || t_term_tax( $o ) !== $tax ) { return new WP_Error( 'invalid_term', 'Empty Term.' ); }
	if ( ! empty( $GLOBALS['T_term_fail'] ) ) { return new WP_Error( 'db_update_error', 'Could not update term in the database.' ); }
	$args = array_merge( wp_slash( array( 'name' => $o->name, 'slug' => $o->slug, 'description' => $o->description, 'parent' => (int) $o->parent ) ), $args );
	$name = trim( wp_unslash( (string) $args['name'] ) );
	if ( '' === $name ) { return new WP_Error( 'empty_term_name', 'A name is required for this term.' ); }
	$parent = (int) $args['parent'];
	if ( $parent > 0 && ! term_exists( $parent, $tax ) ) { return new WP_Error( 'missing_parent', 'Parent term does not exist.' ); }
	$empty = '' === (string) $args['slug'];
	$slug  = sanitize_title( $empty ? $name : (string) $args['slug'] );
	$dup   = get_term_by( 'slug', $slug, $tax );
	if ( $dup && (int) $dup->term_id !== (int) $o->term_id ) {
		if ( $empty || $parent !== (int) $o->parent ) { $slug = t_unique_slug( $slug, $tax, (int) $o->term_id ); }
		else { return new WP_Error( 'duplicate_term_slug', 'The slug "' . $slug . '" is already in use by another term.' ); }
	}
	$o->name = $name; $o->slug = $slug; $o->description = wp_unslash( (string) $args['description'] ); $o->parent = $parent;
	return array( 'term_id' => (int) $o->term_id, 'term_taxonomy_id' => (int) $o->term_id );
}
function wp_delete_term( $term, $tax, $args = array() ) {
	$term = (int) $term;
	$o = t_term_obj( $term );
	if ( ! $o || t_term_tax( $o ) !== $tax ) { return false; }
	if ( 'category' === $tax ) { $default = (int) get_option( 'default_category' ); if ( $default === $term ) { return 0; } }
	$to = get_taxonomy( $tax );
	if ( ! empty( $to->default_term ) ) { $default = (int) get_option( 'default_term_' . $tax ); if ( $default === $term ) { return 0; } }
	if ( isset( $default ) && ! term_exists( $default, $tax ) ) { unset( $default ); }
	if ( ! empty( $GLOBALS['T_term_delete_fail'] ) ) { return false; }
	if ( is_taxonomy_hierarchical( $tax ) ) { foreach ( t_terms_of( $tax ) as $c ) { if ( (int) $c->parent === $term ) { $c->parent = (int) $o->parent; } } }
	foreach ( $GLOBALS['T_object_terms'] as $obj => $taxes ) {
		$ids = array_map( 'intval', (array) ( $taxes[ $tax ] ?? array() ) );
		if ( ! in_array( $term, $ids, true ) ) { continue; }
		$left = array_values( array_diff( $ids, array( $term ) ) );
		if ( array() === $left && isset( $default ) ) { $left = array( $default ); }
		$GLOBALS['T_object_terms'][ $obj ][ $tax ] = $left;
	}
	// core 6.4.1 taxonomy.php ~2122: every term meta row goes; then the term; then the delete_term action, on which
	// _wp_delete_tax_menu_item (nav-menu.php ~1100) deletes for good every menu item linking to the term.
	unset( $GLOBALS['T_term_meta'][ $term ] );
	unset( $GLOBALS['T_terms'][ $term ], $GLOBALS['T_menus'][ $term ] );
	if ( isset( $default ) ) { t_recount( $default ); }
	foreach ( wp_get_associated_nav_menu_items( $term, 'taxonomy', $tax ) as $mi ) { wp_delete_post( $mi, true ); }
	return true;
}
function t_term_ids( $terms, string $tax, bool $create ): array {
	$ids = array();
	foreach ( is_array( $terms ) ? $terms : array( $terms ) as $t ) {
		if ( is_int( $t ) ) { if ( term_exists( $t, $tax ) ) { $ids[] = $t; } continue; }
		$e = term_exists( (string) $t, $tax );
		if ( ! $e && $create ) { $e = wp_insert_term( (string) $t, $tax ); if ( is_wp_error( $e ) ) { continue; } }
		if ( $e ) { $ids[] = (int) $e['term_id']; }
	}
	return array_values( array_unique( $ids ) );
}
function wp_set_object_terms( $object_id, $terms, $tax, $append = false ) {
	if ( ! get_taxonomy( $tax ) ) { return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ); }
	if ( ! empty( $GLOBALS['T_term_fail'] ) ) { return new WP_Error( 'db_insert_error', 'Could not insert term relationship into the database.' ); }
	$ids = t_term_ids( $terms, $tax, true );
	$old = array_map( 'intval', (array) ( $GLOBALS['T_object_terms'][ (int) $object_id ][ $tax ] ?? array() ) );
	$new = $append ? array_values( array_unique( array_merge( $old, $ids ) ) ) : $ids;
	$GLOBALS['T_object_terms'][ (int) $object_id ][ $tax ] = $new;
	foreach ( array_unique( array_merge( $old, $new ) ) as $t ) { t_recount( (int) $t ); }
	return array_map( 'strval', $new );
}
function wp_remove_object_terms( $object_id, $terms, $tax ) {
	if ( ! get_taxonomy( $tax ) ) { return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' ); }
	if ( ! empty( $GLOBALS['T_term_fail'] ) ) { return new WP_Error( 'db_delete_error', 'Could not remove term relationship from the database.' ); }
	$ids = t_term_ids( $terms, $tax, false );
	$old = array_map( 'intval', (array) ( $GLOBALS['T_object_terms'][ (int) $object_id ][ $tax ] ?? array() ) );
	$new = array_values( array_diff( $old, $ids ) );
	$GLOBALS['T_object_terms'][ (int) $object_id ][ $tax ] = $new;
	foreach ( $old as $t ) { t_recount( (int) $t ); }
	return count( $new ) < count( $old );
}
function get_objects_in_term( $term_ids, $taxonomies, $args = array() ) {
	$term_ids = array_map( 'intval', (array) $term_ids ); $taxonomies = (array) $taxonomies; $out = array();
	foreach ( $GLOBALS['T_object_terms'] as $obj => $taxes ) {
		foreach ( $taxonomies as $tax ) { if ( array_intersect( $term_ids, array_map( 'intval', (array) ( $taxes[ $tax ] ?? array() ) ) ) ) { $out[] = (int) $obj; break; } }
	}
	sort( $out );
	return $out;
}
// Term meta (T_term_meta: term id => key => rows), as core's get_metadata / add_metadata (key and value unslashed on add).
function get_term_meta( $id, $k = '', $single = false ) {
	$all = $GLOBALS['T_term_meta'][ (int) $id ] ?? array();
	if ( '' === $k ) { return $all; }
	$vals = $all[ $k ] ?? array();
	return $single ? ( $vals[0] ?? '' ) : $vals;
}
function add_term_meta( $id, $k, $v, $unique = false ) { $GLOBALS['T_term_meta'][ (int) $id ][ wp_unslash( $k ) ][] = wp_unslash( $v ); return true; }
// As core: the nav_menu_item ids whose _menu_item_object_id is the object (post_status any: trash and auto-draft left out),
// of that type, and for a taxonomy item of that taxonomy.
function wp_get_associated_nav_menu_items( $object_id = 0, $object_type = 'post_type', $taxonomy = '' ) {
	$out = array();
	foreach ( $GLOBALS['T_posts'] as $p ) {
		if ( 'nav_menu_item' !== $p->post_type || in_array( $p->post_status, array( 'trash', 'auto-draft' ), true ) ) { continue; }
		if ( (int) get_post_meta( $p->ID, '_menu_item_object_id', true ) !== (int) $object_id ) { continue; }
		$type = get_post_meta( $p->ID, '_menu_item_type', true );
		if ( ( 'post_type' === $object_type && 'post_type' === $type ) || ( 'taxonomy' === $object_type && 'taxonomy' === $type && get_post_meta( $p->ID, '_menu_item_object', true ) === $taxonomy ) ) { $out[] = (int) $p->ID; }
	}
	return array_values( array_unique( $out ) );
}
function wp_get_object_terms( $object_ids, $taxonomies, $args = array() ) {
	$out = array();
	foreach ( (array) $object_ids as $obj ) { foreach ( (array) $taxonomies as $tax ) { foreach ( (array) ( $GLOBALS['T_object_terms'][ (int) $obj ][ $tax ] ?? array() ) as $t ) { $o = t_term_obj( (int) $t ); if ( $o ) { $out[] = 'ids' === ( $args['fields'] ?? 'all' ) ? (int) $t : $o; } } } }
	return $out;
}

// Menus. A menu item is a nav_menu_item post in T_posts with core's meta (_menu_item_type, _menu_item_menu_item_parent,
// _menu_item_object_id, _menu_item_object, _menu_item_target, _menu_item_classes, _menu_item_xfn, _menu_item_url) and its
// menu as the nav_menu term. wp_update_nav_menu_item behaves as core 6.4: the data is NOT merged with the item's own (every
// field not given is reset to its default); a new item is a draft unless menu-item-status is publish; an existing item is
// published unless menu-item-status is draft; position 0 = after the last item; a custom item's object is itself; a
// non-custom item keeps no URL and a title equal to its object's title is stored empty. wp_get_nav_menu_items: post_status
// publish by default ('any' leaves out trash and auto-draft), sorted by menu_order and renumbered 1..n on the copies it
// returns, each copy decorated as wp_setup_nav_menu_item does. wp_delete_nav_menu: every object in the menu (trashed items
// too) is deleted for good, then the term, then the menu is taken off every location (set to 0).
function get_theme_mod( $name, $default = false ) {
	if ( 'nav_menu_locations' === $name ) { return $GLOBALS['T_menu_locations'] ?? $default; }
	return array_key_exists( $name, $GLOBALS['T_theme_mods'] ?? array() ) ? $GLOBALS['T_theme_mods'][ $name ] : $default;
}
function set_theme_mod( $name, $value ) {
	if ( ! empty( $GLOBALS['T_theme_mod_fail'] ) ) { return false; }
	if ( 'nav_menu_locations' === $name ) { $GLOBALS['T_menu_locations'] = $value; } else { $GLOBALS['T_theme_mods'][ $name ] = $value; }
	return true;
}
function is_nav_menu_item( $id = 0 ) { $p = get_post( $id ); return $p && 'nav_menu_item' === $p->post_type; }
function wp_create_nav_menu( $menu_name ) {
	$name = trim( wp_unslash( (string) $menu_name ) );
	if ( get_term_by( 'name', $name, 'nav_menu' ) ) { return new WP_Error( 'menu_exists', 'The menu name <strong>' . esc_html( $name ) . '</strong> conflicts with another menu name. Please try another.' ); }
	$r = wp_insert_term( $menu_name, 'nav_menu' );
	return is_wp_error( $r ) ? $r : (int) $r['term_id'];
}
function t_setup_nav_item( $p ) {
	$i = clone $p; $id = (int) $p->ID;
	$i->db_id = $id; $i->menu_item_parent = (string) get_post_meta( $id, '_menu_item_menu_item_parent', true );
	$i->object_id = (string) get_post_meta( $id, '_menu_item_object_id', true ); $i->object = (string) get_post_meta( $id, '_menu_item_object', true );
	$i->type = (string) get_post_meta( $id, '_menu_item_type', true ); $i->target = (string) get_post_meta( $id, '_menu_item_target', true );
	$i->attr_title = (string) $p->post_excerpt; $i->description = (string) $p->post_content; $i->xfn = (string) get_post_meta( $id, '_menu_item_xfn', true );
	$i->classes = (array) get_post_meta( $id, '_menu_item_classes', true );
	$title = (string) $p->post_title; $url = (string) get_post_meta( $id, '_menu_item_url', true );
	if ( 'post_type' === $i->type ) { $o = get_post( (int) $i->object_id ); if ( '' === $title && $o ) { $title = (string) $o->post_title; } $url = get_permalink( (int) $i->object_id ); }
	if ( 'taxonomy' === $i->type ) { $o = t_term_obj( (int) $i->object_id ); if ( '' === $title && $o ) { $title = (string) $o->name; } $url = 'https://x.example/?term_id=' . (int) $i->object_id; }
	$i->title = $title; $i->url = $url;
	return $i;
}
function t_nav_items( int $menu_id, $status ) {
	$want = 'any' === $status ? null : array_map( 'trim', is_array( $status ) ? $status : explode( ',', (string) $status ) );
	$items = array();
	foreach ( get_objects_in_term( $menu_id, 'nav_menu' ) as $id ) {
		$p = get_post( $id );
		if ( ! $p || 'nav_menu_item' !== $p->post_type ) { continue; }
		if ( null === $want ? in_array( $p->post_status, array( 'trash', 'auto-draft' ), true ) : ! in_array( $p->post_status, $want, true ) ) { continue; }
		$items[] = t_setup_nav_item( $p );
	}
	usort( $items, function ( $a, $b ) { return array( (int) $a->menu_order, (int) $a->ID ) <=> array( (int) $b->menu_order, (int) $b->ID ); } );
	foreach ( $items as $n => $i ) { $i->menu_order = $n + 1; }
	return $items;
}
function wp_update_nav_menu_item( $menu_id = 0, $menu_item_db_id = 0, $menu_item_data = array(), $fire_after_hooks = true ) {
	$menu_id = (int) $menu_id; $db_id = (int) $menu_item_db_id;
	if ( $db_id && ! is_nav_menu_item( $db_id ) ) { return new WP_Error( 'update_nav_menu_item_failed', 'The given object ID is not that of a menu item.' ); }
	$menu = wp_get_nav_menu_object( $menu_id );
	if ( ! $menu && 0 !== $menu_id ) { return new WP_Error( 'invalid_menu_id', 'Invalid menu ID.' ); }
	$args = array_merge( array( 'menu-item-object-id' => 0, 'menu-item-object' => '', 'menu-item-parent-id' => 0, 'menu-item-position' => 0, 'menu-item-type' => 'custom', 'menu-item-title' => '', 'menu-item-url' => '',
		'menu-item-description' => '', 'menu-item-attr-title' => '', 'menu-item-target' => '', 'menu-item-classes' => '', 'menu-item-xfn' => '', 'menu-item-status' => '' ), $menu_item_data );
	if ( 0 === (int) $args['menu-item-position'] ) {
		$all = $menu_id ? t_nav_items( $menu_id, 'publish,draft' ) : array(); $last = array_pop( $all );
		$args['menu-item-position'] = $last ? 1 + $last->menu_order : count( $all );
	}
	$orig_parent = $db_id ? (int) get_post( $db_id )->post_parent : 0;
	if ( 'custom' === $args['menu-item-type'] ) {
		$args['menu-item-url'] = trim( (string) $args['menu-item-url'] );
	} else {
		$args['menu-item-url'] = ''; $orig_title = '';
		if ( 'taxonomy' === $args['menu-item-type'] ) { $o = t_term_obj( (int) $args['menu-item-object-id'] ); $orig_parent = $o ? (int) $o->parent : 0; $orig_title = $o ? $o->name : ''; }
		elseif ( 'post_type' === $args['menu-item-type'] ) { $o = get_post( (int) $args['menu-item-object-id'] ); $orig_parent = (int) $o->post_parent; $orig_title = $o->post_title; }
		if ( wp_unslash( (string) $args['menu-item-title'] ) === $orig_title ) { $args['menu-item-title'] = ''; }
		if ( '' === $args['menu-item-title'] && '' === $args['menu-item-description'] ) { $args['menu-item-description'] = ' '; }
	}
	$post = array( 'menu_order' => $args['menu-item-position'], 'post_content' => $args['menu-item-description'], 'post_excerpt' => $args['menu-item-attr-title'], 'post_parent' => $orig_parent, 'post_title' => $args['menu-item-title'], 'post_type' => 'nav_menu_item' );
	$update = 0 !== $db_id;
	if ( ! $update ) {
		$post['post_status'] = 'publish' === $args['menu-item-status'] ? 'publish' : 'draft';
		$db_id = wp_insert_post( $post, true );
		if ( ! $db_id || is_wp_error( $db_id ) ) { return $db_id; }
	}
	if ( $menu_id && ( ! $update || ! in_array( $menu_id, array_map( 'intval', (array) ( $GLOBALS['T_object_terms'][ $db_id ]['nav_menu'] ?? array() ) ), true ) ) ) {
		$r = wp_set_object_terms( $db_id, array( (int) $menu->term_id ), 'nav_menu' );
		if ( is_wp_error( $r ) ) { return $r; }
	}
	if ( 'custom' === $args['menu-item-type'] ) { $args['menu-item-object-id'] = $db_id; $args['menu-item-object'] = 'custom'; }
	if ( (int) $args['menu-item-parent-id'] === $db_id ) { $args['menu-item-parent-id'] = 0; }
	update_post_meta( $db_id, '_menu_item_type', sanitize_key( $args['menu-item-type'] ) );
	update_post_meta( $db_id, '_menu_item_menu_item_parent', (string) (int) $args['menu-item-parent-id'] );
	update_post_meta( $db_id, '_menu_item_object_id', (string) (int) $args['menu-item-object-id'] );
	update_post_meta( $db_id, '_menu_item_object', sanitize_key( $args['menu-item-object'] ) );
	update_post_meta( $db_id, '_menu_item_target', sanitize_key( $args['menu-item-target'] ) );
	$cls = function ( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', $c ); };
	update_post_meta( $db_id, '_menu_item_classes', array_map( $cls, explode( ' ', (string) $args['menu-item-classes'] ) ) );
	update_post_meta( $db_id, '_menu_item_xfn', implode( ' ', array_map( $cls, explode( ' ', (string) $args['menu-item-xfn'] ) ) ) );
	update_post_meta( $db_id, '_menu_item_url', trim( wp_unslash( (string) $args['menu-item-url'] ) ) );
	if ( $update ) {
		$post['ID'] = $db_id; $post['post_status'] = 'draft' === $args['menu-item-status'] ? 'draft' : 'publish';
		$r = wp_update_post( $post, true );
		if ( is_wp_error( $r ) ) { return $r; }
	}
	return $db_id;
}
function wp_delete_nav_menu( $menu ) {
	$menu = wp_get_nav_menu_object( $menu );
	if ( ! $menu ) { return false; }
	foreach ( get_objects_in_term( (int) $menu->term_id, 'nav_menu' ) as $item ) { wp_delete_post( $item ); }
	$result = empty( $GLOBALS['T_menu_delete_fail'] ) ? wp_delete_term( (int) $menu->term_id, 'nav_menu' ) : false;
	$locations = get_nav_menu_locations();
	foreach ( $locations as $loc => $id ) { if ( (int) $id === (int) $menu->term_id ) { $locations[ $loc ] = 0; } }
	set_theme_mod( 'nav_menu_locations', $locations );
	return $result;
}
