<?php
/**
 * The risky switches (#32, release 2.3.0, Task 1).
 *
 *   php scripts/plugin-abilities-risky-test.php
 *
 * Loads the real inc/ files with WordPress stand-ins (plugin-test-stubs.php).
 * Set D5B_PLUGIN_DIR to test another copy of plugin/divi5-builder (used for mutation testing).
 *
 * The promises under test: a definition marked risky is registered, permitted and run only when the master, its category
 * AND its category's risky switch are all on (a direct PHP call included, refused 'forbidden' and audited once); the
 * risky box is saved only with the form marker and never disturbs the category box (nor the other way round); the
 * upgrade from schema 1 or 2 to 3 turns a master that was on off once (network-wide on multisite) with the 2.3.0 text;
 * and only the switch files write the switch option.
 */
define( 'D5B_VERSION', '2.3.0' );
require __DIR__ . '/plugin-test-stubs.php';
$GLOBALS['T_exits'] = 0;
function d5b_settings_exit() { $GLOBALS['T_exits']++; }
$dir = getenv( 'D5B_PLUGIN_DIR' ) ?: __DIR__ . '/../plugin/divi5-builder';
require $dir . '/inc/parts.php';
require $dir . '/inc/switches.php';
require $dir . '/inc/gate.php';
require $dir . '/inc/takeover.php';
require $dir . '/inc/abilities/categories.php';
require $dir . '/inc/abilities/register.php';
require $dir . '/inc/settings.php';
foreach ( array( 'page-building', 'media-audit', 'media-clean-up', 'updates' ) as $f ) { require $dir . '/parts/' . $f . '.php'; }
define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/d5b-risky-mu-' . getmypid() );
define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/d5b-risky-plugins-' . getmypid() );
define( 'D5B_FILE', WP_PLUGIN_DIR . '/divi5-builder/divi5-builder.php' );

$failed = 0; $n = 0;
function check( $name, $ok, $detail = '' ) { global $failed, $n; $n++; echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $name . ( ! $ok && '' !== $detail ? "\n          " . ( is_string( $detail ) ? $detail : json_encode( $detail ) ) : '' ) . "\n"; if ( ! $ok ) { $failed++; } }

// audit double: the writer returns the row id, d5b_audit_update changes that row
$GLOBALS['T_audit'] = array();
function d5b_audit_write( string $ability, array $args, string $status, int $duration_ms ): int { $GLOBALS['T_audit'][] = compact( 'ability', 'args', 'status', 'duration_ms' ); return count( $GLOBALS['T_audit'] ); }
function d5b_audit_update( int $id, string $status, int $duration_ms, ?array $args = null ): bool {
	if ( ! isset( $GLOBALS['T_audit'][ $id - 1 ] ) ) { return false; }
	$GLOBALS['T_audit'][ $id - 1 ]['status'] = $status; $GLOBALS['T_audit'][ $id - 1 ]['duration_ms'] = $duration_ms;
	return true;
}

function fresh() { t_reset(); t_admin_reset(); t_set_caps( array() ); t_set_multisite( false ); $_POST = array(); $_GET = array(); $GLOBALS['T_exits'] = 0; $GLOBALS['T_audit'] = array(); $GLOBALS['T_user_id'] = 1; d5b_ability_registry( array() ); }
function stored() { return is_multisite() ? get_site_option( 'd5b_switches', null ) : get_option( 'd5b_switches', null ); }
function run_save() { try { d5b_settings_save(); return null; } catch ( Throwable $e ) { return $e; } }
function html( $fn ) { ob_start(); $fn(); return ob_get_clean(); }
function post_abilities( array $extra ) { $_POST = array_merge( array( 'action' => 'd5b_save_switches', '_wpnonce' => 'x' ), $extra ); }
function mk( $name, $cat, array $over = array() ) {
	return array_merge( array(
		'name' => $name, 'category' => $cat, 'label' => 'Test ' . $name,
		'description' => 'Returns a test record for ' . $name . '. Refuses when the caller may not read it.',
		'handler' => function ( array $in ) { $GLOBALS['T_ran'] = ( $GLOBALS['T_ran'] ?? 0 ) + 1; return array( 'ok' => true ); },
		'permission' => function ( array $in ) { return true; },
	), $over );
}
/** Master on, categories on, the settings category with its risky switch as given. */
function site( bool $master, bool $cat_on, bool $risky_on ) {
	fresh();
	$GLOBALS['T_ran'] = 0;
	d5b_ability_add( mk( 'divi5-builder/settings-risky', 'settings', array( 'risky' => true ) ) );
	d5b_ability_add( mk( 'divi5-builder/settings-plain', 'settings' ) );
	if ( $master ) { d5b_abilities_set_master( true, 't' ); }
	d5b_abilities_set_category( 'settings', $cat_on, $risky_on, 't' );
	add_action( 'wp_abilities_api_categories_init', 'd5b_abilities_register_categories' );
	add_action( 'wp_abilities_api_init', 'd5b_abilities_register' );
	t_fire( 'wp_abilities_api_categories_init' ); t_fire( 'wp_abilities_api_init' );
}

echo "the helpers\n";
check( 'd5b_ability_risky: true only for risky => true exactly', true === d5b_ability_risky( array( 'risky' => true ) ) && false === d5b_ability_risky( array() ) && false === d5b_ability_risky( array( 'risky' => 1 ) ) && false === d5b_ability_risky( array( 'risky' => 'yes' ) ) && false === d5b_ability_risky( array( 'risky' => false ) ) );
$four = array(); foreach ( array_keys( d5b_ability_categories() ) as $s ) { if ( d5b_category_has_risky( $s ) ) { $four[] = $s; } }
check( 'd5b_category_has_risky: exactly settings, templates, appearance, health', array( 'settings', 'templates', 'appearance', 'health' ) === $four && false === d5b_category_has_risky( 'nonsense' ), $four );

echo "the registration gate\n";
site( true, true, false );
check( 'risky off: the plain ability is registered, the risky one is not', isset( $GLOBALS['T_abilities']['divi5-builder/settings-plain'] ) && ! isset( $GLOBALS['T_abilities']['divi5-builder/settings-risky'] ), array_keys( $GLOBALS['T_abilities'] ) );
site( true, true, true );
check( 'risky on: both are registered', isset( $GLOBALS['T_abilities']['divi5-builder/settings-plain'], $GLOBALS['T_abilities']['divi5-builder/settings-risky'] ) );
site( false, true, true );
check( 'master off: neither is registered, risky_on or not', array() === $GLOBALS['T_abilities'] );
site( true, false, true );
check( 'category off but risky_on stored: neither is registered', array() === $GLOBALS['T_abilities'] && false === d5b_abilities_risky_on( 'settings' ) && true === d5b_abilities_switches()['categories']['settings']['risky_on'] );

echo "permit and direct run\n";
site( true, true, false );
$GLOBALS['T_audit'] = array();
check( 'risky off: permit refuses', false === d5b_ability_permit( 'divi5-builder/settings-risky', array() ) && 1 === count( $GLOBALS['T_audit'] ) && 'forbidden' === $GLOBALS['T_audit'][0]['status'] );
$GLOBALS['T_audit'] = array();
$r = d5b_ability_run( 'divi5-builder/settings-risky', array( 'x' => 1 ) );
check( 'risky off: a direct run is forbidden (403), the handler never ran, ONE audit row with status forbidden', is_wp_error( $r ) && 'forbidden' === $r->get_error_code() && 0 === $GLOBALS['T_ran'] && 1 === count( $GLOBALS['T_audit'] ) && 'forbidden' === $GLOBALS['T_audit'][0]['status'], array( $GLOBALS['T_ran'], $GLOBALS['T_audit'] ) );
check( 'risky off: the plain ability still runs', true === ( d5b_ability_run( 'divi5-builder/settings-plain', array() )['ok'] ?? null ) );
site( true, true, true );
$GLOBALS['T_audit'] = array();
check( 'risky on: permit allows and a direct run ran the handler (audit ends ok)', true === d5b_ability_permit( 'divi5-builder/settings-risky', array() ) && true === ( d5b_ability_run( 'divi5-builder/settings-risky', array() )['ok'] ?? null ) && 'ok' === end( $GLOBALS['T_audit'] )['status'] );
// the switch is read at call time: flipping it off after registration refuses the next call
d5b_abilities_set_category( 'settings', true, false, 't' ); $GLOBALS['T_ran'] = 0;
check( 'risky switched off after registration: the next direct run is refused', is_wp_error( d5b_ability_run( 'divi5-builder/settings-risky', array() ) ) && 0 === $GLOBALS['T_ran'] );
d5b_abilities_set_category( 'settings', true, true, 't' ); d5b_abilities_set_master( false, 't' );
check( 'master off with risky_on stored: refused', is_wp_error( d5b_ability_run( 'divi5-builder/settings-risky', array() ) ) && false === d5b_abilities_risky_on( 'settings' ) );

echo "fail-closed storage\n";
fresh(); d5b_abilities_set_master( true, 't' );
foreach ( array( '1' => '1', 'int 1' => 1, 'yes' => 'yes', 'array' => array( 1 ) ) as $label => $val ) {
	$all = stored(); $all['abilities']['categories']['settings'] = array( 'on' => true, 'risky_on' => $val ); d5b_switches_store( $all );
	check( "risky_on stored as $label reads off", false === d5b_abilities_risky_on( 'settings' ) );
}
$all = stored(); $all['abilities']['categories']['settings'] = array( 'on' => true, 'risky_on' => true ); d5b_switches_store( $all );
check( 'risky_on stored as a real true reads on', true === d5b_abilities_risky_on( 'settings' ) );

echo "the settings boxes\n";
fresh(); t_set_caps( array( 'manage_options' ) ); $GLOBALS['T_user_login'] = 'doug';
$h = html( 'd5b_settings_render' );
$notes = array(
	'settings'   => 'Can change the homepage, permalinks, time zone and whether search engines index the site. A wrong value is easy to put back from the change history, but visitors see it in the meantime.',
	'templates'  => 'Changes the theme\'s templates and global styles on block themes. A broken template can blank a page until it is put back.',
	'appearance' => 'Can change Custom CSS and theme settings. A wrong CSS rule can hide the whole page until it is put back.',
	'health'     => 'Can run a scheduled task now. Tasks belong to plugins and may do real work (send emails, process orders, run migrations).',
);
$ok = true; foreach ( $notes as $slug => $note ) { if ( false === strpos( $h, 'name="d5b_risky[' . $slug . ']"' ) || false === strpos( $h, esc_html( $note ) ) ) { $ok = false; } }
check( 'the four risky checkboxes render, each with its verbatim note', $ok );
check( 'the label reads "Also allow risky actions" four times', 4 === substr_count( $h, 'Also allow risky actions' ) );
check( 'no other category has a risky box', 4 === preg_match_all( '/name="d5b_risky\[/', $h ) && 0 === preg_match( '/name="d5b_risky\[(posts|pages|widgets|cache|users)\]/', $h ) );
check( 'fresh store: every risky box is unticked', 0 === preg_match( '/name="d5b_risky\[[a-z]+\]"[^>]*checked/', $h ) );
d5b_abilities_set_category( 'appearance', true, true, 'doug' );
$h = html( 'd5b_settings_render' );
check( 'a stored risky_on renders ticked, and only that one', 1 === preg_match( '/name="d5b_risky\[appearance\]"[^>]*checked/', $h ) && 0 === preg_match( '/name="d5b_risky\[settings\]"[^>]*checked/', $h ) );

echo "saving the boxes\n";
fresh(); t_set_caps( array( 'manage_options' ) ); $GLOBALS['T_user_login'] = 'doug';
d5b_abilities_set_master( true, 'doug' );
post_abilities( array( 'd5b_abilities_master' => '1', 'd5b_abilities_cat_form' => '1', 'd5b_abilities_cat' => array_fill_keys( array_keys( d5b_ability_categories() ), '1' ), 'd5b_risky' => array( 'settings' => '1', 'health' => '1' ) ) );
$e = run_save();
$a = d5b_abilities_switches()['categories'];
check( 'ticked risky boxes are stored; the others stay off', null === $e && true === $a['settings']['risky_on'] && true === $a['health']['risky_on'] && false === $a['templates']['risky_on'] && false === $a['appearance']['risky_on'] && 'doug' === $a['settings']['changed_by'], $a );
check( 'the category boxes are unchanged by that save', true === $a['settings']['on'] && true === $a['posts']['on'] );
// only the category box changes
post_abilities( array( 'd5b_abilities_master' => '1', 'd5b_abilities_cat_form' => '1', 'd5b_abilities_cat' => array_diff_key( array_fill_keys( array_keys( d5b_ability_categories() ), '1' ), array( 'settings' => 1 ) ), 'd5b_risky' => array( 'settings' => '1', 'health' => '1' ) ) );
run_save(); $a = d5b_abilities_switches()['categories'];
check( 'category box unticked while the risky box stays ticked: on false, risky_on kept true, but risky reads off', false === $a['settings']['on'] && true === $a['settings']['risky_on'] && false === d5b_abilities_risky_on( 'settings' ) );
// only the risky box changes
post_abilities( array( 'd5b_abilities_master' => '1', 'd5b_abilities_cat_form' => '1', 'd5b_abilities_cat' => array_fill_keys( array_keys( d5b_ability_categories() ), '1' ), 'd5b_risky' => array( 'health' => '1' ) ) );
run_save(); $a = d5b_abilities_switches()['categories'];
check( 'category box ticked again and the settings risky box unticked: on true, risky_on false; health untouched', true === $a['settings']['on'] && false === $a['settings']['risky_on'] && true === $a['health']['risky_on'] );
// no marker: nothing about categories or risky changes
$before = stored();
post_abilities( array( 'd5b_abilities_master' => '1', 'd5b_risky' => array( 'settings' => '1', 'templates' => '1', 'appearance' => '1' ) ) );
run_save();
check( 'without the marker the risky boxes are ignored (the stored switches are byte for byte unchanged)', $before === stored() );
// a risky box for a category with no risky actions is never stored
post_abilities( array( 'd5b_abilities_master' => '1', 'd5b_abilities_cat_form' => '1', 'd5b_abilities_cat' => array_fill_keys( array_keys( d5b_ability_categories() ), '1' ), 'd5b_risky' => array( 'posts' => '1', 'nonsense' => '1', 'health' => '1' ) ) );
run_save(); $a = d5b_abilities_switches()['categories'];
check( 'a posted risky box for posts or an unknown slug is never stored', false === $a['posts']['risky_on'] && ! isset( $a['nonsense'] ) );
// a risky box unticked while the form is saved with all unticked
post_abilities( array( 'd5b_abilities_master' => '1', 'd5b_abilities_cat_form' => '1', 'd5b_abilities_cat' => array_fill_keys( array_keys( d5b_ability_categories() ), '1' ) ) );
run_save(); $a = d5b_abilities_switches()['categories'];
check( 'the marker with no risky field at all saves every risky_on as false', false === $a['health']['risky_on'] && false === $a['settings']['risky_on'] );
// without the capability
fresh(); t_set_caps( array() ); $GLOBALS['T_user_login'] = 'eve'; d5b_abilities_set_master( true, 'doug' ); $before = stored();
post_abilities( array( 'd5b_abilities_master' => '1', 'd5b_abilities_cat_form' => '1', 'd5b_abilities_cat' => array_fill_keys( array_keys( d5b_ability_categories() ), '1' ), 'd5b_risky' => array( 'health' => '1' ) ) );
run_save();
check( 'without manage_options a risky box changes nothing', $before === stored() );

echo "the upgrade steps\n";
$notice = function () { return is_multisite() ? get_site_option( 'd5b_abilities_upgrade_notice', null ) : get_option( 'd5b_abilities_upgrade_notice', null ); };
$text23 = 'divi5-builder 2.3.0 adds AI abilities that can change site settings, theme settings, widgets and caches, and risky actions behind their own switches. The AI abilities master switch was turned off during the update; review the switches and turn it on again when you are ready.';
fresh(); t_set_caps( array( 'manage_options' ) ); d5b_abilities_set_master( true, 'ann' ); d5b_abilities_upgrade_consent( '2', '3' );
$m = d5b_abilities_switches()['master'];
check( "'2' -> '3', master on: master off, changed_by 0 with a 2.3.0 reason, the notice set", false === $m['on'] && '0' === $m['changed_by'] && false !== strpos( $m['reason'], '2.3.0' ) && 2 === $notice(), $m );
check( '... the notice text is the agreed 2.3.0 text, and the settings page shows it', $text23 === d5b_abilities_upgrade_notice_text() && $text23 === d5b_abilities_upgrade_notice_text( 2 ) && false !== strpos( html( 'd5b_settings_render' ), esc_html( $text23 ) ) );
check( '... the 2.2.0 text is still what version 1 gives', 0 === strpos( d5b_abilities_upgrade_notice_text( 1 ), 'divi5-builder 2.2.0 adds' ) );
fresh(); d5b_abilities_set_master( false, 'ann' ); d5b_abilities_upgrade_consent( '2', '3' );
check( "'2' -> '3', master off: nothing (the master row untouched, no notice)", 'ann' === d5b_abilities_switches()['master']['changed_by'] && null === $notice() );
fresh(); d5b_abilities_set_master( true, 'ann' ); d5b_abilities_upgrade_consent( '3', '3' ); d5b_abilities_upgrade_consent( '0', '3' ); d5b_abilities_upgrade_consent( '2', '2' );
check( "'3' already (or a move that is not an upgrade): nothing", true === d5b_abilities_master_on() && null === $notice() );
fresh(); d5b_abilities_set_master( true, 'ann' ); d5b_abilities_upgrade_consent( '1', '3' );
check( "'1' -> '3' (2.2.0 skipped): the 2.3.0 step once, with the 2.3.0 text", false === d5b_abilities_master_on() && 2 === $notice() && false !== strpos( d5b_abilities_switches()['master']['reason'], '2.3.0' ) );
fresh(); d5b_abilities_set_master( true, 'ann' ); d5b_abilities_upgrade_consent( '1', '2' );
check( "the old step still works: '1' -> '2' gives the 2.2.0 reason and notice 1", false === d5b_abilities_master_on() && 1 === $notice() && false !== strpos( d5b_abilities_switches()['master']['reason'], '2.2.0' ) && 0 === strpos( d5b_abilities_upgrade_notice_text(), 'divi5-builder 2.2.0 adds' ) );
fresh(); d5b_abilities_set_master( true, 'ann' ); d5b_abilities_upgrade_consent();
check( 'the call with no arguments is still the 2.2.0 step', false === d5b_abilities_master_on() && 1 === $notice() );
// multisite: once per network
fresh(); t_set_multisite( true ); d5b_abilities_set_master( true, 'ann' );
d5b_abilities_upgrade_consent( '2', '3' );
$first = false === d5b_abilities_master_on() && 2 === $notice() && 1 === get_site_option( 'd5b_abilities_consent_230', null );
d5b_abilities_set_master( true, 'netadmin' ); delete_site_option( 'd5b_abilities_upgrade_notice' );
d5b_abilities_upgrade_consent( '2', '3' );
check( 'multisite: the first site turns the master off and records d5b_abilities_consent_230; the next site leaves a master switched on again alone, no new notice', $first && true === d5b_abilities_master_on() && null === $notice() );
fresh(); t_set_multisite( true ); d5b_abilities_set_master( true, 'ann' ); update_site_option( 'd5b_abilities_consent_220', 1 );
d5b_abilities_upgrade_consent( '1', '3' );
check( 'multisite: a 2.2.0 consent already given does not stop the 2.3.0 step (its own option)', false === d5b_abilities_master_on() && 1 === get_site_option( 'd5b_abilities_consent_230', null ) );

echo "the shipped definitions\n";
foreach ( array( 'blocks-text-fields', 'blocks', 'abilities/secrets', 'abilities/audit', 'abilities/scrub', 'abilities/history', 'abilities/confirm',
	'abilities/read-content', 'abilities/read-structure', 'abilities/read-site', 'abilities/read-divi', 'abilities/read-history',
	'abilities/write-common', 'abilities/write-posts', 'abilities/write-comments', 'abilities/write-media', 'abilities/write-terms', 'abilities/write-menus', 'abilities/write-divi', 'abilities/write-settings', 'abilities/write-appearance', 'abilities/write-widgets', 'abilities/write-site' ) as $f ) { require_once $dir . '/inc/' . $f . '.php'; }
$risky = array(); foreach ( d5b_ability_defs() as $name => $d ) { if ( array_key_exists( 'risky', $d ) ) { $risky[] = $name; } }
check( 'the shipped risky definitions so far: settings-set-homepage, the three appearance ones and cron-run (settings-set is normal; it refuses risky keys itself)', array( 'divi5-builder/settings-set-homepage', 'divi5-builder/appearance-theme-mod-set', 'divi5-builder/appearance-theme-mod-delete', 'divi5-builder/appearance-custom-css-set', 'divi5-builder/cron-run' ) === $risky && true === d5b_ability_defs()['divi5-builder/settings-set-homepage']['risky'] && count( d5b_ability_defs() ) > 50, $risky );

echo "only the switch files write the switches\n";
$allow = array( 'inc/switches.php', 'inc/settings.php' );
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
$scanned = 0; $bad = array();
foreach ( $it as $file ) {
	if ( 'php' !== $file->getExtension() ) { continue; }
	$rel = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) ) ), '/' );
	$scanned++;
	if ( in_array( $rel, $allow, true ) ) { continue; }
	$src = file_get_contents( $file->getPathname() );
	if ( 'uninstall.php' === $rel ) {
		if ( preg_match( '/\b(update|add)_(site_|blog_|network_)?option\s*\(/', $src ) ) { $bad[] = "$rel writes an option"; }
		continue;
	}
	$rest = str_replace( array( 'd5b_switches(', 'd5b_switch_is_on(' ), '', $src );
	if ( false !== strpos( $rest, 'd5b_switches' ) ) { $bad[] = "$rel names the option d5b_switches"; }
	if ( false !== strpos( $src, 'd5b_switch_set' ) || false !== strpos( $src, 'd5b_abilities_set_' ) ) { $bad[] = "$rel mentions a switch setter"; }
	if ( preg_match( '/risky_on[\'"]\s*\]?\s*=[^=>]/', $src ) ) { $bad[] = "$rel assigns risky_on"; }
}
check( 'static scan: no file outside the switch files names the option or calls a setter (risky_on included)', $scanned >= 8 && array() === $bad, $bad );
check( 'uninstall removes the new consent option', false !== strpos( file_get_contents( $dir . '/uninstall.php' ), 'd5b_abilities_consent_230' ) );

echo "\n" . ( $n - $failed ) . " of $n passed\n";
exit( $failed ? 1 : 0 );
