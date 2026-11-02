<?php
/**
 * Write abilities for the caches and scheduled tasks (#32, release 2.3.0): cache-flush and cron-run.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here.
 *
 * Rules (design 2026-10-30, "Cache", "Site health and cron" and guardrail 16):
 *  - cache-flush is a normal write in the cache category: manage_options, and manage_network on a network (a flush there
 *    reaches every site). It calls the Updates part's wpsu_clear_caches(), which is shared code loaded whatever the Updates
 *    switch says (that switch gates the part's routes only), and answers what it flushed. One history row: object_type
 *    cache, action flush, before = what wpsu_detect_caches() found. Nothing to put back; the row says what ran.
 *  - cron-run is a risky action in the health category (the Site health risky switch; manage_options, and manage_network
 *    on a network). It runs ONE event that is scheduled, identified exactly: hook + timestamp, the schedule when given, the
 *    args_key (cron-list) when several events share hook + timestamp. Never an arbitrary action, never a d5b_ hook, never
 *    one of core's lifecycle hooks (init, wp_loaded, shutdown ...). The history row is written before anything changes.
 *  - While the hook runs: wp_doing_cron is true, the user may abort, and its output is buffered and counted
 *    (output_bytes), never sent: printed text would break the JSON answer. The lock is read fresh, past this request's
 *    caches, as wp-cron.php's _get_cron_lock() does: before it is taken, right after (it must read back as ours, else
 *    unavailable and nothing released) and before it is released. Under the lock the event is looked up again in the cron
 *    option read directly (d5b_wsite_cron_array_fresh), not in this request's cached copy.
 *  - A task may call cron-run itself (after deleting the lock): the runs in progress are a stack, and each run removes
 *    only its own entry, so the outer run's fatal or exit is still recorded.
 *  - The run mirrors wp-cron.php for one event: take core's doing_cron lock (refused while WP-Cron holds a fresh one),
 *    reschedule a recurring event and unschedule this run FIRST, then do_action_ref_array( $hook, $args ), then release
 *    the lock (in finally, and only while it is still ours). A failed reschedule or unschedule does not stop the run, as
 *    in core; it is reported.
 *  - PHP cannot interrupt a hook, so the time budget is measure and report: set_time_limit( 60 ) as a best effort, the
 *    duration measured, over_budget past 20 s. A fatal inside the hook leaves the request through ONE shutdown handler,
 *    which marks the audit row fatal and the history row's after { fatal: true } (the schedule is already right).
 *  - A hook that throws: the lock is released and the answer is internal_error with schedule_adjusted: true; the
 *    exception's message never reaches the caller.
 *  - PHP's microtime, set_time_limit and register_shutdown_function are reached through d5b_wsite_now() and two helpers
 *    that a test can replace via $GLOBALS['d5b_wsite_seams'] (now, set_time_limit, register_shutdown).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_wsite_find_event' ) ) {

	/** Over this many milliseconds a run reports over_budget. */
	function d5b_wsite_budget_ms(): int {
		return 20000;
	}

	// ---- the seams (tests replace PHP's own functions here)

	function d5b_wsite_seam( string $name ): ?callable {
		$s = $GLOBALS['d5b_wsite_seams'][ $name ] ?? null;
		return is_callable( $s ) ? $s : null;
	}

	/** The time in seconds, as microtime( true ). */
	function d5b_wsite_now(): float {
		$f = d5b_wsite_seam( 'now' );
		return null !== $f ? (float) $f() : microtime( true );
	}

	/** set_time_limit( 60 ) when PHP allows it. @return bool whether it was asked for */
	function d5b_wsite_time_limit(): bool {
		$f = d5b_wsite_seam( 'set_time_limit' );
		if ( null !== $f ) { return (bool) $f( 60 ); }
		if ( ! function_exists( 'set_time_limit' ) ) { return false; }
		$off = array_map( 'trim', explode( ',', strtolower( (string) ini_get( 'disable_functions' ) ) ) );
		if ( in_array( 'set_time_limit', $off, true ) ) { return false; }
		return (bool) @set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- best effort, a host may refuse
	}

	/** Registers d5b_wsite_on_shutdown once per request. */
	function d5b_wsite_register_shutdown(): void {
		static $done = false;
		if ( $done ) { return; }
		$done = true;
		$f = d5b_wsite_seam( 'register_shutdown' );
		if ( null !== $f ) { $f( 'd5b_wsite_on_shutdown' ); return; }
		register_shutdown_function( 'd5b_wsite_on_shutdown' );
	}

	/** error_get_last(), through the seam. */
	function d5b_wsite_last_error(): ?array {
		$f = d5b_wsite_seam( 'error_get_last' );
		$e = null !== $f ? $f() : error_get_last();
		return is_array( $e ) ? $e : null;
	}

	/**
	 * The doing_cron lock read past this request's caches, as wp-cron.php's _get_cron_lock() reads it (that function is
	 * not loaded outside wp-cron.php): another process may have taken over a stale lock since this request read it.
	 * @return string|int|false
	 */
	function d5b_wsite_cron_lock_fresh() {
		global $wpdb;
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return function_exists( 'wp_cache_get' ) ? wp_cache_get( 'doing_cron', 'transient', true ) : false;
		}
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return 0; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", '_transient_doing_cron' ) );
		return is_object( $row ) ? $row->option_value : 0;
	}

	/** Deletes the doing_cron lock when it is still $mine (a fresh read). @return bool whether it was released */
	function d5b_wsite_release_lock( string $mine ): bool {
		if ( (string) d5b_wsite_cron_lock_fresh() !== $mine ) { return false; }
		delete_transient( 'doing_cron' );
		return true;
	}

	/**
	 * The cron array read past this request's caches (2.3.0 fix wave, I2): WP-Cron in another process may have run (and
	 * moved) the event since this request first read it. A direct read of the cron option, as _get_cron_lock() reads the
	 * lock: with a persistent object cache the shared copy (alloptions, then the option's own key) read with force, else
	 * (or when the cache has neither) the options table. Shaped as _get_cron_array() shapes it. Without $wpdb (never on a
	 * real site), _get_cron_array().
	 */
	function d5b_wsite_cron_array_fresh(): array {
		global $wpdb;
		$raw = null;
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_get' ) ) {
			$all = wp_cache_get( 'alloptions', 'options', true );
			if ( is_array( $all ) && array_key_exists( 'cron', $all ) ) {
				$raw = $all['cron'];
			} else {
				$one = wp_cache_get( 'cron', 'options', true );
				if ( false !== $one ) { $raw = $one; }
			}
		}
		if ( null === $raw ) {
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) { return function_exists( '_get_cron_array' ) ? (array) _get_cron_array() : array(); }
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'cron' ) );
			$raw = is_object( $row ) ? $row->option_value : false;
		}
		$cron = is_string( $raw ) && function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : ( is_string( $raw ) ? @unserialize( $raw ) : $raw ); // phpcs:ignore
		if ( ! is_array( $cron ) ) { return array(); }
		if ( ! isset( $cron['version'] ) && function_exists( '_upgrade_cron_array' ) ) { $cron = _upgrade_cron_array( $cron ); }
		unset( $cron['version'] );
		return $cron;
	}

	/**
	 * The runs in progress in this request, innermost last: each array( token, audit_id, history_id, start, lock, state ).
	 * A task can itself call cron-run (after deleting the lock), so this is a stack (fix wave, M5): a run removes only its
	 * own entry, by its token, never the outer run's.
	 *   'push', $run   adds one          'pop', $token  removes that one and returns it (null when it is not there)
	 *   'top'          the innermost     'take_all'     removes every one and returns them, innermost first
	 */
	function d5b_wsite_runs( string $op, $arg = null ) {
		static $stack = array();
		switch ( $op ) {
			case 'push':
				$stack[] = (array) $arg;
				return null;
			case 'pop':
				foreach ( $stack as $i => $r ) {
					if ( ( $r['token'] ?? null ) === $arg ) { array_splice( $stack, $i, 1 ); return $r; }
				}
				return null;
			case 'take_all':
				$all = array_reverse( $stack ); $stack = array();
				return $all;
			default:
				return $stack ? $stack[ count( $stack ) - 1 ] : null;
		}
	}

	/** The innermost run in progress, or null. */
	function d5b_wsite_running(): ?array {
		return d5b_wsite_runs( 'top' );
	}

	/**
	 * The shutdown handler: the request is ending while a hook still runs. A fatal error (error_get_last says so) marks
	 * the rows fatal, anything else (an exit inside the hook) exited; every run still in progress (a nested one too) is
	 * marked, innermost first. The schedule is already right; each lock is released when it is still ours.
	 */
	function d5b_wsite_on_shutdown(): void {
		$all = (array) d5b_wsite_runs( 'take_all' );
		if ( array() === $all ) { return; }
		$err   = d5b_wsite_last_error();
		$fatal = null !== $err && 0 !== ( (int) ( $err['type'] ?? 0 ) & ( E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR ) );
		$label = $fatal ? 'fatal' : 'exited';
		foreach ( $all as $r ) {
			$ms = max( 0, (int) round( ( d5b_wsite_now() - (float) $r['start'] ) * 1000 ) );
			try {
				if ( (int) $r['audit_id'] > 0 && function_exists( 'd5b_audit_update' ) ) { d5b_audit_update( (int) $r['audit_id'], $label, $ms, null ); }
				if ( (int) $r['history_id'] > 0 && function_exists( 'd5b_history_set_after' ) ) {
					d5b_history_set_after( (int) $r['history_id'], array( $label => true, 'ran' => true, 'duration_ms' => $ms ) + (array) ( $r['state'] ?? array() ) );
				}
			} catch ( Throwable $e ) {
				unset( $e ); // the request is ending; go on to the lock
			}
			try {
				if ( isset( $r['lock'] ) ) { d5b_wsite_release_lock( (string) $r['lock'] ); }
			} catch ( Throwable $e ) {
				unset( $e );
			}
		}
	}

	/** Core's lifecycle hooks: they run on every request, so running one "now" is never a scheduled task's job. */
	function d5b_wsite_lifecycle_hooks(): array {
		return array( 'muplugins_loaded', 'plugins_loaded', 'setup_theme', 'after_setup_theme', 'init', 'wp_loaded', 'rest_api_init', 'admin_init', 'wp', 'parse_request', 'send_headers', 'template_redirect', 'shutdown' );
	}

	// ---- cache-flush

	function d5b_wsite_cache_ok( array $in = array() ): bool {
		return current_user_can( 'manage_options' ) && ( ! is_multisite() || current_user_can( 'manage_network' ) );
	}

	function d5b_wsite_h_cache_flush( array $in ) {
		if ( ! d5b_wsite_cache_ok() ) { return new WP_Error( 'forbidden', 'You do not have permission to flush the caches.' ); }
		$a = d5b_rc_args( $in, array() ); if ( is_wp_error( $a ) ) { return $a; }
		if ( ! function_exists( 'wpsu_clear_caches' ) || ! function_exists( 'wpsu_detect_caches' ) ) {
			return new WP_Error( 'unavailable', 'The cache code is not loaded on this site, so nothing was flushed.' );
		}
		$before  = (array) wpsu_detect_caches();
		$flushed = array_values( array_map( 'strval', (array) wpsu_clear_caches() ) );
		$out     = array( 'flushed' => $flushed );
		$hid     = d5b_wc_history_row( 'divi5-builder/cache-flush', 'cache', 0, 'flush', $before, $out, 'Flushed caches: ' . ( $flushed ? implode( ', ', $flushed ) : 'none' ) );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	// ---- cron-run

	/**
	 * The one scheduled event cron-run may run. @return array|WP_Error array( 'key' => args key, 'event' => core's event )
	 */
	function d5b_wsite_find_event( string $hook, int $timestamp, ?string $schedule, ?string $key, bool $fresh = false ) {
		// Any name core could store; the exact lookup in the cron array below is the guard.
		if ( '' === $hook || strlen( $hook ) > 191 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $hook ) ) {
			return new WP_Error( 'invalid_input', 'hook must be the hook name as cron-list returns it: at most 191 bytes, no control characters.' );
		}
		if ( 1 === preg_match( '/^d5b_/i', $hook ) ) {
			return new WP_Error( 'not_allowed', 'This plugin\'s own scheduled tasks (d5b_...) cannot be run from an ability.' );
		}
		if ( in_array( $hook, d5b_wsite_lifecycle_hooks(), true ) ) {
			return new WP_Error( 'not_allowed', '"' . $hook . '" is a core lifecycle hook; run it from wp-cron, not from an ability.' );
		}
		if ( null !== $key && 1 !== preg_match( '/^[A-Za-z0-9_\-]{1,64}$/D', $key ) ) {
			return new WP_Error( 'invalid_input', 'args_key must be the args_key of the event as cron-list returns it.' );
		}
		$cron   = $fresh ? d5b_wsite_cron_array_fresh() : ( function_exists( '_get_cron_array' ) ? _get_cron_array() : array() );
		$events = is_array( $cron ) && is_array( $cron[ $timestamp ] ?? null ) ? ( $cron[ $timestamp ][ $hook ] ?? null ) : null;
		if ( ! is_array( $events ) || array() === $events ) {
			return new WP_Error( 'not_found', 'No event "' . $hook . '" is scheduled at ' . $timestamp . '. Only a scheduled event can be run: list them with cron-list.' );
		}
		if ( null !== $key ) {
			if ( ! array_key_exists( $key, $events ) ) {
				return new WP_Error( 'not_found', 'No event "' . $hook . '" at ' . $timestamp . ' has that args_key. List them again with cron-list.' );
			}
			$pick = $key;
		} elseif ( count( $events ) > 1 ) {
			return new WP_Error( 'ambiguous', count( $events ) . ' events "' . $hook . '" are scheduled at ' . $timestamp . ' with different arguments. Pass args_key (from cron-list) to say which.',
				array( 'keys' => array_map( 'strval', array_keys( $events ) ) ) );
		} else {
			$pick = (string) array_key_first( $events );
		}
		$ev = is_array( $events[ $pick ] ) ? $events[ $pick ] : array();
		if ( null !== $schedule ) {
			$has  = ! empty( $ev['schedule'] ) ? (string) $ev['schedule'] : 'single';
			$want = '' === $schedule ? 'single' : $schedule;
			if ( $has !== $want ) {
				return new WP_Error( 'not_found', 'The event "' . $hook . '" at ' . $timestamp . ' has the schedule ' . $has . ', not ' . $want . '. List the events again with cron-list.' );
			}
		}
		return array( 'key' => (string) $pick, 'event' => $ev );
	}

	function d5b_wsite_h_cron_run( array $in ) {
		$e = d5b_rs_guard( 'health' ); if ( $e ) { return $e; }
		$a = d5b_rc_args( $in, array( 'hook' => 'key', 'timestamp' => 'int', 'schedule' => 'key', 'args_key' => 'key' ), array( 'hook', 'timestamp' ) );
		if ( is_wp_error( $a ) ) { return $a; }
		if ( ! d5b_abilities_risky_on( 'health' ) ) { return new WP_Error( 'not_allowed', 'Running a scheduled task is a risky action and the Site health risky switch is off.' ); }
		$hook = (string) $a['hook']; $ts = (int) $a['timestamp'];
		$sched = isset( $a['schedule'] ) ? (string) $a['schedule'] : null; $key = isset( $a['args_key'] ) ? (string) $a['args_key'] : null;
		$found = d5b_wsite_find_event( $hook, $ts, $sched, $key );
		if ( is_wp_error( $found ) ) { return $found; }

		// Core's lock, as wp-cron.php takes it, read fresh (past this request's caches) both before taking it and after:
		// another process may hold a fresh one this request's cache does not show, or take it in the same instant. When
		// the lock read back is not ours, nothing is released and nothing runs.
		$now  = d5b_wsite_now();
		$lock = d5b_wsite_cron_lock_fresh();
		if ( $lock && (float) $lock + WP_CRON_LOCK_TIMEOUT > $now ) {
			return new WP_Error( 'unavailable', 'WP-Cron is running; try again in a minute.' );
		}
		$mine = sprintf( '%.22F', $now );
		set_transient( 'doing_cron', $mine );
		if ( (string) d5b_wsite_cron_lock_fresh() !== $mine ) {
			return new WP_Error( 'unavailable', 'WP-Cron started at the same moment; try again in a minute.' );
		}
		try {
			// Again under the lock, from the cron option itself (not this request's copy): WP-Cron may have run (and moved)
			// the event since it was found.
			$found = d5b_wsite_find_event( $hook, $ts, $sched, $key, true );
			if ( is_wp_error( $found ) ) { return $found; }
			$ev       = $found['event'];
			$args     = is_array( $ev['args'] ?? null ) ? $ev['args'] : array();
			$schedule = ! empty( $ev['schedule'] ) ? (string) $ev['schedule'] : '';
			$before   = d5b_rs_cron_row( $hook, $ts, $found['key'], $ev );
			$warn     = array();

			// The history row first: when it cannot be written, nothing has changed yet and nothing runs.
			$hid = d5b_wc_history_row( 'divi5-builder/cron-run', 'cron_event', 0, 'run', $before, array( 'ran' => false, 'running' => true ), 'Ran cron event ' . $hook );
			if ( $hid < 1 ) {
				return new WP_Error( 'history_unavailable', 'The change history row could not be written, so the task was not run and its schedule was not changed.' );
			}

			// As wp-cron.php: reschedule a recurring event, unschedule this run, THEN run the hook. A failure is reported
			// (and core's error action fired) but does not stop the run, as in core.
			$rescheduled = false;
			if ( '' !== $schedule ) {
				$res         = wp_reschedule_event( $ts, $schedule, $hook, $args, true );
				$rescheduled = true === $res;
				if ( is_wp_error( $res ) && function_exists( 'do_action' ) ) { do_action( 'cron_reschedule_event_error', $res, $hook, $ev ); }
				if ( ! $rescheduled ) { $warn[] = 'This recurring task is no longer scheduled: WordPress could not book its next run (' . ( is_wp_error( $res ) ? $res->get_error_code() : 'refused' ) . '). The plugin that owns it must add it again (often by deactivating and activating that plugin).'; }
			}
			$res         = wp_unschedule_event( $ts, $hook, $args, true );
			$unscheduled = true === $res;
			if ( is_wp_error( $res ) && function_exists( 'do_action' ) ) { do_action( 'cron_unschedule_event_error', $res, $hook, $ev ); }
			if ( ! $unscheduled ) { $warn[] = 'WordPress could not take this run off the schedule (' . ( is_wp_error( $res ) ? $res->get_error_code() : 'refused' ) . '); WP-Cron may run it again.'; }
			$state = array( 'rescheduled' => $rescheduled, 'unscheduled' => $unscheduled );

			d5b_wsite_time_limit();
			if ( function_exists( 'ignore_user_abort' ) ) { @ignore_user_abort( true ); } // phpcs:ignore WordPress.PHP.NoSilencedErrors -- as wp-cron.php
			d5b_wsite_register_shutdown();
			$audit = function_exists( 'd5b_ability_audit_current_id' ) ? d5b_ability_audit_current_id() : 0;
			$t0    = d5b_wsite_now();
			$error = null; $bytes = 0;
			static $runs = 0;
			$token = $mine . '#' . ( ++$runs );
			d5b_wsite_runs( 'push', array( 'token' => $token, 'audit_id' => $audit, 'history_id' => $hid, 'start' => $t0, 'lock' => $mine, 'state' => $state ) );
			add_filter( 'wp_doing_cron', '__return_true' );
			$level = ob_get_level();
			ob_start(); // a task that prints would break the JSON answer: its output is counted, never sent
			try {
				do_action_ref_array( $hook, $args );
			} catch ( Throwable $ex ) {
				$error = $ex;
			} finally {
				while ( ob_get_level() > $level ) { $bytes += strlen( (string) ob_get_clean() ); }
				remove_filter( 'wp_doing_cron', '__return_true' );
				d5b_wsite_runs( 'pop', $token ); // this run's own entry only: an outer run's stays
			}
			$ms   = max( 0, (int) round( ( d5b_wsite_now() - $t0 ) * 1000 ) );
			$over = $ms > d5b_wsite_budget_ms();

			if ( null !== $error ) {
				d5b_history_set_after( $hid, array( 'ran' => true, 'failed' => true, 'error' => get_class( $error ), 'schedule_adjusted' => true, 'duration_ms' => $ms, 'over_budget' => $over, 'output_bytes' => $bytes ) + $state );
				return new WP_Error( 'internal_error', 'The scheduled task "' . $hook . '" stopped with an error. Its schedule was already adjusted as WP-Cron does, so this run is off the schedule' . ( $rescheduled ? ' and the next one is booked' : '' ) . '. The site\'s error log may say more.',
					array( 'schedule_adjusted' => true, 'duration_ms' => $ms, 'over_budget' => $over ) );
			}
			$out = array( 'ran' => true, 'hook' => $hook, 'duration_ms' => $ms ) + $state + array( 'over_budget' => $over, 'output_bytes' => $bytes );
			if ( $over ) { $warn[] = 'The task took ' . round( $ms / 1000, 1 ) . ' s, longer than the ' . ( d5b_wsite_budget_ms() / 1000 ) . ' s budget.'; }
			if ( ! d5b_history_set_after( $hid, array( 'ran' => true, 'duration_ms' => $ms, 'over_budget' => $over, 'output_bytes' => $bytes ) + $state ) ) { $warn[] = 'The change history row could not be completed; it still shows the task as running.'; }
			if ( $warn ) { $out['warnings'] = $warn; }
			return $out;
		} finally {
			// Release the lock only while it is still ours, read fresh as wp-cron.php does: a WP-Cron run that took over a
			// stale lock keeps its own.
			d5b_wsite_release_lock( $mine );
		}
	}

	// ---- the definitions

	d5b_wc_def( 'cache-flush', 'cache', 'Flush the caches',
		'Empties every cache cache-status lists (Divi\'s static CSS, WP Fastest Cache, WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, the object cache) and returns flushed, the names of what was emptied. The Updates switch is not needed. Refuses callers without manage_options, and on a network without manage_network. One change-history row records what was found; a flush has nothing to put back. Answers unavailable when the cache code is not loaded.',
		array(), array(), 'd5b_wsite_h_cache_flush', 'd5b_wsite_cache_ok',
		array( 'idempotent' => true ) );

	d5b_wc_def( 'cron-run', 'health', 'Run a scheduled task now',
		'Runs one scheduled WP-Cron event now, as WP-Cron would: hook and timestamp as cron-list returns them, schedule (optional, checked against the event; "single" or "" for a one-off event) and args_key (needed when several events share hook and timestamp; cron-list returns it). A risky action: needs the Site health risky switch; tasks belong to plugins and may do real work (send emails, process orders, run migrations). Needs manage_options, and on a network manage_network. Only a scheduled event runs (not_found otherwise; ambiguous with the keys when args_key is needed); this plugin\'s own d5b_ tasks and core\'s lifecycle hooks (init, wp_loaded, shutdown and the like) are refused with not_allowed. A recurring event is booked for its next time and this run taken off the schedule BEFORE the task runs, as WP-Cron does. Refused with unavailable while WP-Cron is running. A task cannot be stopped once started: the time is measured and over_budget is true past 20 seconds. Returns ran, hook, duration_ms, rescheduled, unscheduled, over_budget and output_bytes (how much the task printed; the text itself is never returned). A task that fails answers internal_error with schedule_adjusted true, duration_ms and over_budget. One change-history row records the event (never its argument values), written before anything changes: when it cannot be written the answer is history_unavailable and nothing ran.',
		array(
			'hook'      => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191, 'description' => 'The hook name as cron-list returns it.' ),
			'timestamp' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The event\'s timestamp as cron-list returns it.' ),
			'schedule'  => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'Optional: the schedule as cron-list returns it (single for a one-off event); the event must have it.' ),
			'args_key'  => array( 'type' => 'string', 'maxLength' => 64, 'description' => 'Optional: the event\'s args_key from cron-list, needed when several events share hook and timestamp.' ),
		), array( 'hook', 'timestamp' ), 'd5b_wsite_h_cron_run', d5b_rs_perm( 'health' ),
		array( 'idempotent' => false ), array( 'risky' => true ) );
}
