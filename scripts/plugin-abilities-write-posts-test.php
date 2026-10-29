<?php
/**
 * Write abilities for posts, pages, custom post types and reusable blocks (#32, release 2.2.0, Task 3).
 *
 *   php scripts/plugin-abilities-write-posts-test.php
 *
 * Loads the real inc/ files (audit, scrubber, change history, reads, writes) with WordPress stand-ins
 * (plugin-test-stubs.php: posts, meta, terms, revisions, trash kept in memory, behaving as core where the plugin depends on it).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: draft by default and publishing needs the capability; a content change needs the hash and a
 * stale one writes nothing; masked text and Divi blocks without unfiltered_html are refused; divi true sets the two flags;
 * trash then restore gives back the old status; cpt refuses the types with their own abilities; the strictest switch wins;
 * every write is audited once and writes one history row whose "before" rebuilds the item, with the content either in a
 * byte-identical revision or in the row; a refusal writes no history row; a site without a trash is never trashed (a
 * permanent delete); and the Abilities API stand-in refuses a null input when the schema has no default, as core does.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
if ( getenv( 'T_EMPTY_TRASH_0' ) ) { define( 'EMPTY_TRASH_DAYS', 0 ); }
define( 'D5B_VERSION', '2.2.0' );
require __DIR__ . '/plugin-test-stubs.php';
foreach ( array( 'parts', 'switches', 'abilities/categories', 'abilities/register', 'abilities/secrets', 'abilities/audit', 'abilities/scrub', 'abilities/history', 'abilities/read-content', 'abilities/write-common', 'abilities/write-posts' ) as $f ) { require $dir . '/inc/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ADMIN = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'unfiltered_html' );
const NO_UFH = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts' );
const CONTRIB = array( 'read', 'edit_posts', 'delete_posts' );

function site( array $caps = ADMIN, int $uid = 3 ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( false );
	$GLOBALS['T_user_id'] = $uid; $GLOBALS['T_users'] = array( 3 => (object) array( 'user_login' => 'ann' ), 5 => (object) array( 'user_login' => 'bob' ) );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
}
/** What a client does: the permission callback first, then the run. 'FORBIDDEN' when the gate says no. */
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function handler( $name, $in = array() ) { return call_user_func( d5b_ability_defs()[ 'divi5-builder/' . $name ]['handler'], $in ); }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function hrows() { global $wpdb; return $wpdb->tables[ d5b_history_table() ] ?? array(); }
function arows() { global $wpdb; return $wpdb->tables[ d5b_audit_table() ] ?? array(); }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function post( $id ) { return $GLOBALS['T_posts'][ (int) $id ] ?? null; }
function meta1( $id, $k ) { return $GLOBALS['T_meta'][ (int) $id ][ $k ][0] ?? null; }
function revs( $id ) { return array_values( array_filter( $GLOBALS['T_posts'], function ( $p ) use ( $id ) { return 'revision' === $p->post_type && (int) $p->post_parent === (int) $id; } ) ); }

/** The item as the stand-in store holds it (not through the plugin's helpers). */
function raw_state( $id ) {
	$p = post( $id ); $terms = array();
	foreach ( $GLOBALS['T_taxonomies'] as $tn => $t ) { if ( in_array( $p->post_type, (array) $t->object_type, true ) ) { $ids = array_map( 'intval', $GLOBALS['T_object_terms'][ $id ][ $tn ] ?? array() ); sort( $ids ); $terms[ $tn ] = $ids; } }
	ksort( $terms );
	$meta = array();
	foreach ( $GLOBALS['T_meta'][ $id ] ?? array() as $k => $vals ) { if ( '_' !== $k[0] && ! d5b_is_secret_key( $k ) ) { $meta[ $k ] = array_values( $vals ); } }
	ksort( $meta );
	return array( 'title' => $p->post_title, 'slug' => $p->post_name, 'status' => $p->post_status, 'excerpt' => $p->post_excerpt, 'author' => (int) $p->post_author, 'parent' => (int) $p->post_parent,
		'date_gmt' => $p->post_date_gmt, 'content' => $p->post_content, 'terms' => $terms, 'meta' => $meta, 'featured_image' => (int) ( meta1( $id, '_thumbnail_id' ) ?? 0 ),
		'divi_flags' => array( '_et_pb_use_builder' => (string) ( meta1( $id, '_et_pb_use_builder' ) ?? '' ), '_et_pb_use_divi_5' => (string) ( meta1( $id, '_et_pb_use_divi_5' ) ?? '' ) ) );
}
/** The item rebuilt from a history row alone (its before, and the revision it points at). */
function rebuilt( array $row ) {
	$b = $row['before'];
	$content = array_key_exists( 'content', $b ) ? $b['content'] : ( post( (int) $row['revision_id'] )->post_content ?? '<missing revision>' );
	return array( 'title' => $b['title'], 'slug' => $b['slug'], 'status' => $b['status'], 'excerpt' => $b['excerpt'], 'author' => $b['author'], 'parent' => $b['parent'],
		'date_gmt' => $b['date_gmt'], 'content' => $content, 'terms' => $b['terms'], 'meta' => $b['meta'], 'featured_image' => $b['featured_image'], 'divi_flags' => $b['divi_flags'] );
}

$names = array();
foreach ( array( 'posts', 'pages', 'cpt', 'blocks' ) as $k ) { foreach ( array( 'create', 'update', 'trash', 'restore' ) as $s ) { $names[] = "$k-$s"; } }

// ---- the subprocess: a site with no trash (EMPTY_TRASH_DAYS 0)
if ( getenv( 'T_EMPTY_TRASH_0' ) ) {
	site();
	$id = t_add_post( array( 'post_author' => 3 ) );
	$r = call( 'posts-trash', array( 'id' => $id ) );
	echo 'SUB:' . json_encode( array( 'code' => code( $r ), 'exists' => null !== post( $id ), 'status' => post( $id )->post_status ?? null, 'deleted' => $GLOBALS['T_deleted'], 'history' => count( hrows() ) ) ) . "\n";
	exit( 0 );
}

echo "registration\n";
$defs = d5b_ability_defs();
$bad = array();
foreach ( $names as $x ) {
	$d = $defs[ 'divi5-builder/' . $x ] ?? null;
	if ( null === $d ) { $bad[] = "$x missing"; continue; }
	if ( $d['category'] !== strtok( $x, '-' ) ) { $bad[] = "$x category"; }
	$an = $d['annotations'] ?? array();
	$destructive = '-trash' === substr( $x, -6 );
	if ( false !== ( $an['readonly'] ?? null ) || $destructive !== ( $an['destructive'] ?? null ) || false !== ( $an['idempotent'] ?? null ) ) { $bad[] = "$x annotations " . json_encode( $an ); }
	if ( d5b_ability_post_content_keys() !== ( $d['content_keys'] ?? null ) ) { $bad[] = "$x content keys"; }
	if ( ! is_callable( $d['handler'] ) || ! is_callable( $d['permission'] ) || 'd5b_wp_h_' . str_replace( '-', '_', $x ) !== $d['handler'] ) { $bad[] = "$x handler"; }
	if ( ! preg_match( '/return/i', $d['description'] ) || ! preg_match( '/refus/i', $d['description'] ) ) { $bad[] = "$x description"; }
}
check( 'all 16 write abilities are defined in their category, readonly false, destructive only for trash, not idempotent, content keys masked like the reads', array() === $bad, $bad );
check( 'update needs id; cpt needs type; create needs nothing else; trash/restore take only id (and type)', array( 'id' ) === $defs['divi5-builder/posts-update']['input_schema']['required'] && array( 'type', 'id' ) === $defs['divi5-builder/cpt-trash']['input_schema']['required']
	&& ! isset( $defs['divi5-builder/posts-create']['input_schema']['required'] ) && array( 'id' ) === array_keys( $defs['divi5-builder/pages-restore']['input_schema']['properties'] ) );
check( 'posts take categories and tags, cpt takes terms, pages take parent, blocks take neither divi nor a featured image',
	isset( $defs['divi5-builder/posts-create']['input_schema']['properties']['categories'], $defs['divi5-builder/cpt-update']['input_schema']['properties']['terms'], $defs['divi5-builder/pages-create']['input_schema']['properties']['parent'] )
	&& ! isset( $defs['divi5-builder/blocks-create']['input_schema']['properties']['divi'] ) && ! isset( $defs['divi5-builder/blocks-create']['input_schema']['properties']['featured_image'] ) && ! isset( $defs['divi5-builder/posts-create']['input_schema']['properties']['parent'] ) );
site();
add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' ); add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );
t_fire( 'wp_abilities_api_categories_init' ); t_fire( 'wp_abilities_api_init' );
$m = $GLOBALS['T_abilities']['divi5-builder/pages-trash']['meta']['annotations'] ?? array();
$u = $GLOBALS['T_abilities']['divi5-builder/pages-update']['meta']['annotations'] ?? array();
check( 'registered with core: pages-trash says readonly false, destructive true; pages-update destructive false', false === $m['readonly'] && true === $m['destructive'] && false === $m['idempotent'] && false === $u['readonly'] && false === $u['destructive'], array( $m, $u ) );

echo "Abilities API stand-in: a null input against the schema, as core\n";
$GLOBALS['T_doing'] = array( 'wp_abilities_api_init' );
wp_register_ability( 't/no-default', array( 'label' => 'x', 'description' => 'x', 'input_schema' => array( 'type' => 'object', 'properties' => array( 'a' => array( 'type' => 'string' ) ) ), 'execute_callback' => function ( $in ) { return 'ran'; }, 'permission_callback' => function () { return true; } ) );
wp_register_ability( 't/with-default', array( 'label' => 'x', 'description' => 'x', 'input_schema' => array( 'type' => 'object', 'default' => array(), 'properties' => array( 'a' => array( 'type' => 'string' ) ) ), 'execute_callback' => function ( $in ) { return $in; }, 'permission_callback' => function () { return true; } ) );
$GLOBALS['T_doing'] = array();
$t_yes = function () { return true; };
$r = wp_get_ability( 't/no-default' )->execute( null );
check( 'a def with no input default and a null input is refused ability_invalid_input (not of type object)', 'ability_invalid_input' === code( $r ) && false !== strpos( $r->get_error_message(), 'object' ), $r );
check( 'with a default the null input becomes the default and the callback runs', array() === wp_get_ability( 't/with-default' )->execute( null ) && 'ran' === wp_get_ability( 't/no-default' )->execute( array() ) );
check( 'a required property and additionalProperties false are enforced; no schema accepts only null', 'ability_invalid_input' === code( ( new T_Ability( 'x/y', array( 'input_schema' => array( 'type' => 'object', 'required' => array( 'id' ) ), 'execute_callback' => $t_yes, 'permission_callback' => $t_yes ) ) )->execute( array() ) )
	&& 'ability_invalid_input' === code( ( new T_Ability( 'x/y', array( 'input_schema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array() ), 'execute_callback' => $t_yes, 'permission_callback' => $t_yes ) ) )->execute( array( 'z' => 1 ) ) )
	&& 'ability_missing_input_schema' === code( ( new T_Ability( 'x/y', array( 'execute_callback' => $t_yes, 'permission_callback' => $t_yes ) ) )->execute( array( 'z' => 1 ) ) ) );
$r = wp_get_ability( 'divi5-builder/posts-create' )->execute( null );
check( 'the plugin registers its writes with a default, so a null input reaches the handler (here: creates an empty-titled draft? no: WordPress refuses empty content)', 'empty_content' === code( $r ), $r );

echo "create\n";
site();
$r = call( 'posts-create', array( 'title' => 'Hello', 'content' => 'Body' ) );
check( 'posts-create without status makes a draft and returns id, url and hash', is_array( $r ) && 'draft' === $r['status'] && $r['id'] > 0 && md5( 'Body' ) === $r['hash'] && '' !== $r['url'] && 'draft' === post( $r['id'] )->post_status, $r );
site( CONTRIB );
$before = count( $GLOBALS['T_posts'] );
$r = call( 'posts-create', array( 'title' => 'Hello', 'status' => 'publish' ) );
check( 'publish without publish_posts is refused not_allowed, nothing is created and no history row is written', 'not_allowed' === code( $r ) && count( $GLOBALS['T_posts'] ) === $before && array() === $GLOBALS['T_inserts'] && array() === hrows(), $r );
check( '... private and future too; pending and draft are fine', 'not_allowed' === code( call( 'posts-create', array( 'title' => 'x', 'status' => 'private' ) ) ) && 'not_allowed' === code( call( 'posts-create', array( 'title' => 'x', 'status' => 'future', 'date' => '2030-01-01T10:00:00Z' ) ) )
	&& 'pending' === call( 'posts-create', array( 'title' => 'x', 'status' => 'pending' ) )['status'] );
site();
$r = call( 'posts-create', array( 'title' => 'Hello', 'status' => 'publish' ) );
check( 'publish with publish_posts is published', 'publish' === ( $r['status'] ?? null ) && 'publish' === post( $r['id'] )->post_status, $r );
check( 'future needs a date; with a future date it is future (date read as UTC with Z, as site time without)', 'invalid_input' === code( call( 'posts-create', array( 'title' => 'x', 'status' => 'future' ) ) )
	&& 'future' === ( $f = call( 'posts-create', array( 'title' => 'x', 'status' => 'future', 'date' => '2030-01-01T12:00:00+02:00' ) ) )['status'] && '2030-01-01 10:00:00' === post( $f['id'] )->post_date_gmt );
check( 'bad input: unknown key, a title over 1000 characters, content over 1 MB, a bad date, status trash', 'invalid_input' === code( call( 'posts-create', array( 'title' => 'x', 'colour' => 'red' ) ) ) && 'invalid_input' === code( call( 'posts-create', array( 'title' => str_repeat( 'é', 1001 ) ) ) )
	&& 'invalid_input' === code( call( 'posts-create', array( 'content' => str_repeat( 'a', 1048577 ) ) ) ) && 'invalid_input' === code( call( 'posts-create', array( 'title' => 'x', 'date' => '2030-02-30T10:00:00' ) ) ) && 'invalid_input' === code( call( 'posts-create', array( 'title' => 'x', 'status' => 'trash' ) ) )
	&& is_array( call( 'posts-create', array( 'title' => str_repeat( 'é', 1000 ) ) ) ) );
$c = "<!-- wp:paragraph -->\n<p>A \"quote\" and a back\\slash and \\u003c</p>\n<!-- /wp:paragraph -->";
$r = call( 'posts-create', array( 'title' => 'Slash\\es', 'content' => $c, 'meta' => array( 'note' => 'a\\b' ) ) );
check( 'content, title and meta with backslashes are stored byte for byte (the write slashes, core unslashes)', $c === post( $r['id'] )->post_content && 'Slash\\es' === post( $r['id'] )->post_title && 'a\\b' === meta1( $r['id'], 'note' ) && md5( $c ) === $r['hash'] );

echo "related inputs\n";
site();
$cat = t_add_term( 'category', 'News' ); $tag = t_add_term( 'post_tag', 'Hot' ); $g = t_add_term( 'post_tag', 'Cold' );
$img = t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png' ) );
$pdf = t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'application/pdf' ) );
$r = call( 'posts-create', array( 'title' => 'T', 'categories' => array( $cat ), 'tags' => array( $tag, $g ), 'featured_image' => $img, 'meta' => array( 'colour' => 'red', 'n' => 3 ), 'author' => 5 ) );
check( 'categories, tags, featured image, meta and author are set', is_array( $r ) && array( $cat ) === $GLOBALS['T_object_terms'][ $r['id'] ]['category'] && array( $tag, $g ) === $GLOBALS['T_object_terms'][ $r['id'] ]['post_tag'] && $img === (int) meta1( $r['id'], '_thumbnail_id' ) && 'red' === meta1( $r['id'], 'colour' ) && 3 === meta1( $r['id'], 'n' ) && 5 === (int) post( $r['id'] )->post_author, $r );
$P = $r['id'];
check( 'tags [] removes the tags; other fields untouched', is_array( call( 'posts-update', array( 'id' => $P, 'tags' => array() ) ) ) && array() === $GLOBALS['T_object_terms'][ $P ]['post_tag'] && array( $cat ) === $GLOBALS['T_object_terms'][ $P ]['category'] );
check( 'a protected (_) or secret-named custom field is refused not_allowed', 'not_allowed' === code( call( 'posts-update', array( 'id' => $P, 'meta' => array( '_edit_lock' => 'x' ) ) ) ) && 'not_allowed' === code( call( 'posts-update', array( 'id' => $P, 'meta' => array( 'stripe_api_key' => 'x' ) ) ) ) && 'invalid_input' === code( call( 'posts-update', array( 'id' => $P, 'meta' => array( 'a' => array( 1 ) ) ) ) ) );
check( 'a term of another taxonomy, an unknown term, a non-image or missing featured image are refused invalid_input', 'invalid_input' === code( call( 'posts-update', array( 'id' => $P, 'categories' => array( $tag ) ) ) ) && 'invalid_input' === code( call( 'posts-update', array( 'id' => $P, 'tags' => array( 99999 ) ) ) )
	&& 'invalid_input' === code( call( 'posts-update', array( 'id' => $P, 'featured_image' => $pdf ) ) ) && 'invalid_input' === code( call( 'posts-update', array( 'id' => $P, 'featured_image' => 99999 ) ) ) );
check( 'featured_image 0 removes it', is_array( call( 'posts-update', array( 'id' => $P, 'featured_image' => 0 ) ) ) && null === meta1( $P, '_thumbnail_id' ) );
check( 'an unknown author is invalid_input', 'invalid_input' === code( call( 'posts-update', array( 'id' => $P, 'author' => 77 ) ) ) );
site( array( 'read', 'edit_posts', 'publish_posts' ) );
check( 'another author without edit_others_posts is refused not_allowed; yourself is fine', 'not_allowed' === code( call( 'posts-create', array( 'title' => 'x', 'author' => 5 ) ) ) && is_array( call( 'posts-create', array( 'title' => 'x', 'author' => 3 ) ) ) );
site();
$top = t_add_post( array( 'post_type' => 'page' ) ); $kid = t_add_post( array( 'post_type' => 'page', 'post_parent' => $top ) ); $apost = t_add_post();
check( 'pages: parent must be a page you can edit, and never the page itself or one of its children', is_array( call( 'pages-create', array( 'title' => 'x', 'parent' => $top ) ) ) && 'invalid_input' === code( call( 'pages-create', array( 'title' => 'x', 'parent' => $apost ) ) )
	&& 'invalid_input' === code( call( 'pages-update', array( 'id' => $top, 'parent' => $kid ) ) ) && 'invalid_input' === code( call( 'pages-update', array( 'id' => $top, 'parent' => $top ) ) ) && is_array( call( 'pages-update', array( 'id' => $kid, 'parent' => 0 ) ) ) );
t_add_type( 'book', true, true, false ); t_add_taxonomy( 'genre', true, true, array( 'book' ) ); $gen = t_add_term( 'genre', 'Crime' );
$r = call( 'cpt-create', array( 'type' => 'book', 'title' => 'B', 'terms' => array( 'genre' => array( $gen ) ) ) );
check( 'cpt terms by taxonomy are set; a taxonomy not registered for the type is refused; a non-hierarchical type takes no parent', is_array( $r ) && array( $gen ) === $GLOBALS['T_object_terms'][ $r['id'] ]['genre']
	&& 'invalid_input' === code( call( 'cpt-create', array( 'type' => 'book', 'title' => 'B', 'terms' => array( 'category' => array( $cat ) ) ) ) ) && 'invalid_input' === code( call( 'cpt-create', array( 'type' => 'book', 'title' => 'B', 'parent' => $r['id'] ) ) ), $r );

echo "update and the hash\n";
site();
$id = t_add_post( array( 'post_author' => 3, 'post_content' => 'Original', 'post_title' => 'Old title' ) );
check( 'content without expect_hash is refused invalid_input', 'invalid_input' === code( call( 'posts-update', array( 'id' => $id, 'content' => 'New' ) ) ) && 'Original' === post( $id )->post_content );
$a0 = count( arows() );
$r = call( 'posts-update', array( 'id' => $id, 'content' => 'New', 'title' => 'New title', 'expect_hash' => md5( 'something else' ) ) );
$d =is_wp_error( $r ) ? $r->get_error_data() : array();
check( 'a wrong hash answers stale with last_saved (ISO 8601) and the current hash, HTTP 409', 'stale' === code( $r ) && '2026-10-02T10:00:00Z' === ( $d['last_saved'] ?? null ) && md5( 'Original' ) === ( $d['hash'] ?? null ) && 409 === ( $d['status'] ?? null ), $d );
check( '... and leaves the content (and the title given with it) byte-identical, with no update call and no history row', 'Original' === post( $id )->post_content && 'Old title' === post( $id )->post_title && array() === $GLOBALS['T_updates'] && array() === hrows() );
check( '... and the refusal is audited once (error:stale)', $a0 + 1 === count( arows() ) && 'error:stale' === end( $GLOBALS['wpdb']->tables['wp_d5b_audit'] )['status'] );
$r = call( 'posts-update', array( 'id' => $id, 'title' => 'Only the title' ) );
check( 'posts-update with the title only needs no hash', is_array( $r ) && 'Only the title' === post( $id )->post_title && 'Original' === post( $id )->post_content, $r );
$r = call( 'posts-update', array( 'id' => $id, 'content' => 'New', 'expect_hash' => strtoupper( md5( 'Original' ) ) ) );
check( 'the right hash writes and returns the new hash', is_array( $r ) && 'New' === post( $id )->post_content && md5( 'New' ) === $r['hash'], $r );
$bs = '<!-- wp:divi/text {"content":"a \\"b\\" \\u003c"} /--> C:\\path';
$r = call( 'posts-update', array( 'id' => $id, 'content' => $bs, 'title' => 'Back\\slash', 'meta' => array( 'p' => 'x\\y' ), 'expect_hash' => md5( 'New' ) ) );
check( 'an update with backslashes stores content, title and meta byte for byte (slashed for core)', is_array( $r ) && $bs === post( $id )->post_content && 'Back\\slash' === post( $id )->post_title && 'x\\y' === meta1( $id, 'p' ) && md5( $bs ) === $r['hash'], $r );
check( 'nothing to change, an item in the trash, and a page id through posts-update are refused', 'invalid_input' === code( call( 'posts-update', array( 'id' => $id ) ) )
	&& 'invalid_input' === code( handler( 'posts-update', array( 'id' => t_add_post( array( 'post_status' => 'trash', 'post_author' => 3 ) ), 'title' => 'x' ) ) ) && 'FORBIDDEN' === call( 'posts-update', array( 'id' => t_add_post( array( 'post_type' => 'page' ) ), 'title' => 'x' ) ) );
site( CONTRIB );
$own = t_add_post( array( 'post_author' => 3, 'post_status' => 'draft' ) ); $theirs = t_add_post( array( 'post_author' => 5, 'post_status' => 'draft' ) );
check( 'a contributor updates their own draft; another user\'s draft (which they cannot read) is refused, not_found from the handler', is_array( call( 'posts-update', array( 'id' => $own, 'title' => 'Mine' ) ) ) && 'FORBIDDEN' === call( 'posts-update', array( 'id' => $theirs, 'title' => 'x' ) ) && 'not_found' === code( handler( 'posts-update', array( 'id' => $theirs, 'title' => 'x' ) ) ) );
check( '... and may not publish it by update', 'not_allowed' === code( call( 'posts-update', array( 'id' => $own, 'status' => 'publish' ) ) ) && 'draft' === post( $own )->post_status );

echo "masked reads\n";
site();
$id = t_add_post( array( 'post_author' => 3, 'post_content' => 'key=sk_live_abc' ) );
$r = call( 'posts-update', array( 'id' => $id, 'content' => 'key=[redacted] and more', 'expect_hash' => md5( 'key=sk_live_abc' ) ) );
check( 'an update whose content holds [redacted] while the stored content does not is refused masked_read', 'masked_read' === code( $r ) && 'key=sk_live_abc' === post( $id )->post_content && array() === hrows(), $r );
check( '... the title and excerpt are checked the same way (no hash needed to be refused)', 'masked_read' === code( call( 'posts-update', array( 'id' => $id, 'title' => 'T [redacted]' ) ) ) && 'masked_read' === code( call( 'posts-update', array( 'id' => $id, 'excerpt' => '[redacted]' ) ) ) );
$id2 = t_add_post( array( 'post_author' => 3, 'post_content' => 'The word [redacted] is in this article.' ) );
check( 'stored content that already holds [redacted] may be written with it', is_array( call( 'posts-update', array( 'id' => $id2, 'content' => 'The word [redacted] is still in this article.', 'expect_hash' => md5( 'The word [redacted] is in this article.' ) ) ) ) );

echo "Divi content\n";
$divi = '<!-- wp:divi/section {"module":{"meta":{"adminLabel":{"desktop":{"value":"Hero \u0022x\u0022"}}}}} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\u003cp\u003eHi\u003c/p\u003e"}}}} /--><!-- /wp:divi/section -->';
site( NO_UFH );
$id = t_add_post( array( 'post_author' => 3, 'post_content' => 'plain' ) );
check( 'Divi-block content from a user without unfiltered_html is refused needs_unfiltered_html (create and update)', 'needs_unfiltered_html' === code( call( 'posts-create', array( 'title' => 'x', 'content' => $divi ) ) ) && 'needs_unfiltered_html' === code( call( 'posts-update', array( 'id' => $id, 'content' => $divi, 'expect_hash' => md5( 'plain' ) ) ) ) && 'plain' === post( $id )->post_content );
check( '... plain content is accepted', is_array( call( 'posts-update', array( 'id' => $id, 'content' => '<p>still plain</p>', 'expect_hash' => md5( 'plain' ) ) ) ) );
site();
$r = call( 'pages-create', array( 'title' => 'Divi page', 'content' => $divi, 'divi' => true ) );
check( 'divi: true sets _et_pb_use_builder and _et_pb_use_divi_5 to on; the Divi JSON is stored byte for byte', is_array( $r ) && 'on' === meta1( $r['id'], '_et_pb_use_builder' ) && 'on' === meta1( $r['id'], '_et_pb_use_divi_5' ) && $divi === post( $r['id'] )->post_content, $r );
$r = call( 'pages-create', array( 'title' => 'No flags', 'content' => $divi ) );
check( 'without divi the flags are not set', null === meta1( $r['id'], '_et_pb_use_builder' ) );
$pg = $r['id'];
check( 'divi: true on update sets them too', is_array( call( 'pages-update', array( 'id' => $pg, 'divi' => true ) ) ) && 'on' === meta1( $pg, '_et_pb_use_builder' ) && 'on' === meta1( $pg, '_et_pb_use_divi_5' ) );

echo "trash and restore\n";
site();
$id = t_add_post( array( 'post_author' => 3, 'post_status' => 'publish', 'post_name' => 'hello' ) );
$r = call( 'posts-trash', array( 'id' => $id ) );
check( 'posts-trash moves it to the trash and returns restore_with', is_array( $r ) && 'trash' === post( $id )->post_status && 'divi5-builder/posts-restore' === $r['restore_with'], $r );
check( 'trashing it again is refused cannot_trash (409)', 'cannot_trash' === code( $x = call( 'posts-trash', array( 'id' => $id ) ) ) && 409 === $x->get_error_data()['status'] );
$r = call( 'posts-restore', array( 'id' => $id ) );
check( 'trash then restore returns the previous status (publish), not draft, and the slug', is_array( $r ) && 'publish' === post( $id )->post_status && 'publish' === $r['status'] && 'hello' === post( $id )->post_name, $r );
check( 'restoring an item that is not in the trash is refused not_found', 'not_found' === code( call( 'posts-restore', array( 'id' => $id ) ) ) );
check( 'the untrash filter is removed after the call', array() === ( $GLOBALS['T_filters']['wp_untrash_post_status'] ?? array() ) );
$pv = t_add_post( array( 'post_author' => 3, 'post_status' => 'private' ) );
call( 'posts-trash', array( 'id' => $pv ) ); call( 'posts-restore', array( 'id' => $pv ) );
check( 'a private item comes back private', 'private' === post( $pv )->post_status );
site( array( 'read', 'edit_posts', 'edit_published_posts', 'delete_posts', 'delete_published_posts' ) );
$id = t_add_post( array( 'post_author' => 3, 'post_status' => 'publish' ) );
call( 'posts-trash', array( 'id' => $id ) ); $r = call( 'posts-restore', array( 'id' => $id ) );
check( 'a caller without publish_posts gets a published item back as a draft (never a publish nobody may make)', is_array( $r ) && 'draft' === post( $id )->post_status, $r );
site( CONTRIB );
$id = t_add_post( array( 'post_author' => 3, 'post_status' => 'publish' ) );
check( 'a contributor may not trash a published post (delete_published_posts)', 'FORBIDDEN' === call( 'posts-trash', array( 'id' => $id ) ) && 'publish' === post( $id )->post_status );
site( array( 'read', 'edit_posts', 'edit_published_posts', 'delete_posts' ) );
$id = t_add_post( array( 'post_author' => 3, 'post_status' => 'publish' ) );
check( 'trash needs delete_post, not edit_post: a user who may edit but not delete a published post is refused (also by the handler)', is_array( call( 'posts-update', array( 'id' => $id, 'title' => 'ok' ) ) ) && 'FORBIDDEN' === call( 'posts-trash', array( 'id' => $id ) ) && 'not_allowed' === code( handler( 'posts-trash', array( 'id' => $id ) ) ) && 'publish' === post( $id )->post_status );
site();
$GLOBALS['T_trash_blocked'] = true; $id = t_add_post( array( 'post_author' => 3 ) );
check( 'a trash a plugin stops is reported cannot_trash and writes no history row', 'cannot_trash' === code( call( 'posts-trash', array( 'id' => $id ) ) ) && array() === hrows() );
putenv( 'T_EMPTY_TRASH_0=1' );
$sub = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d max_execution_time=30 ' . escapeshellarg( __FILE__ ) . ' 2>&1' );
putenv( 'T_EMPTY_TRASH_0' );
$sj = preg_match( '/^SUB:(.*)$/m', $sub, $mm ) ? json_decode( $mm[1], true ) : null;
check( 'a site with no trash (EMPTY_TRASH_DAYS 0): trash is refused cannot_trash and the post is NOT deleted', is_array( $sj ) && 'cannot_trash' === $sj['code'] && true === $sj['exists'] && array() === $sj['deleted'] && 0 === $sj['history'], $sub );

echo "cpt and the types with their own abilities\n";
site();
t_add_type( 'book', true, true, true );
foreach ( array( 'wp_template', 'et_pb_layout', 'post', 'page', 'attachment', 'wp_block' ) as $t ) {
	$x = t_add_post( array( 'post_type' => $t, 'post_author' => 3 ) );
	$codes = array( code( call( 'cpt-create', array( 'type' => $t, 'title' => 'x' ) ) ), code( call( 'cpt-update', array( 'type' => $t, 'id' => $x, 'title' => 'x' ) ) ), code( call( 'cpt-trash', array( 'type' => $t, 'id' => $x ) ) ), code( call( 'cpt-restore', array( 'type' => $t, 'id' => $x ) ) ) );
	check( "cpt-* refuses $t with not_allowed and changes nothing", array( 'not_allowed', 'not_allowed', 'not_allowed', 'not_allowed' ) === $codes && 'Title ' . $x === post( $x )->post_title && 'publish' === post( $x )->post_status, $codes );
}
$bk = t_add_post( array( 'post_type' => 'book', 'post_author' => 3 ) ); $pid = t_add_post( array( 'post_author' => 3 ) );
check( 'cpt-update on a book works; a post id under type book is refused; pages-update refuses a post id', is_array( call( 'cpt-update', array( 'type' => 'book', 'id' => $bk, 'title' => 'Book' ) ) ) && 'FORBIDDEN' === call( 'cpt-update', array( 'type' => 'book', 'id' => $pid, 'title' => 'x' ) )
	&& 'FORBIDDEN' === call( 'pages-update', array( 'id' => $pid, 'title' => 'x' ) ) && 'not_found' === code( handler( 'pages-update', array( 'id' => $pid, 'title' => 'x' ) ) ) && 'Title ' . $pid === post( $pid )->post_title );
$blk = t_add_post( array( 'post_type' => 'wp_block', 'post_author' => 3, 'post_content' => '<!-- wp:paragraph --><p>b</p><!-- /wp:paragraph -->' ) );
check( 'blocks-update carries expect_hash like the others', 'invalid_input' === code( call( 'blocks-update', array( 'id' => $blk, 'content' => 'x' ) ) ) && is_array( call( 'blocks-update', array( 'id' => $blk, 'content' => 'x', 'expect_hash' => md5( '<!-- wp:paragraph --><p>b</p><!-- /wp:paragraph -->' ) ) ) ) );

echo "strictest switch\n";
site();
$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 3 ) ); $blk = t_add_post( array( 'post_type' => 'wp_block', 'post_author' => 3 ) );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
check( 'pages off: cpt-update on a page is refused, pages-update is refused, the page is unchanged', 'not_allowed' === code( call( 'cpt-update', array( 'type' => 'page', 'id' => $pg, 'title' => 'x' ) ) ) && 'FORBIDDEN' === call( 'pages-update', array( 'id' => $pg, 'title' => 'x' ) ) && 'FORBIDDEN' === call( 'pages-create', array( 'title' => 'x' ) ) && 'Title ' . $pg === post( $pg )->post_title );
check( '... and the handler alone answers not_allowed for the page (the switch of its type)', 'not_allowed' === code( handler( 'pages-update', array( 'id' => $pg, 'title' => 'x' ) ) ) && 'not_allowed' === code( handler( 'pages-trash', array( 'id' => $pg ) ) ) && 'not_allowed' === code( handler( 'pages-create', array( 'title' => 'x' ) ) ) );
d5b_abilities_set_category( 'blocks', false, false, 'tester' );
check( 'blocks off: blocks-update and blocks-trash are refused and the block is unchanged', 'FORBIDDEN' === call( 'blocks-update', array( 'id' => $blk, 'title' => 'x' ) ) && 'FORBIDDEN' === call( 'blocks-trash', array( 'id' => $blk ) ) && 'not_allowed' === code( handler( 'blocks-update', array( 'id' => $blk, 'title' => 'x' ) ) ) && 'Title ' . $blk === post( $blk )->post_title && 'publish' === post( $blk )->post_status );
check( '... posts still work', is_array( call( 'posts-create', array( 'title' => 'x' ) ) ) );
check( 'no history row for any refusal above', 1 === count( hrows() ) );

echo "change history\n";
site();
$cat = t_add_term( 'category', 'News' );
$img = t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' ) );
$a0 = count( arows() );
$r = call( 'posts-create', array( 'title' => 'First', 'content' => 'v1', 'categories' => array( $cat ), 'meta' => array( 'colour' => 'red' ), 'featured_image' => $img, 'divi' => true ) );
$id = $r['id']; $h = hlast();
check( 'create: one history row (action create, object_type post, before null, after the whole item, summary)', 1 === count( hrows() ) && 'create' === $h['action'] && 'post' === $h['object_type'] && $id === $h['object_id'] && 'divi5-builder/posts-create' === $h['ability'] && array() === $h['before'] && 'v1' === $h['after']['content'] && 'First' === $h['after']['title'] && "Created post $id 'First'" === $h['summary'], $h );
check( '... audited once (ok) and the history row points at that audit row', $a0 + 1 === count( arows() ) && 'ok' === end( $GLOBALS['wpdb']->tables['wp_d5b_audit'] )['status'] && (int) end( $GLOBALS['wpdb']->tables['wp_d5b_audit'] )['id'] === $h['audit_id'] );
$snap = raw_state( $id );
$r = call( 'posts-update', array( 'id' => $id, 'title' => 'Second', 'content' => 'v2', 'expect_hash' => md5( 'v1' ), 'categories' => array(), 'meta' => array( 'colour' => 'blue' ), 'featured_image' => 0 ) );
$h = hlast();
check( 'first update: no revision holds the old content, so before.content has it and revision_id is null', is_array( $r ) && 'update' === $h['action'] && 'v1' === ( $h['before']['content'] ?? null ) && null === $h['revision_id'], $h );
check( '... the item rebuilt from before equals the item before the write (every field, terms, meta, image, Divi flags)', $snap === rebuilt( $h ), array( $snap, rebuilt( $h ) ) );
check( '... after holds the changed fields only', array( 'categories' ) !== array_keys( $h['after'] ) && 'Second' === $h['after']['title'] && 'v2' === $h['after']['content'] && ! isset( $h['after']['author'] ) && ! isset( $h['after']['slug'] ) && "Updated post $id 'Second'" === $h['summary'], $h['after'] );
$snap = raw_state( $id );
$rv = revs( $id ); $match = end( $rv );
call( 'posts-update', array( 'id' => $id, 'content' => 'v3', 'expect_hash' => md5( 'v2' ) ) );
$h = hlast();
check( 'second update: revision_id points at the revision holding v2 (not the one the update made), and before has no content', (int) $match->ID === $h['revision_id'] && 'v2' === post( $h['revision_id'] )->post_content && ! array_key_exists( 'content', $h['before'] ), $h );
check( '... and rebuilds the item', $snap === rebuilt( $h ), array( $snap, rebuilt( $h ) ) );
$GLOBALS['T_revisions_keep'] = 1;
$snap = raw_state( $id );
call( 'posts-update', array( 'id' => $id, 'content' => 'v4', 'expect_hash' => md5( 'v3' ) ) );
$h = hlast();
check( 'WP_POST_REVISIONS 1: the update pruned the matching revision, so the content is in before (never a dangling revision_id)', 'v3' === ( $h['before']['content'] ?? null ) && null === $h['revision_id'] && $snap === rebuilt( $h ), $h );
$GLOBALS['T_revisions_keep'] = 0;
$snap = raw_state( $id );
call( 'posts-update', array( 'id' => $id, 'content' => 'v5', 'expect_hash' => md5( 'v4' ) ) );
$h = hlast();
check( 'revisions off: before.content holds the previous content', 'v4' === ( $h['before']['content'] ?? null ) && null === $h['revision_id'] && $snap === rebuilt( $h ) );
$GLOBALS['T_revisions_keep'] = -1;
t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id, 'post_name' => $id . '-autosave-v1', 'post_content' => 'v5' ) );
call( 'posts-update', array( 'id' => $id, 'content' => 'v6', 'expect_hash' => md5( 'v5' ) ) );
$h = hlast();
check( 'an autosave with the same content is never the revision pointed at (autosaves are overwritten)', 'v5' === ( $h['before']['content'] ?? null ) && null === $h['revision_id'], $h );
t_add_type( 'book', true, true, true ); $bk = t_add_post( array( 'post_type' => 'book', 'post_author' => 3, 'post_content' => 'book text' ) );
$snap = raw_state( $bk );
call( 'cpt-update', array( 'type' => 'book', 'id' => $bk, 'content' => 'new text', 'expect_hash' => md5( 'book text' ) ) );
$h = hlast();
check( 'a type without revision support: before.content holds it; object_type is the post type; summary names the type', 'book text' === ( $h['before']['content'] ?? null ) && 'book' === $h['object_type'] && "Updated book $bk 'Title $bk'" === $h['summary'] && $snap === rebuilt( $h ), $h );
$snap = raw_state( $id );
call( 'posts-trash', array( 'id' => $id ) ); $h = hlast();
check( 'trash: a history row (action trash) whose before rebuilds the item with its old status and slug', 'trash' === $h['action'] && $snap === rebuilt( $h ) && 'trash' === $h['after']['status'] && "Trashed post $id 'Second'" === $h['summary'], $h );
call( 'posts-restore', array( 'id' => $id ) ); $h = hlast();
check( 'restore: a history row (action restore), before says trash, after says the old status', 'restore' === $h['action'] && 'trash' === $h['before']['status'] && $snap['status'] === $h['after']['status'] );
$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 3, 'post_title' => 'Contact us' ) );
call( 'pages-update', array( 'id' => $pg, 'excerpt' => 'x' ) );
check( 'the summary is one plain sentence: "Updated page 42 \'Contact us\'"', "Updated page $pg 'Contact us'" === hlast()['summary'] );
$h0 = count( hrows() ); $a0 = count( arows() );
foreach ( array( array( 'posts-update', array( 'id' => $id, 'content' => 'x', 'expect_hash' => 'bad' ) ), array( 'posts-create', array( 'title' => 'x', 'meta' => array( '_x' => 1 ) ) ), array( 'pages-trash', array( 'id' => 999999 ) ), array( 'posts-restore', array( 'id' => $id ) ) ) as $c ) { call( $c[0], $c[1] ); }
check( 'each refusal is audited once and writes no history row', $h0 === count( hrows() ) && $a0 + 4 === count( arows() ) );
$a0 = count( arows() ); $h0 = count( hrows() );
call( 'pages-update', array( 'id' => $pg, 'title' => 'Contact' ) );
check( 'each write is audited once and writes one history row', $a0 + 1 === count( arows() ) && $h0 + 1 === count( hrows() ) );
unset( $GLOBALS['wpdb']->tables['wp_d5b_history'] ); $GLOBALS['T_dbdelta_fail'] = true; set_transient( 'd5b_audit_install_failed', 1, 3600 );
$r = call( 'pages-update', array( 'id' => $pg, 'title' => 'Contact again' ) );
check( 'when the history row cannot be stored the write stands, the caller never sees _history_failed, and the audit row says ok_no_history', is_array( $r ) && ! array_key_exists( '_history_failed', $r ) && 'Contact again' === post( $pg )->post_title && 'ok_no_history' === end( $GLOBALS['wpdb']->tables['wp_d5b_audit'] )['status'], $r );
check( 'the result of a write is the detail row (id, url, hash, status, content) with no masked flag for plain content', isset( $r['id'], $r['url'], $r['hash'], $r['status'], $r['content'] ) && ! isset( $r['masked'] ) );

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
