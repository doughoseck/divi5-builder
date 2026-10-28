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
function d5b_audit_write( string $ability, array $args, string $status, int $duration_ms ): int { $GLOBALS['T_audit'][] = compact( 'ability', 'args', 'status' ); return count( $GLOBALS['T_audit'] ); }
function d5b_audit_update( int $id, string $status, int $duration_ms, ?array $args = null ): bool { if ( ! isset( $GLOBALS['T_audit'][ $id - 1 ] ) ) { return false; } $GLOBALS['T_audit'][ $id - 1 ]['status'] = $status; return true; }
// --no-updates (a child run): parts/updates.php is not loaded, so wpsu_detect_caches does not exist.
$no_updates = isset( $argv[1] ) && '--no-updates' === $argv[1];
foreach ( array( 'inc/parts', 'inc/switches', 'inc/abilities/categories', 'inc/abilities/register', 'inc/abilities/secrets', 'inc/abilities/audit', 'inc/abilities/scrub', 'inc/abilities/read-content', 'inc/abilities/read-structure', 'inc/abilities/read-site', 'parts/updates' ) as $f ) {
	if ( $no_updates && 'parts/updates' === $f ) { continue; }
	require $dir . '/' . $f . '.php';
}
if ( $no_updates ) {
	t_set_caps( array( 'read', 'manage_options' ) ); $GLOBALS['T_user_id'] = 1; d5b_abilities_set_master( true, 't' );
	echo 'CACHE=' . json_encode( function_exists( 'wpsu_detect_caches' ) ? 'detector loaded' : d5b_ability_run( 'divi5-builder/cache-status', array() ) ) . "\n";
	exit( 0 );
}

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
/** The handler alone, without the wrapper's gate: what the handler's own defence in depth answers. */
function handler( $name, $in = array() ) { return call_user_func( d5b_ability_defs()[ 'divi5-builder/' . $name ]['handler'], $in ); }
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
check( 'the loader lists scrub.php and read-site.php, and secrets.php before audit.php and scrub.php (they use its rule)', false !== strpos( $loader, "'inc/abilities/scrub.php'" ) && false !== strpos( $loader, "'inc/abilities/read-site.php'" ) && false !== strpos( $loader, "'inc/abilities/secrets.php'" ) && strpos( $loader, "'inc/abilities/secrets.php'" ) < strpos( $loader, "'inc/abilities/audit.php'" ) );

// ---- the scrubber
echo "the scrubber\n";
$k_each = array( 'user_pass', 'user_activation_key', 'session_tokens', '_application_passwords', 'smtp_password', 'mailserver_pass', 'my_secret', 'x_apikey', 'x_api_key', 'API_KEY', 'x_token', 'access_tokens', 'x_salt', 'x_auth_key', 'x_nonce', 'USER_PASS' );
$in = array(); foreach ( $k_each as $k ) { $in[ $k ] = 'v'; }
// round 3: the key rule is a union with an exact harmless list: passenger is on it, compass_dir is not (holds "pass"), so it goes.
$in += array( 'title' => 'Keep', 'author' => 'Keep', 'hash' => 'Keep', 'passenger' => 'v', 'compass_dir' => 'v', 7 => 'Keep', 'nested' => array( 'ok' => 1, 'api_key' => 'v', 'deeper' => array( array( 'token' => 'v', 'name' => 'Keep' ) ) ) );
$out = d5b_ability_scrub( $in );
$left = array_keys( $out );
sort( $left );
$want = array( 7, 'author', 'hash', 'nested', 'passenger', 'title' ); sort( $want );
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

// ---- one shared rule (break-it Important 2): every variant the break-it found is removed from results AND redacted in the audit
echo "one shared secret-key rule\n";
$must = array( 'passwd', 'passphrase', 'password_hash', 'pass_hash', 'password ', ' password', "password\t", 'auth', 'Authorization', 'cookie', 'Cookie', 'set-cookie', 'private_key', 'privkey', 'license_key',
	'logged_in_key', 'application_passwords', 'api-key', 'x-api-key', 'X-API-KEY', 'Api Key ', 'key', 'activation_key', 'pwd', 'db_pwd', 'bearer', 'Bearer', 'credentials', 'credential', 'signature', 'hmac', 'otp',
	'2fa_backup_codes', 'recovery_codes', 'pin', 'csrf', 'xsrf', 'jwt', 'sid', 'session', 'sessid', 'PHPSESSID', 'dsn', 'connection_string', 'webhook_url', 'stripe_sk', 'salt', 'sk_live',
	'password', 'user_pass', 'client_secret', 'access_token', 'refresh_token', 'secret_key', 'auth_key', 'AUTH_SALT', '_application_passwords', 'session_tokens', 'apikey', 'api_key', 'apiKey',
	'totp_secret', 'nonce', '_wpnonce', 'user_activation_key', 'smtp_pass' );
$leak_scrub = array(); $leak_audit = array();
foreach ( $must as $k ) {
	if ( false !== strpos( json_encode( d5b_ability_scrub( array( 'x' => array( $k => 'S3CR3T-VALUE' ) ) ) ), 'S3CR3T' ) ) { $leak_scrub[] = $k; }
	if ( false !== strpos( json_encode( d5b_audit_redact( array( 'x' => array( $k => 'S3CR3T-VALUE' ) ) ) ), 'S3CR3T' ) ) { $leak_audit[] = $k; }
}
check( 'must-scrub: every key variant is removed from a result (' . count( $must ) . ' keys)', array() === $leak_scrub, $leak_scrub );
check( 'must-scrub: the same keys are redacted in the audit args (one rule, no gap either way)', array() === $leak_audit, $leak_audit );
$plain = array( 'title', 'name', 'slug', 'status', 'content', 'author', 'authors', 'author_email', 'hash', 'meta_key', 'menu_key', 'desk', 'task', 'id', 'url', 'version', 'roles', 'count', 'description', 'monkey_business' );
$flagged = array_values( array_filter( $plain, 'd5b_is_secret_key' ) );
check( 'ordinary field names are not secrets (keep list and the "sk" word rule hold)', array() === $flagged, $flagged );
check( 'normalisation: X-API-KEY, api-key and "Api Key " all read as one key', d5b_is_secret_key( 'X-API-KEY' ) && 'xapikey' === d5b_secret_key_norm( 'X-API-KEY' ) && 'apikey' === d5b_secret_key_norm( 'api-key' ) && 'apikey' === d5b_secret_key_norm( 'Api Key ' ) );
$src = file_get_contents( $dir . '/inc/abilities/scrub.php' ) . file_get_contents( $dir . '/inc/abilities/audit.php' );
check( 'the scrubber and the audit redactor have no key regex of their own any more (both call d5b_is_secret_key)', false === strpos( $src, "pass|secret|token|key|auth" ) && false === strpos( $src, 'pass(word)?$' ) && false === strpos( $src, 'function d5b_ability_scrub_key' ) );

echo "name/value pairs\n";
$r = d5b_ability_scrub( array(
	array( 'key' => 'password', 'value' => 'S3CR3T-A' ), array( 'name' => 'smtp_password', 'value' => 'S3CR3T-B' ), array( 'option' => 'stripe_api_key', 'value' => 'S3CR3T-C' ),
	array( 'meta_key' => '_my_secret', 'meta_value' => 'S3CR3T-D' ), array( 'name' => 'color', 'value' => 'red' ), array( 'meta_key' => 'size', 'meta_value' => 'XL' ),
) );
check( 'a value beside a key/name/option/meta_key that names a secret is removed', false === strpos( json_encode( $r ), 'S3CR3T' ), $r );
check( '... and an ordinary pair keeps its value', 'red' === ( $r[4]['value'] ?? null ) && 'XL' === ( $r[5]['meta_value'] ?? null ) && 'color' === $r[4]['name'], $r );

echo "value shapes\n";
$jwt  = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
$pem  = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAxS3CR3T\nmorelines\n-----END RSA PRIVATE KEY-----";
$shapes = array(
	'PEM block'        => array( "before\n$pem\nafter", 'MIIEow', array( 'before', 'after' ) ),
	'PEM without END'  => array( 'key: -----BEGIN PRIVATE KEY-----S3CR3TBODY', 'S3CR3TBODY', array( 'key:' ) ),
	'sk_live_'         => array( 'Use sk_live_51HxS3CR3Tabcdef in the form', 'S3CR3Tabcdef', array( 'Use ', ' in the form' ) ),
	'rk_live_'         => array( 'rk_live_S3CR3T1234 ok', 'S3CR3T1234', array( ' ok' ) ),
	'sk_test_'         => array( 'x sk_test_S3CR3T99999 y', 'S3CR3T99999', array( 'x ', ' y' ) ),
	'JWT'              => array( "token is $jwt end", 'dozjgNryP4J3', array( 'token is ', ' end' ) ),
	'phpass $P$'       => array( 'hash $P$BS3CR3Tabcdefghijklmnopqrstuv. done', 'S3CR3Tabc', array( 'hash ', ' done' ) ),
	'phpass $H$'       => array( '$H$9S3CR3Tabcdefghijk', 'S3CR3T', array() ),
	'$wp$ bcrypt'      => array( "('bob', '\$wp\$2y\$10\$S3CR3TINSERTHASH')", 'S3CR3TINSERT', array( "('bob', '", "')" ) ),
	'bcrypt $2y$'      => array( 'pw $2y$10$S3CR3Tabcdefghijklmnop', 'S3CR3T', array( 'pw ' ) ),
	'argon2id'         => array( '$argon2id$v=19$m=65536,t=4,p=1$S3CR3Tsalt$S3CR3Thash', 'S3CR3T', array() ),
	'Bearer 16+'       => array( 'header Bearer S3CR3Tabcdefghijklmn sent', 'S3CR3Tabcdef', array( 'header Bearer ', ' sent' ) ),
);
foreach ( $shapes as $label => $c ) {
	$o = d5b_ability_scrub( array( 'content' => $c[0], 5 => array( $c[0] ) ) );
	$ok = false === strpos( json_encode( $o ), $c[1] ) && false !== strpos( $o['content'], '[redacted]' );
	foreach ( $c[2] as $keep ) { if ( false === strpos( $o['content'], $keep ) ) { $ok = false; } }
	check( "value shape masked in a string, also under a numeric key: $label (the rest of the text kept)", $ok, $o );
}
$prose = array( 'The price is $5 and $10.', 'Bearer of good news', 'eyJ is not a token', 'sk_live_short', '-----BEGIN PUBLIC KEY-----abc', 'a $P$ sign', 'Pay $2y now' );
$prose_out = array_map( function ( $p ) { return d5b_ability_scrub( array( 'v' => $p ) )['v']; }, $prose ); // one per array: a list holding sk_live_short would be a named row (round 3)
check( 'ordinary text with look-alike bits is left alone', $prose === $prose_out, $prose_out );
check( 'a scalar string result is masked too', '[redacted]' === d5b_ability_scrub( $jwt ) );

echo "WP_Error on the way out\n";
site( ADMIN );
$snapshot = d5b_ability_registry();
d5b_ability_add( array( 'name' => 'divi5-builder/posts-err', 'category' => 'posts', 'label' => 'e', 'description' => 'Returns an error. Refuses nothing.', 'permission' => function ( array $i ) { return true; },
	'handler' => function ( array $i ) use ( $jwt ) { $e = new WP_Error( 'bad', "oops $jwt", array( 'status' => 400, 'db_password' => 'S3CR3T-1', 'user' => (object) array( 'user_pass' => 'S3CR3T-2', 'login' => 'bob' ) ) ); $e->add( 'second', 'more', array( 'api_key' => 'S3CR3T-3' ) ); return $e; } ) );
d5b_ability_add( array( 'name' => 'divi5-builder/posts-perr', 'category' => 'posts', 'label' => 'e', 'description' => 'Returns nothing. Refuses everyone.', 'handler' => function ( array $i ) { return 1; },
	'permission' => function ( array $i ) { return new WP_Error( 'nope', 'no', array( 'db_password' => 'S3CR3T-4', 'status' => 403 ) ); } ) );
$r = d5b_ability_run( 'divi5-builder/posts-err', array() );
$dump = json_encode( array( $r->get_error_messages(), $r->get_all_error_data( 'bad' ), $r->get_all_error_data( 'second' ) ) );
check( 'a handler\'s WP_Error: messages masked by shape, data scrubbed in full (objects too), codes and ordinary data kept', is_wp_error( $r ) && array( 'bad', 'second' ) === $r->get_error_codes() && false === strpos( $dump, 'S3CR3T' ) && false === strpos( $dump, 'dozjgNryP4J3' )
	&& 400 === $r->get_error_data( 'bad' )['status'] && 'bob' === $r->get_error_data( 'bad' )['user']['login'] && 'more' === $r->get_error_message( 'second' ), $dump );
$p = d5b_ability_permit( 'divi5-builder/posts-perr', array() );
check( 'the WP_Error a permission callback returns is scrubbed too (permit)', is_wp_error( $p ) && 'nope' === $p->get_error_code() && false === strpos( json_encode( $p->get_error_data() ), 'S3CR3T' ) && 403 === $p->get_error_data()['status'], $p->get_error_data() );
$r = d5b_ability_run( 'divi5-builder/posts-perr', array() );
check( '... and the same refusal through a direct run', is_wp_error( $r ) && 'nope' === $r->get_error_code() && false === strpos( json_encode( $r->get_error_data() ), 'S3CR3T' ) );
d5b_ability_registry( $snapshot );

// ---- round 2: the key rule tokenises (break-it 2 and 5)
echo "round 2: tokenised key rule\n";
$must2 = array_merge( $must, array( 'basic_auth', 'http_auth', 'proxy_authorization', 'Proxy-Authorization', 'x_auth', 'smtp_auth', 'oauth', 'creds', 'db_creds', 'pw', 'smtp_pw', 'pword', 'seed', 'mnemonic', 'mfa_code', 'PHP_AUTH_PW',
	'smtpPassword', 'apiKey', 'oauth2_token', 'access_key', 'secretKey' ) );
$missed = array_values( array_filter( $must2, function ( $k ) { return ! d5b_is_secret_key( $k ); } ) );
check( 'must-scrub (round 1 list + 21 more): every key is a secret', array() === $missed, $missed );
$should_keep = array( 'compass', 'bypass_cache', 'passenger', 'footprint', 'hotpink', 'basalt', 'monkey', 'turkey', 'hotkey', 'layout_key', 'module_key', 'author', 'author_id', 'authority', 'authorized' );
$eaten2 = array_values( array_filter( $should_keep, 'd5b_is_secret_key' ) );
check( 'should-keep: ordinary keys with a secret word inside another word stay', array() === $eaten2, $eaten2 );
check( 'tokens: split at separators, camelCase and digits', array( 'smtp', 'pw' ) === d5b_secret_key_tokens( 'smtp_pw' ) && array( 'api', 'key' ) === d5b_secret_key_tokens( 'apiKey' ) && array( 'proxy', 'authorization' ) === d5b_secret_key_tokens( 'Proxy-Authorization' ) && array( 'oauth', '2' ) === d5b_secret_key_tokens( 'oauth2' ) );
check( 'a lone "key" token: the whole key, or after api/private/secret/...; not after layout or module', d5b_is_secret_key( 'key' ) && d5b_is_secret_key( 'x-key' ) && d5b_is_secret_key( 'stripe_key' ) && ! d5b_is_secret_key( 'layout_key' ) && ! d5b_is_secret_key( 'sort_key' ) );

echo "round 2: name/value pairs (break-it a9 section 4)\n";
$S = 'S3CR3T-VALUE';
$pairs2 = array(
	'key/value'                 => array( 'key' => 'smtp_password', 'value' => $S ),
	'Key/Value capitals'        => array( 'Key' => 'smtp_password', 'Value' => $S ),
	'NAME/VALUE'                => array( 'NAME' => 'api_key', 'VALUE' => $S ),
	'option_name/option_value'  => array( 'option_name' => 'smtp_password', 'option_value' => $S ),
	'meta_key/meta_value'       => array( 'meta_key' => 'pwd', 'meta_value' => $S ),
	'name/values (plural)'      => array( 'name' => 'password', 'values' => array( $S ) ),
	'name/data'                 => array( 'name' => 'password', 'data' => $S ),
	'id/value'                  => array( 'id' => 'smtp_password', 'value' => $S ),
	'label/value'               => array( 'label' => 'Password', 'value' => $S ),
	'field/val'                 => array( 'field' => 'password', 'val' => $S ),
	'setting/value'             => array( 'setting' => 'api_key', 'value' => $S ),
	'title/value'               => array( 'title' => 'SMTP password', 'value' => $S ),
	'name nested in sub-array'  => array( 'field' => array( 'name' => 'password' ), 'value' => $S ),
	'value nested under pair'   => array( 'name' => 'password', 'value' => array( 'deep' => array( 'x' => $S ) ) ),
	'pair inside numeric list'  => array( array( array( 'name' => 'password', 'value' => $S ) ) ),
	'name as array'             => array( 'name' => array( 'password' ), 'value' => $S ),
	'name with whitespace'      => array( 'name' => ' PASS WORD ', 'value' => $S ),
	'parallel names/values'     => array( 'names' => array( 'color', 'password' ), 'values' => array( 'red', $S ) ),
	'parallel keys/values'      => array( 'keys' => array( 'api_key' ), 'values' => array( $S ) ),
	'[ name, value ] list'      => array( array( 'password', $S ) ),
	'stdClass pair'             => (object) array( 'name' => 'password', 'value' => $S ),
);
$leaked = array(); foreach ( $pairs2 as $label => $v ) { if ( false !== strpos( (string) json_encode( d5b_ability_scrub( array( 'x' => $v ) ) ), 'S3CR3T' ) ) { $leaked[] = $label; } }
check( 'every a9 pair case holds (' . count( $pairs2 ) . ' cases)', array() === $leaked, $leaked );
$o = d5b_ability_scrub( array( 'names' => array( 'color', 'size', 'password' ), 'values' => array( 'red', 'XL', $S ) ) );
check( 'parallel lists: only the secret index goes in the values; the names list itself is a named list (its other names are redacted, the secret label kept)', array( '[redacted]', '[redacted]', 'password' ) === $o['names'] && array( 'red', 'XL', '[redacted]' ) === $o['values'], $o );
$plainp = array( array( 'name' => 'color', 'value' => 'red' ), array( 'Label' => 'Size', 'Data' => 'XL' ), array( 'title', 'Home' ), array( 'names' => array( 'a' ), 'values' => array( 'b' ) ) );
check( 'ordinary pairs, two-item lists and parallel lists keep their values', $plainp === d5b_ability_scrub( $plainp ), d5b_ability_scrub( $plainp ) );

echo "round 2: WP_Error free text (break-it a9 section 5)\n";
$e = new WP_Error( 'one', 'first token=' . $S, array( 'status' => 400, 'api_key' => $S ) );
$e->add( 'two', 'second eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.c2lnbmF0dXJlMTIz', array( 'user' => (object) array( 'user_pass' => $S ) ) );
$e->add_data( array( 'nested' => array( array( 'name' => 'password', 'value' => $S ) ) ), 'two' );
$e->add_data( array( 'list' => array( 'password=' . $S ) ), 'one' );
$e->add( 'three', 'third', new WP_Error( 'inner', 'inner msg', array( 'secret' => $S ) ) );
$e->add( 'four', "mysqli: Access denied for user 'wp'@'localhost' (using password: YES) password=$S" );
$c = d5b_ability_scrub_error( $e );
$dump = json_encode( array_map( function ( $code ) use ( $c ) { return array( $c->get_error_messages( $code ), $c->get_all_error_data( $code ) ); }, $c->get_error_codes() ) );
check( 'every message and every string in the data go through the free-text masker; codes and ordinary data kept', array( 'one', 'two', 'three', 'four' ) === $c->get_error_codes() && false === strpos( $dump, 'S3CR3T' ) && false === strpos( $dump, 'c2lnbmF0' ) && 400 === ( $c->get_all_error_data( 'one' )[0]['status'] ?? null ), $dump );
check( '... the ordinary parts of the messages stay readable', 'third' === $c->get_error_message( 'three' ) && 0 === strpos( $c->get_error_message( 'one' ), 'first token=' ) && false !== strpos( $c->get_error_message( 'four' ), "Access denied for user 'wp'" ) );
check( 'our own refusal messages are not mangled by the masker', 'key is not valid.' === d5b_ability_scrub_text( 'key is not valid.' ) && 'key must be text of at most 255 characters.' === d5b_ability_scrub_text( 'key must be text of at most 255 characters.' ) );

echo "round 2: value shapes (break-it Minor 6)\n";
$pem10 = 'ok $ text ' . str_repeat( '-----BEGIN PRIVATE KEY-----' . str_repeat( 'x', 2000 ) . "\n", 10 ) . 'TAIL';
$o = d5b_secret_mask_values( $pem10 );
check( 'PEM without END, 10 blocks: each BEGIN line goes, the text before and TAIL after survive', false !== strpos( $o, 'TAIL' ) && 0 === strpos( $o, 'ok $ text ' ) && false === strpos( $o, 'xxxx' ) && 10 === substr_count( $o, '[redacted]' ), substr( $o, 0, 200 ) );
$b64 = str_repeat( 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASC', 2 );
$o = d5b_secret_mask_values( "before\n-----BEGIN PRIVATE KEY-----\n$b64\n$b64\nAB==\nafter line\nmore" );
check( 'PEM without END: the base64 lines after BEGIN go too, the first ordinary line stays', "before\n[redacted]\nafter line\nmore" === $o, $o );
$o = d5b_secret_mask_values( "a -----BEGIN RSA PRIVATE KEY-----\n$b64\n-----END RSA PRIVATE KEY----- b -----BEGIN PRIVATE KEY-----\n$b64\n-----END PRIVATE KEY----- c" );
check( 'PEM with END: BEGIN to its own END, each block separately, text between kept', 'a [redacted] b [redacted] c' === $o, $o );
check( 'Bearer: "Bearer instrumentalisation" stays; a 20+ token with a digit or ._-~+/ goes; a short one stays', 'Bearer instrumentalisation of bonds' === d5b_secret_mask_values( 'Bearer instrumentalisation of bonds' )
	&& 'Bearer [redacted] x' === d5b_secret_mask_values( 'Bearer abcdefghij0123456789xyz x' ) && 'Bearer abc.def x' === d5b_secret_mask_values( 'Bearer abc.def x' ), d5b_secret_mask_values( 'Bearer abcdefghij0123456789xyz x' ) );
$t = microtime( true ); d5b_secret_mask_values( '-----BEGIN PRIVATE KEY-----' . str_repeat( "MIIabc\n", 150000 ) ); d5b_secret_mask_values( str_repeat( '-----BEGIN ' . str_repeat( 'A ', 30 ) . 'X', 900 ) . 'PRIVATE KEY' );
check( 'PEM masking stays fast on a 1 MB unterminated block and on BEGIN near-misses', microtime( true ) - $t < 1.0, round( ( microtime( true ) - $t ) * 1000 ) . ' ms' );

// ---- round 3: the key rule is the union of tokens and substrings, minus an exact harmless list (break-it a10, a12)
echo "round 3: key rule = union\n";
$r3_secret = array(
	// a12 (the round-2 regression) and a10 section 2
	'apitoken', 'authtoken', 'bearertoken', 'sessiontoken', 'csrftoken', 'appsecret', 'webhooksecret', 'jwtsecret', 'smtppass', 'dbpass', 'adminpass', 'rootpw', 'passcode', 'apisecret', 'consumersecret',
	'authsalt', 'oauthtoken', 'smtppwd', 'dbcreds', 'basicauth', 'httpauth', 'otpsecret', 'signingsecret', 'masterkey', 'secretphrase', 'api2key', 'api_2_key',
	'accesstokens', 'pincode', 'secretkey', 'privatekey', 'accesskey', 'apikeys', 'wpsalt', 'clientsecret', 'mailpassword', 'sessionid', 'sessid', 'phpsessid', 'xauthtoken', 'proxyauthorization',
	'totp', 'totpkey', 'twofactorsecret', 'recoverycodes', 'backupcodes', 'encryptionkey', 'hashkey', 'pass_word', 'pass-word', 'p_assword', 'pa_ss', 'se_cret', 'to_ken', 'api_k_e_y', 'a_p_i_key',
	'sec-ret-key', 'auth_or_ization', 'Pass Word', 'pass.word', 'tok en',
	// a10 section 1
	'apiKey', 'APIKey', 'ApiKEY', 'APIKEY', 'apiKey2', 'oAuthToken', 'OAuthToken', 'x-Api-Key', 'XApiKey', 'PASSWORD123', 'password1', 'Password_v2', 'pass2', 'pwd1', 'token2', 'v2Token', 'JWTSecret',
	'jwtSecret', 'SMTPPassword', 'smtpPass', 'dbPWD', 'AWSSecretAccessKey', 'awsAccessKeyId', 'secretAccessKey', 'privateKeyPem', 'clientSecretV2', 'HTTPAuth', 'httpAuthUser', 'BasicAuth', 'MFASeed', 'TOTPSecret',
	// accepted over-scrub (round 3): short tokens and words stay hidden
	'pin_color', 'random_seed', 'cookie_notice' );
$missed3 = array_values( array_filter( $r3_secret, function ( $k ) { return ! d5b_is_secret_key( $k ); } ) );
check( 'every a12 and a10 section 1-2 key is a secret (' . count( $r3_secret ) . ' keys)', array() === $missed3, $missed3 );
$harmless = array( 'compass', 'bypass', 'bypass_cache', 'passenger', 'footprint', 'hotpink', 'basalt', 'monkey', 'turkey', 'hotkey', 'author', 'authors', 'author_id', 'author name', 'authority', 'authored', 'authorized', 'layout_key', 'module_key', 'hash', 'meta_key', 'menu_key' );
$eaten3 = array_values( array_filter( $harmless, 'd5b_is_secret_key' ) );
check( 'the exact harmless list stays visible (matched on the joined key)', array() === $eaten3, $eaten3 );
check( 'the harmless list is exact: a longer key holding one of its words is still a secret (compass_dir, bypass_token, passengers)', d5b_is_secret_key( 'compass_dir' ) && d5b_is_secret_key( 'bypass_token' ) && d5b_is_secret_key( 'passengers' ) );

echo "round 3: one general pair rule (break-it a10 section 4)\n";
$p3 = array(
	'Name / Value (Title case)' => array( 'Name' => 'Password', 'Value' => $S ), 'nAmE / vAlUe' => array( 'nAmE' => 'api_key', 'vAlUe' => $S ),
	'"name " (trailing space)' => array( 'name ' => 'password', 'value ' => $S ), 'name with a trailing tab and newline' => array( 'name' => "password\t\n", 'value' => $S ), 'value field "Value " only' => array( 'name' => 'password', 'Value ' => $S ),
	'value field "content"' => array( 'name' => 'password', 'content' => $S ), 'value field "text"' => array( 'name' => 'password', 'text' => $S ),
	'value field "default"' => array( 'name' => 'smtp_password', 'default' => $S ), 'value field "v"' => array( 'k' => 'password', 'v' => $S ),
	'name field "slug"' => array( 'slug' => 'api-key', 'value' => $S ), 'name field "option_key"' => array( 'option_key' => 'api_key', 'value' => $S ),
	'name field "param"' => array( 'param' => 'password', 'value' => $S ), 'name field "header"' => array( 'header' => 'Authorization', 'value' => 'Basic ' . $S ),
	'name in 2nd item of name array' => array( 'name' => array( 'label', 'password' ), 'value' => $S ), 'name as object' => array( 'name' => (object) array( 'n' => 'password' ), 'value' => $S ),
	'3-item list [name,value,x]' => array( array( 'password', $S, 'x' ) ), 'list [value,name]' => array( array( $S, 'password' ) ),
	'keys/vals parallel' => array( 'keys' => array( 'password' ), 'vals' => array( $S ) ), 'labels/values parallel' => array( 'labels' => array( 'password' ), 'values' => array( $S ) ),
	'Names/Values parallel (caps)' => array( 'Names' => array( 'password' ), 'Values' => array( $S ) ), 'names assoc, values assoc' => array( 'names' => array( 'a' => 'password' ), 'values' => array( 'a' => $S ) ),
	'names list, values keyed by name' => array( 'names' => array( 'password' ), 'values' => array( 'password' => $S ) ),
	'widget-like row' => array( 'id_base' => 'custom_html', 'settings' => array( array( 'title' => 'x', 'content' => 'api_key=' . $S ) ) ),
);
$deep30 = array( 'name' => 'password', 'value' => $S ); for ( $i = 0; $i < 30; $i++ ) { $deep30 = array( 'n' => $deep30 ); } $p3['pair 30 levels deep'] = $deep30;
$deep33 = array( 'name' => 'password', 'value' => $S ); for ( $i = 0; $i < 33; $i++ ) { $deep33 = array( 'n' => $deep33 ); } $p3['pair at depth 33'] = $deep33;
// Not ported as "held": a name given only as an index into a separate names list ('name is int index') and a name two levels
// below its value ('name 2 levels down'). They are the documented limits of the pair rule.
$leak3 = array(); foreach ( $p3 as $label => $v ) { if ( false !== strpos( (string) json_encode( d5b_ability_scrub( array( 'x' => $v ) ) ), 'S3CR3T' ) ) { $leak3[] = $label; } }
check( 'every a10 section 4 pair case holds (' . count( $p3 ) . ' cases)', array() === $leak3, $leak3 );
$o = d5b_ability_scrub( array( 'name' => 'smtp_password', 'value' => $S, 'enabled' => true, 'port' => 587 ) );
check( 'the name is kept as a label, the other strings become [redacted], numbers and booleans stay', array( 'name' => 'smtp_password', 'value' => '[redacted]', 'enabled' => true, 'port' => 587 ) === $o, $o );
$rows = d5b_ability_scrub( array( array( 'name' => 'smtp_password', 'value' => $S ), array( 'name' => 'color', 'value' => 'red' ) ) );
check( 'a list of rows: one secret row does not redact its neighbour row', 'red' === $rows[1]['value'] && 'color' === $rows[1]['name'], $rows );
$nine = array( 'a' => 'password', 'b' => 'x1', 'c' => 'x2', 'd' => 'x3', 'e' => 'x4', 'f' => 'x5', 'g' => 'x6', 'h' => 'x7', 'i' => 'x8' );
check( 'an array of more than 8 entries is a record, not a pair: a value that happens to name a secret does not redact it', $nine === d5b_ability_scrub( $nine ), d5b_ability_scrub( $nine ) );

echo "round 3: free text in successful results (break-it a10 section 5)\n";
foreach ( array( 'smtp password=' . $S, 'Authorization: Basic ' . $S, 'curl -u admin:' . $S, "define('DB_PASSWORD','$S');" ) as $t ) {
	$o = d5b_ability_scrub( array( 'content' => $t ) );
	check( 'a result string through the full masker: ' . substr( $t, 0, 30 ), false === strpos( json_encode( $o ), 'S3CR3T' ), $o );
}
site( ADMIN, 1 );
$GLOBALS['T_sidebars'] = array( 'sidebar-1' => array( 'custom_html-2' ) ); $GLOBALS['wp_registered_sidebars'] = array( 'sidebar-1' => array( 'name' => 'Main' ) );
$GLOBALS['T_options']['widget_custom_html'] = array( 2 => array( 'title' => 'x', 'content' => '<script src="https://x/?api_key=' . $S . '"></script>' ) );
$r = call( 'widgets-list' );
check( 'widgets are not post content: a widget\'s content goes through the full masker, and masked: true', is_array( $r ) && false === strpos( json_encode( $r ), 'S3CR3T' ) && true === ( $r['masked'] ?? null ), $r );
$cp = t_add_post( array( 'post_content' => 'Docs: set smtp password=hunter2 in the form.', 'post_title' => 'password=hunter2' ) );
$r = call( 'posts-get', array( 'id' => $cp ) );
check( 'post content keeps free text (value shapes only, so it stays editable); the title is not content and is masked; masked: true', is_array( $r ) && 'Docs: set smtp password=hunter2 in the form.' === $r['content'] && false === strpos( $r['title'], 'hunter2' ) && true === $r['masked'], $r );
$cp2 = t_add_post( array( 'post_content' => 'Docs: set smtp password=hunter2 in the form.' ) );
$r = call( 'posts-get', array( 'id' => $cp2 ) );
check( '... and free text in content alone does not set masked (nothing returned was changed)', is_array( $r ) && ! array_key_exists( 'masked', $r ), $r );
$r = call( 'media-get', array( 'id' => t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_content' => 'token=' . $S ) ) ) );
check( 'media is not in the post-like list: its description goes through the full masker', is_array( $r ) && false === strpos( json_encode( $r ), 'S3CR3T' ) && true === $r['masked'], $r );
$big = str_repeat( 'lorem ', 50000 ) . 'password=' . $S;
$o = d5b_ability_scrub( array( 'note' => $big ) );
check( 'a result string over 256 KB: cut at 256 KB with …[truncated], nothing after the cut passes through', false === strpos( $o['note'], 'S3CR3T' ) && '…[truncated]' === substr( $o['note'], -strlen( '…[truncated]' ) ) );

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
$GLOBALS['T_theme_mods'] = array( 'header_color' => '#ff0000', 'smtp_password' => 'sv-smtp', 'my_secret' => 'sv-v2', 'x_token' => 'sv-v3', 'x_salt' => 'sv-v4', 'x_auth_key' => 'sv-v5', 'x_nonce' => 'sv-v6', 'x_apikey' => 'sv-v7' );
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
$forbidden_values = array( 'hunter2', 'APPPWD-PLANTED-123', 'actkey-xyz', 'SESSTOK', 'sv-smtp', 'sv-secret', 'sv-token', 'sv-salt', 'sv-authkey', 'sv-nonce', 'sv-apikey', 'sv-v2', 'sv-v3', 'sv-v4', 'sv-v5', 'sv-v6', 'sv-v7', 'sv-widget-key', 'sv-postmeta', 'sv-bearer-abc', 'sv-cron-arg' );
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
check( 'defence in depth: the handler alone also refuses an editor', 'forbidden' === code( handler( 'settings-get', array() ) ) );

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
check( 'M-2: bytes_read is what was really read (64 KB here), not what was left after dropping the cut first line', is_array( $r ) && 65536 === $r['bytes_read'] && ! isset( $r['note'] ), is_array( $r ) ? $r['bytes_read'] : $r );
file_put_contents( LOGFILE, str_repeat( 'x', 200000 ) . "\n" );
$r = call( 'error-log' );
check( 'M-2: one line longer than 64 KB: no line, bytes_read 65536, and a note that says why', is_array( $r ) && 0 === $r['line_count'] && 65536 === $r['bytes_read'] && true === $r['truncated'] && false !== strpos( (string) ( $r['note'] ?? '' ), 'no whole line' ), $r );
file_put_contents( LOGFILE, "a password=hunter2 b\nAuthorization: Bearer sv-bearer-abc.def\nconnect https://dbuser:pw0rd@db.example/x ok\napi_key=KEYVAL123 end\n{\"smtp_password\":\"hunter2\"}\ntoken: \"tok 123 456\"\nplain line stays\n" );
$r = call( 'error-log' ); $j = json_encode( $r );
check( 'error-log: passwords, bearer tokens, url credentials, keys and tokens in the text are masked', is_array( $r ) && 7 === $r['line_count'] && false === strpos( $j, 'hunter2' ) && false === strpos( $j, 'sv-bearer' ) && false === strpos( $j, 'pw0rd' ) && false === strpos( $j, 'KEYVAL123' ) && false === strpos( $j, 'tok 123' ), $j );
check( 'error-log: the rest of each line and the plain lines survive masking', false !== strpos( $j, 'plain line stays' ) && false !== strpos( $j, 'db.example' ) && false !== strpos( $j, 'redacted' ), $j );
file_put_contents( LOGFILE, str_repeat( 'z', 5000 ) . "\n" . "bad \xC3\x28 bytes \xFF end\n" );
$r = call( 'error-log' );
check( 'error-log: a line is cut at 2000 characters and invalid UTF-8 does not break the JSON', is_array( $r ) && 2000 === strlen( $r['lines'][0] ) && false !== json_encode( $r ), is_array( $r ) ? strlen( $r['lines'][0] ) : $r );
// M-1: core's wp_check_invalid_utf8( $line, true ) (the stand-in now does what core does) returns an EMPTY line for one bad byte.
check( 'M-1 premise: core\'s wp_check_invalid_utf8( x, true ) blanks a line holding one bad byte', '' === (string) wp_check_invalid_utf8( "bad \xC3\x28 line with text", true ) );
check( 'M-1: the error-log handler does not call wp_check_invalid_utf8 at all (it uses mb_scrub, else iconv //IGNORE)', false === strpos( file_get_contents( $dir . '/inc/abilities/read-site.php' ), 'wp_check_invalid_utf8(' ) );
$r = call( 'error-log' );
check( 'M-1: a line with real invalid bytes keeps its text (bad bytes replaced), so nothing in it is silently dropped', is_array( $r ) && 1 === preg_match( '/^bad .\( bytes . end$/u', $r['lines'][1] ) && false !== json_encode( $r ), is_array( $r ) ? $r['lines'][1] : $r );
// I-1 (Task 5 review): the shapes a debug log really holds
file_put_contents( LOGFILE, implode( "\n", array(
	'[28-Oct-2026 10:00:00 UTC] Array', '(', '    [user_login] => alice', '    [user_pass] => hunter2-printr', '    [pwd] => hunter2-pwd', '    [api_key] => sk_live_PRINTR', ')',
	"array ( 'password' => 'hunter2-varexport', 'stripe_secret' => 'sk_live_VAREXP', )",
	"WordPress database error for query UPDATE wp_users SET user_pass = '\$P\$Bhashhash' WHERE ID = 1",
	"WordPress database error for query INSERT INTO wp_users (user_login, user_pass) VALUES ('bob', '\$wp\$2y\$10\$INSERTHASH')",
	'PHP Notice: smtp auth failed for user bob with password hunter2-plain',
	'stdClass Object ( [access_token] => ya29.OBJTOKEN )',
	str_repeat( 'a', 1990 ) . ' password=hunter2-straddle-xxxxxxxxxxxxxxxxxxxx',
) ) . "\n" );
$r = call( 'error-log' ); $j = json_encode( $r );
check( 'I-1: print_r, var_export, SQL with hashes, "with password x" and a secret across the 2000 cut are all masked', is_array( $r ) && 13 === $r['line_count'] && false === strpos( $j, 'hunter2' ) && false === strpos( $j, 'PRINTR' ) && false === strpos( $j, 'VAREXP' )
	&& false === strpos( $j, 'hashhash' ) && false === strpos( $j, 'INSERTHASH' ) && false === strpos( $j, 'OBJTOKEN' ) && false !== strpos( $j, 'alice' ) && false !== strpos( $j, 'WHERE ID = 1' ), $j );
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
$r = handler( 'users-get', array( 'id' => 2 ) );
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
check( 'defence in depth: the plugins-list handler alone also refuses an editor', 'forbidden' === code( handler( 'plugins-list', array() ) ) );
// multisite: the network capability too
// core-version (M-4): manage_network, not update_core (update_core is do_not_allow under DISALLOW_FILE_MODS)
foreach ( array( 'plugins-list' => 'manage_network_plugins', 'themes-list' => 'manage_network_themes', 'core-version' => 'manage_network' ) as $ab => $netcap ) {
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
t_set_caps( array_merge( ADMIN, array( 'update_core' ) ) );
check( 'multisite: core-version with update_core but without manage_network is refused (update_core is no longer the test)', 'FORBIDDEN' === call( 'core-version' ) );
// users-get (Task 5 I-2): on a network, a user who is not a member of this site is not_found
site( ADMIN, 1 ); t_set_multisite( true ); d5b_abilities_set_master( true, 'tester' );
$GLOBALS['T_users'] = array( 1 => mkuser( 1, 'alice', array( 'administrator' ) ), 99 => mkuser( 99, 'othersite', array() ) );
$GLOBALS['T_user_blogs'] = array( 1 => array( 1 ), 99 => array( 2 ) );
$r = call( 'users-get', array( 'id' => 99 ) );
check( 'multisite: users-get on a user of another site is not_found, and nothing of them is returned', 'not_found' === code( $r ) && false === strpos( json_encode( $r ), 'othersite' ), $r );
check( 'multisite: users-get on a member of this site still works', 'alice' === ( call( 'users-get', array( 'id' => 1 ) )['login'] ?? null ) );
t_set_multisite( false );
check( 'single site: no membership check (users-get on any user works)', 'othersite' === ( call( 'users-get', array( 'id' => 99 ) )['login'] ?? null ) );

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
// M-5: without the updates part (wpsu_detect_caches missing) the answer is { unavailable: true }, never an empty list
$out = array(); exec( escapeshellarg( PHP_BINARY ) . ' -d max_execution_time=30 ' . escapeshellarg( __FILE__ ) . ' --no-updates 2>&1', $out, $rc );
check( 'cache-status without wpsu_detect_caches answers exactly { "unavailable": true }', 0 === $rc && 'CACHE={"unavailable":true}' === trim( implode( '', $out ) ), $out );

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

// ---- meta-get with the real scrubber (break-it a5): secret-named custom fields never leave, even for an administrator
echo "meta-get, deep paging and descriptions\n";
site( ADMIN, 1 );
$mp = t_add_post( array( 'post_author' => 2 ) );
t_add_meta( $mp, 'license_key', 'S3CR3T-VALUE' ); t_add_meta( $mp, 'pwd', 'S3CR3T-VALUE' ); t_add_meta( $mp, 'authorization', 'Bearer S3CR3T-VALUE/x=' ); t_add_meta( $mp, 'customer_phone', '+27 82 000 0000' ); t_add_meta( $mp, '_internal_note', 'protected value' );
$r = call( 'meta-get', array( 'post_id' => $mp ) );
check( 'meta-get as admin: license_key, pwd and authorization are removed; ordinary and _ fields come through', is_array( $r ) && false === strpos( json_encode( $r ), 'S3CR3T' ) && '+27 82 000 0000' === $r['meta']['customer_phone'] && 'protected value' === $r['meta']['_internal_note'] && array( 'customer_phone', '_internal_note' ) === array_keys( $r['meta'] ), $r );
t_add_meta( $mp, 'note2', 'smtp password' );
$r = call( 'meta-get', array( 'post_id' => $mp ) );
check( 'fail-safe: a small meta map holding a value that names a secret ("smtp password") hides its neighbours, keeps the label', is_array( $r ) && '[redacted]' === $r['meta']['customer_phone'] && 'smtp password' === $r['meta']['note2'] && true === $r['masked'], $r );
// deep paging in the site abilities too
$GLOBALS['T_users'] = array( 1 => mkuser( 1, 'alice', array( 'administrator' ) ) );
check( 'deep paging: users-list and templates-list refuse a page more than 10,000 items in (page_too_deep), page 101 x 100 still answers', 'page_too_deep' === code( call( 'users-list', array( 'page' => 102, 'per_page' => 100 ) ) ) && 'page_too_deep' === code( call( 'templates-list', array( 'page' => 600 ) ) ) && is_array( call( 'users-list', array( 'page' => 101, 'per_page' => 100 ) ) ) );
$defs = d5b_ability_defs();
$d = function ( $x ) use ( $defs ) { return (string) $defs[ 'divi5-builder/' . $x ]['description']; };
check( 'descriptions: appearance-get and widgets-list say "values under secret-named keys removed", never "secrets removed"', false !== strpos( $d( 'appearance-get' ), 'values under secret-named keys removed' ) && false !== strpos( $d( 'widgets-list' ), 'values under secret-named keys removed' ) && false === strpos( json_encode( array_column( $defs, 'description' ) ), 'secrets removed' ) );
check( 'descriptions: menus-list and menus-locations name edit_theme_options; core-version names manage_network; meta-get names edit_post', false !== strpos( $d( 'menus-list' ), 'edit_theme_options' ) && false !== strpos( $d( 'menus-locations' ), 'edit_theme_options' ) && false !== strpos( $d( 'core-version' ), 'manage_network' ) && false === strpos( $d( 'core-version' ), 'update_core' ) && false !== strpos( $d( 'meta-get' ), 'edit_post' ) );

// ---- round 2: get abilities say when the scrubber masked something (break-it Minor 6)
echo "round 2/3: masked flag; post-content fields (round 3: content_keys per definition)\n";
$flagged = array_keys( array_filter( d5b_ability_defs(), function ( $x ) { return ! empty( $x['content_keys'] ); } ) ); sort( $flagged );
$want_flag = array_map( function ( $x ) { return 'divi5-builder/' . $x; }, array( 'appearance-get', 'blocks-get', 'blocks-list', 'cpt-get', 'cpt-list', 'global-styles-get', 'pages-get', 'pages-list', 'posts-get', 'posts-list', 'posts-revisions', 'revisions-compare', 'revisions-get', 'revisions-list', 'templates-get', 'templates-list' ) );
check( 'exactly the post-like abilities (posts, pages, cpt, blocks, revisions, templates, global styles, appearance) name content keys', $want_flag === $flagged, $flagged );
site( ADMIN, 1 );
$hashy = 'Price $2y$10$abcdefghijklmnopqrstuv end';
t_add_type( 'book' );
$mpost = t_add_post( array( 'post_content' => $hashy ) ); $plain = t_add_post( array( 'post_content' => 'Nothing secret, $5 only.' ) );
$mpage = t_add_post( array( 'post_type' => 'page', 'post_content' => $hashy ) ); $mblk = t_add_post( array( 'post_type' => 'wp_block', 'post_content' => $hashy ) );
$mbook = t_add_post( array( 'post_type' => 'book', 'post_content' => $hashy ) );
$mrev = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $plain, 'post_content' => $hashy ) );
$matt = t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_content' => $hashy ) );
$GLOBALS['T_block_theme'] = true; $GLOBALS['T_block_templates'] = array( (object) array( 'id' => 'theme//home', 'slug' => 'home', 'theme' => 'theme', 'type' => 'wp_template', 'title' => 'Home', 'source' => 'theme', 'status' => 'publish', 'has_theme_file' => true, 'is_custom' => false, 'content' => $hashy ) );
$bad = array();
foreach ( array( array( 'posts-get', array( 'id' => $mpost ) ), array( 'pages-get', array( 'id' => $mpage ) ), array( 'blocks-get', array( 'id' => $mblk ) ), array( 'cpt-get', array( 'type' => 'book', 'id' => $mbook ) ),
	array( 'revisions-get', array( 'id' => $mrev ) ), array( 'revisions-compare', array( 'from' => $mrev, 'to' => $plain ) ), array( 'media-get', array( 'id' => $matt ) ), array( 'templates-get', array( 'id' => 'theme//home' ) ) ) as $c ) {
	$r = call( $c[0], $c[1] );
	if ( ! is_array( $r ) || true !== ( $r['masked'] ?? null ) || false !== strpos( json_encode( $r ), 'abcdefghijklmnop' ) ) { $bad[] = $c[0] . ':' . json_encode( is_array( $r ) ? ( $r['masked'] ?? 'absent' ) : code( $r ) ); }
}
check( 'a masked secret shape in the content: every get of post-like content returns "masked": true', array() === $bad, $bad );
$r = call( 'posts-get', array( 'id' => $plain ) );
check( 'nothing masked: no "masked" key at all', is_array( $r ) && ! array_key_exists( 'masked', $r ), $r );
$r = call( 'search', array( 'query' => 'Price' ) );
check( 'round 3: every ability says masked: true when a string was masked (here a search snippet)', is_array( $r ) && true === ( $r['masked'] ?? null ) && false !== strpos( json_encode( $r ), '[redacted]' ), $r );
$r = call( 'search', array( 'query' => 'Nothing secret' ) );
check( '... and no "masked" key when nothing was', is_array( $r ) && ! array_key_exists( 'masked', $r ), $r );
$r = call( 'posts-get', array( 'id' => $mpost ) ); $r2 = call( 'posts-get', array( 'id' => $plain ) );
check( 'the flag is per call (a masked read before does not flag the next clean one)', true === $r['masked'] && ! array_key_exists( 'masked', $r2 ) );

// ---- the keep list is built from reality: every ability, as an administrator, over ordinary data (no secrets planted)
echo "keep list: no field our abilities return is called a secret\n";
site( ADMIN, 1 );
$GLOBALS['T_block_theme'] = true;
$GLOBALS['T_users'] = array( 1 => mkuser( 1, 'alice', array( 'administrator' ) ), 2 => mkuser( 2, 'bob', array( 'author' ) ) );
foreach ( d5b_rs_settings_keys() as $k ) { $GLOBALS['T_options'][ $k ] = 'x'; }
$GLOBALS['T_options']['active_plugins'] = array( 'a/a.php' );
$GLOBALS['T_plugins'] = array( 'a/a.php' => array( 'Name' => 'Alpha', 'Version' => '1.0' ) );
$GLOBALS['T_site_transients'] = array( 'update_plugins' => (object) array( 'response' => array( 'a/a.php' => (object) array( 'new_version' => '1.1' ) ) ), 'update_core' => (object) array( 'updates' => array( (object) array( 'response' => 'upgrade', 'current' => '7.0' ) ) ) );
$GLOBALS['T_theme'] = theme_row( 'child', 'Child', '1.0', theme_row( 'parent', 'Parent', '3.0' ), true ); $GLOBALS['T_themes'] = array( $GLOBALS['T_theme'] );
$GLOBALS['T_theme_mods'] = array( 'header_color' => '#ff0000', 'nav_menu_locations' => array( 'primary' => 7 ), 'custom_css_post_id' => 12 );
$GLOBALS['T_custom_css'] = (object) array( 'post_content' => 'body { color: red; }' );
$GLOBALS['T_sidebars'] = array( 'sidebar-1' => array( 'text-3' ) ); $GLOBALS['wp_registered_sidebars'] = array( 'sidebar-1' => array( 'name' => 'Main' ) );
$GLOBALS['T_options']['widget_text'] = array( 3 => array( 'title' => 'Hello', 'text' => 'Welcome', 'filter' => true, 'visual' => true ) );
$GLOBALS['T_cron_array'] = array( 86400 => array( 'wp_version_check' => array( 'k' => array( 'schedule' => 'twicedaily', 'args' => array(), 'interval' => 43200 ) ) ) );
$GLOBALS['T_block_templates'] = array( (object) array( 'id' => 'theme//home', 'slug' => 'home', 'theme' => 'theme', 'type' => 'wp_template', 'title' => 'Home', 'source' => 'theme', 'status' => 'publish', 'has_theme_file' => true, 'is_custom' => false, 'content' => '<p>Hi</p>' ) );
set_transient( 'health-check-site-status-result', json_encode( array( 'good' => 1, 'recommended' => 0, 'critical' => 0 ) ), 3600 );
file_put_contents( LOGFILE, "[28-Oct-2026 10:00:00 UTC] PHP Notice: something ordinary\n" );
$kp = t_add_post( array( 'post_title' => 'Ordinary', 'post_author' => 2 ) );
foreach ( array( 'color' => 'blue', '_edit_lock' => '1:2', '_wp_page_template' => 'default', '_et_pb_use_builder' => 'on', '_et_pb_page_layout' => 'et_full_width_page', '_thumbnail_id' => '9' ) as $mk => $mv ) { t_add_meta( $kp, $mk, $mv ); }
$cat = t_add_term( 'category', 'News' ); $GLOBALS['T_object_terms'][ $kp ] = array( 'category' => array( $cat ) );
$kpg = t_add_post( array( 'post_type' => 'page' ) ); t_add_type( 'event' ); $kev = t_add_post( array( 'post_type' => 'event' ) );
$katt = t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png' ) ); t_add_meta( $katt, '_wp_attached_file', '2026/10/a.png' );
t_add_meta( $katt, '_wp_attachment_metadata', array( 'width' => 10, 'height' => 10, 'sizes' => array( 'thumbnail' => array( 'file' => 'a-1.png', 'width' => 5, 'height' => 5, 'mime-type' => 'image/png' ) ) ) );
$kblk = t_add_post( array( 'post_type' => 'wp_block' ) ); $kcm = t_add_comment( array( 'comment_post_ID' => $kp ) );
$krev = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $kp ) );
$GLOBALS['T_menus'][7] = (object) array( 'term_id' => 7, 'name' => 'Main', 'slug' => 'main', 'count' => 2 );
$GLOBALS['T_menu_items'][7] = array( (object) array( 'ID' => 71, 'title' => 'Ordinary', 'url' => '/o', 'type' => 'post_type', 'object' => 'post', 'object_id' => $kp, 'menu_item_parent' => 0, 'menu_order' => 1 ),
	(object) array( 'ID' => 72, 'title' => 'Shop', 'url' => 'https://shop.example', 'type' => 'custom', 'object' => 'custom', 'object_id' => 72, 'menu_item_parent' => 0, 'menu_order' => 2 ) );
$GLOBALS['T_registered_menus'] = array( 'primary' => 'Primary' ); $GLOBALS['T_menu_locations'] = array( 'primary' => 7 );
$kin = array( 'posts-get' => array( 'id' => $kp ), 'posts-revisions' => array( 'id' => $kp ), 'pages-get' => array( 'id' => $kpg ), 'cpt-list' => array( 'type' => 'event' ), 'cpt-get' => array( 'type' => 'event', 'id' => $kev ),
	'media-get' => array( 'id' => $katt ), 'taxonomy-get' => array( 'taxonomy' => 'category', 'id' => $cat ), 'comments-get' => array( 'id' => $kcm ), 'meta-get' => array( 'post_id' => $kp ), 'blocks-get' => array( 'id' => $kblk ),
	'menus-items' => array( 'menu' => 7 ), 'revisions-list' => array( 'post_id' => $kp ), 'revisions-get' => array( 'id' => $krev ), 'revisions-compare' => array( 'from' => $krev, 'to' => $kp ), 'search' => array( 'query' => 'Ordinary' ),
	'users-get' => array( 'id' => 2 ), 'users-list' => array(), 'templates-get' => array( 'id' => 'theme//home' ), 'comments-list' => array( 'post' => $kp ) );
function t_keys( $v, array &$into ) { if ( ! is_array( $v ) ) { return; } foreach ( $v as $k => $x ) { if ( is_string( $k ) ) { $into[ $k ] = true; } t_keys( $x, $into ); } }
$keys = array(); $notarray = array(); $changed = array();
foreach ( array_keys( d5b_ability_defs() ) as $full ) {
	$short = substr( $full, strlen( 'divi5-builder/' ) );
	$raw = handler( $short, $kin[ $short ] ?? array() );
	if ( ! is_array( $raw ) ) { $notarray[] = $short . ':' . code( $raw ); continue; }
	t_keys( $raw, $keys );
	if ( json_encode( d5b_ability_scrub( $raw ) ) !== json_encode( $raw ) ) { $changed[] = $short; }
}
check( 'every ability answered with data over the ordinary site (' . count( d5b_ability_defs() ) . ' abilities, ' . count( $keys ) . ' distinct keys)', array() === $notarray && count( $keys ) > 60, $notarray );
$eaten = array_values( array_filter( array_keys( $keys ), 'd5b_is_secret_key' ) );
check( 'keep list: d5b_is_secret_key is false for every key any ability returns', array() === $eaten, $eaten );
check( '... and the scrubber changes none of those ordinary results', array() === $changed, $changed );

// clean up the temp dir
@unlink( LOGFILE ); @unlink( $other ); @rmdir( $logdir );
echo "\n" . ( $failed ? "$failed of $n FAILED" : "$n / $n passed" ) . "\n";
exit( $failed ? 1 : 0 );
