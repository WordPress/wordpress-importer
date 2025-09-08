<?php

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\FilesystemException;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_join_unix_paths;

class LocalFilesystemTest extends TestCase {

	private $fs;
	private $test_dir;

	protected function setUp(): void {
		$this->test_dir = wp_join_unix_paths( sys_get_temp_dir(), 'wp-filesystem-test-' . uniqid() );
		mkdir( $this->test_dir );
		$this->fs = LocalFilesystem::create( $this->test_dir );
	}

	protected function tearDown(): void {
		if ( is_dir( $this->test_dir ) ) {
			$this->removeDirectory( $this->test_dir );
		}
	}

	private function removeDirectory( $dir ) {
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = wp_join_unix_paths( $dir, $file );
			is_dir( $path ) ? $this->removeDirectory( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	public function testLs() {
		mkdir( wp_join_unix_paths( $this->test_dir, 'test-dir' ) );
		file_put_contents( wp_join_unix_paths( $this->test_dir, 'test-file.txt' ), 'test' );

		$contents = $this->fs->ls( '/' );
		sort( $contents );

		$this->assertEquals( array( 'test-dir', 'test-file.txt' ), $contents );
	}

	public function testIsDir() {
		mkdir( wp_join_unix_paths( $this->test_dir, 'test-dir' ) );

		$this->assertTrue( $this->fs->is_dir( '/test-dir' ) );
		$this->assertFalse( $this->fs->is_dir( '/nonexistent' ) );
	}

	public function testIsFile() {
		file_put_contents( wp_join_unix_paths( $this->test_dir, 'test.txt' ), 'test' );

		$this->assertTrue( $this->fs->is_file( '/test.txt' ) );
		$this->assertFalse( $this->fs->is_file( '/nonexistent.txt' ) );
	}

	public function testExists() {
		file_put_contents( wp_join_unix_paths( $this->test_dir, 'test.txt' ), 'test' );
		mkdir( wp_join_unix_paths( $this->test_dir, 'test-dir' ) );

		$this->assertTrue( $this->fs->exists( '/test.txt' ) );
		$this->assertTrue( $this->fs->exists( '/test-dir' ) );
		$this->assertFalse( $this->fs->exists( '/nonexistent' ) );
	}

	public function testMkdir() {
		$this->fs->mkdir( '/new-dir' );
		$this->assertTrue( is_dir( wp_join_unix_paths( $this->test_dir, 'new-dir' ) ) );

		$this->expectException( FilesystemException::class );
		$this->fs->mkdir( '/new-dir' );
	}

	public function testMkdirRecursive() {
		$this->fs->mkdir( '/parent/child/grandchild', array( 'recursive' => true ) );
		$this->assertTrue( is_dir( wp_join_unix_paths( $this->test_dir, 'parent/child/grandchild' ) ) );
	}

	public function testRmRemovesExistingFile() {
		file_put_contents( wp_join_unix_paths( $this->test_dir, 'test.txt' ), 'test' );
		$this->fs->rm( '/test.txt' );
		$this->assertFalse( file_exists( wp_join_unix_paths( $this->test_dir, 'test.txt' ) ) );
	}

	public function testRmThrowsOnUnexistentFile() {
		$this->expectException( FilesystemException::class );
		/**
		 * PHPUnit is inconsistent with how it handles warnings.
		 * Some versions convert them to exceptions, others don't.
		 * Let's make sure to rethrow any exceptions as a FilesystemException
		 * for consistent handling across different PHPUnit releases.
		 *
		 * @see https://github.com/sebastianbergmann/phpunit/issues/5062
		 */
		try {
			$this->fs->rm( '/nonexistent.txt' );
		} catch ( Exception $e ) {
			throw new FilesystemException( 'Failed to remove file', 0, $e );
		}
	}

	public function testRmdirRemovesExistingDirectory() {
		mkdir( wp_join_unix_paths( $this->test_dir, 'test-dir' ) );

		$this->assertTrue( is_dir( wp_join_unix_paths( $this->test_dir, 'test-dir' ) ) );
		$this->fs->rmdir( '/test-dir' );
		$this->assertFalse( is_dir( wp_join_unix_paths( $this->test_dir, 'test-dir' ) ) );
	}

	public function testRmdirThrowsOnUnexistentDirectory() {
		$this->expectException( FilesystemException::class );
		/**
		 * PHPUnit is inconsistent with how it handles warnings.
		 * Some versions convert them to exceptions, others don't.
		 * Let's make sure to rethrow any exceptions as a FilesystemException
		 * for consistent handling across different PHPUnit releases.
		 *
		 * @see https://github.com/sebastianbergmann/phpunit/issues/5062
		 */
		try {
			$this->fs->rmdir( '/nonexistent.txt' );
		} catch ( Exception $e ) {
			throw new FilesystemException( 'Failed to remove file', 0, $e );
		}
	}

	public function testRmdirRecursive() {
		mkdir( wp_join_unix_paths( $this->test_dir, 'parent/child/grandchild' ), 0777, true );
		file_put_contents( wp_join_unix_paths( $this->test_dir, 'parent/test.txt' ), 'test' );

		$this->fs->rmdir( '/parent', array( 'recursive' => true ) );
		$this->assertFalse( is_dir( wp_join_unix_paths( $this->test_dir, 'parent' ) ) );
	}

	public function testPutContents() {
		$this->fs->put_contents( '/test.txt', 'Hello World' );
		$this->assertEquals( 'Hello World', file_get_contents( wp_join_unix_paths( $this->test_dir, 'test.txt' ) ) );
	}

	public function testOpenWriteStream() {
		$writer = $this->fs->open_write_stream( '/test.txt' );
		$writer->append_bytes( 'Hello World' );
		$writer->close_writing();

		$this->assertEquals( 'Hello World', file_get_contents( wp_join_unix_paths( $this->test_dir, 'test.txt' ) ) );
	}

	public function testOpenReadStream() {
		file_put_contents( wp_join_unix_paths( $this->test_dir, 'test.txt' ), 'Hello World' );

		$reader  = $this->fs->open_read_stream( '/test.txt' );
		$content = $reader->consume_all();
		$reader->close_reading();

		$this->assertEquals( 'Hello World', $content );
	}

	public function testRename() {
		file_put_contents( wp_join_unix_paths( $this->test_dir, 'old.txt' ), 'test' );

		$this->fs->rename( '/old.txt', '/new.txt' );

		$this->assertFalse( file_exists( wp_join_unix_paths( $this->test_dir, 'old.txt' ) ) );
		$this->assertTrue( file_exists( wp_join_unix_paths( $this->test_dir, 'new.txt' ) ) );
		$this->assertEquals( 'test', file_get_contents( wp_join_unix_paths( $this->test_dir, 'new.txt' ) ) );
	}

	public function testCopyFile() {
		file_put_contents( wp_join_unix_paths( $this->test_dir, 'source.txt' ), 'test content' );

		$this->fs->copy( '/source.txt', '/dest.txt' );

		$this->assertTrue( file_exists( wp_join_unix_paths( $this->test_dir, 'source.txt' ) ) );
		$this->assertTrue( file_exists( wp_join_unix_paths( $this->test_dir, 'dest.txt' ) ) );
		$this->assertEquals( 'test content', file_get_contents( wp_join_unix_paths( $this->test_dir, 'dest.txt' ) ) );
	}

	public function testCopyFileFailsWhenSourceDoesNotExist() {
		$this->expectException( FilesystemException::class );
		$this->fs->copy( '/nonexistent.txt', '/dest.txt' );
	}

	public function testCopyDirectoryRecursively() {
		// Create source directory structure
		mkdir( wp_join_unix_paths( $this->test_dir, 'src/subdir' ), 0777, true );
		file_put_contents( wp_join_unix_paths( $this->test_dir, 'src/file1.txt' ), 'content1' );
		file_put_contents( wp_join_unix_paths( $this->test_dir, 'src/subdir/file2.txt' ), 'content2' );

		$this->fs->copy( '/src', '/dest', array( 'recursive' => true ) );

		// Verify directory structure was copied
		$this->assertTrue( is_dir( wp_join_unix_paths( $this->test_dir, 'dest' ) ) );
		$this->assertTrue( is_dir( wp_join_unix_paths( $this->test_dir, 'dest/subdir' ) ) );
		$this->assertTrue( is_file( wp_join_unix_paths( $this->test_dir, 'dest/file1.txt' ) ) );
		$this->assertTrue( is_file( wp_join_unix_paths( $this->test_dir, 'dest/subdir/file2.txt' ) ) );

		// Verify file contents
		$this->assertEquals( 'content1', file_get_contents( wp_join_unix_paths( $this->test_dir, 'dest/file1.txt' ) ) );
		$this->assertEquals( 'content2', file_get_contents( wp_join_unix_paths( $this->test_dir, 'dest/subdir/file2.txt' ) ) );
	}

	public function testCopyDirectoryFailsWhenSourceDoesNotExist() {
		$this->expectException( FilesystemException::class );
		$this->fs->copy( '/nonexistent', '/dest', array( 'recursive' => true ) );
	}
}
