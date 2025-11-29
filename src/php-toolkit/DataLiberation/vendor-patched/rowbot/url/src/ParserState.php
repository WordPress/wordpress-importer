<?php

declare( strict_types=1 );

namespace WordPressImporter\Rowbot\URL;

use WordPressImporter\Rowbot\URL\State\AuthorityState;
use WordPressImporter\Rowbot\URL\State\FileHostState;
use WordPressImporter\Rowbot\URL\State\FileSlashState;
use WordPressImporter\Rowbot\URL\State\FileState;
use WordPressImporter\Rowbot\URL\State\FragmentState;
use WordPressImporter\Rowbot\URL\State\HostnameState;
use WordPressImporter\Rowbot\URL\State\HostState;
use WordPressImporter\Rowbot\URL\State\NoSchemeState;
use WordPressImporter\Rowbot\URL\State\OpaquePathState;
use WordPressImporter\Rowbot\URL\State\PathOrAuthorityState;
use WordPressImporter\Rowbot\URL\State\PathStartState;
use WordPressImporter\Rowbot\URL\State\PathState;
use WordPressImporter\Rowbot\URL\State\PortState;
use WordPressImporter\Rowbot\URL\State\QueryState;
use WordPressImporter\Rowbot\URL\State\RelativeSlashState;
use WordPressImporter\Rowbot\URL\State\RelativeState;
use WordPressImporter\Rowbot\URL\State\SchemeStartState;
use WordPressImporter\Rowbot\URL\State\SchemeState;
use WordPressImporter\Rowbot\URL\State\SpecialAuthorityIgnoreSlashesState;
use WordPressImporter\Rowbot\URL\State\SpecialAuthoritySlashesState;
use WordPressImporter\Rowbot\URL\State\SpecialRelativeOrAuthorityState;
use WordPressImporter\Rowbot\URL\State\State;

class ParserState {
	public const SCHEME_START = 'scheme_start';
	public const SCHEME = 'scheme';
	public const NO_SCHEME = 'no_scheme';
	public const SPECIAL_RELATIVE_OR_AUTHORITY = 'special_relative_or_authority';
	public const PATH_OR_AUTHORITY = 'path_or_authority';
	public const RELATIVE = 'relative';
	public const RELATIVE_SLASH = 'relative_slash';
	public const SPECIAL_AUTHORITY_SLASHES = 'special_authority_slashes';
	public const SPECIAL_AUTHORITY_IGNORE_SLASHES = 'special_authority_ignore_slashes';
	public const AUTHORITY = 'authority';
	public const HOST = 'host';
	public const HOSTNAME = 'hostname';
	public const PORT = 'port';
	public const FILE = 'file';
	public const FILE_SLASH = 'file_slash';
	public const FILE_HOST = 'file_host';
	public const PATH_START = 'path_start';
	public const PATH = 'path';
	public const OPAQUE_PATH = 'opaque_path';
	public const QUERY = 'query';
	public const FRAGMENT = 'fragment';

	public static function createHandlerFor( $state ): State {
		switch ( $state ) {
			case self::SCHEME_START:
				return new SchemeStartState();
			case self::SCHEME:
				return new SchemeState();
			case self::NO_SCHEME:
				return new NoSchemeState();
			case self::SPECIAL_RELATIVE_OR_AUTHORITY:
				return new SpecialRelativeOrAuthorityState();
			case self::PATH_OR_AUTHORITY:
				return new PathOrAuthorityState();
			case self::RELATIVE:
				return new RelativeState();
			case self::RELATIVE_SLASH:
				return new RelativeSlashState();
			case self::SPECIAL_AUTHORITY_SLASHES:
				return new SpecialAuthoritySlashesState();
			case self::SPECIAL_AUTHORITY_IGNORE_SLASHES:
				return new SpecialAuthorityIgnoreSlashesState();
			case self::AUTHORITY:
				return new AuthorityState();
			case self::HOST:
				return new HostState();
			case self::HOSTNAME:
				return new HostnameState();
			case self::PORT:
				return new PortState();
			case self::FILE:
				return new FileState();
			case self::FILE_SLASH:
				return new FileSlashState();
			case self::FILE_HOST:
				return new FileHostState();
			case self::PATH_START:
				return new PathStartState();
			case self::PATH:
				return new PathState();
			case self::OPAQUE_PATH:
				return new OpaquePathState();
			case self::QUERY:
				return new QueryState();
			case self::FRAGMENT:
				return new FragmentState();
		}
	}
}
