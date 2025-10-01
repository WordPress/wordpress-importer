<?php

namespace WordPress\ByteStream\WriteStream;

use WordPress\ByteStream\ByteStreamException;

class FileWriteStream implements ByteWriteStream {

	private $file_handle;

	/**
	 * Creates a new instance of FileWriter from a file path with a mode (truncate or append).
	 *
	 * @param  string $path  Path to the file.
	 * @param  string $mode  Writing mode: 'truncate' or 'append'.
	 *
	 * @return FileWriteStream
	 * @throws ByteStreamException If the file cannot be opened for writing.
	 */
	public static function from_path( string $path, string $mode = 'append' ): self {
		switch ( $mode ) {
			case 'truncate':
				$file_mode = 'wb'; // Write mode: truncates the file.
				break;
			case 'append':
				$file_mode = 'ab'; // Append mode: appends to the file.
				break;
			default:
				throw new ByteStreamException( esc_html( "Invalid mode: $mode. Use 'truncate' or 'append'." ) );
		}

		$file_handle = fopen( $path, $file_mode );
		if ( false === $file_handle ) {
			throw new ByteStreamException( esc_html( "Failed to open file at path: $path" ) );
		}

		return new self( $file_handle );
	}

	/**
	 * Creates a new instance of FileWriter from an existing file handle.
	 *
	 * @param  resource $file_handle  A valid file handle.
	 *
	 * @return FileWriteStream
	 * @throws ByteStreamException If the file handle is invalid.
	 */
	public static function from_resource_handle( $file_handle ): self {
		return new self( $file_handle );
	}

	/**
	 * Private constructor to enforce the use of static factory methods.
	 *
	 * @param  resource $file_handle  A valid file handle.
	 * @throws ByteStreamException If the file handle is invalid.
	 */
	public function __construct( $file_handle ) {
		if ( ! is_resource( $file_handle ) || 'stream' !== get_resource_type( $file_handle ) ) {
			throw new ByteStreamException( 'Invalid file handle provided.' );
		}
		$this->file_handle = $file_handle;
	}

	/**
	 * Appends bytes to the file.
	 *
	 * @param  string $bytes  The data to write.
	 *
	 * @return void
	 * @throws ByteStreamException If the write operation fails.
	 */
	public function append_bytes( string $bytes ): void {
		$result = fwrite( $this->file_handle, $bytes );
		/**
		 * We cannot just test for `false === $result` if we want to be
		 * compatible with PHP 7.3.
		 *
		 * The `!fwrite()` check is used for PHP 7.3 compatibility.
		 * Between PHP 7.3 and 7.4, this change was made:
		 *
		 * > fread() and fwrite() will now return FALSE if the operation failed. Previously an empty
		 * > string or 0 was returned. EAGAIN/EWOULDBLOCK are not considered failures.
		 *
		 * https://www.php.net/manual/en/migration74.incompatible.php#migration74.incompatible.core.fread-fwrite
		 */
		if ( ! $result && '' !== $bytes ) {
			throw new ByteStreamException( 'Failed to write bytes to file.' );
		}
	}

	/**
	 * Closes the file handle.
	 *
	 * @return void
	 * @throws ByteStreamException If the file handle is already closed.
	 */
	public function close_writing(): void {
		if ( null === $this->file_handle ) {
			throw new ByteStreamException( 'File handle is already closed.' );
		}

		fclose( $this->file_handle );
		$this->file_handle = null;
	}
}
