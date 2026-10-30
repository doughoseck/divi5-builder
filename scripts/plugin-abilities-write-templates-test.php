<?php
/**
 * Write abilities for block themes (#32, release 2.3.0, Task 6): templates-update and global-styles-set.
 *
 *   php scripts/plugin-abilities-write-templates-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php: block templates from files and from posts, the
 * wp_theme term, the global styles resolver and a MODEL of WP_Theme_JSON::remove_insecure_properties; see the stubs).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: on a classic theme both answer is_block_theme false and write nothing (no history row, audited
 * ok); a theme-file template becomes a customised post as core's templates controller makes it (slug, type, publish, the
 * wp_theme term, origin, title, description, a part's area) and WordPress then uses it; the hash of the file content is
 * accepted, a wrong one is stale; content that does not parse is refused; unfiltered_html is required (permission and
 * handler); the history keeps the previous content in full and its source; global-styles-set writes the user layer only
 * (the theme layer still shows through the merged read), sanitised through remove_insecure_properties, stale on a wrong
 * user_hash, and the history keeps the previous user JSON; global-styles-get returns user and user_hash; templates-list
 * returns hash.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.3.0' );
require __DIR__ . '/plugin-test-stubs.php';
function wp_json_encode( $v ) { return json_encode( $v ); }
foreach ( array( 'parts', 'switches', 'abilities/categories', 'abilities/register', 'abilities/secrets', 'abilities/audit', 'abilities/scrub', 'abilities/history', 'abilities/read-content', 'abilities/read-site', 'abilities/write-common', 'blocks-text-fields', 'blocks', 'abilities/write-templates' ) as $f ) { require $dir . '/inc/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 900 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const ADMIN = array( 'read', 'manage_options', 'edit_posts', 'unfiltered_html' );
const HOME  = '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->';
const HEAD  = '<!-- wp:site-title /-->';

function site( bool $block = true, bool $risky = true, array $caps = ADMIN ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( false );
	unset( $GLOBALS['T_theme'] );
	$GLOBALS['T_user_id'] = 3; $_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
	if ( $risky ) { d5b_abilities_set_category( 'templates', true, true, 'tester' ); }
	// core registers the three with revisions; wp_template_part_area is a flat taxonomy.
	foreach ( array( 'wp_template', 'wp_template_part', 'wp_global_styles' ) as $t ) { t_add_type( $t, false, true, false, array( 'title', 'editor', 'revisions' ) ); }
	t_add_taxonomy( 'wp_template_part_area', false, false );
	$GLOBALS['T_block_theme'] = $block;
	$GLOBALS['T_block_templates'] = array(
		(object) array( 'id' => 'teststyle//home', 'slug' => 'home', 'theme' => 'teststyle', 'type' => 'wp_template', 'title' => 'Home', 'description' => 'The home page', 'source' => 'theme', 'status' => 'publish', 'has_theme_file' => true, 'is_custom' => false, 'wp_id' => null, 'content' => HOME ),
		(object) array( 'id' => 'teststyle//header', 'slug' => 'header', 'theme' => 'teststyle', 'type' => 'wp_template_part', 'title' => 'Header', 'description' => '', 'area' => 'header', 'source' => 'theme', 'status' => 'publish', 'has_theme_file' => true, 'is_custom' => false, 'wp_id' => null, 'content' => HEAD ),
	);
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function handler( $name, $in = array() ) { return call_user_func( d5b_ability_defs()[ 'divi5-builder/' . $name ]['handler'], $in ); }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function msg( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
function edata( $r ) { return is_wp_error( $r ) ? $r->get_error_data() : null; }
function hrows() { global $wpdb; return $wpdb->tables[ d5b_history_table() ] ?? array(); }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function arows() { global $wpdb; return array_values( $wpdb->tables[ d5b_audit_table() ] ?? array() ); }
function posts_of( $type ) { return array_values( array_filter( $GLOBALS['T_posts'], function ( $p ) use ( $type ) { return $p->post_type === $type; } ) ); }
function writes() { return count( $GLOBALS['T_inserts'] ) + count( $GLOBALS['T_updates'] ); }
function user_post() { $p = posts_of( 'wp_global_styles' ); return $p ? $p[0] : null; }
function user_json() { $p = user_post(); return $p ? json_decode( $p->post_content, true ) : null; }
function term_names( $id, $tax ) { $o = array(); foreach ( (array) ( $GLOBALS['T_object_terms'][ (int) $id ][ $tax ] ?? array() ) as $t ) { $o[] = $GLOBALS['T_terms'][ (int) $t ]->name; } return $o; }

echo "registration\n";
site();
$defs = d5b_ability_defs();
$tu = $defs['divi5-builder/templates-update'] ?? null; $gs = $defs['divi5-builder/global-styles-set'] ?? null;
check( 'two definitions in the templates category, both risky, not readonly', null !== $tu && null !== $gs && 'templates' === $tu['category'] && 'templates' === $gs['category'] && true === $tu['risky'] && true === $gs['risky'] && false === $tu['annotations']['readonly'] && false === $gs['annotations']['readonly'] );
check( 'required inputs: id + content + expect_hash; styles + expect_hash', array( 'id', 'content', 'expect_hash' ) === $tu['input_schema']['required'] && array( 'styles', 'expect_hash' ) === $gs['input_schema']['required'] );
function registered( bool $risky ) {
	site( true, $risky );
	t_abilities_reset();
	add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' ); add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );
	t_fire( 'wp_abilities_api_categories_init' ); t_fire( 'wp_abilities_api_init' );
	return array( isset( $GLOBALS['T_abilities']['divi5-builder/templates-update'] ), isset( $GLOBALS['T_abilities']['divi5-builder/global-styles-set'] ) );
}
check( 'risky off: neither is registered', array( false, false ) === registered( false ) );
check( 'risky on: both are registered', array( true, true ) === registered( true ) );
site( true, false );
check( 'risky off: a direct call to each handler is refused not_allowed; nothing written',
	'not_allowed' === code( handler( 'templates-update', array( 'id' => 'teststyle//home', 'content' => HOME, 'expect_hash' => md5( HOME ) ) ) ) && 'not_allowed' === code( handler( 'global-styles-set', array( 'styles' => array(), 'expect_hash' => md5( '' ) ) ) ) && 0 === writes() && 0 === count( hrows() ) );

echo "classic theme\n";
site( false );
$a0 = count( arows() );
$r1 = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => '<p>x</p>', 'expect_hash' => md5( HOME ) ) );
$r2 = call( 'global-styles-set', array( 'styles' => array( 'styles' => array( 'color' => array( 'text' => '#123' ) ) ), 'expect_hash' => md5( '' ) ) );
check( 'classic theme: both answer is_block_theme false with a note, nothing written, no history row', is_array( $r1 ) && false === $r1['is_block_theme'] && ! empty( $r1['note'] ) && is_array( $r2 ) && false === $r2['is_block_theme'] && ! empty( $r2['note'] )
	&& 0 === writes() && array() === $GLOBALS['T_posts'] && 0 === count( hrows() ), array( $r1, $r2 ) );
check( 'classic theme: both calls are audited ok', $a0 + 2 === count( arows() ) && 'ok' === arows()[ $a0 ]['status'] && 'ok' === arows()[ $a0 + 1 ]['status'], arows() );

echo "templates-update: theme-file template\n";
site();
$new = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>New home</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
$r = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $new, 'expect_hash' => md5( 'something else' ) ) );
check( 'a wrong hash is stale, with the hash of the file content; nothing written', 'stale' === code( $r ) && md5( HOME ) === ( edata( $r )['hash'] ?? null ) && 0 === writes() && 0 === count( hrows() ), array( code( $r ), edata( $r ) ) );
$r = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $new ) );
check( 'no expect_hash: invalid_input', 'invalid_input' === code( $r ) && 0 === writes() );
foreach ( array( '<!-- wp:group --><p>never closed</p>', '<!-- wp:group --><p>x</p><!-- /wp:columns -->', '<!-- wp:paragraph {"a":} --><p>x</p><!-- /wp:paragraph -->' ) as $bad ) {
	$r = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $bad, 'expect_hash' => md5( HOME ) ) );
	check( 'content that does not parse is invalid_input; nothing written: ' . substr( $bad, 0, 30 ), 'invalid_input' === code( $r ) && 0 === writes() && 0 === count( hrows() ), array( code( $r ), msg( $r ) ) );
}
$r = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => '<p>a [redacted] b</p>', 'expect_hash' => md5( HOME ) ) );
check( 'text holding [redacted] is masked_read', 'masked_read' === code( $r ) && 0 === writes() );
$r = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => '<p>' . str_repeat( 'a', 1048576 ) . '</p>', 'expect_hash' => md5( HOME ) ) );
check( 'content over 1 MB is refused', 'invalid_input' === code( $r ) && 0 === writes() );
foreach ( array( 'teststyle//nope' => 'not_found', 'otherstyle//home' => 'not_found', '../etc' => 'invalid_input', 'teststyle//home/x' => 'invalid_input' ) as $bid => $want ) {
	$r = call( 'templates-update', array( 'id' => $bid, 'content' => HOME, 'expect_hash' => md5( HOME ) ) );
	check( "id $bid: $want", $want === code( $r ) && 0 === writes(), code( $r ) );
}
check( 'unknown input refused', 'invalid_input' === code( call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $new, 'expect_hash' => md5( HOME ), 'type' => 'wp_template' ) ) ) );

site( true, true, array( 'read', 'manage_options', 'edit_posts' ) );
$r = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $new, 'expect_hash' => md5( HOME ) ) );
$h = handler( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $new, 'expect_hash' => md5( HOME ) ) );
check( 'without unfiltered_html: the permission refuses, and the handler answers needs_unfiltered_html; nothing written', 'FORBIDDEN' === $r && 'needs_unfiltered_html' === code( $h ) && 0 === writes() && 0 === count( hrows() ), array( $r, code( $h ) ) );
site( true, true, array( 'read', 'edit_posts', 'unfiltered_html' ) );
check( 'without manage_options: refused', 'FORBIDDEN' === call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $new, 'expect_hash' => md5( HOME ) ) ) && 'forbidden' === code( handler( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $new, 'expect_hash' => md5( HOME ) ) ) ) && 0 === writes() );

site();
$list = call( 'templates-list' );
check( 'templates-list returns hash: md5 of the file content', is_array( $list ) && md5( HOME ) === $list['items'][0]['hash'], $list );
$get = call( 'templates-get', array( 'id' => 'teststyle//home' ) );
check( 'templates-get hash: md5 of the file content', is_array( $get ) && md5( HOME ) === $get['hash'] && 'theme' === $get['source'] );
$r = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $new, 'expect_hash' => strtoupper( $get['hash'] ) ) );
$tp = posts_of( 'wp_template' );
check( 'the hash of the file content (any case) is accepted: created true, source custom, one wp_template post', is_array( $r ) && true === $r['created'] && true === $r['changed'] && 'custom' === $r['source'] && 1 === count( $tp ) && $tp[0]->ID === $r['wp_id'] && md5( $new ) === $r['hash'], $r );
$p = $tp[0];
check( 'the post as core\'s templates controller makes it: slug, publish, content, title, description, origin, the wp_theme term', 'home' === $p->post_name && 'publish' === $p->post_status && $new === $p->post_content && 'Home' === $p->post_title && 'The home page' === $p->post_excerpt
	&& 'theme' === get_post_meta( $p->ID, 'origin', true ) && array( 'teststyle' ) === term_names( $p->ID, 'wp_theme' ), array( (array) $p, term_names( $p->ID, 'wp_theme' ) ) );
$get = call( 'templates-get', array( 'id' => 'teststyle//home' ) );
check( 'WordPress now uses it: templates-get answers source custom with the new content and its hash', is_array( $get ) && 'custom' === $get['source'] && $new === $get['content'] && md5( $new ) === $get['hash'], $get );
$row = hlast();
check( 'history: object_type wp_template, object_id the new post, before = the file content in full and source theme, summary', 1 === count( hrows() ) && 'wp_template' === $row['object_type'] && (int) $p->ID === (int) $row['object_id'] && 'divi5-builder/templates-update' === $row['ability']
	&& array( 'content' => HOME, 'source' => 'theme' ) === $row['before'] && 'Updated template teststyle//home' === $row['summary'] && 0 === (int) $row['revision_id'], $row );
$r = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => HOME, 'expect_hash' => md5( HOME ) ) );
check( 'the file hash after it was customised: stale (with the hash of the stored content)', 'stale' === code( $r ) && md5( $new ) === edata( $r )['hash'] );
$new2 = '<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->';
$r = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $new2, 'expect_hash' => md5( $new ) ) );
$row = hlast();
check( 'a customised template is updated in place (no new post); before = the previous content, source custom', is_array( $r ) && false === $r['created'] && $p->ID === $r['wp_id'] && 1 === count( posts_of( 'wp_template' ) ) && $new2 === get_post( $p->ID )->post_content
	&& array( 'content' => $new, 'source' => 'custom' ) === $row['before'] && (int) $p->ID === (int) $row['object_id'], array( $r, $row ) );
$new3 = '<!-- wp:paragraph --><p>Third</p><!-- /wp:paragraph -->';
$revs = array_values( array_filter( wp_get_post_revisions( $p->ID ), function ( $x ) use ( $new2 ) { return $x->post_content === $new2; } ) );
$r = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $new3, 'expect_hash' => md5( $new2 ) ) );
$row = hlast();
check( 'revision_id points at the revision holding the previous content; the content is kept in full too', is_array( $r ) && 1 === count( $revs ) && (int) $revs[0]->ID === (int) $row['revision_id'] && $new2 === $row['before']['content'], array( $row, count( $revs ) ) );
$c = count( hrows() );
$r = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $new3, 'expect_hash' => md5( $new3 ) ) );
check( 'the same content again: changed false, no new row', is_array( $r ) && false === $r['changed'] && $c === count( hrows() ) );
$list = call( 'templates-list' );
check( 'templates-list hash follows the customised content', md5( $new3 ) === $list['items'][0]['hash'] && 'custom' === $list['items'][0]['source'] );

site();
$part = '<!-- wp:site-title {"level":2} /-->';
$r = call( 'templates-update', array( 'id' => 'teststyle//header', 'content' => $part, 'expect_hash' => md5( HEAD ) ) );
$pp = posts_of( 'wp_template_part' );
check( 'a template part becomes a wp_template_part post with its area and theme terms', is_array( $r ) && 'wp_template_part' === $r['type'] && 1 === count( $pp ) && array( 'header' ) === term_names( $pp[0]->ID, 'wp_template_part_area' ) && array( 'teststyle' ) === term_names( $pp[0]->ID, 'wp_theme' ) && 'wp_template_part' === hlast()['object_type'], array( $r, $pp ) );
site( true, true, array( 'read', 'manage_options', 'unfiltered_html' ) );
$r = call( 'templates-update', array( 'id' => 'teststyle//home', 'content' => $new, 'expect_hash' => md5( HOME ) ) );
check( 'when WordPress drops the theme term (no assign_terms), the read-back finds no customised template: write_failed with the post id', 'write_failed' === code( $r ) && isset( edata( $r )['post_id'] ) && 0 === count( hrows() ), array( code( $r ), msg( $r ) ) );

echo "global-styles-set\n";
site();
$g = call( 'global-styles-get' );
check( 'global-styles-get with no user layer: user empty, user_hash md5 of ""', is_array( $g ) && array() === $g['user'] && md5( '' ) === $g['user_hash'] && 0 === count( posts_of( 'wp_global_styles' ) ), $g );
$styles = array( 'styles' => array( 'color' => array( 'text' => '#111111' ), 'elements' => array( 'link' => array( 'color' => array( 'text' => 'javascript:alert(1)', 'background' => '#eee' ) ) ), 'typography' => array( 'fontSize' => 'expression(alert(1))' ) ) );
$r = call( 'global-styles-set', array( 'styles' => $styles, 'expect_hash' => 'f' . substr( md5( '' ), 1 ) ) );
check( 'a wrong user_hash is stale; nothing written', 'stale' === code( $r ) && md5( '' ) === edata( $r )['hash'] && 0 === count( posts_of( 'wp_global_styles' ) ) && 0 === writes(), code( $r ) );
$r = call( 'global-styles-set', array( 'styles' => $styles, 'expect_hash' => $g['user_hash'] ) );
$u = user_json();
check( 'written: created, one wp_global_styles post with the theme term, flag and version', is_array( $r ) && true === $r['created'] && true === $r['changed'] && 1 === count( posts_of( 'wp_global_styles' ) ) && array( 'teststyle' ) === term_names( user_post()->ID, 'wp_theme' )
	&& true === $u['isGlobalStylesUserThemeJSON'] && 2 === $u['version'] && md5( user_post()->post_content ) === $r['user_hash'], array( $r, $u ) );
check( 'insecure properties removed (javascript: and expression( values), the safe ones kept; removed_insecure true with a warning',
	'#111111' === $u['styles']['color']['text'] && '#eee' === $u['styles']['elements']['link']['color']['background'] && ! isset( $u['styles']['elements']['link']['color']['text'] ) && ! isset( $u['styles']['typography'] )
	&& true === $r['removed_insecure'] && ! empty( $r['warnings'] ), array( $u, $r ) );
check( 'the user layer only: the theme\'s palette and background are NOT copied into the user post', ! isset( $u['settings'] ) && ! isset( $u['styles']['color']['background'] ), $u );
$g = call( 'global-styles-get' );
$m = json_decode( $g['content'], true );
check( 'the merged read still shows the theme defaults under the user change', '#111111' === $m['styles']['color']['text'] && '#ffffff' === $m['styles']['color']['background'] && '#ffffff' === $m['settings']['color']['palette'][0]['color'], $m );
check( 'global-styles-get returns user (the stored layer) and user_hash (md5 of the stored JSON)', $u === $g['user'] && md5( user_post()->post_content ) === $g['user_hash'], $g );
$row = hlast();
check( 'history of the first write: object_type wp_global_styles, object_id the post, before json "" (there was none), summary', 'wp_global_styles' === $row['object_type'] && (int) user_post()->ID === (int) $row['object_id'] && array( 'json' => '' ) === $row['before'] && 'Updated global styles' === $row['summary'], $row );

$prev = user_post()->post_content; $pid = user_post()->ID;
$r = call( 'global-styles-set', array( 'styles' => array( 'styles' => array( 'color' => array( 'text' => '#222' ) ) ), 'expect_hash' => md5( 'nope' ) ) );
check( 'stale again on a wrong hash; the stored layer is unchanged', 'stale' === code( $r ) && $prev === user_post()->post_content );
$round = $g['user']; $round['styles']['color']['text'] = '#222222';
$r = call( 'global-styles-set', array( 'styles' => json_encode( $round ), 'expect_hash' => $g['user_hash'] ) );
$row = hlast();
check( 'a round trip of user (with version and the flag), as JSON text: replaced in the same post; before = the previous user JSON', is_array( $r ) && false === $r['created'] && $pid === $r['id'] && 1 === count( posts_of( 'wp_global_styles' ) ) && '#222222' === user_json()['styles']['color']['text']
	&& array( 'json' => $prev ) === $row['before'] && false === $r['removed_insecure'] && ! isset( $r['warnings'] ), array( $r, $row ) );
$h = md5( user_post()->post_content );
$r = call( 'global-styles-set', array( 'styles' => array( 'settings' => array( 'layout' => array( 'contentSize' => '640px' ) ) ), 'expect_hash' => $h ) );
check( 'the layer is replaced whole: styles left out are no longer in it', is_array( $r ) && ! isset( user_json()['styles'] ) && '640px' === user_json()['settings']['layout']['contentSize'] );
$h = md5( user_post()->post_content );
foreach ( array( array( 'colors' => array() ), array( 'styles' => 'red' ), array( 'styles' => array( 1, 2 ) ), array( 1, 2 ) ) as $bad ) {
	$r = call( 'global-styles-set', array( 'styles' => $bad, 'expect_hash' => $h ) );
	check( 'refused as invalid_input: ' . json_encode( $bad ), 'invalid_input' === code( $r ) && $h === md5( user_post()->post_content ), code( $r ) );
}
foreach ( array( '[1,2]', 'not json', '{"styles":' ) as $bad ) {
	check( 'refused as invalid_input (text): ' . $bad, 'invalid_input' === code( call( 'global-styles-set', array( 'styles' => $bad, 'expect_hash' => $h ) ) ) );
}
check( 'styles over 1 MB refused', 'invalid_input' === code( call( 'global-styles-set', array( 'styles' => array( 'styles' => array( 'x' => str_repeat( 'a', 1048576 ) ) ), 'expect_hash' => $h ) ) ) );
check( 'markup in styles.css refused (core\'s validate_custom_css)', 'invalid_input' === code( call( 'global-styles-set', array( 'styles' => array( 'styles' => array( 'css' => 'p{} </style><script>' ) ), 'expect_hash' => $h ) ) ) );
check( 'text holding [redacted] is masked_read', 'masked_read' === code( call( 'global-styles-set', array( 'styles' => array( 'styles' => array( 'color' => array( 'text' => '[redacted]' ) ) ), 'expect_hash' => $h ) ) ) );
$r = call( 'global-styles-set', array( 'styles' => array( 'styles' => array( 'css' => 'p{color:red}' ) ), 'expect_hash' => $h ) );
check( 'custom CSS is kept for a caller with edit_css', is_array( $r ) && 'p{color:red}' === user_json()['styles']['css'] && false === $r['removed_insecure'], $r );
site( true, true, array( 'read', 'manage_options', 'edit_posts' ) );
$r = call( 'global-styles-set', array( 'styles' => array( 'styles' => array( 'css' => 'p{color:red}', 'color' => array( 'text' => '#333' ) ) ), 'expect_hash' => md5( '' ) ) );
check( 'without edit_css the custom CSS is removed by the sanitising, the rest kept', is_array( $r ) && ! isset( user_json()['styles']['css'] ) && '#333' === user_json()['styles']['color']['text'] && true === $r['removed_insecure'], array( $r, user_json() ) );
site( true, true, array( 'read', 'edit_posts', 'unfiltered_html' ) );
check( 'without manage_options: refused', 'FORBIDDEN' === call( 'global-styles-set', array( 'styles' => array(), 'expect_hash' => md5( '' ) ) ) && 0 === writes() );

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
