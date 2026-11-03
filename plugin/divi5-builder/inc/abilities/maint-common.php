<?php
/**
 * Shared helpers of the maintenance abilities (#32, release 2.4.0): the page checks around a change, the kept copy of a
 * plugin or theme before it is changed, the checked update sequence (d5b_maint_update_sequence) and the swap that puts a
 * kept copy back (d5b_maint_restore), both for plugins and themes, the self rule, the human check sentence and a small rate limit. This file only
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
	 * One round of page checks: each url fetched with wp_remote_get (timeout 15 s or the budget left, whichever is less, so
	 * the round never runs past $budget_s; a fresh cache-buster; the loopback's own sslverify as Site Health has it), read as
	 * pageHealth does. Once less than a second of $budget_s is left, the pages left are not fetched and come back skipped. A page that does not answer has status 0. The url in the result is the url given.
	 * $retry (the after round): a page that answers nothing or 5xx is asked again, up to twice, 6 s apart, as the script does
	 * (right after an update a site can fail once while it leaves maintenance mode); a retry is made only while it fits in the
	 * budget. The before round does not retry.
	 * @return array<int, array{url:string,status:int,bytes:int,closed:bool,fatal:string,title:string,skipped:bool}>
	 */
	function d5b_pages_round( array $urls, int $budget_s = 60, bool $retry = false ): array {
		$start = d5b_maint_now();
		$out   = array();
		$fetch = function ( string $url ) use ( $start, $budget_s ): array {
			// at most 15 s, and never past the round's budget: a page that never answers cannot stretch the round
			$left = (float) $budget_s - ( d5b_maint_now() - $start ); // at least 1 s: the loop below never starts a fetch with less
			$r = wp_remote_get( d5b_maint_bust( $url ), array(
				'timeout'   => min( 15.0, $left ),
				'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			) );
			if ( is_wp_error( $r ) ) { return d5b_maint_page_health( $url, 0, '' ); }
			return d5b_maint_page_health( $url, (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ) );
		};
		foreach ( $urls as $url ) {
			$url = (string) $url;
			if ( d5b_maint_now() - $start > $budget_s - 1 ) { // less than a second left: not fetched
				$out[] = array( 'url' => $url, 'status' => 0, 'bytes' => 0, 'closed' => false, 'fatal' => '', 'title' => '', 'skipped' => true );
				continue;
			}
			$h = $fetch( $url );
			for ( $tries = 1; $retry && $tries < 3 && ( 0 === $h['status'] || $h['status'] >= 500 ); $tries++ ) {
				if ( d5b_maint_now() - $start + 6 > $budget_s - 1 ) { break; } // the wait and at least a second to fetch
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
			elseif ( (int) $p['bytes'] > 2000 && (int) $a['bytes'] < (int) $p['bytes'] * 0.5 ) { $problems[] = $url . ': the page shrank from ' . (int) $p['bytes'] . ' to ' . (int) $a['bytes'] . ' bytes'; }
		}
		return $problems;
	}
}

if ( ! function_exists( 'd5b_pages_check' ) ) {
	/**
	 * A before round, $change(), an after round, compared. When every page failed to fetch before (the loopback does not
	 * work), the result is unavailable: $change() still runs, there is no after round and the human check is the whole check.
	 * $change's own return value is not kept here: a caller that needs it captures it by reference. skipped: the urls a round
	 * ran out of time for (before or after), which were therefore not compared (d5b_pages_warnings says so).
	 * $only_before: set to the before-only result (after empty, problems empty) as soon as the before round is done, before
	 * $change() runs, so a caller whose change stops the check with an exception still has the round to report.
	 * @return array{before:array,after:array,problems:string[],unavailable:bool,skipped:string[]}
	 */
	function d5b_pages_check( callable $change, ?array &$only_before = null ): array {
		$urls   = d5b_pages_watched();
		$before = d5b_pages_round( $urls );
		$unavailable = true;
		foreach ( $before as $p ) { if ( 0 !== (int) $p['status'] ) { $unavailable = false; break; } }
		$only_before = array( 'before' => $before, 'after' => array(), 'problems' => array(), 'unavailable' => $unavailable, 'skipped' => d5b_pages_skipped( $before ) );
		$change();
		if ( $unavailable ) { return array( 'before' => $before, 'after' => array(), 'problems' => array(), 'unavailable' => true, 'skipped' => array() ); }
		$after = d5b_pages_round( $urls, 60, true );
		return array( 'before' => $before, 'after' => $after, 'problems' => d5b_pages_compare( $before, $after ), 'unavailable' => false, 'skipped' => d5b_pages_skipped( array_merge( $before, $after ) ) );
	}

	/** The urls (without the cache-buster) of the pages a round ran out of time for. @return string[] */
	function d5b_pages_skipped( array $rounds ): array {
		$skipped = array();
		foreach ( $rounds as $p ) {
			if ( is_array( $p ) && ! empty( $p['skipped'] ) ) { $skipped[ (string) preg_replace( '/[?&]d5b=[0-9]*$/', '', (string) $p['url'] ) ] = true; }
		}
		return array_keys( $skipped );
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

if ( ! function_exists( 'd5b_maint_linked_error' ) ) {
	/** The refusal for a plugin or theme that is a link or holds one ($what: "is a link" / "contains a link"). Never bypassed. */
	function d5b_maint_linked_error( string $kind, string $what ): WP_Error {
		return new WP_Error( 'linked', 'The ' . $kind . ' ' . $what . ' (a symbolic link or a junction). WordPress would follow it when it removes or replaces the files, and change what it points to, so nothing was changed (without_copy does not lift this). Change it on the server by hand.', array( 'status' => 409 ) );
	}
}

if ( ! function_exists( 'd5b_maint_copy_bypassable' ) ) {
	/** Whether without_copy may go on after this keep-copy error: only copy_too_big, store_full and a genuine failed copy. */
	function d5b_maint_copy_bypassable( WP_Error $e ): bool {
		return in_array( $e->get_error_code(), array( 'copy_too_big', 'store_full', 'copy_failed' ), true );
	}
}

if ( ! function_exists( 'd5b_keep_copy' ) ) {
	/**
	 * Keeps a copy of a plugin folder (WP_PLUGIN_DIR/<dir_name>), a single-file plugin (WP_PLUGIN_DIR/<file>.php) or a theme
	 * folder (the theme root/<dir_name>) in the store, before it is changed: <store>/<row id>-<32 hex>/<kind>/<dir_name>,
	 * a mirror of the original (a folder for a folder, the file for a single-file plugin), made with core's copy_dir.
	 * The size is measured before anything is written. Errors: linked (the plugin or theme is a link or holds one: never
	 * copied, and a caller must not change it either, whatever without_copy says, because core's delete and the upgrader's
	 * clear_destination follow links), copy_too_big (over 512 MB), store_full (the store plus this copy over 2 GB; nothing is
	 * ever deleted early to make room), copy_failed (with the reason; a part-made copy is removed). manifest_sha1: the
	 * fingerprint of the copy's file list (d5b_maint_manifest_keep), stored in the row; a restore refuses a copy that no
	 * longer matches it.
	 * @return array{path:string,bytes:int,files:int,manifest_sha1:string}|WP_Error
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
		if ( d5b_maint_is_link( $src ) ) { return d5b_maint_linked_error( $kind, 'is a link' ); }
		$single = is_file( $src );
		if ( ! $single && ! is_dir( $src ) ) { return $fail( 'the ' . $kind . ' was not found' ); }
		if ( $single && 'theme' === $kind ) { return $fail( 'a theme is a folder' ); }

		$size = d5b_maint_tree_size( $src );
		if ( $size['links'] > 0 ) { return d5b_maint_linked_error( $kind, 'contains a link' ); }
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
		$copy   = function () use ( $folder, $kind, $single, $src, $dest, $wp_filesystem ): bool {
			if ( ! is_dir( $folder . '/' . $kind ) && ! @mkdir( $folder . '/' . $kind, 0755, true ) ) { return false; }
			if ( $single ) { return (bool) $wp_filesystem->copy( $src, $dest, false, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ); }
			return true === copy_dir( $src, $dest );
		};
		// A copy is only kept when it is the whole of the original. When it differs because the original itself changed
		// while it was copied (a plugin writing its own cache or log files), it is made once more from the original as it is
		// now; a second change refuses.
		$done = $copy();
		$got  = $done ? d5b_maint_tree_size( $dest ) : null;
		if ( $done && $got !== $size ) {
			$now = d5b_maint_tree_size( $src );
			if ( $now !== $size && 0 === $now['links'] && $now['bytes'] <= d5b_maint_copy_cap() && $store + $now['bytes'] <= d5b_maint_store_cap() ) {
				d5b_maint_remove_tree( $dest ); clearstatcache();
				$size = $now;
				$done = ! file_exists( $dest ) && $copy();
				$got  = $done ? d5b_maint_tree_size( $dest ) : null;
				if ( $done && ( $got !== $size || d5b_maint_tree_size( $src ) !== $size ) ) {
					d5b_history_files_remove( $row_id, $name );
					return $fail( 'the ' . $kind . ' changed its own files while they were being copied; try again when it is idle' );
				}
			}
		}
		if ( ! $done || $got !== $size ) {
			d5b_history_files_remove( $row_id, $name );
			return $fail( $done ? 'the copy is not complete' : 'copying the files failed' );
		}
		// Its file list, fingerprinted for the row: a restore refuses a copy that no longer matches (copy_tampered).
		$sha = d5b_maint_manifest_keep( $dest );
		if ( '' === $sha ) {
			d5b_history_files_remove( $row_id, $name );
			return $fail( 'the copy could not be listed' );
		}
		return array( 'path' => $dest, 'bytes' => $size['bytes'], 'files' => $size['files'], 'manifest_sha1' => $sha );
	}
}

if ( ! function_exists( 'd5b_maint_item_key' ) ) {
	/** The key that names the item in a history row and a result: plugin (the plugin file) or stylesheet (the theme folder). */
	function d5b_maint_item_key( string $kind ): string {
		return 'theme' === $kind ? 'stylesheet' : 'plugin';
	}
}

if ( ! function_exists( 'd5b_maint_parent' ) ) {
	/** The folder a plugin (WP_PLUGIN_DIR) or a theme (its theme root) lives in, forward slashes, no trailing slash; '' when not known. */
	function d5b_maint_parent( string $kind, string $dir ): string {
		if ( 'plugin' === $kind ) { $p = defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : ''; }
		elseif ( 'theme' === $kind ) { $p = function_exists( 'get_theme_root' ) ? (string) get_theme_root( $dir ) : ''; }
		else { $p = ''; }
		return '' === $p ? '' : rtrim( str_replace( '\\', '/', $p ), '/' );
	}
}

if ( ! function_exists( 'd5b_maint_version' ) ) {
	/** The installed version of a plugin (by its file) or a theme (by its stylesheet), as WordPress reads it now; '' when not installed. */
	function d5b_maint_version( string $kind, string $item ): string {
		if ( 'plugin' === $kind ) {
			if ( ! function_exists( 'get_plugins' ) ) { return ''; }
			$all = (array) get_plugins();
			return isset( $all[ $item ]['Version'] ) ? (string) $all[ $item ]['Version'] : '';
		}
		if ( ! function_exists( 'wp_get_theme' ) ) { return ''; }
		// WordPress keeps each theme's headers for the whole request, and wp_clean_themes_cache only drops the themes it still
		// lists (a folder that lost its style.css is not listed): read this one from disk, as Theme_Upgrader::theme_info does.
		$t = wp_get_theme( $item );
		if ( is_object( $t ) && method_exists( $t, 'cache_delete' ) ) { $t->cache_delete(); $t = wp_get_theme( $item ); }
		return is_object( $t ) && $t->exists() ? (string) $t->get( 'Version' ) : '';
	}
}

if ( ! function_exists( 'd5b_maint_clean_cache' ) ) {
	/** Makes WordPress read the plugins (or the themes) from disk again, and forget their stored update offers. */
	function d5b_maint_clean_cache( string $kind ): void {
		if ( 'plugin' === $kind && function_exists( 'wp_clean_plugins_cache' ) ) { wp_clean_plugins_cache( true ); }
		if ( 'theme' === $kind && function_exists( 'wp_clean_themes_cache' ) ) { wp_clean_themes_cache( true ); }
	}
}

if ( ! function_exists( 'd5b_maint_remove_tree' ) ) {
	/**
	 * Removes a plugin or theme folder (or a single file) with WordPress's file system ($wp_filesystem->delete, as core's
	 * delete_plugins does). A folder holding a link (or a junction) is refused: core's delete would follow it and empty what
	 * it points to. @return bool true when nothing is left at $path
	 */
	function d5b_maint_remove_tree( string $path ): bool {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) { return true; }
		if ( d5b_maint_is_link( $path ) || d5b_maint_tree_size( $path )['links'] > 0 ) { return false; }
		if ( ! d5b_maint_filesystem() ) { return false; }
		global $wp_filesystem;
		$wp_filesystem->delete( $path, is_dir( $path ) );
		clearstatcache();
		return ! file_exists( $path );
	}
}

if ( ! function_exists( 'd5b_maint_rename' ) ) {
	/** bin2hex( random_bytes( $n ) ); a test replaces it with $GLOBALS['d5b_maint_seams']['hex'] (to predict a name). */
	function d5b_maint_hex( int $n ): string {
		$f = $GLOBALS['d5b_maint_seams']['hex'] ?? null;
		if ( is_callable( $f ) ) { return (string) $f( $n ); }
		return bin2hex( random_bytes( max( 1, $n ) ) );
	}

	/** rename( $from, $to ); a test replaces it with $GLOBALS['d5b_maint_seams']['rename'] (to refuse one, as a host can). */
	function d5b_maint_rename( string $from, string $to ): bool {
		$f = $GLOBALS['d5b_maint_seams']['rename'] ?? null;
		if ( is_callable( $f ) ) { return (bool) $f( $from, $to ); }
		return @rename( $from, $to );
	}
}

if ( ! function_exists( 'd5b_maint_tree_sig' ) ) {
	/**
	 * What a folder (or a file) holds, to compare two trees: relative path => size and sha1 of the bytes (a folder: 'dir'),
	 * sorted. null when it holds a link (never followed) or is not there.
	 * @return array<string,string>|null
	 */
	function d5b_maint_tree_sig( string $path ): ?array {
		if ( d5b_maint_is_link( $path ) ) { return null; }
		if ( is_file( $path ) ) { return array( '' => (int) @filesize( $path ) . ':' . (string) @sha1_file( $path ) ); }
		if ( ! is_dir( $path ) ) { return null; }
		$out  = array();
		$walk = function ( string $dir, string $rel ) use ( &$walk, &$out ): bool {
			foreach ( (array) @scandir( $dir ) as $f ) {
				if ( '.' === $f || '..' === $f || ! is_string( $f ) ) { continue; }
				$p = $dir . '/' . $f;
				$r = '' === $rel ? $f : $rel . '/' . $f;
				if ( d5b_maint_is_link( $p ) ) { return false; }
				if ( is_dir( $p ) ) { $out[ $r . '/' ] = 'dir'; if ( ! $walk( $p, $r ) ) { return false; } continue; }
				$out[ $r ] = (int) @filesize( $p ) . ':' . (string) @sha1_file( $p );
			}
			return true;
		};
		if ( ! $walk( rtrim( $path, '/' ), '' ) ) { return null; }
		ksort( $out, SORT_STRING );
		return $out;
	}
}

if ( ! function_exists( 'd5b_maint_copy_in' ) ) {
	/** Copies a folder (copy_dir) or a single file ($wp_filesystem->copy) to $to, which must not exist yet. @return bool true when $to is a whole copy (tree compare) */
	function d5b_maint_copy_in( string $from, string $to ): bool {
		if ( file_exists( $to ) || ! d5b_maint_filesystem() ) { return false; }
		global $wp_filesystem;
		$want = d5b_maint_tree_sig( $from );
		if ( null === $want ) { return false; }
		$ok = is_dir( $from ) ? true === copy_dir( $from, $to ) : (bool) $wp_filesystem->copy( $from, $to, false, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
		clearstatcache();
		return $ok && d5b_maint_tree_sig( $to ) === $want;
	}
}

if ( ! function_exists( 'd5b_maint_move' ) ) {
	/**
	 * Moves a folder or a file: a rename, or (another disk, a host that refuses the rename) a whole copy, then the original
	 * removed. Once the copy at $to is whole it is KEPT whatever happens to $from: when $from cannot be removed after that,
	 * the answer is moved with a leftover. A part-made copy is removed again and $from is left as it was (not moved).
	 * Something already at $to (a folder, a file or a link) refuses the move: not moved, and nothing removed on either side.
	 * @return array{moved:bool,leftover:bool}
	 */
	function d5b_maint_move( string $from, string $to ): array {
		if ( file_exists( $to ) || is_link( $to ) ) { return array( 'moved' => false, 'leftover' => false ); }
		if ( d5b_maint_rename( $from, $to ) ) { return array( 'moved' => true, 'leftover' => false ); }
		if ( ! d5b_maint_copy_in( $from, $to ) ) {
			d5b_maint_remove_tree( $to );
			return array( 'moved' => false, 'leftover' => false );
		}
		return array( 'moved' => true, 'leftover' => ! d5b_maint_remove_tree( $from ) );
	}
}

if ( ! function_exists( 'd5b_maint_maintenance' ) ) {
	/**
	 * WordPress's maintenance mode on or off, as core's upgraders switch it around a swap (WP_Upgrader::maintenance_mode
	 * writes or removes ABSPATH/.maintenance): visitors get "Briefly unavailable" for the moment the files change. false when
	 * the upgrader class or the file system cannot be had (the swap still runs: it is two renames).
	 * Off removes .maintenance only when this request's on wrote it: a file that was there first (another update running,
	 * core's or a person's) is neither rewritten nor removed.
	 */
	function d5b_maint_maintenance( bool $on ): bool {
		if ( ! class_exists( 'WP_Upgrader' ) && function_exists( 'wpsu_load_admin' ) ) { wpsu_load_admin(); }
		if ( ! class_exists( 'WP_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) || ! d5b_maint_filesystem() ) { return false; }
		global $wp_filesystem;
		try {
			if ( $on ) {
				$GLOBALS['d5b_maint_mm_created'] = false;
				if ( $wp_filesystem->exists( $wp_filesystem->abspath() . '.maintenance' ) ) { return true; } // on already, not ours
				$u = new WP_Upgrader( new Automatic_Upgrader_Skin() );
				$u->maintenance_mode( true );
				$GLOBALS['d5b_maint_mm_created'] = true;
				return true;
			}
			if ( empty( $GLOBALS['d5b_maint_mm_created'] ) ) { return true; } // not written by this request: left as it is
			$GLOBALS['d5b_maint_mm_created'] = false;
			$u = new WP_Upgrader( new Automatic_Upgrader_Skin() );
			$u->maintenance_mode( false );
			return true;
		} catch ( Throwable $e ) {
			return false;
		}
	}
}

if ( ! function_exists( 'd5b_maint_wpsu_error' ) ) {
	/** An error of the update engine (wpsu_update) as an ability error code with its message. */
	function d5b_maint_wpsu_error( WP_Error $e ): WP_Error {
		$map = array( 'wpsu_unknown' => 'not_found', 'wpsu_blocked' => 'not_allowed', 'wpsu_site_blocked' => 'not_allowed', 'wpsu_forbidden' => 'forbidden' );
		return new WP_Error( $map[ $e->get_error_code() ] ?? 'unavailable', d5b_maint_err_text( $e ) );
	}
}

if ( ! function_exists( 'd5b_maint_err_text' ) ) {
	/** The plain text of an error (some carry HTML), at most 300 characters. */
	function d5b_maint_err_text( WP_Error $e ): string {
		$m = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $e->get_error_message() ) ) );
		if ( '' === $m ) { return (string) $e->get_error_code(); }
		return strlen( $m ) > 300 ? substr( $m, 0, 297 ) . '...' : $m;
	}
}

if ( ! function_exists( 'd5b_maint_kept_public' ) ) {
	/** A kept copy as a result shows it: bytes and files, never its path. null stays null. */
	function d5b_maint_kept_public( $kept ): ?array {
		return is_array( $kept ) ? array( 'bytes' => (int) ( $kept['bytes'] ?? 0 ), 'files' => (int) ( $kept['files'] ?? 0 ) ) : null;
	}
}

if ( ! function_exists( 'd5b_maint_restore' ) ) {
	/**
	 * Puts back the copy kept by history row $history_id (an update, rollback or delete of the plugin or theme in folder
	 * $dir, or the single-file plugin $dir). Never acts on a copy outside the store: the kept path must be
	 * <store>/<history_id>-<32 hex>/<kind>/<dir>. The caller checks the row belongs to the item and runs the page checks.
	 *  1. the checks: the copy (copy_missing), the files being replaced against the 512 MB per-copy cap (copy_too_big) and the
	 *     2 GB store cap (store_full);
	 *  2. a new rollback row FIRST (before: the version being replaced);
	 *  3. the kept copy is copied into a staging folder beside the live one, .d5b-new-<dir>-<6 random hex>, and compared file
	 *     by file with the kept copy: a copy that is not whole never touches the live folder (copy_failed; the row removed);
	 *  4. maintenance mode on; the live folder renamed to .d5b-old-<dir>-<6 random hex>, the staging one renamed to <dir>;
	 *     maintenance mode off; the PHP opcode cache of the folder invalidated. Both names start with a dot, so WordPress
	 *     never lists a leftover as a plugin;
	 *  5. the replaced files moved into the new row's store folder: the rollback row's kept (a rollback is undoable). Once a
	 *     whole copy is in the store it is kept; a leftover is named in the warnings.
	 * The kept copy stays in the store (it can be put back twice). $opts['keep_optional'] (the automatic restore of
	 * rollback_on_failure): when the replaced files are too big or the store is full, the restore still runs, the replaced
	 * files are removed instead of kept (kept null) and a warning says so. A live folder that is or holds a link is refused
	 * (linked) whatever the options.
	 * The copy must still match the file list fingerprinted in its row (kept.manifest_sha1), else copy_tampered. The whole
	 * restore holds the item's lock (d5b_maint_item_lock, 600 s, an options-table row): a second update or rollback of the same folder
	 * meanwhile is busy and touches nothing. The rollback row is written before the renames with the two folder names
	 * (aside, staging), so a request that dies between them leaves a row that says where the files are.
	 * Errors: invalid_input, not_found (no such row, no copy recorded), copy_missing, copy_tampered, busy, linked,
	 * copy_too_big, store_full, copy_failed, history_unavailable.
	 * @return array{history_id:int,version:string,replaced_version:string,kept:?array,warnings:string[]}|WP_Error
	 */
	function d5b_maint_restore( string $kind, string $dir, int $history_id, array $opts = array() ) {
		$lock  = d5b_maint_item_lock( $kind, $dir );
		$token = d5b_maint_lock_take( $lock, d5b_maint_item_lock_ttl(), 'An update or rollback of this ' . ( 'theme' === $kind ? 'theme' : 'plugin' ), true ); // network-wide on a network: the folders are shared
		if ( is_wp_error( $token ) ) { return $token; }
		try {
			return d5b_maint_restore_run( $kind, $dir, $history_id, $opts );
		} finally {
			d5b_maint_lock_release( $lock, $token, true );
		}
	}

	/** The restore itself, under the item's lock (d5b_maint_restore). */
	function d5b_maint_restore_run( string $kind, string $dir, int $history_id, array $opts ) {
		$fail = function ( string $why ) { return new WP_Error( 'copy_failed', 'The kept copy could not be put back: ' . $why . '.', array( 'status' => 503 ) ); };
		if ( ( 'plugin' !== $kind && 'theme' !== $kind ) || '' === $dir || '.' === $dir[0] || 1 === preg_match( '#[/\\\\\x00]#', $dir ) ) {
			return new WP_Error( 'invalid_input', 'Not a plugin or theme folder name: ' . substr( preg_replace( '/[^A-Za-z0-9._\- ]/', '?', $dir ), 0, 100 ) . '.' );
		}
		$row  = d5b_history_get( $history_id );
		$kept = is_array( $row ) ? ( $row['after']['kept'] ?? null ) : null;
		if ( ! is_array( $kept ) || ! is_string( $kept['path'] ?? null ) ) { return new WP_Error( 'not_found', 'History row ' . $history_id . ' kept no copy.' ); }
		$key  = d5b_maint_item_key( $kind );
		$item = (string) ( $row['before'][ $key ] ?? '' );
		$root = d5b_history_files_root();
		$src  = str_replace( '\\', '/', (string) $kept['path'] );
		$re   = '#^' . preg_quote( $root, '#' ) . '/' . $history_id . '-[0-9a-f]{32}/' . preg_quote( $kind, '#' ) . '/' . preg_quote( $dir, '#' ) . '$#D';
		$gone = new WP_Error( 'copy_missing', 'The copy kept by history row ' . $history_id . ' is no longer there (copies are removed with their row after 30 days).' );
		if ( '' === $root || 1 !== preg_match( $re, $src ) ) { return $gone; }
		foreach ( array( $src, dirname( $src ), dirname( $src, 2 ) ) as $p ) { if ( d5b_maint_is_link( $p ) ) { return $gone; } }
		$single = is_file( $src );
		if ( ! $single && ! is_dir( $src ) ) { return $gone; }
		if ( $single && 'theme' === $kind ) { return $gone; }
		$size = d5b_maint_tree_size( $src );
		if ( $size['links'] > 0 ) { return $fail( 'the copy contains a link' ); }

		$parent = d5b_maint_parent( $kind, $dir );
		if ( '' === $parent ) { return $fail( 'the ' . $kind . 's folder is not known' ); }
		$target = $parent . '/' . $dir;
		// A live folder that is a link or holds one is never swapped, whatever the options.
		if ( d5b_maint_is_link( $target ) ) { return d5b_maint_linked_error( $kind, 'is a link' ); }
		$cur = file_exists( $target ) ? d5b_maint_tree_size( $target ) : null;
		if ( null !== $cur && $cur['links'] > 0 ) { return d5b_maint_linked_error( $kind, 'contains a link' ); }
		// The copy must be exactly what was kept (its file list against the row's fingerprint).
		$ok = d5b_maint_manifest_check( $src, $kept );
		if ( true !== $ok ) { return $ok; }
		// Keeping the files being replaced: refused for space, unless $opts['keep_optional'] (an automatic restore after a
		// broken update: the broken site matters more than the version the update just downloaded).
		$no_keep = '';
		$store   = d5b_history_store_bytes();
		if ( null !== $cur && $cur['bytes'] > d5b_maint_copy_cap() ) {
			$e = new WP_Error( 'copy_too_big', 'The files being replaced are ' . $cur['bytes'] . ' bytes, over the 512 MB a kept copy may take, so nothing was put back.', array( 'status' => 413, 'bytes' => $cur['bytes'], 'cap' => d5b_maint_copy_cap() ) );
			if ( empty( $opts['keep_optional'] ) ) { return $e; }
			$no_keep = 'they are over the 512 MB a kept copy may take';
		} elseif ( null !== $cur && $store + $cur['bytes'] > d5b_maint_store_cap() ) {
			$oldest = d5b_maint_oldest_row_at();
			$e = new WP_Error( 'store_full', 'The kept copies take ' . $store . ' bytes; keeping the files being replaced (' . $cur['bytes'] . ' bytes) would take the store over 2 GB. Copies are removed with their history row after 30 days' . ( null === $oldest ? '' : '; the oldest row is from ' . $oldest . ' UTC' ) . '.', array( 'status' => 507, 'store_bytes' => $store, 'bytes' => $cur['bytes'], 'cap' => d5b_maint_store_cap(), 'oldest_row_at' => $oldest ) );
			if ( empty( $opts['keep_optional'] ) ) { return $e; }
			$no_keep = 'the change history store is full (2 GB)';
		}
		if ( ! d5b_history_files_prepare() ) { return $fail( 'the store folder could not be made' ); }
		if ( ! d5b_maint_filesystem() ) { return $fail( 'no file system access (copy_dir)' ); }

		$replaced = '' === $item ? '' : d5b_maint_version( $kind, $item );
		$to       = (string) ( $row['before']['version'] ?? '' );

		// Two names beside the live folder (a rename there does not cross a disk), both starting with a dot: WordPress skips
		// those when it lists plugins, so a leftover never shows up as a second plugin.
		// 6 random hex characters each: a name taken anyway (a leftover of a request that died) is refused below, before the
		// row is written, and that folder is never touched (copy_failed, nothing changed).
		$staging = $parent . '/.d5b-new-' . $dir . '-' . d5b_maint_hex( 3 );
		$aside   = null === $cur ? '' : $parent . '/.d5b-old-' . $dir . '-' . d5b_maint_hex( 3 );
		$taken   = function ( string $p ): bool { return '' !== $p && ( file_exists( $p ) || is_link( $p ) ); };
		if ( $taken( $staging ) || $taken( $aside ) ) { return $fail( 'copying the files failed; nothing was changed' ); }
		$where   = 'the ' . $kind . 's folder';

		// The row first, naming both folders (names only, in the plugins or themes folder): a request that dies between the
		// renames leaves a row that says where the files are.
		$new = d5b_wc_history_row( 'divi5-builder/' . $kind . 's-rollback', $kind, 0, 'rollback',
			array( $key => $item, 'version' => $replaced, 'from_history_id' => $history_id ),
			array( 'running' => true, 'staging' => basename( $staging ), 'aside' => '' === $aside ? null : basename( $aside ), 'folder' => $where ),
			'Rolled back ' . $kind . ' ' . ( '' === $item ? $dir : $item ) . ( '' === $to ? '' : ' to ' . $to ) );
		if ( $new < 1 ) { return new WP_Error( 'history_unavailable', 'The change history row could not be written, so nothing was put back.' ); }

		// 1. the kept copy into the staging folder, compared file by file: a copy that is not whole never touches the live one
		if ( ! d5b_maint_copy_in( $src, $staging ) ) { // the name was free above: what is there now is this request's part-made copy
			$clean = d5b_maint_remove_tree( $staging );
			d5b_history_delete( $new );
			return $fail( $clean
				? 'copying the files failed; nothing was changed'
				: 'copying the files failed; the ' . $kind . ' itself was not changed, but the part-made copy could not be removed: delete ' . basename( $staging ) . ' in ' . $where . ' by hand' );
		}

		// 2. the swap: two renames, in maintenance mode as core's upgrader does it
		$swapped = false; $err = '';
		$mm = d5b_maint_maintenance( true );
		try {
			if ( '' !== $aside && ! d5b_maint_rename( $target, $aside ) ) {
				$err = 'the live files could not be moved aside';
			} elseif ( ! d5b_maint_rename( $staging, $target ) ) {
				$err = 'the copy could not be moved into place';
				if ( '' !== $aside && ! d5b_maint_rename( $aside, $target ) ) {
					$err .= ' AND the live files could not be put back: they are in ' . basename( $aside ) . ' in ' . $where . '; rename it to ' . $dir . ' at once';
				}
			} else {
				$swapped = true;
			}
		} finally {
			if ( $mm ) { d5b_maint_maintenance( false ); }
		}
		if ( ! $swapped ) {
			$clean    = d5b_maint_remove_tree( $staging );
			$stranded = false !== strpos( $err, ' AND ' ); // the live files are in the aside folder, the live folder is gone
			if ( $stranded ) {
				// Never deleted: the row is the record of where the live files are.
				d5b_history_set_after( $new, array( 'running' => false, 'failed' => 'the copy could not be moved into place and the live files could not be put back', 'aside' => basename( $aside ), 'staging' => $clean ? null : basename( $staging ), 'folder' => $where ) );
			} else {
				d5b_history_delete( $new );
			}
			d5b_maint_clean_cache( $kind );
			$e = $fail( $err . ( $stranded ? '' : '; nothing was changed' ) . ( $clean ? '' : '. The staging copy ' . basename( $staging ) . ' in ' . $where . ' could not be removed; delete it by hand' ) . ( $stranded ? '. History row ' . $new . ' names the folder' : '' ) );
			if ( $stranded ) { $e->add_data( array( 'status' => 503, 'history_id' => $new ) ); }
			return $e;
		}
		if ( function_exists( 'wp_opcache_invalidate_directory' ) && is_dir( $target ) ) { wp_opcache_invalidate_directory( $target ); }
		elseif ( function_exists( 'wp_opcache_invalidate' ) && is_file( $target ) ) { wp_opcache_invalidate( $target, true ); }

		// 3. the replaced files into the new row's store folder: the rollback is itself undoable
		$warn = array(); $kept_new = null; $left_aside = null; // $left_aside: the dot folder still holding replaced files
		if ( '' !== $aside && '' !== $no_keep ) {
			// keep_optional and no room: the replaced (just-downloaded) files are removed instead of kept.
			$gone   = d5b_maint_remove_tree( $aside );
			if ( ! $gone ) { $left_aside = basename( $aside ); }
			$warn[] = 'The version that was replaced was not kept, because ' . $no_keep . ': this rollback itself cannot be undone from the change history (update again to get that version back).'
				. ( $gone ? '' : ' Its files could not be removed either: delete ' . basename( $aside ) . ' in ' . $where . ' by hand (WordPress does not list it).' );
		} elseif ( '' !== $aside ) {
			$folder = d5b_history_files_root() . '/' . $new . '-' . bin2hex( random_bytes( 16 ) );
			$dest   = $folder . '/' . $kind . '/' . $dir;
			$mv     = @mkdir( $folder . '/' . $kind, 0755, true ) ? d5b_maint_move( $aside, $dest ) : array( 'moved' => false, 'leftover' => false );
			if ( $mv['moved'] ) {
				// A whole copy is in the store: it is the record, kept whatever happened to the files beside the live folder.
				$kept_new = array( 'path' => $dest, 'bytes' => $cur['bytes'], 'files' => $cur['files'], 'manifest_sha1' => d5b_maint_manifest_keep( $dest ) );
				if ( $mv['leftover'] ) {
					$warn[] = 'The files that were replaced are kept in the change history store, but a leftover of them could not be removed from ' . $where . ': delete ' . basename( $aside ) . ' there by hand (WordPress does not list it).';
				}
			} else {
				d5b_history_files_remove( $new ); // only the empty (or part-made, already removed) store folder of this row
				$left_aside = basename( $aside ); // the only copy of the replaced files: the row keeps naming it
				$warn[] = 'The files that were replaced could not be moved into the change history store: they are still in ' . basename( $aside ) . ' in ' . $where . ' (WordPress does not list it). Keep that folder until the site is fine; it is the only copy of them.';
			}
		}
		d5b_maint_clean_cache( $kind );
		$version = '' === $item ? '' : d5b_maint_version( $kind, $item );
		$final = array( 'version' => $version, 'kept' => $kept_new, 'from_history_id' => $history_id );
		if ( null !== $left_aside ) { $final += array( 'aside' => $left_aside, 'staging' => null, 'folder' => $where ); }
		if ( ! d5b_history_set_after( $new, $final ) ) {
			$warn[] = 'The change history row of the rollback could not be completed.';
		}
		return array( 'history_id' => $new, 'version' => $version, 'replaced_version' => $replaced, 'kept' => $kept_new, 'warnings' => $warn );
	}
}

if ( ! function_exists( 'd5b_maint_update_sequence' ) ) {
	/**
	 * One checked update of a plugin (kind plugin, $item the plugin file) or a theme (kind theme, $item the stylesheet), whose
	 * files are WP_PLUGIN_DIR/$dir or the theme root/$dir. In this order:
	 *  1. a dry run of the update engine (wpsu_update( kind, item, true )): nothing offered is already_up_to_date (no row, no
	 *     copy); an item the engine does not know, a blocked offer or a site without file changes is refused;
	 *  2. the history row FIRST (its id names the store folder): before { <plugin|stylesheet>, version };
	 *  3. d5b_keep_copy( kind, $dir, row id ); on an error the row is deleted and the update refused with that error, unless
	 *     $opts['without_copy']: then kept is null and the result says the update cannot be put back by this plugin;
	 *  4. wpsu_update( kind, item, false ) inside d5b_pages_check (output swallowed, a throw caught);
	 *  5. d5b_history_set_after: { version (read from disk), kept, pages, rolled_back } (+ failed);
	 *  6. $opts['rollback_on_failure'] and (the pages got worse or the update failed) with a copy: d5b_maint_restore at once,
	 *     then one more page round, compared with the round from before: pages_after_restore { after, problems } in the
	 *     row and the result.
	 * A failed update is the error update_failed whose data carries the same fields as a result.
	 * @return array|WP_Error
	 */
	function d5b_maint_update_sequence( string $kind, string $item, string $dir, array $opts ) {
		if ( 'plugin' !== $kind && 'theme' !== $kind ) { return new WP_Error( 'invalid_input', 'kind must be plugin or theme.' ); }
		if ( ! function_exists( 'wpsu_update' ) ) { return new WP_Error( 'unavailable', 'The update code is not loaded on this site, so nothing was updated.' ); }
		// The item's lock (600 s, an options-table row) for the whole update: a rollback or another update of the same folder meanwhile is
		// busy. The automatic restore below runs under the same lock (a nested take).
		$lock  = d5b_maint_item_lock( $kind, $dir );
		$token = d5b_maint_lock_take( $lock, d5b_maint_item_lock_ttl(), 'An update or rollback of this ' . $kind, true ); // network-wide on a network
		if ( is_wp_error( $token ) ) { return $token; }
		try {
			return d5b_maint_update_run( $kind, $item, $dir, $opts );
		} finally {
			d5b_maint_lock_release( $lock, $token, true );
		}
	}

	/** The update itself, under the item's lock (d5b_maint_update_sequence). */
	function d5b_maint_update_run( string $kind, string $item, string $dir, array $opts ) {
		$key = d5b_maint_item_key( $kind );
		$dry = wpsu_update( $kind, $item, true );
		if ( is_wp_error( $dry ) ) { return d5b_maint_wpsu_error( $dry ); }
		if ( empty( $dry['dry_run'] ) ) {
			return array( $key => $item, 'updated' => false, 'already_up_to_date' => true, 'version' => (string) ( $dry['from'] ?? '' ) );
		}
		$from = (string) ( $dry['from'] ?? '' ); $to = (string) ( $dry['to'] ?? '' );

		$hid = d5b_wc_history_row( 'divi5-builder/' . $kind . 's-update', $kind, 0, 'update', array( $key => $item, 'version' => $from ), array( 'running' => true, 'to' => $to ),
			'Updated ' . $kind . ' ' . $item . ' from ' . $from . ' to ' . $to );
		if ( $hid < 1 ) { return new WP_Error( 'history_unavailable', 'The change history row could not be written, so the ' . $kind . ' was not updated.' ); }
		$kept = d5b_keep_copy( $kind, $dir, $hid );
		$copy_error = null;
		if ( is_wp_error( $kept ) ) {
			// without_copy lifts only a copy that is too big, a full store or a failed copy; never "linked".
			if ( empty( $opts['without_copy'] ) || ! d5b_maint_copy_bypassable( $kept ) ) { d5b_history_delete( $hid ); return $kept; }
			$copy_error = $kept; $kept = null;
		}

		$res = null;
		$pages = d5b_pages_check( function () use ( $kind, $item, &$res ) {
			$level = ob_get_level();
			ob_start(); // an upgrader skin or a plugin's update hook may print; that would break the JSON answer
			try {
				$res = wpsu_update( $kind, $item, false );
			} catch ( Throwable $e ) {
				$res = new WP_Error( 'internal_error', 'the updater stopped with an error (' . get_class( $e ) . ')' );
			} finally {
				while ( ob_get_level() > $level ) { ob_end_clean(); }
			}
		} );
		$done     = is_array( $res ) && ! empty( $res['done'] );
		$error    = is_wp_error( $res ) ? d5b_maint_err_text( $res ) : ( is_array( $res ) ? (string) ( $res['error'] ?? '' ) : 'the updater gave no answer' );
		$version  = d5b_maint_version( $kind, $item );
		$problems = (array) $pages['problems'];
		$after    = array( 'version' => $version, 'kept' => $kept, 'pages' => $pages, 'rolled_back' => false );
		if ( ! $done ) { $after['failed'] = '' === $error ? 'unknown' : $error; }
		$warn = array();
		if ( ! d5b_history_set_after( $hid, $after ) ) { $warn[] = 'The change history row could not be completed.'; }

		$rolled = false; $rb_id = 0;
		if ( ! empty( $opts['rollback_on_failure'] ) && ( array() !== $problems || ! $done ) ) {
			if ( null === $kept ) {
				$warn[] = 'rollback_on_failure was asked, but no copy was kept, so nothing was put back.';
			} else {
				$rb = d5b_maint_restore( $kind, $dir, $hid, array( 'keep_optional' => true ) ); // the site first: keeping the broken version is optional
				if ( is_wp_error( $rb ) ) {
					$warn[] = 'rollback_on_failure: the previous version could not be put back: ' . d5b_maint_err_text( $rb );
				} else {
					$rolled = true; $rb_id = (int) $rb['history_id']; $version = (string) $rb['version'];
					$warn   = array_merge( $warn, (array) $rb['warnings'] );
					// One more page round after the restore, compared with the round from before the update.
					$urls   = array_values( array_map( function ( $p ) { return (string) $p['url']; }, array_filter( (array) $pages['before'], 'is_array' ) ) );
					$round  = d5b_pages_round( $urls, 60, true );
					$after_restore = array( 'after' => $round, 'problems' => d5b_pages_compare( (array) $pages['before'], $round ) );
					$after['rolled_back'] = true; $after['rollback_history_id'] = $rb_id; $after['pages_after_restore'] = $after_restore;
					if ( ! d5b_history_set_after( $hid, $after ) ) { $warn[] = 'The change history row could not be completed.'; }
				}
			}
		}
		if ( null !== $copy_error ) {
			$warn[] = 'No copy of the previous version was kept (' . $copy_error->get_error_code() . ': ' . d5b_maint_err_text( $copy_error ) . '), as without_copy allowed: this plugin cannot put the previous version back.';
		}
		$warn = array_merge( $warn, d5b_pages_warnings( $pages, ! empty( $opts['rollback_on_failure'] ) ) );

		$out = array(
			$key => $item, 'from' => $from, 'to' => $to, 'version' => $version, 'updated' => $done,
			'kept' => d5b_maint_kept_public( $kept ), 'history_id' => $hid, 'rolled_back' => $rolled,
		);
		if ( $rb_id > 0 ) { $out['rollback_history_id'] = $rb_id; $out['pages_after_restore'] = $after_restore; }
		if ( null !== $copy_error ) { $out['copy_error'] = $copy_error->get_error_code(); }
		$out += array(
			'problems' => $problems, 'pages' => $pages, 'human_check' => d5b_human_check(),
			'caches_cleared' => is_array( $res ) ? array_values( (array) ( $res['caches_cleared'] ?? array() ) ) : array(),
		);
		if ( $warn ) { $out['warnings'] = $warn; }
		if ( ! $done ) {
			return new WP_Error( 'update_failed', 'The ' . $kind . ' "' . $item . '" was not updated: ' . ( '' === $error ? 'unknown error' : $error ) . '.' . ( $rolled ? ' The kept copy was put back.' : ( null === $kept ? '' : ' A copy of the previous version is kept (history row ' . $hid . ').' ) ), $out );
		}
		return $out;
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
		// Another name for this plugin's own folder: a link (or junction) to it, or the folder reached another way. The real
		// path of the named plugin's folder is compared with this plugin's folder.
		if ( defined( 'D5B_FILE' ) && defined( 'WP_PLUGIN_DIR' ) && false !== strpos( $f, '/' ) && false === strpos( $f, "\0" ) ) {
			$mine = realpath( dirname( (string) D5B_FILE ) );
			$dir  = (string) WP_PLUGIN_DIR . '/' . dirname( ltrim( str_replace( '\\', '/', trim( $plugin_file ) ), '/' ) );
			$real = file_exists( $dir ) ? realpath( $dir ) : false;
			$norm = function ( string $p ): string { return strtolower( rtrim( str_replace( '\\', '/', $p ), '/' ) ); };
			if ( is_string( $mine ) && is_string( $real ) && $norm( $mine ) === $norm( $real ) ) { return true; }
		}
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
	 * A fixed-window rate limit: true when this attempt is allowed (and counts it), false when $limit attempts were already
	 * allowed in the current window. Windows are $window_s seconds long and start at multiples of it. Each key and window is
	 * one option row, d5b_rate_<key>_<window start> (not autoloaded), added with one INSERT IGNORE that never resets a count;
	 * the attempt is then ONE conditional UPDATE (+1 only while the count is under the limit), and it is allowed only when
	 * that UPDATE changed the row, so two requests in the same instant can never both take the last slot. A refused attempt is
	 * not counted. The call that opens a window removes this key's rows of windows before the previous one.
	 */
	function d5b_maint_rate( string $key, int $limit, int $window_s ): bool {
		global $wpdb;
		if ( $limit < 1 ) { return false; }
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return false; } // no database: fail closed
		$w     = max( 1, $window_s );
		$start = (int) ( floor( d5b_maint_now() / $w ) * $w );
		$base  = 'd5b_rate_' . $key . '_';
		if ( strlen( $base ) > 160 || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $key ) ) { $base = 'd5b_rate_' . md5( $key ) . '_'; } // option_name holds 191
		$name  = $base . $start;
		$table = $wpdb->options;
		if ( d5b_options_insert_once( $table, $name, '0' ) ) {
			// A new window: this key's rows of windows before the previous one go (the name's tail must be all digits, so a
			// longer key that starts the same, reset_user_77 next to reset_user_7, is never matched).
			$old = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM `{$table}` WHERE option_name LIKE %s", $wpdb->esc_like( $base ) . '%' ) );
			foreach ( (array) $old as $o ) {
				$tail = substr( (string) $o, strlen( $base ) );
				if ( '' !== $tail && ctype_digit( $tail ) && (int) $tail < $start - $w ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name = %s", (string) $o ) );
					if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( (string) $o, 'options' ); }
				}
			}
		}
		$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET option_value = option_value + 1 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $name, $limit ) );
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( $name, 'options' ); }
		return 1 === $n;
	}
}

if ( ! function_exists( 'd5b_maint_ok' ) ) {
	/** True when the current user has $cap and, with $network_admin on a network, is a network administrator. */
	function d5b_maint_ok( string $cap, bool $network_admin = false ): bool {
		return current_user_can( $cap ) && ( ! $network_admin || ! is_multisite() || is_super_admin() );
	}

	/** The permission callback for $cap (d5b_maint_ok). */
	function d5b_maint_can( string $cap, bool $network_admin = false ): callable {
		return function ( array $in = array() ) use ( $cap, $network_admin ) { return d5b_maint_ok( $cap, $network_admin ); };
	}
}

if ( ! function_exists( 'd5b_maint_lock_fresh' ) ) {
	/**
	 * The options table that holds the lock: this site's, or with $network on a multisite network the MAIN site's
	 * ($wpdb->base_prefix . 'options'), one row for the whole network (the plugins and themes folders are shared by every site).
	 */
	function d5b_maint_lock_table( bool $network ): string {
		global $wpdb;
		return ( $network && is_multisite() ) ? $wpdb->base_prefix . 'options' : (string) $wpdb->options;
	}

	/** The lock row's value as the database holds it now (never a cached copy); '' when there is none. */
	function d5b_maint_lock_fresh( string $name, bool $network = false ): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return ''; }
		$table = d5b_maint_lock_table( $network );
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$table}` WHERE option_name = %s LIMIT 1", $name ) );
		return null === $v ? '' : (string) $v;
	}

	/**
	 * Takes the lock $name for $ttl seconds. The mutex is the options table's UNIQUE option_name index: the lock is a row
	 * (not autoloaded) added with one INSERT IGNORE, so of two requests in the same instant exactly one adds it; the other is
	 * busy (409). The value is "<random token>|<expires at>". A row whose time has passed (a request that died) is taken over
	 * with one conditional UPDATE ... WHERE option_value = <the stale value read>, so of two requests taking over the same
	 * stale lock exactly one changes the row. A row without "|" or with an expiry that is not a number reads as expired
	 * (expires at 0) and is taken over the same way. A lock this request already holds is taken again at once (a nested call: an
	 * update that puts its copy back holds that item's lock). Released with d5b_maint_lock_release, in finally. $what names
	 * the work in the busy message. $network: the main site's table on a multisite network (d5b_maint_lock_table).
	 * @return string|WP_Error the value written (the caller's token for the release), or busy
	 */
	function d5b_maint_lock_take( string $name, int $ttl, string $what, bool $network = false ) {
		global $wpdb;
		$busy = new WP_Error( 'busy', $what . ' is already running in another request. Wait until it has finished (at most ' . $ttl . ' seconds), read the change history, then decide whether to try again.', array( 'status' => 409 ) );
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return $busy; } // no database: fail closed
		$table = d5b_maint_lock_table( $network );
		$key   = $table . ':' . $name;
		$mine  = $GLOBALS['d5b_maint_locks'][ $key ] ?? null;
		if ( is_array( $mine ) ) { $GLOBALS['d5b_maint_locks'][ $key ][1]++; return (string) $mine[0]; }
		$now   = (int) floor( d5b_maint_now() );
		$value = bin2hex( random_bytes( 8 ) ) . '|' . ( $now + $ttl );
		$won   = d5b_options_insert_once( $table, $name, $value );
		if ( ! $won ) {
			$held = d5b_maint_lock_fresh( $name, $network );
			if ( '' === $held ) {
				// released between the INSERT and the read: one more INSERT, which again only one request can win
				$won = d5b_options_insert_once( $table, $name, $value );
			} elseif ( (int) substr( (string) strrchr( $held, '|' ), 1 ) <= $now ) {
				$n   = $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $held ) );
				$won = 1 === $n;
				if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( $name, 'options' ); }
			}
		}
		if ( ! $won ) { return $busy; }
		$GLOBALS['d5b_maint_locks'][ $key ] = array( $value, 1 );
		return $value;
	}

	/**
	 * Releases the lock $name when its row still holds $token (one conditional DELETE: a lock another request has taken over
	 * meanwhile is left alone); a nested take only counts down. @return bool released
	 */
	function d5b_maint_lock_release( string $name, string $token, bool $network = false ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return false; }
		$table = d5b_maint_lock_table( $network );
		$key   = $table . ':' . $name;
		$mine  = $GLOBALS['d5b_maint_locks'][ $key ] ?? null;
		if ( is_array( $mine ) && $token === $mine[0] && $mine[1] > 1 ) { $GLOBALS['d5b_maint_locks'][ $key ][1]--; return true; }
		if ( is_array( $mine ) && $token === $mine[0] ) { unset( $GLOBALS['d5b_maint_locks'][ $key ] ); }
		$n = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name = %s AND option_value = %s", $name, $token ) );
		if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( $name, 'options' ); }
		return 1 === $n;
	}

	/** How long an item's lock holds (seconds): 600, the ceiling the update engine gives itself (set_time_limit), so a slow update is never taken over. */
	function d5b_maint_item_lock_ttl(): int {
		return 600;
	}

	/** The lock name of one plugin or theme folder: the update, rollback and automatic restore of it never overlap. */
	function d5b_maint_item_lock( string $kind, string $dir ): string {
		return 'd5b_maint_lock_' . $kind . '_' . md5( strtolower( $dir ) );
	}
}

if ( ! function_exists( 'd5b_maint_manifest' ) ) {
	/**
	 * The file list of a copy: relative path => sha1 of the bytes, files only, sorted (a single file: '' => its sha1). null
	 * when it holds a link (never followed) or is not there.
	 * @return array<string,string>|null
	 */
	function d5b_maint_manifest( string $path ): ?array {
		$sig = d5b_maint_tree_sig( $path );
		if ( null === $sig ) { return null; }
		$out = array();
		foreach ( $sig as $rel => $v ) {
			$v = (string) $v;
			if ( 'dir' === $v ) { continue; }
			$out[ (string) $rel ] = (string) substr( $v, (int) strpos( $v, ':' ) + 1 );
		}
		return $out;
	}

	/** The fingerprint of a file list: sha1 of the "path\0sha1\n" lines sorted by path. */
	function d5b_maint_manifest_sha1( array $m ): string {
		$lines = array();
		foreach ( $m as $p => $s ) { $lines[ (string) $p ] = (string) $p . "\0" . (string) $s . "\n"; }
		ksort( $lines, SORT_STRING );
		return sha1( implode( '', $lines ) );
	}

	/**
	 * Records the file list of a kept copy at <store>/<row>-<hex>/manifest.json (beside <kind>/<dir>), so a refusal can say what
	 * changed; the fingerprint goes into the history row (kept.manifest_sha1), which is what a restore trusts. The file is a
	 * convenience: a restore never trusts it unless its own fingerprint matches the row's.
	 * @return string the fingerprint, '' when the copy cannot be listed
	 */
	function d5b_maint_manifest_keep( string $copy ): string {
		$m = d5b_maint_manifest( $copy );
		if ( null === $m ) { return ''; }
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( (object) $m ) : json_encode( (object) $m );
		if ( is_string( $json ) ) { @file_put_contents( dirname( $copy, 2 ) . '/manifest.json', $json ); }
		return d5b_maint_manifest_sha1( $m );
	}

	/**
	 * Whether the kept copy at $src is still exactly what was kept: its file list, re-computed now, against the fingerprint in
	 * the row ($kept['manifest_sha1']). Anything that can write in uploads (a plugin's uninstall routine, another plugin, a
	 * person) could otherwise turn a planted file into code in wp-content on the next rollback.
	 * @return true|WP_Error copy_tampered (409), naming how many files changed, were added or were removed when it can tell
	 */
	function d5b_maint_manifest_check( string $src, array $kept ) {
		$bad = function ( string $why, array $counts = array() ) {
			return new WP_Error( 'copy_tampered', 'The kept copy was changed after it was made (' . $why . '), so it was not put back: it could hold files that were never part of the plugin or theme. Update or reinstall from wordpress.org instead, and check what can write to the uploads folder.', array( 'status' => 409 ) + $counts );
		};
		$want = (string) ( $kept['manifest_sha1'] ?? '' );
		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/D', $want ) ) { return $bad( 'its history row records no file list to check it against' ); }
		$now = d5b_maint_manifest( $src );
		if ( null === $now ) { return $bad( 'it now holds a link' ); }
		if ( hash_equals( $want, d5b_maint_manifest_sha1( $now ) ) ) { return true; }
		$file = dirname( $src, 2 ) . '/manifest.json';
		$list = is_file( $file ) && ! d5b_maint_is_link( $file ) ? json_decode( (string) @file_get_contents( $file ), true ) : null;
		if ( ! is_array( $list ) || ! hash_equals( $want, d5b_maint_manifest_sha1( $list ) ) ) { return $bad( 'its file list was changed as well' ); }
		$was = array(); foreach ( $list as $p => $s ) { $was[ (string) $p ] = (string) $s; }
		$changed = 0;
		foreach ( $now as $p => $s ) { if ( isset( $was[ $p ] ) && $was[ $p ] !== $s ) { $changed++; } }
		$added   = count( array_diff_key( $now, $was ) );
		$removed = count( array_diff_key( $was, $now ) );
		return $bad( $changed . ' file(s) changed, ' . $added . ' added, ' . $removed . ' removed', array( 'changed' => $changed, 'added' => $added, 'removed' => $removed ) );
	}
}

if ( ! function_exists( 'd5b_maint_kept_copies' ) ) {
	/**
	 * The plugins (kind plugin) or themes (kind theme) with a kept copy: the update, rollback and delete rows of that object
	 * type whose kept is recorded (not null), newest first: the plugin file or stylesheet and the version kept (the row's
	 * before), the row id and when it was kept. Never the copy's path. Rows of a rollback undone because the copy did not
	 * hold the plugin or theme (after.restore_mismatch) are left out: they are not a copy anyone should go back to.
	 * One list query for only the update, rollback and delete abilities of the kind (newest 100), then one read per row
	 * listed, so other history (activations, posts ...) never costs a read or pushes a kept row out of the list.
	 */
	function d5b_maint_kept_copies( string $kind ): array {
		$out = array();
		if ( ( 'plugin' !== $kind && 'theme' !== $kind ) || ! function_exists( 'd5b_history_list' ) ) { return $out; }
		$key  = d5b_maint_item_key( $kind );
		$abil = array( 'divi5-builder/' . $kind . 's-update', 'divi5-builder/' . $kind . 's-rollback', 'divi5-builder/' . $kind . 's-delete' );
		foreach ( d5b_history_list( array( 'object_type' => $kind, 'abilities' => $abil ), 100 ) as $r ) {
			if ( ! in_array( (string) ( $r['action'] ?? '' ), array( 'update', 'rollback', 'delete' ), true ) ) { continue; } // only these keep a copy
			$row = d5b_history_get( (int) $r['id'] );
			if ( null === $row || ! empty( $row['after']['restore_mismatch'] ) ) { continue; }
			$kept = $row['after']['kept'] ?? ( $row['before']['kept'] ?? null );
			if ( ! is_array( $kept ) ) { continue; }
			$item = (string) ( $row['before'][ $key ] ?? ( $row['after'][ $key ] ?? '' ) );
			if ( '' === $item ) { continue; }
			$out[] = array( $key => $item, 'version' => (string) ( $row['before']['version'] ?? '' ), 'history_id' => (int) $row['id'], 'kept_at' => d5b_wc_iso( (string) $row['created_at'] ) );
		}
		return $out;
	}

	/** Flags rows of a rollback that was undone for restore_mismatch (after.restore_mismatch true). @return bool all flagged */
	function d5b_maint_mark_mismatch( array $ids ): bool {
		$ok = true;
		foreach ( $ids as $id ) {
			$row = d5b_history_get( (int) $id );
			if ( null === $row ) { $ok = false; continue; }
			$ok = d5b_history_set_after( (int) $id, array( 'restore_mismatch' => true ) + (array) $row['after'] ) && $ok;
		}
		return $ok;
	}
}

if ( ! function_exists( 'd5b_pages_warnings' ) ) {
	/**
	 * The warnings a page check gives: no check at all (the site could not fetch its own pages), and pages that were not
	 * checked in time (skipped in either round, so never compared). With $judging (rollback_on_failure), a line that the
	 * automatic restore could not judge the skipped pages.
	 * @return string[]
	 */
	function d5b_pages_warnings( array $pages, bool $judging = false ): array {
		$warn = array();
		if ( ! empty( $pages['unavailable'] ) ) { $warn[] = 'The site could not fetch its own pages before the change, so there was no page check: only the human check can tell.'; }
		$skipped = array_values( array_map( 'strval', (array) ( $pages['skipped'] ?? array() ) ) );
		if ( $skipped ) {
			$warn[] = count( $skipped ) . ' page(s) were not re-checked in time: ' . implode( ', ', $skipped ) . '. They were not compared, so check them yourself.';
			if ( $judging ) { $warn[] = 'rollback_on_failure could not judge the page(s) that were not re-checked: it acted on the pages it did check only.'; }
		}
		return $warn;
	}
}
