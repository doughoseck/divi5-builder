<?php
/**
 * Must-use loader for Divi 5 Builder.
 *
 * To install Divi 5 Builder as a must-use plugin (no activation step): copy the folder
 * divi5-builder/ into wp-content/mu-plugins/, and this one file next to it, so that you have
 *   wp-content/mu-plugins/divi5-builder-loader.php
 *   wp-content/mu-plugins/divi5-builder/divi5-builder.php
 * WordPress only loads PHP files that sit directly in mu-plugins, which is why this file is needed.
 */
require __DIR__ . '/divi5-builder/divi5-builder.php';
