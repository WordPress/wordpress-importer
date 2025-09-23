<?php

namespace WordPress\DataLiberation\Importer;

class FileVisitorEvent {
	public $type;
	public $dir;
	public $files;

	const EVENT_ENTER = 'entering';
	const EVENT_EXIT  = 'exiting';

	public function __construct( $type, $dir, $files = array() ) {
		$this->type  = $type;
		$this->dir   = $dir;
		$this->files = $files;
	}

	public function is_entering() {
		return self::EVENT_ENTER === $this->type;
	}

	public function is_exiting() {
		return self::EVENT_EXIT === $this->type;
	}
}
