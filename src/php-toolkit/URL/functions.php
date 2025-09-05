<?php

namespace WordPress\DataLiberation\URL;

use Rowbot\URL\URL;
use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;

/**
 * Replaces URLs in the imported content. Rewrites the original
 * site's base URL to the current site's base URL.
 *
 * For example, if the imported WXR file has the following tag:
 *
 *     <wp:base_site_url>https://playground.internal/path</wp:base_site_url>
 *
 * and the current site's base URL is https://mynewsite.com,
 * then the following post content:
 *
 *     <p>
 *         <a href="https://playground.internal/path/work-with-us">Work with us</a>
 *         <a href="/path/contact-us">Contact us</a>
 *     </p>
 *
 * will be rewritten as:
 *
 *     <p>
 *         <a href="https://mynewsite.com/work-with-us">Work with us</a>
 *         <a href="/contact-us">Contact us</a>
 *     </p>
 *
 * Here's another example:
 *
 * ```php
 * php > wp_rewrite_urls([
 *   'block_markup' => '<!-- wp:image {"src": "http://legacy-blog.com/image.jpg"} -->',
 *   'url-mapping' => [
 *     'http://legacy-blog.com' => 'https://modern-webstore.org'
 *   ]
 * ])
 * <!-- wp:image {"src":"https:\/\/modern-webstore.org\/image.jpg"} -->
 * ```
 *
 * This takes into account punycode, relative and absolute URLs, unicode normalization,
 * and other typical gotchas.
 *
 * @TODO Use a proper JSON parser and encoder to:
 * * Support UTF-16 characters
 * * Gracefully handle recoverable encoding issues
 * * Avoid changing the whitespace in the same manner as
 *   we do in WP_HTML_Tag_Processor
 */
function wp_rewrite_urls( $options ) {
	if ( empty( $options['base_url'] ) ) {
		// Use first from-url as base_url if not specified
		$from_urls           = array_keys( $options['url-mapping'] );
		$options['base_url'] = $from_urls[0];
	}

	$url_mapping = array();
	foreach ( $options['url-mapping'] as $from_url_string => $to_url_string ) {
		$url_mapping[] = array(
			'from_url' => WPURL::parse( $from_url_string ),
			'to_url'   => WPURL::parse( $to_url_string ),
		);
	}

	$p = new BlockMarkupUrlProcessor( $options['block_markup'], $options['base_url'] );
	while ( $p->next_url() ) {
		// No need to rewrite anchor links.
		if ( substr( $p->get_raw_url(), 0, 1 ) === '#' ) {
			continue;
		}

		// If the URL cannot be parsed, there's nothing to rewrite.
		$parsed_url = $p->get_parsed_url();
		if ( ! $parsed_url ) {
			continue;
		}

		foreach ( $url_mapping as $mapping ) {
			if ( is_child_url_of( $parsed_url, $mapping['from_url'] ) ) {
				$p->replace_base_url( $mapping['to_url'] );
				break;
			}
		}
	}

	return $p->get_updated_html();
}

/**
 * Check if a given URL matches the current site URL.
 *
 * @param  URL  $parent  The URL to check.
 * @param  string  $child  The current site URL to compare against.
 *
 * @return bool Whether the URL matches the current site URL.
 */
function is_child_url_of( $child, $parent_url ) {
	$parent_url                       = is_string( $parent_url ) ? WPURL::parse( $parent_url ) : $parent_url;
	$child                            = is_string( $child ) ? WPURL::parse( $child ) : $child;
	$child_pathname_no_trailing_slash = rtrim( urldecode( $child->pathname ), '/' );

	if ( false === $child || false === $parent_url ) {
		return false;
	}

	if ( $parent_url->hostname !== $child->hostname ) {
		return false;
	}

	if ( $parent_url->protocol !== $child->protocol ) {
		return false;
	}

	$parent_pathname = urldecode( $parent_url->pathname );

	return (
		// Direct match
		$parent_pathname === $child_pathname_no_trailing_slash ||
		$parent_pathname === $child_pathname_no_trailing_slash . '/' ||
		// Path prefix
		strncmp( $child_pathname_no_trailing_slash . '/', $parent_pathname, strlen( $parent_pathname ) ) === 0
	);
}

/**
 * Decodes the first n **encoded bytes** a URL-encoded string.
 *
 * For example, `urldecode_n( '%22is 6 %3C 6?%22 – asked Achilles', 1 )` returns
 * '"is 6 %3C 6?%22 – asked Achilles' because only the first encoded byte is decoded.
 *
 * @param  string  $string  The string to decode.
 * @param  int  $decode_n  The number of bytes to decode in $input
 *
 * @return string The decoded string.
 */
function urldecode_n( $input, $decode_n ) {
	$result = '';
	$at     = 0;
	while ( true ) {
		if ( $at + 3 > strlen( $input ) ) {
			break;
		}

		$last_at = $at;
		$at     += strcspn( $input, '%', $at );
		// Consume bytes except for the percent sign.
		$result .= substr( $input, $last_at, $at - $last_at );

		// If we've already decoded the requested number of bytes, stop.
		if ( strlen( $result ) >= $decode_n ) {
			break;
		}

		++$at;
		if ( $at > strlen( $input ) ) {
			break;
		}

		$decodable_length = strspn(
			$input,
			'0123456789ABCDEFabcdef',
			$at,
			2
		);

		if ( $decodable_length === 2 ) {
			// Decode the hex sequence.
			$result .= chr( hexdec( $input[ $at ] . $input[ $at + 1 ] ) );
			$at     += 2;
		} else {
			// Consume the next byte and move on.
			$result .= '%';
		}
	}
	$result .= substr( $input, $at );

	return $result;
}
