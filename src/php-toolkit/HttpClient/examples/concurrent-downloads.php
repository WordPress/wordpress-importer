<?php

use WordPress\HttpClient\Client;
use WordPress\HttpClient\ClientEvent;
use WordPress\HttpClient\Request;

require __DIR__ . '/vendor/autoload.php';

$requests = array(
	new Request( 'https://wordpress.org/latest.zip' ),
	new Request( 'https://raw.githubusercontent.com/wpaccessibility/a11y-theme-unit-test/master/a11y-theme-unit-test-data.xml' ),
);

$client = new Client();
$client->enqueue( $requests );

while ( $client->await_next_event() ) {
	$request = $client->get_request();
	echo 'Request ' . $request->id . ': ' . $client->get_event() . ' ';
	switch ( $client->get_event() ) {
		case Client::EVENT_BODY_CHUNK_AVAILABLE:
			echo $request->response->received_bytes . '/' . $request->response->total_bytes . ' bytes received';
			file_put_contents( 'downloads/' . $request->id, $client->get_response_body_chunk(), FILE_APPEND );
			break;
		case Client::EVENT_GOT_HEADERS:
		case Client::EVENT_FINISHED:
			break;
		case Client::EVENT_FAILED:
			echo '– ❌ Failed request to ' . $request->url . ' – ' . $request->error;
			break;
	}
	echo "\n";
}
