<?php

namespace WordPress\HttpClient\Middleware;

use WordPress\DataLiberation\URL\WPURL;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\HttpError;
use WordPress\HttpClient\Request;

class RedirectionMiddleware implements MiddlewareInterface {

	/**
	 * @var MiddlewareInterface
	 */
	private $next_middleware;

	/**
	 * The maximum number of redirects to follow for a single request.
	 *
	 * This prevents infinite redirect loops and provides a degree of control over the client's behavior.
	 * Setting it too high might lead to unexpected navigation paths.
	 *
	 * @var int
	 */
	private $max_redirects;

	/**
	 * @var Client
	 */
	private $client;

	/**
	 * @var ClientState
	 */
	private $state;

	public function __construct( $client_state, $next_middleware, $options = array() ) {
		$this->next_middleware = $next_middleware;
		$this->max_redirects   = $options['max_redirects'] ?? 5;
		$this->state           = $client_state;
		$this->client          = $options['client'];
	}

	public function enqueue( Request $request ) {
		return $this->next_middleware->enqueue( $request );
	}

	public function await_next_event( $requests_ids ): bool {
		if ( ! $this->next_middleware->await_next_event( $requests_ids ) ) {
			return false;
		}
		switch ( $this->state->event ) {
			case Client::EVENT_GOT_HEADERS:
				$this->handle_redirect( $this->state->request );
				break;
		}
		return true;
	}

	/**
	 * @param  Request $request  The request to handle.
	 */
	protected function handle_redirect( Request $request ) {
		$response = $request->response;
		if ( ! $response ) {
			return;
		}
		$code = $response->status_code;
		if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ) ) ) {
			return;
		}

		$location = $response->get_header( 'location' );
		if ( null === $location ) {
			return;
		}

		$redirects_so_far = 0;
		$cause            = $request;
		while ( $cause->redirected_from ) {
			++$redirects_so_far;
			$cause = $cause->redirected_from;
		}

		if ( $redirects_so_far >= $this->max_redirects ) {
			$this->state->set_request_error( $request, new HttpError( 'Too many redirects' ) );
			return;
		}

		$redirect_url = $location;
		$parsed       = WPURL::parse( $redirect_url, $request->url );
		if ( false === $parsed ) {
			$this->state->set_request_error( $request, new HttpError( sprintf( 'Invalid redirect URL: %s', $redirect_url ) ) );
			return;
		}
		$redirect_url = $parsed->toString();

		$this->client->enqueue(
			new Request(
				$redirect_url,
				array(
					// Redirects are always GET requests.
					'method'          => 'GET',
					'redirected_from' => $request,
				)
			)
		);
	}
}
