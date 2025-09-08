<?php

use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\HttpServer\IncomingRequest;
use WordPress\HttpServer\Response\TcpResponseWriteStream;
use WordPress\HttpServer\TcpServer;

use function WordPress\Filesystem\pipe_stream;

require_once __DIR__ . '/../../../../vendor/autoload.php';

error_reporting( E_ALL );

$document_root = realpath( __DIR__ . '/../fixtures' );
$host          = $argv[1] ?? '127.0.0.1';
$port          = isset( $argv[2] ) ? (int) $argv[2] : 8950;
$scenario      = $argv[3] ?? 'default';

$server = new TcpServer( $host, $port );
$server->set_handler( function ( IncomingRequest $request, TcpResponseWriteStream $response ) use ( $document_root, $scenario ) {
	$path  = $request->get_parsed_url()->pathname;
	$query = $request->get_parsed_url()->searchParams;

	if($path === '/echo-method') {
		$response->send_http_code( 200 );
		$response->send_header( 'Content-Type', 'text/plain' );
		$response->append_bytes( $request->method );
		return;
	}

	switch ( $scenario ) {
		case 'echo-method':
			$response->send_http_code( 200 );
			$response->send_header( 'Content-Type', 'text/plain' );
			$response->append_bytes( $request->method );
			break;
		case 'status':
			$status = (int) basename( $path );
			$response->send_http_code( $status );
			$response->send_header( 'Content-Type', 'text/plain' );
			switch ( $status ) {
				case 200:
					$body = 'OK';
					break;
				case 204:
					$body = '';
					break;
				case 301:
				case 302:
				case 303: // Added 303 for POST to GET redirect
				case 307: // Added 307 for temporary redirect
				case 308: // Added 308 for permanent redirect
					$body = 'Redirect';
					break;
				case 400:
					$body = 'Bad Request';
					break;
				case 404:
					$body = 'Not Found';
					break;
				case 500:
					$body = 'Internal Server Error';
					break;
				default:
					$body = 'Status';
					break;
			}
			$response->append_bytes( $body );
			break;
		case 'encoding':
			$encoding = basename( $path );
			if ( $encoding === 'chunked' ) {
				$response->use_chunked_encoding();
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'chunked' );
			} elseif ( $encoding === 'gzip' ) {
				$response->send_header( 'Content-Encoding', 'gzip' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$gz = gzencode( 'gzipped' );
				$response->append_bytes( $gz );
			} elseif ( $encoding === 'deflate' ) {
				$response->send_header( 'Content-Encoding', 'deflate' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$deflate = gzcompress( 'deflated', -1, ZLIB_ENCODING_DEFLATE );
				$response->append_bytes( $deflate );
			} elseif ( $encoding === 'rot13' ) {
				$response->send_header( 'Content-Encoding', 'rot13' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( str_rot13( 'rot13' ) );
			} else {
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'plain' );
			}
			break;
		case 'redirect':
			$type = basename( $path );
			if ( $type === 'absolute' ) {
				$response->send_http_code( 302 );
				$response->send_header( 'Location', '/redirected' );
				$response->append_bytes( 'Redirect' );
			} elseif ( $type === 'relative' ) {
				$response->send_http_code( 302 );
				$response->send_header( 'Location', '/redirected' );
				$response->append_bytes( 'Redirect' );
			} elseif ( $type === 'loop' ) {
				$response->send_http_code( 302 );
				$response->send_header( 'Location', '/redirect/loop' );
				$response->append_bytes( 'Redirect' );
			} elseif ( $type === 'chain-1' ) {
				$response->send_http_code( 302 );
				$response->send_header( 'Location', '/redirect/chain-2' );
				$response->append_bytes( 'Redirect 1' );
			} elseif ( $type === 'chain-2' ) {
				$response->send_http_code( 302 );
				$response->send_header( 'Location', '/redirected-final' );
				$response->append_bytes( 'Redirect 2' );
			} elseif ( $path === '/redirected-final' ) {
				$response->send_http_code( 200 );
				$response->append_bytes( 'Final Redirected Content!' );
			} elseif ( $type === 'post-to-get' ) {
				if ( $request->method === 'POST' ) {
					$response->send_http_code( 303 ); // See Other, explicitly changes POST to GET
					$response->send_header( 'Location', '/echo-method' );
					$response->append_bytes( 'Redirecting POST to GET' );
				} else {
					$response->send_http_code( 200 );
					$response->append_bytes( 'Expected POST, got ' . $request->method );
				}
			} elseif ( $type === 'invalid-location' ) {
				$response->send_http_code( 302 );
				$response->send_header( 'Location', 'ftp://invalid-url' ); // Malformed URL
				$response->append_bytes( 'Invalid Location' );
			} elseif ( $type === 'relative-path-redirect' ) {
				$response->send_http_code( 302 );
				$response->send_header( 'Location', 'new-path/resource.html' );
				$response->append_bytes( 'Redirecting to new-path/resource.html' );
			} elseif ( $path === '/redirected' ) {
				$response->send_http_code( 200 );
				$response->append_bytes( 'redirected!' );
			} elseif ( $path === '/new-path/resource.html' ) {
				$response->send_http_code( 200 );
				$response->append_bytes( 'Arrived at /new-path/resource.html.' );
			} elseif ( $path === '/redirect/new-path/resource.html' ) {
				$response->send_http_code( 200 );
				$response->append_bytes( 'Arrived at /redirect/new-path/resource.html.' );
			} else {
				$response->send_http_code( 404 );
				$response->append_bytes( 'Not Found' );
			}
			break;
		case 'error':
			$err = basename( $path );
			if ( $err === 'broken-connection' ) {
				$response->dangerously_mark_headers_as_sent();
				$response->append_bytes( "HTTP/1.1 200 OK\r\n" );
				$response->append_bytes( "Content-Type: text/pla" );
				// Simulate broken connection by not closing properly
				exit( 1 );
			} elseif ( $err === 'invalid-response' ) {
				// Send malformed HTTP
				$response->dangerously_mark_headers_as_sent();
				$response->append_bytes( "INVALID\r\n\r\n" );
			} elseif ( $err === 'timeout' ) {
				sleep( 2 ); // Simulate timeout longer than client default
				$response->send_http_code( 200 );
				$response->append_bytes( 'timeout' );
			} elseif ( $err === 'timeout-read-body' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Content-Length', '1000' );
				$response->append_bytes( str_repeat( 'a', 100 ) ); // Send part of body
				sleep( 5 ); // Then delay
				$response->append_bytes( str_repeat( 'b', 900 ) ); // Send rest of body
			} elseif ( $err === 'unsupported-encoding' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Transfer-Encoding', 'unsupported' );
				$response->append_bytes( 'This body should not be processed.' );
			} elseif ( $err === 'incomplete-status-line' ) {
				$response->dangerously_mark_headers_as_sent();
				$response->append_bytes( "HTTP/1.1\r\n\r\n" ); // Missing status code and message
			} elseif ( $err === 'connection-refused' ) {
				// This scenario is handled by the client attempting to connect to a non-existent port
				// The server itself doesn't need to do anything here, it's about client's connection attempt.
				$response->send_http_code( 500 ); // Placeholder
				$response->append_bytes( 'This should not be reached.' );
			} elseif ( $err === 'early-eof-headers' ) {
				$response->dangerously_mark_headers_as_sent();
				$response->append_bytes( "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n" );
				exit(1); // Close connection prematurely
			} elseif ( $err === 'early-eof-body' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Content-Length', '100' );
				$response->append_bytes( str_repeat('x', 50) );
				exit(1); // Close connection prematurely
			} elseif ( $err === 'large-headers' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'X-Large-Header', str_repeat('Z', 8192) ); // 8KB header
				$response->append_bytes( 'Large headers sent.' );
			}
			else {
				$response->send_http_code( 500 );
				$response->append_bytes( 'Unknown error' );
			}
			break;
		case 'headers':
			$header = basename( $path );
			if ( $header === 'X-Test-Header' ) {
				$response->send_header( 'X-Test-Header', 'test-value' );
				$response->append_bytes( 'X-Test-Header: test-value' );
			} elseif ( $header === 'X-Long-Header' ) {
				$response->send_header( 'X-Long-Header', str_repeat( 'a', 1000 ) );
				$response->append_bytes( 'X-Long-Header: ' . str_repeat( 'a', 1000 ) );
			} elseif ( $header === 'X-Multi-Header' ) {
				$response->send_header( 'X-Multi-Header', 'value1,value2' );
				$response->append_bytes( 'X-Multi-Header: value1,value2' );
			} elseif ( $header === 'case-insensitivity' ) {
				$response->send_header( 'X-Test-Case', 'Value' );
				$response->append_bytes( 'X-Test-Case: Value' );
			} elseif ( $header === 'multiple-set-cookie' ) {
				$response->send_header( 'Set-Cookie', 'cookie1=value1' );
				$response->send_header( 'Set-Cookie', 'cookie2=value2', false ); // Append, not overwrite
				$response->append_bytes( 'Cookies sent' );
			} else {
				$response->send_header( 'X-Unknown', 'unknown' );
				$response->append_bytes( 'unknown header' );
			}
			break;
		case 'body':
			$type = basename( $path );
			if ( $type === 'empty' ) {
				$response->send_http_code( 200 );
				$response->append_bytes( '' );
			} elseif ( $type === 'small' ) {
				$response->send_http_code( 200 );
				$response->append_bytes( 'small' );
			} elseif ( $type === 'large' ) {
				$response->send_http_code( 200 );
				$response->append_bytes( str_repeat( 'x', 10000 ) );
			} elseif ( $type === 'binary' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Content-Type', 'application/octet-stream' );
				$response->append_bytes( random_bytes( 256 ) );
			} elseif ( $type === 'upload-large' ) {
				// Read entire body from request stream
				$body = '';
				$request_body_stream = $request->get_body_stream(); // Assuming get_body_stream() exists
				if ($request_body_stream) {
					while (!$request_body_stream->reached_end_of_data()) {
						$chunk_size = $request_body_stream->pull(4096);
						if ($chunk_size > 0) {
							$body .= $request_body_stream->consume($chunk_size);
						} else {
							usleep(10000); // Wait a bit if no data is immediately available
						}
					}
					$request_body_stream->close_reading();
				}
				$response->send_http_code( 200 );
				$response->append_bytes( 'Received ' . strlen( $body ) . ' bytes.' );
			} elseif ( $type === 'upload-chunked' ) {
				// Read entire body from request stream (which will be chunk-decoded by the server's HTTP parser)
				$body = '';
				$request_body_stream = $request->get_body_stream(); // Assuming get_body_stream() exists
				if ($request_body_stream) {
					while (!$request_body_stream->reached_end_of_data()) {
						$chunk_size = $request_body_stream->pull(4096);
						if ($chunk_size > 0) {
							$body .= $request_body_stream->consume($chunk_size);
						} else {
							usleep(10000); // Wait a bit if no data is immediately available
						}
					}
					$request_body_stream->close_reading();
				}
				$response->send_http_code( 200 );
				$response->append_bytes( 'Received chunked ' . strlen( $body ) . ' bytes.' );
			} else {
				$response->send_http_code( 404 );
				$response->append_bytes( 'Not Found' );
			}
			break;
		case 'stream':
			$type = basename( $path );
			if ( $type === 'slow' ) {
				$response->use_chunked_encoding();
				for ( $i = 0; $i < 5; $i ++ ) {
					$response->append_bytes( "s" );
					usleep( 600000 ); // 600ms
				}
			} elseif ( $type === 'fast' ) {
				$response->use_chunked_encoding();
				for ( $i = 0; $i < 10; $i ++ ) {
					// 32kb per chunk
					$response->append_bytes( str_repeat("f", 32 * 1024) );
				}
			} else {
				$response->send_http_code( 404 );
				$response->append_bytes( 'Not Found' );
			}
			break;
		case 'cache':
			$type = basename( $path );
			if ( $type === 'max-age' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Cache-Control', 'max-age=3600' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Cached for 1 hour' );
			} elseif ( $type === 'no-cache' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Cache-Control', 'no-cache' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Not cached' );
			} elseif ( $type === 'no-store' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Cache-Control', 'no-store' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Never stored' );
			} elseif ( $type === 'expires' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Expires', gmdate( 'D, d M Y H:i:s', time() + 3600 ) . ' GMT' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Expires in 1 hour' );
			} elseif ( $type === 'etag' ) {
				$etag = '"test-etag-123"';
				// Try both case variations
				$if_none_match = $request->get_header( 'if-none-match' ) ?: $request->get_header( 'If-None-Match' );
				

				
				if ( $if_none_match === $etag ) {
					$response->send_http_code( 304 );
					$response->send_header( 'ETag', $etag );
					// No body for 304
				} else {
					$response->send_http_code( 200 );
					$response->send_header( 'ETag', $etag );
					$response->send_header( 'Cache-Control', 'must-revalidate' );
					$response->send_header( 'Content-Type', 'text/plain' );
					$response->append_bytes( 'ETag response' );
				}
			} elseif ( $type === 'last-modified' ) {
				$last_modified = 'Wed, 01 Jan 2020 00:00:00 GMT';
				// Try both case variations
				$if_modified_since = $request->get_header( 'if-modified-since' ) ?: $request->get_header( 'If-Modified-Since' );
				if ( $if_modified_since === $last_modified ) {
					$response->send_http_code( 304 );
					$response->send_header( 'Last-Modified', $last_modified );
					// No body for 304
				} else {
					$response->send_http_code( 200 );
					$response->send_header( 'Last-Modified', $last_modified );
					$response->send_header( 'Cache-Control', 'must-revalidate' );
					$response->send_header( 'Content-Type', 'text/plain' );
					$response->append_bytes( 'Last-Modified response' );
				}
			} elseif ( $type === 'vary-accept' ) {
				$accept = $request->get_header( 'accept' );
				$response->send_http_code( 200 );
				$response->send_header( 'Vary', 'Accept' );
				$response->send_header( 'Cache-Control', 'max-age=3600' );
				$response->send_header( 'Content-Type', 'text/plain' );
				if ( $accept === 'application/json' ) {
					$response->append_bytes( '{"message": "JSON response"}' );
				} else {
					$response->append_bytes( 'Text response' );
				}
			} elseif ( $type === 'vary-user-agent' ) {
				$user_agent = $request->get_header( 'user-agent' );
				$response->send_http_code( 200 );
				$response->send_header( 'Vary', 'User-Agent' );
				$response->send_header( 'Cache-Control', 'max-age=3600' );
				$response->send_header( 'Content-Type', 'text/plain' );
				if ( strpos( $user_agent, 'Mobile' ) !== false ) {
					$response->append_bytes( 'Mobile response' );
				} else {
					$response->append_bytes( 'Desktop response' );
				}
			} elseif ( $type === 'redirect-301' ) {
				$response->send_http_code( 301 );
				$response->send_header( 'Location', '/cache/redirect-target' );
				$response->send_header( 'Cache-Control', 'max-age=3600' );
				$response->append_bytes( 'Permanent redirect' );
			} elseif ( $type === 'redirect-target' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Cache-Control', 'max-age=3600' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Redirect target content' );
			} elseif ( $type === 'heuristic' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Last-Modified', 'Wed, 01 Jan 2020 00:00:00 GMT' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Heuristic caching' );
			} elseif ( $type === 'post-invalidate' ) {
				if ( $request->method === 'POST' ) {
					$response->send_http_code( 200 );
					$response->send_header( 'Content-Type', 'text/plain' );
					$response->append_bytes( 'POST response - cache invalidated' );
				} else {
					$response->send_http_code( 200 );
					$response->send_header( 'Cache-Control', 'max-age=3600' );
					$response->send_header( 'Content-Type', 'text/plain' );
					$response->append_bytes( 'GET response - cacheable' );
				}
			} elseif ( $type === 's-maxage' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Cache-Control', 's-maxage=7200, max-age=3600' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Shared cache for 2 hours, private cache for 1 hour' );
			} elseif ( $type === 'must-revalidate' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Cache-Control', 'max-age=3600, must-revalidate' );
				$response->send_header( 'ETag', '"must-revalidate-123"' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Must revalidate when stale' );
			} elseif ( $type === 'large-body' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Cache-Control', 'max-age=3600' );
				$response->send_header( 'Content-Type', 'text/plain' );
				// Create a response larger than 64KB to test chunking
				$response->append_bytes( str_repeat( 'Large response body content. ', 5000 ) ); // ~150KB
			} elseif ( $type === 'vary-multiple' ) {
				$accept = $request->get_header( 'accept' );
				$encoding = $request->get_header( 'accept-encoding' );
				$response->send_http_code( 200 );
				$response->send_header( 'Vary', 'Accept, Accept-Encoding' );
				$response->send_header( 'Cache-Control', 'max-age=3600' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( "Accept: {$accept}, Accept-Encoding: {$encoding}" );
			} elseif ( $type === 'private' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Cache-Control', 'private, max-age=3600' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Private cache only' );
			} elseif ( $type === 'both-validators' ) {
				$etag = '"both-validators-123"';
				$last_modified = 'Wed, 01 Jan 2020 00:00:00 GMT';
				$if_none_match = $request->get_header( 'if-none-match' ) ?: $request->get_header( 'If-None-Match' );
				$if_modified_since = $request->get_header( 'if-modified-since' ) ?: $request->get_header( 'If-Modified-Since' );
				
				if ( $if_none_match === $etag || $if_modified_since === $last_modified ) {
					$response->send_http_code( 304 );
					$response->send_header( 'ETag', $etag );
					$response->send_header( 'Last-Modified', $last_modified );
				} else {
					$response->send_http_code( 200 );
					$response->send_header( 'ETag', $etag );
					$response->send_header( 'Last-Modified', $last_modified );
					$response->send_header( 'Cache-Control', 'max-age=3600' );
					$response->send_header( 'Content-Type', 'text/plain' );
					$response->append_bytes( 'Response with both ETag and Last-Modified' );
				}
			} elseif ( $type === 'expired' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Expires', gmdate( 'D, d M Y H:i:s', time() - 3600 ) . ' GMT' ); // Expired 1 hour ago
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Already expired response' );
			} elseif ( $type === 'zero-max-age' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Cache-Control', 'max-age=0' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Zero max-age response' );
			} elseif ( $type === 'no-explicit-cache' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Last-Modified', 'Wed, 01 Jan 2020 00:00:00 GMT' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'No explicit cache headers, only Last-Modified for heuristic caching' );
			} elseif ( $type === 'counter' ) {
				// Simple counter to test cache hit/miss
				$counter_file = sys_get_temp_dir() . '/http_cache_test_counter.txt';
				if ( ! file_exists( $counter_file ) ) {
					file_put_contents( $counter_file, '0' );
				}
				$count = (int) file_get_contents( $counter_file );
				$count++;
				file_put_contents( $counter_file, (string) $count );
				
				$response->send_http_code( 200 );
				$response->send_header( 'Cache-Control', 'max-age=3600' );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( "Request count: {$count}" );
			} elseif ( $type === 'reset-counter' ) {
				// Reset counter for testing
				$counter_file = sys_get_temp_dir() . '/http_cache_test_counter.txt';
				file_put_contents( $counter_file, '0' );
				$response->send_http_code( 200 );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Counter reset' );
			} else {
				$response->send_http_code( 404 );
				$response->append_bytes( 'Cache endpoint not found' );
			}
			break;
		case 'edge-cases':
			$type = basename( $path );
			if ( $type === 'no-body-204' ) {
				$response->send_http_code( 204 );
				// No body
			} elseif ( $type === 'no-body-304' ) {
				$response->send_http_code( 304 );
				// No body
			} elseif ( $type === 'content-length-zero' ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Content-Length', '0' );
				// No body
			} elseif ( $type === 'head-request' ) {
				if ( $request->method === 'HEAD' ) {
					$response->send_http_code( 200 );
					$response->send_header( 'Content-Length', '100' ); // Indicate body length
					// No body sent for HEAD
				} else {
					$response->send_http_code( 400 );
					$response->append_bytes( 'Bad Request, expected HEAD' );
				}
			} elseif ( $type === 'range-request' ) {
				$range_header = $request->get_header( 'range' ); // Assuming get_header exists on IncomingRequest
				if ( $range_header ) {
					// Simple range handling for demonstration
					$response->send_http_code( 206 ); // Partial Content
					$response->send_header( 'Content-Range', 'bytes 0-9/100' );
					$response->append_bytes( '0123456789' );
				} else {
					$response->send_http_code( 200 );
					$response->append_bytes( str_repeat( 'x', 100 ) );
				}
			} else {
				$response->send_http_code( 404 );
				$response->append_bytes( 'Not Found' );
			}
			break;
		default:
			// Serve static files or a default response
			$file = $document_root . $path;
			if ( file_exists( $file ) && is_file( $file ) ) {
				$response->send_http_code( 200 );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->send_header( 'Content-Length', (string) filesize( $file ) );
				$stream = FileReadStream::from_path( $file );
				while ( !$stream->reached_end_of_data() ) {
					$n = $stream->pull( 4096 );
					$response->append_bytes( $stream->consume( $n ) );
				}
				$stream->close_reading();
				$response->close_writing();
			} else {
				$response->send_http_code( 404 );
				$response->send_header( 'Content-Type', 'text/plain' );
				$response->append_bytes( 'Not Found' );
			}
			break;
	}
} );

$server->serve( function ( $host, $port ) {
	echo "Server started on http://{$host}:{$port}\n";
} );
