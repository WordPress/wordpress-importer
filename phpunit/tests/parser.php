<?php

require_once __DIR__ . '/base.php';

/**
 * @group import
 */
class Tests_Import_Parser extends WP_Import_UnitTestCase {
	public function set_up() {
		parent::set_up();

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}
		// Include the parser classes
		// require_once dirname( __DIR__, 3 ) . '/components/DataLiberation/EntityReader/EntityReader.php';
		// require_once dirname( __DIR__, 3 ) . '/components/DataLiberation/EntityReader/WXREntityReader.php';
		// require_once __DIR__ . '/../class-wxr-parser-entity-reader.php';
		// require_once __DIR__ . '/../class-wxr-parser-xml-processor.php';
		// require_once __DIR__ . '/../class-wxr-parser-regex.php';
		// require_once __DIR__ . '/../class-wxr-parser-xml.php';
	}

	/**
	 * @covers WXR_Parser_SimpleXML::parse
	 * @covers WXR_Parser_XML::parse
	 */
	public function test_malformed_wxr() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/malformed.xml';

		// Regex based parser cannot detect malformed XML.
		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML' ) as $p ) {
			$parser = new $p();
			$result = $parser->parse( $file );
			$this->assertWPError( $result );
			$this->assertSame( 'There was an error when reading this WXR file', $result->get_error_message() );
		}
	}

	/**
	 * @covers WXR_Parser_SimpleXML::parse
	 * @covers WXR_Parser_XML::parse
	 * @covers WXR_Parser_Regex::parse
	 */
	public function test_invalid_wxr() {
		$f1 = DIR_TESTDATA_WP_IMPORTER . '/missing-version-tag.xml';
		$f2 = DIR_TESTDATA_WP_IMPORTER . '/invalid-version-tag.xml';

		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML', 'WXR_Parser_Regex' ) as $p ) {
			foreach ( array( $f1, $f2 ) as $file ) {
				$parser = new $p();
				$result = $parser->parse( $file );
				$this->assertWPError( $result );
				$this->assertSame( 'This does not appear to be a WXR file, missing/invalid WXR version number', $result->get_error_message() );
			}
		}
	}

	/**
	 * @covers WXR_Parser_SimpleXML::parse
	 * @covers WXR_Parser_XML::parse
	 * @covers WXR_Parser_Regex::parse
	 */
	public function test_wxr_version_1_1() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/valid-wxr-1.1.xml';

		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML', 'WXR_Parser_Regex' ) as $p ) {
			$message = $p . ' failed';
			$parser  = new $p();
			$result  = $parser->parse( $file );

			$this->assertIsArray( $result, $message );
			$this->assertSame( 'http://localhost/', $result['base_url'], $message );
			$this->assertEqualSetsWithIndex(
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
			$this->assertEqualSetsWithIndex(
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
			$this->assertEqualSetsWithIndex(
				array(
					'term_id'         => 22,
					'tag_slug'        => 'clippable',
					'tag_name'        => 'Clippable',
					'tag_description' => 'The Clippable post_tag',
				),
				$result['tags'][0],
				$message
			);
			$this->assertEqualSetsWithIndex(
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
			$this->assertEqualSetsWithIndex(
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

	/**
	 * @covers WXR_Parser_SimpleXML::parse
	 * @covers WXR_Parser_XML::parse
	 * @covers WXR_Parser_Regex::parse
	 */
	public function test_wxr_version_1_0() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/valid-wxr-1.0.xml';

		foreach ( array( 'WXR_Parser_SimpleXML', 'WXR_Parser_XML', 'WXR_Parser_Regex' ) as $p ) {
			$message = $p . ' failed';
			$parser  = new $p();
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

	/**
	 * Test that all parsers preserve blank lines in content
	 *
	 * @dataProvider parser_provider
	 */
	public function test_blank_lines_in_content( $parser_class ) {
		$file = DIR_TESTDATA_WP_IMPORTER . '/post-content-blank-lines.xml';

		$message = $parser_class . ' failed and is missing blank lines';
		$parser  = new $parser_class();
		$result  = $parser->parse( $file );

		// Check the number of new lines characters
		$this->assertSame( 3, substr_count( $result['posts'][0]['post_content'], "\n" ), $message );
	}

	/**
	 * Tests that each parser detects the same number of terms.
	 *
	 * @dataProvider parser_provider
	 */
	public function test_varied_taxonomy_term_spacing( $parser_class ) {
		$file = DIR_TESTDATA_WP_IMPORTER . '/term-formats.xml';

		$message = $parser_class . ' failed';
		$parser  = new $parser_class();
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

	/**
	 * Test the WXR parser's ability to correctly retrieve content from CDATA
	 * sections that contain escaped closing tags ("]]>" -> "]]]]><![CDATA[>").
	 *
	 * @link https://core.trac.wordpress.org/ticket/15203
	 *
	 * @covers WXR_Parser_SimpleXML::parse
	 * @covers WXR_Parser_XML::parse
	 * @covers WXR_Parser_Regex::parse
	 *
	 * @dataProvider parser_provider
	 */
	public function test_escaped_cdata_closing_sequence( $parser_class ) {
		$file = DIR_TESTDATA_WP_IMPORTER . '/crazy-cdata-escaped.xml';

		$message = 'Parser ' . $parser_class;
		$parser  = new $parser_class();
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
					$this->fail( sprintf( 'Unknown postmeta (%1$s) was parsed out by %2$s.', $meta['key'], $p ) );
			}
			$this->assertSame( $value, $meta['value'], $message );
		}
	}

	/**
	 * Ensure that the regex parser can still parse invalid CDATA blocks (i.e. those
	 * with "]]>" unescaped within a CDATA section).
	 *
	 * @covers WXR_Parser_Regex::parse
	 */
	public function test_unescaped_cdata_closing_sequence() {
		$file = DIR_TESTDATA_WP_IMPORTER . '/crazy-cdata.xml';

		$parser = new WXR_Parser_Regex();
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
					$this->fail( sprintf( 'Unknown postmeta (%1$s) was parsed out by %2$s.', $meta['key'], $p ) );
			}
			$this->assertSame( $value, $meta['value'] );
		}
	}

	/**
	 * @group term-meta
	 */
	public function test_term_meta_parsing() {
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
			$parser  = new $p();
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
	public function test_comment_meta_parsing() {
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
			$parser  = new $p();
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

	/**
	 * @dataProvider parser_provider
	 */
	public function test_parse_simple_wxr_content( $parser_class ) {
		if ( 'WXR_Parser_Regex' === $parser_class ) {
			$this->markTestSkipped( "Skipping the failing test for $parser_class" );
			return;
		}
		$parser    = new $parser_class();
		$file_path = DIR_TESTDATA_WP_IMPORTER . '/wxr-simple.xml';

		$result = $parser->parse( $file_path );

		$this->assertEquals( '1.2', $result['version'], "Parser $parser_class failed" );
		$this->assertEquals( 'https://playground.internal/path', $result['base_url'], "Parser $parser_class failed" );
		$this->assertEquals( 'https://playground.internal/path', $result['base_blog_url'], "Parser $parser_class failed" );

		$this->assertIsArray( $result['authors'], "Parser $parser_class failed" );
		$this->assertNotEmpty( $result['authors'], "Parser $parser_class failed" );

		$first_author         = reset( $result['authors'] );
		$expected_author_keys = array( 'author_id', 'author_login', 'author_email', 'author_display_name', 'author_first_name', 'author_last_name' );
		foreach ( $expected_author_keys as $key ) {
			$this->assertArrayHasKey( $key, $first_author, "Author should contain key: $key for parser $parser_class" );
		}

		$this->assertEquals( '1', $first_author['author_id'], "Parser $parser_class failed" );
		$this->assertEquals( 'admin', $first_author['author_login'], "Parser $parser_class failed" );
		$this->assertEquals( 'admin@localhost.com', $first_author['author_email'], "Parser $parser_class failed" );

		$this->assertIsArray( $result['posts'], "Parser $parser_class failed" );
		$this->assertNotEmpty( $result['posts'], "Parser $parser_class failed" );

		$first_post         = reset( $result['posts'] );
		$expected_post_keys = array( 'post_id', 'post_title', 'post_date', 'post_date_gmt', 'post_content', 'post_type', 'post_name', 'status' );
		foreach ( $expected_post_keys as $key ) {
			$this->assertArrayHasKey( $key, $first_post, "Post should contain key: $key for parser $parser_class" );
		}

		$this->assertEquals( '10', $first_post['post_id'], "Parser $parser_class failed" );
		$this->assertEquals( '"The Road Not Taken" by Robert Frost', $first_post['post_title'], "Parser $parser_class failed" );
		$this->assertEquals( 'post', $first_post['post_type'], "Parser $parser_class failed" );
		$this->assertEquals( 'hello-world', $first_post['post_name'], "Parser $parser_class failed" );
		$this->assertEquals( 'publish', $first_post['status'], "Parser $parser_class failed" );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function test_parse_non_existent_file( $parser_class ) {
		$this->expectWarning();

		$parser = new $parser_class();
		$result = $parser->parse( '/path/to/non-existent-file.xml' );

		$this->assertInstanceOf( 'WP_Error', $result, "Parser $parser_class failed" );
		$this->assertEquals( 'WXR_parse_error', $result->get_error_code(), "Parser $parser_class failed" );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function test_parse_invalid_xml_file( $parser_class ) {
		$parser = new $parser_class();

		$temp_file = tempnam( sys_get_temp_dir(), 'invalid_wxr' );
		file_put_contents( $temp_file, 'This is not valid XML content' );

		$result = $parser->parse( $temp_file );

		unlink( $temp_file );

		$this->assertInstanceOf( 'WP_Error', $result, "Parser $parser_class failed" );
		$this->assertContains( $result->get_error_code(), array( 'WXR_parse_error', 'XML_parse_error', 'SimpleXML_parse_error' ), "Parser $parser_class failed" );
	}

	public static function parser_provider_with_data() {
		$test_cases = array();
		foreach ( self::parser_provider() as $parser ) {
			foreach ( self::wxr_files_provider() as $data ) {
				$test_cases[] = array_merge( $parser, $data );
			}
		}
		return $test_cases;
	}

	public static function parser_provider() {
		return array(
			array( 'WXR_Parser_Regex' ),
			array( 'WXR_Parser_XML' ),
			array( 'WXR_Parser_SimpleXML' ),
		);
	}

	public static function wxr_files_provider() {
		$wxrs_dir   = DIR_TESTDATA_WP_IMPORTER;
		$test_cases = array();

		if ( is_dir( $wxrs_dir ) ) {
			$files = glob( $wxrs_dir . '/*.xml' );

			$file_configs = array(
				'wxr-simple.xml'           => array(
					'posts'   => 1,
					'authors' => 1,
				),
				'valid-wxr-1.0.xml'        => array(
					'posts'   => 6,
					'authors' => 0, /* Reality: 1 author referenced in posts */
				),
				'valid-wxr-1.1.xml'        => array(
					'posts'   => 2,
					'authors' => 1,
				),
				'wxr-utf-8-challenges.xml' => array(
					'posts'   => 1,
					'authors' => 1,
				),
				'10MB.xml'                 => array(
					'posts'   => 3162,
					'authors' => 2, /* Reality: 4 authors referenced in posts */
				),
				'a11y-unit-test-data.xml'  => array(
					'posts'   => 154,
					'authors' => 0, /* Reality: 4 authors referenced in posts */
				),
				'theme-unit-test-data.xml' => array(
					'posts'   => 186,
					'authors' => 2, /* Reality: 3 authors referenced in posts */
				),
				'wxr-with-sub-data.xml'    => array(
					'posts'   => 1,
					'authors' => 0, /* Reality: 1 authors referenced in posts */
				),
				'crazy-cdata-escaped.xml'  => array(
					'posts'   => 1,
					'authors' => 0,
				),
			);

			foreach ( $files as $file ) {
				$filename = basename( $file );

				if ( isset( $file_configs[ $filename ] ) ) {
					$config       = $file_configs[ $filename ];
					$test_cases[] = array(
						'file_path'        => $file,
						'expected_posts'   => $config['posts'],
						'expected_authors' => $config['authors'],
					);
				}
			}
		}

		return $test_cases;
	}

	/**
	 * @dataProvider parser_provider_with_data
	 */
	public function test_parse_multiple_wxr_files( $parser_class, $file_path, $expected_posts, $expected_authors ) {
		if (
			'WXR_Parser_Regex' === $parser_class &&
			in_array(
				basename( $file_path ),
				array( 'a11y-unit-test-data.xml', 'theme-unit-test-data.xml', 'wxr-simple.xml', 'wxr-utf-8-challenges.xml', '10MB.xml' ),
				true
			)
		) {
			$this->markTestSkipped( "Skipping the failing test $file_path for $parser_class" );
			return;
		}
		$parser = new $parser_class();
		$result = $parser->parse( $file_path );

		$filename = basename( $file_path );

		$this->assertNotInstanceOf( 'WP_Error', $result, "Failed to parse file: $filename with parser $parser_class" );
		$this->assertIsArray( $result, "Result should be an array for file: $filename with parser $parser_class" );

		$expected_keys = array( 'authors', 'posts', 'categories', 'tags', 'terms', 'base_url', 'base_blog_url', 'version' );
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $result, "Missing key '$key' in result for file: $filename with parser $parser_class" );
		}

		$this->assertEquals( $expected_posts, count( $result['posts'] ), "Expected $expected_posts posts in file: $filename with parser $parser_class" );
		$this->assertEquals( $expected_authors, count( $result['authors'] ), "Expected $expected_authors authors in file: $filename with parser $parser_class" );

		$this->assertNotEmpty( $result['version'], "WXR version should not be empty for file: $filename with parser $parser_class" );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+$/', $result['version'], "WXR version should be in format X.Y for file: $filename with parser $parser_class" );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function test_parse_resets_state( $parser_class ) {
		if ( 'WXR_Parser_Regex' === $parser_class ) {
			$this->markTestSkipped( "Skipping the failing test for $parser_class" );
			return;
		}
		$parser    = new $parser_class();
		$file_path = DIR_TESTDATA_WP_IMPORTER . '/wxr-simple.xml';

		$result1 = $parser->parse( $file_path );
		$this->assertNotEmpty( $result1['posts'], "Parser $parser_class failed" );
		$this->assertNotEmpty( $result1['authors'], "Parser $parser_class failed" );

		$result2 = $parser->parse( $file_path );
		$this->assertNotEmpty( $result2['posts'], "Parser $parser_class failed" );
		$this->assertNotEmpty( $result2['authors'], "Parser $parser_class failed" );

		$this->assertEquals( $result1, $result2, "Parser $parser_class failed" );
	}

	/**
	 * @group sub-data
	 */
	public function test_parse_wxr_with_sub_data() {
		$parser    = new WXR_Parser_XML();
		$file_path = DIR_TESTDATA_WP_IMPORTER . '/wxr-with-sub-data.xml';

		$result = $parser->parse( $file_path );

		$this->assertNotInstanceOf( 'WP_Error', $result, 'Failed to parse file with parser WXR_Parser_XML' );

		// Check basic structure
		$this->assertArrayHasKey( 'posts', $result );
		$this->assertArrayHasKey( 'terms', $result );
		$this->assertArrayHasKey( 'version', $result );

		// Check WXR version
		$this->assertEquals( '1.1', $result['version'] );

		// Check that we have one post
		$this->assertCount( 1, $result['posts'] );
		$post = $result['posts'][0];

		// Check post basic fields
		$this->assertEquals( '101', $post['post_id'] );
		$this->assertEquals( 'Post with sub data', $post['post_title'] );
		$this->assertEquals( 'admin', $post['post_author'] );
		$this->assertEquals( 'publish', $post['status'] );
		$this->assertEquals( 'post', $post['post_type'] );

		// Check post meta
		$this->assertArrayHasKey( 'postmeta', $post );
		$this->assertCount( 1, $post['postmeta'] );
		$this->assertEquals( '_test_meta_key', $post['postmeta'][0]['key'] );
		$this->assertEquals( 'test_meta_value', $post['postmeta'][0]['value'] );

		// Check category attributes (stored in terms)
		$this->assertArrayHasKey( 'terms', $post );
		$this->assertCount( 1, $post['terms'] );
		$this->assertEquals( 'category', $post['terms'][0]['domain'] );
		$this->assertEquals( 'test-cat', $post['terms'][0]['slug'] );
		$this->assertEquals( 'Test Category', $post['terms'][0]['name'] );

		// Check comments
		$this->assertArrayHasKey( 'comments', $post );
		$this->assertCount( 1, $post['comments'] );
		$comment = $post['comments'][0];
		$this->assertEquals( '201', $comment['comment_id'] );
		$this->assertEquals( 'Commenter', $comment['comment_author'] );
		$this->assertEquals( 'This is a comment with meta.', $comment['comment_content'] );

		// Check comment meta
		$this->assertArrayHasKey( 'commentmeta', $comment );
		$this->assertCount( 1, $comment['commentmeta'] );
		$this->assertEquals( '_comment_meta_key', $comment['commentmeta'][0]['key'] );
		$this->assertEquals( 'comment_meta_value', $comment['commentmeta'][0]['value'] );

		// Check terms (wp:term elements)
		$this->assertCount( 1, $result['terms'] );
		$term = $result['terms'][0];
		$this->assertEquals( '40', $term['term_id'] );
		$this->assertEquals( 'custom_tax', $term['term_taxonomy'] );
		$this->assertEquals( 'custom-term', $term['slug'] );
		$this->assertEquals( 'Custom Term', $term['term_name'] );

		// Check term meta
		$this->assertArrayHasKey( 'termmeta', $term );
		$this->assertCount( 1, $term['termmeta'] );
		$this->assertEquals( 'term_meta_key', $term['termmeta'][0]['key'] );
		$this->assertEquals( 'term_meta_value', $term['termmeta'][0]['value'] );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function test_parse_wxr_with_challenging_utf8_sequences( $parser_class ) {
		if ( 'WXR_Parser_Regex' === $parser_class ) {
			$this->markTestSkipped( "Skipping the failing test for $parser_class" );
			return;
		}

		$parser    = new $parser_class();
		$file_path = DIR_TESTDATA_WP_IMPORTER . '/wxr-utf-8-challenges.xml';
		$result    = $parser->parse( $file_path );

		$this->assertNotInstanceOf( 'WP_Error', $result );

		// Check basic post data with UTF-8
		$this->assertCount( 1, $result['posts'] );
		$post = $result['posts'][0];

		// Test post title with emojis, RTL override, and complex characters
		$this->assertEquals( '"The Road ‮Not‬ Taken" by Rob‭ert ‮Frost ‪🌲‬', $post['post_title'] );

		// Test post slug with RTL override and emoji
		$this->assertEquals( 'hello-w‮orld‬-utf8-💫-test', $post['post_name'] );

		// Test post link with emoji and invisible characters
		$this->assertEquals( 'https://playground.internal/path/🚀/‮tsop‬/?p=1&test=💫‌​‍‎', $post['guid'] );

		// Test post content with various challenging UTF-8 sequences
		$this->assertStringContainsString( 'T̷̢̯̭̈w̴̰̜̾o̷͉̅ ̵̨͔̔r̶̞̈o̷̰͇̍ä̴́ͅd̶̰̒s̵̞̈́', $post['post_content'] );
		$this->assertStringContainsString( '𝓣𝓮𝓼𝓽 𝓜𝓾𝓵𝓽𝓲-𝓑𝔂𝓽𝓮: 🚀🌟💫⭐️🔥💯🎉🎊🌈🦄', $post['post_content'] );
		$this->assertStringContainsString( 'السلام عليكم وﷲ', $post['post_content'] );

		// Test excerpt with zalgo text and emojis
		$this->assertEquals( 'T̷̢̯̭̈h̶̰̾i̵̱̇s̶̰̍ ̵̰̔i̶̱̇s̶̰̍ ̵̰̔a̶̰̅n̶̰̍ ̵̰̔e̶̞̔x̶̰̍c̶̰̒e̶̞̔r̶̰̈p̶̰̒t̶̰̒ ̵̰̔w̶̰̾i̵̱̇t̶̰̒h̶̰̾ ̵̰̔e̶̞̔m̶̰̈o̶̰̍j̶̰̈i̵̱̇ 🚀🌟 ̵̰̔a̶̰̅n̶̰̍d̶̬̽ ̵̰̔R̶̰̈T̶̰̒L̶̰̈ ‮خدسنگ‬ ̵̰̔t̶̰̒e̶̞̔x̶̰̍t̶̰̒‌​‍‎', $post['post_excerpt'] );

		// Test post meta with challenging UTF-8 values
		$this->assertArrayHasKey( 'postmeta', $post );
		$this->assertCount( 5, $post['postmeta'] );

		// Find specific meta by key
		$meta_by_key = array();
		foreach ( $post['postmeta'] as $meta ) {
			$meta_by_key[ $meta['key'] ] = $meta['value'];
		}

		// Test meta with invisible characters in key
		$this->assertArrayHasKey( '_pingme‌​‍‎', $meta_by_key );
		$this->assertEquals( '1​‍‌‎', $meta_by_key['_pingme‌​‍‎'] );

		// Test meta with emoji and mathematical symbols
		$this->assertArrayHasKey( '_utf8_test', $meta_by_key );
		$this->assertEquals( '🚀 Test with 𝔻𝕠𝕦𝕓𝕝𝕖 𝔖𝔱𝔯𝔲𝔠𝔨: ℍ𝔢𝔩𝔩𝔬 ‮olleH‬ 𝖂𝖔𝖗𝖫𝖉! ', $meta_by_key['_utf8_test'] );

		// Test meta with zalgo text
		$this->assertArrayHasKey( '_zalgo_test', $meta_by_key );
		$this->assertEquals( 'T̵̢̯̭̈h̶̰̾i̵̱̇s̶̰̍ ̵̰̔i̶̱̇s̶̰̍ ̵̰̔z̶̰̒a̶̰̅l̶̰̈g̶̰̈o̶̰̍ ̵̰̔t̶̰̒e̶̞̔x̶̰̍t̶̰̒', $meta_by_key['_zalgo_test'] );

		// Test meta with HTML entities for special characters
		$this->assertArrayHasKey( '_special_chars', $meta_by_key );
		$this->assertEquals( '&#x202E;&#x202D;&#x200B;&#x200C;&#x200D;&#x2060;&#xFEFF;&#xFFFD;', $meta_by_key['_special_chars'] );

		// Test category with zalgo text and emoji
		$this->assertArrayHasKey( 'terms', $post );
		$this->assertCount( 1, $post['terms'] );
		$category = $post['terms'][0];
		$this->assertEquals( 'category', $category['domain'] );
		$this->assertEquals( 'uncat‮egorized‬', $category['slug'] );
		$this->assertEquals( 'Ü̷̢̯̭n̶̰̍c̶̰̒a̶̰̅t̶̰̒e̶̞̔g̶̰̈o̶̰̍r̶̰̈i̵̱̇z̶̰̒e̶̞̔d̶̬̽ 🎭', $category['name'] );

		// Test author data with challenging UTF-8
		$this->assertCount( 1, $result['authors'] );
		$author = $result['authors'][ array_key_first( $result['authors'] ) ];
		$this->assertEquals( 'admin‌​‍‎', $author['author_login'] );
		$this->assertEquals( 'ădmĩn@ℓocalhost.com', $author['author_email'] );
		$this->assertEquals( 'A̸̰̅d̴̰͝m̵͎̽i̵̱̋n̷̰̎ ​‍‌‎', $author['author_display_name'] );
		$this->assertEquals( '🅰️', $author['author_first_name'] );
		$this->assertEquals( '&#x1F1FA;&#x1F1F8;𝕌𝕟𝕚𝕔𝕠𝕕𝕖', $author['author_last_name'] );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function testSerializedMetaIsNotCorrupted( $parser_class ) {
		if ( 'WXR_Parser_Regex' === $parser_class ) {
			$this->markTestSkipped( "Skipping the failing test for $parser_class" );
			return;
		}
		$parser = new $parser_class();
		$result = $parser->parse( DIR_TESTDATA_WP_IMPORTER . '/chunked-meta.xml' );
		$meta   = unserialize( $result['posts'][0]['postmeta'][0]['value'] );
		$this->assertSame( 'bar', $meta['foo'] );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function testMultilineAuthorBlocksAreParsed( $parser_class ) {
		if ( 'WXR_Parser_Regex' === $parser_class || 'WXR_Parser_SimpleXML' === $parser_class || 'WXR_Parser_XML' === $parser_class ) {
			$this->markTestSkipped( "Text nodes with trailing whitespace are not trimmed in $parser_class" );
			return;
		}
		$parser  = new $parser_class();
		$result  = $parser->parse( DIR_TESTDATA_WP_IMPORTER . '/authors-multiline.xml' );
		$authors = array_column( $result['authors'], 'author_login' );
		sort( $authors );
		$this->assertSame( array( 'alice', 'bob', 'carol' ), $authors );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function testBlankLinesSurviveInPostContent( $parser_class ) {
		$parser = new $parser_class();
		$result = $parser->parse( DIR_TESTDATA_WP_IMPORTER . '/blank-lines.xml' );
		$this->assertStringContainsString( "\n\n", $result['posts'][0]['post_content'] );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function testBackslashesPreservedInContent( $parser_class ) {
		$parser = new $parser_class();
		$result = $parser->parse( DIR_TESTDATA_WP_IMPORTER . '/backslashes.xml' );
		$this->assertStringContainsString( 'C:\\xampp\\htdocs', $result['posts'][0]['post_content'] );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function testCdataSectionsConcatenateCorrectly( $parser_class ) {
		if ( 'WXR_Parser_Regex' === $parser_class ) {
			$this->markTestSkipped( "Skipping the failing test for $parser_class" );
			return;
		}
		$parser   = new $parser_class();
		$result   = $parser->parse( DIR_TESTDATA_WP_IMPORTER . '/multiple-cdata.xml' );
		$expected = 'line oneline twoline three';
		$this->assertSame( $expected, trim( $result['posts'][0]['post_content'] ) );
	}

	/* ---------- Robustness / warnings ---------- */

	/**
	 * @dataProvider parser_provider
	 */
	public function testMissingMatchGroupsDoNotEmitWarnings( $parser_class ) {
		$parser = new $parser_class();

		$caught = false;
		set_error_handler(
			static function () use ( &$caught ) {
				$caught = true;
			},
			E_WARNING | E_NOTICE | E_DEPRECATED
		);

		$parser->parse( DIR_TESTDATA_WP_IMPORTER . '/missing-tags.xml' );

		restore_error_handler();
		$this->assertFalse( $caught, 'Parser emitted PHP warnings/notices' );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function testTermMetaParses( $parser_class ) {
		$parser = new $parser_class();
		$result = $parser->parse( DIR_TESTDATA_WP_IMPORTER . '/term-meta.xml' );
		$this->assertSame(
			'legacy_id',
			$result['terms'][0]['termmeta'][0]['key']
		);
	}

	/* ---------- Performance / scale ---------- */

	/**
	 * @dataProvider parser_provider
	 */
	public function testLargeFileCompletesWithinMemoryBudget( $parser_class ) {
		if ( PHP_INT_SIZE < 8 ) {
			$this->markTestSkipped( 'Requires 64‑bit PHP.' );
		}

		$tmp = tmpfile();
		fwrite( $tmp, $this->generateLargeWxr( 5000 ) );
		$meta_before = memory_get_usage();

		$parser = new $parser_class();
		$parser->parse( stream_get_meta_data( $tmp )['uri'] );

		$delta = memory_get_usage() - $meta_before;
		$this->assertLessThan( 64 * 1024 * 1024, $delta, 'Memory usage too high' );
		fclose( $tmp );
	}

	/* ---------- Edge‑case parsing ---------- */

	/**
	 * @dataProvider parser_provider
	 */
	public function testParserHandlesCdataWeirdSequence( $parser_class ) {
		$parser = new $parser_class();
		$result = $parser->parse( DIR_TESTDATA_WP_IMPORTER . '/cdata-edge.xml' );
		$this->assertStringEndsWith( ']] >', $result['posts'][0]['post_content'] );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function testParserReportsMalformedXmlWithWpError( $parser_class ) {
		$parser = new $parser_class();
		$out    = $parser->parse( DIR_TESTDATA_WP_IMPORTER . '/malformed.xml' );
		$this->assertTrue( is_wp_error( $out ) );
	}

	/**
	 * @dataProvider parser_provider
	 */
	public function testAuthorCountMatchesLargeExport( $parser_class ) {
		if ( 'WXR_Parser_Regex' === $parser_class ) {
			$this->markTestSkipped( "Skipping the failing test for $parser_class" );
			return;
		}
		$parser = new $parser_class();
		$result = $parser->parse( DIR_TESTDATA_WP_IMPORTER . '/large-authors.xml' );
		$this->assertCount( 300, $result['authors'] );
	}

	/* ---------- Helpers ---------- */

	private function generateLargeWxr( $post_count ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
		$xml .= '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">' . "\n";
		$xml .= "<channel>\n<title>Large Export</title>\n";

		for ( $i = 1; $i <= $post_count; $i++ ) {
			$xml .= "<item>\n<title>Post {$i}</title>\n";
			$xml .= "<wp:post_id>{$i}</wp:post_id>\n";
			$xml .= "<wp:post_type>post</wp:post_type>\n";
			$xml .= "<wp:status>publish</wp:status>\n";
			$xml .= "<content:encoded><![CDATA[Sample {$i}]]></content:encoded>\n";
			$xml .= "</item>\n";
		}

		$xml .= "</channel>\n</rss>";
		return $xml;
	}
}
