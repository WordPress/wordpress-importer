<?php

require_once __DIR__ . '/base.php';

/**
 * @group import
 * @group idempotency
 */
class Tests_Import_Idempotency extends WP_Import_UnitTestCase {

	public function set_up() {
		parent::set_up();

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}

		add_filter( 'import_allow_create_users', '__return_true' );
		add_filter( 'import_allow_fetch_attachments', '__return_true' );

		global $wpdb;
		// Clean database before each test
		foreach ( array( 'posts', 'postmeta', 'comments', 'terms', 'term_taxonomy', 'term_relationships', 'users', 'usermeta' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->$table}" );
		}
	}

	public function tear_down() {
		remove_filter( 'import_allow_create_users', '__return_true' );
		remove_filter( 'import_allow_fetch_attachments', '__return_true' );

		parent::tear_down();
	}

	/**
	 * Test that imports are idempotent when attachments already exist.
	 *
	 * This test reproduces the bug where:
	 * 1. First import creates some attachments
	 * 2. Second import skips existing attachments but fails to populate url_remap
	 * 3. Result: Post URLs are not remapped for existing attachments
	 *
	 * With the fix, url_remap is populated even for existing attachments.
	 *
	 * @covers WP_Import::import
	 */
	public function test_idempotent_import_with_existing_attachments() {
		$authors = array( 'admin' => false );

		// Step 1: Import partial file (simulates interrupted import)
		// This creates the first attachment but no posts
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/idempotency-partial.xml', $authors, true );

		// Verify partial state: 1 attachment, 0 posts
		$attachments_after_partial = get_posts(
			array(
				'post_type'   => 'attachment',
				'numberposts' => -1,
				'post_status' => 'inherit',
			)
		);
		$posts_after_partial       = get_posts(
			array(
				'post_type'   => 'post',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);

		$this->assertCount( 1, $attachments_after_partial, 'Should have 1 attachment after partial import' );
		$this->assertCount( 0, $posts_after_partial, 'Should have 0 posts after partial import' );

		// Verify first attachment exists and has the expected title
		$first_attachment = $attachments_after_partial[0];
		$this->assertSame( 'image-1', $first_attachment->post_title, 'First attachment should be image-1' );

		// Step 2: Import complete file (simulates recovery/completion)
		// This should create all attachments and posts, with proper URL remapping
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/idempotency-complete.xml', $authors, true );

		// Verify final state: 3 attachments, 3 posts
		$attachments_after_complete = get_posts(
			array(
				'post_type'   => 'attachment',
				'numberposts' => -1,
				'post_status' => 'inherit',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
		$posts_after_complete       = get_posts(
			array(
				'post_type'   => 'post',
				'numberposts' => -1,
				'post_status' => 'any',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);

		$this->assertCount( 3, $attachments_after_complete, 'Should have 3 attachments after complete import' );
		$this->assertCount( 3, $posts_after_complete, 'Should have 3 posts after complete import' );

		// Step 3: Verify URL remapping for all posts
		// This is the critical test - all posts should have local URLs, not external ones
		foreach ( $posts_after_complete as $i => $post ) {
			$post_number = $i + 1;

			// Check that post content does not contain external URLs
			$has_external_urls = strpos( $post->post_content, 'yavuzceliker.github.io' ) !== false;
			$this->assertFalse(
				$has_external_urls,
				"Post {$post_number} should not contain external URLs (idempotency bug)"
			);

			// Check that post content contains local URLs
			$has_local_urls = strpos( $post->post_content, 'wp-content/uploads/' ) !== false;
			$this->assertTrue(
				$has_local_urls,
				"Post {$post_number} should contain local URLs"
			);
		}

		// Step 4: Specific test for the bug case
		// Post 1 references attachment 1 (which existed before complete import)
		// This is where the bug occurred - the URL should still be remapped
		$post_1 = $posts_after_complete[0];
		$this->assertSame( 'Post 1 with Image 1', $post_1->post_title, 'Post 1 should have correct title' );

		// Extract image URLs from post content
		preg_match_all( '/src="([^"]*)"/', $post_1->post_content, $matches );
		$image_urls = $matches[1];

		$this->assertNotEmpty( $image_urls, 'Post 1 should contain image URLs' );

		// All image URLs should be local (not external)
		foreach ( $image_urls as $url ) {
			$this->assertStringNotContainsString(
				'yavuzceliker.github.io',
				$url,
				'Post 1 image URL should not be external (this was the idempotency bug)'
			);
			$this->assertStringContainsString(
				'wp-content/uploads/',
				$url,
				'Post 1 image URL should be local'
			);
		}

		// Verify attachment parent relationships
		$this->verify_attachment_parent_relationships( $attachments_after_complete, $posts_after_complete );
	}

	/**
	 * Test that multiple complete imports are idempotent.
	 *
	 * @covers WP_Import::import
	 */
	public function test_multiple_complete_imports_are_idempotent() {
		$authors = array( 'admin' => false );

		// Import the same file twice
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/idempotency-complete.xml', $authors, true );
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/idempotency-complete.xml', $authors, true );

		// Should still have correct counts (not duplicated)
		$attachments = get_posts(
			array(
				'post_type'   => 'attachment',
				'numberposts' => -1,
				'post_status' => 'inherit',
			)
		);
		$posts       = get_posts(
			array(
				'post_type'   => 'post',
				'numberposts' => -1,
				'post_status' => 'any',
			)
		);

		$this->assertCount( 3, $attachments, 'Should still have 3 attachments after duplicate import' );
		$this->assertCount( 3, $posts, 'Should still have 3 posts after duplicate import' );

		// All URLs should still be properly remapped
		foreach ( $posts as $post ) {
			$has_external_urls = strpos( $post->post_content, 'yavuzceliker.github.io' ) !== false;
			$this->assertFalse( $has_external_urls, 'Post should not contain external URLs after duplicate import' );
		}

		// Verify attachment parent relationships are maintained
		$this->verify_attachment_parent_relationships( $attachments, $posts );
	}

	/**
	 * Verify that attachment parent relationships are properly maintained.
	 *
	 * @param array $attachments Array of attachment posts
	 * @param array $posts Array of regular posts
	 */
	private function verify_attachment_parent_relationships( $attachments, $posts ) {
		$post_ids         = wp_list_pluck( $posts, 'ID' );
		$unattached_count = 0;

		foreach ( $attachments as $attachment ) {
			$parent_id = $attachment->post_parent;

			if ( 0 === $parent_id ) {
				++$unattached_count;
			} else {
				$this->assertContains(
					$parent_id,
					$post_ids,
					"Attachment '{$attachment->post_title}' has invalid parent ID {$parent_id}"
				);
			}
		}

		// With proper idempotency fix, all attachments should have valid parents
		// (assuming the test data includes post_parent values)
		if ( count( $attachments ) > 0 ) {
			$this->assertLessThan(
				count( $attachments ),
				$unattached_count,
				'Too many attachments are unattached - parent relationships may not be preserved'
			);
		}
	}
}
