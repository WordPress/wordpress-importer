<?php

namespace WordPress\ByteStream\ByteTransformer;

use WordPress\ByteStream\ByteStreamException;

/**
 * A writer that deflates the bytes written.
 */
class DeflateTransformer implements ByteTransformer {

	private $deflate_handle;

	public function __construct( string $encoding = ZLIB_ENCODING_DEFLATE ) {
		$this->deflate_handle = deflate_init( $encoding );
		if ( false === $this->deflate_handle ) {
			throw new ByteStreamException( 'Failed to initialize deflate handle' );
		}
	}

	public function filter_bytes( string $bytes ) {
		if ( null === $this->deflate_handle ) {
			throw new ByteStreamException( 'Deflate handle is not initialized' );
		}

		$chunk = deflate_add( $this->deflate_handle, $bytes, ZLIB_NO_FLUSH );
		if ( false === $chunk ) {
			throw new ByteStreamException( 'Failed to deflate bytes' );
		}

		return $chunk;
	}

	public function flush(): string {
		if ( null === $this->deflate_handle ) {
			throw new ByteStreamException( 'closing the deflate filter?' );
		}
		$last_chunk           = deflate_add( $this->deflate_handle, '', ZLIB_FINISH );
		$this->deflate_handle = null;

		return $last_chunk;
	}
}
