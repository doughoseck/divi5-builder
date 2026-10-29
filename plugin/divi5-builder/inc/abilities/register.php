<?php
/**
 * The ability registry, the registration gate and the run wrapper. This file only defines functions; it registers no
 * hooks (the loader wires wp_abilities_api_categories_init and wp_abilities_api_init).
 *
 * Nothing is registered unless the Abilities API exists (WordPress 6.9+), the master switch is on and the ability's
 * category switch is on. A switched-off category registers neither its abilities nor the category itself.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_ability_registry' ) ) {
	/**
	 * The one list of definitions, by name. Each group file calls d5b_ability_add() when it is included.
	 * Pass an array to replace the whole list (tests only). No filter on purpose: nothing outside this plugin adds an ability.
	 *
	 * @return array<string, array>
	 */
	function d5b_ability_registry( ?array $replace = null ): array {
		static $defs = array();
		if ( null !== $replace ) { $defs = $replace; }
		return $defs;
	}
}

if ( ! function_exists( 'd5b_ability_add' ) ) {
	/**
	 * Adds one definition: { name, category, label, description, input_schema, output_schema, handler, permission }.
	 * Throws on a duplicate name, a bad name or an unknown category, so a mistake shows in the tests, not on a site.
	 */
	function d5b_ability_add( array $def ): void {
		$name = isset( $def['name'] ) && is_string( $def['name'] ) ? $def['name'] : '';
		if ( 1 !== preg_match( '#^divi5-builder/[a-z0-9]+(-[a-z0-9]+)*$#', $name ) ) {
			throw new InvalidArgumentException( 'Bad ability name: ' . $name );
		}
		$cat = isset( $def['category'] ) && is_string( $def['category'] ) ? $def['category'] : '';
		if ( ! isset( d5b_ability_categories()[ $cat ] ) ) {
			throw new InvalidArgumentException( 'Unknown ability category: ' . $cat );
		}
		if ( isset( d5b_ability_registry()[ $name ] ) ) {
			throw new InvalidArgumentException( 'Duplicate ability name: ' . $name );
		}
		$defs = d5b_ability_registry();
		$defs[ $name ] = $def;
		d5b_ability_registry( $defs );
	}
}

if ( ! function_exists( 'd5b_ability_defs' ) ) {
	/** @return array<string, array> every definition, by name */
	function d5b_ability_defs(): array {
		return d5b_ability_registry();
	}
}

if ( ! function_exists( 'd5b_ability_set_extra_categories' ) ) {
	/**
	 * Strictest switch wins across categories: these definitions (by short name) also need every listed category on, as
	 * well as their own (posts-revisions needs Revisions as well as Posts). An unknown name is ignored; an unknown
	 * category throws, as d5b_ability_add does, so a typo shows in the tests, not on a site.
	 *
	 * @param array<string, string[]> $extra short name => category short slugs
	 */
	function d5b_ability_set_extra_categories( array $extra ): void {
		$defs = d5b_ability_registry();
		$cats = d5b_ability_categories();
		foreach ( $extra as $short => $list ) {
			$name = 'divi5-builder/' . $short;
			if ( ! isset( $defs[ $name ] ) ) { continue; }
			$list = array_values( array_map( 'strval', (array) $list ) );
			foreach ( $list as $c ) {
				if ( ! isset( $cats[ $c ] ) ) { throw new InvalidArgumentException( 'Unknown ability category: ' . $c ); }
			}
			$defs[ $name ]['extra_categories'] = $list;
		}
		d5b_ability_registry( $defs );
	}
}

if ( ! function_exists( 'd5b_ability_categories_on' ) ) {
	/** True when the master, the definition's own category and every one of its extra categories are on. */
	function d5b_ability_categories_on( array $def ): bool {
		if ( ! d5b_abilities_category_on( (string) ( $def['category'] ?? '' ) ) ) { return false; }
		foreach ( (array) ( $def['extra_categories'] ?? array() ) as $c ) {
			if ( ! d5b_abilities_category_on( (string) $c ) ) { return false; }
		}
		return true;
	}
}

if ( ! function_exists( 'd5b_abilities_register_categories' ) ) {
	/** Runs on wp_abilities_api_categories_init. Only categories that are on and hold at least one definition. */
	function d5b_abilities_register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) || ! d5b_abilities_master_on() ) { return; }
		$used = array();
		foreach ( d5b_ability_defs() as $def ) { $used[ $def['category'] ] = true; }
		foreach ( d5b_ability_categories() as $short => $cat ) {
			if ( ! isset( $used[ $short ] ) || ! d5b_abilities_category_on( $short ) ) { continue; }
			if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( $cat['wp_slug'] ) ) { continue; }
			wp_register_ability_category( $cat['wp_slug'], array( 'label' => $cat['label'], 'description' => $cat['description'] ) );
		}
	}
}

if ( ! function_exists( 'd5b_abilities_register' ) ) {
	/** Runs on wp_abilities_api_init. Every definition whose category is on, once. */
	function d5b_abilities_register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! d5b_abilities_master_on() ) { return; }
		// The switches are network-wide: every site that registers abilities needs its own audit table, admin_init or not.
		if ( function_exists( 'd5b_audit_maybe_install' ) ) { d5b_audit_maybe_install(); }
		$cats = d5b_ability_categories();
		foreach ( d5b_ability_defs() as $name => $def ) {
			if ( ! d5b_ability_categories_on( $def ) ) { continue; }
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) { continue; }
			$annotations = array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
			if ( isset( $def['annotations'] ) && is_array( $def['annotations'] ) ) { $annotations = array_merge( $annotations, $def['annotations'] ); }
			$args = array(
				'label'               => $def['label'],
				'description'         => $def['description'],
				'category'            => $cats[ $def['category'] ]['wp_slug'],
				'execute_callback'    => function ( $input = null ) use ( $name ) { return d5b_ability_run( $name, $input ); },
				'permission_callback' => function ( $input = null ) use ( $name ) { return d5b_ability_permit( $name, $input ); },
				'meta'                => array( 'annotations' => $annotations, 'public' => true, 'show_in_rest' => true ),
			);
			if ( ! empty( $def['input_schema'] ) ) {
				// Core validates a missing input (null) against the schema unless the schema has a default.
				if ( 'object' === ( $def['input_schema']['type'] ?? '' ) && ! array_key_exists( 'default', $def['input_schema'] ) ) { $def['input_schema']['default'] = array(); }
				$args['input_schema'] = $def['input_schema'];
			}
			if ( ! empty( $def['output_schema'] ) ) { $args['output_schema'] = $def['output_schema']; }
			wp_register_ability( $name, $args );
		}
	}
}

if ( ! function_exists( 'd5b_ability_audit' ) ) {
	/** One audit row; the audit writer (when present) never throws, and a failure here never reaches the caller. */
	function d5b_ability_audit( string $name, array $args, string $status, int $ms ): void {
		if ( ! function_exists( 'd5b_audit_write' ) ) { return; }
		try { d5b_audit_write( $name, $args, $status, $ms ); } catch ( Throwable $e ) { unset( $e ); }
	}
}

if ( ! function_exists( 'd5b_ability_ms_since' ) ) {
	function d5b_ability_ms_since( float $start ): int { return (int) round( ( microtime( true ) - $start ) * 1000 ); }
}

if ( ! function_exists( 'd5b_ability_check' ) ) {
	/**
	 * The one gate: the master switch, the ability's category switch and its permission callback. Writes no audit row
	 * and never throws. Both the permission callback core calls and the run wrapper ask it, so a direct PHP call to the
	 * wrapper meets the same switches and capabilities as a call through core.
	 *
	 * @return true|false|WP_Error true only when the call may run
	 */
	function d5b_ability_check( string $name, array $args ) {
		$defs = d5b_ability_defs();
		if ( ! isset( $defs[ $name ] ) || ! d5b_abilities_master_on() || ! d5b_ability_categories_on( $defs[ $name ] ) ) { return false; }
		if ( ! is_callable( $defs[ $name ]['permission'] ?? null ) ) { return false; }
		try {
			$out = call_user_func( $defs[ $name ]['permission'], $args );
		} catch ( Throwable $e ) {
			return false;
		}
		if ( true === $out ) { return true; }
		return is_wp_error( $out ) ? $out : false;
	}
}

if ( ! function_exists( 'd5b_ability_clean_error' ) ) {
	/** A WP_Error on its way out: messages and data go through the scrubber when it is loaded. */
	function d5b_ability_clean_error( WP_Error $e ): WP_Error {
		return function_exists( 'd5b_ability_scrub_error' ) ? d5b_ability_scrub_error( $e ) : $e;
	}
}

if ( ! function_exists( 'd5b_ability_http_status' ) ) {
	/** The HTTP status for an error code: bad input 400, refusals 403, missing 404, not available 503, else $default. */
	function d5b_ability_http_status( string $code, int $default = 500 ): int {
		$map = array(
			'invalid_input' => 400, 'page_too_deep' => 400,
			'forbidden' => 403, 'forbidden_anonymous' => 403, 'not_allowed' => 403,
			'not_found' => 404, 'backup_not_found' => 404,
			'stale' => 409, 'masked_read' => 409, 'confirmation_invalid' => 409, 'crosses_markup' => 409, 'ambiguous' => 409, 'cannot_trash' => 409,
			'needs_unfiltered_html' => 403, 'history_unavailable' => 503,
			'unavailable' => 503, 'audit_unavailable' => 503,
			'internal_error' => 500, 'write_failed' => 500,
		);
		return $map[ $code ] ?? $default;
	}
}

if ( ! function_exists( 'd5b_ability_with_status' ) ) {
	/**
	 * Gives an outgoing WP_Error an HTTP status when its data has none. Core's REST controller returns the execute
	 * callback's WP_Error as it is, and an error without a status becomes a 500. A status already there is kept.
	 */
	function d5b_ability_with_status( WP_Error $e, int $default = 500 ): WP_Error {
		$code = (string) $e->get_error_code();
		if ( '' === $code ) { return $e; }
		$data = $e->get_error_data( $code );
		if ( is_array( $data ) && isset( $data['status'] ) ) { return $e; }
		$status = d5b_ability_http_status( $code, $default );
		$e->add_data( is_array( $data ) ? array_merge( $data, array( 'status' => $status ) ) : array( 'status' => $status ), $code );
		return $e;
	}
}

if ( ! function_exists( 'd5b_ability_anonymous_refusal' ) ) {
	/**
	 * Core lets anyone call an ability's permission callback over REST, logged in or not. A caller who is not logged in is
	 * refused here, before any permission callback or handler runs. At most ONE audit row (status forbidden_anonymous) per
	 * 10 minutes for the whole site, whatever the address: a key per address would let rotating addresses write a row (and
	 * two options rows) on every request. The row records the address of the first caller in the window.
	 * The error carries 403; over REST core replaces the permission-path status with rest_authorization_required_code(),
	 * so a logged-out caller sees 401 there.
	 *
	 * @return WP_Error|null the refusal, or null for a logged-in caller
	 */
	function d5b_ability_anonymous_refusal( string $name, array $args, float $start ): ?WP_Error {
		if ( ! function_exists( 'get_current_user_id' ) || 0 !== (int) get_current_user_id() ) { return null; }
		if ( function_exists( 'get_transient' ) && false === get_transient( 'd5b_anon' ) ) {
			if ( function_exists( 'set_transient' ) ) { set_transient( 'd5b_anon', 1, 600 ); }
			d5b_ability_audit( $name, $args, 'forbidden_anonymous', d5b_ability_ms_since( $start ) );
		}
		return d5b_ability_with_status( new WP_Error( 'forbidden', 'You must be logged in to use this ability.' ) );
	}
}

if ( ! function_exists( 'd5b_ability_permit' ) ) {
	/** @return bool|WP_Error true to let the call run; a refusal is logged with status 'forbidden' (one row). */
	function d5b_ability_permit( string $name, $input ) {
		$start = microtime( true );
		$args  = is_array( $input ) ? $input : array();
		$anon  = d5b_ability_anonymous_refusal( $name, $args, $start );
		if ( null !== $anon ) { return $anon; }
		$out   = d5b_ability_check( $name, $args );
		if ( true === $out ) { return true; }
		d5b_ability_audit( $name, $args, 'forbidden', d5b_ability_ms_since( $start ) );
		// A refusal: an error the definition gave without a status is a 403 (core sets its own on this path anyway).
		return is_wp_error( $out ) ? d5b_ability_with_status( d5b_ability_clean_error( $out ), 403 ) : false;
	}
}

if ( ! function_exists( 'd5b_ability_audit_current_state' ) ) {
	/**
	 * The audit row id of the call that is running now. With $set it stores that id and returns the PREVIOUS one (so a
	 * nested call can restore the outer id); without it, it returns the current one. 0 outside a call.
	 */
	function d5b_ability_audit_current_state( ?int $set = null ): int {
		static $current = 0;
		$prev = $current;
		if ( null !== $set ) { $current = max( 0, $set ); }
		return $prev;
	}
}

if ( ! function_exists( 'd5b_ability_audit_current_id' ) ) {
	/** The audit row id of the running ability call (the change history links to it), 0 outside a call. */
	function d5b_ability_audit_current_id(): int {
		return d5b_ability_audit_current_state( null );
	}
}

if ( ! function_exists( 'd5b_ability_audit_start' ) ) {
	/** Writes the 'started' row before a handler runs. @return int its id, 0 when it could not be written */
	function d5b_ability_audit_start( string $name, array $args ): int {
		if ( ! function_exists( 'd5b_audit_write' ) ) { return 0; }
		try { return max( 0, (int) d5b_audit_write( $name, $args, 'started', 0 ) ); } catch ( Throwable $e ) { return 0; }
	}
}

if ( ! function_exists( 'd5b_ability_audit_finish' ) ) {
	/** Puts the final status and duration on the 'started' row; args (when given) replace the stored ones. Never throws. */
	function d5b_ability_audit_finish( int $id, string $status, int $ms, ?array $args ): void {
		if ( ! function_exists( 'd5b_audit_update' ) ) { return; }
		try { d5b_audit_update( $id, $status, $ms, $args ); } catch ( Throwable $e ) { unset( $e ); }
	}
}

if ( ! function_exists( 'd5b_ability_error_status' ) ) {
	/**
	 * The audit status of a handler's own WP_Error: error:<code>, at most 64 characters. The plain statuses (started, ok,
	 * forbidden, internal_error, audit_unavailable, not_found) are written by the wrapper only, so a handler cannot forge them.
	 */
	function d5b_ability_error_status( string $code ): string {
		$s = 'error:' . $code;
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, 64, 'UTF-8' ) : substr( $s, 0, 64 );
	}
}

if ( ! function_exists( 'd5b_ability_scrub_result' ) ) {
	/**
	 * A successful result through the scrubber. Strings under the definition's 'content_keys' (post content of the post-like
	 * abilities) get value-shape masking only, so the content stays editable; every other string gets the full free-text
	 * masker. Any string the scrubber changes adds "masked": true, so a later write can refuse to build on a masked read
	 * (the hash is of the raw content).
	 */
	function d5b_ability_scrub_result( array $def, $result ) {
		if ( ! function_exists( 'd5b_ability_scrub' ) ) { return $result; }
		if ( function_exists( 'd5b_secret_masked_count' ) ) { d5b_secret_masked_count( true ); }
		$content = isset( $def['content_keys'] ) && is_array( $def['content_keys'] ) ? $def['content_keys'] : array();
		$raw     = isset( $def['raw_paths'] ) && is_array( $def['raw_paths'] ) ? $def['raw_paths'] : array();
		$result  = d5b_ability_scrub( $result, 0, $content, false, $raw );
		if ( is_array( $result ) && function_exists( 'd5b_secret_masked_count' ) && d5b_secret_masked_count() > 0 ) {
			$result['masked'] = true;
		}
		return $result;
	}
}

if ( ! function_exists( 'd5b_ability_post_content_keys' ) ) {
	/** The fields that hold post content (raw block markup, CSS, template markup) in the post-like abilities' results. */
	function d5b_ability_post_content_keys(): array {
		return array( 'content', 'raw', 'rendered', 'post_content', 'excerpt', 'css', 'custom_css', 'markup', 'template' );
	}
}

if ( ! function_exists( 'd5b_ability_set_raw_paths' ) ) {
	/**
	 * Declares where a definition's result carries raw third-party data (keys and labels chosen by other plugins): dotted
	 * paths from the result's root, '*' for any one key (sidebars.*.widgets.*.settings). Only there does the scrubber's
	 * row rule (a key-like value naming a secret redacts its neighbours) apply. Unknown names are ignored.
	 *
	 * @param array<string, string[]> $paths short name => paths
	 */
	function d5b_ability_set_raw_paths( array $paths ): void {
		$defs = d5b_ability_registry();
		foreach ( $paths as $short => $list ) {
			$name = 'divi5-builder/' . $short;
			if ( isset( $defs[ $name ] ) ) { $defs[ $name ]['raw_paths'] = array_values( array_map( 'strval', (array) $list ) ); }
		}
		d5b_ability_registry( $defs );
	}
}

if ( ! function_exists( 'd5b_ability_set_content_keys' ) ) {
	/**
	 * Marks these definitions (by short name) as post-like: their post_content_keys fields get value-shape masking only.
	 * An explicit list per ability, never a guess from a key name. Unknown names are ignored.
	 */
	function d5b_ability_set_content_keys( array $short_names ): void {
		$defs = d5b_ability_registry();
		foreach ( $short_names as $short ) {
			$name = 'divi5-builder/' . $short;
			if ( isset( $defs[ $name ] ) ) { $defs[ $name ]['content_keys'] = d5b_ability_post_content_keys(); }
		}
		d5b_ability_registry( $defs );
	}
}

if ( ! function_exists( 'd5b_ability_run' ) ) {
	/**
	 * Runs one ability: the gate again (a direct PHP call skips core's permission callback), then the audit row with
	 * status 'started', then the handler, then the row gets its final status. A fatal error or exit inside the handler
	 * leaves the 'started' row. When that row cannot be written the handler does not run (audit_unavailable).
	 *
	 * @return mixed the handler's result (secrets removed) or a WP_Error; one audit row either way.
	 */
	function d5b_ability_run( string $name, $input ) {
		$start = microtime( true );
		$args  = is_array( $input ) ? $input : array();
		$anon  = d5b_ability_anonymous_refusal( $name, $args, $start );
		if ( null !== $anon ) { return $anon; }
		$defs  = d5b_ability_defs();
		if ( ! isset( $defs[ $name ] ) || ! is_callable( $defs[ $name ]['handler'] ?? null ) ) {
			d5b_ability_audit( $name, $args, 'not_found', 0 );
			return d5b_ability_with_status( new WP_Error( 'not_found', 'This ability is not available.' ) );
		}
		$gate = d5b_ability_check( $name, $args );
		if ( true !== $gate ) {
			d5b_ability_audit( $name, $args, 'forbidden', d5b_ability_ms_since( $start ) );
			return d5b_ability_with_status( d5b_ability_clean_error( is_wp_error( $gate ) ? $gate : new WP_Error( 'forbidden', 'You do not have permission to use this ability, or it is switched off.' ) ), 403 );
		}
		$id = d5b_ability_audit_start( $name, $args );
		if ( $id < 1 ) {
			return d5b_ability_with_status( new WP_Error( 'audit_unavailable', 'The activity log cannot be written on this site, so the ability was not run.' ) );
		}
		$audit_args = $args;
		$outer_id   = d5b_ability_audit_current_state( $id );
		try {
			$result     = call_user_func( $defs[ $name ]['handler'], $args );
			$no_history = false;
			// A write handler sets _history_failed when its change history row could not be stored; the key never leaves here.
			if ( is_array( $result ) && array_key_exists( '_history_failed', $result ) ) {
				$no_history = true === $result['_history_failed'];
				unset( $result['_history_failed'] );
			}
			if ( is_wp_error( $result ) ) { $result = d5b_ability_clean_error( $result ); }
			else { $result = d5b_ability_scrub_result( $defs[ $name ], $result ); }
			$status = is_wp_error( $result ) ? d5b_ability_error_status( (string) $result->get_error_code() ) : ( $no_history ? 'ok_no_history' : 'ok' );
		} catch ( Throwable $e ) {
			// The class and message go to the audit row only; the caller gets a fixed message.
			$audit_args['_exception'] = get_class( $e ) . ': ' . $e->getMessage();
			$result = new WP_Error( 'internal_error', 'Something went wrong on the site. The details are in the activity log.' );
			$status = 'internal_error';
		} finally {
			d5b_ability_audit_current_state( $outer_id );
		}
		// The stored args are written again only when they change (_exception) or a refusal caps them smaller.
		d5b_ability_audit_finish( $id, $status, d5b_ability_ms_since( $start ), in_array( $status, array( 'ok', 'ok_no_history' ), true ) ? null : $audit_args );
		return is_wp_error( $result ) ? d5b_ability_with_status( $result ) : $result;
	}
}
