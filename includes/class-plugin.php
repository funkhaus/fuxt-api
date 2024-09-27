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
		( new REST_Menu_Controller() )->init();
		( new REST_Acf_Controller() )->init();
	}
}
