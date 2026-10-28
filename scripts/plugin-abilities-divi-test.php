<?php
/**
 * The Divi and history read abilities (#32, Task 6).
 *
 *   php scripts/plugin-abilities-divi-test.php
 *
 * Loads the real inc/ files, the real secret scrubber and the real audit log (WordPress stand-ins from plugin-test-stubs.php,
 * plus the few extra ones below) and the real parts/page-building.php, whose REST routes are called for comparison. Set
 * D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: the Theme Builder and design-system abilities return exactly what the existing REST routes return
 * (the same function behind both), and the hash is md5 of the raw content; the capabilities match the routes; canvases and
 * Theme Builder items are reachable through the divi category only; the builder status of a post follows the switch of its own
 * category; the design system survives the scrubber as written (and what it does not is pinned here); the activity log is
 * newest first, filterable, paged, administrator-only and masked.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.1.0' );
require __DIR__ . '/plugin-test-stubs.php';

// ---- stand-ins the shared stubs do not have (page-building.php's Theme Builder reads)
function get_posts( $a ) {
	$types = (array) $a['post_type']; $st = (array) $a['post_status']; $rows = array();
	foreach ( $GLOBALS['T_posts'] as $p ) { if ( in_array( $p->post_type, $types, true ) && in_array( $p->post_status, $st, true ) ) { $rows[] = $p; } }
	usort( $rows, function ( $x, $y ) { return $x->ID <=> $y->ID; } );
	return $rows;
}
function metadata_exists( $t, $id, $k ) { return isset( $GLOBALS['T_meta'][ (int) $id ][ $k ] ); }
function maybe_unserialize( $v ) { return $v; }

foreach ( array( 'inc/parts', 'inc/switches', 'inc/abilities/categories', 'inc/abilities/register', 'inc/abilities/secrets', 'inc/abilities/audit', 'inc/abilities/scrub', 'inc/abilities/read-content', 'inc/abilities/read-divi', 'inc/abilities/read-history', 'parts/page-building' ) as $f ) { require $dir . '/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 700 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const READER = array( 'read' );
const EDITOR = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'read_private_posts' );
const ADMIN  = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'read_private_posts', 'manage_options', 'edit_theme_options', 'edit_pages', 'unfiltered_html' );

/** A fresh site: the real audit table exists, the master switch is on, every category is on. */
function site( array $caps, int $uid = 3 ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_set_caps( $caps ); t_set_multisite( false ); $GLOBALS['T_user_id'] = $uid; $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	d5b_abilities_set_master( true, 'tester' );
	d5b_audit_maybe_install();
	d5b_routes_page_building();
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function handler( $name, $in = array() ) { return call_user_func( d5b_ability_defs()[ 'divi5-builder/' . $name ]['handler'], is_array( $in ) ? $in : array() ); }
function code( $r ) { return is_wp_error( $r ) ? $r->code : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function ids( $r ) { return is_array( $r ) && isset( $r['items'] ) ? array_map( function ( $i ) { return $i['id']; }, $r['items'] ) : array(); }
function rows() { global $wpdb; return $wpdb->tables[ d5b_audit_table() ] ?? array(); }
function last_row() { $r = rows(); return $r ? $r[ count( $r ) - 1 ] : null; }
/** What the REST route answers (its permission callback first, as WordPress does). 'DENIED' when it says no. */
function route( $key, array $params = array() ) {
	$r = $GLOBALS['T_routes'][ $key ] ?? null;
	if ( null === $r ) { return 'NO ROUTE'; }
	if ( ! call_user_func( $r['permission_callback'], new T_Request( $params ) ) ) { return 'DENIED'; }
	return call_user_func( $r['callback'], new T_Request( $params ) );
}
function same( $a, $b ) { return serialize( $a ) === serialize( $b ); }
function seed_audit( $ability, $user, $secs_ago, $status = 'ok' ) {
	global $wpdb; $t = d5b_audit_table();
	$wpdb->tables[ $t ][] = array( 'id' => 1000 + count( $wpdb->tables[ $t ] ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $secs_ago ), 'user_id' => $user, 'ability' => $ability, 'args' => '{"seed":true}', 'status' => $status, 'duration_ms' => 3, 'ip' => '198.51.100.7' );
}

$names = array( 'divi-design-system', 'divi-tb-list', 'divi-tb-get', 'divi-canvases-list', 'divi-canvas-get', 'divi-builder-status', 'history-audit-list' );

// ---- registration
echo "registration\n";
$defs = d5b_ability_defs();
$bad = array();
foreach ( $names as $x ) {
	$d = $defs[ 'divi5-builder/' . $x ] ?? null;
	if ( null === $d ) { $bad[] = "$x missing"; continue; }
	$cat = 0 === strpos( $x, 'history-' ) ? 'history' : 'divi';
	if ( $d['category'] !== $cat ) { $bad[] = "$x category " . $d['category']; }
	if ( array_key_exists( 'annotations', $d ) ) { $bad[] = "$x passes annotations"; }
	if ( ! is_callable( $d['handler'] ) || ! is_callable( $d['permission'] ) ) { $bad[] = "$x callables"; }
	if ( strlen( $d['description'] ) < 40 || ! preg_match( '/return/i', $d['description'] ) || ! preg_match( '/refus/i', $d['description'] ) ) { $bad[] = "$x description"; }
	foreach ( (array) ( $d['input_schema']['properties'] ?? array() ) as $k => $p ) { if ( empty( $p['description'] ) ) { $bad[] = "$x.$k undescribed"; } }
	if ( false !== $d['input_schema']['additionalProperties'] ) { $bad[] = "$x allows unknown input"; }
}
check( 'all 7 Divi and history abilities are defined in their category, lint-clean, with callables and no annotations override', array() === $bad, $bad );
$mine = array_values( array_filter( array_keys( $defs ), function ( $k ) use ( $names ) { return in_array( substr( $k, 14 ), $names, true ); } ) );
$ck = array(); $rp = array();
foreach ( $mine as $k ) { if ( ! empty( $defs[ $k ]['content_keys'] ) ) { $ck[] = substr( $k, 14 ); } if ( ! empty( $defs[ $k ]['raw_paths'] ) ) { $rp[] = substr( $k, 14 ); } }
sort( $ck );
check( 'content keys are declared for the two content abilities only (Theme Builder get, canvas get); nothing declares raw paths, the design system is neither', array( 'divi-canvas-get', 'divi-tb-get' ) === $ck && array() === $rp, array( $ck, $rp ) );
site( ADMIN );
add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' );
add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );
t_fire( 'wp_abilities_api_categories_init' ); t_fire( 'wp_abilities_api_init' );
$ro = true; $missing = array();
foreach ( $names as $x ) {
	$m = $GLOBALS['T_abilities'][ 'divi5-builder/' . $x ] ?? null;
	if ( null === $m ) { $missing[] = $x; continue; }
	if ( array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) !== $m['meta']['annotations'] ) { $ro = false; }
}
check( 'all 7 are registered with WordPress, readonly and not destructive', array() === $missing && $ro && array() === $GLOBALS['T_ability_errors'], array( $missing, $GLOBALS['T_ability_errors'] ) );
$cats = array_keys( $GLOBALS['T_ability_categories'] ); sort( $cats );
check( 'the categories registered for them are divi and history', in_array( 'divi5-builder-divi', $cats, true ) && in_array( 'divi5-builder-history', $cats, true ), $cats );
$loader = file_get_contents( $dir . '/divi5-builder.php' );
check( 'both new files are in the loader\'s explicit list, after read-site', 1 === preg_match( "#'inc/abilities/read-site\.php',\s+'inc/abilities/read-divi\.php',\s+'inc/abilities/read-history\.php',#", $loader ) );
$src_ok = true;
foreach ( array( 'read-divi', 'read-history' ) as $f ) { $s = file_get_contents( "$dir/inc/abilities/$f.php" ); if ( preg_match( '/\badd_action\s*\(|\badd_filter\s*\(|register_rest_route\s*\(|\bwp_register_ability\s*\(/', $s ) ) { $src_ok = false; } }
check( 'the two files register no hooks, routes or abilities themselves', $src_ok );

// ---- the Theme Builder
echo "divi-tb-get / divi-tb-list\n";
site( ADMIN );
$raw = "<!-- wp:divi/section {\"a\":\"x\\\\y \\u003c b\",\"price\":\"\$100 only\"} -->\n<!-- wp:divi/text {\"c\":\"quote \\\" q\"} -->\n<div>Bearer instrumentalisation, caf\xc3\xa9  </div>\n<!-- /wp:divi/text -->\n<!-- /wp:divi/section -->\n";
$h = t_add_post( array( 'post_type' => 'et_header_layout', 'post_title' => 'Header', 'post_content' => $raw, 'post_modified_gmt' => '2026-10-05 08:00:00' ) );
$b = t_add_post( array( 'post_type' => 'et_body_layout', 'post_status' => 'draft', 'post_title' => 'Body', 'post_content' => '[et_pb_section][/et_pb_section]' ) );
$t = t_add_post( array( 'post_type' => 'et_template', 'post_title' => 'Default Website Template', 'post_content' => '' ) );
$pg = t_add_post( array( 'post_type' => 'page', 'post_content' => 'PAGE BODY' ) );
t_add_meta( $h, '_et_pb_use_builder', 'on' ); t_add_meta( $h, '_d5b_prev_content', str_repeat( 'z', 400 ) ); t_add_meta( $h, '_d5b_prev_saved_at', '2026-10-01T00:00:00+00:00' ); t_add_meta( $h, '_other', 'x' );
$hand  = handler( 'divi-tb-get', array( 'id' => $h ) );
$rt    = route( 'GET divi5-builder/v1/tb-layout', array( 'id' => $h ) );
check( 'divi-tb-get returns the same content and hash as the existing tb-get route: the handler\'s array and the route\'s response data are byte-identical', is_array( $hand ) && same( $hand, $rt ) && $raw === $hand['content'] && md5( $raw ) === $hand['hash'], array( $hand, $rt ) );
check( 'the hash is md5 of the raw post_content (what wp.js --expect-hash sends), not of the filtered content', md5( $raw ) === $hand['hash'] && md5( apply_filters( 'the_content', $raw ) ) !== $hand['hash'] );
$w = call( 'divi-tb-get', array( 'id' => $h ) );
check( 'through the wrapper, a realistic layout (backslashes, \\u003c, $100, "Bearer instrumentalisation", accents, trailing blanks) comes back equal and not masked', is_array( $w ) && same( $w, $hand ) && ! isset( $w['masked'] ), $w );
$sec = t_add_post( array( 'post_type' => 'et_footer_layout', 'post_content' => '<!-- wp:divi/text --><div>key sk_live_ABCDEFGH12345678 here</div><!-- /wp:divi/text -->' ) );
$ws = call( 'divi-tb-get', array( 'id' => $sec ) );
check( 'a secret value shape inside the layout is masked, "masked": true is set, and the hash is still of the RAW content (a later write can refuse to build on a masked read)', is_array( $ws ) && false === strpos( $ws['content'], 'sk_live_' ) && true === ( $ws['masked'] ?? false ) && md5( $GLOBALS['T_posts'][ $sec ]->post_content ) === $ws['hash'] && false !== strpos( $ws['content'], '<!-- wp:divi/text -->' ), $ws );
check( 'divi-tb-get on a draft body layout and a template works; format comes from the content', 'divi4' === call( 'divi-tb-get', array( 'id' => $b ) )['format'] && 'empty' === call( 'divi-tb-get', array( 'id' => $t ) )['format'] );
$rt_bad = route( 'GET divi5-builder/v1/tb-layout', array( 'id' => $pg ) );
check( 'an id that is not a Theme Builder item: the same error as the route (not_a_tb_post, 404), through the handler', same( $rt_bad, handler( 'divi-tb-get', array( 'id' => $pg ) ) ) && 'not_a_tb_post' === code( handler( 'divi-tb-get', array( 'id' => $pg ) ) ) );
check( 'tb-get input is checked: id required, a string id is refused, unknown input is refused', 'invalid_input' === code( handler( 'divi-tb-get' ) ) && 'invalid_input' === code( handler( 'divi-tb-get', array( 'id' => 'abc' ) ) ) && 'invalid_input' === code( handler( 'divi-tb-get', array( 'id' => $h, 'x' => 1 ) ) ) );
$list_h = handler( 'divi-tb-list' ); $list_r = route( 'GET divi5-builder/v1/tb-list' );
check( 'divi-tb-list matches the tb-list route byte for byte, in the same order, with hashes and no content', is_array( $list_h ) && same( $list_h, $list_r ) && 4 === $list_h['count'] && md5( $raw ) === $list_h['items'][0]['hash'] && false === strpos( json_encode( $list_h ), 'quote' ), $list_h );
$wl = call( 'divi-tb-list' );
check( 'through the wrapper the list is equal too (the long meta value shows as a byte count)', is_array( $wl ) && same( $wl, $list_h ) && array( '[400 bytes]' ) === $wl['items'][0]['meta']['_d5b_prev_content'], $wl );
// capabilities
site( EDITOR, 1 );
check( 'an editor (edit_posts, no edit_theme_options) is refused both Theme Builder abilities, as the routes refuse them', 'FORBIDDEN' === call( 'divi-tb-get', array( 'id' => 1 ) ) && 'FORBIDDEN' === call( 'divi-tb-list' ) && 'DENIED' === route( 'GET divi5-builder/v1/tb-layout', array( 'id' => 1 ) ) && 'DENIED' === route( 'GET divi5-builder/v1/tb-list' ) );
$a = last_row();
check( 'the refusal is logged as forbidden', $a && 'forbidden' === $a['status'] && 'divi5-builder/divi-tb-list' === $a['ability'], $a );
check( 'defence in depth: the handlers alone also refuse the editor (forbidden), and return no content', 'forbidden' === code( handler( 'divi-tb-get', array( 'id' => 1 ) ) ) && 'forbidden' === code( handler( 'divi-tb-list' ) ) );
site( READER, 3 );
check( 'a reader is refused too', 'FORBIDDEN' === call( 'divi-tb-list' ) );
site( ADMIN );
$h2 = t_add_post( array( 'post_type' => 'et_header_layout', 'post_content' => 'x' ) );
d5b_abilities_set_category( 'divi', false, false, 'tester' );
check( 'with the divi category off, every Theme Builder and Divi ability is refused (and not even registered)', 'FORBIDDEN' === call( 'divi-tb-get', array( 'id' => $h2 ) ) && 'FORBIDDEN' === call( 'divi-tb-list' ) && 'FORBIDDEN' === call( 'divi-design-system' ) && 'FORBIDDEN' === call( 'divi-canvases-list' ) && 'FORBIDDEN' === call( 'divi-builder-status', array( 'post_id' => $h2 ) ) );
d5b_abilities_set_master( false, 'tester' );
check( 'with the master switch off, the history ability is refused too', 'FORBIDDEN' === call( 'history-audit-list' ) );

// ---- the design system
echo "divi-design-system\n";
function ds_fixture() {
	$GLOBALS['T_options']['et_divi_builder_global_presets_d5'] = array(
		'module' => array(
			'divi/button'  => array( 'default' => 'pr1', 'items' => array(
				'pr1' => array( 'name' => 'Primary button', 'version' => '5.18.0', 'attrs' => array( 'module' => array( 'decoration' => array( 'background' => array( 'desktop' => array( 'value' => array( 'color' => '$variable({"type":"color","value":{"name":"gcid-primary-color","settings":{}}})$' ) ) ) ) ) ), 'styleAttrs' => array( 'module' => array( 'decoration' => array( 'border' => array( 'desktop' => array( 'value' => array( 'radius' => '8px' ) ) ) ) ) ) ),
				'pr2' => array( 'name' => 'Session card', 'version' => '5.18.0' ),
				'pr3' => array( 'name' => 'Key visual', 'version' => '5.18.0' ),
				'pr4' => array( 'name' => 'Token blue', 'version' => '5.18.0' ),
				'pr5' => array( 'name' => 'Password field: choose a strong one', 'version' => '5.18.0' ),
			) ),
			'divi/blurb'   => array( 'default' => '', 'items' => array( 'bl1' => array( 'name' => 'Cookie banner', 'version' => '5.18.0', 'attrs' => array( 'title' => array( 'decoration' => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'family' => 'Source Sans Pro', 'weight' => '600' ) ) ) ) ) ) ) ) ) ),
		),
		'group'  => array( 'divi/font' => array( 'default' => 'gp1', 'items' => array( 'gp1' => array( 'name' => 'Body text', 'groupId' => 'designTitleText', 'version' => '5.18.0', 'renderAttrs' => array( 'decoration' => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'size' => '18px' ) ) ) ) ) ) ) ) ) ),
	);
	$GLOBALS['T_options']['et_divi_global_variables'] = array(
		'numbers' => array( 'gvid-xdtsp8ywr8' => array( 'id' => 'gvid-xdtsp8ywr8', 'label' => 'Session length', 'value' => '45px', 'order' => '1', 'status' => 'active', 'lastUpdated' => '1761640000000', 'variableType' => 'numbers' ) ),
		'strings' => array( 'gvid-zca7w37pd4' => array( 'id' => 'gvid-zca7w37pd4', 'label' => 'Token name', 'value' => 'Cookie: chocolate chip', 'order' => '1', 'status' => 'active', 'lastUpdated' => '1761640000001', 'variableType' => 'strings' ) ),
		'fonts'   => array( 'gvid-fnt1q2w3e4' => array( 'id' => 'gvid-fnt1q2w3e4', 'label' => 'Key font', 'value' => 'Source Sans Pro', 'order' => '1', 'status' => 'active', 'variableType' => 'fonts' ) ),
	);
	$GLOBALS['T_options']['et_divi'] = array( 'et_global_data' => array( 'global_colors' => array(
		'gcid-primary-color' => array( 'color' => '#2b5c7a', 'status' => 'active', 'label' => 'Primary', 'order' => 1 ),
		'gcid-a1b2c3d4e5'    => array( 'color' => '#ff6600', 'status' => 'active', 'label' => 'Secret sauce red', 'order' => 2 ),
	) ) );
}
site( ADMIN ); ds_fixture();
$ds_h = handler( 'divi-design-system' ); $ds_r = route( 'GET divi5-builder/v1/design-system' );
check( 'divi-design-system matches /design-system output byte for byte (handler array vs route response data)', is_array( $ds_h ) && same( $ds_h, $ds_r ) && 6 === count( $ds_h['presets']['module'] ) && 'Primary button' === $ds_h['presets']['module'][0]['name'] && isset( $ds_h['variables']['numbers']['gvid-xdtsp8ywr8'] ) && isset( $ds_h['colors']['gcid-primary-color'] ), $ds_h );
$dsf_h = handler( 'divi-design-system', array( 'full' => true ) ); $dsf_r = route( 'GET divi5-builder/v1/design-system', array( 'full' => '1' ) );
check( 'full = true matches /design-system?full=1 byte for byte and carries the preset settings; the default does not', same( $dsf_h, $dsf_r ) && isset( $dsf_h['presets']['module'][0]['attrs'] ) && ! isset( $ds_h['presets']['module'][0]['attrs'] ) );
$ds_w = call( 'divi-design-system' ); $dsf_w = call( 'divi-design-system', array( 'full' => true ) );
check( 'REALISTIC DESIGN SYSTEM through the wrapper: preset and variable names like "Session card", "Key visual", "Token blue", "Password field: ...", "Cookie banner", "Secret sauce red", a font name and a "Cookie: chocolate chip" value all survive unchanged, and nothing is flagged masked', same( $ds_w, $ds_h ) && same( $dsf_w, $dsf_h ) && ! isset( $ds_w['masked'] ) && ! isset( $dsf_w['masked'] ), array( $ds_w === $ds_h ) );
check( 'design system: the preset settings (colour variable reference, radius, font family) come through whole with full = true', 'Source Sans Pro' === $dsf_w['presets']['module'][ array_search( 'Cookie banner', array_column( $dsf_w['presets']['module'], 'name' ), true ) ]['attrs']['title']['decoration']['font']['font']['desktop']['value']['family'] && false !== strpos( json_encode( $dsf_w ), 'gcid-primary-color' ) && false !== strpos( json_encode( $dsf_w ), '8px' ) );
// what the scrubber does take out of a design system (documented, not weakened)
$GLOBALS['T_options']['et_divi_builder_global_presets_d5']['module']['divi/map-pin'] = array( 'default' => '', 'items' => array( 'mp1' => array( 'name' => 'Map pin', 'attrs' => array( 'pin' => array( 'decoration' => array( 'x' => 1 ) ), 'module' => array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'Shop' ) ) ) ) ) ) ) );
$pin = call( 'divi-design-system', array( 'full' => true ) );
$mp  = $pin['presets']['module'][ array_search( 'Map pin', array_column( $pin['presets']['module'], 'name' ), true ) ];
check( 'KNOWN LIMIT: a map-pin preset\'s settings key "pin" is read as the word PIN and removed (key rule, silent); the rest of that preset stays', ! array_key_exists( 'pin', $mp['attrs'] ) && 'Shop' === $mp['attrs']['module']['meta']['adminLabel']['desktop']['value'] && ! isset( $pin['masked'] ), $mp );
$GLOBALS['T_options']['et_divi_global_variables']['numbers']['gvid-xpwq4k9m2a'] = array( 'id' => 'gvid-xpwq4k9m2a', 'label' => 'Gap', 'value' => '20px' );
$GLOBALS['T_options']['et_divi_global_variables']['numbers']['gvid-t9ki9frwpw'] = array( 'id' => 'gvid-t9ki9frwpw', 'label' => 'Margin', 'value' => '30px' );
$dsx = call( 'divi-design-system' );
check( 'KNOWN LIMIT: a variable or colour whose random id happens to END in "pw" (or hold "pw" as a word between digits; about 1 id in 560) is removed as a password key, silently; ids that merely contain the letters pw stay', ! isset( $dsx['variables']['numbers']['gvid-t9ki9frwpw'] ) && isset( $dsx['variables']['numbers']['gvid-xpwq4k9m2a'] ) && isset( $dsx['variables']['numbers']['gvid-xdtsp8ywr8'] ) && ! isset( $dsx['masked'] ), array_keys( $dsx['variables']['numbers'] ) );
site( ADMIN ); ds_fixture();
check( 'design system input: full must be a real boolean; unknown input is refused', 'invalid_input' === code( handler( 'divi-design-system', array( 'full' => 'yes' ) ) ) && 'invalid_input' === code( handler( 'divi-design-system', array( 'full' => 1 ) ) ) && 'invalid_input' === code( handler( 'divi-design-system', array( 'plus' => true ) ) ) && is_array( handler( 'divi-design-system', array( 'full' => false ) ) ) && is_array( handler( 'divi-design-system', array( 'full' => null ) ) ) );
site( array_merge( EDITOR, array( 'edit_theme_options' ) ), 1 );
check( 'the design system needs manage_options: an editor with edit_theme_options is refused, as the route refuses them', 'FORBIDDEN' === call( 'divi-design-system' ) && 'DENIED' === route( 'GET divi5-builder/v1/design-system' ) && 'forbidden' === code( handler( 'divi-design-system' ) ) );
site( array( 'read', 'manage_options' ), 1 );
check( 'manage_options alone is enough for the design system (not for Theme Builder), as the routes have it', 'FORBIDDEN' === call( 'divi-tb-list' ) && is_array( route( 'GET divi5-builder/v1/design-system' ) ) && is_array( call( 'divi-design-system', array( 'full' => false ) ) ) );
check( 'the design system call is audited: one ok row with the arguments', 'ok' === last_row()['status'] && 'divi5-builder/divi-design-system' === last_row()['ability'] && false !== strpos( last_row()['args'], 'full' ), last_row() );

// ---- canvases
echo "divi-canvases-list / divi-canvas-get\n";
site( EDITOR, 1 );
$canvas_raw = '<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Popup"}}}}} --><!-- /wp:divi/section -->';
$c1 = t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_title' => 'Newsletter popup', 'post_content' => $canvas_raw, 'post_author' => 1 ) );
$c2 = t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_title' => 'Mega menu', 'post_status' => 'draft', 'post_content' => '', 'post_author' => 1 ) );
t_add_meta( $c1, '_divi_canvas_parent_post_id', '55' ); t_add_meta( $c1, '_divi_canvas_id', 'b7d0c1de-0000-4000-8000-000000000001' );
$pgc = t_add_post( array( 'post_type' => 'page', 'post_content' => 'PAGE' ) );
$g = call( 'divi-canvas-get', array( 'id' => $c1 ) );
check( 'divi-canvas-get returns the raw content, its hash (md5 of the raw content, as wp.js get-canvas computes it), the linked page and the canvas id', is_array( $g ) && $canvas_raw === $g['content'] && md5( $canvas_raw ) === $g['hash'] && 'Newsletter popup' === $g['title'] && 55 === $g['parent_page_id'] && 'b7d0c1de-0000-4000-8000-000000000001' === $g['canvas_uuid'] && 'divi5' === $g['format'] && strlen( $canvas_raw ) === $g['bytes'], $g );
check( 'canvas content is declared post content: through the wrapper it equals the handler (not masked)', same( $g, handler( 'divi-canvas-get', array( 'id' => $c1 ) ) ) && ! isset( $g['masked'] ) );
check( 'an empty draft canvas works and has the md5 of the empty string', md5( '' ) === call( 'divi-canvas-get', array( 'id' => $c2 ) )['hash'] );
check( 'a page id (wrong kind) and a missing id are refused the same way', 'FORBIDDEN' === call( 'divi-canvas-get', array( 'id' => $pgc ) ) && 'FORBIDDEN' === call( 'divi-canvas-get', array( 'id' => 99999 ) ) && 'not_found' === code( handler( 'divi-canvas-get', array( 'id' => $pgc ) ) ) && 'not_found' === code( handler( 'divi-canvas-get', array( 'id' => 99999 ) ) ) );
$l = call( 'divi-canvases-list' );
check( 'divi-canvases-list returns the canvases, newest first, with status and link, and a total', is_array( $l ) && array( $c2, $c1 ) === ids( $l ) && 2 === $l['total'] && 'draft' === $l['items'][0]['status'] && 55 === $l['items'][1]['parent_page_id'] && ! isset( $l['items'][0]['content'] ), $l );
site( array( 'read', 'edit_posts' ), 3 );
$ca = t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_title' => "Someone else's", 'post_author' => 2, 'post_content' => 'OTHER' ) );
$mine = t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_title' => 'Mine', 'post_author' => 3, 'post_status' => 'draft', 'post_content' => 'MINE' ) );
$lc = call( 'divi-canvases-list' );
check( 'a contributor sees only the canvases they may edit (edit_post), and no total (it could count hidden ones)', array( $mine ) === ids( $lc ) && ! isset( $lc['total'] ), $lc );
check( 'a contributor cannot get another user\'s canvas (edit_post), only their own', 'FORBIDDEN' === call( 'divi-canvas-get', array( 'id' => $ca ) ) && 'not_found' === code( handler( 'divi-canvas-get', array( 'id' => $ca ) ) ) && is_array( call( 'divi-canvas-get', array( 'id' => $mine ) ) ) );
site( READER, 3 );
check( 'a reader (no edit_posts) is refused both', 'FORBIDDEN' === call( 'divi-canvases-list' ) && 'FORBIDDEN' === call( 'divi-canvas-get', array( 'id' => 1 ) ) && 'forbidden' === code( handler( 'divi-canvases-list' ) ) );
site( EDITOR, 1 );
for ( $i = 0; $i < 130; $i++ ) { t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_title' => "C $i", 'post_author' => 1 ) ); }
$p1 = call( 'divi-canvases-list', array( 'per_page' => 500 ) ); $p2 = call( 'divi-canvases-list', array( 'per_page' => 100, 'page' => 2 ) );
check( 'per_page 500 is capped at 100 (the query too, checked before page 2 ran): 100 items, a total of 130, 2 pages', is_array( $p1 ) && 100 === count( $p1['items'] ) && 100 === $p1['per_page'] && 130 === $p1['total'] && 2 === $p1['total_pages'] && 100 === $GLOBALS['T_queries'][ count( $GLOBALS['T_queries'] ) - 2 ]['posts_per_page'], $p1 );
check( 'page 2 holds the remaining 30, none repeated from page 1', 30 === count( $p2['items'] ) && array() === array_intersect( ids( $p1 ), ids( $p2 ) ) );
check( 'a page that starts more than 10,000 items in is refused (page_too_deep); bad paging input is invalid_input', 'page_too_deep' === code( call( 'divi-canvases-list', array( 'per_page' => 100, 'page' => 102 ) ) ) && is_array( call( 'divi-canvases-list', array( 'per_page' => 100, 'page' => 101 ) ) ) && 'invalid_input' === code( call( 'divi-canvases-list', array( 'per_page' => 'lots' ) ) ) && 'invalid_input' === code( call( 'divi-canvases-list', array( 'status' => 'any' ) ) ) );
check( 'canvases and Theme Builder items are reachable through the divi category only: the generic cpt abilities refuse their types with not_allowed', 'not_allowed' === code( call( 'cpt-get', array( 'type' => 'et_pb_canvas', 'id' => $c1 ) ) ) && 'not_allowed' === code( call( 'cpt-list', array( 'type' => 'et_pb_canvas' ) ) ) && 'not_allowed' === code( call( 'cpt-get', array( 'type' => 'et_header_layout', 'id' => $c1 ) ) ) );
// the other switches do not govern canvases
site( EDITOR, 1 );
$c = t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_content' => 'X', 'post_author' => 1 ) );
foreach ( array( 'posts', 'pages', 'cpt', 'meta', 'blocks', 'search' ) as $cat ) { d5b_abilities_set_category( $cat, false, false, 'tester' ); }
check( 'with posts, pages, cpt, meta, blocks and search all off, canvases and Theme Builder still work (only the divi category governs them)', is_array( call( 'divi-canvas-get', array( 'id' => $c ) ) ) && is_array( call( 'divi-canvases-list' ) ) && is_array( call( 'divi-builder-status', array( 'post_id' => $c ) ) ) );

// ---- builder status
echo "divi-builder-status\n";
site( EDITOR, 1 );
$dp = t_add_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => "<!-- wp:divi/section /-->", 'post_author' => 1 ) );
foreach ( array( '_et_pb_use_builder' => 'on', '_et_pb_use_divi_5' => 'on', '_et_builder_version' => 'VB|Divi|5.18.0', '_et_pb_page_layout' => 'et_full_width_page', '_et_pb_side_nav' => 'off', '_et_pb_built_for_post_type' => 'page' ) as $k => $v ) { t_add_meta( $dp, $k, $v ); }
t_add_meta( $dp, '_divi_off_canvas_data', array( 'activeCanvasId' => 'b7d0c1de-0000-4000-8000-000000000001', 'mainCanvasName' => 'Main Canvas' ) );
$s = call( 'divi-builder-status', array( 'post_id' => $dp ) );
check( 'divi-builder-status of a Divi 5 page: mode, flags, version, layout, content format, linked canvas', is_array( $s ) && 'divi5' === $s['builder_mode'] && true === $s['uses_builder'] && true === $s['uses_divi_5'] && 'VB|Divi|5.18.0' === $s['builder_version'] && 'et_full_width_page' === $s['page_layout'] && 'off' === $s['side_nav'] && 'divi5' === $s['content_format'] && 'page' === $s['type'] && array( 'active_canvas_id' => 'b7d0c1de-0000-4000-8000-000000000001', 'main_canvas_name' => 'Main Canvas' ) === $s['off_canvas'], $s );
$old = t_add_post( array( 'post_type' => 'page', 'post_content' => '[et_pb_section][/et_pb_section]', 'post_author' => 1 ) ); t_add_meta( $old, '_et_pb_use_builder', 'on' );
$plain = t_add_post( array( 'post_type' => 'post', 'post_content' => 'Hello', 'post_author' => 1 ) );
check( 'an older Divi page reads divi4; a post that never used the builder reads off, with no off-canvas link', 'divi4' === call( 'divi-builder-status', array( 'post_id' => $old ) )['builder_mode'] && 'off' === call( 'divi-builder-status', array( 'post_id' => $plain ) )['builder_mode'] && null === call( 'divi-builder-status', array( 'post_id' => $plain ) )['off_canvas'] );
$cvx = t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_content' => 'C', 'post_author' => 1 ) );
$tbx = t_add_post( array( 'post_type' => 'et_header_layout', 'post_content' => '<!-- wp:divi/section /-->', 'post_author' => 1 ) );
check( 'builder status works for a canvas and a Theme Builder layout (Divi\'s own types)', 'et_pb_canvas' === call( 'divi-builder-status', array( 'post_id' => $cvx ) )['type'] && 'et_header_layout' === call( 'divi-builder-status', array( 'post_id' => $tbx ) )['type'] );
// capability: edit_post
site( array( 'read', 'edit_posts' ), 3 );
$theirs = t_add_post( array( 'post_type' => 'page', 'post_author' => 2, 'post_content' => 'T' ) ); $own = t_add_post( array( 'post_type' => 'post', 'post_author' => 3, 'post_status' => 'draft', 'post_content' => 'O' ) );
check( 'builder status needs edit_post on that id: another user\'s page is refused (same answer as a missing id), one\'s own draft works', 'FORBIDDEN' === call( 'divi-builder-status', array( 'post_id' => $theirs ) ) && 'FORBIDDEN' === call( 'divi-builder-status', array( 'post_id' => 99999 ) ) && 'not_found' === code( handler( 'divi-builder-status', array( 'post_id' => $theirs ) ) ) && is_array( call( 'divi-builder-status', array( 'post_id' => $own ) ) ) );
check( 'builder status input (handler): post_id required, text refused, unknown input refused; the gate refuses a call with no id', 'invalid_input' === code( handler( 'divi-builder-status' ) ) && 'invalid_input' === code( handler( 'divi-builder-status', array( 'post_id' => 'abc' ) ) ) && 'invalid_input' === code( handler( 'divi-builder-status', array( 'post_id' => $own, 'x' => 1 ) ) ) && 'FORBIDDEN' === call( 'divi-builder-status' ) );
site( READER, 3 );
check( 'a reader is refused', 'FORBIDDEN' === call( 'divi-builder-status', array( 'post_id' => $theirs ) ) );
// strictest switch wins
site( EDITOR, 1 );
t_add_type( 'book', true, true );
$pgp = t_add_post( array( 'post_type' => 'page', 'post_author' => 1, 'post_content' => 'P' ) ); $pop = t_add_post( array( 'post_type' => 'post', 'post_author' => 1, 'post_content' => 'P' ) ); $bk = t_add_post( array( 'post_type' => 'book', 'post_author' => 1, 'post_content' => 'B' ) ); $cv = t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_author' => 1, 'post_content' => 'C' ) );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
check( 'strictest switch wins: with the pages category off, builder status of a page is refused (not_allowed from the handler); a post, a custom type and a canvas still work', 'FORBIDDEN' === call( 'divi-builder-status', array( 'post_id' => $pgp ) ) && 'not_allowed' === code( handler( 'divi-builder-status', array( 'post_id' => $pgp ) ) ) && is_array( call( 'divi-builder-status', array( 'post_id' => $pop ) ) ) && is_array( call( 'divi-builder-status', array( 'post_id' => $bk ) ) ) && is_array( call( 'divi-builder-status', array( 'post_id' => $cv ) ) ) );
d5b_abilities_set_category( 'posts', false, false, 'tester' ); d5b_abilities_set_category( 'cpt', false, false, 'tester' );
check( 'with posts and cpt off too: a post and a custom item are refused, a canvas still works', 'FORBIDDEN' === call( 'divi-builder-status', array( 'post_id' => $pop ) ) && 'FORBIDDEN' === call( 'divi-builder-status', array( 'post_id' => $bk ) ) && 'not_allowed' === code( handler( 'divi-builder-status', array( 'post_id' => $bk ) ) ) && is_array( call( 'divi-builder-status', array( 'post_id' => $cv ) ) ) );
call( 'divi-builder-status', array( 'post_id' => $bk ) );
check( 'a refusal because of a switch is audited as forbidden, with the id', 'forbidden' === last_row()['status'] && 'divi5-builder/divi-builder-status' === last_row()['ability'] && false !== strpos( last_row()['args'], (string) $bk ) );

// ---- history
echo "history-audit-list\n";
site( ADMIN, 1 );
seed_audit( 'divi5-builder/posts-get', 2, 86400 * 3 ); seed_audit( 'divi5-builder/posts-get', 2, 5000 ); seed_audit( 'divi5-builder/pages-get', 1, 4000 ); seed_audit( 'divi5-builder/posts-get', 1, 3000, 'forbidden' ); seed_audit( 'divi5-builder/media-list', 2, 2000 );
$hr = call( 'history-audit-list', array() );
check( 'history-audit-list returns rows newest first (this call\'s own row on top, still "started"; then the five planted rows from the newest to the oldest), with time, user, ability, args, status, duration and ip', is_array( $hr ) && 'divi5-builder/history-audit-list' === $hr['items'][0]['ability'] && 'started' === $hr['items'][0]['status'] && array( 1004, 1003, 1002, 1001, 1000 ) === array_slice( ids( $hr ), 1 ) && 6 === count( $hr['items'] ) && isset( $hr['items'][1]['time_utc'], $hr['items'][1]['user_id'], $hr['items'][1]['args'], $hr['items'][1]['status'], $hr['items'][1]['duration_ms'], $hr['items'][1]['ip'] ), $hr );
$f = call( 'history-audit-list', array( 'ability' => 'divi5-builder/posts-get' ) );
check( 'filter by ability: exactly the rows of that ability, newest first (1003, 1001, 1000)', is_array( $f ) && array( 1003, 1001, 1000 ) === ids( $f ) && array( 'divi5-builder/posts-get' ) === array_values( array_unique( array_column( $f['items'], 'ability' ) ) ), ids( $f ) );
$u = call( 'history-audit-list', array( 'user' => 2, 'ability' => 'divi5-builder/posts-get' ) );
check( 'filter by user (with an ability filter): only that user\'s rows (1001, 1000)', array( 1001, 1000 ) === ids( $u ), ids( $u ) );
check( 'filter by user alone: rows of user 1 only (the planted pages-get and forbidden rows, none of the rows of user 2)', ( function () { $i = ids( call( 'history-audit-list', array( 'user' => 1 ) ) ); return in_array( 1003, $i, true ) && in_array( 1002, $i, true ) && array() === array_intersect( $i, array( 1004, 1001, 1000 ) ); } )() );
$since = gmdate( 'Y-m-d', time() - 86400 );
$sn = call( 'history-audit-list', array( 'since' => $since, 'ability' => 'divi5-builder/posts-get' ) );
check( 'filter by since (YYYY-MM-DD, UTC): the 3-day-old row is left out, the newer ones stay', is_array( $sn ) && array( 1003, 1001 ) === ids( $sn ), ids( $sn ) );
check( 'since must be a real day written YYYY-MM-DD: 2026-02-30, 2026-1-1, 28/10/2026, a time and a number are all refused', 'invalid_input' === code( handler( 'history-audit-list', array( 'since' => '2026-02-30' ) ) ) && 'invalid_input' === code( handler( 'history-audit-list', array( 'since' => '2026-1-1' ) ) ) && 'invalid_input' === code( handler( 'history-audit-list', array( 'since' => '28/10/2026' ) ) ) && 'invalid_input' === code( handler( 'history-audit-list', array( 'since' => '2026-10-28 10:00:00' ) ) ) && 'invalid_input' === code( handler( 'history-audit-list', array( 'since' => 20261028 ) ) ) && is_array( handler( 'history-audit-list', array( 'since' => '2024-02-29' ) ) ) );
check( 'ability up to 191 characters is accepted, 192 is refused; user must be a positive whole number; unknown input is refused', is_array( handler( 'history-audit-list', array( 'ability' => str_repeat( 'a', 191 ) ) ) ) && 'invalid_input' === code( handler( 'history-audit-list', array( 'ability' => str_repeat( 'a', 192 ) ) ) ) && 'invalid_input' === code( handler( 'history-audit-list', array( 'user' => 0 ) ) ) && 'invalid_input' === code( handler( 'history-audit-list', array( 'user' => 'me' ) ) ) && 'invalid_input' === code( handler( 'history-audit-list', array( 'status' => 'ok' ) ) ) );
for ( $i = 129; $i >= 0; $i-- ) { seed_audit( 'divi5-builder/bulk', 1, 100 + $i ); }
$pg1 = call( 'history-audit-list', array( 'ability' => 'divi5-builder/bulk', 'per_page' => 500 ) ); $pg2 = call( 'history-audit-list', array( 'ability' => 'divi5-builder/bulk', 'per_page' => 100, 'page' => 2 ) );
check( 'per_page 500 is capped at 100 and says there is more; page 2 has the remaining 30 and says there is not; no row appears on both pages', is_array( $pg1 ) && 100 === count( $pg1['items'] ) && 100 === $pg1['per_page'] && true === $pg1['has_more'] && 30 === count( $pg2['items'] ) && false === $pg2['has_more'] && array() === array_intersect( ids( $pg1 ), ids( $pg2 ) ), array( count( $pg1['items'] ), $pg2['has_more'] ?? null ) );
$lim = array_filter( $GLOBALS['wpdb']->queries, function ( $q ) { return false !== strpos( $q, 'LIMIT' ); } );
check( 'the database was asked for per_page + 1 rows (101), never 500, and every query went through prepare()', 1 === count( array_filter( $lim, function ( $q ) { return false !== strpos( $q, 'LIMIT 101 OFFSET 0' ); } ) ) && array() === array_filter( $lim, function ( $q ) { return false !== strpos( $q, 'LIMIT 501' ); } ) && array() === $GLOBALS['wpdb']->unprepared, $lim );
check( 'page_too_deep: a page that starts more than 10,000 rows in is refused; bad paging input is invalid_input', 'page_too_deep' === code( call( 'history-audit-list', array( 'per_page' => 100, 'page' => 102 ) ) ) && 'invalid_input' === code( call( 'history-audit-list', array( 'page' => 'x' ) ) ) );
check( 'this call is audited: one row with status ok and its filters in the args', ( function () { $r = rows(); foreach ( array_reverse( $r ) as $x ) { if ( 'divi5-builder/history-audit-list' === $x['ability'] && 'ok' === $x['status'] && false !== strpos( $x['args'], 'divi5-builder/bulk' ) ) { return true; } } return false; } )() );
// secrets
d5b_audit_write( 'divi5-builder/x', array( 'password' => 'hunter2', 'token' => 'abc123def', 'note' => 'fine' ), 'ok', 1 );
$sl = call( 'history-audit-list', array( 'ability' => 'divi5-builder/x' ) );
check( 'rows written with a password or token argument come back redacted, other arguments stay', is_array( $sl ) && 1 === count( $sl['items'] ) && false === strpos( json_encode( $sl ), 'hunter2' ) && false === strpos( json_encode( $sl ), 'abc123def' ) && false !== strpos( $sl['items'][0]['args'], 'fine' ), $sl );
$GLOBALS['wpdb']->tables[ d5b_audit_table() ][] = array( 'id' => 5000, 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'user_id' => 1, 'ability' => 'divi5-builder/old', 'args' => 'api_key=sk_live_ABCDEFGH12345678 and Authorization: Bearer abc.def.ghi', 'status' => 'ok', 'duration_ms' => 1, 'ip' => '' );
$ol = call( 'history-audit-list', array( 'ability' => 'divi5-builder/old' ) );
check( 'even an old, unredacted row is masked on the way out (the wrapper scrubs the text again) and the result says masked', is_array( $ol ) && false === strpos( json_encode( $ol ), 'sk_live_ABCDEFGH' ) && true === ( $ol['masked'] ?? false ), $ol );
// capability
site( EDITOR, 1 );
check( 'an editor is refused (manage_options), the refusal is logged, and the handler alone refuses too', 'FORBIDDEN' === call( 'history-audit-list' ) && 'forbidden' === last_row()['status'] && 'divi5-builder/history-audit-list' === last_row()['ability'] && 'forbidden' === code( handler( 'history-audit-list' ) ) );
site( ADMIN, 1 );
check( 'with no table (never installed) the list is empty, not an error', ( function () { global $wpdb; $wpdb->tables = array(); return is_array( handler( 'history-audit-list' ) ) && array() === handler( 'history-audit-list' )['items']; } )() );
$src = file_get_contents( $dir . '/inc/abilities/read-history.php' );
check( 'multisite: the description says it reads the current site\'s log only', false !== stripos( d5b_ability_defs()['divi5-builder/history-audit-list']['description'], 'current site' ) && 1 === preg_match( '/d5b_audit_list\(/', $src ) );

echo "\n" . ( $failed ? "$failed of $n checks FAILED\n" : "all $n checks passed\n" );
exit( $failed ? 1 : 0 );
