<?php
/**
 * Updates from GitHub Releases (2.5.0, #61). This file only defines functions; the loader adds the hook:
 *   update_plugins_github.com (filter, 10, 4)               -> d5b_updater_filter()
 *   upgrader_pre_download (filter, 10, 4)                   -> d5b_updater_pre_download() (sha256 before unpacking)
 *   plugins_api (filter, 10, 3)                             -> d5b_updater_plugins_api()  (the View details modal)
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
		foreach ( array( 'slug', 'version', 'tag', 'download_url', 'sha256', 'requires', 'requires_php', 'tested' ) as $k ) {
			if ( ! isset( $json[ $k ] ) || ! is_string( $json[ $k ] ) ) { return false; }
		}
		if ( 'divi5-builder' !== $json['slug'] ) { return false; }
		// \z, not $: a trailing newline must not pass.
		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+\z/', $json['version'] ) ) { return false; }
		if ( 1 !== preg_match( '/^v\d{4}\.\d{2}\.\d{2}(\.\d+)?\z/', $json['tag'] ) ) { return false; }
		if ( 1 !== preg_match( '/^[0-9a-f]{64}\z/', $json['sha256'] ) ) { return false; }
		if ( 1 !== preg_match( '/^\d+(\.\d+)*\z/', $json['requires'] ) ) { return false; }
		if ( 1 !== preg_match( '/^\d+(\.\d+)*\z/', $json['requires_php'] ) ) { return false; }
		// tested reaches wp-admin through plugins_api, where core prints it unescaped: a version string or nothing.
		if ( 1 !== preg_match( '/^\d+(\.\d+)*\z/', $json['tested'] ) ) { return false; }
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
		return array(
			'id'           => $repo,
			'slug'         => 'divi5-builder',
			'version'      => $json['version'],
			// Built from the validated tag; the json's changelog_url is never used (a prefix check would let '..' through).
			'url'          => $repo . '/releases/tag/' . $json['tag'],
			'package'      => $json['download_url'],
			'requires'     => $json['requires'],
			'requires_php' => $json['requires_php'],
			'tested'       => $json['tested'],
			'icons'        => array(),
		);
	}
}

if ( ! function_exists( 'd5b_updater_is_our_asset' ) ) {
	/**
	 * Is this URL, by its form, a release asset of our repository (any tag, any scheme, any letter case, with or without a
	 * query)? Used only to REFUSE: such a package is installed only when it is exactly the verified download_url.
	 */
	function d5b_updater_is_our_asset( string $url ): bool {
		$u = wp_parse_url( $url );
		if ( ! is_array( $u ) ) { return false; }
		$host = strtolower( rtrim( (string) ( $u['host'] ?? '' ), '.' ) );
		if ( 'github.com' !== $host && 'www.github.com' !== $host ) { return false; }
		$path = strtolower( (string) ( $u['path'] ?? '' ) );
		return 0 === strpos( preg_replace( '#/+#', '/', $path ), '/doughoseck/divi5-builder/releases/' );
	}
}

if ( ! function_exists( 'd5b_updater_pre_download' ) ) {
	/**
	 * For upgrader_pre_download (WP_Upgrader::download_package uses a non-false answer as the downloaded file; a WP_Error
	 * aborts the update before anything is unpacked). Our verified release asset is downloaded here and its sha256 compared
	 * with the json's; on a mismatch the file is deleted and the update refused. Any other package passes through untouched.
	 */
	function d5b_updater_pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( ! is_string( $package ) || '' === $package ) { return $reply; }
		$ours = d5b_updater_is_our_asset( $package );
		// A package that is not ours costs nothing: no request, not even for the json.
		$json = null;
		if ( $ours ) {
			$r    = d5b_updater_fetch( false );
			$json = d5b_updater_valid( $r['json'] ) ? $r['json'] : null;
		}
		if ( null === $json || $package !== $json['download_url'] ) {
			if ( ! $ours ) { return $reply; }
			// Never install an unverified copy of ourselves: no checksum is known for this package.
			// (The package URL is not repeated: core's upgrader skin prints this message as it is.)
			return new WP_Error( 'd5b_checksum', 'This divi5-builder.zip was not installed: no checksum for it is known (the update file could not be read, or it names another release). Check for updates again and retry.' );
		}
		if ( ! function_exists( 'download_url' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) { return $file; }
		$hash = (string) @hash_file( 'sha256', $file );
		if ( hash_equals( $json['sha256'], $hash ) ) { return $file; }
		@unlink( $file );
		return new WP_Error( 'd5b_checksum', 'The downloaded divi5-builder.zip does not match the checksum published with the release (expected ' . substr( $json['sha256'], 0, 12 ) . ' ..., got ' . substr( $hash, 0, 12 ) . ' ...), so it was not installed.' );
	}
}

if ( ! function_exists( 'd5b_updater_readme_sections' ) ) {
	/**
	 * The View details sections from readme.txt: description = the short description (the first line after the
	 * === Name === header block), changelog = the newest "= x.y.z =" entry as a <ul> plus a link to the release page.
	 * Every piece of readme text is escaped; $tag (already validated, or '') only picks the release page.
	 */
	function d5b_updater_readme_sections( string $readme, string $tag ): array {
		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $readme ) );
		$n     = count( $lines );
		$i     = 0;
		$desc  = '';
		if ( $n > 0 && 1 === preg_match( '/^===.*===\s*$/', $lines[0] ) ) {
			for ( $i = 1; $i < $n && '' !== trim( $lines[ $i ] ); $i++ ) {} // the header fields
			for ( ; $i < $n && '' === trim( $lines[ $i ] ); $i++ ) {}
			if ( $i < $n && 0 !== strpos( ltrim( $lines[ $i ] ), '==' ) ) { $desc = trim( $lines[ $i ] ); }
		}
		$version = ''; $items = array(); $in_log = false; $in_entry = false;
		foreach ( $lines as $line ) {
			$t = trim( $line );
			if ( ! $in_log ) { $in_log = ( 1 === preg_match( '/^==\s*Changelog\s*==$/i', $t ) ); continue; }
			if ( $in_entry && ( 0 === strpos( $t, '=' ) ) ) { break; } // the next entry or section
			if ( ! $in_entry ) {
				if ( 1 === preg_match( '/^=\s*(\d+\.\d+\.\d+)\s*=$/', $t, $m ) ) { $version = $m[1]; $in_entry = true; }
				elseif ( 0 === strpos( $t, '==' ) ) { break; }
				continue;
			}
			if ( '' === $t ) { continue; }
			if ( 0 === strpos( $t, '* ' ) ) { $items[] = trim( substr( $t, 2 ) ); }
			elseif ( array() !== $items ) { $items[ count( $items ) - 1 ] .= ' ' . $t; }
			else { $items[] = $t; }
		}
		$release = 'https://github.com/doughoseck/divi5-builder/releases' . ( '' !== $tag ? '/tag/' . $tag : '' );
		$log = '';
		if ( '' !== $version ) {
			$log .= '<h4>' . esc_html( $version ) . '</h4><ul>';
			foreach ( $items as $it ) { $log .= '<li>' . esc_html( $it ) . '</li>'; }
			$log .= '</ul>';
		}
		$log .= '<p><a href="' . esc_url( $release ) . '">Release notes on GitHub</a></p>';
		return array( 'description' => esc_html( $desc ), 'changelog' => $log );
	}
}

if ( ! function_exists( 'd5b_updater_plugins_api' ) ) {
	/**
	 * For plugins_api: core asks wordpress.org for "View details" unless this filter answers. Only plugin_information for
	 * the slug divi5-builder is answered; everything else passes through. Values from the json only once it validates
	 * (requires, requires_php and tested are version strings by then); the rest comes from readme.txt, escaped.
	 */
	function d5b_updater_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) { return $result; }
		if ( is_array( $args ) ) { $args = (object) $args; }
		if ( ! is_object( $args ) || ! isset( $args->slug ) || 'divi5-builder' !== $args->slug ) { return $result; }
		$r    = d5b_updater_fetch( false );
		$json = d5b_updater_valid( $r['json'] ) ? $r['json'] : null;
		$file = dirname( __DIR__ ) . '/readme.txt';
		$text = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		$date = ( null !== $json && 1 === preg_match( '/^v(\d{4})\.(\d{2})\.(\d{2})/', $json['tag'], $m ) ) ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
		return (object) array(
			'name'          => 'Divi 5 Builder',
			'slug'          => 'divi5-builder',
			'version'       => null !== $json ? $json['version'] : D5B_VERSION,
			'author'        => 'divi5-builder skill',
			'homepage'      => 'https://github.com/doughoseck/divi5-builder',
			'download_link' => null !== $json ? $json['download_url'] : '',
			'requires'      => null !== $json ? $json['requires'] : '',
			'requires_php'  => null !== $json ? $json['requires_php'] : '',
			'tested'        => null !== $json ? $json['tested'] : '',
			// From the validated tag (vYYYY.MM.DD): the json's own last_updated is never shown.
			'last_updated'  => $date,
			'sections'      => d5b_updater_readme_sections( $text, null !== $json ? $json['tag'] : '' ),
		);
	}
}
