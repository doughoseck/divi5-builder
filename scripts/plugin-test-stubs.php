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
	if ( function_exists( 't_settings_reset' ) ) { t_settings_reset(); }
	if ( function_exists( 't_widgets_reset' ) && class_exists( 'WP_Widget_Factory', false ) ) { t_widgets_reset(); }
	if ( function_exists( 't_cron_reset' ) ) { t_cron_reset(); }
	if ( function_exists( 't_templates_reset' ) ) { t_templates_reset(); }
	if ( function_exists( 't_plugins_reset' ) ) { t_plugins_reset(); }
	if ( function_exists( 't_users_reset' ) ) { t_users_reset(); }
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
	// With the plugin stand-ins (T_PLUGIN_STANDINS, at the end of this file) it also changes the active lists as core does.
	function deactivate_plugins( $plugins, $silent = false, $network = null ) { $GLOBALS['T_deactivated'][] = $plugins; $GLOBALS['T_deactivate_calls'][] = array( $plugins, $silent, $network ); if ( function_exists( 't_plugins_deactivate' ) ) { t_plugins_deactivate( $plugins, $network, $silent ); } }
}

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['T_options'] ) ? $GLOBALS['T_options'][ $n ] : $d; }
function update_option( $n, $v, $autoload = null ) { if ( in_array( $n, $GLOBALS['T_frozen_options'] ?? array(), true ) ) { return false; } $GLOBALS['T_writes'][]= array( 'update_option', $n ); $GLOBALS['T_options'][ $n ] = $v; if ( null !== $autoload ) { $GLOBALS['T_autoload'][ $n ] = $autoload; } return true; }
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
// T_upload_basedir (optional): a real folder a test uses as the uploads folder (the media delete moves real files into it).
// As core on a network: a site other than the main one has its own folder, uploads/sites/<blog id>.
function wp_upload_dir( $time = null, $create_dir = true, $refresh = false ) {
	$b = $GLOBALS['T_upload_basedir'] ?? ABSPATH . 'wp-content/uploads';
	$s = ( ! empty( $GLOBALS['T_multisite'] ) && (int) ( $GLOBALS['T_blog_id'] ?? 1 ) !== (int) ( $GLOBALS['T_main_site'] ?? 1 ) ) ? '/sites/' . (int) $GLOBALS['T_blog_id'] : '';
	return array( 'basedir' => $b . $s, 'baseurl' => 'https://x.example/wp-content/uploads' . $s );
}

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
	public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $options = 'wp_options';
	public $users = 'wp_users'; public $usermeta = 'wp_usermeta'; public $comments = 'wp_comments'; public $links = 'wp_links';
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
	// Like wpdb::delete: false on an error (no such table, empty where), else the number of rows deleted.
	function delete( $table, $where, $where_format = null ) {
		if ( ! isset( $this->tables[ $table ] ) || ! is_array( $where ) || array() === $where ) { return false; }
		$keep = array();
		foreach ( $this->tables[ $table ] as $row ) {
			foreach ( $where as $k => $v ) { if ( ! array_key_exists( $k, $row ) || (string) $row[ $k ] !== (string) $v ) { $keep[] = $row; continue 2; } }
		}
		$n = count( $this->tables[ $table ] ) - count( $keep );
		$this->tables[ $table ] = $keep;
		return $n;
	}
	function get_var( $sql ) {
		$this->note( $sql );
		if ( preg_match( "/^SHOW TABLES LIKE '(.*)'$/", $sql, $m ) ) { $t = stripslashes( stripslashes( $m[1] ) ); return isset( $this->tables[ $t ] ) ? $t : null; }
		// As MySQL: the smallest created_at, NULL for an empty table (and for a missing one, with an error).
		// The comments a user wrote, every status (the user stand-ins, T_USER_STANDINS).
		if ( preg_match( '/^SELECT COUNT\(\*\) FROM wp_comments WHERE user_id = (\d+)$/', $sql, $m ) ) {
			$n = 0; foreach ( $GLOBALS['T_comments'] ?? array() as $c ) { if ( (int) $c->user_id === (int) $m[1] ) { $n++; } } return (string) $n;
		}
		if ( preg_match( '/^SELECT MIN\(created_at\) FROM (\S+)$/', $sql, $m ) ) {
			$rows = $this->tables[ $m[1] ] ?? array(); if ( array() === $rows ) { return null; }
			$c = array_column( $rows, 'created_at' ); sort( $c, SORT_STRING ); return (string) $c[0];
		}
		return null;
	}
	// get_col understands the two lookups of the media delete preview, answered from T_meta and T_posts:
	//   SELECT post_id FROM wp_postmeta WHERE meta_key = 'k' AND meta_value = 'v' LIMIT n
	//   SELECT ID FROM wp_posts WHERE post_type NOT IN ('a','b') AND post_status <> 'auto-draft' AND (post_content LIKE 'p' OR ...) ORDER BY ID ASC LIMIT n
	// LIKE as MySQL: % any run, _ one character, a backslash escapes the next character.
	function get_col( $sql ) {
		$this->note( $sql );
		$q = "'((?:[^'\\\\]|\\\\.)*)'";
		// Core's own query in wp_delete_user / remove_user_from_blog: every item (any type, any status) of the author.
		if ( preg_match( '/^SELECT ID FROM wp_posts WHERE post_author = (\d+)$/', $sql, $m ) ) { return array_map( 'strval', t_author_ids( (int) $m[1] ) ); }
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
		if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '_transient_([^']+)' LIMIT 1$/", $sql, $m ) && function_exists( 't_db_transient' ) ) {
			$v = t_db_transient( stripslashes( $m[1] ) );
			return null === $v ? null : (object) array( 'option_value' => $v );
		}
		// The cron option as the database holds it (T_cron_array, serialized with its version), read past the request's
		// own copy (T_cron_request_copy, when a test models one, is what _get_cron_array() answers in this request).
		if ( "SELECT option_value FROM wp_options WHERE option_name = 'cron' LIMIT 1" === $sql ) {
			$GLOBALS['T_cron_fresh_reads'] = ( $GLOBALS['T_cron_fresh_reads'] ?? 0 ) + 1;
			$c = $GLOBALS['T_cron_array'] ?? null;
			return is_array( $c ) ? (object) array( 'option_value' => serialize( $c + array( 'version' => 2 ) ) ) : null;
		}
		if ( ! preg_match( '/^SELECT \* FROM (\S+) WHERE id = (\d+)$/', $sql, $m ) || ! isset( $this->tables[ $m[1] ] ) ) { return null; }
		foreach ( $this->tables[ $m[1] ] as $r ) { if ( (int) $r['id'] === (int) $m[2] ) { return $r; } }
		return null;
	}
	function get_results( $sql, $output = 'OBJECT' ) {
		$this->note( $sql );
		// The items of an author counted by type (any status), as MySQL's GROUP BY answers it.
		if ( preg_match( '/^SELECT post_type, COUNT\(\*\) AS n FROM wp_posts WHERE post_author = (\d+) GROUP BY post_type$/', $sql, $m ) ) {
			$by = array();
			foreach ( t_author_ids( (int) $m[1] ) as $id ) { $t = isset( $GLOBALS['T_posts'][ $id ] ) ? (string) $GLOBALS['T_posts'][ $id ]->post_type : 'post'; $by[ $t ] = ( $by[ $t ] ?? 0 ) + 1; }
			$out = array(); foreach ( $by as $t => $n ) { $r = array( 'post_type' => $t, 'n' => (string) $n ); $out[] = ARRAY_A === $output ? $r : (object) $r; }
			return $out;
		}
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
// As core, an expiration of 0 never expires (the doing_cron lock is set that way).
// As core, a write reaches the database (T_db_transients, when a test models it) and, with a persistent object cache
// (T_ext_cache), the shared cache (T_object_cache['transient']). T_on_set: name => callable run once right after that
// transient is written (another process acting between our write and our read-back).
function set_transient( $n, $v, $ttl = 0 ) {
	if ( ! empty( $GLOBALS['T_transient_fail'] ) ) { return false; }
	$GLOBALS['T_transients'][ $n ] = array( $v, (int) $ttl > 0 ? time() + (int) $ttl : PHP_INT_MAX, $ttl );
	unset( $GLOBALS['T_db_transients'][ $n ] );
	if ( ! empty( $GLOBALS['T_ext_cache'] ) ) { $GLOBALS['T_object_cache']['transient'][ $n ] = $v; }
	if ( isset( $GLOBALS['T_on_set'][ $n ] ) ) { $cb = $GLOBALS['T_on_set'][ $n ]; unset( $GLOBALS['T_on_set'][ $n ] ); $cb(); }
	return true;
}
// T_on_get: name => callable run once right after that transient is read (a concurrent request acting between a read and a write).
function get_transient( $n ) { $t = $GLOBALS['T_transients'][ $n ] ?? null; $v = ( null === $t || $t[1] < time() ) ? false : $t[0]; if ( isset( $GLOBALS['T_on_get'][ $n ] ) ) { $cb = $GLOBALS['T_on_get'][ $n ]; unset( $GLOBALS['T_on_get'][ $n ] ); $cb(); } return $v; }
function delete_transient( $n ) { $GLOBALS['T_transient_deletes'][] = array( $n, $GLOBALS['T_blog_id'] ?? 1 ); $had = false !== get_transient( $n ); unset( $GLOBALS['T_transients'][ $n ], $GLOBALS['T_db_transients'][ $n ], $GLOBALS['T_object_cache']['transient'][ $n ] ); return $had; }
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
	$GLOBALS['T_meta_delete_blocked'] = false; $GLOBALS['T_on_set_terms'] = null; $GLOBALS['T_term_fail'] = false; $GLOBALS['T_term_delete_fail'] = false; $GLOBALS['T_menu_delete_fail'] = false; $GLOBALS['T_theme_mods'] = array(); $GLOBALS['T_term_meta'] = array();
	$GLOBALS['T_cmeta'] = array(); $GLOBALS['T_comment_calls'] = array(); $GLOBALS['T_comment_blocked'] = false; $GLOBALS['T_comments_deleted'] = array(); $GLOBALS['T_new_comment_error'] = null;
	$GLOBALS['T_custom_css'] = null; $GLOBALS['T_css_error'] = null; $GLOBALS['T_css_calls'] = array(); $GLOBALS['T_css_rewrite'] = null;
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
	// core maps edit_css to unfiltered_html (kept direct as well, so a test can grant it alone).
	if ( 'edit_css' === $cap ) { return t_has_cap( 'edit_css' ) || t_has_cap( 'unfiltered_html' ); }
	// core maps add_post_meta and delete_post_meta exactly as edit_post_meta.
	if ( 'edit_post_meta' === $cap || 'delete_post_meta' === $cap || 'add_post_meta' === $cap ) { return null !== $id && t_post_cap( 'edit_post', (int) $id ) && ! is_protected_meta( (string) $key, 'post' ) && ! in_array( (string) $key, $GLOBALS['T_meta_auth_denied'] ?? array(), true ); }
	if ( null !== $id && ( 'read_post' === $cap || 'edit_post' === $cap || 'delete_post' === $cap ) ) { return t_post_cap( $cap, (int) $id ); }
	// The user meta caps as core's map_meta_cap (capabilities.php, 6.4.1): edit_user on oneself needs nothing more; on a network
	// edit_user/edit_users need manage_network_users, and only a super admin edits a super admin; promote_user is promote_users;
	// delete_user/delete_users on a network only a super admin; remove_user never oneself (unless super admin), else remove_users;
	// create_users on a network a super admin, or the network option add_new_users.
	$me = (int) ( $GLOBALS['T_user_id'] ?? 0 );
	$net = ! empty( $GLOBALS['T_multisite'] );
	if ( 'edit_user' === $cap || 'edit_users' === $cap ) {
		if ( 'edit_user' === $cap && null !== $id && (int) $id === $me && $me > 0 ) { return true; }
		if ( $net && ( ( ! is_super_admin() && 'edit_user' === $cap && null !== $id && is_super_admin( (int) $id ) ) || ! t_has_cap( 'manage_network_users' ) ) ) { return false; }
		return t_has_cap( 'edit_users' );
	}
	if ( 'promote_user' === $cap ) { return t_has_cap( 'promote_users' ); }
	if ( 'delete_user' === $cap || 'delete_users' === $cap ) { return ( ! $net || is_super_admin() ) && t_has_cap( 'delete_users' ); }
	if ( 'remove_user' === $cap ) { return ! ( null !== $id && (int) $id === $me && ! is_super_admin() ) && t_has_cap( 'remove_users' ); }
	if ( 'create_users' === $cap && $net ) { return ( is_super_admin() || get_site_option( 'add_new_users' ) ) && t_has_cap( 'create_users' ); }
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
	// wp_delete_file: the callbacks added with add_filter run, as core's (Polylang/WPML return '' to keep a shared file).
	if ( 'wp_delete_file' === $tag ) { foreach ( $GLOBALS['T_filters']['wp_delete_file'] ?? array() as $cb ) { $value = call_user_func( $cb, $value ); } return $value; }
	// widget_update_callback (core's WP_Widget::update_callback): the callbacks added with add_filter run, as core's.
	if ( 'widget_update_callback' === $tag ) { foreach ( $GLOBALS['T_filters']['widget_update_callback'] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$args ); } return $value; }
	// upgrader_pre_download (core's WP_Upgrader::download_package): the callbacks added with add_filter run, as core's.
	if ( 'upgrader_pre_download' === $tag ) { foreach ( $GLOBALS['T_filters']['upgrader_pre_download'] ?? array() as $cb ) { $value = call_user_func( $cb, $value, ...$args ); } return $value; }
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
		// As core: orderby 'date' (core's default, also 'date ID') sorts by the post_date column, 'modified' by post_modified,
		// order DESC by default, ties by id. A stand-in post without the local column uses its _gmt one (the same here).
		// Other orders stay newest id first.
		$ob = trim( (string) ( $a['orderby'] ?? 'date' ) );
		$col = ( '' === $ob || 'date' === $ob || 'date ID' === $ob ) ? 'post_date' : ( 'modified' === $ob ? 'post_modified' : '' );
		if ( '' !== $col ) {
			$asc = 'ASC' === strtoupper( (string) ( $a['order'] ?? 'DESC' ) );
			$v = function ( $p ) use ( $col ) { return (string) ( $p->$col ?? ( $p->{$col . '_gmt'} ?? '' ) ); };
			usort( $rows, function ( $x, $y ) use ( $asc, $v ) { $c = strcmp( $v( $y ), $v( $x ) ) ?: ( $y->ID <=> $x->ID ); return $asc ? -$c : $c; } );
		}
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
	// core: tax_input is set only for a registered taxonomy and only when the user holds its assign_terms capability
	// (silently skipped otherwise); terms of a flat taxonomy are names, created when missing (wp_set_post_terms).
	foreach ( (array) ( $f['tax_input'] ?? array() ) as $tax => $terms ) {
		$tobj = get_taxonomy( $tax );
		if ( ! $tobj || ! current_user_can( $tobj->cap->assign_terms ) ) { continue; }
		wp_set_object_terms( $id, is_array( $terms ) ? array_values( array_filter( $terms ) ) : $terms, $tax );
	}
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
	$GLOBALS['T_files_unlinked'] = array(); $GLOBALS['T_rename_fail'] = array(); $GLOBALS['T_rename_fail_re'] = ''; $GLOBALS['T_renames'] = array();
	unset( $GLOBALS['T_http_fn'], $GLOBALS['T_theme_root'], $GLOBALS['wp_filesystem'], $GLOBALS['T_copy_done'] );
	$GLOBALS['T_user_caps'] = array(); $GLOBALS['T_fs_fail'] = false; $GLOBALS['T_copy_fail'] = false; $GLOBALS['T_fs_copy_fail'] = false; $GLOBALS['T_copy_calls'] = array();
	$GLOBALS['T_copy_fail_to_re'] = ''; $GLOBALS['T_fs_delete_fail_re'] = ''; unset( $GLOBALS['T_copy_re_done'] );
}
// The media delete's file move, plugged here: a REAL rename (the tests use a real temporary uploads folder,
// T_upload_basedir), except that a source path listed in T_rename_fail fails as a refused rename would (false, nothing
// moved). T_renames lists every array( from, to ) asked. T_files_unlinked: the paths wp_delete_attachment found on disk.
if ( ! function_exists( 'd5b_wm_rename' ) ) {
	function d5b_wm_rename( string $from, string $to ): bool {
		$GLOBALS['T_renames'][] = array( $from, $to );
		if ( in_array( $from, $GLOBALS['T_rename_fail'] ?? array(), true ) ) { return false; }
		// T_rename_fail_re: a pattern; a source path matching it fails too (a put-back from a random-named folder).
		if ( '' !== ( $GLOBALS['T_rename_fail_re'] ?? '' ) && 1 === preg_match( $GLOBALS['T_rename_fail_re'], $from ) ) { return false; }
		return @rename( $from, $to );
	}
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
	// T_http_fn (optional): callable( url, args ) answering what T_http does not list (a scripted answer, a WP_Error, or null).
	$r = $GLOBALS['T_http'][ $url ] ?? ( isset( $GLOBALS['T_http_fn'] ) ? call_user_func( $GLOBALS['T_http_fn'], $url, $args ) : null )
		?? new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host (stand-in: no scripted answer).' );
	if ( $r instanceof WP_Error ) { return $r; }
	$body = (string) ( $r['body'] ?? '' );
	if ( isset( $args['limit_response_size'] ) ) { $body = substr( $body, 0, (int) $args['limit_response_size'] ); }
	if ( ! empty( $args['stream'] ) ) { file_put_contents( $args['filename'], $body ); $body = ''; }
	return array( 'headers' => array_change_key_case( (array) ( $r['headers'] ?? array() ), CASE_LOWER ), 'body' => $body, 'response' => array( 'code' => (int) ( $r['code'] ?? 200 ), 'message' => '' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
}
function wp_remote_retrieve_response_code( $r ) { return ( is_wp_error( $r ) || ! isset( $r['response']['code'] ) ) ? '' : (int) $r['response']['code']; }
function wp_remote_retrieve_headers( $r ) { return ( is_wp_error( $r ) || ! isset( $r['headers'] ) ) ? array() : $r['headers']; }
function wp_remote_retrieve_header( $r, $h ) { $all = wp_remote_retrieve_headers( $r ); return $all[ strtolower( $h ) ] ?? ''; }
function wp_remote_retrieve_body( $r ) { return ( is_wp_error( $r ) || ! isset( $r['body'] ) ) ? '' : $r['body']; }
// As core's home_url( $path ): the site address, and '/' . $path when a path is given.
function home_url( $path = '', $scheme = null ) { $u = 'https://x.example'; return ( is_string( $path ) && '' !== $path ) ? $u . '/' . ltrim( $path, '/' ) : $u; }

// ---- the maintenance stand-ins (#32, 2.4.0). t_media_reset() clears them.
//   T_theme_root     what get_theme_root returns (a real folder in a test; default ABSPATH/wp-content/themes)
//   T_user_caps      user id => capabilities of a user other than the current one (the current one has T_caps)
//   super admins     the network option site_admins (logins), as core's get_super_admins reads it
//   T_fs_fail        WP_Filesystem() fails (returns false, $wp_filesystem stays unset), as with FTP credentials needed
//   T_copy_fail      copy_dir copies the first file, then fails as core does on a file it cannot copy (WP_Error)
//   T_fs_copy_fail   $wp_filesystem->copy returns false;  T_copy_calls  every array( function, from, to )
function get_theme_root( $stylesheet_or_template = '' ) { return $GLOBALS['T_theme_root'] ?? ABSPATH . 'wp-content/themes'; }
// As core (capabilities.php): the logins in the network option site_admins, ['admin'] when it was never set.
function get_super_admins() { return get_site_option( 'site_admins', array( 'admin' ) ); }
// As core's is_super_admin( $user_id ): no id = the current user (none signed in: false); an id is looked up with
// get_userdata (unknown: false). On a multisite: the user's login is in get_super_admins(); on a single site: THAT user
// has delete_users (the current user: T_caps; another user: T_user_caps[ id ]).
function is_super_admin( $user_id = false ) {
	$id = $user_id ? (int) $user_id : (int) ( $GLOBALS['T_user_id'] ?? 0 );
	if ( $id < 1 ) { return false; }
	$user = get_userdata( $id );
	if ( $user_id && ! $user ) { return false; }
	$current = $id === (int) ( $GLOBALS['T_user_id'] ?? 0 );
	if ( ! empty( $GLOBALS['T_multisite'] ) ) {
		$login = $user ? $user->user_login : ( $current ? ( $GLOBALS['T_user_login'] ?? '' ) : '' );
		return '' !== (string) $login && in_array( $login, (array) get_super_admins(), true );
	}
	$caps = $current ? (array) ( $GLOBALS['T_caps'] ?? array() ) : (array) ( $GLOBALS['T_user_caps'][ $id ] ?? array() );
	return in_array( 'delete_users', $caps, true );
}
// The direct file system (class-wp-filesystem-direct.php), reduced to what the plugin uses: copy( from, to, overwrite, mode ).
class T_Filesystem {
	function copy( $from, $to, $overwrite = false, $mode = false ) {
		$GLOBALS['T_copy_calls'][] = array( 'wp_filesystem->copy', $from, $to );
		if ( ! empty( $GLOBALS['T_fs_copy_fail'] ) || ( ! $overwrite && file_exists( $to ) ) ) { return false; }
		return @copy( $from, $to );
	}
	// As WP_Filesystem_Direct::delete: a file is unlinked; a folder needs $recursive (else rmdir), then everything in it
	// (a link to a folder is listed as a folder and followed, as core's dirlist does), then the folder.
	function delete( $file, $recursive = false, $type = false ) {
		$GLOBALS['T_fs_deletes'][] = array( $file, $recursive );
		if ( empty( $file ) ) { return false; }
		$file = str_replace( '\\', '/', $file );
		// T_fs_delete_fail_re: a file whose path matches cannot be removed (a host's permissions): false, it stays.
		if ( '' !== ( $GLOBALS['T_fs_delete_fail_re'] ?? '' ) && is_file( $file ) && 1 === preg_match( $GLOBALS['T_fs_delete_fail_re'], $file ) ) { return false; }
		if ( 'f' === $type || is_file( $file ) ) { return @unlink( $file ); }
		if ( ! $recursive && is_dir( $file ) ) { return @rmdir( $file ); }
		$file = rtrim( $file, '/' ) . '/';
		$ok = true;
		foreach ( array_diff( (array) @scandir( $file ), array( '.', '..' ) ) as $f ) { if ( ! $this->delete( $file . $f, $recursive, is_dir( $file . $f ) ? 'd' : 'f' ) ) { $ok = false; } }
		if ( file_exists( $file ) && ! @rmdir( $file ) ) { $ok = false; }
		return $ok;
	}
}
// Removes a test folder (or file) with everything in it; a link is removed, never followed.
function t_plugins_rmtree( $p ) {
	if ( is_link( $p ) || is_file( $p ) ) { @unlink( $p ); return; }
	if ( ! is_dir( $p ) ) { return; }
	foreach ( array_diff( (array) scandir( $p ), array( '.', '..' ) ) as $f ) { t_plugins_rmtree( $p . '/' . $f ); }
	@rmdir( $p );
}
// As core: sets the global $wp_filesystem (direct) and returns true; with T_fs_fail it returns false and sets nothing.
function WP_Filesystem( $args = false, $context = false, $allow_relaxed_file_ownership = false ) {
	if ( ! empty( $GLOBALS['T_fs_fail'] ) ) { return false; }
	$GLOBALS['wp_filesystem'] = new T_Filesystem(); return true;
}
// As core's copy_dir (file.php): needs the global $wp_filesystem (an Error without it, as calling a method on null is);
// the destination folder is made, then every entry copied, folders recursively; a link is followed (core's dirlist reads a
// linked folder as 'd'). Real files are copied. T_copy_fail: fails after the first file with core's code.
function copy_dir( $from, $to, $skip_list = array() ) {
	if ( ! is_object( $GLOBALS['wp_filesystem'] ?? null ) ) { throw new Error( 'Call to a member function dirlist() on null' ); }
	$GLOBALS['T_copy_calls'][] = array( 'copy_dir', $from, $to );
	if ( ! is_dir( $from ) ) { return new WP_Error( 'dirlist_failed_copy_dir', 'Directory listing failed.', basename( $from ) ); }
	$from = rtrim( $from, '/\\' ) . '/'; $to = rtrim( $to, '/\\' ) . '/';
	if ( ! file_exists( $to ) && ! @mkdir( $to ) ) { return new WP_Error( 'mkdir_destination_failed_copy_dir', 'Could not create the destination directory.', basename( $to ) ); }
	foreach ( array_diff( (array) scandir( $from ), array( '.', '..' ) ) as $f ) {
		if ( in_array( $f, $skip_list, true ) ) { continue; }
		if ( is_dir( $from . $f ) ) {
			if ( ! is_dir( $to . $f ) && ! @mkdir( $to . $f ) ) { return new WP_Error( 'mkdir_failed_copy_dir', 'Could not create directory.', $to . $f ); }
			$r = copy_dir( $from . $f, $to . $f ); if ( is_wp_error( $r ) ) { return $r; }
		} else {
			if ( ! empty( $GLOBALS['T_copy_fail'] ) && ( $GLOBALS['T_copy_done'] = ( $GLOBALS['T_copy_done'] ?? 0 ) + 1 ) > 1 ) { return new WP_Error( 'copy_failed_copy_dir', 'Could not copy file.', $to . $f ); }
			// T_copy_fail_to_re: a copy whose destination matches fails the same way, after its first file (one copy of several).
			if ( '' !== ( $GLOBALS['T_copy_fail_to_re'] ?? '' ) && 1 === preg_match( $GLOBALS['T_copy_fail_to_re'], str_replace( '\\', '/', $to . $f ) ) && ( $GLOBALS['T_copy_re_done'] = ( $GLOBALS['T_copy_re_done'] ?? 0 ) + 1 ) > 1 ) { return new WP_Error( 'copy_failed_copy_dir', 'Could not copy file.', $to . $f ); }
			if ( ! @copy( $from . $f, $to . $f ) ) { return new WP_Error( 'copy_failed_copy_dir', 'Could not copy file.', $to . $f ); }
		}
	}
	return true;
}
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
	// As core's wp_delete_attachment_files: the file, each size, the original of a scaled image and the edit backups; on disk
	// too (wp_delete_file in the uploads folder), and a file that is not there is passed over without an error.
	$backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
	$names  = array_merge( array_column( (array) ( $meta['sizes'] ?? array() ), 'file' ), is_string( $meta['original_image'] ?? null ) ? array( $meta['original_image'] ) : array(), is_array( $backup ) ? array_column( $backup, 'file' ) : array() );
	foreach ( array_merge( array( $rel ), array_map( function ( $s ) use ( $rel ) { return dirname( $rel ) . '/' . $s; }, $names ) ) as $f ) {
		if ( '' === $f ) { continue; }
		unset( $GLOBALS['T_files'][ $f ] ); $GLOBALS['T_files_deleted'][] = $f;
		$disk = wp_upload_dir()['basedir'] . '/' . $f;
		// As core's wp_delete_file: a filter that returns '' keeps the file.
		if ( is_file( $disk ) && '' !== (string) apply_filters( 'wp_delete_file', $disk ) ) { @unlink( $disk ); $GLOBALS['T_files_unlinked'][] = $f; }
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
	// core fires the set_object_terms action here: a plugin hooked on it may change the terms again (T_on_set_terms).
	if ( is_callable( $GLOBALS['T_on_set_terms'] ?? null ) ) { call_user_func( $GLOBALS['T_on_set_terms'], (int) $object_id, $tax ); }
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

// ---- site settings (2.3.0, write-settings.php)
// core 6.4.1: sanitize_option (wp-includes/formatting.php) for the options the plugin writes or names, add_settings_error /
// get_settings_errors (wp-admin/includes/template.php), get_available_languages, the rewrite object (set_permalink_structure,
// flush_rules) and the server checks of wp-admin/includes/misc.php and file.php.
//   T_languages     the installed languages (get_available_languages)
//   T_mod_rewrite   got_mod_rewrite() (default true); T_iis7: iis7_supports_permalinks() (default false)
//   T_home_path     get_home_path() (default ABSPATH); T_subdomain: is_subdomain_install() (default false)
//   $wp_rewrite     a T_Rewrite: ->flushes lists every flush_rules call with the structure it saw
class T_Rewrite {
	public $permalink_structure = '';
	public $flushes = array();
	public $inits = 0;
	public function set_permalink_structure( $s ) {
		if ( $this->permalink_structure !== $s ) {
			update_option( 'permalink_structure', $s );
			$this->permalink_structure = (string) get_option( 'permalink_structure', '' );
			$this->inits++;
		}
	}
	public function flush_rules( $hard = true ) { $this->flushes[] = array( 'hard' => $hard, 'structure' => $this->permalink_structure ); }
}
function t_settings_reset() {
	$GLOBALS['wp_settings_errors'] = array(); $GLOBALS['T_languages'] = array();
	unset( $GLOBALS['T_mod_rewrite'], $GLOBALS['T_iis7'], $GLOBALS['T_home_path'], $GLOBALS['T_subdomain'] );
	$GLOBALS['wp_rewrite'] = new T_Rewrite();
}
t_settings_reset();
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['wp_settings_errors'][] = array( 'setting' => $setting, 'code' => $code, 'message' => $message, 'type' => $type );
}
function get_settings_errors( $setting = '', $sanitize = false ) {
	$all = (array) ( $GLOBALS['wp_settings_errors'] ?? array() );
	if ( '' === $setting ) { return $all; }
	return array_values( array_filter( $all, function ( $e ) use ( $setting ) { return $setting === $e['setting']; } ) );
}
function get_available_languages( $dir = null ) { return $GLOBALS['T_languages'] ?? array(); }
function got_mod_rewrite() { return $GLOBALS['T_mod_rewrite'] ?? true; }
function iis7_supports_permalinks() { return $GLOBALS['T_iis7'] ?? false; }
function got_url_rewrite() { return got_mod_rewrite() || iis7_supports_permalinks(); }
function get_home_path() { return $GLOBALS['T_home_path'] ?? ABSPATH; }
function is_subdomain_install() { return $GLOBALS['T_subdomain'] ?? false; }
function flush_rewrite_rules( $hard = true ) { global $wp_rewrite; if ( is_callable( array( $wp_rewrite, 'flush_rules' ) ) ) { $wp_rewrite->flush_rules( $hard ); } }
// core's esc_url character filter (the part sanitize_url applies to a path).
function t_sanitize_url( $s ) { return preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', (string) $s ); }
function sanitize_option( $option, $value ) {
	$original = $value; $error = null;
	// strip_invalid_text_for_column: invalid UTF-8 is dropped from text options.
	$utf8 = function ( $s ) { return is_string( $s ) && 1 !== preg_match( '//u', $s ) ? (string) iconv( 'UTF-8', 'UTF-8//IGNORE', $s ) : $s; };
	switch ( $option ) {
		case 'page_on_front': case 'page_for_posts': case 'start_of_week': case 'users_can_register':
			$value = absint( $value ); break;
		case 'posts_per_page':
			$value = (int) $value; if ( empty( $value ) ) { $value = 1; } if ( $value < -1 ) { $value = abs( $value ); } break;
		case 'default_comment_status':
			if ( '0' === (string) $value || '' === $value ) { $value = 'closed'; } break;
		case 'blogname': case 'blogdescription':
			$value = esc_html( $utf8( $value ) ); break;
		case 'blog_public':
			$value = null === $value ? 1 : (int) $value; break;
		case 'date_format': case 'time_format':
			$value = strip_tags( (string) $utf8( $value ) ); break;
		case 'gmt_offset':
			$value = is_numeric( $value ) ? preg_replace( '/[^0-9:.-]/', '', $value ) : ''; break;
		case 'siteurl': case 'home':
			if ( ! preg_match( '#http(s?)://(.+)#i', (string) $value ) ) { $error = 'The address you entered did not appear to be a valid URL. Please enter a valid URL.'; } break;
		case 'WPLANG':
			if ( ! in_array( $value, get_available_languages(), true ) && ! empty( $value ) ) { $value = get_option( $option ); } break;
		case 'timezone_string':
			if ( ! in_array( $value, timezone_identifiers_list( DateTimeZone::ALL_WITH_BC ), true ) && ! empty( $value ) ) { $error = 'The timezone you have entered is not valid. Please select a valid timezone.'; } break;
		case 'permalink_structure':
			$value = str_replace( 'http://', '', t_sanitize_url( $utf8( $value ) ) );
			if ( '' !== $value && ! preg_match( '/%[^\/%]+%/', $value ) ) { $error = 'A structure tag is required when using custom permalinks. <a href="https://wordpress.org/documentation/article/customize-permalinks/#choosing-your-permalink-structure">Learn more</a>'; }
			break;
	}
	if ( null !== $error ) {
		$value = get_option( $option );
		add_settings_error( $option, "invalid_{$option}", $error );
	}
	// apply_filters( "sanitize_option_{$option}", ... ): the callbacks added with add_filter run, as core's.
	foreach ( $GLOBALS['T_filters'][ "sanitize_option_{$option}" ] ?? array() as $cb ) { $value = call_user_func( $cb, $value, $option, $original ); }
	return $value;
}

// ---- appearance (2.3.0, write-appearance.php)
// core 6.4.1: theme mods (T_theme_mods, shared with the menu stand-ins above), the Custom CSS post and its helpers.
//   T_custom_css       the custom_css post of the active stylesheet (object: ID, post_content, post_modified_gmt) or unset
//   T_css_error        a WP_Error wp_update_custom_css_post returns instead of writing
//   T_css_calls        every css given to wp_update_custom_css_post
//   T_css_rewrite      a callable the css passes through before it is stored (the update_custom_css_data filter)
// edit_css is mapped as core maps it (to unfiltered_html), in current_user_can above.
if ( ! function_exists( 'get_stylesheet' ) ) {
	// As core: the stylesheet option (switch_theme writes it); 'teststyle' when it was never set.
	function get_stylesheet() { return isset( $GLOBALS['T_theme'] ) && is_object( $GLOBALS['T_theme'] ) ? $GLOBALS['T_theme']->get_stylesheet() : (string) get_option( 'stylesheet', 'teststyle' ); }
}
function get_theme_mods() { return $GLOBALS['T_theme_mods'] ?? array(); }
function remove_theme_mod( $name ) {
	if ( ! empty( $GLOBALS['T_theme_mod_fail'] ) ) { return; }
	unset( $GLOBALS['T_theme_mods'][ $name ] );
}
function wp_get_custom_css_post( $stylesheet = '' ) { $p = $GLOBALS['T_custom_css'] ?? null; return is_object( $p ) ? $p : null; }
// As core: the post's content through the wp_get_custom_css filter (T_custom_css_filter, a callable, stands for it).
function wp_get_custom_css( $stylesheet = '' ) {
	$p   = wp_get_custom_css_post( $stylesheet );
	$css = is_object( $p ) ? (string) ( $p->post_content ?? '' ) : '';
	return is_callable( $GLOBALS['T_custom_css_filter'] ?? null ) ? (string) call_user_func( $GLOBALS['T_custom_css_filter'], $css ) : $css;
}
function wp_update_custom_css_post( $css, $args = array() ) {
	$GLOBALS['T_css_calls'][] = $css;
	if ( ( $GLOBALS['T_css_error'] ?? null ) instanceof WP_Error ) { return $GLOBALS['T_css_error']; }
	if ( is_callable( $GLOBALS['T_css_rewrite'] ?? null ) ) { $css = call_user_func( $GLOBALS['T_css_rewrite'], $css ); }
	$p = wp_get_custom_css_post();
	if ( null === $p ) {
		$p = (object) array( 'ID' => $GLOBALS['T_next_id']++, 'post_type' => 'custom_css', 'post_title' => get_stylesheet(), 'post_status' => 'publish' );
		set_theme_mod( 'custom_css_post_id', $p->ID );
	}
	$p->post_content = (string) $css; $p->post_modified_gmt = gmdate( 'Y-m-d H:i:s' );
	$GLOBALS['T_custom_css'] = $p;
	return $p;
}

// ---- widgets (2.3.0, write-widgets.php)
// core 6.4.1 (widgets.php, class-wp-widget.php, class-wp-widget-factory.php, widgets/class-wp-widget-text.php,
// widgets/class-wp-widget-block.php):
//   the placement is the option sidebars_widgets (sidebar id => list of widget ids, plus array_version 3, which
//   wp_get_sidebars_widgets leaves out and wp_set_sidebars_widgets adds); an instance is widget_<id_base>[<number>] with
//   _multiwidget 1 in the same option; $wp_widget_factory->widgets holds the WP_Widget objects keyed by class name;
//   $wp_registered_sidebars the theme's sidebars.
//   T_frozen_options  option names update_option silently refuses (returns false, stores nothing), as a plugin's
//                     pre_update_option filter can
// Not core: wp_kses_post below removes only script/style/iframe tags (their text stays, as kses leaves it) and on*
// attributes, and normalises a bare & to &amp; as core does; core's allow-list removes much more. Block comments such as
// <!-- wp:paragraph --> survive it, as they survive core's kses. Enough to see that a widget's own update() ran.
// widget_update_callback (apply_filters above) runs the callbacks added with add_filter, with all four arguments.
// wp_get_sidebars_widgets: a test that defines its own (plugin-abilities-site-test.php, from T_sidebars) keeps it.
if ( ! function_exists( 'wp_get_sidebars_widgets' ) ) {
	function wp_get_sidebars_widgets( $deprecated = true ) {
		$s = get_option( 'sidebars_widgets', array() );
		if ( is_array( $s ) ) { unset( $s['array_version'] ); }
		foreach ( $GLOBALS['T_filters']['sidebars_widgets'] ?? array() as $cb ) { $s = call_user_func( $cb, $s ); }
		return $s;
	}
}
function wp_set_sidebars_widgets( $sidebars_widgets ) {
	if ( ! isset( $sidebars_widgets['array_version'] ) ) { $sidebars_widgets['array_version'] = 3; }
	update_option( 'sidebars_widgets', $sidebars_widgets );
}
function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) { $args = get_object_vars( $args ); }
	return is_array( $args ) ? array_merge( (array) $defaults, $args ) : (array) $defaults;
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $s ) {
		$s = preg_replace( '#</?(script|style|iframe)\b[^>]*>#i', '', (string) $s );
		$s = preg_replace( '#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $s );
		// As core's wp_kses_normalize_entities: an & that does not start a valid entity becomes &amp;.
		return preg_replace( '/&(?![A-Za-z][A-Za-z0-9]{1,31};|#\d{1,7};|#[xX][0-9A-Fa-f]{1,6};)/', '&amp;', $s );
	}
}
if ( ! class_exists( 'WP_Widget' ) ) {
	class WP_Widget {
		public $id_base; public $name; public $option_name; public $widget_options; public $control_options;
		public $number = false; public $id = false;
		public function __construct( $id_base, $name, $widget_options = array(), $control_options = array() ) {
			$this->id_base         = empty( $id_base ) ? preg_replace( '/(wp_)?widget_/', '', strtolower( get_class( $this ) ) ) : strtolower( $id_base );
			$this->name            = $name;
			$this->option_name     = 'widget_' . $this->id_base;
			$this->widget_options  = wp_parse_args( $widget_options, array( 'classname' => str_replace( '\\', '_', $this->option_name ), 'customize_selective_refresh' => false ) );
			$this->control_options = wp_parse_args( $control_options, array( 'id_base' => $this->id_base ) );
		}
		/** core: returns the new instance as given (a subclass sanitises). */
		public function update( $new_instance, $old_instance ) { return $new_instance; }
	}
	class WP_Widget_Text extends WP_Widget {
		public function __construct() { parent::__construct( 'text', 'Text', array( 'show_instance_in_rest' => true ) ); }
		/** core 6.4.1, as is. */
		public function update( $new_instance, $old_instance ) {
			$new_instance = wp_parse_args( $new_instance, array( 'title' => '', 'text' => '', 'filter' => false, 'visual' => null ) );
			$instance = $old_instance;
			$instance['title'] = sanitize_text_field( $new_instance['title'] );
			if ( current_user_can( 'unfiltered_html' ) ) { $instance['text'] = $new_instance['text']; } else { $instance['text'] = wp_kses_post( $new_instance['text'] ); }
			$instance['filter'] = ! empty( $new_instance['filter'] );
			if ( isset( $old_instance['filter'] ) && 'content' === $old_instance['filter'] ) { $instance['visual'] = true; }
			if ( 'content' === $new_instance['filter'] ) { $instance['visual'] = true; }
			if ( isset( $new_instance['visual'] ) ) { $instance['visual'] = ! empty( $new_instance['visual'] ); }
			if ( ! empty( $instance['visual'] ) ) { $instance['filter'] = true; }
			return $instance;
		}
	}
	class WP_Widget_Block extends WP_Widget {
		protected $default_instance = array( 'content' => '' );
		public function __construct() { parent::__construct( 'block', 'Block', array( 'show_instance_in_rest' => true ) ); }
		/** core 6.4.1, as is. */
		public function update( $new_instance, $old_instance ) {
			$instance = array_merge( $this->default_instance, $old_instance );
			if ( current_user_can( 'unfiltered_html' ) ) { $instance['content'] = $new_instance['content']; } else { $instance['content'] = wp_kses_post( $new_instance['content'] ); }
			return $instance;
		}
	}
	class WP_Widget_Factory {
		public $widgets = array();
		/** core: a class name (keyed by it) or an instance (keyed by its object hash). */
		public function register( $widget ) {
			if ( $widget instanceof WP_Widget ) { $this->widgets[ spl_object_hash( $widget ) ] = $widget; } else { $this->widgets[ $widget ] = new $widget(); }
		}
		public function get_widget_object( $id_base ) {
			foreach ( $this->widgets as $w ) { if ( $w->id_base === $id_base ) { return $w; } }
			return null;
		}
	}
}
/** A fresh factory with core's text and block widgets, no sidebars. */
function t_widgets_reset() {
	$GLOBALS['wp_widget_factory'] = new WP_Widget_Factory();
	$GLOBALS['wp_widget_factory']->register( 'WP_Widget_Text' );
	$GLOBALS['wp_widget_factory']->register( 'WP_Widget_Block' );
	$GLOBALS['wp_registered_sidebars'] = array();
	$GLOBALS['T_frozen_options'] = array();
}
t_widgets_reset();

// ---- WP-Cron (cron-list, cron-run). T_cron_array is core's cron array (timestamp => hook => key => event; a test may set
// false). T_cron_ops records, in order, every reschedule, unschedule and hook run. T_cron_hooks: hook => list of callables
// do_action_ref_array runs with the event's args. T_cron_fail: 'reschedule' / 'unschedule' => true makes that call fail
// (WP_Error when $wp_error, as core).
if ( ! defined( 'WP_CRON_LOCK_TIMEOUT' ) ) { define( 'WP_CRON_LOCK_TIMEOUT', 60 ); }
function t_cron_reset() { $GLOBALS['T_cron_array'] = array(); $GLOBALS['T_cron_ops'] = array(); $GLOBALS['T_cron_hooks'] = array(); $GLOBALS['T_cron_fail'] = array(); $GLOBALS['T_do_actions'] = array(); $GLOBALS['T_ext_cache'] = false; $GLOBALS['T_object_cache'] = array(); $GLOBALS['T_cache_forced'] = array(); $GLOBALS['T_db_transients'] = array(); $GLOBALS['T_on_set'] = array(); unset( $GLOBALS['T_cron_request_copy'] ); $GLOBALS['T_cron_fresh_reads'] = 0; }
// do_action records (T_do_actions: hook, args). The doing_cron lock as _get_cron_lock reads it, past the request's caches:
// with T_ext_cache on, wp_cache_get( ..., force ) reads T_object_cache[ group ][ key ] (T_cache_forced records the force
// flag); without it, $wpdb->get_row on the options table reads T_db_transients[ name ] when set (another process wrote the
// database), else the request's own T_transients.
function do_action( $hook, ...$args ) { $GLOBALS['T_do_actions'][] = array( $hook, $args ); }
if ( ! function_exists( '__return_true' ) ) { function __return_true() { return true; } }
function wp_using_ext_object_cache( $using = null ) { return ! empty( $GLOBALS['T_ext_cache'] ); }
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) { $GLOBALS['T_cache_forced'][] = $force; $found = isset( $GLOBALS['T_object_cache'][ $group ][ $key ] ); return $found ? $GLOBALS['T_object_cache'][ $group ][ $key ] : false; }
function t_db_transient( string $name ) {
	if ( array_key_exists( $name, $GLOBALS['T_db_transients'] ?? array() ) ) { return $GLOBALS['T_db_transients'][ $name ]; }
	return isset( $GLOBALS['T_transients'][ $name ] ) ? $GLOBALS['T_transients'][ $name ][0] : null;
}
t_cron_reset();
// T_cron_request_copy: when set, this request's cached copy of the cron option (alloptions), which another process may
// have changed since: _get_cron_array() answers it, the direct option read (wpdb) answers T_cron_array.
function _get_cron_array() { $c = array_key_exists( 'T_cron_request_copy', $GLOBALS ) ? $GLOBALS['T_cron_request_copy'] : ( $GLOBALS['T_cron_array'] ?? array() ); if ( ! is_array( $c ) ) { return array(); } unset( $c['version'] ); return $c; }
function _set_cron_array( $cron, $wp_error = false ) { $GLOBALS['T_cron_array'] = $cron; return true; }
function t_cron_intervals() { return array( 'hourly' => 3600, 'twicedaily' => 43200, 'daily' => 86400, 'weekly' => 604800 ); }
/** As core: the next run is the first one after now, counted from $timestamp in steps of the interval. */
function wp_reschedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
	$GLOBALS['T_cron_ops'][] = array( 'reschedule', $hook, (int) $timestamp, $recurrence );
	$interval = t_cron_intervals()[ $recurrence ] ?? 0;
	if ( ! empty( $GLOBALS['T_cron_fail']['reschedule'] ) || 0 === $interval ) { return $wp_error ? new WP_Error( 'invalid_schedule', 'Event schedule does not exist.' ) : false; }
	$now  = time();
	$next = (int) $timestamp >= $now ? $now + $interval : $now + ( $interval - ( ( $now - (int) $timestamp ) % $interval ) );
	$cron = is_array( $GLOBALS['T_cron_array'] ) ? $GLOBALS['T_cron_array'] : array();
	$cron[ $next ][ $hook ][ md5( serialize( $args ) ) ] = array( 'schedule' => $recurrence, 'args' => $args, 'interval' => $interval );
	ksort( $cron );
	$GLOBALS['T_cron_array'] = $cron;
	return true;
}
function wp_unschedule_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	$GLOBALS['T_cron_ops'][] = array( 'unschedule', $hook, (int) $timestamp );
	if ( ! empty( $GLOBALS['T_cron_fail']['unschedule'] ) ) { return $wp_error ? new WP_Error( 'could_not_set', 'The cron event list could not be saved.' ) : false; }
	$key  = md5( serialize( $args ) );
	$cron = is_array( $GLOBALS['T_cron_array'] ) ? $GLOBALS['T_cron_array'] : array();
	unset( $cron[ $timestamp ][ $hook ][ $key ] );
	if ( empty( $cron[ $timestamp ][ $hook ] ) ) { unset( $cron[ $timestamp ][ $hook ] ); }
	if ( empty( $cron[ $timestamp ] ) ) { unset( $cron[ $timestamp ] ); }
	$GLOBALS['T_cron_array'] = $cron;
	return true;
}
function do_action_ref_array( $hook, $args ) {
	$GLOBALS['T_cron_ops'][] = array( 'run', $hook, $args );
	foreach ( $GLOBALS['T_cron_hooks'][ $hook ] ?? array() as $cb ) { call_user_func_array( $cb, (array) $args ); }
}
/** Adds a scheduled event the way core stores it; returns its key. */
function t_cron_add( int $ts, string $hook, $schedule = false, array $args = array() ) {
	$key = md5( serialize( $args ) );
	$GLOBALS['T_cron_array'][ $ts ][ $hook ][ $key ] = $schedule ? array( 'schedule' => $schedule, 'args' => $args, 'interval' => t_cron_intervals()[ $schedule ] ?? 3600 ) : array( 'schedule' => false, 'args' => $args );
	ksort( $GLOBALS['T_cron_array'] );
	return $key;
}

// ---- block themes (2.3.0 Task 6): templates and global styles, as core 6.4.1 has them.
//   T_block_theme      wp_is_block_theme()
//   T_block_templates  the theme's FILE templates and parts: objects as core's _build_block_template_result_from_file
//                      builds them (id theme//slug, slug, theme, type, title, description, area, source 'theme', content ...)
//   A customised template is a post of type wp_template / wp_template_part named after the slug, with the wp_theme term
//   (by name) of its theme, as core's templates controller writes it; get_block_template(s) then answers source 'custom'
//   with wp_id and the post's content (core's _build_block_template_result_from_post) and the file one is hidden. A post
//   WITHOUT the wp_theme term is invisible, as in core.
//   T_theme_json       the theme layer of the global styles (theme.json); get_merged_data() lays the user layer (the
//                      wp_global_styles post of the active stylesheet) over it with array_replace_recursive.
// WP_Theme_JSON::remove_insecure_properties is a MODEL, not core's code: core keeps only known styles and settings and
// cleans each value through safecss_filter_attr. For styles the model keeps the structure and drops (1) any string value,
// at any depth, holding expression( or javascript: (case-insensitive), (2) "css" keys unless the user has edit_css (core
// keeps custom CSS only for edit_css). For settings it keeps what core's remove_insecure_settings keeps: the presets and
// blockGap / layout sizes only (see t_settings). Then an empty styles or settings is removed, as core does. Other
// top-level keys (version) pass through, as in core. Core runs it (through wp_filter_global_styles_post) only for users
// without unfiltered_html.
function t_templates_reset() {
	$GLOBALS['T_block_theme'] = false; $GLOBALS['T_block_templates'] = array();
	$GLOBALS['T_theme_json'] = array( 'version' => 2, 'settings' => array( 'color' => array( 'palette' => array( array( 'slug' => 'base', 'color' => '#ffffff' ) ) ) ), 'styles' => array( 'color' => array( 'text' => '#000000', 'background' => '#ffffff' ) ) );
	if ( class_exists( 'WP_Theme_JSON_Resolver', false ) ) { WP_Theme_JSON_Resolver::$user_custom_post_type_id = null; }
}
if ( ! function_exists( 'wp_is_block_theme' ) ) { function wp_is_block_theme() { return ! empty( $GLOBALS['T_block_theme'] ); } }
if ( ! defined( 'WP_TEMPLATE_PART_AREA_UNCATEGORIZED' ) ) { define( 'WP_TEMPLATE_PART_AREA_UNCATEGORIZED', 'uncategorized' ); }
/** The theme names (wp_theme terms) of a post. */
function t_post_themes( int $id ): array {
	$out = array();
	foreach ( (array) ( $GLOBALS['T_object_terms'][ $id ]['wp_theme'] ?? array() ) as $tid ) { $t = $GLOBALS['T_terms'][ (int) $tid ] ?? null; if ( $t ) { $out[] = (string) $t->name; } }
	return $out;
}
/** The customised template post of theme + slug + type, or null. */
function t_template_post( string $theme, string $slug, string $type ) {
	foreach ( $GLOBALS['T_posts'] ?? array() as $p ) {
		if ( $p->post_type === $type && (string) $p->post_name === $slug && 'trash' !== $p->post_status && in_array( $theme, t_post_themes( (int) $p->ID ), true ) ) { return $p; }
	}
	return null;
}
function t_template_from_post( $p, string $theme, $file ) {
	return (object) array( 'id' => $theme . '//' . $p->post_name, 'slug' => (string) $p->post_name, 'theme' => $theme, 'type' => (string) $p->post_type, 'title' => (string) $p->post_title,
		'description' => (string) $p->post_excerpt, 'source' => 'custom', 'origin' => (string) get_post_meta( (int) $p->ID, 'origin', true ), 'status' => (string) $p->post_status,
		'wp_id' => (int) $p->ID, 'content' => (string) $p->post_content, 'has_theme_file' => null !== $file, 'is_custom' => null === $file, 'area' => is_object( $file ) ? ( $file->area ?? null ) : null );
}
if ( ! function_exists( 'get_block_templates' ) ) {
	function get_block_templates( $q = array(), $type = 'wp_template' ) {
		$out = array(); $seen = array();
		foreach ( $GLOBALS['T_block_templates'] ?? array() as $t ) {
			if ( $t->type !== $type ) { continue; }
			$p = t_template_post( (string) $t->theme, (string) $t->slug, $type );
			$out[] = null !== $p ? t_template_from_post( $p, (string) $t->theme, $t ) : $t;
			if ( null !== $p ) { $seen[ (int) $p->ID ] = true; }
		}
		foreach ( $GLOBALS['T_posts'] ?? array() as $p ) {
			if ( $p->post_type !== $type || isset( $seen[ (int) $p->ID ] ) || 'trash' === $p->post_status ) { continue; }
			foreach ( t_post_themes( (int) $p->ID ) as $th ) { if ( $th === get_stylesheet() ) { $out[] = t_template_from_post( $p, $th, null ); } }
		}
		return $out;
	}
}
if ( ! function_exists( 'get_block_template' ) ) {
	function get_block_template( $id, $type = 'wp_template' ) {
		foreach ( get_block_templates( array(), $type ) as $t ) { if ( $t->id === $id ) { return $t; } }
		return null;
	}
}
if ( ! class_exists( 'WP_Theme_JSON', false ) ) {
	class WP_Theme_JSON {
		const LATEST_SCHEMA = 2;
		public static function remove_insecure_properties( $theme_json ) {
			foreach ( array( 'styles', 'settings' ) as $k ) {
				if ( ! isset( $theme_json[ $k ] ) ) { continue; }
				$clean = ! is_array( $theme_json[ $k ] ) ? array() : ( 'styles' === $k ? self::t_clean( $theme_json[ $k ], true ) : self::t_settings( $theme_json[ $k ] ) );
				if ( empty( $clean ) ) { unset( $theme_json[ $k ] ); } else { $theme_json[ $k ] = $clean; }
			}
			return $theme_json;
		}
		/**
		 * Settings as core 6.4.1's remove_insecure_settings keeps them, per settings node (the root and each
		 * settings.blocks.<name>): only the presets (PRESETS_METADATA paths, under an origin key default / blocks / theme /
		 * custom, entries with an unsafe value dropped) and the indirect properties spacing.blockGap (and its left / top)
		 * and layout.contentSize / wideSize, as strings. Everything else (custom, appearanceTools, color.custom,
		 * typography.fluid, spacing.units ...) is removed.
		 */
		private static function t_settings( array $s ): array {
			$out = self::t_settings_node( $s );
			foreach ( (array) ( $s['blocks'] ?? array() ) as $name => $node ) {
				if ( ! is_array( $node ) ) { continue; }
				$n = self::t_settings_node( $node );
				if ( ! empty( $n ) ) { $out['blocks'][ $name ] = $n; }
			}
			return $out;
		}
		private static function t_settings_node( array $s ): array {
			$out = array();
			foreach ( array( array( 'color', 'palette' ), array( 'color', 'gradients' ), array( 'color', 'duotone' ), array( 'typography', 'fontSizes' ), array( 'typography', 'fontFamilies' ), array( 'spacing', 'spacingSizes' ), array( 'shadow', 'presets' ) ) as $p ) {
				foreach ( array( 'default', 'blocks', 'theme', 'custom' ) as $origin ) {
					$list = $s[ $p[0] ][ $p[1] ][ $origin ] ?? null;
					if ( ! is_array( $list ) ) { continue; }
					$keep = array();
					foreach ( $list as $preset ) { if ( is_array( $preset ) && ! preg_match( '/expression\s*\(|javascript\s*:/i', (string) json_encode( $preset ) ) ) { $keep[] = $preset; } }
					if ( $keep ) { $out[ $p[0] ][ $p[1] ][ $origin ] = $keep; }
				}
			}
			foreach ( array( array( 'spacing', 'blockGap' ), array( 'spacing', 'blockGap', 'left' ), array( 'spacing', 'blockGap', 'top' ), array( 'layout', 'contentSize' ), array( 'layout', 'wideSize' ) ) as $p ) {
				$v = $s; foreach ( $p as $seg ) { $v = is_array( $v ) && array_key_exists( $seg, $v ) ? $v[ $seg ] : null; }
				if ( ! is_string( $v ) || preg_match( '/expression\s*\(|javascript\s*:/i', $v ) ) { continue; }
				if ( 3 === count( $p ) ) { $out[ $p[0] ][ $p[1] ][ $p[2] ] = $v; } else { $out[ $p[0] ][ $p[1] ] = $v; }
			}
			return $out;
		}
		private static function t_clean( array $a, bool $styles ): array {
			$out = array();
			foreach ( $a as $k => $v ) {
				if ( $styles && 'css' === $k && ! current_user_can( 'edit_css' ) ) { continue; }
				if ( is_array( $v ) ) { $v = self::t_clean( $v, $styles ); if ( empty( $v ) ) { continue; } }
				elseif ( is_string( $v ) && 1 === preg_match( '/expression\s*\(|javascript\s*:/i', $v ) ) { continue; }
				$out[ $k ] = $v;
			}
			return $out;
		}
	}
}
if ( ! class_exists( 'WP_Theme_JSON_Resolver', false ) ) {
	class T_Merged_Theme_JSON { private $d; function __construct( array $d ) { $this->d = $d; } function get_raw_data() { return $this->d; } }
	class WP_Theme_JSON_Resolver {
		public static $user_custom_post_type_id = null;
		/** The newest published wp_global_styles post of the active stylesheet as an array (get_object_vars); created when asked and absent. */
		public static function get_user_data_from_wp_global_styles( $theme, $create_post = false, $post_status_filter = array( 'publish' ) ) {
			$sheet = get_stylesheet();
			$rows  = $GLOBALS['T_posts'] ?? array();
			krsort( $rows );
			foreach ( $rows as $p ) {
				if ( 'wp_global_styles' === $p->post_type && in_array( $p->post_status, (array) $post_status_filter, true ) && in_array( $sheet, t_post_themes( (int) $p->ID ), true ) ) { return get_object_vars( $p ); }
			}
			if ( ! $create_post ) { return array(); }
			$GLOBALS['T_global_styles_created'] = ( $GLOBALS['T_global_styles_created'] ?? 0 ) + 1;
			$id = wp_insert_post( array( 'post_content' => '{"version": ' . WP_Theme_JSON::LATEST_SCHEMA . ', "isGlobalStylesUserThemeJSON": true }', 'post_status' => 'publish', 'post_title' => 'Custom Styles',
				'post_type' => 'wp_global_styles', 'post_name' => 'wp-global-styles-' . urlencode( $sheet ), 'tax_input' => array( 'wp_theme' => array( $sheet ) ) ), true );
			return is_wp_error( $id ) || ! $id ? array() : get_object_vars( get_post( $id ) );
		}
		public static function get_user_global_styles_post_id() {
			if ( null !== static::$user_custom_post_type_id ) { return static::$user_custom_post_type_id; }
			$cpt = static::get_user_data_from_wp_global_styles( null, true );
			if ( array_key_exists( 'ID', $cpt ) ) { static::$user_custom_post_type_id = $cpt['ID']; }
			return static::$user_custom_post_type_id;
		}
		public static function get_merged_data( $origin = 'custom' ) {
			$data = $GLOBALS['T_theme_json'] ?? array();
			$cpt  = static::get_user_data_from_wp_global_styles( null );
			$user = isset( $cpt['post_content'] ) ? json_decode( (string) $cpt['post_content'], true ) : null;
			if ( is_array( $user ) && ! empty( $user['isGlobalStylesUserThemeJSON'] ) ) { unset( $user['isGlobalStylesUserThemeJSON'] ); $data = array_replace_recursive( $data, $user ); }
			return new T_Merged_Theme_JSON( $data );
		}
	}
}

// ---- plugins and the update list (2.4.0, write-plugins.php). OPT-IN: define T_PLUGIN_STANDINS before requiring this file
// (other suites declare their own get_plugins, get_site_transient and the like). t_reset() clears them.
// core 6.4.1 wp-admin/includes/plugin.php, plugin-install.php, class-plugin-upgrader.php and update.php, reduced:
//   T_plugin_table        file => headers (Name, Version, Network): the fake plugins folder get_plugins() reads
//   active_plugins (option) and active_sitewide_plugins (network option): the active lists, kept as core keeps them
//   T_activate_fail       file => 'fatal' (the sandbox include throws an Error with core's output buffer still open: nothing
//                         activated) | 'output' (activated, THEN core's WP_Error unexpected_output) | 'requirements'
//                         (WP_Error plugin_php_incompatible before anything changes)
//   T_activate_calls      every array( plugin, redirect, network_wide, silent );  T_traps  the order of the named calls
//   T_plugins_api         slug => the object (or WP_Error) plugins_api returns; missing: core's WP_Error plugins_api_failed
//   T_install_result      what Plugin_Upgrader::install returns (default true); T_install_adds  file => headers added on success
//   T_install_calls       every package given to install();  T_plugins_cache_cleans  wp_clean_plugins_cache calls
//   T_site_transients     name => value;  T_site_transient_deletes  every name deleted;  T_update_checks  the update calls
//   T_fresh               name => what wp_version_check / wp_update_plugins / wp_update_themes store (the fresh answer)
//   T_file_mods_off       wp_is_file_mod_allowed() answers false (DISALLOW_FILE_MODS)
//   T_plugin_hooks        hook => list of callables run when activate_plugin / deactivate_plugins fire it (not silent);
//                         T_hook_log every such hook in order
// Update, rollback and delete (Task 3) need real files: with T_plugins_disk true, get_plugins() reads WP_PLUGIN_DIR as core
// does (the .php files at the top and one folder down, their Plugin Name / Version / Network headers) and keeps the answer
// until wp_clean_plugins_cache() (core's 'plugins' cache), so a caller that forgets to clean it sees the old list.
//   T_upgrade_result      file => what Plugin_Upgrader::bulk_upgrade gives for that plugin instead of updating it (false, WP_Error)
//   T_upgrade_calls       every list given to bulk_upgrade;  T_copies_at_upgrade  count( T_copy_calls ) when it ran
//   T_uninstalls          every plugin file uninstall_plugin ran for;  T_delete_calls  every list given to delete_plugins
//   T_fs_deletes          every array( path, recursive ) given to $wp_filesystem->delete
if ( defined( 'T_PLUGIN_STANDINS' ) ) {
	function t_plugins_reset() {
		$GLOBALS['T_plugin_table'] = array(); $GLOBALS['T_activate_fail'] = array(); $GLOBALS['T_activate_calls'] = array(); $GLOBALS['T_traps'] = array();
		$GLOBALS['T_plugins_api'] = array(); $GLOBALS['T_plugins_api_calls'] = array(); $GLOBALS['T_install_adds'] = array(); $GLOBALS['T_install_calls'] = array();
		unset( $GLOBALS['T_install_result'] );
		$GLOBALS['T_plugins_cache_cleans'] = 0; $GLOBALS['T_site_transients'] = array(); $GLOBALS['T_site_transient_deletes'] = array();
		$GLOBALS['T_update_checks'] = array(); $GLOBALS['T_fresh'] = array(); $GLOBALS['T_file_mods_off'] = false;
		$GLOBALS['T_plugin_hooks'] = array(); $GLOBALS['T_hook_log'] = array();
		$GLOBALS['T_plugins_disk'] = false; $GLOBALS['T_plugins_cache'] = null; $GLOBALS['T_upgrade_result'] = array(); $GLOBALS['T_upgrade_calls'] = array();
		$GLOBALS['T_copies_at_upgrade'] = null; $GLOBALS['T_uninstalls'] = array(); $GLOBALS['T_delete_calls'] = array(); $GLOBALS['T_fs_deletes'] = array();
		$GLOBALS['T_maintenance'] = array(); $GLOBALS['T_in_maintenance'] = false; $GLOBALS['T_opcache'] = array();
		$GLOBALS['T_wp_version'] = '6.9'; $GLOBALS['T_core_upgrade_calls'] = array(); $GLOBALS['T_core_upgrade_result'] = null; $GLOBALS['T_lang_upgrade_calls'] = array(); $GLOBALS['T_lang_upgrade_result'] = null;
		t_themes_reset();
	}
	// The headers core's get_plugin_data reads (the first 8 KB, "Plugin Name:" etc. at the start of a comment line); null without a name.
	function t_plugin_headers( $file ) {
		$h = (string) @file_get_contents( $file, false, null, 0, 8192 );
		$get = function ( $k ) use ( $h ) { return preg_match( '/^[ \t\/*#@]*' . preg_quote( $k, '/' ) . ':(.*)$/mi', $h, $m ) ? trim( $m[1] ) : ''; };
		$name = $get( 'Plugin Name' );
		if ( '' === $name ) { return null; }
		return array( 'Name' => $name, 'Version' => $get( 'Version' ), 'Network' => 'true' === strtolower( $get( 'Network' ) ) );
	}
	function get_plugins( $plugin_folder = '' ) {
		$out = array();
		if ( empty( $GLOBALS['T_plugins_disk'] ) ) {
			foreach ( $GLOBALS['T_plugin_table'] as $f => $h ) { $out[ $f ] = array_merge( array( 'Name' => $f, 'Version' => '', 'Network' => false ), $h ); }
			return $out;
		}
		if ( is_array( $GLOBALS['T_plugins_cache'] ) ) { return $GLOBALS['T_plugins_cache']; }
		$root = WP_PLUGIN_DIR;
		foreach ( (array) @scandir( $root ) as $f ) {
			if ( ! is_string( $f ) || '' === $f || '.' === $f[0] ) { continue; }
			if ( is_dir( $root . '/' . $f ) ) {
				foreach ( (array) @scandir( $root . '/' . $f ) as $g ) {
					if ( ! is_string( $g ) || '.' === ( $g[0] ?? '.' ) || '.php' !== substr( $g, -4 ) || ! is_file( $root . '/' . $f . '/' . $g ) ) { continue; }
					$h = t_plugin_headers( $root . '/' . $f . '/' . $g );
					if ( null !== $h ) { $out[ $f . '/' . $g ] = $h; }
				}
			} elseif ( '.php' === substr( $f, -4 ) ) {
				$h = t_plugin_headers( $root . '/' . $f );
				if ( null !== $h ) { $out[ $f ] = $h; }
			}
		}
		uasort( $out, function ( $a, $b ) { return strnatcasecmp( $a['Name'], $b['Name'] ); } );
		return $GLOBALS['T_plugins_cache'] = $out;
	}
	function is_plugin_active_for_network( $plugin ) {
		if ( ! is_multisite() ) { return false; }
		return isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ $plugin ] );
	}
	function is_plugin_active( $plugin ) { return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true ) || is_plugin_active_for_network( $plugin ); }
	function is_plugin_inactive( $plugin ) { return ! is_plugin_active( $plugin ); }
	function is_network_only_plugin( $plugin ) { return ! empty( get_plugins()[ $plugin ]['Network'] ); }
	// As core: a network activation on a multisite (asked for, or a Network: true plugin) goes to the network list; the plugin
	// must be in get_plugins(); an active plugin is left as it is (null); the sandbox include runs inside ob_start(), and a
	// plugin that throws leaves that buffer open (core does not catch); output after activating is WP_Error unexpected_output
	// with the plugin ALREADY active.
	function activate_plugin( $plugin, $redirect = '', $network_wide = false, $silent = false ) {
		$GLOBALS['T_traps'][] = 'activate_plugin';
		$GLOBALS['T_activate_calls'][] = array( $plugin, $redirect, $network_wide, $silent );
		$plugin = trim( $plugin );
		if ( is_multisite() && ( $network_wide || is_network_only_plugin( $plugin ) ) ) { $network_wide = true; $current = (array) get_site_option( 'active_sitewide_plugins', array() ); }
		else { $current = (array) get_option( 'active_plugins', array() ); }
		if ( ! isset( get_plugins()[ $plugin ] ) ) { return new WP_Error( 'plugin_not_found', 'Plugin file does not exist.' ); }
		$fail = $GLOBALS['T_activate_fail'][ $plugin ] ?? '';
		if ( 'requirements' === $fail ) { return new WP_Error( 'plugin_php_incompatible', '<p><strong>Error:</strong> Current PHP version does not meet minimum requirements for Test Plugin.</p>' ); }
		if ( ( $network_wide && ! isset( $current[ $plugin ] ) ) || ( ! $network_wide && ! in_array( $plugin, $current, true ) ) ) {
			ob_start();
			if ( 'fatal' === $fail ) { echo 'Fatal output'; throw new Error( 'Call to undefined function t_missing_function()' ); }
			// Not silent: activate_plugin and activate_{$plugin} (register_activation_hook) BEFORE the list is saved, inside
			// the buffer; activated_plugin after it. A hook that throws leaves the buffer open and the plugin inactive.
			if ( ! $silent ) { t_plugins_fire( 'activate_plugin', $plugin, $network_wide ); t_plugins_fire( "activate_{$plugin}", $network_wide ); }
			if ( $network_wide ) { $c = (array) get_site_option( 'active_sitewide_plugins', array() ); $c[ $plugin ] = time(); update_site_option( 'active_sitewide_plugins', $c ); }
			else { $c = (array) get_option( 'active_plugins', array() ); $c[] = $plugin; sort( $c ); update_option( 'active_plugins', $c ); }
			if ( ! $silent ) { t_plugins_fire( 'activated_plugin', $plugin, $network_wide ); }
			if ( 'output' === $fail ) { echo 'Notice: something printed'; }
			if ( ob_get_length() > 0 ) { $output = ob_get_clean(); return new WP_Error( 'unexpected_output', 'The plugin generated unexpected output.', $output ); }
			ob_end_clean();
		}
		return null;
	}
	// A plugin hook: recorded with do_action (T_do_actions), then the callbacks of T_plugin_hooks[ hook ] run, in order
	// (the stub's do_action only records). T_hook_log: every hook fired, in order.
	function t_plugins_fire( $hook, ...$args ) {
		$GLOBALS['T_hook_log'][] = $hook;
		do_action( $hook, ...$args );
		foreach ( (array) ( $GLOBALS['T_plugin_hooks'][ $hook ] ?? array() ) as $cb ) { $cb( ...$args ); }
	}
	// core's deactivate_plugins after the stand-in above recorded the call: per plugin, not silent: deactivate_plugin, then
	// the lists changed in memory, then deactivate_{$plugin} (register_deactivation_hook) and deactivated_plugin; the lists
	// are SAVED after the loop, so a hook that throws leaves the plugin active.
	function t_plugins_deactivate( $plugins, $network_wide, $silent = false ) {
		$net = is_multisite() ? (array) get_site_option( 'active_sitewide_plugins', array() ) : array();
		$cur = (array) get_option( 'active_plugins', array() );
		$do_blog = false; $do_network = false;
		foreach ( (array) $plugins as $p ) {
			$p = trim( $p );
			if ( ! is_plugin_active( $p ) ) { continue; }
			$net_deact = false !== $network_wide && is_plugin_active_for_network( $p );
			if ( ! $silent ) { t_plugins_fire( 'deactivate_plugin', $p, $net_deact ); }
			if ( false !== $network_wide ) {
				if ( is_plugin_active_for_network( $p ) ) { $do_network = true; unset( $net[ $p ] ); }
				elseif ( $network_wide ) { continue; }
			}
			if ( true !== $network_wide ) { $k = array_search( $p, $cur, true ); if ( false !== $k ) { $do_blog = true; unset( $cur[ $k ] ); } }
			if ( ! $silent ) { t_plugins_fire( "deactivate_{$p}", $net_deact ); t_plugins_fire( 'deactivated_plugin', $p, $net_deact ); }
		}
		if ( $do_blog ) { update_option( 'active_plugins', array_values( $cur ) ); }
		if ( $do_network ) { update_site_option( 'active_sitewide_plugins', $net ); }
	}
	function plugins_api( $action, $args = array() ) {
		$GLOBALS['T_traps'][] = 'plugins_api';
		$GLOBALS['T_plugins_api_calls'][] = array( $action, $args );
		if ( is_array( $args ) ) { $args = (object) $args; }
		$slug = (string) ( $args->slug ?? '' );
		return $GLOBALS['T_plugins_api'][ $slug ] ?? new WP_Error( 'plugins_api_failed', 'Plugin not found.' );
	}
	function wp_clean_plugins_cache( $clear_update_cache = true ) { $GLOBALS['T_plugins_cache_cleans']++; $GLOBALS['T_plugins_cache'] = null; if ( $clear_update_cache ) { delete_site_transient( 'update_plugins' ); } }
	// As core (plugin.php): a plugin with uninstall.php in its folder (for a single-file plugin, the plugins folder itself:
	// dirname is '.'), or one registered in the uninstall_plugins option.
	function is_uninstallable_plugin( $plugin ) {
		$file = plugin_basename( $plugin );
		$u    = (array) get_option( 'uninstall_plugins' );
		return isset( $u[ $file ] ) || file_exists( WP_PLUGIN_DIR . '/' . dirname( $file ) . '/uninstall.php' );
	}
	// As core: uninstall.php is included (the option entry dropped), else the registered callback runs on uninstall_{$file}.
	// (core also defines WP_UNINSTALL_PLUGIN; a constant cannot be defined twice in one test run, so it is not.)
	function uninstall_plugin( $plugin ) {
		$file = plugin_basename( $plugin );
		$GLOBALS['T_traps'][] = 'uninstall_plugin'; $GLOBALS['T_uninstalls'][] = $file;
		$u = (array) get_option( 'uninstall_plugins' );
		do_action( 'pre_uninstall_plugin', $plugin, $u );
		if ( file_exists( WP_PLUGIN_DIR . '/' . dirname( $file ) . '/uninstall.php' ) ) {
			if ( isset( $u[ $file ] ) ) { unset( $u[ $file ] ); update_option( 'uninstall_plugins', $u ); }
			include WP_PLUGIN_DIR . '/' . dirname( $file ) . '/uninstall.php';
			return true;
		}
		if ( isset( $u[ $file ] ) ) {
			$cb = $u[ $file ]; unset( $u[ $file ] ); update_option( 'uninstall_plugins', $u );
			if ( is_callable( $cb ) ) { $cb(); }
		}
		return null;
	}
	// As core's delete_plugins (plugin.php), reduced: the file system (WP_Filesystem; nothing done without it), then per plugin
	// the uninstall routine when it has one, then its folder (or its single file) removed with $wp_filesystem->delete; the
	// deleted plugins dropped from the update list. It does NOT clean the plugins cache (core does not). With the table
	// (not T_plugins_disk) the plugin is dropped from T_plugin_table.
	function delete_plugins( $plugins, $deprecated = '' ) {
		$GLOBALS['T_traps'][] = 'delete_plugins'; $GLOBALS['T_delete_calls'][] = $plugins;
		if ( empty( $plugins ) ) { return false; }
		if ( ! WP_Filesystem() ) { return null; }
		$fs = $GLOBALS['wp_filesystem']; $dir = rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' ) . '/';
		$errors = array();
		foreach ( $plugins as $plugin_file ) {
			if ( is_uninstallable_plugin( $plugin_file ) ) { uninstall_plugin( $plugin_file ); }
			do_action( 'delete_plugin', $plugin_file );
			$this_dir = rtrim( dirname( $dir . $plugin_file ), '/' ) . '/';
			$deleted  = ( strpos( $plugin_file, '/' ) && $this_dir !== $dir ) ? $fs->delete( $this_dir, true ) : $fs->delete( $dir . $plugin_file );
			do_action( 'deleted_plugin', $plugin_file, $deleted );
			if ( ! $deleted ) { $errors[] = $plugin_file; continue; }
			if ( empty( $GLOBALS['T_plugins_disk'] ) ) { unset( $GLOBALS['T_plugin_table'][ $plugin_file ] ); }
		}
		$current = get_site_transient( 'update_plugins' );
		if ( $current ) { foreach ( array_diff( $plugins, $errors ) as $f ) { unset( $current->response[ $f ] ); } set_site_transient( 'update_plugins', $current ); }
		if ( $errors ) { return new WP_Error( 'could_not_remove_plugin', 'Could not fully remove the plugin ' . implode( ', ', $errors ) . '.' ); }
		return true;
	}
	// The new version an update writes (T_plugins_disk): the old folder (or single file) removed, as clear_destination does,
	// then the plugin file with the new Version header and a changelog.txt saying so. With the table: the version changed.
	function t_plugins_replace( $plugin, $version ) {
		if ( empty( $GLOBALS['T_plugins_disk'] ) ) { $GLOBALS['T_plugin_table'][ $plugin ]['Version'] = $version; return array( 'destination_name' => dirname( $plugin ) ); }
		$name = (string) ( get_plugins()[ $plugin ]['Name'] ?? $plugin );
		$path = WP_PLUGIN_DIR . '/' . $plugin;
		if ( false !== strpos( $plugin, '/' ) ) {
			t_plugins_rmtree( dirname( $path ) );
			@mkdir( dirname( $path ), 0777, true );
			file_put_contents( dirname( $path ) . '/changelog.txt', $name . ' ' . $version . ' changes' );
		}
		file_put_contents( $path, "<?php\n/*\nPlugin Name: " . $name . "\nVersion: " . $version . "\n*/\n// the new code of " . $version . "\n" );
		return array( 'destination_name' => dirname( $plugin ) );
	}
	// As core (theme.php): the update list (when asked), the theme folders read again, every theme's cache dropped.
	function wp_clean_themes_cache( $clear_update_cache = true ) {
		$GLOBALS['T_themes_cache_cleans'] = ( $GLOBALS['T_themes_cache_cleans'] ?? 0 ) + 1;
		if ( $clear_update_cache ) { delete_site_transient( 'update_themes' ); }
		search_theme_directories( true );
		foreach ( wp_get_themes( array( 'errors' => null ) ) as $theme ) { $theme->cache_delete(); }
	}
	// As core's wp_clean_update_cache (update.php): the three update transients.
	function wp_clean_update_cache() { $GLOBALS['T_traps'][] = 'wp_clean_update_cache'; wp_clean_plugins_cache(); wp_clean_themes_cache(); delete_site_transient( 'update_core' ); }
	function get_site_transient( $n ) { return $GLOBALS['T_site_transients'][ $n ] ?? false; }
	function set_site_transient( $n, $v, $ttl = 0 ) { $GLOBALS['T_site_transients'][ $n ] = $v; return true; }
	function delete_site_transient( $n ) { $GLOBALS['T_site_transient_deletes'][] = $n; $had = isset( $GLOBALS['T_site_transients'][ $n ] ); unset( $GLOBALS['T_site_transients'][ $n ] ); return $had; }
	// The update checks store what wordpress.org would answer (T_fresh), as core stores the transient after asking.
	function wp_version_check() { $GLOBALS['T_update_checks'][] = 'wp_version_check'; if ( isset( $GLOBALS['T_fresh']['update_core'] ) ) { set_site_transient( 'update_core', $GLOBALS['T_fresh']['update_core'] ); } }
	function wp_update_plugins() { $GLOBALS['T_update_checks'][] = 'wp_update_plugins'; if ( isset( $GLOBALS['T_fresh']['update_plugins'] ) ) { set_site_transient( 'update_plugins', $GLOBALS['T_fresh']['update_plugins'] ); } }
	function wp_update_themes() { $GLOBALS['T_update_checks'][] = 'wp_update_themes'; if ( isset( $GLOBALS['T_fresh']['update_themes'] ) ) { set_site_transient( 'update_themes', $GLOBALS['T_fresh']['update_themes'] ); } }
	function get_core_updates( $options = array() ) { $t = get_site_transient( 'update_core' ); return is_object( $t ) && isset( $t->updates ) ? (array) $t->updates : array(); }
	// As core (update.php): the translations listed in the three update transients (update_core, update_plugins, update_themes).
	function wp_get_translation_updates() {
		$out = array();
		foreach ( array( 'update_core', 'update_plugins', 'update_themes' ) as $t ) {
			$v = get_site_transient( $t );
			if ( is_object( $v ) && ! empty( $v->translations ) ) { foreach ( (array) $v->translations as $tr ) { $out[] = (object) $tr; } }
		}
		return $out;
	}
	// 'version' is the WordPress version: T_wp_version (default 6.9); the core update stand-in changes it.
	if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return 'version' === $k ? (string) ( $GLOBALS['T_wp_version'] ?? '6.9' ) : ''; } }
	// As core: the template option (switch_theme writes it); 'teststyle' when it was never set.
	if ( ! function_exists( 'get_template' ) ) { function get_template() { return (string) get_option( 'template', 'teststyle' ); } }
	function wp_is_file_mod_allowed( $context ) { return empty( $GLOBALS['T_file_mods_off'] ); }
	if ( ! class_exists( 'Automatic_Upgrader_Skin', false ) ) {
		class Automatic_Upgrader_Skin { function get_upgrade_messages() { return array(); } function get_errors() { return new WP_Error(); } }
	}
	// As core's Plugin_Upgrader::install( $package ): true on success (the new plugin in the folder, the plugins cache cleaned),
	// else what the run left in ->result (false, or a WP_Error such as folder_exists). plugin_info() names the new plugin file.
	// As core's WP_Upgrader, reduced to maintenance_mode( $enable ): it writes or removes ABSPATH/.maintenance through the global
	// $wp_filesystem (an Error without it, as core's call on null). Recorded: T_maintenance (each true / false), T_traps
	// (maintenance_on / maintenance_off), T_in_maintenance (whether it is on now).
	if ( ! class_exists( 'WP_Upgrader', false ) ) {
		class WP_Upgrader {
			public $skin;
			function __construct( $skin = null ) { $this->skin = $skin; }
			function maintenance_mode( $enable = false ) {
				if ( ! is_object( $GLOBALS['wp_filesystem'] ?? null ) ) { throw new Error( 'Call to a member function abspath() on null' ); }
				$GLOBALS['T_maintenance'][] = (bool) $enable; $GLOBALS['T_traps'][] = $enable ? 'maintenance_on' : 'maintenance_off';
				$GLOBALS['T_in_maintenance'] = (bool) $enable;
			}
		}
	}
	// As core (functions.php, 6.2+): the opcode cache of every .php file in a folder; recorded in T_opcache.
	function wp_opcache_invalidate_directory( $dir ) { $GLOBALS['T_opcache'][] = str_replace( '\\', '/', $dir ); }
	function wp_opcache_invalidate( $filepath, $force = false ) { $GLOBALS['T_opcache'][] = str_replace( '\\', '/', $filepath ); return true; }
	if ( ! class_exists( 'Plugin_Upgrader', false ) ) {
		class Plugin_Upgrader extends WP_Upgrader {
			public $skin; public $result = array(); private $t_file = '';
			function __construct( $skin = null ) { $this->skin = $skin; }
			function install( $package, $args = array() ) {
				$GLOBALS['T_traps'][] = 'install';
				$GLOBALS['T_install_calls'][] = $package;
				$r = array_key_exists( 'T_install_result', $GLOBALS ) ? $GLOBALS['T_install_result'] : true;
				if ( true !== $r ) { $this->result = $r; return $r; }
				foreach ( $GLOBALS['T_install_adds'] as $f => $h ) { $GLOBALS['T_plugin_table'][ $f ] = $h; $this->t_file = $f; }
				$this->result = array( 'destination_name' => dirname( $this->t_file ) );
				wp_clean_plugins_cache( true );
				return true;
			}
			function plugin_info() { return '' === $this->t_file ? false : $this->t_file; }
			// As core's bulk_upgrade: per plugin, true when the update list has no offer for it (up to date), else the run's
			// result (T_upgrade_result, or the new version written by t_plugins_replace); a false stops the loop; the plugins
			// cache cleaned at the end (clear_update_cache, default true). Returns file => result.
			function bulk_upgrade( $plugins, $args = array() ) {
				$GLOBALS['T_traps'][] = 'bulk_upgrade'; $GLOBALS['T_upgrade_calls'][] = $plugins;
				$GLOBALS['T_copies_at_upgrade'] = count( $GLOBALS['T_copy_calls'] ?? array() );
				$current = get_site_transient( 'update_plugins' );
				$results = array();
				foreach ( $plugins as $plugin ) {
					if ( ! is_object( $current ) || ! isset( $current->response[ $plugin ] ) ) { $results[ $plugin ] = true; continue; }
					$r = $current->response[ $plugin ];
					$result = array_key_exists( $plugin, $GLOBALS['T_upgrade_result'] ) ? $GLOBALS['T_upgrade_result'][ $plugin ] : t_plugins_replace( $plugin, (string) $r->new_version );
					// 'wipe': the run cleared the old folder (clear_destination) and then failed to install the new one.
					if ( 'wipe' === $result ) { t_plugins_rmtree( WP_PLUGIN_DIR . '/' . ( false !== strpos( $plugin, '/' ) ? dirname( $plugin ) : $plugin ) ); $result = new WP_Error( 'copy_failed_copy_dir', 'Could not copy file.' ); }
					$results[ $plugin ] = $result;
					if ( false === $result ) { break; }
				}
				wp_clean_plugins_cache( $args['clear_update_cache'] ?? true );
				return $results;
			}
		}
	}

	// ---- themes (2.4.0 Task 4, write-themes.php), in the same opt-in block. core 6.4.1 wp-includes/theme.php,
	// class-wp-theme.php, wp-admin/includes/theme.php and class-theme-upgrader.php, reduced:
	//   T_themes_disk         wp_get_themes / wp_get_theme read the theme root (get_theme_root(): T_theme_root) as core does:
	//                         each folder not starting with a dot that holds a style.css; off: no themes at all
	//   the options stylesheet / template / current_theme: what get_stylesheet / get_template answer (switch_theme writes them)
	//   T_theme_cache         stylesheet => what WP_Theme read (core's per-request 'themes' cache group): kept until
	//                         cache_delete / wp_clean_themes_cache, so a caller that forgets to clean it reads the old headers
	//   T_theme_dirs          search_theme_directories' list (null: read the folder again)
	//   allowedthemes         the network option (network-enabled) and the site option, as WP_Theme::is_allowed reads them
	//   T_themes_api          slug => what themes_api returns (missing: core's WP_Error themes_api_failed); T_themes_api_calls
	//   T_theme_install_result  what Theme_Upgrader::install returns instead of installing (false, WP_Error)
	//   T_theme_install_adds  stylesheet => style.css headers (Theme Name, Version, Template, Requires PHP) written on install
	//   T_theme_parent_adds   stylesheet => headers of a parent theme the install fetches (check_parent_theme_filter)
	//   T_theme_install_calls every package given to install();  T_theme_downloads every package that passed upgrader_pre_download
	//   T_theme_upgrade_result  stylesheet => what Theme_Upgrader::bulk_upgrade gives instead of updating (false, WP_Error, 'wipe')
	//   T_theme_upgrade_calls every list given to bulk_upgrade (T_copies_at_upgrade as for plugins)
	//   T_switch_calls        every stylesheet given to switch_theme;  T_theme_delete_calls every stylesheet given to delete_theme
	//   T_theme_translations  stylesheet => locales installed; delete_theme removes them (T_theme_translation_deletes)
	function t_themes_reset() {
		$GLOBALS['T_themes_disk'] = false; $GLOBALS['T_theme_cache'] = array(); $GLOBALS['T_theme_dirs'] = null;
		$GLOBALS['T_themes_api'] = array(); $GLOBALS['T_themes_api_calls'] = array(); unset( $GLOBALS['T_theme_install_result'] );
		$GLOBALS['T_theme_install_adds'] = array(); $GLOBALS['T_theme_parent_adds'] = array(); $GLOBALS['T_theme_install_calls'] = array(); $GLOBALS['T_theme_downloads'] = array();
		$GLOBALS['T_theme_upgrade_result'] = array(); $GLOBALS['T_theme_upgrade_calls'] = array(); $GLOBALS['T_switch_calls'] = array(); $GLOBALS['T_theme_delete_calls'] = array();
		$GLOBALS['T_theme_translations'] = array(); $GLOBALS['T_theme_translation_deletes'] = array(); $GLOBALS['T_themes_cache_cleans'] = 0;
	}
	function t_theme_root() { return rtrim( str_replace( '\\', '/', get_theme_root() ), '/' ); }
	// What WP_Theme reads for $stylesheet, from the cache or the folder: null when the folder is not there (theme_not_found,
	// cached as such, as core caches the error), else the headers with error '' or theme_no_stylesheet (folder, no style.css).
	function t_theme_read( $stylesheet ) {
		if ( array_key_exists( $stylesheet, $GLOBALS['T_theme_cache'] ) ) { return $GLOBALS['T_theme_cache'][ $stylesheet ]; }
		$dir = t_theme_root() . '/' . $stylesheet;
		$r   = null;
		if ( ! empty( $GLOBALS['T_themes_disk'] ) && '' !== (string) $stylesheet && is_dir( $dir ) ) {
			$css = (string) @file_get_contents( $dir . '/style.css', false, null, 0, 8192 );
			$get = function ( $k ) use ( $css ) { return preg_match( '/^[ \t\/*#@]*' . preg_quote( $k, '/' ) . ':(.*)$/mi', $css, $m ) ? trim( $m[1] ) : ''; };
			$r = array( 'Name' => $get( 'Theme Name' ), 'Version' => $get( 'Version' ), 'Template' => $get( 'Template' ), 'RequiresPHP' => $get( 'Requires PHP' ), 'error' => '' );
			if ( ! is_file( $dir . '/style.css' ) ) { $r = array( 'Name' => '', 'Version' => '', 'Template' => '', 'RequiresPHP' => '', 'error' => 'theme_no_stylesheet' ); }
		}
		return $GLOBALS['T_theme_cache'][ $stylesheet ] = $r;
	}
	if ( ! class_exists( 'WP_Theme', false ) ) {
		class WP_Theme {
			private $s; private $d;
			function __construct( $stylesheet ) { $this->s = (string) $stylesheet; $this->d = t_theme_read( $this->s ); }
			// As core: false only for theme_not_found (no folder); a folder without style.css "exists" with an error.
			function exists() { return null !== $this->d; }
			function get( $k ) { return null === $this->d ? false : (string) ( $this->d[ $k ] ?? '' ); }
			function display( $k ) { return $this->get( $k ); }
			function get_stylesheet() { return $this->s; }
			function get_template() { return null !== $this->d && '' !== $this->d['Template'] ? $this->d['Template'] : $this->s; }
			function parent() { return null !== $this->d && '' !== $this->d['Template'] ? new WP_Theme( $this->d['Template'] ) : false; }
			function errors() {
				if ( null === $this->d ) { return new WP_Error( 'theme_not_found', 'The theme directory "' . $this->s . '" does not exist.' ); }
				if ( '' !== $this->d['error'] ) { return new WP_Error( $this->d['error'], 'Stylesheet is missing.' ); }
				if ( '' !== $this->d['Template'] && ! ( new WP_Theme( $this->d['Template'] ) )->exists() ) { return new WP_Error( 'theme_no_parent', 'The parent theme is missing. Please install the "' . $this->d['Template'] . '" parent theme.' ); }
				return false;
			}
			// As core: true on a single site; on a network, enabled for the network or for this site.
			function is_allowed( $check = 'both', $blog_id = null ) {
				if ( ! is_multisite() ) { return true; }
				if ( ( 'both' === $check || 'network' === $check ) && ! empty( self::get_allowed_on_network()[ $this->s ] ) ) { return true; }
				if ( ( 'both' === $check || 'site' === $check ) && ! empty( self::get_allowed_on_site( $blog_id )[ $this->s ] ) ) { return true; }
				return false;
			}
			static function get_allowed_on_network() { return (array) get_site_option( 'allowedthemes', array() ); }
			static function get_allowed_on_site( $blog_id = null ) { return (array) get_option( 'allowedthemes', array() ); }
			static function network_disable_theme( $stylesheets ) {
				$allowed = (array) get_site_option( 'allowedthemes', array() );
				foreach ( (array) $stylesheets as $s ) { unset( $allowed[ $s ] ); }
				update_site_option( 'allowedthemes', $allowed );
			}
			function cache_delete() { unset( $GLOBALS['T_theme_cache'][ $this->s ] ); }
			function is_block_theme() { return false; }
			function delete_pattern_cache() {}
		}
	}
	// As core: no name = the active theme.
	function wp_get_theme( $stylesheet = '', $theme_root = '' ) { return new WP_Theme( '' === (string) $stylesheet ? get_stylesheet() : $stylesheet ); }
	// As core (one level, the usual layout): the folders of the theme root that hold a style.css, skipping names that start
	// with a dot; kept until asked with $force.
	function search_theme_directories( $force = false ) {
		if ( is_array( $GLOBALS['T_theme_dirs'] ) && ! $force ) { return $GLOBALS['T_theme_dirs']; }
		$out = array();
		if ( ! empty( $GLOBALS['T_themes_disk'] ) ) {
			$root = t_theme_root();
			foreach ( (array) @scandir( $root ) as $d ) {
				if ( ! is_string( $d ) || '' === $d || '.' === $d[0] || ! is_dir( $root . '/' . $d ) || ! is_file( $root . '/' . $d . '/style.css' ) ) { continue; }
				$out[ $d ] = array( 'theme_file' => $d . '/style.css', 'theme_root' => $root );
			}
		}
		return $GLOBALS['T_theme_dirs'] = $out;
	}
	// As core: errors false (default) = the themes without errors, null = all, true = only the broken ones.
	function wp_get_themes( $args = array() ) {
		$errors = array_key_exists( 'errors', (array) $args ) ? $args['errors'] : false;
		$out = array();
		foreach ( array_keys( search_theme_directories() ) as $s ) {
			$t = new WP_Theme( $s );
			if ( null !== $errors && (bool) $errors !== (bool) $t->errors() ) { continue; }
			$out[ $s ] = $t;
		}
		return $out;
	}
	// As core (theme.php): the theme's Requires PHP against this PHP.
	function validate_theme_requirements( $stylesheet ) {
		$req = (string) wp_get_theme( $stylesheet )->get( 'RequiresPHP' );
		if ( '' !== $req && version_compare( PHP_VERSION, $req, '<' ) ) { return new WP_Error( 'theme_php_incompatible', '<strong>Error:</strong> Current PHP version does not meet minimum requirements for ' . wp_get_theme( $stylesheet )->display( 'Name' ) . '.' ); }
		return true;
	}
	// As core's switch_theme: requirements not met -> wp_die (the request ends: here an Error after the stub's record); no
	// check that the theme exists or is allowed (the caller's job); the options written, then the switch_theme action
	// (T_plugin_hooks callbacks run, so a test can make it throw).
	function switch_theme( $stylesheet ) {
		$GLOBALS['T_traps'][] = 'switch_theme'; $GLOBALS['T_switch_calls'][] = $stylesheet;
		$req = validate_theme_requirements( $stylesheet );
		if ( is_wp_error( $req ) ) { wp_die( $req ); throw new Error( 'wp_die ended the request' ); }
		update_option( 'theme_switch_menu_locations', get_theme_mod( 'nav_menu_locations' ) );
		$old = wp_get_theme(); $new = wp_get_theme( $stylesheet );
		update_option( 'template', $new->get_template() );
		update_option( 'stylesheet', $stylesheet );
		update_option( 'current_theme', $new->get( 'Name' ) );
		update_option( 'theme_switched', $old->get_stylesheet() );
		t_plugins_fire( 'switch_theme', $new->get( 'Name' ), $new, $old );
	}
	// As core's delete_theme (wp-admin/includes/theme.php), reduced: the file system (none: null, core prints a form), the
	// delete_theme action, the folder removed with $wp_filesystem->delete, deleted_theme, then (deleted) its translations
	// removed, on a network the theme disabled for the network, its cache and the update list dropped. Never refuses the
	// active theme itself (the callers do).
	function delete_theme( $stylesheet, $redirect = '' ) {
		$GLOBALS['T_traps'][] = 'delete_theme'; $GLOBALS['T_theme_delete_calls'][] = $stylesheet;
		if ( empty( $stylesheet ) ) { return false; }
		if ( ! WP_Filesystem() ) { return null; }
		$fs = $GLOBALS['wp_filesystem'];
		do_action( 'delete_theme', $stylesheet );
		$theme   = wp_get_theme( $stylesheet );
		$deleted = $fs->delete( t_theme_root() . '/' . $stylesheet . '/', true );
		do_action( 'deleted_theme', $stylesheet, $deleted );
		if ( ! $deleted ) { return new WP_Error( 'could_not_remove_theme', 'Could not fully remove the theme ' . $stylesheet . '.' ); }
		foreach ( (array) ( $GLOBALS['T_theme_translations'][ $stylesheet ] ?? array() ) as $loc ) { $GLOBALS['T_theme_translation_deletes'][] = $stylesheet . '-' . $loc; }
		unset( $GLOBALS['T_theme_translations'][ $stylesheet ] );
		if ( is_multisite() ) { WP_Theme::network_disable_theme( $stylesheet ); }
		$theme->cache_delete();
		delete_site_transient( 'update_themes' );
		return true;
	}
	function themes_api( $action, $args = array() ) {
		$GLOBALS['T_traps'][] = 'themes_api';
		$GLOBALS['T_themes_api_calls'][] = array( $action, $args );
		if ( is_array( $args ) ) { $args = (object) $args; }
		return $GLOBALS['T_themes_api'][ (string) ( $args->slug ?? '' ) ] ?? new WP_Error( 'themes_api_failed', 'Theme not found.' );
	}
	// A theme on disk: style.css with the headers, index.php, and the extra files (path in its folder => content).
	function t_theme_write( $stylesheet, array $h, array $extra = array() ) {
		$dir = t_theme_root() . '/' . $stylesheet;
		if ( ! is_dir( $dir ) ) { mkdir( $dir, 0777, true ); }
		$css = "/*\nTheme Name: " . ( $h['Name'] ?? $stylesheet ) . "\nVersion: " . ( $h['Version'] ?? '1.0' ) . "\n" . ( isset( $h['Template'] ) ? 'Template: ' . $h['Template'] . "\n" : '' ) . ( isset( $h['RequiresPHP'] ) ? 'Requires PHP: ' . $h['RequiresPHP'] . "\n" : '' ) . "*/\n";
		file_put_contents( $dir . '/style.css', $css );
		file_put_contents( $dir . '/index.php', "<?php // " . $stylesheet . ' ' . ( $h['Version'] ?? '1.0' ) . "\n" );
		foreach ( $extra as $rel => $body ) { if ( ! is_dir( dirname( $dir . '/' . $rel ) ) ) { mkdir( dirname( $dir . '/' . $rel ), 0777, true ); } file_put_contents( $dir . '/' . $rel, $body ); }
	}
	// As WP_Upgrader::download_package: the upgrader_pre_download filter first; a WP_Error from it stops the run.
	function t_theme_download( $package ) {
		$reply = apply_filters( 'upgrader_pre_download', false, $package, null, array( 'type' => 'theme', 'action' => 'install' ) );
		if ( is_wp_error( $reply ) ) { return $reply; }
		$GLOBALS['T_theme_downloads'][] = $package;
		return true;
	}
	if ( ! class_exists( 'Theme_Upgrader', false ) ) {
		class Theme_Upgrader extends WP_Upgrader {
			public $result = array();
			// As core: the package downloaded (upgrader_pre_download), the theme written into the theme root (never over a
			// folder that is there: folder_exists), then check_parent_theme_filter: a child whose parent is missing gets the
			// parent from themes_api's download_link (through the same filter); the themes cache cleaned. true or the error.
			function install( $package, $args = array() ) {
				$GLOBALS['T_traps'][] = 'theme_install'; $GLOBALS['T_theme_install_calls'][] = $package;
				$d = t_theme_download( $package );
				if ( is_wp_error( $d ) ) { return $this->result = $d; }
				if ( array_key_exists( 'T_theme_install_result', $GLOBALS ) ) { return $this->result = $GLOBALS['T_theme_install_result']; }
				foreach ( $GLOBALS['T_theme_install_adds'] as $s => $h ) {
					if ( file_exists( t_theme_root() . '/' . $s ) ) { return $this->result = new WP_Error( 'folder_exists', 'Destination folder already exists.' ); }
					t_theme_write( $s, $h ); $this->result = array( 'destination_name' => $s );
				}
				$info = $this->theme_info();
				if ( $info && $info->parent() && ! $info->parent()->exists() ) {
					$p   = $info->get( 'Template' );
					$api = themes_api( 'theme_information', array( 'slug' => $p, 'fields' => array( 'sections' => false, 'tags' => false ) ) );
					if ( is_object( $api ) && ! is_wp_error( $api ) && ! is_wp_error( t_theme_download( $api->download_link ?? '' ) ) && isset( $GLOBALS['T_theme_parent_adds'][ $p ] ) ) { t_theme_write( $p, $GLOBALS['T_theme_parent_adds'][ $p ] ); }
				}
				wp_clean_themes_cache( $args['clear_update_cache'] ?? true );
				return true;
			}
			function theme_info( $theme = null ) {
				if ( empty( $theme ) ) { if ( empty( $this->result['destination_name'] ) ) { return false; } $theme = $this->result['destination_name']; }
				$t = wp_get_theme( $theme ); $t->cache_delete();
				return wp_get_theme( $theme );
			}
			// As core's bulk_upgrade for themes: per theme, true when the update list has no offer, else the run's result
			// (T_theme_upgrade_result, or the new version written by t_theme_replace); a false stops the loop; the themes cache cleaned.
			function bulk_upgrade( $themes, $args = array() ) {
				$GLOBALS['T_traps'][] = 'theme_bulk_upgrade'; $GLOBALS['T_theme_upgrade_calls'][] = $themes;
				$GLOBALS['T_copies_at_upgrade'] = count( $GLOBALS['T_copy_calls'] ?? array() );
				$current = get_site_transient( 'update_themes' );
				$results = array();
				foreach ( $themes as $s ) {
					if ( ! is_object( $current ) || ! isset( $current->response[ $s ] ) ) { $results[ $s ] = true; continue; }
					$o = (array) $current->response[ $s ];
					$result = array_key_exists( $s, $GLOBALS['T_theme_upgrade_result'] ) ? $GLOBALS['T_theme_upgrade_result'][ $s ] : t_theme_replace( $s, (string) $o['new_version'] );
					if ( 'wipe' === $result ) { t_plugins_rmtree( t_theme_root() . '/' . $s ); $result = new WP_Error( 'copy_failed_copy_dir', 'Could not copy file.' ); }
					$results[ $s ] = $result;
					if ( false === $result ) { break; }
				}
				wp_clean_themes_cache( $args['clear_update_cache'] ?? true );
				return $results;
			}
		}
	}
	// The new version an update writes: the old folder removed (clear_destination), then style.css with the new Version (same
	// name and parent) and a changelog.txt saying so.
	function t_theme_replace( $stylesheet, $version ) {
		$old = t_theme_read( $stylesheet );
		t_plugins_rmtree( t_theme_root() . '/' . $stylesheet );
		$h = array( 'Name' => (string) ( $old['Name'] ?? $stylesheet ), 'Version' => $version );
		if ( '' !== (string) ( $old['Template'] ?? '' ) ) { $h['Template'] = $old['Template']; }
		t_theme_write( $stylesheet, $h, array( 'changelog.txt' => $h['Name'] . ' ' . $version . ' changes' ) );
		return array( 'destination_name' => $stylesheet );
	}
	// ---- core and translations (2.4.0 Task 5, write-core.php), in the same opt-in block.
	//   T_wp_version          the WordPress version get_bloginfo( 'version' ) answers (the update_core transient lists what is offered:
	//                         ->updates = [ { response: upgrade, current: version } ], ->translations = [ {...} ])
	//   T_core_upgrade_calls  every update object given to Core_Upgrader::upgrade;  T_core_upgrade_result  what it returns instead of
	//                         updating (a WP_Error, false);  T_lang_upgrade_calls / T_lang_upgrade_result likewise for the language packs
	// As core's Core_Upgrader::upgrade( $current ): the new version string on success (core 6.4.1 class-core-upgrader.php returns
	// the result of update_core(), the new version), else a WP_Error / false. The files are not touched: core's own update_core()
	// replaces the WHOLE of WordPress, nothing a plugin could keep a copy of.
	if ( ! class_exists( 'Core_Upgrader', false ) ) {
		class Core_Upgrader extends WP_Upgrader {
			function upgrade( $current, $args = array() ) {
				$GLOBALS['T_traps'][] = 'core_upgrade'; $GLOBALS['T_core_upgrade_calls'][] = $current; if ( is_callable( $GLOBALS['T_core_upgrade_hook'] ?? null ) ) { call_user_func( $GLOBALS['T_core_upgrade_hook'], $current ); }
				if ( $GLOBALS['T_core_upgrade_result'] instanceof Throwable ) { echo 'printed by the upgrader'; throw $GLOBALS['T_core_upgrade_result']; }
				if ( null !== $GLOBALS['T_core_upgrade_result'] ) { return $GLOBALS['T_core_upgrade_result']; }
				$GLOBALS['T_wp_version'] = (string) $current->current;
				return (string) $current->current;
			}
		}
	}
	// As core's Language_Pack_Upgrader::bulk_upgrade(): every pack in wp_get_translation_updates() installed (one result each),
	// the updates dropped from the three transients; false when there is nothing to do.
	if ( ! class_exists( 'Language_Pack_Upgrader', false ) ) {
		class Language_Pack_Upgrader extends WP_Upgrader {
			function bulk_upgrade( $language_updates = array(), $args = array() ) {
				$GLOBALS['T_traps'][] = 'lang_upgrade';
				if ( empty( $language_updates ) ) { $language_updates = wp_get_translation_updates(); }
				$GLOBALS['T_lang_upgrade_calls'][] = count( $language_updates );
				if ( empty( $language_updates ) ) { return false; }
				if ( null !== $GLOBALS['T_lang_upgrade_result'] ) { return $GLOBALS['T_lang_upgrade_result']; }
				foreach ( array( 'update_core', 'update_plugins', 'update_themes' ) as $t ) {
					$v = get_site_transient( $t );
					if ( is_object( $v ) && isset( $v->translations ) ) { $v->translations = array(); set_site_transient( $t, $v ); }
				}
				return array_fill( 0, count( $language_updates ), true );
			}
		}
	}
	t_plugins_reset();
}

// ---- users (2.4.0 Task 6, write-users.php). OPT-IN: define T_USER_STANDINS before requiring this file. Core 6.4.1 at the surface
// the plugin uses (wp-includes/user.php, pluggable.php, ms-functions.php, wp-admin/includes/user.php):
//   T_users        id => user object (ID, user_login, user_email, display_name, user_url, user_registered, roles = the roles on the
//                  current site); on a network a user is a member of the sites in T_user_blogs (t_add_user adds blog 1)
//   T_umeta        id => key => list of values (get_user_meta and friends; keys and values unslashed as core's metadata API does)
//   T_roles        role => array( name, capabilities ) (wp_roles()->roles);  T_editable_roles  null = all, else the role names the
//                  editable_roles filter leaves (get_editable_roles)
//   T_user_calls   every call: array( function, args ... );  T_generated  every password wp_generate_password returned
//   T_user_pass    id => the plain password the user was created with (wp_insert_user / wpmu_create_user)
//   T_user_mails   every email these functions send: array( to, what )
//   T_author_ids   user id => post ids: the author's items instead of those in T_posts (a 30,000-post user without the posts)
//   T_user_fail    function name => what it returns instead of acting (a WP_Error, false)
//   T_count_users  how many times count_users ran (it queries every time, as core: no cache)
if ( defined( 'T_USER_STANDINS' ) ) {
	function t_users_reset() {
		$GLOBALS['T_users'] = array(); $GLOBALS['T_umeta'] = array(); $GLOBALS['T_user_calls'] = array(); $GLOBALS['T_user_pass'] = array();
		$GLOBALS['T_generated'] = array(); $GLOBALS['T_user_mails'] = array(); $GLOBALS['T_author_ids'] = array(); $GLOBALS['T_user_fail'] = array();
		$GLOBALS['T_count_users'] = 0; $GLOBALS['T_next_user'] = 50; $GLOBALS['T_editable_roles'] = null; $GLOBALS['T_after_count'] = null; $GLOBALS['T_user_caps'] = array();
		// The roles with their capabilities as core's defaults hold them, in the part that matters here (user powers).
		$GLOBALS['T_roles'] = array(
			'administrator' => array( 'name' => 'Administrator', 'capabilities' => array_fill_keys( array( 'read', 'manage_options', 'list_users', 'create_users', 'edit_users', 'promote_users', 'delete_users', 'remove_users', 'edit_posts' ), true ) ),
			'editor'        => array( 'name' => 'Editor', 'capabilities' => array_fill_keys( array( 'read', 'edit_posts', 'edit_others_posts', 'publish_posts' ), true ) ),
			'author'        => array( 'name' => 'Author', 'capabilities' => array_fill_keys( array( 'read', 'edit_posts', 'publish_posts' ), true ) ),
			'contributor'   => array( 'name' => 'Contributor', 'capabilities' => array_fill_keys( array( 'read', 'edit_posts' ), true ) ),
			'subscriber'    => array( 'name' => 'Subscriber', 'capabilities' => array( 'read' => true ) ),
		);
	}
	// The user object get_userdata returns: core's WP_User in the part used here (set_role( '' ) takes every role away, add_role adds one).
	#[AllowDynamicProperties]
	class T_WP_User {
		public $ID = 0; public $user_login = ''; public $user_email = ''; public $display_name = ''; public $user_url = ''; public $user_registered = ''; public $roles = array();
		function __construct( array $f ) { foreach ( $f as $k => $v ) { $this->$k = $v; } }
		function set_role( $role ) { t_set_roles( $this->ID, '' === (string) $role ? array() : array( (string) $role ) ); }
		function add_role( $role ) { if ( '' !== (string) $role ) { t_set_roles( $this->ID, array_values( array_unique( array_merge( (array) $this->roles, array( (string) $role ) ) ) ) ); } }
	}
	// As core's user_can( user, cap ) for a plain capability: what the user's roles on the current site grant (T_roles), plus
	// T_user_caps[ id ] when a test gives a user more.
	function user_can( $user, $cap ) {
		$u = is_object( $user ) ? $user : get_userdata( (int) $user );
		if ( ! $u ) { return false; }
		if ( in_array( $cap, (array) ( $GLOBALS['T_user_caps'][ (int) $u->ID ] ?? array() ), true ) ) { return true; }
		foreach ( (array) $u->roles as $r ) { if ( ! empty( $GLOBALS['T_roles'][ $r ]['capabilities'][ $cap ] ) ) { return true; } }
		return false;
	}
	// A user as core keeps one: the row, the usual meta (nickname, names, description, the role and level of the current site as
	// wp_capabilities / wp_user_level, a session) and, on a network, membership of blog 1 when it has a role.
	function t_add_user( $id, $login, array $roles, array $f = array() ) {
		$GLOBALS['T_users'][ (int) $id ] = new T_WP_User( array_merge( array( 'ID' => (int) $id, 'user_login' => $login, 'user_email' => $login . '@example.org', 'display_name' => ucfirst( $login ),
			'user_url' => '', 'user_registered' => '2026-01-01 10:00:00', 'roles' => array_values( $roles ) ), $f ) );
		$GLOBALS['T_umeta'][ (int) $id ] = array( 'nickname' => array( $login ), 'first_name' => array( '' ), 'last_name' => array( '' ), 'description' => array( '' ),
			'wp_capabilities' => array( array_fill_keys( $roles, true ) ), 'wp_user_level' => array( in_array( 'administrator', $roles, true ) ? '10' : '0' ),
			'session_tokens' => array( array( 'tokhash' => array( 'expiration' => 1 ) ) ), 'rich_editing' => array( 'true' ) );
		if ( array() !== $roles ) { $GLOBALS['T_user_blogs'][ (int) $id ] = array( 1 ); }
		return (int) $id;
	}
	// The author's items: T_author_ids when a test set it, else the posts in T_posts by that author; ascending.
	function t_author_ids( $uid ) {
		if ( isset( $GLOBALS['T_author_ids'][ (int) $uid ] ) ) { return array_values( array_map( 'intval', $GLOBALS['T_author_ids'][ (int) $uid ] ) ); }
		$out = array(); foreach ( $GLOBALS['T_posts'] as $id => $p ) { if ( (int) $p->post_author === (int) $uid ) { $out[] = (int) $id; } }
		sort( $out ); return $out;
	}
	// UPDATE posts SET post_author = to WHERE post_author = from.
	function t_reassign_posts( $from, $to ) {
		foreach ( $GLOBALS['T_posts'] as $p ) { if ( (int) $p->post_author === (int) $from ) { $p->post_author = (int) $to; } }
		if ( isset( $GLOBALS['T_author_ids'][ (int) $from ] ) ) { $GLOBALS['T_author_ids'][ (int) $to ] = array_merge( $GLOBALS['T_author_ids'][ (int) $to ] ?? array(), $GLOBALS['T_author_ids'][ (int) $from ] ); unset( $GLOBALS['T_author_ids'][ (int) $from ] ); }
	}
	function t_set_roles( $id, array $roles ) {
		$GLOBALS['T_users'][ (int) $id ]->roles = array_values( $roles );
		$GLOBALS['T_umeta'][ (int) $id ]['wp_capabilities'] = array( array_fill_keys( $roles, true ) );
	}
	// As core (formatting.php), the parts that matter here: tags, accents, octets and entities out; strict keeps a-z 0-9 space _ . - @.
	function sanitize_user( $username, $strict = false ) {
		$u = strip_tags( (string) $username );
		if ( function_exists( 'remove_accents' ) ) { $u = remove_accents( $u ); }
		$u = preg_replace( '|%([a-fA-F0-9][a-fA-F0-9])|', '', $u ); $u = preg_replace( '/&.+?;/', '', $u );
		if ( $strict ) { $u = preg_replace( '|[^a-z0-9 _.\-@]|i', '', $u ); }
		return preg_replace( '|\s+|', ' ', trim( $u ) );
	}
	function is_email( $email ) { return false !== filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? $email : false; }
	// As core's esc_url_raw: a url with one of wp_allowed_protocols() (http, https, ftp, ftps, mailto, news, irc, gopher, nntp,
	// feed, telnet, mms, rtsp, sms, svn, tel, fax, xmpp, webcal, urn) is kept, another scheme (javascript:) gives ''; without a
	// scheme a domain-like start gets http://.
	function esc_url_raw( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || preg_match( '/[\s"<>]/', $url ) ) { return ''; }
		if ( preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $url, $m ) ) {
			$ok = array( 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn' );
			if ( in_array( strtolower( $m[1] ), $ok, true ) ) { return $url; }
			if ( ! preg_match( '/^[a-z0-9.\-]+\.[a-z]{2,}:\d/i', $url ) ) { return ''; }
		}
		return preg_match( '#^[a-z0-9.\-]+\.[a-z]{2,}([:/][^\s"<>]*)?$#i', $url ) ? 'http://' . $url : '';
	}
	// MySQL compares logins and emails without case (utf8mb4_*_ci).
	function get_user_by( $field, $value ) {
		if ( 'id' === $field || 'ID' === $field ) { return get_userdata( (int) $value ); }
		$col = 'login' === $field ? 'user_login' : ( 'email' === $field ? 'user_email' : null );
		if ( null === $col || '' === (string) $value ) { return false; }
		foreach ( $GLOBALS['T_users'] as $u ) { if ( 0 === strcasecmp( (string) $u->$col, (string) $value ) ) { return $u; } }
		return false;
	}
	function username_exists( $username ) { $u = get_user_by( 'login', $username ); return $u ? (int) $u->ID : false; }
	function email_exists( $email ) { $u = get_user_by( 'email', $email ); return $u ? (int) $u->ID : false; }
	// T_user_fail[ 'get_user_meta:<key>' ]: a database error reading that key (core's get_metadata then gives false).
	function get_user_meta( $id, $key = '', $single = false ) {
		if ( '' !== $key && isset( $GLOBALS['T_user_fail'][ 'get_user_meta:' . $key ] ) ) { return false; }
		$all = $GLOBALS['T_umeta'][ (int) $id ] ?? array();
		if ( '' === $key ) { return $all; }
		$vals = $all[ $key ] ?? array();
		return $single ? ( $vals[0] ?? '' ) : $vals;
	}
	// As core's update_metadata: key and value unslashed; no row yet -> added; else every row of the key set to the value
	// (false when that changes nothing).
	function update_user_meta( $id, $key, $value, $prev = '' ) {
		$GLOBALS['T_user_calls'][] = array( 'update_user_meta', (int) $id, wp_unslash( $key ) );
		if ( isset( $GLOBALS['T_user_fail']['update_user_meta'] ) ) { return $GLOBALS['T_user_fail']['update_user_meta']; }
		// T_user_fail[ 'update_user_meta:<key>' ]: a database error writing that key (nothing stored).
		if ( isset( $GLOBALS['T_user_fail'][ 'update_user_meta:' . wp_unslash( $key ) ] ) ) { return false; }
		$key = wp_unslash( $key ); $value = wp_unslash( $value );
		$rows = $GLOBALS['T_umeta'][ (int) $id ][ $key ] ?? null;
		if ( null === $rows ) { $GLOBALS['T_umeta'][ (int) $id ][ $key ] = array( $value ); return 1000 + count( $GLOBALS['T_umeta'][ (int) $id ] ); }
		$same = true; foreach ( $rows as $r ) { if ( maybe_serialize( $r ) !== maybe_serialize( $value ) ) { $same = false; } }
		if ( $same ) { return false; }
		$GLOBALS['T_umeta'][ (int) $id ][ $key ] = array_fill( 0, count( $rows ), $value );
		return true;
	}
	function add_user_meta( $id, $key, $value, $unique = false ) {
		$key = wp_unslash( $key ); $value = wp_unslash( $value );
		if ( $unique && isset( $GLOBALS['T_umeta'][ (int) $id ][ $key ] ) ) { return false; }
		$GLOBALS['T_umeta'][ (int) $id ][ $key ][] = $value;
		return 2000 + count( $GLOBALS['T_umeta'][ (int) $id ][ $key ] );
	}
	function delete_user_meta( $id, $key, $value = '' ) {
		if ( isset( $GLOBALS['T_user_fail']['delete_user_meta'] ) ) { return $GLOBALS['T_user_fail']['delete_user_meta']; }
		$key = wp_unslash( $key );
		if ( ! isset( $GLOBALS['T_umeta'][ (int) $id ][ $key ] ) ) { return false; }
		unset( $GLOBALS['T_umeta'][ (int) $id ][ $key ] );
		return true;
	}
	// As core: a fresh count of the current site's users by role (every call queries; there is no cache).
	function count_users( $strategy = 'time', $site_id = null ) {
		$GLOBALS['T_count_users']++;
		$out = array( 'total_users' => 0, 'avail_roles' => array() );
		foreach ( $GLOBALS['T_users'] as $u ) {
			if ( ! empty( $GLOBALS['T_multisite'] ) && ! is_user_member_of_blog( (int) $u->ID, get_current_blog_id() ) ) { continue; }
			$out['total_users']++;
			$roles = (array) $u->roles;
			if ( array() === $roles ) { $out['avail_roles']['none'] = ( $out['avail_roles']['none'] ?? 0 ) + 1; }
			foreach ( $roles as $r ) { $out['avail_roles'][ $r ] = ( $out['avail_roles'][ $r ] ?? 0 ) + 1; }
		}
		// T_after_count: a test's stand-in for another request changing the users right after this count was taken.
		if ( is_callable( $GLOBALS['T_after_count'] ?? null ) ) { call_user_func( $GLOBALS['T_after_count'], $GLOBALS['T_count_users'] ); }
		return $out;
	}
	function get_editable_roles() {
		$r = $GLOBALS['T_roles'];
		return is_array( $GLOBALS['T_editable_roles'] ) ? array_intersect_key( $r, array_flip( $GLOBALS['T_editable_roles'] ) ) : $r;
	}
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$v = 'mossylantern' . count( $GLOBALS['T_generated'] ) . 'quietharbor';
		$GLOBALS['T_generated'][] = $v;
		$GLOBALS['T_user_calls'][] = array( 'wp_generate_password', $length, $special_chars, $extra_special_chars );
		return $v;
	}
	// wp_insert_user without an ID: as core (user.php), the input is slashed (core unslashes the row fields, the metadata API the
	// meta); empty or too long login, a login or email already in use refused with core's codes; the role (or default_role) on
	// the current site (on a network that makes the user a member of it).
	function t_user_create( array $d ) {
		$login = trim( sanitize_user( (string) ( $d['user_login'] ?? '' ), true ) );
		if ( '' === $login ) { return new WP_Error( 'empty_user_login', 'Cannot create a user with an empty login name.' ); }
		if ( mb_strlen( $login ) > 60 ) { return new WP_Error( 'user_login_too_long', 'Username may not be longer than 60 characters.' ); }
		if ( username_exists( $login ) ) { return new WP_Error( 'existing_user_login', 'Sorry, that username already exists!' ); }
		$email = (string) wp_unslash( $d['user_email'] ?? '' );
		if ( '' !== $email && email_exists( $email ) ) { return new WP_Error( 'existing_user_email', 'Sorry, that email address is already used!' ); }
		$id = $GLOBALS['T_next_user']++;
		$role = array_key_exists( 'role', $d ) ? (string) $d['role'] : (string) get_option( 'default_role', 'subscriber' );
		$GLOBALS['T_users'][ $id ] = new T_WP_User( array( 'ID' => $id, 'user_login' => $login, 'user_email' => $email, 'display_name' => (string) wp_unslash( $d['display_name'] ?? $login ),
			'user_url' => (string) wp_unslash( $d['user_url'] ?? '' ), 'user_registered' => '2026-11-02 10:00:00', 'roles' => '' === $role ? array() : array( $role ) ) );
		$GLOBALS['T_umeta'][ $id ] = array();
		$GLOBALS['T_user_pass'][ $id ] = (string) ( $d['user_pass'] ?? '' );
		foreach ( array( 'nickname' => $login, 'first_name' => '', 'last_name' => '', 'description' => '' ) as $k => $def ) { update_user_meta( $id, $k, $d[ $k ] ?? $def ); }
		update_user_meta( $id, 'wp_capabilities', '' === $role ? array() : array( $role => true ) );
		if ( '' !== $role ) { $GLOBALS['T_user_blogs'][ $id ][] = get_current_blog_id(); }
		return $id;
	}
	function wp_insert_user( $userdata ) {
		$d = is_object( $userdata ) ? get_object_vars( $userdata ) : (array) $userdata;
		$GLOBALS['T_user_calls'][] = array( 'wp_insert_user', $d );
		if ( isset( $GLOBALS['T_user_fail']['wp_insert_user'] ) ) { return $GLOBALS['T_user_fail']['wp_insert_user']; }
		if ( ! empty( $d['ID'] ) ) { return t_user_apply( $d ); }
		return t_user_create( $d );
	}
	// wp_update_user as core: an unknown id refused; a new email checked (in use: existing_user_email) and core's own notice of
	// the change sent to the OLD address; the fields given set (slashed input); role set on the current site (set_role).
	function t_user_apply( array $d ) {
		$id = (int) ( $d['ID'] ?? 0 ); $u = get_userdata( $id );
		if ( ! $u ) { return new WP_Error( 'invalid_user_id', 'Invalid user ID.' ); }
		if ( isset( $d['user_email'] ) ) {
			$new = (string) wp_unslash( $d['user_email'] );
			if ( 0 !== strcasecmp( $new, (string) $u->user_email ) ) {
				$owner = email_exists( $new );
				if ( $owner && (int) $owner !== $id ) { return new WP_Error( 'existing_user_email', 'Sorry, that email address is already used!' ); }
			}
			if ( $new !== (string) $u->user_email ) { $GLOBALS['T_user_mails'][] = array( (string) $u->user_email, 'email_change_notice' ); }
			$u->user_email = $new;
		}
		foreach ( array( 'display_name' => 'display_name', 'user_url' => 'user_url' ) as $k => $col ) { if ( isset( $d[ $k ] ) ) { $u->$col = (string) wp_unslash( $d[ $k ] ); } }
		foreach ( array( 'first_name', 'last_name', 'description', 'nickname' ) as $k ) { if ( isset( $d[ $k ] ) ) { update_user_meta( $id, $k, $d[ $k ] ); } }
		if ( isset( $d['role'] ) ) { t_set_roles( $id, '' === (string) $d['role'] ? array() : array( (string) $d['role'] ) ); }
		return $id;
	}
	function wp_update_user( $userdata ) {
		$d = is_object( $userdata ) ? get_object_vars( $userdata ) : (array) $userdata;
		$GLOBALS['T_user_calls'][] = array( 'wp_update_user', $d );
		if ( isset( $GLOBALS['T_user_fail']['wp_update_user'] ) ) { return $GLOBALS['T_user_fail']['wp_update_user']; }
		return t_user_apply( $d );
	}
	// pluggable.php: 'user' or 'both' emails the user the link to set a password ('admin' tells the site admin only).
	function wp_new_user_notification( $user_id, $deprecated = null, $notify = '' ) {
		$GLOBALS['T_user_calls'][] = array( 'wp_new_user_notification', $user_id, $deprecated, $notify );
		$u = get_userdata( $user_id );
		if ( $u && in_array( $notify, array( 'user', 'both' ), true ) ) { $GLOBALS['T_user_mails'][] = array( (string) $u->user_email, 'set_password_link' ); }
	}
	// As core (user.php): the login unslashed and trimmed; with an @ it is looked up as an email first, then as a login.
	function retrieve_password( $user_login = null ) {
		$l = trim( (string) wp_unslash( (string) $user_login ) );
		$u = false;
		if ( '' !== $l ) { $u = strpos( $l, '@' ) ? ( get_user_by( 'email', $l ) ?: get_user_by( 'login', $l ) ) : get_user_by( 'login', $l ); }
		$GLOBALS['T_user_calls'][] = array( 'retrieve_password', $user_login, $u ? (int) $u->ID : 0 );
		if ( isset( $GLOBALS['T_user_fail']['retrieve_password'] ) ) { return $GLOBALS['T_user_fail']['retrieve_password']; }
		if ( '' === $l ) { return new WP_Error( 'empty_username', 'Please enter a username or email address.' ); }
		if ( ! $u ) { return new WP_Error( 'invalidcombo', 'There is no account with that username or email address.' ); }
		$GLOBALS['T_user_mails'][] = array( (string) $u->user_email, 'password_reset_link' );
		return true;
	}
	// wp-admin/includes/user.php: with a reassign id every item of the user (core's own query) gets that author; then on a network
	// the user leaves the current site, else the user and every meta row are deleted. false for an unknown user.
	function wp_delete_user( $id, $reassign = null ) {
		global $wpdb;
		$GLOBALS['T_user_calls'][] = array( 'wp_delete_user', $id, $reassign );
		if ( isset( $GLOBALS['T_user_fail']['wp_delete_user'] ) ) { return $GLOBALS['T_user_fail']['wp_delete_user']; }
		$id = (int) $id;
		if ( ! get_userdata( $id ) ) { return false; }
		if ( null !== $reassign ) {
			$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_author = %d", $id ) );
			t_reassign_posts( $id, (int) $reassign );
		} else {
			foreach ( t_author_ids( $id ) as $pid ) { unset( $GLOBALS['T_posts'][ $pid ] ); }
		}
		if ( ! empty( $GLOBALS['T_multisite'] ) ) { remove_user_from_blog( $id, get_current_blog_id() ); }
		else { unset( $GLOBALS['T_users'][ $id ], $GLOBALS['T_umeta'][ $id ] ); }
		return true;
	}
	// ms-functions.php: the user loses every role on the site (no longer a member); with a reassign id the user's items get that author.
	function remove_user_from_blog( $user_id, $blog_id = 0, $reassign = 0 ) {
		global $wpdb;
		$GLOBALS['T_user_calls'][] = array( 'remove_user_from_blog', $user_id, $blog_id, $reassign );
		if ( isset( $GLOBALS['T_user_fail']['remove_user_from_blog'] ) ) { return $GLOBALS['T_user_fail']['remove_user_from_blog']; }
		$user_id = (int) $user_id;
		if ( ! get_userdata( $user_id ) ) { return new WP_Error( 'user_does_not_exist', 'That user does not exist.' ); }
		$GLOBALS['T_user_blogs'][ $user_id ] = array_values( array_diff( $GLOBALS['T_user_blogs'][ $user_id ] ?? array(), array( (int) $blog_id ) ) );
		t_set_roles( $user_id, array() );
		if ( $reassign ) {
			$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_author = %d", $user_id ) );
			if ( ! empty( $post_ids ) ) { t_reassign_posts( $user_id, (int) $reassign ); }
		}
		return true;
	}
	// ms-functions.php: the login made strict and without spaces, the user created for the network with no role on any site;
	// false on failure.
	function wpmu_create_user( $user_name, $password, $email ) {
		$GLOBALS['T_user_calls'][] = array( 'wpmu_create_user', $user_name, $password, $email );
		if ( isset( $GLOBALS['T_user_fail']['wpmu_create_user'] ) ) { return $GLOBALS['T_user_fail']['wpmu_create_user']; }
		$user_name = preg_replace( '/\s+/', '', sanitize_user( $user_name, true ) );
		$id = t_user_create( array( 'user_login' => wp_slash( $user_name ), 'user_email' => wp_slash( $email ), 'user_pass' => $password ) );
		if ( is_wp_error( $id ) ) { return false; }
		t_set_roles( $id, array() ); $GLOBALS['T_user_blogs'][ $id ] = array();
		return $id;
	}
	function add_user_to_blog( $blog_id, $user_id, $role ) {
		$GLOBALS['T_user_calls'][] = array( 'add_user_to_blog', $blog_id, $user_id, $role );
		if ( isset( $GLOBALS['T_user_fail']['add_user_to_blog'] ) ) { return $GLOBALS['T_user_fail']['add_user_to_blog']; }
		if ( ! get_userdata( $user_id ) ) { return new WP_Error( 'user_does_not_exist', 'The requested user does not exist.' ); }
		$GLOBALS['T_user_blogs'][ (int) $user_id ][] = (int) $blog_id;
		t_set_roles( $user_id, array( (string) $role ) );
		return true;
	}
	t_users_reset();
}
