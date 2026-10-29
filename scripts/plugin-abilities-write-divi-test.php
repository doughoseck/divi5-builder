<?php
/**
 * Divi write abilities (#32, release 2.2.0, Tasks 8 and 9): builder mode, canvas link, design-system writes and backups,
 * Theme Builder layout write and restore, d5b_wd_target (the target table of the Divi content edits), and the two content
 * edits divi-section-edit and divi-text-edit with the visible-text map of divi-text-edit.
 *
 *   php scripts/plugin-abilities-write-divi-test.php
 *
 * Loads the real inc/ files and the real parts/page-building.php with WordPress stand-ins (plugin-test-stubs.php, plus the
 * few below) and stand-in Divi design-system classes (through the d5b_ds_class filter, as ds-write-test.php does).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The route snapshot: the three write routes (POST tb-layout, tb-layout-restore, link-canvas) became calls of named
 * functions. A child process loads one page-building.php, runs every success and refusal path through the route (its
 * permission callback first) and prints status, error code, data and the stored state. It runs against the file as it
 * was at ce24986 (from git) and against the file under test; the two outputs must be byte-identical.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.2.0' );
require __DIR__ . '/plugin-test-stubs.php';

// ---- stand-ins the shared stubs do not have
function metadata_exists( $t, $id, $k ) { return isset( $GLOBALS['T_meta'][ (int) $id ][ $k ] ); }
function maybe_unserialize( $v ) { return $v; }
function clean_post_cache( $id ) {}
function wp_json_encode( $v ) { return json_encode( $v ); }
// One block per run of text: enough for d5b_tb_tree to tell a whitespace change (same tree) from damage (another tree).
function parse_blocks( $content ) { return array( array( 'blockName' => null, 'attrs' => array(), 'innerHTML' => (string) $content, 'innerBlocks' => array() ) ); }
function et_get_option( $name, $default = '', ...$rest ) { return array_key_exists( "et:$name", $GLOBALS['T_options'] ) ? $GLOBALS['T_options'][ "et:$name" ] : $default; }
function et_update_option( $name, $value ) { $GLOBALS['T_options'][ "et:$name" ] = $value; return true; }
// Divi's design-system classes, standing in: what is tested is the plugin's read-modify-write and the ability around it.
class T_DS_Data {
	static function sanitize_global_colors_data( $d ) { return $d; }
	static function set_global_colors( $d, $merge = false ) { $g = et_get_option( 'et_global_data', array() ); $g = is_array( $g ) ? $g : array(); $g['global_colors'] = $merge ? array_merge( $g['global_colors'] ?? array(), $d ) : $d; et_update_option( 'et_global_data', $g ); }
	static function set_global_variables( $d, $expected = null ) { et_update_option( 'global_variables', $d ); }
	static function validate_global_variable_ids() { return true; } // Divi 5.15+: a variable is archived, never dropped
}
class T_DS_Preset {
	static function get_data() { return $GLOBALS['T_options']['et:presets'] ?? array(); }
	static function save_data( $d ) { $GLOBALS['T_options']['et:presets'] = $d; return $d; }
	static function prepare_data( $schema ) {
		$out = array();
		foreach ( $schema as $kind => $recs ) { foreach ( $recs as $rec ) { foreach ( $rec['items'] as $it ) { $sub = 'group' === $kind ? $it['groupName'] : $it['moduleName']; $out[ $kind ][ $sub ]['default'] = $rec['default']; $out[ $kind ][ $sub ]['items'][ $it['id'] ] = $it; } } }
		return $out;
	}
	static function maybe_create_default_presets_after_import( $d ) { return $d; }
}
class T_DS_Conv {
	static function get_preset_attrs_mapping( $m ) { return array( 'module' => 'x' ); }
	static function get_preset_attrs( $attrs, $types, $map ) { return in_array( 'style', $types, true ) ? $attrs : array(); }
}

// ---- the route snapshot (also the child process)
const SNAP_ADMIN = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_theme_options', 'unfiltered_html' );
function snap_site( array $caps, int $uid = 1 ) { t_reset(); t_set_caps( $caps ); t_set_multisite( false ); $GLOBALS['T_user_id'] = $uid; d5b_routes_page_building(); }
function snap_route( $path, array $params ) {
	$r = $GLOBALS['T_routes'][ 'POST divi5-builder/v1' . $path ] ?? null;
	if ( null === $r ) { return 'NO ROUTE'; }
	if ( ! call_user_func( $r['permission_callback'], new T_Request( $params, 'POST' ) ) ) { return 'DENIED'; }
	return call_user_func( $r['callback'], new T_Request( $params, 'POST' ) );
}
function snap_state( array $ids ) {
	$out = array();
	foreach ( $ids as $id ) {
		$m = $GLOBALS['T_meta'][ (int) $id ] ?? array();
		foreach ( array( '_d5b_prev_saved_at', '_divi_canvas_created_at' ) as $k ) { if ( isset( $m[ $k ] ) ) { foreach ( $m[ $k ] as $i => $v ) { if ( 0 === strpos( (string) $v, gmdate( 'Y-m-d' ) ) ) { $m[ $k ][ $i ] = 'NOW'; } } } }
		ksort( $m );
		$out[ $id ] = array( 'content' => isset( $GLOBALS['T_posts'][ (int) $id ] ) ? $GLOBALS['T_posts'][ (int) $id ]->post_content : null, 'meta' => $m );
	}
	return $out;
}
function snap_one( $name, $r, array $ids ) {
	if ( is_wp_error( $r ) ) { $d = $r->get_error_data(); $e = array( 'status' => is_array( $d ) ? ( $d['status'] ?? null ) : null, 'code' => $r->get_error_code(), 'message' => $r->get_error_message(), 'data' => $d ); }
	elseif ( is_string( $r ) ) { $e = array( 'status' => $r ); }
	else { $e = array( 'status' => 200, 'json' => json_encode( $r ) ); }
	return array( 'name' => $name ) + $e + array( 'state' => snap_state( $ids ) );
}
function snap_all(): array {
	$D    = "<!-- wp:divi/section -->\n<!-- wp:divi/text {\"c\":\"a \\\" b\"} -->Hello<!-- /wp:divi/text -->\n<!-- /wp:divi/section -->";
	$D2   = "<!-- wp:divi/section -->\n<!-- wp:divi/text {\"c\":\"new\"} -->World<!-- /wp:divi/text -->\n<!-- /wp:divi/section -->";
	$out  = array();
	$tb   = function ( array $caps, callable $params, $name, $before = null ) use ( &$out, $D ) {
		snap_site( $caps );
		$l  = t_add_post( array( 'post_type' => 'et_footer_layout', 'post_author' => 2, 'post_content' => $D, 'post_modified_gmt' => '2026-10-20 08:00:00' ) );
		$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 2, 'post_content' => 'PAGE' ) );
		if ( $before ) { $before( $l ); }
		$out[] = snap_one( $name, snap_route( '/tb-layout', $params( $l, $pg, md5( $D ) ) ), array( $l, $pg ) );
	};
	$noeo = array_values( array_diff( SNAP_ADMIN, array( 'edit_others_posts' ) ) );
	$nouf = array_values( array_diff( SNAP_ADMIN, array( 'unfiltered_html' ) ) );
	$tb( array( 'read', 'edit_posts', 'edit_others_posts', 'unfiltered_html' ), function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $l, 'content' => $D2, 'expect_hash' => $h ); }, 'tb: no edit_theme_options' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $p, 'content' => $D2, 'expect_hash' => $h ); }, 'tb: a page id' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => 99999, 'content' => $D2, 'expect_hash' => $h ); }, 'tb: a missing id' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'content' => $D2, 'expect_hash' => $h ); }, 'tb: no id' );
	$tb( $noeo, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $l, 'content' => $D2, 'expect_hash' => $h ); }, 'tb: no edit_post on the layout' );
	$tb( $nouf, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $l, 'content' => $D2, 'expect_hash' => $h ); }, 'tb: no unfiltered_html' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) { return array( 'id' => $l, 'content' => '', 'expect_hash' => $h ); }, 'tb: empty content' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) { return array( 'id' => $l, 'content' => " \n ", 'expect_hash' => $h ); }, 'tb: blank content' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) { return array( 'id' => $l, 'content' => array( 'x' ), 'expect_hash' => $h ); }, 'tb: content a list' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) { return array( 'id' => $l, 'expect_hash' => $h ); }, 'tb: no content' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $l, 'content' => $D2 ); }, 'tb: no hash' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) { return array( 'id' => $l ); }, 'tb: neither content nor hash (which refusal comes first)' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) { return array( 'id' => $l, 'content' => 'plain', 'expect_hash' => md5( 'other' ) ); }, 'tb: stale hash and not Divi (which refusal comes first)' );
	$tb( $nouf, function ( $l, $p, $h ) { return array( 'id' => $p, 'content' => '' ); }, 'tb: a page id, no unfiltered_html, nothing else (which refusal comes first)' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $l, 'content' => $D2, 'expect_hash' => md5( 'other' ) ); }, 'tb: stale hash' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $l, 'content' => $D2, 'expect_hash' => strtoupper( $h ) ); }, 'tb: hash in capitals' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) { return array( 'id' => $l, 'content' => 'plain words', 'expect_hash' => $h ); }, 'tb: not Divi' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) { return array( 'id' => $l, 'content' => '<!-- wp:divi/section --><!-- wp:divi/text -->x<!-- /wp:divi/section -->', 'expect_hash' => $h ); }, 'tb: unbalanced' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $l, 'content' => $D2, 'expect_hash' => $h, 'dry_run' => true ); }, 'tb: dry run' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $l, 'content' => $D2, 'expect_hash' => $h ); }, 'tb: success' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) { return array( 'id' => $l, 'content' => '<!-- wp:divi/section /-->[et_pb_section][/et_pb_section]', 'expect_hash' => $h, 'mark_divi5' => '1' ); }, 'tb: success, self-closing block, shortcodes, mark_divi5' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => (string) $l, 'content' => $D2, 'expect_hash' => $h, 'dry_run' => 0, 'mark_divi5' => '' ); }, 'tb: success, id as text, falsy flags' );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $l, 'content' => $D2, 'expect_hash' => $h ); }, 'tb: save re-serialises (whitespace only)', function () { $GLOBALS['T_save_content'] = function ( $c ) { return str_replace( '-->', " -->\n", $c ); }; } );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $l, 'content' => $D2, 'expect_hash' => $h ); }, 'tb: save damages the content (auto-revert)', function () { $GLOBALS['T_save_content'] = function ( $c ) { return str_replace( 'World', 'Wor', $c ); }; } );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $l, 'content' => $D2, 'expect_hash' => $h ); }, 'tb: the save fails', function () { $GLOBALS['T_update_fail'] = true; } );
	$tb( SNAP_ADMIN, function ( $l, $p, $h ) use ( $D2 ) { return array( 'id' => $l, 'content' => $D2, 'expect_hash' => $h ); }, 'tb: success over an existing safety copy', function ( $l ) { t_add_meta( $l, '_d5b_prev_content', 'OLDER' ); t_add_meta( $l, '_d5b_prev_saved_at', '2026-01-01T00:00:00+00:00' ); } );

	$rs = function ( array $caps, $name, $before = null, $id = null ) use ( &$out, $D ) {
		snap_site( $caps );
		$l  = t_add_post( array( 'post_type' => 'et_header_layout', 'post_author' => 2, 'post_content' => $D ) );
		$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 2, 'post_content' => 'PAGE' ) );
		if ( $before ) { $before( $l ); }
		$out[] = snap_one( $name, snap_route( '/tb-layout-restore', array( 'id' => null === $id ? $l : ( 'page' === $id ? $pg : $id ) ) ), array( $l, $pg ) );
	};
	$backup = function ( $l ) { t_add_meta( $l, '_d5b_prev_content', "<!-- wp:divi/section -->OLD<!-- /wp:divi/section -->" ); };
	$rs( array( 'read', 'edit_posts', 'edit_others_posts', 'unfiltered_html' ), 'restore: no edit_theme_options', $backup );
	$rs( SNAP_ADMIN, 'restore: a page id', $backup, 'page' );
	$rs( SNAP_ADMIN, 'restore: a missing id', $backup, 99999 );
	$rs( $noeo, 'restore: no edit_post', $backup );
	$rs( $nouf, 'restore: no unfiltered_html', $backup );
	$rs( SNAP_ADMIN, 'restore: no safety copy' );
	$rs( SNAP_ADMIN, 'restore: empty safety copy', function ( $l ) { t_add_meta( $l, '_d5b_prev_content', "  \n" ); } );
	$rs( SNAP_ADMIN, 'restore: success', $backup );
	$rs( SNAP_ADMIN, 'restore: save damages the content', function ( $l ) use ( $backup ) { $backup( $l ); $GLOBALS['T_save_content'] = function ( $c ) { return str_replace( 'OLD', 'NEW', $c ); }; } );

	$lk = function ( array $caps, int $uid, $name, callable $params, $before = null ) use ( &$out ) {
		snap_site( $caps, $uid );
		$c  = t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_author' => 1, 'post_content' => 'C' ) );
		$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 1, 'post_content' => 'P' ) );
		$po = t_add_post( array( 'post_type' => 'page', 'post_author' => 2, 'post_content' => 'Q' ) );
		if ( $before ) { $before( $c, $pg ); }
		$out[] = snap_one( $name, snap_route( '/link-canvas', $params( $c, $pg, $po ) ), array( $c, $pg, $po ) );
	};
	$lk( SNAP_ADMIN, 1, 'link: no ids', function () { return array(); } );
	$lk( array( 'read', 'edit_posts' ), 1, 'link: another user\'s page', function ( $c, $pg, $po ) { return array( 'canvas_id' => $c, 'page_id' => $po ); } );
	$lk( SNAP_ADMIN, 1, 'link: a missing page', function ( $c ) { return array( 'canvas_id' => $c, 'page_id' => 99999 ); } );
	$lk( SNAP_ADMIN, 1, 'link: canvas_id is a page', function ( $c, $pg, $po ) { return array( 'canvas_id' => $po, 'page_id' => $pg ); } );
	$lk( SNAP_ADMIN, 1, 'link: success, new canvas id', function ( $c, $pg ) { return array( 'canvas_id' => $c, 'page_id' => $pg ); } );
	$lk( SNAP_ADMIN, 1, 'link: success, kept canvas id and created_at, a named canvas', function ( $c, $pg ) { return array( 'canvas_id' => (string) $c, 'page_id' => (string) $pg, 'canvas_name' => ' <b>Promo</b> bar ' ); },
		function ( $c ) { t_add_meta( $c, '_divi_canvas_id', 'b7d0c1de-0000-4000-8000-000000000009' ); t_add_meta( $c, '_divi_canvas_created_at', '2026-01-02T03:04:05.000Z' ); t_add_meta( $c, '_divi_canvas_parent_post_id', '7' ); } );
	$lk( SNAP_ADMIN, 1, 'link: an empty canvas_name', function ( $c, $pg ) { return array( 'canvas_id' => $c, 'page_id' => $pg, 'canvas_name' => '' ); } );
	return $out;
}

if ( isset( $argv[1] ) && '--snapshot' === $argv[1] ) {
	require $argv[2];
	echo json_encode( snap_all() );
	exit( 0 );
}

// ---- the plugin, as the loader loads it (write-common.php first among the write files)
foreach ( array( 'parts', 'switches', 'abilities/categories', 'abilities/register', 'abilities/secrets', 'abilities/audit', 'abilities/scrub', 'abilities/history', 'abilities/confirm', 'blocks-text-fields', 'blocks' ) as $f ) { require $dir . '/inc/' . $f . '.php'; }
$d5b_files = array_values( array_filter( glob( $dir . '/inc/abilities/*.php' ), function ( $p ) { return 1 === preg_match( '/^(read|write)-/', basename( $p ) ); } ) );
usort( $d5b_files, function ( $a, $b ) { $w = function ( $p ) { return 'write-common.php' === basename( $p ) ? 1 : ( 0 === strpos( basename( $p ), 'read-' ) ? 0 : 2 ); }; return $w( $a ) <=> $w( $b ) ?: strcmp( $a, $b ); } );
foreach ( $d5b_files as $f ) { require $f; }
require $dir . '/parts/page-building.php';

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 900 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ADMIN   = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'read_private_posts', 'publish_posts', 'manage_options', 'edit_theme_options', 'unfiltered_html' );
const EDITOR  = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'read_private_posts', 'publish_posts', 'unfiltered_html' );
const CONTRIB = array( 'read', 'edit_posts' );
function without( array $caps, string ...$drop ) { return array_values( array_diff( $caps, $drop ) ); }

function site( array $caps = ADMIN, int $uid = 1 ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_set_caps( $caps ); t_set_multisite( false );
	$GLOBALS['T_user_id'] = $uid; $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	$GLOBALS['T_ds_classes'] = array( 'data' => 'T_DS_Data', 'preset' => 'T_DS_Preset', 'conversion' => 'T_DS_Conv' );
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
	d5b_routes_page_building();
	t_add_type( 'book', true, true );
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function handler( $name, $in = array() ) { return call_user_func( d5b_ability_defs()[ 'divi5-builder/' . $name ]['handler'], $in ); }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function status( $r ) { $d = is_wp_error( $r ) ? $r->get_error_data() : null; return is_array( $d ) ? ( $d['status'] ?? null ) : null; }
function hrows() { global $wpdb; return $wpdb->tables[ d5b_history_table() ] ?? array(); }
function arows() { global $wpdb; return $wpdb->tables[ d5b_audit_table() ] ?? array(); }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function alast() { $r = arows(); return $r ? end( $r ) : null; }
function post( $id ) { return $GLOBALS['T_posts'][ (int) $id ] ?? null; }
function rows_of( $id, $k ) { return array_values( $GLOBALS['T_meta'][ (int) $id ][ $k ] ?? array() ); }
function colors() { $g = et_get_option( 'et_global_data', array() ); return $g['global_colors'] ?? array(); }
function vars_store() { return et_get_option( 'global_variables', array() ); }
/** Without the fields that carry the time of the call (lastUpdated, updated, created). */
function untimed( $v ) { if ( ! is_array( $v ) ) { return $v; } foreach ( array( 'lastUpdated', 'updated', 'created' ) as $k ) { unset( $v[ $k ] ); } foreach ( $v as $k => $x ) { $v[ $k ] = untimed( $x ); } return $v; }
function same( $a, $b ) { return json_encode( $a ) === json_encode( $b ); }

$names = array( 'divi-builder-mode', 'divi-link-canvas', 'divi-ds-color-set', 'divi-ds-variable-set', 'divi-ds-variable-archive', 'divi-ds-preset-set', 'divi-ds-backups-list', 'divi-ds-restore', 'divi-tb-write', 'divi-tb-restore', 'divi-section-edit', 'divi-text-edit' );

// =====================================================================================================
echo "registration\n";
$defs = d5b_ability_defs();
$bad  = array();
foreach ( $names as $x ) {
	$d = $defs[ 'divi5-builder/' . $x ] ?? null;
	if ( null === $d ) { $bad[] = "$x missing"; continue; }
	if ( 'divi' !== $d['category'] ) { $bad[] = "$x category"; }
	if ( strlen( $d['description'] ) < 40 || ! preg_match( '/return/i', $d['description'] ) || ! preg_match( '/refus/i', $d['description'] ) ) { $bad[] = "$x description"; }
	foreach ( (array) ( $d['input_schema']['properties'] ?? array() ) as $k => $p ) { if ( empty( $p['description'] ) ) { $bad[] = "$x.$k undescribed"; } }
	if ( false !== $d['input_schema']['additionalProperties'] ) { $bad[] = "$x allows unknown input"; }
	if ( ! is_callable( $d['handler'] ) || ! is_callable( $d['permission'] ) ) { $bad[] = "$x callables"; }
}
check( 'all 12 Divi write abilities are defined in the divi category, lint-clean, closed schemas, with callables', array() === $bad, $bad );
$ann = array();
foreach ( $names as $x ) { $ann[ $x ] = $defs[ 'divi5-builder/' . $x ]['annotations'] ?? null; }
$want = array_fill_keys( $names, array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ) );
$want['divi-builder-mode']['idempotent'] = true; $want['divi-ds-variable-archive']['destructive'] = true; $want['divi-ds-backups-list'] = array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
$want['divi-section-edit']['destructive'] = true; // op remove takes a block out
check( 'annotations: builder-mode idempotent, variable-archive and section-edit (remove) destructive, backups-list read-only; every other write neither', $want === $ann, $ann );
$bad = array();
foreach ( d5b_ability_defs() as $nm => $d ) { if ( false !== strpos( $nm, 'global-colors' ) || false !== strpos( $nm, 'option' ) ) { $bad[] = $nm; } }
check( '/global-colors and /option have no ability: no registered ability name holds "global-colors" or "option" (all shipped files loaded: ' . count( d5b_ability_defs() ) . ' abilities)', array() === $bad && count( d5b_ability_defs() ) > 60, $bad );
$src_pb = file_get_contents( $dir . '/parts/page-building.php' );
$bad    = array();
foreach ( array( 'divi-ds-color-set' => 'd5b_ds_color_upsert', 'divi-ds-variable-set' => 'd5b_ds_variable_upsert', 'divi-ds-variable-archive' => 'd5b_ds_variable_archive', 'divi-ds-preset-set' => 'd5b_ds_preset_upsert' ) as $x => $fn ) {
	preg_match( '/function ' . $fn . '\( \$in, \$dry \) \{(.*?)\r?\n\t\}\r?\n/s', $src_pb, $m );
	preg_match_all( "/\\\$in\\[\\s*'([A-Za-z]+)'\\s*\\]/", $m[1] ?? '', $k );
	$read  = array_values( array_unique( $k[1] ) ); sort( $read );
	$props = array_keys( $defs[ 'divi5-builder/' . $x ]['input_schema']['properties'] ); sort( $props );
	if ( $read !== $props || array() === $read ) { $bad[ $x ] = array( 'function reads' => $read, 'schema' => $props ); }
	if ( isset( $defs[ 'divi5-builder/' . $x ]['input_schema']['properties']['dry_run'] ) || isset( $defs[ 'divi5-builder/' . $x ]['input_schema']['properties']['dryRun'] ) ) { $bad[ $x ] = 'dry_run'; }
}
check( 'each design-system ability\'s input schema is exactly the fields its named function reads from $in (read from the source), and none takes dry_run', array() === $bad, $bad );
$ck = array(); $rp = array();
foreach ( $names as $x ) { if ( ! empty( $defs[ 'divi5-builder/' . $x ]['content_keys'] ) ) { $ck[] = $x; } if ( ! empty( $defs[ 'divi5-builder/' . $x ]['raw_paths'] ) ) { $rp[] = $x; } }
check( 'content keys for the two layout writes and the two content edits; no raw paths (the design system is Divi\'s own data, strict masking as divi-design-system)', array( 'divi-tb-write', 'divi-tb-restore', 'divi-section-edit', 'divi-text-edit' ) === $ck && array() === $rp, array( $ck, $rp ) );
check( 'the two content edits declare the post content keys, content and excerpt among them (the page and the change excerpt are value-shape masked only)', d5b_ability_post_content_keys() === ( $defs['divi5-builder/divi-section-edit']['content_keys'] ?? null ) && d5b_ability_post_content_keys() === ( $defs['divi5-builder/divi-text-edit']['content_keys'] ?? null ) && in_array( 'content', d5b_ability_post_content_keys(), true ) && in_array( 'excerpt', d5b_ability_post_content_keys(), true ) );
$loader = file_get_contents( $dir . '/divi5-builder.php' );
check( 'the loader lists write-divi.php after write-menus.php', 1 === preg_match( "#'inc/abilities/write-menus\.php',\s+'inc/abilities/write-divi\.php',#", $loader ) );
$s = file_get_contents( $dir . '/inc/abilities/write-divi.php' );
check( 'write-divi.php registers no hooks, routes or abilities itself', ! preg_match( '/\badd_action\s*\(|\badd_filter\s*\(|register_rest_route\s*\(|\bwp_register_ability\s*\(/', $s ) );
site();
add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' ); add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );
t_fire( 'wp_abilities_api_categories_init' ); t_fire( 'wp_abilities_api_init' );
$miss = array(); $wrong = array();
foreach ( $names as $x ) { $m = $GLOBALS['T_abilities'][ 'divi5-builder/' . $x ] ?? null; if ( null === $m ) { $miss[] = $x; } elseif ( $want[ $x ] !== $m['meta']['annotations'] ) { $wrong[] = $x; } }
check( 'all 12 are registered with WordPress with those annotations', array() === $miss && array() === $wrong && array() === $GLOBALS['T_ability_errors'], array( $miss, $wrong, $GLOBALS['T_ability_errors'] ) );

// =====================================================================================================
echo "route snapshot: POST tb-layout, tb-layout-restore, link-canvas, before (ce24986) and after the refactor\n";
$run = function ( string $file ) {
	$p = proc_open( array( PHP_BINARY, '-d', 'max_execution_time=30', __FILE__, '--snapshot', $file ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pp );
	if ( ! is_resource( $p ) ) { return array( -1, '', 'proc_open failed' ); }
	$o = stream_get_contents( $pp[1] ); $e = stream_get_contents( $pp[2] ); fclose( $pp[1] ); fclose( $pp[2] );
	return array( proc_close( $p ), $o, $e );
};
$g = proc_open( array( 'git', '-C', __DIR__ . '/..', 'show', 'ce24986:plugin/divi5-builder/parts/page-building.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $gp );
$old_src = is_resource( $g ) ? stream_get_contents( $gp[1] ) : ''; if ( is_resource( $g ) ) { fclose( $gp[1] ); fclose( $gp[2] ); proc_close( $g ); }
$old_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'd5b-pb-ce24986-' . getmypid() . '.php';
file_put_contents( $old_file, $old_src );
check( 'the file as it was at ce24986 comes from git, with the three route closures in it', false !== strpos( $old_src, '$tb_write = function' ) && false === strpos( $old_src, 'function d5b_tb_write' ) );
list( $c1, $before, $e1 ) = $run( $old_file );
list( $c2, $after, $e2 )  = $run( $dir . '/parts/page-building.php' );
@unlink( $old_file );
$snap = json_decode( $before, true );
$cases = is_array( $snap ) ? array_column( $snap, 'name' ) : array();
$codes = is_array( $snap ) ? array_values( array_unique( array_filter( array_column( $snap, 'code' ) ) ) ) : array();
sort( $codes );
$want_codes = array( 'bad_canvas', 'db_update_error', 'empty_backup', 'empty_content', 'expect_hash_required', 'forbidden', 'needs_unfiltered_html', 'no_backup', 'not_a_layout', 'not_divi', 'roundtrip_failed', 'stale', 'unbalanced_blocks' );
check( 'the snapshot ran on both files (exit 0, no PHP warnings) and covers ' . count( $cases ) . ' cases: every refusal code of the three routes, DENIED, dry run and the successes', 0 === $c1 && 0 === $c2 && '' === trim( $e1 . $e2 ) && $want_codes === $codes && count( $cases ) >= 38 && in_array( 'DENIED', array_column( $snap, 'status' ), true ), array( $c1, $c2, $e1, $e2, $codes ) );
check( 'BYTE-IDENTICAL: status, error code, message, data, response JSON and the stored content and meta after every case are the same before and after the refactor', '' !== $before && $before === $after, ( function () use ( $before, $after ) { $a = json_decode( $before, true ); $b = json_decode( $after, true ); foreach ( (array) $a as $i => $x ) { if ( ( $b[ $i ] ?? null ) !== $x ) { return array( $x, $b[ $i ] ?? null ); } } return 'lengths differ'; } )() );
$by = array(); foreach ( (array) $snap as $x ) { $by[ $x['name'] ] = $x; }
check( 'the snapshot is not vacuous: the auto-revert case put the old content back, the success case stored the new content with the old one as the safety copy', 'roundtrip_failed' === ( $by['tb: save damages the content (auto-revert)']['code'] ?? '' ) && false !== strpos( (string) ( $by['tb: success']['json'] ?? '' ), '"new_hash"' ) && false !== strpos( json_encode( $by['tb: success']['state'] ?? array() ), 'World' ) && false !== strpos( json_encode( $by['tb: success']['state'] ?? array() ), '_d5b_prev_content' ) );

// =====================================================================================================
echo "d5b_wd_target: the target table\n";
site();
$ids = array(
	'post' => t_add_post( array( 'post_type' => 'post' ) ), 'page' => t_add_post( array( 'post_type' => 'page' ) ), 'book' => t_add_post( array( 'post_type' => 'book' ) ),
	'et_header_layout' => t_add_post( array( 'post_type' => 'et_header_layout' ) ), 'et_body_layout' => t_add_post( array( 'post_type' => 'et_body_layout' ) ), 'et_footer_layout' => t_add_post( array( 'post_type' => 'et_footer_layout' ) ),
	'et_pb_canvas' => t_add_post( array( 'post_type' => 'et_pb_canvas' ) ), 'et_pb_layout' => t_add_post( array( 'post_type' => 'et_pb_layout' ) ),
	'et_template' => t_add_post( array( 'post_type' => 'et_template' ) ), 'et_theme_builder' => t_add_post( array( 'post_type' => 'et_theme_builder' ) ),
	'attachment' => t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit' ) ), 'wp_block' => t_add_post( array( 'post_type' => 'wp_block' ) ),
	'revision' => t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit' ) ), 'nav_menu_item' => t_add_post( array( 'post_type' => 'nav_menu_item' ) ),
	'wp_template' => t_add_post( array( 'post_type' => 'wp_template' ) ),
);
$table = array( 'post' => 'post', 'page' => 'post', 'book' => 'post', 'et_header_layout' => 'tb', 'et_body_layout' => 'tb', 'et_footer_layout' => 'tb', 'et_pb_canvas' => 'post', 'et_pb_layout' => 'post',
	'et_template' => 'not_allowed', 'et_theme_builder' => 'not_allowed', 'attachment' => 'not_allowed', 'wp_block' => 'not_allowed', 'revision' => 'not_allowed', 'nav_menu_item' => 'not_allowed', 'wp_template' => 'not_allowed' );
$tgt = function () use ( $ids ) { $o = array(); foreach ( $ids as $k => $id ) { $t = d5b_wd_target( $id ); $o[ $k ] = is_wp_error( $t ) ? $t->get_error_code() : ( $t['via'] . ( $t['post']->ID === $id && $t['type'] === $k ? '' : '?' ) ); } return $o; };
check( 'every row: posts, pages and custom items via post; header, body and footer layouts via tb; canvases and Divi Library items via post; templates, Theme Builder, media, blocks, revisions, menu items and WordPress templates not_allowed', $table === $tgt(), $tgt() );
check( 'a missing id and 0 are not_found', 'not_found' === code( d5b_wd_target( 99999 ) ) && 'not_found' === code( d5b_wd_target( 0 ) ) );
t_set_caps( without( ADMIN, 'unfiltered_html' ) );
$o = $tgt(); $exp = array(); foreach ( $table as $k => $v ) { $exp[ $k ] = 'not_allowed' === $v ? 'not_allowed' : 'needs_unfiltered_html'; }
check( 'without unfiltered_html every reachable row is refused (needs_unfiltered_html); d5b_wd_reach without the html check still reaches them', $exp === $o && 'post' === d5b_wd_reach( $ids['page'], false )['via'] && 'tb' === d5b_wd_reach( $ids['et_footer_layout'], false )['via'], $o );
t_set_caps( without( ADMIN, 'edit_theme_options' ) );
check( 'without edit_theme_options the layouts are refused (not_allowed); posts, canvases and library items are not', 'not_allowed' === code( d5b_wd_target( $ids['et_header_layout'] ) ) && 'post' === d5b_wd_target( $ids['page'] )['via'] && 'post' === d5b_wd_target( $ids['et_pb_canvas'] )['via'] );
t_set_caps( ADMIN );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
check( 'strictest switch wins: with pages off a page is not_allowed; posts, custom items, layouts and canvases stay reachable', 'not_allowed' === code( d5b_wd_target( $ids['page'] ) ) && 'post' === d5b_wd_target( $ids['post'] )['via'] && 'post' === d5b_wd_target( $ids['book'] )['via'] && 'tb' === d5b_wd_target( $ids['et_body_layout'] )['via'] && 'post' === d5b_wd_target( $ids['et_pb_layout'] )['via'] );
d5b_abilities_set_category( 'pages', true, false, 'tester' ); d5b_abilities_set_category( 'cpt', false, false, 'tester' ); d5b_abilities_set_category( 'posts', false, false, 'tester' );
check( 'with posts and cpt off a post and a custom item are not_allowed, a page is reachable', 'not_allowed' === code( d5b_wd_target( $ids['post'] ) ) && 'not_allowed' === code( d5b_wd_target( $ids['book'] ) ) && 'post' === d5b_wd_target( $ids['page'] )['via'] );
d5b_abilities_set_category( 'posts', true, false, 'tester' ); d5b_abilities_set_category( 'cpt', true, false, 'tester' ); d5b_abilities_set_category( 'divi', false, false, 'tester' );
$o = $tgt(); $exp = array_fill_keys( array_keys( $table ), 'not_allowed' );
check( 'with the divi category off every row is not_allowed', $exp === $o, $o );
site( CONTRIB, 3 );
$mine = t_add_post( array( 'post_type' => 'page', 'post_author' => 3, 'post_status' => 'draft' ) ); $theirs = t_add_post( array( 'post_type' => 'page', 'post_author' => 2 ) ); $cv = t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_author' => 2 ) );
$tr = t_add_post( array( 'post_type' => 'page', 'post_author' => 3, 'post_status' => 'trash' ) );
t_set_caps( array_merge( CONTRIB, array( 'unfiltered_html' ) ) );
check( 'edit_post on the item: one\'s own draft is reached; another user\'s published page is not_allowed (readable), their canvas not_found; a trashed item is invalid_input', 'post' === d5b_wd_target( $mine )['via'] && 'not_allowed' === code( d5b_wd_target( $theirs ) ) && 'not_found' === code( d5b_wd_target( $cv ) ) && 'invalid_input' === code( d5b_wd_target( $tr ) ) );

// =====================================================================================================
echo "divi-builder-mode\n";
site();
$pg = t_add_post( array( 'post_type' => 'page', 'post_title' => 'Home', 'post_content' => '<!-- wp:divi/section /-->' ) );
$r  = call( 'divi-builder-mode', array( 'post_id' => $pg, 'on' => true ) );
$h  = hlast();
check( 'on sets _et_pb_use_builder and _et_pb_use_divi_5 to on and returns the state', is_array( $r ) && 'on' === $r['builder'] && true === $r['uses_divi_5'] && true === $r['changed'] && array( 'on' ) === rows_of( $pg, '_et_pb_use_builder' ) && array( 'on' ) === rows_of( $pg, '_et_pb_use_divi_5' ), $r );
check( 'one history row: action update, the page, before = both flags absent, after = both on', $h && 'divi5-builder/divi-builder-mode' === $h['ability'] && 'page' === $h['object_type'] && $pg === $h['object_id'] && 'update' === $h['action'] && array( '_et_pb_use_builder' => array(), '_et_pb_use_divi_5' => array() ) === $h['before'] && array( '_et_pb_use_builder' => array( 'on' ), '_et_pb_use_divi_5' => array( 'on' ) ) === $h['after'] && 1 === count( hrows() ), $h );
check( 'audited ok', 'ok' === alast()['status'] && 'divi5-builder/divi-builder-mode' === alast()['ability'] );
$r2 = call( 'divi-builder-mode', array( 'post_id' => $pg, 'on' => true ) );
check( 'idempotent: on again changes nothing (changed false), flags as they were', is_array( $r2 ) && false === $r2['changed'] && array( 'on' ) === rows_of( $pg, '_et_pb_use_builder' ) );
$r3 = call( 'divi-builder-mode', array( 'post_id' => $pg, 'on' => false ) );
$h  = hlast();
check( 'off sets _et_pb_use_builder off and keeps the Divi 5 flag; the history keeps both flags as they were', is_array( $r3 ) && 'off' === $r3['builder'] && true === $r3['uses_divi_5'] && array( 'off' ) === rows_of( $pg, '_et_pb_use_builder' ) && array( 'on' ) === rows_of( $pg, '_et_pb_use_divi_5' ) && array( '_et_pb_use_builder' => array( 'on' ), '_et_pb_use_divi_5' => array( 'on' ) ) === $h['before'], array( $r3, $h['before'] ?? null ) );
check( 'input: on is required and must be a boolean ("true" from a query string is taken), post_id required, unknown input refused', 'invalid_input' === code( handler( 'divi-builder-mode', array( 'post_id' => $pg ) ) ) && 'invalid_input' === code( handler( 'divi-builder-mode', array( 'post_id' => $pg, 'on' => 'yes' ) ) ) && 'invalid_input' === code( handler( 'divi-builder-mode', array( 'on' => true ) ) ) && 'invalid_input' === code( handler( 'divi-builder-mode', array( 'post_id' => $pg, 'on' => true, 'x' => 1 ) ) ) && is_array( handler( 'divi-builder-mode', array( 'post_id' => $pg, 'on' => 'true' ) ) ) );
$tpl = t_add_post( array( 'post_type' => 'et_template' ) ); $lay = t_add_post( array( 'post_type' => 'et_footer_layout' ) );
check( 'a template is refused (not_allowed); a Theme Builder layout and a canvas can be switched', 'FORBIDDEN' === call( 'divi-builder-mode', array( 'post_id' => $tpl, 'on' => true ) ) && 'not_allowed' === code( handler( 'divi-builder-mode', array( 'post_id' => $tpl, 'on' => true ) ) ) && is_array( call( 'divi-builder-mode', array( 'post_id' => $lay, 'on' => true ) ) ) );
$before_rows = count( hrows() ); // the page is on now (the "true" call above): every refused call below tries to switch it off
d5b_abilities_set_category( 'pages', false, false, 'tester' );
check( 'refused when the post\'s category is off: pages off refuses a page (gate FORBIDDEN, handler not_allowed), flags untouched, no history row', 'FORBIDDEN' === call( 'divi-builder-mode', array( 'post_id' => $pg, 'on' => false ) ) && 'not_allowed' === code( handler( 'divi-builder-mode', array( 'post_id' => $pg, 'on' => false ) ) ) && array( 'on' ) === rows_of( $pg, '_et_pb_use_builder' ) && $before_rows === count( hrows() ) );
d5b_abilities_set_category( 'pages', true, false, 'tester' );
d5b_abilities_set_category( 'divi', false, false, 'tester' );
check( 'refused when the divi category is off: the gate refuses and the handler alone answers not_allowed, flags untouched, no history row', 'FORBIDDEN' === call( 'divi-builder-mode', array( 'post_id' => $pg, 'on' => false ) ) && 'not_allowed' === code( handler( 'divi-builder-mode', array( 'post_id' => $pg, 'on' => false ) ) ) && array( 'on' ) === rows_of( $pg, '_et_pb_use_builder' ) && $before_rows === count( hrows() ) );
site( without( ADMIN, 'unfiltered_html' ) );
$pg = t_add_post( array( 'post_type' => 'page' ) );
check( 'without unfiltered_html: refused (gate FORBIDDEN, handler needs_unfiltered_html)', 'FORBIDDEN' === call( 'divi-builder-mode', array( 'post_id' => $pg, 'on' => true ) ) && 'needs_unfiltered_html' === code( handler( 'divi-builder-mode', array( 'post_id' => $pg, 'on' => true ) ) ) && array() === rows_of( $pg, '_et_pb_use_builder' ) );
site( array( 'read', 'edit_posts', 'unfiltered_html' ), 3 );
$theirs = t_add_post( array( 'post_type' => 'page', 'post_author' => 2 ) );
check( 'another user\'s page without edit_others_posts: refused', 'FORBIDDEN' === call( 'divi-builder-mode', array( 'post_id' => $theirs, 'on' => true ) ) && 'not_allowed' === code( handler( 'divi-builder-mode', array( 'post_id' => $theirs, 'on' => true ) ) ) );

// =====================================================================================================
echo "divi-link-canvas\n";
site();
$pg = t_add_post( array( 'post_type' => 'page', 'post_title' => 'Landing' ) ); $cv = t_add_post( array( 'post_type' => 'et_pb_canvas' ) );
t_add_meta( $pg, '_et_pb_use_builder', 'off' );
$r = call( 'divi-link-canvas', array( 'post_id' => $pg, 'canvas_id' => $cv ) );
$h = hlast();
check( 'links the canvas as the route does: canvas id, parent and created time on the canvas, the pointer and both builder flags on the page', is_array( $r ) && true === $r['ok'] && $pg === $r['page_id'] && $cv === $r['canvas_id'] && '00000000-0000-4000-8000-000000000000' === $r['canvas_uuid'] && array( (string) $pg ) === rows_of( $cv, '_divi_canvas_parent_post_id' ) && array( array( 'activeCanvasId' => '00000000-0000-4000-8000-000000000000', 'mainCanvasName' => 'Main Canvas' ) ) === rows_of( $pg, '_divi_off_canvas_data' ) && array( 'on' ) === rows_of( $pg, '_et_pb_use_builder' ), $r );
check( 'history: the page, before = the link meta of both posts as it was (the builder flag off, nothing else), after = as it is now', $h && 'divi5-builder/divi-link-canvas' === $h['ability'] && $pg === $h['object_id'] && array( '_divi_off_canvas_data' => array(), '_et_pb_use_divi_5' => array(), '_et_pb_use_builder' => array( 'off' ) ) === $h['before']['page']['meta'] && array( '_divi_canvas_id' => array(), '_divi_canvas_parent_post_id' => array(), '_divi_canvas_created_at' => array() ) === $h['before']['canvas']['meta'] && $cv === $h['before']['canvas']['id'] && array( 'on' ) === $h['after']['page']['meta']['_et_pb_use_builder'], $h );
$snap_route = snap_route( '/link-canvas', array( 'canvas_id' => $cv, 'page_id' => $pg ) );
check( 'the ability\'s answer is the route\'s answer (the same function)', same( $snap_route, $r ) );
$pg2 = t_add_post( array( 'post_type' => 'page' ) );
check( 'a canvas_id that is not a canvas: the route\'s own refusal (bad_canvas, 400)', 'bad_canvas' === code( call( 'divi-link-canvas', array( 'post_id' => $pg, 'canvas_id' => $pg2 ) ) ) && 400 === status( handler( 'divi-link-canvas', array( 'post_id' => $pg, 'canvas_id' => $pg2 ) ) ) );
check( 'the target must be a post, page or custom item: a canvas, a layout or a template as post_id is refused', 'not_allowed' === code( handler( 'divi-link-canvas', array( 'post_id' => $cv, 'canvas_id' => $cv ) ) ) && 'not_allowed' === code( handler( 'divi-link-canvas', array( 'post_id' => t_add_post( array( 'post_type' => 'et_header_layout' ) ), 'canvas_id' => $cv ) ) ) && 'FORBIDDEN' === call( 'divi-link-canvas', array( 'post_id' => t_add_post( array( 'post_type' => 'et_template' ) ), 'canvas_id' => $cv ) ) );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
$n_before = count( hrows() );
check( 'with pages off a page cannot be linked (FORBIDDEN; handler not_allowed), nothing written', 'FORBIDDEN' === call( 'divi-link-canvas', array( 'post_id' => $pg2, 'canvas_id' => $cv ) ) && 'not_allowed' === code( handler( 'divi-link-canvas', array( 'post_id' => $pg2, 'canvas_id' => $cv ) ) ) && array() === rows_of( $pg2, '_divi_off_canvas_data' ) && $n_before === count( hrows() ) );
site( array( 'read', 'edit_posts' ), 3 );
$mine = t_add_post( array( 'post_type' => 'page', 'post_author' => 3, 'post_status' => 'draft' ) ); $their_cv = t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_author' => 2 ) ); $my_cv = t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_author' => 3, 'post_status' => 'draft' ) ); $their_pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 2 ) );
check( 'edit_post on both, as the route: another user\'s canvas or page is refused; one\'s own pair works (no unfiltered_html needed: meta only)', 'FORBIDDEN' === call( 'divi-link-canvas', array( 'post_id' => $mine, 'canvas_id' => $their_cv ) ) && 'not_found' === code( handler( 'divi-link-canvas', array( 'post_id' => $mine, 'canvas_id' => $their_cv ) ) ) && 'FORBIDDEN' === call( 'divi-link-canvas', array( 'post_id' => $their_pg, 'canvas_id' => $my_cv ) ) && is_array( call( 'divi-link-canvas', array( 'post_id' => $mine, 'canvas_id' => $my_cv ) ) ) );

// =====================================================================================================
echo "design system writes\n";
function ds_seed() {
	et_update_option( 'et_global_data', array( 'global_colors' => array( 'gcid-brand0001' => array( 'id' => 'gcid-brand0001', 'label' => 'Brand', 'color' => '#2b5c7a', 'status' => 'active', 'order' => '1', 'folder' => '', 'usedInPosts' => array() ) ) ) );
	et_update_option( 'global_variables', array(
		'numbers' => array( 'gvid-gap0000001' => array( 'id' => 'gvid-gap0000001', 'label' => 'Gap', 'value' => '20px', 'order' => '1', 'status' => 'active', 'variableType' => 'numbers' ),
			'gvid-wide000001' => array( 'id' => 'gvid-wide000001', 'label' => 'Wide', 'value' => 'calc(var(--gvid-gap0000001) * 2)', 'order' => '2', 'status' => 'active', 'variableType' => 'numbers' ),
			'gvid-lone000001' => array( 'id' => 'gvid-lone000001', 'label' => 'Lone', 'value' => '7px', 'order' => '3', 'status' => 'active', 'variableType' => 'numbers' ) ),
	) );
	$GLOBALS['T_options']['et:presets'] = array( 'module' => array( 'divi/button' => array( 'default' => 'btn0000001', 'items' => array( 'btn0000001' => array( 'type' => 'module', 'id' => 'btn0000001', 'name' => 'Primary', 'moduleName' => 'divi/button', 'attrs' => array( 'module' => array( 'decoration' => array( 'border' => array( 'radius' => '8px' ) ) ) ) ) ) ) ), 'group' => array() );
}
$ds_cases = array(
	'divi-ds-color-set'        => array( 'colors', array( 'label' => 'Brand', 'color' => '#ff6600', 'id' => 'gcid-brand0001' ), 'd5b_ds_color_upsert' ),
	'divi-ds-variable-set'     => array( 'variables', array( 'type' => 'numbers', 'label' => 'Gap', 'value' => '24px', 'id' => 'gvid-gap0000001' ), 'd5b_ds_variable_upsert' ),
	'divi-ds-variable-archive' => array( 'variables', array( 'id' => 'gvid-lone000001' ), 'd5b_ds_variable_archive' ),
	'divi-ds-preset-set'       => array( 'presets', array( 'moduleName' => 'divi/button', 'name' => 'Primary', 'id' => 'btn0000001', 'attrs' => array( 'module' => array( 'decoration' => array( 'border' => array( 'radius' => '12px' ) ) ) ) ), 'd5b_ds_preset_upsert' ),
);
foreach ( $ds_cases as $x => list( $store, $body, $fn ) ) {
	site(); ds_seed();
	$prev  = d5b_ds_read( $store );
	$r     = call( $x, $body );
	$after = d5b_ds_read( $store );
	$h     = hlast();
	$ast   = alast()['status'] ?? null;
	$bk0   = get_option( 'd5b_ds_backups' )[ $store ][0]['value'] ?? null;
	site(); ds_seed();
	$direct = call_user_func( $fn, $body, false );
	$dafter = d5b_ds_read( $store );
	check( "$x calls $fn with the same body and returns its result: the answer and the stored store equal a direct call (times aside)", is_array( $r ) && ! is_wp_error( $direct ) && same( untimed( $r ), untimed( $direct ) ) && same( untimed( $after ), untimed( $dafter ) ) && $after !== $prev, array( $r, $direct ) );
	check( "$x: one history row, object divi_$store, before = the whole store as it was, after = the function's result", $h && 'divi5-builder/' . $x === $h['ability'] && 'divi_' . $store === $h['object_type'] && same( $prev, $h['before'] ) && same( untimed( $r ), untimed( $h['after'] ) ) && ( 'divi-ds-variable-archive' === $x ? 'trash' : 'update' ) === $h['action'], $h );
	check( "$x: audited ok, the store as it was kept as backup 0 (the route's own backup)", 'ok' === $ast && same( $prev, $bk0 ) );
}
site(); ds_seed();
$v = vars_store();
check( 'archive on Divi 5.15+: the variable stays in the store with status archived (Divi\'s reversible delete)', 'archived' === ( call( 'divi-ds-variable-archive', array( 'id' => 'gvid-lone000001' ) ) ? vars_store()['numbers']['gvid-lone000001']['status'] : '' ) && isset( $v['numbers']['gvid-lone000001'] ) );
$n_h = count( hrows() );
$again = call( 'divi-ds-variable-archive', array( 'id' => 'gvid-lone000001' ) );
check( 'archiving it again changes nothing and writes no history row (action unchanged)', is_array( $again ) && 'unchanged' === $again['action'] && $n_h === count( hrows() ) );
$in_use = call( 'divi-ds-variable-archive', array( 'id' => 'gvid-gap0000001' ) );
check( 'the archive guard applies: a variable another active one uses is refused (variable_in_use, 409), nothing written, no history row', 'variable_in_use' === code( $in_use ) && 409 === status( $in_use ) && 'active' === vars_store()['numbers']['gvid-gap0000001']['status'] && $n_h === count( hrows() ) );
$bad_ref = call( 'divi-ds-variable-set', array( 'type' => 'numbers', 'label' => 'Loop', 'value' => 'var(--gvid-nosuch00001)' ) );
check( 'the nested-variable checks apply: a reference to a missing variable is refused (bad_references), nothing written', 'bad_references' === code( $bad_ref ) && 3 === count( vars_store()['numbers'] ) );
site(); ds_seed();
$c0 = colors(); $b0 = get_option( 'd5b_ds_backups', array() );
$dr = call( 'divi-ds-color-set', array( 'label' => 'Accent', 'color' => '#00ff00', 'dry_run' => true ) );
$dr2 = handler( 'divi-ds-color-set', array( 'label' => 'Accent', 'color' => '#00ff00', 'dryRun' => 1 ) );
$dr3 = call( 'divi-ds-preset-set', array( 'moduleName' => 'divi/button', 'name' => 'X', 'attrs' => array( 'a' => 1 ), 'dry_run' => false ) );
check( 'dry_run is not accepted as input: dry_run (true or false) and dryRun are refused (invalid_input), nothing written, no backup, no history row', 'invalid_input' === code( $dr ) && 'invalid_input' === code( $dr2 ) && 'invalid_input' === code( $dr3 ) && same( $c0, colors() ) && $b0 === get_option( 'd5b_ds_backups', array() ) && 0 === count( hrows() ) );
check( 'input types are checked: a label that is a number, attrs that is a list, a status outside the enum, setDefault "maybe" are refused', 'invalid_input' === code( handler( 'divi-ds-color-set', array( 'label' => 5, 'color' => '#000000' ) ) ) && 'invalid_input' === code( handler( 'divi-ds-preset-set', array( 'moduleName' => 'divi/button', 'name' => 'X', 'attrs' => array( 1, 2 ) ) ) ) && 'invalid_input' === code( handler( 'divi-ds-color-set', array( 'label' => 'A', 'color' => '#000000', 'status' => 'gone' ) ) ) && 'invalid_input' === code( handler( 'divi-ds-preset-set', array( 'moduleName' => 'divi/button', 'name' => 'X', 'attrs' => array( 'a' => 1 ), 'setDefault' => 'maybe' ) ) ) );
$mr = call( 'divi-ds-variable-set', array( 'type' => 'numbers', 'label' => 'Gap', 'id' => 'gvid-gap0000001', 'value' => '[redacted]' ) );
$mr2 = call( 'divi-ds-preset-set', array( 'moduleName' => 'divi/button', 'name' => 'Primary', 'attrs' => array( 'module' => array( 'note' => 'key [redacted]' ) ) ) );
check( 'masked_read: a design-system body holding [redacted] (where the store does not) is refused, nothing written, no history row', 'masked_read' === code( $mr ) && 'masked_read' === code( $mr2 ) && '20px' === vars_store()['numbers']['gvid-gap0000001']['value'] && 0 === count( hrows() ) );
$bc = call( 'divi-ds-color-set', array( 'label' => 'Bad', 'color' => 'red' ) );
check( 'the function\'s own refusals come through as they are (bad_color, 400), with no history row', 'bad_color' === code( $bc ) && 400 === status( $bc ) && 0 === count( hrows() ) && same( $c0, colors() ) );
$nv = call( 'divi-ds-variable-set', array( 'type' => 'strings', 'label' => 'Tagline', 'value' => 'Fresh daily' ) );
check( 'a new variable: action created, the history action create, before = the store without it', is_array( $nv ) && 'created' === $nv['action'] && 'create' === hlast()['action'] && ! isset( hlast()['before']['strings'] ) );
$big = call( 'divi-ds-preset-set', array( 'moduleName' => 'divi/text', 'name' => 'Big', 'attrs' => array( 'module' => array( 'decoration' => array( 'note' => str_repeat( 'x', 3000 ) ) ) ) ) );
$args = json_decode( alast()['args'], true );
check( 'audit args are capped at 1 KB per value: the 3 KB attrs are kept as their size, the name as it is', is_array( $big ) && 'ok' === alast()['status'] && array( 'capped' => true, 'bytes' => strlen( json_encode( array( 'module' => array( 'decoration' => array( 'note' => str_repeat( 'x', 3000 ) ) ) ) ) ) ) === ( $args['attrs'] ?? null ) && 'Big' === ( $args['name'] ?? null ), $args );
site( without( ADMIN, 'edit_theme_options' ) ); ds_seed();
check( 'the design system needs manage_options and edit_theme_options (d5b_ds_can, as the routes): without edit_theme_options every ds ability is refused, the handlers too', 'FORBIDDEN' === call( 'divi-ds-color-set', array( 'label' => 'A', 'color' => '#000000' ) ) && 'forbidden' === code( handler( 'divi-ds-color-set', array( 'label' => 'A', 'color' => '#000000' ) ) ) && 'FORBIDDEN' === call( 'divi-ds-backups-list' ) && 'FORBIDDEN' === call( 'divi-ds-restore', array( 'store' => 'colors', 'time' => 'x' ) ) && 'forbidden' === code( handler( 'divi-ds-restore', array( 'store' => 'colors', 'time' => 'x' ) ) ) );
site( without( ADMIN, 'manage_options' ) ); ds_seed();
check( 'without manage_options too', 'FORBIDDEN' === call( 'divi-ds-variable-archive', array( 'id' => 'gvid-lone000001' ) ) && 'FORBIDDEN' === call( 'divi-ds-preset-set', array( 'moduleName' => 'divi/button', 'name' => 'X', 'attrs' => array( 'a' => 1 ) ) ) );

// =====================================================================================================
echo "divi-ds-backups-list / divi-ds-restore\n";
function seed_backups() {
	$v1 = array( 'gcid-old0000001' => array( 'id' => 'gcid-old0000001', 'label' => 'Old one', 'color' => '#111111', 'status' => 'active' ) );
	$v2 = array( 'gcid-old0000002' => array( 'id' => 'gcid-old0000002', 'label' => 'Old two', 'color' => '#222222', 'status' => 'active' ) );
	update_option( 'd5b_ds_backups', array(
		'colors'  => array( array( 'time' => '2026-10-29T09:00:02+00:00', 'user' => 1, 'value' => $v2 ), array( 'time' => '2026-10-29T09:00:01+00:00', 'user' => 1, 'value' => $v1, 'pinned' => true ) ),
		'presets' => array( array( 'time' => '2026-10-28T08:00:00+00:00', 'user' => 1, 'value' => array( 'module' => array() ) ) ),
	) );
	return array( $v1, $v2 );
}
site(); ds_seed(); list( $v1, $v2 ) = seed_backups();
$l = call( 'divi-ds-backups-list' );
check( 'backups-list returns every backup with store, time (the id), first-of-day flag and size, and no index', is_array( $l ) && 3 === count( $l['backups'] ) && '2026-10-28T08:00:00+00:00' === $l['backups'][0]['time'] && 'presets' === $l['backups'][0]['store'] && '2026-10-29T09:00:01+00:00' === $l['backups'][2]['time'] && true === $l['backups'][2]['firstOfDay'] && false === $l['backups'][1]['firstOfDay'] && ! isset( $l['backups'][0]['index'] ) && strlen( json_encode( $v1 ) ) === $l['backups'][2]['bytes'], $l );
$lc = call( 'divi-ds-backups-list', array( 'store' => 'colors' ) );
check( 'backups-list store filter; a bad store is refused', 2 === count( $lc['backups'] ) && array( 'colors' ) === array_values( array_unique( array_column( $lc['backups'], 'store' ) ) ) && 'invalid_input' === code( handler( 'divi-ds-backups-list', array( 'store' => 'pages' ) ) ) );
$prev = colors();
$r = call( 'divi-ds-restore', array( 'store' => 'colors', 'time' => '2026-10-29T09:00:01+00:00' ) );
$h = hlast();
check( 'restore by time restores that backup (the second one in the list, not index 0)', is_array( $r ) && '2026-10-29T09:00:01+00:00' === $r['restoredFrom'] && same( $v1, colors() ), array( $r, colors() ) );
check( 'restore: history row action restore, object divi_colors, before = the store as it was', $h && 'divi5-builder/divi-ds-restore' === $h['ability'] && 'restore' === $h['action'] && 'divi_colors' === $h['object_type'] && same( $prev, $h['before'] ), $h );
$n_h = count( hrows() ); $c_now = colors();
$nf = call( 'divi-ds-restore', array( 'store' => 'colors', 'time' => '2026-10-29T09:00:03+00:00' ) );
$nf2 = call( 'divi-ds-restore', array( 'store' => 'presets', 'time' => '2026-10-29T09:00:02+00:00' ) );
check( 'a time no backup of that store has answers backup_not_found (404), nothing restored, no history row', 'backup_not_found' === code( $nf ) && 404 === status( $nf ) && 'backup_not_found' === code( $nf2 ) && same( $c_now, colors() ) && $n_h === count( hrows() ) );
site(); ds_seed(); list( $v1, $v2 ) = seed_backups();
call( 'divi-ds-color-set', array( 'label' => 'Accent', 'color' => '#00ff00' ) ); // a write between list and restore: a new backup 0, the others move down
call( 'divi-ds-color-set', array( 'label' => 'Accent 2', 'color' => '#00ff01' ) );
$shift = get_option( 'd5b_ds_backups' )['colors'];
$r = call( 'divi-ds-restore', array( 'store' => 'colors', 'time' => '2026-10-29T09:00:02+00:00' ) );
check( 'after writes the same time still resolves: the backup moved from index 0 to ' . array_search( '2026-10-29T09:00:02+00:00', array_column( $shift, 'time' ), true ) . ', and that one (not what is at index 0 now) is restored', is_array( $r ) && '2026-10-29T09:00:02+00:00' === $r['restoredFrom'] && same( $v2, colors() ) && '2026-10-29T09:00:02+00:00' !== $shift[0]['time'], array( $r, colors() ) );
site(); ds_seed();
update_option( 'd5b_ds_backups', array( 'colors' => array( array( 'time' => '2026-10-29T09:00:05+00:00', 'value' => array( 'a' => 1 ) ), array( 'time' => '2026-10-29T09:00:05+00:00', 'value' => array( 'b' => 2 ) ) ) ) );
$c_now = colors();
$am = call( 'divi-ds-restore', array( 'store' => 'colors', 'time' => '2026-10-29T09:00:05+00:00' ) );
check( 'a time two backups share (two writes in one second) is refused (ambiguous, 409), nothing restored', 'ambiguous' === code( $am ) && 409 === status( $am ) && same( $c_now, colors() ) );
check( 'restore input: store and time required, store from the enum, unknown input refused', 'invalid_input' === code( handler( 'divi-ds-restore', array( 'store' => 'colors' ) ) ) && 'invalid_input' === code( handler( 'divi-ds-restore', array( 'time' => 'x' ) ) ) && 'invalid_input' === code( handler( 'divi-ds-restore', array( 'store' => 'x', 'time' => 'x' ) ) ) && 'invalid_input' === code( handler( 'divi-ds-restore', array( 'store' => 'colors', 'time' => 'x', 'index' => 0 ) ) ) );

// =====================================================================================================
echo "divi-tb-write / divi-tb-restore\n";
$OLD = "<!-- wp:divi/section -->\n<!-- wp:divi/text {\"c\":\"Mon to Fri\"} -->Open<!-- /wp:divi/text -->\n<!-- /wp:divi/section -->";
$NEW = "<!-- wp:divi/section -->\n<!-- wp:divi/text {\"c\":\"Mon to Sat\"} -->Open<!-- /wp:divi/text -->\n<!-- /wp:divi/section -->";
site();
$ft = t_add_post( array( 'post_type' => 'et_footer_layout', 'post_title' => 'Footer', 'post_content' => $OLD ) );
t_add_meta( $ft, '_d5b_prev_content', 'EARLIER' ); t_add_meta( $ft, '_d5b_prev_saved_at', '2026-10-01T00:00:00+00:00' );
$pg = t_add_post( array( 'post_type' => 'page', 'post_content' => 'PAGE' ) );
$r  = call( 'divi-tb-write', array( 'id' => $ft, 'content' => $NEW, 'expect_hash' => md5( $OLD ) ) );
$h  = hlast();
check( 'tb-write writes the layout and returns the old and new hash', is_array( $r ) && md5( $OLD ) === $r['old_hash'] && md5( $NEW ) === $r['new_hash'] && 'divi5' === $r['format'] && $NEW === post( $ft )->post_content, $r );
check( 'the safety copy is kept as the route keeps it: _d5b_prev_content = the old content', array( $OLD ) === rows_of( $ft, '_d5b_prev_content' ) );
check( 'history: before = the whole previous content and the safety copy it replaced; after = the result; action update', $h && 'divi5-builder/divi-tb-write' === $h['ability'] && 'et_footer_layout' === $h['object_type'] && $ft === $h['object_id'] && $OLD === $h['before']['content'] && array( 'EARLIER' ) === $h['before']['safety_copy']['_d5b_prev_content'] && array( '2026-10-01T00:00:00+00:00' ) === $h['before']['safety_copy']['_d5b_prev_saved_at'] && md5( $NEW ) === $h['after']['new_hash'], $h );
$a = json_decode( alast()['args'], true );
check( 'the audit row keeps the content as its size and md5', 'ok' === alast()['status'] && array( 'bytes' => strlen( $NEW ), 'md5' => md5( $NEW ) ) === $a['content'], $a );
$n_h = count( hrows() );
$st = call( 'divi-tb-write', array( 'id' => $ft, 'content' => $OLD, 'expect_hash' => md5( $OLD ) ) );
check( 'a stale hash is refused (stale, 409, with the current hash), nothing written, no history row', 'stale' === code( $st ) && 409 === status( $st ) && md5( $NEW ) === $st->get_error_data()['current_hash'] && $NEW === post( $ft )->post_content && $n_h === count( hrows() ) );
check( 'no hash is refused (expect_hash_required, the route\'s refusal)', 'expect_hash_required' === code( call( 'divi-tb-write', array( 'id' => $ft, 'content' => $OLD ) ) ) && $NEW === post( $ft )->post_content );
check( 'refused on a page id (not_a_layout, 404), and on a template', 'not_a_layout' === code( call( 'divi-tb-write', array( 'id' => $pg, 'content' => $OLD, 'expect_hash' => md5( 'PAGE' ) ) ) ) && 404 === status( handler( 'divi-tb-write', array( 'id' => $pg, 'content' => $OLD, 'expect_hash' => md5( 'PAGE' ) ) ) ) && 'PAGE' === post( $pg )->post_content && 'not_a_layout' === code( handler( 'divi-tb-write', array( 'id' => t_add_post( array( 'post_type' => 'et_template', 'post_content' => '' ) ), 'content' => $OLD, 'expect_hash' => md5( '' ) ) ) ) );
$mk = call( 'divi-tb-write', array( 'id' => $ft, 'content' => str_replace( 'Open', '[redacted]', $NEW ), 'expect_hash' => md5( $NEW ) ) );
check( 'masked_read: content holding [redacted] where the layout does not is refused, nothing written', 'masked_read' === code( $mk ) && $NEW === post( $ft )->post_content );
check( 'content over 1 MB is refused (invalid_input); unknown input and dry_run are refused', 'invalid_input' === code( handler( 'divi-tb-write', array( 'id' => $ft, 'content' => str_repeat( 'x', 1048577 ), 'expect_hash' => md5( $NEW ) ) ) ) && 'invalid_input' === code( handler( 'divi-tb-write', array( 'id' => $ft, 'content' => $OLD, 'expect_hash' => md5( $NEW ), 'dry_run' => true ) ) ) && $NEW === post( $ft )->post_content );
$rr = call( 'divi-tb-restore', array( 'id' => $ft ) );
$h  = hlast();
check( 'tb-restore swaps the layout with its safety copy and returns restored', is_array( $rr ) && true === $rr['restored'] && $OLD === post( $ft )->post_content && array( $NEW ) === rows_of( $ft, '_d5b_prev_content' ), $rr );
check( 'tb-restore history: action restore, before = the content it replaced (whole) and the safety copy', $h && 'divi5-builder/divi-tb-restore' === $h['ability'] && 'restore' === $h['action'] && $NEW === $h['before']['content'] && array( $OLD ) === $h['before']['safety_copy']['_d5b_prev_content'], $h );
$n_h = count( hrows() );
// Damage only what is being written ("Sat" is in NEW, not in OLD), so the put-back itself is saved unharmed.
$GLOBALS['T_save_content'] = function ( $c ) { return str_replace( 'Sat', 'Sa', $c ); };
$rt = call( 'divi-tb-write', array( 'id' => $ft, 'content' => $NEW, 'expect_hash' => md5( $OLD ) ) );
$GLOBALS['T_save_content'] = null;
check( 'the auto-revert on a failed round trip applies (roundtrip_failed, 500): the content is put back, no history row', 'roundtrip_failed' === code( $rt ) && 500 === status( $rt ) && $OLD === post( $ft )->post_content && $n_h === count( hrows() ) );
$fresh = t_add_post( array( 'post_type' => 'et_header_layout', 'post_content' => $OLD ) );
check( 'tb-restore of a layout with no safety copy: no_backup (404), no history row', 'no_backup' === code( call( 'divi-tb-restore', array( 'id' => $fresh ) ) ) && 404 === status( handler( 'divi-tb-restore', array( 'id' => $fresh ) ) ) && 'restore' === hlast()['action'] && 2 === count( hrows() ) );
site( without( ADMIN, 'unfiltered_html' ) );
$ft = t_add_post( array( 'post_type' => 'et_footer_layout', 'post_content' => $OLD ) );
check( 'tb-write needs unfiltered_html: refused at the gate (FORBIDDEN) and by the handler (needs_unfiltered_html), nothing written; tb-restore too', 'FORBIDDEN' === call( 'divi-tb-write', array( 'id' => $ft, 'content' => $NEW, 'expect_hash' => md5( $OLD ) ) ) && 'needs_unfiltered_html' === code( handler( 'divi-tb-write', array( 'id' => $ft, 'content' => $NEW, 'expect_hash' => md5( $OLD ) ) ) ) && $OLD === post( $ft )->post_content && 'FORBIDDEN' === call( 'divi-tb-restore', array( 'id' => $ft ) ) );
site( without( ADMIN, 'edit_theme_options' ) );
$ft = t_add_post( array( 'post_type' => 'et_footer_layout', 'post_content' => $OLD ) );
check( 'tb-write needs edit_theme_options: refused at the gate and by the handler (forbidden), nothing written', 'FORBIDDEN' === call( 'divi-tb-write', array( 'id' => $ft, 'content' => $NEW, 'expect_hash' => md5( $OLD ) ) ) && 'forbidden' === code( handler( 'divi-tb-write', array( 'id' => $ft, 'content' => $NEW, 'expect_hash' => md5( $OLD ) ) ) ) && $OLD === post( $ft )->post_content );
site();
$ft = t_add_post( array( 'post_type' => 'et_footer_layout', 'post_content' => $OLD ) );
d5b_abilities_set_category( 'divi', false, false, 'tester' );
check( 'with the divi category off the layout writes are refused', 'FORBIDDEN' === call( 'divi-tb-write', array( 'id' => $ft, 'content' => $NEW, 'expect_hash' => md5( $OLD ) ) ) && 'FORBIDDEN' === call( 'divi-tb-restore', array( 'id' => $ft ) ) && $OLD === post( $ft )->post_content );

// =====================================================================================================
// The page the two content edits work on. Text values are stored the way Divi stores them (d5b_blocks_json_string, which
// the blocks test proves byte-identical to the Visual Builder fixtures).
function J( string $s ): string { return d5b_blocks_json_string( $s ); }
function txt( string $html, string $extra = '' ): string { return '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":' . J( $html ) . '}}}' . $extra . '} /-->'; }
$TRICKY = "<p class=\"hours\">Q \"quote\" & &amp; <br> caf\u{00e9} \u{2014} \u{1F600} \$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-x\"}})\$ Our hours</p>";
$LONG   = '<p>' . str_repeat( 'lorem ipsum ', 20 ) . 'MIDDLE' . str_repeat( ' dolor sit', 20 ) . '</p>';
$PG = implode( "\n", array(
	'<!-- wp:divi/placeholder -->',
	'<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Hero"}}}}} -->',
	'<!-- wp:divi/row -->',
	'<!-- wp:divi/column -->',
	'<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"Welcome"}}},"hoursNote":{"x":"hours"},"module":{"advanced":{"htmlAttributes":{"desktop":{"value":{"class":"hours-box","id":"hours-id"}}}}}} /-->',
	txt( $TRICKY ),
	'<!-- /wp:divi/column -->',
	'<!-- /wp:divi/row -->',
	'<!-- /wp:divi/section -->',
	'<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Times"}}}}} -->',
	'<!-- wp:divi/row -->',
	'<!-- wp:divi/column -->',
	txt( '<p>Open <strong>Mon to Fri</strong> 9 to 5</p>' ),
	'<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Book now","linkUrl":"https://x.test/book-now"}}}}} /-->',
	txt( '<p>Fish &amp; Chips</p>' ),
	'<!-- /wp:divi/column -->',
	'<!-- /wp:divi/row -->',
	'<!-- /wp:divi/section -->',
	'<!-- wp:divi/section -->',
	'<!-- wp:divi/row -->',
	'<!-- wp:divi/column -->',
	txt( '<p>Call us today</p>' ),
	txt( '<p>Call us later</p>' ),
	txt( '<p>Tom & Jerry</p>' ),
	txt( $LONG ),
	'<!-- /wp:divi/column -->',
	'<!-- /wp:divi/row -->',
	'<!-- /wp:divi/section -->',
	'<!-- /wp:divi/placeholder -->',
) );
$SEC = "<!-- wp:divi/section -->\n<!-- wp:divi/row -->\n<!-- wp:divi/column -->\n" . txt( '<p>New section</p>' ) . "\n<!-- /wp:divi/column -->\n<!-- /wp:divi/row -->\n<!-- /wp:divi/section -->";
$MODX = txt( '<p>New module</p>' );
function field_of( string $src, string $addr, string $f = 'content.innerContent.desktop.value' ) { $r = d5b_blocks_parse( $src ); if ( is_wp_error( $r ) ) { return $r; } $b = d5b_blocks_resolve( $r, $addr ); return is_wp_error( $b ) ? $b : ( d5b_blocks_text_fields( $b )[ $f ] ?? null ); }
function blk( string $src, string $addr ) { $r = d5b_blocks_parse( $src ); return d5b_blocks_resolve( $r, $addr ); }
/** Outside the block [start, end) of the old page, the new page is byte-identical: the prefix, and the suffix after the splice. */
function outside_same( string $old, string $new, int $start, int $end ): bool { $tail = strlen( $old ) - $end; return substr( $new, 0, $start ) === substr( $old, 0, $start ) && substr( $new, strlen( $new ) - $tail ) === substr( $old, $end ) && strlen( $new ) >= $start + $tail; }
function home( string $content = null ) { global $PG; return t_add_post( array( 'post_type' => 'page', 'post_title' => 'Home', 'post_content' => $content ?? $PG ) ); }
check( 'the test page parses, with the blocks where the tests expect them', ! is_wp_error( d5b_blocks_parse( $PG ) ) && 'Welcome' === field_of( $PG, '1.1.1.1', 'title.innerContent.desktop.value' ) && $TRICKY === field_of( $PG, '1.1.1.2' ) && 'divi/button' === blk( $PG, '2.1.1.2' )['name'] && 4 === count( d5b_blocks_divi_children( blk( $PG, '3.1.1' ) ) ) );

// =====================================================================================================
echo "divi-section-edit\n";
site();
$pg = home();
$t2 = blk( $PG, '2' );
$r  = call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'replace', 'at' => '2', 'with' => $SEC ) );
$c  = post( $pg )->post_content;
$h  = hlast();
check( 'replace: section 2 is the new section, everything outside it byte-identical', is_array( $r ) && outside_same( $PG, $c, $t2['start'], $t2['end'] ) && substr( $PG, 0, $t2['start'] ) . $SEC . substr( $PG, $t2['end'] ) === $c, is_array( $r ) ? $c : $r );
check( 'replace: the response holds the new hash, the address and the tree of the page as it is now', is_array( $r ) && md5( $c ) === $r['hash'] && '2' === $r['address'] && 'replace' === $r['op'] && d5b_blocks_tree( $c ) === $r['tree'] && 'Hero' === $r['tree'][0]['label'] && $c === $r['content'], $r );
check( 'history: action update, the page, summary "Edited section 2 of page N \'Home\'", before.content = the previous page (first update: no revision holds it)', $h && 'divi5-builder/divi-section-edit' === $h['ability'] && 'page' === $h['object_type'] && $pg === $h['object_id'] && 'update' === $h['action'] && "Edited section 2 of page $pg 'Home'" === $h['summary'] && $PG === ( $h['before']['content'] ?? null ) && 0 === (int) $h['revision_id'] && 'ok' === alast()['status'], $h );
$r2 = call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => $r['hash'], 'op' => 'remove', 'at' => '3' ) );
$h  = hlast();
$t3 = blk( $c, '3' );
$c2 = post( $pg )->post_content;
check( 'remove: section 3 and its line break are gone, everything else byte-identical; address = where it was; the tree has two sections', is_array( $r2 ) && substr( $c, 0, $t3['start'] ) . substr( $c, $t3['end'] + 1 ) === $c2 && '3' === $r2['address'] && 2 === count( array_filter( $r2['tree'], function ( $e ) { return false === strpos( $e['address'], '.' ); } ) ), $r2 );
check( 'second edit: a revision holds the previous content byte for byte, so the history points at it (revision_id) and leaves before.content out; summary "Removed section 3 from"', $h && $h['revision_id'] > 0 && $c === post( $h['revision_id'] )->post_content && ! isset( $h['before']['content'] ) && 0 === strpos( $h['summary'], 'Removed section 3 from page' ), $h );
site();
$pg = home(); $t2 = blk( $PG, '2' );
$r = call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'before', 'at' => 'label:Times', 'with' => $SEC ) );
$c = post( $pg )->post_content;
check( 'before (by label): the new section sits at 2, the old section 2 moved to 3, outside byte-identical', is_array( $r ) && substr( $PG, 0, $t2['start'] ) . $SEC . "\n" . substr( $PG, $t2['start'] ) === $c && '2' === $r['address'] && 'Times' === ( blk( $c, '3' )['attrs']['module']['meta']['adminLabel']['desktop']['value'] ?? '' ), $r );
site();
$pg = home(); $t1 = blk( $PG, '1' );
$r = call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'after', 'at' => '1', 'with' => $SEC ) );
$c = post( $pg )->post_content;
check( 'after: the new section is at 2, outside byte-identical', is_array( $r ) && substr( $PG, 0, $t1['end'] ) . "\n" . $SEC . substr( $PG, $t1['end'] ) === $c && '2' === $r['address'] && 'New section' === wp_strip_all_tags( field_of( $c, '2.1.1.1' ) ), $r );
site();
$pg = home(); $col = blk( $PG, '2.1.1' );
$r = call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'append', 'at' => '2.1.1', 'with' => $MODX ) );
$c = post( $pg )->post_content;
check( 'append: the module is the column\'s last child at 2.1.1.4, outside byte-identical', is_array( $r ) && substr( $PG, 0, $col['innerEnd'] ) . $MODX . "\n" . substr( $PG, $col['innerEnd'] ) === $c && '2.1.1.4' === $r['address'] && '<p>New module</p>' === field_of( $c, '2.1.1.4' ), $r );
site();
$pg = home(); $n_h = count( hrows() );
$st  = call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( 'other' ), 'op' => 'remove', 'at' => '1' ) );
$km  = call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'replace', 'at' => '2', 'with' => $MODX ) );
$km2 = call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'append', 'at' => '2.1.1', 'with' => $SEC ) );
$np  = call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'replace', 'at' => '2', 'with' => '<!-- wp:divi/section --><!-- wp:divi/row -->' ) );
$nd  = call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'replace', 'at' => '2', 'with' => '<p>no blocks</p>' ) );
check( 'a wrong hash answers stale (data.hash = the current hash) and writes nothing', 'stale' === code( $st ) && md5( $PG ) === $st->get_error_data()['hash'] && $PG === post( $pg )->post_content );
check( 'kind check: a module in place of a section, a section inside a column are refused (invalid_input, "cannot go"), nothing written', 'invalid_input' === code( $km ) && false !== strpos( $km->get_error_message(), 'cannot go in place of a section' ) && 'invalid_input' === code( $km2 ) && false !== strpos( $km2->get_error_message(), 'cannot go inside' ) && $PG === post( $pg )->post_content, array( $km, $km2 ) );
check( 'a with that does not parse, or holds no Divi block, is refused (invalid_input), nothing written', 'invalid_input' === code( $np ) && false !== strpos( $np->get_error_message(), 'with does not parse' ) && 'invalid_input' === code( $nd ) && $PG === post( $pg )->post_content );
check( 'with is required unless remove, and refused with remove; op from the enum; at required', 'invalid_input' === code( handler( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'replace', 'at' => '2' ) ) ) && 'invalid_input' === code( handler( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'remove', 'at' => '2', 'with' => $SEC ) ) ) && 'invalid_input' === code( handler( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'move', 'at' => '2', 'with' => $SEC ) ) ) && 'invalid_input' === code( handler( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'remove' ) ) ) && 'invalid_input' === code( handler( 'divi-section-edit', array( 'post_id' => $pg, 'op' => 'remove', 'at' => '2' ) ) ) && $PG === post( $pg )->post_content );
check( 'an address with nothing there is not_found; a label two blocks share is ambiguous with the candidates', 'not_found' === code( call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'remove', 'at' => '9' ) ) ) && ( function () { $dup = str_replace( '"Times"', '"Hero"', $GLOBALS['PG'] ); $id = home( $dup ); $e = call( 'divi-section-edit', array( 'post_id' => $id, 'expect_hash' => md5( $dup ), 'op' => 'remove', 'at' => 'label:Hero' ) ); return 'ambiguous' === code( $e ) && array( '1', '2' ) === $e->get_error_data()['candidates'] && $dup === post( $id )->post_content; } )() && $n_h === count( hrows() ) );
$mk = call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'replace', 'at' => '2', 'with' => str_replace( 'New section', '[redacted]', $SEC ) ) );
check( 'masked_read: with holding [redacted] (the page does not) is refused, nothing written, no history row', 'masked_read' === code( $mk ) && $PG === post( $pg )->post_content && $n_h === count( hrows() ) );
$bad = str_replace( '<!-- /wp:divi/placeholder -->', '', $PG ); $bp = home( $bad );
check( 'a stored page that does not parse is refused (invalid_input), not edited', 'invalid_input' === code( call( 'divi-section-edit', array( 'post_id' => $bp, 'expect_hash' => md5( $bad ), 'op' => 'remove', 'at' => '1' ) ) ) && $bad === post( $bp )->post_content );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
check( 'pages off: a page is refused (gate FORBIDDEN, handler not_allowed), nothing written', 'FORBIDDEN' === call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'remove', 'at' => '1' ) ) && 'not_allowed' === code( handler( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'remove', 'at' => '1' ) ) ) && $PG === post( $pg )->post_content );
site( without( ADMIN, 'unfiltered_html' ) );
$pg = home();
check( 'without unfiltered_html: refused (gate FORBIDDEN, handler needs_unfiltered_html), nothing written', 'FORBIDDEN' === call( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'remove', 'at' => '1' ) ) && 'needs_unfiltered_html' === code( handler( 'divi-section-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'op' => 'remove', 'at' => '1' ) ) ) && $PG === post( $pg )->post_content );
site();
$ft = t_add_post( array( 'post_type' => 'et_footer_layout', 'post_title' => 'Footer', 'post_content' => $PG ) );
$tpl = t_add_post( array( 'post_type' => 'et_template', 'post_content' => $PG ) );
$r  = call( 'divi-section-edit', array( 'post_id' => $ft, 'expect_hash' => md5( $PG ), 'op' => 'remove', 'at' => '3' ) );
$h  = hlast();
check( 'on a Theme Builder layout it goes through d5b_tb_write: the safety copy holds the old content, the response the new hash', is_array( $r ) && array( $PG ) === rows_of( $ft, '_d5b_prev_content' ) && md5( post( $ft )->post_content ) === $r['hash'] && $PG !== post( $ft )->post_content && 'et_footer_layout' === $r['type'], $r );
check( 'layout history: before = the whole previous content and safety copy (as divi-tb-write), after = d5b_tb_write\'s answer, summary names the footer layout', $h && 'et_footer_layout' === $h['object_type'] && $PG === $h['before']['content'] && array() === $h['before']['safety_copy']['_d5b_prev_content'] && md5( $PG ) === $h['after']['old_hash'] && "Removed section 3 from Theme Builder footer layout $ft 'Footer'" === $h['summary'], $h );
check( 'a template is refused (not_allowed)', 'FORBIDDEN' === call( 'divi-section-edit', array( 'post_id' => $tpl, 'expect_hash' => md5( $PG ), 'op' => 'remove', 'at' => '1' ) ) && 'not_allowed' === code( handler( 'divi-section-edit', array( 'post_id' => $tpl, 'expect_hash' => md5( $PG ), 'op' => 'remove', 'at' => '1' ) ) ) );

// =====================================================================================================
echo "divi-text-edit\n";
/** The page with exactly one JSON string changed: the stored field at $addr re-encoded with $html. */
function with_field( string $src, string $addr, string $f, string $html ): string { $r = d5b_blocks_parse( $src ); return d5b_blocks_set_text_field( $src, d5b_blocks_resolve( $r, $addr ), $f, $html ); }
site();
$pg = home();
$r  = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Welcome', 'new' => 'Welcome back' ) );
$c  = post( $pg )->post_content;
$h  = hlast();
check( 'one heading changed: only its title string differs, the hash is the new one, address, field and excerpt returned', is_array( $r ) && with_field( $PG, '1.1.1.1', 'title.innerContent.desktop.value', 'Welcome back' ) === $c && md5( $c ) === $r['hash'] && '1.1.1.1' === $r['address'] && 'title.innerContent.desktop.value' === $r['field'] && 'Welcome back' === $r['excerpt'], $r );
check( 'history: summary "Changed text in page N \'Home\' (1.1.1.1 title)", before.content = the previous page', $h && 'divi5-builder/divi-text-edit' === $h['ability'] && "Changed text in page $pg 'Home' (1.1.1.1 title)" === $h['summary'] && $PG === $h['before']['content'] && 'update' === $h['action'] && 'page' === $h['object_type'], $h );
$r  = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c ), 'old' => 'hours', 'new' => 'times' ) );
$c2 = post( $pg )->post_content;
$want = str_replace( 'Our hours', 'Our times', $TRICKY );
check( 'old in the text and in classes, an id, an attribute name and a value: only the text changes (class="hours", hours-box, hours-id, hoursNote untouched)', is_array( $r ) && with_field( $c, '1.1.1.2', 'content.innerContent.desktop.value', $want ) === $c2 && $want === field_of( $c2, '1.1.1.2' ) && false !== strpos( $c2, '"class":"hours-box","id":"hours-id"' ) && false !== strpos( $c2, '"hoursNote":{"x":"hours"}' ), $r );
check( 'quotes, ampersands, <br>, non-ASCII and $variable(...)$ survive: the field is the old HTML with only "hours" changed, stored with Divi\'s escapes', false !== strpos( $c2, J( $want ) ) && false !== strpos( $want, "\$variable({\"type\":\"content\"" ) && false !== strpos( $want, "& &amp; <br> caf\u{00e9} \u{2014} \u{1F600}" ) );
check( 'second edit: history points at the revision that holds the previous content (revision_id), no before.content', hlast()['revision_id'] > 0 && $c === post( hlast()['revision_id'] )->post_content && ! isset( hlast()['before']['content'] ) );
site();
$GLOBALS['T_revisions_keep'] = 1; // WP_POST_REVISIONS 1: the revision of an update pushes out the one before it
$pg = home();
$r1 = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Welcome', 'new' => 'Hello' ) );
$c1 = post( $pg )->post_content;
$r2 = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c1 ), 'old' => 'Hello', 'new' => 'Hi' ) );
$h  = hlast();
check( 'revisions kept 1: the revision that held the previous content is pruned by the update, so the history keeps before.content and no revision_id', is_array( $r1 ) && is_array( $r2 ) && 0 === (int) $h['revision_id'] && $c1 === ( $h['before']['content'] ?? null ), $h );
site();
$pg = home(); $n_h = count( hrows() );
$nf = array();
foreach ( array( 'hours-box', 'hours-id', 'book-now', 'linkUrl', 'hoursNote', 'class', 'strong', 'adminLabel' ) as $o ) { $e = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => $o, 'new' => 'X' ) ); $nf[ $o ] = code( $e ); }
check( 'old only in a CSS class, an id, an option value (link URL), an attribute name, a tag or tag attribute inside the HTML: not_found, nothing written', array_fill_keys( array_keys( $nf ), 'not_found' ) === $nf && $PG === post( $pg )->post_content && $n_h === count( hrows() ), $nf );
$r = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Fish & Chips', 'new' => 'Fish & Fries <today>' ) );
$c = post( $pg )->post_content;
check( 'the visible text is searched (entities decoded): "Fish & Chips" matches <p>Fish &amp; Chips</p>; new is written as text (& < > escaped) and reads back exactly', is_array( $r ) && '<p>Fish &amp; Fries &lt;today&gt;</p>' === field_of( $c, '2.1.1.3' ) && 'Fish & Fries <today>' === d5b_wd_visible( field_of( $c, '2.1.1.3' ) ) && 'Fish & Fries <today>' === $r['excerpt'], $r );
site();
$pg = home();
$x  = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Mon to Fri 9 to 5', 'new' => 'Mon to Sat 9 to 6' ) );
check( 'old spanning <strong>: crosses_markup with the field HTML, address and field; nothing written', 'crosses_markup' === code( $x ) && '<p>Open <strong>Mon to Fri</strong> 9 to 5</p>' === $x->get_error_data()['html'] && '2.1.1.1' === $x->get_error_data()['address'] && 'content.innerContent.desktop.value' === $x->get_error_data()['field'] && $PG === post( $pg )->post_content, $x );
$r = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => '<strong>Mon to Fri</strong> 9 to 5', 'new' => '<strong>Mon to Sat</strong> 9 to 6', 'html' => true ) );
$c = post( $pg )->post_content;
check( 'with html: true the same change succeeds against the stored HTML', is_array( $r ) && '<p>Open <strong>Mon to Sat</strong> 9 to 6</p>' === field_of( $c, '2.1.1.1' ) && with_field( $PG, '2.1.1.1', 'content.innerContent.desktop.value', '<p>Open <strong>Mon to Sat</strong> 9 to 6</p>' ) === $c && 'Open Mon to Sat 9 to 6' === $r['excerpt'], $r );
$r = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c ), 'old' => 'Mon to Sat', 'new' => 'Every day' ) );
check( 'a match inside one run of text is replaced in place and the tags around it stay', is_array( $r ) && '<p>Open <strong>Every day</strong> 9 to 6</p>' === field_of( post( $pg )->post_content, '2.1.1.1' ), $r );
site();
$pg = home(); $n_h = count( hrows() );
$z  = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'book NOW', 'new' => 'x' ) );
$z2 = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => "Fish  &\nchips", 'new' => 'x' ) );
$z3 = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Nowhere at all', 'new' => 'x' ) );
check( 'zero matches: not_found with near matches (other case; other spacing), as { address, field, text }; none when nothing is near', 'not_found' === code( $z ) && array( array( 'address' => '2.1.1.2', 'field' => 'button.innerContent.desktop.value.text', 'text' => 'Book now' ) ) === $z->get_error_data()['near_matches'] && 'not_found' === code( $z2 ) && array( array( 'address' => '2.1.1.3', 'field' => 'content.innerContent.desktop.value', 'text' => 'Fish & Chips' ) ) === $z2->get_error_data()['near_matches'] && array() === $z3->get_error_data()['near_matches'], array( $z, $z2 ) );
$am = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Call us', 'new' => 'Ring us' ) );
check( 'two matches: ambiguous with the address, field and count of each, nothing written', 'ambiguous' === code( $am ) && array( array( 'address' => '3.1.1.1', 'field' => 'content.innerContent.desktop.value', 'count' => 1 ), array( 'address' => '3.1.1.2', 'field' => 'content.innerContent.desktop.value', 'count' => 1 ) ) === $am->get_error_data()['matches'] && $PG === post( $pg )->post_content && $n_h === count( hrows() ), $am );
$am2 = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'or', 'new' => 'x', 'at' => '3.1.1.4' ) );
check( 'repeated matches in one field count (ambiguous, count > 1)', 'ambiguous' === code( $am2 ) && $am2->get_error_data()['matches'][0]['count'] > 1 );
$rm  = "<!-- wp:divi/section -->\n<!-- wp:divi/row -->\n<!-- wp:divi/column -->\n" . txt( '<p>Room 444</p>' ) . "\n<!-- /wp:divi/column -->\n<!-- /wp:divi/row -->\n<!-- /wp:divi/section -->";
$rmp = home( $rm );
$ov  = call( 'divi-text-edit', array( 'post_id' => $rmp, 'expect_hash' => md5( $rm ), 'old' => '44', 'new' => '45' ) );
check( 'overlapping matches count too: "44" in "444" is ambiguous (count 2), not replaced once', 'ambiguous' === code( $ov ) && 2 === $ov->get_error_data()['matches'][0]['count'] && $rm === post( $rmp )->post_content, $ov );
$r = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Call us', 'new' => 'Ring us', 'at' => '3.1.1.2' ) );
$c = post( $pg )->post_content;
check( 'repeating with at succeeds: only the block at 3.1.1.2 changes', is_array( $r ) && '3.1.1.2' === $r['address'] && '<p>Ring us later</p>' === field_of( $c, '3.1.1.2' ) && '<p>Call us today</p>' === field_of( $c, '3.1.1.1' ) && with_field( $PG, '3.1.1.2', 'content.innerContent.desktop.value', '<p>Ring us later</p>' ) === $c, $r );
$r = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c ), 'old' => 'Call us', 'new' => 'Ring us', 'at' => '3' ) );
check( 'at a section searches the blocks inside it', is_array( $r ) && '3.1.1.1' === $r['address'], $r );
$c = post( $pg )->post_content;
$r = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c ), 'old' => 'MIDDLE', 'new' => 'CENTRE' ) );
check( 'the excerpt is 120 characters of the new visible text, centred on the change', is_array( $r ) && 120 === d5b_rc_chars( $r['excerpt'] ) && abs( strpos( $r['excerpt'], 'CENTRE' ) - ( 60 - 3 ) ) <= 2 && false !== strpos( d5b_wd_visible( field_of( post( $pg )->post_content, '3.1.1.4' ) ), $r['excerpt'] ), $r );
$c = post( $pg )->post_content; $n_h = count( hrows() );
$tj = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c ), 'old' => ' Jerry', 'new' => 'lt;' ) );
check( 'the page is read back before it is written: new that would join a raw & into an entity ("Tom & Jerry" -> "Tom &lt;") reads as "Tom <", so it is refused, nothing written', 'crosses_markup' === code( $tj ) && $c === post( $pg )->post_content && $n_h === count( hrows() ), $tj );
check( 'a wrong hash: stale, nothing written', 'stale' === code( call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Welcome', 'new' => 'Hi' ) ) ) && $c === post( $pg )->post_content );
check( 'input: old empty or over 2000 characters, new over 2000, html not a boolean, unknown input are refused', 'invalid_input' === code( handler( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c ), 'old' => '', 'new' => 'x' ) ) ) && 'invalid_input' === code( handler( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c ), 'old' => str_repeat( "\u{00e9}", 2001 ), 'new' => 'x' ) ) ) && 'invalid_input' === code( handler( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c ), 'old' => 'Welcome', 'new' => str_repeat( 'x', 2001 ) ) ) ) && 'invalid_input' === code( handler( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c ), 'old' => 'Welcome', 'new' => 'x', 'html' => 'maybe' ) ) ) && 'invalid_input' === code( handler( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c ), 'old' => 'Welcome', 'new' => 'x', 'y' => 1 ) ) ) && 'invalid_input' === code( handler( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c ), 'old' => "\xff", 'new' => 'x' ) ) ) && $c === post( $pg )->post_content );
check( 'new may be empty (the text is removed)', is_array( call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $c ), 'old' => 'come', 'new' => '' ) ) ) && 'Wel' === field_of( post( $pg )->post_content, '1.1.1.1', 'title.innerContent.desktop.value' ) );
site();
$pg = home(); $n_h = count( hrows() );
$mk = call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Welcome', 'new' => 'key [redacted]' ) );
check( 'masked_read: new holding [redacted] (the page does not) is refused, nothing written', 'masked_read' === code( $mk ) && $PG === post( $pg )->post_content && $n_h === count( hrows() ) );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
check( 'pages off: a page is refused (gate FORBIDDEN, handler not_allowed)', 'FORBIDDEN' === call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Welcome', 'new' => 'Hi' ) ) && 'not_allowed' === code( handler( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Welcome', 'new' => 'Hi' ) ) ) && $PG === post( $pg )->post_content );
site( without( ADMIN, 'unfiltered_html' ) );
$pg = home();
check( 'without unfiltered_html: refused (gate FORBIDDEN, handler needs_unfiltered_html)', 'FORBIDDEN' === call( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Welcome', 'new' => 'Hi' ) ) && 'needs_unfiltered_html' === code( handler( 'divi-text-edit', array( 'post_id' => $pg, 'expect_hash' => md5( $PG ), 'old' => 'Welcome', 'new' => 'Hi' ) ) ) && $PG === post( $pg )->post_content );
site();
$ft = t_add_post( array( 'post_type' => 'et_footer_layout', 'post_title' => 'Footer', 'post_content' => $PG ) );
$r  = call( 'divi-text-edit', array( 'post_id' => $ft, 'expect_hash' => md5( $PG ), 'old' => 'Mon to Fri', 'new' => 'Mon to Sat' ) );
$h  = hlast();
check( 'on a footer layout (the commonest phone edit) it goes through d5b_tb_write: safety copy = the old content, history before = the whole old content', is_array( $r ) && '<p>Open <strong>Mon to Sat</strong> 9 to 5</p>' === field_of( post( $ft )->post_content, '2.1.1.1' ) && array( $PG ) === rows_of( $ft, '_d5b_prev_content' ) && $h && $PG === $h['before']['content'] && "Changed text in Theme Builder footer layout $ft 'Footer' (2.1.1.1 content)" === $h['summary'] && md5( post( $ft )->post_content ) === $r['hash'], array( $r, $h['summary'] ?? null ) );
$cv = t_add_post( array( 'post_type' => 'et_pb_canvas', 'post_title' => 'Promo', 'post_content' => $PG ) );
check( 'a canvas goes through wp_update_post', is_array( call( 'divi-text-edit', array( 'post_id' => $cv, 'expect_hash' => md5( $PG ), 'old' => 'Welcome', 'new' => 'Hello' ) ) ) && 'Hello' === field_of( post( $cv )->post_content, '1.1.1.1', 'title.innerContent.desktop.value' ) && "Changed text in Divi canvas $cv 'Promo' (1.1.1.1 title)" === hlast()['summary'] );

echo "the visible-text map\n";
$cases = array( '<p>Open <strong>Mon</strong> &amp; <em>Fri</em></p>', '  <b> x </b>  ', '&nbsp; a &#32; ', 'a < b > c', 'x <!-- c --> y', 'p<script>var a = "<b>";</script>q<style>.a{}</style>r', 'a <span title="1 > 2">b</span> c', "caf\u{00e9} &eacute; &#x1F600; &bogus; & ;", '<br>', '' );
$bad = array();
foreach ( $cases as $s ) {
	list( $v, $tok ) = d5b_wd_vis_map( $s );
	// stand-in wp_strip_all_tags has no script/style removal: compare with core's own order of steps
	$core = html_entity_decode( trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $s ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	if ( $v !== $core ) { $bad[] = array( $s, $v, $core ); continue; }
	foreach ( $tok as $x ) { $piece = substr( $s, $x[3], $x[4] - $x[3] ); $want = 't' === $x[0] ? $piece : html_entity_decode( $piece, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); if ( $want !== substr( $v, $x[1], $x[2] - $x[1] ) ) { $bad[] = array( $s, $x ); } }
}
check( 'the walk gives the same visible text as core\'s strip, trim and decode on ' . count( $cases ) . ' tricky inputs, and every token maps to the source bytes it came from', array() === $bad, $bad );
$nf = 0; $bad = array();
foreach ( array( 'vb-5.18.0-variables.html', 'vb-resave-5.15.1/before.html', 'vb-resave-5.15.1/after.html' ) as $fx ) {
	foreach ( d5b_blocks_walk( d5b_blocks_parse( file_get_contents( __DIR__ . '/fixtures/' . $fx ) ) ) as $b ) {
		foreach ( d5b_blocks_text_fields( $b ) as $f => $fh ) { $nf++; if ( d5b_wd_vis_map( $fh )[0] !== d5b_wd_visible( $fh ) ) { $bad[] = array( $fx, $b['addr'], $f ); } }
	}
}
check( "the walk agrees with the visible text on all $nf text fields of the Visual Builder fixtures", $nf >= 20 && array() === $bad, $bad );
list( $v, $tok ) = d5b_wd_vis_map( '<p>Open <strong>Mon</strong> &amp; Fri</p>' );
check( 'spans: "Mon & Fri" maps to "Mon</strong> &amp; Fri" (holds a tag); "& Fri" to "&amp; Fri"; a span ending inside a multi-byte entity is null', array( 8 + 8, 8 + 8 + strlen( 'Mon</strong> &amp; Fri' ) ) === d5b_wd_vis_span( $tok, strpos( $v, 'Mon' ), strlen( $v ) ) && '&amp; Fri' === ( function () use ( $tok, $v ) { $s = d5b_wd_vis_span( $tok, strpos( $v, '&' ), strlen( $v ) ); return substr( '<p>Open <strong>Mon</strong> &amp; Fri</p>', $s[0], $s[1] - $s[0] ); } )() && null === d5b_wd_vis_span( d5b_wd_vis_map( '&eacute;' )[1], 0, 1 ) );

echo "\n" . ( $failed ? "$failed of $n checks FAILED\n" : "all $n checks passed\n" );
exit( $failed ? 1 : 0 );
