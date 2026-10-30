<?php
/**
 * Write abilities for site settings and the homepage (#32, release 2.3.0, Task 2).
 *
 *   php scripts/plugin-abilities-write-settings-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php: options, posts, core's sanitize_option for the
 * keys written, the settings errors list, the rewrite object). Set D5B_PLUGIN_DIR to test another copy of
 * plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: only the allow-listed options are ever written (siteurl, home, admin_email and the rest are
 * refused by construction and nothing is written); a call with a risky key is refused whole while the risky switch is off;
 * values are checked by the plugin's own rule and by core's sanitize_option, whose errors come back as invalid_input; one
 * history row per call holds every old value; permalink_structure is saved through the rewrite object and flushed once;
 * text holding [redacted] is refused; the homepage needs the risky switch AND the Pages category, published pages the
 * caller may edit, two different pages; and a risky definition with a risky-capable extra category needs that risky
 * switch too.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.3.0' );
require __DIR__ . '/plugin-test-stubs.php';
foreach ( array( 'parts', 'switches', 'abilities/categories', 'abilities/register', 'abilities/secrets', 'abilities/audit', 'abilities/scrub', 'abilities/history', 'abilities/read-content', 'abilities/read-site', 'abilities/write-common', 'abilities/write-settings' ) as $f ) { require $dir . '/inc/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ADMIN = array( 'read', 'manage_options', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'read_private_posts', 'unfiltered_html' );

function site( bool $risky = false, array $caps = ADMIN ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( false );
	$GLOBALS['T_user_id'] = 3; $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
	if ( $risky ) { d5b_abilities_set_category( 'settings', true, true, 'tester' ); }
	foreach ( array( 'blogname' => 'Old name', 'blogdescription' => 'Old tagline', 'date_format' => 'F j, Y', 'time_format' => 'g:i a', 'start_of_week' => 1, 'posts_per_page' => 10,
		'default_comment_status' => 'open', 'timezone_string' => 'UTC', 'gmt_offset' => '0', 'WPLANG' => '', 'permalink_structure' => '', 'blog_public' => 1,
		'siteurl' => 'https://example.test', 'home' => 'https://example.test', 'admin_email' => 'a@example.test', 'users_can_register' => 0, 'default_role' => 'subscriber',
		'show_on_front' => 'posts', 'page_on_front' => 0, 'page_for_posts' => 0 ) as $k => $v ) { $GLOBALS['T_options'][ $k ] = $v; }
	$GLOBALS['T_writes'] = array();
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function handler( $name, $in = array() ) { return call_user_func( d5b_ability_defs()[ 'divi5-builder/' . $name ]['handler'], $in ); }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function msg( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
function hrows() { global $wpdb; return $wpdb->tables[ d5b_history_table() ] ?? array(); }
function arows() { global $wpdb; return $wpdb->tables[ d5b_audit_table() ] ?? array(); }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function opt( $k ) { return $GLOBALS['T_options'][ $k ] ?? null; }
/** Option writes since site() (update_option, add_option, delete_option). */
function owrites() { return array_values( array_filter( $GLOBALS['T_writes'], function ( $w ) { return in_array( $w[0], array( 'update_option', 'add_option', 'delete_option' ), true ) && 0 !== strpos( (string) $w[1], 'd5b_' ) && '_transient_d5b_anon' !== $w[1]; } ) ); }
function flushes() { return $GLOBALS['wp_rewrite']->flushes; }

echo "registration\n";
$defs = d5b_ability_defs();
$s = $defs['divi5-builder/settings-set'] ?? null; $h = $defs['divi5-builder/settings-set-homepage'] ?? null;
check( 'settings-set and settings-set-homepage exist in the settings category', null !== $s && null !== $h && 'settings' === $s['category'] && 'settings' === $h['category'] );
$ok = true;
foreach ( array( $s, $h ) as $d ) { $a = $d['annotations'] ?? array(); if ( false !== ( $a['readonly'] ?? null ) || false !== ( $a['destructive'] ?? null ) || true !== ( $a['idempotent'] ?? null ) ) { $ok = false; } }
check( 'both: readonly false, destructive false, idempotent true', $ok, array( $s['annotations'] ?? null, $h['annotations'] ?? null ) );
check( 'settings-set is a normal definition; the homepage is risky with the extra category pages', ! array_key_exists( 'risky', $s ) && true === $h['risky'] && array( 'pages' ) === ( $h['extra_categories'] ?? null ) );
$desc = $s['description'];
$all_named = true; foreach ( array( 'blogname', 'blogdescription', 'date_format', 'time_format', 'start_of_week', 'posts_per_page', 'default_comment_status', 'timezone_string', 'gmt_offset', 'WPLANG', 'permalink_structure', 'blog_public' ) as $k ) { if ( false === strpos( $desc, $k ) ) { $all_named = false; } }
check( 'the description names every normal and risky key and says the risky ones need the switch', $all_named && false !== strpos( $desc, 'Risky keys' ) && false !== strpos( $desc, 'risky switch' ) );
check( 'the allow-list holds exactly the spec\'s 7 normal and 5 risky keys', array( 'blogname', 'blogdescription', 'date_format', 'time_format', 'start_of_week', 'posts_per_page', 'default_comment_status' ) === d5b_ws_keys( 'normal' ) && array( 'timezone_string', 'gmt_offset', 'WPLANG', 'permalink_structure', 'blog_public' ) === d5b_ws_keys( 'risky' ) && 12 === count( d5b_ws_allowed() ) );
check( 'settings is required and an object', array( 'settings' ) === $s['input_schema']['required'] && 'object' === $s['input_schema']['properties']['settings']['type'] );

function registered( bool $risky, bool $pages = true ) {
	site( $risky );
	if ( ! $pages ) { d5b_abilities_set_category( 'pages', false, false, 't' ); }
	t_abilities_reset();
	add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' ); add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );
	t_fire( 'wp_abilities_api_categories_init' ); t_fire( 'wp_abilities_api_init' );
	return array( isset( $GLOBALS['T_abilities']['divi5-builder/settings-set'] ), isset( $GLOBALS['T_abilities']['divi5-builder/settings-set-homepage'] ) );
}
check( 'risky off: settings-set is registered, the homepage is not', array( true, false ) === registered( false ) );
check( 'risky on: both registered', array( true, true ) === registered( true ) );
check( 'risky on but Pages off: the homepage is not registered', array( true, false ) === registered( true, false ) );

echo "the risky gate and extra categories\n";
$GLOBALS['T_extra_ran'] = 0;
d5b_ability_add( array( 'name' => 'divi5-builder/t-risky-extra', 'category' => 'settings', 'label' => 'x', 'description' => 'x', 'risky' => true,
	'handler' => function () { $GLOBALS['T_extra_ran']++; return array( 'ok' => true ); }, 'permission' => function () { return true; }, 'annotations' => array() ) );
d5b_ability_add( array( 'name' => 'divi5-builder/t-risky-pages', 'category' => 'settings', 'label' => 'x', 'description' => 'x', 'risky' => true,
	'handler' => function () { return array( 'ok' => true ); }, 'permission' => function () { return true; }, 'annotations' => array() ) );
d5b_ability_set_extra_categories( array( 't-risky-extra' => array( 'templates' ), 't-risky-pages' => array( 'pages' ) ) );
site( true );
check( 'settings risky on, templates risky off: a risky def with extra category templates is refused (permit and run)', false === d5b_ability_permit( 'divi5-builder/t-risky-extra', array() ) && 'forbidden' === code( d5b_ability_run( 'divi5-builder/t-risky-extra', array() ) ) && 0 === $GLOBALS['T_extra_ran'] );
check( 'with extra category pages (no risky switch of its own) the settings risky switch is enough', true === d5b_ability_permit( 'divi5-builder/t-risky-pages', array() ) );
d5b_abilities_set_category( 'templates', true, true, 't' );
check( 'both risky switches on: allowed', true === d5b_ability_permit( 'divi5-builder/t-risky-extra', array() ) && array( 'ok' => true ) === d5b_ability_run( 'divi5-builder/t-risky-extra', array() ) );
d5b_abilities_set_category( 'settings', true, false, 't' );
check( 'templates risky on, settings risky off: refused (its own category still counts)', false === d5b_ability_permit( 'divi5-builder/t-risky-extra', array() ) );
$defs = d5b_ability_registry(); unset( $defs['divi5-builder/t-risky-extra'], $defs['divi5-builder/t-risky-pages'] ); d5b_ability_registry( $defs );

echo "settings-set: the allow-list\n";
foreach ( array( 'siteurl' => 'https://evil.test', 'home' => 'https://evil.test', 'admin_email' => 'x@evil.test', 'users_can_register' => 1, 'default_role' => 'administrator', 'nonsense_key' => 'x' ) as $k => $v ) {
	site( true );
	$r = call( 'settings-set', array( 'settings' => array( 'blogname' => 'New', $k => $v ) ) );
	check( "$k is refused invalid_input with the allow-list named; nothing written, no history row", 'invalid_input' === code( $r ) && false !== strpos( msg( $r ), 'Allowed: normal: blogname' ) && false !== strpos( msg( $r ), 'blog_public' )
		&& array() === owrites() && 'Old name' === opt( 'blogname' ) && 0 === count( hrows() ), array( code( $r ), msg( $r ), owrites() ) );
}
site( true );
$r = handler( 'settings-set', array( 'settings' => array( "bl\0og<script>" => 'x' ) ) );
check( 'a hostile key is refused and not echoed raw', 'invalid_input' === code( $r ) && false === strpos( msg( $r ), '<script>' ) && false === strpos( msg( $r ), "\0" ) );
check( 'settings empty, a list, a string, or an extra input: invalid_input', 'invalid_input' === code( handler( 'settings-set', array( 'settings' => array() ) ) ) && 'invalid_input' === code( handler( 'settings-set', array( 'settings' => array( 'x', 'y' ) ) ) )
	&& 'invalid_input' === code( handler( 'settings-set', array( 'settings' => 'blogname' ) ) ) && 'invalid_input' === code( handler( 'settings-set', array( 'settings' => array( 'blogname' => 'a' ), 'siteurl' => 'x' ) ) ) && array() === owrites() );

echo "settings-set: normal keys\n";
site( false );
$new = array( 'blogname' => 'Fresh & <b>Co</b>', 'blogdescription' => 'Bread daily', 'date_format' => 'Y-m-d', 'time_format' => 'H:i', 'start_of_week' => 0, 'posts_per_page' => '12', 'default_comment_status' => 'closed' );
$r = call( 'settings-set', array( 'settings' => $new ) );
check( 'normal keys write with risky off', is_array( $r ) && 'Bread daily' === opt( 'blogdescription' ) && 'Y-m-d' === opt( 'date_format' ) && 'H:i' === opt( 'time_format' ) && 0 === opt( 'start_of_week' ) && 12 === opt( 'posts_per_page' ) && 'closed' === opt( 'default_comment_status' ), $r );
check( 'blogname is stored as core\'s sanitize_option leaves it (escaped)', 'Fresh &amp; &lt;b&gt;Co&lt;/b&gt;' === opt( 'blogname' ) && opt( 'blogname' ) === $r['settings']['blogname'], opt( 'blogname' ) );
$row = hlast();
check( 'one history row: option, id 0, update, before holds every old value', 1 === count( hrows() ) && 'option' === $row['object_type'] && 0 === (int) $row['object_id'] && 'update' === $row['action'] && 'divi5-builder/settings-set' === $row['ability']
	&& array( 'blogname' => 'Old name', 'blogdescription' => 'Old tagline', 'date_format' => 'F j, Y', 'time_format' => 'g:i a', 'start_of_week' => 1, 'posts_per_page' => 10, 'default_comment_status' => 'open' ) === $row['before'], $row );
check( 'after holds the stored values; summary lists the changed keys', $row['after']['posts_per_page'] === 12 && 'Changed settings: blogname, blogdescription, date_format, time_format, start_of_week, posts_per_page, default_comment_status' === $row['summary'], $row['summary'] );
check( 'the result lists the changed keys and the audit row is ok', $r['changed'] === array_keys( $new ) && 'ok' === end( $GLOBALS['wpdb']->tables[ d5b_audit_table() ] )['status'] );
$before_rows = count( hrows() );
$r = call( 'settings-set', array( 'settings' => array( 'blogdescription' => 'Bread daily' ) ) );
check( 'a call that changes nothing writes nothing and no row', array() === $r['changed'] && $before_rows === count( hrows() ) );
$GLOBALS['T_writes'] = array();
$r = call( 'settings-set', array( 'settings' => array( 'blogdescription' => 'Old tagline', 'posts_per_page' => 12 ) ) );
check( 'only the keys that change are written, but the row keeps the old value of every key given', array( 'blogdescription' ) === $r['changed'] && array( array( 'update_option', 'blogdescription' ) ) === owrites() && array( 'blogdescription' => 'Bread daily', 'posts_per_page' => 12 ) === hlast()['before'], owrites() );

echo "settings-set: risky keys\n";
site( false );
$r = call( 'settings-set', array( 'settings' => array( 'blogname' => 'New', 'timezone_string' => 'Africa/Johannesburg' ) ) );
check( 'a mix with one risky key and risky off: the whole call is refused not_allowed, naming the key; nothing written', 'not_allowed' === code( $r ) && false !== strpos( msg( $r ), 'timezone_string' ) && array() === owrites() && 'Old name' === opt( 'blogname' ) && 0 === count( hrows() ), $r );
check( 'the same refusal straight to the handler', 'not_allowed' === code( handler( 'settings-set', array( 'settings' => array( 'blog_public' => 0 ) ) ) ) && array() === owrites() );
site( true );
$GLOBALS['T_languages'] = array( 'af', 'de_DE' );
$r = call( 'settings-set', array( 'settings' => array( 'blogname' => 'New', 'timezone_string' => 'Africa/Johannesburg', 'gmt_offset' => 5.5, 'WPLANG' => 'af', 'blog_public' => '0' ) ) );
check( 'risky on: the mix writes all', is_array( $r ) && 'New' === opt( 'blogname' ) && 'Africa/Johannesburg' === opt( 'timezone_string' ) && '5.5' === opt( 'gmt_offset' ) && 'af' === opt( 'WPLANG' ) && 0 === opt( 'blog_public' ) && 1 === count( hrows() ), $r );

echo "settings-set: values\n";
$cases = array(
	array( 'posts_per_page', 'abc' ), array( 'posts_per_page', 0 ), array( 'posts_per_page', 101 ), array( 'posts_per_page', 1.5 ), array( 'posts_per_page', true ),
	array( 'start_of_week', 7 ), array( 'start_of_week', -1 ), array( 'default_comment_status', 'maybe' ),
	array( 'WPLANG', 'fr_FR' ), array( 'WPLANG', 'en_US' ), array( 'blog_public', 2 ), array( 'blog_public', 'yes' ),
	array( 'timezone_string', 'Mars/Base' ), array( 'gmt_offset', '1e0' ), array( 'gmt_offset', 15 ), array( 'gmt_offset', 'abc' ),
	array( 'date_format', str_repeat( 'Y', 51 ) ), array( 'blogname', array( 'x' ) ), array( 'blogname', "bad \xC3\x28 utf8" ), array( 'blogdescription', str_repeat( 'a', 1001 ) ),
);
foreach ( $cases as $c ) {
	site( true ); $GLOBALS['T_languages'] = array( 'af' );
	$r = call( 'settings-set', array( 'settings' => array( 'blogname' => 'Changed', $c[0] => $c[1] ) ) );
	check( $c[0] . ' ' . json_encode( $c[1] ) . ' is refused invalid_input naming the key; nothing written', 'invalid_input' === code( $r ) && 0 === strpos( msg( $r ), $c[0] ) && array() === owrites() && 0 === count( hrows() ), array( code( $r ), msg( $r ) ) );
}
site( true ); $GLOBALS['T_languages'] = array( 'af' );
$r = call( 'settings-set', array( 'settings' => array( 'WPLANG' => 'fr_FR' ) ) );
check( 'WPLANG not installed: the message lists the installed languages', false !== strpos( msg( $r ), 'af' ) && false !== strpos( msg( $r ), 'Install' ) );
foreach ( array( array( 'posts_per_page', 100, 100 ), array( 'posts_per_page', '1', 1 ), array( 'gmt_offset', -9.5, '-9.5' ), array( 'gmt_offset', '14', '14' ), array( 'WPLANG', '', '' ), array( 'timezone_string', '', '' ), array( 'blog_public', true, 1 ), array( 'start_of_week', '6', 6 ) ) as $c ) {
	site( true ); $GLOBALS['T_options']['WPLANG'] = 'af'; $GLOBALS['T_options']['blog_public'] = 0; $GLOBALS['T_options']['posts_per_page'] = 7; $GLOBALS['T_options']['gmt_offset'] = '3';
	$r = call( 'settings-set', array( 'settings' => array( $c[0] => $c[1] ) ) );
	check( $c[0] . ' ' . json_encode( $c[1] ) . ' is accepted and stored as ' . json_encode( $c[2] ), is_array( $r ) && $c[2] === opt( $c[0] ), array( $r, opt( $c[0] ) ) );
}

echo "settings-set: core's own refusals\n";
site( false );
add_filter( 'sanitize_option_blogdescription', function ( $v ) { add_settings_error( 'blogdescription', 'invalid_blogdescription', 'Taglines may not mention <em>cheese</em>.' ); return get_option( 'blogdescription' ); } );
$r = call( 'settings-set', array( 'settings' => array( 'blogname' => 'Fine', 'blogdescription' => 'cheese' ) ) );
check( 'a value core rejects (add_settings_error) answers invalid_input with core\'s message, tags stripped; nothing written', 'invalid_input' === code( $r ) && 'blogdescription: Taglines may not mention cheese.' === msg( $r ) && array() === owrites() && 'Old name' === opt( 'blogname' ), msg( $r ) );
unset( $GLOBALS['T_filters']['sanitize_option_blogdescription'] );
site( false );
add_settings_error( 'blogname', 'old', 'An error from before the call' );
$r = call( 'settings-set', array( 'settings' => array( 'blogname' => 'Fine' ) ) );
check( 'an error already in the list before the call does not refuse it', is_array( $r ) && 'Fine' === opt( 'blogname' ) );

echo "settings-set: permalink_structure\n";
$home = sys_get_temp_dir() . '/d5b-ws-home-' . getmypid() . '/';
@mkdir( $home ); @unlink( $home . '.htaccess' ); @unlink( $home . 'web.config' );
site( true ); $GLOBALS['T_home_path'] = $home; file_put_contents( $home . '.htaccess', "# BEGIN WordPress\n" );
$r = call( 'settings-set', array( 'settings' => array( 'permalink_structure' => '/%postname%/' ) ) );
check( 'permalink_structure writes through the rewrite object (its own copy updated)', is_array( $r ) && '/%postname%/' === opt( 'permalink_structure' ) && '/%postname%/' === $GLOBALS['wp_rewrite']->permalink_structure && 1 === $GLOBALS['wp_rewrite']->inits, $r );
check( 'flushes once, hard, after the structure changed', array( array( 'hard' => true, 'structure' => '/%postname%/' ) ) === flushes(), flushes() );
check( 'rewrite_file_written true: Apache and a writable .htaccess', true === $r['rewrite_file_written'] && array( 'permalink_structure' ) === $r['changed'] && 1 === count( hrows() ) && array( 'permalink_structure' => '' ) === hlast()['before'] );
site( true ); $GLOBALS['T_home_path'] = $home; unlink( $home . '.htaccess' );
$r = call( 'settings-set', array( 'settings' => array( 'permalink_structure' => '/%year%/%postname%/' ) ) );
check( 'no .htaccess: rewrite_file_written false, the call still succeeds', is_array( $r ) && false === $r['rewrite_file_written'] && '/%year%/%postname%/' === opt( 'permalink_structure' ) && 1 === count( flushes() ) );
site( true ); $GLOBALS['T_home_path'] = $home; $GLOBALS['T_iis7'] = true; $GLOBALS['T_mod_rewrite'] = false; file_put_contents( $home . 'web.config', '<configuration/>' );
$r = call( 'settings-set', array( 'settings' => array( 'permalink_structure' => '/%postname%/' ) ) );
check( 'IIS: web.config is the file asked about', is_array( $r ) && true === $r['rewrite_file_written'] );
unlink( $home . 'web.config' ); @rmdir( $home );
site( true );
$r = call( 'settings-set', array( 'settings' => array( 'permalink_structure' => 'postname//%postname%#' ) ) );
check( 'prepared as the Permalinks screen does: leading slash, no double slash, no #', is_array( $r ) && '/postname/%postname%' === opt( 'permalink_structure' ), opt( 'permalink_structure' ) );
site( true ); $GLOBALS['T_mod_rewrite'] = false;
$r = call( 'settings-set', array( 'settings' => array( 'permalink_structure' => '/%postname%/' ) ) );
// core's screen adds /index.php to a typed structure only together with the /blog prefix (its radio values carry it otherwise).
check( 'no URL rewriting: stored as the screen stores a typed structure; rewrite_file_written false', is_array( $r ) && '/%postname%/' === opt( 'permalink_structure' ) && false === $r['rewrite_file_written'], opt( 'permalink_structure' ) );
site( true ); $GLOBALS['T_mod_rewrite'] = false; t_set_multisite( true ); d5b_abilities_set_master( true, 't' ); d5b_abilities_set_category( 'settings', true, true, 't' ); $GLOBALS['T_options']['permalink_structure'] = '/blog/%postname%/'; $GLOBALS['wp_rewrite']->permalink_structure = '/blog/%postname%/';
$r = call( 'settings-set', array( 'settings' => array( 'permalink_structure' => '/%year%/%postname%/' ) ) );
check( 'main site of a sub-folder network without URL rewriting: /index.php in front, as core', is_array( $r ) && '/index.php/%year%/%postname%/' === opt( 'permalink_structure' ) && false === $r['rewrite_file_written'], opt( 'permalink_structure' ) );
site( true ); t_set_multisite( true ); d5b_abilities_set_master( true, 't' ); d5b_abilities_set_category( 'settings', true, true, 't' ); $GLOBALS['T_options']['permalink_structure'] = '/blog/%postname%/'; $GLOBALS['wp_rewrite']->permalink_structure = '/blog/%postname%/';
$r = call( 'settings-set', array( 'settings' => array( 'permalink_structure' => '/%year%/%postname%/' ) ) );
check( '... with URL rewriting: /blog in front, as core', is_array( $r ) && '/blog/%year%/%postname%/' === opt( 'permalink_structure' ), opt( 'permalink_structure' ) );
site( true );
$r = call( 'settings-set', array( 'settings' => array( 'blogname' => 'X', 'permalink_structure' => '/archive/' ) ) );
check( 'a structure without a tag: core\'s error as invalid_input, no tags, nothing written, no flush', 'invalid_input' === code( $r ) && 0 === strpos( msg( $r ), 'permalink_structure: A structure tag is required' ) && false === strpos( msg( $r ), '<a' ) && array() === owrites() && array() === flushes(), msg( $r ) );
site( true ); $GLOBALS['T_options']['permalink_structure'] = '/%postname%/'; $GLOBALS['wp_rewrite']->permalink_structure = '/%postname%/';
$r = call( 'settings-set', array( 'settings' => array( 'permalink_structure' => '' ) ) );
check( '"" (plain links) is written and flushed', is_array( $r ) && '' === opt( 'permalink_structure' ) && 1 === count( flushes() ) && array( 'permalink_structure' => '/%postname%/' ) === hlast()['before'] );
site( true );
$r = call( 'settings-set', array( 'settings' => array( 'blogname' => 'Y' ) ) );
check( 'no flush when the permalink structure is not given', is_array( $r ) && array() === flushes() && ! array_key_exists( 'rewrite_file_written', $r ) );

echo "settings-set: masked text, capability\n";
site( false );
$r = call( 'settings-set', array( 'settings' => array( 'blogname' => 'Fine', 'blogdescription' => 'Call us [redacted]' ) ) );
check( 'a value holding [redacted] is refused masked_read; nothing written', 'masked_read' === code( $r ) && array() === owrites(), $r );
site( false ); $GLOBALS['T_options']['blogdescription'] = 'Old [redacted] tagline';
$r = call( 'settings-set', array( 'settings' => array( 'blogdescription' => 'New [redacted] tagline' ) ) );
check( '... unless the stored value holds it too', is_array( $r ) && 'New [redacted] tagline' === opt( 'blogdescription' ) );
site( true, array( 'read', 'edit_posts' ) );
check( 'without manage_options: refused by the gate and by the handler', 'FORBIDDEN' === call( 'settings-set', array( 'settings' => array( 'blogname' => 'X' ) ) ) && 'forbidden' === code( handler( 'settings-set', array( 'settings' => array( 'blogname' => 'X' ) ) ) ) && 'Old name' === opt( 'blogname' ) );
site( false ); d5b_abilities_set_category( 'settings', false, false, 't' );
check( 'Settings category off: refused', 'FORBIDDEN' === call( 'settings-set', array( 'settings' => array( 'blogname' => 'X' ) ) ) && 'Old name' === opt( 'blogname' ) );

echo "settings-set-homepage\n";
function pages() {
	return array(
		'home' => t_add_post( array( 'post_type' => 'page', 'post_title' => 'Home', 'post_author' => 3 ) ),
		'blog' => t_add_post( array( 'post_type' => 'page', 'post_title' => 'News', 'post_author' => 3 ) ),
		'draft' => t_add_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_author' => 3 ) ),
		'post' => t_add_post( array( 'post_type' => 'post', 'post_author' => 3 ) ),
		'private' => t_add_post( array( 'post_type' => 'page', 'post_status' => 'private', 'post_author' => 3 ) ),
	);
}
site( true ); $p = pages();
foreach ( array(
	'a missing page' => array( 'show_on_front' => 'page', 'page_on_front' => 99999 ),
	'a draft page' => array( 'show_on_front' => 'page', 'page_on_front' => $p['draft'] ),
	'a private page' => array( 'show_on_front' => 'page', 'page_on_front' => $p['private'] ),
	'a post id' => array( 'show_on_front' => 'page', 'page_on_front' => $p['post'] ),
	'the same page twice' => array( 'show_on_front' => 'page', 'page_on_front' => $p['home'], 'page_for_posts' => $p['home'] ),
	'a draft posts page' => array( 'show_on_front' => 'page', 'page_on_front' => $p['home'], 'page_for_posts' => $p['draft'] ),
	'a post as posts page' => array( 'show_on_front' => 'page', 'page_on_front' => $p['home'], 'page_for_posts' => $p['post'] ),
	'page without page_on_front' => array( 'show_on_front' => 'page' ),
	'posts with a page id' => array( 'show_on_front' => 'posts', 'page_on_front' => $p['home'] ),
	'a bad show_on_front' => array( 'show_on_front' => 'blog' ),
) as $what => $in ) {
	$r = call( 'settings-set-homepage', $in );
	check( "homepage: $what is refused invalid_input; nothing written", 'invalid_input' === code( $r ) && array() === owrites() && 0 === count( hrows() ), array( code( $r ), msg( $r ) ) );
}
site( true, array( 'read', 'manage_options' ) ); $p = pages();
$r = call( 'settings-set-homepage', array( 'show_on_front' => 'page', 'page_on_front' => $p['home'] ) );
check( 'homepage: a page the caller may not edit is refused', 'invalid_input' === code( $r ) && array() === owrites() );
site( true ); $p = pages(); d5b_abilities_set_category( 'pages', false, false, 't' );
check( 'homepage: Pages off is refused by the gate, and by the handler (not_allowed)', 'FORBIDDEN' === call( 'settings-set-homepage', array( 'show_on_front' => 'page', 'page_on_front' => $p['home'] ) )
	&& 'not_allowed' === code( handler( 'settings-set-homepage', array( 'show_on_front' => 'page', 'page_on_front' => $p['home'] ) ) ) && 'not_allowed' === code( handler( 'settings-set-homepage', array( 'show_on_front' => 'posts' ) ) ) && array() === owrites() );
site( false ); $p = pages();
check( 'homepage: risky off is refused by the gate, and by the handler (not_allowed)', 'FORBIDDEN' === call( 'settings-set-homepage', array( 'show_on_front' => 'page', 'page_on_front' => $p['home'] ) )
	&& 'not_allowed' === code( handler( 'settings-set-homepage', array( 'show_on_front' => 'page', 'page_on_front' => $p['home'] ) ) ) && array() === owrites() );
site( true ); $p = pages();
$r = call( 'settings-set-homepage', array( 'show_on_front' => 'page', 'page_on_front' => $p['home'], 'page_for_posts' => $p['blog'] ) );
$row = hlast();
check( 'homepage success: three options written', is_array( $r ) && 'page' === opt( 'show_on_front' ) && $p['home'] === opt( 'page_on_front' ) && $p['blog'] === opt( 'page_for_posts' ) && 3 === count( owrites() ), $r );
check( '... one history row with the three old values and the summary', 1 === count( hrows() ) && 'option' === $row['object_type'] && 0 === (int) $row['object_id'] && 'divi5-builder/settings-set-homepage' === $row['ability']
	&& array( 'show_on_front' => 'posts', 'page_on_front' => 0, 'page_for_posts' => 0 ) === $row['before'] && "Set the homepage to page {$p['home']} 'Home'" === $row['summary'] && 'ok' === end( $GLOBALS['wpdb']->tables[ d5b_audit_table() ] )['status'], $row );
$r = call( 'settings-set-homepage', array( 'show_on_front' => 'page', 'page_on_front' => $p['home'], 'page_for_posts' => $p['blog'] ) );
check( 'the same call again changes nothing and writes no row', is_array( $r ) && array() === $r['changed'] && 1 === count( hrows() ) );
$GLOBALS['T_writes'] = array();
$r = call( 'settings-set-homepage', array( 'show_on_front' => 'posts' ) );
check( 'back to the latest posts: all three written (ids 0), the row keeps the page ids', is_array( $r ) && 'posts' === opt( 'show_on_front' ) && 0 === opt( 'page_on_front' ) && 0 === opt( 'page_for_posts' )
	&& array( 'show_on_front' => 'page', 'page_on_front' => $p['home'], 'page_for_posts' => $p['blog'] ) === hlast()['before'] && 'Set the homepage to the latest posts' === hlast()['summary'], hlast() );
site( true ); $p = pages();
$r = call( 'settings-set-homepage', array( 'show_on_front' => 'page', 'page_on_front' => $p['home'], 'page_for_posts' => 0 ) );
check( 'page_for_posts 0 is allowed', is_array( $r ) && $p['home'] === opt( 'page_on_front' ) && 0 === opt( 'page_for_posts' ) );

echo "history failure\n";
site( false );
call( 'settings-set', array( 'settings' => array( 'blogname' => 'Y' ) ) );
unset( $GLOBALS['wpdb']->tables[ d5b_history_table() ] ); $GLOBALS['T_dbdelta_fail'] = true; set_transient( 'd5b_audit_install_failed', 1, 3600 );
$r = call( 'settings-set', array( 'settings' => array( 'blogname' => 'Z' ) ) );
unset( $GLOBALS['T_dbdelta_fail'] );
check( 'when the row cannot be stored the change is made, the caller warned, the audit row ok_no_history', is_array( $r ) && 'Z' === opt( 'blogname' ) && ! array_key_exists( '_history_failed', $r ) && ! empty( $r['warnings'] )
	&& 'ok_no_history' === end( $GLOBALS['wpdb']->tables[ d5b_audit_table() ] )['status'], array( $r, end( $GLOBALS['wpdb']->tables[ d5b_audit_table() ] ) ) );

echo "source\n";
$src = file_get_contents( $dir . '/inc/abilities/write-settings.php' );
check( 'write-settings.php registers no hooks, routes or abilities itself', ! preg_match( '/\badd_action\s*\(|\badd_filter\s*\(|register_rest_route\s*\(|\bwp_register_ability\s*\(/', $src ) );
check( 'siteurl, home and admin_email appear nowhere in the allow-list', ! array_intersect( array( 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'show_on_front' ), array_keys( d5b_ws_allowed() ) ) );
check( 'the loader lists write-settings.php after write-divi.php', 1 === preg_match( "#'inc/abilities/write-divi\.php',\s+'inc/abilities/write-settings\.php',#", file_get_contents( $dir . '/divi5-builder.php' ) ) );

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
