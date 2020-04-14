<?php // phpcs:ignore WordPress.Files.FileName

require_once dirname( __FILE__ ) . '/base.php';

/**
 * @group import
 * @group comment-meta
 */
class Tests_Import_Comment_Meta extends WP_Import_UnitTestCase {
	function setUp() {
		parent::setUp();

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}
	}

	function test_serialized_comment_meta() {
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/test-serialized-comment-meta.xml', array( 'admin' => 'admin' ) );

		$expected_string = '¯\_(ツ)_/¯';
		$expected_array  = array( 'key' => '¯\_(ツ)_/¯' );

		$comments_count = wp_count_comments();
		$this->assertEquals( 1, $comments_count->approved );

		$comments = get_comments();
		$this->assertEquals( 1, count( $comments ) );

		$comment = $comments[0];
		$this->assertEquals( $expected_string, get_comment_meta( $comment->comment_ID, 'string', true ) );
		$this->assertEquals( $expected_array, get_comment_meta( $comment->comment_ID, 'array', true ) );
	}
}
