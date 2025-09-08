<?php

use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\InMemoryFilesystem;

require_once __DIR__ . '/FilesystemTestCase.php';

class InMemoryFilesystemTest extends FilesystemTestCase {

	protected function create_fs(): Filesystem {
		return InMemoryFilesystem::create();
	}
}
