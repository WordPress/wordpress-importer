<?php

namespace WordPress\HttpClient\ByteStream;

use WordPress\ByteStream\ByteStreamException;
use WordPress\ByteStream\ReadStream\BaseByteReadStream;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\HttpClientException;
use WordPress\HttpClient\Request;
use WordPress\HttpClient\Response;

/**
 * Streams bytes from a remote file.
 */
class RequestReadStream extends BaseByteReadStream {

	/**
	 * @var Client
	 */
	private $client;
	/**
	 * @var Request
	 */
	private $request;
	/**
	 * @var Response
	 */
	private $response;
	/**
	 * @var bool
	 */
	private $is_enqueued = false;
	/**
	 * @var int
	 */
	private $remote_file_length;
	/**
	 * @var Tracker
	 */
	private $progress_tracker;

	public function __construct( $request, $options = array() ) {
		if ( is_string( $request ) ) {
			$request = new Request( $request );
		}
		$this->client  = $options['client'] ?? new Client();
		$this->request = $request;
		if ( isset( $options['max_lookbehind_bytes'] ) ) {
			$this->max_lookbehind_bytes = $options['max_lookbehind_bytes'];
		}
		if ( isset( $options['progress_tracker'] ) ) {
			$this->progress_tracker = $options['progress_tracker'];
		}
		if ( isset( $options['eagerly_enqueue'] ) ) {
			$this->ensure_is_enqueued();
		}
	}

	public function get_request(): Request {
		return $this->request;
	}

	public function json() {
		return json_decode( $this->consume_all(), true );
	}

	private function ensure_is_enqueued() {
		if ( ! $this->is_enqueued ) {
			$this->client->enqueue( $this->request );
			$this->is_enqueued = true;
		}
	}

	protected function seek_outside_of_buffer( int $target_offset ): void {
		if ( $target_offset > $this->tell() ) {
			$pulled = $this->pull_exactly( $target_offset - $this->tell() );
			$this->consume( $pulled );
		} else {
			throw new ByteStreamException(
				'RequestReadStream cannot seek() backwards to offset ' . $target_offset . ' outside of the in-memory data buffer. ' .
				'You can either increase the buffer size or implement a custom SeekableRequestReadStream.'
			);
		}
	}

	protected function internal_pull( $max_bytes = 8096 ): string {
		return $this->pull_until_event(
			array(
				'max_bytes' => $max_bytes,
				'event'     => Client::EVENT_BODY_CHUNK_AVAILABLE,
			)
		);
	}

	private function pull_until_event( $options = array() ) {
		$stop_at_event = $options['event'] ?? Client::EVENT_BODY_CHUNK_AVAILABLE;
		$this->ensure_is_enqueued();

		while ( $this->client->await_next_event(
			array(
				'requests' => array( $this->request->latest_redirect() ),
			)
		) ) {
			$request = $this->client->get_request();
			if ( $request->error ) {
				throw new HttpClientException( sprintf( 'HTTP request failed: %s. Method=%s, URL=%s', $request->error->message, $request->method, $request->url ) );
			}
			$response = $request->response;
			if ( ! $response ) {
				continue;
			}
			if ( $request->redirected_to ) {
				continue;
			}
			switch ( $this->client->get_event() ) {
				case Client::EVENT_GOT_HEADERS:
					$this->response = $response;
					$content_length = $response->get_header( 'Content-Length' );
					if ( null !== $content_length ) {
						/**
						 * Best-effort attempt to guess the content-length of the response.
						 *
						 * Web servers often respond with a combination of Content-Length
						 * and Content-Encoding. For example, a 16kb text file may be compressed
						 * to 4kb with gzip and served with a Content-Encoding of `gzip` and a
						 * Content-Length of 4KB.
						 *
						 * If we just use that value, we'd truncate a 16KB body stream with at a
						 * Content-Length of 4KB.
						 *
						 * To correct that behavior, we're discarding the Content-Length header when
						 * it's used alongside a compressed response stream.
						 */
						if ( ! $response->get_header( 'Content-Encoding' ) ) {
							/**
							 * Set the content-length based on the header, but make sure it stays null
							 * when the Content-Length header is not set.
							 *
							 * Important: Don't set the content-length to 0 if the header is missing! This
							 * would tell the streaming machinery there's no body to consume.
							 */
							$this->remote_file_length = (int) $content_length;
						}
					}
					if ( Client::EVENT_GOT_HEADERS === $stop_at_event ) {
						return true;
					}
					break;
				case Client::EVENT_BODY_CHUNK_AVAILABLE:
					if ( Client::EVENT_BODY_CHUNK_AVAILABLE === $stop_at_event ) {
						$body_chunk = $this->client->get_response_body_chunk();

						if ( $this->progress_tracker ) {
							$bytes_downloaded = $this->bytes_already_forgotten + strlen( $this->buffer ) + strlen( $body_chunk );
							// Arbitrarily assume 15MB if no length is provided.
							$length = $this->remote_file_length ? $this->remote_file_length : 15 * 1024 * 1024;
							$this->progress_tracker->set( $bytes_downloaded / $length * 100 );
						}

						return $body_chunk;
					}
					break;
				case Client::EVENT_FINISHED:
					/**
					 * If the server did not provide a Content-Length header,
					 * backfill the file length with the number of downloaded
					 * bytes.
					 */
					if ( null === $this->remote_file_length ) {
						$this->remote_file_length = $this->bytes_already_forgotten + strlen( $this->buffer );
					}

					return '';
				case Client::EVENT_FAILED:
					// TODO: Think through error handling. Errors are expected when working with.
					// the network. Should we auto retry? Make it easy for the caller to retry?
					// Something else?
					throw new ByteStreamException( 'HTTP request failed: ' . $this->client->get_request()->error );
			}
		}

		return '';
	}

	public function length(): ?int {
		return $this->remote_file_length;
	}

	public function await_response() {
		if ( ! $this->response ) {
			$this->pull_until_event(
				array(
					'event' => Client::EVENT_GOT_HEADERS,
				)
			);
		}
		if ( ! $this->response ) {
			throw new ByteStreamException( 'HTTP request failed – never received a response' );
		}

		return $this->response;
	}

	protected function internal_reached_end_of_data(): bool {
		return (
			Request::STATE_FINISHED === $this->request->latest_redirect()->state &&
			! $this->client->has_pending_event( $this->request, Client::EVENT_BODY_CHUNK_AVAILABLE ) &&
			! $this->client->has_pending_event( $this->request, Client::EVENT_FINISHED ) &&
			strlen( $this->buffer ) === $this->offset_in_current_buffer
		);
	}

	protected function internal_close_reading(): void {
		$latest_redirect = $this->request->latest_redirect();
		if (
			$latest_redirect &&
			Request::STATE_FINISHED !== $latest_redirect->state &&
			Request::STATE_FAILED !== $latest_redirect->state
		) {
			throw new ByteStreamException( 'Cancelling the request is not implemented yet' );
		}
	}
}
