<?php

namespace WordPress\HttpClient\Middleware;

use WordPress\HttpClient\Client;
use WordPress\HttpClient\ClientState;
use WordPress\HttpClient\HttpClientException;
use WordPress\HttpClient\Request;
use WordPress\HttpClient\Connection;
use WordPress\HttpClient\Transport\CurlTransport;
use WordPress\HttpClient\Transport\SocketTransport;
use WordPress\HttpClient\Transport\TransportInterface;

class HttpMiddleware implements MiddlewareInterface {

	const EVENT_GOT_HEADERS          = 'EVENT_GOT_HEADERS';
	const EVENT_BODY_CHUNK_AVAILABLE = 'EVENT_BODY_CHUNK_AVAILABLE';
	const EVENT_FAILED               = 'EVENT_FAILED';
	const EVENT_FINISHED             = 'EVENT_FINISHED';

	/**
	 * @var ClientState
	 */
	private $state;
	/**
	 * @var TransportInterface
	 */
	private $transport;

	public function __construct( $client_state, $options = array() ) {
		$this->state     = $client_state;
		$this->transport = $options['transport'];
	}

	/**
	 * Enqueues one or multiple HTTP requests for asynchronous processing.
	 * It does not open the network sockets, only adds the Request objects to
	 * an internal queue. Network transmission is delayed until one of the returned
	 * streams is read from.
	 *
	 * @param  Request $request  The HTTP request to enqueue.
	 */
	public function enqueue( Request $request ) {
		$request->state                           = Request::STATE_ENQUEUED;
		$this->state->requests[]                  = $request;
		$this->state->events[ $request->id ]      = array();
		$this->state->connections[ $request->id ] = new Connection( $request );
	}

	/**
	 * Returns the next event related to any of the HTTP
	 * requests enqueued in this client.
	 *
	 * ## Events
	 *
	 * The returned event is a ClientEvent with $event->name
	 * being one of the following:
	 *
	 * * `Client::EVENT_GOT_HEADERS`
	 * * `Client::EVENT_BODY_CHUNK_AVAILABLE`
	 * * `Client::EVENT_FAILED`
	 * * `Client::EVENT_FINISHED`
	 *
	 * See the ClientEvent class for details on each event.
	 *
	 * Once an event is consumed, it is removed from the
	 * event queue and will not be returned again.
	 *
	 * When there are no events available, this function
	 * blocks and waits for the next one. If all requests
	 * have already finished, and we are not waiting for
	 * any more events, it returns false.
	 *
	 * ## Filtering
	 *
	 * The $query parameter can be used to filter the events
	 * that are returned. It can contain the following keys:
	 *
	 * * `request_id` – The ID of the request to consider.
	 *
	 * For example, to only consider the next `EVENT_GOT_HEADERS`
	 * event for a specific request, you can use:
	 *
	 * ```php
	 * $request = new Request( "https://w.org" );
	 *
	 * $client = new HttpClientClient();
	 * $client->enqueue( $request );
	 * $event = $client->await_next_event( [
	 *    'request_id' => $request->id,
	 * ] );
	 * ```
	 *
	 * Importantly, filtering does not consume unrelated events.
	 * You can await all the events for a request #2, and
	 * then await the next event for request #1 even if the
	 * request #1 has finished before you started awaiting
	 * events for request #2.
	 *
	 * @param $query
	 *
	 * @return bool
	 */
	public function await_next_event( $requests_ids ): bool {
		$ordered_events                   = array(
			Client::EVENT_GOT_HEADERS,
			Client::EVENT_BODY_CHUNK_AVAILABLE,
			Client::EVENT_FAILED,
			Client::EVENT_FINISHED,
		);
		$this->state->event               = null;
		$this->state->request             = null;
		$this->state->response_body_chunk = null;

		// Give the requests an opportunity to time out; 10% more, but at least 300ms.
		$timeout_ms = $this->state->request_timeout_ms + max( 300, $this->state->request_timeout_ms * 0.1 );
		$start_time = microtime( true );

		do {
			foreach ( $requests_ids as $request_id ) {
				foreach ( $ordered_events as $considered_event ) {
					$needs_emitting = $this->state->events[ $request_id ][ $considered_event ] ?? false;
					if ( ! $needs_emitting ) {
						continue;
					}

					$this->state->events[ $request_id ][ $considered_event ] = false;
					$this->state->event                                      = $considered_event;
					$this->state->request                                    = $this->state->get_request_by_id( $request_id );
					switch ( $this->state->event ) {
						case Client::EVENT_BODY_CHUNK_AVAILABLE:
							$this->state->response_body_chunk = $this->state->consume_buffered_response_body( $request_id );
							break;
						case Client::EVENT_FAILED:
						case Client::EVENT_FINISHED:
							// We don't need the response buffer anymore. It's.
							// safe to clean up the connection object now. The.
							// HTTP resource have been closed by now via the.
							// close_connection() method.
							unset( $this->state->connections[ $request_id ] );
							break;
					}

					return true;
				}
			}

			// After we've checked for any available events, see if we've run out of time.
			// This way, we always return any events that were ready before worrying about the timeout.
			// If we checked the timeout first, we might miss events that were already waiting for us.
			// when the timeout is set to zero.
			$time_elapsed_ms = ( microtime( true ) - $start_time ) * 1000;
			if ( $timeout_ms && $time_elapsed_ms >= $timeout_ms ) {
				return false;
			}
		} while ( $this->transport->event_loop_tick() );

		return false;
	}
}
