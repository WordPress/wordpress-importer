<?php

namespace WordPress\ByteStream\WriteStream;

use ArrayAccess;
use ReturnTypeWillChange;
use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\ByteTransformer\ByteTransformer;

class TransformedWriteStream implements ByteWriteStream, ArrayAccess {

	/**
	 * @var ByteWriteStream
	 */
	private $writer;

	/**
	 * @var ByteTransformer[]
	 */
	private $filters = array();

	public function __construct( ByteWriteStream $writer, array $filters = array() ) {
		$this->writer  = $writer;
		$this->filters = $filters;
	}

	public function append_bytes( string $chunk ): void {
		foreach ( $this->filters as $filter ) {
			$chunk = $filter->filter_bytes( $chunk );
			if ( false === $chunk ) {
				return;
			}
		}

		$this->writer->append_bytes( $chunk );
	}

	public function get_downstream_writer(): ByteWriteStream {
		return $this->writer;
	}

	public function close_writing(): void {
		foreach ( $this->filters as $filter ) {
			$this->writer->append_bytes(
				$filter->flush()
			);
		}
	}

	/**
	 *
	 * @param  string $offset  The offset to get.
	 * @throws ByteStreamException If the filter is not found.
	 */
	#[ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		if ( ! isset( $this->filters[ $offset ] ) ) {
			throw new ByteStreamException( esc_html( sprintf( 'Filter %s not found', $offset ) ) );
		}

		return $this->filters[ $offset ];
	}

	/** @disregard P1038 */
	#[ReturnTypeWillChange]
	public function offsetExists( $offset ) {
		return isset( $this->filters[ $offset ] );
	}

	/** @disregard P1038 */
	#[ReturnTypeWillChange]
	public function offsetSet( $offset, $value ) {
		$this->filters[ $offset ] = $value;
	}

	/** @disregard P1038 */
	#[ReturnTypeWillChange]
	public function offsetUnset( $offset ) {
		unset( $this->filters[ $offset ] );
	}
}
