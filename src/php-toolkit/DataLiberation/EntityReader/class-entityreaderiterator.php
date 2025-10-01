<?php

namespace WordPress\DataLiberation\EntityReader;

use Iterator;
use ReturnTypeWillChange;
use WordPress\DataLiberation\DataLiberationException;

/**
 * An iterator that reads entities from a WP_Entity_Reader.
 */
class EntityReaderIterator implements Iterator {

	/**
	 * @var EntityReader
	 */
	private $entity_reader;
	private $is_initialized = false;
	private $key            = 0;

	public function __construct( EntityReader $entity_reader ) {
		$this->entity_reader = $entity_reader;
	}

	public function get_entity_reader() {
		return $this->entity_reader;
	}

	#[ReturnTypeWillChange]
	public function current() {
		$this->ensure_initialized();

		return $this->entity_reader->get_entity();
	}

	#[ReturnTypeWillChange]
	public function next() {
		$this->ensure_initialized();
		$this->advance_to_next_entity();
	}

	#[ReturnTypeWillChange]
	public function key() {
		$this->ensure_initialized();

		return $this->key;
	}

	#[ReturnTypeWillChange]
	public function valid() {
		$this->ensure_initialized();
		if ( $this->entity_reader->is_finished() ) {
			return false;
		}
		// @TODO: Remove these checks once we figure out why.
		// WXREntityReader says next_entity() succeeds.
		// one time once the data stream is exhausted.
		$entity = $this->entity_reader->get_entity();
		if ( ! $entity ) {
			return false;
		}
		if ( ! $entity->get_type() ) {
			return false;
		}

		return true;
	}

	#[ReturnTypeWillChange]
	public function rewind() {
		// rewind is not supported except for the first rewind call that initializes the iterator.
		if ( $this->is_initialized ) {
			throw new DataLiberationException( 'EntityReaderIterator does not support rewinding.' );
		}
		$this->is_initialized = true;
		$this->advance_to_next_entity();
	}

	private function ensure_initialized() {
		if ( ! $this->is_initialized ) {
			$this->is_initialized = true;
			$this->advance_to_next_entity();
		}
	}

	private function advance_to_next_entity() {
		if ( $this->entity_reader->next_entity() ) {
			++$this->key;
		}
	}

	public function get_reentrancy_cursor() {
		return $this->entity_reader->get_reentrancy_cursor();
	}
}
