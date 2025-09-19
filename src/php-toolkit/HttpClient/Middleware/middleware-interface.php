<?php

namespace WordPress\HttpClient\Middleware;

use WordPress\HttpClient\Request;

interface MiddlewareInterface {

	public function enqueue( Request $request );

	public function await_next_event( $requests_ids ): bool;
}
