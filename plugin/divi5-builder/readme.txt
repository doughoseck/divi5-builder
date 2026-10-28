=== Divi 5 Builder ===
Contributors: doughoseck
Tags: divi, rest api
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 2.1.0
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

= 2.1.0 =
* New: abilities for AI tools, through the WordPress Abilities API (WordPress 6.9 or newer; on older versions nothing happens). All of them are off until an administrator turns on the master switch in Settings > Divi 5 Builder. Each category (posts, pages, media, users, settings, Divi and so on) has its own switch as well.
* This release has read-only abilities only: they read content, the site settings that are safe to show, Divi data and the plugin's own activity log. Nothing can be changed through them.
* Every call is written to an audit log before it runs and kept for 30 days. Deleting the plugin removes the log.
* A secret filter hides passwords, keys and tokens from results and from the log. It works on a best-effort basis and cannot catch everything, so leave the master switch off on a site where you do not trust the account that calls the abilities.

= 2.0.1 =
* Design variables can hold other variables (Divi 5.18). A variable write is refused (400) when a reference is missing, archived, of another type, the variable itself, or makes a cycle; only number and string variables may hold references, and a value over 1 MB is refused. A variable that another active variable uses cannot be archived (409), by the archive route or by a restore. Nothing is written on a refusal.
* Very convoluted variable values are refused (400 value_too_complex) rather than half-checked.

= 2.0.0 =
* The four plugins in one, with a switch for each optional part, off by default.
