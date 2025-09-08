<?php

namespace WordPress\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\HttpError;
use WordPress\HttpClient\Request;

if ( ! class_exists( 'WordPress\ByteStream\ReadStream\StringReadStream' ) ) {
    interface ReadStream {
        public function pull( int $length ) : int;
        public function consume( int $length ) : string;
        public function reached_end_of_data() : bool;
        public function close_reading() : void;
        public function length() : ?int;
    }
    class StringReadStream implements ReadStream {
        protected $data;
        protected $offset = 0;
        protected $length = null;

        public function __construct( string $data, ?int $length = null ) {
            $this->data = $data;
            $this->length = $length ?? strlen($data);
        }

        public function pull( int $length ) : int {
            $remaining = $this->length - $this->offset;
            return min( $length, $remaining );
        }

        public function consume( int $length ) : string {
            $chunk = substr( $this->data, $this->offset, $length );
            $this->offset += strlen( $chunk );
            return $chunk;
        }

        public function reached_end_of_data() : bool {
            return $this->offset >= $this->length;
        }

        public function close_reading() : void {
            // No-op for string stream
        }

        public function length() : ?int {
            return $this->length;
        }
    }
}

abstract class ClientTestBase extends TestCase {

	use WithServerTrait;

    /**
     * Create the client instance to be tested.
     * Must be implemented by concrete test classes.
     */
    abstract protected function createClient( array $options = [] ): Client;

    /**
     * Get client-specific error message mappings.
     * Override in concrete test classes to provide client-specific error messages.
     */
    protected function getClientSpecificErrorMessages(): array {
        return [];
    }

    /** one-shot TCP server that writes $rawResponse and dies */
    protected function withRawResponse(string $raw, callable $cb, int $port = 8970): void {
        $tmp  = tempnam(sys_get_temp_dir(), 'srv').'.php';
        $blob = var_export(base64_encode($raw), true);
        file_put_contents($tmp,
        <<<PHP
        <?php
        \$srv = stream_socket_server("tcp://127.0.0.1:$port", \$e, \$s);
        \$c   = @stream_socket_accept(\$srv, 10);
        if (\$c) { fwrite(\$c, base64_decode($blob)); fclose(\$c); }
        fclose(\$srv);
PHP
        );
        $p = new Process(['php', $tmp]); $p->start();
        for ($i = 0; $i < 20 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(50000);
        }
        try   { $cb("http://127.0.0.1:$port"); }
        finally { $p->stop(0); @unlink($tmp); }
    }

    /** server that accepts and closes immediately – provokes fwrite() errors */
    protected function withDroppingServer(callable $cb, int $port = 8971): void {
        $tmp = tempnam(sys_get_temp_dir(), 'srv').'.php';
        file_put_contents($tmp,
        <<<PHP
        <?php
        \$srv = stream_socket_server("tcp://127.0.0.1:$port", \$e, \$s);
        \$c   = @stream_socket_accept(\$srv, 10);
        if (\$c) fclose(\$c);
        fclose(\$srv);
PHP
        );
        $p = new Process(['php', $tmp]); $p->start();
        for ($i = 0; $i < 20 && !@fsockopen('127.0.0.1', $port); $i++) usleep(50000);
        try   { $cb("http://127.0.0.1:$port"); }
        finally { $p->stop(0); @unlink($tmp); }
    }

    /**
     * Helper to consume the entire response body for a request using the event loop.
     */
    protected function consume_entire_body( $client, Request $request ) {
        if($request->state === Request::STATE_CREATED) {
            $client->enqueue( $request );
        }
        $body = '';
        while ( $client->await_next_event([ 'requests' => [ $request ] ] ) ) {
            switch ( $client->get_event() ) {
                case Client::EVENT_BODY_CHUNK_AVAILABLE:
                    $chunk = $client->get_response_body_chunk();
                    if ( $chunk !== false ) { // Ensure chunk is not false
                        $body .= $chunk;
                    }
                    break;
                case Client::EVENT_FAILED:
                    throw $request->error;
                case Client::EVENT_FINISHED:
                    return $body;
            }
        }
        // If the loop finishes without EVENT_FINISHED, it means timeout or no more events
        return $body;
    }

    /**
     * @dataProvider httpMethodProvider
     */
    public function test_http_methods( $method ) {
        $this->withServer( function ( $url ) use ( $method ) {
            $client  = $this->createClient();
            $request = new Request( "$url/echo-method", [ 'method' => $method ] );
            $body    = $this->consume_entire_body( $client, $request );
            $this->assertEquals( $method, $body );
        }, 'echo-method' );
    }

    public function httpMethodProvider() {
        return [
            [ 'GET' ],
            [ 'POST' ],
            [ 'PUT' ],
            [ 'DELETE' ],
            [ 'PATCH' ],
            [ 'OPTIONS' ],
            [ 'HEAD' ],
        ];
    }

    /**
     * @dataProvider statusCodeProvider
     */
    public function test_status_codes( $status, $expectedBody ) {
        $this->withServer( function ( $url ) use ( $status, $expectedBody ) {
            $client  = $this->createClient();
            $request = new Request( "$url/status/$status" );
            $body    = $this->consume_entire_body( $client, $request );
            $this->assertEquals( $status, $request->response->status_code );

            if ( $expectedBody !== null ) {
                $this->assertEquals( $expectedBody, $body );
            }
        }, 'status' );
    }

    public function statusCodeProvider() {
        return [
            [ 200, 'OK' ],
            [ 204, '' ], // 204 No Content should have empty body
            [ 301, 'Redirect' ],
            [ 302, 'Redirect' ],
            [ 303, 'Redirect' ], // Added for POST to GET redirect
            [ 307, 'Redirect' ], // Temporary Redirect
            [ 308, 'Redirect' ], // Permanent Redirect
            [ 400, 'Bad Request' ],
            [ 404, 'Not Found' ],
            [ 500, 'Internal Server Error' ],
        ];
    }

    /**
     * @dataProvider encodingProvider
     */
    public function test_encodings( $encoding, $expectedBody ) {
        $this->withServer( function ( $url ) use ( $encoding, $expectedBody ) {
            $client  = $this->createClient();
            $request = new Request( "$url/encoding/$encoding" );
            $body    = $this->consume_entire_body( $client, $request );
            $this->assertEquals( $expectedBody, $body );
        }, 'encoding' );
    }

    public function encodingProvider() {
        return [
            [ 'identity', 'plain' ],
            [ 'chunked', 'chunked' ],
            [ 'gzip', 'gzipped' ],
            // [ 'deflate', 'deflated' ],
        ];
    }

    /**
     * Test for connection refused.
     */
    public function test_connection_refused() {
        // Use a port that is highly unlikely to be in use
        $port = 9999;
        $host = '127.0.0.1';

        $client  = $this->createClient( [ 'timeout_ms' => 1000 ] ); // Short timeout for connection attempt
        $request = new Request( "http://{$host}:{$port}/" );
        $client->enqueue( $request );

        $error_occurred = false;
        while ( $client->await_next_event( [ 'requests' => [ $request ] ] ) ) {
            switch ( $client->get_event() ) {
                case Client::EVENT_FAILED:
                    $this->assertNotNull( $request->error );
                    if(
                        false === strpos($request->error->message, 'Request timed out') &&
                        false === strpos($request->error->message, 'Failed to connect') &&
                        false === strpos($request->error->message, 'Connection timed out') &&
                        false === strpos($request->error->message, 'Failed to write request bytes') &&
                        false === strpos($request->error->message, 'Connection closed while reading response headers')
                    ) {
                        $this->fail('Unexpected error: ' . $request->error->message);
                    }
                    $error_occurred = true;
                    break 2;
            }
        }
        $this->assertTrue( $error_occurred, 'Connection refused should have resulted in an error.' );
    }


    /**
     * @dataProvider headerProvider
     */
    public function test_headers( $headerName, $headerValue ) {
        $this->withServer( function ( $url ) use ( $headerName, $headerValue ) {
            $client  = $this->createClient();
            $request = new Request( "$url/headers/$headerName" );
            $body    = $this->consume_entire_body( $client, $request );
            $this->assertStringContainsString( $headerValue, $body );
        }, 'headers' );
    }

    public function headerProvider() {
        return [
            [ 'X-Test-Header', 'X-Test-Header: test-value' ],
            [ 'X-Long-Header', 'X-Long-Header: ' . str_repeat( 'a', 1000 ) ],
            [ 'X-Multi-Header', 'X-Multi-Header: value1,value2' ],
            [ 'case-insensitivity', 'X-Test-Case: Value' ], // Test receiving case-insensitive header
        ];
    }

    /**
     * Test that multiple Set-Cookie headers are parsed correctly (as an array).
     */
    public function test_multiple_set_cookie_headers() {
        $this->withServer( function ( $url ) {
            $client  = $this->createClient();
            $request = new Request( "$url/headers/multiple-set-cookie" );
            $client->enqueue( $request );

            $headers_received = false;
            while ( $client->await_next_event( [ 'requests' => [ $request ] ] ) ) {
                switch ( $client->get_event() ) {
                    case Client::EVENT_GOT_HEADERS:
                        $response = $request->response;
                        $this->assertNotNull( $response );
                        $this->assertArrayHasKey( 'set-cookie', $response->headers );
                        $this->assertEquals( 'cookie2=value2', $response->headers['set-cookie'] );
                        $headers_received = true;
                        break;
                    case Client::EVENT_FINISHED:
                        break 2;
                }
            }
            $this->assertTrue( $headers_received, 'Set-Cookie headers should have been received.' );
        }, 'headers' );
    }

    /**
     * Test receiving a very large header.
     */
    public function test_large_response_header() {
        $this->withServer( function ( $url ) {
            $client = $this->createClient();
            $request = new Request( "$url/error/large-headers" ); // Using error scenario for large header
            $body = $this->consume_entire_body( $client, $request );

            $this->assertEquals( 200, $request->response->status_code );
            $this->assertStringContainsString( 'Large headers sent.', $body );
            $this->assertArrayHasKey( 'x-large-header', $request->response->headers );
            $this->assertEquals( 8192, strlen($request->response->headers['x-large-header']) );
        }, 'error' ); // Using 'error' scenario to simulate a server sending large headers
    }


    /**
     * @dataProvider bodyProvider
     */
    public function test_body_types( $type, $expectedLength ) {
        $this->withServer( function ( $url ) use ( $type, $expectedLength ) {
            $client  = $this->createClient();
            $request = new Request( "$url/body/$type" );
            $body    = $this->consume_entire_body( $client, $request );
            $this->assertEquals( $expectedLength, strlen( $body ) );
        }, 'body' );
    }

    public function bodyProvider() {
        return [
            [ 'empty', 0 ],
            [ 'small', 5 ],
            [ 'large', 10000 ],
            [ 'binary', 256 ],
        ];
    }

    /**
     * @dataProvider streamingProvider
     */
    public function test_streaming( $type, $cmp, $expectedChunks ) {
        $this->withServer( function ( $url ) use ( $type, $cmp, $expectedChunks ) {
            $client  = $this->createClient();
            $request = new Request( "$url/stream/$type" );
            $client->enqueue( $request );
            $chunks = [];
            while ( $client->await_next_event( [ 'requests' => [ $request ] ] ) ) {
                switch ( $client->get_event() ) {
                    case Client::EVENT_BODY_CHUNK_AVAILABLE:
                        $chunk = $client->get_response_body_chunk();
                        if ( $chunk !== false ) {
                            $chunks[] = $chunk;
                        }
                        break;
                    case Client::EVENT_FAILED:
                        throw $request->error;
                    case Client::EVENT_FINISHED:
                        break 2;
                }
            }
			if($cmp === '>=') {
				$this->assertGreaterThanOrEqual( $expectedChunks, count($chunks) );
			} else if($cmp === '>') {
				// We only want to know there is more than one chunk, not how many.
				$this->assertGreaterThan( 1, count($chunks) );
			} else {
				throw new \Exception('Invalid comparison operator: ' . $cmp);
			}
        }, 'stream' );
    }

    public function streamingProvider() {
        return [
			// This should take multiple polls and return at least 5 chunks.
			// (each chunk is 32kb and curl sometimes waits for 64kb to become available)
            [ 'slow', '>=', 5 ],
			// This should return more than 1 chunk. Maybe 3, maybe 10, it depends
			// on the client.
            [ 'fast', '>=', 1 ],
        ];
    }

    /**
     * Test redirect chaining.
     */
    public function test_redirect_chain() {
        $this->withServer( function ( $url ) {
            $client  = $this->createClient();
            $request = new Request( "$url/redirect/chain-1" );
            $body1    = $this->consume_entire_body( $client, $request );
            $this->assertEquals( 'Redirect 1', $body1 );
            $this->assertEquals( 302, $request->response->status_code ); // Original request should have 302

            $body2 = $this->consume_entire_body( $client, $request->redirected_to );
            $this->assertEquals( 'Redirect 2', $body2 );
            $this->assertEquals( 302, $request->redirected_to->response->status_code ); // First redirect should have 302

            $body3 = $this->consume_entire_body( $client, $request->redirected_to->redirected_to );
            $this->assertEquals( 'Final Redirected Content!', $body3 );
            $this->assertEquals( 200, $request->redirected_to->redirected_to->response->status_code );
        }, 'redirect' );
    }

    /**
     * Test redirect loop with max_redirects limit.
     */
    public function test_redirect_loop() {
        $this->withServer( function ( $url ) {
            $client  = $this->createClient( [ 'max_redirects' => 2, 'timeout_ms' => 20000 ] ); // Set a low redirect limit
            $request = new Request( "$url/redirect/loop" );
            $client->enqueue( $request );

			$requests = [ $request ];
            $error_occurred = false;
            while ( $client->await_next_event( [ 'requests' => $requests ] ) ) {
                switch ( $client->get_event() ) {
					case Client::EVENT_GOT_HEADERS:
						$requests[] = $request->latest_redirect();
						break;
                    case Client::EVENT_FAILED:
                        $this->assertNotNull( $request->latest_redirect()->error );
                        $this->assertStringContainsString( 'Too many redirects', $request->latest_redirect()->error->message );
                        $error_occurred = true;
                        break 2;
                }
            }
            $this->assertTrue( $error_occurred, 'Redirect loop should have resulted in an error.' );
        }, 'redirect' );
    }

    /**
     * Test POST request redirected to GET (303 See Other).
     */
    public function test_post_to_get_redirect() {
        $this->withServer( function ( $url ) {
            $client  = $this->createClient();
            $request = new Request( "$url/redirect/post-to-get", [ 'method' => 'POST', 'body_stream' => new StringReadStream('test body') ] );
            $original_body    = $this->consume_entire_body( $client, $request );
            $this->assertEquals( 'POST', $request->method );
            $this->assertEquals( 'Redirecting POST to GET', $original_body );

            $redirected = $request->redirected_to;
            $this->consume_entire_body( $client, $redirected );
            $this->assertEquals( 'GET', $redirected->method ); // The final request method should be GET
            $this->assertEquals( 200, $redirected->response->status_code );
        }, 'redirect' );
    }

    /**
     * Test invalid redirect URL.
     */
    public function test_invalid_redirect_url() {
        $this->withServer( function ( $url ) {
            $client  = $this->createClient();
            $request = new Request( "$url/redirect/invalid-location" );
            $client->enqueue( $request );

            $error_occurred = false;
			$requests = [ $request ];
            while ( $client->await_next_event( [ 'requests' => $requests ] ) ) {
                switch ( $client->get_event() ) {
					case Client::EVENT_GOT_HEADERS:
						$requests[] = $request->latest_redirect();
						break;
                    case Client::EVENT_FAILED:
                        $this->assertNotNull( $request->latest_redirect()->error );
                        $this->assertStringContainsString( 'Invalid URL', $request->latest_redirect()->error->message );
                        $error_occurred = true;
                        break 2;
                }
            }
            $this->assertTrue( $error_occurred, 'Invalid redirect URL should have resulted in an error.' );
        }, 'redirect' );
    }

    /**
     * Test no body for 204 No Content status.
     */
    public function test_no_body_204() {
        $this->withServer( function ( $url ) {
            $client  = $this->createClient();
            $request = new Request( "$url/edge-cases/no-body-204" );
            $body    = $this->consume_entire_body( $client, $request );
            $this->assertEquals( 204, $request->response->status_code );
            $this->assertEmpty( $body );
            $this->assertNull( $request->response->total_bytes ); // Content-Length usually absent for 204
        }, 'edge-cases' );
    }

    /**
     * Test no body for 304 Not Modified status.
     */
    public function test_no_body_304() {
        $this->withServer( function ( $url ) {
            $client  = $this->createClient();
            $request = new Request( "$url/edge-cases/no-body-304" );
            $body    = $this->consume_entire_body( $client, $request );
            $this->assertEquals( 304, $request->response->status_code );
            $this->assertEmpty( $body );
            $this->assertNull( $request->response->total_bytes ); // Content-Length usually absent for 304
        }, 'edge-cases' );
    }

    /**
     * Test response with Content-Length: 0.
     */
    public function test_content_length_zero() {
        $this->withServer( function ( $url ) {
            $client  = $this->createClient();
            $request = new Request( "$url/edge-cases/content-length-zero" );
            $body    = $this->consume_entire_body( $client, $request );
            $this->assertEquals( 200, $request->response->status_code );
            $this->assertEquals( 0, $request->response->total_bytes );
            $this->assertEmpty( $body );
        }, 'edge-cases' );
    }

    /**
     * Test Range request.
     */
    public function test_range_request() {
        $this->withServer( function ( $url ) {
            $client  = $this->createClient();
            $request = new Request( "$url/edge-cases/range-request", [ 'headers' => [ 'Range' => 'bytes=0-9' ] ] );
            $body    = $this->consume_entire_body( $client, $request );
            $this->assertEquals( 206, $request->response->status_code );
            $this->assertEquals( '0123456789', $body );
            $this->assertEquals( 'bytes 0-9/100', $request->response->get_header( 'content-range' ) );
        }, 'edge-cases' );
    }

    /* ---------- tiny glue ---------- */

    protected function expectClientError(Request $req, ?float $timeout_ms = null, array $opts = []): void {
        if ($timeout_ms !== null) $opts['timeout_ms'] = $timeout_ms;

        // Apply client-specific error message mappings
        $clientSpecificMappings = $this->getClientSpecificErrorMessages();
        if (isset($opts['message']) && is_array($opts['message'])) {
            $possibleMessages = $opts['message'];
            foreach ($clientSpecificMappings as $testMethod => $mapping) {
                $currentMethod = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
                if ($testMethod === $currentMethod && isset($mapping['message'])) {
                    $possibleMessages = array_merge($possibleMessages, (array) $mapping['message']);
                }
            }
            $opts['message'] = array_unique($possibleMessages);
        } elseif (isset($opts['message'])) {
            $currentMethod = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
            if (isset($clientSpecificMappings[$currentMethod]['message'])) {
                $opts['message'] = array_merge(
                    (array) $opts['message'],
                    (array) $clientSpecificMappings[$currentMethod]['message']
                );
            }
        }

        $client = $this->createClient($opts);
        try {
            $body = $this->consume_entire_body($client, $req);
            $this->fail('Expected error not thrown. First 100 response bytes: ' . substr($body, 0, 100). ". Has error: " . $req->error);
        } catch (HttpError $e) {
			$this->assertStringContainsAny($e->message, $opts['message'] ?? 'Error');
        }
    }

	public function assertStringContainsAny(string $haystack, $needles, ?string $message = null): void {
		if(!is_array($needles)) {
			$needles = [$needles];
		}
		foreach ($needles as $needle) {
			if (strpos($haystack, $needle) !== false) {
				$this->assertTrue(true);
				return;
			}
		}
		$this->fail($message ?? "None of the needles found in haystack: " . $haystack);
	}
}
