<?php
/**
 * The ability categories: data only. This file only defines functions; it registers no hooks.
 *
 * Each short slug (posts, pages, ...) is a switch in the settings page and a category in the Abilities API.
 * The slug WordPress sees is 'divi5-builder-' . the short slug.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'd5b_ability_categories' ) ) {
	/** @return array<string, array{label:string, description:string, wp_slug:string}> keyed by the short slug */
	function d5b_ability_categories(): array {
		$rows = array(
			'posts'     => array( 'Posts', 'Read posts and their revisions.' ),
			'pages'     => array( 'Pages', 'Read pages and their page tree.' ),
			'media'     => array( 'Media', 'Read the media library.' ),
			'taxonomy'  => array( 'Taxonomies', 'Read categories, tags and other taxonomies.' ),
			'comments'  => array( 'Comments', 'Read comments.' ),
			'meta'      => array( 'Custom fields', 'Read custom fields on posts.' ),
			'cpt'       => array( 'Custom post types', 'Read the custom post types and their content.' ),
			'blocks'    => array( 'Blocks', 'Read block types and patterns.' ),
			'menus'     => array( 'Menus', 'Read navigation menus.' ),
			'revisions' => array( 'Revisions', 'Read the revision history of content.' ),
			'search'    => array( 'Search', 'Search site content.' ),
			'settings'  => array( 'Settings', 'Read the safe site settings. Includes the admin email.' ),
			'templates' => array( 'Templates', 'Read templates and template parts.' ),
			'appearance' => array( 'Appearance', 'Read theme appearance details.' ),
			'widgets'   => array( 'Widgets', 'Read widgets and sidebars.' ),
			'cache'     => array( 'Cache', 'Read the cache status.' ),
			'health'    => array( 'Site health', 'Read the site health summary. Includes the debug log and scheduled tasks.' ),
			'users'     => array( 'Users', 'Read users, without any secrets. Includes users\' names, roles and email addresses.' ),
			'plugins'   => array( 'Plugins', 'Read the installed plugins.' ),
			'themes'    => array( 'Themes', 'Read the installed themes.' ),
			'core'      => array( 'WordPress core', 'Read the WordPress version and environment.' ),
			'history'   => array( 'Activity history', 'Read this plugin\'s own activity log.' ),
			'divi'      => array( 'Divi', 'Read Divi layouts and settings.' ),
		);
		$out = array();
		foreach ( $rows as $slug => $row ) {
			$out[ $slug ] = array( 'label' => $row[0], 'description' => $row[1], 'wp_slug' => 'divi5-builder-' . $slug );
		}
		return $out;
	}
}
