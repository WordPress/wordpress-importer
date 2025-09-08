<?php

namespace WordPress\Filesystem\Mixin\Interfaces;

use WordPress\Filesystem\FilesystemException;

interface InternalizedWriteStream {

	/**
	 * Get the next chunk of a file.
	 *
	 * @param  int $stream_id  The stream identifier.
	 *
	 * @throws FilesystemException If the next chunk cannot be retrieved.
	 */
	public function write_stream_append_bytes( int $stream_id, $data );

	/**
	 * Close the file writer.
	 *
	 * @throws FilesystemException If the stream cannot be closed.
	 */
	public function write_stream_close( int $stream_id ): void;
}
