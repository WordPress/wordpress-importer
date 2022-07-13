<?php

require_once dirname( __FILE__ ) . '/base.php';

/**
 * @group import
 * @group term-meta
 */
class Tests_Import_Term_Meta extends WP_Import_UnitTestCase {
	function set_up() {
		parent::set_up();

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}
	}

	function test_serialized_term_meta() {
		register_taxonomy( 'custom_taxonomy', array( 'post' ) );

		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/test-serialized-term-meta.xml', array( 'admin' => 'admin' ) );

		$expected_string = '¯\_(ツ)_/¯';
		$expected_array  = array( 'key' => '¯\_(ツ)_/¯' );

		$term = get_term_by( 'slug', 'post_tag', 'post_tag' );
		$this->assertInstanceOf( 'WP_Term', $term );
		$this->assertSame( $expected_string, get_term_meta( $term->term_id, 'string', true ) );
		$this->assertSame( $expected_array, get_term_meta( $term->term_id, 'array', true ) );

		$term = get_term_by( 'slug', 'category', 'category' );
		$this->assertInstanceOf( 'WP_Term', $term );
		$this->assertSame( $expected_string, get_term_meta( $term->term_id, 'string', true ) );
		$this->assertSame( $expected_array, get_term_meta( $term->term_id, 'array', true ) );

		$term = get_term_by( 'slug', 'custom_taxonomy', 'custom_taxonomy' );
		$this->assertInstanceOf( 'WP_Term', $term );
		$this->assertSame( $expected_string, get_term_meta( $term->term_id, 'string', true ) );
		$this->assertSame( $expected_array, get_term_meta( $term->term_id, 'array', true ) );
	}
}
