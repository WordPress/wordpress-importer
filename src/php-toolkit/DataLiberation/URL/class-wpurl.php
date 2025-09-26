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
	 * Replaces the base in a URL with a different base.
	 *
	 * A base is a protocol, host, and a path segment.
	 *
	 * Expected options:
	 * - url (string|URL): The URL whose base should be replaced. Required.
	 * - old_base_url (string|URL): The base URL currently associated with the URL. Required.
	 * - new_base_url (string|URL): The base URL that should replace the existing one. Required.
	 * - raw_url (string, optional): The original raw URL string. Used to detect relativity.
	 * - is_relative (bool, optional): Whether the original URL was relative. Overrides raw_url detection.
	 * - return_array (bool, optional): Whether to return the detailed array structure. Default false.
	 *
	 * @param array $options The options that control how the base URL is replaced.
	 *
	 * @return string|array|false Returns a string by default, or an array with keys 'url', 'string',
	 *                           'relative_url', and 'was_relative' when 'return_array' is truthy.
	 *                           Returns false on failure.
	 */
	public static function replace_base_url( $options ) {
		if ( ! is_array( $options ) ) {
			return false;
		}

		foreach ( array( 'url', 'old_base_url', 'new_base_url' ) as $required ) {
			if ( ! array_key_exists( $required, $options ) || null === $options[ $required ] ) {
				return false;
			}
		}

		$old_base_url = self::parse( $options['old_base_url'] );
		$new_base_url = self::parse( $options['new_base_url'] );
		$url          = self::parse( $options['url'], $old_base_url ? $old_base_url->toString() : null );

		if ( false === $old_base_url || false === $new_base_url || false === $url ) {
			return false;
		}

		$updated_url = clone $url;

		$updated_url->hostname = $new_base_url->hostname;
		$updated_url->protocol = $new_base_url->protocol;
		$updated_url->port     = $new_base_url->port;

		$from_pathname = $url->pathname;
		$to_pathname   = $new_base_url->pathname;
		$base_pathname = $old_base_url->pathname;

		if ( $base_pathname !== $to_pathname ) {
			$base_pathname_with_trailing_slash = rtrim( $base_pathname, '/' ) . '/';
			$decoded_matched_pathname          = urldecode_n(
				$from_pathname,
				strlen( $base_pathname_with_trailing_slash )
			);
			$to_pathname_with_trailing_slash   = rtrim( $to_pathname, '/' ) . '/';
			$remaining_pathname                = substr(
				$decoded_matched_pathname,
				strlen( $base_pathname_with_trailing_slash )
			);

			$updated_url->pathname = $to_pathname_with_trailing_slash . $remaining_pathname;
		}

		/*
		 * Stylistic choice – if the updated URL has no trailing slash,
		 * do not add it to the new URL. The WHATWG URL parser will
		 * add one automatically if the path is empty, so we have to
		 * explicitly remove it.
		 */
		$new_raw_url                = $updated_url->toString();
		$should_trim_trailing_slash = (
			'' !== $from_pathname &&
			'/' !== substr( $from_pathname, -1 ) &&
			'/' !== $from_pathname &&
			'' === $url->search &&
			'' === $url->hash
		);
		if ( $should_trim_trailing_slash ) {
			$new_raw_url = rtrim( $new_raw_url, '/' );
		}
		if ( ! $new_raw_url ) {
			return false;
		}

		$was_relative = null;
		if ( array_key_exists( 'is_relative', $options ) ) {
			$was_relative = $options['is_relative'];
		}
		if ( null === $was_relative && array_key_exists( 'raw_url', $options ) && is_string( $options['raw_url'] ) ) {
			$was_relative = ! self::can_parse( $options['raw_url'] );
		}
		if ( null === $was_relative ) {
			$was_relative = false;
		}

		$relative_url = null;
		if ( $was_relative ) {
			$relative_url = $updated_url->pathname;
			if ( '' !== $updated_url->search ) {
				$relative_url .= $updated_url->search;
			}
			if ( '' !== $updated_url->hash ) {
				$relative_url .= $updated_url->hash;
			}
		}

		$result = array(
			'url'          => $updated_url,
			'string'       => $new_raw_url,
			'relative_url' => $relative_url,
			'was_relative' => (bool) $was_relative,
		);

		if ( empty( $options['return_array'] ) ) {
			return $result['string'];
		}

		return $result;
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
