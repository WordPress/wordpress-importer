<?php
declare(strict_types=1);

namespace WordPress\Filesystem\Path;

final class WindowsPath {

	private const SEP = '\\';

	/** Converts every “/” to “\” so that later code can assume one separator. */
	private static function normalize_separators( string $path ): string {
		return str_replace( '/', self::SEP, $path );
	}

	/** Absolute = “C:\…\” or “\\server\share\…”. */
	private static function is_absolute( string $path ): bool {
		$path = self::normalize_separators( $path );
		return (bool) preg_match( '/^(?:[A-Za-z]:\\\\|\\\\\\\\[^\\\\]+\\\\[^\\\\]+)/', $path );
	}

	/** Returns “C:\foo\bar” → ["C:", "foo", "bar"]; “\\srv\share\dir” → ["srv", "share", "dir"]. */
	public static function path_segments( string $path ): array {
		$canonical = self::canonicalize( $path );
		$trimmed   = trim( $canonical, self::SEP );

		// root “C:\” or “\\srv\share\” gives empty $trimmed, keep the original string.
		if ( '' === $trimmed ) {
			return array( $canonical );
		}
		return array_values( array_filter( explode( self::SEP, $trimmed ), 'strlen' ) );
	}

	/** Joins segments with a single backslash and keeps any drive-letter or UNC prefix intact. */
	public static function join_paths( string ...$segments ): string {
		if ( ! $segments ) {
			return '';
		}

		$pieces = array();
		$first  = null;
		foreach ( $segments as $seg ) {
			if ( '' === $seg ) {
				continue;
			}
			if ( null === $first ) {
				$first = $seg;
			}
			$pieces[] = trim( self::normalize_separators( $seg ), '\\/' );
		}
		$joined = implode( self::SEP, $pieces );

		// restore UNC double backslash if needed.
		if ( preg_match( '/^\\\\\\\\/', self::normalize_separators( $first ) ) ) {
			return '\\\\' . ltrim( $joined, self::SEP );
		}
		return $joined;
	}

	/** Mirrors Node.js path.resolve for Windows rules. */
	public static function resolve_path( string ...$segments ): string {
		for ( $i = count( $segments ) - 1; $i >= 0; --$i ) {
			if ( self::is_absolute( $segments[ $i ] ) ) {
				return self::canonicalize(
					self::join_paths( ...array_slice( $segments, $i ) )
				);
			}
		}
		array_unshift( $segments, getcwd() );
		return self::canonicalize( self::join_paths( ...$segments ) );
	}

	/**
	 * Cleans up a path, guarantees it is absolute and free of “\.” / “\..”.
	 * Keeps trailing backslash only for drive-root (“C:\”) or UNC-root (“\\srv\share\”).
	 */
	public static function canonicalize( string $path ): string {
		$path = self::normalize_separators( trim( $path ) );

		if ( ! self::is_absolute( $path ) ) {
			$cwd  = rtrim( getcwd(), self::SEP );
			$path = $cwd . self::SEP . $path;
		}

		// split prefix (drive or UNC) from the rest.
		preg_match( '/^(\\\\\\\\[^\\\\]+\\\\[^\\\\]+|[A-Za-z]:)(?:\\\\)?(.*)$/', $path, $m );
		$prefix = $m[1] ?? '';
		$rest   = $m[2] ?? '';

		$stack = array();
		foreach ( explode( self::SEP, $rest ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $stack );
				continue;
			}
			$stack[] = $part;
		}

		$resolved = $prefix;
		if ( '' !== $resolved && self::SEP !== substr( $resolved, -1 ) ) {
			$resolved .= self::SEP;
		}
		$resolved .= implode( self::SEP, $stack );

		// Drive-root and UNC-root must end with “\”.
		if ( $resolved === $prefix ) {
			$resolved .= self::SEP;
		}
		return $resolved;
	}

	/** Consistent dirname() that sticks to backslashes. */
	public static function dirname( string $path ): string {
		$path = self::normalize_separators( $path );
		$dir  = dirname( $path );

		// dirname("C:\foo") returns "C:\", keep that trailing sep; but dirname("C:\") yields "C:\".
		if ( preg_match( '/^[A-Za-z]:$/', $dir ) ) {
			$dir .= self::SEP;
		}
		return $dir;
	}
}
