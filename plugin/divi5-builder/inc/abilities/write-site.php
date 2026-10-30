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
 *    args_key (cron-list) when several events share hook + timestamp. Never an arbitrary action, never a d5b_ hook.
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

	/**
	 * The run in progress: array( audit_id, history_id, start ) while a hook runs, else null. $set starts one; $clear ends
	 * it (and returns it).
	 */
	function d5b_wsite_running( ?array $set = null, bool $clear = false ): ?array {
		static $cur = null;
		if ( $clear ) { $prev = $cur; $cur = null; return $prev; }
		if ( null !== $set ) { $cur = $set; }
		return $cur;
	}

	/** The shutdown handler: the request is ending while a hook still runs (a fatal error or an exit inside it). */
	function d5b_wsite_on_shutdown(): void {
		$r = d5b_wsite_running( null, true );
		if ( null === $r ) { return; }
		$ms = max( 0, (int) round( ( d5b_wsite_now() - (float) $r['start'] ) * 1000 ) );
		try {
			if ( (int) $r['audit_id'] > 0 && function_exists( 'd5b_audit_update' ) ) { d5b_audit_update( (int) $r['audit_id'], 'fatal', $ms, null ); }
			if ( (int) $r['history_id'] > 0 && function_exists( 'd5b_history_set_after' ) ) { d5b_history_set_after( (int) $r['history_id'], array( 'fatal' => true ) ); }
		} catch ( Throwable $e ) {
			unset( $e ); // the request is ending; nothing else can be done
		}
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
	function d5b_wsite_find_event( string $hook, int $timestamp, ?string $schedule, ?string $key ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-\.:]{1,191}$/D', $hook ) ) {
			return new WP_Error( 'invalid_input', 'hook must be the hook name as cron-list returns it (letters, digits, _ - . : only).' );
		}
		if ( 1 === preg_match( '/^d5b_/i', $hook ) ) {
			return new WP_Error( 'not_allowed', 'This plugin\'s own scheduled tasks (d5b_...) cannot be run from an ability.' );
		}
		if ( null !== $key && 1 !== preg_match( '/^[A-Za-z0-9_\-]{1,64}$/D', $key ) ) {
			return new WP_Error( 'invalid_input', 'args_key must be the args_key of the event as cron-list returns it.' );
		}
		$cron   = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
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

		// Core's lock, as wp-cron.php takes it.
		$now  = d5b_wsite_now();
		$lock = get_transient( 'doing_cron' );
		if ( $lock && (float) $lock + WP_CRON_LOCK_TIMEOUT > $now ) {
			return new WP_Error( 'unavailable', 'WP-Cron is running; try again in a minute.' );
		}
		$mine = sprintf( '%.22F', $now );
		set_transient( 'doing_cron', $mine );
		try {
			// Again under the lock: WP-Cron may have run (and moved) the event since it was found.
			$found = d5b_wsite_find_event( $hook, $ts, $sched, $key );
			if ( is_wp_error( $found ) ) { return $found; }
			$ev       = $found['event'];
			$args     = is_array( $ev['args'] ?? null ) ? $ev['args'] : array();
			$schedule = ! empty( $ev['schedule'] ) ? (string) $ev['schedule'] : '';
			$before   = d5b_rs_cron_row( $hook, $ts, $found['key'], $ev );
			$warn     = array();

			// As wp-cron.php: reschedule a recurring event, unschedule this run, THEN run the hook.
			$rescheduled = false;
			if ( '' !== $schedule ) {
				$res         = wp_reschedule_event( $ts, $schedule, $hook, $args, true );
				$rescheduled = true === $res;
				if ( ! $rescheduled ) { $warn[] = 'WordPress could not schedule the next run of this recurring task (' . ( is_wp_error( $res ) ? $res->get_error_code() : 'refused' ) . '); check cron-list.'; }
			}
			$res         = wp_unschedule_event( $ts, $hook, $args, true );
			$unscheduled = true === $res;
			if ( ! $unscheduled ) { $warn[] = 'WordPress could not take this run off the schedule (' . ( is_wp_error( $res ) ? $res->get_error_code() : 'refused' ) . '); WP-Cron may run it again.'; }

			$state = array( 'rescheduled' => $rescheduled, 'unscheduled' => $unscheduled );
			$hid   = d5b_wc_history_row( 'divi5-builder/cron-run', 'cron_event', 0, 'run', $before, array( 'ran' => false, 'running' => true ) + $state, 'Ran cron event ' . $hook );

			d5b_wsite_time_limit();
			d5b_wsite_register_shutdown();
			$audit = function_exists( 'd5b_ability_audit_current_id' ) ? d5b_ability_audit_current_id() : 0;
			$t0    = d5b_wsite_now();
			$error = null;
			d5b_wsite_running( array( 'audit_id' => $audit, 'history_id' => $hid, 'start' => $t0 ) );
			try {
				do_action_ref_array( $hook, $args );
			} catch ( Throwable $ex ) {
				$error = $ex;
			} finally {
				d5b_wsite_running( null, true );
			}
			$ms   = max( 0, (int) round( ( d5b_wsite_now() - $t0 ) * 1000 ) );
			$over = $ms > d5b_wsite_budget_ms();

			if ( null !== $error ) {
				if ( $hid > 0 ) { d5b_history_set_after( $hid, array( 'ran' => true, 'failed' => true, 'error' => get_class( $error ), 'schedule_adjusted' => true, 'duration_ms' => $ms ) + $state ); }
				return new WP_Error( 'internal_error', 'The scheduled task "' . $hook . '" stopped with an error. Its schedule was already adjusted as WP-Cron does, so this run is off the schedule' . ( $rescheduled ? ' and the next one is booked' : '' ) . '. The site\'s error log may say more.',
					array( 'schedule_adjusted' => true ) );
			}
			$out = array( 'ran' => true, 'hook' => $hook, 'duration_ms' => $ms ) + $state + array( 'over_budget' => $over );
			if ( $over ) { $warn[] = 'The task took ' . round( $ms / 1000, 1 ) . ' s, longer than the ' . ( d5b_wsite_budget_ms() / 1000 ) . ' s budget.'; }
			if ( $hid < 1 ) { $out['_history_failed'] = true; }
			elseif ( ! d5b_history_set_after( $hid, array( 'ran' => true, 'duration_ms' => $ms, 'over_budget' => $over ) + $state ) ) { $warn[] = 'The change history row could not be completed; it still shows the task as running.'; }
			if ( $warn ) { $out['warnings'] = $warn; }
			return $out;
		} finally {
			// Release the lock only while it is still ours (a WP-Cron run that took over a stale lock keeps its own).
			if ( (string) get_transient( 'doing_cron' ) === $mine ) { delete_transient( 'doing_cron' ); }
		}
	}

	// ---- the definitions

	d5b_wc_def( 'cache-flush', 'cache', 'Flush the caches',
		'Empties every cache cache-status lists (Divi\'s static CSS, WP Fastest Cache, WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, the object cache) and returns flushed, the names of what was emptied. The Updates switch is not needed. Refuses callers without manage_options, and on a network without manage_network. One change-history row records what was found; a flush has nothing to put back. Answers unavailable when the cache code is not loaded.',
		array(), array(), 'd5b_wsite_h_cache_flush', 'd5b_wsite_cache_ok',
		array( 'idempotent' => true ) );

	d5b_wc_def( 'cron-run', 'health', 'Run a scheduled task now',
		'Runs one scheduled WP-Cron event now, as WP-Cron would: hook and timestamp as cron-list returns them, schedule (optional, checked against the event; "single" or "" for a one-off event) and args_key (needed when several events share hook and timestamp; cron-list returns it). A risky action: needs the Site health risky switch; tasks belong to plugins and may do real work (send emails, process orders, run migrations). Needs manage_options, and on a network manage_network. Only a scheduled event runs (not_found otherwise; ambiguous with the keys when args_key is needed); this plugin\'s own d5b_ tasks are refused. A recurring event is booked for its next time and this run taken off the schedule BEFORE the task runs, as WP-Cron does. Refused with unavailable while WP-Cron is running. A task cannot be stopped once started: the time is measured and over_budget is true past 20 seconds. Returns ran, hook, duration_ms, rescheduled, unscheduled and over_budget. A task that fails answers internal_error with schedule_adjusted true. One change-history row records the event (never its argument values).',
		array(
			'hook'      => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 191, 'description' => 'The hook name as cron-list returns it.' ),
			'timestamp' => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'The event\'s timestamp as cron-list returns it.' ),
			'schedule'  => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'Optional: the schedule as cron-list returns it (single for a one-off event); the event must have it.' ),
			'args_key'  => array( 'type' => 'string', 'maxLength' => 64, 'description' => 'Optional: the event\'s args_key from cron-list, needed when several events share hook and timestamp.' ),
		), array( 'hook', 'timestamp' ), 'd5b_wsite_h_cron_run', d5b_rs_perm( 'health' ),
		array( 'idempotent' => false ), array( 'risky' => true ) );
}
