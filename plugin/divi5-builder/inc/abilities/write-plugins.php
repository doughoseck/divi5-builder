<?php
/**
 * Abilities for plugins (#32, release 2.4.0): plugins-status (a read), updates-check (a normal write in the core category),
 * plugins-install, plugins-activate and plugins-deactivate (risky, in the plugins category).
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
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wplug_ok' ) ) {

	/** True when the current user has $cap and, with $network_admin on a network, is a network administrator. */
	function d5b_wplug_ok( string $cap, bool $network_admin = false ): bool {
		return current_user_can( $cap ) && ( ! $network_admin || ! is_multisite() || is_super_admin() );
	}

	/** The permission callback for $cap. */
	function d5b_wplug_can( string $cap, bool $network_admin = false ): callable {
		return function ( array $in = array() ) use ( $cap, $network_admin ) { return d5b_wplug_ok( $cap, $network_admin ); };
	}

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
	function d5b_wplug_target( array $a, string $verb ) {
		$plugin = (string) $a['plugin'];
		$net    = ! empty( $a['network'] );
		// Exactly as listed: no spaces or control characters around it (trim() would also drop a NUL byte).
		if ( trim( $plugin ) !== $plugin || ( ! d5b_wplug_file_ok( ltrim( $plugin, '/' ) ) && ! d5b_maint_self( $plugin ) ) ) {
			return new WP_Error( 'invalid_input', 'plugin must be the plugin file as plugins-status lists it (folder/file.php).' );
		}
		if ( d5b_maint_self( $plugin ) ) {
			return new WP_Error( 'self', 'This is the Divi 5 Builder plugin itself (or one of the plugins it replaces); it never ' . $verb . 's itself through an ability. Use the Plugins screen.' );
		}
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
	 * The plugins with a kept copy: the history rows of object_type plugin whose kept is recorded (not null), newest first:
	 * the plugin file and the version kept (the row's before), the row id and when it was kept. Never the copy's path.
	 */
	function d5b_wplug_kept_copies(): array {
		$out = array();
		if ( ! function_exists( 'd5b_history_list' ) ) { return $out; }
		foreach ( d5b_history_list( array( 'object_type' => 'plugin' ), 500 ) as $r ) {
			$row = d5b_history_get( (int) $r['id'] );
			if ( null === $row ) { continue; }
			$kept = $row['after']['kept'] ?? ( $row['before']['kept'] ?? null );
			if ( ! is_array( $kept ) ) { continue; }
			$plugin = (string) ( $row['before']['plugin'] ?? ( $row['after']['plugin'] ?? '' ) );
			if ( '' === $plugin ) { continue; }
			$out[] = array( 'plugin' => $plugin, 'version' => (string) ( $row['before']['version'] ?? '' ), 'history_id' => (int) $row['id'], 'kept_at' => d5b_wc_iso( (string) $row['created_at'] ) );
		}
		return $out;
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
		if ( ! d5b_wplug_ok( 'update_plugins' ) ) { return new WP_Error( 'forbidden', 'You do not have permission to read the update list.' ); }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! function_exists( 'wpsu_status' ) ) { return new WP_Error( 'unavailable', 'The update code is not loaded on this site.' ); }
		$s = d5b_wplug_safe_status( (array) wpsu_status( false ) ); // the stored list: nothing cleared, nothing asked
		$s['kept_copies'] = d5b_wplug_kept_copies();
		return $s;
	}

	// ---- updates-check

	function d5b_wplug_h_updates_check( array $in ) {
		if ( ! d5b_wplug_ok( 'update_plugins' ) ) { return new WP_Error( 'forbidden', 'You do not have permission to check for updates.' ); }
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
		if ( ! d5b_wplug_ok( 'install_plugins', true ) ) { return new WP_Error( 'forbidden', 'You do not have permission to install plugins.' ); }
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
		if ( ! d5b_wplug_ok( 'activate_plugins' ) ) { return new WP_Error( 'forbidden', 'You do not have permission to ' . $verb . ' plugins.' ); }
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
		if ( ! empty( $pages['unavailable'] ) ) { $warn[] = 'The site could not fetch its own pages before the change, so there was no page check: only the human check can tell.'; }
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
		if ( ! empty( $pages['unavailable'] ) ) { $warn[] = 'The site could not fetch its own pages before the change, so there was no page check: only the human check can tell.'; }
		if ( ! d5b_history_set_after( $hid, array( 'active' => d5b_wplug_active_list( $net ), 'pages' => $pages, 'reverted' => false ) ) ) { $warn[] = 'The change history row could not be completed.'; }
		if ( $warn ) { $out['warnings'] = $warn; }
		return $out;
	}

	// ---- the definitions

	$d5b_wplug_human = ' Pass the human_check sentence on to the user after the run: the page check compares status, error text and size only, so never report the site as fine.';
	$d5b_wplug_plugin = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'The plugin file as plugins-status lists it (item), for example akismet/akismet.php.' );
	$d5b_wplug_network = array( 'type' => 'boolean', 'default' => false, 'description' => 'Multisite only: for the whole network. Needs a network administrator.' );

	d5b_rc_def( 'plugins-status', 'plugins', 'Plugin, theme and WordPress updates',
		'Returns the installed plugins and themes and WordPress itself with the versions on offer from the stored update list (never asking wordpress.org; updates-check refreshes it), why an offered update cannot run (blocked: no download, PHP or WordPress too old), the PHP version and whether file changes are switched off on the site. kept_copies lists the plugins with a copy kept by an update, rollback or delete in the last 30 days: plugin, version, history_id and kept_at. Refuses callers without update_plugins.',
		array(), array(), 'd5b_wplug_h_status', d5b_wplug_can( 'update_plugins' ) );

	d5b_wc_def( 'updates-check', 'core', 'Check for updates now',
		'Clears the stored update list and asks wordpress.org (and the vendors of premium plugins) again for plugins, themes and WordPress, then returns the fresh list as plugins-status does. At most once per 5 minutes (too_soon). The Updates switch is not needed. Refuses callers without update_plugins. One change-history row records the counts; there is nothing to put back.',
		array(), array(), 'd5b_wplug_h_updates_check', d5b_wplug_can( 'update_plugins' ),
		array( 'idempotent' => false ) );

	d5b_wc_def( 'plugins-install', 'plugins', 'Install a plugin from wordpress.org',
		'Installs a plugin from wordpress.org by its slug (as in wordpress.org/plugins/<slug>/), never activates it. A risky action: needs the Plugins risky switch. Only the download link wordpress.org returns for that slug, on https://downloads.wordpress.org/, is used (bad_source otherwise; nothing is downloaded); premium zips are uploaded on the Plugins screen. A slug that is already installed is refused (exists); not_found when wordpress.org does not know it; install_failed with the reason when the installer fails. Needs install_plugins, and on a network a network administrator. Returns installed, slug, plugin (the file), version and active (false). One change-history row records the plugin and version (undo = delete it).',
		array( 'slug' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'description' => 'The wordpress.org slug, for example contact-form-7.' ) ),
		array( 'slug' ), 'd5b_wplug_h_install', d5b_wplug_can( 'install_plugins', true ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	d5b_wc_def( 'plugins-activate', 'plugins', 'Activate a plugin (checked)',
		'Activates an installed plugin as the Plugins screen does (its own activation routine runs), with a page check around it (the home page and the recently changed pages, fetched before and after). When the pages got worse the plugin is deactivated again at once (its deactivation routine runs, to clean up) and the answer says reverted true with the problems. A plugin that fails to load, throws, prints, or that WordPress refuses answers activation_failed (and is left, or put back, inactive). An uncatchable PHP fatal while the plugin loads (a compile error, a function declared twice) ends the request with no answer at all; the Plugins screen avoids that with a redirect sandbox this call cannot use. When there is no answer, read plugins-status (is it active?) and the change history row of this call, and check the site. A risky action: needs the Plugins risky switch and activate_plugins; network true (multisite) needs a network administrator. This plugin itself is refused (self). Returns plugin, network, active, reverted, problems, pages (before, after, problems, unavailable) and human_check; already_active when it was.' . $d5b_wplug_human . ' One change-history row records the active list before and after, the pages and reverted.',
		array( 'plugin' => $d5b_wplug_plugin, 'network' => $d5b_wplug_network ), array( 'plugin' ), 'd5b_wplug_h_activate', d5b_wplug_can( 'activate_plugins' ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	d5b_wc_def( 'plugins-deactivate', 'plugins', 'Deactivate a plugin (checked)',
		'Deactivates an active plugin (its own deactivation routine runs, as on the Plugins screen), then checks the pages. Never switched back on by itself: problems are reported (raw shortcodes left behind are for the human check). A risky action: needs the Plugins risky switch and activate_plugins; network true (multisite) needs a network administrator, and a network-active plugin is only deactivated that way. This plugin itself is refused (self). Returns plugin, network, active, reverted (always false), problems, pages and human_check; already_inactive when it was.' . $d5b_wplug_human . ' One change-history row records the active list before and after and the pages.',
		array( 'plugin' => $d5b_wplug_plugin, 'network' => $d5b_wplug_network ), array( 'plugin' ), 'd5b_wplug_h_deactivate', d5b_wplug_can( 'activate_plugins' ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	unset( $d5b_wplug_human, $d5b_wplug_plugin, $d5b_wplug_network );
}
