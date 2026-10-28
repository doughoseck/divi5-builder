<?php
/**
 * The abilities switches (#32, Task 1).
 *
 *   php scripts/plugin-abilities-switches-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: the master is off until a person in wp-admin switches it on, a category value can
 * never bypass the master, the 2.0.0 part switches and the abilities switches survive each other's writes, and
 * only a request with a valid nonce and the right capability changes anything.
 */
define( 'D5B_VERSION', '2.1.0' );
require __DIR__ . '/plugin-test-stubs.php';
$GLOBALS['T_exits'] = 0;
function d5b_settings_exit() { $GLOBALS['T_exits']++; }
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
require $dir . '/inc/parts.php';
require $dir . '/inc/switches.php';
require $dir . '/inc/gate.php';
require $dir . '/inc/takeover.php';
require $dir . '/inc/abilities/categories.php';
require $dir . '/inc/settings.php';
foreach ( array( 'page-building', 'media-audit', 'media-clean-up', 'updates' ) as $f ) { require $dir . '/parts/' . $f . '.php'; }
define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/d5b-abs-mu-' . getmypid() );
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/d5b-abs-plugins-' . getmypid() );
define( 'D5B_FILE', WP_PLUGIN_DIR . '/divi5-builder/divi5-builder.php' );

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }
function fresh() { t_reset(); t_admin_reset(); t_set_caps( array() ); t_set_multisite( false ); $_POST = array(); $_GET = array(); $GLOBALS['T_exits'] = 0; }
function stored() { return is_multisite() ? get_site_option( 'd5b_switches', null ) : get_option( 'd5b_switches', null ); }
function run_save() { try { d5b_settings_save(); return null; } catch ( Throwable $e ) { return $e; } }
function html( $fn ) { ob_start(); $fn(); return ob_get_clean(); }
function post_abilities( array $extra ) { $_POST = array_merge( array( 'action' => 'd5b_save_switches', '_wpnonce' => 'x' ), $extra ); }

// ---- categories (data)
echo "the category table\n";
$cats = d5b_ability_categories();
$want = array( 'posts', 'pages', 'media', 'taxonomy', 'comments', 'meta', 'cpt', 'blocks', 'menus', 'revisions', 'search', 'settings', 'templates', 'appearance', 'widgets', 'cache', 'health', 'users', 'plugins', 'themes', 'core', 'history', 'divi' );
check( 'the 23 slugs, in order', $want === array_keys( $cats ), array_keys( $cats ) );
$ok = true;
foreach ( $cats as $slug => $c ) { if ( 'divi5-builder-' . $slug !== $c['wp_slug'] || '' === $c['label'] || '' === $c['description'] || false !== strpos( $c['label'] . $c['description'], "\u{2014}" ) ) { $ok = false; } }
check( 'each has label, description and wp_slug = divi5-builder-<slug>, no em dash', $ok );

// ---- defaults
echo "fresh store\n";
fresh();
$a = d5b_abilities_switches();
check( 'fresh store: master off', false === $a['master']['on'] && false === d5b_abilities_master_on(), $a['master'] );
$allcat = true; $norisky = true;
foreach ( $want as $slug ) { if ( true !== $a['categories'][ $slug ]['on'] ) { $allcat = false; } if ( false !== $a['categories'][ $slug ]['risky_on'] ) { $norisky = false; } }
check( 'fresh store: every category reads on in the normalized store', $allcat && 23 === count( $a['categories'] ) );
check( 'fresh store: every risky off', $norisky );
check( 'fresh store: nothing is written by reading', array() === $GLOBALS['T_writes'] );
check( 'fresh store: category_on and risky_on are false (master off)', false === d5b_abilities_category_on( 'posts' ) && false === d5b_abilities_risky_on( 'posts' ) );
check( 'unknown slug is never on', false === d5b_abilities_category_on( 'nonsense' ) && false === d5b_abilities_risky_on( 'nonsense' ) );
check( 'fresh store: master has empty changed_at and changed_by', '' === $a['master']['changed_at'] && '' === $a['master']['changed_by'] );

// ---- garbage fails closed
echo "garbage stored\n";
$garbage = array(
	'a string'                   => 'garbage',
	'the string 1'               => '1',
	'integer 1'                  => 1,
	'abilities is a string'      => array( 'abilities' => '1' ),
	'master is a string'         => array( 'abilities' => array( 'master' => 'yes', 'categories' => 'x' ) ),
	'master.on is "1"'           => array( 'abilities' => array( 'master' => array( 'on' => '1' ) ) ),
	'master.on is 1'             => array( 'abilities' => array( 'master' => array( 'on' => 1 ) ) ),
	'nested arrays'              => array( 'abilities' => array( 'master' => array( 'on' => array( true ) ), 'categories' => array( 'posts' => array( 'on' => array( true ), 'risky_on' => array( true ) ) ) ) ),
	'abilities as a list'        => array( 'abilities' => array( true, true ) ),
);
foreach ( $garbage as $label => $value ) {
	fresh(); update_option( 'd5b_switches', $value );
	check( "garbage ($label): master off, category and risky off", false === d5b_abilities_master_on() && false === d5b_abilities_category_on( 'posts' ) && false === d5b_abilities_risky_on( 'posts' ) && is_array( d5b_abilities_switches() ) );
}
fresh(); update_option( 'd5b_switches', array( 'abilities' => array( 'master' => array( 'on' => true ), 'categories' => array( 'posts' => array( 'on' => 'yes', 'risky_on' => 'yes' ), 'pages' => array( 'on' => false, 'risky_on' => true ), 'media' => 'junk' ) ) ) );
check( 'master truly true, but a garbage category value does not count as on', true === d5b_abilities_master_on() && false === d5b_abilities_category_on( 'posts' ) && false === d5b_abilities_risky_on( 'posts' ) );
check( 'a category stored off stays off even with master on', false === d5b_abilities_category_on( 'pages' ) );
check( 'a category with junk row reads as the default (on) once master is on', true === d5b_abilities_category_on( 'media' ) );
check( 'risky_on is false when the category is off even if stored true', false === d5b_abilities_risky_on( 'pages' ) );

// ---- category cannot bypass the master
echo "category never bypasses the master\n";
fresh(); update_option( 'd5b_switches', array( 'abilities' => array( 'master' => array( 'on' => false ), 'categories' => array( 'posts' => array( 'on' => true, 'risky_on' => true ) ) ) ) );
check( 'category_on false while master off even if category on', false === d5b_abilities_category_on( 'posts' ) );
check( 'risky_on false while master off even if risky and category on', false === d5b_abilities_risky_on( 'posts' ) );
d5b_abilities_set_master( true, 'doug' );
check( 'master on: category on and risky on now read true', true === d5b_abilities_category_on( 'posts' ) && true === d5b_abilities_risky_on( 'posts' ) );

// ---- setters keep each other's keys
echo "read-modify-write of the whole option\n";
fresh();
d5b_switch_set( 'media_audit', true, 'first' );
d5b_abilities_set_master( true, 'doug' );
check( 'set_master after a 2.0.0 switch: the part key survives', true === d5b_switch_is_on( 'media_audit' ) && true === d5b_abilities_master_on() );
d5b_abilities_set_category( 'posts', false, false, 'doug' );
d5b_switch_set( 'updates', true, 'second' );
$s = stored();
check( 'set_master then d5b_switch_set: both survive in the option', true === $s['abilities']['master']['on'] && 'doug' === $s['abilities']['master']['changed_by'] && true === $s['updates']['on'] && true === $s['media_audit']['on'], $s );
check( 'd5b_switch_set kept the category row', isset( $s['abilities']['categories']['posts'] ) && false === $s['abilities']['categories']['posts']['on'] );
d5b_abilities_set_master( false, 'doug2' );
$s = stored();
check( 'set_master keeps the 2.0.0 part keys', true === $s['updates']['on'] && true === $s['media_audit']['on'] && 'second' === $s['updates']['changed_by'] && 'first' === $s['media_audit']['changed_by'], $s );
check( 'set_master off: recorded, with an ISO time', false === $s['abilities']['master']['on'] && 'doug2' === $s['abilities']['master']['changed_by'] && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $s['abilities']['master']['changed_at'] ), $s['abilities']['master'] );
check( 'set_master keeps the category rows', false === $s['abilities']['categories']['posts']['on'] );
check( '2.0.0 reads are not polluted by the abilities key', array( 'media_audit', 'media_clean_up', 'updates' ) === array_keys( d5b_switches() ) );
fresh();
d5b_abilities_set_category( 'media', true, true, 'doug' );
d5b_abilities_set_category( 'pages', false, false, 'doug' );
$s = stored();
check( 'set_category records on, risky_on, who and when; leaves other categories alone', true === $s['abilities']['categories']['media']['risky_on'] && 'doug' === $s['abilities']['categories']['media']['changed_by'] && false === $s['abilities']['categories']['pages']['on'] && isset( $s['abilities']['categories']['media']['changed_at'] ) && false === d5b_abilities_master_on(), $s );
check( 'single site: add_option once, then update_option, never autoloaded', false === $GLOBALS['T_autoload']['d5b_switches'] && array( array( 'add_option', 'd5b_switches' ), array( 'update_option', 'd5b_switches' ) ) === $GLOBALS['T_writes'], array( $GLOBALS['T_writes'], $GLOBALS['T_autoload'] ) );

echo "set_category with an unknown slug throws\n";
fresh();
foreach ( array( 'nonsense', '', 'divi5-builder-posts', 'Posts', 'posts ' ) as $bad ) {
	$threw = false;
	try { d5b_abilities_set_category( $bad, true, true, 'x' ); } catch ( InvalidArgumentException $e ) { $threw = true; }
	check( 'set_category throws for ' . json_encode( $bad ), $threw );
}
check( 'a thrown set_category wrote nothing', array() === $GLOBALS['T_writes'] && null === stored() );

// ---- settings save
echo "settings save without nonce\n";
fresh(); t_set_caps( array( 'manage_options' ) ); $GLOBALS['T_referer_ok'] = false;
post_abilities( array( 'd5b_abilities_master' => '1', 'd5b_abilities_cat_form' => '1', 'd5b_abilities_cat' => array() ) );
$e = run_save();
check( 'settings save without nonce: unchanged, nothing written', null === stored() && array() === $GLOBALS['T_writes'] && null === $e && false === d5b_abilities_master_on(), $GLOBALS['T_writes'] );
check( 'the nonce action is d5b_switches', in_array( 'd5b_switches', $GLOBALS['T_referer_calls'], true ) );

echo "settings save as editor\n";
fresh(); t_set_caps( array( 'edit_posts', 'activate_plugins' ) );
post_abilities( array( 'd5b_abilities_master' => '1' ) );
$e = run_save();
check( 'settings save as editor: unchanged, refused via wp_die', null === stored() && array() === $GLOBALS['T_writes'] && 1 === count( $GLOBALS['T_dies'] ) && array() === $GLOBALS['T_redirects'], array( $GLOBALS['T_writes'], $GLOBALS['T_dies'] ) );

echo "settings save as admin\n";
fresh(); t_set_caps( array( 'manage_options' ) ); $GLOBALS['T_user_login'] = 'doug';
d5b_switch_set( 'media_audit', true, 'old-admin' );
$GLOBALS['T_writes'] = array();
$cat_on = array(); foreach ( $want as $slug ) { if ( 'comments' !== $slug ) { $cat_on[ $slug ] = '1'; } }
post_abilities( array( 'd5b_on' => array( 'media_audit' => '1' ), 'd5b_abilities_master' => '1', 'd5b_abilities_cat_form' => '1', 'd5b_abilities_cat' => $cat_on ) );
$e = run_save();
$s = stored();
check( 'no exception', null === $e, null === $e ? '' : $e->getMessage() );
check( 'settings save as admin: master on, changed_by recorded', true === $s['abilities']['master']['on'] && 'doug' === $s['abilities']['master']['changed_by'], $s['abilities']['master'] ?? null );
check( 'one category off (comments), changed_by recorded', false === d5b_abilities_category_on( 'comments' ) && 'doug' === $s['abilities']['categories']['comments']['changed_by'], $s['abilities']['categories']['comments'] ?? null );
check( 'the other categories are on and were not written (no row)', true === d5b_abilities_category_on( 'posts' ) && ! isset( $s['abilities']['categories']['posts'] ), $s['abilities']['categories'] );
check( 'the 2.0.0 part switch is untouched', true === $s['media_audit']['on'] && 'old-admin' === $s['media_audit']['changed_by'] );
check( 'risky stays off', false === d5b_abilities_risky_on( 'comments' ) && false === d5b_abilities_risky_on( 'posts' ) );
check( 'redirected back with updated=1, exited once', 1 === count( $GLOBALS['T_redirects'] ) && 1 === $GLOBALS['T_exits'] );
$GLOBALS['T_writes'] = array();
run_save();
check( 'saving the same form again writes nothing (only changed values)', array() === $GLOBALS['T_writes'], $GLOBALS['T_writes'] );
$GLOBALS['T_writes'] = array();
post_abilities( array( 'd5b_on' => array( 'media_audit' => '1' ), 'd5b_abilities_cat_form' => '1', 'd5b_abilities_cat' => $cat_on ) ); // master box unticked
run_save();
check( 'unticking the master switches it off and logs it', false === d5b_abilities_master_on() && 1 === count( $GLOBALS['T_writes'] ), $GLOBALS['T_writes'] );
check( 'with the master off no category is on, whatever the stored rows say', false === d5b_abilities_category_on( 'posts' ) );

echo "disabled boxes are not posted, and must not switch categories off\n";
fresh(); t_set_caps( array( 'manage_options' ) );
post_abilities( array( 'd5b_abilities_master' => '1' ) ); // master off at render: category boxes were disabled, no cat fields posted
run_save();
check( 'master on without any category fields: every category stays on, no category rows written', true === d5b_abilities_master_on() && true === d5b_abilities_category_on( 'posts' ) && true === d5b_abilities_category_on( 'comments' ) && ! isset( stored()['abilities']['categories'] ) || array() === ( stored()['abilities']['categories'] ?? array() ), stored() );
fresh(); t_set_caps( array( 'manage_options' ) );
post_abilities( array( 'd5b_abilities_master' => '1', 'd5b_abilities_cat' => array( 'posts' => '1', 'evil' => '1', 'divi5-builder-pages' => '1' ) ) ); // no marker: categories ignored
run_save();
check( 'no form marker: category fields are ignored, unknown slugs never stored', true === d5b_abilities_category_on( 'pages' ) && ! isset( stored()['abilities']['categories']['evil'] ) && ! isset( stored()['abilities']['categories']['divi5-builder-pages'] ), stored() );
fresh(); t_set_caps( array( 'manage_options' ) );
post_abilities( array( 'd5b_abilities_master' => '1', 'd5b_abilities_cat_form' => '1', 'd5b_abilities_cat' => array( 'posts' => '1', 'evil' => '1' ) ) );
run_save();
check( 'an unknown posted slug is never stored; unticked known categories are off', ! isset( stored()['abilities']['categories']['evil'] ) && false === d5b_abilities_category_on( 'pages' ) && true === d5b_abilities_category_on( 'posts' ), stored() );
fresh(); t_set_caps( array( 'manage_options' ) );
post_abilities( array( 'd5b_abilities_master' => array( '1' ), 'd5b_abilities_cat_form' => '1', 'd5b_abilities_cat' => 'junk' ) );
$e = run_save();
check( 'array/junk posted values do not throw', null === $e, null === $e ? '' : $e->getMessage() );

// ---- the page
echo "the settings page\n";
fresh(); t_set_caps( array( 'manage_options' ) );
$h = html( 'd5b_settings_render' );
$risk = 'Lets AI clients connected to this site (over the REST API) read site content and settings through this plugin. Nothing is shared until you switch this on. Every call is logged for 30 days.';
check( 'has an "AI abilities" section and the master checkbox', false !== strpos( $h, 'AI abilities' ) && false !== strpos( $h, 'name="d5b_abilities_master"' ) );
check( 'the risk note is printed verbatim', false !== strpos( $h, esc_html( $risk ) ) );
check( 'one checkbox per category, named d5b_abilities_cat[<slug>]', 23 === substr_count( $h, 'name="d5b_abilities_cat[' ), substr_count( $h, 'name="d5b_abilities_cat[' ) );
check( 'master off: every category checkbox is disabled and no form marker is printed', 23 === preg_match_all( '/name="d5b_abilities_cat\[[a-z]+\]"[^>]*disabled/', $h ) && false === strpos( $h, 'd5b_abilities_cat_form' ), preg_match_all( '/name="d5b_abilities_cat\[[a-z]+\]"[^>]*disabled/', $h ) );
check( 'master off: the master box is not checked', 1 === preg_match( '/name="d5b_abilities_master"(?![^>]*checked)/', $h ) );
d5b_abilities_set_master( true, 'doug' );
d5b_abilities_set_category( 'users', false, false, 'doug' );
$h = html( 'd5b_settings_render' );
check( 'master on: the master box is checked, category boxes enabled, marker printed', 1 === preg_match( '/name="d5b_abilities_master"[^>]*checked/', $h ) && 0 === preg_match( '/name="d5b_abilities_cat\[[a-z]+\]"[^>]*disabled/', $h ) && false !== strpos( $h, 'name="d5b_abilities_cat_form"' ) );
check( 'master on: users unchecked, posts checked', 1 === preg_match( '/name="d5b_abilities_cat\[posts\]"[^>]*checked/', $h ) && 0 === preg_match( '/name="d5b_abilities_cat\[users\]"[^>]*checked/', $h ) );
check( 'the page prints who last changed the master', false !== strpos( $h, 'doug' ) );
check( 'the 2.0.0 switches are still on the page', false !== strpos( $h, 'name="d5b_on[updates]"' ) );

// ---- multisite
echo "multisite\n";
fresh(); t_set_multisite( true );
d5b_abilities_set_master( true, 'root' );
check( 'multisite: stored as a site option, never update_option', array( array( 'update_site_option', 'd5b_switches' ) ) === $GLOBALS['T_writes'] && array() === $GLOBALS['T_options'] && true === d5b_abilities_master_on(), $GLOBALS['T_writes'] );
d5b_abilities_set_category( 'posts', false, false, 'root' );
check( 'multisite: set_category too', array() === $GLOBALS['T_options'] && false === d5b_abilities_category_on( 'posts' ) );
fresh(); t_set_multisite( true ); t_set_caps( array( 'manage_options' ) ); // a site admin, not a network admin
post_abilities( array( 'd5b_abilities_master' => '1' ) );
run_save();
check( 'multisite: manage_options is not enough, network cap required', null === stored() && array() === $GLOBALS['T_writes'] && 1 === count( $GLOBALS['T_dies'] ) && 'manage_network_options' === d5b_settings_cap() );
fresh(); t_set_multisite( true ); t_set_caps( array( 'manage_network_options' ) ); $GLOBALS['T_user_login'] = 'netadmin';
post_abilities( array( 'd5b_abilities_master' => '1' ) );
run_save();
check( 'multisite: network admin switches the master on; redirect to the network page', true === d5b_abilities_master_on() && 'netadmin' === stored()['abilities']['master']['changed_by'] && 1 === count( $GLOBALS['T_redirects'] ) && 0 === strpos( $GLOBALS['T_redirects'][0], 'https://x.example/wp-admin/network/' ), $GLOBALS['T_redirects'] );

// ---- static scan, as plugin-switches-test.php does it, plus the new setters
echo "only the switch files touch the switches\n";
$allow = array( 'inc/switches.php', 'inc/settings.php' );
$delete_only = array( 'uninstall.php' );
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
$scanned = 0; $bad = array();
foreach ( $it as $file ) {
	if ( 'php' !== $file->getExtension() ) { continue; }
	$rel = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) ) ), '/' );
	$scanned++;
	if ( in_array( $rel, $allow, true ) ) { continue; }
	$src = file_get_contents( $file->getPathname() );
	if ( in_array( $rel, $delete_only, true ) ) {
		if ( preg_match( '/\b(update|add)_(site_|blog_|network_)?option\s*\(/', $src ) ) { $bad[] = "$rel writes an option"; }
		if ( false !== strpos( $src, 'd5b_switch_set' ) || false !== strpos( $src, 'd5b_abilities_set_' ) ) { $bad[] = "$rel mentions a switch setter"; }
		continue;
	}
	$rest = str_replace( array( 'd5b_switches(', 'd5b_switch_is_on(' ), '', $src );
	if ( false !== strpos( $rest, 'd5b_switches' ) ) { $bad[] = "$rel names the option d5b_switches"; }
	if ( false !== strpos( $src, 'd5b_switch_set' ) ) { $bad[] = "$rel mentions d5b_switch_set"; }
	if ( false !== strpos( $src, 'd5b_abilities_set_' ) ) { $bad[] = "$rel mentions an abilities setter"; }
	if ( preg_match( '/[\'"]d5b_[\'"]\s*\./', $src ) ) { $bad[] = "$rel builds an option name from pieces"; }
}
check( 'scanned the plugin source (not vacuous), including inc/abilities/', $scanned >= 8, "$scanned files" );
check( 'static scan: no other file names d5b_switches or calls a switch setter', array() === $bad, $bad );
check( 'the categories file defines functions only (no hooks)', 0 === preg_match( '/\b(add_action|add_filter|register_[a-z_]+)\s*\(/', file_get_contents( $dir . '/inc/abilities/categories.php' ) ) );

echo "\n$n checks, $failed failed\n";
exit( $failed ? 1 : 0 );
