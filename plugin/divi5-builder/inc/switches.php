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
		$raw          = d5b_switches_stored();
		if ( isset( $raw['abilities'] ) ) { $all['abilities'] = $raw['abilities']; } // the abilities switches are not ours to drop
		$all[ $part ] = array( 'on' => $on, 'changed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'changed_by' => $user_login );
		d5b_switches_store( $all );
	}
}

if ( ! function_exists( 'd5b_switches_stored' ) ) {
	/** The whole stored option as an array (anything else reads as empty). Raw: nothing is normalized. */
	function d5b_switches_stored(): array {
		$stored = is_multisite() ? get_site_option( 'd5b_switches', array() ) : get_option( 'd5b_switches', array() );
		return is_array( $stored ) ? $stored : array();
	}
}

if ( ! function_exists( 'd5b_switches_store' ) ) {
	/** Writes the whole option: a site option on multisite, otherwise an option that is not autoloaded. */
	function d5b_switches_store( array $all ): void {
		if ( is_multisite() ) {
			update_site_option( 'd5b_switches', $all );
		} elseif ( null === get_option( 'd5b_switches', null ) ) {
			add_option( 'd5b_switches', $all, '', 'no' );
		} else {
			update_option( 'd5b_switches', $all, false );
		}
	}
}

if ( ! function_exists( 'd5b_abilities_category_stored_on' ) ) {
	/**
	 * One category's stored value: a category never stored reads on (the default, it only matters once the master is on);
	 * a stored value counts as on only when it is an array whose 'on' is a real true. A scalar, false, "off", 0 or [] is off.
	 */
	function d5b_abilities_category_stored_on( array $rows, string $slug ): bool {
		if ( ! array_key_exists( $slug, $rows ) ) { return true; }
		$row = $rows[ $slug ];
		return is_array( $row ) && array_key_exists( 'on', $row ) && true === $row['on'];
	}
}

if ( ! function_exists( 'd5b_abilities_switches' ) ) {
	/**
	 * The AI abilities switches, normalized: { master: {on, changed_at, changed_by}, categories: { slug: {on, risky_on, changed_at, changed_by} } }.
	 * Fail closed: the master and every risky_on read off unless stored as a real true. A category never stored reads on
	 * (it only matters once the master is on); a stored category reads on only as an array with 'on' => true; a
	 * 'categories' value that is not an array turns every category off.
	 * Every known category is listed. Reading writes nothing.
	 */
	function d5b_abilities_switches(): array {
		$raw = d5b_switches_stored();
		$ab  = isset( $raw['abilities'] ) && is_array( $raw['abilities'] ) ? $raw['abilities'] : array();
		$str = function ( $row, $key ) { return is_array( $row ) && isset( $row[ $key ] ) && is_string( $row[ $key ] ) ? $row[ $key ] : ''; };
		$m   = isset( $ab['master'] ) && is_array( $ab['master'] ) ? $ab['master'] : array();
		$out = array(
			'master'     => array( 'on' => isset( $m['on'] ) && true === $m['on'], 'changed_at' => $str( $m, 'changed_at' ), 'changed_by' => $str( $m, 'changed_by' ), 'reason' => $str( $m, 'reason' ) ),
			'categories' => array(),
		);
		// A 'categories' value that is not an array means nothing can be trusted: every category reads off.
		$cats_ok = ! array_key_exists( 'categories', $ab ) || is_array( $ab['categories'] );
		$rows    = $cats_ok && isset( $ab['categories'] ) ? $ab['categories'] : array();
		$slugs   = function_exists( 'd5b_ability_categories' ) ? array_keys( d5b_ability_categories() ) : array();
		foreach ( $slugs as $slug ) {
			$row = isset( $rows[ $slug ] ) && is_array( $rows[ $slug ] ) ? $rows[ $slug ] : array();
			$out['categories'][ $slug ] = array(
				'on'         => $cats_ok && d5b_abilities_category_stored_on( $rows, $slug ),
				'risky_on'   => isset( $row['risky_on'] ) && true === $row['risky_on'],
				'changed_at' => $str( $row, 'changed_at' ),
				'changed_by' => $str( $row, 'changed_by' ),
			);
		}
		return $out;
	}
}

if ( ! function_exists( 'd5b_abilities_master_on' ) ) {
	function d5b_abilities_master_on(): bool {
		return true === d5b_abilities_switches()['master']['on'];
	}
}

if ( ! function_exists( 'd5b_abilities_category_on' ) ) {
	/** False whenever the master is off, and for a slug that is not a known category. */
	function d5b_abilities_category_on( string $slug ): bool {
		$a = d5b_abilities_switches();
		if ( true !== $a['master']['on'] || ! isset( $a['categories'][ $slug ] ) ) { return false; }
		return true === $a['categories'][ $slug ]['on'];
	}
}

if ( ! function_exists( 'd5b_abilities_risky_on' ) ) {
	/** False whenever the master or the category is off. Unused until 2.3.0: the risky switches arrive with 2.3.0 (2.2.0 has no risky abilities). */
	function d5b_abilities_risky_on( string $slug ): bool {
		$a = d5b_abilities_switches();
		if ( true !== $a['master']['on'] || ! isset( $a['categories'][ $slug ] ) || true !== $a['categories'][ $slug ]['on'] ) { return false; }
		return true === $a['categories'][ $slug ]['risky_on'];
	}
}

if ( ! function_exists( 'd5b_abilities_set_master' ) ) {
	/**
	 * Switch the master on or off and record who and when. Keeps every other key of the option. $reason (optional) says why
	 * when no person did it (the plugin itself, changed_by '0': the 2.2.0 update turning it off).
	 */
	function d5b_abilities_set_master( bool $on, string $user_login, string $reason = '' ): void {
		$all = d5b_switches_stored();
		$ab  = isset( $all['abilities'] ) && is_array( $all['abilities'] ) ? $all['abilities'] : array();
		$ab['master'] = array( 'on' => $on, 'changed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'changed_by' => $user_login );
		if ( '' !== $reason ) { $ab['master']['reason'] = $reason; }
		$all['abilities'] = $ab;
		d5b_switches_store( $all );
	}
}

if ( ! function_exists( 'd5b_abilities_set_category' ) ) {
	/** Set one category (and its risky switch) and record who and when. Throws for a slug that is not a known category. */
	function d5b_abilities_set_category( string $slug, bool $on, bool $risky_on, string $user_login ): void {
		if ( ! function_exists( 'd5b_ability_categories' ) || ! isset( d5b_ability_categories()[ $slug ] ) ) {
			throw new InvalidArgumentException( 'Unknown ability category: ' . $slug );
		}
		$all = d5b_switches_stored();
		$ab  = isset( $all['abilities'] ) && is_array( $all['abilities'] ) ? $all['abilities'] : array();
		if ( ! isset( $ab['categories'] ) ) { $ab['categories'] = array(); }
		if ( ! is_array( $ab['categories'] ) ) {
			// A corrupt value reads as every category off: write that down, so setting one category does not turn the
			// others back on as "never stored".
			$ab['categories'] = array_fill_keys( array_keys( d5b_ability_categories() ), array( 'on' => false, 'risky_on' => false, 'changed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'changed_by' => $user_login ) );
		}
		$ab['categories'][ $slug ] = array( 'on' => $on, 'risky_on' => $risky_on, 'changed_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'changed_by' => $user_login );
		$all['abilities'] = $ab;
		d5b_switches_store( $all );
	}
}

if ( ! function_exists( 'd5b_abilities_upgrade_notice_text' ) ) {
	function d5b_abilities_upgrade_notice_text(): string {
		return 'divi5-builder 2.2.0 adds AI abilities that can create and change content. The AI abilities master switch was turned off during the update; review the category switches and turn it on again when you are ready.';
	}
}

if ( ! function_exists( 'd5b_abilities_upgrade_consent' ) ) {
	/**
	 * The update from 2.1.x (schema 1, read-only abilities) to 2.2.0 (schema 2, abilities that write), called by
	 * d5b_audit_maybe_install when it moves d5b_db_version from '1' to '2': a master switch that was on was given for reads
	 * only, so it is turned off (changed_by '0', with the reason) and the settings page shows a notice until it is dismissed
	 * (d5b_abilities_upgrade_notice, stored where the switches are). A master already off: nothing.
	 */
	function d5b_abilities_upgrade_consent(): void {
		if ( ! d5b_abilities_master_on() ) { return; }
		d5b_abilities_set_master( false, '0', 'turned off by the update to 2.2.0, which adds abilities that create and change content' );
		if ( is_multisite() ) { update_site_option( 'd5b_abilities_upgrade_notice', 1 ); } else { update_option( 'd5b_abilities_upgrade_notice', 1, false ); }
	}
}

if ( ! function_exists( 'd5b_abilities_upgrade_notice_on' ) ) {
	function d5b_abilities_upgrade_notice_on(): bool {
		return (bool) ( is_multisite() ? get_site_option( 'd5b_abilities_upgrade_notice', 0 ) : get_option( 'd5b_abilities_upgrade_notice', 0 ) );
	}
}
