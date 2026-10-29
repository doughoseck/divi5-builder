<?php
/**
 * Write abilities for comments (#32, release 2.2.0, Task 4).
 *
 *   php scripts/plugin-abilities-write-comments-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php: comments, comment meta and the trash functions
 * behave as core's comment.php). Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (mutation testing).
 *
 * The promises under test: every moderation action needs moderate_comments and changes only the status, the history "before"
 * holds the whole old comment; restore and unspam give back the status the comment had; a site without a trash never trashes
 * (WordPress would delete); only real comment types are reachable; the strictest switch wins (a comment on a page is out of
 * reach while Pages is off); a reply is posted as the calling user on a readable, open post and WordPress decides approved or
 * held; a refusal writes nothing and no history row; a failed WordPress call writes nothing.
 */
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
if ( getenv( 'T_EMPTY_TRASH_0' ) ) { define( 'EMPTY_TRASH_DAYS', 0 ); }
define( 'D5B_VERSION', '2.2.0' );
require __DIR__ . '/plugin-test-stubs.php';
foreach ( array( 'parts', 'switches', 'abilities/categories', 'abilities/register', 'abilities/secrets', 'abilities/audit', 'abilities/scrub', 'abilities/history', 'abilities/read-content', 'abilities/read-structure', 'abilities/write-common', 'abilities/write-posts', 'abilities/write-comments' ) as $f ) { require $dir . '/inc/' . $f . '.php'; }

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

const MOD = array( 'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts', 'moderate_comments' );
const READER = array( 'read' );

function site( array $caps = MOD, int $uid = 3 ) {
	t_reset(); d5b_audit_install_state( 'reset' ); t_admin_reset(); t_set_caps( $caps ); t_set_multisite( false );
	$GLOBALS['T_user_id'] = $uid; $GLOBALS['T_users'] = array( 3 => (object) array( 'user_login' => 'ann', 'display_name' => 'Ann Admin', 'user_email' => 'ann@example.org', 'user_url' => 'https://ann.example' ), 5 => (object) array( 'user_login' => 'bob' ) );
	$_SERVER['REMOTE_ADDR'] = '203.0.113.9'; d5b_ability_audit_current_state( 0 );
	d5b_abilities_set_master( true, 'tester' ); d5b_audit_maybe_install();
}
function call( $name, $in = array() ) {
	$p = d5b_ability_permit( 'divi5-builder/' . $name, $in );
	return true === $p ? d5b_ability_run( 'divi5-builder/' . $name, $in ) : 'FORBIDDEN';
}
function handler( $name, $in = array() ) { return call_user_func( d5b_ability_defs()[ 'divi5-builder/' . $name ]['handler'], $in ); }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : ( 'FORBIDDEN' === $r ? 'FORBIDDEN' : null ); }
function hrows() { global $wpdb; return $wpdb->tables[ d5b_history_table() ] ?? array(); }
function arows() { global $wpdb; return $wpdb->tables[ d5b_audit_table() ] ?? array(); }
function alast() { $r = arows(); return $r ? end( $r ) : null; }
function hlast() { $r = hrows(); return $r ? d5b_history_get( (int) end( $r )['id'] ) : null; }
function cm( $id ) { return $GLOBALS['T_comments'][ (int) $id ] ?? null; }
function appr( $id ) { return null === cm( $id ) ? null : (string) cm( $id )->comment_approved; }
function calls( $name = null ) { return array_values( array_filter( $GLOBALS['T_comment_calls'], function ( $c ) use ( $name ) { return null === $name || $c[0] === $name; } ) ); }

$actions = array( 'approve', 'unapprove', 'spam', 'unspam', 'trash', 'restore' );

echo "registration\n";
$defs = d5b_ability_defs();
$bad = array();
foreach ( array_merge( $actions, array( 'reply' ) ) as $x ) {
	$d = $defs[ 'divi5-builder/comments-' . $x ] ?? null;
	if ( null === $d ) { $bad[] = "$x missing"; continue; }
	if ( 'comments' !== $d['category'] ) { $bad[] = "$x category"; }
	$an = $d['annotations'] ?? array();
	$want_destructive = in_array( $x, array( 'trash', 'spam' ), true );
	$want_idem = in_array( $x, array( 'approve', 'unapprove', 'spam', 'unspam' ), true );
	if ( false !== ( $an['readonly'] ?? null ) || $want_destructive !== ( $an['destructive'] ?? null ) || $want_idem !== ( $an['idempotent'] ?? null ) ) { $bad[] = "$x annotations " . json_encode( $an ); }
	if ( 'd5b_cm_h_' . $x !== $d['handler'] || ! is_callable( $d['handler'] ) || ! is_callable( $d['permission'] ) ) { $bad[] = "$x handler"; }
	if ( ! preg_match( '/return/i', $d['description'] ) || ! preg_match( '/refus/i', $d['description'] ) ) { $bad[] = "$x description"; }
}
check( 'the 7 comment writes are defined in the comments category; readonly false; destructive for trash and spam; idempotent for the four status settings', array() === $bad, $bad );
check( 'moderation takes only id; reply takes post_id, parent, content (post_id and content required, content 1..65525)',
	array( 'id' ) === array_keys( $defs['divi5-builder/comments-trash']['input_schema']['properties'] ) && array( 'id' ) === $defs['divi5-builder/comments-trash']['input_schema']['required']
	&& array( 'post_id', 'parent', 'content' ) === array_keys( $defs['divi5-builder/comments-reply']['input_schema']['properties'] ) && array( 'post_id', 'content' ) === $defs['divi5-builder/comments-reply']['input_schema']['required']
	&& 65525 === $defs['divi5-builder/comments-reply']['input_schema']['properties']['content']['maxLength'] && 1 === $defs['divi5-builder/comments-reply']['input_schema']['properties']['content']['minLength'] );

// ---- the subprocess: a site with no trash (EMPTY_TRASH_DAYS 0)
if ( getenv( 'T_EMPTY_TRASH_0' ) ) {
	site();
	$po = t_add_post( array( 'post_author' => 3 ) );
	$c = t_add_comment( array( 'comment_post_ID' => $po ) );
	$r = call( 'comments-trash', array( 'id' => $c ) );
	echo 'SUB:' . json_encode( array( 'code' => code( $r ), 'exists' => null !== cm( $c ), 'status' => appr( $c ), 'deleted' => $GLOBALS['T_comments_deleted'], 'wp_calls' => count( calls( 'wp_trash_comment' ) ), 'history' => count( hrows() ) ) ) . "\n";
	exit( 0 );
}

echo "moderation: each action needs moderate_comments and changes only the status\n";
$flow = array(
	// action => array( starting comment_approved, expected after, history action, expected before status )
	'approve'   => array( '0', '1', 'update', 'hold' ),
	'unapprove' => array( '1', '0', 'update', 'approved' ),
	'spam'      => array( '1', 'spam', 'update', 'approved' ),
	'unspam'    => array( 'spam', '0', 'update', 'spam' ),
	'trash'     => array( '0', 'trash', 'trash', 'hold' ),
	'restore'   => array( 'trash', '0', 'restore', 'trash' ),
);
foreach ( $flow as $act => $f ) {
	site();
	$po = t_add_post( array( 'post_author' => 3 ) );
	$c  = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => $f[0], 'comment_content' => 'Original text', 'comment_author' => 'Vera', 'comment_author_url' => 'https://vera.example' ) );
	if ( 'spam' === $f[0] || 'trash' === $f[0] ) { add_comment_meta( $c, '_wp_trash_meta_status', '0' ); }
	$was = (array) cm( $c ); $was_row = d5b_rc_comment_row( cm( $c ), true );
	// a caller without moderate_comments
	t_set_caps( READER );
	$h0 = count( hrows() );
	$x1 = call( 'comments-' . $act, array( 'id' => $c ) ); $x2 = handler( 'comments-' . $act, array( 'id' => $c ) );
	check( "$act: without moderate_comments the gate says no, the handler says not_allowed, nothing changes, no history row", 'FORBIDDEN' === $x1 && 'not_allowed' === code( $x2 ) && (array) cm( $c ) === $was && $h0 === count( hrows() ) );
	t_set_caps( MOD );
	$r = call( 'comments-' . $act, array( 'id' => $c ) );
	$now = (array) cm( $c );
	$diff = array_keys( array_diff_assoc( $now, $was ) );
	check( "$act: moderator changes the status to " . $f[1] . ' and nothing else in the comment', is_array( $r ) && true === $r['changed'] && (string) $f[1] === appr( $c ) && array( 'comment_approved' ) === $diff, array( $r, $diff ) );
	$h = hlast();
	check( "$act: one history row, object comment, action " . $f[2] . ', before holds the whole old comment (old status, content, author, date, url), after the new status',
		1 === count( hrows() ) && 'comment' === $h['object_type'] && $c === (int) $h['object_id'] && $f[2] === $h['action'] && 'divi5-builder/comments-' . $act === $h['ability']
		&& $f[3] === $h['before']['status'] && 'Original text' === $h['before']['content'] && 'Vera' === $h['before']['author'] && '2026-10-03 10:00:00' === $h['before']['date_gmt'] && 'https://vera.example' === $h['before']['author_url'] && $po === $h['before']['post']
		&& $was_row['status'] === $h['before']['status'] && false === strpos( json_encode( $h ), '203.0.113.9' ) && ( $r['status'] ) === $h['after']['status'] && false !== strpos( $h['summary'], 'comment ' . $c ) && false !== strpos( $h['summary'], 'post ' . $po ), $h );
	check( "$act: the last audit row is ok and the result carries no _history_failed", 'ok' === alast()['status'] && ! array_key_exists( '_history_failed', $r ), alast() );
}
site();
$po = t_add_post( array( 'post_author' => 3 ) );
$c = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => '0' ) );
call( 'comments-approve', array( 'id' => $c ) );
check( 'the summary reads "Approved comment N on post M"', 'Approved comment ' . $c . ' on post ' . $po === hlast()['summary'], hlast()['summary'] );
call( 'comments-spam', array( 'id' => $c ) );
check( 'spam summary: "Marked comment N on post M as spam"', 'Marked comment ' . $c . ' on post ' . $po . ' as spam' === hlast()['summary'], hlast()['summary'] );

echo "restore and unspam give the status back (core keeps _wp_trash_meta_status)\n";
site();
$po = t_add_post( array( 'post_author' => 3 ) );
$a1 = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => '1' ) ); $h1 = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => '0' ) );
call( 'comments-trash', array( 'id' => $a1 ) ); call( 'comments-trash', array( 'id' => $h1 ) );
check( 'trash keeps the old status in the comment meta, as wp_trash_comment', 'trash' === appr( $a1 ) && '1' === get_comment_meta( $a1, '_wp_trash_meta_status', true ) && '0' === get_comment_meta( $h1, '_wp_trash_meta_status', true ) && 2 === count( calls( 'wp_trash_comment' ) ) );
call( 'comments-restore', array( 'id' => $a1 ) ); call( 'comments-restore', array( 'id' => $h1 ) );
check( 'restore: the approved comment is approved again, the held one held again; the trash meta is gone', '1' === appr( $a1 ) && '0' === appr( $h1 ) && array() === get_comment_meta( $a1, '_wp_trash_meta_status' ) );
call( 'comments-spam', array( 'id' => $a1 ) ); call( 'comments-spam', array( 'id' => $h1 ) );
call( 'comments-unspam', array( 'id' => $a1 ) ); call( 'comments-unspam', array( 'id' => $h1 ) );
check( 'spam then unspam: approved back to approved, held back to held', '1' === appr( $a1 ) && '0' === appr( $h1 ) );
$sp = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => 'spam' ) );
call( 'comments-unspam', array( 'id' => $sp ) );
check( 'a spam comment with no remembered status (marked outside WordPress) comes back held, as core', '0' === appr( $sp ) );

echo "no-ops and wrong statuses\n";
site();
$po = t_add_post( array( 'post_author' => 3 ) );
$ap = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => '1' ) ); $hd = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => '0' ) );
$sp = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => 'spam' ) ); $tr = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => 'trash' ) );
$GLOBALS['T_comment_calls'] = array();
$r1 = call( 'comments-approve', array( 'id' => $ap ) ); $r2 = call( 'comments-unapprove', array( 'id' => $hd ) ); $r3 = call( 'comments-spam', array( 'id' => $sp ) ); $r4 = call( 'comments-unspam', array( 'id' => $ap ) );
check( 'approve of an approved, unapprove of a held, spam of a spam and unspam of a non-spam comment: changed false, WordPress not called, no history row, no audit failure',
	false === $r1['changed'] && false === $r2['changed'] && false === $r3['changed'] && false === $r4['changed'] && array() === $GLOBALS['T_comment_calls'] && array() === hrows() && 4 === count( arows() ) );
check( 'approve a spam or trashed comment is invalid_input and names the right ability; spam a trashed one too; nothing changes',
	'invalid_input' === code( call( 'comments-approve', array( 'id' => $sp ) ) ) && false !== strpos( call( 'comments-approve', array( 'id' => $sp ) )->get_error_message(), 'comments-unspam' )
	&& 'invalid_input' === code( call( 'comments-unapprove', array( 'id' => $tr ) ) ) && false !== strpos( call( 'comments-approve', array( 'id' => $tr ) )->get_error_message(), 'comments-restore' )
	&& 'invalid_input' === code( call( 'comments-spam', array( 'id' => $tr ) ) ) && 'spam' === appr( $sp ) && 'trash' === appr( $tr ) && array() === hrows() );
check( 'unspam of a trashed comment is invalid_input; restore of a comment that is not in the trash is not_found; trash of a trashed comment is cannot_trash',
	'invalid_input' === code( call( 'comments-unspam', array( 'id' => $tr ) ) ) && 'not_found' === code( call( 'comments-restore', array( 'id' => $ap ) ) ) && 'cannot_trash' === code( call( 'comments-trash', array( 'id' => $tr ) ) ) && array() === hrows() );
check( 'bad input (handler): no id, id 0, an unknown key are invalid_input; the gate refuses them first; a missing comment is not_found', 'invalid_input' === code( handler( 'comments-approve', array() ) ) && 'invalid_input' === code( handler( 'comments-approve', array( 'id' => 0 ) ) )
	&& 'invalid_input' === code( handler( 'comments-approve', array( 'id' => $hd, 'status' => 'x' ) ) ) && 'FORBIDDEN' === call( 'comments-approve', array() ) && 'FORBIDDEN' === call( 'comments-approve', array( 'id' => 99999 ) ) && 'not_found' === code( handler( 'comments-approve', array( 'id' => 99999 ) ) ) );
echo "a plugin stopping WordPress, a site without a trash\n";
site();
$po = t_add_post( array( 'post_author' => 3 ) );
$c = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => '0' ) );
$GLOBALS['T_comment_blocked'] = true;
$r = call( 'comments-approve', array( 'id' => $c ) );
check( 'WordPress refusing the status change (a plugin stopped it) is write_failed, the status is unchanged and no history row is written', 'write_failed' === code( $r ) && '0' === appr( $c ) && array() === hrows() );
$GLOBALS['T_comment_blocked'] = false;
putenv( 'T_EMPTY_TRASH_0=1' ); $out = array(); exec( escapeshellarg( PHP_BINARY ) . ' -d max_execution_time=30 ' . escapeshellarg( __FILE__ ) . ' 2>&1', $out ); putenv( 'T_EMPTY_TRASH_0' );
$sub = null; foreach ( $out as $line ) { if ( 0 === strpos( $line, 'SUB:' ) ) { $sub = json_decode( substr( $line, 4 ), true ); } }
check( 'with EMPTY_TRASH_DAYS 0 comments-trash is refused cannot_trash, WordPress is never asked (it would delete), the comment is still there and no history row is written',
	is_array( $sub ) && 'cannot_trash' === $sub['code'] && $sub['exists'] && '1' === $sub['status'] && array() === $sub['deleted'] && 0 === $sub['wp_calls'] && 0 === $sub['history'], $out );

echo "only real comment types are reachable\n";
foreach ( array( 'order_note', 'block_note', 'note', 'review' ) as $ty ) {
	site();
	$po = t_add_post( array( 'post_author' => 3 ) );
	$c = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => '0', 'comment_type' => $ty ) );
	$was = (array) cm( $c ); $all = true;
	foreach ( $actions as $act ) { $all = $all && 'FORBIDDEN' === call( 'comments-' . $act, array( 'id' => $c ) ) && 'not_found' === code( handler( 'comments-' . $act, array( 'id' => $c ) ) ); }
	check( "type $ty: all six actions are refused (gate and handler), the comment is untouched, nothing logged", $all && (array) cm( $c ) === $was && array() === hrows() && array() === $GLOBALS['T_comment_calls'] );
}
site();
$po = t_add_post( array( 'post_author' => 3 ) );
$ok = true;
foreach ( array( '', 'comment', 'pingback', 'trackback' ) as $ty ) {
	$c = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => '0', 'comment_type' => $ty ) );
	$r = call( 'comments-approve', array( 'id' => $c ) );
	$ok = $ok && is_array( $r ) && '1' === appr( $c );
}
check( 'comment, pingback, trackback and the empty type are reachable', $ok );

echo "strictest switch: the comment's post must be readable and its category on\n";
site();
$page = t_add_post( array( 'post_type' => 'page', 'post_author' => 3 ) ); $post = t_add_post( array( 'post_author' => 3 ) );
$cp = t_add_comment( array( 'comment_post_ID' => $page, 'comment_approved' => '0' ) ); $cq = t_add_comment( array( 'comment_post_ID' => $post, 'comment_approved' => '0' ) );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
$r1 = call( 'comments-approve', array( 'id' => $cp ) ); $r2 = handler( 'comments-approve', array( 'id' => $cp ) ); $r3 = handler( 'comments-trash', array( 'id' => $cp ) );
check( 'Pages off: a comment on a page cannot be moderated (gate, handler not_allowed); unchanged, no history row', 'FORBIDDEN' === $r1 && 'not_allowed' === code( $r2 ) && 'not_allowed' === code( $r3 ) && '0' === appr( $cp ) && array() === hrows() );
check( '... a comment on a post still can', is_array( call( 'comments-approve', array( 'id' => $cq ) ) ) && '1' === appr( $cq ) );
d5b_abilities_set_category( 'pages', true, false, 'tester' );
check( 'Pages on again: the comment on the page can be approved', is_array( call( 'comments-approve', array( 'id' => $cp ) ) ) && '1' === appr( $cp ) );
d5b_abilities_set_category( 'comments', false, false, 'tester' );
check( 'the Comments category off: every comment write is refused by the gate', 'FORBIDDEN' === call( 'comments-spam', array( 'id' => $cq ) ) && 'FORBIDDEN' === call( 'comments-reply', array( 'post_id' => $post, 'content' => 'x' ) ) );
d5b_abilities_set_category( 'comments', true, false, 'tester' );
site();
$priv = t_add_post( array( 'post_author' => 5, 'post_status' => 'private' ) );
$c = t_add_comment( array( 'comment_post_ID' => $priv, 'comment_approved' => '0' ) );
t_set_caps( array( 'read', 'moderate_comments' ) );
check( 'a comment on a private post the moderator may not read is not found (gate and handler), unchanged', 'FORBIDDEN' === call( 'comments-approve', array( 'id' => $c ) ) && 'not_found' === code( handler( 'comments-approve', array( 'id' => $c ) ) ) && '0' === appr( $c ) );
site();
$c = t_add_comment( array( 'comment_post_ID' => 99999, 'comment_approved' => '0' ) );
check( 'a comment whose post is gone is not found', 'FORBIDDEN' === call( 'comments-approve', array( 'id' => $c ) ) && 'not_found' === code( handler( 'comments-approve', array( 'id' => $c ) ) ) );

echo "comments-reply\n";
site();
$po = t_add_post( array( 'post_author' => 5, 'comment_status' => 'open' ) );
$r = call( 'comments-reply', array( 'post_id' => $po, 'content' => 'Thanks <b>all</b>' ) );
$nc = is_array( $r ) ? cm( $r['id'] ) : null;
check( 'a moderator\'s reply is posted as the calling user (author name, email, url, user_id) on the post, approved by wp_allow_comment, top level',
	null !== $nc && 3 === (int) $nc->user_id && 'Ann Admin' === $nc->comment_author && 'ann@example.org' === $nc->comment_author_email && 'https://ann.example' === $nc->comment_author_url
	&& $po === (int) $nc->comment_post_ID && 0 === (int) $nc->comment_parent && '1' === appr( $nc->comment_ID ) && 'approved' === $r['status'] && true === $r['changed'] && 'Thanks <b>all</b>' === $nc->comment_content, $r );
$h = hlast();
check( 'one history row: object comment, action create, no before, after holds the comment, summary names the post and the comment', 1 === count( hrows() ) && 'comment' === $h['object_type'] && 'create' === $h['action'] && (int) $r['id'] === (int) $h['object_id'] && 'divi5-builder/comments-reply' === $h['ability']
	&& ( null === $h['before'] || array() === $h['before'] ) && 'Thanks <b>all</b>' === $h['after']['content'] && $po === $h['after']['post'] && false !== strpos( $h['summary'], 'post ' . $po ), $h );
$par = $r['id'];
$r2 = call( 'comments-reply', array( 'post_id' => $po, 'parent' => $par, 'content' => 'A threaded answer' ) );
check( 'a reply with a parent on the same post is threaded under it', is_array( $r2 ) && $par === (int) cm( $r2['id'] )->comment_parent && false !== strpos( hlast()['summary'], 'Replied to comment ' . $par ) );
$other = t_add_post( array( 'post_author' => 5 ) );
$oc = t_add_comment( array( 'comment_post_ID' => $other, 'comment_approved' => '1' ) ); $sc = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => 'spam' ) ); $tc = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => 'trash' ) );
$hc = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => '0' ) ); $nt = t_add_comment( array( 'comment_post_ID' => $po, 'comment_approved' => '1', 'comment_type' => 'order_note' ) );
$h0 = count( hrows() ); $c0 = count( $GLOBALS['T_comments'] );
$bad = array();
foreach ( array( 'other post' => $oc, 'spam' => $sc, 'trash' => $tc, 'missing' => 99999, 'order note' => $nt ) as $why => $pid ) {
	if ( 'invalid_input' !== code( call( 'comments-reply', array( 'post_id' => $po, 'parent' => $pid, 'content' => 'x' . $why ) ) ) ) { $bad[] = $why; }
}
check( 'a parent on another post, spam, trashed, missing or not a real comment is invalid_input; nothing posted, no history row', array() === $bad && $c0 === count( $GLOBALS['T_comments'] ) && $h0 === count( hrows() ), $bad );
check( 'a parent on hold: a moderator may reply to it', is_array( call( 'comments-reply', array( 'post_id' => $po, 'parent' => $hc, 'content' => 'held parent ok' ) ) ) );
$bad_in = array(
	'empty' => array( 'post_id' => $po, 'content' => '' ), 'spaces' => array( 'post_id' => $po, 'content' => "  \n " ), 'too long' => array( 'post_id' => $po, 'content' => str_repeat( 'a', 65526 ) ),
	'unknown key' => array( 'post_id' => $po, 'content' => 'x', 'status' => 'spam' ), 'no post_id' => array( 'content' => 'x' ), 'no content' => array( 'post_id' => $po ), 'bad parent' => array( 'post_id' => $po, 'parent' => 0, 'content' => 'x' ),
);
$codes = array();
foreach ( $bad_in as $why => $in ) { $codes[ $why ] = code( handler( 'comments-reply', $in ) ); }
check( 'empty text, only spaces, text over 65525 characters, an unknown key, a missing post_id or content and parent 0 are invalid_input (handler); exactly 65525 characters is fine',
	array_unique( array_values( $codes ) ) === array( 'invalid_input' ) && is_array( call( 'comments-reply', array( 'post_id' => $po, 'content' => str_repeat( 'é', 65525 ) ) ) ), $codes );
check( 'text holding [redacted] (from a masked read) is refused masked_read; a parent that holds it lets a reply quote it',
	'masked_read' === code( call( 'comments-reply', array( 'post_id' => $po, 'content' => 'key is [redacted]' ) ) )
	&& is_array( call( 'comments-reply', array( 'post_id' => $po, 'parent' => ( $rc = t_add_comment( array( 'comment_post_ID' => $po, 'comment_content' => 'has [redacted] in it' ) ) ), 'content' => 'quoting [redacted]' ) ) ) );
$d1 = call( 'comments-reply', array( 'post_id' => $po, 'content' => 'dupe check' )); $d2 = call( 'comments-reply', array( 'post_id' => $po, 'content' => 'dupe check' ) );
check( 'the same comment twice is refused by WordPress (duplicate) as invalid_input, and the second writes no history row', is_array( $d1 ) && 'invalid_input' === code( $d2 ) && false !== strpos( $d2->get_error_message(), 'Duplicate' ) );
$GLOBALS['T_new_comment_error'] = new WP_Error( 'comment_flood', 'You are posting comments too quickly.', 429 );
$h0 = count( hrows() );
check( 'a flood refusal from WordPress is invalid_input with its message, no history row', 'invalid_input' === code( call( 'comments-reply', array( 'post_id' => $po, 'content' => 'fast' ) ) ) && $h0 === count( hrows() ) );
$GLOBALS['T_new_comment_error'] = null;

echo "reply: open or closed, readable, the post's category\n";
site( READER, 5 );
$GLOBALS['T_users'][5] = (object) array( 'user_login' => 'bob', 'display_name' => 'Bob', 'user_email' => 'bob@example.org' );
$open = t_add_post( array( 'post_author' => 3, 'comment_status' => 'open' ) ); $closed = t_add_post( array( 'post_author' => 3, 'comment_status' => 'closed' ) );
$r = call( 'comments-reply', array( 'post_id' => $open, 'content' => 'Reader says hi' ) );
check( 'a reader (no moderate_comments) comments on an open post as themself; WordPress holds it when the site moderates', is_array( $r ) && 5 === (int) cm( $r['id'] )->user_id && 'Bob' === cm( $r['id'] )->comment_author && '1' === appr( $r['id'] ) );
update_option( 'comment_moderation', 1 );
$r = call( 'comments-reply', array( 'post_id' => $open, 'content' => 'Reader says hi again' ) );
check( '... with comment_moderation on the reader\'s comment is held (status hold), the answer says so', is_array( $r ) && '0' === appr( $r['id'] ) && 'hold' === $r['status'], $r );
$h0 = count( hrows() ); $c0 = count( $GLOBALS['T_comments'] );
$r1 = call( 'comments-reply', array( 'post_id' => $closed, 'content' => 'Let me in' )); $r2 = handler( 'comments-reply', array( 'post_id' => $closed, 'content' => 'Let me in' ) );
check( 'a closed post refuses a reader (gate and handler not_allowed); nothing posted, no history row', 'FORBIDDEN' === $r1 && 'not_allowed' === code( $r2 ) && $c0 === count( $GLOBALS['T_comments'] ) && $h0 === count( hrows() ) );
t_set_caps( MOD );
$r = call( 'comments-reply', array( 'post_id' => $closed, 'content' => 'Moderator on a closed post' ) );
check( 'a moderator may comment on a closed post', is_array( $r ) && '1' === appr( $r['id'] ) );
site( READER, 5 );
$priv = t_add_post( array( 'post_author' => 3, 'post_status' => 'private', 'comment_status' => 'open' ) ); $draft = t_add_post( array( 'post_author' => 3, 'post_status' => 'draft', 'comment_status' => 'open' ) );
check( 'a post the caller may not read is not found for a reply (gate and handler); a draft they cannot edit too', 'FORBIDDEN' === call( 'comments-reply', array( 'post_id' => $priv, 'content' => 'x' ) ) && 'not_found' === code( handler( 'comments-reply', array( 'post_id' => $priv, 'content' => 'x' ) ) )
	&& 'FORBIDDEN' === call( 'comments-reply', array( 'post_id' => $draft, 'content' => 'x' ) ) );
site();
$draft = t_add_post( array( 'post_author' => 3, 'post_status' => 'draft', 'comment_status' => 'open' ) ); $trash = t_add_post( array( 'post_author' => 3, 'post_status' => 'trash', 'comment_status' => 'open' ) );
check( 'an editor may comment on their own draft; a trashed post takes no comments (invalid_input)', is_array( call( 'comments-reply', array( 'post_id' => $draft, 'content' => 'note to self' ) ) ) && 'invalid_input' === code( call( 'comments-reply', array( 'post_id' => $trash, 'content' => 'x' ) ) ) );
site();
$page = t_add_post( array( 'post_type' => 'page', 'post_author' => 3, 'comment_status' => 'open' ) ); $post = t_add_post( array( 'post_author' => 3, 'comment_status' => 'open' ) );
d5b_abilities_set_category( 'pages', false, false, 'tester' );
$h0 = count( hrows() ); $c0 = count( $GLOBALS['T_comments'] );
check( 'Pages off: a reply on a page is refused (gate FORBIDDEN, handler not_allowed); a reply on a post still works; the page got nothing',
	'FORBIDDEN' === call( 'comments-reply', array( 'post_id' => $page, 'content' => 'x' ) ) && 'not_allowed' === code( handler( 'comments-reply', array( 'post_id' => $page, 'content' => 'x' ) ) ) && $c0 === count( $GLOBALS['T_comments'] ) && $h0 === count( hrows() )
	&& is_array( call( 'comments-reply', array( 'post_id' => $post, 'content' => 'x' ) ) ) );
d5b_abilities_set_category( 'pages', true, false, 'tester' );
check( 'Pages on again: the reply on the page works', is_array( call( 'comments-reply', array( 'post_id' => $page, 'content' => 'x' ) ) ) );
site();
$po = t_add_post( array( 'post_author' => 5, 'comment_status' => 'open' ) );
unset( $GLOBALS['wpdb']->tables['wp_d5b_history'] ); $GLOBALS['T_dbdelta_fail'] = true; set_transient( 'd5b_audit_install_failed', 1, 3600 );
$r = call( 'comments-reply', array( 'post_id' => $po, 'content' => 'history table gone' ) );
check( 'with the history table gone the reply stands, the audit row says ok_no_history and the caller never sees _history_failed', is_array( $r ) && null !== cm( $r['id'] ) && ! array_key_exists( '_history_failed', $r ) && 'ok_no_history' === end( $GLOBALS['wpdb']->tables[ d5b_audit_table() ] )['status'], $r );

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
