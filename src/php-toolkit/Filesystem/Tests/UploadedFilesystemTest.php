<?php

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\InMemoryFilesystem;
use WordPress\Filesystem\UploadedFilesystem;

class UploadedFilesystemTest extends TestCase {

	protected function create_fs( $tree, $files ): Filesystem {
		$uploads_fs = InMemoryFilesystem::create();
		$uploads_fs->mkdir( '/tmp' );
		foreach ( $files as $file ) {
			$uploads_fs->put_contents( $file['tmp_name'], $file['contents'] );
		}

		$params = array(
			'tree' => json_encode( $tree ),
		);

		$request = new class( $params, $files ) {
			private $params;
			private $files;

			public function __construct( $params, $files ) {
				$this->params = $params;
				$this->files  = $files;
			}

			public function get_param( $key ) {
				return $this->params[ $key ] ?? null;
			}

			public function get_file_params() {
				return $this->files;
			}
		};

		return UploadedFilesystem::create(
			$request,
			'tree',
			array(
				'uploads_fs' => $uploads_fs,
			)
		);
	}

	public function testIsFile() {
		$fs = $this->create_fs(
			array(
				array(
					'type'    => 'file',
					'name'    => 'README.md',
					'content' => '@file:file1',
				),
			),
			array(
				'file1' => array(
					'name'     => 'README.md',
					'contents' => '## This is WordPress readme',
					'tmp_name' => '/tmp/file_892378.txt',
					'error'    => UPLOAD_ERR_OK,
				),
			)
		);

		$this->assertTrue( $fs->is_file( '/README.md' ) );
		$this->assertFalse( $fs->is_file( '/nonexistent.txt' ) );
	}

	public function testIsDir() {
		$fs = $this->create_fs(
			array(
				array(
					'type'     => 'directory',
					'name'     => 'src',
					'children' => array(),
				),
			),
			array()
		);

		$this->assertTrue( $fs->is_dir( '/src' ) );
		$this->assertFalse( $fs->is_dir( '/nonexistent' ) );
	}

	public function testExists() {
		$fs = $this->create_fs(
			array(
				array(
					'type'    => 'file',
					'name'    => 'README.md',
					'content' => '@file:file1',
				),
				array(
					'type'     => 'directory',
					'name'     => 'src',
					'children' => array(
						array(
							'type'    => 'file',
							'name'    => 'index.php',
							'content' => '@file:file2',
						),
					),
				),
			),
			array(
				'file1' => array(
					'name'     => 'README.md',
					'contents' => '## This is WordPress readme',
					'tmp_name' => '/tmp/file_892378.txt',
					'error'    => UPLOAD_ERR_OK,
				),
				'file2' => array(
					'name'     => 'index.php',
					'contents' => '<?php echo "Hello World"; ?>',
					'tmp_name' => '/tmp/file_892379.txt',
					'error'    => UPLOAD_ERR_OK,
				),
			)
		);

		$this->assertTrue( $fs->exists( '/README.md' ) );
		$this->assertTrue( $fs->exists( '/src' ) );
		$this->assertTrue( $fs->exists( '/src/index.php' ) );
		$this->assertFalse( $fs->exists( '/nonexistent' ) );
	}

	public function testGetContents() {
		$fs = $this->create_fs(
			array(
				array(
					'type'    => 'file',
					'name'    => 'README.md',
					'content' => '@file:file1',
				),
			),
			array(
				'file1' => array(
					'name'     => 'README.md',
					'contents' => '## This is WordPress readme',
					'tmp_name' => '/tmp/file_892378.txt',
					'error'    => UPLOAD_ERR_OK,
				),
			)
		);

		$this->assertEquals( '## This is WordPress readme', $fs->get_contents( '/README.md' ) );
	}

	public function testListFilesFlat() {
		$fs = $this->create_fs(
			array(
				array(
					'type'    => 'file',
					'name'    => 'README.md',
					'content' => '@file:file1',
				),
			),
			array(
				'file1' => array(
					'name'     => 'README.md',
					'contents' => '## This is WordPress readme',
					'tmp_name' => '/tmp/file_892378.txt',
					'error'    => UPLOAD_ERR_OK,
				),
			)
		);

		$this->assertEquals( array( 'README.md' ), $fs->ls( '/' ) );
	}

	public function testListFilesRecursive() {
		$fs = $this->create_fs(
			array(
				array(
					'type'    => 'file',
					'name'    => 'README.md',
					'content' => '@file:file1',
				),
				array(
					'type'     => 'directory',
					'name'     => 'src',
					'children' => array(
						array(
							'type'    => 'file',
							'name'    => 'index.php',
							'content' => '@file:file2',
						),
						array(
							'type'    => 'file',
							'name'    => 'style.css',
							'content' => '#main-div { color: red; }',
						),
						array(
							'type'     => 'directory',
							'name'     => 'js',
							'children' => array(
								array(
									'type'    => 'file',
									'name'    => 'script.js',
									'content' => 'console.log("Hello, world!");',
								),
							),
						),
					),
				),
			),
			array(
				'file1' => array(
					'name'     => 'README.md',
					'contents' => '## This is WordPress readme',
					'tmp_name' => '/tmp/file_892378.txt',
					'error'    => UPLOAD_ERR_OK,
				),
				'file2' => array(
					'name'     => 'index.php',
					'contents' => '<?php echo "Hello, world!";',
					'tmp_name' => '/tmp/file_892379.txt',
					'error'    => UPLOAD_ERR_OK,
				),
			)
		);

		$this->assertEquals( array( 'README.md', 'src' ), $fs->ls( '/' ) );
		$this->assertTrue( $fs->is_dir( '/src' ) );
		$this->assertEquals( array( 'index.php', 'style.css', 'js' ), $fs->ls( '/src' ) );
		$this->assertEquals( array( 'script.js' ), $fs->ls( '/src/js' ) );
	}
}
