<?php

namespace WordPress\ByteStream\WriteStream;

interface ByteWriteStream {

	/**
	 * Append bytes to the stream.
	 *
	 * @param  string $bytes
	 *
	 * @return void
	 */
	public function append_bytes( string $bytes ): void;

	/**
	 * Closes the stream resources.
	 *
	 * @return void
	 */
	public function close_writing(): void;
}
