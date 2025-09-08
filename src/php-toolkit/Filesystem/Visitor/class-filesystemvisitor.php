<?php

namespace WordPress\Filesystem\Visitor;

use ArrayIterator;
use WordPress\Filesystem\Filesystem;

use function WordPress\Filesystem\wp_join_unix_paths;

class FilesystemVisitor {
	private $filesystem;
	private $directories = array();
	private $files       = array();
	private $current_event;
	private $iterator_stack = array();
	private $current_iterator;
	private $depth = - 1;

	public function __construct( Filesystem $filesystem ) {
		$this->filesystem       = $filesystem;
		$this->iterator_stack[] = $this->create_iterator();
	}

	public function get_current_depth() {
		return $this->depth;
	}

	public function next() {
		while ( ! empty( $this->iterator_stack ) ) {
			$this->current_iterator = end( $this->iterator_stack );

			if ( ! $this->current_iterator->valid() ) {
				array_pop( $this->iterator_stack );
				continue;
			}
			$current = $this->current_iterator->current();
			$this->current_iterator->next();

			if ( ! ( $current instanceof FileVisitorEvent ) ) {
				// It's a directory path, push a new iterator onto the stack.
				$this->iterator_stack[] = $this->create_iterator( $current );
				continue;
			}

			if ( $current->is_entering() ) {
				++$this->depth;
			}
			$this->current_event = $current;
			if ( $current->is_exiting() ) {
				--$this->depth;
			}

			return true;
		}

		return false;
	}

	public function get_event(): ?FileVisitorEvent {
		return $this->current_event;
	}

	private function create_iterator( $dir = '/' ) {
		$this->directories = array();
		$this->files       = array();

		$filesystem = $this->filesystem;
		$children   = $filesystem->ls( $dir );
		if ( false === $children ) {
			return new ArrayIterator( array() );
		}

		foreach ( $children as $child ) {
			if ( $filesystem->is_dir( wp_join_unix_paths( $dir, $child ) ) ) {
				$this->directories[] = $child;
				continue;
			}
			$this->files[] = $child;
		}

		$events   = array();
		$events[] = new FileVisitorEvent( FileVisitorEvent::EVENT_ENTER, $dir, $this->files );
		$prefix   = '/' === $dir ? '' : $dir;
		foreach ( $this->directories as $directory ) {
			$events[] = $prefix . '/' . $directory; // Placeholder for recursion.
		}
		$events[] = new FileVisitorEvent( FileVisitorEvent::EVENT_EXIT, $dir );

		return new ArrayIterator( $events );
	}
}
