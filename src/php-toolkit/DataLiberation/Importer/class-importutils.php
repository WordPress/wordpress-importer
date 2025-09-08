<?php

namespace WordPress\DataLiberation\Importer;

use WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;
use WordPress\DataLiberation\DataLiberationHTMLProcessor;

/**
 * A collection of utility functions for importing data into WordPress.
 *
 * This is a stopgap solution until we have an expressive enough API
 * to co-locate these tactical functions with their domain-specific code.
 */
class ImportUtils {

	/**
	 * Generates a block opener comment with given attributes.
	 *
	 * @param  string $block_name  The name of the block.
	 * @param  array  $attrs  The attributes of the block.
	 *
	 * @return string The block opener.
	 */
	public static function block_opener( $block_name, $attrs = array() ) {
		$template  = "<!-- wp:{$block_name} -->";
		$processor = new BlockMarkupProcessor( $template );
		$processor->next_token();
		$processor->set_block_attributes( $attrs );

		return $processor->get_updated_html();
	}

	/**
	 * Generates a block closer comment.
	 *
	 * @param  string $block_name  The name of the block.
	 *
	 * @return string The block closer.
	 */
	public static function block_closer( $block_name ) {
		return "<!-- /wp:{$block_name} -->";
	}

	/**
	 * Convert an array of WP_Block_Object objects to HTML markup.
	 *
	 * @param  array $blocks  The blocks to convert to markup.
	 *
	 * @return string The HTML markup.
	 */
	public static function convert_blocks_to_markup( $blocks ) {
		$block_markup = '';

		foreach ( $blocks as $block ) {
			// Allow mixing of inner blocks and content strings.
			if ( is_string( $block ) ) {
				$block_markup .= $block;
				continue;
			}
			// Start of block comment.
			$block_markup .= self::block_opener( $block->block_name, $block->attrs );
			$block_markup .= $block->attrs['content'] ?? '';
			$block_markup .= self::convert_blocks_to_markup( $block->inner_blocks );
			$block_markup .= self::block_closer( $block->block_name );
		}

		return $block_markup;
	}

	public static function slug_to_title( $filename ) {
		$name = pathinfo( $filename, PATHINFO_FILENAME );
		$name = preg_replace( '/^\d+/', '', $name );
		$name = str_replace(
			array( '-', '_' ),
			' ',
			$name
		);
		$name = ucwords( $name );

		return $name;
	}

	public static function remove_first_h1_block_from_block_markup( $html ) {
		$p = DataLiberationHTMLProcessor::create_fragment( $html );
		if ( false === $p->next_tag() ) {
			return false;
		}
		if ( 'H1' !== $p->get_tag() ) {
			return false;
		}
		$depth = $p->get_current_depth();
		$title = '';
		do {
			if ( false === $p->next_token() ) {
				break;
			}
			if ( '#text' === $p->get_token_type() ) {
				$title .= $p->get_modifiable_text() . ' ';
			}
		} while ( $p->get_current_depth() > $depth );

		if ( ! $title ) {
			return false;
		}

		// Move past the closing comment.
		$p->next_token();
		if ( '#text' === $p->get_token_type() ) {
			$p->next_token();
		}
		if ( '#comment' !== $p->get_token_type() ) {
			return false;
		}

		return array(
			'h1_content'     => trim( $title ),
			'remaining_html' => substr(
				$html,
				$p->get_string_index_after_current_token()
			),
		);
	}
}
