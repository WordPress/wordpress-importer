<?php

namespace WordPress\Filesystem;

use ValueError;

function ls_recursive( Filesystem $filesystem, $path = '/' ) {
	$tree = array();
	foreach ( $filesystem->ls( $path ) as $item ) {
		if ( $filesystem->is_dir( $item ) ) {
			$tree[] = array(
				'name'     => $item,
				'type'     => 'dir',
				'children' => ls_recursive( $filesystem, $item ),
			);
		} else {
			$tree[] = array(
				'name' => $item,
				'type' => 'file',
			);
		}
	}

	return $tree;
}

/**
 * Copies a file or directory between two Filesystem instances.
 *
 * @param  array $args  The arguments to pass to the copy function. {
 *     @type Filesystem $source_filesystem The source filesystem.
 *     @type Filesystem $destination_filesystem The destination filesystem.
 *     @type string $source_path The path to the source file or directory. It must use forward slashes as path separators.
 *     @type string $destination_path The path to the destination file or directory. It must use forward slashes as path separators.
 *     @type bool $recursive Whether to copy the file or directory recursively.
 * }
 */
function copy_between_filesystems( array $args ) {
	$source           = $args['source_filesystem'];
	$source_path      = $args['source_path'] ?? '/';
	$destination      = $args['target_filesystem'];
	$destination_path = $args['target_path'] ?? '/';
	$recursive        = $args['recursive'] ?? true;

	if ( $source->is_file( $source_path ) ) {
		$destination_dir = wp_unix_dirname( $destination_path );
		if ( ! $destination->is_dir( $destination_dir ) ) {
			$destination->mkdir(
				$destination_dir,
				array(
					'recursive' => true,
				)
			);
		}

		$to_stream = $destination->open_write_stream( $destination_path );
		try {
			$from_stream = $source->open_read_stream( $source_path );
			try {
				$chunks_written = 0;
				while ( ! $from_stream->reached_end_of_data() ) {
					$available = $from_stream->pull( 65536 );
					$to_stream->append_bytes( $from_stream->consume( $available ), $to_stream );
					++$chunks_written;
				}
				if ( 0 === $chunks_written ) {
					// Make sure the file receives at least one chunk.
					// so we can be sure it gets created in case the.
					// destination filesystem is lazy.
					$to_stream->append_bytes( '' );
				}
			} finally {
				$from_stream->close_reading();
			}
		} finally {
			$to_stream->close_writing();
		}
	} elseif ( $source->is_dir( $source_path ) ) {
		if ( ! $recursive ) {
			throw new FilesystemException( 'Cannot copy a directory. Set the option `recursive` to true to copy directories recursively.' );
		}
		if ( ! $destination->is_dir( $destination_path ) ) {
			$destination->mkdir(
				$destination_path,
				array(
					'recursive' => true,
				)
			);
		}
		foreach ( $source->ls( $source_path ) as $item ) {
			copy_between_filesystems(
				array(
					'source_filesystem' => $source,
					'source_path'       => wp_join_unix_paths( $source_path, $item ),
					'target_filesystem' => $destination,
					'target_path'       => wp_join_unix_paths( $destination_path, $item ),
				)
			);
		}
	} elseif ( $source->exists( $source_path ) ) {
		// For now ignore paths that are neither files nor directories.
		// For example, in GitFilesystem that could be a submodule.
		return; // No-op, intentionally ignore.
	} else {
		// When a path does not exist, throw a clear error.
		throw new FilesystemException( 'Path does not exist in the source filesystem: ' . $source_path );
	}
}

/**
 * Pipes data from one stream to another.
 *
 * @param  ByteReadStream  $from_stream  The stream to read from.
 * @param  ByteWriteStream $to_stream  The stream to write to.
 * @param  int             $chunk_size  Optional. The size of chunks to read at a time. Default 65536.
 *
 * @return int The number of chunks written.
 * @throws FilesystemException If there's an error during the transfer.
 */
function pipe_stream( $from_stream, $to_stream, $chunk_size = 65536 ) {
	$chunks_written = 0;
	while ( ! $from_stream->reached_end_of_data() ) {
		$available = $from_stream->pull( $chunk_size );
		$to_stream->append_bytes( $from_stream->consume( $available ) );
		++$chunks_written;
	}

	if ( 0 === $chunks_written ) {
		// Make sure the file receives at least one chunk.
		// so we can be sure it gets created in case the.
		// destination filesystem is lazy.
		$to_stream->append_bytes( '' );
		$chunks_written = 1;
	}

	return $chunks_written;
}


function wp_unix_path_segments( $path ) {
	$without_dots    = wp_unix_path_resolve_dots( $path );
	$without_slashes = trim( $without_dots, '/' );

	return explode( '/', $without_slashes );
}

/**
 * Joins multiple path segments together into a single path.
 *
 * Removes any double slashes between path segments.
 */
function wp_join_unix_paths( ...$path_segments ) {
	$input_starts_with_slash = null;

	$paths = array();
	foreach ( $path_segments as $path_segment ) {
		if ( '' !== $path_segment ) {
			$paths[] = $path_segment;
			if ( null === $input_starts_with_slash ) {
				$input_starts_with_slash = 0 === strncmp( $path_segment, '/', strlen( '/' ) );
			}
		}
	}
	$path = implode( '/', $paths );

	$result = preg_replace( '#/+#', '/', $path );
	if ( $input_starts_with_slash && 0 !== strncmp( $result, '/', strlen( '/' ) ) ) {
		$result = '/' . $result;
	}

	return $result;
}

/**
 * Cleans up a path segment.
 *
 * - Removes the /./ segments
 * - Flattens the /../ segments
 *
 * Example:
 *
 * wp_unix_path_resolve_dots( 'foo/bar/../baz' ) => '/foo/baz'
 *
 * @param  string $path  The file path that needs cleaning up
 * @return string The cleaned, absolute path
 */
function wp_unix_path_resolve_dots( $path ) {
	// Convert to absolute path.
	if ( 0 !== strncmp( $path, '/', strlen( '/' ) ) ) {
		$path = '/' . $path;
	}

	// Resolve . and ..
	$parts      = explode( '/', $path );
	$normalized = array();
	foreach ( $parts as $part ) {
		if ( '.' === $part || '' === $part ) {
			continue;
		}
		if ( '..' === $part ) {
			array_pop( $normalized );
			continue;
		}
		$normalized[] = $part;
	}

	$result = implode( '/', $normalized );
	if ( '.' === $result ) {
		$result = '';
	}
	return $result;
}


/**
 * Like sys_get_temp_dir(), but returns a path using forward slashes
 * as separators.
 */
function wp_unix_sys_get_temp_dir() {
	$path = sys_get_temp_dir();
	if ( '\\' === DIRECTORY_SEPARATOR ) {
		$path = str_replace( '\\', '/', $path );
	}
	return $path;
}

/**
 * A clone of PHP's dirname() that assumes the path is a Unix path.
 *
 * Both functions agree on the following:
 *
 *     dirname("/") === wp_unix_dirname("/") === "/"
 *     dirname("/foo/bar") === wp_unix_dirname("/foo/bar") === "/foo"
 *     dirname("/foo/bar/") === wp_unix_dirname("/foo/bar/") === "/foo/bar"
 *
 * However, they disagree on Windows paths:
 *
 *     dirname("C:/") === "C:/" (when ran on windows)
 *     dirname("C:/") === "." (when ran on unix)
 *
 *     wp_unix_dirname("C:/") === "." (regardless of the OS)
 *
 * This ensures we get reliable results on all host OSes.
 *
 * It might seem weird to use unix semantics on windows. However, keep in mind,
 * that php-toolkit supports more filesystems than just a local disk and that
 * C: is a valid filename on unix.
 *
 * @param string $path   Path to inspect (assumed Unix).
 * @param int    $levels How many levels to climb (≥ 1).
 * @return string
 * @throws ValueError on $levels < 1 (keeps parity with PHP 8.x).
 */
function wp_unix_dirname( string $path, int $levels = 1 ): string {
	if ( $levels < 1 ) {
		throw new ValueError( 'unix_dirname(): $levels must be >= 1' );
	}

	// treat empty string the same way PHP does.
	if ( '' === $path ) {
		return '';
	}

	// if the path is nothing but slashes, the result is always "/".
	if ( strspn( $path, '/' ) === strlen( $path ) ) {
		return 1 === $levels ? '/' : wp_unix_dirname( '/', $levels - 1 );
	}

	// strip trailing slashes (but never the single root slash).
	$path = rtrim( $path, '/' );
	if ( '' === $path ) {        // happens when the original was just "/".
		return 1 === $levels ? '/' : wp_unix_dirname( '/', $levels - 1 );
	}

	// locate the last slash.
	$slash = strrpos( $path, '/' );
	if ( false === $slash ) {    // no slash → current dir.
		$path = '.';
	} else {
		$path = substr( $path, 0, $slash );  // cut off the basename.
		$path = rtrim( $path, '/' );         // collapse duplicate slashes.
		if ( '' === $path ) {
			$path = '/';                   // “/foo” → “/”.
		}
	}

	// recurse for additional levels.
	return $levels > 1 ? wp_unix_dirname( $path, $levels - 1 ) : $path;
}
