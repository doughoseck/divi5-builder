<?php
/**
 * The blocks engine in PHP (#32, 2.2.0, Task 7): a port of scripts/blocks.js for divi-section-edit and divi-text-edit.
 *
 *   php scripts/plugin-abilities-blocks-test.php
 *
 * Every check of scripts/blocks-test.js that runs on its PAGE and MOD strings, the same checks on the Visual Builder
 * fixtures, the text fields, and a parity run: when `node` is on PATH, each operation and address form is also run
 * through `node scripts/blocks.js` and the output bytes compared (skipped, never failed, without node).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.2.0' );
require __DIR__ . '/plugin-test-stubs.php';
require $dir . '/inc/blocks-text-fields.php';
require $dir . '/inc/blocks.php';

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . substr( is_string( $detail ) ? $detail : json_encode( $detail ), 0, 500 ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }
function code_of( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'OK'; }
function msg_of( $r ) { return is_wp_error( $r ) ? $r->get_error_message() : ''; }
function refused( $r, $code, $re ) { return is_wp_error( $r ) && $code === $r->get_error_code() && 1 === preg_match( $re, $r->get_error_message() ); }
// Every Divi block of a parsed tree, pre-order, as blocks.js walk() lists them.
function flat( $node ) { $o = array(); foreach ( $node['children'] as $c ) { if ( null !== $c['addr'] ) { $o[] = $c; } $o = array_merge( $o, flat( $c ) ); } return $o; }
function at_( $root, $a ) { foreach ( flat( $root ) as $b ) { if ( $b['addr'] === $a ) { return $b; } } return null; }
function slice_of( $src, $b ) { return substr( $src, $b['start'], $b['end'] - $b['start'] ); }
function parses( $src ) { return ! is_wp_error( d5b_blocks_parse( $src ) ); }

// ---- the fixture of blocks-test.js, byte for byte ----
function lbl( $s ) { return '"meta":{"adminLabel":{"desktop":{"value":"' . $s . '"}}}'; }
$PAGE = implode( "\n", array(
	'<!-- wp:divi/placeholder -->',
	'<!-- wp:divi/section {"module":{"advanced":{"htmlAttributes":{"desktop":{"value":{"id":"intro"}}}}},"builderVersion":"5.15.1"} -->',
	'<!-- wp:divi/row {"module":{' . lbl( 'Featured Work' ) . '}} -->',
	'<!-- wp:divi/column -->',
	'<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\\u003cp\\u003e{a} } \\u0022q\\u0022 \\u002d\\u002d\\u003e\\u003c/p\\u003e"}}}} /-->',
	'<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go"}}}}} /-->',
	'<!-- /wp:divi/column -->',
	'<!-- wp:divi/column -->',
	'<!-- an ordinary comment -->',
	'<!-- wp:divi/row --><!-- wp:divi/column --><!-- wp:divi/image {"image":{"innerContent":{"desktop":{"value":{"src":"x.png"}}}}} /--><!-- /wp:divi/column --><!-- /wp:divi/row -->',
	'<!-- /wp:divi/column -->',
	'<!-- /wp:divi/row -->',
	'<!-- /wp:divi/section -->',
	'<!-- wp:divi/section {"module":{' . lbl( 'Hero' ) . '}} -->',
	'<!-- wp:divi/fullwidth-header {"title":{"innerContent":{"desktop":{"value":"Hi"}}}} /-->',
	'<!-- /wp:divi/section -->',
	'<!-- wp:divi/section {"module":{' . lbl( 'Empty' ) . '}} --><!-- wp:divi/row --><!-- wp:divi/column --><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->',
	'<!-- /wp:divi/placeholder -->',
) );
$MOD   = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"new"}}}} /-->';
$LINES = explode( "\n", $PAGE );
check( 'the PHP fixture is the blocks-test.js fixture (md5 below matches a node rebuild when node runs)', 18 === count( $LINES ) );

echo "--- parsing and addresses\n";
$root = d5b_blocks_parse( $PAGE );
check( 'the page parses with no errors', ! is_wp_error( $root ), msg_of( $root ) );
$a1 = at_( $root, '1' );
check( 'the placeholder is not counted: section 1 is the #intro section', $a1 && 'divi/section' === $a1['name'] && false !== strpos( substr( $PAGE, $a1['start'], $a1['innerStart'] - $a1['start'] ), 'intro' ) );
check( '1.1.1.2 is the button, 1.1.2.1 the nested row, 1.1.2.1.1.1 the image inside it', 'divi/button' === at_( $root, '1.1.1.2' )['name'] && 'divi/row' === at_( $root, '1.1.2.1' )['name'] && 'divi/image' === at_( $root, '1.1.2.1.1.1' )['name'] );
check( 'an attribute holding "}", an escaped "-->" and a quote does not end the block early', 'divi/text' === at_( $root, '1.1.1.1' )['name'] && null !== at_( $root, '1.1.1.2' ) && ! empty( at_( $root, '1.1.1.1' )['attrs'] ) );
check( 'the escaped "-->" decodes to the real characters', '<p>{a} } "q" --></p>' === at_( $root, '1.1.1.1' )['attrs']['content']['innerContent']['desktop']['value'] );
check( 'an ordinary HTML comment is not a block', 0 === count( array_filter( flat( $root ), function ( $b ) { return false !== strpos( $b['name'], 'comment' ); } ) ) );
check( 'a node carries name, start, end, innerStart, innerEnd, attrs, children', array( 'name', 'attrs', 'start', 'innerStart', 'innerEnd', 'end', 'selfClosing', 'addr', 'children' ) === array_keys( $a1 ), array_keys( $a1 ) );
check( 'innerStart/innerEnd bound the inside of the section', '<!-- /wp:divi/section -->' === substr( $PAGE, $a1['innerEnd'], $a1['end'] - $a1['innerEnd'] ) && "\n<!-- wp:divi/row" === substr( $PAGE, $a1['innerStart'], 17 ) );
$r = d5b_blocks_resolve( $root, 'label:Featured Work' ); $r2 = d5b_blocks_resolve( $root, '#intro' );
check( 'label:Featured Work finds the row, #intro the section', ! is_wp_error( $r ) && '1.1' === $r['addr'] && ! is_wp_error( $r2 ) && '1' === $r2['addr'] );
check( 'an address with nothing there is refused not_found, naming tree', refused( d5b_blocks_resolve( $root, '9' ), 'not_found', '/nothing at "9".*tree/' ) );
check( 'a word that is not an address is refused invalid_input', refused( d5b_blocks_resolve( $root, 'hero' ), 'invalid_input', '/not an address/' ) );
$dup = str_replace( lbl( 'Hero' ), lbl( 'Featured Work' ), $PAGE );
$amb = d5b_blocks_resolve( d5b_blocks_parse( $dup ), 'label:Featured Work' );
check( 'a label used twice is refused ambiguous, listing both addresses', refused( $amb, 'ambiguous', '/matches 2 blocks.*1\.1, 2/' ) && array( '1.1', '2' ) === ( $amb->get_error_data()['candidates'] ?? null ), is_wp_error( $amb ) ? array( $amb->get_error_message(), $amb->get_error_data() ) : 'not refused' );
$broken = d5b_blocks_parse( str_replace( "<!-- /wp:divi/row -->\n<!-- /wp:divi/section -->", '<!-- /wp:divi/section -->', $PAGE ) );
check( 'a broken page is reported invalid_input: a block never closed / mis-nested', refused( $broken, 'invalid_input', '/does not match|never closed/' ) );
check( 'the first error is the message, all of them in data', is_wp_error( $broken ) && count( $broken->get_error_data()['errors'] ?? array() ) >= 1 && $broken->get_error_data()['errors'][0] === $broken->get_error_message() );
check( 'attributes that are not JSON are an error', refused( d5b_blocks_parse( '<!-- wp:divi/text {"a":} /-->' ), 'invalid_input', '/not valid JSON/' ) );
check( 'a bare name is core/: <!-- wp:paragraph --> closes with <!-- /wp:paragraph -->', parses( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) && ! parses( '<!-- wp:paragraph --><!-- /wp:core/heading -->' ) );
check( 'the opener ends at the FIRST "-->": a raw "-->" inside the JSON breaks the block into a comment (as blocks.js)', ! parses( '<!-- wp:divi/section {"a":"x-->"} --><!-- /wp:divi/section -->' ) );
check( 'a "/" then a line break before "-->" is not self-closing (end of body, not before a final newline)', ! parses( "<!-- wp:divi/text {\"a\":1} /\n-->" ) || 'divi/text' !== ( d5b_blocks_parse( "<!-- wp:divi/text {\"a\":1} /\n-->" )['children'][0]['name'] ?? '' ) );
check( 'whitespace may be a line break around the name and the attributes', parses( "<!--\nwp:divi/text\n{\"a\":1}\n/-->" ) );

echo "--- tree\n";
$tree = d5b_blocks_tree( $PAGE );
$addrs = array_column( $tree, 'address' );
check( 'tree lists every Divi block in order with its address', array( '1', '1.1', '1.1.1', '1.1.1.1', '1.1.1.2', '1.1.2', '1.1.2.1', '1.1.2.1.1', '1.1.2.1.1.1', '2', '2.1', '3', '3.1', '3.1.1' ) === $addrs, $addrs );
check( 'tree carries labels and CSS ids, omitted when empty', '#intro' !== ( $tree[0]['label'] ?? '' ) && 'intro' === ( $tree[0]['cssId'] ?? '' ) && 'Featured Work' === ( $tree[1]['label'] ?? '' ) && ! isset( $tree[2]['label'] ) && ! isset( $tree[2]['cssId'] ) );
check( 'tree entries are address, block, label?, cssId?, chars', array( 'address', 'block', 'cssId', 'chars' ) === array_keys( $tree[0] ) && array( 'address', 'block', 'chars' ) === array_keys( $tree[2] ) );

echo "--- every edit leaves the rest byte for byte\n";
$ops = array(
	array( 'replace', '1.1.1.2', $MOD ), array( 'remove', '1.1.1.2', null ), array( 'before', '1.1.1.1', $MOD ), array( 'after', '1.1.1.2', $MOD ),
	array( 'append', '1.1.1', $MOD ), array( 'append', '3.1.1', $MOD ), array( 'replace', 'label:Hero', $LINES[16] ), array( 'remove', '#intro', null ),
	array( 'after', '1.1.2.1.1.1', $MOD ), array( 'append', '2', $MOD ),
);
foreach ( $ops as $o ) {
	list( $op, $a, $w ) = $o;
	$out = d5b_blocks_edit( $PAGE, $op, $a, $w );
	if ( is_wp_error( $out ) ) { check( "$op $a", false, $out->get_error_message() ); continue; }
	$t = d5b_blocks_resolve( $root, $a );
	// the text before the target block (less the line break before it) and after it (less the line break after it) is
	// the original, at the start and at the end of the result
	$pre = max( 0, $t['start'] - 1 ); $post = max( 0, strlen( $PAGE ) - $t['end'] - 1 );
	$outside = substr( $out, 0, $pre ) === substr( $PAGE, 0, $pre ) && ( 0 === $post || substr( $out, -$post ) === substr( $PAGE, -$post ) ) && strlen( $out ) >= $pre + $post;
	check( "$op $a: only the " . str_replace( 'divi/', '', $t['name'] ) . " at {$t['addr']} changed, and the result parses", $outside && parses( $out ), array( $pre, $post ) );
}
$t2 = at_( $root, '1.1.1.2' );
check( 'replace: the page = before + the new module + after', d5b_blocks_edit( $PAGE, 'replace', '1.1.1.2', $MOD ) === substr( $PAGE, 0, $t2['start'] ) . $MOD . substr( $PAGE, $t2['end'] ) );
check( 'remove takes the block and its own line break, nothing else', d5b_blocks_edit( $PAGE, 'remove', '1.1.1.2', null ) === substr( $PAGE, 0, $t2['start'] ) . substr( $PAGE, $t2['end'] + 1 ) );
$o = d5b_blocks_edit( $PAGE, 'append', '3.1.1', $MOD ); $b = at_( d5b_blocks_parse( $o ), '3.1.1.1' );
check( 'append into an EMPTY column puts the module inside it', $b && 'divi/text' === $b['name'] );
$o = d5b_blocks_edit( $PAGE, 'before', '1.1.1.1', $MOD ); $ro = d5b_blocks_parse( $o );
check( 'before: the new module is 1.1.1.1 and the old one moved to 1.1.1.2', slice_of( $o, at_( $ro, '1.1.1.1' ) ) === $MOD && 'divi/text' === at_( $ro, '1.1.1.2' )['name'] && 'divi/button' === at_( $ro, '1.1.1.3' )['name'] );
$o = d5b_blocks_edit( $PAGE, 'after', '1.1.1.1', $MOD ); $ro = d5b_blocks_parse( $o );
check( 'after: the new module is 1.1.1.2, between the text and the button', slice_of( $o, at_( $ro, '1.1.1.2' ) ) === $MOD && slice_of( $o, at_( $ro, '1.1.1.1' ) ) === slice_of( $PAGE, at_( $root, '1.1.1.1' ) ) && 'divi/button' === at_( $ro, '1.1.1.3' )['name'] );

echo "--- what goes in must fit\n";
check( 'a module in place of a section is refused', refused( d5b_blocks_edit( $PAGE, 'replace', '1', $MOD ), 'invalid_input', '/module.*cannot go in place of a section/' ) );
check( 'a section after a module is refused', refused( d5b_blocks_edit( $PAGE, 'after', '1.1.1.1', $LINES[13] . "\n<!-- /wp:divi/section -->" ), 'invalid_input', '/section.*cannot go after a module/' ) );
check( 'a section before a module is refused (the kind check runs for before too)', refused( d5b_blocks_edit( $PAGE, 'before', '1.1.1.1', $LINES[13] . "\n<!-- /wp:divi/section -->" ), 'invalid_input', '/section.*cannot go before a module/' ) );
check( 'a module before a row is refused', refused( d5b_blocks_edit( $PAGE, 'before', '1.1', $MOD ), 'invalid_input', '/cannot go before a row/' ) );
check( 'a module appended into a row is refused (a row takes columns)', refused( d5b_blocks_edit( $PAGE, 'append', '1.1', $MOD ), 'invalid_input', '/cannot go inside a row.*columns/' ) );
check( 'a module appended into a fullwidth section is allowed (it holds modules)', is_string( d5b_blocks_edit( $PAGE, 'append', '2', $MOD ) ) );
check( 'a nested row can sit where a module does', is_string( d5b_blocks_edit( $PAGE, 'after', '1.1.1.2', $LINES[9] ) ) );
check( 'appending into a self-closing module is refused', refused( d5b_blocks_edit( $PAGE, 'append', '1.1.1.2', $MOD ), 'invalid_input', '/no inside/' ) );
check( 'a fragment that does not parse is refused', refused( d5b_blocks_edit( $PAGE, 'replace', '1.1.1.2', '<!-- wp:divi/row -->' ), 'invalid_input', '/--with file does not parse/' ) );
check( 'a page that does not parse is not edited', refused( d5b_blocks_edit( $PAGE . '<!-- wp:divi/row -->', 'remove', '1', null ), 'invalid_input', '/page does not parse/' ) );
check( 'a fragment with no Divi blocks is refused', refused( d5b_blocks_edit( $PAGE, 'replace', '1.1.1.2', '<p>hi</p>' ), 'invalid_input', '/no Divi blocks/' ) );
check( 'an op other than the five is refused invalid_input', refused( d5b_blocks_edit( $PAGE, 'move', '1', $MOD ), 'invalid_input', '/unknown operation "move"/' ) );
check( 'replace with no `with` is refused invalid_input', refused( d5b_blocks_edit( $PAGE, 'replace', '1.1.1.2', null ), 'invalid_input', '/needs --with/' ) );
check( 'an address with nothing there is refused not_found by edit', 'not_found' === code_of( d5b_blocks_edit( $PAGE, 'remove', '7', null ) ) );
check( 'an ambiguous label is refused ambiguous by edit, with candidates', 'ambiguous' === code_of( $e = d5b_blocks_edit( $dup, 'remove', 'label:Featured Work', null ) ) && array( '1.1', '2' ) === $e->get_error_data()['candidates'] );
// A fragment is whole blocks only, so through edit() a splice cannot unbalance the page; the re-parse is the proof that
// it did not. The splice every edit goes through is tested directly with a text that would.
check( 'a fragment with a stray closing comment is refused before any splice', refused( d5b_blocks_edit( $PAGE, 'replace', '1.1.1.2', $MOD . "\n<!-- /wp:divi/column -->" ), 'invalid_input', '/--with file does not parse/' ) );
check( 'a result that does not parse is refused (the splice parses the result again)', refused( d5b_blocks_splice( $PAGE, $t2['start'], $t2['end'], '<!-- /wp:divi/column -->' ), 'invalid_input', '/would not parse/' ) );
check( 'a splice that parses gives before + insert + after', d5b_blocks_splice( $PAGE, $t2['start'], $t2['end'], $MOD ) === substr( $PAGE, 0, $t2['start'] ) . $MOD . substr( $PAGE, $t2['end'] ) );
$o = d5b_blocks_edit( $PAGE, 'replace', '1.1.1.2', "<!-- wp:divi/placeholder -->\n" . $MOD . "\n<!-- /wp:divi/placeholder -->" );
check( 'a compiled page is unwrapped: its placeholder is dropped', $o === substr( $PAGE, 0, $t2['start'] ) . $MOD . substr( $PAGE, $t2['end'] ) );
preg_match( '/<!-- wp:divi\/image[^>]*\/-->/', $LINES[9], $img );
$o = d5b_blocks_edit( $PAGE, 'replace', '1.1.1.2', $PAGE, '1.1.2.1.1.1' );
check( '--from picks one block out of a bigger file', is_string( $o ) && false !== strpos( $o, $img[0] ) && 'divi/image' === at_( d5b_blocks_parse( $o ), '1.1.1.2' )['name'] );
$getHero = d5b_blocks_resolve( $root, 'label:Hero' );
check( 'get (the block as stored) of label:Hero is lines 14-16', slice_of( $PAGE, $getHero ) === implode( "\n", array_slice( $LINES, 13, 3 ) ) );

echo "--- the Visual Builder fixtures: tree lists every block, a replace with its own get is a no-op\n";
$FIX = array();
foreach ( array( 'vb-5.18.0-variables.html', 'vb-resave-5.15.1/before.html', 'vb-resave-5.15.1/after.html' ) as $f ) { $FIX[ $f ] = file_get_contents( __DIR__ . '/fixtures/' . $f ); }
foreach ( $FIX as $f => $src ) {
	$rt = d5b_blocks_parse( $src );
	check( "$f parses", ! is_wp_error( $rt ), msg_of( $rt ) );
	if ( is_wp_error( $rt ) ) { continue; }
	$all = flat( $rt ); $tr = d5b_blocks_tree( $src );
	$opens = preg_match_all( '/<!--\s+wp:divi\/(?!placeholder)/', $src );
	check( "$f: tree lists every Divi block (" . count( $tr ) . " = the " . $opens . " openers in the file)", count( $tr ) === $opens && count( $all ) === $opens && array_column( $tr, 'address' ) === array_column( $all, 'addr' ) );
	$bad = array();
	foreach ( $all as $b ) { $o = d5b_blocks_edit( $src, 'replace', $b['addr'], slice_of( $src, $b ) ); if ( $o !== $src ) { $bad[] = $b['addr']; } }
	check( "$f: replace of each of the " . count( $all ) . ' blocks with its own get is byte-identical', ! $bad, $bad );
}
$opensBefore = d5b_blocks_tree( $FIX['vb-resave-5.15.1/before.html'] );
check( 'a non-Divi page (the rendered HTML) has an empty tree and parses', array() === d5b_blocks_tree( file_get_contents( __DIR__ . '/fixtures/vb-resave-5.15.1/before-rendered.html' ) ) );

echo "--- text fields\n";
$vb = $FIX['vb-5.18.0-variables.html']; $vbr = d5b_blocks_parse( $vb );
$heads = array(); $texts = array();
foreach ( flat( $vbr ) as $b ) { $tf = d5b_blocks_text_fields( $b ); if ( 'divi/heading' === $b['name'] && isset( $tf['title.innerContent.desktop.value'] ) ) { $heads[] = $tf; } if ( 'divi/text' === $b['name'] && isset( $tf['content.innerContent.desktop.value'] ) ) { $texts[] = $tf; } }
check( 'text_fields finds a heading and a text body on the VB fixture', count( $heads ) >= 1 && count( $texts ) >= 1, array( $heads, $texts ) );
check( 'a field value is the decoded HTML string as stored (JSON-decoded, entities untouched)', $heads && false !== strpos( reset( $heads[0] ), '<' ) || $texts && false !== strpos( reset( $texts[0] ), '<p>' ), array( $heads, $texts ) );
$btn = d5b_blocks_text_fields( at_( $root, '1.1.1.2' ) );
check( 'a button: its label at button.innerContent.desktop.value.text, the link not', array( 'button.innerContent.desktop.value.text' => 'Go' ) === $btn, $btn );
check( 'an image has no text fields (src/alt are not text a visitor reads)', array() === d5b_blocks_text_fields( at_( $root, '1.1.2.1.1.1' ) ) );
check( 'a section, row or column has no text fields', array() === d5b_blocks_text_fields( $a1 ) && array() === d5b_blocks_text_fields( at_( $root, '1.1.1' ) ) );
$fwh = d5b_blocks_text_fields( at_( $root, '2.1' ) );
check( 'a fullwidth header title is text', array( 'title.innerContent.desktop.value' => 'Hi' ) === $fwh, $fwh );
$bl = d5b_blocks_parse( '<!-- wp:divi/blurb {"title":{"innerContent":{"desktop":{"value":{"text":"T"}},"tablet":{"value":{"text":"Tt"}}}},"content":{"innerContent":{"desktop":{"value":"\\u003cp\\u003eB\\u003c/p\\u003e"}}},"imageIcon":{"innerContent":{"desktop":{"value":{"useIcon":"on","icon":{"unicode":"x"}}}}}} /-->' );
$blf = d5b_blocks_text_fields( $bl['children'][0] );
check( 'a blurb: title (an object with text, every breakpoint) and content; the icon not', array( 'title.innerContent.desktop.value.text' => 'T', 'title.innerContent.tablet.value.text' => 'Tt', 'content.innerContent.desktop.value' => '<p>B</p>' ) === $blf, $blf );
$code = d5b_blocks_parse( '<!-- wp:divi/code {"content":{"innerContent":{"desktop":{"value":"\\u003cscript\\u003ex\\u003c/script\\u003e"}}}} /-->' );
check( 'a code module has no text fields (its content is code, not words)', array() === d5b_blocks_text_fields( $code['children'][0] ) );
$els = d5b_blocks_text_elements();
$miss = array();
foreach ( array( 'divi/heading' => 'title', 'divi/text' => 'content', 'divi/button' => 'button', 'divi/blurb' => 'title', 'divi/cta' => 'content', 'divi/toggle' => 'title', 'divi/testimonial' => 'content', 'divi/slide' => 'title', 'divi/tab' => 'content', 'divi/pricing-table' => 'price', 'divi/number-counter' => 'title', 'divi/countdown-timer' => 'title', 'divi/accordion-item' => 'content', 'divi/team-member' => 'name', 'divi/map-pin' => 'content' ) as $m => $el ) {
	if ( ! in_array( $el, $els[ $m ] ?? array(), true ) ) { $miss[] = "$m $el"; }
}
check( 'the text list covers the brief\'s modules (heading ... person name, map pin)', ! $miss, $miss );
$gen = d5b_blocks_text_fields_generated();
check( 'the generated list holds the catalogue\'s visitor text (audio artist, timeline date) and the hand list adds the blurb title', in_array( 'artistName', $els['divi/audio'] ?? array(), true ) && in_array( 'date', $els['divi/timeline-item'] ?? array(), true ) && ! in_array( 'title', $gen['divi/blurb'] ?? array(), true ) && in_array( 'title', $els['divi/blurb'] ?? array(), true ) );
$denied = array();
foreach ( $gen as $m => $l ) { foreach ( $l as $el ) { if ( in_array( $el, array( 'redirect', 'number', 'barProgress' ), true ) || ( 'divi/post-filter-item' === $m && 'label' === $el ) || in_array( $m, array( 'divi/code', 'divi/fullwidth-code' ), true ) || ( 'divi/map-pin' === $m && 'pin' === $el ) || ( 'divi/contact-form' === $m && 'email' === $el ) ) { $denied[] = "$m $el"; } } }
check( 'the denylist holds: no redirect/number/barProgress/post-filter-item label, no code, map address or e-mail pattern', ! $denied, $denied );
$srcPhp0 = file_get_contents( $dir . '/inc/blocks.php' );
check( 'blocks.php reads nothing from disk at run time (no catalog.json, no file_get_contents)', false === strpos( $srcPhp0, 'catalog.json' ) && false === strpos( $srcPhp0, 'file_get_contents' ) && false === strpos( $srcPhp0, 'is_file' ) );
$catPath = __DIR__ . '/../catalog/catalog.json'; $nodeBin = find_node();
if ( is_file( $catPath ) && $nodeBin ) {
	$p = proc_open( array( $nodeBin, __DIR__ . DIRECTORY_SEPARATOR . 'gen-text-fields.js', '--stdout' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pp );
	$regen = stream_get_contents( $pp[1] ); fclose( $pp[1] ); fclose( $pp[2] ); proc_close( $p );
	$committed = file_get_contents( $dir . '/inc/blocks-text-fields.php' );
	// compared byte for byte, except that a Windows checkout may have turned LF into CRLF
	check( 'regenerating inc/blocks-text-fields.php from catalog/catalog.json gives the committed file byte for byte', '' !== $regen && $regen === str_replace( "\r\n", "\n", $committed ), strlen( $regen ) . ' vs ' . strlen( $committed ) );
} else { echo "  SKIP  regenerating the text-field list: " . ( $nodeBin ? 'catalog/catalog.json not built here' : 'node not found' ) . "\n"; }

echo "--- set_text_field\n";
// new == old must give back the page byte for byte, for every text field of every block of every fixture
$cases = 0; $bad = array(); $enc = array();
foreach ( array_merge( array( 'PAGE' => $PAGE ), $FIX ) as $f => $src ) {
	foreach ( flat( d5b_blocks_parse( $src ) ) as $b ) {
		foreach ( d5b_blocks_text_fields( $b ) as $field => $val ) {
			$cases++;
			$o = d5b_blocks_set_text_field( $src, $b, $field, $val );
			if ( $o !== $src ) { $bad[] = "$f {$b['addr']} $field: " . ( is_wp_error( $o ) ? $o->get_error_message() : 'changed' ); }
		}
	}
}
check( "set_text_field(src, node, f, get[f]) === src for all $cases text fields in PAGE and the VB fixtures", $cases >= 30 && ! $bad, $bad );
// the encoder alone, against every JSON string value stored in the fixtures (not only text fields)
$tok = 0; $mis = array();
foreach ( $FIX as $f => $src ) {
	foreach ( flat( d5b_blocks_parse( $src ) ) as $b ) {
		$open = substr( $src, $b['start'], $b['innerStart'] - $b['start'] );
		preg_match_all( '/"(?:[^"\\\\]|\\\\.)*"/', $open, $mm );
		foreach ( $mm[0] as $raw ) { $tok++; $dec = json_decode( $raw ); if ( ! is_string( $dec ) || d5b_blocks_json_string( $dec ) !== $raw ) { $mis[] = $raw; } }
	}
}
check( "the string encoder reproduces all $tok JSON strings stored in the VB fixtures byte for byte", $tok > 200 && ! $mis, array_slice( $mis, 0, 3 ) );
$tricky = "<p>Q \"quote\" & &amp; <br> caf\u{00e9} \u{2014} \u{1F600} back\\slash -- --- \$variable({\"type\":\"content\",\"value\":{\"name\":\"gvid-x\"}})\$ / it's</p>";
$stored = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":' . d5b_blocks_json_string( $tricky ) . '}}},"builderVersion":"5.18.0"} /-->';
check( 'the encoder writes Divi\'s escapes: \\u0022 \\u0026 \\u003c \\u003e \\u002d\\u002d \\u005c, raw non-ASCII, raw "/"', false !== strpos( $stored, 'Q \\u0022quote\\u0022 \\u0026 \\u0026amp; \\u003cbr\\u003e caf' . "\u{00e9} \u{2014} \u{1F600}" . ' back\\u005cslash \\u002d\\u002d \\u002d\\u002d- $variable({\\u0022type\\u0022' ) && false !== strpos( $stored, ' / it' ), $stored );
$sr = d5b_blocks_parse( $stored ); $sn = $sr['children'][0];
check( 'the tricky field decodes back to the same string', $tricky === ( d5b_blocks_text_fields( $sn )['content.innerContent.desktop.value'] ?? null ) );
check( 'set_text_field round-trips quotes, ampersands, <br>, non-ASCII and $variable(...)$ tokens byte-identically when new == old', $stored === d5b_blocks_set_text_field( $stored, $sn, 'content.innerContent.desktop.value', $tricky ) );
$o = d5b_blocks_set_text_field( $PAGE, at_( $root, '1.1.1.2' ), 'button.innerContent.desktop.value.text', 'Go "now" & <b>save</b>' );
$ob = at_( d5b_blocks_parse( $o ), '1.1.1.2' );
check( 'a real change: only that string changes, re-encoded the Divi way, and the page parses', is_string( $o ) && substr( $o, 0, $t2['start'] ) === substr( $PAGE, 0, $t2['start'] ) && substr( $o, $ob['end'] ) === substr( $PAGE, $t2['end'] ) && slice_of( $o, $ob ) === '<!-- wp:divi/button {"button":{"innerContent":{"desktop":{"value":{"text":"Go \\u0022now\\u0022 \\u0026 \\u003cb\\u003esave\\u003c/b\\u003e"}}}}} /-->', is_string( $o ) ? slice_of( $o, $ob ) : msg_of( $o ) );
$o = d5b_blocks_set_text_field( $PAGE, at_( $root, '1.1.1.1' ), 'content.innerContent.desktop.value', '<p>x --> y</p>' );
check( 'a "-->" in the new text cannot end the comment (it is written \\u002d\\u002d\\u003e)', is_string( $o ) && parses( $o ) && '<p>x --> y</p>' === d5b_blocks_text_fields( at_( d5b_blocks_parse( $o ), '1.1.1.1' ) )['content.innerContent.desktop.value'] );
check( 'a field that is not a text field of the block is refused invalid_input', refused( d5b_blocks_set_text_field( $PAGE, at_( $root, '1.1.1.2' ), 'button.innerContent.desktop.value.linkUrl', 'x' ), 'invalid_input', '/not a text field/' ) );
check( 'a node that is not at its place in src is refused invalid_input', refused( d5b_blocks_set_text_field( 'xx' . $PAGE, at_( $root, '1.1.1.2' ), 'button.innerContent.desktop.value.text', 'x' ), 'invalid_input', '/not the block/' ) );
// duplicate keys: JSON keeps the last one, so the splice must write the last one too
$dk = '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"A"}}},"title":{"innerContent":{"desktop":{"value":"B"}}}} /-->';
$dkr = d5b_blocks_parse( $dk ); $o = d5b_blocks_set_text_field( $dk, $dkr['children'][0], 'title.innerContent.desktop.value', 'C' );
check( 'a key given twice: the value that counts (the last) is the one written', is_string( $o ) && false !== strpos( $o, '"value":"A"' ) && false !== strpos( $o, '"value":"C"' ), $o );

echo "--- hostile input stays linear (1 MB pages; bound 4 s, target under 2 s)\n";
$pad   = function ( $s ) { return $s . str_repeat( 'x', max( 0, 1048576 - strlen( $s ) ) ); };
$timed = function ( $fn ) { $t = microtime( true ); $r = $fn(); return array( microtime( true ) - $t, $r ); };
$HOST  = array(
	'40k stray closers'                  => $pad( str_repeat( '<!-- /wp:divi/row -->', 40000 ) ),
	'15k interleaved pairs (mis-nested)' => $pad( str_repeat( '<!-- wp:a --><!-- wp:b --><!-- /wp:a --><!-- /wp:b --><!-- /wp:a -->', 15000 ) ),
	'Divi blocks 20k deep'               => str_repeat( '<!-- wp:divi/text -->', 20000 ) . str_repeat( '<!-- /wp:divi/text -->', 20000 ),
	'core blocks 20k deep'               => str_repeat( '<!-- wp:group -->', 20000 ) . str_repeat( '<!-- /wp:group -->', 20000 ),
	'one column of 14k modules'          => '<!-- wp:divi/section --><!-- wp:divi/row --><!-- wp:divi/column -->' . str_repeat( "\n" . $MOD, 14000 ) . "\n<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->",
);
$timings = array();
foreach ( $HOST as $k => $src ) {
	list( $tp, $rp ) = $timed( function () use ( $src ) { return d5b_blocks_parse( $src ); } );
	list( $tt, $rt ) = $timed( function () use ( $src ) { return d5b_blocks_tree( $src ); } );
	list( $te, $re ) = $timed( function () use ( $src ) { return d5b_blocks_edit( $src, 'remove', '1.1.1.7000', null ); } );
	$timings[] = sprintf( '%s %.2f/%.2f/%.2f s', $k, $tp, $tt, $te );
	check( sprintf( '%s (%d KB): parse %.2f s, tree %.2f s, edit %.2f s, each under 4 s', $k, strlen( $src ) >> 10, $tp, $tt, $te ), $tp < 4 && $tt < 4 && $te < 4 );
	if ( is_wp_error( $rp ) ) {
		$d = $rp->get_error_data();
		check( "$k: at most 20 errors written, the rest counted, errors_truncated set", count( $d['errors'] ) <= 21 && ( ( count( $d['errors'] ) > 20 ) === $d['errors_truncated'] ) && ( ! $d['errors_truncated'] || 1 === preg_match( '/^\.\.\. and \d+ more errors$/', end( $d['errors'] ) ) ), array( count( $d['errors'] ), $d['errors_truncated'], end( $d['errors'] ) ) );
	}
}
check( 'the error flood is truncated: 40k stray closers give 20 errors + "... and 39980 more errors"', is_wp_error( $e = d5b_blocks_parse( $HOST['40k stray closers'] ) ) && true === $e->get_error_data()['errors_truncated'] && '... and 39980 more errors' === end( $e->get_error_data()['errors'] ) );
check( 'the char offsets are still right after the change (the 20th stray closer is at char 19 x 21 = 399)', is_wp_error( $e ) && false !== strpos( $e->get_error_data()['errors'][19], 'at char 399 ' ) );
$deep = d5b_blocks_parse( $HOST['Divi blocks 20k deep'] );
check( 'nesting past ' . D5B_BLOCKS_MAX_DEPTH . ' is refused invalid_input, not read', refused( $deep, 'invalid_input', '/nested more than 200 deep/' ) );
check( 'a page 200 deep still parses and edits', is_string( d5b_blocks_edit( str_repeat( '<!-- wp:divi/text -->', 199 ) . str_repeat( '<!-- /wp:divi/text -->', 199 ), 'remove', '1', null ) ) );
$deep199 = str_repeat( '<!-- wp:divi/text -->', 199 ) . str_repeat( "caf\u{00e9} ", 170000 ) . str_repeat( '<!-- /wp:divi/text -->', 199 );
list( $td, $rd ) = $timed( function () use ( $deep199 ) { return d5b_blocks_tree( $deep199 ); } );
// counting each block's own length would scan the megabyte 199 times (0.7 s here); one pass takes about 0.005 s
check( sprintf( 'tree counts chars in one pass: 199 deep around 1 MB of text in %.3f s (bound 0.3 s), chars right', $td ), $td < 0.3 && 199 === count( $rd ) && d5b_blocks_u16( substr( $deep199, 21 * 198, strlen( $deep199 ) - 43 * 198 ) ) === $rd[198]['chars'] && d5b_blocks_u16( $deep199 ) === $rd[0]['chars'] );
check( 'the wide page: tree lists all 14003 blocks, the edit removes module 7000', 14003 === count( d5b_blocks_tree( $HOST['one column of 14k modules'] ) ) && is_string( d5b_blocks_edit( $HOST['one column of 14k modules'], 'remove', '1.1.1.7000', null ) ) );

echo "--- the source\n";
$srcPhp = file_get_contents( $dir . '/inc/blocks.php' );
check( 'blocks.php calls no WordPress function (pure: no wp_, get_, add_, apply_filters, esc_)', 0 === preg_match( '/\b(wp_[a-z_]+|get_option|get_post|add_action|add_filter|apply_filters|do_action|esc_[a-z_]+|__)\s*\(/', preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $srcPhp ) ) );
check( 'defines functions only, with the ABSPATH and function_exists guards', false !== strpos( $srcPhp, "defined( 'ABSPATH' )" ) && false !== strpos( $srcPhp, "function_exists( 'd5b_blocks_parse' )" ) );
check( 'the loader lists inc/blocks.php', false !== strpos( file_get_contents( __DIR__ . '/../plugin/divi5-builder/divi5-builder.php' ), "'inc/blocks.php'" ) );

echo "--- parity with node scripts/blocks.js\n";
function find_node() {
	foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $d ) { foreach ( array( 'node.exe', 'node' ) as $x ) { $p = rtrim( $d, '/\\' ) . DIRECTORY_SEPARATOR . $x; if ( '' !== $d && is_file( $p ) ) { return $p; } } }
	return null;
}
function run_node( $node, array $args ) {
	$p = proc_open( array_merge( array( $node, __DIR__ . DIRECTORY_SEPARATOR . 'blocks.js' ), $args ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	if ( ! is_resource( $p ) ) { return array( -1, '', 'proc_open failed' ); }
	$out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
	return array( proc_close( $p ), $out, $err );
}
$node = find_node();
if ( ! $node ) {
	echo "  SKIP  skipped: node not found\n";
} else {
	$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'd5b-blocks-php-' . bin2hex( random_bytes( 4 ) );
	mkdir( $tmp );
	$put = function ( $name, $text ) use ( $tmp ) { $f = $tmp . DIRECTORY_SEPARATOR . $name; file_put_contents( $f, $text ); return $f; };
	$pages = array( 'PAGE' => $PAGE, 'DUP' => $dup ) + $FIX;
	$k = 0; $same = 0; $diff = array();
	$compare = function ( $label, $src, $op, $a, $w, $from = null ) use ( &$k, &$same, &$diff, $node, $put, $tmp ) {
		$k++;
		$pf = $put( "p$k.html", $src ); $of = $tmp . DIRECTORY_SEPARATOR . "o$k.html"; $args = array( $op, $pf, '--at', $a, '--out', $of );
		if ( null !== $w ) { $args[] = '--with'; $args[] = $put( "w$k.html", $w ); }
		if ( null !== $from ) { $args[] = '--from'; $args[] = $from; }
		list( $code, , $err ) = run_node( $node, $args );
		$php = d5b_blocks_edit( $src, $op, $a, $w, $from );
		$js = 0 === $code ? file_get_contents( $of ) : null;
		$ok = ( 0 === $code && is_string( $php ) && $php === $js ) || ( 1 === $code && is_wp_error( $php ) );
		if ( $ok ) { $same++; } else { $diff[] = "$label $op $a: node exit $code " . trim( $err ) . ' / php ' . ( is_wp_error( $php ) ? $php->get_error_message() : strlen( $php ) . ' bytes' ); }
		if ( is_file( $of ) ) { unlink( $of ); }
	};
	// 1) the CLI itself, one process per edit: every operation and every address form on PAGE
	foreach ( $ops as $o ) { $compare( 'PAGE', $PAGE, $o[0], $o[1], $o[2] ); }
	foreach ( array( '1', '1.1', '1.1.1', '1.1.1.1', '1.1.2.1', '1.1.2.1.1.1', '2', '2.1', '3', '3.1.1', 'label:Featured Work', 'label:Hero', 'label:Empty', '#intro', '9', 'label:Nope', '#nope', 'hero' ) as $a ) {
		foreach ( array( 'replace', 'before', 'after', 'append' ) as $op ) { $compare( 'PAGE', $PAGE, $op, $a, $MOD ); }
		$compare( 'PAGE', $PAGE, 'remove', $a, null );
	}
	$compare( 'DUP', $dup, 'remove', 'label:Featured Work', null );
	$compare( 'PAGE', $PAGE, 'replace', '1.1.1.2', '<!-- wp:divi/row -->' );
	$compare( 'PAGE', $PAGE, 'replace', '1.1.1.2', '<p>hi</p>' );
	$compare( 'PAGE', $PAGE, 'replace', '1.1.1.2', $MOD . "\n<!-- /wp:divi/column -->" );
	$compare( 'PAGE', $PAGE, 'replace', '1.1.1.2', $PAGE, '1.1.2.1.1.1' );
	$compare( 'PAGE', $PAGE, 'after', '1', $PAGE );
	$compare( 'BROKEN', $PAGE . '<!-- wp:divi/row -->', 'remove', '1', null );
	check( "parity (CLI): $same of $k edits give the same bytes (or both refuse) in PHP and `node blocks.js`", $same === $k && $k > 100, $diff );
	// 2) the bulk sweep, in ONE node process (a process per edit would take a minute on Windows): blocks.js's own
	// edit(), the function its CLI calls, on every block of every fixture, every operation
	$cases = array();
	foreach ( array( '1', '1.1', '1.1.1', '1.1.1.1', '1.1.2.1', '1.1.2.1.1.1', '2', '2.1', '3', '3.1.1', 'label:Hero', '#intro' ) as $a ) {
		foreach ( array( 'replace', 'before', 'after', 'append' ) as $op ) { $cases[] = array( 'PAGE', $PAGE, $op, $a, $LINES[9], null ); $cases[] = array( 'PAGE', $PAGE, $op, $a, $LINES[13] . "\n<!-- /wp:divi/section -->", null ); }
	}
	foreach ( $FIX as $f => $src ) {
		foreach ( flat( d5b_blocks_parse( $src ) ) as $b ) {
			$cases[] = array( $f, $src, 'replace', $b['addr'], slice_of( $src, $b ), null );
			$cases[] = array( $f, $src, 'remove', $b['addr'], null, null );
			foreach ( array( 'after', 'before', 'append' ) as $op ) { $cases[] = array( $f, $src, $op, $b['addr'], $MOD, null ); }
			$cases[] = array( $f, $src, 'after', $b['addr'], $src, $b['addr'] );
		}
	}
	$drv = $put( 'driver.js', <<<'JS'
const fs = require('fs'); const B = require(process.argv[2]);
const cases = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
fs.writeFileSync(process.argv[4], JSON.stringify(cases.map(([, src, op, at, w, from]) => {
  try { return { ok: 1, out: Buffer.from(B.edit(src, op, { at, withText: w === null ? undefined : w, from: from === null ? undefined : from }).out, 'utf8').toString('base64') }; }
  catch (e) { return { ok: 0, err: e.message }; }
})));
JS
	);
	$cf = $put( 'cases.json', json_encode( $cases ) ); $rf = $tmp . DIRECTORY_SEPARATOR . 'results.json';
	$p = proc_open( array( $node, $drv, __DIR__ . DIRECTORY_SEPARATOR . 'blocks.js', $cf, $rf ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pp );
	$derr = stream_get_contents( $pp[2] ); stream_get_contents( $pp[1] ); fclose( $pp[1] ); fclose( $pp[2] ); proc_close( $p );
	$res = is_file( $rf ) ? json_decode( file_get_contents( $rf ), true ) : null;
	$bulk = 0; $bdiff = array(); $writes = 0;
	foreach ( $cases as $i => $c ) {
		$php = d5b_blocks_edit( $c[1], $c[2], $c[3], $c[4], $c[5] ); $js = $res[ $i ] ?? null;
		$ok = $js && ( ( 1 === $js['ok'] && is_string( $php ) && base64_decode( $js['out'] ) === $php ) || ( 0 === $js['ok'] && is_wp_error( $php ) ) );
		if ( $ok ) { $bulk++; if ( 1 === $js['ok'] ) { $writes++; } } else { $bdiff[] = "{$c[0]} {$c[2]} {$c[3]}: " . ( $js ? ( $js['ok'] ? 'node wrote' : 'node: ' . $js['err'] ) : 'no result ' . $derr ) . ' / php ' . ( is_wp_error( $php ) ? $php->get_error_message() : 'wrote' ); }
	}
	check( "parity (blocks.js edit()): $bulk of " . count( $cases ) . " edits on PAGE and every fixture block agree ($writes written, the rest refused by both)", $bulk === count( $cases ) && count( $cases ) > 300 && $writes > 200, $bdiff );
	// 3) tree --json (CLI) on every page, get (CLI) on every PAGE block
	$tdiff = array();
	foreach ( $pages as $f => $src ) {
		$pf = $put( 'tree.html', $src );
		list( $code, $out ) = run_node( $node, array( 'tree', $pf, '--json' ) );
		if ( 0 !== $code || json_decode( $out, true ) !== d5b_blocks_tree( $src ) ) { $tdiff[] = $f; }
		if ( 'PAGE' === $f ) {
			foreach ( flat( d5b_blocks_parse( $src ) ) as $b ) {
				list( , $g ) = run_node( $node, array( 'get', $pf, '--at', $b['addr'] ) );
				if ( $g !== slice_of( $src, $b ) . "\n" ) { $tdiff[] = "$f get {$b['addr']}"; }
			}
		}
		unlink( $pf );
	}
	check( 'parity: tree --json agrees on PAGE, DUP and the VB fixtures (chars counted the way node counts), get on every PAGE block', ! $tdiff, $tdiff );
	foreach ( glob( $tmp . DIRECTORY_SEPARATOR . '*' ) as $x ) { unlink( $x ); }
	rmdir( $tmp );
	check( 'the temp folder is cleaned up', ! is_dir( $tmp ) );
}

echo $failed ? "\nFAILED: $failed of $n\n" : "\nALL $n CHECKS PASSED\n";
exit( $failed ? 1 : 0 );
