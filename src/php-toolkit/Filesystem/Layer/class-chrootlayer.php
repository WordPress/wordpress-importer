<?php

namespace WordPress\Filesystem\Layer;

use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\Filesystem\Filesystem;
use WordPress\Filesystem\LocalFilesystem;

use function WordPress\Filesystem\wp_join_unix_paths;
use function WordPress\Filesystem\wp_unix_path_resolve_dots;

/**
 * A filesystem wrapper that chroot's the filesystem to a specific path.
 */
class ChrootLayer extends Layer {

	/**
	 * @var string
	 */
	private $chroot;

	/**
	 * @param  Filesystem $fs  The filesystem to chroot.
	 * @param  string     $chroot  The root path to chroot to.
	 */
	public function __construct( Filesystem $fs, $chroot ) {
		parent::__construct( $fs );
		$this->chroot = $this->forward_slashes_on_local_filesystem_on_windows(
			rtrim( $chroot, '/' ) . '/'
		);
	}

	/**
	 * Transforms an absolute path or relative path to be contained within a chroot.
	 *
	 * @param  string $path  The path to normalize.
	 *
	 * @return string The normalized path.
	 */
	public function chrooted_path( $path ) {
		$path = $this->forward_slashes_on_local_filesystem_on_windows( $path );
		return wp_join_unix_paths(
			$this->chroot,
			wp_unix_path_resolve_dots( $path )
		);
	}

	/**
	 * Make sure we use forward slashes when addressing a local filesystem on
	 * a Windows host. This allows all the wp_unix_* functions to work.
	 *
	 * This is an abstraction leak! ChrootLayer is generic but it makes choices
	 * on behalf of LocalFilesystem.
	 *
	 * @TODO: Reorganize the code to avoid this. Otherwise, every Layer class
	 *        will need to implement this logic. That's error-prone.
	 *
	 * @param  string $path  The path to normalize.
	 *
	 * @return string The normalized path.
	 */
	private function forward_slashes_on_local_filesystem_on_windows( $path ) {
		if ( '\\' === DIRECTORY_SEPARATOR && $this->fs instanceof LocalFilesystem ) {
			return str_replace( '\\', '/', $path );
		}
		return $path;
	}

	public function exists( $path ) {
		$path = $this->chrooted_path( $path );

		return $this->fs->exists( $path );
	}

	public function is_file( $path ) {
		$path = $this->chrooted_path( $path );

		return $this->fs->is_file( $path );
	}

	public function is_dir( $path ) {
		$path = $this->chrooted_path( $path );

		return $this->fs->is_dir( $path );
	}

	public function mkdir( $path, $options = array() ) {
		$path = $this->chrooted_path( $path );

		return $this->fs->mkdir( $path, $options );
	}

	public function rm( $path, $options = array() ) {
		$path = $this->chrooted_path( $path );

		return $this->fs->rm( $path, $options );
	}

	public function rmdir( $path, $options = array() ) {
		$path = $this->chrooted_path( $path );

		return $this->fs->rmdir( $path, $options );
	}

	public function ls( $path = '/' ) {
		$path = $this->chrooted_path( $path );

		return $this->fs->ls( $path );
	}

	public function open_read_stream( $path ): ByteReadStream {
		$path = $this->chrooted_path( $path );

		return $this->fs->open_read_stream( $path );
	}

	public function open_write_stream( $path ): ByteWriteStream {
		$path = $this->chrooted_path( $path );

		return $this->fs->open_write_stream( $path );
	}

	public function copy( $source, $destination, $options = array() ) {
		$source      = $this->chrooted_path( $source );
		$destination = $this->chrooted_path( $destination );

		return $this->fs->copy( $source, $destination, $options );
	}

	public function rename( $source, $destination, $options = array() ) {
		$source      = $this->chrooted_path( $source );
		$destination = $this->chrooted_path( $destination );

		return $this->fs->rename( $source, $destination, $options );
	}

	public function get_contents( $path ) {
		$path = $this->chrooted_path( $path );

		return $this->fs->get_contents( $path );
	}

	public function put_contents( $path, $contents, $options = array() ) {
		$path = $this->chrooted_path( $path );

		return $this->fs->put_contents( $path, $contents, $options );
	}

	public function get_meta(): array {
		return array_merge( array( 'chroot' => $this->chroot ), $this->fs->get_meta() );
	}
}
