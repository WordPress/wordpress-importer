<?php

use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\SQLiteFilesystem;

require_once __DIR__ . '/FilesystemTestCase.php';

class SQLiteFilesystemTest extends FilesystemTestCase {

	protected function create_fs(): Filesystem {
		return SQLiteFilesystem::create();
	}
}
