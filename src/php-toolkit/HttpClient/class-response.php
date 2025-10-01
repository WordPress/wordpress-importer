<?php

namespace WordPress\HttpClient;

use WordPress\HttpServer\StatusCode;

class Response {

	public $protocol;
	public $status_code;
	public $status_message;
	public $headers = array();
	public $request;

	public $received_bytes = 0;
	public $total_bytes    = null;

	public function __construct( ?Request $request = null ) {
		$this->request = $request;
	}

	public function get_header( $name ) {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	public function get_reason_phrase() {
		return StatusCode::text( $this->status_code );
	}

	public function ok() {
		return $this->status_code >= 200 && $this->status_code < 400;
	}

	/**
	 * Parses HTTP headers string into a new Response object.
	 *
	 * Supports both HTTP/1.x and HTTP/2 response header formats:
	 * - HTTP/1.x: "HTTP/1.1 200 OK" with optional status message
	 * - HTTP/2: ":status: 200" pseudo-header without status message
	 *
	 * @param  string       $headers_raw  The HTTP headers to parse.
	 * @param  Request|null $request  Optional request object to associate with the response.
	 *
	 * @return Response|false A new Response object, or false if the headers are invalid.
	 */
	public static function from_http_headers( $headers_raw, ?Request $request = null ) {
		$lines      = explode( "\r\n", $headers_raw );
		$first_line = array_shift( $lines );

		$response       = new Response( $request );
		$headers_parsed = array();

		// Check if this is HTTP/2 format (starts with :status pseudo-header).
		if ( 0 === strpos( $first_line, ':status:' ) ) {
			// HTTP/2 format - parse :status pseudo-header.
			$status_parts = explode( ':', $first_line, 3 );
			if ( count( $status_parts ) < 3 ) {
				return false;
			}

			$status_code = (int) trim( $status_parts[2] );
			if ( $status_code < 100 || $status_code > 599 ) {
				return false;
			}

			$response->protocol       = 'HTTP/2.0';
			$response->status_code    = $status_code;
			$response->status_message = null; // HTTP/2 doesn't have status messages.

			// Process remaining headers.
			foreach ( $lines as $line ) {
				if ( empty( $line ) ) {
					continue;
				}

				if ( false === strpos( $line, ': ' ) ) {
					// Invalid header format.
					continue;
				}

				$header_parts = explode( ': ', $line, 2 );
				$header_name  = strtolower( trim( $header_parts[0] ) );
				$header_value = trim( $header_parts[1] );

				// Skip pseudo-headers (already processed :status above).
				if ( 0 === strpos( $header_name, ':' ) ) {
					continue;
				}

				$headers_parsed[ $header_name ] = $header_value;
			}
		} else {
			// HTTP/1.x format - parse traditional status line.
			$status_parts = explode( ' ', $first_line, 3 );
			if ( count( $status_parts ) < 2 ) {
				return false;
			}

			$protocol       = $status_parts[0];
			$status_code    = (int) $status_parts[1];
			$status_message = isset( $status_parts[2] ) ? $status_parts[2] : '';

			if ( $status_code < 100 || $status_code > 599 ) {
				return false;
			}

			$response->protocol       = $protocol;
			$response->status_code    = $status_code;
			$response->status_message = $status_message;

			// Process remaining headers.
			foreach ( $lines as $line ) {
				if ( empty( $line ) ) {
					continue;
				}

				if ( false === strpos( $line, ': ' ) ) {
					// Invalid header format.
					continue;
				}

				$header_parts = explode( ': ', $line, 2 );
				/**
				 * Headers names are case-insensitive.
				 *
				 * RFC 7230 states:
				 *
				 * > Each header field consists of a case-insensitive field name followed by a colon (":"),
				 * > optional leading whitespace, the field value, and optional trailing whitespace."
				 */
				$headers_parsed[ strtolower( $header_parts[0] ) ] = $header_parts[1];
			}
		}

		$response->headers = $headers_parsed;
		$content_length    = $response->get_header( 'content-length' );
		if ( null !== $content_length ) {
			$response->total_bytes = (int) $content_length;
		}
		return $response;
	}
}
