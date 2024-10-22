<?php
/**
 * Class REST_Menu_Controller
 *
 * @package FuxtApi
 */

namespace FuxtApi;

use Symfony\Component\DomCrawler\Crawler;

/**
 * Class REST_Menu_Controller
 *
 * @package FuxtApi
 */
class Block_Parser extends \WPCOMVIP\BlockDataApi\ContentParser {
	/**
	 * Initialize the class.
	 *
	 * @param WP_Block_Type_Registry|null $block_registry the block registry instance.
	 */
	public function __construct( $block_registry = null ) {

		// Load all additions.
		foreach ( glob( fuxt_api_get_plugin_instance()->dir_path . '/includes/libs/block-additions/*.php' ) as $filename ) {
			require_once $filename;
		}

		parent::__construct( $block_registry );

		$this->init();
	}

	/**
	 * Init function.
	 */
	public function init() {
		add_filter( 'vip_block_data_api__sourced_block_result', array( $this, 'set_attr_inner_html' ), 10, 4 );
	}

	/**
	 * Set html and innerHtml attribute.
	 */
	public function set_attr_inner_html( $sourced_block, $block_name, $post_id, $parsed_block ) {
		$sourced_block['html']      = render_block( $parsed_block );
		$sourced_block['innerHtml'] = $this->get_inner_html( $sourced_block['html'] );
		return $sourced_block;
	}

	/**
	 * Get inner html of an html.
	 *
	 * @param string $html HTML string.
	 *
	 * @return string
	 */
	public function get_inner_html( $html ) {
		$dom = new \DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML( sprintf( '<body>%s</body>', trim( $html ) ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$body_node   = $dom->getElementsByTagName( 'body' )->item( 0 );
		$first_child = $body_node ? $body_node->firstChild : $dom->documentElement->firstChild; // phpcs:ignore

		$inner_html = '';
		if ( $first_child ) {
			foreach ( $first_child->childNodes as $child ) { // phpcs:ignore
				$inner_html .= $dom->saveHTML( $child );
			}
		}

		return $inner_html;
	}
}
