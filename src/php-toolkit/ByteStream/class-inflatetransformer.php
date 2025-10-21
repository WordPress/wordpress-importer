<?php

namespace WordPress\ByteStream\ByteTransformer;

use WordPress\ByteStream\ByteStreamException;

/**
 * A writer that inflates compressed bytes.
 */
class InflateTransformer implements ByteTransformer {

	private $inflate_handle;

	public function __construct( string $encoding = ZLIB_ENCODING_DEFLATE ) {
		$this->inflate_handle = inflate_init( $encoding );
		if ( false === $this->inflate_handle ) {
			throw new ByteStreamException( 'Failed to initialize inflate handle' );
		}
	}

	public function filter_bytes( string $bytes ) {
		if ( null === $this->inflate_handle ) {
			throw new ByteStreamException( 'Inflate handle is not initialized' );
		}

		$inflated_data = inflate_add( $this->inflate_handle, $bytes, ZLIB_NO_FLUSH );
		if ( false === $inflated_data ) {
			$last_error = error_get_last();
			if ( empty( $last_error ) ) {
				$last_error = array( 'message' => 'Unknown error' );
			}
			throw new ByteStreamException( esc_html( 'Failed to inflate data: ' . $last_error['message'] ) );
		}

		return $inflated_data;
	}

	public function flush(): string {
		if ( null === $this->inflate_handle ) {
			throw new ByteStreamException( 'closing the inflate filter?' );
		}

		$last_chunk           = inflate_add( $this->inflate_handle, '', ZLIB_FINISH );
		$this->inflate_handle = null;

		return $last_chunk;
	}
}
