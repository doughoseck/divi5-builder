<?php
/**
 * The content read abilities (#32, Task 4).
 *
 *   php scripts/plugin-abilities-content-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php: posts, caps, meta, terms, comments, menus,
 * revisions). Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: every ability is registered under its category and is read-only; every item is checked with
 * read_post (lists drop what the caller may not read, a get refuses it, and the refusal is audited); the internal post
 * types are refused with not_allowed; per_page is capped at 100; the hash is md5 of the raw content; custom fields come back
 * keyed by name and protected keys need edit rights; every handler checks its own input.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.1.0' );
require __DIR__ . '/plugin-test-stubs.php';
foreach ( array( 'parts', 'switches', 'abilities/categories', 'abilities/register', 'abilities/read-content', 'abilities/read-structure' ) as $f ) { require $dir . '/inc/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

// audit and scrub doubles: the audit writer records, the scrubber masks values by KEY the way Task 5's will
$GLOBALS['T_audit'] = array();
function d5b_audit_write( string $ability, array $args, string $status, int $duration_ms ): bool { $GLOBALS['T_audit'][] = compact( 'ability', 'args', 'status' ); return true; }
function d5b_ability_scrub( $v ) {
	if ( ! is_array( $v ) ) { return $v; }
	$o = array();
	foreach ( $v as $k => $x ) { $o[ $k ] = ( is_string( $k ) && preg_match( '/pass|secret|token|api_key/i', $k ) ) ? '[hidden]' : d5b_ability_scrub( $x ); }
	return $o;
}

const READER = array( 'read' );
const EDITOR = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'read_private_posts' );
function site( array $caps, int $uid = 3 ) {
	t_reset(); t_set_caps( $caps ); t_set_multisite( false ); $GLOBALS['T_user_id'] = $uid; $GLOBALS['T_audit'] = array();
	d5b_abilities_set_master( true, 'tester' );
}
/** What a client does: the permission callback first, then the run. 'FORBIDDEN' when the gate says no. */
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function code( $r ) { return is_wp_error( $r ) ? $r->code : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function ids( $r ) { return is_array( $r ) && isset( $r['items'] ) ? array_map( function ( $i ) { return $i['id']; }, $r['items'] ) : array(); }
function last_audit() { return $GLOBALS['T_audit'] ? $GLOBALS['T_audit'][ count( $GLOBALS['T_audit'] ) - 1 ] : null; }

$names = array( 'posts-list', 'posts-get', 'posts-revisions', 'pages-list', 'pages-get', 'cpt-types', 'cpt-list', 'cpt-get', 'media-list', 'media-get', 'taxonomy-list', 'taxonomy-get',
	'comments-list', 'comments-get', 'meta-get', 'blocks-list', 'blocks-get', 'menus-list', 'menus-items', 'menus-locations', 'revisions-list', 'revisions-get', 'revisions-compare', 'search' );

// ---- registration
echo "registration\n";
$defs = d5b_ability_defs();
$want = array_map( function ( $x ) { return 'divi5-builder/' . $x; }, $names ); $have = array_keys( $defs ); sort( $want ); sort( $have );
check( 'all ' . count( $names ) . ' content abilities are defined, and nothing else', $want === $have, $have );
$bad = array();
foreach ( $names as $x ) {
	$d = $defs[ 'divi5-builder/' . $x ] ?? array();
	$cat = 'posts-revisions' === $x ? 'posts' : strtok( $x, '-' );
	if ( ( $d['category'] ?? '' ) !== $cat ) { $bad[] = "$x category " . ( $d['category'] ?? '?' ); }
	if ( array_key_exists( 'annotations', $d ) ) { $bad[] = "$x passes annotations"; }
	if ( ! is_callable( $d['handler'] ?? null ) || ! is_callable( $d['permission'] ?? null ) ) { $bad[] = "$x handler or permission"; }
	if ( strlen( (string) ( $d['description'] ?? '' ) ) < 40 || ! preg_match( '/return/i', $d['description'] ?? '' ) || ! preg_match( '/refus/i', $d['description'] ?? '' ) ) { $bad[] = "$x description"; }
	foreach ( (array) ( $d['input_schema']['properties'] ?? array() ) as $k => $p ) { if ( empty( $p['description'] ) ) { $bad[] = "$x.$k undescribed"; } }
}
check( 'every content ability sits in its category, has callables and a lint-clean description, and passes no annotations override', array() === $bad, $bad );
site( READER );
add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' );
add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );
t_fire( 'wp_abilities_api_categories_init' ); t_fire( 'wp_abilities_api_init' );
$ro = true; $missing = array();
foreach ( $names as $x ) {
	$m = $GLOBALS['T_abilities'][ 'divi5-builder/' . $x ] ?? null;
	if ( null === $m ) { $missing[] = $x; continue; }
	if ( array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) !== $m['meta']['annotations'] ) { $ro = false; }
}
check( 'every content ability is registered with WordPress, readonly and not destructive', array() === $missing && $ro && array() === $GLOBALS['T_ability_errors'], array( $missing, $GLOBALS['T_ability_errors'] ) );
$cats = array_keys( $GLOBALS['T_ability_categories'] ); sort( $cats );
check( 'the categories used are registered once each', array( 'divi5-builder-blocks', 'divi5-builder-comments', 'divi5-builder-cpt', 'divi5-builder-media', 'divi5-builder-menus', 'divi5-builder-meta', 'divi5-builder-pages', 'divi5-builder-posts', 'divi5-builder-revisions', 'divi5-builder-search', 'divi5-builder-taxonomy' ) === $cats, $cats );

// ---- posts-get
echo "posts-get\n";
site( READER );
$raw = "<!-- wp:paragraph -->\n<p>Hello &amp; <b>welcome</b></p>\n<!-- /wp:paragraph -->\n[gallery ids=\"1\"]";
$cat = t_add_term( 'category', 'News' ); $tag = t_add_term( 'post_tag', 'Fresh' );
$pub = t_add_post( array( 'post_title' => 'Hello', 'post_content' => $raw, 'post_password' => '' ) );
$GLOBALS['T_object_terms'][ $pub ] = array( 'category' => array( $cat ), 'post_tag' => array( $tag ) );
$r = call( 'posts-get', array( 'id' => $pub ) );
check( 'posts-get returns the content as stored', is_array( $r ) && $raw === $r['content'] && 'Hello' === $r['title'] && 'publish' === $r['status'], $r );
check( 'posts-get: hash = md5 of the raw content (not of the filtered content)', is_array( $r ) && md5( $raw ) === $r['hash'] && md5( apply_filters( 'the_content', $raw ) ) !== $r['hash'], $r );
check( 'posts-get returns categories and tags', is_array( $r ) && array( array( 'id' => $cat, 'name' => 'News', 'slug' => 'news' ) ) === $r['categories'] && 'Fresh' === $r['tags'][0]['name'], $r );
$json = json_encode( $r );
check( 'posts-get: no secrets (no post_password, no password key at all)', false === stripos( $json, 'password' ), $json );
$pw = t_add_post( array( 'post_password' => 'letmein', 'post_content' => 'secret text' ) );
check( 'a password-protected post is refused to a reader, and its text is never returned', 'FORBIDDEN' === call( 'posts-get', array( 'id' => $pw ) ) && 'not_found' === code( d5b_ability_run( 'divi5-builder/posts-get', array( 'id' => $pw ) ) ) );
site( EDITOR, 1 );
$pw = t_add_post( array( 'post_password' => 'letmein', 'post_content' => 'secret text', 'post_author' => 1 ) );
$r = call( 'posts-get', array( 'id' => $pw ) );
check( 'an editor reads a password-protected post, but post_password is still not in the result', is_array( $r ) && 'secret text' === $r['content'] && false === stripos( json_encode( $r ), 'letmein' ), $r );
$page = t_add_post( array( 'post_type' => 'page' ) );
check( 'posts-get refuses a page id (wrong kind) and a missing id the same way', 'FORBIDDEN' === call( 'posts-get', array( 'id' => $page ) ) && 'FORBIDDEN' === call( 'posts-get', array( 'id' => 99999 ) ) );

// ---- permission per object
echo "permission per object\n";
site( array( 'read', 'edit_posts' ), 3 );
$other = t_add_post( array( 'post_status' => 'draft', 'post_author' => 2, 'post_content' => "someone else's draft" ) );
$own   = t_add_post( array( 'post_status' => 'draft', 'post_author' => 3, 'post_content' => 'my draft' ) );
$GLOBALS['T_audit'] = array();
check( "posts-get: a contributor reading another user's draft is refused", 'FORBIDDEN' === call( 'posts-get', array( 'id' => $other ) ) );
$a = last_audit();
check( 'the refusal is logged as forbidden with the id', $a && 'forbidden' === $a['status'] && 'divi5-builder/posts-get' === $a['ability'] && array( 'id' => $other ) === $a['args'], $GLOBALS['T_audit'] );
check( 'the same contributor reads their own draft', is_array( call( 'posts-get', array( 'id' => $own ) ) ) && 'my draft' === call( 'posts-get', array( 'id' => $own ) )['content'] );
check( 'defence in depth: the handler alone also refuses the other draft (not_found, no content)', 'not_found' === code( d5b_ability_run( 'divi5-builder/posts-get', array( 'id' => $other ) ) ) );
site( array(), 3 );
check( 'with no capabilities every list is refused', 'FORBIDDEN' === call( 'posts-list' ) && 'FORBIDDEN' === call( 'media-list' ) && 'FORBIDDEN' === call( 'search', array( 'query' => 'x' ) ) );

// ---- posts-list
echo "posts-list\n";
site( READER, 3 );
for ( $i = 0; $i < 130; $i++ ) { t_add_post( array( 'post_title' => "Post $i" ) ); }
$r = call( 'posts-list', array( 'per_page' => 500 ) );
check( 'per_page 500 is capped at 100', is_array( $r ) && 100 === count( $r['items'] ) && 100 === $r['per_page'] && 130 === $r['total'] && 2 === $r['total_pages'], is_array( $r ) ? array( count( $r['items'] ), $r['per_page'], $r['total'] ) : $r );
check( 'the query itself was built with 100, not 500', 100 === end( $GLOBALS['T_queries'] )['posts_per_page'] );
$r2 = call( 'posts-list', array( 'per_page' => 100, 'page' => 2 ) );
check( 'page 2 holds the remaining 30, none repeated from page 1', is_array( $r2 ) && 30 === count( $r2['items'] ) && array() === array_intersect( ids( $r ), ids( $r2 ) ), $r2 );
check( 'per_page 0 becomes 1; page 0 becomes 1', 1 === count( call( 'posts-list', array( 'per_page' => 0 ) )['items'] ) && 1 === call( 'posts-list', array( 'page' => 0 ) )['page'] );
check( 'a per_page that is not a number is refused', 'invalid_input' === code( call( 'posts-list', array( 'per_page' => 'lots' ) ) ) && 'invalid_input' === code( call( 'posts-list', array( 'per_page' => array( 5 ) ) ) ) );
site( READER, 3 );
$pub = t_add_post( array( 'post_title' => 'Public' ) );
$priv = t_add_post( array( 'post_status' => 'private', 'post_author' => 2, 'post_title' => 'Private' ) );
$draft = t_add_post( array( 'post_status' => 'draft', 'post_author' => 2, 'post_title' => 'Draft' ) );
$r = call( 'posts-list', array( 'status' => 'any' ) );
check( 'private posts and other users\' drafts are hidden from a subscriber (status any)', array( $pub ) === ids( $r ), ids( $r ) );
check( 'asking for status private as a subscriber returns nothing', array() === ids( call( 'posts-list', array( 'status' => 'private' ) ) ) );
site( EDITOR, 1 );
$pub = t_add_post( array( 'post_title' => 'Public' ) ); $priv = t_add_post( array( 'post_status' => 'private', 'post_author' => 2 ) ); $draft = t_add_post( array( 'post_status' => 'draft', 'post_author' => 2 ) );
check( 'the same list, run by an editor, does include them (so the hiding above is the permission check)', array( $draft, $priv, $pub ) === ids( call( 'posts-list', array( 'status' => 'any' ) ) ), ids( call( 'posts-list', array( 'status' => 'any' ) ) ) );
check( 'search, category, tag and author filters reach the query', ( function () use ( $pub ) { call( 'posts-list', array( 'search' => 'zz', 'category' => 4, 'tag' => 5, 'author' => 2 ) ); $q = end( $GLOBALS['T_queries'] ); return 'zz' === $q['s'] && 4 === $q['cat'] && 5 === $q['tag_id'] && 2 === $q['author']; } )() );
check( 'a bad status is refused, so is an unknown input name', 'invalid_input' === code( call( 'posts-list', array( 'status' => 'trash' ) ) ) && 'invalid_input' === code( call( 'posts-list', array( 'post_type' => 'page' ) ) ) );
check( 'null input is read as no filters', is_array( d5b_ability_run( 'divi5-builder/posts-list', null ) ) );

// ---- cpt and the internal types
echo "cpt\n";
site( EDITOR, 1 );
t_add_type( 'book', true, true ); t_add_type( 'secret_thing', false, false );
$internal = array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'custom_css', 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'et_pb_layout', 'et_theme_builder', 'et_pb_canvas' );
$book = t_add_post( array( 'post_type' => 'book', 'post_content' => 'Chapter one' ) );
$refused = array(); $leaked = array();
foreach ( $internal as $t ) {
	t_add_type( $t, true, true );
	$id = t_add_post( array( 'post_type' => $t, 'post_content' => 'internal ' . $t ) );
	if ( 'not_allowed' !== code( call( 'cpt-get', array( 'type' => $t, 'id' => $id ) ) ) ) { $refused[] = "cpt-get $t"; }
	if ( 'not_allowed' !== code( call( 'cpt-list', array( 'type' => $t ) ) ) ) { $refused[] = "cpt-list $t"; }
	if ( 'FORBIDDEN' !== call( 'meta-get', array( 'post_id' => $id ) ) ) { $leaked[] = "meta-get $t"; }
	if ( 'FORBIDDEN' !== call( 'revisions-list', array( 'post_id' => $id ) ) ) { $leaked[] = "revisions-list $t"; }
	if ( 'not_allowed' !== code( call( 'search', array( 'query' => 'internal', 'types' => array( $t ) ) ) ) ) { $leaked[] = "search $t"; }
}
check( 'cpt-get and cpt-list refuse every internal type with not_allowed (all 12, incl. et_pb_layout and wp_template)', array() === $refused, $refused );
check( 'meta-get, revisions-list and search do not reach internal types either', array() === $leaked, $leaked );
$r = call( 'cpt-types' );
$tn = array_map( function ( $i ) { return $i['name']; }, $r['items'] );
check( 'cpt-types lists the public custom type, not the internal ones, not non-public ones', in_array( 'book', $tn, true ) && array() === array_intersect( $internal, $tn ) && ! in_array( 'secret_thing', $tn, true ), $tn );
check( 'cpt-types leaves out types that have their own category (post, page, attachment, wp_block) and revision', array() === array_intersect( array( 'post', 'page', 'attachment', 'wp_block', 'revision', 'nav_menu_item' ), $tn ), $tn );
$r = call( 'cpt-get', array( 'type' => 'book', 'id' => $book ) );
check( 'cpt-get returns a custom item with content and hash', is_array( $r ) && 'Chapter one' === $r['content'] && md5( 'Chapter one' ) === $r['hash'], $r );
check( 'cpt-get: an id of another type is refused; so are post and page here', 'FORBIDDEN' === call( 'cpt-get', array( 'type' => 'book', 'id' => t_add_post() ) ) && 'not_allowed' === code( call( 'cpt-get', array( 'type' => 'post', 'id' => t_add_post() ) ) ) );
check( 'cpt: a non-public type is not_allowed, an unknown one is invalid_type, a bad name is invalid_input', 'not_allowed' === code( call( 'cpt-list', array( 'type' => 'secret_thing' ) ) ) && 'invalid_type' === code( call( 'cpt-list', array( 'type' => 'nope' ) ) ) && 'invalid_input' === code( call( 'cpt-list', array( 'type' => '../etc' ) ) ) && 'invalid_input' === code( call( 'cpt-list' ) ) );
check( 'cpt-list lists the item', array( $book ) === ids( call( 'cpt-list', array( 'type' => 'book' ) ) ) );
site( READER, 3 );
t_add_type( 'book', true, true );
$hidden = t_add_post( array( 'post_type' => 'book', 'post_status' => 'draft', 'post_author' => 2 ) );
check( 'cpt-get on a draft of someone else is refused for a subscriber, and cpt-list drops it', 'FORBIDDEN' === call( 'cpt-get', array( 'type' => 'book', 'id' => $hidden ) ) && array() === ids( call( 'cpt-list', array( 'type' => 'book', 'status' => 'any' ) ) ) );

// ---- pages
echo "pages\n";
site( READER, 3 );
$top = t_add_post( array( 'post_type' => 'page', 'post_content' => 'Top' ) ); $kid = t_add_post( array( 'post_type' => 'page', 'post_parent' => $top ) ); $postx = t_add_post();
check( 'pages-list lists pages only; parent 0 gives the top level; parent N the children', array( $kid, $top ) === ids( call( 'pages-list' ) ) && array( $top ) === ids( call( 'pages-list', array( 'parent' => 0 ) ) ) && array( $kid ) === ids( call( 'pages-list', array( 'parent' => $top ) ) ) );
$r = call( 'pages-get', array( 'id' => $top ) );
check( 'pages-get returns content, parent and hash', is_array( $r ) && 'Top' === $r['content'] && md5( 'Top' ) === $r['hash'] && 0 === $r['parent'] );
check( 'pages-get refuses a blog post id', 'FORBIDDEN' === call( 'pages-get', array( 'id' => $postx ) ) );

// ---- media
echo "media\n";
site( READER, 3 );
$privp = t_add_post( array( 'post_status' => 'private', 'post_author' => 2 ) );
$m1 = t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg', 'post_title' => 'Photo', 'post_excerpt' => 'A caption', 'post_content' => 'Long description' ) );
t_add_meta( $m1, '_wp_attached_file', '2026/10/a.jpg' ); t_add_meta( $m1, '_wp_attachment_image_alt', 'Alt words' );
t_add_meta( $m1, '_wp_attachment_metadata', array( 'width' => 800, 'height' => 600, 'sizes' => array( 'thumbnail' => array( 'file' => 'a-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg' ) ) ) );
$m2 = t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'application/pdf', 'post_parent' => $privp ) );
$r = call( 'media-get', array( 'id' => $m1 ) );
check( 'media-get returns alt, caption, url, sizes with width and height, and the hash of the description', is_array( $r ) && 'Alt words' === $r['alt'] && 'A caption' === $r['caption'] && 'https://x.example/wp-content/uploads/2026/10/a.jpg' === $r['file_url'] && 150 === $r['sizes']['thumbnail']['width'] && 'https://x.example/wp-content/uploads/2026/10/a-150x150.jpg' === $r['sizes']['thumbnail']['url'] && 800 === $r['sizes']['full']['width'] && md5( 'Long description' ) === $r['hash'], $r );
check( 'media-list: the mime filter is passed on; a file under a private post is hidden from a subscriber', array( $m1 ) === ids( call( 'media-list' ) ) && 'image' === ( call( 'media-list', array( 'mime' => 'image' ) ) ? end( $GLOBALS['T_queries'] )['post_mime_type'] : '' ) );
check( 'media-get refuses the file under a private post', 'FORBIDDEN' === call( 'media-get', array( 'id' => $m2 ) ) );
check( 'media-list refuses a mime that is not a mime', 'invalid_input' === code( call( 'media-list', array( 'mime' => 'image/<script>' ) ) ) );

// ---- taxonomy
echo "taxonomy\n";
site( READER, 3 );
$c1 = t_add_term( 'category', 'News' ); $c2 = t_add_term( 'category', 'Sport', $c1 ); $nm = t_add_term( 'nav_menu', 'Main' ); $th = t_add_term( 'wp_theme', 'twentytwenty' );
$r = call( 'taxonomy-list' );
check( 'taxonomy-list defaults to categories, with parent and totals', array( $c1, $c2 ) === ids( $r ) && 2 === $r['total'] && $c1 === $r['items'][1]['parent'], $r );
check( 'taxonomy-get returns the term; a term of another taxonomy is not found', 'Sport' === call( 'taxonomy-get', array( 'taxonomy' => 'category', 'id' => $c2 ) )['name'] && 'not_found' === code( call( 'taxonomy-get', array( 'taxonomy' => 'post_tag', 'id' => $c2 ) ) ) );
check( 'internal and non-public taxonomies are refused; an unknown one is invalid_taxonomy', 'not_allowed' === code( call( 'taxonomy-list', array( 'taxonomy' => 'nav_menu' ) ) ) && 'not_allowed' === code( call( 'taxonomy-get', array( 'taxonomy' => 'wp_theme', 'id' => $th ) ) ) && 'invalid_taxonomy' === code( call( 'taxonomy-list', array( 'taxonomy' => 'nope' ) ) ) );

// ---- comments
echo "comments\n";
site( READER, 3 );
$cp = t_add_post(); $privp = t_add_post( array( 'post_status' => 'private', 'post_author' => 2 ) );
$ok = t_add_comment( array( 'comment_post_ID' => $cp, 'comment_content' => 'Good read' ) );
$hold = t_add_comment( array( 'comment_post_ID' => $cp, 'comment_approved' => '0' ) );
$onpriv = t_add_comment( array( 'comment_post_ID' => $privp ) );
$r = call( 'comments-list' );
check( 'comments-list: a reader sees approved comments on readable posts only', array( $ok ) === ids( $r ), ids( $r ) );
check( 'comments never carry the IP address, and a reader gets no email address', false === strpos( json_encode( $r ), '203.0.113.9' ) && ! isset( $r['items'][0]['author_email'] ) );
check( 'a reader may not ask for held, spam or all comments', 'FORBIDDEN' === call( 'comments-list', array( 'status' => 'hold' ) ) && 'FORBIDDEN' === call( 'comments-list', array( 'status' => 'all' ) ) && 'forbidden_status' === code( d5b_ability_run( 'divi5-builder/comments-list', array( 'status' => 'hold' ) ) ) );
check( 'comments-get: approved is returned; held and the one on a private post are refused', 'Good read' === call( 'comments-get', array( 'id' => $ok ) )['content'] && 'FORBIDDEN' === call( 'comments-get', array( 'id' => $hold ) ) && 'FORBIDDEN' === call( 'comments-get', array( 'id' => $onpriv ) ) && 'not_found' === code( d5b_ability_run( 'divi5-builder/comments-get', array( 'id' => $hold ) ) ) );
check( 'comments-list for a post the caller cannot read is refused', 'FORBIDDEN' === call( 'comments-list', array( 'post' => $privp ) ) );
check( 'comments-list with a post gives totals; without one it gives none (a site-wide total would count unreadable posts)', 1 === call( 'comments-list', array( 'post' => $cp ) )['total'] && ! array_key_exists( 'total', call( 'comments-list' ) ) );
site( array( 'read', 'moderate_comments' ), 3 );
$cp = t_add_post(); $hold = t_add_comment( array( 'comment_post_ID' => $cp, 'comment_approved' => '0' ) );
$r = call( 'comments-list', array( 'status' => 'hold' ) );
check( 'a moderator lists held comments and sees the email address (still no IP)', array( $hold ) === ids( $r ) && 'visitor@example.org' === $r['items'][0]['author_email'] && false === strpos( json_encode( $r ), '203.0.113.9' ), $r );

// ---- meta
echo "meta-get\n";
site( READER, 3 );
$mp = t_add_post( array( 'post_author' => 1 ) );
t_add_meta( $mp, 'color', 'red' ); t_add_meta( $mp, '_edit_lock', '1:2' ); t_add_meta( $mp, 'api_key', 'sk-live-123' ); t_add_meta( $mp, 'tags', 'a' ); t_add_meta( $mp, 'tags', 'b' );
$r = call( 'meta-get', array( 'post_id' => $mp ) );
check( 'meta-get omits _ keys for a user without edit_post', is_array( $r ) && ! array_key_exists( '_edit_lock', $r['meta'] ) && 1 === $r['omitted_protected'] && 'red' === $r['meta']['color'], $r );
check( 'meta-get shape: an object keyed by meta key, one value or a list of values; never a list of key/value pairs', is_array( $r ) && array( 'color', 'api_key', 'tags' ) === array_keys( $r['meta'] ) && array( 'a', 'b' ) === $r['meta']['tags'], $r );
check( 'the key-based scrubber works on it: api_key comes back masked', '[hidden]' === $r['meta']['api_key'] && false === strpos( json_encode( $r ), 'sk-live-123' ), $r );
$r = call( 'meta-get', array( 'post_id' => $mp, 'key' => 'color' ) );
check( 'meta-get with a key returns just that key', array( 'color' => 'red' ) === $r['meta'] );
check( 'meta-get for a protected key without edit rights returns nothing for it', array() === call( 'meta-get', array( 'post_id' => $mp, 'key' => '_edit_lock' ) )['meta'] );
site( EDITOR, 1 );
$mp = t_add_post( array( 'post_author' => 1 ) ); t_add_meta( $mp, '_edit_lock', '1:2' ); t_add_meta( $mp, 'color', 'red' );
$r = call( 'meta-get', array( 'post_id' => $mp ) );
check( 'an editor (edit_post) gets the _ keys too', isset( $r['meta']['_edit_lock'] ) && 0 === $r['omitted_protected'], $r );
site( array( 'read', 'edit_posts' ), 3 );
$d = t_add_post( array( 'post_status' => 'draft', 'post_author' => 2 ) ); t_add_meta( $d, 'color', 'red' );
check( 'meta-get on an unreadable draft is refused', 'FORBIDDEN' === call( 'meta-get', array( 'post_id' => $d ) ) );

// ---- blocks
echo "blocks\n";
site( READER, 3 );
$blk = t_add_post( array( 'post_type' => 'wp_block', 'post_content' => '<!-- wp:paragraph --><p>Reusable</p><!-- /wp:paragraph -->' ) ); $notblk = t_add_post();
$r = call( 'blocks-get', array( 'id' => $blk ) );
check( 'blocks-list / blocks-get return the block with its raw markup and hash; a post id is refused', array( $blk ) === ids( call( 'blocks-list' ) ) && is_array( $r ) && md5( '<!-- wp:paragraph --><p>Reusable</p><!-- /wp:paragraph -->' ) === $r['hash'] && 'FORBIDDEN' === call( 'blocks-get', array( 'id' => $notblk ) ) );

// ---- menus
echo "menus\n";
site( array( 'read', 'edit_theme_options' ), 3 );
$pg = t_add_post( array( 'post_type' => 'page', 'post_title' => 'About' ) ); $pgp = t_add_post( array( 'post_type' => 'page', 'post_status' => 'private', 'post_author' => 2, 'post_title' => 'Secret page' ) );
$GLOBALS['T_menus'][7] = (object) array( 'term_id' => 7, 'name' => 'Main', 'slug' => 'main', 'count' => 3 );
$GLOBALS['T_menu_items'][7] = array(
	(object) array( 'ID' => 71, 'title' => 'About', 'url' => '/about', 'type' => 'post_type', 'object' => 'page', 'object_id' => $pg, 'menu_item_parent' => 0, 'menu_order' => 1 ),
	(object) array( 'ID' => 72, 'title' => 'Secret page', 'url' => '/secret', 'type' => 'post_type', 'object' => 'page', 'object_id' => $pgp, 'menu_item_parent' => 0, 'menu_order' => 2 ),
	(object) array( 'ID' => 73, 'title' => 'Shop', 'url' => 'https://shop.example', 'type' => 'custom', 'object' => 'custom', 'object_id' => 73, 'menu_item_parent' => 0, 'menu_order' => 3 ),
);
$GLOBALS['T_registered_menus'] = array( 'primary' => 'Primary', 'footer' => 'Footer' ); $GLOBALS['T_menu_locations'] = array( 'primary' => 7 );
check( 'menus-list lists menus', array( 7 ) === ids( call( 'menus-list' ) ) );
$titles = array_map( function ( $i ) { return $i['title']; }, call( 'menus-items', array( 'menu' => 'main' ) )['items'] );
check( 'menus-items (by slug) drops the link to a private page the caller cannot read', array( 'About', 'Shop' ) === $titles, $titles );
check( 'menus-items also takes the id; an unknown menu is not_found', 2 === count( call( 'menus-items', array( 'menu' => 7 ) )['items'] ) && 'not_found' === code( call( 'menus-items', array( 'menu' => 'nope' ) ) ) );
$loc = call( 'menus-locations' );
check( 'menus-locations returns each location with its menu id (0 when empty)', array( 'primary' => 7, 'footer' => 0 ) === array_column( $loc['items'], 'menu_id', 'location' ), $loc );

// ---- revisions
echo "revisions\n";
site( EDITOR, 1 );
$P = t_add_post( array( 'post_author' => 1, 'post_content' => 'version three' ) );
$r1 = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $P, 'post_content' => 'version one' ) );
$r2 = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $P, 'post_content' => 'version two' ) );
$other = t_add_post( array( 'post_author' => 1, 'post_content' => 'unrelated' ) );
$rx = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $other, 'post_content' => 'unrelated old' ) );
$r = call( 'revisions-compare', array( 'from' => $r1, 'to' => $r2 ) );
check( 'revisions-compare returns both contents and their hashes', is_array( $r ) && 'version one' === $r['from']['content'] && 'version two' === $r['to']['content'] && md5( 'version one' ) === $r['from']['hash'] && md5( 'version two' ) === $r['to']['hash'] && false === $r['identical'], $r );
check( 'revisions-compare: a revision against the live post works; the same revision twice is identical', 'version three' === call( 'revisions-compare', array( 'from' => $r1, 'to' => $P ) )['to']['content'] && true === call( 'revisions-compare', array( 'from' => $r1, 'to' => $r1 ) )['identical'] );
check( 'revisions-compare refuses two ids of different posts', 'invalid_input' === code( call( 'revisions-compare', array( 'from' => $r1, 'to' => $rx ) ) ) );
check( 'revisions-list, posts-revisions, revisions-get', array( $r2, $r1 ) === ids( call( 'revisions-list', array( 'post_id' => $P ) ) ) && array( $r2, $r1 ) === ids( call( 'posts-revisions', array( 'id' => $P ) ) ) && md5( 'version one' ) === call( 'revisions-get', array( 'id' => $r1 ) )['hash'] );
check( 'posts-revisions refuses a page; revisions-get refuses a non-revision', 'FORBIDDEN' === call( 'posts-revisions', array( 'id' => t_add_post( array( 'post_type' => 'page', 'post_author' => 1 ) ) ) ) && 'FORBIDDEN' === call( 'revisions-get', array( 'id' => $P ) ) );
site( READER, 3 );
$P = t_add_post( array( 'post_author' => 1 ) ); $r1 = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $P, 'post_content' => 'old' ) );
check( 'a reader (cannot edit the post) is refused every revision ability, though the post is public', 'FORBIDDEN' === call( 'revisions-list', array( 'post_id' => $P ) ) && 'FORBIDDEN' === call( 'posts-revisions', array( 'id' => $P ) ) && 'FORBIDDEN' === call( 'revisions-get', array( 'id' => $r1 ) ) && 'FORBIDDEN' === call( 'revisions-compare', array( 'from' => $r1, 'to' => $P ) ) );

// ---- search
echo "search\n";
site( READER, 3 );
$s1 = t_add_post( array( 'post_title' => 'Alpha news', 'post_content' => '<p>All about <b>alpha</b></p>' ) ); $s2 = t_add_post( array( 'post_title' => 'Alpha draft', 'post_status' => 'draft', 'post_author' => 2 ) );
$s3 = t_add_post( array( 'post_title' => 'Alpha protected', 'post_password' => 'pw' ) ); $s4 = t_add_post( array( 'post_title' => 'Alpha page', 'post_type' => 'page' ) );
$r = call( 'search', array( 'query' => 'alpha' ) );
check( 'search finds readable posts and pages; drafts of others and password-protected posts are left out', array( $s4, $s1 ) === ids( $r ), ids( $r ) );
check( 'search results carry a text snippet without tags', 'All about alpha' === $r['items'][1]['snippet'], $r );
check( 'search: a query is required; an unknown or internal type is not_allowed', 'invalid_input' === code( call( 'search' ) ) && 'not_allowed' === code( call( 'search', array( 'query' => 'a', 'types' => array( 'nope' ) ) ) ) );

// ---- every handler checks its own input
echo "input checks\n";
site( array_merge( EDITOR, array( 'edit_theme_options' ) ), 1 );
$bad = array();
foreach ( $names as $x ) {
	if ( 'invalid_input' !== code( d5b_ability_run( 'divi5-builder/' . $x, array( 'bogus' => 1 ) ) ) ) { $bad[] = "$x accepts an unknown input"; }
}
foreach ( array( 'posts-get' => 'id', 'pages-get' => 'id', 'media-get' => 'id', 'comments-get' => 'id', 'blocks-get' => 'id', 'revisions-get' => 'id', 'meta-get' => 'post_id', 'revisions-list' => 'post_id', 'posts-revisions' => 'id' ) as $x => $k ) {
	foreach ( array( -5, '12abc', array( 3 ), 0, 1.5, true ) as $v ) {
		$rr = d5b_ability_run( 'divi5-builder/' . $x, array( $k => $v ) );
		if ( 'invalid_input' !== code( $rr ) ) { $bad[] = "$x $k=" . json_encode( $v ) . ' gave ' . ( is_wp_error( $rr ) ? $rr->code : 'a result' ); }
	}
	if ( 'invalid_input' !== code( d5b_ability_run( 'divi5-builder/' . $x, array() ) ) ) { $bad[] = "$x without $k"; }
}
check( 'every ability refuses an unknown input; every id input refuses -5, "12abc", an array, 0, a float, true and a missing value', array() === $bad, $bad );
check( 'a string of digits is a good id', is_array( call( 'posts-get', array( 'id' => (string) $pub ) ) ) || 'FORBIDDEN' !== call( 'posts-get', array( 'id' => (string) t_add_post() ) ) );
check( 'the permission callback never throws on junk input', ( function () use ( $names ) { foreach ( $names as $x ) { foreach ( array( array( 'id' => array( 1 ) ), array( 'type' => array(), 'id' => 'x' ), array( 'from' => -1 ) ) as $in ) { $p = d5b_ability_permit( 'divi5-builder/' . $x, $in ); if ( true !== $p && false !== $p ) { return false; } } } return true; } )() );


// ---- fix round 1
echo "count oracle (the reviewer's probe: a total must never count rows the caller cannot read)\n";
function leaked_total( $r ) { return is_array( $r ) && ( ( $r['total'] ?? 0 ) > 0 || ( $r['total_pages'] ?? 0 ) > 1 ); }
site( READER, 3 );
t_add_post( array( 'post_status' => 'private', 'post_author' => 2, 'post_title' => 'x', 'post_content' => 'the code is K7Q9' ) );
t_add_post( array( 'post_status' => 'draft', 'post_author' => 2, 'post_title' => 'y', 'post_content' => 'merger with ACME' ) );
t_add_post( array( 'post_status' => 'pending', 'post_author' => 2, 'post_title' => 'y2', 'post_content' => 'pending PEND77' ) );
t_add_post( array( 'post_status' => 'future', 'post_author' => 2, 'post_title' => 'y3', 'post_content' => 'future FUT88' ) );
t_add_post( array( 'post_status' => 'publish', 'post_author' => 2, 'post_password' => 'pw', 'post_content' => 'pwd-only PIN 4412' ) );
$oracle = array(); $shown = array();
foreach ( array( 'K7Q', 'ACME', 'PEND77', 'FUT88', '4412' ) as $w ) {
	$tries = array( 'search' => call( 'search', array( 'query' => $w ) ) );
	foreach ( array( 'any', 'draft', 'pending', 'future', 'private', 'publish' ) as $st ) { $tries[ "posts-list $st" ] = call( 'posts-list', array( 'status' => $st, 'search' => $w ) ); }
	foreach ( $tries as $label => $r ) {
		if ( ! is_array( $r ) || array() !== ids( $r ) || leaked_total( $r ) ) { $oracle[] = "$label $w => " . json_encode( $r ); }
	}
	$shown[] = $w;
}
check( 'a subscriber learns nothing from hidden private, draft, pending, future and password posts: no items and no total through search and posts-list (every status)', array() === $oracle, array_slice( $oracle, 0, 3 ) );
t_add_post( array( 'post_content' => 'visible K7Qvisible' ) );
check( 'positive control: a matching public post is found, with a total of exactly 1', 1 === call( 'search', array( 'query' => 'K7Q' ) )['total'] && 1 === call( 'posts-list', array( 'search' => 'K7Q' ) )['total'] );
check( 'the query itself is scoped: no "any", protected posts excluded, drafts limited to the caller', ( function () { call( 'posts-list', array( 'status' => 'any' ) ); $q = end( $GLOBALS['T_queries'] ); call( 'posts-list', array( 'status' => 'draft' ) ); $d = end( $GLOBALS['T_queries'] ); call( 'search', array( 'query' => 'z' ) ); $s = end( $GLOBALS['T_queries'] ); return array( 'publish', 'private' ) === $q['post_status'] && false === $q['has_password'] && 3 === $d['author'] && false === $d['has_password'] && array( 'publish' ) === $s['post_status'] && false === $s['has_password']; } )() );
check( 'a contributor asking for another author\'s drafts gets an empty page', array() === ids( call( 'posts-list', array( 'status' => 'draft', 'author' => 2 ) ) ) );
site( array( 'read', 'edit_posts' ), 3 );
$mine = t_add_post( array( 'post_status' => 'draft', 'post_author' => 3, 'post_content' => 'my K7Q draft' ) ); t_add_post( array( 'post_status' => 'draft', 'post_author' => 2, 'post_content' => 'their K7Q draft' ) );
$r = call( 'posts-list', array( 'status' => 'draft', 'search' => 'K7Q' ) );
check( 'a contributor lists only their own drafts, and the total counts only those', array( $mine ) === ids( $r ) && 1 === $r['total'], $r );
site( EDITOR, 1 );
t_add_post( array( 'post_status' => 'private', 'post_author' => 2, 'post_content' => 'the code is K7Q9' ) ); t_add_post( array( 'post_status' => 'draft', 'post_author' => 2, 'post_content' => 'ACME deal' ) ); t_add_post( array( 'post_password' => 'pw', 'post_author' => 2, 'post_content' => 'PIN 4412' ) );
check( 'an editor (edit_others_posts, read_private_posts) does see them, with totals', 1 === call( 'posts-list', array( 'status' => 'any', 'search' => 'K7Q' ) )['total'] && 1 === call( 'posts-list', array( 'status' => 'draft', 'search' => 'ACME' ) )['total'] && 1 === call( 'search', array( 'query' => '4412' ) )['total'] && 1 === call( 'search', array( 'query' => 'K7Q' ) )['total'] );
site( READER, 3 );
$pp = t_add_post( array( 'post_status' => 'private', 'post_author' => 2 ) );
t_add_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $pp, 'post_title' => 'M9Zsecret', 'post_mime_type' => 'image/png' ) );
$r = call( 'media-list', array( 'search' => 'M9Z' ) );
check( 'media-list: a file under a private post gives a subscriber no item and no total', array() === ids( $r ) && ! array_key_exists( 'total', $r ) && ! array_key_exists( 'total_pages', $r ), $r );
site( EDITOR, 1 );
check( 'media-list never reports a total (the parent check happens after the query)', ! array_key_exists( 'total', call( 'media-list' ) ) );

echo "belt and braces: a total never survives a dropped item\n";
site( READER, 3 );
t_add_post( array( 'post_status' => 'draft', 'post_author' => 2 ) ); t_add_post();
$r = d5b_rc_list( array( 'post_status' => array( 'publish', 'draft' ) ), array( 'post' ), array() );
check( 'd5b_rc_list: when the PHP check drops an item (a query that was not scoped), total and total_pages are left out', 1 === count( $r['items'] ) && ! array_key_exists( 'total', $r ) && ! array_key_exists( 'total_pages', $r ), $r );

echo "strictest switch wins\n";
site( EDITOR, 1 );
d5b_abilities_set_category( 'pages', false, false, 'tester' ); d5b_abilities_set_category( 'posts', false, false, 'tester' );
$pg = t_add_post( array( 'post_type' => 'page', 'post_content' => 'PAGE BODY', 'post_author' => 2, 'post_status' => 'private' ) );
$po = t_add_post( array( 'post_content' => 'POST BODY', 'post_author' => 1 ) );
$pg_rev = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $pg, 'post_content' => 'old page' ) );
$po_rev = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $po, 'post_content' => 'old post' ) );
check( 'pages and posts off: revisions-compare on a page (live/live, rev/live) returns nothing', 'FORBIDDEN' === call( 'revisions-compare', array( 'from' => $pg, 'to' => $pg ) ) && 'FORBIDDEN' === call( 'revisions-compare', array( 'from' => $pg_rev, 'to' => $pg ) ) && 'not_allowed' === code( d5b_ability_run( 'divi5-builder/revisions-compare', array( 'from' => $pg, 'to' => $pg ) ) ) );
check( 'revisions-list / revisions-get / posts-revisions on content of an off category: refused, not_allowed from the handler', 'FORBIDDEN' === call( 'revisions-list', array( 'post_id' => $po ) ) && 'FORBIDDEN' === call( 'revisions-get', array( 'id' => $po_rev ) ) && 'FORBIDDEN' === call( 'posts-revisions', array( 'id' => $po ) ) && 'not_allowed' === code( d5b_ability_run( 'divi5-builder/revisions-list', array( 'post_id' => $po ) ) ) && 'not_allowed' === code( d5b_ability_run( 'divi5-builder/revisions-get', array( 'id' => $po_rev ) ) ) );
$nq = count( $GLOBALS['T_queries'] );
check( 'search with types [page] returns nothing; the default search leaves posts and pages out', array() === ids( call( 'search', array( 'query' => 'BODY', 'types' => array( 'page' ) ) ) ) && array() === ids( call( 'search', array( 'query' => 'BODY' ) ) ) );
check( 'search does not even query a type whose category is off', ( function () use ( $nq ) { foreach ( array_slice( $GLOBALS['T_queries'], $nq ) as $q ) { if ( array_intersect( (array) $q['post_type'], array( 'page', 'post' ) ) ) { return false; } } return true; } )() );
check( 'meta-get on a post of an off category: refused, not_allowed from the handler', 'FORBIDDEN' === call( 'meta-get', array( 'post_id' => $po ) ) && 'not_allowed' === code( d5b_ability_run( 'divi5-builder/meta-get', array( 'post_id' => $po ) ) ) );
$cm = t_add_comment( array( 'comment_post_ID' => $po ) );
check( 'comments on content of an off category are not listed or returned, and the post filter is refused', array() === ids( call( 'comments-list' ) ) && 'FORBIDDEN' === call( 'comments-get', array( 'id' => $cm ) ) && 'FORBIDDEN' === call( 'comments-list', array( 'post' => $po ) ) && 'not_allowed' === code( d5b_ability_run( 'divi5-builder/comments-list', array( 'post' => $po ) ) ) );
t_add_type( 'book', true, true ); $bk = t_add_post( array( 'post_type' => 'book' ) ); $bk_rev = t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $bk ) );
check( 'categories that are on still work (a custom item, its meta and revisions)', is_array( call( 'meta-get', array( 'post_id' => $bk ) ) ) && is_array( call( 'revisions-get', array( 'id' => $bk_rev ) ) ) );
d5b_abilities_set_category( 'cpt', false, false, 'tester' );
check( 'with cpt off too, a custom item is out of reach through meta-get and revisions', 'FORBIDDEN' === call( 'meta-get', array( 'post_id' => $bk ) ) && 'FORBIDDEN' === call( 'revisions-get', array( 'id' => $bk_rev ) ) );
d5b_abilities_set_category( 'cpt', true, false, 'tester' );
site( array_merge( EDITOR, array( 'edit_theme_options' ) ), 1 );
d5b_abilities_set_category( 'posts', false, false, 'tester' );
$GLOBALS['T_menus'][8] = (object) array( 'term_id' => 8, 'name' => 'Side', 'slug' => 'side', 'count' => 2 );
$po = t_add_post( array( 'post_content' => 'POST BODY', 'post_author' => 1 ) );
$GLOBALS['T_menu_items'][8] = array(
	(object) array( 'ID' => 81, 'title' => 'Our post', 'url' => '/our-post', 'type' => 'post_type', 'object' => 'post', 'object_id' => $po, 'menu_item_parent' => 0, 'menu_order' => 1 ),
	(object) array( 'ID' => 82, 'title' => 'Shop', 'url' => 'https://shop.example', 'type' => 'custom', 'object' => 'custom', 'object_id' => 82, 'menu_item_parent' => 0, 'menu_order' => 2 ),
);
$mi = call( 'menus-items', array( 'menu' => 'side' ) )['items'];
check( 'menus-items: a link to a post of an off category keeps its slot but loses title and url; other items are untouched', 2 === count( $mi ) && '' === $mi[0]['title'] && '' === $mi[0]['url'] && 81 === $mi[0]['id'] && 'Shop' === $mi[1]['title'] );

echo "only exposed post types\n";
site( READER, 3 );
t_add_type( 'user_request', false, false );
$ur = t_add_post( array( 'post_type' => 'user_request', 'post_title' => 'victim@example.org' ) ); t_add_meta( $ur, 'note', 'visible?' );
check( 'meta-get, revisions-list and comments-list refuse a non-public, non-REST type such as user_request', 'FORBIDDEN' === call( 'meta-get', array( 'post_id' => $ur ) ) && 'not_found' === code( d5b_ability_run( 'divi5-builder/meta-get', array( 'post_id' => $ur ) ) ) && 'FORBIDDEN' === call( 'comments-list', array( 'post' => $ur ) ) );

echo "output bounds\n";
site( READER, 3 );
$big = t_add_post();
t_add_meta( $big, 'huge', str_repeat( 'A', 70000 ) ); t_add_meta( $big, 'small', 'ok' );
$r = call( 'meta-get', array( 'post_id' => $big ) );
check( 'meta-get: a value over 64 KB is replaced by {_truncated, bytes}; small ones are untouched', is_array( $r ) && is_array( $r['meta']['huge'] ?? null ) && true === $r['meta']['huge']['_truncated'] && $r['meta']['huge']['bytes'] > 70000 && 'ok' === $r['meta']['small'] && false === $r['truncated'], $r );
$many = t_add_post();
for ( $i = 0; $i < 50; $i++ ) { t_add_meta( $many, "k$i", str_repeat( 'B', 60000 ) ); }
$r = call( 'meta-get', array( 'post_id' => $many ) );
check( 'meta-get: the total is held to 512 KB and truncated is set', is_array( $r ) && true === $r['truncated'] && count( $r['meta'] ) < 10 && strlen( json_encode( $r ) ) < 600000, is_array( $r ) ? array( count( $r['meta'] ), strlen( json_encode( $r ) ) ) : $r );
$ob = t_add_post(); t_add_meta( $ob, 'obj', new stdClass() ); t_add_meta( $ob, 'inc', unserialize( 'O:11:"NotExisting":0:{}' ) ); t_add_meta( $ob, 'nest', array( 'a' => array( 1, new stdClass() ) ) ); t_add_meta( $ob, 'arr', array( 'x' => 1 ) );
$r = call( 'meta-get', array( 'post_id' => $ob ) );
check( 'meta-get: scalars and arrays pass through; objects (also __PHP_Incomplete_Class) become "[object]", even inside arrays', '[object]' === $r['meta']['obj'] && '[object]' === $r['meta']['inc'] && array( 'a' => array( 1, '[object]' ) ) === $r['meta']['nest'] && array( 'x' => 1 ) === $r['meta']['arr'], $r );
site( EDITOR, 1 );
$P = t_add_post( array( 'post_author' => 1 ) );
for ( $i = 0; $i < 130; $i++ ) { t_add_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $P, 'post_content' => "rev $i" ) ); }
$r = call( 'revisions-list', array( 'post_id' => $P, 'per_page' => 500 ) );
$q = end( $GLOBALS['T_queries'] );
check( 'revisions-list pages in the database: 130 revisions, 100 per page, only ids fetched by the query, total 130', is_array( $r ) && 100 === count( $r['items'] ) && 130 === $r['total'] && 'ids' === $q['fields'] && 'revision' === $q['post_type'] && 100 === $q['posts_per_page'] && $P === $q['post_parent'], $q );
check( 'revisions-list page 2 holds the rest, none repeated', 30 === count( call( 'revisions-list', array( 'post_id' => $P, 'per_page' => 100, 'page' => 2 ) )['items'] ) );
check( 'revisions-list never loads the whole list (wp_get_post_revisions is not called by the plugin code)', false === strpos( file_get_contents( $dir . '/inc/abilities/read-structure.php' ), 'wp_get_post_revisions(' ) );

echo "minor fixes\n";
site( EDITOR, 1 );
$r = d5b_ability_run( 'divi5-builder/posts-list', array( str_repeat( 'x', 65536 ) => 1 ) );
check( 'the unknown-input error names at most 100 characters of the key', is_wp_error( $r ) && 'invalid_input' === $r->code && strlen( $r->message ) < 250, is_wp_error( $r ) ? strlen( $r->message ) : $r );
$ok = true; $P = t_add_post( array( 'post_author' => 1 ) );
foreach ( array( array( 'posts-list', array( 'page' => PHP_INT_MAX ) ), array( 'posts-list', array( 'page' => '99999999999999999999999' ) ), array( 'taxonomy-list', array( 'page' => PHP_INT_MAX ) ), array( 'revisions-list', array( 'post_id' => $P, 'page' => PHP_INT_MAX ) ), array( 'comments-list', array( 'page' => PHP_INT_MAX ) ) ) as $c ) {
	$r = d5b_ability_run( 'divi5-builder/' . $c[0], $c[1] );
	if ( ! is_array( $r ) || 100000 !== $r['page'] ) { $ok = false; }
}
check( 'page is clamped to 100000 everywhere (no overflow)', $ok );
site( array( 'read' ), 3 );
$GLOBALS['T_menus'][7] = (object) array( 'term_id' => 7, 'name' => 'Main', 'slug' => 'main', 'count' => 0 );
check( 'menus abilities need edit_theme_options: a subscriber is refused all three, and the handlers refuse too', 'FORBIDDEN' === call( 'menus-list' ) && 'FORBIDDEN' === call( 'menus-items', array( 'menu' => 'main' ) ) && 'FORBIDDEN' === call( 'menus-locations' ) && 'forbidden' === code( d5b_ability_run( 'divi5-builder/menus-list', array() ) ) );
$cp = t_add_post();
$note = t_add_comment( array( 'comment_post_ID' => $cp, 'comment_type' => 'note', 'comment_content' => 'block note' ) ); $ord = t_add_comment( array( 'comment_post_ID' => $cp, 'comment_type' => 'order_note', 'comment_content' => 'order note' ) );
$ping = t_add_comment( array( 'comment_post_ID' => $cp, 'comment_type' => 'pingback' ) ); $legacy = t_add_comment( array( 'comment_post_ID' => $cp, 'comment_type' => '' ) ); $norm = t_add_comment( array( 'comment_post_ID' => $cp ) );
$r = call( 'comments-list' );
check( 'comments: only comment, pingback, trackback and the legacy empty type; block notes and order notes are excluded from list and get', 3 === count( ids( $r ) ) && array() === array_diff( ids( $r ), array( $norm, $legacy, $ping ) ), ids( $r ) );
check( 'comments-get refuses a note and an order note', 'FORBIDDEN' === call( 'comments-get', array( 'id' => $note ) ) && 'FORBIDDEN' === call( 'comments-get', array( 'id' => $ord ) ) && is_array( call( 'comments-get', array( 'id' => $ping ) ) ) );

echo "\n" . ( $n - $failed ) . " / $n passed\n";
exit( $failed ? 1 : 0 );
