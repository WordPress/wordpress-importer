<?php

namespace WordPress\Filesystem\Mixin;

use WordPress\Filesystem\FilesystemException;

/**
 * Implements rename() for files using the copy() and rm() methods.
 */
trait RenameFileViaCopyAndRm {

	public function rename( $from_path, $to_path, $options = array() ) {
		if ( ! $this->is_file( $from_path ) ) {
			throw new FilesystemException( sprintf( 'Path is not a file: %s', $from_path ) );
		}

		$this->copy( $from_path, $to_path, $options );
		$this->rm( $from_path );
	}
}
