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
			'posts'     => array( 'Posts', 'Read, create, update and trash posts; restore from the trash.' ),
			'pages'     => array( 'Pages', 'Read, create, update and trash pages and see the page tree; restore from the trash.' ),
			'media'     => array( 'Media', 'Read the media library, upload files, change their details and trash them; restore from the trash.' ),
			'taxonomy'  => array( 'Taxonomies', 'Read, create, change and assign categories, tags and other taxonomies; delete a term after a confirmation step.' ),
			'comments'  => array( 'Comments', 'Read comments, moderate them and reply to them.' ),
			'meta'      => array( 'Custom fields', 'Read, set and delete custom fields on posts; a delete needs a confirmation step.' ),
			'cpt'       => array( 'Custom post types', 'Read, create, update and trash custom post type content; restore from the trash.' ),
			'blocks'    => array( 'Blocks', 'Read block types and patterns.' ),
			'menus'     => array( 'Menus', 'Read navigation menus, change them and their items, and delete a menu after a confirmation step.' ),
			'revisions' => array( 'Revisions', 'Read the revision history of content and restore an earlier revision.' ),
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
			'history'   => array( 'Activity history', 'Read this plugin\'s activity log and the change history of its writes.' ),
			'divi'      => array( 'Divi', 'Read Divi layouts and settings, edit a section or a piece of text, write Theme Builder items and restore backups.' ),
		);
		$out = array();
		foreach ( $rows as $slug => $row ) {
			$out[ $slug ] = array( 'label' => $row[0], 'description' => $row[1], 'wp_slug' => 'divi5-builder-' . $slug );
		}
		return $out;
	}
}
