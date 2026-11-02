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
		$t = wp_get_theme( $item );
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

if ( ! function_exists( 'd5b_maint_move' ) ) {
	/**
	 * Moves a folder or file: a rename, or (another disk, a host that refuses the rename) a copy that must be whole, then the
	 * original removed. A part-made copy is removed again. @return bool true when $to holds it and $from is gone
	 */
	function d5b_maint_move( string $from, string $to ): bool {
		if ( @rename( $from, $to ) ) { return true; }
		if ( ! d5b_maint_filesystem() || file_exists( $to ) ) { return false; }
		global $wp_filesystem;
		$size = d5b_maint_tree_size( $from );
		$ok   = is_dir( $from ) ? true === copy_dir( $from, $to ) : (bool) $wp_filesystem->copy( $from, $to, false, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
		if ( ! $ok || d5b_maint_tree_size( $to ) !== $size ) { d5b_maint_remove_tree( $to ); return false; }
		return d5b_maint_remove_tree( $from );
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
	 * $dir, or the single-file plugin $dir): a new rollback row is written FIRST (before: the version being replaced), the
	 * current folder is renamed to <dir>.d5b-<time> beside it, the kept copy is copied in with copy_dir (it stays in the
	 * store, so a copy can be put back twice), and the renamed folder is moved into the new row's store folder: the new row's
	 * kept. When the copy cannot be put back whole, the half-made folder is removed and the current one renamed back
	 * (nothing changed, the new row removed). Never acts on a copy outside the store: the kept path must be
	 * <store>/<history_id>-<32 hex>/<kind>/<dir>. The caller checks the row belongs to the item and runs the page checks.
	 * Errors: not_found (no such row, no copy recorded), copy_missing (the copy is gone or not where a copy is kept),
	 * store_full, copy_failed, history_unavailable.
	 * @return array{history_id:int,version:string,replaced_version:string,kept:?array,warnings:string[]}|WP_Error
	 */
	function d5b_maint_restore( string $kind, string $dir, int $history_id ) {
		$fail = function ( string $why ) { return new WP_Error( 'copy_failed', 'The kept copy could not be put back: ' . $why . '.', array( 'status' => 503 ) ); };
		if ( ( 'plugin' !== $kind && 'theme' !== $kind ) || '' === $dir || '.' === $dir || '..' === $dir || 1 === preg_match( '#[/\\\\\x00]#', $dir ) ) { return $fail( 'not a plugin or theme folder' ); }
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
		if ( d5b_maint_is_link( $target ) ) { return $fail( 'the ' . $kind . ' is a link' ); }
		$cur = file_exists( $target ) ? d5b_maint_tree_size( $target ) : null;
		$store = d5b_history_store_bytes();
		if ( null !== $cur && $store + $cur['bytes'] > d5b_maint_store_cap() ) {
			$oldest = d5b_maint_oldest_row_at();
			return new WP_Error( 'store_full', 'The kept copies take ' . $store . ' bytes; keeping the files being replaced (' . $cur['bytes'] . ' bytes) would take the store over 2 GB. Copies are removed with their history row after 30 days' . ( null === $oldest ? '' : '; the oldest row is from ' . $oldest . ' UTC' ) . '.', array( 'status' => 507, 'store_bytes' => $store, 'bytes' => $cur['bytes'], 'cap' => d5b_maint_store_cap(), 'oldest_row_at' => $oldest ) );
		}
		if ( ! d5b_history_files_prepare() ) { return $fail( 'the store folder could not be made' ); }
		if ( ! d5b_maint_filesystem() ) { return $fail( 'no file system access (copy_dir)' ); }
		global $wp_filesystem;

		$replaced = '' === $item ? '' : d5b_maint_version( $kind, $item );
		$to       = (string) ( $row['before']['version'] ?? '' );
		$new      = d5b_wc_history_row( 'divi5-builder/' . $kind . 's-rollback', $kind, 0, 'rollback',
			array( $key => $item, 'version' => $replaced, 'from_history_id' => $history_id ), array( 'running' => true ),
			'Rolled back ' . $kind . ' ' . ( '' === $item ? $dir : $item ) . ( '' === $to ? '' : ' to ' . $to ) );
		if ( $new < 1 ) { return new WP_Error( 'history_unavailable', 'The change history row could not be written, so nothing was put back.' ); }

		// 1. the current files aside, in the same folder (a rename there does not cross a disk)
		$aside = '';
		if ( null !== $cur ) {
			$base = $target . '.d5b-' . (int) d5b_maint_now();
			$aside = $base;
			for ( $i = 2; file_exists( $aside ) && $i < 100; $i++ ) { $aside = $base . '-' . $i; }
			if ( file_exists( $aside ) || ! @rename( $target, $aside ) ) {
				d5b_history_delete( $new );
				return $fail( 'the current files could not be moved aside; nothing was changed' );
			}
		}
		// 2. the kept copy in, whole, or everything as it was
		$ok = $single ? (bool) $wp_filesystem->copy( $src, $target, false, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ) : true === copy_dir( $src, $target );
		if ( $ok ) { clearstatcache(); $ok = d5b_maint_tree_size( $target ) === $size; }
		if ( ! $ok ) {
			$clean = d5b_maint_remove_tree( $target );
			$back  = '' === $aside || ( $clean && @rename( $aside, $target ) );
			d5b_history_delete( $new );
			d5b_maint_clean_cache( $kind );
			return $fail( $back ? 'copying the files failed; the ' . $kind . ' is as it was' : 'copying the files failed AND the current files could not be put back: they are in ' . basename( $aside ) . ' beside the ' . $kind . 's folder\'s other entries; rename it to ' . $dir );
		}
		// 3. the replaced files into the new row's store folder: the rollback is itself undoable
		$warn = array(); $kept_new = null;
		if ( '' !== $aside ) {
			$folder = d5b_history_files_root() . '/' . $new . '-' . bin2hex( random_bytes( 16 ) );
			$dest   = $folder . '/' . $kind . '/' . $dir;
			if ( @mkdir( $folder . '/' . $kind, 0755, true ) && d5b_maint_move( $aside, $dest ) ) {
				$kept_new = array( 'path' => $dest, 'bytes' => $cur['bytes'], 'files' => $cur['files'] );
			} else {
				d5b_history_files_remove( $new );
				$warn[] = 'The files that were replaced could not be moved into the change history store; they are still in ' . basename( $aside ) . ' in the ' . $kind . 's folder. Remove that folder by hand once the site is fine.';
			}
		}
		d5b_maint_clean_cache( $kind );
		$version = '' === $item ? '' : d5b_maint_version( $kind, $item );
		if ( ! d5b_history_set_after( $new, array( 'version' => $version, 'kept' => $kept_new, 'from_history_id' => $history_id ) ) ) {
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
	 *  6. $opts['rollback_on_failure'] and (the pages got worse or the update failed) with a copy: d5b_maint_restore at once.
	 * A failed update is the error update_failed whose data carries the same fields as a result.
	 * @return array|WP_Error
	 */
	function d5b_maint_update_sequence( string $kind, string $item, string $dir, array $opts ) {
		if ( 'plugin' !== $kind && 'theme' !== $kind ) { return new WP_Error( 'invalid_input', 'kind must be plugin or theme.' ); }
		if ( ! function_exists( 'wpsu_update' ) ) { return new WP_Error( 'unavailable', 'The update code is not loaded on this site, so nothing was updated.' ); }
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
			if ( empty( $opts['without_copy'] ) ) { d5b_history_delete( $hid ); return $kept; }
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
				$rb = d5b_maint_restore( $kind, $dir, $hid );
				if ( is_wp_error( $rb ) ) {
					$warn[] = 'rollback_on_failure: the previous version could not be put back: ' . d5b_maint_err_text( $rb );
				} else {
					$rolled = true; $rb_id = (int) $rb['history_id']; $version = (string) $rb['version'];
					$warn   = array_merge( $warn, (array) $rb['warnings'] );
					$after['rolled_back'] = true; $after['rollback_history_id'] = $rb_id;
					if ( ! d5b_history_set_after( $hid, $after ) ) { $warn[] = 'The change history row could not be completed.'; }
				}
			}
		}
		if ( null !== $copy_error ) {
			$warn[] = 'No copy of the previous version was kept (' . $copy_error->get_error_code() . ': ' . d5b_maint_err_text( $copy_error ) . '), as without_copy allowed: this plugin cannot put the previous version back.';
		}
		if ( ! empty( $pages['unavailable'] ) ) { $warn[] = 'The site could not fetch its own pages before the change, so there was no page check: only the human check can tell.'; }

		$out = array(
			$key => $item, 'from' => $from, 'to' => $to, 'version' => $version, 'updated' => $done,
			'kept' => d5b_maint_kept_public( $kept ), 'history_id' => $hid, 'rolled_back' => $rolled,
		);
		if ( $rb_id > 0 ) { $out['rollback_history_id'] = $rb_id; }
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
