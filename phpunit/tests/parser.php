<?php

require_once dirname( __FILE__ ) . '/base.php';

/**
 * @group import
 */
class Tests_Import_Parser extends WP_Import_UnitTestCase {
	function set_up() {
		parent::set_up();

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}

	}

	function test_malformed_wxr() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/malformed.xml';

		// regex based parser cannot detect malformed XML
		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML' ) as $p ) {
			$parser = new $p;
			$result = $parser->parse( $file );
			$this->assertWPError( $result );
			$this->assertSame( 'There was an error when reading this WXR file', $result->get_error_message() );
		}
	}

	function test_invalid_wxr() {
		$f1 = DIR_TESTDATA_WP_IMPORTER . '/missing-version-tag.xml';
		$f2 = DIR_TESTDATA_WP_IMPORTER . '/invalid-version-tag.xml';

		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML', 'WXR_Parser_Regex' ) as $p ) {
			foreach ( array( $f1, $f2 ) as $file ) {
				$parser = new $p;
				$result = $parser->parse( $file );
				$this->assertWPError( $result );
				$this->assertSame( 'This does not appear to be a WXR file, missing/invalid WXR version number', $result->get_error_message() );
			}
		}
	}

	function test_wxr_version_1_1() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/valid-wxr-1.1.xml';

		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML', 'WXR_Parser_Regex' ) as $p ) {
			$message = $p . ' failed';
			$parser  = new $p;
			$result  = $parser->parse( $file );

			$this->assertIsArray( $result, $message );
			$this->assertSame( 'http://localhost/', $result['base_url'], $message );
			$this->assertEquals(
				array(
					'author_id'           => 2,
					'author_login'        => 'john',
					'author_email'        => 'johndoe@example.org',
					'author_display_name' => 'John Doe',
					'author_first_name'   => 'John',
					'author_last_name'    => 'Doe',
				),
				$result['authors']['john'],
				$message
			);
			$this->assertEquals(
				array(
					'term_id'              => 3,
					'category_nicename'    => 'alpha',
					'category_parent'      => '',
					'cat_name'             => 'alpha',
					'category_description' => 'The alpha category',
				),
				$result['categories'][0],
				$message
			);
			$this->assertEquals(
				array(
					'term_id'         => 22,
					'tag_slug'        => 'clippable',
					'tag_name'        => 'Clippable',
					'tag_description' => 'The Clippable post_tag',
				),
				$result['tags'][0],
				$message
			);
			$this->assertEquals(
				array(
					'term_id'          => 40,
					'term_taxonomy'    => 'post_tax',
					'slug'             => 'bieup',
					'term_parent'      => '',
					'term_name'        => 'bieup',
					'term_description' => 'The bieup post_tax',
				),
				$result['terms'][0],
				$message
			);

			$this->assertCount( 2, $result['posts'], $message );
			$this->assertCount( 19, $result['posts'][0], $message );
			$this->assertCount( 18, $result['posts'][1], $message );
			$this->assertEquals(
				array(
					array(
						'name'   => 'alpha',
						'slug'   => 'alpha',
						'domain' => 'category',
					),
					array(
						'name'   => 'Clippable',
						'slug'   => 'clippable',
						'domain' => 'post_tag',
					),
					array(
						'name'   => 'bieup',
						'slug'   => 'bieup',
						'domain' => 'post_tax',
					),
				),
				$result['posts'][0]['terms'],
				$message
			);
			$this->assertSame(
				array(
					array(
						'key'   => '_wp_page_template',
						'value' => 'default',
					),
				),
				$result['posts'][1]['postmeta'],
				$message
			);
		}
	}

	function test_wxr_version_1_0() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/valid-wxr-1.0.xml';

		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML', 'WXR_Parser_Regex' ) as $p ) {
			$message = $p . ' failed';
			$parser  = new $p;
			$result  = $parser->parse( $file );

			$this->assertIsArray( $result, $message );
			$this->assertSame( 'http://localhost/', $result['base_url'], $message );
			$this->assertSame( 'alpha', $result['categories'][0]['category_nicename'], $message );
			$this->assertSame( 'alpha', $result['categories'][0]['cat_name'], $message );
			$this->assertSame( '', $result['categories'][0]['category_parent'], $message );
			$this->assertSame( 'The alpha category', $result['categories'][0]['category_description'], $message );
			$this->assertSame( 'chicken', $result['tags'][0]['tag_slug'], $message );
			$this->assertSame( 'chicken', $result['tags'][0]['tag_name'], $message );

			$this->assertCount( 6, $result['posts'], $message );
			$this->assertCount( 19, $result['posts'][0], $message );
			$this->assertCount( 18, $result['posts'][1], $message );

			$this->assertEquals(
				array(
					array(
						'name'   => 'Uncategorized',
						'slug'   => 'uncategorized',
						'domain' => 'category',
					),
				),
				$result['posts'][0]['terms'],
				$message
			);
			$this->assertEquals(
				array(
					array(
						'name'   => 'alpha',
						'slug'   => 'alpha',
						'domain' => 'category',
					),
					array(
						'name'   => 'news',
						'slug'   => 'news',
						'domain' => 'tag',
					),
					array(
						'name'   => 'roar',
						'slug'   => 'roar',
						'domain' => 'tag',
					),
				),
				$result['posts'][2]['terms'],
				$message
			);
			$this->assertEquals(
				array(
					array(
						'name'   => 'chicken',
						'slug'   => 'chicken',
						'domain' => 'tag',
					),
					array(
						'name'   => 'child',
						'slug'   => 'child',
						'domain' => 'category',
					),
					array(
						'name'   => 'face',
						'slug'   => 'face',
						'domain' => 'tag',
					),
				),
				$result['posts'][3]['terms'],
				$message
			);

			$this->assertSame(
				array(
					array(
						'key'   => '_wp_page_template',
						'value' => 'default',
					),
				),
				$result['posts'][1]['postmeta'],
				$message
			);
		}
	}

	// Test that all parsers preserve blank lines in content
	function test_blank_lines_in_content() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/post-content-blank-lines.xml';

		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML', 'WXR_Parser_Regex' ) as $p ) {
			$message = $p . ' failed and is missing blank lines';
			$parser  = new $p;
			$result  = $parser->parse( $file );

			// Check the number of new lines characters
			$this->assertSame( 3, substr_count( $result['posts'][0]['post_content'], PHP_EOL ), $message );
		}
	}

	// Tests that each parser detects the same number of terms.
	function test_varied_taxonomy_term_spacing() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/term-formats.xml';

		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML', 'WXR_Parser_Regex' ) as $p ) {
			$message = $p . ' failed';
			$parser  = new $p;
			$result  = $parser->parse( $file );

			$this->assertIsArray( $result, $message );
			$this->assertSame( 'http://localhost/', $result['base_url'], $message );

			$this->assertEmpty( $result['authors'], $message );
			$this->assertEmpty( $result['posts'], $message );

			$this->assertCount( 2, $result['categories'], $message );
			$this->assertCount( 3, $result['tags'], $message );
			$this->assertCount( 2, $result['terms'], $message );

			// TODO: Verify the content of the terms extracted and verify each has the expected fields & field types.
		}
	}

	/**
	 * Test the WXR parser's ability to correctly retrieve content from CDATA
	 * sections that contain escaped closing tags ("]]>" -> "]]]]><![CDATA[>").
	 *
	 * @link https://core.trac.wordpress.org/ticket/15203
	 */
	function test_escaped_cdata_closing_sequence() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/crazy-cdata-escaped.xml';

		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML', 'WXR_Parser_Regex' ) as $p ) {
			$message = 'Parser ' . $p;
			$parser  = new $p;
			$result  = $parser->parse( $file );

			$post = $result['posts'][0];
			$this->assertSame( 'Content with nested <![CDATA[ tags ]]> :)', $post['post_content'], $message );
			foreach ( $post['postmeta'] as $meta ) {
				switch ( $meta['key'] ) {
					case 'Plain string':
						$value = 'Foo';
						break;
					case 'Closing CDATA':
						$value = ']]>';
						break;
					case 'Alot of CDATA':
						$value = 'This has <![CDATA[ opening and ]]> closing <![CDATA[ tags like this: ]]>';
						break;
					default:
						$this->fail( 'Unknown postmeta (' . $meta['key'] . ') was parsed out by' . $p );
				}
				$this->assertSame( $value, $meta['value'], $message );
			}
		}
	}

	/**
	 * Ensure that the regex parser can still parse invalid CDATA blocks (i.e. those
	 * with "]]>" unescaped within a CDATA section).
	 */
	function test_unescaped_cdata_closing_sequence() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/crazy-cdata.xml';

		$parser = new WXR_Parser_Regex;
		$result = $parser->parse( $file );

		$post = $result['posts'][0];
		$this->assertSame( 'Content with nested <![CDATA[ tags ]]> :)', $post['post_content'] );
		foreach ( $post['postmeta'] as $meta ) {
			switch ( $meta['key'] ) {
				case 'Plain string':
					$value = 'Foo';
					break;
				case 'Closing CDATA':
					$value = ']]>';
					break;
				case 'Alot of CDATA':
					$value = 'This has <![CDATA[ opening and ]]> closing <![CDATA[ tags like this: ]]>';
					break;
				default:
					$this->fail( 'Unknown postmeta (' . $meta['key'] . ') was parsed out by' . $p );
			}
			$this->assertSame( $value, $meta['value'] );
		}
	}

	/**
	 * @group term-meta
	 */
	function test_term_meta_parsing() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/test-serialized-term-meta.xml';

		$expected_meta = array(
			array(
				'key'   => 'string',
				'value' => '¯\_(ツ)_/¯',
			),
			array(
				'key'   => 'array',
				'value' => 'a:1:{s:3:"key";s:13:"¯\_(ツ)_/¯";}',
			),
		);

		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML', 'WXR_Parser_Regex' ) as $p ) {
			$message = 'Parser ' . $p;
			$parser  = new $p;
			$result  = $parser->parse( $file );

			$this->assertCount( 1, $result['categories'], $message );
			$this->assertCount( 1, $result['tags'], $message );
			$this->assertCount( 1, $result['terms'], $message );

			$category = $result['categories'][0];
			$this->assertArrayHasKey( 'termmeta', $category, $message );
			$this->assertCount( 2, $category['termmeta'], $message );
			$this->assertSame( $expected_meta, $category['termmeta'], $message );

			$tag = $result['tags'][0];
			$this->assertArrayHasKey( 'termmeta', $tag, $message );
			$this->assertCount( 2, $tag['termmeta'], $message );
			$this->assertSame( $expected_meta, $tag['termmeta'], $message );

			$term = $result['terms'][0];
			$this->assertArrayHasKey( 'termmeta', $term, $message );
			$this->assertCount( 2, $term['termmeta'], $message );
			$this->assertSame( $expected_meta, $term['termmeta'], $message );
		}
	}

	/**
	 * @group comment-meta
	 */
	function test_comment_meta_parsing() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/test-serialized-comment-meta.xml';

		$expected_meta = array(
			array(
				'key'   => 'string',
				'value' => '¯\_(ツ)_/¯',
			),
			array(
				'key'   => 'array',
				'value' => 'a:1:{s:3:"key";s:13:"¯\_(ツ)_/¯";}',
			),
		);

		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML', 'WXR_Parser_Regex' ) as $p ) {
			$message = 'Parser ' . $p;
			$parser  = new $p;
			$result  = $parser->parse( $file );

			$this->assertCount( 1, $result['posts'], $message );

			$post = $result['posts'][0];
			$this->assertArrayHasKey( 'comments', $post, $message );

			$comment = $post['comments'][0];
			$this->assertArrayHasKey( 'commentmeta', $comment, $message );
			$this->assertCount( 2, $comment['commentmeta'], $message );
			$this->assertSame( $expected_meta, $comment['commentmeta'], $message );
		}
	}
}
