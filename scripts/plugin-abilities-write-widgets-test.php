<?php
/**
 * Write abilities for widgets (#32, release 2.3.0, Task 4).
 *
 *   php scripts/plugin-abilities-write-widgets-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php: sidebars_widgets, widget_<type> options, the
 * widget factory with core's text and block widgets, capabilities). Set D5B_PLUGIN_DIR to test another copy of
 * plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: settings go through the widget's own update(); a block widget with blocks needs unfiltered_html;
 * a new widget gets type-N with the next number and is placed where asked; update, move and remove keep a history row from
 * which the old map and instance can be rebuilt exactly; a widget holding a secret cannot be updated or removed; an id in no
 * sidebar is not_found; legacy single-instance widgets, unknown sidebars and types, [redacted] text, secret keys and
 * oversized settings are refused; edit_theme_options is needed.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.3.0' );
require __DIR__ . '/plugin-test-stubs.php';
foreach ( array( 'parts', 'switches', 'abilities/categories', 'abilities/register', 'abilities/secrets', 'abilities/audit', 'abilities/scrub', 'abilities/history', 'abilities/read-content', 'abilities/read-site', 'abilities/write-common', 'abilities/write-widgets' ) as $f ) { require $dir . '/inc/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ADMIN = array( 'read', 'manage_options', 'edit_theme_options', 'unfiltered_html' );
const NOUF  = array( 'read', 'edit_theme_options' );
const BLOCK3 = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';

/** A widget whose update() says no, as a widget can (core then keeps the old instance). */
class T_Widget_No extends WP_Widget { public function __construct() { parent::__construct( 'nope', 'Nope' ); } public function update( $n, $o ) { return false; } }
class T_Widget_Plain extends WP_Widget { public function __construct() { parent::__construct( 'plain', 'Plain' ); } }

function map0() {
	return array( 'wp_inactive_widgets' => array( 'text-4' ), 'sidebar-1' => array( 'text-2', 'block-3', 'calendar', 'legacy_thing-2' ), 'footer-1' => array() );
}
function site( array $caps = ADMIN ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( false );
	$GLOBALS['T_user_id'] = 3; $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
	d5b_abilities_set_category( 'widgets', true, false, 'tester' );
	$GLOBALS['wp_registered_sidebars'] = array( 'sidebar-1' => array( 'name' => 'Main' ), 'footer-1' => array( 'name' => 'Footer' ) );
	$GLOBALS['wp_widget_factory']->register( 'T_Widget_No' );
	$GLOBALS['wp_widget_factory']->register( 'T_Widget_Plain' );
	$GLOBALS['T_options']['sidebars_widgets'] = map0() + array( 'array_version' => 3 );
	$GLOBALS['T_options']['widget_text']  = array( 2 => array( 'title' => 'Hi', 'text' => '<b>x</b>', 'filter' => false, 'visual' => false ), 4 => array( 'title' => 'Off', 'text' => 'o', 'filter' => false, 'visual' => false ), '_multiwidget' => 1 );
	$GLOBALS['T_options']['widget_block'] = array( 3 => array( 'content' => BLOCK3 ), '_multiwidget' => 1 );
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
function smap() { $m = $GLOBALS['T_options']['sidebars_widgets']; unset( $m['array_version'] ); return $m; }
/** The map as the row keeps it (written here, not taken from the plugin): a list of { sidebar, widgets }. */
function pairs( array $m ) { $o = array(); foreach ( $m as $k => $v ) { $o[] = array( 'sidebar' => (string) $k, 'widgets' => $v ); } return $o; }
/** A map rebuilt from the row's pairs alone. */
function unpair( array $p ) { $m = array(); foreach ( $p as $x ) { $m[ $x['sidebar'] ] = $x['widgets']; } return $m; }
function opt( $t ) { return $GLOBALS['T_options'][ 'widget_' . $t ] ?? null; }
/** Everything a refusal must leave as it was. */
function snapshot() { return json_encode( array( $GLOBALS['T_options']['sidebars_widgets'] ?? null, opt( 'text' ), opt( 'block' ), opt( 'plain' ), count( hrows() ) ) ); }

echo "registration\n";
site();
$defs = d5b_ability_defs();
$names = array( 'widgets-add', 'widgets-update', 'widgets-move', 'widgets-remove' );
$d = array(); foreach ( $names as $nm ) { $d[ $nm ] = $defs[ 'divi5-builder/' . $nm ] ?? null; }
check( 'four definitions in the widgets category, none risky', ! in_array( null, $d, true ) && array( 'widgets' ) === array_values( array_unique( array_map( function ( $x ) { return $x['category']; }, $d ) ) ) && array() === array_filter( $d, function ( $x ) { return array_key_exists( 'risky', $x ); } ) );
check( 'annotations: none readonly or destructive; update and move idempotent, add and remove not',
	! array_filter( $d, function ( $x ) { return false !== $x['annotations']['readonly'] || false !== $x['annotations']['destructive']; } )
	&& true === $d['widgets-update']['annotations']['idempotent'] && true === $d['widgets-move']['annotations']['idempotent'] && false === $d['widgets-add']['annotations']['idempotent'] && false === $d['widgets-remove']['annotations']['idempotent'] );
check( 'required inputs', array( 'sidebar', 'type', 'settings' ) === $d['widgets-add']['input_schema']['required'] && array( 'id', 'settings' ) === $d['widgets-update']['input_schema']['required'] && array( 'id', 'sidebar', 'position' ) === $d['widgets-move']['input_schema']['required'] && array( 'id' ) === $d['widgets-remove']['input_schema']['required'] );
check( 'settings on add and update results is raw third-party data (raw_paths)', array( 'settings' ) === ( $d['widgets-add']['raw_paths'] ?? null ) && array( 'settings' ) === ( $d['widgets-update']['raw_paths'] ?? null ) && array( 'sidebars.*.widgets.*.settings' ) === ( $defs['divi5-builder/widgets-list']['raw_paths'] ?? null ) );
check( 'd5b_ww_sidebar_ok, d5b_ww_type and d5b_ww_instance_in exist as the brief names them', true === d5b_ww_sidebar_ok( 'sidebar-1' ) && true === d5b_ww_sidebar_ok( 'wp_inactive_widgets' ) && 'invalid_input' === code( d5b_ww_sidebar_ok( 'nope' ) ) && d5b_ww_type( 'text' ) instanceof WP_Widget_Text && null === d5b_ww_type( 'calendar' ) && is_array( d5b_ww_instance_in( d5b_ww_type( 'text' ), array( 'title' => 'a' ), null ) ) );

echo "add\n";
site( NOUF );
$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'text', 'settings' => array( 'title' => '<i>Title</i>', 'text' => 'a<script>alert(1)</script>b' ), 'position' => 1 ) );
$row = hlast();
check( 'a text widget: the widget\'s own update() ran (script tag stripped, title as text), id text-5, placed at position 1',
	is_array( $r ) && 'text-5' === $r['id'] && 'sidebar-1' === $r['sidebar'] && 1 === $r['position'] && 'aalert(1)b' === $r['settings']['text'] && 'Title' === $r['settings']['title'] && false === $r['settings']['filter']
	&& array( 'text-2', 'text-5', 'block-3', 'calendar', 'legacy_thing-2' ) === smap()['sidebar-1'] && 'aalert(1)b' === opt( 'text' )[5]['text'] && 1 === opt( 'text' )['_multiwidget'] && 3 === $GLOBALS['T_options']['sidebars_widgets']['array_version'], $r );
check( 'markup the widget removed (no unfiltered_html) is named in warnings', is_array( $r ) && in_array( 'WordPress removed markup you may not save (no unfiltered_html); the stored settings are in settings.', $r['warnings'] ?? array(), true ), $r['warnings'] ?? null );
check( 'the row: widget, the number, create, before absent with the whole old map as sidebar/widgets pairs, after the place and instance, summary',
	1 === count( hrows() ) && 'widget' === $row['object_type'] && 5 === (int) $row['object_id'] && 'create' === $row['action'] && 'divi5-builder/widgets-add' === $row['ability']
	&& array( 'absent' => true, 'sidebars_widgets' => pairs( map0() ) ) === $row['before'] && map0() === unpair( $row['before']['sidebars_widgets'] ) && 'text-5' === $row['after']['id'] &&'sidebar-1' === $row['after']['sidebar'] && 1 === $row['after']['position'] && opt( 'text' )[5] === $row['after']['instance'] && 'Added widget text-5 to sidebar-1' === $row['summary'], $row );
$r = call( 'widgets-add', array( 'sidebar' => 'footer-1', 'type' => 'text', 'settings' => array( 'title' => 'F' ) ) );
check( 'no position: at the end; the number goes on (text-6)', is_array( $r ) && 'text-6' === $r['id'] && array( 'text-6' ) === smap()['footer-1'] && 0 === $r['position'] );
check( '... a title core strips tags from and nothing else removed: no warning', is_array( $r ) && ! isset( $r['warnings'] ) );
$r = call( 'widgets-add', array( 'sidebar' => 'footer-1', 'type' => 'text', 'settings' => array(), 'position' => 99 ) );
check( 'a position past the end puts it last; {} settings take the defaults', is_array( $r ) && 'text-7' === $r['id'] && array( 'text-6', 'text-7' ) === smap()['footer-1'] && 1 === $r['position'] && '' === $r['settings']['title'] );
$r = call( 'widgets-add', array( 'sidebar' => 'wp_inactive_widgets', 'type' => 'plain', 'settings' => array( 'a' => 1 ), 'position' => 0 ) );
check( 'a type with nothing stored starts at 2; wp_inactive_widgets is a sidebar too', is_array( $r ) && 'plain-2' === $r['id'] && array( 'plain-2', 'text-4' ) === smap()['wp_inactive_widgets'] && array( 2 => array( 'a' => 1 ), '_multiwidget' => 1 ) === opt( 'plain' ) );
site();
$GLOBALS['T_options']['sidebars_widgets']['footer-1'] = array( 'plain-8' );
$r = call( 'widgets-add', array( 'sidebar' => 'footer-1', 'type' => 'plain', 'settings' => array() ) );
check( 'a number already placed (without stored settings) is not reused', is_array( $r ) && 'plain-9' === $r['id'] );

site();
$s0 = snapshot();
foreach ( array( 'nope-sidebar', 'orphaned_widgets_1', 'Sidebar-1', '' ) as $sb ) {
	$r = call( 'widgets-add', array( 'sidebar' => $sb, 'type' => 'text', 'settings' => array() ) );
	check( 'an unknown sidebar is refused: ' . json_encode( $sb ), 'invalid_input' === code( $r ) && $s0 === snapshot(), array( code( $r ), msg( $r ) ) );
}
foreach ( array( 'calendar', 'Text', 'legacy_thing', '' ) as $ty ) {
	$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => $ty, 'settings' => array() ) );
	check( 'an unknown or legacy type is refused: ' . json_encode( $ty ), 'invalid_input' === code( $r ) && $s0 === snapshot(), array( code( $r ), msg( $r ) ) );
}
$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'nope', 'settings' => array( 'a' => 1 ) ) );
check( 'a widget whose update() says no is invalid_input; nothing written', 'invalid_input' === code( $r ) && $s0 === snapshot() );
foreach ( array( 'a list' => array( 1, 2 ), 'text' => 'x', 'invalid UTF-8' => array( 't' => "\xFF" ), 'over 256 KB' => array( 'text' => str_repeat( 'a', 262200 ) ), 'a secret key' => array( 'title' => 't', 'api_key' => 'abc' ), 'a nested secret key' => array( 'o' => array( 'smtp_password' => 'x' ) ) ) as $what => $set ) {
	$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'text', 'settings' => $set ) );
	check( "settings that are $what are refused", 'invalid_input' === code( $r ) && $s0 === snapshot(), array( code( $r ), msg( $r ) ) );
}
$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'text', 'settings' => array( 'title' => 't', 'text' => 'see [redacted] here' ) ) );
check( 'settings holding [redacted] are masked_read', 'masked_read' === code( $r ) && $s0 === snapshot() );
foreach ( array( array( 'position' => -1 ), array( 'position' => 'x' ), array( 'position' => 1.5 ), array( 'extra' => 1 ), array( 'settings' => null ) ) as $bad ) {
	$in = array_merge( array( 'sidebar' => 'sidebar-1', 'type' => 'text', 'settings' => array() ), $bad );
	if ( array_key_exists( 'settings', $bad ) ) { unset( $in['settings'] ); }
	$r = call( 'widgets-add', $in );
	check( 'bad input refused: ' . json_encode( $bad ), 'invalid_input' === code( $r ) && $s0 === snapshot(), array( code( $r ), msg( $r ) ) );
}
$big = array( 'text' => str_repeat( 'b', 260000 ) );
$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'text', 'settings' => $big ) );
check( 'just under 256 KB is accepted', is_array( $r ) && 260000 === strlen( opt( 'text' )[ (int) substr( $r['id'], 5 ) ]['text'] ) );

echo "block widgets\n";
site( NOUF );
$s0 = snapshot();
foreach ( array( '<!-- wp:divi/section {"a":1} /-->', '<p onclick="steal()">Hi</p>', '<p>a</p><script>x()</script>', '<!-- wp:paragraph --><p>Tom & <iframe src="x"></iframe></p><!-- /wp:paragraph -->' ) as $c ) {
	$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'block', 'settings' => array( 'content' => $c ) ) );
	check( 'without unfiltered_html, Divi blocks or HTML kses would change are refused: ' . json_encode( substr( $c, 0, 30 ) ), 'needs_unfiltered_html' === code( $r ) && $s0 === snapshot(), array( code( $r ), msg( $r ) ) );
}
$r = call( 'widgets-update', array( 'id' => 'block-3', 'settings' => array( 'content' => BLOCK3 . '<p onclick="x">y</p>' ) ) );
check( '... and the same for an update', 'needs_unfiltered_html' === code( $r ) && $s0 === snapshot() );
$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'block', 'settings' => array( 'content' => '<p>Plain <b>HTML</b></p>' ) ) );
check( 'without unfiltered_html, HTML kses keeps is fine', is_array( $r ) && 'block-4' === $r['id'] && '<p>Plain <b>HTML</b></p>' === opt( 'block' )[4]['content'] && ! isset( $r['warnings'] ) );
$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'block', 'settings' => array( 'content' => BLOCK3 ) ) );
check( 'without unfiltered_html, a simple paragraph block (it survives kses) is accepted as given', is_array( $r ) && 'block-5' === $r['id'] && BLOCK3 === opt( 'block' )[5]['content'], array( code( $r ), msg( $r ) ) );
$tj = '<!-- wp:paragraph --><p>Tom & Jerry</p><!-- /wp:paragraph -->';
$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'block', 'settings' => array( 'content' => $tj ) ) );
check( 'without unfiltered_html, "Tom & Jerry" is accepted (kses only normalises the &, as core: &amp;), no warning', is_array( $r ) && 'block-6' === $r['id'] && str_replace( '&', '&amp;', $tj ) === opt( 'block' )[6]['content'] && ! isset( $r['warnings'] ), array( code( $r ), msg( $r ), $r ) );
$r = call( 'widgets-add', array( 'sidebar' => 'footer-1', 'type' => 'text', 'settings' => array( 'title' => '<b>T</b>', 'text' => 'Tom & Jerry <b>ok</b>' ) ) );
check( 'a text widget: a title core strips tags from and a bare & kses only normalises are no warning', is_array( $r ) && ! isset( $r['warnings'] ) && 'Tom &amp; Jerry <b>ok</b>' === $r['settings']['text'], $r );
$r = call( 'widgets-update', array( 'id' => 'text-2', 'settings' => array( 'title' => 'Hi', 'text' => '<p onmouseover="x()">hover</p>' ) ) );
check( 'an update whose markup the widget removed is named in warnings too', is_array( $r ) && '<p>hover</p>' === opt( 'text' )[2]['text'] && in_array( 'WordPress removed markup you may not save (no unfiltered_html); the stored settings are in settings.', $r['warnings'] ?? array(), true ), $r );
site( ADMIN );
$r = call( 'widgets-add', array( 'sidebar' => 'footer-1', 'type' => 'text', 'settings' => array( 'text' => 'a<script>x()</script>' ) ) );
check( 'with unfiltered_html a text widget keeps its script, no warning', is_array( $r ) && 'a<script>x()</script>' === $r['settings']['text'] && ! isset( $r['warnings'] ), $r );
site( NOUF );
$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'block', 'settings' => array( 'title' => 'no content' ) ) );
check( 'a block widget without settings.content text is invalid_input', 'invalid_input' === code( $r ) );
site( ADMIN );
$c = BLOCK3 . '<!-- wp:html --><div onclick="go()"><script>x()</script></div><!-- /wp:html -->';
$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'block', 'settings' => array( 'content' => $c ), 'position' => 0 ) );
check( 'with unfiltered_html the blocks are stored exactly as given', is_array( $r ) && 'block-4' === $r['id'] && $c === opt( 'block' )[4]['content'] && $c === $r['settings']['content'] && 'block-4' === smap()['sidebar-1'][0], $r );

echo "update\n";
site();
$r = call( 'widgets-update', array( 'id' => 'text-2', 'settings' => array( 'title' => 'New', 'text' => 'body' ) ) );
$row = hlast();
check( 'update stores the widget\'s instance; the row keeps sidebar, position and the whole old instance',
	is_array( $r ) && true === $r['changed'] && 'New' === opt( 'text' )[2]['title'] && 'body' === $r['settings']['text'] && 'sidebar-1' === $r['sidebar'] && 0 === $r['position']
	&& 'update' === $row['action'] && 'widget' === $row['object_type'] && 2 === (int) $row['object_id'] && 'Updated widget text-2' === $row['summary']
	&& array( 'id' => 'text-2', 'sidebar' => 'sidebar-1', 'position' => 0, 'instance' => array( 'title' => 'Hi', 'text' => '<b>x</b>', 'filter' => false, 'visual' => false ) ) === $row['before'] && opt( 'text' )[2] === $row['after']['instance'], $row );
$n0 = count( hrows() );
$r = call( 'widgets-update', array( 'id' => 'text-2', 'settings' => array( 'title' => 'New', 'text' => 'body' ) ) );
check( 'the same settings again: changed false, no row', is_array( $r ) && false === $r['changed'] && $n0 === count( hrows() ) );
$r = call( 'widgets-update', array( 'id' => 'text-4', 'settings' => array( 'title' => 'Inactive too' ) ) );
check( 'a widget in wp_inactive_widgets can be updated', is_array( $r ) && 'wp_inactive_widgets' === $r['sidebar'] && 'Inactive too' === opt( 'text' )[4]['title'] );
$s0 = snapshot();
$r = call( 'widgets-update', array( 'id' => 'text-2', 'settings' => array( 'text' => 'x [redacted]' ) ) );
check( 'update with [redacted] text is masked_read', 'masked_read' === code( $r ) && $s0 === snapshot() );
$GLOBALS['T_options']['widget_text'][2]['text'] = 'kept [redacted] by the site';
$r = call( 'widgets-update', array( 'id' => 'text-2', 'settings' => array( 'title' => 'T2', 'text' => 'kept [redacted] by the site' ) ) );
check( '... unless the stored settings hold it too', is_array( $r ) && 'T2' === opt( 'text' )[2]['title'] );
$s0 = snapshot();
check( 'update with a secret key is refused', 'invalid_input' === code( call( 'widgets-update', array( 'id' => 'text-2', 'settings' => array( 'auth_token' => 'x' ) ) ) ) && $s0 === snapshot() );
check( 'update without settings is refused', 'invalid_input' === code( call( 'widgets-update', array( 'id' => 'text-2' ) ) ) && $s0 === snapshot() );

echo "move\n";
site();
$r = call( 'widgets-move', array( 'id' => 'text-2', 'sidebar' => 'wp_inactive_widgets', 'position' => 5 ) );
$row = hlast();
check( 'move to wp_inactive_widgets: placed last there, gone from sidebar-1; the row keeps the whole old map',
	is_array( $r ) && true === $r['changed'] && 'wp_inactive_widgets' === $r['sidebar'] && 1 === $r['position'] && array( 'text-4', 'text-2' ) === smap()['wp_inactive_widgets'] && array( 'block-3', 'calendar', 'legacy_thing-2' ) === smap()['sidebar-1']
	&& 'move' === $row['action'] && 2 === (int) $row['object_id'] && 'Moved widget text-2 to wp_inactive_widgets' === $row['summary']
	&& array( 'id' => 'text-2', 'sidebar' => 'sidebar-1', 'position' => 0, 'sidebars_widgets' => pairs( map0() ) ) === $row['before'] && map0() === unpair( $row['before']['sidebars_widgets'] ) && array( 'sidebar' => 'wp_inactive_widgets', 'position' => 1 ) === $row['after']
	&& isset( opt( 'text' )[2] ), array( $r, $row ) );
$r = call( 'widgets-move', array( 'id' => 'text-2', 'sidebar' => 'sidebar-1', 'position' => 0 ) );
check( '... and back to position 0: the map is the original again', is_array( $r ) && map0() === smap() && 'Moved widget text-2 to sidebar-1' === hlast()['summary'], smap() );
$r = call( 'widgets-move', array( 'id' => 'block-3', 'sidebar' => 'sidebar-1', 'position' => 0 ) );
check( 'a move within the same sidebar reorders it', is_array( $r ) && array( 'block-3', 'text-2', 'calendar', 'legacy_thing-2' ) === smap()['sidebar-1'] && 0 === $r['position'] && 'sidebar-1' === hlast()['before']['sidebar'] && 1 === hlast()['before']['position'] );
$r = call( 'widgets-move', array( 'id' => 'block-3', 'sidebar' => 'sidebar-1', 'position' => 99 ) );
check( 'past the end: last', is_array( $r ) && array( 'text-2', 'calendar', 'legacy_thing-2', 'block-3' ) === smap()['sidebar-1'] && 3 === $r['position'] );
$n0 = count( hrows() );
$r = call( 'widgets-move', array( 'id' => 'block-3', 'sidebar' => 'sidebar-1', 'position' => 3 ) );
check( 'to where it already is: changed false, no row', is_array( $r ) && false === $r['changed'] && $n0 === count( hrows() ) );
$r = call( 'widgets-move', array( 'id' => 'block-3', 'sidebar' => 'footer-1', 'position' => 0 ) );
check( 'into an empty sidebar', is_array( $r ) && array( 'block-3' ) === smap()['footer-1'] );
$s0 = snapshot();
check( 'move to an unknown sidebar is refused', 'invalid_input' === code( call( 'widgets-move', array( 'id' => 'text-2', 'sidebar' => 'orphaned_widgets_1', 'position' => 0 ) ) ) && $s0 === snapshot() );
check( 'move without a position is refused', 'invalid_input' === code( call( 'widgets-move', array( 'id' => 'text-2', 'sidebar' => 'footer-1' ) ) ) && $s0 === snapshot() );

echo "remove\n";
site();
$block100 = '<!-- wp:html -->' . str_repeat( '<p>row</p>', 10240 ) . '<!-- /wp:html -->'; // over 100 KB
$GLOBALS['T_options']['widget_block'][3] = array( 'content' => $block100, 'extra' => array( 'x' => 1 ) );
$map_before = smap(); $inst_before = opt( 'block' )[3]; $opt_before = opt( 'block' );
$r = call( 'widgets-remove', array( 'id' => 'block-3' ) );
$row = hlast();
check( 'remove takes it out of its sidebar and deletes its settings', is_array( $r ) && true === $r['removed'] && 'sidebar-1' === $r['sidebar'] && 1 === $r['position'] && array( 'text-2', 'calendar', 'legacy_thing-2' ) === smap()['sidebar-1'] && ! isset( opt( 'block' )[3] ) && 1 === opt( 'block' )['_multiwidget'] );
check( 'the row keeps sidebar, position, the whole instance (100 KB block) and the whole map; delete, summary',
	'delete' === $row['action'] && 3 === (int) $row['object_id'] && 'Removed widget block-3 from sidebar-1' === $row['summary'] && 'block-3' === $row['before']['id'] && 'sidebar-1' === $row['before']['sidebar'] && 1 === $row['before']['position']
	&& $inst_before === $row['before']['instance'] && strlen( $block100 ) > 102400 && pairs( $map_before ) === $row['before']['sidebars_widgets'] && array( 'absent' => true ) === $row['after'], array_keys( $row['before'] ?? array() ) );
// Rebuild from the row alone: the map from its pairs, the instance into widget_block under the row's number.
$o = opt( 'block' ); $o[ (int) $row['object_id'] ] = $row['before']['instance']; ksort( $o );
$want = $opt_before; ksort( $want );
check( 'rebuilt from the row: the same map and the same instance', unpair( $row['before']['sidebars_widgets'] ) === $map_before && $want === $o );
site();
$s0 = snapshot();
foreach ( array( 'text-9', 'block-77' ) as $id ) {
	check( "an id in no sidebar is not_found (update, move, remove): $id", 'not_found' === code( call( 'widgets-update', array( 'id' => $id, 'settings' => array() ) ) ) && 'not_found' === code( call( 'widgets-move', array( 'id' => $id, 'sidebar' => 'footer-1', 'position' => 0 ) ) ) && 'not_found' === code( call( 'widgets-remove', array( 'id' => $id ) ) ) && $s0 === snapshot() );
}
$GLOBALS['T_options']['widget_text'][9] = array( 'title' => 'stored only' );
$s0 = snapshot();
check( 'settings stored but placed nowhere: still not_found', 'not_found' === code( call( 'widgets-remove', array( 'id' => 'text-9' ) ) ) && $s0 === snapshot() );

// fix wave M4: every placement goes; ids are matched exactly
site();
$GLOBALS['T_options']['sidebars_widgets']['footer-1'] = array( 'block-3', 'text-2' ); // a broken layout: text-2 in two sidebars
$map_before = smap();
$r = call( 'widgets-remove', array( 'id' => 'text-2' ) );
$row = hlast();
check( 'a widget placed in two sidebars: remove takes it out of BOTH; placements lists both', is_array( $r ) && array( 'block-3', 'calendar', 'legacy_thing-2' ) === smap()['sidebar-1'] && array( 'block-3' ) === smap()['footer-1'] && ! isset( opt( 'text' )[2] )
	&& array( array( 'sidebar' => 'sidebar-1', 'position' => 0 ), array( 'sidebar' => 'footer-1', 'position' => 1 ) ) === $r['placements'] && 'sidebar-1' === $r['sidebar'], array( $r, smap() ) );
check( '... the row records every placement and the whole map before', array( array( 'sidebar' => 'sidebar-1', 'position' => 0 ), array( 'sidebar' => 'footer-1', 'position' => 1 ) ) === $row['before']['placements'] && unpair( $row['before']['sidebars_widgets'] ) === $map_before, $row['before'] );
site();
$GLOBALS['T_options']['sidebars_widgets']['footer-1'] = array( 'text-02' );
$s0 = snapshot();
foreach ( array( 'widgets-remove' => array( 'id' => 'text-02' ), 'widgets-update' => array( 'id' => 'text-02', 'settings' => array( 'title' => 'x' ) ), 'widgets-move' => array( 'id' => 'text-02', 'sidebar' => 'sidebar-1', 'position' => 0 ) ) as $ab => $in ) {
	$r = call( $ab, $in );
	check( "$ab: text-02 is not text-2 (a leading zero is invalid_input); text-2's settings and placements are untouched", 'invalid_input' === code( $r ) && $s0 === snapshot() && isset( opt( 'text' )[2] ), array( code( $r ), msg( $r ) ) );
}

echo "legacy single-instance widgets\n";
site();
$s0 = snapshot();
foreach ( array( 'calendar', 'legacy_thing-2', 'text-0', 'text-', '-2' ) as $id ) {
	$a = call( 'widgets-update', array( 'id' => $id, 'settings' => array( 'a' => 1 ) ) );
	$b = call( 'widgets-move', array( 'id' => $id, 'sidebar' => 'footer-1', 'position' => 0 ) );
	$c = call( 'widgets-remove', array( 'id' => $id ) );
	check( "a legacy or malformed id is refused on update, move and remove: $id", 'invalid_input' === code( $a ) && 'invalid_input' === code( $b ) && 'invalid_input' === code( $c ) && $s0 === snapshot(), array( code( $a ), code( $b ), code( $c ) ) );
}

echo "a widget whose settings hold a secret\n";
site();
$GLOBALS['T_options']['widget_text'][6] = array( 'title' => 'x', 'api_key' => 'sk-live-xyz' );
$GLOBALS['T_options']['widget_text'][8] = array( 'title' => 'y', 'deep' => (object) array( 'auth_token' => 'zzz' ) );
$GLOBALS['T_options']['sidebars_widgets']['footer-1'] = array( 'text-6', 'text-8' );
$s0 = snapshot();
foreach ( array( 'text-6', 'text-8' ) as $id ) {
	$a = call( 'widgets-update', array( 'id' => $id, 'settings' => array( 'title' => 'plain' ) ) );
	$c = call( 'widgets-remove', array( 'id' => $id ) );
	check( "$id cannot be updated or removed (not_allowed); nothing changes", 'not_allowed' === code( $a ) && 'not_allowed' === code( $c ) && $s0 === snapshot() && 'sk-live-xyz' === opt( 'text' )[6]['api_key'], array( code( $a ), code( $c ) ) );
}
check( 'it can still be moved (the instance is not touched)', is_array( call( 'widgets-move', array( 'id' => 'text-6', 'sidebar' => 'wp_inactive_widgets', 'position' => 0 ) ) ) && 'sk-live-xyz' === opt( 'text' )[6]['api_key'] );

echo "sidebar ids the history's key filter would drop as keys\n";
site();
check( 'precondition: footer-token is a secret-looking name to the key filter', d5b_is_secret_key( 'footer-token' ) );
$GLOBALS['wp_registered_sidebars']['footer-token'] = array( 'name' => 'Footer token' );
$GLOBALS['T_options']['sidebars_widgets']['footer-token'] = array( 'text-4' );
unset( $GLOBALS['T_options']['sidebars_widgets']['wp_inactive_widgets'] );
$GLOBALS['T_options']['sidebars_widgets']['orphaned_widgets_1'] = array( 'text-8' );
$GLOBALS['T_options']['widget_text'][8] = array( 'title' => 'orphan', 'text' => 'o', 'filter' => false, 'visual' => false );
$keeps = function ( $row ) { $s = array_column( $row['before']['sidebars_widgets'] ?? array(), 'sidebar' ); return in_array( 'footer-token', $s, true ) && in_array( 'orphaned_widgets_1', $s, true ); };
$m1 = smap();
$a = call( 'widgets-add', array( 'sidebar' => 'footer-token', 'type' => 'text', 'settings' => array( 'title' => 'n' ) ) );
check( 'add into footer-token works; the row keeps both sidebars and rebuilds the map', is_array( $a ) && 'footer-token' === $a['sidebar'] && $keeps( hlast() ) && $m1 === unpair( hlast()['before']['sidebars_widgets'] ), array( $a, hlast()['before'] ?? null ) );
$m2 = smap();
$b = call( 'widgets-move', array( 'id' => 'text-2', 'sidebar' => 'footer-token', 'position' => 0 ) );
check( 'move into footer-token works; the row keeps both', is_array( $b ) && 'text-2' === smap()['footer-token'][0] && $keeps( hlast() ) && $m2 === unpair( hlast()['before']['sidebars_widgets'] ) );
$c = call( 'widgets-update', array( 'id' => 'text-8', 'settings' => array( 'title' => 'still orphan' ) ) );
check( 'update of a widget in orphaned_widgets_1 works', is_array( $c ) && 'orphaned_widgets_1' === $c['sidebar'] && 'still orphan' === opt( 'text' )[8]['title'] );
$m3 = smap();
$d = call( 'widgets-remove', array( 'id' => 'text-8' ) );
check( 'remove from orphaned_widgets_1 works; the row keeps both and the instance', is_array( $d ) && array() === smap()['orphaned_widgets_1'] && $keeps( hlast() ) && $m3 === unpair( hlast()['before']['sidebars_widgets'] ) && 'still orphan' === hlast()['before']['instance']['title'] );
$e = call( 'widgets-remove', array( 'id' => 'text-4' ) );
check( 'remove from footer-token works', is_array( $e ) && 'footer-token' === $e['sidebar'] && ! in_array( 'text-4', smap()['footer-token'], true ) && $keeps( hlast() ) );

echo "core's widget_update_callback filter\n";
site();
$seen = null;
add_filter( 'widget_update_callback', function ( $inst, $new, $old, $w ) use ( &$seen ) { $seen = array( $new, $old, $w->id_base ); if ( is_array( $inst ) ) { $inst['visibility'] = 'logged-in'; } return $inst; } );
$r = call( 'widgets-update', array( 'id' => 'text-2', 'settings' => array( 'title' => 'F', 'text' => 't' ) ) );
check( 'the filter gets the instance, the new settings, the old instance and the widget, and what it returns is stored', is_array( $r ) && 'logged-in' === opt( 'text' )[2]['visibility'] && 'logged-in' === $r['settings']['visibility'] && array( 'title' => 'F', 'text' => 't' ) === $seen[0] && 'Hi' === $seen[1]['title'] && 'text' === $seen[2], array( $r, $seen ) );
$r = call( 'widgets-add', array( 'sidebar' => 'footer-1', 'type' => 'plain', 'settings' => array( 'a' => 1 ) ) );
check( '... on add too (old instance empty)', is_array( $r ) && 'logged-in' === $r['settings']['visibility'] && array() === $seen[1] );
site();
add_filter( 'widget_update_callback', function ( $inst ) { return false; } );
$s0 = snapshot();
$r = call( 'widgets-update', array( 'id' => 'text-2', 'settings' => array( 'title' => 'X' ) ) );
$a = call( 'widgets-add', array( 'sidebar' => 'footer-1', 'type' => 'text', 'settings' => array( 'title' => 'X' ) ) );
check( 'a filter returning false refuses (invalid_input, a plugin refused); nothing written', 'invalid_input' === code( $r ) && 'invalid_input' === code( $a ) && false !== stripos( msg( $r ), 'plugin refused' ) && $s0 === snapshot(), array( code( $r ), msg( $r ) ) );

echo "write failures and read-back\n";
site();
$GLOBALS['T_frozen_options'] = array( 'widget_text' );
$s0 = snapshot();
$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'text', 'settings' => array( 'title' => 'a' ) ) );
$u = call( 'widgets-update', array( 'id' => 'text-2', 'settings' => array( 'title' => 'b' ) ) );
check( 'settings WordPress does not store: write_failed, nothing placed, no row', 'write_failed' === code( $r ) && 'write_failed' === code( $u ) && $s0 === snapshot() );
$GLOBALS['T_frozen_options'] = array( 'sidebars_widgets' );
$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'block', 'settings' => array( 'content' => 'x' ) ) );
$m = call( 'widgets-move', array( 'id' => 'text-2', 'sidebar' => 'footer-1', 'position' => 0 ) );
$x = call( 'widgets-remove', array( 'id' => 'text-2' ) );
check( 'a layout WordPress does not store: write_failed, the new settings taken out again, no row', 'write_failed' === code( $r ) && 'write_failed' === code( $m ) && 'write_failed' === code( $x ) && ! isset( opt( 'block' )[4] ) && isset( opt( 'text' )[2] ) && 0 === count( hrows() ), array( code( $r ), code( $m ), code( $x ) ) );
site();
add_filter( 'sidebars_widgets', function ( $s ) { if ( isset( $s['sidebar-1'] ) && in_array( 'text-5', $s['sidebar-1'], true ) ) { $s['sidebar-1'] = array_values( array_diff( $s['sidebar-1'], array( 'text-5' ) ) ); $s['sidebar-1'][] = 'text-5'; } return $s; } );
$r = call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'text', 'settings' => array( 'title' => 'a' ), 'position' => 0 ) );
check( 'a layout another plugin changes on read: the stored place is returned with a warning', is_array( $r ) && 4 === $r['position'] && ! empty( $r['warnings'] ), $r );

echo "permissions\n";
site( array( 'read', 'manage_options', 'unfiltered_html' ) );
$s0 = snapshot();
check( 'without edit_theme_options all four are not allowed, in the permission callback and in the handler',
	'FORBIDDEN' === call( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'text', 'settings' => array() ) ) && 'FORBIDDEN' === call( 'widgets-update', array( 'id' => 'text-2', 'settings' => array() ) ) && 'FORBIDDEN' === call( 'widgets-move', array( 'id' => 'text-2', 'sidebar' => 'footer-1', 'position' => 0 ) ) && 'FORBIDDEN' === call( 'widgets-remove', array( 'id' => 'text-2' ) )
	&& 'forbidden' === code( handler( 'widgets-add', array( 'sidebar' => 'sidebar-1', 'type' => 'text', 'settings' => array() ) ) ) && 'forbidden' === code( handler( 'widgets-remove', array( 'id' => 'text-2' ) ) ) && $s0 === snapshot() );
site();
d5b_abilities_set_category( 'widgets', false, false, 'tester' );
check( 'with the Widgets category off the abilities are not run', ! is_array( call( 'widgets-remove', array( 'id' => 'text-2' ) ) ) && isset( opt( 'text' )[2] ) );

echo "source\n";
$src = file_get_contents( $dir . '/inc/abilities/write-widgets.php' );
check( 'write-widgets.php registers no hooks, routes or abilities itself', ! preg_match( '/\badd_action\s*\(|\badd_filter\s*\(|register_rest_route\s*\(|\bwp_register_ability\s*\(/', $src ) );
check( 'the loader lists write-widgets.php after write-appearance.php', 1 === preg_match( "#'inc/abilities/write-appearance\.php',\s+'inc/abilities/write-widgets\.php',#", file_get_contents( $dir . '/divi5-builder.php' ) ) );

echo "\n$n checks, $failed failed\n";
exit( $failed ? 1 : 0 );
