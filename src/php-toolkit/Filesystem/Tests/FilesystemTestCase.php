<?php

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\FilesystemException;

abstract class FilesystemTestCase extends TestCase {

	protected $fs;

	protected function setUp(): void {
		$this->fs = $this->create_fs();
	}

	abstract protected function create_fs(): Filesystem;

	public function testLs() {
		$this->fs->mkdir( '/test-dir' );
		$this->fs->put_contents( '/test-file.txt', 'test' );

		$contents = $this->fs->ls( '/' );
		sort( $contents );

		$this->assertEquals( array( 'test-dir', 'test-file.txt' ), $contents );
	}

	public function testIsDir() {
		$this->fs->mkdir( '/test-dir' );

		$this->assertTrue( $this->fs->is_dir( '/test-dir' ) );
		$this->assertFalse( $this->fs->is_dir( '/nonexistent' ) );
	}

	public function testIsFile() {
		$this->fs->put_contents( '/test.txt', 'test' );

		$this->assertTrue( $this->fs->is_file( '/test.txt' ) );
		$this->assertFalse( $this->fs->is_file( '/nonexistent.txt' ) );
	}

	public function testExists() {
		$this->fs->put_contents( '/test.txt', 'test' );
		$this->fs->mkdir( '/test-dir' );

		$this->assertTrue( $this->fs->exists( '/test.txt' ) );
		$this->assertTrue( $this->fs->exists( '/test-dir' ) );
		$this->assertFalse( $this->fs->exists( '/nonexistent' ) );
	}

	public function testMkdir() {
		$this->fs->mkdir( '/new-dir' );
		$this->assertTrue( $this->fs->is_dir( '/new-dir' ) );

		$this->expectException( FilesystemException::class );
		$this->fs->mkdir( '/new-dir' );
	}

	public function testMkdirRecursive() {
		$this->fs->mkdir(
			'/new-dir/sub-dir/more/nested/layers',
			array(
				'recursive' => true,
			)
		);
		$this->assertTrue( $this->fs->is_dir( '/new-dir/sub-dir/more/nested/layers' ) );
	}

	public function testRmRemovesExistingFile() {
		$this->fs->put_contents( '/test.txt', 'test' );
		$this->fs->rm( '/test.txt' );
		$this->assertFalse( $this->fs->exists( '/test.txt' ) );
	}

	public function testRmThrowsOnUnexistentFile() {
		$this->expectException( FilesystemException::class );
		$this->fs->rm( '/nonexistent.txt' );
	}

	public function testRmdirRemovesExistingDirectory() {
		$this->fs->mkdir( '/test-dir' );

		$this->assertTrue( $this->fs->is_dir( '/test-dir' ) );
		$this->fs->rmdir( '/test-dir' );
		$this->assertFalse( $this->fs->exists( '/test-dir' ) );
	}

	public function testRmdirThrowsOnUnexistentDirectory() {
		$this->expectException( FilesystemException::class );
		$this->fs->rmdir( '/nonexistent' );
	}

	public function testRmdirRecursive() {
		$this->fs->mkdir( '/parent/child/grandchild', array( 'recursive' => true ) );
		$this->fs->put_contents( '/parent/test.txt', 'test' );

		$this->fs->rmdir( '/parent', array( 'recursive' => true ) );
		$this->assertFalse( $this->fs->exists( '/parent' ) );
	}

	public function testPutContents() {
		$this->fs->put_contents( '/test.txt', 'Hello World' );
		$this->assertEquals( 'Hello World', $this->fs->get_contents( '/test.txt' ) );
	}

	public function testOpenWriteStream() {
		$writer = $this->fs->open_write_stream( '/test.txt' );
		$writer->append_bytes( 'Hello World' );
		$writer->close_writing();

		$this->assertEquals( 'Hello World', $this->fs->get_contents( '/test.txt' ) );
	}

	public function testOpenReadStream() {
		$this->fs->put_contents( '/test.txt', 'Hello World' );

		$reader  = $this->fs->open_read_stream( '/test.txt' );
		$content = $reader->consume_all();
		$reader->close_writing();

		$this->assertEquals( 'Hello World', $content );
	}

	public function testRename() {
		$this->fs->put_contents( '/old.txt', 'test' );

		$this->fs->rename( '/old.txt', '/new.txt' );

		$this->assertFalse( $this->fs->exists( '/old.txt' ) );
		$this->assertTrue( $this->fs->exists( '/new.txt' ) );
		$this->assertEquals( 'test', $this->fs->get_contents( '/new.txt' ) );
	}

	public function testCopyFile() {
		$this->fs->put_contents( '/source.txt', 'test content' );

		$this->fs->copy( '/source.txt', '/dest.txt' );

		$this->assertTrue( $this->fs->exists( '/source.txt' ) );
		$this->assertTrue( $this->fs->exists( '/dest.txt' ) );
		$this->assertEquals( 'test content', $this->fs->get_contents( '/dest.txt' ) );
	}

	public function testCopyFileFailsWhenSourceDoesNotExist() {
		$this->expectException( FilesystemException::class );
		$this->fs->copy( '/nonexistent.txt', '/dest.txt' );
	}

	public function testCopyDirectoryRecursively() {
		// Create source directory structure
		$this->fs->mkdir( '/src/subdir', array( 'recursive' => true ) );
		$this->fs->put_contents( '/src/file1.txt', 'content1' );
		$this->fs->put_contents( '/src/subdir/file2.txt', 'content2' );

		$this->fs->copy( '/src', '/dest', array( 'recursive' => true ) );

		// Verify directory structure was copied
		$this->assertTrue( $this->fs->is_dir( '/dest' ) );
		$this->assertTrue( $this->fs->is_dir( '/dest/subdir' ) );
		$this->assertTrue( $this->fs->is_file( '/dest/file1.txt' ) );
		$this->assertTrue( $this->fs->is_file( '/dest/subdir/file2.txt' ) );

		// Verify file contents
		$this->assertEquals( 'content1', $this->fs->get_contents( '/dest/file1.txt' ) );
		$this->assertEquals( 'content2', $this->fs->get_contents( '/dest/subdir/file2.txt' ) );
	}

	public function testCopyDirectoryFailsWhenSourceDoesNotExist() {
		$this->expectException( FilesystemException::class );
		$this->fs->copy( '/nonexistent', '/dest', array( 'recursive' => true ) );
	}
}
