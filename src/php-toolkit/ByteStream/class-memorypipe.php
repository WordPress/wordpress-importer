<?php

namespace WordPress\ByteStream;

use WordPress\ByteStream\ReadStream\BaseByteReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;

class MemoryPipe extends BaseByteReadStream implements ByteWriteStream {

	protected $is_writing_closed;

	public function __construct( ?string $bytes = null, $expected_length = null ) {
		if ( null !== $bytes && strlen( $bytes ) > 0 && null !== $expected_length ) {
			throw new ByteStreamException( 'A MemoryPipe accepts either a non-empty string representing the entire data, or an expected length when the data is not available yet. It does not accept both arguments.' );
		}
		if ( null !== $bytes ) {
			$this->buffer          = $bytes;
			$this->expected_length = strlen( $bytes );
			// If we have a full buffer, it's already in memory and we don't need.
			// to clean up old data as we stream it.
			// If we did clean up old data, we would lose the ability to seek() to.
			// the beginning of the buffer.
			$this->max_lookbehind_bytes = PHP_INT_MAX;
		} elseif ( null !== $expected_length ) {
			$this->expected_length = $expected_length;
		}
	}

	public function close_writing(): void {
		$this->is_writing_closed = true;
		$this->expected_length   = $this->bytes_already_forgotten + strlen( $this->buffer );
	}

	public function append_bytes( string $new_bytes ): void {
		if ( $this->is_writing_closed ) {
			throw new ByteStreamException( 'Cannot append bytes to a closed stream.' );
		}
		if ( null !== $this->length() && $this->tell() + strlen( $new_bytes ) > $this->length() ) {
			throw new ByteStreamException( 'Appending bytes to the stream would exceed the expected length.' );
		}
		$this->buffer .= $new_bytes;
	}

	protected function pull_no_more_than( $n ): int {
		if ( $this->count_consumable_bytes() > 0 ) {
			return min( $n, $this->count_consumable_bytes() );
		}
		throw new NotEnoughDataException( 'Cannot pull bytes after exhausting the buffer from a MemoryPipe. You are likely missing a $pipe->reached_end_of_data() check before the pull() call.' );
	}

	protected function pull_exactly( $n ): int {
		if ( $this->count_consumable_bytes() >= $n ) {
			return min( $n, $this->count_consumable_bytes() );
		}
		throw new NotEnoughDataException( 'Cannot pull bytes from a MemoryPipe.' );
	}

	protected function internal_pull( $n ): string {
		throw new NotEnoughDataException( 'Cannot pull bytes from a MemoryPipe.' );
	}

	protected function seek_outside_of_buffer( int $target_offset ): void {
		throw new NotEnoughDataException( 'Cannot seek past the available data. Call append_bytes() first.' );
	}

	public function length(): ?int {
		return $this->expected_length;
	}
}
