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
			if ( ! d5b_abilities_category_on( $def['category'] ) ) { continue; }
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
			if ( ! empty( $def['input_schema'] ) ) { $args['input_schema'] = $def['input_schema']; }
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
		if ( ! isset( $defs[ $name ] ) || ! d5b_abilities_master_on() || ! d5b_abilities_category_on( (string) ( $defs[ $name ]['category'] ?? '' ) ) ) { return false; }
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

if ( ! function_exists( 'd5b_ability_permit' ) ) {
	/** @return bool|WP_Error true to let the call run; a refusal is logged with status 'forbidden' (one row). */
	function d5b_ability_permit( string $name, $input ) {
		$start = microtime( true );
		$args  = is_array( $input ) ? $input : array();
		$out   = d5b_ability_check( $name, $args );
		if ( true === $out ) { return true; }
		d5b_ability_audit( $name, $args, 'forbidden', d5b_ability_ms_since( $start ) );
		return is_wp_error( $out ) ? d5b_ability_clean_error( $out ) : false;
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
		$defs  = d5b_ability_defs();
		if ( ! isset( $defs[ $name ] ) || ! is_callable( $defs[ $name ]['handler'] ?? null ) ) {
			d5b_ability_audit( $name, $args, 'not_found', 0 );
			return new WP_Error( 'ability_not_found', 'This ability is not available.' );
		}
		$gate = d5b_ability_check( $name, $args );
		if ( true !== $gate ) {
			d5b_ability_audit( $name, $args, 'forbidden', d5b_ability_ms_since( $start ) );
			return d5b_ability_clean_error( is_wp_error( $gate ) ? $gate : new WP_Error( 'forbidden', 'You do not have permission to use this ability, or it is switched off.' ) );
		}
		$id = d5b_ability_audit_start( $name, $args );
		if ( $id < 1 ) {
			return new WP_Error( 'audit_unavailable', 'The activity log cannot be written on this site, so the ability was not run.' );
		}
		$audit_args = $args;
		try {
			$result = call_user_func( $defs[ $name ]['handler'], $args );
			if ( is_wp_error( $result ) ) { $result = d5b_ability_clean_error( $result ); }
			elseif ( function_exists( 'd5b_ability_scrub' ) ) { $result = d5b_ability_scrub( $result ); }
			$status = is_wp_error( $result ) ? (string) $result->get_error_code() : 'ok';
		} catch ( Throwable $e ) {
			// The class and message go to the audit row only; the caller gets a fixed message.
			$audit_args['_exception'] = get_class( $e ) . ': ' . $e->getMessage();
			$result = new WP_Error( 'internal_error', 'Something went wrong on the site. The details are in the activity log.' );
			$status = 'internal_error';
		}
		// The stored args are written again only when they change (_exception) or a refusal caps them smaller.
		d5b_ability_audit_finish( $id, $status, d5b_ability_ms_since( $start ), 'ok' === $status ? null : $audit_args );
		return $result;
	}
}
