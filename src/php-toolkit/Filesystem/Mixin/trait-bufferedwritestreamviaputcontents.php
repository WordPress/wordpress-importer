<?php

namespace WordPress\Filesystem\Mixin;

use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\WriteStream\ByteWriteStream;

/**
 * Implements open_write_stream() as a buffered write stream that, upon closing,
 * writes the contents to the filesystem using the put_contents() method.
 */
trait BufferedWriteStreamViaPutContents {

	public function open_write_stream( $path ): ByteWriteStream {
		$fs = $this;

		return new class( $fs, $path ) extends MemoryPipe {
			private $fs;
			private $path;

			public function __construct( $fs, $path ) {
				$this->fs   = $fs;
				$this->path = $path;
			}

			public function close_writing(): void {
				parent::close_writing();
				$pipe_contents = $this->consume_all();
				parent::close_reading();
				$this->fs->put_contents( $this->path, $pipe_contents );
			}
		};
	}
}
