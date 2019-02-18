<?php

class WP_REST_Import_Attachments {
	const NAMESPACE = 'wordpress-importer/v1';

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
	}

	function upload_and_import_permissions_check( $request ) {
		// Begin copy from class-wp-rest-posts-controller
		if ( ! empty( $request['id'] ) ) {
			return new WP_Error( 'rest_post_exists', __( 'Cannot create existing post.' ), array( 'status' => 400 ) );
		}

		$post_type = get_post_type_object( 'attachment' );

		if ( ! empty( $request['author'] ) && get_current_user_id() !== $request['author'] && ! current_user_can( $post_type->cap->edit_others_posts ) ) {
			return new WP_Error( 'rest_cannot_edit_others', __( 'Sorry, you are not allowed to create posts as this user.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( ! empty( $request['sticky'] ) && ! current_user_can( $post_type->cap->edit_others_posts ) ) {
			return new WP_Error( 'rest_cannot_assign_sticky', __( 'Sorry, you are not allowed to make posts sticky.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( ! current_user_can( $post_type->cap->create_posts ) ) {
			return new WP_Error( 'rest_cannot_create', __( 'Sorry, you are not allowed to create posts as this user.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		// Do we need this? probably not
		// if ( ! $this->check_assign_terms_permission( $request ) ) {
		//	return new WP_Error( 'rest_cannot_assign_term', __( 'Sorry, you are not allowed to assign the provided terms.' ), array( 'status' => rest_authorization_required_code() ) );
		//	}

		// End copy from class-wp-rest-posts-controller

		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'upload_forbidden', __( 'Sorry, you are not allowed to upload files as this user' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( ! current_user_can( 'import' ) ) {
			return new WP_Error( 'import_forbidden', __( 'Sorry, you are not allowed to import as this user' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	function add_attachment( $request ) {
		// Get the file via $_FILES or raw data.
		$files = $request->get_file_params();
		$headers = $request->get_headers();

		if ( empty( $files['import'] ) ) {
			return new WP_Error( 'rest_upload_no_data', __( 'No data supplied.' ), array( 'status' => 400 ) );
		}

		$max_bytes = apply_filters( 'import_upload_size_limit', wp_max_upload_size() );
		$file_size = (int) filesize( $files['import']['tmp_name'] );
		if ( $file_size > $max_bytes ) {
			return new WP_Error( 'file_too_large', __( 'Cannot upload the archive file -- it was too large' ), array( 'status' => 400 ) );
		}

		// Verify hash, if given.
		if ( ! empty( $headers['content_md5'] ) ) {
			$content_md5 = array_shift( $headers['content_md5'] );
			$expected    = trim( $content_md5 );
			$actual      = md5_file( $files['file']['tmp_name'] );

			if ( $expected !== $actual ) {
				return new WP_Error( 'rest_upload_hash_mismatch', __( 'Content hash did not match expected.' ), array( 'status' => 412 ) );
			}
		}

		/** Include admin files to get access to wp_handle_upload() & wp_import_handle_upload. */
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/import.php';

		// Pass off to WP to handle the actual upload.
		return wp_import_handle_upload();
	}
}
