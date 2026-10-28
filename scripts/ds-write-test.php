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
 * The functions under test are the ones in plugin/divi5-builder/parts/page-building.php itself. Exits non-zero on failure.
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
class WP_Error { public $code; public $message; public $data; function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } function get_error_code() { return $this->code; } function get_error_message() { return $this->message; } function get_error_data() { return $this->data; } }
#[AllowDynamicProperties]
class WP_Block_Type { public $name; function __construct( $name, $args = array() ) { $this->name = $name; foreach ( $args as $k => $v ) { $this->$k = $v; } } }
class WP_Block_Type_Registry { static function get_instance() { return new self(); } function get_registered( $n ) { return null; } }

require "$divi/includes/builder-5/server/vendor/autoload.php";
require getenv( 'D5B_PLUGIN_FILE' ) ?: __DIR__ . '/../plugin/divi5-builder/parts/page-building.php'; // the env override is for mutation runs

$failed = 0;
$check  = function ( $name, $ok, $detail = '' ) use ( &$failed ) { echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ( ! $ok && $detail ) ? "\n          $detail" : '' ) . "\n"; if ( ! $ok ) { $failed++; } return $ok; };
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
$check( 'older Divi (no id check): archiving a variable removes it', ! is_wp_error( $ar = d5b_ds_variable_archive( array( 'id' => $v['id'] ), false ) ) && 'removed' === $ar['action'] && ! isset( $vars()['numbers'][ $v['id'] ] ) && isset( $vars()['numbers']['gvid-aaa'] ) );
et_update_option( 'global_variables', array( 'numbers' => array( 'gvid-aaa' => array( 'id' => 'gvid-aaa', 'label' => 'Gap', 'value' => '20px', 'order' => '1', 'status' => 'active' ) ) ) );
$v13 = d5b_ds_variable_upsert( array( 'type' => 'strings', 'label' => 'Extra', 'value' => 'x' ), false );
$rr  = d5b_ds_restore( 'variables', 0 );
$check( 'older Divi: restore drops a newer variable, exactly as before', ! is_wp_error( $rr ) && ! isset( $vars()['strings'] ) && ! isset( $rr['archived'] ) && '20px' === $vars()['numbers']['gvid-aaa']['value'], is_wp_error( $rr ) ? $rr->get_error_message() : '' );

echo "--- variables on Divi 5.15+ (its id check, archive, WP_Error, silent refusal) ---\n";
// The real Divi 5.15.1 rules, copied from GlobalData::set_global_variables() and its two validators.
class D5B_Fake_Data515 extends D5B_Fake_Data {
	static $no_perm = false; static $last_expected = null;
	static function validate_global_variable_ids( $cur, $next ) { foreach ( $cur as $t => $items ) { if ( array_diff( array_keys( (array) $items ), array_keys( (array) ( $next[ $t ] ?? array() ) ) ) ) { return new WP_Error( 'global_variable_ids_missing', "CRITICAL: Global variable IDs are missing during sync! Type: $t.", array( 'status' => 400 ) ); } } return null; }
	static function set_global_variables( $d, $expected = null ) {
		if ( self::$no_perm ) { return null; }
		self::$last_expected = $expected;
		if ( null !== $expected && d5b_ds_ksort( $expected ) !== d5b_ds_ksort( et_get_option( 'global_variables', array() ) ) ) { return new WP_Error( 'global_variables_stale', 'Global variables changed after this save was prepared.', array( 'status' => 409 ) ); }
		$cur = et_get_option( 'global_variables', array() );
		if ( $e = self::validate_global_variable_ids( $cur, $d ) ) { return $e; }
		foreach ( $d as $t => $items ) { foreach ( $items as $id => $it ) { if ( ! in_array( $it['status'] ?? null, array( 'active', 'archived' ), true ) ) { return new WP_Error( 'global_variable_invalid_status', "Invalid global variable status during sync! ID: $id." , array( 'status' => 400 ) ); } } }
		et_update_option( 'global_variables', $d );
		return null;
	}
}
$GLOBALS['d5b_test_classes']['data'] = 'D5B_Fake_Data515';
et_update_option( 'global_variables', array( 'numbers' => array( 'gvid-aaa' => array( 'id' => 'gvid-aaa', 'label' => 'Gap', 'value' => '20px', 'order' => '1', 'status' => 'active' ) ) ) );
$before515 = $vars();
$t1 = d5b_ds_variable_upsert( array( 'type' => 'numbers', 'label' => 'ZZ Test Gap', 'value' => '1px' ), false );
$t2 = d5b_ds_variable_upsert( array( 'type' => 'strings', 'label' => 'ZZ Test Text', 'value' => 'hi' ), false );
$check( '5.15: two test variables created', ! is_wp_error( $t1 ) && ! is_wp_error( $t2 ) && isset( $vars()['strings'][ $t2['id'] ] ) );
$check( '5.15: a write tells Divi which store it was based on (so a builder save in between is not overwritten)', is_array( D5B_Fake_Data515::$last_expected ) && isset( D5B_Fake_Data515::$last_expected['numbers'][ $t1['id'] ] ) && ! isset( D5B_Fake_Data515::$last_expected['strings'] ) );
$saved = $vars();
$st    = d5b_ds_set_variables( 'D5B_Fake_Data515', $saved, array( 'numbers' => array() ) );
$check( '5.15: a store changed since it was read is refused (409 stale), nothing written', is_wp_error( $st ) && 'global_variables_stale' === $st->get_error_code() && 409 === $st->get_error_data()['status'] && $vars() === $saved );
// the bug of 1.8.3: restore wrote the backup as it was, Divi refused it, and the route said ok:true
$saved = $vars();
$raw   = D5B_Fake_Data515::set_global_variables( $before515 );
$check( 'the fake behaves like Divi 5.15: a store that drops ids is refused with a WP_Error', is_wp_error( $raw ) && 'global_variable_ids_missing' === $raw->get_error_code() && $vars() === $saved );
$rr = d5b_ds_restore( 'variables', 1 ); // backup 1 = before the first test write
$now = $vars();
$check( '5.15: restore succeeds', ! is_wp_error( $rr ) && true === $rr['ok'], is_wp_error( $rr ) ? $rr->get_error_message() : '' );
$check( '5.15: restore reports the newer variables as archived', ! is_wp_error( $rr ) && array( $t1['id'], $t2['id'] ) == $rr['archived'] );
$check( '5.15: the newer variables are still in the store, archived', 'archived' === ( $now['numbers'][ $t1['id'] ]['status'] ?? '' ) && 'archived' === ( $now['strings'][ $t2['id'] ]['status'] ?? '' ) );
$check( '5.15: every variable of the backup is back exactly', $canon( $now['numbers']['gvid-aaa'] ) === $canon( $before515['numbers']['gvid-aaa'] ) );
$t3 = d5b_ds_variable_upsert( array( 'type' => 'numbers', 'label' => 'ZZ Test Gap', 'value' => '2px' ), false );
$check( '5.15: the same label again makes a NEW variable (an archived one is deleted)', ! is_wp_error( $t3 ) && 'created' === $t3['action'] && $t3['id'] !== $t1['id'] );
$a = d5b_ds_variable_archive( array( 'id' => $t3['id'] ), true );
$check( '5.15: archive dry run writes nothing', ! is_wp_error( $a ) && 'archived' === $a['action'] && 'active' === $vars()['numbers'][ $t3['id'] ]['status'] );
$a = d5b_ds_variable_archive( array( 'id' => $t3['id'] ), false );
$check( '5.15: archive sets the status, empties the order (as the builder does), keeps the id, touches nothing else', ! is_wp_error( $a ) && 'archived' === $vars()['numbers'][ $t3['id'] ]['status'] && '' === $vars()['numbers'][ $t3['id'] ]['order'] && $canon( $vars()['numbers']['gvid-aaa'] ) === $canon( $before515['numbers']['gvid-aaa'] ), is_wp_error( $a ) ? $a->get_error_message() : '' );
$a2 = d5b_ds_variable_archive( array( 'id' => $t3['id'] ), false );
$check( '5.15: archiving twice is harmless', ! is_wp_error( $a2 ) && 'unchanged' === $a2['action'] );
$check( '5.15: archive of an unknown id is refused', is_wp_error( d5b_ds_variable_archive( array( 'id' => 'gvid-nope' ), false ) ) );
$check( '5.15: archive without an id is refused', is_wp_error( d5b_ds_variable_archive( array( 'label' => 'Gap' ), false ) ) );
$back = d5b_ds_variable_upsert( array( 'type' => 'numbers', 'id' => $t3['id'], 'label' => 'ZZ Test Gap', 'value' => '3px' ), false );
$check( '5.15: by id, an archived variable is brought back, and says so', ! is_wp_error( $back ) && 'updated' === $back['action'] && ! empty( $back['unarchived'] ) && 'active' === $vars()['numbers'][ $t3['id'] ]['status'] && '' !== $vars()['numbers'][ $t3['id'] ]['order'] );
D5B_Fake_Data515::$no_perm = true;
$saved = $vars();
$np    = d5b_ds_restore( 'variables', 0 );
$check( '5.15: a restore Divi silently skipped (no permission) is an ERROR, not ok:true', is_wp_error( $np ) && 'not_saved' === $np->get_error_code() && $vars() === $saved );
$check( '5.15: an archive Divi silently skipped is an error too', is_wp_error( d5b_ds_variable_archive( array( 'id' => $t3['id'] ), false ) ) );
$check( '5.15: a create Divi silently skipped is an error too', is_wp_error( d5b_ds_variable_upsert( array( 'type' => 'numbers', 'label' => 'Ghost', 'value' => '1px' ), false ) ) );
D5B_Fake_Data515::$no_perm = false;
// a WP_Error from Divi is passed on with its code and an HTTP status
$GLOBALS['d5b_options']['d5b_ds_backups']['variables'][0]['value'] = array( 'numbers' => array( 'gvid-aaa' => array( 'id' => 'gvid-aaa', 'label' => 'Gap', 'value' => '20px', 'status' => 'bogus' ) ) );
$bad = d5b_ds_restore( 'variables', 0 );
$check( '5.15: Divi\'s WP_Error reaches the caller, with its code and a 400', is_wp_error( $bad ) && 'global_variable_invalid_status' === $bad->get_error_code() && 400 === ( $bad->get_error_data()['status'] ?? 0 ) );

echo "--- nested variables (Divi 5.18, issue #45): references checked, archive guarded ---\n";
// The token is the one the Visual Builder stored on a live 5.18.0 site (ids and labels from that site's store).
$tok = function ( $id ) { return '$variable({"type":"content","value":{"name":"' . $id . '","settings":{}}})$'; };
$nv  = function ( $id, $label, $value, $status = 'active', $type = 'numbers' ) { return array( 'id' => $id, 'label' => $label, 'value' => $value, 'order' => 'archived' === $status ? '' : '1', 'status' => $status, 'lastUpdated' => '2026-10-28T08:08:44.912Z', 'variableType' => $type ); };
et_update_option( 'global_variables', array(
	'numbers' => array( 'gvid-xdtsp8ywr8' => $nv( 'gvid-xdtsp8ywr8', 'Space', '40px' ), 'gvid-k1iggz3omp' => $nv( 'gvid-k1iggz3omp', 'Min Heading', '28px' ), 'gvid-4az4m3i650' => $nv( 'gvid-4az4m3i650', 'Max Heading', '56px' ) ),
	'strings' => array( 'gvid-n7rq1b1o9w' => $nv( 'gvid-n7rq1b1o9w', 'Year', '2026', 'active', 'strings' ), 'gvid-q35xsw1fp0' => $nv( 'gvid-q35xsw1fp0', 'Legal Name', 'Example Ltd', 'active', 'strings' ), 'gvid-old0000001' => $nv( 'gvid-old0000001', 'Old', 'gone', 'archived', 'strings' ) ),
) );
$refs = d5b_ds_variable_refs( 'clamp( ' . $tok( 'gvid-k1iggz3omp' ) . ', 4vw, ' . $tok( 'gvid-4az4m3i650' ) . ')' );
$check( 'references are read from a nested value (' . ( method_exists( 'ET\\Builder\\Packages\\GlobalData\\GlobalData', 'get_global_variable_references' ) ? 'Divi\'s own get_global_variable_references' : 'the fallback patterns: this Divi has no get_global_variable_references' ) . ')', array( 'gvid-4az4m3i650', 'gvid-k1iggz3omp' ) === ( function ( $r ) { sort( $r ); return $r; } )( $refs ), json_encode( $refs ) );
$check( 'var(--gvid-…) counts as a reference too; a dynamic-content token (post_author) and a colour id do not', array( 'gvid-xdtsp8ywr8' ) === d5b_ds_variable_refs( 'calc(var(--gvid-xdtsp8ywr8) * -1) ' . $tok( 'post_author' ) . ' var(--gcid-primary-color)' ) );
// The fallback finder (Divi before 5.18), with DynamicData and then with nothing of Divi's at all (our scanner only).
$bsl  = chr( 92 );
$enc  = array(
	'a plain token'                                         => array( $tok( 'gvid-plain00001' ), array( 'gvid-plain00001' ) ),
	'a token with &quot; quotes'                            => array( str_replace( '"', '&quot;', $tok( 'gvid-quot000001' ) ), array( 'gvid-quot000001' ) ),
	'a block-encoded token (backslash-u0022 quotes)'        => array( str_replace( '"', $bsl . 'u0022', $tok( 'gvid-uenc000001' ) ), array( 'gvid-uenc000001' ) ),
	'a token whose settings string holds ")$", then another' => array( '$variable({"type":"content","value":{"name":"gvid-sett000001","settings":{"before":"a)$b"}}})$ ' . $tok( 'gvid-next000001' ), array( 'gvid-next000001', 'gvid-sett000001' ) ),
	'a token inside another token\'s settings'              => array( '$variable({"type":"content","value":{"name":"post_title","settings":{"after":' . json_encode( $tok( 'gvid-inner00001' ) ) . '}}})$', array( 'gvid-inner00001' ) ),
	'a colour token naming a gcid (not a variable)'         => array( '$variable({"type":"color","value":{"name":"gcid-x","settings":{}}})$', array() ),
);
foreach ( array( 'with Divi\'s DynamicData' => array( 'refs' => '' ), 'with our scanner only' => array( 'refs' => '', 'dynamic' => '' ) ) as $how => $off ) {
	$keep = $GLOBALS['d5b_test_classes'];
	$GLOBALS['d5b_test_classes'] = $off + $keep;
	foreach ( $enc as $what => list( $val, $want ) ) {
		$got = d5b_ds_variable_refs( $val ); sort( $got );
		$check( "fallback finder ($how): $what", $want === $got, json_encode( $got ) );
	}
	// Hostile input, 1 MB (the largest value the size check lets through): the search must stay linear. The old scanner
	// restarted after every unclosed opener: 64 KB of "$variable({" took 26 s, 1 MB would take hours.
	// 64 KB first: a quadratic scanner fails this one in seconds (by assertion) instead of hanging for hours on 1 MB.
	$adv = substr( str_repeat( '$variable({', 6000 ), 0, 65536 );
	$t0  = microtime( true ); d5b_ds_variable_refs( $adv ); $dt = microtime( true ) - $t0;
	if ( ! $check( sprintf( 'fallback finder (%s): 64 KB of "$variable({" repeated finishes in %.3f s (< 0.5 s)', $how, $dt ), $dt < 0.5 ) ) { $GLOBALS['d5b_test_classes'] = $keep; continue; }
	foreach ( array( '$variable({', '$variable({"', '$variable({"a":"' ) as $unit ) {
		$adv = substr( str_repeat( $unit, intdiv( 1048576, strlen( $unit ) ) + 1 ), 0, 1048576 );
		$t0  = microtime( true ); d5b_ds_variable_refs( $adv ); $dt = microtime( true ) - $t0;
		$check( sprintf( 'fallback finder (%s): 1 MB of %s repeated finishes in %.3f s (< 2 s)', $how, json_encode( $unit ), $dt ), $dt < 2.0 );
	}
	$deep = '$variable(' . str_repeat( '{', 1048576 - 10 );
	$t0   = microtime( true ); d5b_ds_variable_refs( $deep ); $dt = microtime( true ) - $t0;
	$check( sprintf( 'fallback finder (%s): 1 MB of nested "{" finishes in %.3f s (< 2 s)', $how, $dt ), $dt < 2.0 );
	$many = str_repeat( '$variable({})x', 74898 ) . $tok( 'gvid-last000001' ); // closed but not tokens, then a real one
	$t0   = microtime( true ); $got = d5b_ds_variable_refs( $many ); $dt = microtime( true ) - $t0;
	$check( sprintf( 'fallback finder (%s): 1 MB of closed non-tokens, then a real token: found, in %.3f s (< 2 s)', $how, $dt ), array( 'gvid-last000001' ) === $got && $dt < 2.0, json_encode( $got ) );
	$GLOBALS['d5b_test_classes'] = $keep;
}
$fluid = 'clamp( ' . $tok( 'gvid-k1iggz3omp' ) . ', 4vw, ' . $tok( 'gvid-4az4m3i650' ) . ')';
$f     = d5b_ds_variable_upsert( array( 'type' => 'numbers', 'label' => 'Fluid Heading', 'value' => $fluid ), false );
$check( 'nested number variable (Fluid Heading = clamp( Min, 4vw, Max )) is accepted and stored exactly', ! is_wp_error( $f ) && $fluid === ( $vars()['numbers'][ $f['id'] ]['value'] ?? '' ), is_wp_error( $f ) ? $f->get_error_message() : '' );
$copy = 'Copyright © ' . $tok( 'gvid-n7rq1b1o9w' ) . ' ' . $tok( 'gvid-q35xsw1fp0' ) . '. All rights reserved.';
$cr   = d5b_ds_variable_upsert( array( 'type' => 'strings', 'label' => 'Copyright', 'value' => $copy ), false );
$check( 'nested string variable (Copyright © Year Legal Name) is accepted and stored exactly', ! is_wp_error( $cr ) && $copy === ( $vars()['strings'][ $cr['id'] ]['value'] ?? '' ), is_wp_error( $cr ) ? $cr->get_error_message() : '' );
$check( 'math on a variable (Space * -1) is accepted', ! is_wp_error( $neg = d5b_ds_variable_upsert( array( 'type' => 'numbers', 'label' => 'Negative space', 'value' => $tok( 'gvid-xdtsp8ywr8' ) . ' * -1' ), false ) ), is_wp_error( $neg ) ? $neg->get_error_message() : '' );
$refused = function ( $label, $in, $problem ) use ( $check, $vars ) {
	$before = json_encode( $vars() ); $bk = count( $GLOBALS['d5b_options']['d5b_ds_backups']['variables'] ?? array() );
	foreach ( array( true, false ) as $dry ) {
		$r    = d5b_ds_variable_upsert( $in, $dry );
		$data = is_wp_error( $r ) ? $r->get_error_data() : array();
		$check( $label . ( $dry ? ' (dry run)' : '' ) . ': refused, 400 bad_references, problem "' . $problem . '", nothing written, no backup taken',
			is_wp_error( $r ) && 'bad_references' === $r->get_error_code() && 400 === ( $data['status'] ?? 0 ) && in_array( $problem, array_column( $data['references'] ?? array(), 'problem' ), true )
			&& json_encode( $vars() ) === $before && count( $GLOBALS['d5b_options']['d5b_ds_backups']['variables'] ?? array() ) === $bk, is_wp_error( $r ) ? $r->get_error_message() : json_encode( $r ) );
	}
	return isset( $r ) && is_wp_error( $r ) ? $r : null;
};
$refused( 'a reference to a variable that does not exist', array( 'type' => 'numbers', 'label' => 'Bad', 'value' => $tok( 'gvid-nosuchvar1' ) . ' * 2' ), 'missing' );
$refused( 'a var(--gvid-…) reference to a variable that does not exist', array( 'type' => 'numbers', 'label' => 'Bad', 'value' => 'calc(var(--gvid-nosuchvar2) / 2)' ), 'missing' );
$refused( 'a reference to an archived variable', array( 'type' => 'strings', 'label' => 'Bad', 'value' => 'x ' . $tok( 'gvid-old0000001' ) ), 'archived' );
$refused( 'a reference to the variable itself', array( 'type' => 'numbers', 'id' => 'gvid-xdtsp8ywr8', 'label' => 'Space', 'value' => $tok( 'gvid-xdtsp8ywr8' ) . ' * 2' ), 'self' );
$refused( 'a number variable using a string variable', array( 'type' => 'numbers', 'label' => 'Bad', 'value' => $tok( 'gvid-n7rq1b1o9w' ) ), 'type' );
$refused( 'a string variable using a number variable', array( 'type' => 'strings', 'label' => 'Bad', 'value' => 'size ' . $tok( 'gvid-xdtsp8ywr8' ) ), 'type' );
$refused( 'a links variable holding a reference (only numbers and strings may)', array( 'type' => 'links', 'label' => 'Bad', 'value' => 'https://example.com/' . $tok( 'gvid-n7rq1b1o9w' ) ), 'not_allowed' );
$refused( 'a gradients variable holding a var(--gvid-…) reference', array( 'type' => 'gradients', 'label' => 'Bad', 'value' => 'linear-gradient(var(--gvid-xdtsp8ywr8), red)' ), 'not_allowed' );
$check( 'a links variable without references is still fine', ! is_wp_error( $lk = d5b_ds_variable_upsert( array( 'type' => 'links', 'label' => 'Home', 'value' => 'https://example.com/' ), true ) ), is_wp_error( $lk ) ? $lk->get_error_message() : '' );
$before = json_encode( $vars() );
$big    = d5b_ds_variable_upsert( array( 'type' => 'strings', 'label' => 'Huge', 'value' => str_repeat( 'a', 1048577 ) ), false );
$check( 'a value over 1 MB is refused (400 value_too_large), nothing written', is_wp_error( $big ) && 'value_too_large' === $big->get_error_code() && 400 === $big->get_error_data()['status'] && json_encode( $vars() ) === $before );
$check( 'a value of exactly 1 MB is accepted (dry run)', ! is_wp_error( d5b_ds_variable_upsert( array( 'type' => 'strings', 'label' => 'Big', 'value' => str_repeat( 'a', 1048576 ) ), true ) ) );
$va = d5b_ds_variable_upsert( array( 'type' => 'numbers', 'label' => 'ZZ A', 'value' => '10px' ), false );
$vb = d5b_ds_variable_upsert( array( 'type' => 'numbers', 'label' => 'ZZ B', 'value' => $tok( $va['id'] ) . ' * 2' ), false );
$check( 'A, then B uses A: accepted', ! is_wp_error( $va ) && ! is_wp_error( $vb ), is_wp_error( $vb ) ? $vb->get_error_message() : '' );
$cyc = $refused( 'then A uses B (a cycle)', array( 'type' => 'numbers', 'id' => $va['id'], 'label' => 'ZZ A', 'value' => $tok( $vb['id'] ) . ' + 1px' ), 'cycle' );
$check( 'the cycle is named, start to end', $cyc && false !== strpos( $cyc->get_error_message(), $va['id'] . ' -> ' . $vb['id'] . ' -> ' . $va['id'] ) );
$vc = d5b_ds_variable_upsert( array( 'type' => 'numbers', 'label' => 'ZZ C', 'value' => $tok( $vb['id'] ) . ' / 2' ), false );
$refused( 'a longer cycle (C uses B uses A, then A uses C)', array( 'type' => 'numbers', 'id' => $va['id'], 'label' => 'ZZ A', 'value' => $tok( $vc['id'] ) ), 'cycle' );
$check( 'a value without references is never blocked by them (A = 12px)', ! is_wp_error( d5b_ds_variable_upsert( array( 'type' => 'numbers', 'id' => $va['id'], 'label' => 'ZZ A', 'value' => '12px' ), false ) ) );
// archive guard
$arch = function ( $id, $dry = false ) { return d5b_ds_variable_archive( array( 'id' => $id ), $dry ); };
$before = json_encode( $vars() ); $bk = count( $GLOBALS['d5b_options']['d5b_ds_backups']['variables'] );
foreach ( array( true, false ) as $dry ) {
	$x    = $arch( 'gvid-k1iggz3omp', $dry );
	$deps = is_wp_error( $x ) ? ( $x->get_error_data()['dependents'] ?? array() ) : array();
	$check( 'archive of a variable another active variable uses' . ( $dry ? ' (dry run)' : '' ) . ': refused, 409 variable_in_use, the user listed by id and label, nothing written',
		is_wp_error( $x ) && 'variable_in_use' === $x->get_error_code() && 409 === ( $x->get_error_data()['status'] ?? 0 ) && array( $f['id'] ) === array_column( $deps, 'id' ) && 'Fluid Heading' === $deps[0]['label']
		&& false !== strpos( $x->get_error_message(), $f['id'] . ' ("Fluid Heading")' ) && json_encode( $vars() ) === $before && count( $GLOBALS['d5b_options']['d5b_ds_backups']['variables'] ) === $bk, is_wp_error( $x ) ? $x->get_error_message() : json_encode( $x ) );
}
$hero = d5b_ds_variable_upsert( array( 'type' => 'numbers', 'label' => 'Hero heading', 'value' => $tok( $f['id'] ) . ' * 1.5' ), false );
$x    = $arch( 'gvid-k1iggz3omp' );
$deps = is_wp_error( $x ) ? $x->get_error_data()['dependents'] : array();
$byid = array_column( $deps, 'direct', 'id' );
$check( 'an indirect user is listed too (Hero heading uses Fluid Heading uses Min Heading)', ! is_wp_error( $hero ) && 2 === count( $deps ) && true === ( $byid[ $f['id'] ] ?? null ) && false === ( $byid[ $hero['id'] ] ?? null ) && false !== strpos( $x->get_error_message(), '(indirectly)' ), is_wp_error( $x ) ? $x->get_error_message() : '' );
$check( 'archive of a string variable used by Copyright: refused', is_wp_error( $y = $arch( 'gvid-q35xsw1fp0' ) ) && 'variable_in_use' === $y->get_error_code() );
$check( 'archive of a variable nobody uses still works', ! is_wp_error( $z = $arch( $vc['id'] ) ) && 'archived' === $z['action'] && 'archived' === $vars()['numbers'][ $vc['id'] ]['status'] );
$check( 'once the users are archived first (Hero, then Fluid), the variable can be archived', ! is_wp_error( $arch( $hero['id'] ) ) && ! is_wp_error( $arch( $f['id'] ) ) && ! is_wp_error( $z = $arch( 'gvid-k1iggz3omp' ) ) && 'archived' === $z['action'] && 'archived' === $vars()['numbers']['gvid-k1iggz3omp']['status'] );
$check( 'an archived user does not block (Fluid is archived, Max Heading can go)', ! is_wp_error( $arch( 'gvid-4az4m3i650' ) ) );
// a restore that would archive a variable an active one (in the restored store) uses
$cur  = $vars();
$back = $cur; unset( $back['numbers'][ $va['id'] ] ); $back['numbers'][ $vb['id'] ]['status'] = 'active'; // B uses A; the backup has no A, so the restore archives A
$GLOBALS['d5b_options']['d5b_ds_backups']['variables'][0]['value'] = $back;
$bk = count( $GLOBALS['d5b_options']['d5b_ds_backups']['variables'] );
$rx = d5b_ds_restore( 'variables', 0 );
$check( 'a restore that would archive a variable an active one uses: refused, 409 variable_in_use, nothing written, no backup', is_wp_error( $rx ) && 'variable_in_use' === $rx->get_error_code() && 409 === ( $rx->get_error_data()['status'] ?? 0 ) && $vars() === $cur && count( $GLOBALS['d5b_options']['d5b_ds_backups']['variables'] ) === $bk, is_wp_error( $rx ) ? $rx->get_error_message() : json_encode( $rx ) );
$GLOBALS['d5b_test_classes']['data'] = 'D5B_Fake_Data';
// Divi's colour and preset savers return no error today; if a later Divi does, it must not become a success
class D5B_Fake_Refuses { static function save_data( $d ) { return new WP_Error( 'nope', 'refused' ); } static function set_global_colors( $d ) { return new WP_Error( 'nope', 'refused' ); } }
$GLOBALS['d5b_test_classes'] = array( 'preset' => 'D5B_Fake_Refuses', 'data' => 'D5B_Fake_Refuses' );
$check( 'a WP_Error from Divi\'s preset or colour saver is passed on', is_wp_error( d5b_ds_write( 'presets', array() ) ) && is_wp_error( d5b_ds_write( 'colors', array() ) ) );
$GLOBALS['d5b_test_classes'] = array( 'preset' => 'D5B_Fake_Preset', 'data' => 'D5B_Fake_Data' );

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
