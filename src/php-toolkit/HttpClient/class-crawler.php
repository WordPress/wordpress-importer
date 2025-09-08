<?php

namespace WordPress\HttpClient;

use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;
use WordPress\DataLiberation\URL\WPURL;

use function WordPress\DataLiberation\URL\is_child_url_of;

/**
 * A simple web crawler.
 */
class Crawler {
	/** @var Client */
	private $client;

	/** @var array */
	private $visited_urls = array();

	/** @var string|null */
	private $base_url = null;

	/** @var string|null */
	private $current_url = null;

	/** @var string */
	private $current_content = '';

	/** @var callable|null */
	private $preprocess_url = null;

	private $responses = array();

	/**
	 * @param  string $base_url  The starting URL to crawl
	 * @param  array  $options  Client options
	 */
	public function __construct( $base_url, array $options = array() ) {
		$this->client                    = $options['client'] ?? new Client();
		$this->preprocess_url            = $options['preprocess_url'] ?? null;
		$this->base_url                  = $base_url;
		$this->visited_urls[ $base_url ] = true;
		$this->client->enqueue( new Request( $base_url ) );
	}

	/**
	 * Get the URL of the current page being crawled
	 *
	 * @return string|null
	 */
	public function get_current_url() {
		return $this->current_url;
	}

	/**
	 * Get the content of the current page being crawled
	 *
	 * @return string
	 */
	public function get_current_content() {
		return $this->current_content;
	}

	/**
	 * Crawl the next matching URL
	 *
	 * @return bool True if a page was crawled, false if crawling is finished
	 */
	public function crawl_next() {
		$this->current_url     = null;
		$this->current_content = '';
		while ( $this->client->await_next_event() ) {
			$current_request = $this->client->get_request();
			if ( $current_request->error ) {
				// Skip failed requests.
				// @TODO: Error handling.
				continue;
			}

			if ( $current_request->redirected_to ) {
				// Handle redirects by following the redirect chain.
				$current_request = $current_request->latest_redirect();
			}
			if ( ! is_child_url_of( $current_request->url, $this->base_url ) ) {
				// @TODO: Abort the request instead of just ignoring its events.
				continue;
			}

			$this->current_url = $current_request->url;
			switch ( $this->client->get_event() ) {
				case Client::EVENT_BODY_CHUNK_AVAILABLE:
					if ( ! isset( $this->responses[ $this->current_url ] ) ) {
						$this->responses[ $this->current_url ] = '';
					}
					$this->responses[ $this->current_url ] .= $this->client->get_response_body_chunk();
					break;

				case Client::EVENT_FINISHED:
					if ( ! isset( $this->responses[ $this->current_url ] ) ) {
						continue 2;
					}
					// Extract new URLs from content.
					$p = new BlockMarkupUrlProcessor( $this->responses[ $this->current_url ], $this->current_url );
					while ( $p->next_url() ) {
						$this->enqueue_url( $p->get_parsed_url() );
					}
					$this->current_content = $this->responses[ $this->current_url ];
					unset( $this->responses[ $this->current_url ] );

					return true;
			}
		}

		return false;
	}

	private function enqueue_url( $url ) {
		$parsed       = WPURL::parse( $url );
		$parsed->hash = '';

		if ( $this->preprocess_url ) {
			$parsed = call_user_func( $this->preprocess_url, $parsed );
			if ( false === $parsed ) {
				return;
			}
		}

		$normalized_url = $parsed->toString();
		if ( ! isset( $this->visited_urls[ $normalized_url ] ) ) {
			$this->visited_urls[ $normalized_url ] = true;
			$this->client->enqueue( new Request( $normalized_url ) );
		}
	}
}
