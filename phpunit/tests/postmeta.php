<?php
/**
 * WordPress Importer postmeta import tests.
 *
 * @package WordPress
 * @subpackage Importer
 */

// Include base testcase.
require_once dirname( __FILE__ ) . '/base.php';

/**
 * WordPress Importer postmeta import tests.
 *
 * @group import
 */
class Tests_Import_Postmeta extends WP_Import_UnitTestCase {
	/**
	 * Sets up the fixture, for example, open a network connection.
	 *
	 * This method is called before a test is executed.
	 */
	public function setUp() {
		parent::setUp();

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}
	}

	/**
	 * Test serialized postmeta with no CDATA.
	 */
	public function test_serialized_postmeta_no_cdata() {
		$this->import_wp( DIR_TESTDATA_WP_IMPORTER . '/test-serialized-postmeta-no-cdata.xml', array( 'johncoswell' => 'john' ) );
		$expected['special_post_title'] = 'A special title';
		$expected['is_calendar']        = '';
		$this->assertEquals( $expected, get_post_meta( 122, 'post-options', true ) );
	}

	/**
	 * Test with Ultimate Tag Warrior (plugin) postmeta data.
	 */
	public function test_utw_postmeta() {
		$this->import_wp( DIR_TESTDATA_WP_IMPORTER . '/test-utw-post-meta-import.xml', array( 'johncoswell' => 'john' ) );

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
	 * Test serialized postmeta with CDATA.
	 *
	 * @ticket 9633
	 */
	public function test_serialized_postmeta_with_cdata() {
		$this->import_wp( DIR_TESTDATA_WP_IMPORTER . '/test-serialized-postmeta-with-cdata.xml', array( 'johncoswell' => 'johncoswell' ) );

		// HTML in the CDATA should work with old WordPress version.
		$this->assertEquals( '<pre>some html</pre>', get_post_meta( 10, 'contains-html', true ) );
		// Serialised will only work with 3.0 onwards.
		$expected['special_post_title'] = 'A special title';
		$expected['is_calendar']        = '';
		$this->assertEquals( $expected, get_post_meta( 10, 'post-options', true ) );
	}

	/**
	 * Test serialized post with with evil content in CDATA.
	 *
	 * @ticket 11574
	 */
	public function test_serialized_postmeta_with_evil_stuff_in_cdata() {
		$this->import_wp( DIR_TESTDATA_WP_IMPORTER . '/test-serialized-postmeta-with-cdata.xml', array( 'johncoswell' => 'johncoswell' ) );
		// Evil content in the CDATA.
		$this->assertEquals( '<wp:meta_value>evil</wp:meta_value>', get_post_meta( 10, 'evil', true ) );
	}
}
