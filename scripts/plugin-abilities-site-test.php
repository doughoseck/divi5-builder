<?php
/**
 * The site read abilities and the secret scrubber (#32, Task 5).
 *
 *   php scripts/plugin-abilities-site-test.php
 *
 * Loads the real inc/ files (and parts/updates.php for its cache detection) with WordPress stand-ins (plugin-test-stubs.php plus
 * the users, plugins, themes, cron, widgets and template stand-ins below). Set D5B_PLUGIN_DIR to test another copy of
 * plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: nothing a secret is stored under leaves the site (the scan runs EVERY registered ability as an
 * administrator against planted secrets); settings-get answers only from its allow-list; the debug log is read from its end only,
 * masked line by line, and takes no path; cron-list never shows argument values; health-status never runs Site Health; the
 * plugin, theme and core lists never trigger an update check; users need list_users and show the email only then; on a network
 * the plugin, theme and core abilities need the network capability too.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.1.0' );
require __DIR__ . '/plugin-test-stubs.php';
$logdir = sys_get_temp_dir() . '/d5b-site-test-' . getmypid();
@mkdir( $logdir, 0777, true );
define( 'WP_CONTENT_DIR', $logdir );
const LOGFILE = WP_CONTENT_DIR . '/debug.log';

// ---- stand-ins the shared stubs do not have
class T_Theme {
	public $d;
	function __construct( array $d ) { $this->d = $d; }
	function get( $k ) { return $this->d[ $k ] ?? ''; }
	function get_stylesheet() { return $this->d['stylesheet']; }
	function get_template() { return $this->d['template']; }
	function parent() { return $this->d['parent'] ?? false; }
	function is_block_theme() { return ! empty( $this->d['block'] ); }
}
class WP_User_Query {
	private $r = array(); private $t = 0;
	function __construct( $a ) {
		$GLOBALS['T_user_queries'][] = $a;
		$all = array_values( $GLOBALS['T_users'] );
		if ( isset( $a['role'] ) ) { $all = array_values( array_filter( $all, function ( $u ) use ( $a ) { return in_array( $a['role'], $u->roles, true ); } ) ); }
		if ( isset( $a['search'] ) ) { $s = trim( $a['search'], '*' ); $all = array_values( array_filter( $all, function ( $u ) use ( $s ) { return false !== stripos( $u->user_login . ' ' . $u->user_email . ' ' . $u->display_name, $s ); } ) ); }
		$this->t = count( $all );
		$this->r = array_slice( $all, ( $a['paged'] - 1 ) * $a['number'], $a['number'] );
	}
	function get_results() { return $this->r; }
	function get_total() { return $this->t; }
}
// Anything that would check for updates, run a Site Health test or make an HTTP request records itself here.
class WP_Site_Health { function __construct() { $GLOBALS['T_traps'][] = 'site-health'; } static function get_instance() { $GLOBALS['T_traps'][] = 'site-health'; return new self(); } function get_tests() { $GLOBALS['T_traps'][] = 'site-health-tests'; return array(); } }
function wp_update_plugins() { $GLOBALS['T_traps'][] = 'wp_update_plugins'; }
function wp_update_themes() { $GLOBALS['T_traps'][] = 'wp_update_themes'; }
function wp_version_check() { $GLOBALS['T_traps'][] = 'wp_version_check'; }
function wp_remote_get() { $GLOBALS['T_traps'][] = 'wp_remote_get'; return array(); }
function wp_remote_post() { $GLOBALS['T_traps'][] = 'wp_remote_post'; return array(); }
function get_userdata( $id ) { return $GLOBALS['T_users'][ (int) $id ] ?? false; }
function get_plugins() { return $GLOBALS['T_plugins']; }
function get_site_transient( $n ) { return $GLOBALS['T_site_transients'][ $n ] ?? false; }
function wp_get_theme() { return $GLOBALS['T_theme']; }
function wp_get_themes() { return $GLOBALS['T_themes']; }
function get_stylesheet() { return $GLOBALS['T_theme']->get_stylesheet(); }
function get_theme_mods() { return $GLOBALS['T_theme_mods']; }
function wp_get_custom_css_post( $s = '' ) { return $GLOBALS['T_custom_css']; }
function _get_cron_array() { return $GLOBALS['T_cron_array']; }
function wp_get_sidebars_widgets() { return $GLOBALS['T_sidebars']; }
function wp_is_block_theme() { return $GLOBALS['T_block_theme']; }
function get_block_templates( $q = array(), $type = 'wp_template' ) { return array_values( array_filter( $GLOBALS['T_block_templates'], function ( $t ) use ( $type ) { return $t->type === $type; } ) ); }
function get_block_template( $id, $type = 'wp_template' ) { foreach ( $GLOBALS['T_block_templates'] as $t ) { if ( $t->id === $id && $t->type === $type ) { return $t; } } return null; }
function get_bloginfo( $k = '' ) { return 'version' === $k ? $GLOBALS['T_wp_version'] : ''; }
function wp_json_encode( $v ) { return json_encode( $v ); }

// Audit double: the writer records. The real audit.php (for the masker) is loaded after it, and its writer steps aside.
$GLOBALS['T_audit'] = array();
function d5b_audit_write( string $ability, array $args, string $status, int $duration_ms ): bool { $GLOBALS['T_audit'][] = compact( 'ability', 'args', 'status' ); return true; }
foreach ( array( 'inc/parts', 'inc/switches', 'inc/abilities/categories', 'inc/abilities/register', 'inc/abilities/audit', 'inc/abilities/scrub', 'inc/abilities/read-content', 'inc/abilities/read-structure', 'inc/abilities/read-site', 'parts/updates' ) as $f ) { require $dir . '/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 600 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const READER = array( 'read' );
const EDITOR = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'read_private_posts', 'edit_pages' );
const ADMIN  = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'read_private_posts', 'edit_pages', 'moderate_comments', 'manage_options', 'list_users', 'edit_theme_options' );

function theme_row( $slug, $name, $ver, $parent = false, $block = false ) { return new T_Theme( array( 'stylesheet' => $slug, 'template' => $parent ? $parent->get_stylesheet() : $slug, 'Name' => $name, 'Version' => $ver, 'parent' => $parent, 'block' => $block ) ); }
function fx_reset() {
	foreach ( array( 'T_users', 'T_user_queries', 'T_plugins', 'T_site_transients', 'T_theme_mods', 'T_cron_array', 'T_sidebars', 'T_block_templates', 'T_traps', 'wp_registered_sidebars' ) as $k ) { $GLOBALS[ $k ] = array(); }
	$GLOBALS['T_theme'] = theme_row( 'twentytest', 'Twenty Test', '1.2' ); $GLOBALS['T_themes'] = array( $GLOBALS['T_theme'] );
	$GLOBALS['T_custom_css'] = null; $GLOBALS['T_block_theme'] = false; $GLOBALS['T_wp_version'] = '6.9.1';
	unset( $GLOBALS['wp_fastest_cache'] );
	if ( is_file( LOGFILE ) ) { unlink( LOGFILE ); }
}
function site( array $caps, int $uid = 3 ) {
	t_reset(); fx_reset(); t_set_caps( $caps ); t_set_multisite( false ); $GLOBALS['T_user_id'] = $uid; $GLOBALS['T_audit'] = array();
	d5b_abilities_set_master( true, 'tester' );
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function code( $r ) { return is_wp_error( $r ) ? $r->code : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function last_audit() { return $GLOBALS['T_audit'] ? $GLOBALS['T_audit'][ count( $GLOBALS['T_audit'] ) - 1 ] : null; }
function mkuser( $id, $login, array $roles ) {
	return (object) array( 'ID' => $id, 'user_login' => $login, 'user_pass' => 'hunter2', 'user_email' => $login . '@example.org', 'display_name' => ucfirst( $login ), 'user_registered' => '2026-01-0' . $id . ' 10:00:00',
		'user_activation_key' => 'actkey-xyz', 'roles' => $roles, 'session_tokens' => array( 'SESSTOK' => array( 'expiration' => 1 ) ), '_application_passwords' => array( array( 'password' => 'APPPWD-PLANTED-123' ) ) );
}

$site_names = array( 'settings-get', 'templates-list', 'templates-get', 'global-styles-get', 'appearance-get', 'widgets-list', 'cache-status', 'health-status', 'cron-list', 'error-log', 'users-list', 'users-get', 'plugins-list', 'themes-list', 'core-version' );
$cat_of = array( 'settings-get' => 'settings', 'templates-list' => 'templates', 'templates-get' => 'templates', 'global-styles-get' => 'templates', 'appearance-get' => 'appearance', 'widgets-list' => 'widgets', 'cache-status' => 'cache',
	'health-status' => 'health', 'cron-list' => 'health', 'error-log' => 'health', 'users-list' => 'users', 'users-get' => 'users', 'plugins-list' => 'plugins', 'themes-list' => 'themes', 'core-version' => 'core' );

// ---- registration
echo "registration\n";
$defs = d5b_ability_defs();
$bad = array();
foreach ( $site_names as $x ) {
	$d = $defs[ 'divi5-builder/' . $x ] ?? null;
	if ( null === $d ) { $bad[] = "$x missing"; continue; }
	if ( $d['category'] !== $cat_of[ $x ] ) { $bad[] = "$x category " . $d['category']; }
	if ( array_key_exists( 'annotations', $d ) ) { $bad[] = "$x passes annotations"; }
	if ( ! is_callable( $d['handler'] ) || ! is_callable( $d['permission'] ) ) { $bad[] = "$x callables"; }
	if ( strlen( $d['description'] ) < 40 || ! preg_match( '/return/i', $d['description'] ) || ! preg_match( '/refus/i', $d['description'] ) ) { $bad[] = "$x description"; }
	foreach ( (array) ( $d['input_schema']['properties'] ?? array() ) as $k => $p ) { if ( empty( $p['description'] ) ) { $bad[] = "$x.$k undescribed"; } }
}
check( 'all ' . count( $site_names ) . ' site abilities are defined in the right category, read-only, lint-clean', array() === $bad, $bad );
site( ADMIN );
add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' );
add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );
t_fire( 'wp_abilities_api_categories_init' ); t_fire( 'wp_abilities_api_init' );
$ro = true; $missing = array();
foreach ( $site_names as $x ) {
	$m = $GLOBALS['T_abilities'][ 'divi5-builder/' . $x ] ?? null;
	if ( null === $m ) { $missing[] = $x; continue; }
	if ( array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) !== $m['meta']['annotations'] ) { $ro = false; }
}
check( 'every site ability is registered with WordPress, readonly and not destructive', array() === $missing && $ro && array() === $GLOBALS['T_ability_errors'], array( $missing, $GLOBALS['T_ability_errors'] ) );
$noprops = true;
foreach ( array( 'error-log', 'settings-get', 'cron-list', 'health-status', 'plugins-list' ) as $x ) { if ( ! empty( $defs[ 'divi5-builder/' . $x ]['input_schema']['properties'] ) ) { $noprops = false; } }
check( 'error-log, settings-get, cron-list, health-status and plugins-list declare no input properties at all', $noprops );
$loader = file_get_contents( $dir . '/divi5-builder.php' );
check( 'the loader lists scrub.php and read-site.php', false !== strpos( $loader, "'inc/abilities/scrub.php'" ) && false !== strpos( $loader, "'inc/abilities/read-site.php'" ) );

// ---- the scrubber
echo "the scrubber\n";
$k_each = array( 'user_pass', 'user_activation_key', 'session_tokens', '_application_passwords', 'smtp_password', 'mailserver_pass', 'my_secret', 'x_apikey', 'x_api_key', 'API_KEY', 'x_token', 'access_tokens', 'x_salt', 'x_auth_key', 'x_nonce', 'USER_PASS' );
$in = array(); foreach ( $k_each as $k ) { $in[ $k ] = 'v'; }
$in += array( 'title' => 'Keep', 'passenger' => 'Keep', 'compass_dir' => 'Keep', 7 => 'Keep', 'nested' => array( 'ok' => 1, 'api_key' => 'v', 'deeper' => array( array( 'token' => 'v', 'name' => 'Keep' ) ) ) );
$out = d5b_ability_scrub( $in );
$left = array_keys( $out );
sort( $left );
$want = array( 7, 'compass_dir', 'nested', 'passenger', 'title' ); sort( $want );
check( 'every alternative of the key rule removes its key (and nothing else goes)', $left === $want, $left );
check( 'the scrubber recurses through nested arrays and lists', array( 'ok' => 1, 'deeper' => array( array( 'name' => 'Keep' ) ) ) === $out['nested'], $out['nested'] );
foreach ( array( 'pass$ (smtp_password)' => 'smtp_password', 'secret' => 'my_secret', 'api_?key' => 'x_apikey', 'token' => 'x_token', 'salt' => 'x_salt', 'auth_key' => 'x_auth_key', 'nonce' => 'x_nonce', 'user_pass' => 'user_pass', 'user_activation_key' => 'user_activation_key', 'session_tokens' => 'session_tokens', '_application_passwords' => '_application_passwords' ) as $why => $key ) {
	check( "scrub: key rule '$why' removes $key", array( 'a' => 1 ) === d5b_ability_scrub( array( 'a' => 1, $key => 'v' ) ) );
}
class T_Json implements JsonSerializable { function jsonSerialize(): mixed { return array( 'x' => 1, 'user_pass' => 'z' ); } }
$o = d5b_ability_scrub( (object) array( 'name' => 'n', 'user_pass' => 'hunter2', 'inner' => (object) array( 'api_key' => 'k', 'ok' => 1 ) ) );
check( 'an object becomes an array and is scrubbed, nested objects too (never passed through)', array( 'name' => 'n', 'inner' => array( 'ok' => 1 ) ) === $o, $o );
check( 'a JsonSerializable object is scrubbed', array( 'x' => 1 ) === d5b_ability_scrub( new T_Json() ) );
check( 'scalars and null pass unchanged', 5 === d5b_ability_scrub( 5 ) && null === d5b_ability_scrub( null ) && 'x' === d5b_ability_scrub( 'x' ) && true === d5b_ability_scrub( true ) );
$deep = array(); $p = &$deep; for ( $i = 0; $i < 60; $i++ ) { $p['a'] = array(); $p = &$p['a']; } unset( $p );
check( 'absurdly deep nesting is cut, not recursed forever', false !== strpos( json_encode( d5b_ability_scrub( $deep ) ), 'too deep' ) );

// ---- the wrapper applies it
echo "the wrapper applies the scrubber\n";
site( ADMIN );
$snapshot = d5b_ability_registry();
d5b_ability_add( array( 'name' => 'divi5-builder/posts-probe', 'category' => 'posts', 'label' => 'p', 'description' => 'Returns a probe. Refuses nothing.',
	'handler' => function ( array $i ) { return array( 'ok' => 1, 'user_pass' => 'hunter2', 'obj' => (object) array( 'api_key' => 'k', 'fine' => 2 ) ); }, 'permission' => function ( array $i ) { return true; } ) );
$r = d5b_ability_run( 'divi5-builder/posts-probe', array() );
check( 'a result returned through d5b_ability_run is scrubbed once scrub.php is loaded (objects too)', array( 'ok' => 1, 'obj' => array( 'fine' => 2 ) ) === $r, $r );
d5b_ability_registry( $snapshot );

// ---- fixtures for the secret scan
echo "secret scan: every ability, as an administrator, against planted secrets\n";
site( ADMIN, 1 );
$GLOBALS['T_block_theme'] = true;
$GLOBALS['T_users'] = array( 1 => mkuser( 1, 'alice', array( 'administrator' ) ), 2 => mkuser( 2, 'bob', array( 'editor' ) ) );
$GLOBALS['T_options']['mailserver_pass'] = 'hunter2';
$GLOBALS['T_options']['smtp_login'] = 'hunter2';
$GLOBALS['T_options']['blogname'] = 'Test Site';
$GLOBALS['T_options']['admin_email'] = 'admin@example.org';
$GLOBALS['T_options']['active_plugins'] = array( 'a/a.php' );
$GLOBALS['T_plugins'] = array( 'a/a.php' => array( 'Name' => 'Alpha', 'Version' => '1.0' ), 'b/b.php' => array( 'Name' => 'Beta', 'Version' => '2.0' ) );
$GLOBALS['T_theme_mods'] = array( 'header_color' => '#ff0000', 'smtp_password' => 'sv-smtp', 'my_secret' => 'sv-secret', 'x_token' => 'sv-token', 'x_salt' => 'sv-salt', 'x_auth_key' => 'sv-authkey', 'x_nonce' => 'sv-nonce', 'x_apikey' => 'sv-apikey' );
$GLOBALS['T_custom_css'] = (object) array( 'post_content' => 'body { color: red; }' );
$GLOBALS['T_sidebars'] = array( 'sidebar-1' => array( 'text-3' ), 'wp_inactive_widgets' => array(), 'array_version' => 3 );
$GLOBALS['wp_registered_sidebars'] = array( 'sidebar-1' => array( 'name' => 'Main sidebar' ) );
$GLOBALS['T_options']['widget_text'] = array( 3 => array( 'title' => 'Hello widget', 'api_key' => 'sv-widget-key' ), '_multiwidget' => 1 );
$GLOBALS['T_cron_array'] = array( 86400 => array( 'send_mail_hook' => array( 'abc' => array( 'schedule' => 'hourly', 'args' => array( 'sv-cron-arg@example.org', 42 ), 'interval' => 3600 ) ) ) );
$GLOBALS['T_block_templates'] = array( (object) array( 'id' => 'theme//home', 'slug' => 'home', 'theme' => 'theme', 'type' => 'wp_template', 'title' => 'Home', 'source' => 'theme', 'status' => 'publish', 'has_theme_file' => true, 'is_custom' => false, 'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ) );
set_transient( 'health-check-site-status-result', json_encode( array( 'good' => 14, 'recommended' => 3, 'critical' => 1 ) ), 3600 );
file_put_contents( LOGFILE, "[28-Oct-2026 10:00:00 UTC] PHP Warning: smtp login failed password=hunter2 for alice\n[28-Oct-2026 10:00:01 UTC] PHP Notice: Authorization: Bearer sv-bearer-abc.def\n[28-Oct-2026 10:00:02 UTC] PHP Notice: fine line\n" );
$sp = t_add_post( array( 'post_title' => 'Scan post' ) ); t_add_meta( $sp, '_api_key', 'sv-postmeta' ); t_add_meta( $sp, 'color', 'blue' );
$pg = t_add_post( array( 'post_type' => 'page' ) );
t_add_type( 'event' ); $ev = t_add_post( array( 'post_type' => 'event' ) );
$att = t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png' ) ); t_add_meta( $att, '_wp_attached_file', '2026/10/a.png' );
$blk = t_add_post( array( 'post_type' => 'wp_block' ) );
$cat = t_add_term( 'category', 'News' ); $cm = t_add_comment( array( 'comment_post_ID' => $sp ) );
$rev = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $sp ) );
$GLOBALS['T_menus'][7] = (object) array( 'term_id' => 7, 'name' => 'Main', 'slug' => 'main', 'count' => 0 ); $GLOBALS['T_menu_items'][7] = array();
$inputs = array( 'posts-get' => array( 'id' => $sp ), 'posts-revisions' => array( 'id' => $sp ), 'pages-get' => array( 'id' => $pg ), 'cpt-list' => array( 'type' => 'event' ), 'cpt-get' => array( 'type' => 'event', 'id' => $ev ),
	'media-get' => array( 'id' => $att ), 'taxonomy-get' => array( 'taxonomy' => 'category', 'id' => $cat ), 'comments-get' => array( 'id' => $cm ), 'meta-get' => array( 'post_id' => $sp ), 'blocks-get' => array( 'id' => $blk ),
	'menus-items' => array( 'menu' => 7 ), 'revisions-list' => array( 'post_id' => $sp ), 'revisions-get' => array( 'id' => $rev ), 'revisions-compare' => array( 'from' => $rev, 'to' => $sp ), 'search' => array( 'query' => 'Scan' ),
	'users-get' => array( 'id' => 2 ), 'templates-get' => array( 'id' => 'theme//home' ) );

// the planted secrets are really there before the scrubber runs (so the scan can fail)
$raw_meta = d5b_rc_h_meta_get( array( 'post_id' => $sp ) );
check( 'planted: meta-get\'s own handler returns the secret meta key (the scrubber is what removes it)', is_array( $raw_meta ) && isset( $raw_meta['meta']['_api_key'] ), $raw_meta );
$raw_app = d5b_rs_h_appearance_get( array() );
check( 'planted: appearance-get\'s handler returns the secret theme mods', isset( $raw_app['theme_mods']['smtp_password'], $raw_app['theme_mods']['x_nonce'] ), $raw_app );
$raw_w = d5b_rs_h_widgets_list( array() );
check( 'planted: widgets-list\'s handler returns the widget instance with its api_key', 'sv-widget-key' === ( $raw_w['sidebars'][0]['widgets'][0]['settings']['api_key'] ?? null ), $raw_w );
check( 'planted: the debug log file holds hunter2', false !== strpos( (string) file_get_contents( LOGFILE ), 'hunter2' ) );

$forbidden_keys   = array( 'user_pass', 'user_activation_key', 'session_tokens', '_application_passwords', 'mailserver_pass', 'smtp_password', 'api_key', '_api_key', 'my_secret', 'x_token', 'x_salt', 'x_auth_key', 'x_nonce', 'x_apikey' );
$forbidden_values = array( 'hunter2', 'APPPWD-PLANTED-123', 'actkey-xyz', 'SESSTOK', 'sv-smtp', 'sv-secret', 'sv-token', 'sv-salt', 'sv-authkey', 'sv-nonce', 'sv-apikey', 'sv-widget-key', 'sv-postmeta', 'sv-bearer-abc', 'sv-cron-arg' );
$writes_before = count( $GLOBALS['T_writes'] );
$ran = 0; $leaks = array(); $noinput = array(); $errs = array(); $live = 0;
foreach ( array_keys( d5b_ability_defs() ) as $full ) {
	$short = substr( $full, strlen( 'divi5-builder/' ) );
	$in    = $inputs[ $short ] ?? array();
	$req   = d5b_ability_defs()[ $full ]['input_schema']['required'] ?? array();
	if ( array_diff( $req, array_keys( $in ) ) ) { $noinput[] = $short; continue; }
	$r = call( $short, $in );
	$ran++;
	if ( 'FORBIDDEN' === $r || is_wp_error( $r ) ) { $errs[] = $short . ':' . ( is_wp_error( $r ) ? $r->code : $r ); continue; }
	$json = json_encode( $r );
	if ( false === $json ) { $leaks[] = "$short: not JSON"; continue; }
	if ( strlen( $json ) > 20 ) { $live++; }
	foreach ( array_merge( $forbidden_keys, $forbidden_values ) as $needle ) { if ( false !== stripos( $json, $needle ) ) { $leaks[] = "$short contains $needle"; } }
}
check( 'running every ability wrote no option and made no update check or HTTP request', count( $GLOBALS['T_writes'] ) === $writes_before && array() === $GLOBALS['T_traps'], array( array_slice( $GLOBALS['T_writes'], $writes_before ), $GLOBALS['T_traps'] ) );
check( 'the scan has an input for every ability that needs one', array() === $noinput, $noinput );
check( 'every ability ran and answered (none refused, none failed): ' . $ran . ' abilities', array() === $errs && $ran === count( d5b_ability_defs() ) && $ran >= 39, array( $ran, $errs ) );
check( 'secret scan: no output of any ability contains a forbidden key or a planted secret value', array() === $leaks, $leaks );
$vis = json_encode( array( call( 'appearance-get' ), call( 'widgets-list' ), call( 'users-get', array( 'id' => 2 ) ), call( 'error-log' ), call( 'meta-get', array( 'post_id' => $sp ) ) ) );
check( 'the scan is not vacuous: ordinary fields of the same results do come through', false !== strpos( $vis, '#ff0000' ) && false !== strpos( $vis, 'Hello widget' ) && false !== strpos( $vis, 'bob@example.org' ) && false !== strpos( $vis, 'fine line' ) && false !== strpos( $vis, 'blue' ), $vis );

// ---- settings-get
echo "settings-get\n";
site( ADMIN );
$GLOBALS['T_options'] = $GLOBALS['T_options'] + array( 'blogname' => 'My Site', 'admin_email' => 'me@example.org', 'mailserver_pass' => 'hunter2', 'mailserver_login' => 'mailuser', 'siteurl' => 'https://x.example', 'active_plugins' => array( 'a/a.php' ), 'auth_key' => 'AKEY', 'posts_per_page' => '10', 'secret_thing' => 'x' );
$r = call( 'settings-get' );
$allow = array( 'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'WPLANG', 'posts_per_page', 'show_on_front', 'page_on_front', 'page_for_posts', 'default_comment_status', 'permalink_structure', 'blog_public' );
check( 'settings-get returns only allow-listed keys (and all of them)', is_array( $r ) && array() === array_diff( array_keys( $r['settings'] ), $allow ) && array() === array_diff( $allow, array_keys( $r['settings'] ) ), is_array( $r ) ? array_keys( $r['settings'] ) : $r );
check( 'settings-get: mailserver_pass, auth_key and other options are not returned; values come through', 'My Site' === $r['settings']['blogname'] && false === strpos( json_encode( $r ), 'hunter2' ) && false === strpos( json_encode( $r ), 'AKEY' ) && false === strpos( json_encode( $r ), 'mailuser' ) && 'me@example.org' === $r['settings']['admin_email'], $r );
check( 'settings-get takes no input', 'invalid_input' === code( call( 'settings-get', array( 'name' => 'mailserver_pass' ) ) ) );
site( EDITOR );
check( 'settings-get as an editor: refused and logged', 'FORBIDDEN' === call( 'settings-get' ) && 'forbidden' === last_audit()['status'] );
check( 'defence in depth: the handler alone also refuses an editor', 'forbidden' === code( d5b_ability_run( 'divi5-builder/settings-get', array() ) ) );

// ---- error-log
echo "error-log\n";
site( ADMIN );
check( 'error-log: a missing log answers exactly { no_debug_log: true }', array( 'no_debug_log' => true ) === call( 'error-log' ), call( 'error-log' ) );
$other = $logdir . '/other.txt'; file_put_contents( $other, "OTHER-FILE-CONTENT\n" ); file_put_contents( LOGFILE, "real log line\n" );
foreach ( array( array( 'path' => $other ), array( 'file' => '../other.txt' ), array( 'lines' => 5 ) ) as $bad_in ) {
	$r = call( 'error-log', $bad_in );
	check( 'error-log does not accept ' . key( $bad_in ), 'invalid_input' === code( $r ) && false === strpos( json_encode( $r ), 'OTHER-FILE-CONTENT' ), $r );
}
check( 'the path comes from WP_DEBUG_LOG only when it is a string path', '/var/log/wp.log' === d5b_rs_log_path_for( '/var/log/wp.log', '/c' ) && '/c/debug.log' === d5b_rs_log_path_for( true, '/c/' ) && '/c/debug.log' === d5b_rs_log_path_for( false, '/c' ) && '/c/debug.log' === d5b_rs_log_path_for( '1', '/c' ) && '/c/debug.log' === d5b_rs_log_path_for( '', '/c' ) );
$lines = array(); for ( $i = 1; $i <= 1000; $i++ ) { $lines[] = "line $i"; }
file_put_contents( LOGFILE, implode( "\n", $lines ) . "\n" );
$r = call( 'error-log' );
check( 'error-log: 1000 lines answer the last 200', is_array( $r ) && 200 === count( $r['lines'] ) && 'line 801' === $r['lines'][0] && 'line 1000' === $r['lines'][199] && true === $r['truncated'], is_array( $r ) ? array( count( $r['lines'] ), $r['lines'][0] ) : $r );
$lines = array(); for ( $i = 1; $i <= 400; $i++ ) { $lines[] = 'L' . $i . ' ' . str_repeat( 'x', 996 - strlen( (string) $i ) ); }
file_put_contents( LOGFILE, implode( "\n", $lines ) . "\n" );
$r = call( 'error-log' );
check( 'error-log: a 400 KB log reads at most 64 KB (bytes_read), not 200 lines of it', is_array( $r ) && $r['bytes_read'] <= 65536 && $r['file_bytes'] > 390000 && $r['line_count'] <= 66 && $r['line_count'] >= 60, is_array( $r ) ? array( $r['bytes_read'], $r['line_count'] ) : $r );
check( 'error-log: that output is under 70 KB, ends at the last line and starts on a whole line', is_array( $r ) && strlen( json_encode( $r ) ) < 70000 && 0 === strpos( $r['lines'][ $r['line_count'] - 1 ], 'L400 ' ) && 1 === preg_match( '/^L\d+ x+$/', $r['lines'][0] ), is_array( $r ) ? $r['lines'][0] : $r );
$mem = memory_get_usage();
file_put_contents( LOGFILE, str_repeat( str_repeat( 'y', 99 ) . "\n", 120000 ) ); // 12 MB
$r = call( 'error-log' );
check( 'error-log: a 12 MB log still reads only the tail', is_array( $r ) && $r['bytes_read'] <= 65536 && 200 === $r['line_count'] && memory_get_usage() - $mem < 4000000, is_array( $r ) ? array( $r['bytes_read'], memory_get_usage() - $mem ) : $r );
file_put_contents( LOGFILE, "a password=hunter2 b\nAuthorization: Bearer sv-bearer-abc.def\nconnect https://dbuser:pw0rd@db.example/x ok\napi_key=KEYVAL123 end\n{\"smtp_password\":\"hunter2\"}\ntoken: \"tok 123 456\"\nplain line stays\n" );
$r = call( 'error-log' ); $j = json_encode( $r );
check( 'error-log: passwords, bearer tokens, url credentials, keys and tokens in the text are masked', is_array( $r ) && 7 === $r['line_count'] && false === strpos( $j, 'hunter2' ) && false === strpos( $j, 'sv-bearer' ) && false === strpos( $j, 'pw0rd' ) && false === strpos( $j, 'KEYVAL123' ) && false === strpos( $j, 'tok 123' ), $j );
check( 'error-log: the rest of each line and the plain lines survive masking', false !== strpos( $j, 'plain line stays' ) && false !== strpos( $j, 'db.example' ) && false !== strpos( $j, 'redacted' ), $j );
file_put_contents( LOGFILE, str_repeat( 'z', 5000 ) . "\n" . "bad \xC3\x28 bytes \xFF end\n" );
$r = call( 'error-log' );
check( 'error-log: a line is cut at 2000 characters and invalid UTF-8 does not break the JSON', is_array( $r ) && 2000 === strlen( $r['lines'][0] ) && false !== json_encode( $r ), is_array( $r ) ? strlen( $r['lines'][0] ) : $r );
site( EDITOR );
check( 'error-log as an editor: refused', 'FORBIDDEN' === call( 'error-log' ) );

// ---- cron-list
echo "cron-list\n";
site( ADMIN );
$GLOBALS['T_cron_array'] = array(
	86400 => array( 'daily_hook' => array( 'k1' => array( 'schedule' => 'daily', 'args' => array( 'sv-cron-arg@example.org', 'tokenvalue' ), 'interval' => 86400 ) ) ),
	90000 => array( 'once_hook' => array( 'k2' => array( 'schedule' => false, 'args' => array() ) ) ),
);
$r = call( 'cron-list' );
check( 'cron-list: hook, schedule, next run (UTC ISO 8601) and the argument COUNT', is_array( $r ) && 2 === $r['total'] && array( 'hook' => 'daily_hook', 'schedule' => 'daily', 'next_run' => '1970-01-02T00:00:00Z', 'arg_count' => 2 ) === $r['events'][0] && 'single' === $r['events'][1]['schedule'] && 0 === $r['events'][1]['arg_count'], $r );
check( 'cron-list: no argument value anywhere in the output', false === strpos( json_encode( $r ), 'sv-cron-arg' ) && false === strpos( json_encode( $r ), 'tokenvalue' ), json_encode( $r ) );
$big = array(); for ( $i = 0; $i < 600; $i++ ) { $big[ 1000 + $i ] = array( 'h' . $i => array( 'k' => array( 'schedule' => false, 'args' => array() ) ) ); }
$GLOBALS['T_cron_array'] = $big;
$r = call( 'cron-list' );
check( 'cron-list: capped at 500 events, with the real total and a truncated flag', is_array( $r ) && 500 === count( $r['events'] ) && 600 === $r['total'] && true === $r['truncated'], is_array( $r ) ? array( count( $r['events'] ), $r['total'] ) : $r );
$GLOBALS['T_cron_array'] = false;
check( 'cron-list: no cron array answers an empty list', array() === call( 'cron-list' )['events'] );

// ---- health-status
echo "health-status\n";
site( ADMIN );
$r = call( 'health-status' );
check( 'health-status without a stored result answers no_cached_result with a note', is_array( $r ) && true === $r['no_cached_result'] && false !== strpos( $r['note'], 'Site Health' ), $r );
set_transient( 'health-check-site-status-result', json_encode( array( 'good' => 14, 'recommended' => 3, 'critical' => 1 ) ), 3600 );
$r = call( 'health-status' );
check( 'health-status returns the stored counts', is_array( $r ) && 14 === $r['good'] && 3 === $r['recommended'] && 1 === $r['critical'] && 18 === $r['total'], $r );
check( 'health-status never ran a Site Health test or made an HTTP request, and no update check', array() === $GLOBALS['T_traps'], $GLOBALS['T_traps'] );
site( ADMIN ); $GLOBALS['T_options']['health-check-site-status-result'] = json_encode( array( 'good' => 1, 'recommended' => 0, 'critical' => 0 ) );
check( 'health-status falls back to the stored option', 1 === ( call( 'health-status' )['good'] ?? null ) );

// ---- users
echo "users\n";
site( ADMIN, 1 );
$GLOBALS['T_users'] = array( 1 => mkuser( 1, 'alice', array( 'administrator' ) ), 2 => mkuser( 2, 'bob', array( 'editor' ) ), 3 => mkuser( 3, 'carol', array( 'editor', 'author' ) ) );
$r = call( 'users-get', array( 'id' => 3 ) );
check( 'users-get: id, login, display name, roles, registered and the email (the caller has list_users)', is_array( $r ) && 3 === $r['id'] && 'carol' === $r['login'] && 'Carol' === $r['display_name'] && array( 'editor', 'author' ) === $r['roles'] && '2026-01-03 10:00:00' === $r['registered_gmt'] && 'carol@example.org' === $r['email'], $r );
check( 'users-get: no password hash, key, session or application password', array( 'id', 'login', 'display_name', 'roles', 'registered_gmt', 'email' ) === array_keys( $r ) && false === strpos( json_encode( $r ), 'hunter2' ), array_keys( $r ) );
check( 'users-get: a missing user is not_found; a bad id is invalid_input', 'not_found' === code( call( 'users-get', array( 'id' => 99 ) ) ) && 'invalid_input' === code( call( 'users-get', array( 'id' => 'x' ) ) ) && 'invalid_input' === code( call( 'users-get' ) ) );
$r = call( 'users-list', array( 'per_page' => 500, 'role' => 'editor', 'search' => 'bo' ) );
check( 'users-list: per_page 500 reaches the query as 100; role and search are passed on', 100 === end( $GLOBALS['T_user_queries'] )['number'] && 'editor' === end( $GLOBALS['T_user_queries'] )['role'] && '*bo*' === end( $GLOBALS['T_user_queries'] )['search'] && 'bob' === $r['items'][0]['login'] && 1 === $r['total'], $r );
$r = call( 'users-list', array( 'per_page' => 2, 'page' => 2 ) );
check( 'users-list: paging gives page 2 of 3 users with the total', is_array( $r ) && 1 === count( $r['items'] ) && 3 === $r['items'][0]['id'] && 3 === $r['total'] && 2 === $r['total_pages'] && 2 === $r['page'], $r );
check( 'users-list: a bad role or an unknown input is refused', 'invalid_input' === code( call( 'users-list', array( 'role' => 'a b' ) ) ) && 'invalid_input' === code( call( 'users-list', array( 'meta_key' => 'x' ) ) ) );
check( 'users-list: no secret field in any row', false === strpos( json_encode( call( 'users-list' ) ), 'hunter2' ) && false === strpos( json_encode( call( 'users-list' ) ), 'actkey' ) );
site( EDITOR, 2 );
$GLOBALS['T_users'] = array( 2 => mkuser( 2, 'bob', array( 'editor' ) ) );
check( 'users-get: an editor (no list_users) is refused at the gate, and logged', 'FORBIDDEN' === call( 'users-get', array( 'id' => 2 ) ) && 'forbidden' === last_audit()['status'] );
check( 'users-list: an editor is refused', 'FORBIDDEN' === call( 'users-list' ) );
$r = d5b_ability_run( 'divi5-builder/users-get', array( 'id' => 2 ) );
check( 'users-get: even a direct handler call by an editor returns an error and no email', 'forbidden' === code( $r ) && false === strpos( json_encode( $r ), 'example.org' ), $r );
check( 'the email field itself checks list_users (a row built for a caller without it has no email)', ! array_key_exists( 'email', d5b_rs_user_row( $GLOBALS['T_users'][2] ) ) );

// ---- plugins, themes, core
echo "plugins, themes, core\n";
site( ADMIN );
$GLOBALS['T_options']['active_plugins'] = array( 'a/a.php' );
$GLOBALS['T_plugins'] = array( 'b/b.php' => array( 'Name' => 'beta', 'Version' => '2.0' ), 'a/a.php' => array( 'Name' => 'Alpha', 'Version' => '1.0' ) );
$GLOBALS['T_site_transients']['update_plugins'] = (object) array( 'response' => array( 'b/b.php' => (object) array( 'new_version' => '2.5' ) ) );
$r = call( 'plugins-list' );
check( 'plugins-list: name, version, active and update available, sorted by name', is_array( $r ) && 'Alpha' === $r['items'][0]['name'] && true === $r['items'][0]['active'] && false === $r['items'][0]['update_available'] && 'beta' === $r['items'][1]['name'] && false === $r['items'][1]['active'] && true === $r['items'][1]['update_available'] && '2.5' === $r['items'][1]['new_version'], $r );
$GLOBALS['T_themes'] = array( theme_row( 'parent', 'Parent', '3.0' ), theme_row( 'child', 'Child', '1.0', theme_row( 'parent', 'Parent', '3.0' ) ) ); $GLOBALS['T_theme'] = $GLOBALS['T_themes'][1];
$GLOBALS['T_site_transients']['update_themes'] = (object) array( 'response' => array( 'parent' => array( 'new_version' => '3.1' ) ) );
$r = call( 'themes-list' );
check( 'themes-list: active, parent and update available', is_array( $r ) && false === $r['items'][0]['active'] && '3.1' === $r['items'][0]['new_version'] && true === $r['items'][1]['active'] && 'Parent' === $r['items'][1]['parent']['name'] && false === $r['items'][1]['update_available'], $r );
$GLOBALS['T_site_transients']['update_core'] = (object) array( 'updates' => array( (object) array( 'response' => 'upgrade', 'current' => '6.9.4' ) ) );
$r = call( 'core-version' );
check( 'core-version: version and update available', is_array( $r ) && '6.9.1' === $r['version'] && true === $r['update_available'] && '6.9.4' === $r['new_version'], $r );
$GLOBALS['T_site_transients']['update_core'] = (object) array( 'updates' => array( (object) array( 'response' => 'latest', 'current' => '6.9.1' ) ) );
check( 'core-version: nothing waiting when the stored answer is latest', false === call( 'core-version' )['update_available'] );
check( 'none of plugins-list, themes-list, core-version checked for updates or made a request', array() === $GLOBALS['T_traps'], $GLOBALS['T_traps'] );
site( EDITOR );
$GLOBALS['T_plugins'] = array( 'a/a.php' => array( 'Name' => 'Alpha', 'Version' => '1.0' ) ); $GLOBALS['T_traps'] = array();
$GLOBALS['T_audit'] = array();
check( 'plugins-list as editor: refused and logged', 'FORBIDDEN' === call( 'plugins-list' ) && 1 === count( $GLOBALS['T_audit'] ) && 'forbidden' === last_audit()['status'] && 'divi5-builder/plugins-list' === last_audit()['ability'], $GLOBALS['T_audit'] );
check( 'themes-list and core-version as editor: refused', 'FORBIDDEN' === call( 'themes-list' ) && 'FORBIDDEN' === call( 'core-version' ) );
check( 'defence in depth: the plugins-list handler alone also refuses an editor', 'forbidden' === code( d5b_ability_run( 'divi5-builder/plugins-list', array() ) ) );
// multisite: the network capability too
foreach ( array( 'plugins-list' => 'manage_network_plugins', 'themes-list' => 'manage_network_themes', 'core-version' => 'update_core' ) as $ab => $netcap ) {
	site( ADMIN ); t_set_multisite( true ); d5b_abilities_set_master( true, 'tester' );
	$a = call( $ab );
	t_set_caps( array_merge( ADMIN, array( $netcap ) ) );
	$b = call( $ab );
	site( ADMIN ); t_set_multisite( false );
	$c = call( $ab );
	check( "multisite: $ab needs $netcap as well as manage_options (a single site does not)", 'FORBIDDEN' === $a && is_array( $b ) && is_array( $c ), array( $a, code( $b ), code( $c ) ) );
}
site( ADMIN ); t_set_multisite( true ); d5b_abilities_set_master( true, 'tester' );
check( 'multisite: the site-level abilities (settings-get) still need only manage_options', is_array( call( 'settings-get' ) ) );

// ---- cache-status
echo "cache-status\n";
site( ADMIN );
$r = call( 'cache-status' );
check( 'cache-status: no cache plugin active', is_array( $r ) && array() === $r['active'] && false === $r['page_cache_constant'], $r );
class T_Fc { public $cleared = 0; function deleteCache( $x = false ) { $this->cleared++; } }
$GLOBALS['wp_fastest_cache'] = new T_Fc();
if ( ! function_exists( 'rocket_clean_domain' ) ) { function rocket_clean_domain() { $GLOBALS['T_rocket'] = 1; } }
$r = call( 'cache-status' );
check( 'cache-status: active cache plugins are named from the updates part\'s detection (WP Fastest Cache, WP Rocket)', is_array( $r ) && array( 'WP Fastest Cache', 'WP Rocket' ) === $r['active'], $r );
check( 'cache-status changed nothing (no cache was emptied)', 0 === $GLOBALS['wp_fastest_cache']->cleared && empty( $GLOBALS['T_rocket'] ) );
$cleared = wpsu_clear_caches();
check( 'wpsu_clear_caches still empties exactly what the detection lists, in the same order (behaviour of the updates part unchanged)', 1 === $GLOBALS['wp_fastest_cache']->cleared && 1 === ( $GLOBALS['T_rocket'] ?? 0 ) && array_values( wpsu_detect_caches() ) === $cleared, $cleared );
site( EDITOR );
check( 'cache-status as editor: refused', 'FORBIDDEN' === call( 'cache-status' ) );

// ---- templates, global styles, appearance, widgets
echo "templates, global styles, appearance, widgets\n";
site( ADMIN );
$r = call( 'templates-list' ); $g = call( 'global-styles-get' ); $t = call( 'templates-get', array( 'id' => 'theme//home' ) );
check( 'classic theme: templates-list is empty with a note; global-styles-get and templates-get answer a note', is_array( $r ) && array() === $r['items'] && false === $r['is_block_theme'] && false === $g['is_block_theme'] && false === $t['is_block_theme'] && ! empty( $g['note'] ), array( $r, $g ) );
$GLOBALS['T_block_theme'] = true;
$GLOBALS['T_block_templates'] = array(
	(object) array( 'id' => 'theme//home', 'slug' => 'home', 'theme' => 'theme', 'type' => 'wp_template', 'title' => 'Home', 'source' => 'theme', 'status' => 'publish', 'has_theme_file' => true, 'is_custom' => false, 'content' => '<p>Home</p>' ),
	(object) array( 'id' => 'theme//header', 'slug' => 'header', 'theme' => 'theme', 'type' => 'wp_template_part', 'title' => 'Header', 'source' => 'theme', 'status' => 'publish', 'has_theme_file' => true, 'is_custom' => false, 'content' => str_repeat( 'é', 200000 ) ),
);
$r = call( 'templates-list' );
check( 'templates-list: templates only by default, no content in the rows', is_array( $r ) && 1 === $r['total'] && 'theme//home' === $r['items'][0]['id'] && ! array_key_exists( 'content', $r['items'][0] ), $r );
check( 'templates-list type=wp_template_part lists parts', 'theme//header' === call( 'templates-list', array( 'type' => 'wp_template_part' ) )['items'][0]['id'] && 'invalid_input' === code( call( 'templates-list', array( 'type' => 'post' ) ) ) );
$t = call( 'templates-get', array( 'id' => 'theme//home' ) );
check( 'templates-get: raw content and its hash', is_array( $t ) && '<p>Home</p>' === $t['content'] && md5( '<p>Home</p>' ) === $t['hash'] && false === $t['truncated'], $t );
$t = call( 'templates-get', array( 'id' => 'theme//header', 'type' => 'wp_template_part' ) );
check( 'templates-get: content over 256 KB is cut at 262144 bytes (whole characters) with a truncated flag', is_array( $t ) && true === $t['truncated'] && 262144 === strlen( $t['content'] ) && 400000 === $t['content_bytes'] && false !== json_encode( $t ), is_array( $t ) ? array( strlen( $t['content'] ), $t['truncated'] ) : $t );
check( 'templates-get: unknown id is not_found; a bad id is invalid_input', 'not_found' === code( call( 'templates-get', array( 'id' => 'theme//nope' ) ) ) && 'invalid_input' === code( call( 'templates-get', array( 'id' => '../../etc/passwd' ) ) ) && 'invalid_input' === code( call( 'templates-get' ) ) );
if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) ) {
	class T_ThemeJson { function get_raw_data() { return array( 'version' => 3, 'styles' => array( 'color' => array( 'text' => '#111' ), 'big' => str_repeat( 'a', 300000 ) ) ); } }
	class WP_Theme_JSON_Resolver { static function get_merged_data() { return new T_ThemeJson(); } }
}
$g = call( 'global-styles-get' );
check( 'global-styles-get: JSON text capped at 256 KB with a truncated flag', is_array( $g ) && true === $g['truncated'] && 262144 === strlen( $g['content'] ) && $g['content_bytes'] > 300000, is_array( $g ) ? array( $g['truncated'], strlen( $g['content'] ) ) : $g );
$GLOBALS['T_theme'] = theme_row( 'child', 'Child', '1.0', theme_row( 'parent', 'Parent', '3.0' ), true );
$GLOBALS['T_theme_mods'] = array( 'header_color' => '#abc' );
$GLOBALS['T_custom_css'] = (object) array( 'post_content' => str_repeat( 'a', 300000 ) );
$a = call( 'appearance-get' );
check( 'appearance-get: theme, parent, theme mods and Custom CSS (capped at 256 KB with a flag)', is_array( $a ) && 'Child' === $a['theme']['name'] && 'Parent' === $a['theme']['parent']['name'] && true === $a['theme']['block_theme'] && '#abc' === $a['theme_mods']['header_color'] && true === $a['custom_css_truncated'] && 262144 === strlen( $a['custom_css'] ) && 300000 === $a['custom_css_bytes'], is_array( $a ) ? array_keys( $a ) : $a );
$GLOBALS['T_custom_css'] = (object) array( 'post_content' => 'p{}' ); $GLOBALS['T_theme_mods'] = array( 'x_big' => str_repeat( 'b', 300000 ), 'smtp_password' => 'sv-smtp', 'ok_mod' => 1 );
$a = call( 'appearance-get' );
check( 'appearance-get: theme mods over 256 KB are replaced by their names (secret names left out) with a flag', is_array( $a ) && true === $a['theme_mods_truncated'] && array( 'x_big', 'ok_mod' ) === $a['theme_mod_names'] && false === strpos( json_encode( $a ), 'sv-smtp' ) && false === strpos( json_encode( $a ), 'smtp_password' ), is_array( $a ) ? array_keys( $a ) : $a );
$GLOBALS['T_sidebars'] = array( 'sidebar-1' => array( 'text-3', 'custom_html-9' ), 'wp_inactive_widgets' => array(), 'array_version' => 3 );
$GLOBALS['wp_registered_sidebars'] = array( 'sidebar-1' => array( 'name' => 'Main sidebar' ) );
$GLOBALS['T_options']['widget_text'] = array( 3 => array( 'title' => 'Hello' ) );
$GLOBALS['T_options']['widget_custom_html'] = array( 9 => array( 'content' => str_repeat( 'q', 20000 ) ) );
$w = call( 'widgets-list' );
check( 'widgets-list: sidebars with their widgets, type and settings; array_version is not a sidebar', is_array( $w ) && 2 === count( $w['sidebars'] ) && 'Main sidebar' === $w['sidebars'][0]['name'] && 'text' === $w['sidebars'][0]['widgets'][0]['type'] && 'Hello' === $w['sidebars'][0]['widgets'][0]['settings']['title'] && 2 === $w['widget_count'], $w );
check( 'widgets-list: one widget\'s settings over 16 KB are replaced by a truncated marker', true === ( $w['sidebars'][0]['widgets'][1]['settings']['_truncated'] ?? null ) );
site( array( 'read', 'manage_options' ) );
check( 'widgets-list needs edit_theme_options (manage_options is not enough)', 'FORBIDDEN' === call( 'widgets-list' ) );
site( array( 'read', 'edit_theme_options' ) );
check( 'widgets-list is allowed with edit_theme_options alone; appearance-get is not', is_array( call( 'widgets-list' ) ) && 'FORBIDDEN' === call( 'appearance-get' ) );
site( EDITOR );
check( 'templates-list, templates-get, global-styles-get, appearance-get as editor: refused', 'FORBIDDEN' === call( 'templates-list' ) && 'FORBIDDEN' === call( 'templates-get', array( 'id' => 'a//b' ) ) && 'FORBIDDEN' === call( 'global-styles-get' ) && 'FORBIDDEN' === call( 'appearance-get' ) );

// clean up the temp dir
@unlink( LOGFILE ); @unlink( $other ); @rmdir( $logdir );
echo "\n" . ( $failed ? "$failed of $n FAILED" : "$n / $n passed" ) . "\n";
exit( $failed ? 1 : 0 );
