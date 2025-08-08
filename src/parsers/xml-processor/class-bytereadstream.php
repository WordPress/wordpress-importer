<?php

namespace WordPress\ByteStream\ReadStream;

/**
 * Interface for streaming, seekable byte readers.
 *
 * Implementations of this interface can be used to read data from
 * various sources, such as files, strings, network sockets, zip files,
 * parsers, etc.
 */
interface ByteReadStream {

	const PULL_NO_MORE_THAN = '#pull-no-more-than';
	const PULL_EXACTLY      = '#pull-exactly';

	/**
	 * Get the total length of the data stream.
	 *
	 * @return int|null The length of the data stream, or null if the length is unknown.
	 */
	public function length();

	/**
	 * Get the current position in the data stream.
	 *
	 * @return int The current byte offset in the data stream.
	 */
	public function tell();

	/**
	 * Seek to a specific position in the data stream.
	 *
	 * @param  int  $offset  The byte offset to seek to.
	 *
	 * @return void
	 * @throws ByteStreamException If the offset is invalid.
	 */
	public function seek( $offset );

	/**
	 * Check if the end of the data stream has been reached.
	 * At this point, next_bytes() will always return false until
	 * seek() is called.
	 *
	 * @return bool Whether the end of the data stream has been reached.
	 */
	public function reached_end_of_data();

	/**
	 * Read the next chunk of bytes from the data stream.
	 *
	 * @return int how many bytes were pulled
	 */
	public function pull( $n, $mode = self::PULL_NO_MORE_THAN );

	/**
	 * Get the next $n bytes without advancing the pointer.
	 *
	 * @return string The bytes read.
	 */
	public function peek( $n );

	/**
	 * Returns $n bytes and advances the pointer.
	 *
	 * @param $n
	 *
	 * @return string
	 */
	public function consume( $n );

	/**
	 * Returns all remaining bytes in the stream.
	 *
	 * @return string
	 */
	public function consume_all();

	/**
	 * Close the data stream.
	 *
	 * @return void
	 */
	public function close_reading();
}
