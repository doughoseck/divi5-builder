<?php
/**
 * Abilities for plugins (#32, release 2.4.0): plugins-status (a read), updates-check (a normal write in the core category),
 * plugins-install, plugins-activate, plugins-deactivate, plugins-update, plugins-rollback, plugins-delete-preview and
 * plugins-delete (risky, in the plugins category).
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here. It uses the Updates part's engine (wpsu_status, always
 * loaded; the Updates switch gates only that part's REST routes) and the maintenance helpers of maint-common.php.
 *
 * Rules (design 2026-11-02, "Abilities > Plugins" and guardrails 18, 21, 22, 24):
 *  - plugins-status reads the stored update list (wpsu_status( false )): it never clears a transient and never asks
 *    wordpress.org. It adds kept_copies: the history rows of object_type plugin whose kept copy is recorded (kept not null).
 *  - updates-check clears the update caches and asks again (wpsu_status( true, true )), at most once per 5 minutes per
 *    site (too_soon). One history row with the counts; nothing to put back.
 *  - plugins-install takes a wordpress.org slug only. The download link must be the one plugins_api returned, on
 *    https://downloads.wordpress.org/ (anything else, a plugins_api filter's link included, is bad_source BEFORE the
 *    upgrader runs). A slug whose folder is installed is refused (exists). Never activates. On a network: a network
 *    administrator only (install_plugins is network-wide).
 *  - plugins-activate runs the page checks around activate_plugin(), not silent (the plugin's own activation routine runs,
 *    as on the Plugins screen): when the pages got worse the plugin is deactivated again (reverted: true), not silent
 *    either, so its deactivation routine cleans up what its activation made (one that throws is caught, and the plugin is
 *    then switched off silently). A WP_Error from activate_plugin, or a plugin that throws while it is loaded, is
 *    activation_failed and there is no after round; a plugin core switched on before complaining (unexpected_output) is
 *    switched off again. plugins-deactivate runs the checks after and is never reverted.
 *  - This plugin (and the old plugins it replaces) is refused for activate and deactivate (self). Network activation and
 *    deactivation need a network administrator; a network-active plugin is not switched per site.
 *  - Each activate / deactivate writes its history row first (before = the active list), and fills in the after (the
 *    active list, the pages, reverted) at the end. Every activate / deactivate answer carries pages and human_check.
 *  - No download link, password, key or token goes into a result or a row.
 *  - plugins-update is d5b_maint_update_sequence (maint-common.php): row first, copy of the folder (or single file), the
 *    update inside the page checks, rollback_on_failure puts the copy back at once. plugins-rollback puts back the copy of
 *    an update, rollback or delete row of that plugin (newer_copy_exists unless force_older), keeping the replaced files in
 *    a new rollback row (d5b_maint_restore); the plugin file must resolve afterwards, else the swap is undone. plugins-delete
 *    needs the preview's code (bound to plugin|version|keep_data), refuses an active plugin, writes the row and the copy
 *    first, then delete_plugins() (which runs the uninstall routine) or, with keep_data, removes the files itself.
 *    Update, rollback and delete need a network administrator on a network (the files are shared by every site).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wplug_load' ) ) {

	// The capability helpers are maint-common.php's d5b_maint_ok / d5b_maint_can, shared with themes and core.

	/** Core's admin functions (get_plugins, activate_plugin, plugins_api, the upgrader) when they are not loaded yet. */
	function d5b_wplug_load(): void {
		if ( function_exists( 'wpsu_load_admin' ) ) { wpsu_load_admin(); }
		if ( ! function_exists( 'plugins_api' ) && is_file( ABSPATH . 'wp-admin/includes/plugin-install.php' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin-install.php'; }
		if ( ! function_exists( 'get_plugins' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
	}

	/** A plugin file as get_plugins() names it: file.php or folder/file.php, no '..', no other path. */
	function d5b_wplug_file_ok( string $f ): bool {
		return 1 === preg_match( '#^[A-Za-z0-9._\- ]{1,100}(/[A-Za-z0-9._\- ]{1,100})?\.php$#D', $f ) && false === strpos( $f, '..' );
	}

	/** The download link plugins_api gave, when it is on https://downloads.wordpress.org/ exactly; else false. */
	function d5b_wplug_source_ok( $link ): bool {
		if ( ! is_string( $link ) || strlen( $link ) > 2048 || 1 === preg_match( '/[\s\x00-\x1F\x7F\\\\]/', $link ) ) { return false; }
		if ( 1 !== preg_match( '#^https://downloads\.wordpress\.org/#', $link ) ) { return false; }
		$p = parse_url( $link );
		return is_array( $p ) && 'https' === ( $p['scheme'] ?? '' ) && 'downloads.wordpress.org' === ( $p['host'] ?? '' ) && ! isset( $p['user'] ) && ! isset( $p['pass'] ) && ! isset( $p['port'] );
	}

	/** The installed plugin file whose folder (or single file) is $slug, a folder of that name without a plugin in it, or null. */
	function d5b_wplug_installed_slug( string $slug ): ?string {
		foreach ( array_keys( (array) get_plugins() ) as $f ) {
			$f    = (string) $f;
			$base = false !== strpos( $f, '/' ) ? substr( $f, 0, (int) strpos( $f, '/' ) ) : substr( $f, 0, -4 );
			if ( strtolower( $base ) === $slug ) { return $f; }
		}
		if ( defined( 'WP_PLUGIN_DIR' ) && ( is_dir( WP_PLUGIN_DIR . '/' . $slug ) || is_file( WP_PLUGIN_DIR . '/' . $slug . '.php' ) ) ) { return $slug . '/'; }
		return null;
	}

	/** The active list of this site, or of the network ($network). */
	function d5b_wplug_active_list( bool $network ): array {
		$l = $network ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array_values( (array) get_option( 'active_plugins', array() ) );
		return array_values( array_map( 'strval', $l ) );
	}

	/** Whether $plugin is in that list. */
	function d5b_wplug_is_active( string $plugin, bool $network ): bool {
		return in_array( $plugin, d5b_wplug_active_list( $network ), true );
	}

	/**
	 * The plugin an activate / deactivate acts on, checked: the file name, never this plugin itself (self), network only on a
	 * network and only for a network administrator, installed, and not network-active when asked per site.
	 * @return array{plugin:string,network:bool}|WP_Error
	 */
	/** The plugin file named in the input, checked as a name only: well formed, and never this plugin itself (self). null when fine. */
	function d5b_wplug_name_error( string $plugin, string $verb ): ?WP_Error {
		// Exactly as listed: no spaces or control characters around it (trim() would also drop a NUL byte).
		if ( trim( $plugin ) !== $plugin || ( ! d5b_wplug_file_ok( ltrim( $plugin, '/' ) ) && ! d5b_maint_self( $plugin ) ) ) {
			return new WP_Error( 'invalid_input', 'plugin must be the plugin file as plugins-status lists it (folder/file.php).' );
		}
		if ( d5b_maint_self( $plugin ) ) {
			return new WP_Error( 'self', 'This is the Divi 5 Builder plugin itself (or one of the plugins it replaces); it never ' . $verb . 's itself through an ability. Use the Plugins screen.' );
		}
		return null;
	}

	function d5b_wplug_target( array $a, string $verb ) {
		$plugin = (string) $a['plugin'];
		$net    = ! empty( $a['network'] );
		$bad    = d5b_wplug_name_error( $plugin, $verb );
		if ( null !== $bad ) { return $bad; }
		if ( $net && ! is_multisite() ) { return new WP_Error( 'invalid_input', 'network is only for a multisite network.' ); }
		if ( $net && ! is_super_admin() ) { return new WP_Error( 'forbidden', 'Only a network administrator can ' . $verb . ' a plugin for the whole network.' ); }
		d5b_wplug_load();
		if ( ! function_exists( 'get_plugins' ) ) { return new WP_Error( 'unavailable', 'The plugin functions of WordPress are not loaded.' ); }
		if ( ! isset( get_plugins()[ $plugin ] ) ) { return new WP_Error( 'not_found', 'No installed plugin "' . $plugin . '". plugins-status lists them.' ); }
		if ( ! $net && is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin ) ) {
			return new WP_Error( 'not_allowed', '"' . $plugin . '" is active for the whole network; a network administrator changes that with network: true.' );
		}
		return array( 'plugin' => $plugin, 'network' => $net );
	}

	/** The plain text of a core error message (some carry HTML), at most 300 characters. */
	function d5b_wplug_msg( WP_Error $e ): string {
		$m = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $e->get_error_message() ) ) );
		return '' === $m ? $e->get_error_code() : d5b_rc_cut( $m, 300 );
	}

	/**
	 * The status as an answer: each plugin and theme row keeps only its known fields (item, name, version, active,
	 * new_version, kind, blocked). An offer's package url can carry a licence key in its query (premium plugins); the engine
	 * does not copy it today and the secret filter does not look inside urls, so it is dropped here by construction.
	 */
	function d5b_wplug_safe_status( array $s ): array {
		$keep = array_flip( array( 'item', 'name', 'version', 'active', 'new_version', 'kind', 'blocked' ) );
		foreach ( array( 'plugins', 'themes' ) as $k ) {
			if ( ! isset( $s[ $k ] ) || ! is_array( $s[ $k ] ) ) { continue; }
			$s[ $k ] = array_values( array_map( function ( $r ) use ( $keep ) { return is_array( $r ) ? array_intersect_key( $r, $keep ) : array(); }, $s[ $k ] ) );
		}
		return $s;
	}

	// ---- plugins-status (read)

	function d5b_wplug_h_status( array $in ) {
		if ( ! d5b_maint_ok( 'update_plugins' ) ) { return new WP_Error( 'forbidden', 'You do not have permission to read the update list.' ); }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! function_exists( 'wpsu_status' ) ) { return new WP_Error( 'unavailable', 'The update code is not loaded on this site.' ); }
		$s = d5b_wplug_safe_status( (array) wpsu_status( false ) ); // the stored list: nothing cleared, nothing asked
		$s['kept_copies'] = d5b_maint_kept_copies( 'plugin' );
		return $s;
	}

	// ---- updates-check

	function d5b_wplug_h_updates_check( array $in ) {
		if ( ! d5b_maint_ok( 'update_plugins' ) ) { return new WP_Error( 'forbidden', 'You do not have permission to check for updates.' ); }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! function_exists( 'wpsu_status' ) ) { return new WP_Error( 'unavailable', 'The update code is not loaded on this site, so nothing was checked.' ); }
		if ( ! d5b_maint_rate( 'updates-check', 1, 300 ) ) {
			return new WP_Error( 'too_soon', 'The update list was refreshed less than 5 minutes ago; plugins-status shows it. Try again later.' );
		}
		$s = d5b_wplug_safe_status( (array) wpsu_status( true, true ) );
		$count = function ( $rows ) { return count( array_filter( (array) $rows, function ( $r ) { return is_array( $r ) && null !== ( $r['new_version'] ?? null ); } ) ); };
		$counts = array(
			'plugins'      => $count( $s['plugins'] ?? array() ),
			'themes'       => $count( $s['themes'] ?? array() ),
			'core'         => null !== ( $s['wordpress']['new_version'] ?? null ) ? 1 : 0,
			'translations' => (int) ( $s['translations'] ?? 0 ),
		);
		$hid = d5b_wc_history_row( 'divi5-builder/updates-check', 'updates', 0, 'check', null, $counts,
			'Checked for updates: ' . $counts['plugins'] . ' plugins, ' . $counts['themes'] . ' themes, ' . ( $counts['core'] ? 'WordPress' : 'no WordPress' ) . ' waiting' );
		if ( $hid < 1 ) { $s['_history_failed'] = true; }
		return $s;
	}

	// ---- plugins-install

	function d5b_wplug_h_install( array $in ) {
		if ( ! d5b_maint_ok( 'install_plugins', true ) ) { return new WP_Error( 'forbidden', 'You do not have permission to install plugins.' ); }
		$a = d5b_rc_args( $in, array( 'slug' => 'key' ), array( 'slug' ) ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! d5b_abilities_risky_on( 'plugins' ) ) { return new WP_Error( 'not_allowed', 'Installing a plugin is a risky action and the Plugins risky switch is off.' ); }
		$slug = (string) $a['slug'];
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_\-]{0,99}$/D', $slug ) ) {
			return new WP_Error( 'invalid_input', 'slug must be the plugin\'s wordpress.org slug (lower-case letters, numbers, - and _), as in wordpress.org/plugins/<slug>/.' );
		}
		$blocked = function_exists( 'wpsu_site_blocked' ) ? (string) wpsu_site_blocked() : '';
		if ( '' !== $blocked ) { return new WP_Error( 'not_allowed', 'This site refuses plugin installs: ' . $blocked . '.' ); }
		d5b_wplug_load();
		if ( ! function_exists( 'get_plugins' ) || ! function_exists( 'plugins_api' ) || ! class_exists( 'Plugin_Upgrader' ) ) {
			return new WP_Error( 'unavailable', 'The installer of WordPress is not loaded on this site, so nothing was installed.' );
		}
		$have = d5b_wplug_installed_slug( $slug );
		if ( null !== $have ) {
			return new WP_Error( 'exists', 'A plugin "' . $slug . '" is already installed (' . $have . '); install never replaces one. Use plugins-update for a newer version.', array( 'plugin' => $have ) );
		}

		$info = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false, 'reviews' => false, 'banners' => false, 'icons' => false, 'screenshots' => false, 'versions' => false ) ) );
		if ( is_wp_error( $info ) ) {
			$m = d5b_wplug_msg( $info );
			if ( 1 === preg_match( '/not found/i', $m ) ) { return new WP_Error( 'not_found', 'wordpress.org has no plugin "' . $slug . '".' ); }
			return new WP_Error( 'unavailable', 'wordpress.org could not be asked about "' . $slug . '": ' . $m );
		}
		$link = is_object( $info ) ? ( $info->download_link ?? null ) : ( is_array( $info ) ? ( $info['download_link'] ?? null ) : null );
		// Guardrail 21: the link as plugins_api returned it, on downloads.wordpress.org over https, checked BEFORE the upgrader.
		if ( ! d5b_wplug_source_ok( $link ) ) {
			return new WP_Error( 'bad_source', 'The download link given for "' . $slug . '" is not on https://downloads.wordpress.org/, so nothing was installed. Only wordpress.org plugins can be installed here; upload other zips on the Plugins screen.' );
		}

		$hid = d5b_wc_history_row( 'divi5-builder/plugins-install', 'plugin', 0, 'install', null, array( 'slug' => $slug, 'installing' => true ), 'Installed plugin ' . $slug );
		if ( $hid < 1 ) { return new WP_Error( 'history_unavailable', 'The change history row could not be written, so the plugin was not installed.' ); }
		if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 600 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors -- as the Updates part
		$level = ob_get_level();
		$up    = null;
		ob_start(); // the upgrader skin may print; that would break the JSON answer
		try {
			$up  = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$res = $up->install( $link );
		} catch ( Throwable $e ) {
			$res = new WP_Error( 'internal_error', 'the installer stopped with an error (' . get_class( $e ) . ')' );
		} finally {
			while ( ob_get_level() > $level ) { ob_end_clean(); }
		}
		if ( true !== $res ) {
			$why = is_wp_error( $res ) ? d5b_wplug_msg( $res ) : 'the installer reported a failure';
			d5b_history_set_after( $hid, array( 'slug' => $slug, 'failed' => true ) );
			return new WP_Error( 'install_failed', 'The plugin "' . $slug . '" was not installed: ' . $why );
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) { wp_clean_plugins_cache( false ); }
		$file = is_object( $up ) && method_exists( $up, 'plugin_info' ) ? $up->plugin_info() : false;
		// What was installed must be the plugin asked for: its folder (the upgrader's destination) equal to the slug. A
		// plugins_api answer changed by another plugin can name another wordpress.org plugin's zip; that one is removed again.
		$dest = is_object( $up ) && is_array( $up->result ?? null ) ? (string) ( $up->result['destination_name'] ?? '' ) : '';
		if ( '' === $dest && is_string( $file ) && '' !== $file ) { $dest = false !== strpos( $file, '/' ) ? (string) strtok( $file, '/' ) : (string) preg_replace( '/\.php$/', '', $file ); }
		if ( $dest !== $slug ) {
			$named   = 1 === preg_match( '/^[A-Za-z0-9._\- ]{1,100}$/D', $dest ) && '.' !== $dest && '..' !== $dest;
			$removed = $named && defined( 'WP_PLUGIN_DIR' ) && d5b_maint_remove_tree( WP_PLUGIN_DIR . '/' . $dest ) && ! file_exists( WP_PLUGIN_DIR . '/' . $dest . '.php' );
			if ( function_exists( 'wp_clean_plugins_cache' ) ) { wp_clean_plugins_cache( false ); }
			d5b_history_set_after( $hid, array( 'slug' => $slug, 'failed' => true, 'installed_instead' => $named ? $dest : '', 'removed' => $removed ) );
			return new WP_Error( 'bad_source', 'The download given for "' . $slug . '" installed ' . ( $named ? '"' . $dest . '"' : 'another plugin' ) . ' instead (a filter of another plugin may have changed the answer), so it '
				. ( $removed ? 'was removed again. Nothing is installed.' : 'could not be removed: delete it on the Plugins screen now. It is not active.' ) );
		}
		if ( ! is_string( $file ) || '' === $file ) { $file = (string) d5b_wplug_installed_slug( $slug ); }
		$all     = (array) get_plugins();
		$version = isset( $all[ $file ]['Version'] ) ? (string) $all[ $file ]['Version'] : '';
		$out     = array( 'installed' => true, 'slug' => $slug, 'plugin' => $file, 'version' => $version, 'active' => false );
		if ( ! d5b_history_set_after( $hid, array( 'plugin' => $file, 'version' => $version ) ) ) {
			$out['warnings'] = array( 'The change history row could not be completed; it still shows the install as running.' );
		}
		return $out;
	}

	// ---- plugins-activate / plugins-deactivate

	/** The common start of activate and deactivate: capability, input, the risky switch, the target. */
	function d5b_wplug_start( array $in, string $verb ) {
		if ( ! d5b_maint_ok( 'activate_plugins' ) ) { return new WP_Error( 'forbidden', 'You do not have permission to ' . $verb . ' plugins.' ); }
		$a = d5b_rc_args( $in, array( 'plugin' => 'key', 'network' => 'bool' ), array( 'plugin' ) ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! d5b_abilities_risky_on( 'plugins' ) ) { return new WP_Error( 'not_allowed', 'To ' . $verb . ' a plugin is a risky action and the Plugins risky switch is off.' ); }
		return d5b_wplug_target( $a, $verb );
	}

	/**
	 * Deactivates $plugin as the Plugins screen does (not silent: its own deactivation routine runs, so what its activation
	 * routine made is cleaned up), with anything it prints swallowed and anything it throws caught. core saves the active list
	 * AFTER the routine, so a routine that throws leaves the plugin active: then it is deactivated again, silently.
	 * @return array{thrown:bool,forced:bool}
	 */
	function d5b_wplug_deactivate( string $plugin, bool $net ): array {
		$thrown = false; $forced = false;
		$run = function ( bool $silent ) use ( $plugin, $net, &$thrown ) {
			$level = ob_get_level();
			ob_start(); // a routine that prints would break the JSON answer
			try {
				deactivate_plugins( $plugin, $silent, $net );
			} catch ( Throwable $e ) {
				$thrown = true;
			} finally {
				while ( ob_get_level() > $level ) { ob_end_clean(); }
			}
		};
		$run( false );
		if ( d5b_wplug_is_active( $plugin, $net ) ) { $run( true ); $forced = true; }
		return array( 'thrown' => $thrown, 'forced' => $forced );
	}

	function d5b_wplug_h_activate( array $in ) {
		$t = d5b_wplug_start( $in, 'activate' );
		if ( is_wp_error( $t ) ) { return $t; }
		$plugin = $t['plugin']; $net = $t['network'];
		if ( ! $net && is_multisite() && function_exists( 'is_network_only_plugin' ) && is_network_only_plugin( $plugin ) ) {
			return new WP_Error( 'not_allowed', '"' . $plugin . '" can only be activated for the whole network (Network: true); a network administrator does that with network: true.' );
		}
		if ( d5b_wplug_is_active( $plugin, $net ) ) {
			return array( 'plugin' => $plugin, 'network' => $net, 'active' => true, 'already_active' => true, 'reverted' => false, 'problems' => array(), 'pages' => null, 'human_check' => d5b_human_check() );
		}
		$before = d5b_wplug_active_list( $net );
		$hid    = d5b_wc_history_row( 'divi5-builder/plugins-activate', 'plugin', 0, 'activate', array( 'plugin' => $plugin, 'network' => $net, 'active' => $before ), array( 'running' => true ), 'Activated plugin ' . $plugin . ( $net ? ' for the network' : '' ) );
		if ( $hid < 1 ) { return new WP_Error( 'history_unavailable', 'The change history row could not be written, so the plugin was not activated.' ); }

		// The activation inside the page checks. A WP_Error (or a plugin that throws while it is loaded) stops the check
		// before its after round: the exception below carries it out of d5b_pages_check.
		$err   = null;
		$level = ob_get_level();
		try {
			$pages = d5b_pages_check( function () use ( $plugin, $net, $level, &$err ) {
				try {
					$r = activate_plugin( $plugin, '', $net, false ); // not silent: the plugin's own activation routine runs, as on the Plugins screen
				} catch ( Throwable $e ) {
					$r = new WP_Error( 'internal_error', 'the plugin stopped with an error while it was loaded (' . get_class( $e ) . ')' );
				} finally {
					while ( ob_get_level() > $level ) { ob_end_clean(); } // core's sandbox buffer, left open by a plugin that throws
				}
				if ( is_wp_error( $r ) ) {
					$err = $r;
					throw new RuntimeException( 'd5b_activation_failed' );
				}
			} );
		} catch ( RuntimeException $x ) {
			if ( null === $err ) { throw $x; }
		}

		if ( null !== $err ) {
			// unexpected_output: core switched the plugin on before it complained. The answer is "failed", so the state is too.
			$reverted = false; $rv = array( 'thrown' => false, 'forced' => false );
			if ( d5b_wplug_is_active( $plugin, $net ) ) { $rv = d5b_wplug_deactivate( $plugin, $net ); $reverted = true; }
			$still = d5b_wplug_is_active( $plugin, $net );
			d5b_history_set_after( $hid, array( 'active' => d5b_wplug_active_list( $net ), 'failed' => $err->get_error_code(), 'reverted' => $reverted, 'deactivation_error' => $rv['thrown'] ) );
			return new WP_Error( 'activation_failed', 'The plugin "' . $plugin . '" could not be activated: ' . d5b_wplug_msg( $err ) . '.'
				. ( $reverted ? ' WordPress had switched it on before it complained, so it was switched off again.' : '' )
				. ( $rv['thrown'] ? ' Its deactivation routine stopped with an error, so it was switched off without it.' : '' )
				. ( $still ? ' It is still active: deactivate it on the Plugins screen.' : '' ),
				array( 'plugin' => $plugin, 'reason' => $err->get_error_code(), 'reverted' => $reverted, 'active' => $still, 'pages' => null, 'human_check' => d5b_human_check() ) );
		}

		$problems = (array) $pages['problems'];
		$reverted = false; $rv = array( 'thrown' => false, 'forced' => false );
		if ( array() !== $problems ) {
			$rv = d5b_wplug_deactivate( $plugin, $net ); // guardrail 22: a checked activation that made the pages worse is undone
			$reverted = true;
		}
		$active = d5b_wplug_is_active( $plugin, $net );
		$out    = array( 'plugin' => $plugin, 'network' => $net, 'active' => $active, 'reverted' => $reverted, 'problems' => $problems, 'pages' => $pages, 'human_check' => d5b_human_check() );
		$warn   = array();
		if ( $rv['thrown'] ) { $warn[] = 'The plugin\'s deactivation routine stopped with an error, so it was switched off without it; what its activation routine made may still be there.'; }
		if ( $reverted && $active ) { $warn[] = 'The pages got worse but the plugin could not be switched off again: deactivate it on the Plugins screen.'; }
		if ( ! $reverted && ! $active ) { $warn[] = 'WordPress reported no error, but the plugin is not active.'; }
		$warn = array_merge( $warn, d5b_pages_warnings( $pages ) );
		if ( ! d5b_history_set_after( $hid, array( 'active' => d5b_wplug_active_list( $net ), 'pages' => $pages, 'reverted' => $reverted ) ) ) { $warn[] = 'The change history row could not be completed.'; }
		if ( $warn ) { $out['warnings'] = $warn; }
		return $out;
	}

	function d5b_wplug_h_deactivate( array $in ) {
		$t = d5b_wplug_start( $in, 'deactivate' );
		if ( is_wp_error( $t ) ) { return $t; }
		$plugin = $t['plugin']; $net = $t['network'];
		if ( ! d5b_wplug_is_active( $plugin, $net ) ) {
			return array( 'plugin' => $plugin, 'network' => $net, 'active' => false, 'already_inactive' => true, 'reverted' => false, 'problems' => array(), 'pages' => null, 'human_check' => d5b_human_check() );
		}
		$before = d5b_wplug_active_list( $net );
		$hid    = d5b_wc_history_row( 'divi5-builder/plugins-deactivate', 'plugin', 0, 'deactivate', array( 'plugin' => $plugin, 'network' => $net, 'active' => $before ), array( 'running' => true ), 'Deactivated plugin ' . $plugin . ( $net ? ' for the network' : '' ) );
		if ( $hid < 1 ) { return new WP_Error( 'history_unavailable', 'The change history row could not be written, so the plugin was not deactivated.' ); }
		$rv    = array( 'thrown' => false, 'forced' => false );
		$pages = d5b_pages_check( function () use ( $plugin, $net, &$rv ) { $rv = d5b_wplug_deactivate( $plugin, $net ); } );
		$active = d5b_wplug_is_active( $plugin, $net );
		$out    = array( 'plugin' => $plugin, 'network' => $net, 'active' => $active, 'reverted' => false, 'problems' => (array) $pages['problems'], 'pages' => $pages, 'human_check' => d5b_human_check() );
		$warn   = array();
		if ( $rv['thrown'] ) { $warn[] = 'The plugin\'s deactivation routine stopped with an error, so it was switched off without it.'; }
		if ( $active ) { $warn[] = 'The plugin is still active.'; }
		$warn = array_merge( $warn, d5b_pages_warnings( $pages ) );
		if ( ! d5b_history_set_after( $hid, array( 'active' => d5b_wplug_active_list( $net ), 'pages' => $pages, 'reverted' => false ) ) ) { $warn[] = 'The change history row could not be completed.'; }
		if ( $warn ) { $out['warnings'] = $warn; }
		return $out;
	}

	// ---- plugins-update / plugins-rollback / plugins-delete-preview / plugins-delete

	/** The folder of a plugin in the plugins folder (folder/file.php), or the file itself for a single-file plugin. */
	function d5b_wplug_dir( string $plugin ): string {
		return false !== strpos( $plugin, '/' ) ? dirname( $plugin ) : $plugin;
	}

	/**
	 * The common start of update, rollback and delete: the capability (and a network administrator on a network: these
	 * change the files every site shares), the input, the risky switch, the plugin name (self refused). $installed: the
	 * plugin must be installed (update, delete); a rollback can put back a plugin that is gone.
	 * @return array|WP_Error the input, with plugin checked
	 */
	function d5b_wplug_maint_start( array $in, string $cap, string $verb, array $spec, array $required, bool $installed ) {
		if ( ! d5b_maint_ok( $cap, true ) ) { return new WP_Error( 'forbidden', 'You do not have permission to ' . $verb . ' plugins.' ); }
		$a = d5b_rc_args( $in, $spec, $required ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! d5b_abilities_risky_on( 'plugins' ) ) { return new WP_Error( 'not_allowed', 'To ' . $verb . ' a plugin is a risky action and the Plugins risky switch is off.' ); }
		if ( $installed ) {
			$t = d5b_wplug_target( array( 'plugin' => (string) $a['plugin'], 'network' => is_multisite() ), $verb );
			if ( is_wp_error( $t ) ) { return $t; }
			$a['plugin'] = $t['plugin'];
			return $a;
		}
		$bad = d5b_wplug_name_error( (string) $a['plugin'], $verb );
		if ( null !== $bad ) { return $bad; }
		d5b_wplug_load();
		if ( ! function_exists( 'get_plugins' ) ) { return new WP_Error( 'unavailable', 'The plugin functions of WordPress are not loaded.' ); }
		return $a;
	}

	function d5b_wplug_h_update( array $in ) {
		$a = d5b_wplug_maint_start( $in, 'update_plugins', 'update', array( 'plugin' => 'key', 'rollback_on_failure' => 'bool', 'without_copy' => 'bool' ), array( 'plugin' ), true );
		if ( is_wp_error( $a ) ) { return $a; }
		return d5b_maint_update_sequence( 'plugin', $a['plugin'], d5b_wplug_dir( $a['plugin'] ), array(
			'rollback_on_failure' => ! empty( $a['rollback_on_failure'] ),
			'without_copy'        => ! empty( $a['without_copy'] ),
		) );
	}

	function d5b_wplug_h_rollback( array $in ) {
		$a = d5b_wplug_maint_start( $in, 'update_plugins', 'restore', array( 'plugin' => 'key', 'history_id' => 'id', 'force_older' => 'bool' ), array( 'plugin', 'history_id' ), false );
		if ( is_wp_error( $a ) ) { return $a; }
		$plugin = (string) $a['plugin']; $hid = (int) $a['history_id']; $dir = d5b_wplug_dir( $plugin );
		$row = d5b_history_get( $hid );
		if ( null === $row || 'plugin' !== $row['object_type'] || ! in_array( $row['action'], array( 'update', 'rollback', 'delete' ), true ) || $plugin !== (string) ( $row['before']['plugin'] ?? '' ) ) {
			return new WP_Error( 'not_found', 'History row ' . $hid . ' is not an update, rollback or delete of "' . $plugin . '". plugins-status lists the kept copies (kept_copies).' );
		}
		$kept = $row['after']['kept'] ?? null;
		if ( ! is_array( $kept ) || ! is_string( $kept['path'] ?? null ) ) {
			return new WP_Error( 'not_found', 'History row ' . $hid . ' kept no copy of "' . $plugin . '" (it was changed without one), so there is nothing to put back.' );
		}
		if ( ! file_exists( (string) $kept['path'] ) ) {
			return new WP_Error( 'copy_missing', 'The copy kept by history row ' . $hid . ' is no longer there (copies are removed with their row after 30 days).' );
		}
		// Putting back a plugin that is not there (deleted) is an install: it needs install_plugins as well.
		if ( ! file_exists( d5b_maint_parent( 'plugin', $dir ) . '/' . $dir ) && ! d5b_maint_ok( 'install_plugins', true ) ) {
			return new WP_Error( 'forbidden', '"' . $plugin . '" is not installed, so putting it back is an install: you do not have permission to install plugins.' );
		}
		if ( empty( $a['force_older'] ) ) {
			foreach ( d5b_maint_kept_copies( 'plugin' ) as $k ) {
				if ( $plugin === $k['plugin'] && (int) $k['history_id'] > $hid ) {
					return new WP_Error( 'newer_copy_exists', 'A newer copy of "' . $plugin . '" is kept (history row ' . (int) $k['history_id'] . ', version ' . $k['version'] . '). Roll back to that one, or pass force_older: true to put back the older copy of row ' . $hid . '.', array( 'newer_history_id' => (int) $k['history_id'], 'newer_version' => (string) $k['version'] ) );
				}
			}
		}
		$installed = d5b_maint_version( 'plugin', $plugin );
		$row_after = (string) ( $row['after']['version'] ?? '' );
		$warn = array();
		if ( '' !== $installed && '' !== $row_after && version_compare( $installed, $row_after, '>' ) ) {
			$warn[] = 'Version ' . $installed . ' is installed now, newer than the ' . $row_after . ' history row ' . $hid . ' left: the plugin was updated since. Putting back ' . (string) ( $row['before']['version'] ?? '?' ) . ' skips more than one version.';
		}

		$res   = null;
		$pages = d5b_pages_check( function () use ( $dir, $hid, &$res ) { $res = d5b_maint_restore( 'plugin', $dir, $hid ); } );
		if ( is_wp_error( $res ) ) { return $res; }
		$warn = array_merge( $warn, (array) $res['warnings'] );
		$new  = (int) $res['history_id'];

		// The plugin file must still be there: a copy whose main file has another name would leave WordPress without the
		// plugin (an active one is switched off on the next admin page). Then the swap is undone at once.
		if ( '' === d5b_maint_version( 'plugin', $plugin ) && is_array( $res['kept'] ) ) {
			$back = d5b_maint_restore( 'plugin', $dir, $new );
			// Once undone, both rows of this rollback are flagged: their copies are not offered as kept copies and do not count
			// as newer than the copy that was asked for. (Not undone: row $new holds the only copy of the files that were live.)
			if ( ! is_wp_error( $back ) ) { d5b_maint_mark_mismatch( array( $new, (int) $back['history_id'] ) ); }
			return new WP_Error( 'restore_mismatch', 'The copy kept by history row ' . $hid . ' does not hold the plugin file "' . $plugin . '" (the plugin may have renamed its main file), so '
				. ( is_wp_error( $back ) ? 'WordPress cannot find the plugin now and the files could not be put back: ' . d5b_maint_err_text( $back ) . '. Roll back to history row ' . $new . '.' : 'the files that were there are back (history row ' . (int) $back['history_id'] . ').' ),
				array( 'history_id' => $new, 'human_check' => d5b_human_check() ) );
		}
		if ( '' === d5b_maint_version( 'plugin', $plugin ) ) {
			$warn[] = 'WordPress does not find the plugin file "' . $plugin . '" in the files put back.';
		}
		$active = function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin );
		$done   = d5b_history_get( $new );
		$after  = ( null === $done ? array() : (array) $done['after'] );
		$after['pages'] = $pages;
		if ( ! d5b_history_set_after( $new, $after ) ) { $warn[] = 'The change history row could not be completed.'; }
		$warn = array_merge( $warn, d5b_pages_warnings( $pages ) );
		$out = array(
			'plugin' => $plugin, 'restored_from' => $hid, 'history_id' => $new, 'version' => (string) $res['version'], 'replaced_version' => (string) $res['replaced_version'],
			'kept' => d5b_maint_kept_public( $res['kept'] ), 'active' => $active, 'problems' => (array) $pages['problems'], 'pages' => $pages, 'human_check' => d5b_human_check(),
		);
		if ( $warn ) { $out['warnings'] = $warn; }
		return $out;
	}

	/** The other plugin files WordPress lists in the same folder as $plugin (a single-file plugin has none), sorted. */
	function d5b_wplug_siblings( string $plugin ): array {
		if ( false === strpos( $plugin, '/' ) || ! function_exists( 'get_plugins' ) ) { return array(); }
		$dir = dirname( $plugin ) . '/';
		$out = array();
		foreach ( array_keys( (array) get_plugins() ) as $f ) {
			$f = (string) $f;
			if ( $f !== $plugin && 0 === strpos( $f, $dir ) ) { $out[] = $f; }
		}
		sort( $out );
		return $out;
	}

	/** Whether deleting $plugin runs an uninstall routine: uninstall.php in its folder, or one registered in uninstall_plugins (as core). */
	function d5b_wplug_has_uninstall( string $plugin ): bool {
		if ( function_exists( 'is_uninstallable_plugin' ) ) { return (bool) is_uninstallable_plugin( $plugin ); }
		$u = (array) get_option( 'uninstall_plugins', array() );
		return isset( $u[ $plugin ] ) || ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/' . dirname( $plugin ) . '/uninstall.php' ) );
	}

	/** The confirmation fingerprint of a delete: the plugin, its version, keep_data and without_copy (a code is for one delete as previewed). */
	function d5b_wplug_delete_fp( string $plugin, bool $keep_data, bool $without_copy ): string {
		return $plugin . '|' . d5b_maint_version( 'plugin', $plugin ) . '|' . ( $keep_data ? '1' : '0' ) . '|' . ( $without_copy ? '1' : '0' );
	}

	/**
	 * The common start of the delete preview and the delete: everything but the code. An active plugin is refused, and so is
	 * a site that refuses file changes (before a code is issued or used).
	 */
	function d5b_wplug_delete_start( array $in, bool $with_code ) {
		$spec = array( 'plugin' => 'key', 'keep_data' => 'bool', 'without_copy' => 'bool' ) + ( $with_code ? array( 'confirmation' => 'str' ) : array() );
		$a = d5b_wplug_maint_start( $in, 'delete_plugins', 'delete', $spec, array( 'plugin' ), true );
		if ( is_wp_error( $a ) ) { return $a; }
		$a['keep_data']    = ! empty( $a['keep_data'] );
		$a['without_copy'] = ! empty( $a['without_copy'] );
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $a['plugin'] ) ) {
			return new WP_Error( 'active', '"' . $a['plugin'] . '" is active. Deactivate it first (plugins-deactivate, which checks the pages), then delete it.' );
		}
		// The delete removes the whole folder: another plugin file in it (two plugins, one folder) goes too.
		foreach ( d5b_wplug_siblings( $a['plugin'] ) as $sib ) {
			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $sib ) ) {
				return new WP_Error( 'active', '"' . $sib . '" is in the same folder and is active; the delete would remove its files too. Deactivate it first (plugins-deactivate), then delete.', array( 'sibling' => $sib ) );
			}
		}
		$blocked = function_exists( 'wpsu_site_blocked' ) ? (string) wpsu_site_blocked() : '';
		if ( '' !== $blocked ) { return new WP_Error( 'not_allowed', 'This site refuses plugin deletes: ' . $blocked . '.' ); }
		return $a;
	}

	function d5b_wplug_h_delete_preview( array $in ) {
		$a = d5b_wplug_delete_start( $in, false );
		if ( is_wp_error( $a ) ) { return $a; }
		$plugin = $a['plugin']; $keep = $a['keep_data']; $nocopy = $a['without_copy'];
		$all  = (array) get_plugins();
		$un   = d5b_wplug_has_uninstall( $plugin );
		$size = d5b_maint_tree_size( d5b_maint_parent( 'plugin', $plugin ) . '/' . d5b_wplug_dir( $plugin ) );
		$code = d5b_confirm_issue( 'divi5-builder/plugins-delete', 'plugin', $plugin, d5b_wplug_delete_fp( $plugin, $keep, $nocopy ) );
		if ( is_wp_error( $code ) ) { return $code; }
		if ( $keep ) {
			$note = 'The files are removed without the plugin\'s uninstall routine (it is not run), so its tables and settings stay in the database. A copy of the files is kept for 30 days: plugins-rollback with the history row puts the plugin back as it was.';
		} elseif ( $un ) {
			$note = 'WordPress runs the plugin\'s uninstall routine, which usually removes its own tables and settings: that data goes for good; only the files can be put back (a copy is kept for 30 days, plugins-rollback). Pass keep_data: true (on the preview and the delete) to keep the data.';
		} else {
			$note = 'The plugin has no uninstall routine, so its data (if any) stays in the database. A copy of the files is kept for 30 days: plugins-rollback with the history row puts the plugin back.';
		}
		$note .= ' Its translation files (wp-content/languages/plugins) are not copied and are not put back by a rollback; WordPress fetches them again.';
		$sibs = d5b_wplug_siblings( $plugin );
		if ( $sibs ) { $note .= ' The folder also holds ' . implode( ', ', $sibs ) . ' (siblings): the delete removes the whole folder, so they go too.'; }
		if ( $nocopy ) { $note .= ' without_copy: when no copy can be kept the plugin is deleted anyway, and this plugin cannot put it back.'; }
		return array(
			'plugin' => $plugin, 'name' => (string) ( $all[ $plugin ]['Name'] ?? $plugin ), 'version' => d5b_maint_version( 'plugin', $plugin ),
			'keep_data' => $keep, 'without_copy' => $nocopy, 'has_uninstall' => $un, 'siblings' => $sibs, 'bytes' => $size['bytes'], 'files' => $size['files'], 'note' => $note,
		) + $code;
	}

	function d5b_wplug_h_delete( array $in ) {
		$a = d5b_wplug_delete_start( $in, true );
		if ( is_wp_error( $a ) ) { return $a; }
		$plugin = $a['plugin']; $keep = $a['keep_data']; $nocopy = $a['without_copy']; $dir = d5b_wplug_dir( $plugin );
		// (the site-blocked check ran in d5b_wplug_delete_start, before the code is used)
		$ok = d5b_confirm_check( 'divi5-builder/plugins-delete', 'plugin', $plugin, d5b_wplug_delete_fp( $plugin, $keep, $nocopy ), (string) ( $a['confirmation'] ?? '' ) );
		if ( true !== $ok ) { return $ok; }
		$version = d5b_maint_version( 'plugin', $plugin );
		$un      = d5b_wplug_has_uninstall( $plugin );
		// The row FIRST (its id names the store folder), then the copy; without a whole copy nothing is deleted (unless without_copy).
		$hid = d5b_wc_history_row( 'divi5-builder/plugins-delete', 'plugin', 0, 'delete',
			array( 'plugin' => $plugin, 'version' => $version, 'keep_data' => $keep, 'has_uninstall' => $un ), array( 'running' => true ),
			'Deleted plugin ' . $plugin . ' ' . $version . ( $keep ? ' (data kept)' : '' ) );
		if ( $hid < 1 ) { return new WP_Error( 'history_unavailable', 'The change history row could not be written, so the plugin was not deleted.' ); }
		$kept = d5b_keep_copy( 'plugin', $dir, $hid );
		$copy_error = null;
		if ( is_wp_error( $kept ) ) {
			// without_copy lifts only a copy that is too big, a full store or a failed copy; never "linked".
			if ( ! $nocopy || ! d5b_maint_copy_bypassable( $kept ) ) { d5b_history_delete( $hid ); return $kept; }
			$copy_error = $kept; $kept = null;
		}

		$path = d5b_maint_parent( 'plugin', $dir ) . '/' . $dir;
		$err  = '';
		$level = ob_get_level();
		ob_start(); // an uninstall routine may print
		try {
			if ( $keep ) {
				// delete_plugins() always runs the uninstall routine: the files are removed here instead, with core's hooks
				// around it and the plugin dropped from the stored update list, as delete_plugins does.
				do_action( 'delete_plugin', $plugin );
				$gone = d5b_maint_remove_tree( $path );
				do_action( 'deleted_plugin', $plugin, $gone );
				if ( ! $gone ) { $err = 'the files could not be removed'; }
				else {
					$current = get_site_transient( 'update_plugins' );
					if ( is_object( $current ) && isset( $current->response ) && is_array( $current->response ) ) { unset( $current->response[ $plugin ] ); set_site_transient( 'update_plugins', $current ); }
				}
			} elseif ( ! d5b_maint_filesystem() ) {
				$err = 'no file system access'; // delete_plugins would ask for FTP details and stop the request
			} else {
				$r = delete_plugins( array( $plugin ) );
				if ( is_wp_error( $r ) ) { $err = d5b_maint_err_text( $r ); }
				elseif ( true !== $r ) { $err = 'WordPress could not reach the files'; }
			}
		} catch ( Throwable $e ) {
			$err = 'the uninstall routine or the delete stopped with an error (' . get_class( $e ) . ')';
		} finally {
			while ( ob_get_level() > $level ) { ob_end_clean(); }
		}
		// core's delete_plugins leaves the plugins cache as it was; the update list keeps the other plugins' offers
		if ( function_exists( 'wp_clean_plugins_cache' ) ) { wp_clean_plugins_cache( false ); }
		clearstatcache();
		$deleted = ! file_exists( $path ) && '' === d5b_maint_version( 'plugin', $plugin );
		$ran     = $un && ! $keep;
		$warn    = array();
		if ( ! d5b_history_set_after( $hid, array( 'deleted' => $deleted, 'kept' => $kept, 'uninstall_ran' => $ran ) + ( $deleted ? array() : array( 'failed' => '' === $err ? 'the files are still there' : $err ) ) ) ) {
			$warn[] = 'The change history row could not be completed.';
		}
		if ( ! $deleted ) {
			return new WP_Error( 'delete_failed', 'The plugin "' . $plugin . '" was not fully deleted: ' . ( '' === $err ? 'some files are still there' : $err ) . '.' . ( null === $kept ? ' No copy of its files was kept (without_copy).' : ' A copy of its files is kept (history row ' . $hid . ').' ) . ( $ran ? ' Its uninstall routine may already have run.' : '' ),
				array( 'plugin' => $plugin, 'history_id' => $hid, 'uninstall_ran' => $ran ) );
		}
		$out = array(
			'plugin' => $plugin, 'version' => $version, 'deleted' => true, 'keep_data' => $keep, 'uninstall_ran' => $ran,
			'kept' => d5b_maint_kept_public( $kept ), 'history_id' => $hid,
			'note' => null === $kept
				? 'No copy of the files was kept, so this plugin cannot put it back' . ( $ran ? ', and the data its uninstall routine removed is gone for good.' : '; reinstall it to have it again.' )
				: 'The files can be put back from the kept copy if it is unchanged: plugins-rollback with history_id ' . $hid . ', for 30 days (not its translation files)' . ( $ran ? '; the data its uninstall routine removed is gone for good.' : '.' ),
		);
		if ( null !== $copy_error ) {
			$out['copy_error'] = $copy_error->get_error_code();
			$warn[] = 'No copy of the files was kept (' . $copy_error->get_error_code() . ': ' . d5b_maint_err_text( $copy_error ) . '), as without_copy allowed: this plugin cannot put the plugin back.';
		}
		if ( $warn ) { $out['warnings'] = $warn; }
		return $out;
	}

	// ---- the definitions

	$d5b_wplug_human = ' Pass the human_check sentence on to the user after the run: the page check compares status, error text and size only, so never report the site as fine.';
	$d5b_wplug_plugin = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'The plugin file as plugins-status lists it (item), for example akismet/akismet.php.' );
	$d5b_wplug_network = array( 'type' => 'boolean', 'default' => false, 'description' => 'Multisite only: for the whole network. Needs a network administrator.' );

	d5b_rc_def( 'plugins-status', 'plugins', 'Plugin, theme and WordPress updates',
		'Returns the installed plugins and themes and WordPress itself with the versions on offer from the stored update list (never asking wordpress.org; updates-check refreshes it), why an offered update cannot run (blocked: no download, PHP or WordPress too old), the PHP version and whether file changes are switched off on the site. kept_copies lists the plugins with a copy kept by an update, rollback or delete in the last 30 days: plugin, version, history_id and kept_at. Refuses callers without update_plugins.',
		array(), array(), 'd5b_wplug_h_status', d5b_maint_can( 'update_plugins' ) );

	d5b_wc_def( 'updates-check', 'core', 'Check for updates now',
		'Clears the stored update list and asks wordpress.org (and the vendors of premium plugins) again for plugins, themes and WordPress, then returns the fresh list as plugins-status does. At most once per 5 minutes (too_soon). The Updates switch is not needed. Refuses callers without update_plugins. One change-history row records the counts; there is nothing to put back.',
		array(), array(), 'd5b_wplug_h_updates_check', d5b_maint_can( 'update_plugins' ),
		array( 'idempotent' => false ) );

	d5b_wc_def( 'plugins-install', 'plugins', 'Install a plugin from wordpress.org',
		'Installs a plugin from wordpress.org by its slug (as in wordpress.org/plugins/<slug>/), never activates it. A risky action: needs the Plugins risky switch. Only the download link wordpress.org returns for that slug, on https://downloads.wordpress.org/, is used (bad_source otherwise; nothing is downloaded); premium zips are uploaded on the Plugins screen. What was installed must be that slug\'s folder: anything else is removed again and refused (bad_source). A slug that is already installed is refused (exists); not_found when wordpress.org does not know it; install_failed with the reason when the installer fails. Needs install_plugins, and on a network a network administrator. Returns installed, slug, plugin (the file), version and active (false). One change-history row records the plugin and version (undo = delete it).',
		array( 'slug' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'The wordpress.org slug, for example contact-form-7.' ) ),
		array( 'slug' ), 'd5b_wplug_h_install', d5b_maint_can( 'install_plugins', true ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	d5b_wc_def( 'plugins-activate', 'plugins', 'Activate a plugin (checked)',
		'Activates an installed plugin as the Plugins screen does (its own activation routine runs), with a page check around it (the home page and the recently changed pages, fetched before and after). When the pages got worse the plugin is deactivated again at once (its deactivation routine runs, to clean up) and the answer says reverted true with the problems. A plugin that fails to load, throws, prints, or that WordPress refuses answers activation_failed (and is left, or put back, inactive). An uncatchable PHP fatal while the plugin loads (a compile error, a function declared twice) ends the request with no answer at all; the Plugins screen avoids that with a redirect sandbox this call cannot use. When there is no answer, read plugins-status (is it active?) and the change history row of this call, and check the site. A risky action: needs the Plugins risky switch and activate_plugins; network true (multisite) needs a network administrator. This plugin itself is refused (self). Returns plugin, network, active, reverted, problems, pages (before, after, problems, unavailable) and human_check; already_active when it was.' . $d5b_wplug_human . ' One change-history row records the active list before and after, the pages and reverted.',
		array( 'plugin' => $d5b_wplug_plugin, 'network' => $d5b_wplug_network ), array( 'plugin' ), 'd5b_wplug_h_activate', d5b_maint_can( 'activate_plugins' ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	d5b_wc_def( 'plugins-deactivate', 'plugins', 'Deactivate a plugin (checked)',
		'Deactivates an active plugin (its own deactivation routine runs, as on the Plugins screen), then checks the pages. Never switched back on by itself: problems are reported (raw shortcodes left behind are for the human check). A risky action: needs the Plugins risky switch and activate_plugins; network true (multisite) needs a network administrator, and a network-active plugin is only deactivated that way. This plugin itself is refused (self). Returns plugin, network, active, reverted (always false), problems, pages and human_check; already_inactive when it was.' . $d5b_wplug_human . ' One change-history row records the active list before and after and the pages.',
		array( 'plugin' => $d5b_wplug_plugin, 'network' => $d5b_wplug_network ), array( 'plugin' ), 'd5b_wplug_h_deactivate', d5b_maint_can( 'activate_plugins' ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	$d5b_wplug_keep = array( 'type' => 'boolean', 'default' => false, 'description' => 'true: remove the files without the plugin\'s uninstall routine, so its data stays (fully undoable). The same value on the preview and the delete: the code is for one of the two.' );
	$d5b_wplug_nocopy = array( 'type' => 'boolean', 'default' => false, 'description' => 'Delete even when no copy of the files can be kept (then this plugin cannot put it back). The same value on the preview and the delete.' );

	d5b_wc_def( 'plugins-update', 'plugins', 'Update a plugin (checked, with a kept copy)',
		'Updates an installed plugin to the version on offer (plugins-status shows it; updates-check refreshes the list). First a copy of the plugin\'s folder (or its single file) is kept in the change history store for 30 days, then WordPress updates it, with a page check around the update (the home page and the recently changed pages, fetched before and after). When the copy cannot be made the update is refused (copy_too_big over 512 MB, store_full when the store would pass 2 GB, copy_failed) unless without_copy is true: then the update runs with kept null and this plugin cannot put the previous version back. A plugin that is a link or holds one is always refused (linked), without_copy or not. rollback_on_failure (default false): when the pages got worse (or the update failed) the kept copy is put back at once, rolled_back is true and one more page round after the restore is returned as pages_after_restore (after, problems). By default a worse page is only reported (problems), so the maintainer can fix things on the new version or call plugins-rollback. Nothing on offer: already_up_to_date (nothing changed). A failed update is update_failed with the same fields in its data (and history_id). An update or rollback of the same item already running in another request: busy (409), nothing changed. A risky action: needs the Plugins risky switch and update_plugins (on a network, a network administrator). This plugin itself is refused (self). Returns plugin, from, to, version (installed now), updated, kept (bytes, files), history_id, rolled_back (and rollback_history_id), problems, pages and human_check.' . $d5b_wplug_human . ' One change-history row records the version before, the copy and the pages.',
		array( 'plugin' => $d5b_wplug_plugin,
			'rollback_on_failure' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Put the kept copy back at once when the pages got worse after the update.' ),
			'without_copy' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Update even when no copy can be kept (no undo by this plugin). Only for a host without the disk space.' ) ),
		array( 'plugin' ), 'd5b_wplug_h_update', d5b_maint_can( 'update_plugins', true ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	d5b_wc_def( 'plugins-rollback', 'plugins', 'Roll a plugin back to a kept copy (checked)',
		'Puts back the copy of a plugin kept by an update, rollback or delete, named by its change-history row (history_id; plugins-status lists them under kept_copies). The files there now are kept in turn (a rollback is itself undoable), an active plugin stays active, and the pages are checked around the swap. Refuses a row of another plugin or without a copy (not_found), a copy that is gone (copy_missing), a copy whose files no longer match the list recorded when it was kept (copy_tampered: a file changed, added or removed since), an update or rollback of the same plugin running in another request (busy), and a row older than the newest kept copy of that plugin (newer_copy_exists) unless force_older is true. warnings when the installed version is newer than the one that row left. A copy that does not hold the plugin file is put back out again (restore_mismatch). Also puts back a deleted plugin (from its delete row; it stays inactive; that is an install, so it needs install_plugins too). The swap is made in a staging folder first and switched in with WordPress\'s maintenance mode on for the moment of two renames. A risky action: needs the Plugins risky switch and update_plugins (on a network, a network administrator). This plugin itself is refused (self). Returns plugin, restored_from, history_id (the rollback row), version, replaced_version, kept, active, problems, pages and human_check.' . $d5b_wplug_human,
		array( 'plugin' => $d5b_wplug_plugin,
			'history_id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The change-history row whose copy is put back (kept_copies in plugins-status).' ),
			'force_older' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Put back this copy although a newer copy of the plugin is kept.' ) ),
		array( 'plugin', 'history_id' ), 'd5b_wplug_h_rollback', d5b_maint_can( 'update_plugins', true ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	d5b_wc_def( 'plugins-delete-preview', 'plugins', 'Preview a plugin delete',
		'Step one of a plugin delete. Changes nothing. Returns the plugin, its version and size, siblings (other plugin files in the same folder: the delete removes the whole folder, so they go too; an active sibling refuses the delete with active), has_uninstall (WordPress will run its uninstall routine, which usually removes its tables and settings for good) and a note saying what goes for good, with a confirmation code valid for 10 minutes for this user, this plugin, this keep_data and this without_copy. Show the preview to the user and wait for their explicit OK before calling divi5-builder/plugins-delete with the code and the same keep_data and without_copy. The plugin\'s translation files are not copied and a rollback of the delete does not put them back. An active plugin is refused (active): deactivate it first, on purpose, so the pages are checked; a site that refuses file changes is refused (not_allowed) and gets no code. A risky action: needs the Plugins risky switch and delete_plugins (on a network, a network administrator). This plugin itself is refused (self).',
		array( 'plugin' => $d5b_wplug_plugin, 'keep_data' => $d5b_wplug_keep, 'without_copy' => $d5b_wplug_nocopy ), array( 'plugin' ), 'd5b_wplug_h_delete_preview', d5b_maint_can( 'delete_plugins', true ),
		array( 'readonly' => true ), array( 'risky' => true ) );

	d5b_wc_def( 'plugins-delete', 'plugins', 'Delete a plugin',
		'Step two of a plugin delete: only with the confirmation code from plugins-delete-preview, by the same user, for the same plugin and version and the same keep_data and without_copy, used once, within 10 minutes (confirmation_invalid with data.reason otherwise; nothing is deleted). The change-history row is written first and a copy of the files is kept for 30 days (refused when it cannot be: copy_too_big, store_full, copy_failed; unless without_copy, then kept is null and this plugin cannot put it back; a plugin that is a link or holds one is always refused: linked). The translation files are not copied and a rollback does not put them back. Then WordPress deletes the plugin, running its uninstall routine (its data goes for good), or with keep_data true the files are removed without it (the delete_plugin / deleted_plugin hooks still fire). An active plugin is refused (active), and so is a site that refuses file changes (not_allowed, before the code is used). A risky action: needs the Plugins risky switch and delete_plugins (on a network, a network administrator). This plugin itself is refused (self). Returns plugin, version, deleted, keep_data, uninstall_ran, kept (bytes, files) and history_id: plugins-rollback with it puts the files back from the kept copy if it is unchanged.',
		array( 'plugin' => $d5b_wplug_plugin, 'keep_data' => $d5b_wplug_keep, 'without_copy' => $d5b_wplug_nocopy, 'confirmation' => array( 'type' => 'string', 'description' => 'The confirmation code from the preview, after the user said OK.' ) ),
		array( 'plugin' ), 'd5b_wplug_h_delete', d5b_maint_can( 'delete_plugins', true ),
		array( 'destructive' => true ), array( 'risky' => true ) );

	unset( $d5b_wplug_human, $d5b_wplug_plugin, $d5b_wplug_network, $d5b_wplug_keep, $d5b_wplug_nocopy );
}
