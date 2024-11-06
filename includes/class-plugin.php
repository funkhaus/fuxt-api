<?php
/**
 * Class Plugin
 *
 * @package FuxtApi
 */

namespace FuxtApi;

use \FuxtApi\Plugin_Base;

/**
 * Class Plugin
 *
 * @package FuxtApi
 */
class Plugin extends Plugin_Base {

	public function init() {
		// Load modules.
		( new REST_Post_Controller() )->init();
		( new REST_Settings_Controller() )->init();
		( new REST_Menu_Controller() )->init();
		( new REST_Acf_Controller() )->init();
		( new REST_Posts_Controller() )->init();

		$this->update_check();
	}

	public function update_check() {
		if ( is_admin() ) { // note the use of is_admin() to double check that this is happening in the admin
			if ( ! class_exists( 'WP_GitHub_Updater' ) ) {
				require_once $this->dir_path . '/includes/libs/class-wp-github-updater.php';
			}

			$config = array(
				'slug'               => plugin_basename( $this->file ),
				'proper_folder_name' => dirname( plugin_basename( $this->file ) ),
				'api_url'            => 'https://api.github.com/repos/funkhaus/fuxt-api', // the GitHub API url of your GitHub repo
				'raw_url'            => 'https://raw.github.com/funkhaus/fuxt-api/main', // the GitHub raw url of your GitHub repo
				'github_url'         => 'https://github.com/funkhaus/fuxt-api', // the GitHub url of your GitHub repo
				'zip_url'            => 'https://github.com/funkhaus/fuxt-api/zipball/main', // the zip url of the GitHub repo
				'sslverify'          => true, // whether WP should check the validity of the SSL cert when getting an update, see https://github.com/jkudish/WordPress-GitHub-Plugin-Updater/issues/2 and https://github.com/jkudish/WordPress-GitHub-Plugin-Updater/issues/4 for details
				'requires'           => '6.0.0', // which version of WordPress does your plugin require?
				'tested'             => '6.6.2', // which version of WordPress is your plugin tested up to?
				'readme'             => 'README.md', // which file to use as the readme for the version number
				'access_token'       => '', // Access private repositories by authorizing under Plugins > GitHub Updates when this example plugin is installed
			);

			new \WP_GitHub_Updater( $config );
		}
	}
}
