<?php
/**
 * The four parts of the plugin and what the gate needs to know about each.
 * This file only defines functions; it registers no hooks.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_parts' ) ) {
	/**
	 * @return array<string, array{label:string,has_switch:bool,routes:string,legacy_const:string,legacy_slug:string,probe:string,risk:string}>
	 */
	function d5b_parts(): array {
		return array(
			'page_building'  => array(
				'label'        => 'Page building',
				'has_switch'   => false,
				'routes'       => 'd5b_routes_page_building',
				'legacy_const' => 'D5B_REST_VERSION',
				'legacy_slug'  => 'divi5-builder-rest',
				'probe'        => '/divi5-builder/v1/version',
				'risk'         => '',
			),
			'media_audit'    => array(
				'label'        => 'Media audit',
				'has_switch'   => true,
				'routes'       => 'd5b_routes_media_audit',
				'legacy_const' => 'WPMA_VERSION',
				'legacy_slug'  => 'wp-media-audit',
				'probe'        => '/wp-media-audit/v1/info',
				'risk'         => 'Lists every uploaded file, its size, and where the site uses it. It changes nothing, but it shows a lot about your site to anyone with an administrator login. Switch it off when the audit is done.',
			),
			'media_clean_up' => array(
				'label'        => 'Media clean-up',
				'has_switch'   => true,
				'routes'       => 'd5b_routes_media_clean_up',
				'legacy_const' => 'WPMQ_VERSION',
				'legacy_slug'  => 'wp-media-quarantine',
				'probe'        => '/wp-media-quarantine/v1/batches',
				'risk'         => 'Moves uploaded files out of your uploads folder into a quarantine folder. Anything moved disappears from the pages that use it until it is moved back. Take a backup first, and check the site after every move.',
			),
			'updates'        => array(
				'label'        => 'Updates',
				'has_switch'   => true,
				'routes'       => 'd5b_routes_updates',
				'legacy_const' => 'WPSU_VERSION',
				'legacy_slug'  => 'wp-site-updates',
				'probe'        => '/wp-site-updates/v1/info',
				'risk'         => 'Updates plugins, themes and WordPress itself. An update can break a site. Take a backup first, and check your key pages after every update.',
			),
		);
	}
}

if ( ! function_exists( 'd5b_risk_note' ) ) {
	/** The plain-words risk note for a part, with the network sentence added on multisite. */
	function d5b_risk_note( string $part ): string {
		$parts = d5b_parts();
		if ( ! isset( $parts[ $part ] ) || '' === $parts[ $part ]['risk'] ) { return ''; }
		$note = $parts[ $part ]['risk'];
		if ( is_multisite() ) {
			if ( 'media_clean_up' === $part ) { $note .= ' On a network, check the sites that share these files.'; }
			if ( 'updates' === $part ) { $note .= ' On a multisite network an update applies to every site in it at once.'; }
		}
		return $note;
	}
}
