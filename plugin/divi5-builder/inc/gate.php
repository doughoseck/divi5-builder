<?php
/**
 * The gate: on rest_api_init (priority 99, late, so an old copy's routes are already there to see)
 * it registers the routes of each part that is wanted. A part that is switched off, or that an old
 * stand-alone copy already provides, registers nothing at all: its routes do not exist.
 * This file only defines functions; the loader adds the hook.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_gate' ) ) {
	function d5b_gate(): void {
		$GLOBALS['d5b_stood_aside'] = array();
		$existing                   = rest_get_server()->get_routes();
		foreach ( d5b_parts() as $key => $part ) {
			if ( defined( $part['legacy_const'] ) ) {
				$GLOBALS['d5b_stood_aside'][ $key ] = 'constant';
			} elseif ( isset( $existing[ $part['probe'] ] ) ) {
				$GLOBALS['d5b_stood_aside'][ $key ] = 'route';
			} elseif ( d5b_switch_is_on( $key ) && function_exists( $part['routes'] ) ) {
				call_user_func( $part['routes'] );
			}
		}
		register_rest_route( 'divi5-builder/v1', '/status', array(
			'methods'             => 'GET',
			'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
			'callback'            => 'd5b_status',
		) );
	}
}

if ( ! function_exists( 'd5b_status' ) ) {
	function d5b_status() {
		$switches = array();
		foreach ( d5b_switches() as $key => $row ) { $switches[ $key ] = $row['on']; }
		return array(
			'version'     => D5B_VERSION,
			'divi5'       => defined( 'ET_BUILDER_5_DIR' ),
			'switches'    => $switches,
			'stood_aside' => isset( $GLOBALS['d5b_stood_aside'] ) ? $GLOBALS['d5b_stood_aside'] : array(),
		);
	}
}
