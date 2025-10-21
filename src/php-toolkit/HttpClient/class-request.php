<?php

namespace WordPress\HttpClient;

class Request {

	const STATE_CREATED            = 'STATE_CREATED';
	const STATE_ENQUEUED           = 'STATE_ENQUEUED';
	const STATE_WILL_ENABLE_CRYPTO = 'STATE_WILL_ENABLE_CRYPTO';
	const STATE_WILL_SEND_HEADERS  = 'STATE_WILL_SEND_HEADERS';
	const STATE_WILL_SEND_BODY     = 'STATE_WILL_SEND_BODY';
	const STATE_SENT               = 'STATE_SENT';
	const STATE_RECEIVING_HEADERS  = 'STATE_RECEIVING_HEADERS';
	const STATE_RECEIVING_BODY     = 'STATE_RECEIVING_BODY';
	const STATE_RECEIVED           = 'STATE_RECEIVED';
	const STATE_FAILED             = 'STATE_FAILED';
	const STATE_FINISHED           = 'STATE_FINISHED';

	private static $last_id;

	public $id;

	public $state = self::STATE_CREATED;

	public $url;
	public $is_ssl;
	public $method;
	public $headers;
	public $http_version;
	/**
	 * @var WP_Byte_Reader
	 */
	public $upload_body_stream;
	public $redirected_from;
	public $redirected_to;

	public $cache_key;

	/**
	 * @var HttpError
	 */
	public $error;
	/**
	 * @var Response
	 */
	public $response;

	/**
	 * @param  string $url
	 */
	public function __construct( string $url, $request_info = array() ) {
		$request_info = array_merge(
			array(
				'http_version'    => '1.1',
				'method'          => 'GET',
				'headers'         => array(),
				'body_stream'     => null,
				'redirected_from' => null,
			),
			$request_info
		);

		$this->id     = ++ self::$last_id;
		$this->is_ssl = 0 === strpos( $url, 'https://' );

		// Extract username/password from URL if present.
		// @TODO: Use the WHATWG URL parser.
		$url_parts = parse_url( $url );
		if ( ! empty( $url_parts['user'] ) ) {
			$auth = $url_parts['user'];
			if ( ! empty( $url_parts['pass'] ) ) {
				$auth .= ':' . $url_parts['pass'];
			}
			// Add basic auth header.
			$request_info['headers']['authorization'] = 'Basic ' . base64_encode( $auth );

			// Remove credentials from URL.
			$url =
				$url_parts['scheme'] . '://' .
				$url_parts['host'] .
				( ! empty( $url_parts['port'] ) ? ':' . $url_parts['port'] : '' ) .
				( ! empty( $url_parts['path'] ) ? $url_parts['path'] : '' ) .
				( ! empty( $url_parts['query'] ) ? '?' . $url_parts['query'] : '' ) .
				( ! empty( $url_parts['fragment'] ) ? '#' . $url_parts['fragment'] : '' );
		}

		$this->url    = $url;
		$this->method = $request_info['method'];

		$headers = array(
			'host'            => isset( $url_parts['host'] ) ? $url_parts['host'] : '',
			'user-agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.82 Safari/537.36',
			'accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
			'accept-language' => 'en-US,en;q=0.9',
			'connection'      => 'close',
		);
		if ( $request_info['body_stream'] ) {
			$length = $request_info['body_stream']->length();
			if ( null !== $length ) {
				$headers['content-length'] = $length;
			} else {
				$headers['transfer-encoding'] = 'chunked';
			}
		}
		foreach ( $request_info['headers'] as $k => $v ) {
			$headers[ $k ] = $v;
		}
		$this->headers            = array_change_key_case( $headers, CASE_LOWER );
		$this->upload_body_stream = $request_info['body_stream'];
		$this->http_version       = $request_info['http_version'];
		$this->redirected_from    = $request_info['redirected_from'];
		if ( $this->redirected_from ) {
			$this->redirected_from->redirected_to = $this;
		}
	}

	public function __clone() {
		$this->id = ++ self::$last_id;
	}

	public function get_header( $name ) {
		return $this->headers[ $name ] ?? null;
	}

	public function latest_redirect() {
		$request = $this;
		while ( $request->redirected_to ) {
			$request = $request->redirected_to;
		}

		return $request;
	}

	public function original_request() {
		$request = $this;
		while ( $request->redirected_from ) {
			$request = $request->redirected_from;
		}

		return $request;
	}

	public function is_redirected() {
		return null !== $this->redirected_to;
	}
}
