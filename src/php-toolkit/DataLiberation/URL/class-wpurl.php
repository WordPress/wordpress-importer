<?php

namespace WordPress\DataLiberation\URL;

use Rowbot\URL\URL;

/**
 * An abstraction to make swapping the URL parser easier later on.
 * We do not need it in the long run. It adds extra overhead of the
 * function call – let's remove it once the URL parser is decided.
 */
class WPURL {

	public static function parse( $url, $base = null ) {
		if ( is_string( $url ) ) {
			return URL::parse( $url, $base ) ?? false;
		} elseif ( is_a( $url, 'Rowbot\URL\URL' ) ) {
			return $url;
		}

		return false;
	}

	public static function can_parse( $url, $base = null ) {
		return URL::canParse( $url, $base );
	}

	/**
	 * Prepends a protocol to any matched URL without the double slash.
	 *
	 * Imagine we have a base URL of `https://example.com` and a text like `Visit myblog.com`.
	 * This Processor would match `myblog.com` as a URL candidate and then the parser
	 * would parse it as `https://example.com/myblog.com`, which is not what a user would expect.
	 *
	 * To get `https://myblog.com`, we need to prepend a protocol and turn that candidate into
	 * `https://myblog.com` before parsing.
	 */
	public static function ensure_protocol( $raw_url, $protocol = 'https' ) {
		if ( ! self::has_http_https_protocol( $raw_url ) ) {
			$raw_url = $protocol . '://' . $raw_url;
		}

		return $raw_url;
	}

	/**
	 * This method only considers http and https protocols.
	 */
	public static function has_http_https_protocol( $raw_url ) {
		return (
			(
				// Protocol-relative URLs.
				strlen( $raw_url ) > 2 &&
				'/' === $raw_url[0] &&
				'/' === $raw_url[1]
			) || (
				strlen( $raw_url ) > 5 &&
				( 'h' === $raw_url[0] || 'H' === $raw_url[0] ) &&
				( 't' === $raw_url[1] || 'T' === $raw_url[1] ) &&
				( 't' === $raw_url[2] || 'T' === $raw_url[2] ) &&
				( 'p' === $raw_url[3] || 'P' === $raw_url[3] ) &&
				':' === $raw_url[4]
			) || (
				strlen( $raw_url ) > 6 &&
				( 'h' === $raw_url[0] || 'H' === $raw_url[0] ) &&
				( 't' === $raw_url[1] || 'T' === $raw_url[1] ) &&
				( 't' === $raw_url[2] || 'T' === $raw_url[2] ) &&
				( 'p' === $raw_url[3] || 'P' === $raw_url[3] ) &&
				( 's' === $raw_url[4] || 'S' === $raw_url[4] ) &&
				':' === $raw_url[5]
			)
		);
	}

	public static function append_path( $base_url, $path ) {
		$base_url           = self::parse( $base_url );
		$base_url->pathname = rtrim( $base_url->pathname, '/' ) . '/' . ltrim( $path, '/' );

		return $base_url->toString();
	}

	/**
	 * Checks if a TLD is in the known public domain suffix list.
	 * This reduces false positives like `index.html` or `plugins.php`.
	 *
	 * @see https://publicsuffix.org/
	 *
	 * @param string $tld The top-level domain to check.
	 * @return bool True if the TLD is a known public domain, false otherwise.
	 */
	public static function is_known_public_domain( $tld ) {
		static $public_suffix_list = null;

		if ( null === $public_suffix_list ) {
			$public_suffix_list = require_once __DIR__ . '/public-suffix-list.php';
		}

		// @TODO: Parse wildcards and exceptions from the public suffix list.
		$tld = strtolower( $tld );
		return ! empty( $public_suffix_list[ $tld ] ) || 'internal' === $tld;
	}
}
