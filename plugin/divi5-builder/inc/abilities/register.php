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

if ( ! function_exists( 'd5b_ability_permit' ) ) {
	/** @return bool|WP_Error true to let the call run; a refusal is logged with status 'forbidden'. */
	function d5b_ability_permit( string $name, $input ) {
		$start = microtime( true );
		$args  = is_array( $input ) ? $input : array();
		$defs  = d5b_ability_defs();
		$ok    = false;
		$out   = false;
		if ( isset( $defs[ $name ] ) && d5b_abilities_master_on() && d5b_abilities_category_on( $defs[ $name ]['category'] ) && is_callable( $defs[ $name ]['permission'] ?? null ) ) {
			try {
				$out = call_user_func( $defs[ $name ]['permission'], $args );
				$ok  = true === $out;
			} catch ( Throwable $e ) {
				$out = false;
				$ok  = false;
			}
		}
		if ( $ok ) { return true; }
		d5b_ability_audit( $name, $args, 'forbidden', (int) round( ( microtime( true ) - $start ) * 1000 ) );
		return is_wp_error( $out ) ? $out : false;
	}
}

if ( ! function_exists( 'd5b_ability_run' ) ) {
	/** @return mixed the handler's result (secrets removed) or a WP_Error; one audit row either way. */
	function d5b_ability_run( string $name, $input ) {
		$start = microtime( true );
		$args  = is_array( $input ) ? $input : array();
		$defs  = d5b_ability_defs();
		if ( ! isset( $defs[ $name ] ) || ! is_callable( $defs[ $name ]['handler'] ?? null ) ) {
			d5b_ability_audit( $name, $args, 'not_found', 0 );
			return new WP_Error( 'ability_not_found', 'This ability is not available.' );
		}
		$audit_args = $args;
		try {
			$result = call_user_func( $defs[ $name ]['handler'], $args );
			if ( function_exists( 'd5b_ability_scrub' ) && ! is_wp_error( $result ) ) { $result = d5b_ability_scrub( $result ); }
			$status = is_wp_error( $result ) ? (string) $result->get_error_code() : 'ok';
		} catch ( Throwable $e ) {
			// The class and message go to the audit row only; the caller gets a fixed message.
			$audit_args['_exception'] = get_class( $e ) . ': ' . $e->getMessage();
			$result = new WP_Error( 'internal_error', 'Something went wrong on the site. The details are in the activity log.' );
			$status = 'internal_error';
		}
		d5b_ability_audit( $name, $audit_args, $status, (int) round( ( microtime( true ) - $start ) * 1000 ) );
		return $result;
	}
}
