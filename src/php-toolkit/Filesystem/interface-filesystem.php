<?php

namespace WordPress\Filesystem;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;

/**
 * Interface for filesystem implementations.
 *
 * It enables navigating multiple filesystem implementations in a unified way.
 * For example, Zip_Filesystem and Local_Filesystem are both implemented
 * as subclasses of this class.
 */
interface Filesystem {

	/**
	 * List the contents of a directory.
	 *
	 * @param  string $parent  The path to the parent directory.
	 *
	 * @return array<string> The contents of the directory.
	 * @throws FilesystemException If the directory cannot be listed.
	 */
	public function ls( $parent = '/' );

	/**
	 * Check if a path is a directory.
	 *
	 * @param  string $path  The path to check.
	 *
	 * @return bool True if the path is a directory, false otherwise.
	 * @throws FilesystemException If the path cannot be checked.
	 */
	public function is_dir( $path );

	/**
	 * Check if a path is a file.
	 *
	 * @param  string $path  The path to check.
	 *
	 * @return bool True if the path is a file, false otherwise.
	 * @throws FilesystemException If the path cannot be checked.
	 */
	public function is_file( $path );

	/**
	 * Check if a path exists.
	 *
	 * @param  string $path  The path to check.
	 *
	 * @return bool True if the path exists, false otherwise.
	 * @throws FilesystemException If the path cannot be checked.
	 */
	public function exists( $path );

	/**
	 * Create a directory.
	 *
	 * @param  string $path  The path to create.
	 * @param  array  $options  Additional options.
	 *
	 * @throws FilesystemException If the directory cannot be created.
	 */
	public function mkdir( $path, $options = array() );

	/**
	 * Remove a file.
	 *
	 * @param  string $path  The path to remove.
	 *
	 * @throws FilesystemException If the file cannot be removed.
	 */
	public function rm( $path );

	/**
	 * Remove a directory.
	 *
	 * @param  string $path  The path to remove.
	 * @param  array  $options  Additional options.
	 *
	 * @throws FilesystemException If the directory cannot be removed.
	 */
	public function rmdir( $path, $options = array() );

	/**
	 * Start streaming a file.
	 *
	 * @param  string $path  The path to the file.
	 *
	 * @return ByteReadStream The stream identifier.
	 * @throws FilesystemException If the stream cannot be opened.
	 * @example
	 *
	 * $fs->open_read_stream($path);
	 * while($fs->next_file_chunk()) {
	 *     $chunk = $fs->get_file_chunk();
	 *     // process $chunk
	 * }
	 * $fs->close_read_stream();
	 */
	public function open_read_stream( $path ): ByteReadStream;

	/**
	 * Open a write stream to a file.
	 *
	 * @param  string $path  The path to write to.
	 *
	 * @return ByteWriteStream The stream identifier.
	 * @throws FilesystemException If the stream cannot be opened.
	 */
	public function open_write_stream( $path ): ByteWriteStream;

	/**
	 * Write data to a file.
	 *
	 * @param  string $path  The path to write to.
	 * @param  string $data  The data to write.
	 * @param  array  $options  Additional options.
	 *
	 * @throws FilesystemException If the data cannot be written.
	 */
	public function put_contents( $path, $data, $options = array() );

	/**
	 * Copy a file from one path to another.
	 *
	 * @param  string $from_path  The path to copy from.
	 * @param  string $to_path  The path to copy to.
	 * @param  array  $options  Additional options.
	 *
	 * @throws FilesystemException If the file cannot be copied.
	 */
	public function copy( $from_path, $to_path, $options = array() );

	/**
	 * Moves a file from one path to another.
	 *
	 * @param  string $from_path  The path to move from.
	 * @param  string $to_path  The path to move to.
	 * @param  array  $options  Additional options.
	 *
	 * @throws FilesystemException If the file cannot be moved.
	 */
	public function rename( $from_path, $to_path, $options = array() );

	/**
	 * Buffers the entire contents of a file into a string
	 * and returns it.
	 *
	 * @param  string $path  The path to the file.
	 *
	 * @return string The contents of the file.
	 * @throws FilesystemException If the file cannot be read.
	 */
	public function get_contents( $path );

	/**
	 * Get the metadata of the filesystem instance. Different
	 * for every filesystem implementation.
	 *
	 * @return array<string, mixed> The metadata of the filesystem.
	 */
	public function get_meta(): array;
}
