<?php

namespace WordPress\HttpClient;

use Exception;

class HttpError extends Exception {
	public $message;
	public $request;

	public function __construct( $message, ?Request $request = null ) {
		$this->message = $message;
		$this->request = $request;
		parent::__construct( $message );
	}

	public function __toString() {
		$url = $this->request->url ?? '';
		if ( strlen( $url ) > 100 ) {
			$url = substr( $url, 0, 97 ) . '...';
		}
		return sprintf(
			'%s (Request: %s, %s)',
			$this->message,
			$this->request->id ?? 'unknown',
			$url
		);
	}
}
