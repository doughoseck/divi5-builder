<?php
/**
 * Write abilities for navigation menus (#32, release 2.2.0, Task 6).
 *
 *   php scripts/plugin-abilities-write-menus-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php: wp_create_nav_menu, wp_update_nav_menu_item,
 * wp_get_nav_menu_items, wp_delete_nav_menu, the nav_menu_locations theme mod behave as core 6.4 where the plugin depends on
 * it; notably wp_update_nav_menu_item resets every field it is not given). Set D5B_PLUGIN_DIR to test another copy of
 * plugin/divi5-builder (mutation testing).
 *
 * The promises under test: every ability needs edit_theme_options; items round-trip through add, update, remove (trash)
 * and restore (published, in its menu, parent and position); a page item needs Pages on; reorder takes the whole menu or
 * nothing; assign-location sets and clears; the menu delete is two-step, resolves the menu to its id before fingerprinting,
 * writes its history row (menu, every item, locations) first and deletes nothing when it cannot.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.2.0' );
require __DIR__ . '/plugin-test-stubs.php';
foreach ( array( 'parts', 'switches', 'abilities/categories', 'abilities/register', 'abilities/secrets', 'abilities/audit', 'abilities/scrub', 'abilities/history', 'abilities/confirm', 'abilities/read-content', 'abilities/read-structure', 'abilities/write-common', 'abilities/write-menus' ) as $f ) { require $dir . '/inc/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ADMIN = array( 'read', 'edit_theme_options', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'read_private_posts' );
const EDITOR = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'read_private_posts', 'manage_categories' );

function site( array $caps = ADMIN, int $uid = 3 ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( false );
	$GLOBALS['T_user_id'] = $uid; $GLOBALS['T_users'] = array( 3 => (object) array( 'user_login' => 'ann' ), 4 => (object) array( 'user_login' => 'cat' ) );
	$GLOBALS['T_registered_menus'] = array( 'primary' => 'Primary', 'footer' => 'Footer' );
	d5b_ability_audit_current_state( 0 );
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function handler( $name, $in = array() ) { return call_user_func( d5b_ability_defs()[ 'divi5-builder/' . $name ]['handler'], $in ); }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function msg( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
function reason( $r ) { return is_wp_error( $r ) ? ( $r->get_error_data()['reason'] ?? null ) : null; }
function hrows() { global $wpdb; return $wpdb->tables[ d5b_history_table() ] ?? array(); }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function post( $id ) { return $GLOBALS['T_posts'][ (int) $id ] ?? null; }
function pmeta( $id, $k ) { return get_post_meta( $id, $k, true ); }
function in_menu( $item ) { return array_map( 'intval', $GLOBALS['T_object_terms'][ (int) $item ]['nav_menu'] ?? array() ); }
/** The published items of a menu as menus-items lists them: id => array( parent, order ). */
function order_of( $menu ) { $o = array(); foreach ( wp_get_nav_menu_items( $menu ) as $i ) { $o[] = array( (int) $i->ID, (int) $i->menu_item_parent ); } return $o; }
function break_history() { unset( $GLOBALS['wpdb']->tables['wp_d5b_history'] ); $GLOBALS['T_dbdelta_fail'] = true; set_transient( 'd5b_audit_install_failed', 1, 3600 ); }
function mend_history() { $GLOBALS['T_dbdelta_fail'] = false; $GLOBALS['wpdb']->tables['wp_d5b_history'] = array(); }
function codes_issued() { return array_values( array_filter( array_keys( $GLOBALS['T_transients'] ), function ( $k ) { return 0 === strpos( $k, 'd5b_confirm_' ); } ) ); }

echo "registration\n";
$defs = d5b_ability_defs();
$want = array(
	'menus-create' => array( false, false, false ), 'menus-item-add' => array( false, false, false ), 'menus-item-update' => array( false, false, true ),
	'menus-item-remove' => array( false, true, false ), 'menus-item-restore' => array( false, false, false ), 'menus-reorder' => array( false, false, true ),
	'menus-assign-location' => array( false, false, true ), 'menus-delete-preview' => array( true, false, false ), 'menus-delete' => array( false, true, false ),
);
$bad = array();
foreach ( $want as $x => $w ) {
	$d = $defs[ 'divi5-builder/' . $x ] ?? null;
	if ( null === $d ) { $bad[] = "$x missing"; continue; }
	$an = $d['annotations'] ?? array();
	if ( 'menus' !== $d['category'] || array( $an['readonly'] ?? null, $an['destructive'] ?? null, $an['idempotent'] ?? null ) !== $w ) { $bad[] = "$x " . json_encode( array( $d['category'], $an ) ); }
}
check( 'the 9 menu writes are defined in the menus category with the agreed annotations (preview readonly; remove and delete destructive; assign-location idempotent)', array() === $bad, $bad );
check( 'menus-delete requires menu and confirmation; menus-reorder requires menu and items', array( 'menu', 'confirmation' ) === $defs['divi5-builder/menus-delete']['input_schema']['required'] && array( 'menu', 'items' ) === $defs['divi5-builder/menus-reorder']['input_schema']['required'] );

echo "edit_theme_options\n";
site( EDITOR );
$GLOBALS['T_menus'][7] = (object) array( 'term_id' => 7, 'name' => 'Main', 'slug' => 'main', 'count' => 0, 'taxonomy' => 'nav_menu' );
$refused = array();
foreach ( array( 'menus-create' => array( 'name' => 'X' ), 'menus-item-add' => array( 'menu' => 7, 'type' => 'custom', 'object' => '/x', 'title' => 'X' ), 'menus-item-update' => array( 'menu' => 7, 'item' => 1, 'title' => 'X' ),
	'menus-item-remove' => array( 'menu' => 7, 'item' => 1 ), 'menus-item-restore' => array( 'menu' => 7, 'item' => 1 ), 'menus-reorder' => array( 'menu' => 7, 'items' => array( array( 'id' => 1 ) ) ),
	'menus-assign-location' => array( 'location' => 'primary', 'menu' => 7 ), 'menus-delete-preview' => array( 'menu' => 7 ), 'menus-delete' => array( 'menu' => 7, 'confirmation' => 'X' ) ) as $ab => $in ) {
	if ( 'FORBIDDEN' !== call( $ab, $in ) || 'forbidden' !== code( handler( $ab, $in ) ) ) { $refused[] = $ab; }
}
check( 'without edit_theme_options every menu write is refused by the gate and by its handler (forbidden); nothing changed', array() === $refused && array( 7 ) === array_keys( $GLOBALS['T_menus'] ) && array() === $GLOBALS['T_posts'] && array() === $GLOBALS['T_menu_locations'] && array() === codes_issued(), $refused );
site();
d5b_abilities_set_category( 'menus', false, false, 'tester' );
check( 'Menus switched off: refused', 'FORBIDDEN' === call( 'menus-create', array( 'name' => 'X' ) ) && array() === $GLOBALS['T_menus'] );

echo "menus-create and the items round trip\n";
site();
$r = call( 'menus-create', array( 'name' => 'Main menu' ) );
$h = hlast();
check( 'creates a menu: id, name, slug; history row nav_menu create', is_array( $r ) && 'Main menu' === $r['name'] && 'main-menu' === $r['slug'] && isset( $GLOBALS['T_menus'][ $r['id'] ] ) && 'nav_menu' === $h['object_type'] && 'create' === $h['action'] && $r['id'] === (int) $h['object_id'], $r );
$m = $r['id'];
check( 'a name another menu has: invalid_input (no HTML in the message)', 'invalid_input' === code( $r2 = call( 'menus-create', array( 'name' => 'Main menu' ) ) ) && false === strpos( msg( $r2 ), '<' ), $r2 );
$page = t_add_post( array( 'post_type' => 'page', 'post_title' => 'About us' ) );
$cat  = t_add_term( 'category', 'News' );
$home = call( 'menus-item-add', array( 'menu' => $m, 'type' => 'custom', 'object' => 'https://x.example/', 'title' => 'Home' ) );
$h = hlast();
check( 'adds a published custom link with the url, last in the menu (position 1 in an empty menu, never 0); history row nav_menu_item create', is_array( $home ) && 1 === $home['position'] && 'publish' === post( $home['id'] )->post_status && 'https://x.example/' === $home['url'] && 'custom' === $home['type'] && $m === $home['menu'] && 'nav_menu_item' === $h['object_type'] && 'create' === $h['action'] && 'Home' === $h['after']['title'], $home );
$about = call( 'menus-item-add', array( 'menu' => 'main-menu', 'type' => 'post_type', 'object' => $page ) );
check( 'adds a page by the menu\'s slug; empty title = the page\'s own; the item links to the page', is_array( $about ) && 'page' === $about['object'] && $page === $about['object_id'] && 'About us' === wp_get_nav_menu_items( $m )[1]->title && array( $m ) === in_menu( $about['id'] ), $about );
$news = call( 'menus-item-add', array( 'menu' => 'Main menu', 'type' => 'taxonomy', 'object' => $cat, 'title' => 'All news', 'parent' => $about['id'] ) );
check( 'adds a term link by the menu\'s name, under a parent', is_array( $news ) && 'category' === $news['object'] && $about['id'] === $news['parent'] && 3 === count( wp_get_nav_menu_items( $m ) ), $news );
$bad = array();
foreach ( array( 'javascript:alert(1)', '//evil.example/', 'data:text/html,x', 'http://a b', "https://x.example/\n" ) as $u ) { if ( 'invalid_input' !== code( call( 'menus-item-add', array( 'menu' => $m, 'type' => 'custom', 'object' => $u, 'title' => 'Bad' ) ) ) ) { $bad[] = $u; } }
check( 'custom urls other than http, https, mailto, tel or a link on the site are refused', array() === $bad && 3 === count( wp_get_nav_menu_items( $m ) ), $bad );
$r = call( 'menus-item-add', array( 'menu' => $m, 'type' => 'custom', 'object' => '/x', 'title' => 'X', 'parent' => 999 ) );
check( 'a parent outside the menu: invalid_input', 'invalid_input' === code( $r ), $r );
$r = call( 'menus-item-add', array( 'menu' => 'nope', 'type' => 'custom', 'object' => '/x', 'title' => 'X' ) );
check( 'an unknown menu: not_found', 'not_found' === code( $r ), $r );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
$r = call( 'menus-item-add', array( 'menu' => $m, 'type' => 'post_type', 'object' => $page ) );
check( 'strictest switch: a page item while Pages is off is refused not_allowed, nothing added', 'not_allowed' === code( $r ) && 3 === count( wp_get_nav_menu_items( $m ) ), $r );
d5b_abilities_set_category( 'pages', true, false, 'tester' );
d5b_abilities_set_category( 'taxonomy', false, false, 'tester' );
check( '... a term item while Taxonomies is off too', 'not_allowed' === code( call( 'menus-item-add', array( 'menu' => $m, 'type' => 'taxonomy', 'object' => $cat ) ) ) );
d5b_abilities_set_category( 'taxonomy', true, false, 'tester' );
$priv = t_add_post( array( 'post_type' => 'page', 'post_status' => 'private', 'post_author' => 9 ) );
t_set_caps( array_values( array_diff( ADMIN, array( 'read_private_posts' ) ) ) );
check( '... and a page the caller may not read: not_found', 'not_found' === code( call( 'menus-item-add', array( 'menu' => $m, 'type' => 'post_type', 'object' => $priv ) ) ) );
t_set_caps( ADMIN );

$GLOBALS['T_posts'][ $home['id'] ]->post_modified_gmt = '2026-01-01 00:00:00';
$r = call( 'menus-item-update', array( 'menu' => $m, 'item' => $home['id'], 'title' => 'Start', 'classes' => 'big  red', 'target' => '_blank' ) );
$h = hlast();
check( 'update changes the title, classes and target, and keeps everything else (url, type, position, parent)', is_array( $r ) && 'Start' === $r['title'] && array( 'big', 'red' ) === $r['classes'] && '_blank' === $r['target'] && 'https://x.example/' === $r['url'] && 'custom' === $r['type'] && $home['position'] === $r['position'] && 'publish' === $r['status'], $r );
check( '... history row: before = the full item, after = only the changed fields', 'update' === $h['action'] && 'Home' === $h['before']['title'] && 'https://x.example/' === $h['before']['url'] && array( 'title', 'classes', 'target' ) === array_keys( $h['after'] ), $h );
$r = call( 'menus-item-update', array( 'menu' => $m, 'item' => $about['id'], 'url' => 'https://x.example/elsewhere' ) );
check( 'a url on an item that links to content: invalid_input', 'invalid_input' === code( $r ), $r );
$r = call( 'menus-item-update', array( 'menu' => $m, 'item' => $about['id'], 'parent' => $news['id'] ) );
check( 'a parent below the item (a loop): invalid_input', 'invalid_input' === code( $r ) && 0 === (int) pmeta( $about['id'], '_menu_item_menu_item_parent' ), $r );
$r = call( 'menus-item-update', array( 'menu' => $m, 'item' => $home['id'], 'title' => 'leak [redacted]' ) );
check( 'a title holding [redacted]: masked_read', 'masked_read' === code( $r ) && 'Start' === post( $home['id'] )->post_title, $r );

echo "remove and restore\n";
$before = array( 'parent' => (int) pmeta( $news['id'], '_menu_item_menu_item_parent' ), 'position' => (int) post( $news['id'] )->menu_order, 'title' => post( $news['id'] )->post_title );
$r = call( 'menus-item-remove', array( 'menu' => $m, 'item' => $news['id'] ) );
$hrm = hlast();
check( 'remove trashes the nav_menu_item (a post), it leaves the published menu; returns restore_with', is_array( $r ) && 'trash' === post( $news['id'] )->post_status && 2 === count( wp_get_nav_menu_items( $m ) ) && 'divi5-builder/menus-item-restore' === $r['restore_with'], $r );
check( '... the history row keeps the item in full: menu, parent, position, title, url, type, object, classes, target', 'trash' === $hrm['action'] && 'nav_menu_item' === $hrm['object_type'] && $m === $hrm['before']['menu'] && $about['id'] === $hrm['before']['parent'] && $before['position'] === $hrm['before']['position'] && 'All news' === $hrm['before']['title'] && 'taxonomy' === $hrm['before']['type'] && 'category' === $hrm['before']['object'] && $cat === $hrm['before']['object_id'] && array_key_exists( 'classes', $hrm['before'] ) && array_key_exists( 'target', $hrm['before'] ) && array_key_exists( 'url', $hrm['before'] ), $hrm );
check( 'removing it again: cannot_trash; updating it while in the trash: invalid_input', 'cannot_trash' === code( call( 'menus-item-remove', array( 'menu' => $m, 'item' => $news['id'] ) ) ) && 'invalid_input' === code( call( 'menus-item-update', array( 'menu' => $m, 'item' => $news['id'], 'title' => 'x' ) ) ) );
$r = call( 'menus-item-restore', array( 'menu' => $m, 'item' => $news['id'] ) );
$h = hlast();
check( 'restore brings it back PUBLISHED, in its menu, under its parent, at its position, with its title', is_array( $r ) && 'publish' === post( $news['id'] )->post_status && array( $m ) === in_menu( $news['id'] ) && $about['id'] === (int) pmeta( $news['id'], '_menu_item_menu_item_parent' ) && $before['position'] === (int) post( $news['id'] )->menu_order && 'All news' === post( $news['id'] )->post_title && 3 === count( wp_get_nav_menu_items( $m ) ) && ! isset( $r['parent_reset'] ), $r );
check( '... history row restore (before: trash, after: publish)', 'restore' === $h['action'] && 'trash' === $h['before']['status'] && 'publish' === $h['after']['status'], $h );
check( 'restoring an item that is not in the trash: not_found', 'not_found' === code( call( 'menus-item-restore', array( 'menu' => $m, 'item' => $news['id'] ) ) ) );
// The parent is gone while the child is in the trash: the fallback parent given is used when it is valid, else top level.
call( 'menus-item-remove', array( 'menu' => $m, 'item' => $news['id'] ) );
call( 'menus-item-remove', array( 'menu' => $m, 'item' => $about['id'] ) );
$r = call( 'menus-item-restore', array( 'menu' => $m, 'item' => $news['id'], 'parent' => $home['id'] ) );
check( 'a parent that is in the trash is replaced by the fallback parent given', is_array( $r ) && $home['id'] === (int) pmeta( $news['id'], '_menu_item_menu_item_parent' ) && ! isset( $r['parent_reset'] ), $r );
call( 'menus-item-remove', array( 'menu' => $m, 'item' => $news['id'] ) );
call( 'menus-item-remove', array( 'menu' => $m, 'item' => $home['id'] ) );
$r = call( 'menus-item-restore', array( 'menu' => $m, 'item' => $news['id'], 'parent' => 999 ) );
check( '... and with no valid parent at all it goes to the top level with parent_reset', is_array( $r ) && 0 === (int) pmeta( $news['id'], '_menu_item_menu_item_parent' ) && true === ( $r['parent_reset'] ?? null ), $r );
call( 'menus-item-restore', array( 'menu' => $m, 'item' => $home['id'] ) );
$r = call( 'menus-item-restore', array( 'menu' => $m, 'item' => $about['id'] ) );
check( 'the parent item comes back too', is_array( $r ) && 'publish' === post( $about['id'] )->post_status, $r );
// The menu relation and the position lost (an item restored by hand elsewhere): the inputs are the fallback.
call( 'menus-item-remove', array( 'menu' => $m, 'item' => $news['id'] ) );
unset( $GLOBALS['T_object_terms'][ $news['id'] ]['nav_menu'] ); $GLOBALS['T_posts'][ $news['id'] ]->menu_order = 0;
$r = call( 'menus-item-restore', array( 'menu' => $m, 'item' => $news['id'], 'position' => 2 ) );
check( 'when the item lost its menu and position, the menu and position given are used', is_array( $r ) && array( $m ) === in_menu( $news['id'] ) && 2 === (int) post( $news['id'] )->menu_order && 'publish' === post( $news['id'] )->post_status, $r );
$m2 = wp_create_nav_menu( 'Other' );
call( 'menus-item-remove', array( 'menu' => $m, 'item' => $news['id'] ) );
check( 'restore through another menu: not_found, the item stays in the trash', 'not_found' === code( call( 'menus-item-restore', array( 'menu' => $m2, 'item' => $news['id'] ) ) ) && 'trash' === post( $news['id'] )->post_status );
call( 'menus-item-restore', array( 'menu' => $m, 'item' => $news['id'] ) );

echo "menus-reorder\n";
$ids = array_column( order_of( $m ), 0 );
$h0 = count( hrows() );
$r = call( 'menus-reorder', array( 'menu' => $m, 'items' => array( array( 'id' => $about['id'], 'parent' => 0 ), array( 'id' => $news['id'], 'parent' => $about['id'] ) ) ) );
check( 'a list missing an item of the menu is refused (data.missing), nothing moved', 'invalid_input' === code( $r ) && array( $home['id'] ) === ( $r->get_error_data()['missing'] ?? null ) && $ids === array_column( order_of( $m ), 0 ) && count( hrows() ) === $h0, $r );
$x = t_add_post( array( 'post_type' => 'nav_menu_item', 'post_status' => 'publish' ) );
$r = call( 'menus-reorder', array( 'menu' => $m, 'items' => array( array( 'id' => $home['id'] ), array( 'id' => $about['id'] ), array( 'id' => $news['id'] ), array( 'id' => $x ) ) ) );
check( 'a list holding an item from elsewhere is refused (data.not_in_menu)', 'invalid_input' === code( $r ) && array( $x ) === ( $r->get_error_data()['not_in_menu'] ?? null ), $r );
$r = call( 'menus-reorder', array( 'menu' => $m, 'items' => array( array( 'id' => $home['id'], 'parent' => $news['id'] ), array( 'id' => $about['id'], 'parent' => $home['id'] ), array( 'id' => $news['id'], 'parent' => $about['id'] ) ) ) );
check( 'parents that make a loop are refused', 'invalid_input' === code( $r ) && $ids === array_column( order_of( $m ), 0 ), $r );
$r = call( 'menus-reorder', array( 'menu' => $m, 'items' => array( array( 'id' => $home['id'] ), array( 'id' => $home['id'] ), array( 'id' => $news['id'] ) ) ) );
check( 'an item twice is refused', 'invalid_input' === code( $r ), $r );
$r = call( 'menus-reorder', array( 'menu' => $m, 'items' => array( array( 'id' => $news['id'], 'parent' => 0 ), array( 'id' => $home['id'], 'parent' => 0 ), array( 'id' => $about['id'], 'parent' => $home['id'] ) ) ) );
$h = hlast();
check( 'a whole order is applied: positions 1..3 and the parents given', is_array( $r ) && array( array( $news['id'], 0 ), array( $home['id'], 0 ), array( $about['id'], $home['id'] ) ) === order_of( $m ) && 'publish' === post( $about['id'] )->post_status, array( $r, order_of( $m ) ) );
check( '... the other fields of the moved items are kept (title, classes, target of Start)', 'Start' === post( $home['id'] )->post_title && array( 'big', 'red' ) === pmeta( $home['id'], '_menu_item_classes' ) && '_blank' === pmeta( $home['id'], '_menu_item_target' ) );
check( '... history row reorder on the menu, before = the old order with parents', 'reorder' === $h['action'] && 'nav_menu' === $h['object_type'] && $m === (int) $h['object_id'] && $ids === array_column( $h['before']['items'], 'id' ) && 3 === count( $h['after']['items'] ), $h );

echo "menus-assign-location\n";
$r = call( 'menus-assign-location', array( 'location' => 'primary', 'menu' => 'main-menu' ) );
$h = hlast();
check( 'assigns the menu (by slug) to a location; returns the menu id and the previous one; history row assign', is_array( $r ) && $m === $r['menu_id'] && 0 === $r['previous_menu_id'] && $m === get_nav_menu_locations()['primary'] && 'assign' === $h['action'] && 0 === $h['before']['menu_id'], $r );
$r = call( 'menus-assign-location', array( 'location' => 'primary', 'menu' => 0 ) );
check( 'menu 0 clears it; the history row keeps the previous menu', is_array( $r ) && 0 === $r['menu_id'] && $m === $r['previous_menu_id'] && 0 === get_nav_menu_locations()['primary'] && $m === hlast()['before']['menu_id'], $r );
check( 'an unknown location: invalid_input; an unknown menu: not_found; no menu given: invalid_input',
	'invalid_input' === code( call( 'menus-assign-location', array( 'location' => 'sidebar', 'menu' => $m ) ) ) && 'not_found' === code( call( 'menus-assign-location', array( 'location' => 'primary', 'menu' => 'nope' ) ) ) && 'invalid_input' === code( handler( 'menus-assign-location', array( 'location' => 'primary' ) ) ) );
call( 'menus-assign-location', array( 'location' => 'primary', 'menu' => $m ) );
call( 'menus-assign-location', array( 'location' => 'footer', 'menu' => $m ) );

echo "the two-step menu delete\n";
call( 'menus-item-remove', array( 'menu' => $m, 'item' => $news['id'] ) );
$trashed = $news['id'];
$pv = call( 'menus-delete-preview', array( 'menu' => $m ) );
check( 'the preview lists every item (the trashed one too), the locations, and a code; it changes nothing',
	is_array( $pv ) && $m === $pv['menu']['id'] && 3 === count( $pv['items'] ) && in_array( $trashed, array_column( $pv['items'], 'id' ), true ) && array( 'primary', 'footer' ) === $pv['locations'] && 1 === preg_match( '/^[A-HJ-NP-Z2-9]{12}$/', $pv['confirmation'] ) && isset( $GLOBALS['T_menus'][ $m ] ), $pv );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
$pvh = call( 'menus-delete-preview', array( 'menu' => $m ) );
d5b_abilities_set_category( 'pages', true, false, 'tester' );
$hidden = array_values( array_filter( $pvh['items'], function ( $i ) use ( $about ) { return $i['id'] === $about['id']; } ) )[0];
check( '... a link to a page while Pages is off keeps its slot only (type hidden, no title or object)', 'hidden' === $hidden['type'] && ! isset( $hidden['title'] ) && ! isset( $hidden['object_id'] ), $hidden );
$r = call( 'menus-delete', array( 'menu' => $m ) );
check( 'delete without a code: confirmation_invalid (missing), nothing deleted', 'confirmation_invalid' === code( $r ) && 'missing' === reason( $r ) && isset( $GLOBALS['T_menus'][ $m ] ), $r );
$pv = call( 'menus-delete-preview', array( 'menu' => 'main-menu' ) );
$GLOBALS['T_posts'][ $home['id'] ]->post_modified_gmt = '2026-01-01 00:00:00';
$pv = call( 'menus-delete-preview', array( 'menu' => 'main-menu' ) );
call( 'menus-item-update', array( 'menu' => $m, 'item' => $home['id'], 'title' => 'Start again' ) );
$r = call( 'menus-delete', array( 'menu' => 'main-menu', 'confirmation' => $pv['confirmation'] ) );
check( 'a delete (by slug) after an item changed is refused stale: the menu is resolved to its id before the fingerprint', 'confirmation_invalid' === code( $r ) && 'stale' === reason( $r ) && isset( $GLOBALS['T_menus'][ $m ] ) && null !== post( $home['id'] ), $r );
$pv = call( 'menus-delete-preview', array( 'menu' => 'Main menu' ) );
call( 'menus-item-add', array( 'menu' => $m, 'type' => 'custom', 'object' => '/late', 'title' => 'Late' ) );
$r = call( 'menus-delete', array( 'menu' => 'Main menu', 'confirmation' => $pv['confirmation'] ) );
check( '... and after an item was added (by name)', 'stale' === reason( $r ) && isset( $GLOBALS['T_menus'][ $m ] ), $r );
$all = get_objects_in_term( $m, 'nav_menu' );
$pv = call( 'menus-delete-preview', array( 'menu' => $m ) );
break_history();
$r = call( 'menus-delete', array( 'menu' => $m, 'confirmation' => $pv['confirmation'] ) );
check( 'when the history row cannot be written: history_unavailable; the menu, every item and the locations are still there', 'history_unavailable' === code( $r ) && isset( $GLOBALS['T_menus'][ $m ] ) && $all === get_objects_in_term( $m, 'nav_menu' ) && $m === get_nav_menu_locations()['footer'], $r );
mend_history();
$pv = call( 'menus-delete-preview', array( 'menu' => $m ) );
$GLOBALS['T_menu_delete_fail'] = true;
$r = call( 'menus-delete', array( 'menu' => $m, 'confirmation' => $pv['confirmation'] ) );
$GLOBALS['T_menu_delete_fail'] = false;
$hr = hrows(); $full = d5b_history_get( (int) $hr[ count( $hr ) - 2 ]['id'] ); $fail = hlast();
check( 'when WordPress does not finish: write_failed (may be partly deleted), the full row is kept and a delete_failed row records it', 'write_failed' === code( $r ) && false !== strpos( msg( $r ), 'partly deleted' ) && 'delete' === $full['action'] && count( $all ) === count( $full['before']['items'] ) && 'delete_failed' === $fail['action'] && (int) $full['id'] === $fail['after']['history_id'], array( $r, $fail ) );
// Rebuild what the failed delete took (the stand-in deleted the items) so the valid delete has a full menu again.
foreach ( $full['before']['items'] as $d ) {
	t_add_post( array( 'ID' => $d['id'], 'post_type' => 'nav_menu_item', 'post_status' => $d['status'], 'post_title' => $d['title'], 'menu_order' => $d['position'], 'post_content' => $d['description'], 'post_excerpt' => $d['attr_title'] ) );
	foreach ( array( '_menu_item_type' => $d['type'], '_menu_item_menu_item_parent' => (string) $d['parent'], '_menu_item_object_id' => (string) $d['object_id'], '_menu_item_object' => $d['object'], '_menu_item_target' => $d['target'], '_menu_item_classes' => $d['classes'], '_menu_item_xfn' => $d['xfn'], '_menu_item_url' => $d['url'] ) as $k => $v ) { update_post_meta( $d['id'], $k, $v ); }
	wp_set_object_terms( $d['id'], array( $m ), 'nav_menu' );
}
$GLOBALS['T_menu_locations'] = array( 'primary' => $m, 'footer' => $m );
check( '... and that row was enough to rebuild the menu exactly (every item, its fields, its menu)', $all === get_objects_in_term( $m, 'nav_menu' ) && 'Start again' === post( $home['id'] )->post_title && 'trash' === post( $trashed )->post_status && array( 'big', 'red' ) === pmeta( $home['id'], '_menu_item_classes' ) );
$pv = call( 'menus-delete-preview', array( 'menu' => $m ) );
$r = call( 'menus-delete', array( 'menu' => $m, 'confirmation' => $pv['confirmation'] ) );
$h = hlast();
check( 'a valid delete removes the menu and every item (the trashed one too) and takes it off its locations', is_array( $r ) && true === $r['deleted'] && ! isset( $GLOBALS['T_menus'][ $m ] ) && array() === array_filter( $all, function ( $i ) { return null !== post( $i ); } ) && 0 === get_nav_menu_locations()['primary'] && 0 === get_nav_menu_locations()['footer'] && array( 'primary', 'footer' ) === $r['locations_cleared'], $r );
check( '... the history row holds the menu, every item in full and its locations', (int) $h['id'] === $r['history_id'] && 'nav_menu' === $h['object_type'] && 'delete' === $h['action'] && 'Main menu' === $h['before']['name'] && $all === ( function ( $ids ) { sort( $ids ); return $ids; } )( array_column( $h['before']['items'], 'id' ) ) && array( 'primary', 'footer' ) === $h['before']['locations'] && 'Start again' === array_column( $h['before']['items'], 'title', 'id' )[ $home['id'] ], $h );
check( 'deleting again: not_found', 'not_found' === code( call( 'menus-delete', array( 'menu' => $m, 'confirmation' => $pv['confirmation'] ) ) ) );

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
