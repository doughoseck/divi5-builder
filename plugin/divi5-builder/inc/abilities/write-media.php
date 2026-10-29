<?php
/**
 * Write abilities for media (#32, release 2.2.0): upload (from a URL or base64), update the metadata, trash and restore
 * (only where MEDIA_TRASH is on), and the two-step permanent delete (only where MEDIA_TRASH is off).
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here.
 *
 * Rules every ability here follows (design 2026-10-29, "Media", "Delete preview and confirmation", guardrail 11):
 *  - The Media category must be on (strictest switch); a parent post must be one the caller may edit AND whose own
 *    category is on.
 *  - Upload needs upload_files; update needs edit_post on the attachment; trash, restore and the delete need delete_post.
 *  - The URL fetch is the only place the plugin reaches the internet. Every hop (the URL and each redirect target) is
 *    checked by d5b_wm_safe_url before it is requested: http or https only, ports 80 and 443 only, no user name or
 *    password, no unusual numeric host forms, and the host must resolve only to public addresses. Redirects are followed by
 *    hand (at most 3). The real file type is read from the bytes (wp_check_filetype_and_ext), never from Content-Type.
 *    No DNS rebinding: each request is pinned to the address that was checked (CURLOPT_RESOLVE, set from a one-shot
 *    http_api_curl action), so curl never resolves the host again; SNI and the certificate check still use the host
 *    name. A transport that is not curl, a proxy, or a pin that cannot be set: the upload is refused (unavailable).
 *    reject_unsafe_urls stays on as a second check. SVG is never taken from a URL or base64 (stored XSS).
 *  - Trash, never delete, where the site has a media trash. Without one, a delete needs the preview's confirmation code,
 *    and the history row is written BEFORE the delete: when it cannot be written nothing is deleted (history_unavailable).
 *  - Every other successful write writes one change-history row after the write; when that row cannot be stored the
 *    result carries _history_failed and the audit row gets ok_no_history. A refusal writes no history row.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wm_dns_lookup' ) ) {
	/**
	 * Every address $host resolves to: IPv4 through gethostbynamel, IPv6 through dns_get_record (AAAA). An empty list when
	 * it does not resolve. Kept apart (and pluggable) so the tests can script DNS answers without touching the network.
	 * @return string[]
	 */
	function d5b_wm_dns_lookup( string $host ): array {
		$out = array();
		$v4  = @gethostbynamel( $host );
		if ( is_array( $v4 ) ) { $out = $v4; }
		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
			$v6 = @dns_get_record( $host, DNS_AAAA );
			foreach ( is_array( $v6 ) ? $v6 : array() as $r ) { if ( isset( $r['ipv6'] ) ) { $out[] = (string) $r['ipv6']; } }
		}
		return array_values( array_unique( array_map( 'strval', $out ) ) );
	}
}

if ( ! function_exists( 'd5b_wm_rename' ) ) {
	/**
	 * Moves one file (PHP's rename: same folder tree, so no copy is made). Kept apart (and pluggable) so the tests can make
	 * one move fail and see the moves before it put back.
	 */
	function d5b_wm_rename( string $from, string $to ): bool {
		return @rename( $from, $to );
	}
}

if ( ! function_exists( 'd5b_wm_curl_pin' ) ) {
	/**
	 * Sets CURLOPT_RESOLVE ("host:port:address" entries) on a curl handle. False when it cannot be set. Kept apart (and
	 * pluggable) so the tests can see what was pinned on their stand-in handle.
	 */
	function d5b_wm_curl_pin( $handle, array $resolve ): bool {
		if ( ! defined( 'CURLOPT_RESOLVE' ) || ! function_exists( 'curl_setopt' ) ) { return false; }
		try {
			return (bool) @curl_setopt( $handle, CURLOPT_RESOLVE, $resolve );
		} catch ( Throwable $e ) {
			return false;
		}
	}
}

if ( ! function_exists( 'd5b_wm_safe_url' ) ) {

	/** True when the site keeps a media trash (MEDIA_TRASH defined and true). */
	function d5b_wm_media_trash(): bool {
		return defined( 'MEDIA_TRASH' ) && (bool) constant( 'MEDIA_TRASH' );
	}

	/** True for an IPv4 address (4 packed bytes) in a private, loopback, link-local, shared, reserved or multicast range. */
	function d5b_wm_v4_blocked( string $bin ): bool {
		$n = unpack( 'N', $bin )[1];
		$ranges = array(
			array( '0.0.0.0', 8 ), array( '10.0.0.0', 8 ), array( '100.64.0.0', 10 ), array( '127.0.0.0', 8 ), array( '169.254.0.0', 16 ),
			array( '172.16.0.0', 12 ), array( '192.0.0.0', 24 ), array( '192.168.0.0', 16 ), array( '198.18.0.0', 15 ), array( '224.0.0.0', 4 ), array( '240.0.0.0', 4 ),
		);
		foreach ( $ranges as $r ) {
			$mask = ( 0xFFFFFFFF << ( 32 - $r[1] ) ) & 0xFFFFFFFF;
			if ( ( $n & $mask ) === ( ip2long( $r[0] ) & $mask ) ) { return true; }
		}
		return false;
	}

	/**
	 * True when the address must never be fetched: IPv4 as d5b_wm_v4_blocked; IPv6 ::, ::1, fc00::/7, fe80::/10, fec0::/10,
	 * ff00::/8, Teredo 2001::/32, and the forms that carry an IPv4 address (::ffff:a.b.c.d, ::a.b.c.d, 64:ff9b::/96, 2002::/16)
	 * judged by that address. Anything that does not parse is blocked.
	 */
	function d5b_wm_ip_blocked( string $ip ): bool {
		$bin = @inet_pton( trim( $ip, '[]' ) );
		if ( false === $bin ) { return true; }
		if ( 4 === strlen( $bin ) ) { return d5b_wm_v4_blocked( $bin ); }
		if ( 16 !== strlen( $bin ) ) { return true; }
		$p12 = substr( $bin, 0, 12 );
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === $p12 || str_repeat( "\0", 12 ) === $p12 || "\x00\x64\xff\x9b" . str_repeat( "\0", 8 ) === $p12 ) {
			return d5b_wm_v4_blocked( substr( $bin, 12 ) );
		}
		if ( "\x20\x02" === substr( $bin, 0, 2 ) ) { return d5b_wm_v4_blocked( substr( $bin, 2, 4 ) ); }
		if ( "\x20\x01\x00\x00" === substr( $bin, 0, 4 ) ) { return true; }
		$b0 = ord( $bin[0] ); $b1 = ord( $bin[1] );
		if ( 0xfc === ( $b0 & 0xfe ) || 0xff === $b0 ) { return true; }
		if ( 0xfe === $b0 && ( 0x80 === ( $b1 & 0xc0 ) || 0xc0 === ( $b1 & 0xc0 ) ) ) { return true; }
		return false;
	}

	/**
	 * May this URL be fetched? As d5b_wm_check_url, without the addresses.
	 * @return true|WP_Error invalid_input
	 */
	function d5b_wm_safe_url( string $url ) {
		$c = d5b_wm_check_url( $url );
		return is_wp_error( $c ) ? $c : true;
	}

	/**
	 * May this URL be fetched? http or https; no user name or password; port 80 or 443 (or none); a host name or a public
	 * IP literal; a host name must resolve, and to public addresses only (every address it resolves to is checked).
	 * Nothing is requested here.
	 * @return array|WP_Error invalid_input; else array( host, port (the effective one), ips (the checked addresses), literal )
	 */
	function d5b_wm_check_url( string $url ) {
		$bad = function ( string $why ) { return new WP_Error( 'invalid_input', 'This URL is not fetched: ' . $why ); };
		if ( strlen( $url ) > 2048 || 1 !== preg_match( '/^[\x21-\x7e]+$/', $url ) || false !== strpos( $url, '\\' ) ) {
			return $bad( 'it must be a plain http or https address of at most 2048 characters, with no spaces or backslashes.' );
		}
		if ( 1 !== preg_match( '#^([A-Za-z][A-Za-z0-9+.\-]*)://([^/?\#]*)#', $url, $m ) ) { return $bad( 'it is not a full address (http://... or https://...).' ); }
		$scheme = strtolower( $m[1] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) { return $bad( 'only http and https addresses are fetched.' ); }
		if ( false !== strpos( $m[2], '@' ) ) { return $bad( 'a user name or password in the address is not allowed.' ); }
		$u = parse_url( $url );
		if ( ! is_array( $u ) || ! isset( $u['host'] ) || '' === $u['host'] || isset( $u['user'] ) || isset( $u['pass'] ) ) { return $bad( 'it is not a full address (http://... or https://...).' ); }
		// The authority as written must be exactly host[:port], so no other parser can read a different host from it.
		$authority = strtolower( $m[2] );
		$host      = strtolower( (string) $u['host'] );
		$port      = isset( $u['port'] ) ? (int) $u['port'] : null;
		if ( $authority !== $host . ( null === $port ? '' : ':' . $port ) ) { return $bad( 'the host part could not be read unambiguously.' ); }
		if ( null !== $port && 80 !== $port && 443 !== $port ) { return $bad( 'only the standard ports 80 and 443 are fetched.' ); }
		$eff  = null === $port ? ( 'https' === $scheme ? 443 : 80 ) : $port;
		$host = rtrim( $host, '.' );
		if ( '' === $host ) { return $bad( 'it has no host.' ); }
		if ( '[' === $host[0] ) {
			$ip = trim( $host, '[]' );
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) { return $bad( 'the IPv6 address is not valid.' ); }
			if ( d5b_wm_ip_blocked( $ip ) ) { return $bad( 'it points to a private, loopback, link-local or reserved address, which is never fetched.' ); }
			return array( 'host' => $host, 'port' => $eff, 'ips' => array( $ip ), 'literal' => true );
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			if ( d5b_wm_ip_blocked( $host ) ) { return $bad( 'it points to a private, loopback, link-local or reserved address, which is never fetched.' ); }
			return array( 'host' => $host, 'port' => $eff, 'ips' => array( $host ), 'literal' => true );
		}
		$labels = explode( '.', $host );
		$last   = (string) end( $labels );
		// 127.1, 2130706433, 0x7f.0.0.1 ...: numeric forms some resolvers read as an address. A real name never ends in a number.
		if ( 1 === preg_match( '/^(\d+|0x[0-9a-f]*)$/', $last ) ) { return $bad( 'write an IP address as four plain numbers (a.b.c.d), or use the host name.' ); }
		if ( 1 !== preg_match( '/^[a-z0-9_]([a-z0-9_\-]*[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_\-]*[a-z0-9_])?)*$/', $host ) ) { return $bad( 'the host name is not valid.' ); }
		if ( 'localhost' === $host || 'localhost' === $last ) { return $bad( 'it points to this server (localhost), which is never fetched.' ); }
		$ips = d5b_wm_dns_lookup( $host );
		if ( array() === $ips ) { return $bad( 'the host name does not resolve.' ); }
		foreach ( $ips as $ip ) {
			if ( d5b_wm_ip_blocked( (string) $ip ) ) { return $bad( 'it points to a private, loopback, link-local or reserved address, which is never fetched.' ); }
		}
		return array( 'host' => $host, 'port' => $eff, 'ips' => array_values( array_map( 'strval', $ips ) ), 'literal' => false );
	}

	/**
	 * The CURLOPT_RESOLVE entry that pins a checked host to a checked address: "host:port:ip", the first IPv4 address,
	 * else the first IPv6 one in brackets. '' for an IP literal (nothing to resolve).
	 */
	function d5b_wm_pin_entry( array $c ): string {
		if ( ! empty( $c['literal'] ) ) { return ''; }
		$pick = '';
		foreach ( $c['ips'] as $ip ) { if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) { $pick = $ip; break; } }
		if ( '' === $pick ) { $pick = '[' . trim( (string) $c['ips'][0], '[]' ) . ']'; }
		return $c['host'] . ':' . (int) $c['port'] . ':' . $pick;
	}

	/** True when WordPress would send this request through a proxy (the proxy resolves the host itself: a pin cannot hold). */
	function d5b_wm_proxied( string $url ): bool {
		if ( ! class_exists( 'WP_HTTP_Proxy' ) ) { return false; }
		$p = new WP_HTTP_Proxy();
		return $p->is_enabled() && $p->send_through_proxy( $url );
	}

	/** False when WordPress's first transport for this request is known not to be curl. */
	function d5b_wm_curl_first( string $url, array $args ): bool {
		if ( ! function_exists( 'curl_init' ) || ! function_exists( 'curl_exec' ) ) { return false; }
		if ( class_exists( 'WP_Http' ) && method_exists( 'WP_Http', '_get_first_available_transport' ) ) {
			$t = ( new WP_Http() )->_get_first_available_transport( $args, $url );
			return 'WP_Http_Curl' === $t;
		}
		return true;
	}

	function d5b_wm_no_pin(): WP_Error {
		return new WP_Error( 'unavailable', 'This site\'s HTTP transport cannot pin the checked address (it needs curl without a proxy), so a URL upload could be redirected to an internal address by a DNS change and is refused. Upload the file as base64 instead.' );
	}

	/**
	 * One request, pinned: a one-shot http_api_curl action (last priority) sets CURLOPT_RESOLVE to the checked address on
	 * the handle of this request, and is removed after it. When the action did not run (not curl) or the pin could not
	 * be set, the answer is discarded and unavailable returned.
	 * @return array|WP_Error the wp_remote_get response
	 */
	function d5b_wm_pinned_get( string $url, array $c, array $args ) {
		if ( d5b_wm_proxied( $url ) || ! d5b_wm_curl_first( $url, $args ) ) { return d5b_wm_no_pin(); }
		$entry = d5b_wm_pin_entry( $c );
		$host  = trim( (string) $c['host'], '[]' );
		$state = (object) array( 'fired' => false, 'pinned' => false );
		$cb    = function ( $handle, $r = null, $req = '' ) use ( $state, $entry, $host ) {
			// Only the request made here: another one in between (a plugin's own call) is not touched.
			if ( '' !== (string) $req && strtolower( trim( rtrim( (string) parse_url( (string) $req, PHP_URL_HOST ), '.' ), '[]' ) ) !== $host ) { return; }
			$state->fired  = true;
			$state->pinned = '' === $entry ? true : d5b_wm_curl_pin( $handle, array( $entry ) );
		};
		add_action( 'http_api_curl', $cb, PHP_INT_MAX, 3 );
		try {
			$r = wp_remote_get( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $cb, PHP_INT_MAX );
		}
		if ( is_wp_error( $r ) && ! $state->fired ) { return $r; }
		if ( ! $state->fired || ! $state->pinned ) { return d5b_wm_no_pin(); }
		return $r;
	}

	/** A Location header made absolute against the URL that answered with it. */
	function d5b_wm_absolute( string $loc, string $base ): string {
		if ( 1 === preg_match( '#^[A-Za-z][A-Za-z0-9+.\-]*:#', $loc ) ) { return $loc; }
		$b      = parse_url( $base );
		$scheme = strtolower( (string) ( $b['scheme'] ?? 'http' ) );
		if ( 0 === strpos( $loc, '//' ) ) { return $scheme . ':' . $loc; }
		$origin = $scheme . '://' . (string) ( $b['host'] ?? '' ) . ( isset( $b['port'] ) ? ':' . (int) $b['port'] : '' );
		$path   = (string) ( $b['path'] ?? '/' );
		if ( '' !== $loc && '/' === $loc[0] ) { return $origin . $loc; }
		if ( '' !== $loc && '?' === $loc[0] ) { return $origin . $path . $loc; }
		$dir = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
		return $origin . ( '' === $dir ? '/' : $dir ) . $loc;
	}

	/** Deletes a temporary file if it is still there. */
	function d5b_wm_unlink( $file ): void {
		if ( is_string( $file ) && '' !== $file && file_exists( $file ) ) { @unlink( $file ); }
	}

	/** The site's upload limit in bytes. */
	function d5b_wm_max(): int {
		return max( 0, (int) wp_max_upload_size() );
	}

	function d5b_wm_too_large(): WP_Error {
		return new WP_Error( 'invalid_input', 'The file is larger than this site\'s upload limit (' . d5b_wm_max() . ' bytes).' );
	}

	/**
	 * Downloads $url into a temporary file, checking every hop with d5b_wm_safe_url and following at most 3 redirects by
	 * hand. Only a 200 answer is kept.
	 * @return array|WP_Error array( file, url (the final one), disposition )
	 */
	function d5b_wm_fetch( string $url ) {
		$max = d5b_wm_max();
		for ( $hop = 0; ; $hop++ ) {
			$ok = d5b_wm_check_url( $url );
			if ( is_wp_error( $ok ) ) {
				return 0 === $hop ? $ok : new WP_Error( 'invalid_input', 'Redirect ' . $hop . ' was refused. ' . $ok->get_error_message() );
			}
			$tmp = wp_tempnam( 'd5b-upload' );
			if ( ! is_string( $tmp ) || '' === $tmp ) { return new WP_Error( 'write_failed', 'No temporary file could be made for the download.' ); }
			// The temporary file is removed on every way out of this hop (an error, a redirect, an exception thrown by the HTTP
			// layer or a filter) except the one that hands it to the caller.
			$keep = false;
			try {
				$r = d5b_wm_pinned_get( $url, $ok, array(
					'redirection' => 0, 'timeout' => 20, 'stream' => true, 'filename' => $tmp,
					'limit_response_size' => $max + 1, 'reject_unsafe_urls' => true,
				) );
				if ( is_wp_error( $r ) ) {
					if ( 'unavailable' === $r->get_error_code() ) { return $r; }
					return new WP_Error( 'invalid_input', 'The download failed: ' . $r->get_error_message() );
				}
				$code = (int) wp_remote_retrieve_response_code( $r );
				if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
					$loc = wp_remote_retrieve_header( $r, 'location' );
					if ( ! is_string( $loc ) || '' === trim( $loc ) ) { return new WP_Error( 'invalid_input', 'The server answered with a redirect (HTTP ' . $code . ') but no single Location.' ); }
					if ( $hop >= 3 ) { return new WP_Error( 'invalid_input', 'More than 3 redirects: the download was stopped.' ); }
					$url = d5b_wm_absolute( trim( $loc ), $url );
					continue;
				}
				if ( 200 !== $code ) {
					return new WP_Error( 'invalid_input', 'The server answered HTTP ' . $code . ': only 200 is accepted.' );
				}
				$len = wp_remote_retrieve_header( $r, 'content-length' );
				if ( is_string( $len ) && ctype_digit( $len ) && (int) $len > $max ) { return d5b_wm_too_large(); }
				$disp = wp_remote_retrieve_header( $r, 'content-disposition' );
				$keep = true;
				return array( 'file' => $tmp, 'url' => $url, 'disposition' => is_string( $disp ) ? $disp : '', 'transport' => 'curl' );
			} finally {
				if ( ! $keep ) { d5b_wm_unlink( $tmp ); }
			}
		}
	}

	/** The file name of a download: Content-Disposition's, else the last part of the URL path; sanitized ('' when none). */
	function d5b_wm_url_filename( string $url, string $disposition ): string {
		$name = '';
		if ( 1 === preg_match( '/filename\*?\s*=\s*(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $m ) ) { $name = rawurldecode( trim( $m[1] ) ); }
		if ( '' === $name ) { $name = rawurldecode( (string) parse_url( $url, PHP_URL_PATH ) ); }
		$name = basename( str_replace( '\\', '/', $name ) );
		return (string) sanitize_file_name( $name );
	}

	/** The URL without its query and fragment (a signed URL's token is not kept in the history). */
	function d5b_wm_bare_url( string $url ): string {
		$cut = strcspn( $url, '?#' );
		return substr( $url, 0, $cut );
	}

	/** The four metadata fields media-update changes. */
	function d5b_wm_meta4( $p ): array {
		return array(
			'title' => (string) $p->post_title, 'alt' => (string) get_post_meta( (int) $p->ID, '_wp_attachment_image_alt', true ),
			'caption' => (string) $p->post_excerpt, 'description' => (string) $p->post_content,
		);
	}

	/** Everything the history keeps of an attachment: every field, its metadata, its file, URL and sizes. */
	function d5b_wm_state( $p ): array {
		$meta = wp_get_attachment_metadata( (int) $p->ID );
		return d5b_wc_snapshot( $p ) + d5b_wm_meta4( $p ) + array(
			'mime' => (string) $p->post_mime_type, 'url' => (string) wp_get_attachment_url( (int) $p->ID ),
			'file' => (string) get_post_meta( (int) $p->ID, '_wp_attached_file', true ),
			'metadata' => d5b_wc_meta_value( is_array( $meta ) ? $meta : array() ),
			'sizes' => d5b_rc_media_detail( $p )['sizes'],
		);
	}

	/** "Uploaded media 12 'photo.jpg'": the file name, else the title. */
	function d5b_wm_summary( string $verb, $p ): string {
		$file = basename( (string) get_post_meta( (int) $p->ID, '_wp_attached_file', true ) );
		$name = d5b_rc_cut( preg_replace( '/\s+/', ' ', '' === $file ? (string) $p->post_title : $file ), 80 );
		return $verb . ' media ' . (int) $p->ID . ( '' === $name ? '' : " '" . $name . "'" );
	}

	/** One history row; its id, 0 when it was not stored. */
	function d5b_wm_history( string $ability, $p, string $action, ?array $before, array $after, string $summary ): int {
		if ( ! function_exists( 'd5b_history_write' ) ) { return 0; }
		return d5b_history_write( array(
			'ability' => 'divi5-builder/' . $ability, 'object_type' => 'attachment', 'object_id' => (int) $p->ID, 'action' => $action,
			'before' => $before, 'after' => $after, 'revision_id' => 0, 'summary' => $summary,
		) );
	}

	function d5b_wm_out( $p, bool $stored, array $extra = array() ): array {
		$out = d5b_rc_media_detail( $p ) + $extra;
		if ( ! $stored ) { $out['_history_failed'] = true; }
		return $out;
	}

	/**
	 * The posts that use the attachment: as featured image (_thumbnail_id), or in their content (the file's path in the
	 * uploads folder without its extension, so the resized copies count too, also in the JSON-escaped form Divi blocks can
	 * hold). At most 100 of each. A content match is "may use": a file whose name starts the same also matches.
	 * @return array array( posts => list of array( id, type, title, status, how ), capped => bool )
	 */
	function d5b_wm_used_by( $p ): array {
		global $wpdb;
		$id  = (int) $p->ID;
		$out = array();
		$capped = false;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) { return array( 'posts' => array(), 'capped' => false ); }
		$pm = isset( $wpdb->postmeta ) ? (string) $wpdb->postmeta : $wpdb->prefix . 'postmeta';
		$po = isset( $wpdb->posts ) ? (string) $wpdb->posts : $wpdb->prefix . 'posts';
		$feat = (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $pm WHERE meta_key = %s AND meta_value = %s LIMIT %d", '_thumbnail_id', (string) $id, 101 ) );
		if ( count( $feat ) > 100 ) { $capped = true; $feat = array_slice( $feat, 0, 100 ); }
		foreach ( $feat as $pid ) { $out[ (int) $pid ] = 'featured_image'; }
		$file = (string) get_post_meta( $id, '_wp_attached_file', true );
		if ( '' !== $file ) {
			$stem = preg_replace( '/\.[A-Za-z0-9]+$/', '', $file );
			$plain = '%' . $wpdb->esc_like( $stem ) . '%';
			$esc   = '%' . $wpdb->esc_like( str_replace( '/', '\\/', $stem ) ) . '%';
			$hits  = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $po WHERE post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft' AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID ASC LIMIT %d", $plain, $esc, 101 ) );
			if ( count( $hits ) > 100 ) { $capped = true; $hits = array_slice( $hits, 0, 100 ); }
			foreach ( $hits as $pid ) { $out[ (int) $pid ] = isset( $out[ (int) $pid ] ) ? 'featured_image+content' : 'content'; }
		}
		ksort( $out );
		$rows = array();
		foreach ( $out as $pid => $how ) {
			$q = get_post( (int) $pid );
			if ( ! is_object( $q ) ) { continue; }
			$rows[] = array( 'id' => (int) $pid, 'type' => (string) $q->post_type, 'title' => (string) $q->post_title, 'status' => (string) $q->post_status, 'how' => $how );
		}
		return array( 'posts' => $rows, 'capped' => $capped );
	}

	/**
	 * Checks the parent: 0 or absent is none; otherwise a post the caller may edit, not an attachment, whose own category
	 * is on (strictest switch). @return true|WP_Error
	 */
	function d5b_wm_check_parent( array $a ) {
		if ( ! isset( $a['parent'] ) || 0 === $a['parent'] ) { return true; }
		$pp = d5b_rc_post( $a['parent'], null, false );
		if ( null === $pp || 'attachment' === $pp->post_type || ! current_user_can( 'edit_post', (int) $pp->ID ) ) {
			return new WP_Error( 'invalid_input', 'parent must be the id of a post, page or item you may edit (or 0 for none).' );
		}
		if ( ! d5b_rc_type_on( (string) $pp->post_type ) ) {
			return new WP_Error( 'not_allowed', 'The ability category of the parent is switched off, so media cannot be attached to it.' );
		}
		return true;
	}

	/**
	 * Puts a downloaded or decoded temporary file in the media library: size cap, the real type read from the file and
	 * checked against the site's allowed types, then media_handle_sideload. The temporary file is always removed.
	 * @return int|WP_Error the attachment id
	 */
	function d5b_wm_no_svg(): WP_Error {
		return new WP_Error( 'not_allowed', 'SVG files are not uploaded through the abilities, even where the site allows them: an SVG can carry script (a stored cross-site scripting risk), so upload it by hand in wp-admin after checking it.' );
	}

	function d5b_wm_store( string $tmp, string $filename, array $a ) {
		$size = @filesize( $tmp );
		if ( false === $size || 0 === (int) $size ) { d5b_wm_unlink( $tmp ); return new WP_Error( 'invalid_input', 'The file is empty.' ); }
		if ( (int) $size > d5b_wm_max() ) { d5b_wm_unlink( $tmp ); return d5b_wm_too_large(); }
		if ( 1 === preg_match( '/\.svgz?$/i', $filename ) ) { d5b_wm_unlink( $tmp ); return d5b_wm_no_svg(); }
		$chk  = wp_check_filetype_and_ext( $tmp, $filename );
		$type = is_array( $chk ) ? ( $chk['type'] ?? false ) : false;
		$ext  = is_array( $chk ) ? ( $chk['ext'] ?? false ) : false;
		// Even where the site allows SVG: an AI-fetched SVG can carry script (stored XSS), so it is uploaded by hand only.
		if ( 'image/svg+xml' === $type || ( is_string( $ext ) && 1 === preg_match( '/^svgz?$/i', $ext ) ) || ( is_array( $chk ) && ! empty( $chk['proper_filename'] ) && 1 === preg_match( '/\.svgz?$/i', (string) $chk['proper_filename'] ) ) ) {
			d5b_wm_unlink( $tmp );
			return d5b_wm_no_svg();
		}
		if ( ! $type || ! $ext || ! in_array( $type, array_values( (array) get_allowed_mime_types() ), true ) ) {
			d5b_wm_unlink( $tmp );
			return new WP_Error( 'invalid_input', 'This kind of file is not allowed on this site, or the file is not what its name says (the type is read from the file itself, not from its name or the server\'s Content-Type).' );
		}
		if ( ! empty( $chk['proper_filename'] ) ) { $filename = (string) $chk['proper_filename']; }
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$post = array();
		if ( isset( $a['title'] ) ) { $post['post_title'] = $a['title']; }
		if ( isset( $a['caption'] ) ) { $post['post_excerpt'] = $a['caption']; }
		if ( isset( $a['description'] ) ) { $post['post_content'] = $a['description']; }
		try {
			$id = media_handle_sideload( array( 'name' => $filename, 'tmp_name' => $tmp, 'size' => (int) $size, 'error' => 0 ), (int) ( $a['parent'] ?? 0 ), null, wp_slash( $post ) );
		} finally {
			d5b_wm_unlink( $tmp );
		}
		if ( is_wp_error( $id ) ) { return d5b_wc_core_error( $id ); }
		if ( (int) $id < 1 ) { return new WP_Error( 'write_failed', 'WordPress did not add the file to the media library.' ); }
		if ( isset( $a['alt'] ) ) { d5b_wc_apply_meta( (int) $id, array( '_wp_attachment_image_alt' => wp_strip_all_tags( $a['alt'] ) ) ); }
		return (int) $id;
	}

	/** The masked-read check over the four text inputs against what is stored ('' on upload). @return true|WP_Error */
	function d5b_wm_check_masked( array $a, ?array $stored ) {
		foreach ( array( 'title', 'alt', 'caption', 'description' ) as $k ) {
			if ( ! isset( $a[ $k ] ) ) { continue; }
			$r = d5b_wc_check_masked( (string) $a[ $k ], null === $stored ? '' : (string) $stored[ $k ] );
			if ( true !== $r ) { return $r; }
		}
		return true;
	}

	// ---- the abilities

	/** @return array|WP_Error */
	function d5b_wm_upload( array $in ) {
		$url = $in['url'] ?? null;
		$b64 = $in['base64'] ?? null;
		unset( $in['url'], $in['base64'] );
		$a = d5b_rc_args( $in, array( 'filename' => 'key', 'title' => 'text:1000', 'alt' => 'text:1000', 'caption' => 'text:10000', 'description' => 'text', 'parent' => 'int' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		if ( ! d5b_rc_type_on( 'attachment' ) ) { return new WP_Error( 'not_allowed', 'The Media ability category is switched off.' ); }
		if ( ! current_user_can( 'upload_files' ) ) { return new WP_Error( 'not_allowed', 'You may not upload files.' ); }
		$has_url = null !== $url && '' !== $url;
		$has_b64 = null !== $b64 && '' !== $b64;
		if ( $has_url === $has_b64 ) { return new WP_Error( 'invalid_input', 'Give exactly one of url or base64.' ); }
		if ( $has_url && ( ! is_string( $url ) || strlen( $url ) > 2048 ) ) { return new WP_Error( 'invalid_input', 'url must be text of at most 2048 characters.' ); }
		if ( $has_b64 && ! is_string( $b64 ) ) { return new WP_Error( 'invalid_input', 'base64 must be text.' ); }
		$ok = d5b_wm_check_masked( $a, null );
		if ( true !== $ok ) { return $ok; }
		$ok = d5b_wm_check_parent( $a );
		if ( true !== $ok ) { return $ok; }
		$filename = isset( $a['filename'] ) ? (string) sanitize_file_name( basename( str_replace( '\\', '/', $a['filename'] ) ) ) : '';
		if ( 1 === preg_match( '/\.svgz?$/i', $filename ) ) { return d5b_wm_no_svg(); }
		if ( $has_b64 ) {
			if ( '' === $filename ) { return new WP_Error( 'invalid_input', 'filename is required with base64 (for example photo.jpg).' ); }
			if ( 1 === preg_match( '#^data:[^,]{0,200};base64,#i', $b64, $m ) ) { $b64 = substr( $b64, strlen( $m[0] ) ); }
			// Refused before decoding when even the encoded text is too long for the limit.
			if ( strlen( $b64 ) > 4 * (int) ceil( d5b_wm_max() / 3 ) + 8 ) { return d5b_wm_too_large(); }
			$bin = base64_decode( $b64, true );
			if ( false === $bin || '' === $bin ) { return new WP_Error( 'invalid_input', 'base64 is not valid base64 text.' ); }
			if ( strlen( $bin ) > d5b_wm_max() ) { return d5b_wm_too_large(); }
			$tmp = wp_tempnam( $filename );
			if ( ! is_string( $tmp ) || '' === $tmp || false === @file_put_contents( $tmp, $bin ) ) { d5b_wm_unlink( $tmp ); return new WP_Error( 'write_failed', 'No temporary file could be made for the upload.' ); }
			unset( $bin );
			$source = array( 'source' => 'base64' );
		} else {
			$got = d5b_wm_fetch( $url );
			if ( is_wp_error( $got ) ) { return $got; }
			$tmp = $got['file'];
			if ( '' === $filename ) { $filename = d5b_wm_url_filename( $got['url'], $got['disposition'] ); }
			if ( '' === $filename || false === strpos( $filename, '.' ) ) {
				d5b_wm_unlink( $tmp );
				return new WP_Error( 'invalid_input', 'The file name could not be read from the URL: give filename with its extension (for example photo.jpg).' );
			}
			$source = array( 'source' => 'url', 'source_url' => d5b_wm_bare_url( $got['url'] ) );
		}
		$id = d5b_wm_store( $tmp, $filename, $a );
		if ( is_wp_error( $id ) ) { return $id; }
		$p = get_post( $id );
		if ( ! is_object( $p ) ) { return new WP_Error( 'write_failed', 'WordPress did not add the file to the media library.' ); }
		$stored = d5b_wm_history( 'media-upload', $p, 'upload', null, d5b_wm_state( $p ) + $source, d5b_wm_summary( 'Uploaded', $p ) );
		return d5b_wm_out( $p, $stored > 0, $has_url ? array( 'transport' => $got['transport'] ) : array() );
	}

	/** @return array|WP_Error */
	function d5b_wm_update( array $in ) {
		$a = d5b_rc_args( $in, array( 'id' => 'id', 'title' => 'text:1000', 'alt' => 'text:1000', 'caption' => 'text:10000', 'description' => 'text' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$p = d5b_wc_post( $a['id'], array( 'attachment' ), 'edit_post' );
		if ( null === $p ) { return d5b_wc_deny( $a['id'], array( 'attachment' ) ); }
		if ( 'trash' === $p->post_status ) { return new WP_Error( 'invalid_input', 'This media item is in the trash: restore it first (divi5-builder/media-restore).' ); }
		$changes = array_diff_key( $a, array( 'id' => 1 ) );
		if ( array() === $changes ) { return new WP_Error( 'invalid_input', 'Nothing to change: give at least one of title, alt, caption, description.' ); }
		$before = d5b_wm_meta4( $p );
		$ok = d5b_wm_check_masked( $a, $before );
		if ( true !== $ok ) { return $ok; }
		$arr = array( 'ID' => (int) $p->ID );
		foreach ( array( 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' ) as $k => $f ) {
			if ( array_key_exists( $k, $a ) ) { $arr[ $f ] = $a[ $k ]; }
		}
		if ( count( $arr ) > 1 ) {
			$r = wp_update_post( wp_slash( $arr ), true );
			if ( is_wp_error( $r ) ) { return d5b_wc_core_error( $r ); }
			if ( (int) $r < 1 ) { return new WP_Error( 'write_failed', 'WordPress did not save the change.' ); }
		}
		if ( array_key_exists( 'alt', $a ) ) { d5b_wc_apply_meta( (int) $p->ID, array( '_wp_attachment_image_alt' => wp_strip_all_tags( $a['alt'] ) ) ); }
		$np = get_post( (int) $p->ID );
		if ( ! is_object( $np ) ) { return new WP_Error( 'write_failed', 'WordPress did not save the change.' ); }
		$stored = d5b_wm_history( 'media-update', $np, 'update', $before, d5b_wc_diff( $before, d5b_wm_meta4( $np ) ), d5b_wm_summary( 'Updated', $np ) );
		return d5b_wm_out( $np, $stored > 0 );
	}

	/** @return array|WP_Error */
	function d5b_wm_trash( array $in ) {
		$a = d5b_rc_args( $in, array( 'id' => 'id' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$p = d5b_wc_post( $a['id'], array( 'attachment' ), 'delete_post' );
		if ( null === $p ) { return d5b_wc_deny( $a['id'], array( 'attachment' ) ); }
		if ( ! d5b_wm_media_trash() ) {
			return new WP_Error( 'cannot_trash', 'This site keeps no media trash (MEDIA_TRASH is off), so WordPress would delete the file for good. It is not trashed. To delete it, run divi5-builder/media-delete-preview, show the preview to the user and, with their OK, call divi5-builder/media-delete with its code.', array( 'use' => 'divi5-builder/media-delete-preview' ) );
		}
		if ( 'trash' === $p->post_status ) { return new WP_Error( 'cannot_trash', 'This media item is already in the trash.' ); }
		if ( ! d5b_wc_trash_available() ) { return new WP_Error( 'cannot_trash', 'This site has no trash (EMPTY_TRASH_DAYS is 0), so WordPress would delete the file for good. It is not trashed.' ); }
		$before = d5b_wm_state( $p );
		$r  = wp_trash_post( (int) $p->ID );
		$np = get_post( (int) $p->ID );
		if ( ! $r || ! is_object( $np ) || 'trash' !== $np->post_status ) { return new WP_Error( 'cannot_trash', 'WordPress did not move the media item to the trash (a plugin may have stopped it).' ); }
		$stored = d5b_wm_history( 'media-trash', $np, 'trash', $before, d5b_wc_diff( $before, d5b_wm_state( $np ) ), d5b_wm_summary( 'Trashed', $np ) );
		return d5b_wm_out( $np, $stored > 0, array( 'restore_with' => 'divi5-builder/media-restore' ) );
	}

	/** @return array|WP_Error */
	function d5b_wm_restore( array $in ) {
		$a = d5b_rc_args( $in, array( 'id' => 'id' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$p = d5b_wc_post( $a['id'], array( 'attachment' ), 'delete_post' );
		if ( null === $p ) { return d5b_wc_deny( $a['id'], array( 'attachment' ) ); }
		if ( 'trash' !== $p->post_status ) { return new WP_Error( 'not_found', 'This media item is not in the trash.' ); }
		$before = d5b_wm_state( $p );
		// WordPress gives an attachment back the status inherit (core's default for attachments, also in 5.6+).
		$r  = wp_untrash_post( (int) $p->ID );
		$np = get_post( (int) $p->ID );
		if ( ! $r || ! is_object( $np ) || 'trash' === $np->post_status ) { return new WP_Error( 'write_failed', 'WordPress did not restore the media item.' ); }
		$stored = d5b_wm_history( 'media-restore', $np, 'restore', $before, d5b_wc_diff( $before, d5b_wm_state( $np ) ), d5b_wm_summary( 'Restored', $np ) );
		return d5b_wm_out( $np, $stored > 0 );
	}

	/** The uploads folder, resolved ('' when it is not there). */
	function d5b_wm_uploads_base(): string {
		$u = wp_upload_dir( null, false );
		$b = is_array( $u ) && is_string( $u['basedir'] ?? null ) ? realpath( $u['basedir'] ) : false;
		return false === $b ? '' : rtrim( str_replace( '\\', '/', $b ), '/' );
	}

	/**
	 * Every file WordPress removes with the attachment, as paths relative to the uploads folder: the file itself, each
	 * registered size, the original of a scaled image (original_image) and the backups of an edited image
	 * (_wp_attachment_backup_sizes). Duplicates and paths that leave the uploads folder are dropped. Whether each exists is
	 * not checked here.
	 * @return string[]
	 */
	function d5b_wm_file_list( int $id ): array {
		$rel = str_replace( '\\', '/', (string) get_post_meta( $id, '_wp_attached_file', true ) );
		if ( '' === $rel ) { return array(); }
		$dir  = dirname( $rel );
		$dir  = ( '.' === $dir || '' === $dir ) ? '' : $dir . '/';
		$out  = array( $rel );
		$meta = wp_get_attachment_metadata( $id );
		$meta = is_array( $meta ) ? $meta : array();
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
			if ( is_array( $s ) && is_string( $s['file'] ?? null ) && '' !== $s['file'] ) { $out[] = $dir . basename( $s['file'] ); }
		}
		if ( is_string( $meta['original_image'] ?? null ) && '' !== $meta['original_image'] ) { $out[] = $dir . basename( $meta['original_image'] ); }
		$backup = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
		foreach ( is_array( $backup ) ? $backup : array() as $s ) {
			if ( is_array( $s ) && is_string( $s['file'] ?? null ) && '' !== $s['file'] ) { $out[] = $dir . basename( $s['file'] ); }
		}
		$keep = array();
		foreach ( $out as $r ) {
			// Relative, inside uploads, never the history folder itself.
			if ( '' === $r || '/' === $r[0] || 1 === preg_match( '#(^|/)\.\.?(/|$)|^[A-Za-z]:|^d5b-history(/|$)#', $r ) ) { continue; }
			$keep[ $r ] = true;
		}
		return array_keys( $keep );
	}

	/** Moves files back from the history folder ($moved: uploads-relative path => kept path), then removes the folder if empty. */
	function d5b_wm_put_back( string $base, array $moved, string $dir ): bool {
		$all = true;
		foreach ( array_reverse( $moved, true ) as $rel => $kept ) {
			$src = $base . '/' . $rel;
			if ( file_exists( $src ) || ! d5b_wm_rename( $kept, $src ) ) { $all = false; }
		}
		if ( '' !== $dir && is_dir( $dir ) && ! is_link( $dir ) ) { @rmdir( $dir ); }
		return $all;
	}

	/**
	 * Keeps the files of attachment $id for 30 days: before WordPress deletes them, each one (d5b_wm_file_list that is a
	 * plain file inside the uploads folder) is MOVED (renamed, not copied; the name kept) into
	 * wp-content/uploads/d5b-history/<history row id>-<32 random hex>/. When any move fails, what was moved is moved back and the answer is
	 * history_unavailable. The prune removes the folder with its row.
	 * @return array|WP_Error array( dir (relative to uploads, or null when nothing was there), files (uploads-relative paths
	 *         kept), missing (listed but not on disk) ) and, for the caller only, _moved (path => kept path) and _base
	 */
	function d5b_wm_keep_files( int $hid, int $id ) {
		$fail = function ( string $why ) {
			return new WP_Error( 'history_unavailable', 'The file could not be kept in the change history (' . $why . '), so the permanent delete was refused. Nothing was deleted.' );
		};
		$list = d5b_wm_file_list( $id );
		$base = d5b_wm_uploads_base();
		$have = array(); $missing = array();
		foreach ( $list as $rel ) {
			$src = '' === $base ? '' : $base . '/' . $rel;
			if ( '' === $src || is_link( $src ) || ! is_file( $src ) ) { $missing[] = $rel; continue; }
			$real = str_replace( '\\', '/', (string) realpath( $src ) );
			if ( '' === $real || 0 !== strpos( $real, $base . '/' ) ) { $missing[] = $rel; continue; }
			$have[] = $rel;
		}
		if ( array() === $have ) { return array( 'dir' => null, 'files' => array(), 'missing' => $missing, '_moved' => array(), '_base' => $base ); }
		if ( ! d5b_history_files_prepare() ) { return $fail( 'the folder wp-content/uploads/d5b-history could not be made' ); }
		$root = d5b_history_files_root();
		// <row id>-<32 random hex>: not guessable, so the kept file cannot be fetched where .htaccess is ignored (nginx).
		try { $rand = bin2hex( random_bytes( 16 ) ); } catch ( Throwable $e ) { return $fail( 'no random name could be made' ); }
		$name = $hid . '-' . $rand;
		$dir  = $root . '/' . $name;
		if ( file_exists( $dir ) || is_link( $dir ) ) { return $fail( 'd5b-history/' . $name . ' already exists' ); }
		if ( ! @mkdir( $dir, 0755 ) ) { return $fail( 'a folder in d5b-history could not be made' ); }
		$real_dir = str_replace( '\\', '/', (string) realpath( $dir ) );
		if ( '' === $real_dir || 0 !== strpos( $real_dir, $base . '/' ) ) { @rmdir( $dir ); return $fail( 'd5b-history resolves outside the uploads folder' ); }
		$moved = array();
		foreach ( $have as $rel ) {
			$dst = $dir . '/' . basename( $rel );
			if ( file_exists( $dst ) || ! d5b_wm_rename( $base . '/' . $rel, $dst ) ) {
				d5b_wm_put_back( $base, $moved, $dir );
				return $fail( 'moving ' . basename( $rel ) . ' failed' );
			}
			$moved[ $rel ] = $dst;
		}
		return array( 'dir' => 'd5b-history/' . $name, 'files' => $have, 'missing' => $missing, '_moved' => $moved, '_base' => $base, '_dir' => $dir );
	}

	/**
	 * A media item with a custom field the history cannot keep in full (a secret-named key, or a value holding one) is not
	 * deleted for good: the one rule, as for terms and menus.
	 */
	function d5b_wm_secret_error(): WP_Error {
		return d5b_wc_secret_error( 'media item has a custom field that' );
	}

	function d5b_wm_has_trash_error(): WP_Error {
		return new WP_Error( 'not_allowed', 'This site keeps a media trash (MEDIA_TRASH is on): use divi5-builder/media-trash, which can be undone, instead of a permanent delete.', array( 'use' => 'divi5-builder/media-trash' ) );
	}

	/** @return array|WP_Error */
	function d5b_wm_delete_preview( array $in ) {
		$a = d5b_rc_args( $in, array( 'id' => 'id' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$p = d5b_wc_post( $a['id'], array( 'attachment' ), 'delete_post' );
		if ( null === $p ) { return d5b_wc_deny( $a['id'], array( 'attachment' ) ); }
		if ( d5b_wm_media_trash() ) { return d5b_wm_has_trash_error(); }
		if ( ! d5b_wc_post_secret_free( (int) $p->ID ) ) { return d5b_wm_secret_error(); }
		$used   = d5b_wm_used_by( $p );
		$shown  = array();
		$hidden = 0;
		foreach ( $used['posts'] as $row ) {
			if ( current_user_can( 'read_post', (int) $row['id'] ) ) { $shown[] = $row; } else { $hidden++; }
		}
		$code = d5b_confirm_issue( 'divi5-builder/media-delete', 'attachment', (int) $p->ID, d5b_fp_media( (int) $p->ID ) );
		if ( is_wp_error( $code ) ) { return $code; }
		return array(
			'item' => d5b_rc_media_detail( $p ), 'used_by' => $shown, 'used_by_hidden' => $hidden, 'used_by_capped' => $used['capped'],
			'note' => 'A permanent delete removes the media item from WordPress; it cannot be undone from WordPress. The change history keeps its details, and the file (every size) is kept for 30 days in wp-content/uploads/d5b-history and can be put back by hand.',
		) + $code;
	}

	/** @return array|WP_Error */
	function d5b_wm_delete( array $in ) {
		$a = d5b_rc_args( $in, array( 'id' => 'id', 'confirmation' => 'str' ), array( 'id' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		$p = d5b_wc_post( $a['id'], array( 'attachment' ), 'delete_post' );
		if ( null === $p ) { return d5b_wc_deny( $a['id'], array( 'attachment' ) ); }
		if ( d5b_wm_media_trash() ) { return d5b_wm_has_trash_error(); }
		$id = (int) $p->ID;
		if ( ! d5b_wc_post_secret_free( $id ) ) { return d5b_wm_secret_error(); }
		$ok = d5b_confirm_check( 'divi5-builder/media-delete', 'attachment', $id, d5b_fp_media( $id ), (string) ( $a['confirmation'] ?? '' ) );
		if ( true !== $ok ) { return $ok; }
		// The history row comes FIRST: once the item is gone it is all there is to rebuild it from.
		$before = d5b_wm_state( $p ) + array( 'used_by' => d5b_wm_used_by( $p )['posts'] );
		$hid = d5b_wm_history( 'media-delete', $p, 'delete', $before, array( 'deleted' => true, 'file_copy' => null ), d5b_wm_summary( 'Permanently deleted', $p ) );
		if ( $hid < 1 ) {
			return new WP_Error( 'history_unavailable', 'The change history cannot be written on this site, so the permanent delete was refused: without that record nothing could be put back. Nothing was deleted; run the preview again once the history works.' );
		}
		$exact = d5b_wc_delete_record_ok( $hid );
		if ( true !== $exact ) { return $exact; }
		// Then the files are moved (not copied) into d5b-history/<row id>-<random hex>/, kept 30 days; the row says where.
		$kept = d5b_wm_keep_files( $hid, $id );
		if ( is_wp_error( $kept ) ) { d5b_history_delete( $hid ); return $kept; }
		$copy = array( 'dir' => $kept['dir'], 'files' => $kept['files'], 'missing' => $kept['missing'] );
		if ( ! d5b_history_set_after( $hid, array( 'deleted' => true, 'file_copy' => $copy ) ) ) {
			d5b_wm_put_back( $kept['_base'], $kept['_moved'], (string) ( $kept['_dir'] ?? '' ) );
			d5b_history_delete( $hid );
			return new WP_Error( 'history_unavailable', 'Where the file was kept could not be recorded in the change history, so the permanent delete was refused. Nothing was deleted.' );
		}
		$url = (string) wp_get_attachment_url( $id );
		$r   = wp_delete_attachment( $id, true );
		if ( ! $r || null !== get_post( $id ) ) {
			// There is no history update: a second row records the failure, the first keeps the full state. The files go back
			// where they were, so the item that is still there keeps its file.
			$back = d5b_wm_put_back( $kept['_base'], $kept['_moved'], (string) ( $kept['_dir'] ?? '' ) );
			$err  = ! $r ? 'wp_delete_attachment_failed' : 'still_present';
			$fid  = d5b_wm_history( 'media-delete', $p, 'delete_failed', null, array( 'deleted' => false, 'error' => $err, 'history_id' => $hid, 'files_put_back' => $back ), d5b_wm_summary( 'Failed to delete', $p ) );
			return new WP_Error( 'write_failed', 'WordPress did not finish the delete (a plugin may have stopped it): the media item may be partly deleted (some of its data may be gone). ' . ( $back ? 'Its files were put back. ' : 'Some of its files could not be put back: they are in wp-content/uploads/' . $kept['dir'] . '. ' ) . 'History row ' . $hid . ' holds its full state' . ( $fid > 0 ? '; row ' . $fid . ' records the failure.' : '.' ), array( 'history_id' => $hid ) );
		}
		return array( 'deleted' => true, 'id' => $id, 'file_url' => $url, 'history_id' => $hid, 'file_kept' => $copy['dir'] );
	}

	// ---- permissions (the handler checks again)

	function d5b_wm_can_upload( array $in ): bool {
		return current_user_can( 'read' ) && current_user_can( 'upload_files' ) && d5b_rc_type_on( 'attachment' );
	}
	function d5b_wm_can_edit( array $in ): bool {
		return current_user_can( 'read' ) && null !== d5b_wc_post( $in['id'] ?? 0, array( 'attachment' ), 'edit_post' );
	}
	function d5b_wm_can_delete( array $in ): bool {
		return current_user_can( 'read' ) && null !== d5b_wc_post( $in['id'] ?? 0, array( 'attachment' ), 'delete_post' );
	}

	function d5b_wm_h_upload( array $in ) { return d5b_wm_upload( $in ); }
	function d5b_wm_h_update( array $in ) { return d5b_wm_update( $in ); }
	function d5b_wm_h_trash( array $in ) { return d5b_wm_trash( $in ); }
	function d5b_wm_h_restore( array $in ) { return d5b_wm_restore( $in ); }
	function d5b_wm_h_delete_preview( array $in ) { return d5b_wm_delete_preview( $in ); }
	function d5b_wm_h_delete( array $in ) { return d5b_wm_delete( $in ); }

	// ---- the definitions

	$d5b_wm_id = array( 'id' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The id of the media item (see media-list).' ) );
	$d5b_wm_meta = array(
		'title'       => array( 'type' => 'string', 'maxLength' => 1000, 'description' => 'The title, at most 1000 characters.' ),
		'alt'         => array( 'type' => 'string', 'maxLength' => 1000, 'description' => 'The alternative text of an image (what a screen reader says), at most 1000 characters.' ),
		'caption'     => array( 'type' => 'string', 'maxLength' => 10000, 'description' => 'The caption, at most 10000 characters.' ),
		'description' => array( 'type' => 'string', 'description' => 'The description, at most 1 MB.' ),
	);

	d5b_wc_def( 'media-upload', 'media', 'Upload a media file',
		'Adds one file to the media library, from url (fetched by the site) or from base64, and returns its id, link, every image size and the hash of its description. Both are capped at the site\'s upload size limit and its allowed file types; the type is read from the file\'s own bytes, never from its name or the server\'s Content-Type. A url must be http or https on port 80 or 443, with no user name or password, and its host must resolve only to public addresses: private, loopback, link-local and reserved addresses are refused before anything is requested. It is fetched with a 20-second timeout and at most 3 redirects, each target checked the same way before it is requested; only an HTTP 200 answer is kept. Each request is pinned to the address that was checked, so a host that changes its DNS answer in between (DNS rebinding) cannot send the request elsewhere; this needs curl as the site\'s HTTP transport and no proxy, otherwise a url upload is refused with unavailable (base64 still works). The result says transport: curl. SVG files are refused with not_allowed, even where the site allows them (upload SVG by hand in wp-admin). filename is required with base64 and optional with url (read from the URL otherwise). parent (optional) attaches the file to a post, page or item you may edit whose category is on. Refuses a caller without upload_files, text holding [redacted] (masked_read), and the Media category switched off. The change history records the upload.',
		array( 'url' => array( 'type' => 'string', 'maxLength' => 2048, 'description' => 'An http or https address of the file. Give url or base64, not both.' ),
			'base64' => array( 'type' => 'string', 'description' => 'The file itself, base64 encoded (a data: prefix is allowed). Give url or base64, not both.' ),
			'filename' => array( 'type' => 'string', 'maxLength' => 255, 'description' => 'The file name with its extension, for example photo.jpg. Required with base64. It is cleaned the way WordPress cleans file names.' ),
		) + $d5b_wm_meta + array( 'parent' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'The id of the post, page or item to attach the file to, or 0 for none.' ) ),
		array(), 'd5b_wm_h_upload', 'd5b_wm_can_upload', array() );

	d5b_wc_def( 'media-update', 'media', 'Update media details',
		'Changes the title, alt text, caption or description of one media item (only the fields given; the file itself is never replaced) and returns the item. Refuses an item you may not edit, an item in the trash, and text holding [redacted] from a masked read (masked_read). The change history keeps the previous values.',
		$d5b_wm_id + $d5b_wm_meta, array( 'id' ), 'd5b_wm_h_update', 'd5b_wm_can_edit', array( 'idempotent' => true ) );

	d5b_wc_def( 'media-trash', 'media', 'Trash a media item',
		'Moves one media item to the trash and returns it with restore_with, the ability that puts it back. Only on a site with a media trash (MEDIA_TRASH on): otherwise it is refused with cannot_trash and data.use names divi5-builder/media-delete-preview, the first step of a confirmed permanent delete. Also refuses an item already in the trash, an item you may not delete, and a site without a trash (EMPTY_TRASH_DAYS 0). The change history keeps a full copy of its details.',
		$d5b_wm_id, array( 'id' ), 'd5b_wm_h_trash', 'd5b_wm_can_delete', array( 'destructive' => true ) );

	d5b_wc_def( 'media-restore', 'media', 'Restore a media item',
		'Takes one media item out of the trash (back to the status inherit, as WordPress does for media) and returns it. Refuses an item that is not in the trash or that you may not delete.',
		$d5b_wm_id, array( 'id' ), 'd5b_wm_h_restore', 'd5b_wm_can_delete', array() );

	d5b_wc_def( 'media-delete-preview', 'media', 'Preview a media delete',
		'Step one of a permanent delete, for a site without a media trash (MEDIA_TRASH off; with it on this is refused with not_allowed: use media-trash). Changes nothing. Returns the item, used_by (the posts that use it as featured image or have its file path in their content, at most 100 of each; posts you may not read are only counted in used_by_hidden), and a confirmation code valid for 10 minutes for this user and this item as it is now. Show the preview to the user and wait for their explicit OK before calling divi5-builder/media-delete with the code. Refuses an item you may not delete.',
		$d5b_wm_id, array( 'id' ), 'd5b_wm_h_delete_preview', 'd5b_wm_can_delete', array( 'readonly' => true ) );

	d5b_wc_def( 'media-delete', 'media', 'Delete a media item for good',
		'Step two of a permanent delete: deletes one media item and its file (every size) from the server, and returns deleted true with the history row id. Only with the confirmation code from media-delete-preview, by the same user, for the same item, unchanged since the preview, used once, within 10 minutes; anything else is refused with confirmation_invalid and data.reason, and nothing is deleted. The change history row is written BEFORE the delete and keeps every detail, the metadata, the URL, the sizes and the posts that used it; the file is kept for 30 days in wp-content/uploads/d5b-history and can be put back by hand (the file and every size are moved to d5b-history/<history row id>-<random>/, a name nobody can guess, before WordPress deletes the item; file_kept in the result and file_copy in the history row name the folder). When that row cannot be written, or the file cannot be moved there, the delete is refused (history_unavailable) and nothing is deleted. Refused with not_allowed on a site with a media trash (use media-trash), and for an item with a custom field holding a secret-named key (the history could not keep it; delete it in wp-admin).',
		$d5b_wm_id + array( 'confirmation' => array( 'type' => 'string', 'description' => 'The confirmation code from media-delete-preview, after the user said OK.' ) ),
		array( 'id', 'confirmation' ), 'd5b_wm_h_delete', 'd5b_wm_can_delete', array( 'destructive' => true ) );

	unset( $d5b_wm_id, $d5b_wm_meta );

	/**
	 * The upload's args as the audit row keeps them: the URL without its query or fragment (a signed URL's token), and a
	 * base64 file as its length only.
	 */
	function d5b_wm_audit_args( array $args ): array {
		if ( isset( $args['url'] ) && is_string( $args['url'] ) ) { $args['url'] = d5b_wm_bare_url( $args['url'] ); }
		if ( array_key_exists( 'base64', $args ) ) { $args['base64'] = array( 'base64_bytes' => is_string( $args['base64'] ) ? strlen( $args['base64'] ) : 0 ); }
		return $args;
	}
	d5b_ability_set_audit_args( array( 'media-upload' => 'd5b_wm_audit_args' ) );
}
