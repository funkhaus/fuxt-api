<?php
/**
 * Plugin Name: fuxt-api
 * Plugin URI: https://github.com/funkhaus/fuxt-api
 * Description: An opinionated extension of the WP-JSON API geared towards the fuxt framework.
 * Author: funkhaus, drewbaker, rsm0128
 * Author URI: https://github.com/funkhaus
 * Text Domain: fuxt-api
 * Version: 0.1.4
 * Year: 2024
 */

require_once __DIR__ . '/includes/class-plugin-base.php';
require_once __DIR__ . '/includes/class-plugin.php';

/**
 * Fuxt API Plugin Instance
 *
 * @return \FuxtApi\Plugin
 */
function fuxt_api_get_plugin_instance() {
	static $fuxt_api_plugin;

	if ( is_null( $fuxt_api_plugin ) ) {
		$fuxt_api_plugin = new \FuxtApi\Plugin( __FILE__ );

		if ( function_exists( 'wp_get_environment_type' ) ) {
			$fuxt_api_plugin->set_site_environment_type( wp_get_environment_type() );
		}

		$fuxt_api_plugin->init();
	}

	return $fuxt_api_plugin;
}

fuxt_api_get_plugin_instance();
