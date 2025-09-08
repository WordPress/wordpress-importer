<?php

namespace WordPress\Filesystem\Mixin;

use WordPress\Filesystem\FilesystemException;

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_path_segments;

/**
 * Implements a recursive mkdir() function by calling mkdir_single() for
 * each non-existing segment of the path.
 */
trait MkdirRecursive {

	public function mkdir( $path, $options = array() ) {
		// Windows paths compatibility for LocalFilesystem:.
		if ( method_exists( $this, 'normalize_path' ) ) {
			$path = $this->normalize_path( $path );
		}

		$recursive = $options['recursive'] ?? false;
		if ( ! $recursive ) {
			$this->mkdir_single( $path, $options );

			return;
		}

		/**
		 * We'll only be checking the subpaths of the filesystem root,
		 * while assuming that the root itself already exists.
		 *
		 * Before we may proceed, we need to confirm our assumption that
		 * $path is within the filesystem root.
		 *
		 * ChrootLayer typically takes care of this. The code below is just
		 * extra sanity checking before we run a bunch of string operations on
		 * $path with the assumption that it started with $root.
		 */
		$root = rtrim( $this->get_root(), '/' ) . '/';
		$path = rtrim( $path, '/' ) . '/';

		// Windows paths compatibility for LocalFilesystem:.
		if ( method_exists( $this, 'normalize_path' ) ) {
			$root = $this->normalize_path( $root );
			$path = $this->normalize_path( $path );
		}
		// Assert to be extra sure the operation is safe:.
		if ( 0 !== strncmp( $path, $root, strlen( $root ) ) ) {
			throw new FilesystemException( sprintf( 'Path %s is not within the root %s', $path, $root ) );
		}

		$child_path = substr( $path, strlen( $root ) );
		$segments   = wp_unix_path_segments( $child_path );
		for ( $i = 0; $i < count( $segments ); $i++ ) {
			$parent_path = wp_join_unix_paths(
				$root,
				...array_slice( $segments, 0, $i + 1 )
			);
			if ( ! $this->exists( $parent_path ) ) {
				$this->mkdir_single( $parent_path, $options );
			}
		}
	}

	private function parent_paths( $path ) {
		$segments = wp_unix_path_segments( $path );
		$paths    = array();
		for ( $i = 0; $i < count( $segments ) - 1; $i++ ) {
			$paths[] = $segments[ $i ];
			yield wp_join_unix_paths( ...$paths );
		}
		yield $path;
	}

	abstract protected function get_root(): string;

	abstract protected function mkdir_single( $path, $options = array() );
}
