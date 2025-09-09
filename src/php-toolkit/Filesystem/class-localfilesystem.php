<?php

namespace WordPress\Filesystem;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\ByteStream\WriteStream\FileWriteStream;
use WordPress\Filesystem\Layer\ChrootLayer;

/**
 * Represents the currently available filesystem.
 */
class LocalFilesystem implements Filesystem {

	use Mixin\PutContentsViaWriteStream;
	use Mixin\GetContentsViaReadStream;
	use Mixin\RmdirRecursive;
	use Mixin\MkdirRecursive;
	use Mixin\CopyDirectoryRecursive;

	private $root;

	public static function create( $root = null ) {
		// Make sure the root path uses forward slashes on Windows.
		// This allows us to use all wp_unix_* functions across the board.
		if ( null === $root ) {
			if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
				$system_drive = getenv( 'SystemDrive' );
				$root         = $system_drive ? $system_drive . '\\' : 'C:\\';
			} else {
				$root = '/';
			}
		} elseif ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
				$root = self::normalize_path( $root );
		}

		if ( ! is_dir( $root ) ) {
			if ( false === mkdir( $root, 0755, true ) ) {
				throw new FilesystemException( sprintf( 'Root directory did not exist and could not be created: %s', var_export( $root, true ) ) );
			}
		}

		return new ChrootLayer(
			new LocalFilesystem( $root ),
			$root
		);
	}

	/**
	 * Use LocalFilesystem::create() to ensure the correct filesystem layers are applied.
	 */
	private function __construct( $root ) {
		$this->root = $root;
	}

	/**
	 * For mkdir_recursive().
	 */
	public function get_root(): string {
		return $this->root;
	}

	public function get_meta(): array {
		return array(
			'root' => $this->root,
		);
	}

	public function ls( $path = '/' ) {
		$dh = @opendir( $path );
		if ( false === $dh ) {
			throw new FilesystemException(
				sprintf( 'Failed to open directory: %s', $path )
			);
		}

		$children = array();
		while ( true ) {
			$filename = readdir( $dh );
			if ( false === $filename ) {
				break;
			}
			if ( '.' === $filename || '..' === $filename ) {
				continue;
			}
			$children[] = $filename;
		}
		closedir( $dh );

		return $children;
	}

	public function is_dir( $path ) {
		return is_dir( $path );
	}

	public function is_file( $path ) {
		return is_file( $path );
	}

	public function exists( $path ) {
		return file_exists( $path );
	}

	public function rename( $old_path, $new_path, $options = array() ) {
		if ( false === @rename( $old_path, $new_path ) ) {
			throw new FilesystemException(
				sprintf( 'Failed to rename: %s to %s', $old_path, $new_path )
			);
		}

		return true;
	}

	public function copy_file( $from_path, $to_path, $options ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === @copy( $from_path, $to_path ) ) {
			throw new FilesystemException(
				sprintf( 'Failed to copy file: %s to %s', $from_path, $to_path )
			);
		}
	}

	protected function mkdir_single( $path, $options = array() ) {
		$resolved_path = $this->normalize_path( $path );
		if ( $this->exists( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Path already exists: %s', $path )
			);
		}
		if ( false === @mkdir( $resolved_path ) ) {
			throw new FilesystemException(
				sprintf( 'Failed to create directory: %s', $resolved_path )
			);
		}
		if ( isset( $options['chmod'] ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === @chmod( $path, $options['chmod'] ) ) {
				throw new FilesystemException(
					sprintf( 'Failed to chmod directory: %s', $path )
				);
			}
		}
	}

	public function rm( $path ) {
		if ( false === @unlink( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Failed to remove file: %s', $path )
			);
		}
	}

	protected function rmdir_single( $path, $options = array() ) {
		if ( false === @rmdir( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Failed to remove directory: %s', $path )
			);
		}
	}

	public function put_contents( $path, $data, $options = array() ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === @file_put_contents(
			$path,
			$data
		) ) {
			throw new FilesystemException(
				sprintf( 'Failed to write to file: %s', $path )
			);
		}
	}

	public function open_write_stream( $path ): ByteWriteStream {
		return FileWriteStream::from_path( $path, 'truncate' );
	}

	public function open_read_stream( $path ): ByteReadStream {
		return FileReadStream::from_path( $path );
	}

	/**
	 * Turns a linux path into an OS-specific path.
	 *
	 * This is necessary because Filesystem-related classes use
	 * forward slashes to separate paths. They must. LocalFilesystem
	 * is just one of the available Filesystem implementations.
	 *
	 * Therefore, the problem of converting forward slashes to
	 * OS-specific path separators is specific to the LocalFilesystem
	 * class
	 */
	private static function normalize_path( $path ) {
		return str_replace( DIRECTORY_SEPARATOR, '/', $path );
	}
}
