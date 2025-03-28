<?php

require_once __DIR__ . '/base.php';

/**
 * @group import
 */
class Tests_Import_Import extends WP_Import_UnitTestCase {
	public function set_up() {
		parent::set_up();

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}

		add_filter( 'import_allow_create_users', '__return_true' );

		global $wpdb;
		// Crude but effective: make sure there's no residual data in the main tables.
		foreach ( array( 'posts', 'postmeta', 'comments', 'terms', 'term_taxonomy', 'term_relationships', 'users', 'usermeta' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->$table}" );
		}
	}

	public function tear_down() {
		remove_filter( 'import_allow_create_users', '__return_true' );

		parent::tear_down();
	}

	/**
	 * @covers WP_Import::import
	 */
	public function test_small_import() {
		global $wpdb;

		$authors = array(
			'admin'  => false,
			'editor' => false,
			'author' => false,
		);
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/small-export.xml', $authors );

		// Ensure that authors were imported correctly.
		$user_count = count_users();
		$this->assertSame( 3, $user_count['total_users'] );
		$admin = get_user_by( 'login', 'admin' );
		$this->assertSame( 'admin', $admin->user_login );
		$this->assertSame( 'local@host.null', $admin->user_email );
		$editor = get_user_by( 'login', 'editor' );
		$this->assertSame( 'editor', $editor->user_login );
		$this->assertSame( 'editor@example.org', $editor->user_email );
		$this->assertSame( 'FirstName', $editor->user_firstname );
		$this->assertSame( 'LastName', $editor->user_lastname );
		$author = get_user_by( 'login', 'author' );
		$this->assertSame( 'author', $author->user_login );
		$this->assertSame( 'author@example.org', $author->user_email );

		// Check that terms were imported correctly.
		$this->assertSame( '30', wp_count_terms( array( 'taxonomy' => 'category' ) ) );
		$this->assertSame( '3', wp_count_terms( array( 'taxonomy' => 'post_tag' ) ) );
		$foo = get_term_by( 'slug', 'foo', 'category' );
		$this->assertSame( 0, $foo->parent );
		$bar     = get_term_by( 'slug', 'bar', 'category' );
		$foo_bar = get_term_by( 'slug', 'foo-bar', 'category' );
		$this->assertSame( $bar->term_id, $foo_bar->parent );

		// Check that posts/pages were imported correctly.
		$post_count = wp_count_posts( 'post' );
		$this->assertSame( '5', $post_count->publish );
		$this->assertSame( '1', $post_count->private );
		$page_count = wp_count_posts( 'page' );
		$this->assertSame( '4', $page_count->publish );
		$this->assertSame( '1', $page_count->draft );
		$comment_count = wp_count_comments();
		$this->assertSame( 1, $comment_count->total_comments );

		$posts = get_posts(
			array(
				'numberposts' => 20,
				'post_type'   => 'any',
				'post_status' => 'any',
				'orderby'     => 'ID',
			)
		);
		$this->assertCount( 11, $posts );

		$post = $posts[0];
		$this->assertSame( 'Many Categories', $post->post_title );
		$this->assertSame( 'many-categories', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$cats = wp_get_post_categories( $post->ID );
		$this->assertCount( 27, $cats );

		$post = $posts[1];
		$this->assertSame( 'Non-standard post format', $post->post_title );
		$this->assertSame( 'non-standard-post-format', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$cats = wp_get_post_categories( $post->ID );
		$this->assertCount( 1, $cats );
		$this->assertTrue( has_post_format( 'aside', $post->ID ) );

		$post = $posts[2];
		$this->assertSame( 'Top-level Foo', $post->post_title );
		$this->assertSame( 'top-level-foo', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$cats = wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) );
		$this->assertCount( 1, $cats );
		$this->assertSame( 'foo', $cats[0]->slug );

		$post = $posts[3];
		$this->assertSame( 'Foo-child', $post->post_title );
		$this->assertSame( 'foo-child', $post->post_name );
		$this->assertSame( (string) $editor->ID, $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$cats = wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) );
		$this->assertCount( 1, $cats );
		$this->assertSame( 'foo-bar', $cats[0]->slug );

		$post = $posts[4];
		$this->assertSame( 'Private Post', $post->post_title );
		$this->assertSame( 'private-post', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'private', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$cats = wp_get_post_categories( $post->ID );
		$this->assertCount( 1, $cats );
		$tags = wp_get_post_tags( $post->ID );
		$this->assertCount( 3, $tags );
		$this->assertSame( 'tag1', $tags[0]->slug );
		$this->assertSame( 'tag2', $tags[1]->slug );
		$this->assertSame( 'tag3', $tags[2]->slug );

		$post = $posts[5];
		$this->assertSame( '1-col page', $post->post_title );
		$this->assertSame( '1-col-page', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$this->assertSame( 'onecolumn-page.php', get_post_meta( $post->ID, '_wp_page_template', true ) );

		$post = $posts[6];
		$this->assertSame( 'Draft Page', $post->post_title );
		$this->assertSame( '', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$this->assertSame( 'default', get_post_meta( $post->ID, '_wp_page_template', true ) );

		$post = $posts[7];
		$this->assertSame( 'Parent Page', $post->post_title );
		$this->assertSame( 'parent-page', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$this->assertSame( 'default', get_post_meta( $post->ID, '_wp_page_template', true ) );

		$post = $posts[8];
		$this->assertSame( 'Child Page', $post->post_title );
		$this->assertSame( 'child-page', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( $posts[7]->ID, $post->post_parent );
		$this->assertSame( 'default', get_post_meta( $post->ID, '_wp_page_template', true ) );

		$post = $posts[9];
		$this->assertSame( 'Sample Page', $post->post_title );
		$this->assertSame( 'sample-page', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$this->assertSame( 'default', get_post_meta( $post->ID, '_wp_page_template', true ) );

		$post = $posts[10];
		$this->assertSame( 'Hello world!', $post->post_title );
		$this->assertSame( 'hello-world', $post->post_name );
		$this->assertSame( (string) $author->ID, $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$cats = wp_get_post_categories( $post->ID );
		$this->assertCount( 1, $cats );
	}

	/**
	 * @covers WP_Import::import
	 */
	public function test_double_import() {
		$authors = array(
			'admin'  => false,
			'editor' => false,
			'author' => false,
		);
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/small-export.xml', $authors );
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/small-export.xml', $authors );

		$user_count = count_users();
		$this->assertSame( 3, $user_count['total_users'] );
		$admin = get_user_by( 'login', 'admin' );
		$this->assertSame( 'admin', $admin->user_login );
		$this->assertSame( 'local@host.null', $admin->user_email );
		$editor = get_user_by( 'login', 'editor' );
		$this->assertSame( 'editor', $editor->user_login );
		$this->assertSame( 'editor@example.org', $editor->user_email );
		$this->assertSame( 'FirstName', $editor->user_firstname );
		$this->assertSame( 'LastName', $editor->user_lastname );
		$author = get_user_by( 'login', 'author' );
		$this->assertSame( 'author', $author->user_login );
		$this->assertSame( 'author@example.org', $author->user_email );

		$this->assertSame( '30', wp_count_terms( array( 'taxonomy' => 'category' ) ) );
		$this->assertSame( '3', wp_count_terms( array( 'taxonomy' => 'post_tag' ) ) );
		$foo = get_term_by( 'slug', 'foo', 'category' );
		$this->assertSame( 0, $foo->parent );
		$bar     = get_term_by( 'slug', 'bar', 'category' );
		$foo_bar = get_term_by( 'slug', 'foo-bar', 'category' );
		$this->assertSame( $bar->term_id, $foo_bar->parent );

		$post_count = wp_count_posts( 'post' );
		$this->assertSame( '5', $post_count->publish );
		$this->assertSame( '1', $post_count->private );
		$page_count = wp_count_posts( 'page' );
		$this->assertSame( '4', $page_count->publish );
		$this->assertSame( '1', $page_count->draft );
		$comment_count = wp_count_comments();
		$this->assertSame( 1, $comment_count->total_comments );
	}

	/**
	 * @ticket 21007
	 *
	 * @covers WP_Import::import
	 */
	public function test_slashes_should_not_be_stripped() {
		global $wpdb;

		$authors = array( 'admin' => false );
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/slashes.xml', $authors );

		$alpha = get_term_by( 'slug', 'alpha', 'category' );
		$this->assertSame( 'a \"great\" category', $alpha->name );

		$tag1 = get_term_by( 'slug', 'tag1', 'post_tag' );
		$this->assertSame( "foo\'bar", $tag1->name );

		$posts = get_posts(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
			)
		);
		$this->assertNotEmpty( $posts );
		$this->assertSame( 'Slashes aren\\\'t \"cool\"', $posts[0]->post_content );

		$comments = get_comments(
			array(
				'post_id' => $posts[0]->post_ID,
			)
		);
		$this->assertNotEmpty( $comments );
		$this->assertSame( '\o/ ¯\_(ツ)_/¯', $comments[0]->comment_content );
	}

	/**
	 * Ensure no PHP 8.1 deprecation notice is thrown when a URL is passed without a path component.
	 *
	 * Note: this test doesn't test anything else of the functionality in the `WP_Import::fetch_remote_file()` method!
	 */
	public function test_fetch_remote_file_php81_deprecation() {
		$importer = new WP_Import();
		$result   = $importer->fetch_remote_file( 'https://example.com', array() );

		$this->assertWPError( $result, 'Call to fetch_remote_file() did not return expected WP Error object' );
		$this->assertSame(
			'Sorry, this file type is not permitted for security reasons.',
			$result->get_error_message(),
			'The WP Error object did not contain the expected error'
		);
	}
}
