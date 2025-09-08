<?php

namespace WordPress\Filesystem\Mixin;

trait CopyRecursiveViaStreaming {

	use CopyDirectoryRecursive, CopyFileViaStreaming {
		CopyFileViaStreaming::copy as copy_file;
		CopyDirectoryRecursive::copy insteadof CopyFileViaStreaming;
	}
}
