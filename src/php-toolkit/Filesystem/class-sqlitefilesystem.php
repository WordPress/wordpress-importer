<?php

namespace WordPress\Filesystem;

use Exception;
use SQLite3;
use WordPress\ByteStream\MemoryPipe;
use WordPress\ByteStream\ReadStream\ByteReadStream;
use WordPress\Filesystem\Layer\ChrootLayer;
use WordPress\Filesystem\Mixin\BufferedWriteStreamViaPutContents;
use WordPress\Filesystem\Mixin\CopyRecursiveViaStreaming;
use WordPress\Filesystem\Mixin\MkdirRecursive;

/**
 * Stores files in SQLite database.
 */
class SQLiteFilesystem implements Filesystem {

	use BufferedWriteStreamViaPutContents;
	use MkdirRecursive;
	use CopyRecursiveViaStreaming;

	private $db;
	private $transaction_level = 0;

	public static function create( $db_path = ':memory:' ) {
		return new ChrootLayer(
			new SQLiteFilesystem( $db_path ),
			'/'
		);
	}

	public function get_root(): string {
		return '/';
	}

	private function __construct( $db_path ) {
		$this->db = new SQLite3( $db_path );
		$this->db->exec(
			'
			CREATE TABLE IF NOT EXISTS files (
				path TEXT PRIMARY KEY,
				type TEXT NOT NULL,
				contents BLOB
			);
			CREATE TABLE IF NOT EXISTS directory_entries (
				parent_path TEXT,
				name TEXT,
				PRIMARY KEY (parent_path, name)
			);
		'
		);

		// Create root directory if it doesn't exist.
		$stmt = $this->db->prepare( 'INSERT OR IGNORE INTO files (path, type) VALUES (?, ?)' );
		$stmt->bindValue( 1, '/', SQLITE3_TEXT );
		$stmt->bindValue( 2, 'dir', SQLITE3_TEXT );
		$stmt->execute();
	}

	public function ls( $path = '/' ) {
		$stmt = $this->db->prepare(
			'
			SELECT name FROM directory_entries
			WHERE parent_path = ?
		'
		);
		$stmt->bindValue( 1, $path, SQLITE3_TEXT );
		$result = $stmt->execute();

		$entries = array();
		while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
			$entries[] = $row['name'];
		}

		return $entries;
	}

	public function is_dir( $path ) {
		$stmt = $this->db->prepare(
			'
			SELECT type FROM files
			WHERE path = ? AND type = ?
		'
		);
		$stmt->bindValue( 1, $path, SQLITE3_TEXT );
		$stmt->bindValue( 2, 'dir', SQLITE3_TEXT );
		$result = $stmt->execute();

		return false !== $result->fetchArray();
	}

	public function is_file( $path ) {
		$stmt = $this->db->prepare(
			'
			SELECT type FROM files
			WHERE path = ? AND type = ?
		'
		);
		$stmt->bindValue( 1, $path, SQLITE3_TEXT );
		$stmt->bindValue( 2, 'file', SQLITE3_TEXT );
		$result = $stmt->execute();

		return false !== $result->fetchArray();
	}

	public function exists( $path ) {
		$stmt = $this->db->prepare(
			'
			SELECT 1 FROM files
			WHERE path = ?
		'
		);
		$stmt->bindValue( 1, $path, SQLITE3_TEXT );
		$result = $stmt->execute();

		return false !== $result->fetchArray();
	}

	public function open_read_stream( $path ): ByteReadStream {
		return new MemoryPipe( $this->get_contents( $path ) );
	}

	public function get_contents( $path ) {
		if ( ! $this->is_file( $path ) ) {
			throw new FilesystemException(
				sprintf( 'File not found: %s', $path )
			);
		}
		$stmt = $this->db->prepare( 'SELECT contents FROM files WHERE path = ?' );
		$stmt->bindValue( 1, $path, SQLITE3_TEXT );
		$result = $stmt->execute();
		$row    = $result->fetchArray( SQLITE3_ASSOC );

		return $row['contents'];
	}

	public function rename( $from_path, $to_path, $options = array() ) {
		if ( ! $this->exists( $from_path ) ) {
			throw new FilesystemException(
				sprintf( 'File not found: %s', $from_path )
			);
		}

		$parent = wp_unix_dirname( $to_path );
		if ( ! $this->is_dir( $parent ) ) {
			throw new FilesystemException(
				sprintf( 'Parent directory not found: %s', $parent )
			);
		}

		try {
			$this->in_transaction(
				function () use ( $from_path, $to_path ) {
					// Update the file path.
					$stmt = $this->db->prepare( 'UPDATE files SET path = ? WHERE path = ?' );
					$stmt->bindValue( 1, $to_path, SQLITE3_TEXT );
					$stmt->bindValue( 2, $from_path, SQLITE3_TEXT );
					$stmt->execute();

					// Update directory entries.
					$old_parent = wp_unix_dirname( $from_path );
					$parent     = wp_unix_dirname( $to_path );

					$stmt = $this->db->prepare(
						'
                        DELETE FROM directory_entries
                        WHERE parent_path = ? AND name = ?
                    '
					);
					$stmt->bindValue( 1, $old_parent, SQLITE3_TEXT );
					$stmt->bindValue( 2, basename( $from_path ), SQLITE3_TEXT );
					$stmt->execute();

					$stmt = $this->db->prepare(
						'
                        INSERT INTO directory_entries (parent_path, name)
                        VALUES (?, ?)
                    '
					);
					$stmt->bindValue( 1, $parent, SQLITE3_TEXT );
					$stmt->bindValue( 2, basename( $to_path ), SQLITE3_TEXT );
					$stmt->execute();
				}
			);

			return true;
		} catch ( Exception $e ) {
			throw new FilesystemException(
				sprintf( 'Failed to rename file: %s to %s', $from_path, $to_path ),
				0,
				$e
			);
		}
	}

	public function mkdir_single( $path, $options = array() ) {
		if ( $this->exists( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Directory already exists: %s', $path )
			);
		}

		$parent = wp_unix_dirname( $path );
		if ( ! $this->is_dir( $parent ) ) {
			throw new FilesystemException(
				sprintf( 'Parent directory not found: %s', $parent )
			);
		}

		try {
			$this->in_transaction(
				function () use ( $path ) {
					$parent = wp_unix_dirname( $path );

					$stmt = $this->db->prepare(
						'
                        INSERT INTO files (path, type)
                        VALUES (?, ?)
                    '
					);
					$stmt->bindValue( 1, $path, SQLITE3_TEXT );
					$stmt->bindValue( 2, 'dir', SQLITE3_TEXT );
					$stmt->execute();

					$stmt = $this->db->prepare(
						'
                        INSERT INTO directory_entries (parent_path, name)
                        VALUES (?, ?)
                    '
					);
					$stmt->bindValue( 1, $parent, SQLITE3_TEXT );
					$stmt->bindValue( 2, basename( $path ), SQLITE3_TEXT );
					$stmt->execute();
				}
			);

			return true;
		} catch ( Exception $e ) {
			throw new FilesystemException(
				sprintf( 'Failed to create directory: %s', $path ),
				0,
				$e
			);
		}
	}

	public function rm( $path ) {
		if ( ! $this->is_file( $path ) ) {
			throw new FilesystemException(
				sprintf( 'File not found: %s', $path )
			);
		}

		try {
			$this->in_transaction(
				function () use ( $path ) {
					$parent = wp_unix_dirname( $path );

					$stmt = $this->db->prepare( 'DELETE FROM files WHERE path = ?' );
					$stmt->bindValue( 1, $path, SQLITE3_TEXT );
					$stmt->execute();

					$stmt = $this->db->prepare(
						'
                        DELETE FROM directory_entries
                        WHERE parent_path = ? AND name = ?
                    '
					);
					$stmt->bindValue( 1, $parent, SQLITE3_TEXT );
					$stmt->bindValue( 2, basename( $path ), SQLITE3_TEXT );
					$stmt->execute();
				}
			);
		} catch ( Exception $e ) {
			throw new FilesystemException(
				sprintf( 'Failed to remove file: %s', $path ),
				0,
				$e
			);
		}
	}

	public function rmdir( $path, $options = array() ) {
		$recursive = $options['recursive'] ?? false;
		if ( ! $this->is_dir( $path ) ) {
			throw new FilesystemException(
				sprintf( 'Directory not found: %s', $path )
			);
		}

		try {
			$this->in_transaction(
				function () use ( $path, $options, $recursive ) {
					if ( $recursive ) {
						$path = rtrim( $path, '/' );
						foreach ( $this->ls( $path ) as $child ) {
							$child_path = wp_join_unix_paths( $path, $child );
							if ( $this->is_dir( $child_path ) ) {
								$this->rmdir( $child_path, $options );
							} else {
								$this->rm( $child_path );
							}
						}
					}

					$parent = wp_unix_dirname( $path );

					$stmt = $this->db->prepare( 'DELETE FROM files WHERE path = ?' );
					$stmt->bindValue( 1, $path, SQLITE3_TEXT );
					$stmt->execute();

					$stmt = $this->db->prepare(
						'
                        DELETE FROM directory_entries
                        WHERE parent_path = ? AND name = ?
                    '
					);
					$stmt->bindValue( 1, $parent, SQLITE3_TEXT );
					$stmt->bindValue( 2, basename( $path ), SQLITE3_TEXT );
					$stmt->execute();
				}
			);
		} catch ( Exception $e ) {
			throw new FilesystemException(
				sprintf( 'Failed to remove directory: %s', $path ),
				0,
				$e
			);
		}
	}

	public function put_contents( $path, $data, $options = array() ) {
		$parent = wp_unix_dirname( $path );
		if ( ! $this->is_dir( $parent ) ) {
			throw new FilesystemException(
				sprintf( 'Parent directory not found: %s', $parent )
			);
		}

		try {
			$this->in_transaction(
				function () use ( $path, $data ) {
					$parent = wp_unix_dirname( $path );

					$stmt = $this->db->prepare(
						'
                        INSERT OR REPLACE INTO files (path, type, contents)
                        VALUES (?, ?, ?)
                    '
					);
					$stmt->bindValue( 1, $path, SQLITE3_TEXT );
					$stmt->bindValue( 2, 'file', SQLITE3_TEXT );
					$stmt->bindValue( 3, $data, SQLITE3_BLOB );
					$stmt->execute();

					$stmt = $this->db->prepare(
						'
                        INSERT OR REPLACE INTO directory_entries (parent_path, name)
                        VALUES (?, ?)
                    '
					);
					$stmt->bindValue( 1, $parent, SQLITE3_TEXT );
					$stmt->bindValue( 2, basename( $path ), SQLITE3_TEXT );
					$stmt->execute();
				}
			);

			return true;
		} catch ( Exception $e ) {
			throw new FilesystemException(
				sprintf( 'Failed to put contents: %s', $path ),
				0,
				$e
			);
		}
	}

	private function in_transaction( $callback ) {
		$current_level = $this->transaction_level++;
		try {
			if ( 0 === $current_level ) {
				$this->db->exec( 'BEGIN TRANSACTION' );
				try {
					$callback();
					$this->db->exec( 'COMMIT' );
				} catch ( Exception $e ) {
					$this->db->exec( 'ROLLBACK' );
					throw $e;
				}
			} else {
				$this->db->exec( 'SAVEPOINT level_' . $current_level );
				try {
					$callback();
					$this->db->exec( 'RELEASE SAVEPOINT level_' . $current_level );
				} catch ( Exception $e ) {
					$this->db->exec( 'ROLLBACK TO SAVEPOINT level_' . $current_level );
					throw $e;
				}
			}
		} finally {
			--$this->transaction_level;
		}
	}

	public function get_meta(): array {
		return array();
	}
}
