<?php
/**
 * Write abilities for widgets (#32, release 2.3.0): widgets-add, widgets-update, widgets-move and widgets-remove.
 *
 * This file only defines functions. The definitions are added to the registry when the file is included (register.php
 * collects them); nothing is registered with WordPress from here.
 *
 * The model is core's own (widgets.php, class-wp-widget.php, the widgets REST controller):
 *  - A widget id is <id_base>-<number>. Its settings (the instance) live in the option widget_<id_base>[<number>], next to
 *    _multiwidget 1; where it is placed lives in sidebars_widgets (sidebar id => list of widget ids), read and written
 *    through wp_get_sidebars_widgets / wp_set_sidebars_widgets. wp_inactive_widgets is the "Inactive widgets" list.
 *  - The widget types are the WP_Widget objects in $wp_widget_factory, found by id_base. Only these multi-instance widgets
 *    are handled: a legacy single-instance widget (an id without -<number>, or a type that is not a WP_Widget) is refused.
 *  - A new widget gets the next number: the largest one in use for its type (stored or placed) plus 1, at least 2 (core
 *    numbers the same way).
 *
 * Rules (design 2026-10-30, "Widgets" and "Guardrails"):
 *  - All four are normal writes in the widgets category; they need edit_theme_options.
 *  - Settings go through the widget's own update() sanitiser and then core's widget_update_callback filter, as
 *    WP_Widget::update_callback applies them (false from a plugin refuses). Core's REST-only hooks (rest_after_save_widget,
 *    rest_delete_widget) do not run. A block widget whose content core's kses would change (beyond entity normalisation),
 *    or that holds Divi blocks, needs unfiltered_html: without it update() would rewrite it and corrupt the block markup.
 *    Markup a text widget's update() removed for a user without unfiltered_html is reported as a warning.
 *  - Settings are an object, JSON-safe, at most 256 KB encoded; text holding [redacted] (a masked read) is refused, and so
 *    is a secret-named key. A widget whose stored settings hold a secret-named key cannot be updated or removed: the
 *    history could not keep it in full (the 2.2.0 rule).
 *  - The one rule: every change writes one history row (object_type widget, object_id the number) whose before can rebuild
 *    the old state: the widget id, the sidebar, the position, the whole instance and, for add, move and remove, the whole
 *    sidebars_widgets map as a list of { sidebar, widgets } pairs (sidebar ids are values, never keys, so the history's
 *    key filter cannot drop a sidebar such as footer-token). A remove is not a two-step delete: the row is the copy.
 *  - After each write the map and the instance are read again; a difference from what was written is a warning.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_ww_sidebar_ok' ) ) {

	/** The theme's registered sidebars: id => args. */
	function d5b_ww_registered(): array {
		return isset( $GLOBALS['wp_registered_sidebars'] ) && is_array( $GLOBALS['wp_registered_sidebars'] ) ? $GLOBALS['wp_registered_sidebars'] : array();
	}

	/**
	 * A sidebar a widget may be placed in: a registered one or wp_inactive_widgets. @return true|WP_Error
	 */
	function d5b_ww_sidebar_ok( string $sidebar ) {
		if ( 'wp_inactive_widgets' === $sidebar || array_key_exists( $sidebar, d5b_ww_registered() ) ) { return true; }
		$ids = array_slice( array_map( 'strval', array_keys( d5b_ww_registered() ) ), 0, 30 );
		return new WP_Error( 'invalid_input', 'sidebar must be a sidebar of the active theme or wp_inactive_widgets. Sidebars: ' . ( $ids ? implode( ', ', $ids ) . ', ' : '' ) . 'wp_inactive_widgets.' );
	}

	/** The registered multi-instance widget type with this id_base, or null. */
	function d5b_ww_type( string $id_base ) {
		$f = $GLOBALS['wp_widget_factory'] ?? null;
		if ( ! is_object( $f ) || ! isset( $f->widgets ) || ! is_array( $f->widgets ) ) { return null; }
		foreach ( $f->widgets as $w ) {
			if ( $w instanceof WP_Widget && (string) $w->id_base === $id_base ) { return $w; }
		}
		return null;
	}

	/** The id_bases of the registered types, for messages. */
	function d5b_ww_type_list(): string {
		$f = $GLOBALS['wp_widget_factory'] ?? null;
		$out = array();
		if ( is_object( $f ) && isset( $f->widgets ) && is_array( $f->widgets ) ) {
			foreach ( $f->widgets as $w ) { if ( $w instanceof WP_Widget ) { $out[] = (string) $w->id_base; } }
		}
		return implode( ', ', array_slice( $out, 0, 50 ) );
	}

	function d5b_ww_unknown_type( string $id_base ): WP_Error {
		return new WP_Error( 'invalid_input', 'The widget type "' . d5b_rc_cut( $id_base, 100 ) . '" is not a registered multi-instance widget (legacy single-instance widgets are changed in wp-admin). Types: ' . d5b_ww_type_list() . '.' );
	}

	/**
	 * A widget id split as core's wp_parse_widget_id splits it, with its type. @return array|WP_Error array( id_base, number, WP_Widget )
	 */
	function d5b_ww_parse( $id ) {
		if ( ! is_string( $id ) || '' === $id ) { return new WP_Error( 'invalid_input', 'id is required: the widget id as widgets-list returns it, such as text-3.' ); }
		if ( 1 !== preg_match( '/^(.+)-(\d{1,9})$/D', $id, $m ) || (int) $m[2] < 1 ) {
			return new WP_Error( 'invalid_input', 'The widget "' . d5b_rc_cut( $id, 100 ) . '" is not a multi-instance widget (type-number); legacy single-instance widgets are changed in wp-admin.' );
		}
		// Ids are matched exactly: text-02 is not text-2 (its number would be, and the settings of text-2 would change).
		if ( '0' === $m[2][0] ) {
			return new WP_Error( 'invalid_input', 'The widget id "' . d5b_rc_cut( $id, 100 ) . '" has a number with a leading zero; use the id exactly as widgets-list returns it.' );
		}
		$w = d5b_ww_type( $m[1] );
		if ( null === $w ) { return d5b_ww_unknown_type( $m[1] ); }
		return array( $m[1], (int) $m[2], $w );
	}

	function d5b_ww_json( $v ): string {
		$j = function_exists( 'wp_json_encode' ) ? wp_json_encode( $v ) : json_encode( $v );
		return is_string( $j ) ? $j : '';
	}

	function d5b_ww_unknown( array $in, array $allowed ): ?WP_Error {
		foreach ( array_keys( $in ) as $k ) {
			if ( ! in_array( $k, $allowed, true ) ) {
				return new WP_Error( 'invalid_input', 'Unknown input "' . substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $k ), 0, 100 ) . '". Allowed: ' . implode( ', ', $allowed ) . '.' );
			}
		}
		return null;
	}

	/** The placement map (sidebars_widgets without array_version). */
	function d5b_ww_map(): array {
		$m = function_exists( 'wp_get_sidebars_widgets' ) ? wp_get_sidebars_widgets() : array();
		return is_array( $m ) ? $m : array();
	}

	/**
	 * The map as the history keeps it: a list of { sidebar, widgets } in the map's order. Sidebar ids are values, so the
	 * history's key filter never drops one (footer-token, orphaned_widgets_1 ...). d5b_ww_map_from_pairs turns it back.
	 */
	function d5b_ww_pairs( array $map ): array {
		$out = array();
		foreach ( $map as $sid => $ids ) { $out[] = array( 'sidebar' => (string) $sid, 'widgets' => is_array( $ids ) ? array_values( $ids ) : $ids ); }
		return $out;
	}

	/** The map rebuilt from d5b_ww_pairs (a restore, and the tests). */
	function d5b_ww_map_from_pairs( array $pairs ): array {
		$map = array();
		foreach ( $pairs as $p ) { if ( is_array( $p ) && isset( $p['sidebar'] ) ) { $map[ (string) $p['sidebar'] ] = $p['widgets'] ?? array(); } }
		return $map;
	}

	/** Where the widget is placed: array( sidebar, position ), or null when it is in no sidebar. */
	function d5b_ww_find( string $id, array $map ): ?array {
		foreach ( $map as $sid => $ids ) {
			if ( ! is_array( $ids ) ) { continue; }
			$pos = array_search( $id, array_values( array_map( 'strval', $ids ) ), true );
			if ( false !== $pos ) { return array( (string) $sid, (int) $pos ); }
		}
		return null;
	}

	/** Every place the widget is in: a list of array( sidebar, position ), in the map's order (empty when none). */
	function d5b_ww_find_all( string $id, array $map ): array {
		$out = array();
		foreach ( $map as $sid => $ids ) {
			if ( ! is_array( $ids ) ) { continue; }
			foreach ( array_values( array_map( 'strval', $ids ) ) as $pos => $x ) { if ( $x === $id ) { $out[] = array( (string) $sid, (int) $pos ); } }
		}
		return $out;
	}

	/** The map with $id taken out of $sidebar (every occurrence there). */
	function d5b_ww_take( array $map, string $id, string $sidebar ): array {
		$ids = is_array( $map[ $sidebar ] ?? null ) ? $map[ $sidebar ] : array();
		$map[ $sidebar ] = array_values( array_filter( $ids, function ( $x ) use ( $id ) { return (string) $x !== $id; } ) );
		return $map;
	}

	/** The map with $id put into $sidebar at $position (0-based, clamped to the end). */
	function d5b_ww_put( array $map, string $id, string $sidebar, ?int $position ): array {
		$ids = is_array( $map[ $sidebar ] ?? null ) ? array_values( $map[ $sidebar ] ) : array();
		$at  = null === $position ? count( $ids ) : max( 0, min( $position, count( $ids ) ) );
		array_splice( $ids, $at, 0, array( $id ) );
		$map[ $sidebar ] = $ids;
		return $map;
	}

	/** The stored instances of a type: number => instance (_multiwidget left in). */
	function d5b_ww_instances( string $id_base ): array {
		$o = get_option( 'widget_' . $id_base, array() );
		return is_array( $o ) ? $o : array();
	}

	/** The stored instance of one widget, or null. */
	function d5b_ww_instance( string $id_base, int $number ) {
		$all = d5b_ww_instances( $id_base );
		return array_key_exists( $number, $all ) ? $all[ $number ] : null;
	}

	/** Writes one instance into widget_<id_base>, as WP_Widget::save_settings does (_multiwidget 1). */
	function d5b_ww_save( string $id_base, int $number, array $instance ): void {
		$all = d5b_ww_instances( $id_base );
		$all[ $number ] = $instance;
		$all['_multiwidget'] = 1;
		update_option( 'widget_' . $id_base, $all );
	}

	/** Deletes one instance from widget_<id_base>. */
	function d5b_ww_unsave( string $id_base, int $number ): void {
		$all = d5b_ww_instances( $id_base );
		unset( $all[ $number ] );
		$all['_multiwidget'] = 1;
		update_option( 'widget_' . $id_base, $all );
	}

	/** The next number of a type: the largest in use (stored or placed) plus 1, at least 2. */
	function d5b_ww_next_number( string $id_base, array $map ): int {
		$max = 1;
		foreach ( array_keys( d5b_ww_instances( $id_base ) ) as $k ) { if ( is_int( $k ) ) { $max = max( $max, $k ); } }
		$re = '/^' . preg_quote( $id_base, '/' ) . '-(\d+)$/D';
		foreach ( $map as $ids ) {
			if ( ! is_array( $ids ) ) { continue; }
			foreach ( $ids as $wid ) { if ( 1 === preg_match( $re, (string) $wid, $m ) ) { $max = max( $max, (int) $m[1] ); } }
		}
		return $max + 1;
	}

	/**
	 * The incoming settings, checked before the widget sees them: an object, JSON-safe, at most 256 KB encoded, no
	 * secret-named key, no [redacted] unless the stored settings ($stored_json) hold it too. @return array|WP_Error
	 */
	function d5b_ww_settings_in( $raw, string $stored_json ) {
		$s = d5b_wc_unobject( $raw );
		if ( ! is_array( $s ) || ( array() !== $s && d5b_rc_is_list( $s ) ) ) { return new WP_Error( 'invalid_input', 'settings must be an object of setting name => value (for a block widget {"content": "<!-- wp:paragraph -->..."}).' ); }
		if ( ! d5b_wc_json_safe( $s ) ) { return new WP_Error( 'invalid_input', 'settings must hold text, numbers, true/false and lists/objects of those (valid UTF-8, at most 8 levels deep).' ); }
		$enc = d5b_ww_json( $s );
		if ( '' === $enc || strlen( $enc ) > 262144 ) { return new WP_Error( 'invalid_input', 'settings are too large: at most 256 KB once encoded.' ); }
		if ( ! d5b_wc_secret_free( $s ) ) { return new WP_Error( 'invalid_input', 'settings hold a secret-named key; secrets are not written by an ability. Change it in wp-admin.' ); }
		$masked = d5b_wc_check_masked( $enc, $stored_json );
		if ( true !== $masked ) { return $masked; }
		return $s;
	}

	/** Text with its entities decoded: kses normalises entities (a bare & becomes &amp;), which changes nothing that shows. */
	function d5b_ww_decoded( string $s ): string {
		return html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * The instance the widget's own update() makes of $settings ($old: the stored instance, or none for a new widget), then
	 * core's widget_update_callback filter, as WP_Widget::update_callback runs them; false from either refuses. Without
	 * unfiltered_html, a block widget whose content kses would change beyond entity normalisation, or that holds Divi
	 * blocks, is refused needs_unfiltered_html. @return array|WP_Error
	 */
	function d5b_ww_instance_in( WP_Widget $w, array $settings, ?array $old ) {
		if ( 'block' === (string) $w->id_base || ( class_exists( 'WP_Widget_Block' ) && $w instanceof WP_Widget_Block ) ) {
			$content = $settings['content'] ?? null;
			if ( ! is_string( $content ) ) { return new WP_Error( 'invalid_input', 'A block widget needs settings.content: the block markup as text.' ); }
			if ( ! current_user_can( 'unfiltered_html' ) && ( d5b_wc_has_divi( $content ) || d5b_ww_decoded( (string) wp_kses_post( $content ) ) !== d5b_ww_decoded( $content ) ) ) {
				return new WP_Error( 'needs_unfiltered_html', 'This block widget holds Divi blocks or HTML that WordPress removes for users without the unfiltered_html capability (an administrator or editor on a single site); saving it would change the block markup.' );
			}
		}
		$old = $old ?? array();
		try {
			$r = $w->update( $settings, $old );
		} catch ( Throwable $e ) {
			return new WP_Error( 'invalid_input', 'The widget could not take these settings (' . d5b_rc_cut( $e->getMessage(), 200 ) . ').' );
		}
		if ( false === $r ) { return new WP_Error( 'invalid_input', 'The widget refused these settings (its own update() check said no).' ); }
		// core's WP_Widget::update_callback: plugins (visibility rules and the like) change or refuse the instance here.
		$r = apply_filters( 'widget_update_callback', $r, $settings, $old, $w );
		if ( false === $r ) { return new WP_Error( 'invalid_input', 'A plugin refused this widget\'s settings (the widget_update_callback filter).' ); }
		if ( ! is_array( $r ) ) { return new WP_Error( 'invalid_input', 'The widget refused these settings (its update() did not return settings).' ); }
		return $r;
	}

	/**
	 * For a caller without unfiltered_html: true when a text value given (the title left out: core strips tags from every
	 * title) was stored differently, beyond entity normalisation, i.e. the widget's own update() removed markup.
	 */
	function d5b_ww_markup_removed( array $settings, $stored ): bool {
		if ( current_user_can( 'unfiltered_html' ) || ! is_array( $stored ) ) { return false; }
		foreach ( $settings as $k => $v ) {
			if ( 'title' === $k || ! is_string( $v ) || ! array_key_exists( $k, $stored ) || ! is_string( $stored[ $k ] ) ) { continue; }
			if ( d5b_ww_decoded( $stored[ $k ] ) !== d5b_ww_decoded( $v ) ) { return true; }
		}
		return false;
	}

	function d5b_ww_markup_warning(): string {
		return 'WordPress removed markup you may not save (no unfiltered_html); the stored settings are in settings.';
	}

	/** True when two instances are the same once stored (stdClass as arrays). */
	function d5b_ww_same( $a, $b ): bool {
		return d5b_ww_json( d5b_wc_unobject( $a ) ) === d5b_ww_json( d5b_wc_unobject( $b ) );
	}

	/** The position input: null when absent, an int from 0, or a WP_Error. */
	function d5b_ww_position( array $in, bool $required ) {
		if ( ! array_key_exists( 'position', $in ) || null === $in['position'] ) {
			return $required ? new WP_Error( 'invalid_input', 'position is required: 0 for the top of the sidebar; a larger number than the widgets there puts it at the end.' ) : null;
		}
		$p = $in['position'];
		if ( is_string( $p ) && 1 === preg_match( '/^\d{1,6}$/D', $p ) ) { $p = (int) $p; }
		if ( ! is_int( $p ) || $p < 0 ) { return new WP_Error( 'invalid_input', 'position must be a whole number from 0 (the top of the sidebar).' ); }
		return $p;
	}

	function d5b_ww_sidebar_in( array $in ) {
		$s = $in['sidebar'] ?? null;
		if ( ! is_string( $s ) || '' === $s ) { return new WP_Error( 'invalid_input', 'sidebar is required: a sidebar id as widgets-list returns it, or wp_inactive_widgets.' ); }
		$ok = d5b_ww_sidebar_ok( $s );
		return true === $ok ? $s : $ok;
	}

	/** Warnings for a placement read back that differs from what was written. */
	function d5b_ww_placement_warn( string $id, ?string $sidebar, ?int $position, array &$warn ): ?array {
		$at = d5b_ww_find( $id, d5b_ww_map() );
		if ( null === $sidebar ) {
			if ( null !== $at ) { $warn[] = 'WordPress still lists the widget in ' . $at[0] . ' (another plugin may change the widget layout); read it again.'; }
			return $at;
		}
		if ( null === $at || $at[0] !== $sidebar || $at[1] !== $position ) {
			$warn[] = 'WordPress stored the widget layout differently from what was written (another plugin may change it); the stored place is in sidebar and position.';
		}
		return $at;
	}

	// ---- the handlers

	function d5b_ww_h_add( array $in ) {
		$e = d5b_rs_guard( 'widgets' ); if ( $e ) { return $e; }
		$e = d5b_ww_unknown( $in, array( 'sidebar', 'type', 'settings', 'position' ) ); if ( $e ) { return $e; }
		$sidebar = d5b_ww_sidebar_in( $in ); if ( is_wp_error( $sidebar ) ) { return $sidebar; }
		$type = $in['type'] ?? null;
		if ( ! is_string( $type ) || '' === $type ) { return new WP_Error( 'invalid_input', 'type is required: a widget type id such as block or text. Types: ' . d5b_ww_type_list() . '.' ); }
		$w = d5b_ww_type( $type );
		if ( null === $w ) { return d5b_ww_unknown_type( $type ); }
		$pos = d5b_ww_position( $in, false ); if ( is_wp_error( $pos ) ) { return $pos; }
		if ( ! array_key_exists( 'settings', $in ) ) { return new WP_Error( 'invalid_input', 'settings is required: the widget\'s settings ({} for its defaults).' ); }
		$s = d5b_ww_settings_in( $in['settings'], '' ); if ( is_wp_error( $s ) ) { return $s; }
		$map = d5b_ww_map();
		$inst = d5b_ww_instance_in( $w, $s, null ); if ( is_wp_error( $inst ) ) { return $inst; }
		$before = array( 'absent' => true, 'sidebars_widgets' => d5b_ww_pairs( $map ) );
		$big = d5b_wc_check_content_size( d5b_ww_json( $before ) ); if ( true !== $big ) { return $big; }
		$number = d5b_ww_next_number( $type, $map );
		$id     = $type . '-' . $number;
		d5b_ww_save( $type, $number, $inst );
		$stored = d5b_ww_instance( $type, $number );
		if ( null === $stored ) { return new WP_Error( 'write_failed', 'WordPress did not store the widget\'s settings (another plugin may block it).' ); }
		$new = d5b_ww_put( $map, $id, $sidebar, $pos );
		wp_set_sidebars_widgets( $new );
		if ( null === d5b_ww_find( $id, d5b_ww_map() ) ) {
			// Not placed: take the settings out again, so nothing is left behind that no row records.
			d5b_ww_unsave( $type, $number );
			return new WP_Error( 'write_failed', 'WordPress did not store the widget layout (another plugin may block it); nothing was added.' );
		}
		$want = d5b_ww_find( $id, $new );
		$warn = array();
		$at   = d5b_ww_placement_warn( $id, $want[0], $want[1], $warn );
		if ( ! d5b_ww_same( $stored, $inst ) ) { $warn[] = 'WordPress stored the settings differently from what the widget made of them (another plugin may change them); the stored settings are in settings.'; }
		if ( d5b_ww_markup_removed( $s, d5b_wc_unobject( $stored ) ) ) { $warn[] = d5b_ww_markup_warning(); }
		$out = array( 'id' => $id, 'sidebar' => null === $at ? null : $at[0], 'position' => null === $at ? null : $at[1], 'settings' => d5b_wc_unobject( $stored ) );
		if ( $warn ) { $out['warnings'] = $warn; }
		$hid = d5b_wc_history_row( 'divi5-builder/widgets-add', 'widget', $number, 'create', $before,
			array( 'id' => $id, 'sidebar' => $out['sidebar'], 'position' => $out['position'], 'instance' => d5b_wc_meta_value( $stored ) ),
			'Added widget ' . $id . ' to ' . $sidebar );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	/** id, its type and where it is. @return array|WP_Error array( id, id_base, number, widget, map, sidebar, position ) */
	function d5b_ww_existing( array $in ) {
		$p = d5b_ww_parse( $in['id'] ?? null ); if ( is_wp_error( $p ) ) { return $p; }
		$map = d5b_ww_map();
		$id  = (string) $in['id'];
		$at  = d5b_ww_find( $id, $map );
		if ( null === $at ) { return new WP_Error( 'not_found', 'The widget "' . d5b_rc_cut( $id, 100 ) . '" is not in any sidebar (nor in the inactive widgets). Read widgets-list for the ids.' ); }
		return array( $id, $p[0], $p[1], $p[2], $map, $at[0], $at[1] );
	}

	function d5b_ww_h_update( array $in ) {
		$e = d5b_rs_guard( 'widgets' ); if ( $e ) { return $e; }
		$e = d5b_ww_unknown( $in, array( 'id', 'settings' ) ); if ( $e ) { return $e; }
		$x = d5b_ww_existing( $in ); if ( is_wp_error( $x ) ) { return $x; }
		list( $id, $base, $number, $w, $map, $sidebar, $position ) = $x;
		$old = d5b_ww_instance( $base, $number );
		if ( null === $old ) { return new WP_Error( 'not_found', 'The widget "' . $id . '" has no stored settings to change.' ); }
		if ( ! d5b_wc_secret_free( $old ) ) { return d5b_wc_secret_error( 'widget' ); }
		if ( ! array_key_exists( 'settings', $in ) ) { return new WP_Error( 'invalid_input', 'settings is required: the widget\'s new settings.' ); }
		$s = d5b_ww_settings_in( $in['settings'], d5b_ww_json( d5b_wc_unobject( $old ) ) ); if ( is_wp_error( $s ) ) { return $s; }
		$old_arr = is_array( $old ) ? d5b_wc_unobject( $old ) : array();
		$inst = d5b_ww_instance_in( $w, $s, $old_arr ); if ( is_wp_error( $inst ) ) { return $inst; }
		if ( d5b_ww_same( $old, $inst ) ) { return array( 'id' => $id, 'sidebar' => $sidebar, 'position' => $position, 'settings' => d5b_wc_unobject( $old ), 'changed' => false ); }
		$before = array( 'id' => $id, 'sidebar' => $sidebar, 'position' => $position, 'instance' => d5b_wc_meta_value( $old ) );
		$big = d5b_wc_check_content_size( d5b_ww_json( $before ) ); if ( true !== $big ) { return $big; }
		d5b_ww_save( $base, $number, $inst );
		$stored = d5b_ww_instance( $base, $number );
		if ( null === $stored || d5b_ww_same( $stored, $old ) ) { return new WP_Error( 'write_failed', 'WordPress did not store the widget\'s settings (another plugin may block it).' ); }
		$warn = array();
		if ( ! d5b_ww_same( $stored, $inst ) ) { $warn[] = 'WordPress stored the settings differently from what the widget made of them (another plugin may change them); the stored settings are in settings.'; }
		if ( d5b_ww_markup_removed( $s, d5b_wc_unobject( $stored ) ) ) { $warn[] = d5b_ww_markup_warning(); }
		$out = array( 'id' => $id, 'sidebar' => $sidebar, 'position' => $position, 'settings' => d5b_wc_unobject( $stored ), 'changed' => true );
		if ( $warn ) { $out['warnings'] = $warn; }
		$hid = d5b_wc_history_row( 'divi5-builder/widgets-update', 'widget', $number, 'update', $before, array( 'instance' => d5b_wc_meta_value( $stored ) ), 'Updated widget ' . $id );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	function d5b_ww_h_move( array $in ) {
		$e = d5b_rs_guard( 'widgets' ); if ( $e ) { return $e; }
		$e = d5b_ww_unknown( $in, array( 'id', 'sidebar', 'position' ) ); if ( $e ) { return $e; }
		$to  = d5b_ww_sidebar_in( $in ); if ( is_wp_error( $to ) ) { return $to; }
		$pos = d5b_ww_position( $in, true ); if ( is_wp_error( $pos ) ) { return $pos; }
		$x = d5b_ww_existing( $in ); if ( is_wp_error( $x ) ) { return $x; }
		list( $id, $base, $number, $w, $map, $from, $from_pos ) = $x;
		$new  = d5b_ww_put( d5b_ww_take( $map, $id, $from ), $id, $to, $pos );
		$want = d5b_ww_find( $id, $new );
		if ( d5b_ww_json( $new ) === d5b_ww_json( $map ) ) {
			return array( 'id' => $id, 'sidebar' => $from, 'position' => $from_pos, 'changed' => false );
		}
		$before = array( 'id' => $id, 'sidebar' => $from, 'position' => $from_pos, 'sidebars_widgets' => d5b_ww_pairs( $map ) );
		$big = d5b_wc_check_content_size( d5b_ww_json( $before ) ); if ( true !== $big ) { return $big; }
		wp_set_sidebars_widgets( $new );
		$now = d5b_ww_map();
		if ( d5b_ww_json( $now ) === d5b_ww_json( $map ) ) { return new WP_Error( 'write_failed', 'WordPress did not store the widget layout (another plugin may block it).' ); }
		$warn = array();
		$at   = d5b_ww_placement_warn( $id, $want[0], $want[1], $warn );
		$out  = array( 'id' => $id, 'sidebar' => null === $at ? null : $at[0], 'position' => null === $at ? null : $at[1], 'changed' => true );
		if ( $warn ) { $out['warnings'] = $warn; }
		$hid = d5b_wc_history_row( 'divi5-builder/widgets-move', 'widget', $number, 'move', $before, array( 'sidebar' => $out['sidebar'], 'position' => $out['position'] ), 'Moved widget ' . $id . ' to ' . $to );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	function d5b_ww_h_remove( array $in ) {
		$e = d5b_rs_guard( 'widgets' ); if ( $e ) { return $e; }
		$e = d5b_ww_unknown( $in, array( 'id' ) ); if ( $e ) { return $e; }
		$x = d5b_ww_existing( $in ); if ( is_wp_error( $x ) ) { return $x; }
		list( $id, $base, $number, $w, $map, $sidebar, $position ) = $x;
		$old = d5b_ww_instance( $base, $number );
		if ( null !== $old && ! d5b_wc_secret_free( $old ) ) { return d5b_wc_secret_error( 'widget' ); }
		// Every placement of the id goes (a broken layout can hold it in two sidebars): the settings are deleted, so a
		// placement left behind would point at nothing. All of them are recorded.
		$places = array_map( function ( $p ) { return array( 'sidebar' => $p[0], 'position' => $p[1] ); }, d5b_ww_find_all( $id, $map ) );
		$before = array( 'id' => $id, 'sidebar' => $sidebar, 'position' => $position, 'placements' => $places, 'instance' => null === $old ? array( 'absent' => true ) : d5b_wc_meta_value( $old ), 'sidebars_widgets' => d5b_ww_pairs( $map ) );
		$big = d5b_wc_check_content_size( d5b_ww_json( $before ) ); if ( true !== $big ) { return $big; }
		$new = $map;
		foreach ( $places as $p ) { $new = d5b_ww_take( $new, $id, $p['sidebar'] ); }
		wp_set_sidebars_widgets( $new );
		if ( null !== d5b_ww_find( $id, d5b_ww_map() ) && d5b_ww_json( d5b_ww_map() ) === d5b_ww_json( $map ) ) {
			return new WP_Error( 'write_failed', 'WordPress did not store the widget layout (another plugin may block it).' );
		}
		$warn = array();
		d5b_ww_placement_warn( $id, null, null, $warn );
		if ( null !== $old ) {
			d5b_ww_unsave( $base, $number );
			if ( null !== d5b_ww_instance( $base, $number ) ) { $warn[] = 'WordPress kept the widget\'s settings (another plugin may block the change); the widget is no longer placed.'; }
		}
		$out = array( 'id' => $id, 'sidebar' => $sidebar, 'position' => $position, 'placements' => $places, 'removed' => true );
		if ( $warn ) { $out['warnings'] = $warn; }
		$hid = d5b_wc_history_row( 'divi5-builder/widgets-remove', 'widget', $number, 'delete', $before, array( 'absent' => true ), 'Removed widget ' . $id . ' from ' . $sidebar );
		if ( $hid < 1 ) { $out['_history_failed'] = true; }
		return $out;
	}

	// ---- the definitions

	$d5b_ww_id       = array( 'type' => 'string', 'minLength' => 3, 'maxLength' => 255, 'description' => 'The widget id as widgets-list returns it: type-number, such as text-3 or block-5.' );
	$d5b_ww_sidebar  = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'A sidebar id as widgets-list returns it, or wp_inactive_widgets (the inactive widgets).' );
	$d5b_ww_settings = array( 'type' => 'object', 'description' => 'The widget\'s settings (its instance). For a block widget {"content": "<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->"}; for a text widget {"title": "...", "text": "..."}.' );
	$d5b_ww_position = array( 'type' => 'integer', 'minimum' => 0, 'description' => 'The place in the sidebar, 0 for the top; a number past the end puts it last.' );
	$d5b_ww_rules    = 'Settings go through the widget\'s own sanitiser and core\'s widget_update_callback filter, as wp-admin saves them (a plugin that refuses them makes the answer invalid_input); core\'s REST-only widget hooks (rest_after_save_widget, rest_delete_widget) do not run. Without unfiltered_html, a block widget holding Divi blocks or HTML WordPress would remove is refused (needs_unfiltered_html), and markup a text widget removed is named in warnings. Refuses settings over 256 KB, a secret-named key and text holding [redacted] (masked_read). Only multi-instance widgets (type-number); legacy single-instance widgets are refused.';

	d5b_wc_def( 'widgets-add', 'widgets', 'Add a widget',
		'Adds a widget of type (a registered widget type such as block or text) to sidebar (a sidebar of the theme, or wp_inactive_widgets) at position (0 is the top; left out, the end). ' . $d5b_ww_rules . ' Returns the new widget id (type-number), where it was placed and the stored settings. One change-history row keeps the whole widget layout before the change.',
		array( 'sidebar' => $d5b_ww_sidebar, 'type' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'The widget type id, such as block, text or custom_html.' ), 'settings' => $d5b_ww_settings, 'position' => $d5b_ww_position ),
		array( 'sidebar', 'type', 'settings' ), 'd5b_ww_h_add', d5b_rs_perm( 'widgets' ),
		array() );

	d5b_wc_def( 'widgets-update', 'widgets', 'Change a widget',
		'Changes the settings of one widget (settings is the new instance; the widget\'s own sanitiser merges it as wp-admin does). ' . $d5b_ww_rules . ' Refuses a widget in no sidebar (not_found) and a widget whose stored settings hold a secret-named key. Returns the stored settings and whether they changed. One change-history row keeps the whole old settings.',
		array( 'id' => $d5b_ww_id, 'settings' => $d5b_ww_settings ),
		array( 'id', 'settings' ), 'd5b_ww_h_update', d5b_rs_perm( 'widgets' ),
		array( 'idempotent' => true ) );

	d5b_wc_def( 'widgets-move', 'widgets', 'Move a widget',
		'Moves one widget to sidebar at position (0 is the top; past the end, last), within the same sidebar to reorder it, or to wp_inactive_widgets to take it off the site and keep its settings. Refuses a widget in no sidebar (not_found) and an unknown sidebar. Returns where it is now and whether it moved. One change-history row keeps the whole widget layout before the move. Core\'s REST-only widget hooks do not run.',
		array( 'id' => $d5b_ww_id, 'sidebar' => $d5b_ww_sidebar, 'position' => $d5b_ww_position ),
		array( 'id', 'sidebar', 'position' ), 'd5b_ww_h_move', d5b_rs_perm( 'widgets' ),
		array( 'idempotent' => true ) );

	d5b_wc_def( 'widgets-remove', 'widgets', 'Remove a widget',
		'Removes one widget from its sidebar (from every sidebar, when a broken layout lists it more than once) and deletes its settings. To take a widget off the site but keep it, move it to wp_inactive_widgets instead. The change-history row keeps every place it was in, the whole settings and the whole widget layout, so it can be put back. Refuses a widget in no sidebar (not_found) and a widget whose settings hold a secret-named key. Returns the id, where it was (sidebar and position: the first place; placements: all of them) and removed true. Core\'s REST-only widget hooks (rest_delete_widget) do not run.',
		array( 'id' => $d5b_ww_id ),
		array( 'id' ), 'd5b_ww_h_remove', d5b_rs_perm( 'widgets' ),
		array() );

	// Widget settings are keyed and labelled by other plugins and themes: raw third-party data, as in widgets-list.
	d5b_ability_set_raw_paths( array( 'widgets-add' => array( 'settings' ), 'widgets-update' => array( 'settings' ) ) );
	unset( $d5b_ww_id, $d5b_ww_sidebar, $d5b_ww_settings, $d5b_ww_position, $d5b_ww_rules );
}
