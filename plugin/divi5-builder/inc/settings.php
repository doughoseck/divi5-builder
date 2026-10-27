<?php
/**
 * The settings page (the only place a switch can be flipped) and the admin notice for leftovers.
 * This file only defines functions; the loader adds the hooks:
 *   admin_menu, network_admin_menu                          -> d5b_settings_menu()
 *   admin_post_d5b_save_switches,
 *   network_admin_edit_d5b_save_switches                    -> d5b_settings_save()
 *   admin_notices, network_admin_notices                    -> d5b_admin_notice()
 *
 * Who may flip a switch: a person in wp-admin, through the form below, with a valid nonce (action
 * d5b_switches) and manage_options (manage_network_options on multisite, where every switch is network-wide
 * and the page lives in Network Admin > Settings). Nothing else calls the switch writer.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_settings_cap' ) ) {
	/** The capability needed to see and save the page. */
	function d5b_settings_cap(): string { return is_multisite() ? 'manage_network_options' : 'manage_options'; }
}

if ( ! function_exists( 'd5b_settings_menu' ) ) {
	/** Registers the page for the current context only: Settings on a single site, Network Admin > Settings on multisite. */
	function d5b_settings_menu(): void {
		if ( is_multisite() ) {
			if ( is_network_admin() ) {
				add_submenu_page( 'settings.php', 'Divi 5 Builder', 'Divi 5 Builder', 'manage_network_options', 'divi5-builder', 'd5b_settings_render' );
			}
			return;
		}
		add_options_page( 'Divi 5 Builder', 'Divi 5 Builder', 'manage_options', 'divi5-builder', 'd5b_settings_render' );
	}
}

if ( ! function_exists( 'd5b_settings_exit' ) ) {
	/** Stops the request after the redirect. A function so the tests can stand in for it. */
	function d5b_settings_exit(): void { exit; }
}

if ( ! function_exists( 'd5b_settings_render' ) ) {
	function d5b_settings_render(): void {
		if ( ! current_user_can( d5b_settings_cap() ) ) { return; }
		$parts    = d5b_parts();
		$switches = d5b_switches();
		$action   = is_multisite() ? network_admin_url( 'edit.php?action=d5b_save_switches' ) : admin_url( 'admin-post.php' );
		echo '<div class="wrap"><h1>Divi 5 Builder</h1>';
		if ( isset( $_GET['updated'] ) ) { echo '<div class="notice notice-success"><p>Settings saved.</p></div>'; }
		echo '<p>Version ' . esc_html( D5B_VERSION ) . '. ';
		echo defined( 'ET_BUILDER_5_DIR' ) ? 'Divi 5 detected.' : 'Divi 5 not detected: page building is idle.';
		echo '</p>';
		echo '<p>Page building is always on. The parts below are off until you switch them on, and each one can do real harm to a site if used carelessly.</p>';
		echo '<form method="post" action="' . esc_url( $action ) . '">';
		wp_nonce_field( 'd5b_switches' );
		echo '<input type="hidden" name="action" value="d5b_save_switches" />';
		echo '<table class="form-table" role="presentation">';
		foreach ( $switches as $key => $row ) {
			$id = 'd5b-on-' . $key;
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $parts[ $key ]['label'] ) . '</label></th><td>';
			echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="d5b_on[' . esc_attr( $key ) . ']" value="1"' . ( $row['on'] ? ' checked="checked"' : '' ) . ' /> Switched on</label>';
			echo '<p class="description">' . esc_html( d5b_risk_note( $key ) ) . '</p>';
			if ( '' !== $row['changed_at'] || '' !== $row['changed_by'] ) {
				echo '<p class="description">Last switched ' . ( $row['on'] ? 'on' : 'off' ) . ' ' . esc_html( $row['changed_at'] ) . ' by ' . esc_html( $row['changed_by'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		echo '<p class="submit"><input type="submit" class="button button-primary" value="Save changes" /></p>';
		echo '</form>';
		d5b_legacy_refresh();
		foreach ( d5b_takeover_lines() as $line ) {
			echo '<p>' . esc_html( $line['text'] ) . '</p>';
		}
		echo '</div>';
	}
}

if ( ! function_exists( 'd5b_settings_save' ) ) {
	/** The form handler. Nonce and capability first; then only the switchable parts whose box changed. */
	function d5b_settings_save(): void {
		if ( ! check_admin_referer( 'd5b_switches' ) ) { return; }
		if ( ! current_user_can( d5b_settings_cap() ) ) {
			wp_die( 'You are not allowed to change these settings.', '', array( 'response' => 403 ) );
			return;
		}
		$posted = isset( $_POST['d5b_on'] ) && is_array( $_POST['d5b_on'] ) ? wp_unslash( $_POST['d5b_on'] ) : array();
		$login  = (string) wp_get_current_user()->user_login;
		// Walk the switchable parts, not the posted keys: page_building and unknown keys can never get through.
		foreach ( d5b_switches() as $key => $row ) {
			$want = isset( $posted[ $key ] );
			if ( $want !== $row['on'] ) { d5b_switch_set( $key, $want, $login ); }
		}
		$base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
		wp_safe_redirect( $base . '?page=divi5-builder&updated=1' );
		d5b_settings_exit();
	}
}

if ( ! function_exists( 'd5b_admin_notice' ) ) {
	/** Shows the takeover lines to people who can manage plugins: red ones as errors, the rest as warnings. */
	function d5b_admin_notice(): void {
		if ( ! current_user_can( is_multisite() ? 'manage_network_plugins' : 'activate_plugins' ) ) { return; }
		d5b_legacy_refresh();
		foreach ( d5b_takeover_lines() as $line ) {
			echo '<div class="notice ' . ( $line['red'] ? 'notice-error' : 'notice-warning' ) . '"><p>' . esc_html( $line['text'] ) . '</p></div>';
		}
	}
}
