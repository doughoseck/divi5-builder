<?php
/**
 * The switches: one option, d5b_switches, that says which optional parts are on.
 * This file only defines functions; it registers no hooks.
 *
 * Only a person in wp-admin may change a switch (the admin page calls d5b_switch_set after a
 * capability check and a nonce). Nothing here is exposed over REST: the option is never passed to
 * register_setting(), and no route, WP-CLI command or script calls d5b_switch_set.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_switches' ) ) {
	/** The stored switches, with every missing part filled in as off. Parts without a switch are not listed. */
	function d5b_switches(): array {
		$stored = is_multisite() ? get_site_option( 'd5b_switches', array() ) : get_option( 'd5b_switches', array() );
		if ( ! is_array( $stored ) ) { $stored = array(); }
		$out = array();
		foreach ( d5b_parts() as $key => $part ) {
			if ( ! $part['has_switch'] ) { continue; }
			$row         = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();
			$out[ $key ] = array(
				'on'         => isset( $row['on'] ) && true === $row['on'],
				'changed_at' => isset( $row['changed_at'] ) && is_string( $row['changed_at'] ) ? $row['changed_at'] : '',
				'changed_by' => isset( $row['changed_by'] ) && is_string( $row['changed_by'] ) ? $row['changed_by'] : '',
			);
		}
		return $out;
	}
}

if ( ! function_exists( 'd5b_switch_is_on' ) ) {
	function d5b_switch_is_on( string $part ): bool {
		$parts = d5b_parts();
		if ( ! isset( $parts[ $part ] ) ) { return false; }
		if ( ! $parts[ $part ]['has_switch'] ) { return true; }
		$all = d5b_switches();
		return true === $all[ $part ]['on'];
	}
}

if ( ! function_exists( 'd5b_switch_set' ) ) {
	/** Turn a part on or off and record who and when. Throws for a part with no switch. */
	function d5b_switch_set( string $part, bool $on, string $user_login ): void {
		$parts = d5b_parts();
		if ( ! isset( $parts[ $part ] ) || ! $parts[ $part ]['has_switch'] ) {
			throw new InvalidArgumentException( 'This part has no switch: ' . $part );
		}
		$all          = d5b_switches();
		$all[ $part ] = array( 'on' => $on, 'changed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'changed_by' => $user_login );
		if ( is_multisite() ) {
			update_site_option( 'd5b_switches', $all );
		} elseif ( null === get_option( 'd5b_switches', null ) ) {
			add_option( 'd5b_switches', $all, '', 'no' );
		} else {
			update_option( 'd5b_switches', $all, false );
		}
	}
}
