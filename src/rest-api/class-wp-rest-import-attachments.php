<?php

class WP_REST_Import_Attachments {
	const NAMESPACE = 'wordpress-importer/v1';
	const ATTACHMENT_OPTION_NAME = 'wordpress_importer_attachment_id';
	const NONCE_NAME = 'wordpress-importer-rest-api';

	function __construct() {
		$this->register_routes();
	}

	protected function register_routes() {
		register_rest_route( self::NAMESPACE, '/attachment', array(
			// 'show_in_index' => false,
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'add_attachment' ),
			'permission_callback' => array( $this, 'upload_and_import_permissions_check' ),
			'args' => array(),
		) );

		// @TODO: move to its own class
		register_rest_route( self::NAMESPACE, '/start', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'start_import' ),
			// @TODO: check correct permissions
			'permission_callback' => array( $this, 'upload_and_import_permissions_check' ),
			'args' => array(),
		) );
	}

	// @TODO: move to its own class
	function start_import( $request ) {
		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', 1 );
		}

		if ( ! class_exists( 'WP_Import' ) ) {
			return new WP_Error( 'missing_wp_import', 'The WP_Import class does not exist' );
		}
		if ( ! function_exists( 'wordpress_importer_init' ) ) {
			return new WP_Error( 'missing_wp_import_init', 'The wordpress_importer_init function does not exist' );
		}
		// The REST API does not do `admin_init`, so we need to source a bunch of stuff
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		wordpress_importer_init();
		if ( empty( $GLOBALS['wp_import'] ) ) {
			return new WP_Error( 'empty_wp_import', 'The wp_import global is empty' );
		}

		// Generate authors map expected by WP_Import
		$authors = $request['authors'];

		foreach( $authors as $import_author => $site_author ) {
			$existing_user = get_user_by( 'login', $site_author );

			if ( $existing_user ) {
				$user_id = $existing_user->ID;
			} else {
				// @TODO: Allow for specifying name?
				$user_id = wp_create_user( $site_author, wp_generate_password() );
			}

			$sanitized_import_author = sanitize_user( $import_author, true );
			$GLOBALS['wp_import']->author_mapping[ $sanitized_import_author ] = $user_id;
		}

		// Map fetch_attachments setting
		$GLOBALS['wp_import']->fetch_attachments = ! empty( $request['fetch_attachments'] );

		// Get attachment
		$attachment_id = get_option( self::ATTACHMENT_OPTION_NAME );

		if ( ! $attachment_id ) {
			return new WP_Error( 'missing_attachment', 'Attachment did not exist' );
		}

		$file = get_attached_file( $attachment_id );
		set_time_limit(0);
		/**
		 *  WP_Import::import currently outputs html to the page. This breaks
		 *  apiFetch, which expects only JSON. For now, buffer that output
		 *  @TODO: make this better in a better way
		 **/
		ob_start();
		$GLOBALS['wp_import']->import( $file );
		ob_end_clean();

		return true;
	}

	function upload_and_import_permissions_check( $request ) {
		$post_type = get_post_type_object( 'attachment' );

		if ( ! current_user_can( $post_type->cap->create_posts ) ) {
			return new WP_Error(
				'rest_cannot_create',
				__( 'Sorry, you are not allowed to create attachments as this user.', 'wordpress-importer' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'upload_forbidden',
				__( 'Sorry, you are not allowed to upload files as this user.', 'wordpress-importer' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! current_user_can( 'import' ) ) {
			return new WP_Error(
				'import_forbidden',
				__( 'Sorry, you are not allowed to import as this user.', 'wordpress-importer' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	function add_attachment( $request ) {
		// Get the file via $_FILES or raw data.
		$files = $request->get_file_params();
		$headers = $request->get_headers();

		if ( empty( $files['import'] ) ) {
			return new WP_Error(
				'rest_upload_no_data',
				__( 'No data supplied.', 'wordpress-importer' ),
				array( 'status' => 400 )
			);
		}

		$max_bytes = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		$file_size = (int) filesize( $files['import']['tmp_name'] );
		if ( $file_size > $max_bytes ) {
			return new WP_Error(
				'file_too_large',
				__( 'Cannot upload the archive file -- it was too large.', 'wordpress-importer' ),
				array( 'status' => 400 )
			);
		}

		// Verify hash, if given.
		if ( ! empty( $headers['content_md5'] ) ) {
			$content_md5 = array_shift( $headers['content_md5'] );
			$expected    = trim( $content_md5 );
			$actual      = md5_file( $files['file']['tmp_name'] );

			if ( $expected !== $actual ) {
				return new WP_Error(
					'rest_upload_hash_mismatch',
					__( 'Content hash did not match expected.', 'wordpress-importer' ),
					array( 'status' => 412 )
				);
			}
		}

		/** Include admin files to get access to wp_handle_upload() & wp_import_handle_upload. */
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/import.php';

		// Pass off to WP to handle the actual upload.
		$attachment = wp_import_handle_upload();

		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		if ( ! is_array( $attachment ) || ! isset( $attachment['id'] ) ) {
			return new WP_Error( 'rest_upload_attachment_failed', __( 'Attachment was not completed successfully.' ), array( 'status' => 400 ) );
		}

		return self::process_attachment( $attachment );
	}

	private function process_attachment( $attachment ) {
		// Persist attachment ID
		update_option( self::ATTACHMENT_OPTION_NAME, $attachment['id'] );

		// Include WXR file parsers
		if ( ! class_exists( 'WXR_Parser' ) ) {
			require dirname( __FILE__ ) . '/../parsers.php';
		}

		// Parse WXR to get authors
		$parser = new WXR_Parser();
		$import_data = $parser->parse( $attachment['file'] );

		if ( is_wp_error( $import_data ) ) {
			return $import_data;
		}

		//@TODO: we probably just wanna map all posts to a single author as opposed to erroring if the WXR lacks author data
		if ( ! is_array( $import_data ) || ! isset( $import_data['authors'] ) || ! is_array( $import_data['authors'] ) ) {
			return new WP_Error( 'rest_upload_parsing_failed', __( 'Could not process authors from import file.' ), array( 'status' => 400 ) );
		}

		$authors = array_values( $import_data['authors'] );

		return array(
			'nonce'   => wp_create_nonce( self::NONCE_NAME ),
			'authors' => $authors,
		);
	}
}
