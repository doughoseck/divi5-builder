<?php
/**
 * Updates from GitHub Releases (2.5.0, #61). This file only defines functions; the loader adds the hook:
 *   update_plugins_github.com (filter, 10, 4)               -> d5b_updater_filter()
 *
 * WordPress 5.8+ asks the update_plugins_{host} filter for every plugin whose Update URI header names that host; ours names
 * https://github.com/doughoseck/divi5-builder. The answer comes from plugin/update.json on the repository's main branch,
 * which the build writes with the version bump. Nothing in it is trusted until d5b_updater_valid() says so: the package can
 * only ever be our own repository's release asset for the json's own tag, on github.com, over https.
 *
 * One request per site per 12 hours (1 hour after a failure), kept in the site transient d5b_update_json:
 *   array( 'json' => the last VALID json or null, 'checked' => unix time of the last request, 'ok' => bool, 'error' => string )
 * The freshness is counted from 'checked'; the transient itself is kept longer so that, when the next request fails (a GitHub
 * hiccup), the last good answer is still there to offer.
 *
 * A must-use copy never gets an offer: WordPress cannot update a must-use plugin, it is updated by replacing the folder.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_updater_is_mu' ) ) {
	/** Is this copy inside the must-use folder? (A plain string compare of normalised paths; no realpath.) */
	function d5b_updater_is_mu(): bool {
		if ( ! defined( 'WPMU_PLUGIN_DIR' ) || ! defined( 'D5B_FILE' ) ) { return false; }
		$dir = rtrim( wp_normalize_path( WPMU_PLUGIN_DIR ), '/' );
		return '' !== $dir && 0 === strpos( wp_normalize_path( D5B_FILE ), $dir . '/' );
	}
}

if ( ! function_exists( 'd5b_updater_our_file' ) ) {
	/** Our plugin file as WordPress names it (folder/file.php). */
	function d5b_updater_our_file(): string { return plugin_basename( D5B_FILE ); }
}

if ( ! function_exists( 'd5b_updater_valid' ) ) {
	/** Is this decoded update.json exactly the shape the build writes, pointing at our own release asset? */
	function d5b_updater_valid( $json ): bool {
		if ( ! is_array( $json ) ) { return false; }
		foreach ( array( 'slug', 'version', 'tag', 'download_url', 'sha256', 'requires', 'requires_php' ) as $k ) {
			if ( ! isset( $json[ $k ] ) || ! is_string( $json[ $k ] ) ) { return false; }
		}
		if ( 'divi5-builder' !== $json['slug'] ) { return false; }
		// \z, not $: a trailing newline must not pass.
		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+\z/', $json['version'] ) ) { return false; }
		if ( 1 !== preg_match( '/^v\d{4}\.\d{2}\.\d{2}(\.\d+)?\z/', $json['tag'] ) ) { return false; }
		if ( 1 !== preg_match( '/^[0-9a-f]{64}\z/', $json['sha256'] ) ) { return false; }
		if ( 1 !== preg_match( '/^\d+(\.\d+)*\z/', $json['requires'] ) ) { return false; }
		if ( 1 !== preg_match( '/^\d+(\.\d+)*\z/', $json['requires_php'] ) ) { return false; }
		$u = wp_parse_url( $json['download_url'] );
		if ( ! is_array( $u ) ) { return false; }
		if ( 'https' !== ( $u['scheme'] ?? '' ) ) { return false; }
		if ( 'github.com' !== ( $u['host'] ?? '' ) ) { return false; }
		foreach ( array( 'user', 'pass', 'port', 'query', 'fragment' ) as $k ) {
			if ( isset( $u[ $k ] ) ) { return false; }
		}
		if ( '/doughoseck/divi5-builder/releases/download/' . $json['tag'] . '/divi5-builder.zip' !== ( $u['path'] ?? '' ) ) { return false; }
		return true;
	}
}

if ( ! function_exists( 'd5b_updater_fetch' ) ) {
	/**
	 * The update.json, from the cache while it is fresh (12 hours after a good request, 1 hour after a failed one) or else
	 * from GitHub. $force asks GitHub whatever the cache says.
	 * @return array array( 'ok' => bool, 'json' => array|null, 'error' => string, 'checked' => int, 'from_cache' => bool )
	 */
	function d5b_updater_fetch( bool $force = false ): array {
		$cached = get_site_transient( 'd5b_update_json' );
		$cached = ( is_array( $cached ) && isset( $cached['checked'] ) && is_int( $cached['checked'] ) ) ? $cached : null;
		if ( ! $force && null !== $cached ) {
			$age = time() - $cached['checked'];
			$ttl = ! empty( $cached['ok'] ) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS;
			if ( $age >= 0 && $age < $ttl ) {
				return array(
					'ok'         => ! empty( $cached['ok'] ),
					'json'       => is_array( $cached['json'] ?? null ) ? $cached['json'] : null,
					'error'      => is_string( $cached['error'] ?? null ) ? $cached['error'] : '',
					'checked'    => $cached['checked'],
					'from_cache' => true,
				);
			}
		}
		$last_good = ( null !== $cached && d5b_updater_valid( $cached['json'] ?? null ) ) ? $cached['json'] : null;

		$response = wp_safe_remote_get( 'https://raw.githubusercontent.com/doughoseck/divi5-builder/main/plugin/update.json', array(
			'timeout' => 5,
			'headers' => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'divi5-builder/' . D5B_VERSION . '; ' . home_url( '/' ),
			),
		) );
		$json = null; $error = '';
		if ( is_wp_error( $response ) ) {
			$error = 'could not read the update file: ' . $response->get_error_message();
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$error = 'could not read the update file (HTTP ' . (int) wp_remote_retrieve_response_code( $response ) . ')';
		} else {
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $decoded ) ) {
				$error = 'the update file is not valid JSON';
			} elseif ( ! d5b_updater_valid( $decoded ) ) {
				$error = 'the update file failed validation';
			} else {
				$json = $decoded;
			}
		}
		$ok    = null !== $json;
		$entry = array( 'json' => $ok ? $json : $last_good, 'checked' => time(), 'ok' => $ok, 'error' => $error );
		// Kept well past the 12 hours so a failed request still finds the last good answer; freshness comes from 'checked'.
		set_site_transient( 'd5b_update_json', $entry, 30 * DAY_IN_SECONDS );
		return array( 'ok' => $ok, 'json' => $entry['json'], 'error' => $error, 'checked' => $entry['checked'], 'from_cache' => false );
	}
}

if ( ! function_exists( 'd5b_updater_filter' ) ) {
	/**
	 * For update_plugins_github.com. Core sets id and plugin itself and needs version; package is what it downloads.
	 * Another plugin's answer passes through untouched; ours is false unless the json is valid and NEWER than this copy.
	 */
	function d5b_updater_filter( $update, array $plugin_data, string $plugin_file, $locales ) {
		if ( $plugin_file !== d5b_updater_our_file() || d5b_updater_is_mu() ) { return $update; }
		$r    = d5b_updater_fetch();
		$json = $r['json'];
		// A failed request still answers from the last good json it kept (validated again here, as everything read back is).
		if ( ! d5b_updater_valid( $json ) || ! version_compare( $json['version'], D5B_VERSION, '>' ) ) { return false; }
		$repo = 'https://github.com/doughoseck/divi5-builder';
		$url  = ( isset( $json['changelog_url'] ) && is_string( $json['changelog_url'] ) && 0 === strpos( $json['changelog_url'], $repo . '/' ) ) ? $json['changelog_url'] : $repo;
		return array(
			'id'           => $repo,
			'slug'         => 'divi5-builder',
			'version'      => $json['version'],
			'url'          => $url,
			'package'      => $json['download_url'],
			'requires'     => $json['requires'],
			'requires_php' => $json['requires_php'],
			'tested'       => ( isset( $json['tested'] ) && is_string( $json['tested'] ) ) ? $json['tested'] : '',
			'icons'        => array(),
		);
	}
}
