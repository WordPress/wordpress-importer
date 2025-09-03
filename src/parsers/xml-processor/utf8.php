<?php

namespace WordPress\Encoding;

/*
 * UTF-8 decoding pipeline by Dennis Snell (@dmsnell), originally
 * proposed in https://github.com/WordPress/wordpress-develop/pull/6883.
 *
 * It enables parsing XML documents with incomplete UTF-8 byte sequences
 * without crashing or depending on the mbstring extension.
 */

if ( ! defined( 'UTF8_DECODER_ACCEPT' ) ) {
	define( 'UTF8_DECODER_ACCEPT', 0 );
}

if ( ! defined( 'UTF8_DECODER_REJECT' ) ) {
	define( 'UTF8_DECODER_REJECT', 1 );
}

/**
 * Indicates if a given byte stream represents valid UTF-8.
 *
 * Note that unpaired surrogate halves are not valid UTF-8 and will be rejected.
 *
 * Example:
 *
 *     true  === utf8_is_valid_byte_stream( 'Hello, World! 🌎' );
 *
 *     false === utf8_is_valid_byte_stream( "Latin1 is n\xF6t valid UTF-8.", 0, $error_at );
 *     12    === $error_at;
 *
 *     false === utf8_is_valid_byte_stream( "Surrogate halves like '\xDE\xFF\x80' are not permitted.", 0, $error_at );
 *     23    === $error_at;
 *
 *     false === utf8_is_valid_byte_stream( "Broken stream: \xC2\xC2", 0, $error_at );
 *     15    === $error_at;
 *
 * @param  string  $bytes  Text to validate as UTF-8 bytes.
 * @param  int  $starting_byte  Byte offset in string where decoding should begin.
 * @param  int|null  $first_error_byte_at  Optional. If provided and byte stream fails to validate,
 *                                      will be set to the byte offset where the first invalid
 *                                      byte appeared. Otherwise, will not be set.
 *
 * @return bool Whether the given byte stream represents valid UTF-8.
 * @since {WP_VERSION}
 *
 */
function utf8_is_valid_byte_stream( $bytes, $starting_byte = 0, &$first_error_byte_at = null ) {
	$state         = UTF8_DECODER_ACCEPT;
	$last_start_at = $starting_byte;

	for ( $at = $starting_byte, $end = strlen( $bytes ); $at < $end && UTF8_DECODER_REJECT !== $state; $at++ ) {
		if ( UTF8_DECODER_ACCEPT === $state ) {
			$last_start_at = $at;
		}

		$state = utf8_decoder_apply_byte( $bytes[ $at ], $state );
	}

	if ( UTF8_DECODER_ACCEPT === $state ) {
		return true;
	} else {
		$first_error_byte_at = $last_start_at;

		return false;
	}
}

/**
 * Returns number of code points found within a UTF-8 string, similar to `strlen()`.
 *
 * If the byte stream fails to properly decode as UTF-8 this function will set the
 * byte index of the first error byte and report the number of decoded code points.
 *
 * @param  string  $bytes  Text for which to count code points.
 * @param  int|null  $first_error_byte_at  Optional. If provided, will be set upon finding
 *                                      the first invalid byte.
 *
 * @return int How many code points were decoded in the given byte stream before an error
 *             or before reaching the end of the string.
 * @since {WP_VERSION}
 *
 */
function utf8_codepoint_count( $bytes, &$first_error_byte_at = null ) {
	$state         = UTF8_DECODER_ACCEPT;
	$last_start_at = 0;
	$count         = 0;
	$codepoint     = 0;

	for ( $at = 0, $end = strlen( $bytes ); $at < $end && UTF8_DECODER_REJECT !== $state; $at++ ) {
		if ( UTF8_DECODER_ACCEPT === $state ) {
			$last_start_at = $at;
		}

		$state = utf8_decoder_apply_byte( $bytes[ $at ], $state, $codepoint );

		if ( UTF8_DECODER_ACCEPT === $state ) {
			++$count;
		}
	}

	if ( UTF8_DECODER_ACCEPT !== $state ) {
		$first_error_byte_at = $last_start_at;
	}

	return $count;
}

/**
 * Inner loop for a number of UTF-8 decoding-related functions.
 *
 * You probably don't need this! This is highly-specific and optimized
 * code for UTF-8 operations used in other functions.
 *
 * @see http://bjoern.hoehrmann.de/utf-8/decoder/dfa/
 *
 * @since {WP_VERSION}
 *
 * @access private
 *
 * @param  string  $byte  Next byte to be applied in UTF-8 decoding or validation.
 * @param  int  $state  UTF-8 decoding state, one of the following values:<br><ul>
 *                             <li>`UTF8_DECODER_ACCEPT`: Decoder is ready for a new code point.<br>
 *                             <li>`UTF8_DECODER_REJECT`: An error has occurred.<br>
 *                             Any other positive value: Decoder is waiting for additional bytes.
 * @param  int|null  $codepoint  Optional. If provided, will accumulate the decoded code point as
 *                             each byte is processed. If not provided or unable to decode, will
 *                             not be set, or will be set to invalid and unusable data.
 *
 * @return int Next decoder state after processing the current byte.
 */
function utf8_decoder_apply_byte( $byte, $state, &$codepoint = 0 ) {
	/**
	 * State classification and transition table for UTF-8 validation.
	 *
	 * > The first part of the table maps bytes to character classes that
	 * > to reduce the size of the transition table and create bitmasks.
	 * >
	 * > The second part is a transition table that maps a combination
	 * > of a state of the automaton and a character class to a state.
	 *
	 * @see http://bjoern.hoehrmann.de/utf-8/decoder/dfa/
	 */
	static $state_table = (
		"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" .
		"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" .
		"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" .
		"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" .
		"\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09\x09" .
		"\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07" .
		"\x08\x08\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02\x02" .
		"\x10\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x03\x04\x03\x03" .
		"\x11\x06\x06\x06\x05\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08" .
		"\x00\x01\x02\x03\x05\x08\x07\x01\x01\x01\x04\x06\x01\x01\x01\x01" .
		"\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x00\x01\x01\x01\x01\x01\x00\x01\x00\x01\x01\x01\x01\x01\x01" .
		"\x01\x02\x01\x01\x01\x01\x01\x02\x01\x02\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x02\x01\x01\x01\x01\x01\x01\x01\x01" .
		"\x01\x02\x01\x01\x01\x01\x01\x01\x01\x02\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x03\x01\x03\x01\x01\x01\x01\x01\x01" .
		"\x01\x03\x01\x01\x01\x01\x01\x03\x01\x03\x01\x01\x01\x01\x01\x01\x01\x03\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01\x01"
	);

	$byte      = ord( $byte );
	$type      = ord( $state_table[ $byte ] );
	$codepoint = ( UTF8_DECODER_ACCEPT === $state )
		? ( ( 0xFF >> $type ) & $byte )
		: ( ( $byte & 0x3F ) | ( $codepoint << 6 ) );

	return ord( $state_table[ 256 + ( $state * 16 ) + $type ] );
}

/**
 * Extract a slice of a text by code point, where invalid byte sequences count
 * as a single code point, U+FFFD (the Unicode replacement character `�`).
 *
 * This function does not permit passing negative indices and will return
 * the original string if such are provide.
 *
 * @param  string  $text  Input text from which to extract.
 * @param  int  $from  Start extracting after this many code-points.
 * @param  int  $length  Extract this many code points.
 *
 * @return string Extracted slice of input string.
 */
function utf8_substr( $text, $from = 0, $length = null ) {
	if ( $from < 0 || ( isset( $length ) && $length < 0 ) ) {
		return $text;
	}

	$position_in_input = 0;
	$codepoint_at      = 0;
	$end_byte          = strlen( $text );
	$buffer            = '';
	$seen_codepoints   = 0;
	$sliced_codepoints = 0;
	$decoder_state     = UTF8_DECODER_ACCEPT;

	// Get to the start of the string.
	while ( $position_in_input < $end_byte && $seen_codepoints < $length ) {
		$decoder_state = utf8_decoder_apply_byte( $text[ $position_in_input ], $decoder_state );

		if ( UTF8_DECODER_ACCEPT === $decoder_state ) {
			++$position_in_input;

			if ( $seen_codepoints >= $from ) {
				++$sliced_codepoints;
				$buffer .= substr( $text, $codepoint_at, $position_in_input - $codepoint_at );
			}

			++$seen_codepoints;
			$codepoint_at = $position_in_input;
		} elseif ( UTF8_DECODER_REJECT === $decoder_state ) {
			// "\u{FFFD}" is not supported in PHP 5.6.
			$buffer .= "\xEF\xBF\xBD";

			// Skip to the start of the next code point.
			while ( UTF8_DECODER_REJECT === $decoder_state && $position_in_input < $end_byte ) {
				$decoder_state = utf8_decoder_apply_byte( $text[ ++$position_in_input ], UTF8_DECODER_ACCEPT );
			}

			++$seen_codepoints;
			$codepoint_at  = $position_in_input;
			$decoder_state = UTF8_DECODER_ACCEPT;
		} else {
			++$position_in_input;
		}
	}

	return $buffer;
}

/**
 * Extract a unicode codepoint from a specific offset in text.
 * Invalid byte sequences count as a single code point, U+FFFD
 * (the Unicode replacement character ``).
 *
 * This function does not permit passing negative indices and will return
 * null if such are provided.
 *
 * @param  string  $text  Input text from which to extract.
 * @param  int  $byte_offset  Start at this byte offset in the input text.
 * @param  int  $matched_bytes  How many bytes were matched to produce the codepoint.
 *
 * @return int Unicode codepoint.
 */
function utf8_codepoint_at( $text, $byte_offset = 0, &$matched_bytes = 0 ) {
	if ( $byte_offset < 0 ) {
		return null;
	}

	$position_in_input = $byte_offset;
	$codepoint_at      = $byte_offset;
	$end_byte          = strlen( $text );
	$codepoint         = null;
	$decoder_state     = UTF8_DECODER_ACCEPT;

	// Get to the start of the string.
	while ( $position_in_input < $end_byte ) {
		$decoder_state = utf8_decoder_apply_byte( $text[ $position_in_input ], $decoder_state );

		if ( UTF8_DECODER_ACCEPT === $decoder_state ) {
			++$position_in_input;
			$codepoint = utf8_ord( substr( $text, $codepoint_at, $position_in_input - $codepoint_at ) );
			break;
		} elseif ( UTF8_DECODER_REJECT === $decoder_state ) {
			// "\u{FFFD}" is not supported in PHP 5.6.
			$codepoint = utf8_ord( "\xEF\xBF\xBD" );
			break;
		} else {
			++$position_in_input;
		}
	}

	$matched_bytes = $position_in_input - $byte_offset;

	return $codepoint;
}

/**
 * Convert a UTF-8 byte sequence to its Unicode codepoint.
 *
 * @param  string  $character  UTF-8 encoded byte sequence representing a single Unicode character.
 *
 * @return int Unicode codepoint.
 */
function utf8_ord( $character ) {
	// Convert the byte sequence to its binary representation
	$bytes = unpack( 'C*', $character );

	// Initialize the codepoint
	$codepoint = 0;

	// Calculate the codepoint based on the number of bytes
	if ( count( $bytes ) === 1 ) {
		$codepoint = $bytes[1];
	} elseif ( count( $bytes ) === 2 ) {
		$codepoint = ( ( $bytes[1] & 0x1F ) << 6 ) | ( $bytes[2] & 0x3F );
	} elseif ( count( $bytes ) === 3 ) {
		$codepoint = ( ( $bytes[1] & 0x0F ) << 12 ) | ( ( $bytes[2] & 0x3F ) << 6 ) | ( $bytes[3] & 0x3F );
	} elseif ( count( $bytes ) === 4 ) {
		$codepoint = ( ( $bytes[1] & 0x07 ) << 18 ) | ( ( $bytes[2] & 0x3F ) << 12 ) | ( ( $bytes[3] & 0x3F ) << 6 ) | ( $bytes[4] & 0x3F );
	}

	return $codepoint;
}

/**
 * Encode a code point number into the UTF-8 encoding.
 *
 * This encoder implements the UTF-8 encoding algorithm for converting
 * a code point into a byte sequence. If it receives an invalid code
 * point it will return the Unicode Replacement Character U+FFFD `�`.
 *
 * Example:
 *
 *     '🅰' === WP_HTML_Decoder::codepoint_to_utf8_bytes( 0x1f170 );
 *
 *     // Half of a surrogate pair is an invalid code point.
 *     '�' === WP_HTML_Decoder::codepoint_to_utf8_bytes( 0xd83c );
 *
 * @since 6.6.0
 *
 * @see https://www.rfc-editor.org/rfc/rfc3629 For the UTF-8 standard.
 *
 * @param int $codepoint Which code point to convert.
 * @return string Converted code point, or `�` if invalid.
 */
function codepoint_to_utf8_bytes( $codepoint ) {
	// Pre-check to ensure a valid code point.
	if (
		$codepoint <= 0 ||
		( $codepoint >= 0xD800 && $codepoint <= 0xDFFF ) ||
		$codepoint > 0x10FFFF
	) {
		return '�';
	}

	if ( $codepoint <= 0x7F ) {
		return chr( $codepoint );
	}

	if ( $codepoint <= 0x7FF ) {
		$byte1 = chr( ( 0xC0 | ( ( $codepoint >> 6 ) & 0x1F ) ) );
		$byte2 = chr( $codepoint & 0x3F | 0x80 );

		return "{$byte1}{$byte2}";
	}

	if ( $codepoint <= 0xFFFF ) {
		$byte1 = chr( ( $codepoint >> 12 ) | 0xE0 );
		$byte2 = chr( ( $codepoint >> 6 ) & 0x3F | 0x80 );
		$byte3 = chr( $codepoint & 0x3F | 0x80 );

		return "{$byte1}{$byte2}{$byte3}";
	}

	// Any values above U+10FFFF are eliminated above in the pre-check.
	$byte1 = chr( ( $codepoint >> 18 ) | 0xF0 );
	$byte2 = chr( ( $codepoint >> 12 ) & 0x3F | 0x80 );
	$byte3 = chr( ( $codepoint >> 6 ) & 0x3F | 0x80 );
	$byte4 = chr( $codepoint & 0x3F | 0x80 );

	return "{$byte1}{$byte2}{$byte3}{$byte4}";
}
