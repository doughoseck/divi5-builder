=== Divi 5 Builder ===
Contributors: doughoseck
Tags: divi, rest api
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 2.2.0
License: MIT

Build and edit Divi 5 pages over the REST API, plus optional media audit, media clean-up and updates, each behind a switch that is off by default.

== Description ==

One plugin for the divi5-builder skill.

* Page building is always on: REST routes to build and edit Divi 5 pages, popups, Theme Builder, Custom CSS and the design system. Only signed-in editors and administrators can use them.
* Media audit, media clean-up and updates are off until an administrator turns them on in Settings > Divi 5 Builder (on a multisite network: Network Admin > Settings). While a part is off its routes do not exist.
* On a multisite network, network-activate the plugin (or activate it on the main site): Network Admin only loads those, so a plugin activated on one subsite alone cannot show its switches.
* If the old stand-alone plugins are installed, activating this plugin deactivates them. Your files are not deleted.
* Deleting the plugin (Plugins > Delete) removes the options it stored, so a reinstall starts with every switch off.

== Installation ==

1. Upload divi5-builder.zip in Plugins > Add New and activate it, or
2. as a must-use plugin: copy the folder divi5-builder/ into wp-content/mu-plugins/ and the one file divi5-builder-loader.php next to it (it is published next to divi5-builder.zip in each release).

== Changelog ==

= 2.2.0 =
* New: abilities that change things, behind the same master switch and the category switches. AI tools can now create, update, trash and restore posts, pages and custom post types, work with media, categories and tags, comments, custom fields and menus, restore revisions, and edit Divi content. Nothing is changed unless the master switch and the category are both on.
* Nothing is lost for good in one step. Content is trashed, never deleted. Permanent deletes (a term, a menu, a custom field, a media file) take two steps: a preview first, then a confirmation code that works once, for the same user, for 10 minutes. A permanently deleted media file is kept for 30 days in wp-content/uploads/d5b-history and can be put back by hand.
* Updating from 2.1.x: if the AI abilities master switch was on, the update turns it off and the settings page says so, because 2.2.0 abilities can change content. Review the category switches and turn it on again when you are ready.
* Change history: every change is kept for 30 days with a full copy of what it replaced, so it can be put back. Settings > Divi 5 Builder shows the list, with a link to the WordPress revision compare screen. A change that worked but could not be recorded is flagged in the audit log and on the settings page.
* Divi: edit one section, or one piece of text, of a page without touching the rest of it.
* Every content update must carry the hash from the read, so it cannot overwrite a change made in between.
* A media upload is never an SVG, only comes from http or https addresses that are not on a private network, and is capped by size and type.
* A result that had secrets hidden can never be written back: the update is refused rather than overwriting the real value with the placeholder.
* The categories now say what they can read and change.

= 2.1.0 =
* New: abilities for AI tools, through the WordPress Abilities API (WordPress 6.9 or newer). On older versions no ability is registered, but the (empty) audit log table and its daily clean-up are still created. Every ability is off until an administrator turns on the master switch in Settings > Divi 5 Builder. Each category (posts, pages, media, users, settings, Divi and so on) has its own switch as well, and can be unticked in the same save that turns the master on.
* This release has read-only abilities only: they read content, the site settings that are safe to show, Divi data and the plugin's own activity log. Nothing can be changed through them. Callers who are not logged in are refused, and on a multisite network the debug log and scheduled tasks need a network administrator.
* Every call is written to an audit log before it runs and kept for 30 days. Deleting the plugin removes the log. If the log table cannot be created, every call is refused and the settings page says so.
* A secret filter hides passwords, keys and tokens from results and from the log. It works on a best-effort basis and cannot catch everything, so leave the master switch off on a site where you do not trust the account that calls the abilities.

= 2.0.1 =
* Design variables can hold other variables (Divi 5.18). A variable write is refused (400) when a reference is missing, archived, of another type, the variable itself, or makes a cycle; only number and string variables may hold references, and a value over 1 MB is refused. A variable that another active variable uses cannot be archived (409), by the archive route or by a restore. Nothing is written on a refusal.
* Very convoluted variable values are refused (400 value_too_complex) rather than half-checked.

= 2.0.0 =
* The four plugins in one, with a switch for each optional part, off by default.
