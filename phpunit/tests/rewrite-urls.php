<?php

require_once __DIR__ . '/base.php';

use function WordPress\DataLiberation\URL\wp_rewrite_urls;

/**
 * @group import
 * @group rewrite-urls
 */
class Tests_Import_Rewrite_Urls extends WP_Import_UnitTestCase {

	const FROM = 'https://legacy-blog.com';
	const TO   = 'https://modern-webstore.org';

	/**
	 * Run block markup through the URL rewriter with a single mapping.
	 *
	 * @param string $block_markup Markup to rewrite.
	 * @return string Rewritten markup.
	 */
	private function rewrite( $block_markup ) {
		return wp_rewrite_urls(
			array(
				'block_markup' => $block_markup,
				'url-mapping'  => array( self::FROM => self::TO ),
				'base_url'     => self::FROM,
			)
		);
	}

	/**
	 * Fragment-only references point within the document that contains them,
	 * so there is no origin to migrate and nothing to rewrite.
	 *
	 * Rewriting them resolves the fragment against the base URL first, which
	 * turns an in-page anchor into a link to a different page: `#section`
	 * became `/#section` and a bare `#` became `/`, dropping the fragment
	 * altogether. Blocks whose saved markup contains such an href then fail
	 * validation after an import.
	 *
	 * @covers ::WordPress\DataLiberation\URL\wp_rewrite_urls
	 *
	 * @dataProvider data_fragment_only_urls
	 *
	 * @param string $block_markup Markup containing a fragment-only reference.
	 */
	public function test_does_not_rewrite_fragment_only_urls( $block_markup ) {
		$this->assertSame(
			$block_markup,
			$this->rewrite( $block_markup ),
			'Fragment-only references must survive a rewrite untouched.'
		);
	}

	/**
	 * Data provider for fragment-only references.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function data_fragment_only_urls() {
		return array(
			'named fragment'                => array( '<a href="#section">Jump</a>' ),
			'bare hash'                     => array( '<a href="#">Placeholder</a>' ),
			'hyphenated fragment'           => array( '<a href="#add-to-calendar">iCal</a>' ),
			'fragment in a block attribute' => array( '<!-- wp:button {"url":"#login"} -->' ),
		);
	}

	/**
	 * The guard is limited to references that are *only* a fragment. A real
	 * URL keeps being rewritten, and keeps its fragment while doing so.
	 *
	 * @covers ::WordPress\DataLiberation\URL\wp_rewrite_urls
	 */
	public function test_still_rewrites_absolute_urls_carrying_a_fragment() {
		$this->assertSame(
			'<a href="' . self::TO . '/page#section">Deep link</a>',
			$this->rewrite( '<a href="' . self::FROM . '/page#section">Deep link</a>' )
		);
	}

	/**
	 * Ordinary rewriting is unaffected.
	 *
	 * @covers ::WordPress\DataLiberation\URL\wp_rewrite_urls
	 */
	public function test_still_rewrites_absolute_urls() {
		$this->assertSame(
			'<a href="' . self::TO . '/page">Page</a>',
			$this->rewrite( '<a href="' . self::FROM . '/page">Page</a>' )
		);
	}
}
