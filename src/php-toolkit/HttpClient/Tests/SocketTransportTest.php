<?php

namespace WordPress\HttpClient\Tests;

use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

require_once __DIR__ . '/ClientTestBase.php';

class SocketTransportTest extends ClientTestBase {

    public function test_unsupported_encoding() {
        $this->withServer(function (string $base) {
            $request = new Request( "$base/encoding/rot13" );
            $this->expectClientError($request, 300, [
                'message' => 'Unsupported transfer encoding received from the server: rot13'
            ]);
        }, 'encoding');
    }

    /**
     * Test HEAD request.
     */
    public function test_cutoff_head_request() {
        $this->withServer( function ( $url ) {
            $client  = $this->createClient();
            $request = new Request( "$url/edge-cases/head-request", [ 'method' => 'HEAD' ] );
            $body    = $this->consume_entire_body( $client, $request );
            $this->assertEquals( 200, $request->response->status_code );
            $this->assertEquals( 100, $request->response->total_bytes ); // Content-Length should be parsed
            $this->assertEmpty( $body ); // Body should be empty for HEAD
        }, 'edge-cases' );
    }
	public function test_invalid_scheme() {
        $this->expectClientError(new Request('gopher://x'), 300, [
            'message' => 'only HTTP and HTTPS URLs are supported:'
        ]);
    }

    public function test_dns_failure() {
        $this->expectClientError(new Request('http://nope.' . uniqid() . '/'), 300, [
            'message' => ['unable to open a stream to http://nope.', 'Request timed out', 'Invalid URL']
        ]);
    }

    public function test_ssl_handshake_failure() {
        $this->withServer(function (string $base) {
            $url = str_replace('http://', 'https://', $base).'/body/small';
            $this->expectClientError(new Request($url), 250, [
                'message' => ['Request timed out', 'Failed to enable crypto']
            ]);
        }, 'body');
    }

    public function test_write_failure() {
        $this->withDroppingServer(function (string $base) {
            $req        = new Request("$base/submit", [
                'body_stream' => new StringReadStream(str_repeat('A', 262144))
            ]);
            $req->method = 'POST';
            $this->expectClientError($req, null, [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Broken pipe', 'Request timed out']
            ]);
        });
    }

    public function test_malformed_status_line() {
        $this->withRawResponse("HTP/1.1 200 OK\r\n\r\n", function (string $base) {
            $this->expectClientError(new Request("$base/"), null, [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Request timed out']
            ]);
        });
    }

    public function test_malformed_headers() {
        $this->withRawResponse("HTTP/1.1 200 OK\r\nBadHeader\r\n\r\n", function (string $base) {
            $this->expectClientError(new Request("$base/"), null, [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Request timed out']
            ]);
        });
    }

    public function test_eof_mid_headers() {
        $this->withRawResponse("HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n", function (string $base) {
            $this->expectClientError(new Request("$base/"), null, [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Request timed out']
            ]);
        });
    }

    public function test_invalid_chunk_size() {
        $body = "Z\r\nHELLO\r\n0\r\n\r\n";
        $this->withRawResponse("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n$body", function (string $base) {
            $this->expectClientError(new Request("$base/"), null, [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Request timed out']
            ]);
        });
    }

    public function test_missing_last_chunk() {
        $body = "5\r\nHELLO\r\n";           // no terminating 0-chunk
        $this->withRawResponse("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n$body", function (string $base) {
            $this->expectClientError(new Request("$base/"), 300, [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Request timed out']
            ]);
        });
    }

    public function test_corrupted_gzip() {
        $raw = "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: 4\r\n\r\nBAD!";
        $this->withRawResponse($raw, function (string $base) {
            $this->expectClientError(new Request("$base/"), null, [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Request timed out']
            ]);
        });
    }

    protected function createClient( array $options = [] ): Client {
        return new Client( array_merge( $options, [ 'transport' => 'socket' ] ) );
    }

    /**
     * @dataProvider errorProvider
     */
    public function test_errors( $scenario, $expectedErrorSubstring ) {
		
        $this->withServer( function ( $url ) use ( $scenario, $expectedErrorSubstring ) {
			if(!is_array($expectedErrorSubstring)) {
				$expectedErrorSubstring = [$expectedErrorSubstring];
			}
            $client  = $this->createClient( [ 'timeout_ms' => 1000 ] ); // Increased timeout for timeout tests
            $request = new Request( "$url/error/$scenario" );
            $client->enqueue( $request );

            $error_occurred = false;
            while ( $client->await_next_event( [ 'requests' => [ $request ] ] ) ) {
                switch ( $client->get_event() ) {
                    case Client::EVENT_FAILED:
                        $error_occurred = true;
                        $this->assertNotNull( $request->error );
                        $this->assertStringContainsAny( $request->error->message, $expectedErrorSubstring, 'Request should have errored for scenario: ' . $scenario );
                        break 2; // Break out of switch and while
                }
            }
            $this->assertTrue( $error_occurred, 'Request should have errored for scenario: ' . $scenario );
        }, 'error' );
    }

	public function errorProvider() {
		return [
			'Broken Connection' => [ 'broken-connection', ['Connection closed while reading response headers.', 'Request timed out'] ],
			'Invalid Response' => [ 'invalid-response', 'Malformed HTTP headers received from the server.' ],
			'Unsupported Encoding' => [ 'unsupported-encoding', 'Unsupported transfer encoding received from the server: unsupported' ],
			'Incomplete Status Line' => [ 'incomplete-status-line', 'Malformed HTTP headers received from the server.' ],
			'Early EOF Headers' => [ 'early-eof-headers', ['Connection closed while reading response headers.', 'Request timed out' ]],
			'Timeout' => [ 'timeout', 'Request timed out' ],
			'Timeout Read Body' => [ 'timeout-read-body', 'Request timed out' ],
		];
	}

    protected function getClientSpecificErrorMessages(): array {
        return [
            'test_dns_failure' => [
                'message' => ['unable to open a stream to http://nope.', 'Request timed out']
            ],
            'test_ssl_handshake_failure' => [
                'message' => ['Request timed out', 'Failed to enable crypto']
            ],
            'test_write_failure' => [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Broken pipe', 'Request timed out']
            ],
            'test_malformed_status_line' => [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Request timed out']
            ],
            'test_malformed_headers' => [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Request timed out']
            ],
            'test_eof_mid_headers' => [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Request timed out']
            ],
            'test_invalid_chunk_size' => [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Request timed out']
            ],
            'test_missing_last_chunk' => [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Request timed out']
            ],
            'test_corrupted_gzip' => [
                'message' => ['Failed to write request bytes', 'Connection closed while reading response headers', 'Request timed out']
            ],
        ];
    }
}
