<?php

namespace WordPress\Filesystem;

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\Filesystem\Layer\ChrootLayer;

/**
 * A filesystem implementation that reads from an uploaded directory tree structure.
 * This is useful for handling file uploads through the REST API where files are
 * sent as part of a directory tree structure.
 */
class UploadedFilesystem implements Filesystem {

	use Mixin\ReadOnlyFilesystem;
	use Mixin\GetContentsViaReadStream;

	/**
	 * @var array The directory tree structure
	 */
	private $tree;

	/**
	 * @var REST_Request The request object containing uploaded files
	 */
	private $request;

	/**
	 * @var Filesystem The filesystem to read the uploaded files from.
	 */
	private $uploads_fs;

	/**
	 * Create a new UploadedFilesystem instance.
	 *
	 * @param  REST_Request $request  The request object containing uploaded files
	 * @param  string       $tree_parameter_name  The name of the parameter containing the tree structure
	 * @param  array        $options  The options array {
	 *
	 * @return UploadedFilesystem The new instance
	 * @var Filesystem $uploads_fs Optional. The filesystem to read the uploaded files from.
	 * }
	 */
	public static function create( $request, $tree_parameter_name, $options = array() ) {
		return new ChrootLayer(
			new UploadedFilesystem( $request, $tree_parameter_name, $options ),
			'/'
		);
	}

	/**
	 * @param  REST_Request $request             The request object containing uploaded files
	 * @param  string       $tree_parameter_name The name of the parameter containing the tree structure
	 * @param  array        $options             The options array
	 *
	 * @internal
	 * Use the static create() method instead.
	 */
	private function __construct( $request, $tree_parameter_name, $options = array() ) {
		$tree_json = $request->get_param( $tree_parameter_name );
		if ( ! $tree_json ) {
			throw new FilesystemException( 'Invalid file tree structure' );
		}

		$tree = json_decode( $tree_json, true );
		if ( ! $tree ) {
			throw new FilesystemException( 'Invalid JSON structure' );
		}

		if ( ! isset( $tree['type'] ) || 'directory' !== $tree['type'] || ! isset( $tree['name'] ) || '' !== $tree['name'] ) {
			$tree = array(
				'type'     => 'directory',
				'name'     => '',
				'children' => $tree,
			);
		}

		$this->request    = $request;
		$this->tree       = $tree;
		$this->uploads_fs = $options['uploads_fs'] ?? LocalFilesystem::create( '/' );
	}

	public function ls( $parent = '/' ) {
		$parent = '/' . ltrim( wp_unix_path_resolve_dots( $parent ), '/' );
		$node   = $this->find_node( $parent );
		if ( ! $node || 'directory' !== $node['type'] ) {
			return array();
		}

		return array_map(
			function ( $child ) {
				return $child['name'];
			},
			$node['children'] ?? array()
		);
	}

	public function is_dir( $path ) {
		$node = $this->find_node( $path );

		return $node && 'directory' === $node['type'];
	}

	public function is_file( $path ) {
		$node = $this->find_node( $path );

		return $node && 'file' === $node['type'];
	}

	public function exists( $path ) {
		return null !== $this->find_node( $path );
	}

	public function mkdir( $path, $options = array() ) {
		throw new FilesystemException( 'Not implemented' );
	}

	public function rm( $path ) {
		throw new FilesystemException( 'Not implemented' );
	}

	public function rmdir( $path, $options = array() ) {
		throw new FilesystemException( 'Not implemented' );
	}

	public function open_read_stream( $path ): ByteReadStream {
		$node = $this->find_node( $path );
		if ( ! $node || 'file' !== $node['type'] ) {
			throw new FilesystemException(
				sprintf( 'File not found: %s', $path )
			);
		}

		// Handle file content from request.
		if ( ! isset( $node['content'] ) || ! is_string( $node['content'] ) ) {
			$node['content'] = '';
		}

		if ( 0 === strpos( $node['content'], '@file:' ) ) {
			$file_key      = substr( $node['content'], 6 );
			$uploaded_file = $this->request->get_file_params()[ $file_key ] ?? null;

			if ( ! $uploaded_file || UPLOAD_ERR_OK !== $uploaded_file['error'] ) {
				throw new FilesystemException(
					sprintf( 'File upload error: %s', $uploaded_file['error'] )
				);
			}

			return $this->uploads_fs->open_read_stream( $uploaded_file['tmp_name'] );
		}

		// Handle inline content.
		return new MemoryPipe( $node['content'] );
	}

	/**
	 * Find a node in the tree by its path
	 *
	 * @param  string $path  The path to find
	 *
	 * @return array|null The node if found, null otherwise
	 */
	private function find_node( $path ) {
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return $this->tree;
		}

		$parts   = explode( '/', $path );
		$current = $this->tree;
		foreach ( $parts as $part ) {
			$found = false;
			foreach ( $current['children'] as $node ) {
				if ( $node['name'] === $part ) {
					$found   = true;
					$current = $node;
					break;
				}
			}
			if ( ! $found ) {
				return null;
			}
		}

		return $current;
	}

	public function get_meta(): array {
		return array();
	}
}
