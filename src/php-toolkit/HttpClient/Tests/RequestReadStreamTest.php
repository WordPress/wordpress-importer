<?php

namespace WordPress\HttpClient\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use WordPress\ByteStream\ByteStreamException;
use WordPress\HttpClient\ByteStream\RequestReadStream;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;
use WordPress\HttpClient\Response;

trait WithTestServer {
	protected function withServer( callable $callback, $scenario = 'default', $host = '127.0.0.1', $port = 8950 ) {
		$serverRoot = __DIR__ . '/test-server';
		$server     = new Process( [
			'php',
			"$serverRoot/run.php",
			$host,
			$port,
			$scenario,
		], $serverRoot );
		$server->start();
		try {
			$attempts = 0;
			while ( $server->isRunning() ) {
				$output = $server->getIncrementalOutput();
				if ( strncmp( $output, 'Server started on http://', strlen( 'Server started on http://' ) ) === 0 ) {
					break;
				}
				usleep( 40000 );
				if ( ++ $attempts > 20 ) {
					$this->fail( 'Server did not start' );
				}
			}
			$callback( "http://{$host}:{$port}" );
		} finally {
			$server->stop( 0 );
		}
	}
}

class RequestReadStreamTest extends TestCase {
	use WithTestServer;

	private $fixture = '/preface-to-pygmalion.txt';

	public function testConstructWithString() {
		$this->withServer(function($url) {
			$test_url = $url . $this->fixture;
			$stream = new RequestReadStream( $test_url );
			$this->assertInstanceOf( RequestReadStream::class, $stream );
			$this->assertInstanceOf( Request::class, $stream->get_request() );
			$this->assertEquals( $test_url, $stream->get_request()->url );
		});
	}

	public function testConstructWithRequest() {
		$this->withServer(function($url) {
			$test_url = $url . $this->fixture;
			$request = new Request( $test_url );
			$stream  = new RequestReadStream( $request );
			$this->assertInstanceOf( RequestReadStream::class, $stream );
			$this->assertSame( $request, $stream->get_request() );
		});
	}

	public function testConstructWithCustomClient() {
		$this->withServer(function($url) {
			$test_url = $url . $this->fixture;
			$client = new Client();
			$stream = new RequestReadStream( $test_url, [ 'client' => $client ] );
			$this->assertInstanceOf( RequestReadStream::class, $stream );
			$response = $stream->await_response();
			$this->assertInstanceOf( Response::class, $response );
		});
	}

	public function testGetResponse() {
		$this->withServer(function($url) {
			$test_url = $url . $this->fixture;
			$stream   = new RequestReadStream( $test_url );
			$response = $stream->await_response();
			$this->assertInstanceOf( Response::class, $response );
			$this->assertEquals( 200, $response->status_code );
			$this->assertStringContainsString( 'text/plain', $response->get_header( 'Content-Type' ) );
		});
	}

	public function testAwaitResponse() {
		$this->withServer(function($url) {
			$test_url = $url . $this->fixture;
			$stream   = new RequestReadStream( $test_url );
			$response = $stream->await_response();
			$this->assertInstanceOf( Response::class, $response );
			$this->assertEquals( 200, $response->status_code );
		});
	}

	public function testLength() {
		$this->withServer(function($url) {
			$test_url = $url . $this->fixture;
			$stream = new RequestReadStream( $test_url );
			$stream->await_response();
			$length = $stream->length();
			$this->assertIsInt( $length );
			$this->assertGreaterThan( 0, $length );
		});
	}

	public function testReadingContent() {
		$this->withServer(function($url) {
			$test_url = $url . $this->fixture;
			$stream = new RequestReadStream( $test_url );
			$stream->await_response();

			$nb_bytes_pulled = $stream->pull( 1024 );
			$this->assertGreaterThan( 0, $nb_bytes_pulled );

			$data = $stream->consume( $nb_bytes_pulled );
			$this->assertNotEmpty( $data );
			$this->assertStringContainsString( 'PREFACE TO PYGMALION', $data );

			// Pull more data if available
			if ( ! $stream->reached_end_of_data() ) {
				$nb_bytes_pulled = $stream->pull( 1024 );
				$this->assertIsInt( $nb_bytes_pulled );
				if ($nb_bytes_pulled > 0) {
					$this->assertGreaterThan( 0, $nb_bytes_pulled );
				}
			}

			// Test reading to the end
			$stream      = new RequestReadStream( $test_url );
			$stream->await_response();
			$all_content = $stream->consume_all();
			$this->assertNotEmpty( $all_content );
			$this->assertStringContainsString( 'Professor of Phonetics', $all_content );
		});
	}

	public function testRedirects() {
		$this->withServer(function($url) {
			$test_url = $url . '/redirect/relative-path-redirect';
			$stream = new RequestReadStream( $test_url );
			$response = $stream->await_response();
			
			// Should follow redirects and get the final response
			$this->assertInstanceOf( Response::class, $response );
			$this->assertEquals( 200, $response->status_code );
			
			// Should be able to read the final content
			$content = $stream->consume_all();
			$this->assertStringContainsString( 'Arrived at /redirect/new-path/resource.html.', $content );
			
			// Check that the request was redirected
			$request = $stream->get_request();
			$this->assertNotNull( $request->redirected_to );
			$this->assertStringContainsString( '/redirect/new-path/resource.html', $request->redirected_to->url );
		}, 'redirect');
	}

	public function testTell() {
		$this->withServer(function($url) {
			$test_url = $url . $this->fixture;
			$stream = new RequestReadStream( $test_url );
			$stream->await_response();
			$length = $stream->length();
			$seek = ($length && $length > 100) ? 100 : 0;
			$stream->pull( 10 );
			$stream->seek( $seek );
			$this->assertEquals( $seek, $stream->tell() );
		});
	}

	public function testReachedEndOfData() {
		$this->withServer(function($url) {
			$test_url = $url . $this->fixture;
			$stream = new RequestReadStream( $test_url );
			$stream->await_response();
			$this->assertFalse( $stream->reached_end_of_data() );
			while ( ! $stream->reached_end_of_data() ) {
				$nb = $stream->pull( 4096 );
				if ($nb === 0) break;
				$stream->consume( $nb );
			}
			$this->assertTrue( $stream->reached_end_of_data() );
		});
	}

	public function testCloseReading() {
		$this->withServer(function($url) {
			$test_url = $url . $this->fixture;
			$stream = new RequestReadStream( $test_url );
			$stream->await_response();
			$stream->pull( 10 );
			while ( ! $stream->reached_end_of_data() ) {
				$nb = $stream->pull( 4096 );
				if ($nb === 0) break;
				$stream->consume( $nb );
			}
			$stream->close_reading();
			$this->expectException( ByteStreamException::class );
			$stream->pull( 10 );
		});
	}
}
