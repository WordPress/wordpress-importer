<?php

namespace WordPress\Filesystem\Mixin;

use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\Filesystem\ByteStream\FilesystemWriteStream;

trait InternalizeWriteStream {

	/**
	 * Start streaming a file.
	 *
	 * @param  string $path  The path to the file.
	 *
	 * @return ByteWriteStream The stream.
	 * @example
	 *
	 * $fs->open_read_stream($path);
	 * while($fs->next_file_chunk()) {
	 *     $chunk = $fs->get_file_chunk();
	 *     // process $chunk
	 * }
	 * $fs->close_read_stream();
	 */
	public function open_write_stream( $path ): ByteWriteStream {
		$stream_id = $this->write_stream_internal_open( $path );

		return new FilesystemWriteStream( $this, $stream_id );
	}

	abstract protected function write_stream_internal_open( string $path ): int;

	abstract public function write_stream_append_bytes( int $stream_id, $data );

	abstract public function write_stream_close( int $stream_id );
}
