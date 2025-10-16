src/php-toolkit/DataLiberation/class-dataliberationexception.php <?php

namespace WordPress\DataLiberation;

use Exception;

/**
 * Represents an error that occurs during the data liberation process.
 */
class DataLiberationException extends Exception {

	public $code_str;

	public function __construct( $code_str = '', $message = '', $code = 0, $previous = null ) {
		parent::__construct( $message, $code, $previous );
		$this->code_str = $code_str;
	}
}
