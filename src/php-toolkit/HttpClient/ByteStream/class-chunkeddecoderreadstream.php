<?php

namespace WordPress\HttpClient\ByteStream;

use Exception;
use WordPress\ByteStream\ReadStream\BaseByteReadStream;

class ChunkedDecoderReadStream extends BaseByteReadStream {

	private $state           = self::SCAN_CHUNK_SIZE;
	const SCAN_CHUNK_SIZE    = 'SCAN_CHUNK_SIZE';
	const SCAN_CHUNK_DATA    = 'SCAN_CHUNK_DATA';
	const SCAN_CHUNK_TRAILER = 'SCAN_CHUNK_TRAILER';
	const SCAN_FINAL_CHUNK   = 'SCAN_FINAL_CHUNK';

	private $upstream;
	private $chunk_remaining_bytes = 0;

	public function __construct( BaseByteReadStream $upstream ) {
		$this->upstream = $upstream;
	}

	protected function internal_pull( $n ): string {
		if ( self::SCAN_FINAL_CHUNK === $this->state ) {
			return '';
		}

		while ( true ) {
			if ( self::SCAN_CHUNK_SIZE === $this->state ) {
				// Try to peek enough bytes to find chunk size and CRLF.
				$this->upstream->pull( 20 );
				$peeked = $this->upstream->peek( 20 );

				if ( strlen( $peeked ) < 3 ) { // Need at least "0\r\n".
					break;
				}

				$chunk_bytes_nb = strspn( $peeked, '0123456789abcdefABCDEF' );
				if ( 0 === $chunk_bytes_nb ) {
					throw new Exception( 'Invalid chunk size format' );
				}

				$clrf_pos = strpos( $peeked, "\r\n", $chunk_bytes_nb );
				if ( false === $clrf_pos ) {
					break;
				}

				// Now we can safely consume the chunk header.
				$chunk_header = $this->upstream->consume( $clrf_pos + 2 );
				$chunk_size   = hexdec( substr( $chunk_header, 0, $chunk_bytes_nb ) );

				if ( 0 === $chunk_size ) {
					$this->state = self::SCAN_FINAL_CHUNK;

					return '';
				}

				$this->chunk_remaining_bytes = $chunk_size;
				$this->state                 = self::SCAN_CHUNK_DATA;
			} elseif ( self::SCAN_CHUNK_DATA === $this->state ) {
				$bytes_to_read = min( $this->chunk_remaining_bytes, 65536 );
				$available     = $this->upstream->pull( $bytes_to_read );
				if ( 0 === $available ) {
					break;
				}

				$data                         = $this->upstream->consume( $available );
				$this->chunk_remaining_bytes -= strlen( $data );
				if ( 0 === $this->chunk_remaining_bytes ) {
					$this->state = self::SCAN_CHUNK_TRAILER;
				}

				return $data;
			} elseif ( self::SCAN_CHUNK_TRAILER === $this->state ) {
				$this->upstream->pull( 2 );
				$trailer = $this->upstream->peek( 2 );

				if ( strlen( $trailer ) < 2 ) {
					break;
				}

				if ( "\r\n" !== $trailer ) {
					throw new Exception( 'Expected CRLF after chunk data' );
				}

				$this->upstream->consume( 2 );
				$this->state = self::SCAN_CHUNK_SIZE;
			}
		}

		return '';
	}

	protected function internal_reached_end_of_data(): bool {
		return self::SCAN_FINAL_CHUNK === $this->state;
	}
}
