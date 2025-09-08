<?php

namespace WordPress\HttpClient;

class ClientState {

	/**
	 * Microsecond is 1 millionth of a second.
	 *
	 * @var int
	 */
	const MICROSECONDS_TO_SECONDS = 1000000;

	/**
	 * 5/100th of a second
	 */
	const NONBLOCKING_TIMEOUT_MICROSECONDS = 0.05 * self::MICROSECONDS_TO_SECONDS;

	/**
	 * The maximum number of concurrent connections allowed.
	 *
	 * This is as a safeguard against:
	 * * Spreading our network bandwidth too thin and not making any real progress on any
	 *   request.
	 * * Overwhelming the server with too many requests.
	 *
	 * @var int
	 */
	public $concurrency;

	/**
	 * All the HTTP requests ever enqueued with this Client.
	 *
	 * Each Request may have a different state, and this Client will manage them
	 * asynchronously, moving them through the various states as the network
	 * operations progress.
	 *
	 * @since Next Release
	 * @var Request[]
	 */
	public $requests = array();

	/**
	 * Network connection details managed privately by this Client.
	 *
	 * Each Request has a corresponding Connection object that contains
	 * the connection handle, response buffer, and other details.
	 *
	 * These are internal, will change without warning, and should not be
	 * exposed to the outside world.
	 *
	 * @var array
	 */
	public $connections         = array();
	public $events              = array();
	public $event               = null;
	public $request             = null;
	public $response_body_chunk = null;
	public $request_timeout_ms  = null;

	public function __construct( $options = array() ) {
		$this->concurrency        = $options['concurrency'] ?? 10;
		$this->request_timeout_ms = $options['timeout_ms'] ?? 30000;
	}

	public function has_pending_event( $request, $event_type ) {
		return $this->events[ $request->id ][ $event_type ] ?? false;
	}

	/**
	 * Returns the next event found by await_next_event().
	 *
	 * @return string|bool The next event, or false if no event is set.
	 */
	public function get_event() {
		if ( null === $this->event ) {
			return false;
		}

		return $this->event;
	}

	/**
	 * Returns the request associated with the last event found
	 * by await_next_event().
	 *
	 * @return Request
	 */
	public function get_request() {
		if ( null === $this->request ) {
			return false;
		}

		return $this->request;
	}

	/**
	 * Returns the response body chunk associated with the EVENT_BODY_CHUNK_AVAILABLE
	 * event found by await_next_event().
	 *
	 * @return string|false
	 */
	public function get_response_body_chunk() {
		if ( null === $this->response_body_chunk ) {
			return false;
		}

		return $this->response_body_chunk;
	}

	public function get_active_requests( $states = null ) {
		$processed_requests = $this->get_requests(
			array(
				Request::STATE_WILL_ENABLE_CRYPTO,
				Request::STATE_WILL_SEND_HEADERS,
				Request::STATE_WILL_SEND_BODY,
				Request::STATE_SENT,
				Request::STATE_RECEIVING_HEADERS,
				Request::STATE_RECEIVING_BODY,
				Request::STATE_RECEIVED,
			)
		);
		$available_slots    = $this->concurrency - count( $processed_requests );
		$enqueued_requests  = $this->get_requests( Request::STATE_ENQUEUED );
		for ( $i = 0; $i < $available_slots; $i++ ) {
			if ( ! isset( $enqueued_requests[ $i ] ) ) {
				break;
			}
			$processed_requests[] = $enqueued_requests[ $i ];
		}
		if ( null !== $states ) {
			$processed_requests = static::filter_requests_by_state( $processed_requests, $states );
		}

		return $processed_requests;
	}

	public function get_requests( $states ) {
		if ( ! is_array( $states ) ) {
			$states = array( $states );
		}

		return static::filter_requests_by_state( $this->requests, $states );
	}

	public static function filter_requests_by_state( array $requests, $states ) {
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

	public function get_request_by_id( $request_id ) {
		foreach ( $this->requests as $request ) {
			if ( $request->id === $request_id ) {
				return $request;
			}
		}
	}

	/**
	 * Consumes $length bytes received in response to a given request.
	 *
	 * @return string
	 */
	public function consume_buffered_response_body( $request_id ) {
		$request = $this->get_request_by_id( $request_id );
		if ( null === $request ) {
			return false;
		}
		$connection = $this->connections[ $request->id ];
		if (
			Request::STATE_RECEIVING_BODY === $request->state ||
			Request::STATE_RECEIVED === $request->state ||
			Request::STATE_FINISHED === $request->state
		) {
			return $connection->consume_buffer();
		}

		$end_of_data = Request::STATE_FINISHED === $request->state && (
			! is_resource( $this->connections[ $request->id ]->http_socket ) ||
			$this->connections[ $request->id ]->decoded_response_stream->reached_end_of_data()
		);
		if ( $end_of_data ) {
			return false;
		}

		return '';
	}

	public function set_request_error( Request $request, $error ) {
		$request->error                                       = $error;
		$request->state                                       = Request::STATE_FAILED;
		$this->events[ $request->id ][ Client::EVENT_FAILED ] = true;
	}

	public function set_request_finished( Request $request ) {
		$request->state = Request::STATE_FINISHED;
		$this->events[ $request->id ][ Client::EVENT_FINISHED ] = true;
	}
}
