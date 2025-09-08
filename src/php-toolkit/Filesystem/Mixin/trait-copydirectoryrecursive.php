<?php

namespace WordPress\Filesystem\Mixin;

use function WordPress\Filesystem\copy_between_filesystems;

trait CopyDirectoryRecursive {

	public function copy( $from_path, $to_path, $options = array() ) {
		copy_between_filesystems(
			array(
				'source_filesystem' => $this,
				'source_path'       => $from_path,
				'target_filesystem' => $this,
				'target_path'       => $to_path,
				'recursive'         => $options['recursive'] ?? true,
			)
		);
	}

	abstract public function copy_file( $from_path, $to_path, $options );
}
