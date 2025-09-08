<?php

namespace WordPress\Filesystem\Mixin;

use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\Filesystem\FilesystemException;

trait ReadOnlyFilesystem {

	public function mkdir( $path, $options = array() ) {
		throw new FilesystemException(
			sprintf( 'Cannot create directory: %s', $path )
		);
	}

	public function rm( $path, $options = array() ) {
		throw new FilesystemException(
			sprintf( 'Cannot remove file: %s', $path )
		);
	}

	public function rmdir( $path, $options = array() ) {
		throw new FilesystemException(
			sprintf( 'Cannot remove directory: %s', $path )
		);
	}

	public function put_contents( $path, $contents, $options = array() ) {
		throw new FilesystemException(
			sprintf( 'Cannot write to file: %s', $path )
		);
	}

	public function rename( $old_path, $new_path, $options = array() ) {
		throw new FilesystemException(
			sprintf( 'Cannot rename file: %s to %s', $old_path, $new_path )
		);
	}

	public function delete( $path, $options = array() ) {
		throw new FilesystemException(
			sprintf( 'Cannot delete file: %s', $path )
		);
	}

	public function copy( $source_path, $destination_path, $options = array() ) {
		throw new FilesystemException(
			sprintf( 'Cannot copy file: %s to %s', $source_path, $destination_path )
		);
	}

	public function open_write_stream( $path ): ByteWriteStream {
		throw new FilesystemException(
			sprintf( 'Cannot open write stream: %s', $path )
		);
	}
}
