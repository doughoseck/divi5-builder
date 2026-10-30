<?php
/**
 * Write abilities for theme mods and Custom CSS (#32, release 2.3.0, Task 3).
 *
 *   php scripts/plugin-abilities-write-appearance-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php: theme mods, the Custom CSS post, capabilities).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: a theme mod name is plain ASCII and never a reserved, secret-named or case-variant name; a mod
 * whose current value holds a secret key cannot be set or deleted; the history row keeps the old value (or absent) so the
 * change can be undone; deleting an absent mod is not_found; Custom CSS needs edit_css, a matching expect_hash (stale
 * otherwise), no </style, at most 256 KB, no [redacted]; the row keeps the whole previous stylesheet; appearance-get
 * returns custom_css_hash; every ability is absent while the Appearance risky switch is off.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.3.0' );
require __DIR__ . '/plugin-test-stubs.php';
foreach ( array( 'parts', 'switches', 'abilities/categories', 'abilities/register', 'abilities/secrets', 'abilities/audit', 'abilities/scrub', 'abilities/history', 'abilities/read-content', 'abilities/read-site', 'abilities/write-common', 'abilities/write-appearance' ) as $f ) { require $dir . '/inc/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ADMIN = array( 'read', 'manage_options', 'edit_posts', 'unfiltered_html' );

class T_Th { function get_stylesheet() { return 'child'; } function get_template() { return 'parent'; } function get( $k ) { return 'Child'; } function parent() { return false; } function is_block_theme() { return false; } }
function wp_get_theme() { return $GLOBALS['T_theme']; }

function site( bool $risky = false, array $caps = ADMIN ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( false );
	$GLOBALS['T_user_id'] = 3; $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	$GLOBALS['T_theme'] = new T_Th();
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
	if ( $risky ) { d5b_abilities_set_category( 'appearance', true, true, 'tester' ); }
	$GLOBALS['T_theme_mods'] = array( 'header_color' => '#ff0000', 'layout' => array( 'cols' => 3, 'wide' => true ), 'nav_menu_locations' => array( 'primary' => 7 ), 'custom_css_post_id' => 12 );
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function handler( $name, $in = array() ) { return call_user_func( d5b_ability_defs()[ 'divi5-builder/' . $name ]['handler'], $in ); }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function msg( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
function hrows() { global $wpdb; return $wpdb->tables[ d5b_history_table() ] ?? array(); }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function mods() { return $GLOBALS['T_theme_mods']; }
function css_is( $s ) { $GLOBALS['T_custom_css'] = (object) array( 'ID' => 55, 'post_type' => 'custom_css', 'post_content' => $s, 'post_modified_gmt' => '2026-10-01 10:00:00' ); }

echo "registration\n";
site( false );
$defs = d5b_ability_defs();
$set = $defs['divi5-builder/appearance-theme-mod-set'] ?? null; $del = $defs['divi5-builder/appearance-theme-mod-delete'] ?? null; $css = $defs['divi5-builder/appearance-custom-css-set'] ?? null;
check( 'three definitions exist in the appearance category, all risky', null !== $set && null !== $del && null !== $css && 'appearance' === $set['category'] && 'appearance' === $del['category'] && 'appearance' === $css['category'] && true === $set['risky'] && true === $del['risky'] && true === $css['risky'] );
check( 'annotations: set idempotent, delete not destructive but idempotent, css idempotent; none readonly', false === $set['annotations']['readonly'] && true === $set['annotations']['idempotent'] && false === $del['annotations']['destructive'] && true === $del['annotations']['idempotent'] && false === $css['annotations']['destructive'] && true === $css['annotations']['idempotent'] && false === $css['annotations']['readonly'] );
check( 'required inputs: name+value, name, css+expect_hash; css is a content key', array( 'name', 'value' ) === $set['input_schema']['required'] && array( 'name' ) === $del['input_schema']['required'] && array( 'css', 'expect_hash' ) === $css['input_schema']['required'] && in_array( 'css', $css['content_keys'] ?? array(), true ) );

function registered( bool $risky ) {
	site( $risky );
	t_abilities_reset();
	add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' ); add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );
	t_fire( 'wp_abilities_api_categories_init' ); t_fire( 'wp_abilities_api_init' );
	return array( isset( $GLOBALS['T_abilities']['divi5-builder/appearance-theme-mod-set'] ), isset( $GLOBALS['T_abilities']['divi5-builder/appearance-theme-mod-delete'] ), isset( $GLOBALS['T_abilities']['divi5-builder/appearance-custom-css-set'] ) );
}
check( 'risky off: none of the three is registered', array( false, false, false ) === registered( false ) );
check( 'risky on: all three are registered', array( true, true, true ) === registered( true ) );
site( false );
check( 'risky off: a direct call to each handler is refused not_allowed; nothing changes', 'not_allowed' === code( handler( 'appearance-theme-mod-set', array( 'name' => 'x', 'value' => 1 ) ) ) && 'not_allowed' === code( handler( 'appearance-theme-mod-delete', array( 'name' => 'header_color' ) ) ) && 'not_allowed' === code( handler( 'appearance-custom-css-set', array( 'css' => 'a{}', 'expect_hash' => md5( '' ) ) ) ) && '#ff0000' === mods()['header_color'] && 0 === count( hrows() ) );

echo "theme mod set\n";
site( true );
foreach ( array( 'nav_menu_locations', 'custom_css_post_id', 'sidebars_widgets', 'Nav_Menu_Locations' ) as $nm ) {
	$r = call( 'appearance-theme-mod-set', array( 'name' => $nm, 'value' => 1 ) );
	check( "reserved name $nm refused; nothing written", 'not_allowed' === code( $r ) && 0 === count( hrows() ) && array( 'primary' => 7 ) === mods()['nav_menu_locations'] && 12 === mods()['custom_css_post_id'] && ! isset( mods()['sidebars_widgets'] ), array( code( $r ), msg( $r ) ) );
}
foreach ( array( "p\xC3\xA4ssword", 'caf' . "\xC3\xA9", 'has space', '', 'a/b', "x\0y", str_repeat( 'a', 256 ), "\xEF\xBC\xBF" . 'x' ) as $nm ) {
	$r = call( 'appearance-theme-mod-set', array( 'name' => $nm, 'value' => 1 ) );
	check( 'non-ASCII or malformed name refused: ' . json_encode( substr( $nm, 0, 12 ) ), 'invalid_input' === code( $r ) && 0 === count( hrows() ), is_wp_error( $r ) ? $r->get_error_code() : $r );
}
foreach ( array( 'smtp_password', 'my_api_key', 'x_token' ) as $nm ) {
	$r = call( 'appearance-theme-mod-set', array( 'name' => $nm, 'value' => 'v' ) );
	check( "secret-named mod $nm refused", 'not_allowed' === code( $r ) && ! isset( mods()[ $nm ] ), array( code( $r ), msg( $r ) ) );
}
check( 'nothing was written by any refusal above', 0 === count( hrows() ) );

site( true );
$r = call( 'appearance-theme-mod-set', array( 'name' => 'accent', 'value' => '#00ff00' ) );
$row = hlast();
check( 'a new mod is set; the row: theme_mod, id 0, update, before absent, after the value, summary', is_array( $r ) && '#00ff00' === mods()['accent'] && true === $r['changed'] && 1 === count( hrows() )
	&& 'theme_mod' === $row['object_type'] && 0 === (int) $row['object_id'] && 'update' === $row['action'] && 'divi5-builder/appearance-theme-mod-set' === $row['ability'] && array( 'absent' => true ) === $row['before'] && array( 'value' => '#00ff00' ) === $row['after'] && 'Set theme mod accent' === $row['summary'], array( $r, $row ) );
$r = call( 'appearance-theme-mod-set', array( 'name' => 'accent', 'value' => '#0000ff' ) );
$row = hlast();
check( 'changing it: before holds the old value', '#0000ff' === mods()['accent'] && array( 'value' => '#00ff00' ) === $row['before'] && 2 === count( hrows() ), $row );
$r = call( 'appearance-theme-mod-set', array( 'name' => 'accent', 'value' => '#0000ff' ) );
check( 'the same value again: changed false, no write, no new row', is_array( $r ) && false === $r['changed'] && 2 === count( hrows() ) );
foreach ( array( 42, 3.5, true, false, 'text', array( 'a' => 1, 'b' => array( 1, 2, 'x' ) ), array( 1, 2, 3 ) ) as $i => $val ) {
	$r = call( 'appearance-theme-mod-set', array( 'name' => 'rt' . $i, 'value' => $val ) );
	$g = get_theme_mod( 'rt' . $i, 'MISSING' );
	check( 'round trip ' . json_encode( $val ), is_array( $r ) && $g === $val && $r['value'] === $val, array( $r, $g ) );
}
$r = call( 'appearance-theme-mod-set', array( 'name' => 'obj', 'value' => (object) array( 'k' => 'v' ) ) );
check( 'an object value is stored as its array', is_array( $r ) && array( 'k' => 'v' ) === mods()['obj'] );
check( 'null and a missing value are invalid_input', 'invalid_input' === code( handler( 'appearance-theme-mod-set', array( 'name' => 'n1', 'value' => null ) ) ) && 'invalid_input' === code( handler( 'appearance-theme-mod-set', array( 'name' => 'n1' ) ) ) && ! isset( mods()['n1'] ) );
check( 'a value over 64 KB encoded is refused; one just under is stored', 'invalid_input' === code( handler( 'appearance-theme-mod-set', array( 'name' => 'big', 'value' => str_repeat( 'a', 65535 ) ) ) ) && ! isset( mods()['big'] ) && is_array( handler( 'appearance-theme-mod-set', array( 'name' => 'big', 'value' => str_repeat( 'a', 65000 ) ) ) ) );
check( 'invalid UTF-8 and a too deep array are refused', 'invalid_input' === code( handler( 'appearance-theme-mod-set', array( 'name' => 'bad', 'value' => "\xFF\xFE" ) ) ) && 'invalid_input' === code( handler( 'appearance-theme-mod-set', array( 'name' => 'bad', 'value' => array( array( array( array( array( array( array( array( array( 1 ) ) ) ) ) ) ) ) ) ) ) ) );
check( 'a value with a secret-named key is refused', 'invalid_input' === code( handler( 'appearance-theme-mod-set', array( 'name' => 'opts', 'value' => array( 'api_key' => 'abc' ) ) ) ) && ! isset( mods()['opts'] ) );
check( 'text holding [redacted] is masked_read, unless the stored value holds it too', 'masked_read' === code( handler( 'appearance-theme-mod-set', array( 'name' => 'accent', 'value' => 'a [redacted] b' ) ) ) && '#0000ff' === mods()['accent'] );
check( 'unknown input refused', 'invalid_input' === code( handler( 'appearance-theme-mod-set', array( 'name' => 'x', 'value' => 1, 'extra' => 1 ) ) ) );

echo "case variants\n";
site( true );
$GLOBALS['T_theme_mods']['Header_Color'] = '#111';
$n0 = count( hrows() );
$r1 = call( 'appearance-theme-mod-set', array( 'name' => 'HEADER_COLOR', 'value' => '#222' ) );
$r2 = call( 'appearance-theme-mod-set', array( 'name' => 'header_color', 'value' => '#333' ) );
$r3 = call( 'appearance-theme-mod-delete', array( 'name' => 'HEADER_COLOR' ) );
check( 'a name differing from an existing mod only by case is refused (set and delete), both exact names too; nothing written', 'invalid_input' === code( $r1 ) && 'invalid_input' === code( $r2 ) && 'invalid_input' === code( $r3 ) && '#ff0000' === mods()['header_color'] && '#111' === mods()['Header_Color'] && $n0 === count( hrows() ), array( code( $r1 ), code( $r2 ), code( $r3 ) ) );
site( true );
check( 'a case variant of a name that does not exist is fine', is_array( call( 'appearance-theme-mod-set', array( 'name' => 'Brand_Color', 'value' => 'x' ) ) ) );
check( 'the exact existing name is fine', is_array( call( 'appearance-theme-mod-set', array( 'name' => 'header_color', 'value' => '#abc' ) ) ) && '#abc' === mods()['header_color'] );

echo "theme mod delete\n";
site( true );
$r = call( 'appearance-theme-mod-delete', array( 'name' => 'layout' ) );
$row = hlast();
check( 'delete removes the mod; the row keeps the full old value; action delete; after absent', is_array( $r ) && true === $r['deleted'] && ! array_key_exists( 'layout', mods() ) && array( 'value' => array( 'cols' => 3, 'wide' => true ) ) === $row['before'] && array( 'absent' => true ) === $row['after']
	&& 'delete' === $row['action'] && 'theme_mod' === $row['object_type'] && 'Deleted theme mod layout' === $row['summary'], array( $r, $row ) );
$n0 = count( hrows() );
$r = call( 'appearance-theme-mod-delete', array( 'name' => 'layout' ) );
check( 'deleting an absent mod is not_found; no row', 'not_found' === code( $r ) && $n0 === count( hrows() ) );
foreach ( array( 'nav_menu_locations', 'custom_css_post_id', 'sidebars_widgets' ) as $nm ) {
	check( "deleting reserved $nm refused", 'not_allowed' === code( call( 'appearance-theme-mod-delete', array( 'name' => $nm ) ) ) && array( 'primary' => 7 ) === mods()['nav_menu_locations'] && 12 === mods()['custom_css_post_id'] );
}
check( 'deleting a secret-named name refused', 'not_allowed' === code( call( 'appearance-theme-mod-delete', array( 'name' => 'smtp_password' ) ) ) );

echo "a mod whose current value holds a secret\n";
site( true );
$GLOBALS['T_theme_mods']['holder'] = array( 'color' => 'red', 'api_key' => 'sk-live-xyz' );
$GLOBALS['T_theme_mods']['holder2'] = (object) array( 'auth_token' => 'zzz' );
$n0 = count( hrows() );
$a = call( 'appearance-theme-mod-set', array( 'name' => 'holder', 'value' => 'plain' ) );
$b = call( 'appearance-theme-mod-delete', array( 'name' => 'holder' ) );
$c = call( 'appearance-theme-mod-set', array( 'name' => 'holder2', 'value' => 'plain' ) );
$d = call( 'appearance-theme-mod-delete', array( 'name' => 'holder2' ) );
check( 'set and delete are refused not_allowed; the value stays; no row', 'not_allowed' === code( $a ) && 'not_allowed' === code( $b ) && 'not_allowed' === code( $c ) && 'not_allowed' === code( $d ) && 'sk-live-xyz' === mods()['holder']['api_key'] && $n0 === count( hrows() ), array( code( $a ), code( $b ), code( $c ), code( $d ) ) );

echo "permissions\n";
site( true, array( 'read', 'edit_posts' ) );
check( 'without manage_options the theme mod abilities are not allowed', 'FORBIDDEN' === call( 'appearance-theme-mod-set', array( 'name' => 'x', 'value' => 1 ) ) && 'FORBIDDEN' === call( 'appearance-theme-mod-delete', array( 'name' => 'header_color' ) ) && 'forbidden' === code( handler( 'appearance-theme-mod-set', array( 'name' => 'x', 'value' => 1 ) ) ) );
site( true, array( 'read', 'manage_options', 'edit_posts' ) );
css_is( 'a{}' );
check( 'without edit_css (unfiltered_html) the CSS ability is not allowed, in the permission callback and in the handler; theme mods still work', 'FORBIDDEN' === call( 'appearance-custom-css-set', array( 'css' => 'b{}', 'expect_hash' => md5( 'a{}' ) ) ) && 'forbidden' === code( handler( 'appearance-custom-css-set', array( 'css' => 'b{}', 'expect_hash' => md5( 'a{}' ) ) ) ) && 'a{}' === wp_get_custom_css() && is_array( call( 'appearance-theme-mod-set', array( 'name' => 'x', 'value' => 1 ) ) ) );
site( true, array( 'read', 'edit_css' ) );
check( 'edit_css alone allows the CSS ability (theme mods need manage_options)', true === d5b_ability_permit( 'divi5-builder/appearance-custom-css-set', array() ) && 'FORBIDDEN' === call( 'appearance-theme-mod-set', array( 'name' => 'x', 'value' => 1 ) ) );

echo "custom css\n";
site( true );
$old = str_repeat( ".a{color:red}\n", 15000 ); // about 210 KB
css_is( $old );
$h = md5( $old );
$a = call( 'appearance-get' );
check( 'appearance-get returns custom_css_hash = md5 of the whole stylesheet, before its 256 KB cut', is_array( $a ) && $h === $a['custom_css_hash'] && false === $a['custom_css_truncated'], is_array( $a ) ? $a['custom_css_hash'] ?? 'none' : $a );
css_is( str_repeat( 'a', 300000 ) );
$a = call( 'appearance-get' );
check( 'the hash covers the whole stylesheet when the text is cut', is_array( $a ) && true === $a['custom_css_truncated'] && md5( str_repeat( 'a', 300000 ) ) === $a['custom_css_hash'] );
css_is( $old );
$n0 = count( hrows() );
$r = call( 'appearance-custom-css-set', array( 'css' => 'p{}', 'expect_hash' => md5( 'other' ) ) );
check( 'a wrong hash is stale with data.hash = the current hash; nothing written', 'stale' === code( $r ) && $h === ( $r->get_error_data()['hash'] ?? null ) && $old === wp_get_custom_css() && $n0 === count( hrows() ) && array() === $GLOBALS['T_css_calls'] );
$r = call( 'appearance-custom-css-set', array( 'css' => 'p{}' ) );
check( 'expect_hash is required', 'invalid_input' === code( $r ) && array() === $GLOBALS['T_css_calls'] );
$r = call( 'appearance-custom-css-set', array( 'expect_hash' => $h ) );
check( 'css is required', 'invalid_input' === code( $r ) );
foreach ( array( 'a{}</style><script>x</script>', 'a{} </STYLE >', 'a{}</Style' ) as $bad ) {
	$r = call( 'appearance-custom-css-set', array( 'css' => $bad, 'expect_hash' => $h ) );
	check( 'a closing style tag is refused: ' . json_encode( $bad ), 'invalid_input' === code( $r ) && $old === wp_get_custom_css() && array() === $GLOBALS['T_css_calls'], array( code( $r ), msg( $r ) ) );
}
$r = call( 'appearance-custom-css-set', array( 'css' => str_repeat( 'a', 262145 ), 'expect_hash' => $h ) );
check( '257 KB (262145 bytes) is refused', 'invalid_input' === code( $r ) && $old === wp_get_custom_css() );
$r = call( 'appearance-custom-css-set', array( 'css' => str_repeat( 'a', 262144 ), 'expect_hash' => $h ) );
check( 'exactly 256 KB is accepted', is_array( $r ) && 262144 === $r['bytes'] );
css_is( $old );
$r = call( 'appearance-custom-css-set', array( 'css' => 'a{content:"[redacted]"}', 'expect_hash' => $h ) );
check( 'css holding [redacted] is masked_read', 'masked_read' === code( $r ) && $old === wp_get_custom_css() );
check( 'invalid UTF-8 refused', 'invalid_input' === code( call( 'appearance-custom-css-set', array( 'css' => "a{}\xFF", 'expect_hash' => $h ) ) ) );
check( 'unknown input refused', 'invalid_input' === code( call( 'appearance-custom-css-set', array( 'css' => 'a{}', 'expect_hash' => $h, 'stylesheet' => 'x' ) ) ) );
check( 'd5b_wa_css_check and d5b_wa_mod_name_ok exist as the brief names them', true === d5b_wa_css_check( 'a{}' ) && 'invalid_input' === code( d5b_wa_css_check( '</style>' ) ) && true === d5b_wa_mod_name_ok( 'abc' ) );

css_is( $old );
$n0 = count( hrows() );
$r = call( 'appearance-custom-css-set', array( 'css' => '', 'expect_hash' => strtoupper( $h ) ) );
$row = hlast();
check( 'an empty css with the right hash (any letter case) writes; the row holds the previous 200 KB stylesheet whole', is_array( $r ) && true === $r['changed'] && '' === wp_get_custom_css() && md5( '' ) === $r['hash'] && 0 === $r['bytes'] && $n0 + 1 === count( hrows() )
	&& 'custom_css' === $row['object_type'] && 55 === (int) $row['object_id'] && 55 === $r['id'] && 'update' === $row['action'] && 'divi5-builder/appearance-custom-css-set' === $row['ability'] && array( 'css' => $old ) === $row['before'] && strlen( $old ) > 200000 && 'Updated Custom CSS (0 bytes)' === $row['summary'], array( $r, strlen( $row['before']['css'] ?? '' ) ) );
$r = call( 'appearance-custom-css-set', array( 'css' => 'h1{color:blue}', 'expect_hash' => md5( '' ) ) );
$row = hlast();
check( 'the next write with the returned hash works; summary has the byte count', is_array( $r ) && 'h1{color:blue}' === wp_get_custom_css() && md5( 'h1{color:blue}' ) === $r['hash'] && 'Updated Custom CSS (14 bytes)' === $row['summary'] && array( 'css' => '' ) === $row['before'] && array( 'css' => 'h1{color:blue}' ) === $row['after'], $row );
$n0 = count( hrows() );
$r = call( 'appearance-custom-css-set', array( 'css' => 'h1{color:blue}', 'expect_hash' => md5( 'h1{color:blue}' ) ) );
check( 'writing the same stylesheet changes nothing and writes no row', is_array( $r ) && false === $r['changed'] && $n0 === count( hrows() ) && 1 === count( array_filter( $GLOBALS['T_css_calls'], function ( $c ) { return 'h1{color:blue}' === $c; } ) ) );
site( true );
$GLOBALS['T_custom_css'] = null;
$r = call( 'appearance-custom-css-set', array( 'css' => 'a{}', 'expect_hash' => md5( '' ) ) );
check( 'with no stylesheet yet the hash of "" applies; core creates the post and the row points at it', is_array( $r ) && 'a{}' === wp_get_custom_css() && $r['id'] === (int) hlast()['object_id'] && $r['id'] > 0 && $r['id'] === mods()['custom_css_post_id'], $r );
site( true );
css_is( 'old{}' );
$GLOBALS['T_css_error'] = new WP_Error( 'db_update_error', 'boom' );
$r = call( 'appearance-custom-css-set', array( 'css' => 'new{}', 'expect_hash' => md5( 'old{}' ) ) );
check( 'a database error from core is write_failed; no row; the stylesheet stays', 'write_failed' === code( $r ) && 'old{}' === wp_get_custom_css() && 0 === count( hrows() ) );
$GLOBALS['T_css_error'] = new WP_Error( 'invalid_css', 'Not valid css' );
check( 'any other core refusal is invalid_input with its message', 'invalid_input' === code( call( 'appearance-custom-css-set', array( 'css' => 'new{}', 'expect_hash' => md5( 'old{}' ) ) ) ) );
$GLOBALS['T_css_error'] = null;
$big = str_repeat( 'x', 9 * 1048576 );
css_is( $big );
$r = call( 'appearance-custom-css-set', array( 'css' => 'a{}', 'expect_hash' => md5( $big ) ) );
check( 'a previous stylesheet over the history\'s 8 MB is refused too_large_to_record; nothing written', 'too_large_to_record' === code( $r ) && $big === wp_get_custom_css() && 0 === count( hrows() ) );
site( true );
css_is( 'old{}' );
$GLOBALS['T_css_rewrite'] = function ( $c ) { return $c . '/*x*/'; };
$r = call( 'appearance-custom-css-set', array( 'css' => 'new{}', 'expect_hash' => md5( 'old{}' ) ) );
check( 'a stylesheet another plugin changes on save: the stored hash is returned with a warning', is_array( $r ) && md5( 'new{}/*x*/' ) === $r['hash'] && ! empty( $r['warnings'] ), $r );

echo "audit and scrubbing\n";
site( true );
css_is( 'a{}' );
$r = call( 'appearance-custom-css-set', array( 'css' => 'b{color:red}', 'expect_hash' => md5( 'a{}' ) ) );
check( 'the response is scrubbed as content (css is a content key) and the audit row is ok', is_array( $r ) && 'ok' === end( $GLOBALS['wpdb']->tables[ d5b_audit_table() ] )['status'] );

echo "\n$n checks, $failed failed\n";
exit( $failed ? 1 : 0 );
