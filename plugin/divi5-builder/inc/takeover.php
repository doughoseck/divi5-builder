<?php
/**
 * Takeover from the four old plugins (#37).
 *
 *  - on activation (and once, in must-use mode) the old plugins that are active are deactivated;
 *  - a copy that cannot be deactivated (a must-use file, a code snippet, a plugin switched on again later)
 *    is detected by the gate, which stands aside for it; the report and lines below tell the owner;
 *  - a part whose switch is off but whose old copy is still live is called out in red;
 *  - activating an old plugin over REST (core's /wp/v2/plugins, an Application Password or a script) is refused,
 *    so a switch that is off cannot be bypassed that way. A person activating it in wp-admin is not stopped.
 *
 * This file only defines functions; it registers no hooks. The loader hooks
 * d5b_takeover_on_activation (register_activation_hook), d5b_takeover_mu_admin_init (admin_init) and
 * d5b_refuse_legacy_rest_activation (rest_pre_dispatch).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_legacy_basenames' ) ) {
	/** The two plugin file names an old copy of a part can have. */
	function d5b_legacy_basenames( string $part ): array {
		$parts = d5b_parts();
		if ( ! isset( $parts[ $part ] ) ) { return array(); }
		$slug = $parts[ $part ]['legacy_slug'];
		return array( $slug . '.php', $slug . '/' . $slug . '.php' );
	}
}

if ( ! function_exists( 'd5b_takeover_get' ) ) {
	/**
	 * The takeover record(s) that apply to this site, merged, or null when none exists.
	 * Per site by default (one subsite's activation must not decide for the others); a deactivation that was
	 * network-wide is stored as a network option and applies to every site. $scope 'network' reads only that one.
	 */
	function d5b_takeover_get( string $scope = 'any' ) {
		$site = 'network' === $scope ? null : get_option( 'd5b_takeover', null );
		$net  = is_multisite() ? get_site_option( 'd5b_takeover', null ) : null;
		$site = is_array( $site ) ? $site : null;
		$net  = is_array( $net ) ? $net : null;
		if ( null === $site && null === $net ) { return null; }
		$list = array();
		foreach ( array( $site, $net ) as $r ) {
			if ( $r && isset( $r['deactivated'] ) && is_array( $r['deactivated'] ) ) { $list = array_merge( $list, $r['deactivated'] ); }
		}
		$first = $site ? $site : $net;
		return array( 'done_at' => isset( $first['done_at'] ) ? $first['done_at'] : '', 'deactivated' => array_values( array_unique( $list ) ) );
	}
}

if ( ! function_exists( 'd5b_takeover_active_basenames' ) ) {
	/** Plugin basenames that are active: on this site, and (on multisite, when asked) network-wide. */
	function d5b_takeover_active_basenames( bool $network_wide = false ): array {
		$out    = array();
		$active = get_option( 'active_plugins', array() );
		if ( is_array( $active ) ) { foreach ( $active as $b ) { if ( is_string( $b ) ) { $out[] = $b; } } }
		if ( $network_wide || is_multisite() ) {
			$sw = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $sw ) ) { foreach ( array_keys( $sw ) as $b ) { if ( is_string( $b ) ) { $out[] = $b; } } }
		}
		return array_values( array_unique( $out ) );
	}
}

if ( ! function_exists( 'd5b_takeover_deactivate' ) ) {
	/**
	 * Deactivate every old plugin that is active. Names are matched whole, never by substring.
	 * Network-wide: network-active ones go too (deactivate_plugins( ..., true )); ordinary ones on this site always go.
	 * @return string[] the basenames deactivated
	 */
	function d5b_takeover_deactivate( bool $network ): array {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			$file = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( file_exists( $file ) ) { require_once $file; }
			if ( ! function_exists( 'deactivate_plugins' ) ) { return array(); }
		}
		$old = array();
		foreach ( array_keys( d5b_parts() ) as $part ) { $old = array_merge( $old, d5b_legacy_basenames( $part ) ); }

		$normal = array();
		$active = get_option( 'active_plugins', array() );
		if ( is_array( $active ) ) { $normal = array_values( array_intersect( $old, $active ) ); }
		$sitewide = array();
		if ( $network ) {
			$sw = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $sw ) ) { $sitewide = array_values( array_intersect( $old, array_keys( $sw ) ) ); }
		}
		if ( $normal ) { deactivate_plugins( $normal, true, false ); }
		if ( $sitewide ) { deactivate_plugins( $sitewide, true, true ); }

		$done  = array_merge( $normal, $sitewide );
		$value = array( 'done_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'deactivated' => $done );
		if ( $network && is_multisite() ) {
			update_site_option( 'd5b_takeover', $value ); // a network-wide deactivation is recorded network-wide
		} elseif ( null === get_option( 'd5b_takeover', null ) ) {
			add_option( 'd5b_takeover', $value, '', 'no' );
		} else {
			update_option( 'd5b_takeover', $value, false );
		}
		return $done;
	}
}

if ( ! function_exists( 'd5b_takeover_on_activation' ) ) {
	/** For register_activation_hook( D5B_FILE, ... ). */
	function d5b_takeover_on_activation( $network_wide = false ): void {
		d5b_takeover_deactivate( (bool) $network_wide );
	}
}

if ( ! function_exists( 'd5b_takeover_norm' ) ) {
	function d5b_takeover_norm( string $path ): string {
		return rtrim( preg_replace( '#/+#', '/', str_replace( '\\', '/', $path ) ), '/' );
	}
}

if ( ! function_exists( 'd5b_takeover_is_mu' ) ) {
	/** Is this plugin file inside the must-use directory? */
	function d5b_takeover_is_mu( string $file ): bool {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) { return false; }
		$dir = d5b_takeover_norm( WPMU_PLUGIN_DIR );
		return '' !== $dir && 0 === strpos( d5b_takeover_norm( $file ), $dir . '/' );
	}
}

if ( ! function_exists( 'd5b_takeover_mu_admin_init' ) ) {
	/** For admin_init. A must-use plugin is never "activated", so its takeover runs once, here. */
	function d5b_takeover_mu_admin_init(): void {
		if ( ! defined( 'D5B_FILE' ) || ! d5b_takeover_is_mu( D5B_FILE ) ) { return; }
		if ( ! current_user_can( 'activate_plugins' ) ) { return; }
		// Network-wide deactivation is a network-admin action: a subsite user with only activate_plugins gets
		// this site's ordinary plugins handled and nothing network-wide.
		$network = is_multisite() && current_user_can( 'manage_network_plugins' );
		if ( null !== d5b_takeover_get( $network ? 'network' : 'any' ) ) { return; }
		d5b_takeover_deactivate( $network );
	}
}

if ( ! function_exists( 'd5b_legacy_report' ) ) {
	/**
	 * Old copies of a part on this site. Works outside REST requests (uses constants, files and options).
	 * A part counts as served by the old copy only when the gate stood aside for it (d5b_legacy_seen, written
	 * at each REST request). An old copy whose constant is defined but which registered no routes is
	 * 'inactive': it is not serving anything and can never be "LIVE".
	 * @return array<int, array{part:string,how:string,file:?string,live_while_off:bool}> how: mu|plugin|other|inactive
	 */
	function d5b_legacy_report(): array {
		$seen   = function_exists( 'd5b_legacy_seen_get' ) ? d5b_legacy_seen_get() : array();
		$active = d5b_takeover_active_basenames( true );
		$rows   = array();
		foreach ( d5b_parts() as $key => $part ) {
			$how     = null;
			$file    = null;
			$serving = in_array( $key, $seen, true );
			if ( defined( $part['legacy_const'] ) && ! $serving ) {
				$how = 'inactive';
			} elseif ( defined( $part['legacy_const'] ) ) {
				$mu = $part['legacy_slug'] . '.php';
				if ( defined( 'WPMU_PLUGIN_DIR' ) && file_exists( rtrim( WPMU_PLUGIN_DIR, '/\\' ) . '/' . $mu ) ) {
					$how = 'mu'; $file = $mu;
				} else {
					foreach ( d5b_legacy_basenames( $key ) as $b ) {
						if ( in_array( $b, $active, true ) ) { $how = 'plugin'; $file = $b; break; }
					}
					if ( null === $how ) { $how = 'other'; }
				}
			} elseif ( $serving ) {
				$how = 'other';
			}
			if ( null === $how ) { continue; }
			$rows[] = array(
				'part'           => $key,
				'how'            => $how,
				'file'           => $file,
				'live_while_off' => $serving && $part['has_switch'] && ! d5b_switch_is_on( $key ),
			);
		}
		return $rows;
	}
}

if ( ! function_exists( 'd5b_takeover_lines' ) ) {
	/** The notice lines, in order. @return array<int, array{text:string,red:bool}> */
	function d5b_takeover_lines(): array {
		$lines = array();
		$t     = d5b_takeover_get();
		if ( $t && ! empty( $t['deactivated'] ) && is_array( $t['deactivated'] ) ) {
			$active = d5b_takeover_active_basenames( true );
			$gone   = array_values( array_diff( $t['deactivated'], $active ) );
			if ( $gone ) {
				$lines[] = array( 'text' => 'Replaced by Divi 5 Builder, you can delete them: ' . implode( ', ', $gone ), 'red' => false );
			}
		}
		$parts = d5b_parts();
		foreach ( d5b_legacy_report() as $row ) {
			$label = $parts[ $row['part'] ]['label'];
			if ( 'inactive' === $row['how'] ) {
				$text = 'An old copy of ' . $label . ' is installed but inactive. Delete it to finish the move.';
			} elseif ( 'mu' === $row['how'] ) {
				$text = 'The old file wp-content/mu-plugins/' . $row['file'] . ' is still in charge of ' . $label . '. Delete it to finish the move.';
			} elseif ( 'plugin' === $row['how'] ) {
				$text = 'The old plugin ' . $row['file'] . ' is active again and is in charge of ' . $label . '. Deactivate and delete it.';
			} else {
				$text = 'An older copy (perhaps a code snippet) is already providing ' . $label . '. Remove it to finish the move.';
			}
			$lines[] = array( 'text' => $text, 'red' => false );
			if ( $row['live_while_off'] ) {
				$lines[] = array( 'text' => $label . ' is LIVE on this site through the old copy, even though its switch here is off.', 'red' => true );
			}
		}
		return $lines;
	}
}

if ( ! function_exists( 'd5b_legacy_refresh' ) ) {
	/**
	 * Called by the admin notice and the settings page before they show the takeover lines: brings the
	 * stand-aside record up to date, so the red LIVE line never lags behind a person re-activating an old
	 * plugin. Runs only when an old copy's constant is defined; otherwise it does nothing at all.
	 * rest_get_server() builds the server and fires rest_api_init once (core), which runs the gate for it;
	 * the direct d5b_gate() call is then a no-op (once per server) and covers a removed gate hook.
	 */
	function d5b_legacy_refresh(): void {
		$any = false;
		foreach ( d5b_parts() as $part ) {
			if ( defined( $part['legacy_const'] ) ) { $any = true; break; }
		}
		if ( ! $any || ! function_exists( 'rest_get_server' ) ) { return; }
		$server = rest_get_server();
		if ( function_exists( 'd5b_gate' ) ) { d5b_gate( $server ); }
	}
}

if ( ! function_exists( 'd5b_legacy_rest_ids' ) ) {
	/**
	 * The ids core's /wp/v2/plugins uses for the old plugins: the basename without ".php"
	 * ("wp-site-updates" and "wp-site-updates/wp-site-updates"), lower case.
	 * @return string[]
	 */
	function d5b_legacy_rest_ids(): array {
		$ids = array();
		foreach ( array_keys( d5b_parts() ) as $part ) {
			foreach ( d5b_legacy_basenames( $part ) as $b ) { $ids[] = strtolower( substr( $b, 0, -4 ) ); }
		}
		return $ids;
	}
}

if ( ! function_exists( 'd5b_refuse_legacy_rest_activation' ) ) {
	/**
	 * For rest_pre_dispatch. Refuses (403) a REST request that would activate one of the old plugins:
	 *  - POST/PUT/PATCH /wp/v2/plugins/<old plugin> with status active or network-active;
	 *  - POST /wp/v2/plugins (install) with the slug of an old plugin and status active or network-active.
	 * Every other request passes through untouched, as does an earlier filter's answer.
	 *
	 * @param mixed $result  null, or what an earlier filter decided
	 * @param mixed $server  the WP_REST_Server (unused)
	 * @param mixed $request the WP_REST_Request
	 * @return mixed $result unchanged, or a WP_Error with status 403
	 */
	function d5b_refuse_legacy_rest_activation( $result, $server = null, $request = null ) {
		if ( null !== $result || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) || ! method_exists( $request, 'get_method' ) ) {
			return $result;
		}
		if ( ! in_array( strtoupper( (string) $request->get_method() ), array( 'POST', 'PUT', 'PATCH' ), true ) ) { return $result; }
		$status = $request->get_param( 'status' );
		if ( ! is_string( $status ) || ! in_array( strtolower( trim( $status ) ), array( 'active', 'network-active' ), true ) ) {
			return $result;
		}
		// Core matches routes case-insensitively, and a client may percent-encode the "/" in the id.
		$route = strtolower( rawurldecode( (string) $request->get_route() ) );
		$route = rtrim( preg_replace( '#/+#', '/', $route ), '/' );
		$ids   = d5b_legacy_rest_ids();
		$hit   = false;
		if ( preg_match( '#^/wp/v2/plugins/(.+)$#', $route, $m ) ) {
			$id  = preg_replace( '#\.php$#', '', $m[1] );
			$hit = in_array( $id, $ids, true );
		} elseif ( '/wp/v2/plugins' === $route ) {
			$slug = $request->get_param( 'slug' );
			$hit  = is_string( $slug ) && in_array( strtolower( trim( $slug ) ), $ids, true );
		}
		if ( ! $hit ) { return $result; }
		return new WP_Error(
			'd5b_old_plugin_refused',
			'This old plugin is replaced by Divi 5 Builder and cannot be activated over the REST API. Turn the feature on in Settings > Divi 5 Builder instead.',
			array( 'status' => 403 )
		);
	}
}
