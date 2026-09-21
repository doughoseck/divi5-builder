<?php
/**
 * Local test of the Theme Builder write guard's "same block tree" comparison (mu-plugin >= 1.5.1).
 *
 *   php scripts/tree-guard-test.php <path-to-a-wordpress>/wp-includes <sent.html> <stored.html>
 *
 * <sent.html>   = content as it was sent (any escape form, wrapper closed or not)
 * <stored.html> = the same content as the site stored it
 * The pair must compare EQUAL, and every deliberate damage to it must compare DIFFERENT.
 * The comparison code is lifted out of the plugin file itself, so this tests what ships. Exits non-zero on failure.
 */
if ( $argc < 4 ) { fwrite( STDERR, "usage: php tree-guard-test.php <wp-includes dir> <sent.html> <stored.html>\n" ); exit( 2 ); }
$inc = rtrim( $argv[1], '/\\' );
foreach ( array( 'class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php', 'class-wp-block-parser.php' ) as $f ) {
	if ( file_exists( "$inc/$f" ) ) { require_once "$inc/$f"; }
}
if ( ! class_exists( 'WP_Block_Parser' ) ) { fwrite( STDERR, "STOP: no WP_Block_Parser under $inc\n" ); exit( 2 ); }
function parse_blocks( $content ) { $p = new WP_Block_Parser(); return $p->parse( $content ); }

$src = file_get_contents( __DIR__ . '/../assets/divi5-builder-rest.php' );
if ( ! preg_match( '/\$tb_tree = (function \( \$content \) \{.*?\n\t\};)/s', $src, $m ) ) { fwrite( STDERR, "STOP: \$tb_tree not found in the plugin\n" ); exit( 2 ); }
$tb_tree = eval( 'return ' . $m[1] );

$sent   = str_replace( "\r\n", "\n", trim( file_get_contents( $argv[2] ) ) );
$stored = str_replace( "\r\n", "\n", trim( file_get_contents( $argv[3] ) ) );
$failed = 0;
$check  = function ( $name, $ok ) use ( &$failed ) { echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . "\n"; if ( ! $ok ) { $failed++; } };
$same   = function ( $a, $b ) use ( $tb_tree ) { return $tb_tree( $a ) === $tb_tree( $b ); };
$swap   = function ( $s, $from, $to ) { $n = 0; $r = preg_replace( $from, $to, $s, 1, $n ); if ( 1 !== $n ) { fwrite( STDERR, "STOP: mutation $from did not apply\n" ); exit( 2 ); } return $r; };

$check( 'the two inputs really do differ in bytes', $sent !== $stored );
$check( 'sent and stored have the same tree', $same( $sent, $stored ) );
$check( 'tree is not trivially empty', strlen( $tb_tree( $stored ) ) > 200 );

echo "--- every one of these is damage and must be caught ---\n";
$check( 'one character changed inside an attribute', ! $same( $sent, $swap( $stored, '/quote/', 'quoTe' ) ) );
$check( 'backslashes stripped (what an unslashed save does)', ! $same( $sent, stripslashes( $stored ) ) );
$check( 'a block removed', ! $same( $sent, $swap( $stored, '/<!-- wp:divi\/text .*?<!-- \/wp:divi\/text -->/s', '' ) ) );
preg_match( '/<!-- wp:divi\/text .*?<!-- \/wp:divi\/text -->/s', $stored, $blk );
$moved = str_replace( '<!-- /wp:divi/section -->', '<!-- /wp:divi/section -->' . $blk[0], str_replace( $blk[0], '', $stored ) );
$check( 'same block, byte for byte, moved out of its column', $moved !== $stored && ! $same( $sent, $moved ) );
$check( 'an attribute added', ! $same( $sent, $swap( $stored, '/\{"builderVersion"/', '{"extra":1,"builderVersion"' ) ) );
$check( 'stray text between blocks', ! $same( $sent, $swap( $stored, '/<!-- \/wp:divi\/row -->/', 'oops<!-- /wp:divi/row -->' ) ) );
$check( 'content cut off half way', ! $same( $sent, substr( $stored, 0, (int) ( strlen( $stored ) / 2 ) ) ) );
$check( 'a block comment broken so it becomes plain text', ! $same( $sent, $swap( $stored, '/<!-- wp:divi\/column /', '<!- wp:divi/column ' ) ) );

echo $failed ? "\n$failed check(s) FAILED\n" : "\nall checks passed\n";
exit( $failed ? 1 : 0 );
