<?php

namespace WordPress\Filesystem\Mixin;

use function WordPress\Filesystem\wp_join_unix_paths;

trait RmdirRecursive {

	public function rmdir( $path, $options = array() ) {
		$recursive = $options['recursive'] ?? false;
		if ( $recursive ) {
			foreach ( $this->ls( $path ) as $child ) {
				$child_path = wp_join_unix_paths( $path, $child );
				if ( $this->is_dir( $child_path ) ) {
					$this->rmdir( $child_path, $options );
				} else {
					$this->rm( $child_path );
				}
			}
		}
		$this->rmdir_single( $path, $options );
	}

	abstract protected function rmdir_single( $path, $options = array() );
}
