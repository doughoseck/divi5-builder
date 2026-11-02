<?php
/**
 * Abilities for themes (#32, release 2.4.0): themes-status (a read), themes-install, themes-switch, themes-update,
 * themes-rollback, themes-delete-preview and themes-delete (risky, in the themes category).
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here. It uses the Updates part's engine (wpsu_status,
 * wpsu_update), the maintenance helpers of maint-common.php and the capability, source and status helpers of
 * write-plugins.php (d5b_wplug_ok, d5b_wplug_can, d5b_wplug_source_ok, d5b_wplug_msg, d5b_wplug_safe_status).
 *
 * Rules (design 2026-11-02, "Abilities > Themes", "Keeping a copy" and guardrails 21, 22, 23):
 *  - A theme is named by its stylesheet: the folder name exactly as WordPress lists it (search_theme_directories). Another
 *    case of the same name is not that theme (on a case-insensitive disk it would reach the same folder and slip past the
 *    "in use" check), so a name not in the list is not_found.
 *  - themes-status reads the stored update list (wpsu_status( false )): never clears or asks anything. It leaves out the
 *    plugin list (a themes read needs update_themes only) and adds kept_copies: the theme rows with a kept copy.
 *  - themes-install takes a wordpress.org slug only; the download link must be the one themes_api returned, on
 *    https://downloads.wordpress.org/ (bad_source before the upgrader). WordPress's theme upgrader also fetches a missing
 *    parent theme from themes_api: while the install runs, every download goes through the same check (upgrader_pre_download),
 *    so a parent link elsewhere is not downloaded (the result says the parent is missing). Never switches.
 *  - themes-switch: the theme must be listed, allowed on this site (multisite), not broken (its parent there) and meet its
 *    requirements (core's switch_theme would end the request with wp_die). The page checks run around switch_theme(); when
 *    the pages got worse the previous theme is switched back (reverted: true). A switch that throws is switch_failed, with
 *    the previous theme back and no after round. The history row is written first: before { stylesheet, template }.
 *  - themes-update / themes-rollback: d5b_maint_update_sequence / d5b_maint_restore with kind theme, as for plugins.
 *  - themes-delete needs the preview's code (bound to stylesheet|version|without_copy). The active theme and its parent are
 *    refused (in_use), and on a network a theme enabled for the whole network (other sites may use it; core's network screen
 *    offers no delete for it either). The row and the copy first, then delete_theme(), which also removes the theme's
 *    translation files (not copied, not put back).
 *  - Install, update, rollback and delete need a network administrator on a network (the files are shared by every site);
 *    switch works per site with switch_themes. Every switch, update, rollback and delete answer carries pages and human_check.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wthm_load' ) ) {

	/** Core's admin functions (themes_api, delete_theme, the upgrader) when they are not loaded yet. */
	function d5b_wthm_load(): void {
		if ( function_exists( 'wpsu_load_admin' ) ) { wpsu_load_admin(); }
		if ( ! function_exists( 'themes_api' ) && is_file( ABSPATH . 'wp-admin/includes/theme.php' ) ) { require_once ABSPATH . 'wp-admin/includes/theme.php'; }
		if ( ! class_exists( 'Theme_Upgrader' ) && is_file( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; }
	}

	/** A theme folder name: letters, numbers, . _ -, not starting with a dot, no '..', at most 100 characters. */
	function d5b_wthm_name_ok( string $s ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_\-][A-Za-z0-9._\-]{0,99}$/D', $s ) && false === strpos( $s, '..' );
	}

	/** The installed themes as WordPress lists them (broken ones included): stylesheet => WP_Theme. */
	function d5b_wthm_all(): array {
		return function_exists( 'wp_get_themes' ) ? (array) wp_get_themes( array( 'errors' => null ) ) : array();
	}

	/** The active theme and its parent (the same name twice for a theme without a parent). */
	function d5b_wthm_in_use(): array {
		return array_values( array_unique( array( (string) get_stylesheet(), (string) get_template() ) ) );
	}

	/** Whether $s is the active theme or its parent, in any case (a case-insensitive disk). */
	function d5b_wthm_is_in_use( string $s ): bool {
		foreach ( d5b_wthm_in_use() as $u ) { if ( 0 === strcasecmp( $u, $s ) ) { return true; } }
		return false;
	}

	/**
	 * The theme rows with a kept copy: the update, rollback and delete rows of object_type theme whose kept is recorded (not
	 * null), newest first: the stylesheet and the version kept (the row's before), the row id and when. Never the copy's path.
	 */
	function d5b_wthm_kept_copies(): array {
		$out = array();
		if ( ! function_exists( 'd5b_history_list' ) ) { return $out; }
		foreach ( d5b_history_list( array( 'object_type' => 'theme' ), 500 ) as $r ) {
			if ( ! in_array( (string) ( $r['action'] ?? '' ), array( 'update', 'rollback', 'delete' ), true ) ) { continue; }
			$row = d5b_history_get( (int) $r['id'] );
			if ( null === $row ) { continue; }
			$kept = $row['after']['kept'] ?? ( $row['before']['kept'] ?? null );
			if ( ! is_array( $kept ) ) { continue; }
			$s = (string) ( $row['before']['stylesheet'] ?? ( $row['after']['stylesheet'] ?? '' ) );
			if ( '' === $s ) { continue; }
			$out[] = array( 'stylesheet' => $s, 'version' => (string) ( $row['before']['version'] ?? '' ), 'history_id' => (int) $row['id'], 'kept_at' => d5b_wc_iso( (string) $row['created_at'] ) );
		}
		return $out;
	}

	/** Runs switch_theme( $s ) with anything printed swallowed. @return Throwable|null what it threw */
	function d5b_wthm_switch( string $s ): ?Throwable {
		$level = ob_get_level();
		ob_start(); // a theme's switch hook may print
		try {
			switch_theme( $s );
			return null;
		} catch ( Throwable $e ) {
			return $e;
		} finally {
			while ( ob_get_level() > $level ) { ob_end_clean(); }
		}
	}

	// ---- themes-status (read)

	function d5b_wthm_h_status( array $in ) {
		if ( ! d5b_wplug_ok( 'update_themes' ) ) { return new WP_Error( 'forbidden', 'You do not have permission to read the update list.' ); }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! function_exists( 'wpsu_status' ) ) { return new WP_Error( 'unavailable', 'The update code is not loaded on this site.' ); }
		$s = d5b_wplug_safe_status( (array) wpsu_status( false ) ); // the stored list: nothing cleared, nothing asked
		unset( $s['plugins'] );
		$s['kept_copies'] = d5b_wthm_kept_copies();
		return $s;
	}

	// ---- themes-install

	function d5b_wthm_h_install( array $in ) {
		if ( ! d5b_wplug_ok( 'install_themes', true ) ) { return new WP_Error( 'forbidden', 'You do not have permission to install themes.' ); }
		$a = d5b_rc_args( $in, array( 'slug' => 'key' ), array( 'slug' ) ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! d5b_abilities_risky_on( 'themes' ) ) { return new WP_Error( 'not_allowed', 'Installing a theme is a risky action and the Themes risky switch is off.' ); }
		$slug = (string) $a['slug'];
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_\-]{0,99}$/D', $slug ) ) {
			return new WP_Error( 'invalid_input', 'slug must be the theme\'s wordpress.org slug (lower-case letters, numbers, - and _), as in wordpress.org/themes/<slug>/.' );
		}
		$blocked = function_exists( 'wpsu_site_blocked' ) ? (string) wpsu_site_blocked() : '';
		if ( '' !== $blocked ) { return new WP_Error( 'not_allowed', 'This site refuses theme installs: ' . $blocked . '.' ); }
		d5b_wthm_load();
		if ( ! function_exists( 'wp_get_themes' ) || ! function_exists( 'themes_api' ) || ! class_exists( 'Theme_Upgrader' ) ) {
			return new WP_Error( 'unavailable', 'The installer of WordPress is not loaded on this site, so nothing was installed.' );
		}
		$root = d5b_maint_parent( 'theme', $slug );
		foreach ( array_keys( d5b_wthm_all() ) as $have ) {
			if ( 0 === strcasecmp( (string) $have, $slug ) ) { return new WP_Error( 'exists', 'A theme "' . $have . '" is already installed; install never replaces one. Use themes-update for a newer version.', array( 'stylesheet' => (string) $have ) ); }
		}
		if ( '' !== $root && file_exists( $root . '/' . $slug ) ) {
			return new WP_Error( 'exists', 'A folder "' . $slug . '" is already in the themes folder; install never replaces one.' );
		}

		$info = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false, 'tags' => false, 'screenshot_url' => false, 'reviews' => false ) ) );
		if ( is_wp_error( $info ) ) {
			$m = d5b_wplug_msg( $info );
			if ( 1 === preg_match( '/not found/i', $m ) ) { return new WP_Error( 'not_found', 'wordpress.org has no theme "' . $slug . '".' ); }
			return new WP_Error( 'unavailable', 'wordpress.org could not be asked about "' . $slug . '": ' . $m );
		}
		$link = is_object( $info ) ? ( $info->download_link ?? null ) : ( is_array( $info ) ? ( $info['download_link'] ?? null ) : null );
		// Guardrail 21: the link as themes_api returned it, on downloads.wordpress.org over https, checked BEFORE the upgrader.
		if ( ! d5b_wplug_source_ok( $link ) ) {
			return new WP_Error( 'bad_source', 'The download link given for "' . $slug . '" is not on https://downloads.wordpress.org/, so nothing was installed. Only wordpress.org themes can be installed here; upload other zips on the Themes screen.' );
		}

		$hid = d5b_wc_history_row( 'divi5-builder/themes-install', 'theme', 0, 'install', null, array( 'slug' => $slug, 'installing' => true ), 'Installed theme ' . $slug );
		if ( $hid < 1 ) { return new WP_Error( 'history_unavailable', 'The change history row could not be written, so the theme was not installed.' ); }
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 600 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors -- as the Updates part
		// Every download of this install (the theme, and a parent theme the upgrader fetches by itself) must be on wordpress.org.
		$guard = function ( $reply, $package = '' ) {
			if ( ! d5b_wplug_source_ok( $package ) ) { return new WP_Error( 'bad_source', 'A download that is not on https://downloads.wordpress.org/ was refused.' ); }
			return $reply;
		};
		add_filter( 'upgrader_pre_download', $guard, PHP_INT_MAX, 2 );
		$level = ob_get_level();
		$up    = null;
		ob_start(); // the upgrader skin may print; that would break the JSON answer
		try {
			$up  = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
			$res = $up->install( $link );
		} catch ( Throwable $e ) {
			$res = new WP_Error( 'internal_error', 'the installer stopped with an error (' . get_class( $e ) . ')' );
		} finally {
			while ( ob_get_level() > $level ) { ob_end_clean(); }
			remove_filter( 'upgrader_pre_download', $guard, PHP_INT_MAX );
		}
		if ( true !== $res ) {
			$why = is_wp_error( $res ) ? d5b_wplug_msg( $res ) : 'the installer reported a failure';
			d5b_history_set_after( $hid, array( 'slug' => $slug, 'failed' => true ) );
			return new WP_Error( 'install_failed', 'The theme "' . $slug . '" was not installed: ' . $why );
		}
		if ( function_exists( 'wp_clean_themes_cache' ) ) { wp_clean_themes_cache( false ); }
		$theme = is_object( $up ) && method_exists( $up, 'theme_info' ) ? $up->theme_info() : false;
		$s     = is_object( $theme ) ? (string) $theme->get_stylesheet() : $slug;
		$out   = array( 'installed' => true, 'slug' => $slug, 'stylesheet' => $s, 'version' => d5b_maint_version( 'theme', $s ), 'active' => false );
		$warn  = array();
		$t     = wp_get_theme( $s );
		if ( $t->exists() && $t->parent() ) {
			$out['parent']           = (string) $t->get_template();
			$out['parent_installed'] = $t->parent()->exists() && ! $t->parent()->errors();
			if ( ! $out['parent_installed'] ) { $warn[] = 'This is a child theme and its parent theme "' . $out['parent'] . '" is not installed (wordpress.org did not give it, or not from https://downloads.wordpress.org/): install the parent before switching to it.'; }
		}
		if ( ! d5b_history_set_after( $hid, array( 'stylesheet' => $s, 'version' => $out['version'] ) ) ) {
			$warn[] = 'The change history row could not be completed; it still shows the install as running.';
		}
		if ( $warn ) { $out['warnings'] = $warn; }
		return $out;
	}

	// ---- themes-switch

	function d5b_wthm_h_switch( array $in ) {
		if ( ! d5b_wplug_ok( 'switch_themes' ) ) { return new WP_Error( 'forbidden', 'You do not have permission to switch themes.' ); }
		$a = d5b_rc_args( $in, array( 'stylesheet' => 'key' ), array( 'stylesheet' ) ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! d5b_abilities_risky_on( 'themes' ) ) { return new WP_Error( 'not_allowed', 'Switching the theme is a risky action and the Themes risky switch is off.' ); }
		$s = (string) $a['stylesheet'];
		if ( ! d5b_wthm_name_ok( $s ) ) { return new WP_Error( 'invalid_input', 'stylesheet must be the theme folder as themes-status lists it (item).' ); }
		d5b_wthm_load();
		if ( ! function_exists( 'switch_theme' ) || ! function_exists( 'wp_get_themes' ) ) { return new WP_Error( 'unavailable', 'The theme functions of WordPress are not loaded.' ); }
		$all = d5b_wthm_all();
		if ( ! isset( $all[ $s ] ) ) { return new WP_Error( 'not_found', 'No installed theme "' . $s . '". themes-status lists them.' ); }
		$theme = $all[ $s ];
		if ( is_multisite() && ! $theme->is_allowed() ) {
			return new WP_Error( 'not_allowed', '"' . $s . '" is not enabled for this site; a network administrator enables it on the network Themes screen.' );
		}
		$broken = $theme->errors();
		if ( is_wp_error( $broken ) ) { return new WP_Error( 'not_allowed', '"' . $s . '" is broken: ' . d5b_wplug_msg( $broken ) ); }
		$req = function_exists( 'validate_theme_requirements' ) ? validate_theme_requirements( $s ) : true;
		if ( is_wp_error( $req ) ) { return new WP_Error( 'not_allowed', '"' . $s . '" cannot be switched to: ' . d5b_wplug_msg( $req ) ); }
		if ( get_stylesheet() === $s ) {
			return array( 'stylesheet' => $s, 'template' => (string) get_template(), 'active' => true, 'already_active' => true, 'reverted' => false, 'problems' => array(), 'pages' => null, 'human_check' => d5b_human_check() );
		}
		$prev = array( 'stylesheet' => (string) get_stylesheet(), 'template' => (string) get_template() );
		$hid  = d5b_wc_history_row( 'divi5-builder/themes-switch', 'theme', 0, 'switch', $prev, array( 'running' => true ), 'Switched the theme from ' . $prev['stylesheet'] . ' to ' . $s );
		if ( $hid < 1 ) { return new WP_Error( 'history_unavailable', 'The change history row could not be written, so the theme was not switched.' ); }

		// The switch inside the page checks. A switch that throws stops the check before its after round.
		$err = null;
		try {
			$pages = d5b_pages_check( function () use ( $s, &$err ) {
				$err = d5b_wthm_switch( $s );
				if ( null !== $err ) { throw new RuntimeException( 'd5b_switch_failed' ); }
			} );
		} catch ( RuntimeException $x ) {
			if ( null === $err ) { throw $x; }
		}

		$back_err = null; $reverted = false;
		if ( null !== $err ) {
			if ( get_stylesheet() !== $prev['stylesheet'] || get_template() !== $prev['template'] ) { $back_err = d5b_wthm_switch( $prev['stylesheet'] ); $reverted = true; }
			$now = array( 'stylesheet' => (string) get_stylesheet(), 'template' => (string) get_template() );
			$ok  = $now === $prev;
			d5b_history_set_after( $hid, $now + array( 'failed' => 'switch_failed', 'reverted' => $reverted ) );
			return new WP_Error( 'switch_failed', 'Switching to "' . $s . '" stopped with an error (' . get_class( $err ) . ').'
				. ( $reverted ? ( $ok ? ' The previous theme "' . $prev['stylesheet'] . '" is active again.' : ' The previous theme could not be switched back: switch to "' . $prev['stylesheet'] . '" on the Themes screen at once.' ) : ' The theme was not switched.' ),
				array( 'stylesheet' => $s, 'previous' => $prev, 'reverted' => $reverted, 'active' => get_stylesheet() === $s, 'pages' => null, 'human_check' => d5b_human_check() ) );
		}

		$problems = (array) $pages['problems'];
		if ( array() !== $problems ) {
			$back_err = d5b_wthm_switch( $prev['stylesheet'] ); // guardrail 22: a checked switch that made the pages worse is undone
			$reverted = true;
		}
		$now  = array( 'stylesheet' => (string) get_stylesheet(), 'template' => (string) get_template() );
		$out  = array( 'stylesheet' => $now['stylesheet'], 'template' => $now['template'], 'previous' => $prev, 'active' => $now['stylesheet'] === $s, 'reverted' => $reverted, 'problems' => $problems, 'pages' => $pages, 'human_check' => d5b_human_check() );
		$warn = array();
		if ( $reverted && $now !== $prev ) { $warn[] = 'The pages got worse but the previous theme "' . $prev['stylesheet'] . '" could not be switched back' . ( null !== $back_err ? ' (an error stopped it)' : '' ) . ': switch to it on the Themes screen.'; }
		if ( ! $reverted && $now['stylesheet'] !== $s ) { $warn[] = 'WordPress reported no error, but "' . $s . '" is not the active theme.'; }
		if ( ! empty( $pages['unavailable'] ) ) { $warn[] = 'The site could not fetch its own pages before the change, so there was no page check: only the human check can tell.'; }
		if ( ! d5b_history_set_after( $hid, $now + array( 'pages' => $pages, 'reverted' => $reverted ) ) ) { $warn[] = 'The change history row could not be completed.'; }
		if ( $warn ) { $out['warnings'] = $warn; }
		return $out;
	}

	// ---- themes-update / themes-rollback / themes-delete-preview / themes-delete

	/**
	 * The common start of update, rollback and delete: the capability (a network administrator on a network: the files are
	 * shared by every site), the input, the risky switch, the stylesheet. $installed: the theme must be listed (update,
	 * delete); a rollback can put back a theme that is gone.
	 * @return array|WP_Error the input, with stylesheet checked
	 */
	function d5b_wthm_maint_start( array $in, string $cap, string $verb, array $spec, array $required, bool $installed ) {
		if ( ! d5b_wplug_ok( $cap, true ) ) { return new WP_Error( 'forbidden', 'You do not have permission to ' . $verb . ' themes.' ); }
		$a = d5b_rc_args( $in, $spec, $required ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! d5b_abilities_risky_on( 'themes' ) ) { return new WP_Error( 'not_allowed', 'To ' . $verb . ' a theme is a risky action and the Themes risky switch is off.' ); }
		$s = (string) $a['stylesheet'];
		if ( ! d5b_wthm_name_ok( $s ) ) { return new WP_Error( 'invalid_input', 'stylesheet must be the theme folder as themes-status lists it (item).' ); }
		d5b_wthm_load();
		if ( ! function_exists( 'wp_get_themes' ) ) { return new WP_Error( 'unavailable', 'The theme functions of WordPress are not loaded.' ); }
		if ( $installed && ! isset( d5b_wthm_all()[ $s ] ) ) { return new WP_Error( 'not_found', 'No installed theme "' . $s . '". themes-status lists them.' ); }
		return $a;
	}

	function d5b_wthm_h_update( array $in ) {
		$a = d5b_wthm_maint_start( $in, 'update_themes', 'update', array( 'stylesheet' => 'key', 'rollback_on_failure' => 'bool', 'without_copy' => 'bool' ), array( 'stylesheet' ), true );
		if ( is_wp_error( $a ) ) { return $a; }
		return d5b_maint_update_sequence( 'theme', $a['stylesheet'], $a['stylesheet'], array(
			'rollback_on_failure' => ! empty( $a['rollback_on_failure'] ),
			'without_copy'        => ! empty( $a['without_copy'] ),
		) );
	}

	function d5b_wthm_h_rollback( array $in ) {
		$a = d5b_wthm_maint_start( $in, 'update_themes', 'restore', array( 'stylesheet' => 'key', 'history_id' => 'id', 'force_older' => 'bool' ), array( 'stylesheet', 'history_id' ), false );
		if ( is_wp_error( $a ) ) { return $a; }
		$s = (string) $a['stylesheet']; $hid = (int) $a['history_id'];
		$row = d5b_history_get( $hid );
		if ( null === $row || 'theme' !== $row['object_type'] || ! in_array( $row['action'], array( 'update', 'rollback', 'delete' ), true ) || $s !== (string) ( $row['before']['stylesheet'] ?? '' ) ) {
			return new WP_Error( 'not_found', 'History row ' . $hid . ' is not an update, rollback or delete of the theme "' . $s . '". themes-status lists the kept copies (kept_copies).' );
		}
		$kept = $row['after']['kept'] ?? null;
		if ( ! is_array( $kept ) || ! is_string( $kept['path'] ?? null ) ) {
			return new WP_Error( 'not_found', 'History row ' . $hid . ' kept no copy of "' . $s . '" (it was changed without one), so there is nothing to put back.' );
		}
		if ( ! file_exists( (string) $kept['path'] ) ) {
			return new WP_Error( 'copy_missing', 'The copy kept by history row ' . $hid . ' is no longer there (copies are removed with their row after 30 days).' );
		}
		// Putting back a theme that is not there (deleted) is an install: it needs install_themes as well.
		if ( ! file_exists( d5b_maint_parent( 'theme', $s ) . '/' . $s ) && ! d5b_wplug_ok( 'install_themes', true ) ) {
			return new WP_Error( 'forbidden', '"' . $s . '" is not installed, so putting it back is an install: you do not have permission to install themes.' );
		}
		if ( empty( $a['force_older'] ) ) {
			foreach ( d5b_wthm_kept_copies() as $k ) {
				if ( $s === $k['stylesheet'] && (int) $k['history_id'] > $hid ) {
					return new WP_Error( 'newer_copy_exists', 'A newer copy of "' . $s . '" is kept (history row ' . (int) $k['history_id'] . ', version ' . $k['version'] . '). Roll back to that one, or pass force_older: true to put back the older copy of row ' . $hid . '.', array( 'newer_history_id' => (int) $k['history_id'], 'newer_version' => (string) $k['version'] ) );
				}
			}
		}
		$installed = d5b_maint_version( 'theme', $s );
		$row_after = (string) ( $row['after']['version'] ?? '' );
		$warn = array();
		if ( '' !== $installed && '' !== $row_after && version_compare( $installed, $row_after, '>' ) ) {
			$warn[] = 'Version ' . $installed . ' is installed now, newer than the ' . $row_after . ' history row ' . $hid . ' left: the theme was updated since. Putting back ' . (string) ( $row['before']['version'] ?? '?' ) . ' skips more than one version.';
		}

		$res   = null;
		$pages = d5b_pages_check( function () use ( $s, $hid, &$res ) { $res = d5b_maint_restore( 'theme', $s, $hid ); } );
		if ( is_wp_error( $res ) ) { return $res; }
		$warn = array_merge( $warn, (array) $res['warnings'] );
		$new  = (int) $res['history_id'];

		// The theme must be readable afterwards (a style.css with its version): a copy without it would leave WordPress with
		// a broken theme (the active one falls back to the default theme on the next page). Then the swap is undone at once.
		if ( '' === d5b_maint_version( 'theme', $s ) && is_array( $res['kept'] ) ) {
			$back = d5b_maint_restore( 'theme', $s, $new );
			return new WP_Error( 'restore_mismatch', 'The copy kept by history row ' . $hid . ' does not hold a readable theme "' . $s . '" (no style.css with a version), so '
				. ( is_wp_error( $back ) ? 'WordPress cannot read the theme now and the files could not be put back: ' . d5b_maint_err_text( $back ) . '. Roll back to history row ' . $new . '.' : 'the files that were there are back (history row ' . (int) $back['history_id'] . ').' ),
				array( 'history_id' => $new, 'human_check' => d5b_human_check() ) );
		}
		if ( '' === d5b_maint_version( 'theme', $s ) ) { $warn[] = 'WordPress does not read a version for the theme "' . $s . '" in the files put back.'; }
		$active = d5b_wthm_is_in_use( $s );
		$done   = d5b_history_get( $new );
		$after  = ( null === $done ? array() : (array) $done['after'] );
		$after['pages'] = $pages;
		if ( ! d5b_history_set_after( $new, $after ) ) { $warn[] = 'The change history row could not be completed.'; }
		if ( ! empty( $pages['unavailable'] ) ) { $warn[] = 'The site could not fetch its own pages before the change, so there was no page check: only the human check can tell.'; }
		if ( 'delete' === $row['action'] && is_multisite() ) { $warn[] = 'On a network, deleting the theme also disabled it for the network; putting the files back does not enable it again.'; }
		$out = array(
			'stylesheet' => $s, 'restored_from' => $hid, 'history_id' => $new, 'version' => (string) $res['version'], 'replaced_version' => (string) $res['replaced_version'],
			'kept' => d5b_maint_kept_public( $res['kept'] ), 'active' => $active, 'problems' => (array) $pages['problems'], 'pages' => $pages, 'human_check' => d5b_human_check(),
		);
		if ( $warn ) { $out['warnings'] = $warn; }
		return $out;
	}

	/** The confirmation fingerprint of a delete: the theme, its version and without_copy (a code is for one delete as previewed). */
	function d5b_wthm_delete_fp( string $s, bool $without_copy ): string {
		return $s . '|' . d5b_maint_version( 'theme', $s ) . '|' . ( $without_copy ? '1' : '0' );
	}

	/**
	 * The common start of the delete preview and the delete: everything but the code. The active theme and its parent are
	 * refused (in_use), on a network a theme enabled for the whole network too, and a site that refuses file changes.
	 */
	function d5b_wthm_delete_start( array $in, bool $with_code ) {
		$spec = array( 'stylesheet' => 'key', 'without_copy' => 'bool' ) + ( $with_code ? array( 'confirmation' => 'str' ) : array() );
		$a = d5b_wthm_maint_start( $in, 'delete_themes', 'delete', $spec, array( 'stylesheet' ), true );
		if ( is_wp_error( $a ) ) { return $a; }
		$a['without_copy'] = ! empty( $a['without_copy'] );
		$s = (string) $a['stylesheet'];
		if ( d5b_wthm_is_in_use( $s ) ) {
			return new WP_Error( 'in_use', '"' . $s . '" is ' . ( 0 === strcasecmp( (string) get_stylesheet(), $s ) ? 'the active theme' : 'the parent of the active theme' ) . ', so it is not deleted. Switch to another theme first (themes-switch, which checks the pages).' );
		}
		if ( is_multisite() && class_exists( 'WP_Theme' ) && ! empty( WP_Theme::get_allowed_on_network()[ $s ] ) ) {
			return new WP_Error( 'in_use', '"' . $s . '" is enabled for the whole network, so other sites may use it; it is not deleted. A network administrator disables it on the network Themes screen first.' );
		}
		$blocked = function_exists( 'wpsu_site_blocked' ) ? (string) wpsu_site_blocked() : '';
		if ( '' !== $blocked ) { return new WP_Error( 'not_allowed', 'This site refuses theme deletes: ' . $blocked . '.' ); }
		return $a;
	}

	/** The installed themes whose parent is $s. */
	function d5b_wthm_children( string $s ): array {
		$out = array();
		foreach ( d5b_wthm_all() as $k => $t ) { if ( (string) $k !== $s && $t->get_template() === $s ) { $out[] = (string) $k; } }
		return $out;
	}

	function d5b_wthm_h_delete_preview( array $in ) {
		$a = d5b_wthm_delete_start( $in, false );
		if ( is_wp_error( $a ) ) { return $a; }
		$s = $a['stylesheet']; $nocopy = $a['without_copy'];
		$size = d5b_maint_tree_size( d5b_maint_parent( 'theme', $s ) . '/' . $s );
		$kids = d5b_wthm_children( $s );
		$code = d5b_confirm_issue( 'divi5-builder/themes-delete', 'theme', $s, d5b_wthm_delete_fp( $s, $nocopy ) );
		if ( is_wp_error( $code ) ) { return $code; }
		$note = 'A copy of the files is kept for 30 days: themes-rollback with the history row puts the theme back. WordPress also removes the theme\'s translation files (wp-content/languages/themes); they are not copied and a rollback does not put them back (WordPress fetches them again). The theme\'s settings (theme mods) stay in the database.';
		if ( $kids ) { $note .= ' ' . count( $kids ) . ' installed child theme(s) need it as their parent and will be broken: ' . implode( ', ', $kids ) . '.'; }
		if ( is_multisite() ) { $note .= ' On a network it is also disabled for the network, and a rollback does not enable it again.'; }
		if ( $nocopy ) { $note .= ' without_copy: when no copy can be kept the theme is deleted anyway, and this plugin cannot put it back.'; }
		$t = wp_get_theme( $s );
		return array(
			'stylesheet' => $s, 'name' => (string) $t->get( 'Name' ), 'version' => d5b_maint_version( 'theme', $s ), 'without_copy' => $nocopy,
			'children' => $kids, 'bytes' => $size['bytes'], 'files' => $size['files'], 'note' => $note,
		) + $code;
	}

	function d5b_wthm_h_delete( array $in ) {
		$a = d5b_wthm_delete_start( $in, true );
		if ( is_wp_error( $a ) ) { return $a; }
		$s = $a['stylesheet']; $nocopy = $a['without_copy'];
		$ok = d5b_confirm_check( 'divi5-builder/themes-delete', 'theme', $s, d5b_wthm_delete_fp( $s, $nocopy ), (string) ( $a['confirmation'] ?? '' ) );
		if ( true !== $ok ) { return $ok; }
		$version = d5b_maint_version( 'theme', $s );
		// The row FIRST (its id names the store folder), then the copy; without a whole copy nothing is deleted (unless without_copy).
		$hid = d5b_wc_history_row( 'divi5-builder/themes-delete', 'theme', 0, 'delete', array( 'stylesheet' => $s, 'version' => $version ), array( 'running' => true ), 'Deleted theme ' . $s . ' ' . $version );
		if ( $hid < 1 ) { return new WP_Error( 'history_unavailable', 'The change history row could not be written, so the theme was not deleted.' ); }
		$kept = d5b_keep_copy( 'theme', $s, $hid );
		$copy_error = null;
		if ( is_wp_error( $kept ) ) {
			// without_copy lifts only a copy that is too big, a full store or a failed copy; never "linked".
			if ( ! $nocopy || ! d5b_maint_copy_bypassable( $kept ) ) { d5b_history_delete( $hid ); return $kept; }
			$copy_error = $kept; $kept = null;
		}

		$path  = d5b_maint_parent( 'theme', $s ) . '/' . $s;
		$err   = '';
		$level = ob_get_level();
		ob_start(); // delete_theme prints a credentials form when it has no file system access
		try {
			if ( ! d5b_maint_filesystem() ) {
				$err = 'no file system access'; // delete_theme would ask for FTP details
			} else {
				$r = delete_theme( $s );
				if ( is_wp_error( $r ) ) { $err = d5b_maint_err_text( $r ); }
				elseif ( true !== $r ) { $err = 'WordPress could not reach the files'; }
			}
		} catch ( Throwable $e ) {
			$err = 'the delete stopped with an error (' . get_class( $e ) . ')';
		} finally {
			while ( ob_get_level() > $level ) { ob_end_clean(); }
		}
		if ( function_exists( 'wp_clean_themes_cache' ) ) { wp_clean_themes_cache( false ); }
		clearstatcache();
		$deleted = ! file_exists( $path ) && ! isset( d5b_wthm_all()[ $s ] );
		$warn    = array();
		if ( ! d5b_history_set_after( $hid, array( 'deleted' => $deleted, 'kept' => $kept ) + ( $deleted ? array() : array( 'failed' => '' === $err ? 'the files are still there' : $err ) ) ) ) {
			$warn[] = 'The change history row could not be completed.';
		}
		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', 'The theme "' . $s . '" was not fully deleted: ' . ( '' === $err ? 'some files are still there' : $err ) . '.' . ( null === $kept ? ' No copy of its files was kept (without_copy).' : ' A copy of its files is kept (history row ' . $hid . ').' ),
				array( 'stylesheet' => $s, 'history_id' => $hid ) );
		}
		$out = array(
			'stylesheet' => $s, 'version' => $version, 'deleted' => true, 'kept' => d5b_maint_kept_public( $kept ), 'history_id' => $hid,
			'note' => null === $kept
				? 'No copy of the files was kept, so this plugin cannot put it back; reinstall it to have it again.'
				: 'themes-rollback with history_id ' . $hid . ' puts the files back for 30 days (not its translation files).',
		);
		if ( null !== $copy_error ) {
			$out['copy_error'] = $copy_error->get_error_code();
			$warn[] = 'No copy of the files was kept (' . $copy_error->get_error_code() . ': ' . d5b_maint_err_text( $copy_error ) . '), as without_copy allowed: this plugin cannot put the theme back.';
		}
		if ( $warn ) { $out['warnings'] = $warn; }
		return $out;
	}

	// ---- the definitions

	$d5b_wthm_human = ' Pass the human_check sentence on to the user after the run: the page check compares status, error text and size only, so never report the site as fine.';
	$d5b_wthm_sheet = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'The theme folder (stylesheet) exactly as themes-status lists it (item), for example twentytwentyfour.' );
	$d5b_wthm_nocopy = array( 'type' => 'boolean', 'default' => false, 'description' => 'Delete even when no copy of the files can be kept (then this plugin cannot put it back). The same value on the preview and the delete.' );

	d5b_rc_def( 'themes-status', 'themes', 'Theme updates',
		'Returns the installed themes (active true for the active theme and its parent) with the versions on offer from the stored update list (never asking wordpress.org; updates-check refreshes it), why an offered update cannot run (blocked), WordPress itself, the PHP version and whether file changes are switched off on the site. kept_copies lists the themes with a copy kept by an update, rollback or delete in the last 30 days: stylesheet, version, history_id and kept_at. Refuses callers without update_themes.',
		array(), array(), 'd5b_wthm_h_status', d5b_wplug_can( 'update_themes' ) );

	d5b_wc_def( 'themes-install', 'themes', 'Install a theme from wordpress.org',
		'Installs a theme from wordpress.org by its slug (as in wordpress.org/themes/<slug>/), never switches to it. A risky action: needs the Themes risky switch. Only the download link wordpress.org returns for that slug, on https://downloads.wordpress.org/, is used (bad_source otherwise; nothing is downloaded); a parent theme WordPress fetches for a child theme must come from there too, else it is left out and the result says parent_installed false. A slug that is already installed is refused (exists); not_found when wordpress.org does not know it; install_failed with the reason when the installer fails. Needs install_themes, and on a network a network administrator. Returns installed, slug, stylesheet, version, active (false), and parent / parent_installed for a child theme. One change-history row records the theme and version (undo = delete it).',
		array( 'slug' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'The wordpress.org slug, for example astra.' ) ),
		array( 'slug' ), 'd5b_wthm_h_install', d5b_wplug_can( 'install_themes', true ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	d5b_wc_def( 'themes-switch', 'themes', 'Switch the theme (checked)',
		'Switches this site to an installed theme, with a page check around it (the home page and the recently changed pages, fetched before and after). When the pages got worse the previous theme is switched back at once and the answer says reverted true with the problems. Refused: a theme that is not installed (not_found; the name must be as listed, also in case), not enabled for this site on a network, broken (its parent theme missing) or whose PHP or WordPress requirements are not met (not_allowed). A switch that stops with an error is switch_failed, with the previous theme back. Widgets and menus are remapped by WordPress on the next admin page, as after a switch on the Themes screen. A risky action: needs the Themes risky switch and switch_themes. Returns stylesheet and template (active now), previous { stylesheet, template }, active, reverted, problems, pages and human_check; already_active when it was.' . $d5b_wthm_human . ' One change-history row records the previous stylesheet and template, the theme after, the pages and reverted.',
		array( 'stylesheet' => $d5b_wthm_sheet ), array( 'stylesheet' ), 'd5b_wthm_h_switch', d5b_wplug_can( 'switch_themes' ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	d5b_wc_def( 'themes-update', 'themes', 'Update a theme (checked, with a kept copy)',
		'Updates an installed theme to the version on offer (themes-status shows it; updates-check refreshes the list). First a copy of the theme folder is kept in the change history store for 30 days, then WordPress updates it, with a page check around the update. When the copy cannot be made the update is refused (copy_too_big over 512 MB, store_full when the store would pass 2 GB, copy_failed) unless without_copy is true: then the update runs with kept null and this plugin cannot put the previous version back. A theme that is a link or holds one is always refused (linked). rollback_on_failure (default false): when the pages got worse (or the update failed) the kept copy is put back at once, rolled_back is true and one more page round after the restore is returned as pages_after_restore. By default a worse page is only reported (problems). Nothing on offer: already_up_to_date. A failed update is update_failed with the same fields in its data. A risky action: needs the Themes risky switch and update_themes (on a network, a network administrator). Returns stylesheet, from, to, version, updated, kept (bytes, files), history_id, rolled_back (and rollback_history_id), problems, pages and human_check.' . $d5b_wthm_human . ' One change-history row records the version before, the copy and the pages.',
		array( 'stylesheet' => $d5b_wthm_sheet,
			'rollback_on_failure' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Put the kept copy back at once when the pages got worse after the update.' ),
			'without_copy' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Update even when no copy can be kept (no undo by this plugin). Only for a host without the disk space.' ) ),
		array( 'stylesheet' ), 'd5b_wthm_h_update', d5b_wplug_can( 'update_themes', true ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	d5b_wc_def( 'themes-rollback', 'themes', 'Roll a theme back to a kept copy (checked)',
		'Puts back the copy of a theme kept by an update, rollback or delete, named by its change-history row (history_id; themes-status lists them under kept_copies). The files there now are kept in turn (a rollback is itself undoable), the active theme stays active, and the pages are checked around the swap. Refuses a row of another theme or without a copy (not_found), a copy that is gone (copy_missing), and a row older than the newest kept copy of that theme (newer_copy_exists) unless force_older is true. A copy without a readable style.css is put back out again (restore_mismatch). Also puts back a deleted theme (that is an install, so it needs install_themes too). The swap is made in a staging folder first and switched in with WordPress\'s maintenance mode on for the moment of two renames. A risky action: needs the Themes risky switch and update_themes (on a network, a network administrator). Returns stylesheet, restored_from, history_id (the rollback row), version, replaced_version, kept, active, problems, pages and human_check.' . $d5b_wthm_human,
		array( 'stylesheet' => $d5b_wthm_sheet,
			'history_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The change-history row whose copy is put back (kept_copies in themes-status).' ),
			'force_older' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Put back this copy although a newer copy of the theme is kept.' ) ),
		array( 'stylesheet', 'history_id' ), 'd5b_wthm_h_rollback', d5b_wplug_can( 'update_themes', true ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	d5b_wc_def( 'themes-delete-preview', 'themes', 'Preview a theme delete',
		'Step one of a theme delete. Changes nothing. Returns the theme, its version and size, children (installed child themes that need it as their parent) and a note saying what goes, with a confirmation code valid for 10 minutes for this user, this theme and this without_copy. Show the preview to the user and wait for their explicit OK before calling divi5-builder/themes-delete with the code and the same without_copy. The active theme and its parent are refused (in_use), and on a network a theme enabled for the whole network; a site that refuses file changes gets no code (not_allowed). A risky action: needs the Themes risky switch and delete_themes (on a network, a network administrator).',
		array( 'stylesheet' => $d5b_wthm_sheet, 'without_copy' => $d5b_wthm_nocopy ), array( 'stylesheet' ), 'd5b_wthm_h_delete_preview', d5b_wplug_can( 'delete_themes', true ),
		array( 'readonly' => true ), array( 'risky' => true ) );

	d5b_wc_def( 'themes-delete', 'themes', 'Delete a theme',
		'Step two of a theme delete: only with the confirmation code from themes-delete-preview, by the same user, for the same theme and version and the same without_copy, used once, within 10 minutes (confirmation_invalid with data.reason otherwise; nothing is deleted). The change-history row is written first and a copy of the files is kept for 30 days (refused when it cannot be: copy_too_big, store_full, copy_failed; unless without_copy, then kept is null and this plugin cannot put it back; a theme that is a link or holds one is always refused: linked). Then WordPress deletes the theme and its translation files (not copied, not put back by a rollback). The active theme and its parent are refused (in_use), and so is a site that refuses file changes (not_allowed, before the code is used). A risky action: needs the Themes risky switch and delete_themes (on a network, a network administrator). Returns stylesheet, version, deleted, kept (bytes, files) and history_id: themes-rollback with it puts the files back.',
		array( 'stylesheet' => $d5b_wthm_sheet, 'without_copy' => $d5b_wthm_nocopy, 'confirmation' => array( 'type' => 'string', 'description' => 'The confirmation code from the preview, after the user said OK.' ) ),
		array( 'stylesheet' ), 'd5b_wthm_h_delete', d5b_wplug_can( 'delete_themes', true ),
		array( 'destructive' => true ), array( 'risky' => true ) );

	unset( $d5b_wthm_human, $d5b_wthm_sheet, $d5b_wthm_nocopy );
}
