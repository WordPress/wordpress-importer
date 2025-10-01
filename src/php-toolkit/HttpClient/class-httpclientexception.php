<?php

namespace WordPress\HttpClient;

use Exception;

class HttpClientException extends Exception {
	public $request;

	public function __construct( $message, $code = 0, ?Exception $previous = null, $request = null ) {
		parent::__construct( $message, $code, $previous );
		$this->request = $request;
	}
}
