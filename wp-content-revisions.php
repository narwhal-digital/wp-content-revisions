<?php
/**
 * Plugin Name: WP Content Revisions
 * Plugin URI: https://github.com/NarwhalDigital/wp-content-revisions
 * Description: Allows creation of content revisions which allow you to save and preview updates to content without
 * publishing.
 * Author: Narwhal.Digital
 * Author URI: https://narwhal.digital/
 * Version: 1.0.0
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wp-content-revisions
 */

// Check plugin requirements.
global $pagenow;
if ( 'plugins.php' === $pagenow ) {
	require __DIR__ . '/includes/plugin-check.php';
	$pluginCheck = new WP_ContentRevisions_PluginCheck( __FILE__ );
	$pluginCheck->min_php_version = '5.6';
	$pluginCheck->min_wp_version = '4.8.0';
	$pluginCheck->check_plugin_requirements();
}

require __DIR__ . '/ContentRevisions.php';