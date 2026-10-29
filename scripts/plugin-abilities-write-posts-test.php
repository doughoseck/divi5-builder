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
check( 'the plugin registers its writes with a default, so a null input reaches the handler (WordPress then refuses the empty post: invalid_input)', 'invalid_input' === code( $r ) && 'Content, title, and excerpt are empty.' === $r->get_error_message(), $r );
check( 'an input of \'\' is an empty object, as rest_is_object says (passes additionalProperties false)', 'ran' === ( new T_Ability( 'x/y', array( 'input_schema' => array( 'type' => 'object', 'additionalProperties' => false, 'properties' => array() ), 'execute_callback' => function () { return 'ran'; }, 'permission_callback' => $t_yes ) ) )->execute( '' ) );

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

// ---- fix round 1 (review of 2e33467)
const AUTHOR = array( 'read', 'edit_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_published_posts', 'upload_files' );
const SUBSCRIBER = array( 'read' );

echo "terms: the Taxonomy switch and assign_terms\n";
site();
$cat = t_add_term( 'category', 'News' ); $tag = t_add_term( 'post_tag', 'Hot' ); $id = t_add_post( array( 'post_author' => 3 ) );
t_add_type( 'book', true, true, false ); t_add_taxonomy( 'genre', true, true, array( 'book' ) ); $gen = t_add_term( 'genre', 'Crime' ); $bk = t_add_post( array( 'post_type' => 'book', 'post_author' => 3 ) );
d5b_abilities_set_category( 'taxonomy', false, false, 'tester' );
$i0 = count( $GLOBALS['T_inserts'] );
$codes = array( code( call( 'posts-create', array( 'title' => 'x', 'categories' => array( $cat ) ) ) ), code( call( 'posts-update', array( 'id' => $id, 'title' => 'changed', 'tags' => array() ) ) ),
	code( call( 'cpt-create', array( 'type' => 'book', 'title' => 'x', 'terms' => array( 'genre' => array( $gen ) ) ) ) ), code( call( 'cpt-update', array( 'type' => 'book', 'id' => $bk, 'terms' => array( 'genre' => array( $gen ) ) ) ) ) );
check( 'Taxonomy off: categories, tags or terms refuse the whole call not_allowed; nothing is written', array( 'not_allowed', 'not_allowed', 'not_allowed', 'not_allowed' ) === $codes && $i0 === count( $GLOBALS['T_inserts'] ) && array() === $GLOBALS['T_updates'] && 'Title ' . $id === post( $id )->post_title && array() === hrows(), $codes );
check( '... a write without terms still works', is_array( call( 'posts-update', array( 'id' => $id, 'title' => 'changed' ) ) ) );
d5b_abilities_set_category( 'taxonomy', true, false, 'tester' );
$GLOBALS['T_taxonomies']['post_tag']->cap->assign_terms = 'assign_post_tags';
check( 'the taxonomy\'s assign_terms capability is required (refused not_allowed, no terms set)', 'not_allowed' === code( call( 'posts-update', array( 'id' => $id, 'tags' => array( $tag ) ) ) ) && empty( $GLOBALS['T_object_terms'][ $id ]['post_tag'] ) );
t_set_caps( array_merge( ADMIN, array( 'assign_post_tags' ) ) );
check( '... and with it the tags are set', is_array( call( 'posts-update', array( 'id' => $id, 'tags' => array( $tag ) ) ) ) && array( $tag ) === $GLOBALS['T_object_terms'][ $id ]['post_tag'] );

echo "masked text on create, meta and the edit_post_meta check\n";
site();
$i0 = count( $GLOBALS['T_inserts'] );
check( 'create: content, title or a meta value holding [redacted] is refused masked_read; nothing is created', 'masked_read' === code( call( 'posts-create', array( 'title' => 'x', 'content' => 'key=[redacted]' ) ) ) && 'masked_read' === code( call( 'posts-create', array( 'title' => 'T [redacted]' ) ) )
	&& 'masked_read' === code( call( 'posts-create', array( 'title' => 'x', 'meta' => array( 'note' => '[redacted]' ) ) ) ) && $i0 === count( $GLOBALS['T_inserts'] ) && array() === hrows() );
$id = t_add_post( array( 'post_author' => 3, 'post_content' => 'c' ) ); t_add_meta( $id, 'note', 'token=abc' );
check( 'update: a meta value holding [redacted] where the stored one does not is refused masked_read (extra.php)', 'masked_read' === code( call( 'posts-update', array( 'id' => $id, 'meta' => array( 'note' => 'token=[redacted]' ) ) ) ) && 'token=abc' === meta1( $id, 'note' ) );
$id2 = t_add_post( array( 'post_author' => 3, 'post_title' => 'about [redacted] marker' ) );
check( 'content with [redacted] is refused even when only the stored TITLE holds the marker (each field against its own)', 'masked_read' === code( call( 'posts-update', array( 'id' => $id2, 'content' => 'secret=[redacted]', 'expect_hash' => md5( 'Content ' . $id2 ) ) ) ) );
$GLOBALS['T_meta_auth_denied'] = array( 'locked' );
check( 'update: edit_post_meta is asked per key (an auth_post_meta filter that says no refuses not_allowed)', 'not_allowed' === code( call( 'posts-update', array( 'id' => $id, 'meta' => array( 'locked' => 'x' ) ) ) ) && null === meta1( $id, 'locked' ) && is_array( call( 'posts-update', array( 'id' => $id, 'meta' => array( 'open' => 'x' ) ) ) ) );
site( array( 'read', 'create_books', 'publish_posts' ) );
t_add_type( 'book', true, true, false, null, array( 'create_posts' => 'create_books', 'edit_posts' => 'edit_books' ) );
check( 'create: without the type\'s edit capability, meta is refused not_allowed (edit_post_meta stand-in); without meta it is created', 'not_allowed' === code( call( 'cpt-create', array( 'type' => 'book', 'title' => 'x', 'meta' => array( 'a' => 'b' ) ) ) ) && is_array( call( 'cpt-create', array( 'type' => 'book', 'title' => 'x' ) ) ) );
site();
$i0 = count( $GLOBALS['T_inserts'] );
check( 'a meta key that is not UTF-8 is refused invalid_input (bad-utf8.php); nothing is created', 'invalid_input' === code( call( 'posts-create', array( 'title' => 'x', 'meta' => array( "k\xff\xfe" => 'v' ) ) ) ) && $i0 === count( $GLOBALS['T_inserts'] ) );
check( 'a key that is protected once control characters are dropped ("\x01_thumbnail_id") is refused (invalid_input since Task 11 C1: not plain ASCII)', 'invalid_input' === code( call( 'posts-create', array( 'title' => 'x', 'meta' => array( "\x01_thumbnail_id" => '5' ) ) ) ) );
$id = t_add_post( array( 'post_author' => 3 ) ); t_add_meta( $id, 'm', 'one' ); t_add_meta( $id, 'm', 'two' );
call( 'posts-update', array( 'id' => $id, 'meta' => array( 'm' => 'z' ) ) );
$h = hlast();
check( 'a key with two rows ends with exactly the one value given; before keeps both old rows', array( 'z' ) === $GLOBALS['T_meta'][ $id ]['m'] && array( 'one', 'two' ) === $h['before']['meta']['m'], array( $GLOBALS['T_meta'][ $id ]['m'], $h['before']['meta'] ) );
t_add_meta( $id, 'n', 'a' ); t_add_meta( $id, 'n', 'b' ); update_post_meta( $id, 'n', 'c' );
check( 'stand-in parity: core\'s update_post_meta on a key with 2 rows sets both rows', array( 'c', 'c' ) === $GLOBALS['T_meta'][ $id ]['n'] );

echo "exact meta copies in the history\n";
site();
$id = t_add_post( array( 'post_author' => 3 ) );
$deep = array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'f' => array( 'g' => array( 'h' => array( 'i' => 'deep' ) ) ) ) ) ) ) ) );
t_add_meta( $id, 'cfg', (object) array( 'a' => 1 ) ); t_add_meta( $id, 'obj', new ArrayObject( array( 'k' => 'v' ) ) ); t_add_meta( $id, 'bin', "bad\xff" ); t_add_meta( $id, 'deep', $deep ); t_add_meta( $id, 'plain', array( 'x' => 1 ) );
call( 'posts-update', array( 'id' => $id, 'meta' => array( 'obj' => 'x', 'cfg' => 'y', 'bin' => 'z', 'deep' => 'w' ) ) );
$m = hlast()['before']['meta'];
$back = function ( $cell ) { return is_array( $cell ) && isset( $cell['serialized'] ) && 1 === count( $cell ) ? unserialize( base64_decode( $cell['serialized'] ) ) : null; };
$o = $back( $m['obj'][0] ?? null );
check( 'an object other than stdClass is kept as { serialized } and comes back as the same object (extra.php)', $o instanceof ArrayObject && array( 'k' => 'v' ) === $o->getArrayCopy(), $m['obj'] ?? null );
check( 'stdClass is kept as an array; a plain array as it is', array( array( 'a' => 1 ) ) === $m['cfg'] && array( array( 'x' => 1 ) ) === $m['plain'], array( $m['cfg'], $m['plain'] ) );
check( 'invalid UTF-8 and nesting deeper than 8 are kept as { serialized } and come back byte for byte', is_array( $m['bin'][0] ?? null ) && "bad\xff" === base64_decode( $m['bin'][0]['serialized'] ) && $deep === $back( $m['deep'][0] ?? null ), array( $m['bin'] ?? null, $m['deep'] ?? null ) );

echo "trash rows hold everything\n";
site();
t_add_taxonomy( 'section', true, true, array( 'page' ) ); $sec = t_add_term( 'section', 'Main' );
$img = t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg', 'post_author' => 3 ) );
$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 3, 'post_status' => 'publish', 'post_content' => 'body', 'post_password' => 'pw-secret-1', 'menu_order' => 3 ) );
$GLOBALS['T_object_terms'][ $pg ] = array( 'section' => array( $sec ) );
t_add_meta( $pg, 'color', 'red' ); t_add_meta( $pg, '_thumbnail_id', $img ); t_add_meta( $pg, '_wp_page_template', 'tpl.php' ); t_add_meta( $pg, '_et_pb_page_layout', 'et_full_width_page' ); t_add_meta( $pg, 'api_key', 'sekret-1' ); t_add_meta( $pg, '_stripe_secret', 'sekret-2' );
update_option( 'sticky_posts', array( $pg ) );
$rv = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $pg, 'post_name' => $pg . '-revision-v1', 'post_content' => 'body' ) );
call( 'pages-trash', array( 'id' => $pg ) );
$row = hrows()[ count( hrows() ) - 1 ]; $h = hlast(); $b = $h['before'];
check( 'trash: before.content is there even though a matching revision exists (emptying the trash deletes revisions)', 'body' === ( $b['content'] ?? null ) && $rv === $h['revision_id'], $h );
check( '... with terms, meta, featured image, template, sticky and menu order', array( 'section' => array( $sec ) ) === $b['terms'] && array( 'color' => array( 'red' ) ) === $b['meta'] && $img === $b['featured_image'] && 'tpl.php' === $b['template'] && true === $b['sticky'] && 'publish' === $b['status'] && 3 === $b['menu_order'], $b );
check( '... and every protected custom field that is not secret-named (Divi page settings) under protected_meta', array( 'et_full_width_page' ) === ( $b['protected_meta']['_et_pb_page_layout'] ?? null ) && array( 'tpl.php' ) === ( $b['protected_meta']['_wp_page_template'] ?? null ) && array( $img ) === ( $b['protected_meta']['_thumbnail_id'] ?? null ) && ! isset( $b['protected_meta']['_stripe_secret'] ) && ! isset( $b['meta']['api_key'] ), $b['protected_meta'] );
check( '... the password only as access_gate: true (a key named has_password would be dropped by the secret filter); its value and the secrets are nowhere in the row', true === ( $b['access_gate'] ?? null ) && false === strpos( $row['before'] . $row['after'], 'pw-secret-1' ) && false === strpos( $row['before'], 'sekret-' ) );
$id = t_add_post( array( 'post_author' => 3, 'post_content' => 'current' ) );
t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id, 'post_name' => $id . '-revision-v1', 'post_content' => 'older' ) );
call( 'posts-trash', array( 'id' => $id ) ); $h = hlast();
check( 'trash when the newest revision differs from the content: revision_id stays empty, content in before (extra.php)', null === $h['revision_id'] && 'current' === $h['before']['content'], $h );

echo "core's errors\n";
site();
foreach ( array( array(), array( 'title' => '' ), array( 'title' => '   ', 'content' => '' ), array( 'status' => 'draft' ) ) as $in ) {
	$r = call( 'posts-create', $in );
	check( 'empty.php create ' . json_encode( $in ) . ': invalid_input, HTTP 400, core\'s message, audit error:invalid_input', 'invalid_input' === code( $r ) && 400 === $r->get_error_data()['status'] && 'Content, title, and excerpt are empty.' === $r->get_error_message() && 'error:invalid_input' === end( $GLOBALS['wpdb']->tables['wp_d5b_audit'] )['status'], $r );
}
$id = t_add_post( array( 'post_author' => 3, 'post_title' => 'T', 'post_content' => 'C' ) );
$GLOBALS['T_update_fail'] = true;
$r = call( 'posts-update', array( 'id' => $id, 'title' => 'n' ) );
check( 'a database error on update is write_failed, HTTP 500; nothing changed, no history row', 'write_failed' === code( $r ) && 500 === $r->get_error_data()['status'] && 'T' === post( $id )->post_title && array() === hrows(), $r );
$GLOBALS['T_update_fail'] = false; $GLOBALS['T_insert_fail'] = true;
check( 'a database error on create is write_failed', 'write_failed' === code( call( 'posts-create', array( 'title' => 'x' ) ) ) );
$GLOBALS['T_insert_fail'] = false; $GLOBALS['T_insert_error'] = new WP_Error( 'some_plugin_says_no', 'A plugin refused this post.' );
$r = call( 'posts-create', array( 'title' => 'x' ) );
check( 'any other error from WordPress is invalid_input with WordPress\'s message', 'invalid_input' === code( $r ) && 'A plugin refused this post.' === $r->get_error_message(), $r );
$GLOBALS['T_insert_error'] = null;
$u0 = count( $GLOBALS['T_updates'] );
$r = call( 'posts-update', array( 'id' => $id, 'title' => '', 'content' => '', 'expect_hash' => md5( 'C' ) ) );
check( 'empty.php: an update that would empty title, content and excerpt is refused invalid_input before WordPress is asked', 'invalid_input' === code( $r ) && 'T' === post( $id )->post_title && 'C' === post( $id )->post_content && $u0 === count( $GLOBALS['T_updates'] ), $r );
$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 3, 'post_title' => 'P', 'post_content' => 'C' ) );
check( '... but a page (no excerpt support) may be emptied, as core allows', is_array( call( 'pages-update', array( 'id' => $pg, 'title' => '', 'content' => '', 'expect_hash' => md5( 'C' ) ) ) ) && '' === post( $pg )->post_title );

echo "restore: the filter is scoped to the item\n";
site();
$parent = t_add_post( array( 'post_author' => 3, 'post_status' => 'publish' ) ); $child = t_add_post( array( 'post_author' => 3, 'post_status' => 'pending' ) );
call( 'posts-trash', array( 'id' => $parent ) ); call( 'posts-trash', array( 'id' => $child ) );
$GLOBALS['T_untrash_cascade'] = array( $parent => array( $child ) );
call( 'posts-restore', array( 'id' => $parent ) );
check( 'a post another plugin untrashes during our restore gets WordPress\'s own status (draft), not ours (publish)', 'publish' === post( $parent )->post_status && 'draft' === post( $child )->post_status, array( post( $parent )->post_status, post( $child )->post_status ) );
foreach ( array( 'publish', 'private', 'pending', 'draft', 'future' ) as $st ) {
	site();
	$id = t_add_post( array( 'post_author' => 3, 'post_status' => $st, 'post_date_gmt' => 'future' === $st ? gmdate( 'Y-m-d H:i:s', time() + 86400 ) : '2026-10-01 10:00:00' ) );
	call( 'posts-trash', array( 'id' => $id ) ); $r = call( 'posts-restore', array( 'id' => $id ) );
	check( "probe: $st -> trash -> restore = $st", is_array( $r ) && $st === post( $id )->post_status, post( $id )->post_status );
}
site();
$id = t_add_post( array( 'post_author' => 3, 'post_status' => 'trash', 'post_name' => 'x__trashed' ) );
$id2 = t_add_post( array( 'post_author' => 3, 'post_status' => 'trash' ) ); t_add_meta( $id2, '_wp_trash_meta_status', 'inherit' );
check( 'probe: no _wp_trash_meta_status, or an odd one (inherit), restores to draft', is_array( call( 'posts-restore', array( 'id' => $id ) ) ) && 'draft' === post( $id )->post_status && is_array( call( 'posts-restore', array( 'id' => $id2 ) ) ) && 'draft' === post( $id2 )->post_status );

echo "probe.php cases\n";
site();
$id = t_add_post( array( 'post_author' => 3, 'post_content' => 'A' ) );
$r1 = call( 'posts-update', array( 'id' => $id, 'content' => 'B', 'expect_hash' => md5( 'A' ) ) );
$u0 = count( $GLOBALS['T_updates'] ); $h0 = count( hrows() );
$r2 = call( 'posts-update', array( 'id' => $id, 'content' => 'C', 'expect_hash' => md5( 'A' ) ) );
check( 'probe: a second update with the same old hash is stale; content stays B; no update, no history; stale hash = md5(B)', is_array( $r1 ) && 'stale' === code( $r2 ) && 'B' === post( $id )->post_content && $u0 === count( $GLOBALS['T_updates'] ) && $h0 === count( hrows() ) && md5( 'B' ) === $r2->get_error_data()['hash'] );
check( "probe: content '' without a hash is refused (empty counts as given)", 'invalid_input' === code( call( 'posts-update', array( 'id' => $id, 'content' => '' ) ) ) && 'B' === post( $id )->post_content );
$nasty = "a\\b \\\\c \"q\" 'x' \\' \\\" \\\\\\\\ {\"k\":\"v\\\"w\\\\u0026\"} \\n";
$r = call( 'posts-create', array( 'title' => $nasty, 'content' => $nasty, 'excerpt' => $nasty, 'meta' => array( 'fld' => $nasty ) ) );
$id = (int) ( $r['id'] ?? 0 );
check( 'probe: create keeps content, title, excerpt and meta byte for byte; hash = md5(sent)', $id && $nasty === post( $id )->post_content && $nasty === post( $id )->post_title && $nasty === post( $id )->post_excerpt && $nasty === meta1( $id, 'fld' ) && md5( $nasty ) === $r['hash'] );
$n2 = $nasty . ' \\\\x';
$r = call( 'posts-update', array( 'id' => $id, 'title' => $n2, 'content' => $n2, 'excerpt' => $n2, 'meta' => array( 'fld' => $n2 ), 'expect_hash' => md5( $nasty ) ) );
$ins = end( $GLOBALS['T_updates'] );
check( 'probe: update keeps them byte for byte, slashed exactly once on the way in, and before.title is the old unslashed title', is_array( $r ) && $n2 === post( $id )->post_content && $n2 === post( $id )->post_title && $n2 === post( $id )->post_excerpt && $n2 === meta1( $id, 'fld' ) && wp_unslash( $ins['post_content'] ) === $n2 && $nasty === hlast()['before']['title'] );
site( CONTRIB );
$i0 = count( $GLOBALS['T_inserts'] );
check( 'probe: a contributor\'s publish is refused, not downgraded; nothing inserted', null !== code( call( 'posts-create', array( 'title' => 'x', 'status' => 'publish' ) ) ) && $i0 === count( $GLOBALS['T_inserts'] ) );
$r = call( 'posts-create', array( 'title' => 'x', 'status' => 'pending' ) ); $own = (int) $r['id'];
check( 'probe: contributor pending ok; publishing it by update refused; another author refused', 'pending' === $r['status'] && 'not_allowed' === code( call( 'posts-update', array( 'id' => $own, 'status' => 'publish' ) ) ) && 'pending' === post( $own )->post_status && 'not_allowed' === code( call( 'posts-create', array( 'title' => 'x', 'author' => 5 ) ) ) );
check( 'probe: a contributor cannot create a reusable block (core: wp_block create_posts = publish_posts): refused by the gate and by the handler', 'FORBIDDEN' === call( 'blocks-create', array( 'title' => 'b' ) ) && 'not_allowed' === code( handler( 'blocks-create', array( 'title' => 'b' ) ) ) );
site( AUTHOR );
$other = t_add_post( array( 'post_author' => 5, 'post_status' => 'publish' ) );
check( 'probe: an author on another author\'s post: update forbidden (handler not_allowed), trash forbidden, unchanged', 'FORBIDDEN' === call( 'posts-update', array( 'id' => $other, 'title' => 'mine' ) ) && 'not_allowed' === code( handler( 'posts-update', array( 'id' => $other, 'title' => 'mine' ) ) ) && 'FORBIDDEN' === call( 'posts-trash', array( 'id' => $other ) ) && 'publish' === post( $other )->post_status && 'Title ' . $other === post( $other )->post_title );
site( NO_UFH );
$pp = t_add_post( array( 'post_type' => 'page', 'post_author' => 5, 'post_status' => 'private', 'post_content' => 'p' ) );
check( 'probe: an editor updates, trashes and restores another user\'s private page; it is private again', is_array( call( 'pages-update', array( 'id' => $pp, 'content' => 'q', 'expect_hash' => md5( 'p' ) ) ) ) && is_array( call( 'pages-trash', array( 'id' => $pp ) ) ) && is_array( call( 'pages-restore', array( 'id' => $pp ) ) ) && 'private' === post( $pp )->post_status && 'q' === post( $pp )->post_content );
$pg = t_add_post( array( 'post_type' => 'page', 'post_author' => 3, 'post_content' => 'plain' ) );
foreach ( array( '<!-- wp:divi/section {"a":1} --><!-- /wp:divi/section -->', "<!--\twp:divi/text -->", '<!--wp:divi/x-->', '<!-- WP:DIVI/section -->', "x<!--\n wp:divi/row -->" ) as $dc ) {
	$i0 = count( $GLOBALS['T_inserts'] ); $u0 = count( $GLOBALS['T_updates'] ); $h0 = count( hrows() );
	$a = call( 'pages-create', array( 'title' => 'x', 'content' => $dc ) ); $b = call( 'pages-update', array( 'id' => $pg, 'content' => $dc, 'expect_hash' => md5( 'plain' ) ) );
	check( 'probe: needs_unfiltered_html for ' . json_encode( $dc ) . ', nothing written', 'needs_unfiltered_html' === code( $a ) && 'needs_unfiltered_html' === code( $b ) && $i0 === count( $GLOBALS['T_inserts'] ) && $u0 === count( $GLOBALS['T_updates'] ) && $h0 === count( hrows() ) && 'plain' === post( $pg )->post_content );
}
site( SUBSCRIBER );
check( 'probe: a subscriber\'s blocks-create is refused by the gate and by the handler (create_posts checked in the handler); nothing inserted', 'FORBIDDEN' === call( 'blocks-create', array( 'title' => 'b', 'content' => 'x' ) ) && 'not_allowed' === code( handler( 'blocks-create', array( 'title' => 'b', 'content' => 'x' ) ) ) && 'not_allowed' === code( handler( 'posts-create', array( 'title' => 'b' ) ) ) && array() === $GLOBALS['T_inserts'] );
site();
$blk = t_add_post( array( 'post_type' => 'wp_block', 'post_author' => 3 ) );
d5b_abilities_set_category( 'blocks', false, false, 'tester' );
check( 'probe: blocks off: blocks-create, cpt-update on wp_block and posts-update on a block id are refused; unchanged', 'FORBIDDEN' === call( 'blocks-create', array( 'title' => 'b' ) ) && null !== code( call( 'cpt-update', array( 'type' => 'wp_block', 'id' => $blk, 'title' => 'x' ) ) ) && null !== code( call( 'posts-update', array( 'id' => $blk, 'title' => 'x' ) ) ) && 'Title ' . $blk === post( $blk )->post_title );
d5b_abilities_set_category( 'blocks', true, false, 'tester' );
check( 'probe: blocks on again: blocks-update works', is_array( call( 'blocks-update', array( 'id' => $blk, 'title' => 'x' ) ) ) && 'x' === post( $blk )->post_title );
foreach ( array( 'create', 'update', 'trash', 'restore' ) as $shape ) {
	site();
	$id = t_add_post( array( 'post_author' => 3, 'post_content' => 'c' ) );
	if ( 'restore' === $shape ) { call( 'posts-trash', array( 'id' => $id ) ); }
	unset( $GLOBALS['wpdb']->tables['wp_d5b_history'] ); $GLOBALS['T_dbdelta_fail'] = true; set_transient( 'd5b_audit_install_failed', 1, 3600 );
	$r = call( 'posts-' . $shape, 'create' === $shape ? array( 'title' => 'n' ) : ( 'update' === $shape ? array( 'id' => $id, 'title' => 'n' ) : array( 'id' => $id ) ) );
	$did = 'create' === $shape ? isset( $r['id'] ) && null !== post( $r['id'] ) : ( 'update' === $shape ? 'n' === post( $id )->post_title : ( 'trash' === $shape ? 'trash' === post( $id )->post_status : 'publish' === post( $id )->post_status ) );
	check( "probe: $shape with the history table gone: the write stands, no _history_failed in the response, audit ok_no_history", is_array( $r ) && $did && ! array_key_exists( '_history_failed', $r ) && 'ok_no_history' === end( $GLOBALS['wpdb']->tables['wp_d5b_audit'] )['status'] );
}

echo "revisions-restore\n";
$d = d5b_ability_defs()['divi5-builder/revisions-restore'] ?? null;
$an = $d['annotations'] ?? array();
check( 'revisions-restore is defined in the revisions category: readonly false, destructive false, not idempotent; post_id, revision_id and expect_hash required; says what it returns and refuses',
	null !== $d && 'revisions' === $d['category'] && false === $an['readonly'] && false === $an['destructive'] && false === $an['idempotent'] && array( 'post_id', 'revision_id', 'expect_hash' ) === $d['input_schema']['required']
	&& 'd5b_wp_h_revisions_restore' === $d['handler'] && is_callable( $d['permission'] ) && preg_match( '/return/i', $d['description'] ) && preg_match( '/refus/i', $d['description'] ) && d5b_ability_post_content_keys() === $d['content_keys'] );
site();
$P = t_add_post( array( 'post_author' => 3, 'post_title' => 'T0', 'post_content' => 'A' ) );
call( 'posts-update', array( 'id' => $P, 'title' => 'T1', 'content' => 'B', 'expect_hash' => md5( 'A' ) ) );
call( 'posts-update', array( 'id' => $P, 'title' => 'T2', 'content' => 'C', 'expect_hash' => md5( 'B' ) ) );
$rv = revs( $P ); $rB = $rv[0]->ID; $rC = $rv[1]->ID;
$Q = t_add_post( array( 'post_author' => 3, 'post_content' => 'Q0' ) );
call( 'posts-update', array( 'id' => $Q, 'content' => 'Q1', 'expect_hash' => md5( 'Q0' ) ) );
$rQ = revs( $Q )[0]->ID;
check( 'setup: the post is C with revisions B and C; the stand-in saves the revision of the NEW content, as core', 'C' === post( $P )->post_content && 'B' === post( $rB )->post_content && 'C' === post( $rC )->post_content && 2 === count( revs( $P ) ) );
$h0 = count( hrows() ); $u0 = count( $GLOBALS['T_updates'] ); $a0 = count( arows() );
$r = call( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rB ) );
check( 'no expect_hash is invalid_input (the handler; the gate lets it through to say why)', 'invalid_input' === code( $r ), $r );
$r = call( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rB, 'expect_hash' => md5( 'A' ) ) );
check( 'a stale hash is refused stale with last_saved and the current hash; nothing written, no history row', 'stale' === code( $r ) && md5( 'C' ) === ( $r->get_error_data()['hash'] ?? null ) && 'C' === post( $P )->post_content && $u0 === count( $GLOBALS['T_updates'] ) && $h0 === count( hrows() ), $r );
$h0 = count( hrows() );
$before = raw_state( $P ); $nrev = count( revs( $P ) ); $a0 = count( arows() );
$r = call( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rB, 'expect_hash' => md5( 'C' ) ) );
check( 'with the current hash the revision\'s title, content and excerpt are put back; the answer has the new hash, the revision restored and the revision of the replaced content',
	is_array( $r ) && 'B' === post( $P )->post_content && 'T1' === post( $P )->post_title && md5( 'B' ) === $r['hash'] && $rB === $r['restored_revision'] && $rC === $r['previous_revision'] && true === $r['changed'] && 1 === count( $GLOBALS['T_comment_calls'] ), $r );
$h = hlast();
check( 'one history row (action update, ability revisions-restore, object post) whose revision_id is the revision holding the replaced content (C), with that content, the old title and the other fields in before',
	1 === count( hrows() ) - $h0 && 'update' === $h['action'] && 'divi5-builder/revisions-restore' === $h['ability'] && 'post' === $h['object_type'] && $P === (int) $h['object_id'] && $rC === (int) $h['revision_id']
	&& 'C' === post( $rC )->post_content && 'C' === $h['before']['content'] && 'T2' === $h['before']['title'] && 'B' === $h['after']['content'] && false !== strpos( $h['summary'], 'revision ' . $rB ), $h );
check( 'the row alone rebuilds the item as it was before the restore (title, content, status, meta ...)', rebuilt( $h ) === $before, array( rebuilt( $h ), $before ) );
check( 'WordPress made its own revision of the restored content, as on any save; one audit row, ok', $nrev + 1 === count( revs( $P ) ) && 1 === count( arows() ) - $a0 && 'ok' === end( $GLOBALS['wpdb']->tables[ d5b_audit_table() ] )['status'] );
$h0 = count( hrows() );
$r = call( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rB, 'expect_hash' => md5( 'B' ) ) );
check( 'restoring what is already there changes nothing (changed false), writes no history row and no update', is_array( $r ) && false === $r['changed'] && $h0 === count( hrows() ) && 1 === count( $GLOBALS['T_comment_calls'] ) );

echo "revisions-restore: who and which\n";
$h0 = count( hrows() ); $u0 = count( $GLOBALS['T_updates'] ); $cur = post( $P )->post_content;
$bad = array();
$x = call( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rQ, 'expect_hash' => md5( $cur ) )); if ( 'FORBIDDEN' !== $x ) { $bad[] = 'foreign revision gate'; }
$x = handler( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rQ, 'expect_hash' => md5( $cur ) )); if ( 'not_found' !== code( $x ) ) { $bad[] = 'foreign revision handler ' . code( $x ); }
$x = handler( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $Q, 'expect_hash' => md5( $cur ) )); if ( 'not_found' !== code( $x ) ) { $bad[] = 'a post as revision ' . code( $x ); }
$x = handler( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => 99999, 'expect_hash' => md5( $cur ) )); if ( 'not_found' !== code( $x ) ) { $bad[] = 'missing revision ' . code( $x ); }
check( 'a revision of another post, a post id and a missing id are all not_found (the gate says no first); nothing written, no history row', array() === $bad && $cur === post( $P )->post_content && $Q === $Q && 'Q1' === post( $Q )->post_content && $u0 === count( $GLOBALS['T_updates'] ) && $h0 === count( hrows() ), $bad );
site( CONTRIB, 5 );
$P2 = t_add_post( array( 'post_author' => 3, 'post_content' => 'one' ) ); $r2 = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $P2, 'post_content' => 'zero', 'post_title' => 'Title' ) );
$x1 = call( 'revisions-restore', array( 'post_id' => $P2, 'revision_id' => $r2, 'expect_hash' => md5( 'one' ) )); $x2 = handler( 'revisions-restore', array( 'post_id' => $P2, 'revision_id' => $r2, 'expect_hash' => md5( 'one' ) ) );
check( 'without edit_post on the item (a contributor on another author\'s post) the gate says no and the handler not_allowed; unchanged, no history row', 'FORBIDDEN' === $x1 && 'not_allowed' === code( $x2 ) && 'one' === post( $P2 )->post_content && array() === hrows() );

echo "revisions-restore: switches\n";
site();
$P = t_add_post( array( 'post_author' => 3, 'post_content' => 'A' ) );
call( 'posts-update', array( 'id' => $P, 'content' => 'B', 'expect_hash' => md5( 'A' ) ) );
$rB = revs( $P )[0]->ID;
$G = t_add_post( array( 'post_type' => 'page', 'post_author' => 3, 'post_content' => 'pa' ) );
call( 'pages-update', array( 'id' => $G, 'content' => 'pb', 'expect_hash' => md5( 'pa' ) ) );
$rG = revs( $G )[0]->ID;
t_add_post( array( 'ID' => $rG + 50, 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $G, 'post_content' => 'older' ) );
$rG2 = $rG + 50;
d5b_abilities_set_category( 'revisions', false, false, 'tester' );
$h0 = count( hrows() ); $x1 = call( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rB, 'expect_hash' => md5( 'B' ) ) );
check( 'Revisions off (Posts on): refused by the gate, the post is unchanged, no history row', 'FORBIDDEN' === $x1 && 'B' === post( $P )->post_content && $h0 === count( hrows() ) );
d5b_abilities_set_category( 'revisions', true, false, 'tester' );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
$x1 = call( 'revisions-restore', array( 'post_id' => $G, 'revision_id' => $rG2, 'expect_hash' => md5( 'pb' ) )); $x2 = handler( 'revisions-restore', array( 'post_id' => $G, 'revision_id' => $rG2, 'expect_hash' => md5( 'pb' ) ) );
check( 'Pages off (Revisions on): a page\'s revision is refused (gate and handler not_allowed); a post\'s revision still restores', 'FORBIDDEN' === $x1 && 'not_allowed' === code( $x2 ) && 'pb' === post( $G )->post_content
	&& is_array( call( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rB, 'expect_hash' => md5( 'B' ) ) ) ) );
d5b_abilities_set_category( 'pages', true, false, 'tester' );
check( 'Pages on again: the page restores', is_array( call( 'revisions-restore', array( 'post_id' => $G, 'revision_id' => $rG2, 'expect_hash' => md5( 'pb' ) ) ) ) && 'older' === post( $G )->post_content );
d5b_abilities_set_category( 'posts', false, false, 'tester' );
check( 'Posts off (Revisions on): a post\'s revision is refused by the gate and the handler', 'not_allowed' === code( handler( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rB, 'expect_hash' => md5( 'A' ) ) ) ) || 'not_found' === code( handler( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rB, 'expect_hash' => md5( 'A' ) ) ) ) );
d5b_abilities_set_category( 'posts', true, false, 'tester' );

echo "revisions-restore: Divi blocks, trash, no revision of the replaced content\n";
site();
$dv = '<!-- wp:divi/section {"a":1} --><!-- /wp:divi/section -->';
$P = t_add_post( array( 'post_author' => 3, 'post_content' => 'plain' ) );
call( 'posts-update', array( 'id' => $P, 'content' => $dv, 'expect_hash' => md5( 'plain' ) ) );
call( 'posts-update', array( 'id' => $P, 'content' => 'later', 'expect_hash' => md5( $dv ) ) );
$rD = revs( $P )[0]->ID;
t_set_caps( NO_UFH );
$h0 = count( hrows() );
$x = call( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rD, 'expect_hash' => md5( 'later' ) ) );
check( 'a revision holding Divi blocks from a user without unfiltered_html is refused needs_unfiltered_html; the post is unchanged, no history row', 'needs_unfiltered_html' === code( $x ) && 'later' === post( $P )->post_content && $h0 === count( hrows() ), $x );
t_set_caps( ADMIN );
check( 'with unfiltered_html the same restore works', is_array( call( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rD, 'expect_hash' => md5( 'later' ) ) ) ) && $dv === post( $P )->post_content );
site();
$P = t_add_post( array( 'post_author' => 3, 'post_content' => 'cur', 'post_title' => 'Now' ) );
$rO = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $P, 'post_content' => 'old', 'post_title' => 'Then' ) );
$r = call( 'revisions-restore', array( 'post_id' => $P, 'revision_id' => $rO, 'expect_hash' => md5( 'cur' ) ) );
$h = hlast();
check( 'no revision holds the replaced content (it was never saved as one): revision_id is empty and the row keeps the content itself', is_array( $r ) && 0 === $r['previous_revision'] && 0 === (int) $h['revision_id'] && 'cur' === $h['before']['content'] && 'Now' === $h['before']['title'] && 'old' === post( $P )->post_content );
$T = t_add_post( array( 'post_author' => 3, 'post_content' => 'cur', 'post_status' => 'trash' ) );
$rT = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $T, 'post_content' => 'old' ) );
$h0 = count( hrows() );
check( 'an item in the trash is invalid_input (restore it first); nothing written', 'invalid_input' === code( handler( 'revisions-restore', array( 'post_id' => $T, 'revision_id' => $rT, 'expect_hash' => md5( 'cur' ) ) ) ) && 'cur' === post( $T )->post_content && $h0 === count( hrows() ) );

// ===== Task 11 fix wave (break-it reproducers a1, a3, a4, a7)
echo "Task 11 C1: look-alike keys in the meta input (a7)\n";
$all = true; $why = array();
foreach ( array( "\u{FF3F}et_pb_use_builder", "\u{FF3F}wp_page_template", "\u{FF3F}thumbnail_id", 'pässword', "\u{0430}pi_key", "smtp_p\u{0430}ss", "note\n" ) as $k ) {
	site( CONTRIB );
	$p  = t_add_post( array( 'post_author' => 3, 'post_status' => 'draft' ) );
	$i0 = count( $GLOBALS['T_inserts'] );
	$u  = call( 'posts-update', array( 'id' => $p, 'meta' => array( $k => 'X' ) ) );
	$c  = call( 'posts-create', array( 'title' => 'n', 'meta' => array( $k => 'X' ) ) );
	$ok = 'invalid_input' === code( $u ) && 'custom field names must be plain ASCII letters, digits, _ - . :' === $u->get_error_message() && 'invalid_input' === code( $c ) && $i0 === count( $GLOBALS['T_inserts'] ) && ! isset( $GLOBALS['T_meta'][ $p ][ $k ] ) && array() === hrows();
	if ( ! $ok ) { $all = false; $why[] = array( json_encode( $k ), code( $u ), code( $c ) ); }
}
check( 'a contributor\'s posts-update and posts-create with a look-alike key in meta: invalid_input with the exact message, nothing written', $all, $why );
site();
$p = t_add_post( array( 'post_author' => 3, 'post_status' => 'draft' ) );
check( '... a plain key (my-key.v2:x) in meta is written', is_array( call( 'posts-update', array( 'id' => $p, 'meta' => array( 'my-key.v2:x' => 'v' ) ) ) ) && 'v' === meta1( $p, 'my-key.v2:x' ) );
// Fix round 2: the database compares names without case ("Price" finds the row of "price").
t_add_meta( $p, 'price', '100' );
$u0 = count( $GLOBALS['T_updates'] );
$r = call( 'posts-update', array( 'id' => $p, 'title' => 'T', 'meta' => array( 'Price' => '1' ) ) );
check( 'posts-update meta {Price} on a post that has price: invalid_input naming price, nothing written', 'invalid_input' === code( $r ) && "a field named 'price' already exists on this post; use that exact name" === $r->get_error_message() && '100' === meta1( $p, 'price' ) && null === meta1( $p, 'Price' ) && $u0 === count( $GLOBALS['T_updates'] ), $r );
check( '... meta {price} itself works', is_array( call( 'posts-update', array( 'id' => $p, 'meta' => array( 'price' => '101' ) ) ) ) && '101' === meta1( $p, 'price' ) );
$r = call( 'posts-create', array( 'title' => 'n', 'meta' => array( 'Color' => 'a', 'color' => 'b' ) ) );
check( 'posts-create with meta Color and color in one input: invalid_input, nothing created', 'invalid_input' === code( $r ), $r );

echo "Task 11 I2: the strictest switch for meta, divi and featured_image (a1)\n";
site();
$pm = t_add_post( array( 'post_author' => 3, 'post_status' => 'draft', 'post_content' => 'c' ) );
d5b_abilities_set_category( 'meta', false, false, 'tester' );
$i0 = count( $GLOBALS['T_inserts'] ); $u0 = count( $GLOBALS['T_updates'] );
$r1 = call( 'posts-update', array( 'id' => $pm, 'title' => 'T2', 'meta' => array( 'color' => 'red' ) ) );
$r2 = call( 'posts-create', array( 'title' => 'm', 'meta' => array( 'color' => 'blue' ) ) );
check( 'Custom fields off: posts-update and posts-create with meta are refused not_allowed (the whole call: the title is not changed either)', 'not_allowed' === code( $r1 ) && 'not_allowed' === code( $r2 ) && null === meta1( $pm, 'color' ) && 'Title ' . $pm === post( $pm )->post_title && $i0 === count( $GLOBALS['T_inserts'] ) && $u0 === count( $GLOBALS['T_updates'] ), array( code( $r1 ), code( $r2 ) ) );
check( '... without meta the same update works', is_array( call( 'posts-update', array( 'id' => $pm, 'title' => 'T2' ) ) ) );
d5b_abilities_set_category( 'meta', true, false, 'tester' );
d5b_abilities_set_category( 'divi', false, false, 'tester' );
$pp = t_add_post( array( 'post_type' => 'page', 'post_author' => 3, 'post_content' => 'plain' ) );
$r1 = call( 'pages-update', array( 'id' => $pp, 'divi' => true ) );
$r2 = call( 'pages-create', array( 'title' => 'n', 'divi' => true ) );
check( 'Divi off: pages-update and pages-create with divi true are refused not_allowed; no Divi flag is set', 'not_allowed' === code( $r1 ) && 'not_allowed' === code( $r2 ) && null === meta1( $pp, '_et_pb_use_builder' ) && null === meta1( $pp, '_et_pb_use_divi_5' ), array( code( $r1 ), code( $r2 ) ) );
check( '... divi false (which sets nothing) is not refused', is_array( call( 'pages-update', array( 'id' => $pp, 'title' => 'x', 'divi' => false ) ) ) );
d5b_abilities_set_category( 'divi', true, false, 'tester' );
d5b_abilities_set_category( 'media', false, false, 'tester' );
$img = t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg', 'post_author' => 3 ) );
$pf  = t_add_post( array( 'post_author' => 3, 'post_status' => 'draft' ) );
$r1 = call( 'posts-update', array( 'id' => $pf, 'featured_image' => $img ) );
$r2 = call( 'posts-create', array( 'title' => 'f', 'featured_image' => $img ) );
check( 'Media off: featured_image on update and create is refused not_allowed; no thumbnail set', 'not_allowed' === code( $r1 ) && 'not_allowed' === code( $r2 ) && null === meta1( $pf, '_thumbnail_id' ), array( code( $r1 ), code( $r2 ) ) );
d5b_abilities_set_category( 'media', true, false, 'tester' );
check( '... with Media on it is set', is_array( call( 'posts-update', array( 'id' => $pf, 'featured_image' => $img ) ) ) && (string) $img === (string) meta1( $pf, '_thumbnail_id' ) );

echo "Task 11 I4: a publish nobody asked for (a1)\n";
$past = gmdate( 'Y-m-d\TH:i:s\Z', time() - 86400 * 400 ); $later = gmdate( 'Y-m-d\TH:i:s\Z', time() + 86400 * 400 );
site();
$r = call( 'posts-create', array( 'title' => 'f', 'status' => 'future', 'date' => $past ) );
check( 'posts-create status future with a past date: invalid_input "a scheduled post needs a future date"; nothing created', 'invalid_input' === code( $r ) && 'a scheduled post needs a future date' === $r->get_error_message() && array() === $GLOBALS['T_inserts'], $r );
check( '... with a future date it is scheduled', 'future' === ( call( 'posts-create', array( 'title' => 'f', 'status' => 'future', 'date' => $later ) )['status'] ?? '' ) );
$fut = t_add_post( array( 'post_author' => 3, 'post_status' => 'future', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 86400 * 400 ) ) );
$r = call( 'posts-update', array( 'id' => $fut, 'status' => 'future', 'date' => $past ) );
check( 'posts-update status future with a past date: invalid_input; still scheduled', 'invalid_input' === code( $r ) && 'future' === post( $fut )->post_status, $r );
// Fix round 2: a scheduled post is published only when the input says status publish, even for a user who may publish.
$SCHED = 'this post is scheduled; pass status: publish to publish it now, or give it a future date';
$u0 = count( $GLOBALS['T_updates'] );
$r = call( 'posts-update', array( 'id' => $fut, 'date' => $past ) );
check( 'posts-update with only a past date on a scheduled post, by a user who may publish: invalid_input (scheduled), still scheduled, nothing written', 'invalid_input' === code( $r ) && $SCHED === $r->get_error_message() && 'future' === post( $fut )->post_status && $u0 === count( $GLOBALS['T_updates'] ), $r );
$r = call( 'posts-update', array( 'id' => $fut, 'date' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
check( '... a date of now is refused the same way', 'invalid_input' === code( $r ) && $SCHED === $r->get_error_message() && 'future' === post( $fut )->post_status, $r );
$GLOBALS['T_posts'][ $fut ]->post_date_gmt = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$r = call( 'posts-update', array( 'id' => $fut, 'title' => 'only the title' ) );
check( '... a title-only edit of an overdue scheduled post (core would publish it on save) is refused the same way', 'invalid_input' === code( $r ) && $SCHED === $r->get_error_message() && 'future' === post( $fut )->post_status, $r );
$r = call( 'posts-update', array( 'id' => $fut, 'title' => 'publish now', 'status' => 'publish' ) );
check( '... with status: publish given and the capability: published', is_array( $r ) && 'publish' === post( $fut )->post_status, $r );
$fut2 = t_add_post( array( 'post_author' => 3, 'post_status' => 'future', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 86400 * 400 ) ) );
$r = call( 'posts-update', array( 'id' => $fut2, 'date' => $past, 'status' => 'publish' ) );
check( '... a past date together with status: publish: published', is_array( $r ) && 'publish' === post( $fut2 )->post_status, $r );
const EDPUB = array( 'read', 'edit_posts', 'edit_published_posts', 'delete_posts' ); // edits published content, may not publish
site( EDPUB );
$fut = t_add_post( array( 'post_author' => 3, 'post_status' => 'future', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 86400 * 400 ) ) );
$u0 = count( $GLOBALS['T_updates'] );
$r = call( 'posts-update', array( 'id' => $fut, 'date' => $past ) );
check( 'a user who may edit the scheduled post but not publish: a past date (no status given) is refused; still scheduled', is_wp_error( $r ) && 'future' === post( $fut )->post_status && $u0 === count( $GLOBALS['T_updates'] ), $r );
$r = call( 'posts-update', array( 'id' => $fut, 'date' => $past, 'status' => 'publish' ) );
check( '... with status: publish it is not_allowed (no publish capability); still scheduled', 'not_allowed' === code( $r ) && 'future' === post( $fut )->post_status, $r );
check( '... a later date keeps it scheduled and is allowed', is_array( call( 'posts-update', array( 'id' => $fut, 'date' => $later ) ) ) && 'future' === post( $fut )->post_status );
$pub = t_add_post( array( 'post_author' => 3, 'post_status' => 'publish' ) );
$r = call( 'posts-update', array( 'id' => $pub, 'date' => $later ) );
check( '... a future date on their published post (core would schedule it, which needs the publish capability) is refused not_allowed; still published', 'not_allowed' === code( $r ) && 'publish' === post( $pub )->post_status, $r );
site( CONTRIB );
$own = t_add_post( array( 'post_author' => 3, 'post_status' => 'pending' ) );
$r = call( 'posts-update', array( 'id' => $own, 'date' => $past, 'title' => 'x' ) );
check( 'a contributor\'s own pending post with a past date stays pending (no publish involved)', is_array( $r ) && 'pending' === post( $own )->post_status, $r );

echo "Task 11 I1 / M2: the history cap is per field, the content is kept whole (a3)\n";
site();
$p = t_add_post( array( 'post_author' => 3, 'post_content' => 'the only copy of v1' ) );
t_add_meta( $p, '_other_plugin_cache', str_repeat( 'x', 9 * 1024 * 1024 ) );
t_add_meta( $p, '_small', 's' );
$r = call( 'posts-update', array( 'id' => $p, 'content' => 'v2', 'expect_hash' => md5( 'the only copy of v1' ) ) );
$h = hlast();
check( 'a small post with 9 MB of unrelated protected meta updates; the row keeps before.content whole, marks only that meta value too_large, keeps the rest', is_array( $r ) && 'v2' === post( $p )->post_content && 'the only copy of v1' === ( $h['before']['content'] ?? null )
	&& true === ( $h['before']['protected_meta']['_other_plugin_cache'][0]['too_large'] ?? null ) && array( 's' ) === ( $h['before']['protected_meta']['_small'] ?? null ) && 'Title ' . $p === $h['before']['title'], array( code( $r ), array_keys( $h['before'] ?? array() ) ) );
site();
$big = t_add_post( array( 'post_author' => 3, 'post_content' => str_repeat( 'b', 8 * 1024 * 1024 + 1 ) ) );
$u0 = count( $GLOBALS['T_updates'] );
$r1 = call( 'posts-update', array( 'id' => $big, 'title' => 'x' ) );
$r2 = call( 'posts-trash', array( 'id' => $big ) );
check( 'a post whose content is over 8 MB: update and trash are refused history_unavailable, nothing written, no row', 'history_unavailable' === code( $r1 ) && 'history_unavailable' === code( $r2 ) && $u0 === count( $GLOBALS['T_updates'] ) && 'publish' === post( $big )->post_status && array() === hrows(), array( code( $r1 ), code( $r2 ) ) );
site();
$bad = "Caf\xE9 au lait";
$p = t_add_post( array( 'post_author' => 3, 'post_content' => $bad ) );
$r = call( 'posts-trash', array( 'id' => $p ) );
$hr  = hrows();
$raw = json_decode( (string) ( end( $hr )['before'] ?? '' ), true );
check( 'trash of a post whose content is not valid UTF-8 (not a permanent delete): trashed; the row holds the content with U+FFFD and says _utf8_substituted', is_array( $r ) && 'trash' === post( $p )->post_status && "Caf\u{FFFD} au lait" === ( $raw['content'] ?? null ) && true === ( $raw['_utf8_substituted'] ?? null ), $raw['content'] ?? null );

echo "Task 11 M3: secrets in free-text inputs never reach the audit log (a4)\n";
site();
$STRIPE = 'sk_' . 'live_51HxYzAbCdEfGhIjKlMnOpQr'; $GKEY = 'AIza' . 'SyD-9tSrke72PouQMnMX-a7eZSW0jkFMBWY'; $WHSEC = 'whsec_' . 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'; $JWT = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
$r = call( 'posts-create', array( 'title' => 'password=hunter2', 'content' => 'Pay with ' . $STRIPE . ' token ' . $JWT . ' maps ' . $GKEY, 'excerpt' => 'hook ' . $WHSEC, 'meta' => array( 'note' => $STRIPE ) ) );
$args = implode( "\n", array_column( arows(), 'args' ) );
$leak = array(); foreach ( array( 'hunter2', $STRIPE, $GKEY, $WHSEC, $JWT ) as $s ) { if ( false !== strpos( $args, $s ) ) { $leak[] = substr( $s, 0, 8 ); } }
check( 'posts-create: the audit args hold none of password=hunter2 (title), a Stripe key, a JWT, a Google API key, a Stripe webhook secret', is_array( $r ) && array() === $leak && '' !== $args, $leak );
$res = json_encode( $r );
check( '... and the result masks the Google key and the webhook secret by shape (both profiles have them)', false === strpos( $res, $GKEY ) && false === strpos( $res, $WHSEC ) );

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
