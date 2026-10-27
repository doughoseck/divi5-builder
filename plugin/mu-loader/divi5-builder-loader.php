<?php
/**
 * Plugin Name: Divi 5 Builder (must-use loader)
 * Description: Loads Divi 5 Builder from the divi5-builder/ folder next to this file.
 *
 * To install Divi 5 Builder as a must-use plugin (no activation step): copy the folder
 * divi5-builder/ into wp-content/mu-plugins/, and this one file next to it, so that you have
 *   wp-content/mu-plugins/divi5-builder-loader.php
 *   wp-content/mu-plugins/divi5-builder/divi5-builder.php
 * WordPress only loads PHP files that sit directly in mu-plugins, which is why this file is needed.
 * Each release publishes this file next to divi5-builder.zip; in the repository it is plugin/mu-loader/.
 * If the folder has another name (a renamed download, for example), this file does nothing rather than
 * stop the site: rename the folder to divi5-builder.
 */
$d5b_loader_file = __DIR__ . '/divi5-builder/divi5-builder.php';
if ( is_file( $d5b_loader_file ) ) { require $d5b_loader_file; }
unset( $d5b_loader_file );
