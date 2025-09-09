<?php

namespace WordPress\Filesystem;

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Filesystem\Mixin\Interfaces\InternalizedWriteStream;

/**
 * Represents an in-memory filesystem.
 */
class InMemoryFilesystem implements Filesystem, InternalizedWriteStream {

	use Mixin\InternalizeWriteStream;
	use Mixin\GetContentsViaReadStream;
	use Mixin\MkdirRecursive;
	use Mixin\CopyRecursiveViaStreaming;

	private $last_write_stream_id = 0;
	private $write_streams        = array();
	private $files                = array();

	public static function create() {
		return new ChrootLayer(
			new InMemoryFilesystem(),
			'/'
		);
	}

	private function __construct() {
		$this->files['/'] = array(
			'type'     => 'dir',
			'contents' => array(),
		);
	}

	protected function get_root(): string {
		return '/';
	}

	public function ls( $parent_path = '/' ) {
		if ( ! isset( $this->files[ $parent_path ] ) || 'dir' !== $this->files[ $parent_path ]['type'] ) {
			throw new FilesystemException(
				sprintf( 'Directory not found: %s', $parent_path )
			);
		}

		return array_keys( $this->files[ $parent_path ]['contents'] );
	}

	public function is_dir( $path ) {
		return isset( $this->files[ $path ] ) && 'dir' === $this->files[ $path ]['type'];
	}

	public function is_file( $path ) {
		return isset( $this->files[ $path ] ) && 'file' === $this->files[ $path ]['type'];
	}

	public function exists( $path ) {
		return isset( $this->files[ $path ] );
	}

	public function open_read_stream( $path ): ByteReadStream {
		if ( ! $this->is_file( $path ) ) {
			throw new FilesystemException(
				sprintf( 'File not found: %s', $path )
			);
		}

		return new MemoryPipe( $this->files[ $path ]['contents'] );
	}

	public function rename( $old_path, $new_path, $options = array() ) {
		if ( ! $this->exists( $old_path ) ) {
			throw new FilesystemException(
				sprintf( 'File not found: %s', $old_path )
			);
		}

		$parent = wp_unix_dirname( $new_path );
		if ( ! $this->is_dir( $parent ) ) {
			throw new FilesystemException(
				sprintf( 'Parent directory not found: %s', $parent )
			);
		}

		$this->files[ $new_path ] = $this->files[ $old_path ];
		unset( $this->files[ $old_path ] );

		return true;
	}

	public function mkdir_single( $path, $options = array() ) {
		if ( $this->exists( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Directory already exists: %s', $path )
			);
		}

		$parent = wp_unix_dirname( $path );
		if ( ! $this->is_dir( $parent ) ) {
			throw new FilesystemException(
				sprintf( 'Parent directory not found: %s', $parent )
			);
		}

		$this->files[ $path ]                                    = array(
			'type'     => 'dir',
			'contents' => array(),
		);
		$this->files[ $parent ]['contents'][ basename( $path ) ] = true;

		return true;
	}

	public function rm( $path ) {
		if ( ! $this->is_file( $path ) ) {
			throw new FilesystemException(
				sprintf( 'File not found: %s', $path )
			);
		}

		$parent = wp_unix_dirname( $path );
		unset( $this->files[ $parent ]['contents'][ basename( $path ) ] );
		unset( $this->files[ $path ] );

		return true;
	}

	public function rmdir( $path, $options = array() ) {
		$recursive = $options['recursive'] ?? false;
		if ( ! $this->is_dir( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Directory not found: %s', $path )
			);
		}

		if ( $recursive ) {
			$path = rtrim( $path, '/' );
			foreach ( $this->ls( $path ) as $child ) {
				if ( $this->is_dir( $path . '/' . $child ) ) {
					$this->rmdir( $path . '/' . $child, $options );
				} else {
					$this->rm( $path . '/' . $child );
				}
			}
		}

		$parent = wp_unix_dirname( $path );
		unset( $this->files[ $parent ]['contents'][ basename( $path ) ] );
		unset( $this->files[ $path ] );

		return true;
	}

	public function put_contents( $path, $data, $options = array() ) {
		$parent = wp_unix_dirname( $path );
		if ( ! $this->is_dir( $parent ) ) {
			throw new FilesystemException(
				sprintf( 'Parent directory not found: %s', $parent )
			);
		}

		$this->files[ $path ]                                    = array(
			'type'     => 'file',
			'contents' => $data,
		);
		$this->files[ $parent ]['contents'][ basename( $path ) ] = true;

		return true;
	}

	protected function write_stream_internal_open( string $path ): int {
		$this->put_contents( $path, '' );
		$stream_id                         = $this->last_write_stream_id++;
		$this->write_streams[ $stream_id ] = $path;

		return $stream_id;
	}

	public function write_stream_append_bytes( int $stream_id, $data ): bool {
		if ( ! isset( $this->write_streams[ $stream_id ] ) ) {
			throw new FilesystemException(
				sprintf( 'Cannot append bytes to a write stream that is not open' )
			);
		}
		$path                              = $this->write_streams[ $stream_id ];
		$this->files[ $path ]['contents'] .= $data;

		return true;
	}

	public function write_stream_close( int $stream_id ): void {
		if ( ! isset( $this->write_streams[ $stream_id ] ) ) {
			throw new FilesystemException(
				sprintf( 'Cannot close a write stream that is not open' )
			);
		}
		unset( $this->write_streams[ $stream_id ] );
	}

	public function get_meta(): array {
		return array();
	}
}
