<?php

use PHPUnit\Framework\TestCase;

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_dirname;
use ValueError;

class FunctionsTest extends TestCase {
	public function testBasicPathJoining() {
		$this->assertEquals( 'foo/bar', wp_join_unix_paths( 'foo', 'bar' ) );
		$this->assertEquals( '/foo/bar', wp_join_unix_paths( '/foo', 'bar' ) );
	}

	public function testRemovesEmptySegments() {
		$this->assertEquals( 'foo/bar', wp_join_unix_paths( 'foo', '', 'bar' ) );
		$this->assertEquals( 'foo/bar', wp_join_unix_paths( '', 'foo', 'bar' ) );
	}

	public function testPreserveLeadingSlash() {
		$this->assertEquals( '/foo/bar', wp_join_unix_paths( '/foo', '/bar' ) );
		$this->assertEquals( 'foo/bar', wp_join_unix_paths( 'foo', '/bar' ) );
	}

	public function testDeduplicatesMultipleSlashes() {
		$this->assertEquals( '/foo/bar', wp_join_unix_paths( '/foo/', '/bar' ) );
		$this->assertEquals( '/foo/bar', wp_join_unix_paths( '/foo//', '//bar' ) );
	}

	public function testSingleArgument() {
		$this->assertEquals( '/foo', wp_join_unix_paths( '/foo' ) );
		$this->assertEquals( 'foo', wp_join_unix_paths( 'foo' ) );
	}

	public function testMultipleSegments() {
		$this->assertEquals( 'foo/bar/baz', wp_join_unix_paths( 'foo', 'bar', 'baz' ) );
		$this->assertEquals( '/foo/bar/baz', wp_join_unix_paths( '/foo', 'bar', 'baz' ) );
	}

	public function testEmptyStrings() {
		$this->assertEquals( '', wp_join_unix_paths( '' ) );
		$this->assertEquals( '', wp_join_unix_paths( '', '', '' ) );
	}

	public function testMixedSlashes() {
		$this->assertEquals( '/foo/bar/baz', wp_join_unix_paths( '/foo/', '/bar/', '/baz' ) );
		$this->assertEquals( 'foo/bar/baz', wp_join_unix_paths( 'foo/', '/bar/', '/baz' ) );
	}
	
	/**
	 * @dataProvider wpUnixDirnameProvider
	 */
	public function testWpUnixDirname($path, $levels, $expected, $expected_dirname=null) {
		// Validate wp_unix_dirname output.
		$this->assertEquals(
			$expected,
			wp_unix_dirname($path, $levels)
		);

		// Validate parity with PHP dirname() where an expected value is supplied (non-null).
		if ($expected_dirname !== false) {
			$this->assertEquals(
				$expected,
				dirname($path, $levels),
				'dirname() parity'
			);
		}
	}

	public static function wpUnixDirnameProvider() {
		return [
			// Basic paths
			'Basic: /foo/bar' => [ '/foo/bar', 1, '/foo', true ],
			'Basic: /foo/bar/baz' => [ '/foo/bar/baz', 1, '/foo/bar', true ],
			'Basic: foo/bar' => [ 'foo/bar', 1, 'foo', true ],

			// Root and empty
			'Root: /' => [ '/', 1, '/', false ],
			'Root: /foo' => [ '/foo', 1, '/', false ],
			'Empty path' => [ '', 1, '', true ],

			// Trailing slashes
			'Trailing slash: /foo/' => [ '/foo/', 1, '/', false ],
			'Trailing slash: /foo/bar/' => [ '/foo/bar/', 1, '/foo', true ],

			// Multiple slashes
			'Multiple slashes: //' => [ '//', 1, '/', false ],
			'Multiple slashes: ///' => [ '///', 1, '/', false ],

			// No slash / relative
			'No slash: foo' => [ 'foo', 1, '.', true ],
			'Current dir: ./foo' => [ './foo', 1, '.', true ],
			'Parent dir: ../foo' => [ '../foo', 1, '..', true ],
			'Parent dir: ../../foo' => [ '../../foo', 1, '../..', true ],

			// Windows-style paths (no dirname parity asserted → false)
			'Windows: C:/' => [ 'C:/', 1, '.', false ],
			'Windows: C:/foo' => [ 'C:/foo', 1, 'C:', false ],
			'Windows: C:/foo/bar' => [ 'C:/foo/bar', 1, 'C:/foo', false ],

			// Multiple levels
			'Multiple levels: /foo/bar/baz, 2' => [ '/foo/bar/baz', 2, '/foo', true ],
			'Multiple levels: /foo/bar/baz, 3' => [ '/foo/bar/baz', 3, '/', false ],
			'Multiple levels: /foo/bar/baz, 4' => [ '/foo/bar/baz', 4, '/', false ],
			'Multiple levels: foo/bar/baz, 3' => [ 'foo/bar/baz', 3, '.', true ],

			// Deep nesting
			'Deep nesting: /a/b/c/d/e/f/g, 3' => [ '/a/b/c/d/e/f/g', 3, '/a/b/c/d', true ],
			'Deep nesting: /a/b/c/d/e/f/g, 7' => [ '/a/b/c/d/e/f/g', 7, '/', false ],
			'Deep nesting: /a/b/c/d/e/f/g, 10' => [ '/a/b/c/d/e/f/g', 10, '/', false ],

			// Edge cases
			'Edge: /foo/./bar' => [ '/foo/./bar', 1, '/foo/.', true ],
			'Edge: ./.' => [ './.', 1, '.', true ],
			'Edge: /..' => [ '/..', 1, '/', false ],
			'Edge: /foo/bar////' => [ '/foo/bar////', 1, '/foo', true ],

			// Weird Windows paths (expecting dot, no dirname parity)
			'Weird Windows: C:\\CHAMELEON' => [ 'C:\\CHAMELEON', 1, '.', false ],
			'Weird Windows: c:\\chameleon' => [ 'c:\\chameleon', 1, '.', false ],
			'Weird Windows: C:\/\\//\\\\///Chameleon' => [ 'C:\/\\//\\\\///Chameleon', 1, 'C:\/\\//\\\\', false ],
			'Weird Windows: C:\\Windows\\..\\Users\\..\\Chameleon' => [ 'C:\\Windows\\..\\Users\\..\\Chameleon', 1, '.', false ],
			'Weird Windows: \\\\localhost\\C$\\Chameleon' => [ '\\\\localhost\\C$\\Chameleon', 1, '.', false ],
			'Weird Windows: \\\\127.0.0.1\\C$\\Chameleon' => [ '\\\\127.0.0.1\\C$\\Chameleon', 1, '.', false ],
			'Weird Windows: \\\\?\\C:\\Chameleon' => [ '\\\\?\\C:\\Chameleon', 1, '.', false ],
			'Weird Windows: \\\\.\\C:\\Chameleon' => [ '\\\\.\'\\C:\\Chameleon', 1, '.', false ],
			'Weird Windows: \\\\.\\UNC\\localhost\\C$\\Chameleon' => [ '\\\\.\'\\UNC\\localhost\\C$\\Chameleon', 1, '.', false ],
			'Weird Windows: \\\\?\\Volume{59e01a55-88c5-411e-bf0a-92820bdb2549}\\Chameleon' => [ '\\\\?\\Volume{59e01a55-88c5-411e-bf0a-92820bdb2549}\\Chameleon', 1, '.', false ],
			'Weird Windows: \\\\.\\GLOBALROOT\\Device\\HarddiskVolume4\\Chameleon' => [ '\\\\.\'\\GLOBALROOT\\Device\\HarddiskVolume4\\Chameleon', 1, '.', false ],
		];
	}

	/**
	 * @dataProvider wpUnixDirnameInvalidLevelsProvider
	 */
	public function testWpUnixDirnameInvalidLevels($label, $path, $levels) {
		$this->expectException(ValueError::class);
		wp_unix_dirname($path, $levels);
	}

	public static function wpUnixDirnameInvalidLevelsProvider() {
		return [
			'Zero levels' => [
				'desc'   => 'Zero levels',
				'path'   => '/foo/bar',
				'levels' => 0,
			],
			'Negative levels' => [
				'desc'   => 'Negative levels',
				'path'   => '/foo/bar',
				'levels' => -1,
			],
		];
	}
}
