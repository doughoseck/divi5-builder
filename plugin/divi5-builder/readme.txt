=== Divi 5 Builder ===
Contributors: doughoseck
Tags: divi, rest api
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 2.0.0
License: MIT

Build and edit Divi 5 pages over the REST API, plus optional media audit, media clean-up and updates, each behind a switch that is off by default.

== Description ==

One plugin for the divi5-builder skill.

* Page building is always on: REST routes to build and edit Divi 5 pages, popups, Theme Builder, Custom CSS and the design system. Only signed-in editors and administrators can use them.
* Media audit, media clean-up and updates are off until an administrator turns them on in Settings > Divi 5 Builder (on a multisite network: Network Admin > Settings). While a part is off its routes do not exist.
* If the old stand-alone plugins are installed, activating this plugin deactivates them. Your files are not deleted.

== Installation ==

1. Upload divi5-builder.zip in Plugins > Add New and activate it, or
2. as a must-use plugin: copy the folder divi5-builder/ into wp-content/mu-plugins/ and the one file divi5-builder-loader.php next to it.

== Changelog ==

= 2.0.0 =
* The four plugins in one, with a switch for each optional part, off by default.
