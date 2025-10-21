<?php

namespace WordPress\HttpClient\Transport;

use WordPress\HttpClient\Client;
use WordPress\HttpClient\ClientState;
use WordPress\HttpClient\HttpClientException;
use WordPress\HttpClient\HttpError;
use WordPress\HttpClient\Request;
use WordPress\HttpClient\Response;

/**
 * An HTTP client using curl multihandle.
 *
 * @extends Client
 */
class CurlTransport implements TransportInterface {

	/**
	 * @var ClientState
	 */
	protected $state;

	/**
	 * @var \CurlMultiHandle cURL multi-handle managing parallel requests
	 */
	protected $multi_handle;

	/**
	 * @var array Map of cURL handle resource IDs to request IDs (for callbacks).
	 */
	protected $handle_map = array();

	/**
	 * Initializes a new CurlClient with optional settings.
	 *
	 * @param ClientState $state The client state containing configuration.
	 */
	public function __construct( ClientState $state ) {
		$this->state = $state;

		$this->multi_handle = curl_multi_init();
		curl_multi_setopt( $this->multi_handle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX );
		curl_multi_setopt( $this->multi_handle, CURLMOPT_MAX_TOTAL_CONNECTIONS, $this->state->concurrency );
		curl_multi_setopt( $this->multi_handle, CURLMOPT_MAX_HOST_CONNECTIONS, $this->state->concurrency );
	}

	/**
	 * Destructor to clean up the curl_multi handle.
	 */
	public function __destruct() {
		if ( $this->multi_handle ) {
			curl_multi_close( $this->multi_handle );
			$this->multi_handle = null;
		}
	}

	public function event_loop_tick(): bool {
		if ( 0 === count( $this->state->get_active_requests() ) ) {
			return false;
		}

		$this->open_nonblocking_curl_handles(
			$this->state->get_active_requests( array( Request::STATE_ENQUEUED ) )
		);

		if ( 0 === count( $this->handle_map ) ) {
			return false;
		}

		$this->poll_active_curl_requests();

		foreach ( $this->state->get_active_requests( array( Request::STATE_RECEIVED ) ) as $request ) {
			$this->mark_finished( $request );
		}

		return true;
	}

	private function open_nonblocking_curl_handles( $requests ) {
		foreach ( $requests as $request ) {
			// Initialize and add the curl handle for this request.
			$ch = $this->init_curl_handle( $request );
			/** @var \CurlHandle $ch */
			if ( ! $ch ) {
				// If initialization fails, immediately mark this request as failed.
				$this->set_error( $request, new HttpError( 'Failed to initialize cURL handle', $request ) );
				continue;
			}
			if ( 0 !== curl_multi_add_handle( $this->multi_handle, $ch ) ) {
				$this->set_error( $request, new HttpError( 'Failed to add cURL handle to multi handle', $request ) );
				continue;
			}
			$this->state->connections[ $request->id ]->http_socket = $ch;
			$this->handle_map[ (int) $ch ]                         = $request->id;
		}
	}

	private function poll_active_curl_requests() {
		$running = 0;
		do {
			$mrc = curl_multi_exec( $this->multi_handle, $running );
		} while ( CURLM_CALL_MULTI_PERFORM === $mrc );

		// Handle any completed requests.
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( $info = curl_multi_info_read( $this->multi_handle ) ) {
			if ( CURLMSG_DONE === $info['msg'] ) {
				$ch = $info['handle'];
				$id = $this->handle_map[ (int) $ch ] ?? null;
				if ( null === $id ) {
					throw new HttpClientException( 'Received completion event for an unknown request ' . ( $ch ? (int) $ch : 'unknown' ) );
				}
				$request = $this->state->get_request_by_id( $id );
				if ( CURLE_OK !== $info['result'] ) {
					$this->set_error( $request, new HttpError( sprintf( 'cURL error %d: %s', $info['result'], curl_error( $ch ) ) ) );
					return;
				}
				if ( ! $request->response ) {
					$this->set_error( $request, new HttpError( 'Connection closed while reading response headers.', $request ) );
					return;
				}
				if ( Request::STATE_FAILED === $request->state || Request::STATE_FINISHED === $request->state ) {
					// We've already handled errors and successes.
					continue;
				}
				// We'll mark it as finished in the event_loop_tick() method.
				$request->state = Request::STATE_RECEIVED;
			}
		}

		// @TODO: What kind of timeout should we use here?
		curl_multi_select( $this->multi_handle, 0.05 );
	}

	/**
	 * Create and configure a curl handle for the given Request.
	 *
	 * @param Request $request The HTTP request to prepare.
	 * @return resource|false Returns the configured cURL handle, or false on failure.
	 */
	private function init_curl_handle( $request ) {
		$ch = curl_init();
		if ( ! $ch ) {
			throw new HttpClientException( 'Failed to initialize cURL handle' );
		}
		// Basic curl settings for the request.
		curl_setopt( $ch, CURLOPT_URL, $request->url );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
		// Redirects are handled in the Client.
		curl_setopt( $ch, CURLOPT_MAXREDIRS, 0 );
		curl_setopt( $ch, CURLOPT_TIMEOUT_MS, $this->state->request_timeout_ms );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false ); // use callbacks for data.
		curl_setopt( $ch, CURLOPT_HEADER, false );         // headers via callback.
		curl_setopt( $ch, CURLOPT_ENCODING, '' );
		// Set HTTP method and body if needed.
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $request->method );
		if ( ! empty( $request->upload_body_stream ) ) {
			curl_setopt( $ch, CURLOPT_UPLOAD, true );
			curl_setopt(
				$ch,
				CURLOPT_READFUNCTION,
				function ( $ch, $fp, $length ) use ( $request ) {
					$stream = $request->upload_body_stream;
					// Pull at most $length bytes until we either get some bytes.
					// or we reach the end of the stream.
					while ( ! $stream->reached_end_of_data() ) {
						$got_bytes = $stream->pull( $length );
						if ( $got_bytes > 0 ) {
							return $stream->consume( $got_bytes );
						}
					}
					return '';
				}
			);
		}
		// Set headers if provided.
		if ( ! empty( $request->headers ) ) {
			$header_lines = array();
			foreach ( $request->headers as $name => $value ) {
				$header_lines[] = "{$name}: {$value}";
				if ( 'content-length' === $name && is_numeric( $value ) ) {
					curl_setopt( $ch, CURLOPT_INFILESIZE, (int) $value );
				}
			}
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $header_lines );
		}
		// Set callback functions for data and headers.
		curl_setopt(
			$ch,
			CURLOPT_WRITEFUNCTION,
			function ( $ch, $data ) {
				return $this->handle_body_data( $ch, $data );
			}
		);
		curl_setopt(
			$ch,
			CURLOPT_HEADERFUNCTION,
			function ( $ch, $header ) {
				return $this->handle_header_line( $ch, $header );
			}
		);
		// Disable signals (required for timeout in multi).
		curl_setopt( $ch, CURLOPT_NOSIGNAL, true );
		$request->state = Request::STATE_WILL_SEND_HEADERS;

		return $ch;
	}

	/**
	 * `cURL` callback to handle incoming header lines.
	 * Triggers an EVENT_GOT_HEADERS event when the header section is complete.
	 *
	 * @param resource $ch         The cURL handle.
	 * @param string   $header_line A line from the response headers.
	 * @return int Number of bytes handled (required by cURL).
	 */
	private function handle_header_line( $ch, $header_line ) {
		$request = $this->get_request_by_handle( $ch );
		if ( null === $request ) {
			throw new HttpClientException( 'Received header data for an unknown request ' . ( $ch ? (int) $ch : 'unknown' ) );
		}
		$connection = $this->state->connections[ $request->id ];
		if ( 0 === strlen( $connection->response_buffer ) ) {
			$request->state = Request::STATE_RECEIVING_HEADERS;
		}
		$connection->response_buffer .= $header_line;

		// Check for the end of the header section.
		if ( '' === trim( $header_line ) ) {
			$request->response           = Response::from_http_headers(
				$connection->response_buffer,
				$request
			);
			$connection->response_buffer = '';
			if ( false === $request->response ) {
				$request->response = new Response( $request );
				$this->set_error( $request, new HttpError( 'Failed to parse headers', $request ) );
				return strlen( $header_line );
			}
			$this->state->events[ $request->id ][ Client::EVENT_GOT_HEADERS ] = true;
			$request->state = Request::STATE_RECEIVING_BODY;
			return strlen( $header_line );
		}

		return strlen( $header_line );
	}

	/**
	 * `cURL` callback to handle chunks of response body data.
	 * Triggers an EVENT_BODY_CHUNK_AVAILABLE event for each chunk received.
	 *
	 * @param resource $ch   The cURL handle.
	 * @param string   $data The chunk of response body data.
	 * @return int Number of bytes handled.
	 */
	private function handle_body_data( $ch, $data ) {
		$request = $this->get_request_by_handle( $ch );
		if ( null === $request ) {
			throw new HttpClientException( 'Received body data for an unknown request ' . ( $ch ? (int) $ch : 'unknown' ) );
		}
		$this->state->connections[ $request->id ]->response_buffer                .= $data;
		$this->state->events[ $request->id ][ Client::EVENT_BODY_CHUNK_AVAILABLE ] = true;

		return strlen( $data );
	}

	private function get_request_by_handle( $handle ) {
		$request_id = $this->handle_map[ (int) $handle ] ?? null;
		return $this->state->get_request_by_id( $request_id );
	}

	private function mark_finished( Request $request ) {
		$this->state->set_request_finished( $request );
		$this->close_connection( $request );
	}

	private function set_error( Request $request, $error ) {
		$this->state->set_request_error( $request, $error );
		$this->close_connection( $request );
	}

	private function close_connection( Request $request ) {
		$handle = $this->state->connections[ $request->id ]->http_socket;
		if ( null !== $handle ) {
			curl_multi_remove_handle( $this->multi_handle, $handle );
			curl_close( $handle );
		}
		unset( $this->handle_map[ (int) $handle ] );
	}
}
