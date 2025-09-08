<?php

namespace WordPress\Filesystem\Mixin;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\Filesystem\FilesystemException;

/**
 * Implements put_contents() using the write stream provided by the open_write_stream() method.
 */
trait PutContentsViaWriteStream {

	public function put_contents( $path, $data, $options = array() ) {
		$stream = $this->open_write_stream( $path );
		try {
			if ( is_string( $data ) ) {
				$stream->append_bytes( $data );
			} elseif ( is_object( $data ) && $data instanceof ByteReadStream ) {
				while ( $data->pull() ) {
					$stream->append_bytes( $data->peek() );
				}
			} else {
				throw new FilesystemException( 'Invalid $data argument provided. Expected a string or a Byte_Reader instance. Received: ' . gettype( $data ) );
			}
		} finally {
			$stream->close_writing();
		}
	}
}
