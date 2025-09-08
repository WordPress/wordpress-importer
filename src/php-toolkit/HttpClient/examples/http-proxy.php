<?php
/**
 * HTTP Proxy implemented using HttpClient\Client
 *
 * This could be a replacement for the curl-based PHPProxy shipped
 * in https://github.com/WordPress/wordpress-playground/pull/1546.
 */

use WordPress\HttpClient\Client;
use WordPress\HttpClient\ClientEvent;
use WordPress\HttpClient\Request;

require __DIR__ . '/vendor/autoload.php';

function get_target_url( $server_data = null ) {
	if ( null === $server_data ) {
		$server_data = $_SERVER;
	}
	$request_uri = $server_data['REQUEST_URI'];
	$target_url  = $request_uri;

	// Remove the current script name from the beginning of $targetUrl.
	if ( 0 === strpos( $target_url, $server_data['SCRIPT_NAME'] ) ) {
		$target_url = substr( $target_url, strlen( $server_data['SCRIPT_NAME'] ) );
	}

	// Remove the leading slash.
	if ( '/' === $target_url[0] || '?' === $target_url[0] ) {
		$target_url = substr( $target_url, 1 );
	}

	return $target_url;
}

$target_url = get_target_url();
$host       = parse_url( $target_url, PHP_URL_HOST );
$requests   = array(
	new Request(
		$target_url,
		array(
			'method'      => $_SERVER['REQUEST_METHOD'],
			'headers'     => array_merge(
				getallheaders(),
				array(
					'Accept-Encoding' => 'gzip, deflate',
					'Host' => $host,
				)
			),
			'body_stream' => 'POST' === $_SERVER['REQUEST_METHOD'] ? fopen( 'php://input', 'r' ) : null,
		)
	),
);

$client = new Client();
$client->enqueue( $requests );

$headers_sent = false;
while ( $client->await_next_event() ) {
	$request = $client->get_request();
	switch ( $client->get_event() ) {
		case Client::EVENT_GOT_HEADERS:
			http_response_code( $request->response->status_code );
			foreach ( $request->response->headers as $name => $value ) {
				if (
					'transfer-encoding' === $name ||
					'set-cookie' === $name ||
					'content-encoding' === $name
				) {
					continue;
				}
				header( "$name: $value" );
			}
			$headers_sent = true;
			break;
		case Client::EVENT_BODY_CHUNK_AVAILABLE:
			echo $client->get_response_body_chunk();
			break;
		case Client::EVENT_FAILED:
			if ( ! $headers_sent ) {
				http_response_code( 500 );
				echo 'Failed request to ' . $request->url . ' – ' . $request->error;
			}
			break;
		case Client::EVENT_FINISHED:
			break;
	}
	echo "\n";
}
