<?php
/**
 * @package WordPress
 */

class WP_Block_Parser_Error extends Exception {
	/**
	 * The type of error.
	 *
	 * @var string
	 */
	private $type;

	const TYPE_SUSPICIOUS_DELIMITER = 'suspicious-delimiter';
	const TYPE_MISMATCHED_CLOSER    = 'mismatched-block-closer';
	const TYPE_PARSE_ERROR          = 'parse-error';

	public function __construct( $message, $type = self::TYPE_PARSE_ERROR, ?Exception $previous = null ) {
		$this->type = $type;
		parent::__construct( $message, 0, $previous );
	}

	public function get_type() {
		return $this->type;
	}
}
