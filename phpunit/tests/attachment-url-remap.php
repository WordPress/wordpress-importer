<?php

require_once __DIR__ . '/base.php';

/**
 * Tests remapping of attachment URLs in imported post content.
 *
 * @group import
 *
 * @see https://github.com/WordPress/wordpress-importer/issues/276
 */
class Tests_Import_Attachment_Url_Remap extends WP_Import_UnitTestCase {
	// Other tests import into 2008/06 with flat folders. wp_upload_dir() caches per date, so keep this date apart.
	const SOURCE_BASE = 'https://source.example';
	const SOURCE_DIR  = 'https://source.example/wp-content/uploads/2013/05';

	/**
	 * Remote URL => body, served by the mocked HTTP request.
	 *
	 * @var array
	 */
	protected $remote_files = array();

	protected $previous_uploads_structure;

	public function set_up() {
		parent::set_up();

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}

		add_filter( 'import_allow_create_users', '__return_true' );
		add_filter( 'pre_http_request', array( $this, 'filter_mock_remote_file' ), 10, 3 );

		global $wpdb;
		foreach ( array( 'posts', 'postmeta', 'comments', 'terms', 'term_taxonomy', 'term_relationships', 'users', 'usermeta' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->$table}" );
		}

		// The source site and the target site both store uploads in year/month folders.
		$this->previous_uploads_structure = get_option( 'uploads_use_yearmonth_folders' );
		update_option( 'uploads_use_yearmonth_folders', 1 );
		// wp_upload_dir() caches per upload date, so drop any flat-folder entry from earlier tests.
		wp_upload_dir( '2013/05', true, true );
	}

	public function tear_down() {
		remove_filter( 'import_allow_create_users', '__return_true' );
		remove_filter( 'pre_http_request', array( $this, 'filter_mock_remote_file' ), 10 );
		$this->remote_files = array();

		// Delete imported files so a re-imported name does not get a "-2" suffix.
		$this->remove_added_uploads();

		update_option( 'uploads_use_yearmonth_folders', $this->previous_uploads_structure );
		wp_upload_dir( '2013/05', true, true );

		parent::tear_down();
	}

	public static function data_rewrite_urls() {
		return array(
			'With URL rewriting'    => array( true ),
			'Without URL rewriting' => array( false ),
		);
	}

	/**
	 * The scenario from the issue: a big image is exported as photo-scaled.jpg, while post
	 * content embeds its sub-sizes, which are named after the original photo.jpg.
	 *
	 * @dataProvider data_rewrite_urls
	 */
	public function test_scaled_image_sub_size_urls_follow_the_imported_file( $rewrite_urls ) {
		$this->import_items(
			array(
				$this->attachment( 611, 'photo-scaled.jpg' ),
				$this->post(
					555,
					'<a href="{src}/photo-scaled.jpg"><img src="{src}/photo-1024x500.jpg" /></a>' .
					'<img src="{src}/photo-300x146.jpg" />' .
					'<a href="{src}/photo.jpg">original</a>'
				),
			),
			$rewrite_urls
		);

		$new = $this->new_stub( 'photo-scaled.jpg' );
		if ( $this->wp_appends_number_to_scaled_names() ) {
			$this->assertSame( $this->target_dir() . '/photo-scaled-1', $new, 'WordPress no longer renames -scaled files on upload.' );
		}

		$this->assert_urls(
			array( "{$new}.jpg", "{$new}-1024x500.jpg", "{$new}-300x146.jpg", "{$new}.jpg" ),
			$this->imported_content( 555 )
		);
	}

	/**
	 * Only exact sub-size file names are remapped. Other files whose names start like the
	 * image, and sizes the target site did not create, keep their file names.
	 *
	 * @dataProvider data_rewrite_urls
	 */
	public function test_files_sharing_the_image_name_are_left_alone( $rewrite_urls ) {
		$existing = self::factory()->post->create( array( 'post_content' => '<img src="' . self::SOURCE_DIR . '/photo-booth.jpg" />' ) );

		$this->import_items(
			array(
				$this->attachment( 611, 'photo-scaled.jpg' ),
				$this->post(
					555,
					'<img src="{src}/photo-1024x500.jpg" />' .
					'<a href="{src}/photo-license.pdf">License</a>' .
					'<img src="{src}/photo-booth.jpg" />' .
					'<a href="{src}/photo-scaled-license.pdf">License</a>' .
					'<img src="{src}/photography.jpg" />' .
					'<img src="{src}/photo-999x999.jpg" />'
				),
			),
			$rewrite_urls
		);

		$new  = $this->new_stub( 'photo-scaled.jpg' );
		$urls = $this->urls( $this->imported_content( 555 ) );

		$this->assertSame( "{$new}-1024x500.jpg", array_shift( $urls ) );
		foreach ( array( 'photo-license.pdf', 'photo-booth.jpg', 'photo-scaled-license.pdf', 'photography.jpg', 'photo-999x999.jpg' ) as $file ) {
			// Only the domain may change, when URL rewriting runs (WordPress 6.7+, PHP 7.4+).
			$this->assertContains( array_shift( $urls ), array( self::SOURCE_DIR . "/{$file}", $this->target_dir() . "/{$file}" ), "{$file} was changed." );
		}

		$this->assert_urls( array( self::SOURCE_DIR . '/photo-booth.jpg' ), get_post( $existing )->post_content );
	}

	/**
	 * A file that is its own attachment keeps its URL, even when its name looks like a sub-size
	 * of another imported image. On the source site the name can only belong to one of them.
	 *
	 * @dataProvider data_rewrite_urls
	 */
	public function test_attachment_named_like_a_sub_size_keeps_its_url( $rewrite_urls ) {
		$this->import_items(
			array(
				$this->attachment( 610, 'photo-1024x500.jpg', 800, 400 ),
				$this->attachment( 611, 'photo-scaled.jpg' ),
				$this->post( 555, '<img src="{src}/photo-1024x500.jpg" /><img src="{src}/photo-300x146.jpg" />' ),
			),
			$rewrite_urls
		);

		$own    = $this->new_stub( 'photo-1024x500.jpg' );
		$scaled = $this->new_stub( 'photo-scaled.jpg' );
		$this->assert_urls( array( "{$own}.jpg", "{$scaled}-300x146.jpg" ), $this->imported_content( 555 ) );
	}

	/**
	 * Images rotated from their EXIF data are stored as photo-rotated.jpg, while their
	 * sub-sizes are named after the original photo.jpg, like scaled images.
	 *
	 * @dataProvider data_rewrite_urls
	 */
	public function test_rotated_image_sub_size_urls_follow_the_imported_file( $rewrite_urls ) {
		$this->import_items(
			array(
				$this->attachment( 611, 'photo-rotated.jpg', 1200, 1600 ),
				$this->post( 555, '<a href="{src}/photo-rotated.jpg"><img src="{src}/photo-768x1024.jpg" /></a>' ),
			),
			$rewrite_urls
		);

		$new = $this->new_stub( 'photo-rotated.jpg' );
		$this->assert_urls( array( "{$new}.jpg", "{$new}-768x1024.jpg" ), $this->imported_content( 555 ) );
	}

	/**
	 * Blocks such as Cover keep the image URL in their attributes too. URL rewriting stores
	 * those with escaped slashes, so both forms must follow the imported file.
	 *
	 * @dataProvider data_rewrite_urls
	 */
	public function test_image_urls_in_block_attributes_are_remapped( $rewrite_urls ) {
		// Import as an admin, as in real life: kses would re-serialize the block attributes otherwise.
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );

		$this->import_items(
			array(
				$this->attachment( 611, 'photo-scaled.jpg' ),
				$this->post(
					555,
					'<!-- wp:cover {"url":"{src}/photo-1024x500.jpg","id":611} --><div class="wp-block-cover"><img class="wp-block-cover__image-background wp-image-611" src="{src}/photo-1024x500.jpg"/></div><!-- /wp:cover -->' .
					'<!-- wp:media-text {"mediaId":611,"mediaLink":"{src}/photo-scaled.jpg","mediaType":"image","mediaUrl":"{src}/photo-scaled.jpg"} --><div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="{src}/photo-scaled.jpg"/></figure></div><!-- /wp:media-text -->'
				),
			),
			$rewrite_urls
		);

		$new    = $this->new_stub( 'photo-scaled.jpg' );
		$blocks = parse_blocks( $this->imported_content( 555 ) );

		$this->assertSame( "{$new}-1024x500.jpg", $blocks[0]['attrs']['url'] );
		$this->assert_urls( array( "{$new}-1024x500.jpg" ), $blocks[0]['innerHTML'] );
		$this->assertSame( "{$new}.jpg", $blocks[1]['attrs']['mediaUrl'] );
		$this->assertSame( "{$new}.jpg", $blocks[1]['attrs']['mediaLink'] );
		$this->assert_urls( array( "{$new}.jpg" ), $blocks[1]['innerHTML'] );
	}

	/**
	 * A file renamed because its name is taken on the target site must be remapped once:
	 * canola-1.jpg, not canola-1-1.jpg.
	 *
	 * @dataProvider data_rewrite_urls
	 */
	public function test_renamed_image_is_remapped_exactly_once( $rewrite_urls ) {
		$this->create_upload( 'canola.jpg' );

		$this->import_items(
			array(
				$this->attachment( 611, 'canola.jpg', 1200, 800 ),
				$this->post( 555, '<a href="{src}/canola.jpg"><img src="{src}/canola-300x200.jpg" /></a>' ),
			),
			$rewrite_urls
		);

		$new = $this->new_stub( 'canola.jpg' );
		$this->assertSame( $this->target_dir() . '/canola-1', $new );
		$this->assert_urls( array( "{$new}.jpg", "{$new}-300x200.jpg" ), $this->imported_content( 555 ) );
	}

	/**
	 * @dataProvider data_rewrite_urls
	 */
	public function test_scaled_image_renamed_again_on_name_collision( $rewrite_urls ) {
		$this->create_upload( 'photo-scaled-1.jpg' );

		$this->import_items(
			array(
				$this->attachment( 611, 'photo-scaled.jpg' ),
				$this->post( 555, '<a href="{src}/photo-scaled.jpg"><img src="{src}/photo-1024x500.jpg" /></a>' ),
			),
			$rewrite_urls
		);

		$new = $this->new_stub( 'photo-scaled.jpg' );
		if ( $this->wp_appends_number_to_scaled_names() ) {
			$this->assertSame( $this->target_dir() . '/photo-scaled-2', $new );
		}
		$this->assert_urls( array( "{$new}.jpg", "{$new}-1024x500.jpg" ), $this->imported_content( 555 ) );
	}

	/**
	 * Imported images whose names start like another imported image keep their own URLs.
	 *
	 * @dataProvider data_rewrite_urls
	 */
	public function test_images_sharing_a_name_prefix_keep_their_own_urls( $rewrite_urls ) {
		$this->import_items(
			array(
				$this->attachment( 611, 'photo-scaled.jpg' ),
				$this->attachment( 612, 'photo-booth.jpg', 1200, 800 ),
				$this->attachment( 613, 'photo-2-scaled.jpg' ),
				$this->post(
					555,
					'<img src="{src}/photo-1024x500.jpg" />' .
					'<img src="{src}/photo-booth.jpg" />' .
					'<img src="{src}/photo-booth-300x200.jpg" />' .
					'<img src="{src}/photo-2-1024x500.jpg" />' .
					'<img src="{src}/photo-2-scaled.jpg" />'
				),
			),
			$rewrite_urls
		);

		$photo   = $this->new_stub( 'photo-scaled.jpg' );
		$booth   = $this->new_stub( 'photo-booth.jpg' );
		$photo_2 = $this->new_stub( 'photo-2-scaled.jpg' );

		$this->assert_urls(
			array( "{$photo}-1024x500.jpg", "{$booth}.jpg", "{$booth}-300x200.jpg", "{$photo_2}-1024x500.jpg", "{$photo_2}.jpg" ),
			$this->imported_content( 555 )
		);
	}

	/**
	 * @dataProvider data_rewrite_urls
	 */
	public function test_image_used_in_several_posts_and_enclosures( $rewrite_urls ) {
		$this->import_items(
			array(
				$this->attachment( 611, 'photo-scaled.jpg' ),
				$this->post( 555, '<img src="{src}/photo-1024x500.jpg" />', '{src}/photo-scaled.jpg' ),
				$this->post( 556, '<img src="{src}/photo-300x146.jpg" /><img src="{src}/photo-1024x500.jpg" />' ),
			),
			$rewrite_urls
		);

		$new = $this->new_stub( 'photo-scaled.jpg' );
		$this->assert_urls( array( "{$new}-1024x500.jpg" ), $this->imported_content( 555 ) );
		$this->assert_urls( array( "{$new}-300x146.jpg", "{$new}-1024x500.jpg" ), $this->imported_content( 556 ) );

		$post_id = $this->imported_post_id( 555 );
		$this->assertSame( array( "{$new}.jpg\n0\nimage/jpeg\n" ), get_post_meta( $post_id, 'enclosure' ) );
	}

	/**
	 * Posts already on the site that link to the source site were remapped before, and still are.
	 * Unrelated posts stay as they were.
	 */
	public function test_existing_posts_are_remapped_and_unrelated_posts_untouched() {
		$linking   = self::factory()->post->create( array( 'post_content' => '<img src="' . self::SOURCE_DIR . '/photo-1024x500.jpg" />' ) );
		$unrelated = self::factory()->post->create( array( 'post_content' => 'Nothing to see, still here.' ) );

		$this->import_items(
			array(
				$this->attachment( 611, 'photo-scaled.jpg' ),
				$this->post( 555, '<img src="{src}/photo-1024x500.jpg" />' ),
			),
			false
		);

		$new = $this->new_stub( 'photo-scaled.jpg' );
		$this->assert_urls( array( "{$new}-1024x500.jpg" ), get_post( $linking )->post_content );
		$this->assertSame( 'Nothing to see, still here.', get_post( $unrelated )->post_content );
	}

	/**
	 * An attachment without a GUID adds an empty key to the URL map, which must not wipe or
	 * corrupt content. "%" must survive the prepared SQL.
	 *
	 * @dataProvider data_rewrite_urls
	 */
	public function test_attachment_without_guid( $rewrite_urls ) {
		$attachment         = $this->attachment( 611, 'photo-scaled.jpg' );
		$attachment['guid'] = '';

		$this->import_items(
			array(
				$attachment,
				$this->post( 555, '100% %s %d Text <img src="{src}/photo-1024x500.jpg" /> more text' ),
			),
			$rewrite_urls
		);

		$new     = $this->new_stub( 'photo-scaled.jpg' );
		$content = $this->imported_content( 555 );
		$this->assert_urls( array( "{$new}-1024x500.jpg" ), $content );
		$this->assertStringStartsWith( '100% %s %d Text <img', $content );
		$this->assertStringEndsWith( '> more text', $content );
	}

	public static function data_concurrent_edit() {
		return array(
			'Post content'                => array( 'post', false ),
			'Enclosure'                   => array( 'enclosure', false ),
			'Post using another folder'   => array( 'post', true ),
			'Enclosure in another folder' => array( 'enclosure', true ),
		);
	}

	/**
	 * An edit saved between reading a row and writing it back must not be lost.
	 *
	 * @dataProvider data_concurrent_edit
	 */
	public function test_edit_saved_during_backfill_is_kept( $type, $another_folder ) {
		global $wpdb;

		$old     = self::SOURCE_DIR . '/photo.jpg';
		$post_id = self::factory()->post->create( array( 'post_content' => '<img src="' . $old . '" />' ) );
		if ( 'post' === $type ) {
			$table   = $wpdb->posts;
			$column  = 'post_content';
			$where   = $wpdb->prepare( 'ID = %d', $post_id );
			$initial = get_post( $post_id )->post_content;
		} else {
			$table   = $wpdb->postmeta;
			$column  = 'meta_value';
			$where   = $wpdb->prepare( 'meta_id = %d', add_post_meta( $post_id, 'enclosure', "{$old}\n0\nimage/jpeg\n" ) );
			$initial = "{$old}\n0\nimage/jpeg\n";
		}

		$edited_url   = $another_folder ? self::SOURCE_BASE . '/wp-content/uploads/2014/06/other.jpg' : $old;
		$target_url   = $another_folder ? $this->target_dir() . '/other.jpg' : $this->target_dir() . '/photo-1.jpg';
		$edited_value = 'Edited. ' . str_replace( $old, $edited_url, $initial );

		$importer            = new WP_Import();
		$importer->url_remap = array(
			$old        => $this->target_dir() . '/photo-1.jpg',
			$edited_url => $target_url,
		);

		// Save an edit right before the importer writes the row.
		$edited = false;
		$edit   = static function ( $query ) use ( $wpdb, $table, $column, $where, $edited_value, &$edited ) {
			if ( ! $edited && 0 === strpos( $query, "UPDATE {$table} SET {$column} = CASE" ) ) {
				$edited = true;
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = %s WHERE {$where}", $edited_value ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			return $query;
		};
		add_filter( 'query', $edit );
		try {
			$importer->backfill_attachment_urls();
		} finally {
			remove_filter( 'query', $edit );
		}

		$this->assertTrue( $edited, 'The edit did not run.' );
		$this->assertSame(
			'Edited. ' . str_replace( $old, $target_url, $initial ),
			$wpdb->get_var( "SELECT {$column} FROM {$table} WHERE {$where}" ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Many rows are read and written in a few batched queries, and long rows are split over
	 * several statements.
	 */
	public function test_backfill_batches_reads_and_writes() {
		global $wpdb;

		$old = self::SOURCE_DIR . '/photo.jpg';
		$new = $this->target_dir() . '/photo-1.jpg';
		$ids = array();
		for ( $i = 0; $i < 250; $i++ ) {
			// Every 5th row is long, so the rows of one read do not fit in one statement.
			$padding = 0 === $i % 5 ? str_repeat( 'x', 30000 ) : '';
			$ids[]   = self::factory()->post->create( array( 'post_content' => "<img src=\"{$old}\" />{$padding}<img src=\"{$old}\" />" ) );
		}

		$importer            = new WP_Import();
		$importer->url_remap = array( $old => $new );

		$queries = $wpdb->num_queries;
		$importer->backfill_attachment_urls();
		$queries = $wpdb->num_queries - $queries;

		foreach ( $ids as $id ) {
			$this->assert_urls( array( $new, $new ), $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) ) );
		}
		// 4 lookups, 1 read of 250 rows, and a few writes split by size: not one query per row.
		$this->assertLessThan( 25, $queries );
	}

	public static function data_malformed_metadata() {
		return array(
			'Not serialized'          => array( 'just a string' ),
			'Serialized string'       => array( serialize( 'a string' ) ),
			'Sizes is not an array'   => array( serialize( array( 'sizes' => 'large' ) ) ),
			'Size is not an array'    => array( serialize( array( 'sizes' => array( 'large' => 'photo-1024x500.jpg' ) ) ) ),
			'File is not a string'    => array( serialize( array( 'sizes' => array( 'large' => array( 'file' => array( 'x' ) ) ) ) ) ),
			'Object'                  => array( 'O:13:"WP_HTML_Token":1:{s:8:"bookmark";s:1:"x";}' ),
			'Corrupted serialization' => array( 'a:1:{s:5:"sizes";a:1:{' ),
		);
	}

	/**
	 * Broken or hostile `_wp_attachment_metadata` must not stop the import or the sub-size remap.
	 *
	 * @dataProvider data_malformed_metadata
	 */
	public function test_malformed_source_metadata_is_ignored( $metadata ) {
		$attachment             = $this->attachment( 611, 'photo-scaled.jpg' );
		$attachment['metadata'] = $metadata;

		$this->import_items(
			array(
				$attachment,
				$this->post( 555, '<img src="{src}/photo-1024x500.jpg" />' ),
			),
			false
		);

		$new = $this->new_stub( 'photo-scaled.jpg' );
		$this->assert_urls( array( "{$new}-1024x500.jpg" ), $this->imported_content( 555 ) );
	}

	public static function data_real_images() {
		return array(
			'Square, scaled'                   => array( 3000, 3000, 0, true ),
			'Landscape, scaled'                => array( 3000, 2000, 0, true ),
			'Odd landscape, scaled'            => array( 3001, 1999, 0, true ),
			'Panorama, scaled'                 => array( 4000, 1000, 0, true ),
			'Portrait, scaled'                 => array( 1500, 3500, 0, true ),
			'Just over the threshold'          => array( 2561, 1441, 0, true ),
			'Below the threshold'              => array( 2000, 1500, 0, true ),
			'EXIF rotated'                     => array( 1200, 800, 6, true ),
			'EXIF rotated, and scaled'         => array( 3000, 2000, 6, true ),
			// Without exported metadata, sub-sizes are matched by name and dimensions, which hold when no size is 1px off.
			'Square, scaled, no metadata'      => array( 3000, 3000, 0, false ),
			'Panorama, scaled, no metadata'    => array( 4000, 1000, 0, false ),
			'Below the threshold, no metadata' => array( 2000, 1500, 0, false ),
			'EXIF rotated, no metadata'        => array( 1200, 800, 6, false ),
		);
	}

	/**
	 * Round trip with real images: WordPress generates the scaled, rotated and sub-size files
	 * on the "source" site, the importer downloads the exported file, WordPress regenerates the
	 * sub-sizes from it, and every URL in the imported content must point to an existing file.
	 *
	 * @dataProvider data_real_images
	 */
	public function test_real_image_round_trip_leaves_no_broken_urls( $width, $height, $orientation, $export_metadata ) {
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) ) || ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'Needs GD to create the test images.' );
		}
		if ( $orientation && ! function_exists( 'exif_read_data' ) ) {
			$this->markTestSkipped( 'Needs the exif extension to rotate images.' );
		}

		// "Source" site: upload the image and let WordPress create every file for it.
		$upload = wp_upload_bits( 'photo.jpg', null, $this->create_jpeg( $width, $height, $orientation ), '2013/05' );
		$this->assertEmpty( $upload['error'] );

		$source_id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg' ), $upload['file'] );
		wp_update_attachment_metadata( $source_id, wp_generate_attachment_metadata( $source_id, $upload['file'] ) );

		$urls = array( wp_get_attachment_url( $source_id ) );
		if ( function_exists( 'wp_get_original_image_url' ) ) {
			$urls[] = wp_get_original_image_url( $source_id );
		}
		foreach ( array_keys( wp_get_attachment_metadata( $source_id )['sizes'] ) as $size ) {
			$urls[] = wp_get_attachment_image_url( $source_id, $size );
		}
		$this->assertGreaterThan( 2, count( $urls ), 'WordPress did not create sub-sizes for the test image.' );

		$uploads      = wp_upload_dir();
		$content      = '';
		$exported_url = str_replace( $uploads['baseurl'], self::SOURCE_BASE . '/wp-content/uploads', wp_get_attachment_url( $source_id ) );
		foreach ( array_unique( $urls ) as $url ) {
			$content .= '<img src="' . str_replace( $uploads['baseurl'], self::SOURCE_BASE . '/wp-content/uploads', $url ) . '" />';
		}
		$exported_body = file_get_contents( get_attached_file( $source_id ) );
		$attached_file = get_post_meta( $source_id, '_wp_attached_file', true );
		$metadata      = wp_get_attachment_metadata( $source_id );

		// Clear the "source" site, then import into the now empty "target" site.
		wp_delete_attachment( $source_id, true );
		$this->assertSame( array(), glob( dirname( $upload['file'] ) . '/photo*' ), 'Source files were left behind.' );

		$attachment                  = $this->attachment( 611, basename( $attached_file ) );
		$attachment['url']           = $exported_url;
		$attachment['guid']          = $exported_url;
		$attachment['attached_file'] = $attached_file;
		if ( $export_metadata ) {
			$attachment['metadata'] = serialize( $metadata );
		}
		$this->remote_files[ $exported_url ] = $exported_body;
		$this->import_items( array( $attachment, $this->post( 555, $content ) ), true );

		$imported = $this->urls( $this->imported_content( 555 ) );
		$this->assertCount( count( array_unique( $urls ) ), $imported );
		foreach ( $imported as $url ) {
			$this->assertStringStartsWith( $uploads['baseurl'] . '/', $url, "Not remapped: {$url}" );
			$this->assertFileExists( $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) ), "Broken image URL: {$url}" );
		}
	}

	/**
	 * Serves the files in $remote_files for the importer's download request.
	 */
	public function filter_mock_remote_file( $preempt, $parsed_args, $url ) {
		if ( ! isset( $this->remote_files[ $url ] ) || empty( $parsed_args['filename'] ) ) {
			return $preempt;
		}

		file_put_contents( $parsed_args['filename'], $this->remote_files[ $url ] );

		return array(
			'headers'  => array(
				'content-length' => (string) strlen( $this->remote_files[ $url ] ),
				'content-type'   => 'image/jpeg',
			),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/**
	 * Builds an attachment item stored in the source site's 2013/05 uploads folder.
	 *
	 * The served image is 2560x1250 by default, as photo-scaled.jpg would be, so the target
	 * site creates sub-sizes such as 1024x500 and 300x146.
	 */
	protected function attachment( $id, $file_name, $width = 2560, $height = 1250 ) {
		static $jpegs = array();

		if ( ! isset( $jpegs[ "{$width}x{$height}" ] ) ) {
			$jpegs[ "{$width}x{$height}" ] = $this->create_jpeg( $width, $height, 0 );
		}

		$url = self::SOURCE_DIR . '/' . $file_name;

		$this->remote_files[ $url ] = $jpegs[ "{$width}x{$height}" ];

		return array(
			'type'          => 'attachment',
			'id'            => $id,
			'name'          => sanitize_title( pathinfo( $file_name, PATHINFO_FILENAME ) ),
			'url'           => $url,
			'guid'          => $url,
			'attached_file' => '2013/05/' . $file_name,
		);
	}

	/**
	 * Builds a post item. "{src}" in the content and enclosure stands for the source uploads folder.
	 */
	protected function post( $id, $content, $enclosure = '' ) {
		return array(
			'type'      => 'post',
			'id'        => $id,
			'name'      => "post-{$id}",
			'content'   => str_replace( '{src}', self::SOURCE_DIR, $content ),
			'enclosure' => str_replace( '{src}', self::SOURCE_DIR, $enclosure ),
		);
	}

	protected function import_items( $items, $rewrite_urls ) {
		$xml = '<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
	<title>Source</title>
	<link>' . self::SOURCE_BASE . '</link>
	<wp:wxr_version>1.2</wp:wxr_version>
	<wp:base_site_url>' . self::SOURCE_BASE . '</wp:base_site_url>
	<wp:base_blog_url>' . self::SOURCE_BASE . '</wp:base_blog_url>
	<wp:author><wp:author_id>1</wp:author_id><wp:author_login><![CDATA[admin]]></wp:author_login><wp:author_email><![CDATA[admin@example.com]]></wp:author_email><wp:author_display_name><![CDATA[admin]]></wp:author_display_name></wp:author>';

		foreach ( $items as $item ) {
			$is_attachment = 'attachment' === $item['type'];

			$xml .= '
	<item>
		<title>' . $item['name'] . '</title>
		<dc:creator><![CDATA[admin]]></dc:creator>
		<guid isPermaLink="false">' . ( $is_attachment ? $item['guid'] : self::SOURCE_BASE . '/?p=' . $item['id'] ) . '</guid>
		<content:encoded><![CDATA[' . ( $is_attachment ? '' : $item['content'] ) . ']]></content:encoded>
		<excerpt:encoded><![CDATA[]]></excerpt:encoded>
		<wp:post_id>' . $item['id'] . '</wp:post_id>
		<wp:post_date>2013-05-10 06:17:54</wp:post_date>
		<wp:post_date_gmt>2013-05-10 13:17:54</wp:post_date_gmt>
		<wp:comment_status>open</wp:comment_status>
		<wp:ping_status>closed</wp:ping_status>
		<wp:post_name>' . $item['name'] . '</wp:post_name>
		<wp:status>' . ( $is_attachment ? 'inherit' : 'publish' ) . '</wp:status>
		<wp:post_parent>0</wp:post_parent>
		<wp:menu_order>0</wp:menu_order>
		<wp:post_type>' . $item['type'] . '</wp:post_type>
		<wp:post_password></wp:post_password>
		<wp:is_sticky>0</wp:is_sticky>';

			if ( $is_attachment ) {
				$xml .= '
		<wp:attachment_url>' . $item['url'] . '</wp:attachment_url>
		<wp:postmeta><wp:meta_key>_wp_attached_file</wp:meta_key><wp:meta_value><![CDATA[' . $item['attached_file'] . ']]></wp:meta_value></wp:postmeta>';
				if ( isset( $item['metadata'] ) ) {
					$xml .= '
		<wp:postmeta><wp:meta_key>_wp_attachment_metadata</wp:meta_key><wp:meta_value><![CDATA[' . $item['metadata'] . ']]></wp:meta_value></wp:postmeta>';
				}
			} elseif ( $item['enclosure'] ) {
				$xml .= '
		<wp:postmeta><wp:meta_key>enclosure</wp:meta_key><wp:meta_value><![CDATA[' . $item['enclosure'] . "\n0\nimage/jpeg\n" . ']]></wp:meta_value></wp:postmeta>';
			}

			$xml .= '
	</item>';
		}

		$xml .= '
</channel>
</rss>';

		$file = wp_tempnam( 'wxr-attachment-url-remap.xml' );
		file_put_contents( $file, $xml );

		try {
			$this->_import_wp( $file, array(), true, $rewrite_urls );
		} finally {
			unlink( $file );
		}
	}

	/**
	 * Returns the imported attachment's URL without extension, e.g. ".../2013/05/photo-scaled-1".
	 */
	protected function new_stub( $source_file_name ) {
		$attachments = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'name'        => sanitize_title( pathinfo( $source_file_name, PATHINFO_FILENAME ) ),
			)
		);
		$this->assertCount( 1, $attachments, "Attachment {$source_file_name} was not imported." );

		$url = wp_get_attachment_url( $attachments[0]->ID );
		$this->assertStringStartsWith( $this->target_dir() . '/', $url );

		return substr( $url, 0, -strlen( '.' . pathinfo( $url, PATHINFO_EXTENSION ) ) );
	}

	protected function imported_post_id( $source_id ) {
		$posts = get_posts( array( 'name' => "post-{$source_id}" ) );
		$this->assertCount( 1, $posts, "Post {$source_id} was not imported." );

		return $posts[0]->ID;
	}

	protected function imported_content( $source_id ) {
		return get_post( $this->imported_post_id( $source_id ) )->post_content;
	}

	/**
	 * Returns the src and href values in the HTML, in order. Comparing these instead of the
	 * markup keeps the tests independent of how WordPress formats tags, e.g. <img /> or <img>.
	 */
	protected function urls( $html ) {
		preg_match_all( '/\b(?:src|href)="([^"]*)"/', $html, $matches );

		return $matches[1];
	}

	protected function assert_urls( $expected, $html ) {
		$this->assertSame( $expected, $this->urls( $html ), $html );
	}

	protected function target_dir() {
		return 'http://example.org/wp-content/uploads/2013/05';
	}

	/**
	 * WordPress 5.3 started appending "-1" to uploaded files named like "-scaled" images.
	 */
	protected function wp_appends_number_to_scaled_names() {
		return version_compare( get_bloginfo( 'version' ), '5.3', '>=' );
	}

	protected function create_upload( $file_name ) {
		$upload = wp_upload_bits( $file_name, null, 'existing file', '2013/05' );
		$this->assertEmpty( $upload['error'] );
		$this->assertSame( $this->target_dir() . '/' . $file_name, $upload['url'] );
	}

	/**
	 * Creates a JPEG, optionally with an EXIF orientation tag.
	 */
	protected function create_jpeg( $width, $height, $orientation ) {
		$image = imagecreatetruecolor( $width, $height );
		imagefilledrectangle( $image, 0, 0, (int) ( $width / 2 ), $height, imagecolorallocate( $image, 200, 30, 30 ) );
		ob_start();
		imagejpeg( $image, null, 60 );
		$jpeg = ob_get_clean();

		if ( ! $orientation ) {
			return $jpeg;
		}

		// A minimal big-endian EXIF block with a single Orientation (0x0112) entry, right after SOI.
		$tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack( 'n', $orientation ) . "\x00\x00" . "\x00\x00\x00\x00";
		$app1 = "Exif\x00\x00" . $tiff;

		return substr( $jpeg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $app1 ) + 2 ) . $app1 . substr( $jpeg, 2 );
	}
}
