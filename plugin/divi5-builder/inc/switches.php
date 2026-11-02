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
		// A category added after the first switches were stored (email, 2.4.0) is off until an admin ticks it: a site whose
		// master was on must not start sending email on update. The older categories keep their default.
		if ( ! array_key_exists( $slug, $rows ) ) { return ! in_array( $slug, array( 'email' ), true ); }
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

if ( ! function_exists( 'd5b_abilities_switch_rev' ) ) {
	/**
	 * The revision of the stored abilities switches (master, categories, risky boxes, who and when): the settings form
	 * carries it as d5b_switch_rev, and a save whose page showed another state is refused (2.3.0 fix wave, I1), so a page
	 * opened before another admin's change or the update's consent step cannot quietly undo it.
	 */
	function d5b_abilities_switch_rev(): string {
		return md5( serialize( d5b_abilities_switches() ) );
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
	/** False whenever the master or the category is off; the stored risky_on counts only as a real true. */
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
	/** The notice text: $version 1 is the 2.2.0 text, 2 the 2.3.0 text, 3 the 2.4.0 text; null reads which one is stored (2.2.0's when unknown). */
	function d5b_abilities_upgrade_notice_text( ?int $version = null ): string {
		if ( null === $version ) {
			$v       = (int) ( is_multisite() ? get_site_option( 'd5b_abilities_upgrade_notice', 0 ) : get_option( 'd5b_abilities_upgrade_notice', 0 ) );
			$version = in_array( $v, array( 2, 3 ), true ) ? $v : 1;
		}
		if ( 3 === $version ) {
			return 'The AI abilities master switch was turned off by the update to 2.4.0, which adds abilities that manage users, plugins, themes, WordPress updates and email, and whose normal writes send a test email, send password reset links, update translations and refresh the update list. Review the switches and turn it on again when you are ready.';
		}
		if ( 2 === $version ) {
			return 'divi5-builder 2.3.0 adds AI abilities that can change site settings, theme settings, widgets and caches, and risky actions behind their own switches. The AI abilities master switch was turned off during the update; review the switches and turn it on again when you are ready.';
		}
		return 'divi5-builder 2.2.0 adds AI abilities that can create and change content. The AI abilities master switch was turned off during the update; review the category switches and turn it on again when you are ready.';
	}
}

if ( ! function_exists( 'd5b_abilities_upgrade_consent' ) ) {
	/**
	 * The update steps, called by d5b_audit_maybe_install when it moves d5b_db_version up: a master switch that was on was
	 * given before the abilities could do this much, so it is turned off (changed_by '0', with the reason) and the settings
	 * page shows a notice until it is dismissed (d5b_abilities_upgrade_notice, stored where the switches are). A master
	 * already off: nothing.
	 *
	 * '1' -> '2' (2.1.x to 2.2.0, abilities that write): the 2.2.0 step, once per network (d5b_abilities_consent_220).
	 * '2' -> '3' and '1' -> '3' (2.3.0: settings, theme settings, widgets, caches, risky actions): the 2.3.0 step, once per
	 * network (d5b_abilities_consent_230); a site that skips 2.2.0 gets this one step with the 2.3.0 text.
	 * '3' -> '4', and '1' or '2' -> '4' (2.4.0: users, plugins, themes, WordPress updates and email; normal writes that send a
	 * test email, send password reset links, update translations and refresh the update list): the 2.4.0 step, once per
	 * network (d5b_abilities_consent_240), notice 3; a site that skips 2.2.0 and 2.3.0 gets this one step.
	 * No arguments means the 2.2.0 step, as before. Any other move: nothing.
	 */
	function d5b_abilities_upgrade_consent( string $from = '1', string $to = '2' ): void {
		if ( '4' === $to && in_array( $from, array( '1', '2', '3' ), true ) ) {
			$once = 'd5b_abilities_consent_240'; $notice = 3;
			$why  = 'turned off by the update to 2.4.0, which adds abilities that manage users, plugins, themes, WordPress updates and email, and whose normal writes send a test email, send password reset links, update translations and refresh the update list';
		} elseif ( '3' === $to && in_array( $from, array( '1', '2' ), true ) ) {
			$once = 'd5b_abilities_consent_230'; $notice = 2;
			$why  = 'turned off by the update to 2.3.0, which adds abilities that change site settings, theme settings, widgets and caches';
		} elseif ( '2' === $to && '1' === $from ) {
			$once = 'd5b_abilities_consent_220'; $notice = 1;
			$why  = 'turned off by the update to 2.2.0, which adds abilities that create and change content';
		} else {
			return;
		}
		// On a network the switches are one network option but every site upgrades its own tables: the step runs once per
		// network, so a master switched on again after the first site's update stays on.
		if ( is_multisite() ) {
			if ( get_site_option( $once, 0 ) ) { return; }
			update_site_option( $once, 1 );
		}
		if ( ! d5b_abilities_master_on() ) { return; }
		d5b_abilities_set_master( false, '0', $why );
		if ( is_multisite() ) { update_site_option( 'd5b_abilities_upgrade_notice', $notice ); } else { update_option( 'd5b_abilities_upgrade_notice', $notice, false ); }
	}
}

if ( ! function_exists( 'd5b_abilities_upgrade_notice_on' ) ) {
	function d5b_abilities_upgrade_notice_on(): bool {
		return (bool) ( is_multisite() ? get_site_option( 'd5b_abilities_upgrade_notice', 0 ) : get_option( 'd5b_abilities_upgrade_notice', 0 ) );
	}
}
