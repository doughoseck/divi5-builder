<?php
/**
 * Abilities for WordPress core (#32, release 2.4.0, Task 5): core-status (a read), core-update (risky) and
 * translations-update (a normal write), all in the core category.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here. It uses the Updates part's engine (wpsu_status,
 * wpsu_update, wpsu_core_kind, wpsu_site_blocked; always loaded, the Updates switch gates only that part's REST routes) and
 * the maintenance helpers of maint-common.php and write-plugins.php.
 *
 * Rules (design 2026-11-02, "Abilities > Core" and "The one rule still holds"):
 *  - core-status reads the stored update list (wpsu_status( false )): it never clears a transient and never asks wordpress.org.
 *    It returns only version, offered, kind, blocked and update_available: never the raw status.
 *  - core-update( version ) takes a version that is on offer right now (the engine enforces it, and it is checked again here
 *    against the fresh list), runs wpsu_update( 'core' ) inside the page checks and never keeps a copy: WordPress replaces its
 *    own files and no plugin can copy them. The row keeps the versions only; the answer says rollback "none, restore a
 *    backup" and carries the human check and the pages. A risky action; on a network, a network administrator.
 *  - translations-update() installs the language packs on offer (wpsu_update( 'translations' )). A normal write: no copy and
 *    no page checks (language files do not change what a page renders by themselves; a pack cannot be put back, WordPress
 *    fetches the next one). The row records the count.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wcore_h_status' ) ) {

	// The capability helpers are maint-common.php's d5b_maint_ok / d5b_maint_can (maint-common sorts before this file).

	/** The WordPress version in ABSPATH/wp-includes/version.php, read from the file now ('' when it cannot be read). */
	function d5b_wcore_disk_version(): string {
		$c = is_readable( ABSPATH . 'wp-includes/version.php' ) ? (string) @file_get_contents( ABSPATH . 'wp-includes/version.php' ) : '';
		return 1 === preg_match( '/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $c, $m ) ? $m[1] : '';
	}

	// ---- core-status (read)

	function d5b_wcore_h_status( array $in ) {
		if ( ! d5b_maint_ok( 'update_core' ) ) { return new WP_Error( 'forbidden', 'You do not have permission to read the WordPress update status.' ); }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! function_exists( 'wpsu_status' ) ) { return new WP_Error( 'unavailable', 'The update code is not loaded on this site.' ); }
		$s  = (array) wpsu_status( false ); // the stored list: nothing cleared, nothing asked
		$wp = (array) ( $s['wordpress'] ?? array() );
		return array(
			'version'          => (string) ( $wp['version'] ?? '' ),
			'offered'          => array_values( array_map( 'strval', (array) ( $wp['offered'] ?? array() ) ) ),
			'kind'             => isset( $wp['kind'] ) && is_string( $wp['kind'] ) ? $wp['kind'] : null, // minor (a maintenance release) or major, as WordPress counts
			'blocked'          => function_exists( 'wpsu_site_blocked' ) ? (string) wpsu_site_blocked() : '',
			'update_available' => null !== ( $wp['new_version'] ?? null ),
		);
	}

	// ---- core-update

	function d5b_wcore_h_update( array $in ) {
		if ( ! d5b_maint_ok( 'update_core', true ) ) { return new WP_Error( 'forbidden', 'You do not have permission to update WordPress.' ); }
		$a = d5b_rc_args( $in, array( 'version' => 'str' ), array( 'version' ) ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! d5b_abilities_risky_on( 'core' ) ) { return new WP_Error( 'not_allowed', 'Updating WordPress is a risky action and the WordPress core risky switch is off.' ); }
		$version = (string) $a['version'];
		if ( 1 !== preg_match( '/^[0-9]+\.[0-9]+(\.[0-9]+)?(-[A-Za-z0-9.]+)?$/D', $version ) ) {
			return new WP_Error( 'invalid_input', 'version must be a WordPress version number as core-status lists it (offered), for example 6.9.1.' );
		}
		if ( ! function_exists( 'wpsu_update' ) || ! function_exists( 'wpsu_status' ) ) { return new WP_Error( 'unavailable', 'The update code is not loaded on this site, so nothing was updated.' ); }

		// A dry run first: it asks wordpress.org again (the list is always fresh), refuses a version that is not on offer or a site
		// that refuses file changes, and says when nothing is on offer at all.
		$dry = wpsu_update( 'core', $version, true );
		if ( is_wp_error( $dry ) ) {
			if ( 'wpsu_core_version' === $dry->get_error_code() ) { return new WP_Error( 'invalid_input', d5b_maint_err_text( $dry ), array( 'status' => 409 ) ); }
			return d5b_maint_wpsu_error( $dry );
		}
		if ( empty( $dry['dry_run'] ) ) {
			return array( 'updated' => false, 'already_up_to_date' => true, 'version' => (string) ( $dry['from'] ?? '' ) );
		}
		$from = (string) ( $dry['from'] ?? '' ); $to = (string) ( $dry['to'] ?? '' );

		$hid = d5b_wc_history_row( 'divi5-builder/core-update', 'core', 0, 'update', array( 'version' => $from ), array( 'version' => $from, 'to' => $to, 'running' => true ), 'Updated WordPress from ' . $from . ' to ' . $to );
		if ( $hid < 1 ) { return new WP_Error( 'history_unavailable', 'The change history row could not be written, so WordPress was not updated.' ); }

		// No copy: WordPress replaces its own files and cannot be copied by a plugin. The update runs inside the page checks.
		$res   = null;
		$pages = d5b_pages_check( function () use ( $version, &$res ) {
			if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 600 ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors -- as the Updates part
			$level = ob_get_level();
			ob_start(); // an upgrader skin or an update hook may print; that would break the JSON answer
			try {
				$res = wpsu_update( 'core', $version, false );
			} catch ( Throwable $e ) {
				$res = new WP_Error( 'internal_error', 'the updater stopped with an error (' . get_class( $e ) . ')' );
			} finally {
				while ( ob_get_level() > $level ) { ob_end_clean(); }
			}
		} );
		$done  = is_array( $res ) && ! empty( $res['done'] );
		// The offer changed between the dry run and the run (a newer release, or none left): the engine refused before touching
		// anything, so the row is removed and the call is refused like the dry run would have refused it.
		// A row that cannot be deleted is completed instead (nothing installed), so it never stays "running", and the answer says so.
		$drop = function () use ( $hid, $from ): ?string {
			if ( d5b_history_delete( $hid ) ) { return null; }
			d5b_history_set_after( $hid, array( 'version' => $from, 'aborted' => 'the offer changed before the run; nothing was installed' ) );
			return 'Its change history row ' . $hid . ' could not be removed; it now says nothing was installed.';
		};
		if ( is_wp_error( $res ) && 'wpsu_core_version' === $res->get_error_code() ) {
			$kept = $drop();
			return new WP_Error( 'invalid_input', d5b_maint_err_text( $res ) . ( null === $kept ? '' : ' ' . $kept ), array( 'status' => 409 ) + ( null === $kept ? array() : array( 'history_id' => $hid ) ) );
		}
		if ( is_array( $res ) && ! $done && '' === (string) ( $res['error'] ?? '' ) && 'already up to date' === ( $res['note'] ?? '' ) ) {
			$kept = $drop();
			return array( 'updated' => false, 'already_up_to_date' => true, 'version' => $from ) + ( null === $kept ? array() : array( 'history_id' => $hid, 'warnings' => array( $kept ) ) );
		}
		$error = is_wp_error( $res ) ? d5b_maint_err_text( $res ) : ( is_array( $res ) ? (string) ( $res['error'] ?? '' ) : 'the updater gave no answer' );
		// The version now: what the updater reports when it finished; otherwise (and when it reports nothing) read fresh from
		// wp-includes/version.php, as the engine does (get_bloginfo is stale for the rest of this request).
		$disk  = d5b_wcore_disk_version();
		$now   = $done && is_array( $res ) && '' !== (string) ( $res['now'] ?? '' ) ? (string) $res['now'] : ( '' !== $disk ? $disk : ( $done ? $to : $from ) );
		$problems = (array) $pages['problems'];
		$after = array( 'version' => $now, 'pages' => $pages );
		if ( ! $done ) { $after['failed'] = '' === $error ? 'unknown' : $error; }
		$warn = array();
		if ( ! d5b_history_set_after( $hid, $after ) ) { $warn[] = 'The change history row could not be completed.'; }
		if ( array() !== $problems ) { $warn[] = 'The pages got worse. This plugin keeps no copy of WordPress and cannot put the previous version back: restore a backup.'; }
		$warn = array_merge( $warn, d5b_pages_warnings( $pages ) );
		if ( $done ) { $warn[] = 'WordPress may ask for a database update the next time an administrator opens the dashboard; do that, then look at the site.'; }

		$out = array(
			'from' => $from, 'to' => $to, 'version' => $now, 'updated' => $done, 'kind' => function_exists( 'wpsu_core_kind' ) ? wpsu_core_kind( $from, $to ) : null,
			'history_id' => $hid, 'rollback' => 'none, restore a backup', 'problems' => $problems, 'pages' => $pages, 'human_check' => d5b_human_check(),
			'caches_cleared' => is_array( $res ) ? array_values( (array) ( $res['caches_cleared'] ?? array() ) ) : array(),
		);
		if ( $warn ) { $out['warnings'] = $warn; }
		if ( ! $done ) {
			return new WP_Error( 'update_failed', 'WordPress was not updated to ' . $to . ': ' . ( '' === $error ? 'unknown error' : $error ) . '. This plugin keeps no copy of WordPress: if the site is broken, restore a backup.', $out );
		}
		return $out;
	}

	// ---- translations-update

	function d5b_wcore_h_translations( array $in ) {
		if ( ! d5b_maint_ok( 'update_core' ) ) { return new WP_Error( 'forbidden', 'You do not have permission to update translations.' ); }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! function_exists( 'wpsu_update' ) ) { return new WP_Error( 'unavailable', 'The update code is not loaded on this site, so nothing was updated.' ); }
		$dry = wpsu_update( 'translations', '', true );
		if ( is_wp_error( $dry ) ) { return d5b_maint_wpsu_error( $dry ); }
		if ( empty( $dry['dry_run'] ) ) { return array( 'updated' => false, 'already_up_to_date' => true, 'count' => 0 ); }
		$count = function_exists( 'wp_get_translation_updates' ) ? count( (array) wp_get_translation_updates() ) : 0;

		$hid = d5b_wc_history_row( 'divi5-builder/translations-update', 'translations', 0, 'update', null, array( 'count' => $count, 'running' => true ), 'Updated ' . $count . ' translation' . ( 1 === $count ? '' : 's' ) );
		if ( $hid < 1 ) { return new WP_Error( 'history_unavailable', 'The change history row could not be written, so the translations were not updated.' ); }
		$res   = null;
		$level = ob_get_level();
		ob_start();
		try {
			$res = wpsu_update( 'translations', '', false );
		} catch ( Throwable $e ) {
			$res = new WP_Error( 'internal_error', 'the updater stopped with an error (' . get_class( $e ) . ')' );
		} finally {
			while ( ob_get_level() > $level ) { ob_end_clean(); }
		}
		$done  = is_array( $res ) && ! empty( $res['done'] );
		$error = is_wp_error( $res ) ? d5b_maint_err_text( $res ) : ( is_array( $res ) ? (string) ( $res['error'] ?? '' ) : 'the updater gave no answer' );
		$after = array( 'count' => $count );
		if ( ! $done ) { $after['failed'] = '' === $error ? 'unknown' : $error; }
		$warn = array();
		if ( ! d5b_history_set_after( $hid, $after ) ) { $warn[] = 'The change history row could not be completed.'; }
		$out = array( 'updated' => $done, 'count' => $count, 'history_id' => $hid, 'undo' => 'none: WordPress fetches the next language pack itself', 'caches_cleared' => is_array( $res ) ? array_values( (array) ( $res['caches_cleared'] ?? array() ) ) : array() );
		if ( $warn ) { $out['warnings'] = $warn; }
		if ( ! $done ) {
			return new WP_Error( 'update_failed', 'The translations were not updated: ' . ( '' === $error ? 'unknown error' : $error ) . '.', $out );
		}
		return $out;
	}

	// ---- the definitions

	d5b_rc_def( 'core-status', 'core', 'WordPress update status',
		'Returns the WordPress version, the versions on offer (offered, from the stored update list: nothing is cleared and wordpress.org is not asked; updates-check refreshes it), kind of the newest one (minor = a maintenance release such as 6.9.1, major = a new release such as 7.0, as WordPress counts), blocked (why this site refuses updates, empty when it does not) and update_available. Refuses callers without update_core.',
		array(), array(), 'd5b_wcore_h_status', d5b_maint_can( 'update_core' ) );

	d5b_wc_def( 'core-update', 'core', 'Update WordPress (checked, no copy)',
		'Updates WordPress itself to a version that is on offer right now (core-status lists offered; updates-check refreshes the list); any other version is refused (invalid_input, 409) and nothing is changed. A risky action: needs the WordPress core risky switch and update_core (on a network, a network administrator). NO COPY is kept: WordPress replaces its own files and this plugin cannot put the previous version back. The answer says rollback "none, restore a backup": take a backup first. The update runs inside a page check (the home page and the recently changed pages, fetched before and after); problems are reported, never undone. Nothing on offer: already_up_to_date (nothing changed). A failed update is update_failed with the same fields in its data. WordPress may ask for a database update the next time an administrator opens the dashboard. An uncatchable PHP fatal during the update ends the request with no answer at all: then read core-status and the change history row, and check the site. Returns from, to, version (installed now), updated, kind, history_id, rollback, problems, pages (before, after, problems, unavailable), human_check and caches_cleared. Pass the human_check sentence on to the user after the run: the page check compares status, error text and size only, so never report the site as fine. One change-history row records the versions only.',
		array( 'version' => array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 40, 'description' => 'The WordPress version to go to, one of offered in core-status, for example 6.9.1.' ) ),
		array( 'version' ), 'd5b_wcore_h_update', d5b_maint_can( 'update_core', true ),
		array( 'idempotent' => false ), array( 'risky' => true ) );

	d5b_wc_def( 'translations-update', 'core', 'Update translations',
		'Installs the language packs on offer for WordPress, plugins and themes (the stored list is refreshed first). A normal write: no copy is kept and no page check is made (language files do not change what a page renders by themselves, and WordPress fetches the next pack itself, so there is nothing to put back). Nothing on offer: already_up_to_date. Refuses callers without update_core and a site that does not allow file changes (not_allowed). Returns updated, count, history_id and caches_cleared; one change-history row records the count.',
		array(), array(), 'd5b_wcore_h_translations', d5b_maint_can( 'update_core' ),
		array( 'idempotent' => false ) );
}
