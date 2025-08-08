<?php

namespace WordPress\ByteStream\ReadStream;

use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\NotEnoughDataException;

abstract class BaseByteReadStream implements ByteReadStream {

	const CHUNK_SIZE = 100;// 64 * 1024;

	protected $buffer_size = 2048;

	protected $buffer = '';
	protected $offset_in_current_buffer = 0;
	protected $bytes_already_forgotten = 0;
	protected $is_closed = false;
	protected $expected_length = null;

	public function length() {
		return $this->expected_length;
	}

	public function pull( $n = self::CHUNK_SIZE, $mode = self::PULL_NO_MORE_THAN ) {
		switch ( $mode ) {
			case self::PULL_NO_MORE_THAN:
			case self::PULL_EXACTLY:
				break;
			default:
				throw new ByteStreamException( 'Invalid pull mode' );
		}
		if ( $this->is_closed ) {
			throw new ByteStreamException( 'Cannot pull() on a closed producer' );
		}

		if ( $n === 0 ) {
			return 0;
		}

		if ( $n < 0 ) {
			throw new ByteStreamException( 'Cannot pull a negative number of bytes' );
		}

		if ( $n <= $this->count_consumable_bytes() ) {
			return $n;
		}

		if ( $this->reached_end_of_data() ) {
			if ( $mode === ByteReadStream::PULL_EXACTLY ) {
				throw new NotEnoughDataException( 'End of data reached while pulling' );
			}

			return 0;
		}

		if ( $mode === ByteReadStream::PULL_NO_MORE_THAN ) {
			return $this->pull_no_more_than( $n );
		}

		return $this->pull_exactly( $n );
	}

	protected function pull_exactly( $n ) {
		$empty_pulls = 0;
		while ( $this->count_consumable_bytes() < $n ) {
			$consumable_before = $this->count_consumable_bytes();
			$this->pull_no_more_than( $n );
			$consumable_after = $this->count_consumable_bytes();

			if ( $consumable_after === $consumable_before ) {
				++ $empty_pulls;
				if ( $this->reached_end_of_data() ) {
					throw new NotEnoughDataException( 'End of data reached while pulling' );
				}
			}

			if ( $empty_pulls > 4 ) {
				throw new NotEnoughDataException( '4 empty pulls in a row, we are probably at the end of the data' );
			}
		}

		return $n;
	}

	protected function pull_no_more_than( $n ) {
		$this->buffer .= $this->internal_pull( self::CHUNK_SIZE );

		return min( $n, $this->count_consumable_bytes() );
	}

	public function consume_all(): string {
		$body = '';
		while ( true ) {
			if ( $this->reached_end_of_data() ) {
				return $body;
			}
			$consumable = $this->pull( 64 * 1024 );
			$body       .= $this->consume( $consumable );
		}
	}

	protected function count_consumable_bytes() {
		return strlen( $this->buffer ) - $this->offset_in_current_buffer;
	}

	abstract protected function internal_pull( $n );

	public function peek( $n ) {
		return substr( $this->buffer, $this->offset_in_current_buffer, $n );
	}

	public function consume( $n ) {
		if ( strlen( $this->buffer ) < $this->offset_in_current_buffer + $n ) {
			throw new NotEnoughDataException( 'Cannot consume more bytes than available in the buffer.' );
		}
		$bytes                          = substr( $this->buffer, $this->offset_in_current_buffer, $n );
		$this->offset_in_current_buffer += $n;
		if ( $this->offset_in_current_buffer > $this->buffer_size ) {
			$overflow                       = $this->offset_in_current_buffer - $this->buffer_size;
			$this->offset_in_current_buffer -= $overflow;
			$this->bytes_already_forgotten  += $overflow;
			$this->buffer                   = substr( $this->buffer, $overflow );
		}

		return $bytes;
	}

	public function seek( int $target_offset ) {
		// We have that offset in the buffer, let's just update the pointer
		if ( $target_offset >= $this->bytes_already_forgotten && $target_offset <= $this->bytes_already_forgotten + strlen( $this->buffer ) ) {
			$this->offset_in_current_buffer = $target_offset - $this->bytes_already_forgotten;

			return;
		}
		if ( null !== $this->length() && $target_offset > $this->length() ) {
			$length = $this->length();
			throw new NotEnoughDataException( sprintf( 'Cannot seek to past the stream length (seeked to %d, stream length is %d).',
				$target_offset, $length ) );
		}

		if ( $target_offset < 0 ) {
			throw new ByteStreamException( 'Cannot seek to a negative offset' );
		}

		// Seeking outside of buffer range, we need a producer-specific implementation
		$this->seek_outside_of_buffer( $target_offset );
	}

	protected function seek_outside_of_buffer( int $target_offset ) {
		throw new ByteStreamException( 'Cannot seek outside of the buffered range' );
	}

	public function tell() {
		return $this->bytes_already_forgotten + $this->offset_in_current_buffer;
	}

	public function reached_end_of_data() {
		if ( $this->is_closed ) {
			return true;
		}
		if ( $this->count_consumable_bytes() > 0 ) {
			return false;
		}
		if ( null !== $this->length() ) {
			return $this->tell() >= $this->length();
		}

		return $this->internal_reached_end_of_data();
	}

	protected function internal_reached_end_of_data() {
		return false;
	}

	public function close_reading() {
		$this->is_closed = true;
	}

	protected function internal_close_reading() {
	}
}
