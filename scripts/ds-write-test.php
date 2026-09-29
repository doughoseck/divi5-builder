<?php
/**
 * Local test of the design-system WRITE routes (mu-plugin >= 1.8), no WordPress needed.
 *
 *   php scripts/ds-write-test.php "<path to a Divi 5 theme folder>"
 *
 * Part 1 loads Divi's REAL Conversion class from that folder (its own autoloader, WordPress stubbed) and proves that
 * the plugin's split of a preset's attrs into styleAttrs / renderAttrs reproduces what the Visual Builder stored.
 * The expected values are real presets read from a live site.
 * Part 2 runs the plugin's upsert / backup / restore logic against fake Divi stores and breaks it on purpose.
 * The functions under test are the ones in assets/divi5-builder-rest.php itself. Exits non-zero on failure.
 */
if ( $argc < 2 ) { fwrite( STDERR, "usage: php ds-write-test.php <Divi theme folder>\n" ); exit( 2 ); }
$divi = rtrim( $argv[1], '/\\' );
if ( ! file_exists( "$divi/includes/builder-5/server/vendor/autoload.php" ) ) { fwrite( STDERR, "STOP: no Divi 5 under $divi\n" ); exit( 2 ); }

// ---- WordPress stubs: only what the code under test touches ----
define( 'ABSPATH', __DIR__ . '/' );
define( 'ET_BUILDER_5_DIR', "$divi/includes/builder-5/" );
define( 'ET_BUILDER_VERSION', '5.0.0-test' );
$GLOBALS['d5b_options'] = array();
$GLOBALS['d5b_caps']    = array( 'manage_options' => true, 'edit_theme_options' => true );
function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value, ...$args ) { return ( 'd5b_ds_class' === $tag && isset( $GLOBALS['d5b_test_classes'][ $args[0] ] ) ) ? $GLOBALS['d5b_test_classes'][ $args[0] ] : $value; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_kses_post( $s ) { return $s; }
function wp_kses( $s ) { return $s; }
function wp_kses_no_null( $s ) { return str_replace( "\0", '', (string) $s ); }
function wp_check_invalid_utf8( $s ) { return (string) $s; }
function esc_url_raw( $s ) { return (string) $s; }
function esc_attr( $s ) { return (string) $s; }
function esc_html( $s ) { return (string) $s; }
function wp_unslash( $v ) { return $v; }
function wp_slash( $v ) { return $v; }
function et_core_intentionally_unescaped( $s ) { return $s; }
function do_action() {}
function register_post_meta() {}
function __( $s ) { return $s; }
function esc_html__( $s ) { return $s; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['d5b_caps'][ $cap ] ); }
function get_current_user_id() { return 1; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['d5b_options'] ) ? $GLOBALS['d5b_options'][ $name ] : $default; }
function update_option( $name, $value ) { $GLOBALS['d5b_options'][ $name ] = $value; return true; }
function maybe_unserialize( $v ) { return $v; }
function sanitize_text_field( $s ) { return is_scalar( $s ) ? trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ) : ''; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_date( $f ) { return gmdate( $f ); }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function et_get_option( $name, $default = '' ) { return array_key_exists( "et:$name", $GLOBALS['d5b_options'] ) ? $GLOBALS['d5b_options'][ "et:$name" ] : $default; }
function et_update_option( $name, $value ) { $GLOBALS['d5b_options'][ "et:$name" ] = $value; return true; }
class WP_Error { public $code; public $message; public $data; function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } function get_error_code() { return $this->code; } function get_error_message() { return $this->message; } }
#[AllowDynamicProperties]
class WP_Block_Type { public $name; function __construct( $name, $args = array() ) { $this->name = $name; foreach ( $args as $k => $v ) { $this->$k = $v; } } }
class WP_Block_Type_Registry { static function get_instance() { return new self(); } function get_registered( $n ) { return null; } }

require "$divi/includes/builder-5/server/vendor/autoload.php";
require getenv( 'D5B_PLUGIN_FILE' ) ?: __DIR__ . '/../assets/divi5-builder-rest.php'; // the env override is for mutation runs

$failed = 0;
$check  = function ( $name, $ok, $detail = '' ) use ( &$failed ) { echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ( ! $ok && $detail ) ? "\n          $detail" : '' ) . "\n"; if ( ! $ok ) { $failed++; } };
$canon  = function ( $v ) { return json_encode( d5b_ds_ksort( $v ) ); };

// =====================================================================================================
echo "--- PART 1: the split, against presets the Visual Builder really stored (a live site, 2026-09-29) ---\n";
$real = json_decode( file_get_contents( __DIR__ . '/../assets/ds-write-fixtures.json' ), true );
$check( 'fixtures loaded, one of them an option group preset made in the builder', is_array( $real ) && count( $real ) >= 4 && 'group' === ( end( $real )['kind'] ?? '' ) );
foreach ( $real as $p ) {
	$split = d5b_ds_split( $p['moduleName'], $p['attrs'] );
	if ( is_wp_error( $split ) ) { $check( $p['name'] . ': split ran', false, $split->get_error_message() ); continue; }
	$check( $p['name'] . ': styleAttrs identical to the builder\'s', $canon( $split['styleAttrs'] ) === $canon( $p['styleAttrs'] ?? array() ), 'got ' . json_encode( $split['styleAttrs'] ) );
	$check( $p['name'] . ': renderAttrs identical to the builder\'s', $canon( $split['renderAttrs'] ) === $canon( $p['renderAttrs'] ?? array() ), 'got ' . json_encode( $split['renderAttrs'] ) );
}
$bad = d5b_ds_split( 'divi/no-such-module', array( 'module' => array( 'decoration' => array() ) ) );
$check( 'an unknown module is refused, not split into nothing', is_wp_error( $bad ) );
$btn = array( 'button' => array( 'innerContent' => array( 'desktop' => array( 'value' => array( 'text' => 'BOOK NOW', 'linkUrl' => 'https://example.com' ) ) ), 'decoration' => array( 'button' => array( 'desktop' => array( 'value' => array( 'enable' => 'on' ) ) ), 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'weight' => '400', 'color' => '$variable({"type":"color","value":{"name":"gcid-black00001","settings":{}}})$' ) ) ) ) ) ) );
$btn['module'] = array( 'meta' => array( 'adminLabel' => array( 'desktop' => array( 'value' => 'My button' ) ) ), 'decoration' => array( 'interactions' => array( 'desktop' => array( 'value' => array( 'interactions' => array( array( 'id' => 'x' ) ) ) ) ) ) );
$s   = d5b_ds_split( 'divi/button', $btn );
$cnt = is_wp_error( $s ) ? array() : d5b_ds_content( $btn, $s['contentAttrs'] );
$check( 'button: content (text, url, admin label) is recognised as content', isset( $cnt['button']['innerContent'], $cnt['module']['meta'] ) );
$check( 'button: content never reaches styleAttrs or renderAttrs', ! is_wp_error( $s ) && false === strpos( json_encode( array( $s['styleAttrs'], $s['renderAttrs'] ) ), 'BOOK NOW' ) );
$check( 'button: the colour token survives untouched', ! is_wp_error( $s ) && false !== strpos( json_encode( $s['styleAttrs'] ), 'gcid-black00001' ) );

// =====================================================================================================
echo "--- PART 2: upsert, backup, restore, against fake stores ---\n";
// From here Divi's store classes are replaced by recording fakes: what is tested is OUR read-modify-write.
$GLOBALS['d5b_test_classes'] = array( 'preset' => 'D5B_Fake_Preset', 'data' => 'D5B_Fake_Data' );
class D5B_Fake_Preset {
	static $data = array(); static $saves = 0;
	static function get_data() { return self::$data; }
	static function save_data( $d ) { self::$data = $d; self::$saves++; return $d; }
	static function prepare_data( $schema ) { return \ET\Builder\Packages\GlobalData\GlobalPreset::prepare_data( $schema ); }
	static function maybe_create_default_presets_after_import( $d ) { foreach ( array( 'module', 'group' ) as $k ) { foreach ( $d[ $k ] ?? array() as $n => $rec ) { if ( ! empty( $rec['items'] ) && ( empty( $rec['default'] ) || ! isset( $rec['items'][ $rec['default'] ] ) ) ) { $d[ $k ][ $n ]['items']['fakedefault'] = array( 'id' => 'fakedefault', 'name' => 'Default', 'type' => $k, 'moduleName' => $n ); $d[ $k ][ $n ]['default'] = 'fakedefault'; } } } return $d; }
}
class D5B_Fake_Data {
	static $refuse_vars = false;
	static function sanitize_global_colors_data( $d ) { return \ET\Builder\Packages\GlobalData\GlobalData::sanitize_global_colors_data( $d ); }
	static function set_global_colors( $d, $merge = false ) { $g = et_get_option( 'et_global_data', array() ); $g['global_colors'] = $merge ? array_merge( $g['global_colors'] ?? array(), $d ) : $d; et_update_option( 'et_global_data', $g ); }
	static function set_global_variables( $d ) { if ( self::$refuse_vars ) { return; } et_update_option( 'global_variables', $d ); }
}
$colors = function () { $g = et_get_option( 'et_global_data', array() ); return $g['global_colors'] ?? array(); };
$vars   = function () { return et_get_option( 'global_variables', array() ); };

$text = $real[0];
D5B_Fake_Preset::$data = array( 'module' => array( 'divi/text' => array( 'default' => $text['id'], 'items' => array( $text['id'] => array( 'id' => $text['id'], 'name' => $text['name'], 'type' => 'module', 'moduleName' => 'divi/text', 'attrs' => $text['attrs'] ) ) ) ), 'group' => array() );
$before = $canon( D5B_Fake_Preset::$data );

$in  = array( 'moduleName' => 'divi/button', 'name' => 'Button Yellow', 'attrs' => $btn );
$dry = d5b_ds_preset_upsert( $in, true );
$check( 'dry run returns the item', ! is_wp_error( $dry ) && 'created' === $dry['action'] && ! empty( $dry['item']['styleAttrs'] ) );
$check( 'dry run writes nothing', 0 === D5B_Fake_Preset::$saves && $canon( D5B_Fake_Preset::$data ) === $before );
$check( 'dry run takes no backup', empty( $GLOBALS['d5b_options']['d5b_ds_backups'] ) );

$r = d5b_ds_preset_upsert( $in, false );
$check( 'create: saved once', ! is_wp_error( $r ) && 1 === D5B_Fake_Preset::$saves );
$id = is_wp_error( $r ) ? '' : $r['item']['id'];
$check( 'create: id is safe for a CSS class', (bool) preg_match( '/^[a-z0-9]{8,32}$/', $id ) );
$check( 'create: content was stripped from the preset', ! is_wp_error( $r ) && false === strpos( json_encode( $r['item'] ), 'BOOK NOW' ) && false === strpos( json_encode( $r['item'] ), 'My button' ) && ! empty( $r['strippedContent'] ) );
$check( 'create: an interaction inside a preset is warned about', ! is_wp_error( $r ) && false !== strpos( implode( ' ', $r['warnings'] ), 'module.decoration.interactions' ) );
$check( 'create: item has every field Divi requires', ! is_wp_error( $r ) && ! array_diff( array( 'type', 'id', 'name', 'moduleName', 'version', 'created', 'updated', 'attrs', 'styleAttrs' ), array_keys( $r['item'] ) ) );
$check( 'create: timestamps are milliseconds, like the builder\'s', ! is_wp_error( $r ) && 13 === strlen( (string) $r['item']['created'] ) );
$check( 'create: the existing Text preset is untouched', $canon( D5B_Fake_Preset::$data['module']['divi/text'] ) === $canon( json_decode( $before, true )['module']['divi/text'] ) );
$check( 'create: a new module type gets a valid default, by Divi\'s own rule', isset( D5B_Fake_Preset::$data['module']['divi/button']['items'][ D5B_Fake_Preset::$data['module']['divi/button']['default'] ] ) );
$check( 'create: the new preset is NOT made the default', D5B_Fake_Preset::$data['module']['divi/button']['default'] !== $id );
$check( 'create: a backup of the previous store exists', $canon( $GLOBALS['d5b_options']['d5b_ds_backups']['presets'][0]['value'] ?? null ) === $before );

$r2 = d5b_ds_preset_upsert( $in, false );
$check( 'same name again updates, never duplicates', ! is_wp_error( $r2 ) && 'updated' === $r2['action'] && $r2['item']['id'] === $id && 2 === count( D5B_Fake_Preset::$data['module']['divi/button']['items'] ) );
$check( 'update keeps created, moves updated', ! is_wp_error( $r2 ) && $r2['item']['created'] === $r['item']['created'] && $r2['item']['updated'] >= $r['item']['updated'] );

$in3 = $in; $in3['attrs']['button']['decoration']['font']['font']['desktop']['value'] = array( 'weight' => '700' );
$r3  = d5b_ds_preset_upsert( $in3, false );
$check( 'update replaces attrs wholesale (a removed value does not linger)', ! is_wp_error( $r3 ) && false === strpos( json_encode( $r3['item'] ), 'gcid-black00001' ) && false !== strpos( json_encode( $r3['item']['styleAttrs'] ), '700' ) );

$font = array( 'button' => array( 'decoration' => array( 'font' => array( 'font' => array( 'desktop' => array( 'value' => array( 'weight' => '700' ) ) ) ) ) ) );
$r4   = d5b_ds_preset_upsert( array( 'moduleName' => 'divi/button', 'name' => 'Aligned', 'attrs' => $font + array( 'module' => array( 'advanced' => array( 'alignment' => array( 'desktop' => array( 'value' => 'left' ) ) ) ) ) ), false );
$check( 'a markup setting (alignment) lands in renderAttrs', ! is_wp_error( $r4 ) && isset( $r4['item']['renderAttrs']['module']['advanced']['alignment'] ) );
$r5   = d5b_ds_preset_upsert( array( 'moduleName' => 'divi/button', 'name' => 'Aligned', 'attrs' => $font ), false );
$check( 'update: renderAttrs of the old version do not linger', ! is_wp_error( $r5 ) && 'updated' === $r5['action'] && ! isset( $r5['item']['renderAttrs'] ) && ! isset( D5B_Fake_Preset::$data['module']['divi/button']['items'][ $r5['id'] ]['renderAttrs'] ) );

echo "--- every one of these must be refused ---\n";
$saves = D5B_Fake_Preset::$saves;
foreach ( array(
	'no name'                         => array( 'moduleName' => 'divi/button', 'attrs' => $btn ),
	'no attrs'                        => array( 'moduleName' => 'divi/button', 'name' => 'X' ),
	'attrs that are only content'     => array( 'moduleName' => 'divi/button', 'name' => 'X', 'attrs' => array( 'button' => array( 'innerContent' => $btn['button']['innerContent'] ) ) ),
	'module name with a path in it'   => array( 'moduleName' => '../evil', 'name' => 'X', 'attrs' => $btn ),
	'unknown module'                  => array( 'moduleName' => 'divi/nope', 'name' => 'X', 'attrs' => $btn ),
	'id that is not class-safe'       => array( 'moduleName' => 'divi/button', 'name' => 'X', 'id' => 'a b"c', 'attrs' => $btn ),
	'group preset without groupName'  => array( 'kind' => 'group', 'moduleName' => 'divi/button', 'name' => 'X', 'groupId' => 'button.decoration.font', 'attrs' => $btn ),
	'kind that does not exist'        => array( 'kind' => 'theme', 'moduleName' => 'divi/button', 'name' => 'X', 'attrs' => $btn ),
) as $label => $bad_in ) {
	$check( $label, is_wp_error( d5b_ds_preset_upsert( $bad_in, false ) ) );
}
$check( 'none of the refusals saved anything', $saves === D5B_Fake_Preset::$saves );

echo "--- option group presets ---\n";
$gfx   = end( $real ); // made by hand in the builder: it holds the WHOLE module's design, not only the border
$whole = $gfx['attrs'] + array( 'content' => array( 'innerContent' => array( 'desktop' => array( 'value' => '<p>text</p>' ) ) ) );
$gin   = array( 'kind' => 'group', 'moduleName' => 'divi/text', 'groupName' => $gfx['groupName'], 'groupId' => $gfx['groupId'], 'name' => 'Yellow bar', 'attrs' => $whole );
$g     = d5b_ds_preset_upsert( $gin, true );
$check( 'group preset: accepted', ! is_wp_error( $g ) && 'group' === $g['kind'] && $gfx['groupName'] === $g['for'], is_wp_error( $g ) ? $g->get_error_message() : '' );
$check( 'group preset: holds the settings of its own group', ! is_wp_error( $g ) && $canon( $g['item']['attrs']['module']['decoration']['border'] ) === $canon( $gfx['attrs']['module']['decoration']['border'] ) );
$check( 'group preset: holds NOTHING from other groups (the builder stores them, Divi never applies them)', ! is_wp_error( $g ) && array( 'border' ) === array_keys( $g['item']['attrs']['module']['decoration'] ) && array( 'module' ) === array_keys( $g['item']['attrs'] ) );
$check( 'group preset: what was left out is reported', ! is_wp_error( $g ) && count( $g['leftOutOfGroup'] ) >= 3 && false !== strpos( implode( ' ', $g['warnings'] ), 'left out' ) );
$check( 'group preset: its style part is the group\'s part of the builder\'s', ! is_wp_error( $g ) && $canon( $g['item']['styleAttrs'] ) === $canon( array( 'module' => array( 'decoration' => array( 'border' => $gfx['styleAttrs']['module']['decoration']['border'] ) ) ) ) );
$check( 'group preset: carries groupName, groupId and the module it was made in', ! is_wp_error( $g ) && $gfx['groupId'] === $g['item']['groupId'] && $gfx['groupName'] === $g['item']['groupName'] && 'divi/text' === $g['item']['moduleName'] && 'group' === $g['item']['type'] );
$gsave = d5b_ds_preset_upsert( $gin, false );
$check( 'group preset: stored under its group name, module presets untouched', ! is_wp_error( $gsave ) && isset( D5B_Fake_Preset::$data['group'][ $gfx['groupName'] ]['items'][ $gsave['id'] ] ) && isset( D5B_Fake_Preset::$data['module']['divi/text'] ) );
$check( 'group preset: attrs with nothing under the group are refused', is_wp_error( d5b_ds_preset_upsert( array( 'groupId' => 'module.decoration.boxShadow' ) + $gin, true ) ) );
$comp = d5b_ds_preset_upsert( array( 'groupId' => 'designText', 'groupName' => 'divi/font', 'name' => 'Composite' ) + $gin, true );
$check( 'group preset: a composite group id cannot be filtered, and says so', ! is_wp_error( $comp ) && false !== strpos( implode( ' ', $comp['warnings'] ), 'not a path' ) );

echo "--- colours ---\n";
et_update_option( 'et_global_data', array( 'global_colors' => array( 'gcid-yellow0001' => array( 'id' => 'gcid-yellow0001', 'label' => 'Yellow', 'color' => '#fec10e', 'status' => 'active', 'order' => '6' ) ) ) );
$c = d5b_ds_color_upsert( array( 'label' => 'Overlay', 'color' => '#112233' ), false );
$check( 'create colour: new gcid, existing colour kept', ! is_wp_error( $c ) && 0 === strpos( $c['id'], 'gcid-' ) && 2 === count( $colors() ) && '#fec10e' === $colors()['gcid-yellow0001']['color'], is_wp_error( $c ) ? $c->get_error_message() : '' );
$check( 'create colour: order continues after the highest', ! is_wp_error( $c ) && '7' === (string) $colors()[ $c['id'] ]['order'] );
$c2 = d5b_ds_color_upsert( array( 'label' => 'Overlay', 'color' => '#445566' ), false );
$check( 'same label updates the same colour', ! is_wp_error( $c2 ) && $c2['id'] === $c['id'] && 2 === count( $colors() ) && '#445566' === $colors()[ $c['id'] ]['color'] );
$n = count( $colors() );
$check( 'dry run of a colour writes nothing', ! is_wp_error( d5b_ds_color_upsert( array( 'label' => 'Dry', 'color' => '#abcdef' ), true ) ) && $n === count( $colors() ) );
$check( 'a colour that is not a colour is refused', is_wp_error( d5b_ds_color_upsert( array( 'label' => 'Bad', 'color' => 'javascript:alert(1)' ), false ) ) );
$check( 'a colour may be a token pointing at another colour', ! is_wp_error( d5b_ds_color_upsert( array( 'label' => 'Alias', 'color' => '$variable({"type":"color","value":{"name":"gcid-yellow0001","settings":{"opacity":40}}})$' ), true ) ) );
$check( 'rgba is accepted', ! is_wp_error( d5b_ds_color_upsert( array( 'label' => 'Tint', 'color' => 'rgba(0,0,0,0.4)' ), true ) ) );

echo "--- variables ---\n";
et_update_option( 'global_variables', array( 'numbers' => array( 'gvid-aaa' => array( 'id' => 'gvid-aaa', 'label' => 'Gap', 'value' => '20px', 'order' => 1, 'status' => 'active' ) ), 'strings' => array( 'gvid-bbb' => array( 'id' => 'gvid-bbb', 'label' => 'Tagline', 'value' => 'x', 'order' => 1, 'status' => 'active' ) ) ) );
$v = d5b_ds_variable_upsert( array( 'type' => 'numbers', 'label' => 'Section pad', 'value' => '50px' ), false );
$check( 'create variable: existing ones kept, other types too', ! is_wp_error( $v ) && 2 === count( $vars()['numbers'] ) && '20px' === $vars()['numbers']['gvid-aaa']['value'] && isset( $vars()['strings']['gvid-bbb'] ), is_wp_error( $v ) ? $v->get_error_message() : '' );
$check( 'create variable: id repeated inside the item', ! is_wp_error( $v ) && $vars()['numbers'][ $v['id'] ]['id'] === $v['id'] );
$vi = is_wp_error( $v ) ? array() : $vars()['numbers'][ $v['id'] ];
$check( 'create variable: the same fields, in the same order, as one made in the builder', array( 'id', 'label', 'value', 'order', 'status', 'lastUpdated', 'variableType' ) === array_keys( $vi ), implode( ',', array_keys( $vi ) ) );
$check( 'create variable: variableType is the type, order is a string', 'numbers' === ( $vi['variableType'] ?? '' ) && '2' === ( $vi['order'] ?? null ) );
$check( 'timestamps are UTC, in the builder\'s form', (bool) preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $vi['lastUpdated'] ?? '' ) && abs( strtotime( $vi['lastUpdated'] ) - time() ) < 5, $vi['lastUpdated'] ?? '' );
$vu = d5b_ds_variable_upsert( array( 'type' => 'numbers', 'label' => 'Gap', 'value' => '22px' ), false );
$check( 'update of a variable made elsewhere keeps its order and gains variableType', ! is_wp_error( $vu ) && 'gvid-aaa' === $vu['id'] && '1' === (string) $vars()['numbers']['gvid-aaa']['order'] && 'numbers' === $vars()['numbers']['gvid-aaa']['variableType'] && '22px' === $vars()['numbers']['gvid-aaa']['value'] );
$check( 'an id that belongs to another type is refused', is_wp_error( d5b_ds_variable_upsert( array( 'type' => 'numbers', 'id' => 'gvid-bbb', 'label' => 'Clash', 'value' => '1px' ), false ) ) );
$check( 'colors is not a variable type (they live in the colour store)', is_wp_error( d5b_ds_variable_upsert( array( 'type' => 'colors', 'label' => 'X', 'value' => '#fff' ), false ) ) );
D5B_Fake_Data::$refuse_vars = true;
$check( 'a write Divi silently ignored is reported as an ERROR, not as success', is_wp_error( d5b_ds_variable_upsert( array( 'type' => 'numbers', 'label' => 'Ghost', 'value' => '1px' ), false ) ) );
D5B_Fake_Data::$refuse_vars = false;

echo "--- restore ---\n";
$now = $canon( D5B_Fake_Preset::$data );
$rs  = d5b_ds_restore( 'presets', 0 );
$check( 'restore puts back the store as it was before the last write', ! is_wp_error( $rs ) && $canon( D5B_Fake_Preset::$data ) !== $now );
$check( 'restore itself is backed up (it can be undone)', $canon( $GLOBALS['d5b_options']['d5b_ds_backups']['presets'][0]['value'] ) === $now );
$check( 'restore of a backup that does not exist is refused', is_wp_error( d5b_ds_restore( 'presets', 99 ) ) );
$check( 'restore of an unknown store is refused', is_wp_error( d5b_ds_restore( 'options', 0 ) ) );
$first = $canon( end( $GLOBALS['d5b_options']['d5b_ds_backups']['colors'] )['value'] );
for ( $i = 0; $i < 25; $i++ ) { d5b_ds_color_upsert( array( 'label' => "C$i", 'color' => '#000000' ), false ); }
$bk = $GLOBALS['d5b_options']['d5b_ds_backups']['colors'];
$check( 'backups are capped (newest 10 + the first of the day)', 11 === count( $bk ) );
$check( 'a long run of writes does not push out the store as it was before the first one', ! empty( end( $bk )['pinned'] ) && $canon( end( $bk )['value'] ) === $first );
$check( 'only one backup per day is kept that way', 1 === count( array_filter( $bk, function ( $b ) { return ! empty( $b['pinned'] ); } ) ) );
$old                                            = $bk;
foreach ( $old as $i => $b ) { $old[ $i ]['time'] = '2020-01-0' . ( 1 + $i % 9 ) . 'T00:00:00+00:00'; $old[ $i ]['pinned'] = true; }
$GLOBALS['d5b_options']['d5b_ds_backups']['colors'] = $old;
d5b_ds_color_upsert( array( 'label' => 'After', 'color' => '#000000' ), false );
$check( 'first-of-day backups are capped too (5 days)', 5 === count( array_filter( $GLOBALS['d5b_options']['d5b_ds_backups']['colors'], function ( $b ) { return ! empty( $b['pinned'] ); } ) ) );

echo "--- permissions ---\n";
$GLOBALS['d5b_caps'] = array( 'manage_options' => true, 'edit_theme_options' => false );
$check( 'without edit_theme_options nothing is allowed', false === d5b_ds_can() );
$GLOBALS['d5b_caps'] = array( 'manage_options' => false, 'edit_theme_options' => true );
$check( 'without manage_options nothing is allowed', false === d5b_ds_can() );

echo "--- selftest ---\n";
$GLOBALS['d5b_caps'] = array( 'manage_options' => true, 'edit_theme_options' => true );
$grp = end( $real ); $txt = $real[0];
$item = function ( $p, $type ) { return array( 'id' => $p['id'], 'name' => $p['name'], 'type' => $type, 'moduleName' => $p['moduleName'], 'attrs' => $p['attrs'], 'styleAttrs' => $p['styleAttrs'], 'renderAttrs' => $p['renderAttrs'] ); };
D5B_Fake_Preset::$data = array(
	'module' => array( 'divi/text' => array( 'default' => $txt['id'], 'items' => array( $txt['id'] => $item( $txt, 'module' ) ) ), 'divi/blurb' => array( 'default' => 'emptydefault', 'items' => array( 'emptydefault' => array( 'id' => 'emptydefault', 'name' => 'Blurb 1', 'type' => 'module', 'moduleName' => 'divi/blurb' ) ) ) ),
	'group'  => array( $grp['groupName'] => array( 'default' => '', 'items' => array( $grp['id'] => $item( $grp, 'group' ) + array( 'groupName' => $grp['groupName'], 'groupId' => $grp['groupId'] ) ) ) ),
);
$st = d5b_ds_selftest();
$check( 'module preset, option group preset and an empty default: 3 checked, 0 different', ! is_wp_error( $st ) && 3 === $st['checked'] && 0 === $st['different'], json_encode( $st ) );
$check( 'the group preset is reported as a group preset', ! is_wp_error( $st ) && 1 === count( array_filter( $st['presets'], function ( $r ) { return 'group' === $r['kind']; } ) ) );
D5B_Fake_Preset::$data['group'][ $grp['groupName'] ]['items'][ $grp['id'] ]['styleAttrs']['module']['decoration']['sizing']['desktop']['value']['maxWidth'] = '901px';
$st = d5b_ds_selftest();
$check( 'a group preset whose stored style part is wrong is reported', ! is_wp_error( $st ) && 1 === $st['different'] );
unset( D5B_Fake_Preset::$data['module']['divi/text']['items'][ $txt['id'] ]['renderAttrs'] );
$st = d5b_ds_selftest();
$check( 'a module preset that lost its markup part is reported', ! is_wp_error( $st ) && 2 === $st['different'] );

echo $failed ? "\n$failed FAILED\n" : "\nall passed\n";
exit( $failed ? 1 : 0 );
