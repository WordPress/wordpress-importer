<?php
/**
 * XML_Character_Filter file.
 *
 * @package WordPress
 * @subpackage Importer
 */

// String which will be prefixed to stream URLs to add this filter.
define( 'XML_CHARACTER_FILTER_PREFIX', 'php://filter/read=xml_character_filter/resource=' );

/**
 * Class XML_Character_Filter.
 *
 * XML Character Stream Filter to sanitize XML input. Removes control characters except newline, tab and return.
 *
 * Usage: php://filter/read=xml_character_filter/resource=zip://archive.zip#import.xml;
 */
class XML_Character_Filter extends php_user_filter {
	/**
	 * List of control characters to remove.
	 *
	 * @var array
	 */
	private $chars = array();

	/**
	 * This method is called whenever data is read from or written to the attached stream (such as with fread() or fwrite()).
	 *
	 * @param resource $in       A resource pointing to a bucket brigade which contains one or more bucket objects containing data to be filtered.
	 * @param resource $out      A resource pointing to a second bucket brigade into which the modified buckets should be placed.
	 * @param int      $consumed Reference to the length of the data that the filter reads in and alters.
	 * @param bool     $closing  Whether the stream is in the process of closing.
	 * @return int PSFS_PASS_ON|PSFS_FEED_ME|PSFS_ERR_FATAL.
	 */
	public function filter( $in, $out, &$consumed, $closing ) {
		while ( $bucket = stream_bucket_make_writeable( $in ) ) { //phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			$consumed    += $bucket->datalen;
			$bucket->data = $this->replace_chars( $bucket->data );
			stream_bucket_append( $out, $bucket );
		}

		return PSFS_PASS_ON;
	}

	/**
	 * This method is called during instantiation of the filter class object.
	 *
	 * @return bool
	 */
	public function onCreate() {
		for ( $ascii_num = 0; $ascii_num < 32; $ascii_num++ ) {
			if ( 9 !== $ascii_num && 10 !== $ascii_num && 13 !== $ascii_num ) {
				$this->chars[] = chr( $ascii_num );
			}
		}
		$this->chars[] = chr( 127 );

		return true;
	}

	/**
	 * Replace control characters.
	 *
	 * @param string $string Data to replace.
	 * @return string
	 */
	private function replace_chars( $string ) {
		return str_replace( $this->chars, ' ', $string );
	}
}
stream_filter_register( 'xml_character_filter', 'XML_Character_Filter' );
