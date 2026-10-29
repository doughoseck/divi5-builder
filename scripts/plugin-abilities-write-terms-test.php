<?php
/**
 * Write abilities for taxonomy terms and custom fields (#32, release 2.2.0, Task 6).
 *
 *   php scripts/plugin-abilities-write-terms-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php: wp_insert_term, wp_update_term, wp_delete_term,
 * wp_set_object_terms, wp_remove_object_terms, get_objects_in_term, delete_post_meta behave as core 6.4 where the plugin
 * depends on it). Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (mutation testing).
 *
 * The promises under test: create/update/delete need the taxonomy's manage_terms and assign its assign_terms; internal
 * taxonomies are refused; assign adds, replaces and removes on one post and is refused when the post's category is off; the
 * term delete is two-step, refuses the default category, writes its history row (term, children, every item id) first and
 * deletes nothing when it cannot; meta-set refuses protected and secret-named keys and objects, replaces every row of a key
 * and keeps them all in the history; the meta delete is two-step and keeps every row first.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.2.0' );
require __DIR__ . '/plugin-test-stubs.php';
foreach ( array( 'parts', 'switches', 'abilities/categories', 'abilities/register', 'abilities/secrets', 'abilities/audit', 'abilities/scrub', 'abilities/history', 'abilities/confirm', 'abilities/read-content', 'abilities/read-structure', 'abilities/write-common', 'abilities/write-terms' ) as $f ) { require $dir . '/inc/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ED = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'read_private_posts', 'edit_private_posts', 'manage_categories' );
const AUTHOR = array( 'read', 'edit_posts', 'edit_published_posts' );
const READER = array( 'read' );

function site( array $caps = ED, int $uid = 3 ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( false );
	$GLOBALS['T_user_id'] = $uid; $GLOBALS['T_users'] = array( 3 => (object) array( 'user_login' => 'ann' ), 4 => (object) array( 'user_login' => 'cat' ) );
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
function terms_of( $id, $tax ) { $ids = array_map( 'intval', $GLOBALS['T_object_terms'][ (int) $id ][ $tax ] ?? array() ); sort( $ids ); return $ids; }
function term( $id ) { return $GLOBALS['T_terms'][ (int) $id ] ?? null; }
function meta( $id, $k ) { return $GLOBALS['T_meta'][ (int) $id ][ $k ] ?? null; }
function break_history() { unset( $GLOBALS['wpdb']->tables['wp_d5b_history'] ); $GLOBALS['T_dbdelta_fail'] = true; set_transient( 'd5b_audit_install_failed', 1, 3600 ); }
function mend_history() { $GLOBALS['T_dbdelta_fail'] = false; $GLOBALS['wpdb']->tables['wp_d5b_history'] = array(); }

echo "registration\n";
$defs = d5b_ability_defs();
$want = array(
	'taxonomy-create' => array( 'taxonomy', false, false, false ), 'taxonomy-update' => array( 'taxonomy', false, false, true ), 'taxonomy-assign' => array( 'taxonomy', false, false, false ),
	'taxonomy-delete-preview' => array( 'taxonomy', true, false, false ), 'taxonomy-delete' => array( 'taxonomy', false, true, false ),
	'meta-set' => array( 'meta', false, false, true ), 'meta-delete-preview' => array( 'meta', true, false, false ), 'meta-delete' => array( 'meta', false, true, false ),
);
$bad = array();
foreach ( $want as $x => $w ) {
	$d = $defs[ 'divi5-builder/' . $x ] ?? null;
	if ( null === $d ) { $bad[] = "$x missing"; continue; }
	$an = $d['annotations'] ?? array();
	if ( array( $d['category'], $an['readonly'] ?? null, $an['destructive'] ?? null, $an['idempotent'] ?? null ) !== $w ) { $bad[] = "$x " . json_encode( array( $d['category'], $an ) ); }
	if ( ! is_callable( $d['handler'] ) || ! is_callable( $d['permission'] ) ) { $bad[] = "$x callables"; }
}
check( 'the 8 writes are defined in taxonomy / meta with the agreed annotations (previews readonly; deletes destructive; update and meta-set idempotent)', array() === $bad, $bad );
check( 'the deletes require the confirmation; assign requires post_id, taxonomy, terms; meta-set requires post_id, key, value',
	array( 'taxonomy', 'id', 'confirmation' ) === $defs['divi5-builder/taxonomy-delete']['input_schema']['required'] && array( 'post_id', 'key', 'confirmation' ) === $defs['divi5-builder/meta-delete']['input_schema']['required']
	&& array( 'post_id', 'taxonomy', 'terms' ) === $defs['divi5-builder/taxonomy-assign']['input_schema']['required'] && array( 'post_id', 'key', 'value' ) === $defs['divi5-builder/meta-set']['input_schema']['required'] );
check( 'meta-set and meta-delete-preview declare their plugin-shaped data as raw paths (value, rows), as meta-get does',
	array( 'value' ) === ( $defs['divi5-builder/meta-set']['raw_paths'] ?? null ) && array( 'rows' ) === ( $defs['divi5-builder/meta-delete-preview']['raw_paths'] ?? null ) );
check( 'meta-set says that every row of a repeated field is replaced', false !== strpos( $defs['divi5-builder/meta-set']['description'], 'ALL replaced' ) );

echo "taxonomy-create and taxonomy-update\n";
site();
$r = call( 'taxonomy-create', array( 'taxonomy' => 'category', 'name' => 'News', 'description' => 'All the news' ) );
$h = hlast();
check( 'creates a category and returns it (id, name, slug from the name, description, parent 0)', is_array( $r ) && 'News' === $r['name'] && 'news' === $r['slug'] && 'All the news' === $r['description'] && 0 === $r['parent'] && null !== term( $r['id'] ), $r );
check( '... one history row: object_type term, action create, after = the term, summary names it', is_array( $h ) && 'term' === $h['object_type'] && (int) $h['object_id'] === $r['id'] && 'create' === $h['action'] && 'News' === $h['after']['name'] && array() === $h['before'] && "Created category term {$r['id']} 'News'" === $h['summary'], $h );
$news = $r['id'];
$r = call( 'taxonomy-create', array( 'taxonomy' => 'category', 'name' => 'Local', 'parent' => $news, 'slug' => 'Local News!' ) );
check( 'a child with a given slug (cleaned as WordPress cleans slugs)', is_array( $r ) && $news === $r['parent'] && 'local-news' === $r['slug'], $r );
$local = $r['id'];
$r = call( 'taxonomy-create', array( 'taxonomy' => 'category', 'name' => 'News' ) );
check( 'a name that exists at the same level: invalid_input with data.existing_id', 'invalid_input' === code( $r ) && $news === ( $r->get_error_data()['existing_id'] ?? null ), $r );
$r = call( 'taxonomy-create', array( 'taxonomy' => 'post_tag', 'name' => 'Flat', 'parent' => $news ) );
check( 'a parent in a taxonomy without hierarchy: invalid_input', 'invalid_input' === code( $r ), $r );
$r = call( 'taxonomy-create', array( 'taxonomy' => 'category', 'name' => 'Odd', 'parent' => 99999 ) );
check( 'a parent that is not a term of the taxonomy: invalid_input', 'invalid_input' === code( $r ), $r );
$r = call( 'taxonomy-create', array( 'taxonomy' => 'category', 'name' => 'Leak [redacted]' ) );
check( 'a name holding [redacted]: masked_read, nothing created', 'masked_read' === code( $r ) && false === get_term_by( 'name', 'Leak [redacted]', 'category' ), $r );
$c0 = count( $GLOBALS['T_terms'] ); $h0 = count( hrows() );
check( 'internal and non-public taxonomies are refused (nav_menu, wp_theme): not_allowed, by the gate and by the handler',
	'FORBIDDEN' === call( 'taxonomy-create', array( 'taxonomy' => 'nav_menu', 'name' => 'X' ) ) && 'not_allowed' === code( handler( 'taxonomy-create', array( 'taxonomy' => 'nav_menu', 'name' => 'X' ) ) )
	&& 'not_allowed' === code( handler( 'taxonomy-create', array( 'taxonomy' => 'wp_theme', 'name' => 'X' ) ) ) && 'invalid_input' === code( handler( 'taxonomy-create', array( 'taxonomy' => 'nope', 'name' => 'X' ) ) ) );
site( AUTHOR );
$r1 = call( 'taxonomy-create', array( 'taxonomy' => 'category', 'name' => 'Mine' ) );
$r2 = handler( 'taxonomy-create', array( 'taxonomy' => 'category', 'name' => 'Mine' ) );
check( 'without the taxonomy\'s manage_terms (manage_categories): the gate refuses and the handler says not_allowed; nothing created, no history row', 'FORBIDDEN' === $r1 && 'not_allowed' === code( $r2 ) && array() === $GLOBALS['T_terms'] && array() === hrows(), array( $r1, $r2 ) );
site();
d5b_abilities_set_category( 'taxonomy', false, false, 'tester' );
check( 'Taxonomies switched off: refused by the gate', 'FORBIDDEN' === call( 'taxonomy-create', array( 'taxonomy' => 'category', 'name' => 'X' ) ) && array() === $GLOBALS['T_terms'] );
site();
$news = t_add_term( 'category', 'News' ); $local = t_add_term( 'category', 'Local', $news ); $deep = t_add_term( 'category', 'Deep', $local ); $sport = t_add_term( 'category', 'Sport' );
$r = call( 'taxonomy-update', array( 'taxonomy' => 'category', 'id' => $news, 'name' => 'Headlines', 'description' => 'Top stories' ) );
$h = hlast();
check( 'update renames and changes the description, returns the term', is_array( $r ) && 'Headlines' === $r['name'] && 'Top stories' === $r['description'] && 'news' === $r['slug'], $r );
check( '... history row: before = the full previous term, after = only the changed fields', 'update' === $h['action'] && 'News' === $h['before']['name'] && 'About News' === $h['before']['description'] && 'news' === $h['before']['slug'] && array( 'name', 'description' ) === array_keys( $h['after'] ), $h );
$r = call( 'taxonomy-update', array( 'taxonomy' => 'category', 'id' => $news, 'parent' => $deep ) );
check( 'a parent below the term itself (a loop): invalid_input, nothing changed', 'invalid_input' === code( $r ) && 0 === term( $news )->parent, $r );
$r = call( 'taxonomy-update', array( 'taxonomy' => 'category', 'id' => $news, 'parent' => $news ) );
check( 'the term as its own parent: invalid_input', 'invalid_input' === code( $r ), $r );
$r = call( 'taxonomy-update', array( 'taxonomy' => 'category', 'id' => $sport, 'slug' => 'news' ) );
check( 'a slug another term uses: invalid_input (WordPress\'s duplicate_term_slug)', 'invalid_input' === code( $r ) && 'sport' === term( $sport )->slug, $r );
$r = call( 'taxonomy-update', array( 'taxonomy' => 'post_tag', 'id' => $news, 'name' => 'X' ) );
check( 'a term of another taxonomy: not_found', 'not_found' === code( $r ), $r );
$r = call( 'taxonomy-update', array( 'taxonomy' => 'category', 'id' => $news ) );
check( 'nothing to change: invalid_input', 'invalid_input' === code( $r ), $r );
term( $sport )->description = 'Has a [redacted] word';
$r = call( 'taxonomy-update', array( 'taxonomy' => 'category', 'id' => $news, 'description' => 'copy [redacted]' ) );
$r2 = call( 'taxonomy-update', array( 'taxonomy' => 'category', 'id' => $sport, 'description' => 'Has a [redacted] word, still' ) );
check( 'masked_read when the incoming text holds [redacted] and the stored one does not; allowed when the stored one does', 'masked_read' === code( $r ) && is_array( $r2 ), array( $r, $r2 ) );

echo "taxonomy-assign\n";
site();
$a = t_add_term( 'category', 'Alpha' ); $b = t_add_term( 'category', 'Beta' ); $c = t_add_term( 'category', 'Gamma' ); $tag = t_add_term( 'post_tag', 'Tagged' );
$p = t_add_post( array( 'post_author' => 3 ) ); $GLOBALS['T_object_terms'][ $p ]['category'] = array( $a );
$r = call( 'taxonomy-assign', array( 'post_id' => $p, 'taxonomy' => 'category', 'terms' => array( $b, 'Gamma' ) ) );
$h = hlast();
check( 'mode add (the default) adds by id and by name, keeps the others; returns the terms now and the previous ids', is_array( $r ) && array( $a, $b, $c ) === terms_of( $p, 'category' ) && array( $a ) === $r['previous_term_ids'] && array( $a, $b, $c ) === array_column( $r['terms'], 'id' ) && 'add' === $r['mode'], $r );
check( '... history row on the post (object_type post), action assign, before holds the previous term ids', 'post' === $h['object_type'] && $p === (int) $h['object_id'] && 'assign' === $h['action'] && array( 'taxonomy' => 'category', 'term_ids' => array( $a ) ) === $h['before'] && array( $a, $b, $c ) === $h['after']['term_ids'], $h );
$r = call( 'taxonomy-assign', array( 'post_id' => $p, 'taxonomy' => 'category', 'terms' => array( $c ), 'mode' => 'replace' ) );
$h = hlast();
check( 'mode replace makes them the only ones; before = the three', is_array( $r ) && array( $c ) === terms_of( $p, 'category' ) && array( $a, $b, $c ) === $h['before']['term_ids'], $r );
$r = call( 'taxonomy-assign', array( 'post_id' => $p, 'taxonomy' => 'category', 'terms' => array( 'gamma' ), 'mode' => 'remove' ) );
check( 'mode remove takes them off (a slug works too)', is_array( $r ) && array() === terms_of( $p, 'category' ) && array( $c ) === hlast()['before']['term_ids'], $r );
$r = call( 'taxonomy-assign', array( 'post_id' => $p, 'taxonomy' => 'category', 'terms' => array(), 'mode' => 'add' ) );
$r2 = call( 'taxonomy-assign', array( 'post_id' => $p, 'taxonomy' => 'category', 'terms' => array( $a ) ) );
$r3 = call( 'taxonomy-assign', array( 'post_id' => $p, 'taxonomy' => 'category', 'terms' => array(), 'mode' => 'replace' ) );
check( 'an empty list only with replace (it clears the taxonomy on the post)', 'invalid_input' === code( $r ) && is_array( $r2 ) && is_array( $r3 ) && array() === terms_of( $p, 'category' ), array( $r, $r3 ) );
$h0 = count( hrows() );
$r = call( 'taxonomy-assign', array( 'post_id' => $p, 'taxonomy' => 'category', 'terms' => array( 'Nope', $tag, 99999 ) ) );
check( 'unknown names, a term of another taxonomy, an unknown id: invalid_input naming them, nothing assigned, no history row', 'invalid_input' === code( $r ) && 3 === count( $r->get_error_data()['missing'] ?? array() ) && array() === terms_of( $p, 'category' ) && count( hrows() ) === $h0, $r );
$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 3 ) );
$r = call( 'taxonomy-assign', array( 'post_id' => $pg, 'taxonomy' => 'category', 'terms' => array( $a ) ) );
check( 'a taxonomy the post type does not use: invalid_input', 'invalid_input' === code( $r ), $r );
d5b_abilities_set_category( 'posts', false, false, 'tester' );
$r = call( 'taxonomy-assign', array( 'post_id' => $p, 'taxonomy' => 'category', 'terms' => array( $a ) ) );
$r2 = handler( 'taxonomy-assign', array( 'post_id' => $p, 'taxonomy' => 'category', 'terms' => array( $a ) ) );
check( 'strictest switch: Posts off refuses assigning to a post (gate; the handler says not_allowed), nothing assigned', 'FORBIDDEN' === $r && 'not_allowed' === code( $r2 ) && array() === terms_of( $p, 'category' ), array( $r, $r2 ) );
d5b_abilities_set_category( 'posts', true, false, 'tester' );
d5b_abilities_set_category( 'taxonomy', false, false, 'tester' );
check( 'Taxonomies off: refused', 'FORBIDDEN' === call( 'taxonomy-assign', array( 'post_id' => $p, 'taxonomy' => 'category', 'terms' => array( $a ) ) ) );
d5b_abilities_set_category( 'taxonomy', true, false, 'tester' );
site( READER );
$p2 = t_add_post( array( 'post_author' => 3 ) ); $a2 = t_add_term( 'category', 'Alpha' );
check( 'without assign_terms (edit_posts) and edit_post: refused', 'FORBIDDEN' === call( 'taxonomy-assign', array( 'post_id' => $p2, 'taxonomy' => 'category', 'terms' => array( $a2 ) ) ) && array() === terms_of( $p2, 'category' ) );

echo "the two-step term delete\n";
site();
$top = t_add_term( 'category', 'Top' ); $t = t_add_term( 'category', 'Doomed', $top ); $kid = t_add_term( 'category', 'Kid', $t ); $kid2 = t_add_term( 'category', 'Kid two', $t ); $other = t_add_term( 'category', 'Other' );
$posts = array();
for ( $i = 0; $i < 102; $i++ ) { $posts[] = $x = t_add_post( array( 'post_author' => 3 ) ); wp_set_object_terms( $x, array( $t ), 'category' ); }
wp_set_object_terms( $posts[0], array( $other ), 'category', true );
$pv = call( 'taxonomy-delete-preview', array( 'taxonomy' => 'category', 'id' => $t ) );
check( 'the preview returns the term, its count, its children, the first 100 item ids and the total, and a 12-character code with the message',
	is_array( $pv ) && $t === $pv['term']['id'] && 102 === $pv['count'] && array( $kid, $kid2 ) === array_column( $pv['children'], 'id' ) && 2 === $pv['children_total'] && 100 === count( $pv['posts'] ) && array_slice( $posts, 0, 100 ) === $pv['posts'] && 102 === $pv['posts_total'] && true === $pv['posts_capped']
	&& 1 === preg_match( '/^[A-HJ-NP-Z2-9]{12}$/', $pv['confirmation'] ) && false !== stripos( $pv['message'], 'explicit OK' ), $pv );
check( '... and it changed nothing: no history row, the term is there', array() === hrows() && null !== term( $t ) );
$r = call( 'taxonomy-delete', array( 'taxonomy' => 'category', 'id' => $t ) );
check( 'delete without a code: confirmation_invalid (missing), nothing deleted', 'FORBIDDEN' !== $r && 'confirmation_invalid' === code( $r ) && 'missing' === reason( $r ) && null !== term( $t ) && array() === hrows(), $r );
wp_update_term( $t, 'category', array( 'name' => 'Renamed' ) );
$r = call( 'taxonomy-delete', array( 'taxonomy' => 'category', 'id' => $t, 'confirmation' => $pv['confirmation'] ) );
check( 'a delete after a rename is refused stale, nothing deleted, no history row', 'confirmation_invalid' === code( $r ) && 'stale' === reason( $r ) && null !== term( $t ) && array() === hrows(), $r );
$pv = call( 'taxonomy-delete-preview', array( 'taxonomy' => 'category', 'id' => $t ) );
wp_set_object_terms( t_add_post(), array( $t ), 'category' );
$r = call( 'taxonomy-delete', array( 'taxonomy' => 'category', 'id' => $t, 'confirmation' => $pv['confirmation'] ) );
check( 'a delete after the count changed is refused stale', 'stale' === reason( $r ) && null !== term( $t ), $r );
$all = get_objects_in_term( $t, 'category' );
$pv = call( 'taxonomy-delete-preview', array( 'taxonomy' => 'category', 'id' => $t ) );
break_history();
$r = call( 'taxonomy-delete', array( 'taxonomy' => 'category', 'id' => $t, 'confirmation' => $pv['confirmation'] ) );
check( 'when the history row cannot be written the delete is refused history_unavailable: the term, its children and every assignment are still there',
	'history_unavailable' === code( $r ) && null !== term( $t ) && $t === term( $kid )->parent && $all === get_objects_in_term( $t, 'category' ), $r );
mend_history();
$pv = call( 'taxonomy-delete-preview', array( 'taxonomy' => 'category', 'id' => $t ) );
$GLOBALS['T_term_delete_fail'] = true;
$r = call( 'taxonomy-delete', array( 'taxonomy' => 'category', 'id' => $t, 'confirmation' => $pv['confirmation'] ) );
$GLOBALS['T_term_delete_fail'] = false;
$hr = hrows(); $full = d5b_history_get( (int) $hr[ count( $hr ) - 2 ]['id'] ); $fail = hlast();
check( 'when WordPress does not delete: write_failed naming the full row; a second row (delete_failed) records it', 'write_failed' === code( $r ) && null !== term( $t ) && 'delete' === $full['action'] && 'delete_failed' === $fail['action'] && false === $fail['after']['deleted'] && (int) $full['id'] === $fail['after']['history_id'] && false !== strpos( msg( $r ), 'History row ' . $full['id'] ), array( $r, $fail ) );
$GLOBALS['wpdb']->tables['wp_d5b_history'] = array();
$pv = call( 'taxonomy-delete-preview', array( 'taxonomy' => 'category', 'id' => $t ) );
$r = call( 'taxonomy-delete', array( 'taxonomy' => 'category', 'id' => $t, 'confirmation' => $pv['confirmation'] ) );
$h = hlast();
check( 'a valid delete removes the term; its children move up to its parent; the items lose it (the one that had another category keeps that)',
	is_array( $r ) && true === $r['deleted'] && null === term( $t ) && $top === term( $kid )->parent && $top === term( $kid2 )->parent && array( $other ) === terms_of( $posts[0], 'category' ) && array() === get_objects_in_term( $t, 'category' ), $r );
check( '... the history row (its id returned) holds the term, its children and EVERY item id it was on (' . count( $all ) . ')',
	(int) $h['id'] === $r['history_id'] && 'term' === $h['object_type'] && 'delete' === $h['action'] && 'Renamed' === $h['before']['name'] && $top === $h['before']['parent'] && array( $kid, $kid2 ) === array_column( $h['before']['children'], 'id' ) && $all === $h['before']['object_ids'] && 103 === count( $h['before']['object_ids'] ), $h );
$re = wp_insert_term( $h['before']['name'], 'category', array( 'slug' => $h['before']['slug'], 'description' => $h['before']['description'], 'parent' => $h['before']['parent'] ) );
foreach ( $h['before']['object_ids'] as $o ) { wp_set_object_terms( $o, array( $re['term_id'] ), 'category', true ); }
foreach ( $h['before']['children'] as $ch ) { wp_update_term( $ch['id'], 'category', array( 'parent' => $re['term_id'] ) ); }
$nt = term( $re['term_id'] );
check( '... and the row is enough to rebuild it: same name, slug, description, parent, children and items', 'Renamed' === $nt->name && $h['before']['slug'] === $nt->slug && $h['before']['description'] === $nt->description && $top === $nt->parent && $all === get_objects_in_term( $nt->term_id, 'category' ) && $nt->term_id === term( $kid )->parent );
check( 'deleting again is not_found', 'not_found' === code( handler( 'taxonomy-delete', array( 'taxonomy' => 'category', 'id' => $t, 'confirmation' => $pv['confirmation'] ) ) ) );
site();
$def = t_add_term( 'category', 'Uncategorized' ); update_option( 'default_category', $def );
$r1 = call( 'taxonomy-delete-preview', array( 'taxonomy' => 'category', 'id' => $def ) );
$r2 = call( 'taxonomy-delete', array( 'taxonomy' => 'category', 'id' => $def, 'confirmation' => 'ABCDEFGHJKLM' ) );
check( 'the default category: preview and delete refused not_allowed, no code issued, nothing deleted', 'not_allowed' === code( $r1 ) && 'not_allowed' === code( $r2 ) && null !== term( $def ) && array() === array_filter( array_keys( $GLOBALS['T_transients'] ), function ( $k ) { return 0 === strpos( $k, 'd5b_confirm_' ); } ), array( $r1, $r2 ) );
$GLOBALS['T_taxonomies']['genre'] = (object) array( 'name' => 'genre', 'label' => 'Genre', 'public' => true, 'show_in_rest' => true, 'hierarchical' => false, 'object_type' => array( 'post' ), 'default_term' => array( 'name' => 'Any' ), 'cap' => (object) array( 'assign_terms' => 'edit_posts', 'manage_terms' => 'manage_categories' ) );
$g = t_add_term( 'genre', 'Any' ); update_option( 'default_term_genre', $g );
check( 'a taxonomy\'s default term is refused the same way', 'not_allowed' === code( call( 'taxonomy-delete-preview', array( 'taxonomy' => 'genre', 'id' => $g ) ) ) );
site( AUTHOR );
$x = t_add_term( 'category', 'X' );
check( 'without manage_terms: preview and delete refused by the gate', 'FORBIDDEN' === call( 'taxonomy-delete-preview', array( 'taxonomy' => 'category', 'id' => $x ) ) && 'FORBIDDEN' === call( 'taxonomy-delete', array( 'taxonomy' => 'category', 'id' => $x, 'confirmation' => 'X' ) ) && null !== term( $x ) );

echo "meta-set\n";
site();
$p = t_add_post( array( 'post_author' => 3 ) );
t_add_meta( $p, 'color', 'red' );
$r = call( 'meta-set', array( 'post_id' => $p, 'key' => 'color', 'value' => 'blue' ) );
$h = hlast();
check( 'sets a field and returns it (field name, value, replaced_rows 1)', is_array( $r ) && 'color' === $r['field'] && 'blue' === $r['value'] && 1 === $r['replaced_rows'] && array( 'blue' ) === meta( $p, 'color' ), $r );
check( '... history row: object_type post_meta on the post, action update, before = every previous row, after = the new value', 'post_meta' === $h['object_type'] && $p === (int) $h['object_id'] && 'update' === $h['action'] && array( 'field' => 'color', 'rows' => array( 'red' ) ) === $h['before'] && 'blue' === $h['after']['value'], $h );
$r = call( 'meta-set', array( 'post_id' => $p, 'key' => 'size', 'value' => array( 'w' => 3, 'list' => array( 1, 'two', true ) ) ) );
check( 'a new field with an array value: action create, before has no rows', is_array( $r ) && array( 'w' => 3, 'list' => array( 1, 'two', true ) ) === meta( $p, 'size' )[0] && 'create' === hlast()['action'] && array() === hlast()['before']['rows'], $r );
t_add_meta( $p, 'multi', 'one' ); t_add_meta( $p, 'multi', 'two' ); t_add_meta( $p, 'multi', array( 'three' => 3 ) );
$r = call( 'meta-set', array( 'post_id' => $p, 'key' => 'multi', 'value' => 'only' ) );
check( 'a field with several rows: ALL replaced by the one value (replaced_rows 3); the history keeps all three', is_array( $r ) && 3 === $r['replaced_rows'] && array( 'only' ) === meta( $p, 'multi' ) && array( 'one', 'two', array( 'three' => 3 ) ) === hlast()['before']['rows'], $r );
t_add_meta( $p, 'a\\b', 'x' ); t_add_meta( $p, 'a\\b', 'y' );
$r = call( 'meta-set', array( 'post_id' => $p, 'key' => 'a\\b', 'value' => 'z' ) );
check( 'a key with a backslash and several rows is replaced exactly (core unslashes keys)', is_array( $r ) && array( 'z' ) === meta( $p, 'a\\b' ) && null === meta( $p, 'ab' ), array( $r, $GLOBALS['T_meta'][ $p ] ) );
$h0 = count( hrows() );
$refused = array();
foreach ( array( '_secret_flag', '_et_pb_use_builder', 'api_key', 'smtp_pw', 'stripe_secret', 'my_password', 'auth_token' ) as $k ) {
	$r = handler( 'meta-set', array( 'post_id' => $p, 'key' => $k, 'value' => 'v' ) );
	if ( 'not_allowed' !== code( $r ) || null !== meta( $p, $k ) ) { $refused[] = $k . ' => ' . ( code( $r ) ?? 'ok' ); }
}
check( 'protected keys (_...) and secret-named keys (api_key, smtp_pw, stripe_secret, my_password, auth_token) are refused not_allowed and nothing is stored', array() === $refused && count( hrows() ) === $h0, $refused );
$r = call( 'meta-set', array( 'post_id' => $p, 'key' => 'obj', 'value' => (object) array( 'a' => 1 ) ) );
$r2 = call( 'meta-set', array( 'post_id' => $p, 'key' => 'obj', 'value' => array( 'deep' => (object) array( 'a' => 1 ) ) ) );
$r3 = call( 'meta-set', array( 'post_id' => $p, 'key' => 'obj', 'value' => null ) );
check( 'an object value (at any depth) and a null value are refused invalid_input', 'invalid_input' === code( $r ) && 'invalid_input' === code( $r2 ) && 'invalid_input' === code( $r3 ) && null === meta( $p, 'obj' ), array( $r, $r2 ) );
$r = call( 'meta-set', array( 'post_id' => $p, 'key' => 'color', 'value' => 'copy of [redacted]' ) );
check( 'a value holding [redacted] where the stored one does not: masked_read', 'masked_read' === code( $r ) && array( 'blue' ) === meta( $p, 'color' ), $r );
$GLOBALS['T_meta_auth_denied'] = array( 'locked' );
$r = handler( 'meta-set', array( 'post_id' => $p, 'key' => 'locked', 'value' => 'v' ) );
check( 'a key the site does not let the caller edit (edit_post_meta false): not_allowed', 'not_allowed' === code( $r ) && null === meta( $p, 'locked' ), $r );
$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 3 ) );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
$r = call( 'meta-set', array( 'post_id' => $pg, 'key' => 'color', 'value' => 'x' ) );
$r2 = handler( 'meta-set', array( 'post_id' => $pg, 'key' => 'color', 'value' => 'x' ) );
check( 'strictest switch: a page\'s field while Pages is off is refused (gate; handler not_allowed)', 'FORBIDDEN' === $r && 'not_allowed' === code( $r2 ) && null === meta( $pg, 'color' ), array( $r, $r2 ) );
d5b_abilities_set_category( 'pages', true, false, 'tester' );
d5b_abilities_set_category( 'meta', false, false, 'tester' );
check( 'Custom fields off: refused', 'FORBIDDEN' === call( 'meta-set', array( 'post_id' => $p, 'key' => 'color', 'value' => 'x' ) ) );
site( READER );
$p = t_add_post( array( 'post_author' => 3 ) );
check( 'without edit_post: refused by the gate', 'FORBIDDEN' === call( 'meta-set', array( 'post_id' => $p, 'key' => 'color', 'value' => 'x' ) ) && null === meta( $p, 'color' ) );

echo "the two-step custom field delete\n";
site();
$p = t_add_post( array( 'post_author' => 3 ) );
t_add_meta( $p, 'gallery', array( 'ids' => array( 1, 2 ) ) ); t_add_meta( $p, 'gallery', 'second' );
$pv = call( 'meta-delete-preview', array( 'post_id' => $p, 'key' => 'gallery' ) );
check( 'the preview returns the count and every value, and a code; it changes nothing', is_array( $pv ) && 2 === $pv['count'] && array( array( 'ids' => array( 1, 2 ) ), 'second' ) === $pv['rows'] && 1 === preg_match( '/^[A-HJ-NP-Z2-9]{12}$/', $pv['confirmation'] ) && 2 === count( meta( $p, 'gallery' ) ) && array() === hrows(), $pv );
$r = call( 'meta-delete', array( 'post_id' => $p, 'key' => 'gallery' ) );
check( 'delete without a code: confirmation_invalid, nothing deleted', 'confirmation_invalid' === code( $r ) && 2 === count( meta( $p, 'gallery' ) ), $r );
$GLOBALS['T_meta'][ $p ]['gallery'][1] = 'changed';
$r = call( 'meta-delete', array( 'post_id' => $p, 'key' => 'gallery', 'confirmation' => $pv['confirmation'] ) );
check( 'a delete after a value changed is refused stale', 'stale' === reason( $r ) && 2 === count( meta( $p, 'gallery' ) ), $r );
$pv = call( 'meta-delete-preview', array( 'post_id' => $p, 'key' => 'gallery' ) );
break_history();
$r = call( 'meta-delete', array( 'post_id' => $p, 'key' => 'gallery', 'confirmation' => $pv['confirmation'] ) );
check( 'when the history row cannot be written: history_unavailable, every row still there', 'history_unavailable' === code( $r ) && 2 === count( meta( $p, 'gallery' ) ), $r );
mend_history();
$pv = call( 'meta-delete-preview', array( 'post_id' => $p, 'key' => 'gallery' ) );
$r = call( 'meta-delete', array( 'post_id' => $p, 'key' => 'gallery', 'confirmation' => $pv['confirmation'] ) );
$h = hlast();
check( 'a valid delete removes every row and returns the history id', is_array( $r ) && true === $r['deleted'] && 2 === $r['rows_deleted'] && null === meta( $p, 'gallery' ) && (int) $h['id'] === $r['history_id'], $r );
check( '... the history row holds the full value of every row', 'post_meta' === $h['object_type'] && 'delete' === $h['action'] && array( 'field' => 'gallery', 'rows' => array( array( 'ids' => array( 1, 2 ) ), 'changed' ) ) === $h['before'], $h );
t_add_meta( $p, 'c:\\path', 'one' ); t_add_meta( $p, 'cpath', 'innocent' );
$pv = call( 'meta-delete-preview', array( 'post_id' => $p, 'key' => 'c:\\path' ) );
$r = call( 'meta-delete', array( 'post_id' => $p, 'key' => 'c:\\path', 'confirmation' => $pv['confirmation'] ?? '' ) );
check( 'a key with a backslash is deleted exactly (the key is slashed for core), a look-alike key is untouched', is_array( $r ) && null === meta( $p, 'c:\\path' ) && array( 'innocent' ) === meta( $p, 'cpath' ), array( $r, $GLOBALS['T_meta'][ $p ] ?? null ) );
t_add_meta( $p, 'kept', 'v' );
$pv = call( 'meta-delete-preview', array( 'post_id' => $p, 'key' => 'kept' ) );
$GLOBALS['T_meta_delete_blocked'] = true;
$r = call( 'meta-delete', array( 'post_id' => $p, 'key' => 'kept', 'confirmation' => $pv['confirmation'] ) );
$GLOBALS['T_meta_delete_blocked'] = false;
check( 'when WordPress does not delete: write_failed and a delete_failed row', 'write_failed' === code( $r ) && array( 'v' ) === meta( $p, 'kept' ) && 'delete_failed' === hlast()['action'], $r );
check( 'protected and secret-named keys: the preview is refused not_allowed; a missing field is not_found',
	'not_allowed' === code( handler( 'meta-delete-preview', array( 'post_id' => $p, 'key' => '_thumbnail_id' ) ) ) && 'not_allowed' === code( handler( 'meta-delete-preview', array( 'post_id' => $p, 'key' => 'api_key' ) ) ) && 'not_found' === code( call( 'meta-delete-preview', array( 'post_id' => $p, 'key' => 'nothing' ) ) ) );
site( READER );
$p = t_add_post( array( 'post_author' => 3 ) ); t_add_meta( $p, 'color', 'red' );
check( 'without edit_post: preview and delete refused by the gate', 'FORBIDDEN' === call( 'meta-delete-preview', array( 'post_id' => $p, 'key' => 'color' ) ) && 'FORBIDDEN' === call( 'meta-delete', array( 'post_id' => $p, 'key' => 'color', 'confirmation' => 'X' ) ) && array( 'red' ) === meta( $p, 'color' ) );

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
