<?php

namespace WordPress\ByteStream\ByteTransformer;

/**
 * Interface for transforming byte streams.
 *
 * Implementations of this interface can be used to transform byte streams
 * in various ways, such as computing a checksum, decrypting, etc.
 */
interface ByteTransformer {

	/**
	 * Appends bytes to the filter and transforms them.
	 *
	 * @param  string $bytes  The bytes to append.
	 *
	 * @return string|false The filtered bytes, or false if no bytes were filtered.
	 */
	public function filter_bytes( string $bytes );

	/**
	 * Flushes the filter and returns the last chunk of data.
	 *
	 * @return string The last chunk of data.
	 */
	public function flush(): string;
}
