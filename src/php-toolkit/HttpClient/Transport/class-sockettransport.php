<?php

namespace WordPress\HttpClient\Transport;

use WordPress\ByteStream\ByteTransformer\InflateTransformer;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\ByteStream\ReadStream\TransformedReadStream;
use WordPress\HttpClient\ByteStream\ChunkedDecoderReadStream;
use WordPress\HttpClient\ByteStream\ChunkedEncoderByteTransformer;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\ClientState;
use WordPress\HttpClient\HttpError;
use WordPress\HttpClient\Request;
use WordPress\HttpClient\Response;

/**
 * An HTTP client using stream_socket_client(). Supports
 * concurrent connections just like curl_multi.
 *
 * Supports:
 * * Concurrency
 * * HTTP 1.0 and 1.1
 * * HTTPS via TLS 1.2 and 1.3
 * * Chunked transfer encoding
 * * Streaming requests and responses
 * * GZip and Deflate transfer encoding
 */
class SocketTransport implements TransportInterface {

	protected const STREAM_SELECT_READ  = 1;
	protected const STREAM_SELECT_WRITE = 2;

	/**
	 * @var ClientState
	 */
	protected $state;

	public function __construct( ClientState $state ) {
		$this->state = $state;
	}

	public function event_loop_tick(): bool {
		if ( 0 === count( $this->state->get_active_requests() ) ) {
			return false;
		}

		foreach ( $this->state->get_active_requests(
			array(
				Request::STATE_WILL_ENABLE_CRYPTO,
				Request::STATE_WILL_SEND_HEADERS,
				Request::STATE_WILL_SEND_BODY,
				Request::STATE_SENT,
				Request::STATE_RECEIVING_HEADERS,
				Request::STATE_RECEIVING_BODY,
				Request::STATE_RECEIVED,
			)
		) as $request ) {
			$time_elapsed_ms = $this->state->connections[ $request->id ]->time_elapsed_ms();
			if ( $time_elapsed_ms > $this->state->request_timeout_ms ) {
				$this->set_error( $request, new HttpError( sprintf( 'Request timed out after %d ms.', (int) $time_elapsed_ms ) ) );
			}
		}

		$this->open_nonblocking_http_sockets(
			$this->state->get_active_requests( Request::STATE_ENQUEUED )
		);

		$this->enable_crypto(
			$this->state->get_active_requests( Request::STATE_WILL_ENABLE_CRYPTO )
		);

		$this->send_request_headers(
			$this->state->get_active_requests( Request::STATE_WILL_SEND_HEADERS )
		);

		$this->send_request_body(
			$this->state->get_active_requests( Request::STATE_WILL_SEND_BODY )
		);

		$nb_headers_received = $this->receive_response_headers(
			$this->state->get_active_requests( Request::STATE_RECEIVING_HEADERS )
		);

		foreach ( $this->state->get_active_requests( Request::STATE_RECEIVED ) as $request ) {
			$this->mark_finished( $request );
		}

		/**
		 * Allows the caller to consume the headers before we start polling
		 * for the body of those requests.
		 *
		 * This prevents the following scenario:
		 *
		 * 1. The consumer calls await_next_event() and they're only interested in
		 *    the EVENT_GOT_HEADERS event.
		 * 2. In the same event_loop_tick:
		 *    * The headers arrive
		 *    * The request is promoted to STATE_RECEIVING_BODY
		 *    * We poll for the response body
		 *    * We wait 10 more seconds before the body starts arriving
		 * 3. The consumer gets the EVENT_GOT_HEADERS event 10 seconds later
		 *    than they could have.
		 */
		if ( $nb_headers_received > 0 ) {
			return true;
		}

		$this->receive_response_body(
			$this->state->get_active_requests( Request::STATE_RECEIVING_BODY )
		);

		return true;
	}

	/**
	 * Opens HTTP or HTTPS streams using stream_socket_client() without blocking,
	 * and returns nearly immediately.
	 *
	 * The act of opening a stream is non-blocking itself. This function uses
	 * a tcp:// stream wrapper, because both https:// and ssl:// wrappers would block
	 * until the SSL handshake is complete.
	 * The actual socket it then switched to non-blocking mode using stream_set_blocking().
	 *
	 * @param  Request $requests  The Request to open the socket for.
	 *
	 * @return bool Whether the stream was opened successfully.
	 */
	protected function open_nonblocking_http_sockets( $requests ) {
		foreach ( $requests as $request ) {
			$url    = $request->url;
			$parts  = parse_url( $url );
			$scheme = $parts['scheme'];
			if ( ! in_array( $scheme, array( 'http', 'https' ) ) ) {
				$this->set_error(
					$request,
					new HttpError( 'stream_http_open_nonblocking: Invalid scheme in URL ' . $url . ' – only http:// and https:// URLs are supported' )
				);
				continue;
			}

			$is_ssl = 'https' === $scheme;
			$port   = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
			$host   = $parts['host'];

			// Create stream context.
			$context = stream_context_create(
				array(
					'socket' => array(
						'isSsl'       => $is_ssl,
						'originalUrl' => $url,
						'socketUrl'   => 'tcp://' . $host . ':' . $port,
					),
				)
			);

			$stream = @stream_socket_client(
				'tcp://' . $host . ':' . $port,
				$errno,
				$errstr,
				$this->state->request_timeout_ms / 1000,
				STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
				$context
			);

			if ( false === $stream ) {
				$this->set_error(
					$request,
					new HttpError( "stream_http_open_nonblocking: stream_socket_client() was unable to open a stream to $url. $errno: $errstr" )
				);
				continue;
			}

			stream_set_blocking( $stream, false );

			$this->state->connections[ $request->id ]->http_socket = $stream;
			$this->state->connections[ $request->id ]->started_at  = microtime( true );
			if ( $is_ssl ) {
				$request->state = Request::STATE_WILL_ENABLE_CRYPTO;
			} else {
				$request->state = Request::STATE_WILL_SEND_HEADERS;
			}
		}

		return true;
	}

	/**
	 * Handle transfer encodings.
	 *
	 * @param  Request $request
	 *
	 * @return false|resource
	 */
	protected function decode_and_monitor_response_body_stream( Request $request ) {
		$transfer_encodings = array();

		$transfer_encoding = $request->response->get_header( 'transfer-encoding' );
		if ( $transfer_encoding ) {
			$transfer_encodings = array_map( 'trim', explode( ',', $transfer_encoding ) );
		}

		$content_encoding = $request->response->get_header( 'content-encoding' );
		if ( $content_encoding && ! in_array( $content_encoding, $transfer_encodings ) ) {
			$transfer_encodings[] = $content_encoding;
		}

		$body_stream = FileReadStream::from_resource(
			$this->state->connections[ $request->id ]->http_socket
		);

		$transformers = array();
		foreach ( $transfer_encodings as $transfer_encoding ) {
			switch ( $transfer_encoding ) {
				case 'chunked':
					$body_stream = new ChunkedDecoderReadStream( $body_stream );
					break;
				case 'gzip':
					$transformers[] = new InflateTransformer(
						'gzip' === $transfer_encoding ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_RAW
					);
					break;
				case 'deflate':
					$transformers[] = new InflateTransformer( ZLIB_ENCODING_DEFLATE );
					break;
				case 'identity':
					// No-op.
					break;
				default:
					$this->set_error(
						$request,
						new HttpError( 'Unsupported transfer encoding received from the server: ' . $transfer_encoding )
					);
					break;
			}
		}

		return new TransformedReadStream(
			$body_stream,
			$transformers
		);
	}

	/**
	 * Sends HTTP requests using streams.
	 *
	 * Enables crypto on the $requests HTTP socksts and sends the request body asynchronously.
	 *
	 * @param  Request[] $requests  An array of HTTP requests.
	 */
	protected function enable_crypto( array $requests ) {
		foreach ( $this->stream_select( $requests, static::STREAM_SELECT_WRITE ) as $request ) {
			@stream_set_timeout( $this->state->connections[ $request->id ]->http_socket, 1 );

			// Use @ to suppress warnings. They're collected by error_get_last().
			$enabled_crypto = @stream_socket_enable_crypto(
				$this->state->connections[ $request->id ]->http_socket,
				true,
				STREAM_CRYPTO_METHOD_TLS_CLIENT
			);
			if ( false === $enabled_crypto ) {
				$last_error = error_get_last();
				$this->set_error(
					$request,
					new HttpError( 'Failed to enable crypto: ' . ( is_array( $last_error ) ? $last_error['message'] : 'unknown' ) )
				);
				continue;
			} elseif ( 0 === $enabled_crypto ) {
				// The SSL handshake isn't finished yet, let's skip it.
				// for now and try again on the next event loop pass.
				continue;
			}
			// SSL connection established, let's send the headers.
			$request->state = Request::STATE_WILL_SEND_HEADERS;
		}
	}

	/**
	 * Sends HTTP request headers.
	 *
	 * @param  Request[] $requests  An array of HTTP requests.
	 */
	protected function send_request_headers( array $requests ) {
		foreach ( $this->stream_select( $requests, static::STREAM_SELECT_WRITE ) as $request ) {
			$header_bytes = static::prepare_request_headers( $request );
			if ( false === @fwrite( $this->state->connections[ $request->id ]->http_socket, $header_bytes ) ) {
				$last_error         = error_get_last();
				$last_error_message = is_array( $last_error ) ? $last_error['message'] : 'unknown';
				$this->set_error(
					$request,
					new HttpError( 'Failed to write request bytes - ' . $last_error_message )
				);
				continue;
			}

			if ( $request->upload_body_stream ) {
				$request->state = Request::STATE_WILL_SEND_BODY;

				if ( 'chunked' === $request->get_header( 'transfer-encoding' ) ) {
					$request->upload_body_stream = new TransformedReadStream(
						$request->upload_body_stream,
						array( new ChunkedEncoderByteTransformer() )
					);
				}
			} else {
				$request->state = Request::STATE_RECEIVING_HEADERS;
			}
		}
	}

	/**
	 * Sends HTTP request body.
	 *
	 * @param  Request[] $requests  An array of HTTP requests.
	 */
	protected function send_request_body( array $requests ) {
		foreach ( $this->stream_select( $requests, self::STREAM_SELECT_WRITE ) as $request ) {
			if ( $request->upload_body_stream->reached_end_of_data() ) {
				$request->upload_body_stream->close_reading();
				$request->upload_body_stream = null;
				$request->state              = Request::STATE_RECEIVING_HEADERS;
				continue;
			}

			$available_bytes = $request->upload_body_stream->pull( 65536 );
			if ( 0 === $available_bytes ) {
				// Not all pull() calls must yield bytes, maybe we just need to wait for the next chunk.
				// Let's continue and keep trying.
				// @TODO: Implement a generic timeout mechanism for pull() calls.
				continue;
			}

			$chunk = $request->upload_body_stream->consume( $available_bytes );
			if ( ! @fwrite( $this->state->connections[ $request->id ]->http_socket, $chunk ) ) {
				$last_error         = error_get_last();
				$last_error_message = is_array( $last_error ) ? $last_error['message'] : 'unknown';
				$this->set_error( $request, new HttpError( 'Failed to write request bytes: ' . $last_error_message ) );
				continue;
			}
		}
	}

	/**
	 * Reads the next received portion of HTTP response headers for multiple requests.
	 *
	 * @param  array $requests  An array of requests.
	 */
	protected function receive_response_headers( $requests ) {
		$nb_headers_received = 0;

		foreach ( $this->stream_select( $requests, static::STREAM_SELECT_READ ) as $request ) {
			if ( ! $request->response ) {
				$request->response = new Response( $request );
			}
			$connection = $this->state->connections[ $request->id ];
			$response   = $request->response;

			while ( true ) {
				// @TODO: Use a larger chunk size here and then scan for \r\n\r\n.
				// 1 seems slow and overly conservative.
				if (
					! $this->state->connections[ $request->id ]->http_socket ||
					! is_resource( $this->state->connections[ $request->id ]->http_socket ) ||
					@feof( $this->state->connections[ $request->id ]->http_socket )
				) {
					$this->set_error( $request, new HttpError( 'Connection closed while reading response headers.' ) );
					break;
				}

				$header_byte = fread( $this->state->connections[ $request->id ]->http_socket, 1 );

				if ( false === $header_byte || '' === $header_byte ) {
					if (
						! $this->state->connections[ $request->id ]->http_socket ||
						! is_resource( $this->state->connections[ $request->id ]->http_socket ) ||
						@feof( $this->state->connections[ $request->id ]->http_socket )
					) {
						$this->set_error( $request, new HttpError( 'Connection closed while reading response headers.' ) );
						break;
					}
					break;
				}
				$connection->response_buffer .= $header_byte;

				$buffer_size = strlen( $connection->response_buffer );
				if (
					$buffer_size < 4 ||
					"\r" !== $connection->response_buffer[ $buffer_size - 4 ] ||
					"\n" !== $connection->response_buffer[ $buffer_size - 3 ] ||
					"\r" !== $connection->response_buffer[ $buffer_size - 2 ] ||
					"\n" !== $connection->response_buffer[ $buffer_size - 1 ]
				) {
					continue;
				}

				$request->response           = Response::from_http_headers(
					$connection->response_buffer,
					$request
				);
				$connection->response_buffer = '';
				if ( false === $request->response ) {
					$this->set_error( $request, new HttpError( 'Malformed HTTP headers received from the server.' ) );
					break;
				}

				$this->state->events[ $request->id ][ Client::EVENT_GOT_HEADERS ] = true;
				++$nb_headers_received;

				if ( 0 === $response->total_bytes ) {
					$request->state = Request::STATE_RECEIVED;
					break;
				}

				$request->state = Request::STATE_RECEIVING_BODY;
				$this->state->connections[ $request->id ]->decoded_response_stream = $this->decode_and_monitor_response_body_stream( $request );
				break;
			}
		}

		return $nb_headers_received;
	}

	/**
	 * Reads the next received portion of HTTP response headers for multiple requests.
	 *
	 * @param  array $requests  An array of requests.
	 */
	protected function receive_response_body( $requests ) {
		// @TODO: Assume body is fully received when either.
		// * Content-Length is reached.
		// * The last chunk in Transfer-Encoding: chunked is received.
		// * The connection is closed.
		foreach ( $this->stream_select( $requests, static::STREAM_SELECT_READ ) as $request ) {
			$stream = $this->state->connections[ $request->id ]->decoded_response_stream;

			while ( true ) {
				$available_bytes = $stream->pull( 65536 );
				if ( $available_bytes > 0 ) {
					$body_chunk                         = $stream->consume( $available_bytes );
					$request->response->received_bytes += $available_bytes;
					$this->state->connections[ $request->id ]->response_buffer                .= $body_chunk;
					$this->state->events[ $request->id ][ Client::EVENT_BODY_CHUNK_AVAILABLE ] = true;
					break; // Process one chunk per loop iteration.
				} elseif ( $stream->reached_end_of_data() ) {
					$request->state = Request::STATE_RECEIVED;
					break;
				}
			}
		}
	}

	/**
	 * Prepares an HTTP request string for a given URL.
	 *
	 * @param  Request $request  The Request to prepare the HTTP headers for.
	 *
	 * @return string The prepared HTTP request string.
	 */
	protected static function prepare_request_headers( Request $request ) {
		$url   = $request->url;
		$parts = parse_url( $url );
		$path  = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

		$headers = $request->headers;

		/**
		 * Disable the gzip transfer compression when requesting a byte range.
		 *
		 * When we're requesting a byte range AND gzipped transfer encoding,
		 * our intention is to get compressed bytes 0-X of the original file.
		 *
		 * However, some servers will compress the file first, and then return
		 * the compressed bytes 0-X. The result is both unpredictable and impossible
		 * to decompress.
		 */
		if ( ! array_key_exists( 'range', $headers ) && ! array_key_exists( 'accept-encoding', $headers ) ) {
			$headers['accept-encoding'] = 'gzip';
		}

		$request_parts = array(
			"$request->method $path HTTP/$request->http_version",
		);

		foreach ( $headers as $name => $value ) {
			$request_parts[] = "$name: $value";
		}

		return implode( "\r\n", $request_parts ) . "\r\n\r\n";
	}

	protected function filter_requests( array $requests, $states ) {
		if ( ! is_array( $states ) ) {
			$states = array( $states );
		}
		$results = array();
		foreach ( $requests as $request ) {
			if ( in_array( $request->state, $states ) ) {
				$results[] = $request;
			}
		}

		return $results;
	}


	protected function stream_select( $requests, $mode ) {
		if ( empty( $requests ) ) {
			return array();
		}

		$read  = array();
		$write = array();
		foreach ( $requests as $k => $request ) {
			if ( $mode & static::STREAM_SELECT_READ ) {
				$read[ $k ] = $this->state->connections[ $request->id ]->http_socket;
			}
			if ( $mode & static::STREAM_SELECT_WRITE ) {
				$write[ $k ] = $this->state->connections[ $request->id ]->http_socket;
			}
		}
		$except = null;
		if ( 0 === count( $read ) && 0 === count( $write ) ) {
			return array();
		}

		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		$ready = @stream_select( $read, $write, $except, 0, ClientState::NONBLOCKING_TIMEOUT_MICROSECONDS );
		if ( false === $ready ) {
			foreach ( $requests as $request ) {
				$this->set_error( $request, new HttpError( 'Error: ' . error_get_last()['message'] ) );
			}

			return array();
		} elseif ( $ready <= 0 ) {
			// @TODO allow at most X stream_select attempts per request.
			// foreach ( $unprocessed_requests as $request ) {.
			// $this->>set_error($request, new HttpError( 'stream_select timed out' ));.
			// }.
			return array();
		}

		$selected_requests = array();
		foreach ( array_keys( $read ) as $k ) {
			$selected_requests[ $k ] = $requests[ $k ];
		}
		foreach ( array_keys( $write ) as $k ) {
			$selected_requests[ $k ] = $requests[ $k ];
		}

		return $selected_requests;
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
		$socket = $this->state->connections[ $request->id ]->http_socket;
		if ( $socket && is_resource( $socket ) ) {
			// Close the TCP socket.
			if ( $this->state->connections[ $request->id ]->decoded_response_stream ) {
				$stream = $this->state->connections[ $request->id ]->decoded_response_stream;
				$stream->close_reading();
				$this->state->connections[ $request->id ]->decoded_response_stream = null;
			} else {
				@fclose( $socket );
			}
		}
	}
}
