<?php
/**
 * Class Block
 *
 * @package FuxtApi
 */

namespace FuxtApi\Utils;

/**
 * Class Post
 *
 * @package FuxtApi
 */
class Block {

	/**
	 * Generate blocks data from block content.
	 *
	 * @param \WP_Post $post Post object.
	 *
	 * @return array
	 */
	public static function filter_blocks( $post ) {
		$parsed_blocks   = parse_blocks( $post->post_content );
		$extended_blocks = array();

		foreach ( $parsed_blocks as $parsed_block ) {
			if ( $parsed_block['blockName'] ) {
				$extended_blocks[] = self::extend_block( $parsed_block, $post );
			}
		}
		return $extended_blocks;
	}

	/**
	 * Extend block data.
	 *
	 * @param \WP_Block_Parser_Block $block Parsed block object.
	 * @param \WP_Post               $post  Post object.
	 *
	 * @return array [blockName => '', attrs => [], innterHtml => '', innerBlocks => [], 'embed' => []]
	 *
	 */
	private static function extend_block( $block, $post ) {
		$extended_block = array(
			'blockName' => $block['blockName'],
			'attrs'     => self::get_attributes( $block, $post->ID ),
			'innerHtml' => self::get_inner_html( render_block( $block ) ),
		);

		// Recursively extend any innerBlocks also.
		if ( ! empty( $block['innerBlocks'] ) ) {
			$extended_inner_blocks = array();
			foreach ( $block['innerBlocks'] as $inner_block ) {
				$extended_inner_blocks[] = self::extend_block( $inner_block, $post );
			}
			$extended_block['innerBlocks'] = $extended_inner_blocks;
		}

		// Specific block handling.
		switch ( $block['blockName'] ) {
			case 'core/image':
				// Add `embed`
				$extended_block['embed'] = Utils::get_mediadata( $block['attrs']['id'] );
				break;
		}

		return apply_filters( 'fuxt_extend_block', $extended_block );
	}

	/**
	 * Get block attributes.
	 *
	 * @param \WP_Block_Parser_Block $block   Parsed block object.
	 * @param int                    $post_id Post ID.
	 *
	 * @return array
	 *
	 */
	private static function get_attributes( $block, $post_id ) {
		static $block_registry;

		if ( null === $block_registry ) {
			$block_registry = \WP_Block_Type_Registry::get_instance();
		}

		$block_definition            = $block_registry->get_registered( $block['blockName'] ) ?? null;
		$block_definition_attributes = $block_definition->attributes ?? [];
		$block_attributes            = $block['attrs'];

		// Make id field mandatory.
		$block_definition_attributes['id'] = array(
			'type'      => 'string',
			'source'    => 'attribute',
			'attribute' => 'id',
		);

		// Make tag name field mandatory.
		$block_definition_attributes['tagName'] = array(
			'type'   => 'string',
			'source' => 'tag',
		);

		$dom_xpath   = null;
		$dom_element = null;

		foreach ( $block_definition_attributes as $block_attribute_name => $block_attribute_definition ) {
			$attribute_source        = $block_attribute_definition['source'] ?? null;
			$attribute_default_value = $block_attribute_definition['default'] ?? null;

			// Handle attribute setting case. Don't need to parse DOM.
			if ( null === $attribute_source ) {
				if ( ! isset( $block_attributes[ $block_attribute_name ] ) && null !== $attribute_default_value ) {
					$block_attributes[ $block_attribute_name ] = $attribute_default_value;
				}

				continue;
			}

			// Init $dom_xpath and $dom_element.
			if ( null === $dom_element ) {
				$dom_document = self::get_dom_document( $block['innerHTML'] );
				$body_node    = $dom_document->getElementsByTagName( 'body' )->item( 0 );
				$dom_element  = $body_node ? $body_node->firstChild : $dom_document->documentElement->firstChild;
				$dom_xpath    = new \DOMXPath( $dom_document );
			}

			// If selector is defined set the element as dom element.
			$seleted_dom = $dom_element;
			if ( ! empty( $block_attribute_definition['selector'] ) ) {
				$child_nodes = $dom_xpath->query( $block_attribute_definition['selector'], $dom_element->parentNode );

				if ( ! empty( $child_nodes ) && ! empty( $child_nodes[0] ) ) {
					$seleted_dom = $child_nodes[0];
				}
			}

			// Get attribute value from DOM.
			$attribute_value = null;
			if ( 'attribute' === $attribute_source || 'property' === $attribute_source ) {
				$attribute_value = $seleted_dom->getAttribute( $block_attribute_definition['attribute'] );
			} elseif ( 'rich-text' === $attribute_source || 'html' === $attribute_source ) {
				$attribute_value = $seleted_dom->ownerDocument->saveHTML( $seleted_dom );
			} elseif ( 'text' === $attribute_source ) {
				$attribute_value = $seleted_dom->textContent;
			} elseif ( 'tag' === $attribute_source ) {
				$attribute_value = strtolower( $seleted_dom->tagName );
			} elseif ( 'meta' === $attribute_source ) {
				$meta_key = $block_attribute_definition['meta'];

				if ( metadata_exists( 'post', $post_id, $meta_key ) ) {
					$attribute_value = get_post_meta( $post_id, $meta_key, true );
				}
			}

			if ( null === $attribute_value ) {
				$attribute_value = $attribute_default_value;
			}

			$block_attributes[ $block_attribute_name ] = $attribute_value;
		}

		// Sort attributes by key to ensure consistent output.
		ksort( $block_attributes );

		return $block_attributes;
	}

	/**
	 * Get dom document object by html.
	 *
	 * @param string $html HTML string.
	 *
	 * @return \DOMDocument
	 */
	private static function get_dom_document( $html ) {
		$dom = new \DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML( sprintf( '<body>%s</body>', trim( $html ) ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		return $dom;
	}

	/**
	 * Get inner html from outer html string.
	 *
	 * @param string $html HTML string.
	 *
	 * @return string
	 */
	private static function get_inner_html( $html ) {
		$dom = new \DOMDocument();

		libxml_use_internal_errors( true );
		$dom->loadHTML( sprintf( '<body>%s</body>', trim( $html ) ), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$body_node   = $dom->getElementsByTagName( 'body' )->item( 0 );
		$first_child = $body_node ? $body_node->firstChild : $dom->documentElement->firstChild;

		$inner_html = '';
		if ( $first_child ) {
			foreach ( $first_child->childNodes as $child ) {
				$inner_html .= $dom->saveHTML( $child );
			}
		}

		return $inner_html;
	}

}
