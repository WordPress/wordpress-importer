<?php

namespace WordPress\HttpClient\Tests;

use WordPress\HttpClient\Client;
use WordPress\HttpClient\HttpError;
use WordPress\HttpClient\Request;

require_once __DIR__ . '/ClientTestBase.php';

class CurlTransportTest extends ClientTestBase {

    public function test_unsupported_encoding() {
        $this->withServer(function (string $base) {
            $request = new Request( "$base/encoding/rot13" );
            $this->expectClientError($request, 300, [
                'message' => 'cURL error 61: Unrecognized content encoding type'
            ]);
        }, 'encoding');
    }

    public function test_invalid_scheme() {
        $this->expectClientError(new Request('gopher://x'), 300, [
            'message' => 'only HTTP and HTTPS URLs are supported:'
        ]);
    }

    public function test_dns_failure()                  {
        $this->expectClientError(new Request('http://nope.' . uniqid() . '/'), 300, [
            'message' => ['unable to open a stream to http://nope.', 'Request timed out', 'cURL error', 'Invalid URL']
        ]);
    }

    public function test_ssl_handshake_failure() {
        $this->withServer(function (string $base) {
            $url = str_replace('http://', 'https://', $base).'/body/small';
            $this->expectClientError(new Request($url), 250, [
                'message' => 'cURL error'
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
                'message' => 'cURL error'
            ]);
        });
    }

    public function test_malformed_status_line() {
        $this->withRawResponse("HTP/1.1 200 OK\r\n\r\n", function (string $base) {
            $this->expectClientError(new Request("$base/"), null, [
                'message' => 'cURL error'
            ]);
        });
    }

    public function test_malformed_headers() {
        $this->withRawResponse("HTTP/1.1 200 OK\r\nBadHeader\r\n\r\n", function (string $base) {
            $this->expectClientError(new Request("$base/"), null, [
                'message' => 'cURL error'
            ]);
        });
    }

    public function test_eof_mid_headers() {
        $this->withRawResponse("HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n", function (string $base) {
            $this->expectClientError(new Request("$base/"), null, [
                'message' => 'cURL error'
            ]);
        });
    }

    public function test_invalid_chunk_size() {
        $body = "Z\r\nHELLO\r\n0\r\n\r\n";
        $this->withRawResponse("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n$body", function (string $base) {
            $this->expectClientError(new Request("$base/"), null, [
                'message' => 'cURL error'
            ]);
        });
    }

    public function test_missing_last_chunk() {
        $body = "5\r\nHELLO\r\n";           // no terminating 0-chunk
        $this->withRawResponse("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n$body", function (string $base) {
            $this->expectClientError(new Request("$base/"), 300, [
                'message' => 'cURL error'
            ]);
        });
    }

    public function test_corrupted_gzip() {
        $raw = "HTTP/1.1 200 OK\r\nContent-Encoding: gzip\r\nContent-Length: 4\r\n\r\nBAD!";
        $this->withRawResponse($raw, function (string $base) {
            $this->expectClientError(new Request("$base/"), null, [
                'message' => 'cURL error'
            ]);
        });
    }

    /**
     * Test HEAD request.
     */
    public function test_cutoff_head_request() {
		$this->expectException(HttpError::class);
		$this->expectExceptionMessage('100 bytes');
        $this->withServer( function ( $url ) {
            $client  = $this->createClient();
            $request = new Request( "$url/edge-cases/head-request", [ 'method' => 'HEAD' ] );
            $body    = $this->consume_entire_body( $client, $request );
            $this->assertEquals( 200, $request->response->status_code );
            $this->assertEquals( 100, $request->response->total_bytes ); // Content-Length should be parsed
            $this->assertEmpty( $body ); // Body should be empty for HEAD
        }, 'edge-cases' );
    }

    protected function createClient( array $options = [] ): Client {
        return new Client( array_merge( $options, [ 'transport' => 'curl' ] ) );
    }

    /**
     * @dataProvider errorProvider
     */
    public function test_errors( $scenario, $expectedErrorSubstring ) {
        $this->withServer( function ( $url ) use ( $scenario, $expectedErrorSubstring ) {
            $request = new Request( "$url/error/$scenario" );
			$this->expectClientError($request, 300, [
				'message' => $expectedErrorSubstring
			]);
        }, 'error' );
    }

    public function errorProvider() {
        return [
            'Broken Connection' => [ 'broken-connection', ['Connection closed while reading response headers.', 'cURL error', 'Request timed out' ]],
            'Invalid Response' => [ 'invalid-response', 'cURL error 1: Received HTTP/0.9 when not allowed' ],
            'Timeout' => [ 'timeout', 'cURL error' ],
            'Timeout Read Body' => [ 'timeout-read-body', 'cURL error' ],

			// cURL ignores unsupported transfer encodings
            // 'Unsupported Transfer Encoding' => [ 'unsupported-encoding', 'Unsupported transfer encoding received from the server: unsupported' ],

            'Incomplete Status Line' => [ 'incomplete-status-line', 'cURL error 1: Unsupported HTTP' ],
            'Early EOF Headers' => [ 'early-eof-headers', ['Connection closed while reading response headers.', 'cURL error', 'Request timed out' ]],
        ];
    }

    /**
     * Test Arrived at /new-path/resource.html.
     */
    public function test_relative_path_redirect() {
        $this->withServer( function ( $url ) {
            $client  = $this->createClient();
            $request = new Request( "$url/redirect/relative-path-redirect" );

            $body = $this->consume_entire_body( $client, $request );
            $this->assertEquals( 'Redirecting to new-path/resource.html', $body );
            $this->assertEquals( 302, $request->response->status_code );
            $this->assertStringContainsString( '/redirect/new-path/resource.html', $request->redirected_to->url );

            $redirected_body = $this->consume_entire_body( $client, $request->redirected_to );
            $this->assertEquals( 'Arrived at /redirect/new-path/resource.html.', $redirected_body );
            $this->assertEquals( 200, $request->redirected_to->response->status_code );
        }, 'redirect' );
    }

    protected function getClientSpecificErrorMessages(): array {
        return [
            'test_dns_failure' => [
                'message' => ['Could not resolve host', 'Couldn\'t resolve host', 'Request timed out', 'cURL error']
            ],
            'test_ssl_handshake_failure' => [
                'message' => ['SSL handshake failed', 'SSL connect error', 'Request timed out', 'cURL error']
            ],
            'test_write_failure' => [
                'message' => ['Send failure', 'Failed sending data', 'Broken pipe', 'Request timed out', 'cURL error']
            ],
            'test_malformed_status_line' => [
                'message' => ['Malformed HTTP response', 'Invalid response', 'Request timed out', 'cURL error']
            ],
            'test_malformed_headers' => [
                'message' => ['Malformed HTTP response', 'Invalid response', 'Request timed out', 'cURL error']
            ],
            'test_eof_mid_headers' => [
                'message' => ['Transfer closed with outstanding read data remaining', 'Request timed out', 'cURL error']
            ],
            'test_invalid_chunk_size' => [
                'message' => ['Chunked-encoded data was malformed', 'Request timed out', 'cURL error']
            ],
            'test_missing_last_chunk' => [
                'message' => ['Chunked-encoded data was malformed', 'Request timed out', 'cURL error']
            ],
            'test_corrupted_gzip' => [
                'message' => ['Error in the HTTP2 framing layer', 'Content encoding error', 'Request timed out', 'cURL error']
            ],
        ];
    }
}
