<?php

namespace WordPress\Filesystem\Layer;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\Filesystem\Filesystem;

/**
 * Layer base-class that delegates all calls to another filesystem.
 * Every Filesystem layer can extend this class and override just the methods
 * it needs to change.
 */
class Layer implements Filesystem {

	/**
	 * @var Filesystem
	 */
	protected $fs;

	/**
	 * @param  Filesystem $fs  The filesystem to delegate to.
	 */
	public function __construct( Filesystem $fs ) {
		$this->fs = $fs;
	}

	public function exists( $path ) {
		return $this->fs->exists( $path );
	}

	public function is_file( $path ) {
		return $this->fs->is_file( $path );
	}

	public function is_dir( $path ) {
		return $this->fs->is_dir( $path );
	}

	public function mkdir( $path, $options = array() ) {
		return $this->fs->mkdir( $path, $options );
	}

	public function rm( $path, $options = array() ) {
		return $this->fs->rm( $path, $options );
	}

	public function rmdir( $path, $options = array() ) {
		return $this->fs->rmdir( $path, $options );
	}

	public function ls( $path = '/' ) {
		return $this->fs->ls( $path );
	}

	public function open_read_stream( $path ): ByteReadStream {
		return $this->fs->open_read_stream( $path );
	}

	public function open_write_stream( $path ): ByteWriteStream {
		return $this->fs->open_write_stream( $path );
	}

	public function copy( $source, $destination, $options = array() ) {
		return $this->fs->copy( $source, $destination, $options );
	}

	public function rename( $source, $destination, $options = array() ) {
		return $this->fs->rename( $source, $destination, $options );
	}

	public function get_contents( $path ) {
		return $this->fs->get_contents( $path );
	}

	public function put_contents( $path, $contents, $options = array() ) {
		return $this->fs->put_contents( $path, $contents, $options );
	}

	public function get_meta(): array {
		return $this->fs->get_meta();
	}
}
