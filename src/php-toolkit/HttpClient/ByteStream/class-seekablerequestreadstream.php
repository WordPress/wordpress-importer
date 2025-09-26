<?php

namespace WordPress\HttpClient\ByteStream;

use WordPress\ByteStream\FileReadWriteStream;
use WordPress\ByteStream\ReadStream\BaseByteReadStream;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\HttpClient\Request;

/**
 * HTTP reader that can seek() within the stream.
 *
 * Downloaded bytes are stored in a temporary file. All the read operations are delegated to that file.
 *
 * – Seek()-ing forward is done by fetching all the bytes up to the target offset.
 * – Seek()-ing backwards is done by seeking within the temporary file.
 */
class SeekableRequestReadStream implements ByteReadStream {


	/**
	 * RequestReadStream
	 */
	private $remote;
	/**
	 * FileReadWriteStream
	 */
	private $cache;
	private $temp;
	private $length_resolved = false;

	public function __construct( $request, array $options = array() ) {
		if ( is_string( $request ) ) {
			$request = new Request( $request );
		}
		$this->remote = new RequestReadStream( $request, $options );
		$this->temp   = $options['cache_path'] ?? tempnam( sys_get_temp_dir(), 'wp_http_cache_' );
		$this->cache  = FileReadWriteStream::from_path( $this->temp, true );
	}

	private function pipe_until( int $offset ): void {
		while ( null === $this->cache->length() || $this->cache->length() < $offset ) {
			$pulled = $this->remote->pull( BaseByteReadStream::CHUNK_SIZE_BYTES );
			if ( 0 === $pulled ) {
				break;
			}
			$this->cache->append_bytes( $this->remote->consume( $pulled ) );
		}
	}

	public function length(): ?int {
		if ( ! $this->length_resolved && null === $this->remote->length() ) {
			/**
			 * Wait for the remote headers before returning the length.
			 *
			 * This is an inconsistency between RequestReadStream::length():
			 *
			 * * RequestReadStream returns null until the remote headers are known.
			 * * SeekableRequestReadStream proactively waits for the remote headers.
			 *
			 * That's because:
			 *
			 * * RequestReadStream class is a lower-level utility where we simply
			 *   expose what's available at the moment. The developer is responsible
			 *   for awaiting the response headers.
			 * * SeekableRequestReadStream is a higher-level tool meant for usage
			 *   when knowing the length is vital, e.g. reading from a remote ZIP file.
			 */
			$this->remote->await_response();
			if ( null === $this->remote->length() ) {
				// The server did not send the Content-Length header.
				// We need to consume the entire stream to infer the length.
				$position = $this->tell();
				$this->consume_all();
				$this->seek( $position );
			}
			$this->length_resolved = true;
		}

		return $this->remote->length();
	}

	public function tell(): int {
		return $this->cache->tell();
	}

	public function seek( int $offset ) {
		$this->pipe_until( $offset );
		$this->cache->seek( $offset );
	}

	public function reached_end_of_data(): bool {
		return $this->remote->reached_end_of_data() && $this->cache->reached_end_of_data();
	}

	public function pull( $n, $mode = self::PULL_NO_MORE_THAN ): int {
		$this->pipe_until( $this->tell() + $n );

		return $this->cache->pull( $n, $mode );
	}

	public function peek( $n ): string {
		$this->pipe_until( $this->tell() + $n );

		return $this->cache->peek( $n );
	}

	public function consume( $n ): string {
		return $this->cache->consume( $n );
	}

	public function consume_all(): string {
		while ( ! $this->remote->reached_end_of_data() ) {
			$pulled = $this->remote->pull( BaseByteReadStream::CHUNK_SIZE_BYTES );
			if ( $pulled > 0 ) {
				$this->cache->append_bytes( $this->remote->consume( $pulled ) );
			}
		}
		$this->cache->close_writing();

		return $this->cache->consume_all();
	}

	public function await_response() {
		return $this->remote->await_response();
	}

	public function close_reading(): void {
		$this->remote->close_reading();
		$this->cache->close_reading();
		@unlink( $this->temp );
	}
}
