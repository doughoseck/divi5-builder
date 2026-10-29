<?php
/**
 * Confirmation codes for permanent deletes (#32, 2.2.0, Task 2).
 *
 *   php scripts/plugin-abilities-confirm-test.php
 *
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
define( 'D5B_VERSION', '2.2.0' );
require __DIR__ . '/plugin-test-stubs.php';
require $dir . '/inc/abilities/confirm.php';

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }
function fresh() { t_reset(); $GLOBALS['T_user_id'] = 1; $GLOBALS['T_transient_fail'] = false; $GLOBALS['T_on_get'] = array(); }
function reason( $r ) { return is_wp_error( $r ) && 'confirmation_invalid' === $r->get_error_code() ? ( $r->get_error_data()['reason'] ?? '?' ) : ( true === $r ? 'OK' : 'other' ); }
const AB = 'divi5-builder/taxonomy-delete';
function expire( $name ) { $GLOBALS['T_transients'][ $name ][1] = time() - 1; }

echo "issue\n";
fresh();
$r = d5b_confirm_issue( AB, 'term', 12, 'fp1' );
check( 'returns exactly confirmation, expires_at, message', array( 'confirmation', 'expires_at', 'message' ) === array_keys( $r ) );
check( 'the code is 12 characters from A-HJ-NP-Z2-9', 1 === preg_match( '/^[A-HJ-NP-Z2-9]{12}$/', $r['confirmation'] ), $r['confirmation'] );
$t = strtotime( $r['expires_at'] );
check( 'expires_at is ISO 8601 UTC, 10 minutes out', 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $r['expires_at'] ) && abs( $t - ( time() + 600 ) ) <= 2, $r['expires_at'] );
check( 'the message is the fixed sentence', 'Show this preview to the user and wait for their explicit OK before calling the delete.' === $r['message'] );
$codes = array(); for ( $i = 0; $i < 30; $i++ ) { $codes[] = d5b_confirm_issue( AB, 'term', 100 + $i, 'f' )['confirmation']; }
check( '30 codes are all different and all in the alphabet', 30 === count( array_unique( $codes ) ) && 30 === count( preg_grep( '/^[A-HJ-NP-Z2-9]{12}$/', $codes ) ) );
$bad = 0; foreach ( $codes as $c ) { if ( false !== strpbrk( $c, 'IO01' ) ) { $bad++; } } check( 'no 0, 1, I or O ever appears', 0 === $bad );

echo "storage\n";
fresh();
$r = d5b_confirm_issue( AB, 'term', 12, 'fp1' );
$key = 'd5b_confirm_' . sha1( '1|' . AB . '|term|12' );
$GLOBALS['T_user_id'] = 2;
check( 'user 2 presenting user 1\'s code is refused (wrong_user), whatever the key format', 'wrong_user' === reason( d5b_confirm_check( AB, 'term', 12, 'fp1', $r['confirmation'] ) ) );
$GLOBALS['T_user_id'] = 1;
check( 'the transient is keyed d5b_confirm_ + sha1(user|ability|type|id), ttl 600', isset( $GLOBALS['T_transients'][ $key ] ) && 600 === $GLOBALS['T_transients'][ $key ][2] );
$v = $GLOBALS['T_transients'][ $key ][0];
check( 'it holds code_hash, fingerprint and issued only', array( 'code_hash', 'fingerprint', 'issued' ) === array_keys( $v ) && 'fp1' === $v['fingerprint'] );
check( 'the code is stored hashed, never in clear', $v['code_hash'] === wp_hash( $r['confirmation'] ) && false === strpos( serialize( $GLOBALS['T_transients'] ), $r['confirmation'] ) );
$src = file_get_contents( $dir . '/inc/abilities/confirm.php' );
check( 'the comparison uses hash_equals, never == or === on hashes; salted wp_hash; no sha1 of the code', false !== strpos( $src, 'hash_equals(' ) && false !== strpos( $src, 'wp_hash( $code )' ) && 0 === preg_match( '/sha1\(\s*\$code/', $src ) && 0 === preg_match( '/\)\s*[!=]==?\s*\$hash|\$hash\s*[!=]==?|\[.code_hash.\]\s*[!=]==?/', $src ) );
check( 'the file defines functions only and has the ABSPATH guard', false !== strpos( $src, "defined( 'ABSPATH' )" ) && 0 === preg_match( '/^(?!\s*(\/\/|\*|\/\*)).*\b(add_action|add_filter|register_[a-z_]+_hook)\s*\(/m', $src ) );
check( 'the loader lists confirm.php', false !== strpos( file_get_contents( __DIR__ . '/../plugin/divi5-builder/divi5-builder.php' ), "'inc/abilities/confirm.php'" ) );
$r2 = d5b_confirm_issue( AB, 'term', 12, 'fp2' );
check( 'issuing again for the same user and object replaces the code', 'missing' === reason( d5b_confirm_check( AB, 'term', 12, 'fp2', $r['confirmation'] ) ) && true === d5b_confirm_check( AB, 'term', 12, 'fp2', $r2['confirmation'] ) );

echo "check\n";
fresh();
$r = d5b_confirm_issue( AB, 'term', 12, 'fp1' ); $c = $r['confirmation'];
check( 'succeeds with the right user, object, fingerprint and code', true === d5b_confirm_check( AB, 'term', 12, 'fp1', $c ) );
check( 'the code is consumed: the transient is gone', ! isset( $GLOBALS['T_transients'][ $key ] ) );
check( 'a replay reports used', 'used' === reason( d5b_confirm_check( AB, 'term', 12, 'fp1', $c ) ) );
$r = d5b_confirm_issue( AB, 'term', 12, 'fp1' );
check( 'issuing anew after use works again (the used marker is cleared)', true === d5b_confirm_check( AB, 'term', 12, 'fp1', $r['confirmation'] ) );

fresh(); $c = d5b_confirm_issue( AB, 'term', 12, 'fp1' )['confirmation'];
expire( $key );
check( 'expired: the transient is gone, reports expired', 'expired' === reason( d5b_confirm_check( AB, 'term', 12, 'fp1', $c ) ) );
fresh();
check( 'a code never issued reports expired', 'expired' === reason( d5b_confirm_check( AB, 'term', 12, 'fp1', 'ABCDEFGHJKLM' ) ) );

fresh(); $c = d5b_confirm_issue( AB, 'term', 12, 'fp1' )['confirmation'];
$GLOBALS['T_user_id'] = 2;
check( 'another user presenting the issuer\'s code reports wrong_user', 'wrong_user' === reason( d5b_confirm_check( AB, 'term', 12, 'fp1', $c ) ) );
check( 'another user guessing a wrong code learns nothing (expired)', 'expired' === reason( d5b_confirm_check( AB, 'term', 12, 'fp1', 'ABCDEFGHJKLM' ) ) );
$GLOBALS['T_user_id'] = 1;
check( 'the wrong user did not consume the code', true === d5b_confirm_check( AB, 'term', 12, 'fp1', $c ) );

fresh(); $ca = d5b_confirm_issue( AB, 'term', 12, 'fp1' )['confirmation']; $cb = d5b_confirm_issue( AB, 'term', 13, 'fp1' )['confirmation'];
check( 'the code for object 12 presented for object 13 reports wrong_object', 'wrong_object' === reason( d5b_confirm_check( AB, 'term', 13, 'fp1', $ca ) ) );
check( 'a mistyped code for object 13 (a code is outstanding) reports missing', 'missing' === reason( d5b_confirm_check( AB, 'term', 13, 'fp1', 'ABCDEFGHJKLM' ) ) );
check( 'the code for 12 presented for object 99 (nothing issued) reports wrong_object', 'wrong_object' === reason( d5b_confirm_check( AB, 'term', 99, 'fp1', $ca ) ) );
check( 'a code for a different ability is not accepted for this one', in_array( reason( d5b_confirm_check( 'divi5-builder/menus-delete', 'term', 12, 'fp1', $ca ) ), array( 'expired', 'wrong_object' ), true ) );
check( 'neither mismatch consumed anything', true === d5b_confirm_check( AB, 'term', 12, 'fp1', $ca ) && true === d5b_confirm_check( AB, 'term', 13, 'fp1', $cb ) );

fresh(); $c = d5b_confirm_issue( AB, 'term', 12, 'fp1' )['confirmation'];
check( 'a changed fingerprint reports stale', 'stale' === reason( d5b_confirm_check( AB, 'term', 12, 'fp-changed', $c ) ) );
check( 'stale did not consume: with the old fingerprint it still works', true === d5b_confirm_check( AB, 'term', 12, 'fp1', $c ) );
check( 'no code reports missing (empty, whitespace)', 'missing' === reason( d5b_confirm_check( AB, 'term', 12, 'fp1', '' ) ) && 'missing' === reason( d5b_confirm_check( AB, 'term', 12, 'fp1', '  ' ) ) );
check( 'an empty code does not consume an outstanding one', true === d5b_confirm_check( AB, 'term', 12, 'fp1', d5b_confirm_issue( AB, 'term', 12, 'fp1' )['confirmation'] ) );
check( 'the error is confirmation_invalid with data.reason', ( function () { $e = d5b_confirm_check( AB, 'term', 12, 'fp1', '' ); return is_wp_error( $e ) && 'confirmation_invalid' === $e->get_error_code() && 'missing' === $e->get_error_data()['reason']; } )() );
check( 'an id given as a string or int is the same object', ( function () { $c = d5b_confirm_issue( AB, 'term', '31', 'f' )['confirmation']; return true === d5b_confirm_check( AB, 'term', 31, 'f', $c ); } )() );
check( 'a code issued as user 1 expires after 10 minutes (TTL 600 on all four transients)', ( function () { d5b_confirm_issue( AB, 'term', 40, 'f' ); foreach ( $GLOBALS['T_transients'] as $k => $t ) { if ( 0 === strpos( $k, 'd5b_confirm_' ) && 600 !== $t[2] ) { return false; } } return true; } )() );

echo "fingerprints\n";
fresh();
$term = (object) array( 'name' => 'News', 'slug' => 'news', 'parent' => 0, 'count' => 3 );
$base = d5b_fp_term( $term );
check( 'term: name|slug|parent|count', 'News|news|0|3' === $base );
$all = true;
foreach ( array( 'name' => 'Newz', 'slug' => 'newz', 'parent' => 4, 'count' => 4 ) as $f => $nv ) { $t2 = clone $term; $t2->$f = $nv; $all = $all && $base !== d5b_fp_term( $t2 ); }
check( 'term: each field changes it; an unchanged copy does not', $all && $base === d5b_fp_term( clone $term ) );

$GLOBALS['T_menus'][7] = (object) array( 'term_id' => 7, 'name' => 'Main', 'slug' => 'main', 'count' => 2 );
$GLOBALS['T_menu_items'][7] = array( (object) array( 'ID' => 21, 'post_modified_gmt' => '2026-10-01 10:00:00' ), (object) array( 'ID' => 20, 'post_modified_gmt' => '2026-10-02 10:00:00' ) );
$m0 = d5b_fp_menu( 7 );
check( 'menu: ids with modified times, sorted', '20@2026-10-02 10:00:00,21@2026-10-01 10:00:00' === $m0, $m0 );
$GLOBALS['T_menu_items'][7] = array_reverse( $GLOBALS['T_menu_items'][7] );
check( 'menu: order of the list does not change it', $m0 === d5b_fp_menu( 7 ) );
$GLOBALS['T_menu_items'][7][0]->post_modified_gmt = '2026-10-05 10:00:00';
check( 'menu: an item edited changes it', $m0 !== d5b_fp_menu( 7 ) );
$GLOBALS['T_menu_items'][7] = array( (object) array( 'ID' => 20, 'post_modified_gmt' => '2026-10-02 10:00:00' ) );
check( 'menu: an item removed changes it', $m0 !== d5b_fp_menu( 7 ) );
check( 'menu: a menu that does not exist gives an empty string', '' === d5b_fp_menu( 999 ) );

$p = t_add_post(); t_add_meta( $p, 'k', 'one' );
$f0 = d5b_fp_meta( $p, 'k' );
check( 'meta: row count + sha1 of the serialized rows, stable', '1:' . sha1( serialize( array( 'one' ) ) ) === $f0 && $f0 === d5b_fp_meta( $p, 'k' ) );
$GLOBALS['T_meta'][ $p ]['k'] = array( 'two' );
check( 'meta: a changed value changes it', $f0 !== d5b_fp_meta( $p, 'k' ) );
$GLOBALS['T_meta'][ $p ]['k'] = array( array( 'a' => 1 ) ); $f1 = d5b_fp_meta( $p, 'k' ); $GLOBALS['T_meta'][ $p ]['k'] = array( array( 'a' => 2 ) );
check( 'meta: an array value is covered too', $f1 !== d5b_fp_meta( $p, 'k' ) );

$a = t_add_post( array( 'post_type' => 'attachment', 'post_modified_gmt' => '2026-10-02 10:00:00' ) ); t_add_meta( $a, '_wp_attached_file', '2026/10/a.png' );
$g0 = d5b_fp_media( $a );
check( 'media: post_modified_gmt|file path', '2026-10-02 10:00:00|/var/www/uploads/2026/10/a.png' === $g0, $g0 );
$GLOBALS['T_posts'][ $a ]->post_modified_gmt = '2026-10-03 10:00:00';
check( 'media: a new modified time changes it', $g0 !== d5b_fp_media( $a ) );
$GLOBALS['T_posts'][ $a ]->post_modified_gmt = '2026-10-02 10:00:00'; $GLOBALS['T_meta'][ $a ]['_wp_attached_file'] = array( '2026/10/b.png' );
check( 'media: a new file path changes it; unchanged does not', $g0 !== d5b_fp_media( $a ) && ( $GLOBALS['T_meta'][ $a ]['_wp_attached_file'] = array( '2026/10/a.png' ) ) && $g0 === d5b_fp_media( $a ) );

echo "fix round 1\n";
fresh(); $p = t_add_post(); t_add_meta( $p, 'k', 'one' );
$c = d5b_confirm_issue( AB, 'meta', $p, d5b_fp_meta( $p, 'k' ) )['confirmation'];
t_add_meta( $p, 'k', 'two' );
check( 'meta: a second row added after the preview -> stale', 'stale' === reason( d5b_confirm_check( AB, 'meta', $p, d5b_fp_meta( $p, 'k' ), $c ) ) );
$a = d5b_fp_meta( $p, 'k' ); $GLOBALS['T_meta'][ $p ]['k'][1] = 'three';
check( 'meta: changing row 2 changes it', $a !== d5b_fp_meta( $p, 'k' ) );
fresh(); $p = t_add_post(); $none = d5b_fp_meta( $p, 'k' ); t_add_meta( $p, 'k', '' );
check( 'meta: an absent key differs from one empty row', $none !== d5b_fp_meta( $p, 'k' ) );

fresh(); $c = d5b_confirm_issue( AB, 'term', 12, 'fp1' )['confirmation'];
$GLOBALS['T_on_get'][ $key ] = function () use ( $key ) { delete_transient( $key ); };
$res = d5b_confirm_check( AB, 'term', 12, 'fp1', $c );
check( 'atomic consume: a concurrent check that consumed it between read and delete makes this one fail (used)', 'used' === reason( $res ), reason( $res ) );
fresh(); $c = d5b_confirm_issue( AB, 'term', 12, 'fp1' )['confirmation'];
$ok = 0; for ( $i = 0; $i < 3; $i++ ) { if ( true === d5b_confirm_check( AB, 'term', 12, 'fp1', $c ) ) { $ok++; } }
check( 'three checks of one code: exactly one passes', 1 === $ok );

fresh(); $GLOBALS['T_transient_fail'] = true;
$e = d5b_confirm_issue( AB, 'term', 12, 'fp1' );
check( 'issue with a failing set_transient returns WP_Error unavailable, no code', is_wp_error( $e ) && 'unavailable' === $e->get_error_code() );
$GLOBALS['T_transient_fail'] = false;

fresh(); $c = d5b_confirm_issue( AB, 'term', 5, 'f' )['confirmation'];
check( 'issue with 5, check with "05" succeeds', true === d5b_confirm_check( AB, 'term', '05', 'f', $c ) );
$c = d5b_confirm_issue( AB, 'term', '05', 'f' )['confirmation'];
check( 'issue with "05", check with 5 succeeds', true === d5b_confirm_check( AB, 'term', 5, 'f', $c ) );
$c = d5b_confirm_issue( AB, 'meta', 'slug-a', 'f' )['confirmation'];
check( 'a non-numeric id is left alone (works as given)', true === d5b_confirm_check( AB, 'meta', 'slug-a', 'f', $c ) );
check( 'fp_menu documents that it takes the menu\'s term id', false !== strpos( $src, "menu's term id" ) );

echo "\n" . ( $failed ? "FAILED: $failed of $n\n" : "ALL $n PASSED\n" );
exit( $failed ? 1 : 0 );
