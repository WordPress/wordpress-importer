<?php

namespace WordPress\DataLiberation\Importer;

use Exception;
use WordPress\Filesystem\Filesystem;
use WordPress\HttpClient\Client;
use WordPress\HttpClient\Request;

use function WordPress\Filesystem\wp_join_unix_paths;

class AttachmentDownloader {
	private $client;
	private $fps = array();
	private $output_root;
	private $output_paths = array();
	/**
	 * @var Filesystem
	 */
	private $source_from_filesystem;

	private $pending_events = array();
	private $enqueued_url;
	private $progress = array();

	public function __construct( $output_root, $options = array() ) {
		$this->client                 = new Client();
		$this->output_root            = $output_root;
		$this->source_from_filesystem = $options['source_from_filesystem'] ?? null;
	}

	public function get_progress() {
		return $this->progress;
	}

	/**
	 * Whether any downloads are still in progress.
	 *
	 * Note that zero active requests does not mean all work is done.
	 * Even if all the response bytes are received, we still need to process
	 * them and emit the final success/failure events.
	 *
	 * @return bool
	 */
	public function has_pending_requests() {
		return count( $this->client->get_active_requests() ) > 0 || count( $this->pending_events ) > 0 || count( $this->progress ) > 0;
	}

	public function get_client() {
		return $this->client;
	}

	public function enqueue_if_not_exists( $url, $output_relative_path ) {
		$this->enqueued_url = $url;

		$output_path = wp_join_unix_paths( $this->output_root, $output_relative_path );
		if ( file_exists( $output_path ) ) {
			$this->pending_events[] = new AttachmentDownloaderEvent(
				$this->enqueued_url,
				AttachmentDownloaderEvent::ALREADY_EXISTS
			);

			return true;
		}
		if ( file_exists( $output_path . '.partial' ) ) {
			$this->pending_events[] = new AttachmentDownloaderEvent(
				$this->enqueued_url,
				AttachmentDownloaderEvent::IN_PROGRESS
			);

			return true;
		}

		$output_dir = dirname( $output_path );
		if ( ! file_exists( $output_dir ) ) {
			// @TODO: think through the chmod of the created directory.
			mkdir( $output_dir, 0777, true );
		}

		$protocol = parse_url( $url, PHP_URL_SCHEME );
		if ( null === $protocol ) {
			return false;
		}

		switch ( $protocol ) {
			case 'file':
				if ( ! $this->source_from_filesystem ) {
					_doing_it_wrong(
						__METHOD__,
						'Cannot process file:// URLs without a source filesystem instance. Use the source_from_filesystem option to pass in a filesystem instance to WP_Attachment_Downloader.',
						'1.0'
					);

					return false;
				}
				$source_path = parse_url( $url, PHP_URL_PATH );
				if ( false === $source_path ) {
					return false;
				}

				// Just copy the file over.
				// @TODO: think through the chmod of the created file.
				$stream = null;
				try {
					$stream = $this->source_from_filesystem->open_read_stream( $source_path );
					$fp     = fopen( $output_path, 'wb' );
					while ( ! $stream->reached_end_of_data() ) {
						$pulled = $stream->pull( 65536 );
						$chunk  = $stream->consume( $pulled );
						fwrite( $fp, $chunk );
					}
					fclose( $fp );

					$this->pending_events[] = new AttachmentDownloaderEvent(
						$this->enqueued_url,
						AttachmentDownloaderEvent::SUCCESS
					);
				} catch ( Exception $e ) {
					$this->pending_events[] = new AttachmentDownloaderEvent(
						$this->enqueued_url,
						AttachmentDownloaderEvent::FAILURE,
						'copy_failed'
					);
				} finally {
					if ( $stream ) {
						$stream->close_reading();
					}
				}

				return true;
			case 'http':
			case 'https':
				// Create a placeholder file to indicate that the download is in progress.
				touch( $output_path . '.partial' );
				$request                               = new Request( $url );
				$this->output_paths[ $request->id ]    = $output_path;
				$this->progress[ $this->enqueued_url ] = array(
					'received' => null,
					'total'    => null,
				);
				$this->client->enqueue( $request );

				return true;
		}

		return false;
	}

	public function get_enqueued_url() {
		return $this->enqueued_url;
	}

	public function queue_full() {
		return count( $this->client->get_active_requests() ) >= 10;
	}

	/**
	 * Returns and clears all pending events.
	 *
	 * @return AttachmentDownloaderEvent[]
	 */
	public function get_events() {
		$events               = $this->pending_events;
		$this->pending_events = array();
		return $events;
	}

	public function poll() {
		while ( $this->client->await_next_event() ) {
			$event   = $this->client->get_event();
			$request = $this->client->get_request();
			if ( Client::EVENT_FAILED === $event ) {
				$this->on_failure( $request->url, $request->id, $request->error );
				return true;
			}

			// Only process responses this was the last request in the chain.
			if ( $request->is_redirected() ) {
				continue;
			}

			// The request object we get from the client may be a redirect.
			// Let's keep referring to the original request.
			$original_url        = $request->original_request()->url;
			$original_request_id = $request->original_request()->id;

			/**
			 * @TODO: Whenever we get a redirect to a URL we've already processed,
			 *        stop and emit a success event.
			 */
			switch ( $event ) {
				case Client::EVENT_GOT_HEADERS:
					if ( file_exists( $this->output_paths[ $original_request_id ] . '.partial' ) ) {
						unlink( $this->output_paths[ $original_request_id ] . '.partial' );
					}
					echo $this->output_paths[ $original_request_id ] . "\n";
					$fp = fopen( $this->output_paths[ $original_request_id ] . '.partial', 'wb' );
					if ( false !== $fp ) {
						$this->fps[ $original_request_id ]           = $fp;
						$this->progress[ $original_url ]['received'] = 0;
						if ( $request->response->get_header( 'Content-Length' ) ) {
							$this->progress[ $original_url ]['total'] = $request->response->get_header( 'Content-Length' );
						}
					}
					break;
				case Client::EVENT_BODY_CHUNK_AVAILABLE:
					$chunk = $this->client->get_response_body_chunk();
					if ( ! fwrite( $this->fps[ $original_request_id ], $chunk ) ) {
						// @TODO: Don't echo the error message. Attach it to the import session instead for the user to review later on.
						_doing_it_wrong(
							__METHOD__,
							sprintf( 'Failed to write to file: %s', $this->output_paths[ $original_request_id ] ),
							'1.0'
						);
					}
					$this->progress[ $original_url ]['received'] += strlen( $chunk );
					break;
				case Client::EVENT_FINISHED:
					if ( $request->response->ok() ) {
						$this->on_success( $original_url, $original_request_id );
					} else {
						$this->on_failure( $original_url, $original_request_id, 'http_error_' . $request->response->status_code );
					}
					break;
			}

			return true;
		}

		return false;
	}

	private function on_failure( $original_url, $original_request_id, $error = null ) {
		if ( isset( $this->fps[ $original_request_id ] ) ) {
			if ( is_resource( $this->fps[ $original_request_id ] ) ) {
				fclose( $this->fps[ $original_request_id ] );
			}
			unset( $this->fps[ $original_request_id ] );
		}
		if ( isset( $this->output_paths[ $original_request_id ] ) ) {
			$partial_file = $this->output_paths[ $original_request_id ] . '.partial';
			if ( file_exists( $partial_file ) ) {
				unlink( $partial_file );
			}
		}
		$this->pending_events[] = new AttachmentDownloaderEvent(
			$original_url,
			AttachmentDownloaderEvent::FAILURE,
			$error
		);
		unset( $this->progress[ $original_url ] );
		unset( $this->output_paths[ $original_request_id ] );
	}

	private function on_success( $original_url, $original_request_id ) {
		// Only clean up if this was the last request in the chain.
		if ( isset( $this->fps[ $original_request_id ] ) ) {
			if ( is_resource( $this->fps[ $original_request_id ] ) ) {
				fclose( $this->fps[ $original_request_id ] );
			}
			unset( $this->fps[ $original_request_id ] );
		}
		if ( isset( $this->output_paths[ $original_request_id ] ) ) {
			if ( false === rename(
				$this->output_paths[ $original_request_id ] . '.partial',
				$this->output_paths[ $original_request_id ]
			) ) {
				// @TODO: Log an error.
			}
		}
		$this->pending_events[] = new AttachmentDownloaderEvent(
			$original_url,
			AttachmentDownloaderEvent::SUCCESS
		);
		unset( $this->progress[ $original_url ] );
		unset( $this->output_paths[ $original_request_id ] );
	}

	public function __destruct() {
		// Ensure any remaining open file descriptors are closed.
		foreach ( $this->fps as $request_id => $fp ) {
			if ( is_resource( $fp ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@fclose( $fp );
			}
			unset( $this->fps[ $request_id ] );
		}
	}
}
