<?php

namespace WordPress\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use WordPress\Filesystem\LocalFilesystem;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Middleware\CacheMiddleware;
use WordPress\HttpClient\Request;
use WordPress\HttpClient\Response;

class CacheMiddlewareTest extends TestCase {

	private $cache_dir;
	private $state;
	private $next_middleware;
	private $cache_middleware;

	protected function setUp(): void {
		// Create temporary cache directory
		$this->cache_dir = sys_get_temp_dir() . '/http_cache_test_' . uniqid();
		mkdir( $this->cache_dir, 0777, true );

		// Set up mocks
		$this->state = new MockClientState();
		$this->next_middleware = new MockMiddleware();
		$this->cache_middleware = new CacheMiddleware(
			$this->state,
			$this->next_middleware,
			[ 'cache_dir' => $this->cache_dir ]
		);
	}

	protected function tearDown(): void {
		try {
			LocalFilesystem::create($this->cache_dir)->rmdir('/', ['recursive' => true]);
		} catch ( \Exception $e ) {
			// Ignore errors on windows – CI sometimes fails to remove the topmost directory
			if(PHP_OS_FAMILY === 'Windows') {
				return;
			}
			throw $e;
		}
	}

	public function test_cache_miss_forwards_to_next_middleware(): void {
		$request = new Request( 'https://example.com/test' );
		
		$this->cache_middleware->enqueue( $request );
		
		$this->assertTrue( $this->next_middleware->was_called );
		$this->assertSame( $request, $this->next_middleware->last_request );
	}

	public function test_non_cacheable_methods_invalidate_cache(): void {
		// First, create a cached entry
		$get_request = new Request( 'https://example.com/test' );
		$this->createCachedResponse( $get_request, 'Cached content' );
		
		// Now make a POST request to the same URL
		$post_request = new Request( 'https://example.com/test', [ 'method' => 'POST' ] );
		
		$this->cache_middleware->enqueue( $post_request );
		
		$this->assertTrue( $this->next_middleware->was_called );
		
		// Verify cache files were deleted
		$cache_files = glob( $this->cache_dir . '/*.json' );
		$this->assertEmpty( $cache_files );
	}

	public function test_cache_hit_serves_from_cache(): void {
		$request = new Request( 'https://example.com/test' );
		$cached_content = 'This is cached content';
		$this->createCachedResponse( $request, $cached_content );
		
		$this->cache_middleware->enqueue( $request );
		
		// Should not call next middleware
		$this->assertFalse( $this->next_middleware->was_called );
		
		// Should start replay
		$this->assertTrue( $this->cache_middleware->await_next_event( [] ) );
		
		// Verify headers event
		$this->assertEquals( Client::EVENT_GOT_HEADERS, $this->state->event );
		$this->assertEquals( 200, $request->response->status_code );
		$content_type = $request->response->get_header( 'Content-Type' );
		$this->assertEquals( 'text/plain', $content_type );
	}

	public function test_cache_replay_body_chunks(): void {
		$request = new Request( 'https://example.com/test' );
		$cached_content = str_repeat( 'Large content chunk. ', 1000 ); // ~20KB
		$this->createCachedResponse( $request, $cached_content );
		
		$this->cache_middleware->enqueue( $request );
		
		// Headers event
		$this->assertTrue( $this->cache_middleware->await_next_event( [] ) );
		$this->assertEquals( Client::EVENT_GOT_HEADERS, $this->state->event );
		
		// Body chunks
		$received_body = '';
		while ( $this->cache_middleware->await_next_event( [] ) ) {
			if ( $this->state->event === Client::EVENT_BODY_CHUNK_AVAILABLE ) {
				$received_body .= $this->state->response_body_chunk;
			} elseif ( $this->state->event === Client::EVENT_FINISHED ) {
				break;
			}
		}
		
		$this->assertEquals( $cached_content, $received_body );
	}

	public function test_large_response_chunking(): void {
		$request = new Request( 'https://example.com/test' );
		// Create content larger than 64KB chunk size
		$large_content = str_repeat( 'X', 100 * 1024 ); // 100KB
		$this->createCachedResponse( $request, $large_content );
		
		$this->cache_middleware->enqueue( $request );
		
		// Skip headers
		$this->cache_middleware->await_next_event( [] );
		
		// Count chunks and verify size
		$chunk_count = 0;
		$total_size = 0;
		while ( $this->cache_middleware->await_next_event( [] ) ) {
			if ( $this->state->event === Client::EVENT_BODY_CHUNK_AVAILABLE ) {
				$chunk_count++;
				$chunk_size = strlen( $this->state->response_body_chunk );
				$total_size += $chunk_size;
				
				// Verify chunk size is reasonable (should be 64KB or less for final chunk)
				$this->assertLessThanOrEqual( 64 * 1024, $chunk_size );
			} elseif ( $this->state->event === Client::EVENT_FINISHED ) {
				break;
			}
		}
		
		$this->assertGreaterThan( 1, $chunk_count ); // Should have multiple chunks
		$this->assertEquals( strlen( $large_content ), $total_size );
	}

	public function test_etag_validation(): void {
		$request = new Request( 'https://example.com/test' );
		$etag = '"test-etag-123"';
		
		// Create cached response with ETag
		$meta = [
			'url' => $request->url,
			'status' => 200,
			'headers' => [ 'etag' => $etag, 'content-type' => 'text/plain' ],
			'stored_at' => time() - 7200, // 2 hours ago, expired
			'etag' => $etag,
		];
		$this->createCachedEntry( $request, 'Cached content', $meta );
		
		$this->cache_middleware->enqueue( $request );
		
		// Should add If-None-Match header
		$this->assertTrue( $this->next_middleware->was_called );
		$this->assertEquals( $etag, $this->next_middleware->last_request->headers['if-none-match'] );
	}

	public function test_last_modified_validation(): void {
		$request = new Request( 'https://example.com/test' );
		$last_modified = 'Wed, 01 Jan 2020 00:00:00 GMT';
		
		// Create cached response with Last-Modified and explicit expiry
		$meta = [
			'url' => $request->url,
			'status' => 200,
			'headers' => [ 
				'last-modified' => $last_modified, 
				'content-type' => 'text/plain',
				'cache-control' => 'max-age=3600' // Explicit expiry
			],
			'stored_at' => time() - 7200, // 2 hours ago, expired
			'last_modified' => $last_modified,
			'max_age' => 3600, // 1 hour max age (expired)
		];
		$this->createCachedEntry( $request, 'Cached content', $meta );
		
		$this->cache_middleware->enqueue( $request );
		
		// Should add If-Modified-Since header
		$this->assertTrue( $this->next_middleware->was_called );
		$this->assertEquals( $last_modified, $this->next_middleware->last_request->headers['if-modified-since'] );
	}

	public function test_304_response_serves_cached_body(): void {
		$request = new Request( 'https://example.com/test' );
		$cached_content = 'Original cached content';
		$this->createCachedResponse( $request, $cached_content );
		
		// Enqueue the request first to set up cache_key and validators
		$this->cache_middleware->enqueue( $request );
		
		// Simulate 304 response from server
		$this->state->event = Client::EVENT_GOT_HEADERS;
		$this->state->request = $request;
		$request->response = new Response( $request );
		$request->response->status_code = 304;
		
		// Process the 304 response
		$this->assertTrue( $this->cache_middleware->await_next_event( [] ) );
		
		// Should start serving cached body
		$received_body = '';
		while ( $this->cache_middleware->await_next_event( [] ) ) {
			if ( $this->state->event === Client::EVENT_BODY_CHUNK_AVAILABLE ) {
				$received_body .= $this->state->response_body_chunk;
			} elseif ( $this->state->event === Client::EVENT_FINISHED ) {
				break;
			}
		}
		
		$this->assertEquals( $cached_content, $received_body );
	}

	public function test_vary_header_different_cache_keys(): void {
		$request1 = new Request( 'https://example.com/test' );
		$request1->headers['Accept'] = 'application/json';
		$request2 = new Request( 'https://example.com/test' );
		$request2->headers['Accept'] = 'text/html';
		
		// First, simulate caching the first request
		$response1 = new Response( $request1 );
		$response1->status_code = 200;
		$response1->headers = [ 
			'vary' => 'Accept', // Use lowercase key
			'content-type' => 'application/json',
			'cache-control' => 'max-age=3600' // Make it cacheable - use lowercase key
		];
		$response1->request = $request1; // Set the request property
		
		$this->cache_middleware->enqueue( $request1 );
		
		// Set up the mock middleware to return true so handleNetwork gets called
		$this->next_middleware->should_return_true_from_await = true;
		$this->state->event = Client::EVENT_GOT_HEADERS;
		$this->state->request = $request1;
		$request1->response = $response1;
		$this->cache_middleware->await_next_event( [] ); // Process headers - this updates cache_key with Vary
		$cache_key1 = $request1->cache_key; // Get the updated cache key
		$this->finishCachingRequest( $request1, 'JSON response' );
		
		// Reset state for second request
		$this->next_middleware->reset();
		$this->state = new MockClientState();
		
		// Now test second request with different Accept header
		$this->cache_middleware->enqueue( $request2 );
		// Simulate network response for second request with Vary header
		$response2 = new Response( $request2 );
		$response2->status_code = 200;
		$response2->headers = [ 
			'vary' => 'Accept', 
			'content-type' => 'text/html',
			'cache-control' => 'max-age=3600' // Make it cacheable - use lowercase key
		];
		$response2->request = $request2; // Set the request property
		
		// Set up the mock middleware to return true so handleNetwork gets called
		$this->next_middleware->should_return_true_from_await = true;
		$this->state->event = Client::EVENT_GOT_HEADERS;
		$this->state->request = $request2;
		$request2->response = $response2;
		$this->cache_middleware->await_next_event( [] ); // Process headers - this updates cache_key with Vary
		$cache_key2 = $request2->cache_key; // Get the updated cache key
		
		$this->assertNotEquals( $cache_key1 ?? '', $cache_key2 ?? '' );
	}

	public function test_max_age_freshness(): void {
		$request = new Request( 'https://example.com/test' );
		
		// Create fresh cached response (max-age: 3600, stored now)
		$meta = [
			'url' => $request->url,
			'status' => 200,
			'headers' => [ 'Cache-Control' => 'max-age=3600', 'Content-Type' => 'text/plain' ],
			'stored_at' => time(), // Just stored
			'max_age' => 3600,
		];
		$this->createCachedEntry( $request, 'Fresh content', $meta );
		
		$this->cache_middleware->enqueue( $request );
		
		// Should serve from cache
		$this->assertFalse( $this->next_middleware->was_called );
	}

	public function test_expired_max_age(): void {
		$request = new Request( 'https://example.com/test' );
		
		// Create expired cached response
		$meta = [
			'url' => $request->url,
			'status' => 200,
			'headers' => [ 'Cache-Control' => 'max-age=3600', 'Content-Type' => 'text/plain' ],
			'stored_at' => time() - 7200, // 2 hours ago
			'max_age' => 3600, // 1 hour max age
		];
		$this->createCachedEntry( $request, 'Expired content', $meta );
		
		$this->cache_middleware->enqueue( $request );
		
		// Should not serve from cache
		$this->assertTrue( $this->next_middleware->was_called );
	}

	public function test_s_maxage_takes_precedence(): void {
		$request = new Request( 'https://example.com/test' );
		$meta = [
			'url' => $request->url,
			'status' => 200,
			'headers' => [ 'cache-control' => 's-maxage=7200, max-age=1800', 'content-type' => 'text/plain' ],
			'stored_at' => time() - 3600, // 1 hour ago
			'max_age' => 1800, // 30 minutes (would be expired)
			's_maxage' => 7200, // 2 hours (still fresh)
		];
		$this->createCachedEntry( $request, 'S-maxage content', $meta );
		
		$this->cache_middleware->enqueue( $request );
		
		// Should serve from cache (fresh due to s-maxage)
		$this->assertFalse( $this->next_middleware->was_called );
	}

	public function test_must_revalidate_with_explicit_expiry(): void {
		$request = new Request( 'https://example.com/test' );
		
		// Fresh response with must-revalidate
		$meta = [
			'url' => $request->url,
			'status' => 200,
			'headers' => [ 'Cache-Control' => 'max-age=3600, must-revalidate', 'Content-Type' => 'text/plain' ],
			'stored_at' => time() - 1800, // 30 minutes ago
			'max_age' => 3600, // 1 hour (still fresh)
		];
		$this->createCachedEntry( $request, 'Must revalidate content', $meta );
		
		$this->cache_middleware->enqueue( $request );
		
		// Should serve from cache when fresh
		$this->assertFalse( $this->next_middleware->was_called );
	}

	public function test_must_revalidate_expired_no_heuristic(): void {
		$request = new Request( 'https://example.com/test' );
		// Expired response with must-revalidate and no explicit expiry
		$meta = [
			'url' => $request->url,
			'status' => 200,
			'headers' => [ 
				'Cache-Control' => 'must-revalidate', 
				'Last-Modified' => 'Wed, 01 Jan 2020 00:00:00 GMT',
				'Content-Type' => 'text/plain' 
			],
			'stored_at' => time() - 86400, // 1 day ago
			'last_modified' => 'Wed, 01 Jan 2020 00:00:00 GMT',
			'max_age' => 0, // Explicitly expired
		];
		$this->createCachedEntry( $request, 'Must revalidate no heuristic', $meta );
		$this->cache_middleware->enqueue( $request );
		// Should not use heuristic caching with must-revalidate
		$this->assertTrue( $this->next_middleware->was_called );
	}

	public function test_heuristic_caching(): void {
		$request = new Request( 'https://example.com/test' );
		
		// Response with only Last-Modified for heuristic caching
		$last_modified_time = time() - 86400 * 10; // 10 days ago
		$meta = [
			'url' => $request->url,
			'status' => 200,
			'headers' => [ 
				'Last-Modified' => gmdate( 'D, d M Y H:i:s', $last_modified_time ) . ' GMT',
				'Content-Type' => 'text/plain' 
			],
			'stored_at' => time() - 3600, // 1 hour ago
			'last_modified' => gmdate( 'D, d M Y H:i:s', $last_modified_time ) . ' GMT',
		];
		$this->createCachedEntry( $request, 'Heuristic cache content', $meta );
		
		$this->cache_middleware->enqueue( $request );
		
		// Should serve from cache using heuristic (10% of age = ~24 hours)
		$this->assertFalse( $this->next_middleware->was_called );
	}

	public function test_network_response_caching(): void {
		$request = new Request( 'https://example.com/test' );
		
		// Set up cache key as would happen during enqueue
		[ $key, ] = $this->cache_middleware->lookup( $request );
		$request->cache_key = $key;
		
		$response = new Response( $request );
		$response->status_code = 200;
		$response->headers = [ 'cache-control' => 'max-age=3600', 'content-type' => 'text/plain' ];
		$response->request = $request; // Set the request property
		
		// Set up the mock middleware to return true so handleNetwork gets called
		$this->next_middleware->should_return_true_from_await = true;
		$this->state->event = Client::EVENT_GOT_HEADERS;
		$this->state->request = $request;
		$request->response = $response;
		$this->assertTrue( $this->cache_middleware->await_next_event( [] ) );
		
		$content = 'Network response content';
		$this->state->event = Client::EVENT_BODY_CHUNK_AVAILABLE;
		$this->state->response_body_chunk = $content;
		$this->assertTrue( $this->cache_middleware->await_next_event( [] ) );
		
		$this->state->event = Client::EVENT_FINISHED;
		$this->assertTrue( $this->cache_middleware->await_next_event( [] ) );
		
		$url_hash = sha1($request->url);
		$cache_files = glob( $this->cache_dir . '/' . $url_hash . '_*.json' );
		$this->assertNotEmpty( $cache_files );
		
		$body_files = glob( $this->cache_dir . '/' . $url_hash . '_*.body' );
		$this->assertNotEmpty( $body_files );
		
		$cached_content = file_get_contents( $body_files[0] );
		$this->assertEquals( $content, $cached_content );
	}

	public function test_non_cacheable_response_not_stored(): void {
		$request = new Request( 'https://example.com/test' );
		
		// Set up cache key as would happen during enqueue
		[ $key, ] = $this->cache_middleware->lookup( $request );
		$request->cache_key = $key;
		
		$response = new Response( $request );
		$response->status_code = 200;
		$response->headers = [ 'cache-control' => 'no-store', 'content-type' => 'text/plain' ];
		$response->request = $request; // Set the request property
		
		// Set up the mock middleware to return true so handleNetwork gets called
		$this->next_middleware->should_return_true_from_await = true;
		$this->state->event = Client::EVENT_GOT_HEADERS;
		$this->state->request = $request;
		$request->response = $response;
		$this->assertTrue( $this->cache_middleware->await_next_event( [] ) );
		
		// Simulate body chunk for non-cacheable response
		$this->state->event = Client::EVENT_BODY_CHUNK_AVAILABLE;
		$this->state->response_body_chunk = 'Non-cacheable content';
		$this->assertTrue( $this->cache_middleware->await_next_event( [] ) );
		
		// Simulate finish
		$this->state->event = Client::EVENT_FINISHED;
		$this->assertTrue( $this->cache_middleware->await_next_event( [] ) );
		
		$url_hash = sha1($request->url);
		// Check for both temp and cache files - should be empty since response is not cacheable
		$temp_files = glob( $this->cache_dir . '/' . $url_hash . '_*.tmp' );
		$cache_files = glob( $this->cache_dir . '/' . $url_hash . '_*.json' );
		$body_files = glob( $this->cache_dir . '/' . $url_hash . '_*.body' );
		
		$this->assertEmpty( $temp_files );
		$this->assertEmpty( $cache_files );
		$this->assertEmpty( $body_files );
	}

	private function createCachedResponse( Request $request, string $content, array $headers = [] ): void {
		$default_headers = [ 'content-type' => 'text/plain' ];
		$headers = array_merge( $default_headers, $headers );
		
		$meta = [
			'url' => $request->url,
			'status' => 200,
			'headers' => $headers,
			'stored_at' => time(),
			'max_age' => 3600, // 1 hour
		];
		
		$this->createCachedEntry( $request, $content, $meta );
	}

	private function createCachedEntry( Request $request, string $content, array $meta ): void {
		[ $key, ] = $this->cache_middleware->lookup( $request );
		$request->cache_key = $key;
		$url_hash = sha1($request->url);
		$meta_file = $this->cache_dir . '/' . $url_hash . '_' . $key . '.json';
		$body_file = $this->cache_dir . '/' . $url_hash . '_' . $key . '.body';
		file_put_contents( $meta_file, json_encode( $meta ) );
		file_put_contents( $body_file, $content );
	}

	private function finishCachingRequest( Request $request, string $content ): void {
		// Simulate body chunk
		$this->state->event = Client::EVENT_BODY_CHUNK_AVAILABLE;
		$this->state->response_body_chunk = $content;
		$this->cache_middleware->await_next_event( [] );
		
		// Simulate finish
		$this->state->event = Client::EVENT_FINISHED;
		$this->cache_middleware->await_next_event( [] );
	}
}

class MockClientState {
	public $event = '';
	public $request = null;
	public $response_body_chunk = '';
}

class MockMiddleware {
	public $was_called = false;
	public $last_request = null;
	public $mock_response = null;
	public $should_return_304 = false;
	public $should_return_true_from_await = false;

	public function enqueue( Request $request ) {
		$this->was_called = true;
		$this->last_request = $request;
		
		if ( $this->should_return_304 ) {
			$request->response = new Response( $request );
			$request->response->status_code = 304;
		}
	}

	public function await_next_event( $requests_ids ): bool {
		return $this->should_return_true_from_await;
	}

	public function reset(): void {
		$this->was_called = false;
		$this->last_request = null;
		$this->mock_response = null;
		$this->should_return_304 = false;
		$this->should_return_true_from_await = false;
	}
} 