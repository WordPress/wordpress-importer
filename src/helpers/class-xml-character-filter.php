<?php

/*
 * String which will be prefixed to stream URLs to add this filter
 */
define( 'XML_CHARACTER_FILTER_PREFIX', 'php://filter/read=xml_character_filter/resource=' );

/**
 * Class XML_Character_Filter 
 *
 * Filter for PHP stream
 *
 * Remove control characters except newline, tab and return
 *
 * Usage: php://filter/read=xml_character_filter/resource=zip://archive.zip#import.xml;
 */
class XML_Character_Filter extends php_user_filter {

	private $chars = [];

	public function filter( $in, $out, &$consumed, $closing ) {
		while ( $bucket = stream_bucket_make_writeable( $in ) ) {
			$consumed     += $bucket->datalen;
			$bucket->data = $this->replace_chars( $bucket->data );
			stream_bucket_append( $out, $bucket );
		}

		return PSFS_PASS_ON;
	}

	private function replace_chars( $string ) {
		return str_replace( $this->chars, ' ', $string );
	}

	public function onCreate() {
		for ( $ascii_num = 0; $ascii_num < 32; $ascii_num ++ ) {
			if ( $ascii_num !== 9 && $ascii_num !== 10 && $ascii_num !== 13 ) {
				$this->chars[] = chr( $ascii_num );
			}
		}
		$this->chars[] = chr( 127 );

		return true;
	}
}

stream_filter_register( 'xml_character_filter', 'XML_Character_Filter' );
