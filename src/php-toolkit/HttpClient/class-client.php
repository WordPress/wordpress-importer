<?php

namespace WordPress\HttpClient;

use WordPress\DataLiberation\URL\WPURL;
use WordPress\HttpClient\ByteStream\RequestReadStream;
use WordPress\HttpClient\Middleware\CacheMiddleware;
use WordPress\HttpClient\Middleware\HttpMiddleware;
use WordPress\HttpClient\Middleware\RedirectionMiddleware;
use WordPress\HttpClient\Transport\CurlTransport;
use WordPress\HttpClient\Transport\SocketTransport;

class Client {

	const EVENT_GOT_HEADERS          = 'EVENT_GOT_HEADERS';
	const EVENT_BODY_CHUNK_AVAILABLE = 'EVENT_BODY_CHUNK_AVAILABLE';
	const EVENT_FAILED               = 'EVENT_FAILED';
	const EVENT_FINISHED             = 'EVENT_FINISHED';

	/**
	 * All the HTTP requests ever enqueued with this Client.
	 *
	 * Each Request may have a different state, and this Client will manage them
	 * asynchronously, moving them through the various states as the network
	 * operations progress.
	 *
	 * @since Next Release
	 * @var MiddlewareInterface
	 */
	private $middleware;

	/**
	 * @var ClientState
	 */
	private $state;

	public function __construct( $options = array() ) {
		$this->state = new ClientState( $options );
		if ( empty( $options['transport'] ) || 'auto' === $options['transport'] ) {
			$options['transport'] = extension_loaded( 'curl' ) ? 'curl' : 'sockets';
		}

		switch ( $options['transport'] ) {
			case 'curl':
				$transport = new CurlTransport( $this->state );
				break;
			case 'socket':
			case 'sockets':
				$transport = new SocketTransport( $this->state );
				break;
			default:
				throw new HttpClientException( sprintf( 'Invalid transport: %s', esc_html( $options['transport'] ) ) );
		}

		$middleware = new HttpMiddleware( $this->state, array( 'transport' => $transport ) );
		if ( isset( $options['cache_dir'] ) ) {
			$middleware = new CacheMiddleware(
				$this->state,
				$middleware,
				array(
					'cache_dir' => $options['cache_dir'],
				)
			);
		}

		$this->middleware = new RedirectionMiddleware(
			$this->state,
			$middleware,
			array(
				'client'        => $this,
				'max_redirects' => 5,
			)
		);
	}

	/**
	 * Returns a RemoteFileReader that streams the response body of the
	 * given request.
	 *
	 * @param  Request $request  The request to stream.
	 * @param  array   $options  Options for the request.
	 *
	 * @return RequestReadStream
	 */
	public function fetch( $request, array $options = array() ) {
		if ( is_string( $request ) ) {
			$request = new Request( $request );
		}
		return new RequestReadStream(
			$request,
			array_merge(
				array( 'client' => $this ),
				is_array( $options ) ? $options : iterator_to_array( $options )
			)
		);
	}

	/**
	 * Returns an array of RemoteFileReader instances that stream the response bodies
	 * of the given requests.
	 *
	 * @param  Request[] $requests  The requests to stream.
	 * @param  array     $options   Options for the requests.
	 *
	 * @return RequestReadStream[]
	 */
	public function fetch_many( array $requests, array $options = array() ) {
		$streams = array();

		foreach ( $requests as $request ) {
			$streams[] = $this->fetch( $request, $options );
		}

		return $streams;
	}

	/**
	 * Enqueues one or multiple HTTP requests for asynchronous processing.
	 * It does not open the network sockets, only adds the Request objects to
	 * an internal queue. Network transmission is delayed until one of the returned
	 * streams is read from.
	 *
	 * @param  Request[]|Request|string|string[] $requests  The HTTP request(s) to enqueue.
	 */
	public function enqueue( $requests ) {
		if ( ! is_array( $requests ) ) {
			$requests = array( $requests );
		}

		foreach ( $requests as $request ) {
			if ( is_string( $request ) ) {
				$request = new Request( $request );
			}
			if ( array_key_exists( $request->id, $this->state->connections ) ) {
				throw new HttpClientException( sprintf( 'Request %s is already enqueued.', esc_html( $request->id ) ) );
			}

			if ( Request::STATE_CREATED !== $request->state ) {
				throw new HttpClientException( sprintf( 'Request %s is not in the created state.', esc_html( $request->id ) ) );
			}

			$this->middleware->enqueue( $request );

			// @TODO: Debug why https://wpthemetestdata.files.wordpress.com/2008/06/dsc20050727_091048_222.jpg is not getting parsed
			// $parsed = WPURL::parse( $request->url );
			// if ( false === $parsed ) {
			//  $this->state->set_request_error( $request, new HttpError( sprintf( 'Invalid URL: %s', $request->url ) ) );
			//  continue;
			// }
			// if ( 'http:' !== $parsed->protocol && 'https:' !== $parsed->protocol ) {
			//  $this->state->set_request_error(
			//      $request,
			//      new HttpError( sprintf( 'Invalid URL – only HTTP and HTTPS URLs are supported: %s', $parsed->toString() ) )
			//  );
			//  continue;
			// }
		}
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
	 * $client->enqueue( [$request] );
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
	 * @param array $query Query parameters for filtering events.
	 *
	 * @return bool
	 */
	public function await_next_event( array $query = array() ) {
		$requests_ids = array();
		if ( empty( $query['requests'] ) ) {
			$requests_ids = array_keys( $this->state->events );
		} else {
			$requests_ids = array_map(
				function ( $request ) {
					return $request->id;
				},
				$query['requests']
			);
		}
		return $this->middleware->await_next_event( $requests_ids );
	}

	public function has_pending_event( Request $request, string $event_type ) {
		return $this->state->has_pending_event( $request, $event_type );
	}

	/**
	 * Returns the next event found by await_next_event().
	 *
	 * @return string|bool The next event, or false if no event is set.
	 */
	public function get_event() {
		if ( null === $this->state->event ) {
			return false;
		}

		return $this->state->event;
	}

	/**
	 * Returns the request associated with the last event found
	 * by await_next_event().
	 *
	 * @return Request
	 */
	public function get_request() {
		if ( null === $this->state->request ) {
			return false;
		}

		return $this->state->request;
	}

	public function get_response() {
		return $this->get_request()->response;
	}

	/**
	 * Returns the response body chunk associated with the EVENT_BODY_CHUNK_AVAILABLE
	 * event found by await_next_event().
	 *
	 * @return string|false
	 */
	public function get_response_body_chunk() {
		if ( null === $this->state->response_body_chunk ) {
			return false;
		}

		return $this->state->response_body_chunk;
	}

	public function get_active_requests( $states = null ) {
		return $this->state->get_active_requests( $states );
	}
}
