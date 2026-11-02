<?php
/**
 * Shared helpers of the maintenance abilities (#32, release 2.4.0): the page checks around a change, the kept copy of a
 * plugin or theme before it is changed, the self rule, the human check sentence and a small rate limit. This file only
 * defines functions; the abilities that use them are in write-plugins.php, write-themes.php and the others.
 *
 * The page checks are a port of scripts/site-care/site-updates.js (pageHealth, compareHealth, FATAL): the same rules, the
 * same problem sentences. PHP's own clock cannot be replaced, so a test sets $GLOBALS['d5b_maint_seams']['now'].
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_maint_now' ) ) {
	/** The time in seconds, as microtime( true ); a test replaces it with $GLOBALS['d5b_maint_seams']['now']. */
	function d5b_maint_now(): float {
		$f = $GLOBALS['d5b_maint_seams']['now'] ?? null;
		return is_callable( $f ) ? (float) $f() : microtime( true );
	}
}

if ( ! function_exists( 'd5b_maint_fatal_re' ) ) {
	/** Signs of a broken page in its HTML: the FATAL expression of site-updates.js, verbatim. */
	function d5b_maint_fatal_re(): string {
		return '/There has been a critical error on (this|your) website|Fatal error<\/b>|<b>Parse error<\/b>|Error establishing a database connection|Briefly unavailable for scheduled maintenance/i';
	}
}

if ( ! function_exists( 'd5b_maint_bust' ) ) {
	/** $url with the cache-buster d5b=<time in milliseconds> (as the script's Date.now()), replacing one already there. */
	function d5b_maint_bust( string $url ): string {
		$t = (string) (int) floor( d5b_maint_now() * 1000 );
		if ( 1 === preg_match( '/([?&])d5b=[0-9]*/', $url ) ) { return (string) preg_replace( '/([?&])d5b=[0-9]*/', '${1}d5b=' . $t, $url, 1 ); }
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'd5b=' . $t;
	}
}

if ( ! function_exists( 'd5b_pages_watched' ) ) {
	/**
	 * The pages watched around a change, as the script's default: the home page, the five most recently modified published
	 * pages and the newest published post, without repeats, at most 7, each with the cache-buster ?d5b=<time>.
	 * @return string[]
	 */
	function d5b_pages_watched(): array {
		$urls = array( home_url( '/' ) );
		$queries = array(
			array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 5, 'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => true, 'ignore_sticky_posts' => true ),
			array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => true, 'ignore_sticky_posts' => true ),
		);
		foreach ( $queries as $args ) {
			if ( ! class_exists( 'WP_Query' ) ) { break; }
			$q = new WP_Query( $args );
			foreach ( (array) $q->posts as $id ) {
				$link = get_permalink( is_object( $id ) ? $id->ID : (int) $id );
				if ( is_string( $link ) && '' !== $link ) { $urls[] = $link; }
			}
		}
		$urls = array_slice( array_values( array_unique( $urls ) ), 0, 7 );
		return array_map( 'd5b_maint_bust', $urls );
	}
}

if ( ! function_exists( 'd5b_maint_page_health' ) ) {
	/** One page as the script's pageHealth reads it: status, bytes, closed (</html> present), fatal text, title. */
	function d5b_maint_page_health( string $url, int $status, string $html ): array {
		$title = preg_match( '/<title[^>]*>([\s\S]*?)<\/title>/i', $html, $m ) ? trim( $m[1] ) : '';
		$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 80, 'UTF-8' ) : substr( $title, 0, 80 );
		$fatal = preg_match( d5b_maint_fatal_re(), $html, $f ) ? $f[0] : '';
		return array( 'url' => $url, 'status' => $status, 'bytes' => strlen( $html ), 'closed' => 1 === preg_match( '/<\/html>/i', $html ), 'fatal' => $fatal, 'title' => $title, 'skipped' => false );
	}
}

if ( ! function_exists( 'd5b_pages_round' ) ) {
	/**
	 * One round of page checks: each url fetched with wp_remote_get (timeout 15 s, a fresh cache-buster, the loopback's own
	 * sslverify as Site Health has it), read as pageHealth does. Once $budget_s seconds have gone by, the pages left are not
	 * fetched and come back skipped. A page that does not answer has status 0. The url in the result is the url given.
	 * $retry (the after round): a page that answers nothing or 5xx is asked again, up to twice, 6 s apart, as the script does
	 * (right after an update a site can fail once while it leaves maintenance mode); a retry is made only while it fits in the
	 * budget. The before round does not retry.
	 * @return array<int, array{url:string,status:int,bytes:int,closed:bool,fatal:string,title:string,skipped:bool}>
	 */
	function d5b_pages_round( array $urls, int $budget_s = 60, bool $retry = false ): array {
		$start = d5b_maint_now();
		$out   = array();
		$fetch = function ( string $url ): array {
			$r = wp_remote_get( d5b_maint_bust( $url ), array(
				'timeout'   => 15,
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			) );
			if ( is_wp_error( $r ) ) { return d5b_maint_page_health( $url, 0, '' ); }
			return d5b_maint_page_health( $url, (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ) );
		};
		foreach ( $urls as $url ) {
			$url = (string) $url;
			if ( d5b_maint_now() - $start >= $budget_s ) {
				$out[] = array( 'url' => $url, 'status' => 0, 'bytes' => 0, 'closed' => false, 'fatal' => '', 'title' => '', 'skipped' => true );
				continue;
			}
			$h = $fetch( $url );
			for ( $tries = 1; $retry && $tries < 3 && ( 0 === $h['status'] || $h['status'] >= 500 ); $tries++ ) {
				if ( d5b_maint_now() - $start + 6 >= $budget_s ) { break; }
				d5b_maint_sleep( 6 );
				$h = $fetch( $url );
			}
			$out[] = $h;
		}
		return $out;
	}
}

if ( ! function_exists( 'd5b_maint_sleep' ) ) {
	/** sleep( $s ); a test replaces it with $GLOBALS['d5b_maint_seams']['sleep'] (the suite never really waits). */
	function d5b_maint_sleep( int $s ): void {
		$f = $GLOBALS['d5b_maint_seams']['sleep'] ?? null;
		if ( is_callable( $f ) ) { $f( $s ); return; }
		sleep( $s );
	}
}

if ( ! function_exists( 'd5b_maint_oldest_row_at' ) ) {
	/** The created date (UTC, 'Y-m-d H:i:s') of the oldest history row, when space in the store frees up; null when none. */
	function d5b_maint_oldest_row_at(): ?string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return null; }
		$quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$v     = $wpdb->get_var( 'SELECT MIN(created_at) FROM ' . d5b_history_table() );
		if ( null !== $quiet ) { $wpdb->suppress_errors( $quiet ); }
		return is_string( $v ) && '' !== $v ? $v : null;
	}
}

if ( ! function_exists( 'd5b_pages_compare' ) ) {
	/**
	 * What got worse between two rounds, as the script's compareHealth: a page that was already bad before (not 200, a fatal,
	 * cut off; or not fetched) is not blamed on the change; worse = not 200, a fatal, cut off, or shrunk below half when it was
	 * over 2000 bytes. One sentence per page. A page skipped in the after round (out of time) was not seen, so it is not blamed.
	 * @return string[]
	 */
	function d5b_pages_compare( array $before, array $after ): array {
		$b = array();
		foreach ( $before as $p ) { if ( is_array( $p ) && isset( $p['url'] ) ) { $b[ (string) $p['url'] ] = $p; } }
		$problems = array();
		foreach ( $after as $a ) {
			if ( ! is_array( $a ) || ! isset( $a['url'] ) ) { continue; }
			$p = $b[ (string) $a['url'] ] ?? null;
			if ( null === $p ) { continue; }
			$was_ok = 200 === (int) ( $p['status'] ?? 0 ) && '' === (string) ( $p['fatal'] ?? '' ) && ! empty( $p['closed'] );
			if ( ! $was_ok || ! empty( $a['skipped'] ) ) { continue; }
			$url = (string) $a['url'];
			$st  = (int) ( $a['status'] ?? 0 );
			if ( 200 !== $st ) { $problems[] = $url . ': answered ' . ( 0 === $st ? 'nothing' : (string) $st ) . ' (was 200)'; }
			elseif ( '' !== (string) ( $a['fatal'] ?? '' ) ) { $problems[] = $url . ': shows "' . $a['fatal'] . '"'; }
			elseif ( empty( $a['closed'] ) ) { $problems[] = $url . ': the page is cut off (no closing </html>)'; }
			elseif ( (int) $p['bytes'] > 2000 && (int) $a['bytes'] < (int) $p['bytes'] * 0.5 ) { $problems[] = $url . ': the page shrank from ' . (int) $p['bytes'] . ' to ' . (int) $a['bytes'] . ' characters'; }
		}
		return $problems;
	}
}

if ( ! function_exists( 'd5b_pages_check' ) ) {
	/**
	 * A before round, $change(), an after round, compared. When every page failed to fetch before (the loopback does not
	 * work), the result is unavailable: $change() still runs, there is no after round and the human check is the whole check.
	 * $change's own return value is not kept here: a caller that needs it captures it by reference.
	 * @return array{before:array,after:array,problems:string[],unavailable:bool}
	 */
	function d5b_pages_check( callable $change ): array {
		$urls   = d5b_pages_watched();
		$before = d5b_pages_round( $urls );
		$unavailable = true;
		foreach ( $before as $p ) { if ( 0 !== (int) $p['status'] ) { $unavailable = false; break; } }
		$change();
		if ( $unavailable ) { return array( 'before' => $before, 'after' => array(), 'problems' => array(), 'unavailable' => true ); }
		$after = d5b_pages_round( $urls, 60, true );
		return array( 'before' => $before, 'after' => $after, 'problems' => d5b_pages_compare( $before, $after ), 'unavailable' => false );
	}
}

if ( ! function_exists( 'd5b_maint_copy_cap' ) ) {
	/** The largest copy kept of one plugin or theme: 512 MB. */
	function d5b_maint_copy_cap(): int {
		return 536870912;
	}
}

if ( ! function_exists( 'd5b_maint_store_cap' ) ) {
	/** The largest the whole store (uploads/d5b-history) may grow to: 2 GB. */
	function d5b_maint_store_cap(): int {
		return 2147483648;
	}
}

if ( ! function_exists( 'd5b_maint_tree_size' ) ) {
	/**
	 * The size of a file, or of a folder and everything in it: bytes, files and links (a link, or a junction, is counted and
	 * never followed).
	 * @return array{bytes:int,files:int,links:int}
	 */
	function d5b_maint_tree_size( string $path ): array {
		$t = array( 'bytes' => 0, 'files' => 0, 'links' => 0 );
		if ( d5b_maint_is_link( $path ) ) { $t['links']++; return $t; }
		if ( is_file( $path ) ) { $t['bytes'] = (int) @filesize( $path ); $t['files'] = 1; return $t; }
		if ( ! is_dir( $path ) ) { return $t; }
		foreach ( (array) @scandir( $path ) as $f ) {
			if ( '.' === $f || '..' === $f || ! is_string( $f ) ) { continue; }
			$s = d5b_maint_tree_size( $path . '/' . $f );
			foreach ( $s as $k => $v ) { $t[ $k ] += $v; }
		}
		return $t;
	}
}

if ( ! function_exists( 'd5b_maint_filesystem' ) ) {
	/** core's copy_dir and an initialised $wp_filesystem (what the upgrader uses). false when either cannot be had. */
	function d5b_maint_filesystem(): bool {
		if ( ! function_exists( 'copy_dir' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'copy_dir' ) ) { return false; }
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) && function_exists( 'WP_Filesystem' ) ) { WP_Filesystem(); }
		return is_object( $wp_filesystem );
	}
}

if ( ! function_exists( 'd5b_keep_copy' ) ) {
	/**
	 * Keeps a copy of a plugin folder (WP_PLUGIN_DIR/<dir_name>), a single-file plugin (WP_PLUGIN_DIR/<file>.php) or a theme
	 * folder (the theme root/<dir_name>) in the store, before it is changed: <store>/<row id>-<32 hex>/<kind>/<dir_name>,
	 * a mirror of the original (a folder for a folder, the file for a single-file plugin), made with core's copy_dir.
	 * The size is measured before anything is written. Errors: copy_too_big (over 512 MB), store_full (the store plus this
	 * copy over 2 GB; nothing is ever deleted early to make room), copy_failed (with the reason; a part-made copy is removed).
	 * @return array{path:string,bytes:int,files:int}|WP_Error
	 */
	function d5b_keep_copy( string $kind, string $dir_name, int $row_id ) {
		$fail = function ( string $why ) { return new WP_Error( 'copy_failed', 'No copy could be kept: ' . $why . '.', array( 'status' => 503 ) ); };
		if ( 'plugin' !== $kind && 'theme' !== $kind ) { return $fail( 'unknown kind' ); }
		if ( $row_id < 1 ) { return $fail( 'no history row' ); }
		if ( '' === $dir_name || '.' === $dir_name || '..' === $dir_name || 1 === preg_match( '#[/\\\\\x00]#', $dir_name ) ) { return $fail( 'not a folder or file name' ); }
		if ( 'plugin' === $kind ) {
			if ( ! defined( 'WP_PLUGIN_DIR' ) ) { return $fail( 'the plugins folder is not known' ); }
			$parent = (string) WP_PLUGIN_DIR;
		} else {
			$parent = function_exists( 'get_theme_root' ) ? (string) get_theme_root( $dir_name ) : '';
			if ( '' === $parent ) { return $fail( 'the themes folder is not known' ); }
		}
		$src = rtrim( str_replace( '\\', '/', $parent ), '/' ) . '/' . $dir_name;
		if ( d5b_maint_is_link( $src ) ) { return $fail( 'the ' . $kind . ' is a link' ); }
		$single = is_file( $src );
		if ( ! $single && ! is_dir( $src ) ) { return $fail( 'the ' . $kind . ' was not found' ); }
		if ( $single && 'theme' === $kind ) { return $fail( 'a theme is a folder' ); }

		$size = d5b_maint_tree_size( $src );
		if ( $size['links'] > 0 ) { return $fail( 'the ' . $kind . ' contains a link, which is not copied' ); }
		if ( $size['bytes'] > d5b_maint_copy_cap() ) {
			return new WP_Error( 'copy_too_big', 'The ' . $kind . ' is ' . $size['bytes'] . ' bytes, over the 512 MB a kept copy may take.', array( 'status' => 413, 'bytes' => $size['bytes'], 'cap' => d5b_maint_copy_cap() ) );
		}
		$store = d5b_history_store_bytes();
		if ( $store + $size['bytes'] > d5b_maint_store_cap() ) {
			$oldest = d5b_maint_oldest_row_at();
			return new WP_Error( 'store_full', 'The kept copies take ' . $store . ' bytes; this copy (' . $size['bytes'] . ' bytes) would take the store over 2 GB. Copies are removed with their history row after 30 days' . ( null === $oldest ? '' : '; the oldest row is from ' . $oldest . ' UTC' ) . '.', array( 'status' => 507, 'store_bytes' => $store, 'bytes' => $size['bytes'], 'cap' => d5b_maint_store_cap(), 'oldest_row_at' => $oldest ) );
		}

		if ( ! d5b_history_files_prepare() ) { return $fail( 'the store folder could not be made' ); }
		if ( ! d5b_maint_filesystem() ) { return $fail( 'no file system access (copy_dir)' ); }
		global $wp_filesystem;
		$name   = $row_id . '-' . bin2hex( random_bytes( 16 ) );
		$folder = d5b_history_files_root() . '/' . $name;
		$dest   = $folder . '/' . $kind . '/' . $dir_name;
		$done   = false;
		if ( @mkdir( $folder . '/' . $kind, 0755, true ) ) {
			if ( $single ) {
				$done = (bool) $wp_filesystem->copy( $src, $dest, false, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
			} else {
				$done = true === copy_dir( $src, $dest );
			}
		}
		// A copy is only kept when it is the whole of the original.
		$got = $done ? d5b_maint_tree_size( $dest ) : null;
		if ( ! $done || $got !== $size ) {
			d5b_history_files_remove( $row_id, $name );
			return $fail( $done ? 'the copy is not complete' : 'copying the files failed' );
		}
		return array( 'path' => $dest, 'bytes' => $size['bytes'], 'files' => $size['files'] );
	}
}

if ( ! function_exists( 'd5b_maint_self' ) ) {
	/**
	 * True for this plugin's own file (divi5-builder/divi5-builder.php, or wherever this copy is installed) and the old
	 * plugins it replaces (each part's legacy file, in a folder or on its own). The maintenance abilities refuse to act on them.
	 */
	function d5b_maint_self( string $plugin_file ): bool {
		$f = strtolower( ltrim( str_replace( '\\', '/', trim( $plugin_file ) ), '/' ) );
		if ( '' === $f ) { return false; }
		$own = array( 'divi5-builder/divi5-builder.php' );
		if ( defined( 'D5B_FILE' ) && function_exists( 'plugin_basename' ) ) { $own[] = (string) plugin_basename( D5B_FILE ); }
		if ( function_exists( 'd5b_parts' ) && function_exists( 'd5b_legacy_basenames' ) ) {
			foreach ( array_keys( d5b_parts() ) as $part ) { $own = array_merge( $own, d5b_legacy_basenames( $part ) ); }
		}
		foreach ( $own as $o ) { if ( strtolower( str_replace( '\\', '/', $o ) ) === $f ) { return true; } }
		return false;
	}
}

if ( ! function_exists( 'd5b_human_check' ) ) {
	/** The fixed sentence every update, rollback, activate, deactivate and theme switch result carries (spec: the human check). */
	function d5b_human_check(): string {
		return 'Please look at the site yourself now, in a browser and on a phone: check for layout breaks, missing images, errors in the browser console, forms that no longer work and raw shortcodes showing as text. The page check only compares status, error text and size, so finding nothing there does not mean the site is fine, and it cannot see any of these. There is no substitute for the human check and it only takes a few seconds.';
	}
}

if ( ! function_exists( 'd5b_maint_rate' ) ) {
	/**
	 * A fixed-window rate limit kept in a transient: true when this attempt is allowed (and counts it), false when $limit
	 * attempts were already allowed in the current $window_s seconds. A refused attempt is not counted.
	 */
	function d5b_maint_rate( string $key, int $limit, int $window_s ): bool {
		$name = 'd5b_rate_' . md5( $key );
		$now  = d5b_maint_now();
		$w    = max( 1, $window_s );
		$v    = get_transient( $name );
		if ( ! is_array( $v ) || ! isset( $v['start'], $v['count'] ) || $now - (float) $v['start'] >= $w || $now < (float) $v['start'] ) {
			$v = array( 'start' => $now, 'count' => 0 );
		}
		if ( (int) $v['count'] >= $limit ) { return false; }
		$v['count'] = (int) $v['count'] + 1;
		set_transient( $name, $v, (int) max( 1, ceil( $w - ( $now - (float) $v['start'] ) ) ) );
		return true;
	}
}
