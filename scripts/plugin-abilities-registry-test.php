<?php
/**
 * The annotations of every ability, over the whole registry (#32, release 2.2.0, final wave B1).
 *
 *   php scripts/plugin-abilities-registry-test.php
 *
 * Loads every inc/abilities file with WordPress stand-ins (plugin-test-stubs.php) and checks the rule the annotations follow:
 *  - destructive is true ONLY for a trash, a permanent delete and menus-item-remove (an archive, a spam mark, a block
 *    removed from a page are undone by the plugin's own abilities or kept in the change history: not destructive);
 *  - a read (readonly true) is never destructive;
 *  - no ability is destructive AND idempotent: core's run route picks the HTTP method from the annotations (readonly ->
 *    GET, destructive + idempotent -> DELETE, else POST), and a DELETE with a JSON body is not what the clients send.
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.2.0' );
require __DIR__ . '/plugin-test-stubs.php';
foreach ( array( 'parts', 'switches', 'blocks-text-fields', 'blocks', 'abilities/categories', 'abilities/register', 'abilities/secrets', 'abilities/audit', 'abilities/scrub', 'abilities/history', 'abilities/confirm',
	'abilities/read-content', 'abilities/read-structure', 'abilities/read-site', 'abilities/read-divi', 'abilities/read-history',
	'abilities/write-common', 'abilities/write-posts', 'abilities/write-comments', 'abilities/write-media', 'abilities/write-terms', 'abilities/write-menus', 'abilities/write-divi' ) as $f ) {
	require_once $dir . '/inc/' . $f . '.php';
}

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

/** The annotations as d5b_abilities_register gives them to core (its defaults merged with the definition's). */
function ann( array $def ): array {
	$a = array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	return isset( $def['annotations'] ) && is_array( $def['annotations'] ) ? array_merge( $a, $def['annotations'] ) : $a;
}
/** The HTTP method core's run route expects for these annotations. */
function method( array $a ): string {
	if ( true === $a['readonly'] ) { return 'GET'; }
	return ( true === $a['destructive'] && true === $a['idempotent'] ) ? 'DELETE' : 'POST';
}

$defs = d5b_ability_defs();
$destructive_ok = array( 'posts-trash', 'pages-trash', 'cpt-trash', 'blocks-trash', 'comments-trash', 'media-trash', 'media-delete', 'taxonomy-delete', 'meta-delete', 'menus-delete', 'menus-item-remove' );
$writes = 0; $bad = array(); $deletes = array(); $ro_bad = array(); $seen = array();
foreach ( $defs as $name => $d ) {
	$short = substr( $name, strlen( 'divi5-builder/' ) );
	$a     = ann( $d );
	if ( 'DELETE' === method( $a ) ) { $deletes[] = $short; }
	if ( true === $a['readonly'] ) {
		if ( true === $a['destructive'] ) { $ro_bad[] = $short; }
		continue;
	}
	$writes++;
	$want = in_array( $short, $destructive_ok, true );
	if ( $want ) { $seen[] = $short; }
	if ( $want !== $a['destructive'] ) { $bad[] = $short . ' destructive ' . json_encode( $a['destructive'] ); }
}
sort( $seen ); $exp = $destructive_ok; sort( $exp );
check( 'the registry holds the 2.2.0 writes (not vacuous)', $writes >= 50, $writes );
check( 'every write in the list of trashes, permanent deletes and menus-item-remove exists', $exp === $seen, array_values( array_diff( $exp, $seen ) ) );
check( 'destructive is true for exactly those, and false for every other write (archive, spam, section-edit included)', array() === $bad, $bad );
check( 'no read is destructive', array() === $ro_bad, $ro_bad );
check( 'no ability is destructive and idempotent (core would expect DELETE on its run route)', array() === $deletes, $deletes );
foreach ( array( 'comments-spam', 'comments-unspam', 'divi-section-edit', 'divi-ds-variable-archive' ) as $s ) {
	$a = ann( $defs[ 'divi5-builder/' . $s ] );
	check( "$s: destructive false, idempotent false, run with POST", false === $a['destructive'] && false === $a['idempotent'] && 'POST' === method( $a ), $a );
}

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
