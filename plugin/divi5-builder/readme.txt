=== Divi 5 Builder ===
Contributors: doughoseck
Tags: divi, rest api
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 2.5.0
License: MIT

Build and edit Divi 5 pages over the REST API, plus optional media audit, media clean-up and updates, each behind a switch that is off by default.

== Description ==

One plugin for the divi5-builder skill.

Use at your own risk. With the AI abilities switched on, this plugin lets connected AI clients create, change, trash and (after a preview and a confirmation code) permanently delete content on your site, and (with the risky switches) install, update, roll back and delete plugins and themes, update WordPress and manage users. Its change history is a 30-day safety net for mistakes, not a backup. Keep your normal backups, test on a staging site first, and switch on only the categories you need. The software is provided as is, without warranty of any kind (MIT licence).

* Page building is always on: REST routes to build and edit Divi 5 pages, popups, Theme Builder, Custom CSS and the design system. Only signed-in editors and administrators can use them.
* Media audit, media clean-up and updates are off until an administrator turns them on in Settings > Divi 5 Builder (on a multisite network: Network Admin > Settings). While a part is off its routes do not exist.
* On a multisite network, network-activate the plugin (or activate it on the main site): Network Admin only loads those, so a plugin activated on one subsite alone cannot show its switches.
* If the old stand-alone plugins are installed, activating this plugin deactivates them. Your files are not deleted.
* Deleting the plugin (Plugins > Delete) removes the options it stored, so a reinstall starts with every switch off.

== Installation ==

1. Upload divi5-builder.zip in Plugins > Add New and activate it, or
2. as a must-use plugin: copy the folder divi5-builder/ into wp-content/mu-plugins/ and the one file divi5-builder-loader.php next to it (it is published next to divi5-builder.zip in each release).

From then on, a plugin installed the first way gets its updates in Dashboard > Updates, from GitHub Releases. A must-use copy is updated by replacing the folder.

== Changelog ==

= 2.5.0 =
* Updates now come from GitHub Releases. A new release shows in Dashboard > Updates like any other plugin update, and the plugins-status ability lists it (plugins-update still never replaces this plugin itself; use Update now). Settings > Divi 5 Builder says where updates come from, the newest version and when it was checked, with a Check now link.
* The downloaded zip is checked against the checksum published with the release before WordPress unpacks it; a zip that does not match is deleted and not installed. A must-use copy is not updated by WordPress (it never updates must-use plugins), and the Settings page says so: replace the folder as before.
* The first install is still an upload of divi5-builder.zip (or a MainWP push). Updating from 2.4.3 asks nothing again: no switch changes.

= 2.4.3 =
* A refresh of the update list from outside wp-admin no longer hides a pending Divi update. Divi only connects its own updater inside wp-admin, so a refresh from the Updates tool or the updates-check ability used to rebuild WordPress's list without Divi, and a Divi update that wp-admin showed was gone until the next wp-admin visit. The plugin now connects the Elegant Themes updater itself before every refresh (for a user who may manage options), so the list matches what wp-admin shows.
* Updating from 2.4.2 asks nothing again: no switch changes.

= 2.4.2 =
* Popups made over REST now appear. Divi keeps a per-post note of which canvases a page, post or layout has, and only its own builder save refreshed it, so a popup canvas linked or edited over REST stayed out of the page. The plugin now drops that note on every save of Divi content and on every link-canvas call (for the new parent and the previous one).
* link-canvas can make a canvas global (page id 0, `link-canvas <canvas> global` in the script): no parent at all, so one popup serves the homepage and the single-post template alike, wherever the trigger lives. Making a canvas global needs the Theme Builder level of trust (edit_theme_options).
* Updating from 2.4.1 asks nothing again: no switch changes.

= 2.4.1 =
* Secrets: more key shapes are hidden wherever they appear, in plugin headers, user names and mailer errors too: GitHub and GitLab tokens, AWS access key ids and Slack tokens. A payment, GitHub, AWS, Slack, GitLab or Google key is hidden even when it runs straight on from other text.
* Two requests at the same moment: the limits (test emails, reset links, update checks), the one-at-a-time locks of updates, rollbacks and administrator changes, and the one-time "the update asks again" step now use the database's own guarantee that a name exists only once, so two requests in the same instant can never both get through. The limits count in fixed windows (10:00, 10:10 ...).
* The kept copies are listed with one short lookup, however long the change history is.
* Sharper edges: the page check never runs past its 60 seconds, a failed activation still reports the pages it checked before, a plugin that writes its own files while it is being copied is copied again (or refused with a clear message), a rollback never moves anything over a folder that is already there and never ends another update's maintenance mode, a broken reset-link guard on a user blocks the reset instead of allowing it, a user delete records the names of the fields it could not keep, and a mailer error never shows the recipient's address, in its given form or its punycode form.
* Updating from 2.4.0 asks nothing again: no switch changes.

= 2.4.0 =
* New: AI abilities for site maintenance, behind the same master switch and the category switches. AI tools can now see what is out of date, check for updates now, install plugins and themes from wordpress.org, activate and deactivate plugins, switch the theme, update plugins, themes, WordPress and translations, roll a plugin or theme back to its previous version, and delete plugins and themes. Around that, the small user and email tasks: create a user, change a user's details or role, send a password reset link, delete a user, and send a test email.
* The old version is kept. Before a plugin or theme is updated, rolled back or deleted, a copy of its folder is kept for 30 days in wp-content/uploads/d5b-history, so the previous version can be put back with one call, premium plugins included (the ones you cannot download again). If the copy cannot be made the change does not happen. One copy can be at most 512 MB and all kept copies together 2 GB; at the cap the change is refused, and old copies are never deleted early to make room. The settings page shows how much the store holds.
* The human check. Every update, rollback, activation, deactivation and theme switch checks the home page and the recently changed pages before and after (status, error text, size), and a plugin activation or theme switch that makes them worse is switched back at once. That check cannot see layout breaks, missing images, errors in the browser console, broken forms or raw shortcodes, so every result also asks you to look at the site yourself, in a browser and on a phone. There is no substitute for the human check and it only takes a few seconds.
* Risky switches: Users, Plugins, Themes and WordPress core each have "Also allow risky actions", off by default, with a plain note about what it allows. Of the changes, only sending a password reset link, sending a test email, updating translations and checking for updates work without it.
* New category Email, off by default: a test email goes only to you, to the site's admin address or to an existing user, never to an address an AI tool makes up.
* Updating from 2.3.x: if the AI abilities master switch was on, the update turns it off and the settings page says so, because 2.4.0 abilities manage users, plugins, themes, WordPress updates and email. Review the switches and turn it on again when you are ready.
* Change history: every one of these changes keeps what is needed to put it back for 30 days: the previous version and its files for an update, rollback or delete, the previous active plugins or theme for an activation or switch, and for a user delete the user's record (without secrets) and the items given to another author. Two exceptions: WordPress itself is never copied (there is no rollback for a core update; keep a backup) and translations are not copied. A plugin delete runs the plugin's own uninstall routine, as on the Plugins screen, so its data goes for good; the preview says so, and keep_data deletes only the files.
* Safe by construction: this plugin never deactivates, updates, rolls back or deletes itself. No password is ever set or shown: a new user gets WordPress's own email to set one, and a reset is WordPress's own link. You cannot change your own role, email or password through the abilities, delete yourself, or leave the site without an administrator. Another administrator's email is never changed, and no reset link goes out for 24 hours after a user's email or role was changed through the abilities. Plugins and themes come only from downloads.wordpress.org, and a plugin or theme folder that is a link is never changed. Deletes take a preview and a confirmation code.

= 2.3.0 =
* New: AI abilities for site management, behind the same master switch and the category switches. AI tools can now change site settings (title, tagline, date and time formats, posts per page, comment default), set the homepage, set theme settings and the Custom CSS, add, change, move and remove widgets, flush the caches, run one scheduled task now, and on block themes change templates and global styles.
* Risky switches: the riskiest of these (the homepage, permalinks, time zone and search engine visibility; Custom CSS and theme settings; running a scheduled task; templates and global styles) have a second switch under their category, "Also allow risky actions", off by default, each with a plain note about what it allows. A risky action needs the master, the category and the risky switch.
* Updating from 2.2.x: if the AI abilities master switch was on, the update turns it off and the settings page says so, because 2.3.0 abilities can change settings, widgets and caches. Review the switches and turn it on again when you are ready.
* Change history: every one of these changes keeps the full previous value for 30 days (the old option values, the whole previous stylesheet, the widget layout and the widget's settings, the previous template). Flushing the caches and running a task leave a row saying what ran; there is nothing to put back.
* Safe by construction: the site address (siteurl, home) and the admin email are never changed, only the listed settings can be, a task runs only if it is scheduled (never this plugin's own, never core's lifecycle hooks), and a value that was hidden in a read can never be written back. Theme settings, templates and global styles need the capability WordPress itself asks for (edit_theme_options), and the Custom CSS needs edit_theme_options and edit_css, as in the Customizer.
* Global styles: changed as WordPress changes them. For a user without unfiltered_html they are sanitised as WordPress does, and a change that would lose something that way is refused with what it would lose, never dropped silently; an administrator's styles are stored as given.
* Settings page: a page loaded before the switches changed (another admin, or this update) cannot be saved over them; it asks you to reload.

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
