<?php

require_once __DIR__ . '/base.php';

/**
 * @group import
 * @group post-meta
 */
class Tests_Import_Postmeta extends WP_Import_UnitTestCase {
	public function set_up() {
		parent::set_up();

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}

	}

	public function test_serialized_postmeta_no_cdata() {
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/test-serialized-postmeta-no-cdata.xml', array( 'johncoswell' => 'john' ) );
		$expected['special_post_title'] = 'A special title';
		$expected['is_calendar']        = '';
		$this->assertSame( $expected, get_post_meta( 122, 'post-options', true ) );
	}

	public function test_utw_postmeta() {
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/test-utw-post-meta-import.xml', array( 'johncoswell' => 'john' ) );

		$classy      = new StdClass();
		$classy->tag = 'album';
		$expected[]  = $classy;
		$classy      = new StdClass();
		$classy->tag = 'apple';
		$expected[]  = $classy;
		$classy      = new StdClass();
		$classy->tag = 'art';
		$expected[]  = $classy;
		$classy      = new StdClass();
		$classy->tag = 'artwork';
		$expected[]  = $classy;
		$classy      = new StdClass();
		$classy->tag = 'dead-tracks';
		$expected[]  = $classy;
		$classy      = new StdClass();
		$classy->tag = 'ipod';
		$expected[]  = $classy;
		$classy      = new StdClass();
		$classy->tag = 'itunes';
		$expected[]  = $classy;
		$classy      = new StdClass();
		$classy->tag = 'javascript';
		$expected[]  = $classy;
		$classy      = new StdClass();
		$classy->tag = 'lyrics';
		$expected[]  = $classy;
		$classy      = new StdClass();
		$classy->tag = 'script';
		$expected[]  = $classy;
		$classy      = new StdClass();
		$classy->tag = 'tracks';
		$expected[]  = $classy;
		$classy      = new StdClass();
		$classy->tag = 'windows-scripting-host';
		$expected[]  = $classy;
		$classy      = new StdClass();
		$classy->tag = 'wscript';
		$expected[]  = $classy;

		$this->assertEquals( $expected, get_post_meta( 150, 'test', true ) );
	}

	/**
	 * @ticket 9633
	 */
	public function test_serialized_postmeta_with_cdata() {
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/test-serialized-postmeta-with-cdata.xml', array( 'johncoswell' => 'johncoswell' ) );

		// HTML in the CDATA should work with old WordPress version.
		$this->assertSame( '<pre>some html</pre>', get_post_meta( 10, 'contains-html', true ) );
		// Serialised will only work with 3.0 onwards.
		$expected['special_post_title'] = 'A special title';
		$expected['is_calendar']        = '';
		$this->assertSame( $expected, get_post_meta( 10, 'post-options', true ) );
	}

	/**
	 * @ticket 11574
	 */
	public function test_serialized_postmeta_with_evil_stuff_in_cdata() {
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/test-serialized-postmeta-with-cdata.xml', array( 'johncoswell' => 'johncoswell' ) );
		// Evil content in the CDATA.
		$this->assertSame( '<wp:meta_value>evil</wp:meta_value>', get_post_meta( 10, 'evil', true ) );
	}

	/**
	 * @ticket 11574
	 */
	public function test_serialized_postmeta_with_slashes() {
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/test-serialized-postmeta-with-cdata.xml', array( 'johncoswell' => 'johncoswell' ) );

		$expected_integer      = '1';
		$expected_string       = '¯\_(ツ)_/¯';
		$expected_array        = array( 'key' => '¯\_(ツ)_/¯' );
		$expected_array_nested = array(
			'key' => array(
				'foo' => '¯\_(ツ)_/¯',
				'bar' => '\o/',
			),
		);

		$this->assertSame( $expected_string, get_post_meta( 10, 'string', true ) );
		$this->assertSame( $expected_array, get_post_meta( 10, 'array', true ) );
		$this->assertSame( $expected_array_nested, get_post_meta( 10, 'array-nested', true ) );
		$this->assertSame( $expected_integer, get_post_meta( 10, 'integer', true ) );
	}
}
