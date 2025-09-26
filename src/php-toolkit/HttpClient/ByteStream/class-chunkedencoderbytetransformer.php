<?php

namespace WordPress\HttpClient\ByteStream;

use WordPress\ByteStream\ByteTransformer\ByteTransformer;

class ChunkedEncoderByteTransformer implements ByteTransformer {

	/**
	 * Encodes $bytes using chunked encoding as:
	 *
	 * <chunk-size><CRLF><chunk-data><CRLF>
	 *
	 * @param  string $bytes  The bytes to encode.
	 */
	public function filter_bytes( $bytes ): string {
		if ( 0 === strlen( $bytes ) ) {
			return '';
		}
		$chunk_size = strtoupper( dechex( strlen( $bytes ) ) );

		return $chunk_size . "\r\n" . $bytes . "\r\n";
	}

	public function flush(): string {
		return "0\r\n\r\n";
	}
}
