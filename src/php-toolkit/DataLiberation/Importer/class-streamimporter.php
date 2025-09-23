<?php

namespace WordPress\DataLiberation\Importer;

use InvalidArgumentException;
use WordPress\ByteStream\ReadStream\FileReadStream;
use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;
use WordPress\DataLiberation\EntityReader\EntityReaderIterator;
use WordPress\DataLiberation\EntityReader\WXREntityReader;
use WordPress\DataLiberation\URL\WPURL;
use WordPress\HttpClient\ByteStream\RequestReadStream;

use function WordPress\DataLiberation\URL\is_child_url_of;

/**
 * Idea:
 * * Stream-process the WXR file.
 * * Frontload all the assets before processing the posts – in an idempotent
 *   and re-entrant way.
 * * Import the posts, rewrite the URLs and IDs before inserting anything.
 * * Never do any post-processing at the database level after inserting. That's
 *   too slow for large datasets.
 *
 * @TODO:
 * ✅ Re-entrant import
 * * Log progress and errors for the user to decide what to do.
 *   * Indexing
 *   * ✅ Frontloading
 *   * Entity import
 * * Error out if `source_site_url` is not set by the time we're processing
 *   the first encountered URL.
 * * Disable anything remotely related to KSES during the import. KSES
 *   modifies and often corrupts the content, and it also slows down the
 *   import. If we don't trust the imported content, we have larger problems
 *   than some escaping.
 * * Research which other filters are also worth disabling during the import.
 *   What would be a downside of disabling ALL the filters except the ones
 *   registered by WordPress Core? The upside would be preventing plugins from
 *   messing with the imported content. The downside would be the same. What else?
 *   Perhaps that could be a choice and left up to the API consumer?
 */
class StreamImporter {

	/**
	 * Populated from the WXR file's <wp:base_blog_url> tag.
	 *
	 * @var string
	 */
	protected $source_site_url;
	/**
	 * A list of [original_url, migrated_url] pairs for rewriting the URLs
	 * in the imported content.
	 *
	 * @var array
	 */
	protected $site_url_mapping = array();
	/**
	 * A list of URLs to frontload the media files from.
	 *
	 * @var array
	 */
	protected $source_media_root_urls = array();
	/**
	 * A list of candidate base URLs that have been spotted in the WXR file.
	 *
	 * For example, the theme unit test data refers to a site with a base
	 * URL https://wpthemetestdata.wordpress.com/, but it contains attachments
	 * from https://wpthemetestdata.files.wordpress.com/.
	 *
	 * Every time the importer encounters a previously unseen attachment domain,
	 * it needs more information to map it. We can't just guess. Assuming we're
	 * importing into https://example.com/, we could guess that
	 * https://wpthemetestdata.files.wordpress.com/ maps to:
	 *
	 * * https://example.com/
	 * * https://example.com/wp-content/
	 * * https://example.com/wp-content/uploads/
	 * * ...a completely different path.
	 *
	 * There's no reliable way to guess the correct mapping. Instead of trying,
	 * we're exposing the external domain to the API consumer, who can then
	 * gather additional information from the user and decide whether to map
	 * it and how.
	 *
	 * Once the API consumer decides on the mapping, it can call
	 * add_url_mapping() to tell the importer what to map that domain to.
	 *
	 * @var array
	 */
	protected $site_url_mapping_candidates = array();

	/**
	 * @var callable
	 */
	protected $entity_reader_factory;
	/**
	 * @var array
	 * @param  array|string|null  $query  {
	 *
	 * @type string $uploads_path The directory to download the media attachments to.
	 *                                      E.g. WP_CONTENT_DIR . '/uploads'
	 * @type string $new_media_root_url The URL where the media attachments will be accessible
	 *                                      after the import. E.g. http://127.0.0.1:9400/wp-content/uploads/
	 * }
	 */
	protected $options;

	const STAGE_INITIAL          = '#initial';
	const STAGE_INDEX_ENTITIES   = '#index_entities';
	const STAGE_TOPOLOGICAL_SORT = '#topological_sort';
	const STAGE_FRONTLOAD_ASSETS = '#frontload_assets';
	const STAGE_IMPORT_ENTITIES  = '#import_entities';
	const STAGE_FINISHED         = '#finished';

	const STAGES_IN_ORDER = array(
		self::STAGE_INITIAL,
		self::STAGE_INDEX_ENTITIES,
		self::STAGE_TOPOLOGICAL_SORT,
		self::STAGE_FRONTLOAD_ASSETS,
		self::STAGE_IMPORT_ENTITIES,
		self::STAGE_FINISHED,
	);

	/**
	 * The current state of the import process.
	 *
	 * @var string
	 */
	protected $stage = self::STAGE_INITIAL;
	/**
	 * The next stage of the import process. An explicit call to
	 * next_stage() is required to advance the importer.
	 *
	 * This mechanism gives the consumer a chance to handle any failures
	 * from the current stage, e.g. backfilling the image assets that
	 * failed to download.
	 *
	 * @var string
	 */
	protected $next_stage;

	/**
	 * Iterator that streams entities to import.
	 *
	 * @var EntityReaderIterator|null
	 */
	protected $entity_iterator;

	/**
	 * @var string|null
	 */
	protected $resume_at_entity;

	/**
	 * Is this the first time we're creating an iterator? If so,
	 * we'll assume there was some activity before and we need to
	 * resume from the reentrancy cursor. Otherwise, we'll assume
	 * we've just transitioned to the next stage and we don't need
	 * to resume from the reentrancy cursor.
	 *
	 * @TODO: This logic is flawed. Let's get rid of these implicit
	 * assumptions and handle the reentrancy cursor explicitly. Perhaps
	 * emit the cursor from this StreamImporter class and encode the
	 * "stage" in it, then use the cursor to resume only if the cursor
	 * stage matches the current stage.
	 *
	 * @var bool
	 */
	protected $first_iterator = true;

	/**
	 * A map of currently downloaded resources for each entity in
	 * the following format:
	 *
	 * [$entity_cursor => [$resource_id => true]]
	 *
	 * @var array<string,array<string,bool>>
	 */
	protected $active_downloads = array();

	/**
	 * @var AttachmentDownloader|null
	 */
	protected $downloader;

	public static function create_for_wxr_file( $wxr_path, $options = array(), $cursor = null ) {
		return static::create(
			function ( $cursor = null ) use ( $wxr_path ) {
				return WXREntityReader::create( FileReadStream::from_path( $wxr_path ), $cursor );
			},
			$options,
			$cursor
		);
	}

	public static function create_for_wxr_url( $wxr_url, $options = array(), $cursor = null ) {
		return static::create(
			function ( $cursor = null ) use ( $wxr_url ) {
				return WXREntityReader::create( new RequestReadStream( $wxr_url ), $cursor );
			},
			$options,
			$cursor
		);
	}

	public static function create(
		$entity_reader_factory,
		$options = array(),
		$cursor = null
	) {
		$options  = static::parse_options( $options );
		$importer = new static( $entity_reader_factory, $options );
		if ( null !== $cursor && true !== $importer->initialize_from_cursor( $cursor ) ) {
			return false;
		}

		return $importer;
	}

	protected function initialize_from_cursor( $cursor ) {
		$cursor = json_decode( $cursor, true );
		if ( ! is_array( $cursor ) ) {
			_doing_it_wrong( __METHOD__, 'Cannot resume an importer with a non-array cursor.', '1.0.0' );

			return false;
		}
		$this->stage            = $cursor['stage'];
		$this->next_stage       = $cursor['next_stage'];
		$this->resume_at_entity = $cursor['resume_at_entity'];
		if ( ! empty( $cursor['source_site_url'] ) ) {
			$this->set_source_site_url( $cursor['source_site_url'] );
		}
		if ( ! empty( $cursor['site_url_mapping'] ) ) {
			foreach ( $cursor['site_url_mapping'] as $pair ) {
				$this->add_url_mapping( $pair['from'], $pair['to'] );
			}
		}
		if ( ! empty( $cursor['site_url_mapping_candidates'] ) ) {
			$this->site_url_mapping_candidates = $cursor['site_url_mapping_candidates'];
		}

		return true;
	}

	protected function set_source_site_url( $source_site_url ) {
		$this->source_site_url = $source_site_url;
		// -1 is a well-known index for the source site URL.
		// Every subsequent call to set_source_site_url() will.
		// override that mapping.
		$this->site_url_mapping[- 1] = array(
			'from' => WPURL::parse( $source_site_url ),
			'to'   => WPURL::parse( $this->options['new_site_content_root_url'] ),
		);
	}

	public function get_site_url_mapping_candidates() {
		// Only return the candidates that have been spotted in the last index_entities() call.
		if ( self::STAGE_INDEX_ENTITIES !== $this->stage ) {
			return array();
		}
		$new_candidates = array();
		foreach ( $this->site_url_mapping_candidates as $base_url => $status ) {
			if ( false === $status ) {
				$new_candidates[] = $base_url;
			}
		}

		return $new_candidates;
	}

	public function add_url_mapping( $from_url, $to_url ) {
		$this->site_url_mapping[] = array(
			'from' => WPURL::parse( $from_url ),
			'to'   => WPURL::parse( $to_url ),
		);
	}

	public function add_source_media_root_url( $source_media_root_url ) {
		$this->source_media_root_urls[] = WPURL::parse( $source_media_root_url );
	}

	public function get_reentrancy_cursor() {
		$serialized_site_url_mapping = array();
		foreach ( $this->site_url_mapping as $pair ) {
			$serialized_site_url_mapping[] = array(
				'from' => (string) $pair['from'],
				'to'   => (string) $pair['to'],
			);
		}

		return json_encode(
			array(
				'stage'                       => $this->stage,
				/**
				 * Store `next_stage` to distinguish between the start and the end of the entity
				 * stream. `resume_at_entity` may be null in both cases.
				 */
				'next_stage'                  => $this->next_stage,
				'resume_at_entity'            => $this->resume_at_entity,
				'source_site_url'             => $this->source_site_url,
				'site_url_mapping'            => $serialized_site_url_mapping,
				'site_url_mapping_candidates' => $this->site_url_mapping_candidates,
			)
		);
	}

	protected static function parse_options( $options ) {
		if ( ! isset( $options['source_site_url'] ) ) {
			// @TODO: Throw only if source_site_url is not set by the time we're processing the first encountered URL
			// throw new DataLiberationException( 'The "source_site_url" option is required' );
		}
		if ( ! isset( $options['new_site_content_root_url'] ) ) {
			if ( ! function_exists( 'get_site_url' ) ) {
				throw new InvalidArgumentException( 'Option "new_site_content_root_url" is required' );
			}
			$options['new_site_content_root_url'] = get_site_url();
		}

		if ( ! isset( $options['uploads_path'] ) ) {
			$options['uploads_path'] = wp_get_upload_dir()['basedir'];
		}
		// Remove the trailing slash to make concatenation easier later.
		$options['uploads_path'] = rtrim( $options['uploads_path'], '/' );

		if ( ! isset( $options['new_media_root_url'] ) ) {
			if ( ! function_exists( 'get_site_url' ) ) {
				throw new InvalidArgumentException( 'Option "new_media_root_url" is required' );
			}
			$options['new_media_root_url'] = rtrim( get_site_url(), '/' ) . '/wp-content/uploads';
		}
		// Remove the trailing slash to make concatenation easier later.
		$options['new_media_root_url'] = rtrim( $options['new_media_root_url'], '/' );

		if ( ! isset( $options['index_batch_size'] ) ) {
			$options['index_batch_size'] = 10000;
		}

		return $options;
	}

	protected function __construct(
		$entity_reader_factory,
		$options = array()
	) {
		$this->entity_reader_factory = $entity_reader_factory;
		$this->options               = $options;
		if ( ! empty( $options['source_site_url'] ) ) {
			$this->set_source_site_url( $options['source_site_url'] );
		}

		if ( isset( $options['source_media_root_urls'] ) ) {
			foreach ( $options['source_media_root_urls'] as $source_media_root_url ) {
				$this->add_source_media_root_url( $source_media_root_url );
			}
		}

		if ( isset( $options['additional_url_mappings'] ) ) {
			foreach ( $options['additional_url_mappings'] as $additional_url_mapping ) {
				$this->add_url_mapping( $additional_url_mapping['from'], $additional_url_mapping['to'] );
			}
		}
	}

	protected $frontloading_retries_iterator;

	public function set_frontloading_retries_iterator( $frontloading_retries_iterator ) {
		$this->frontloading_retries_iterator = $frontloading_retries_iterator;
	}

	/**
	 * The WordPress entity importer instance.
	 *
	 * @TODO: Consider inlining the importer code into this class.
	 *
	 * @var WP_Entity_Importer
	 */
	protected $entity_sink;

	public function next_step() {
		switch ( $this->stage ) {
			case self::STAGE_INITIAL:
				$this->next_stage = self::STAGE_INDEX_ENTITIES;

				return false;
			case self::STAGE_INDEX_ENTITIES:
				if ( true === $this->index_next_entities() ) {
					return true;
				}
				$this->next_stage = self::STAGE_TOPOLOGICAL_SORT;

				return false;
			case self::STAGE_TOPOLOGICAL_SORT:
				// @TODO: Different modes:
				// 1. skip, reprocess.
				// 2. sort topologically.
				$this->next_stage = self::STAGE_FRONTLOAD_ASSETS;

				return false;
			case self::STAGE_FRONTLOAD_ASSETS:
				if ( true === $this->frontload_next_entity() ) {
					return true;
				}
				$this->next_stage = self::STAGE_IMPORT_ENTITIES;

				return false;
			case self::STAGE_IMPORT_ENTITIES:
				if ( true === $this->import_next_entity() ) {
					return true;
				}
				$this->next_stage = self::STAGE_FINISHED;

				return false;
			case self::STAGE_FINISHED:
				return false;
		}
	}

	public function get_stage() {
		return $this->stage;
	}

	public function get_next_stage() {
		return $this->next_stage;
	}

	public function advance_to_next_stage() {
		if ( null === $this->next_stage ) {
			return false;
		}
		$this->stage      = $this->next_stage;
		$this->next_stage = null;

		return true;
	}

	protected $indexed_entities_counts = array();
	protected $indexed_assets_urls     = array();

	protected function index_next_entities() {
		if ( null !== $this->next_stage ) {
			return false;
		}

		if ( null === $this->entity_iterator ) {
			$this->entity_iterator = $this->create_entity_iterator();
		}

		// Mark all mapping candidates as seen.
		foreach ( $this->site_url_mapping_candidates as $base_url => $status ) {
			$this->site_url_mapping_candidates[ $base_url ] = true;
		}

		// Reset the counts and URLs found in the previous pass.
		$this->indexed_entities_counts = array();
		$this->indexed_assets_urls     = array();

		// We're done if all the entities are processed.
		if ( ! $this->entity_iterator->valid() ) {
			$this->entity_iterator  = null;
			$this->resume_at_entity = null;

			return false;
		}

		/**
		 * Internalize the loop to avoid computing the reentrancy cursor
		 * on every entity in the imported data stream.
		 */
		for ( $i = 0; $i < $this->options['index_batch_size']; ++$i ) {
			if ( ! $this->entity_iterator->valid() ) {
				break;
			}
			/**
			 * Identify the static assets referenced in the current entity
			 * and enqueue them for download.
			 */
			$entity = $this->get_current_entity();

			$type = $entity->get_type();

			// Count entities by type.
			if ( ! isset( $this->indexed_entities_counts[ $type ] ) ) {
				$this->indexed_entities_counts[ $type ] = 0;
			}
			++$this->indexed_entities_counts[ $type ];

			/**
			 * Track unique assets URLs.
			 *
			 * This enables reliably communicating the download progress to the consumer.
			 * If we only counted the URLs, the duplicates would inflate the total count.
			 * Also, distinguishing assets by their URLs is useful for tracking the bytes
			 * downloaded per file.
			 *
			 * @TODO Consider adapting the array size to the available memory. We can hold
			 * every single post in memory for sure, otherwise WordPress would not
			 * be able to render it, but can we hold all the URLs from 10k posts at once?
			 */
			$data = $entity->get_data();
			switch ( $type ) {
				case 'site_option':
					if ( 'home' === $data['option_name'] ) {
						$this->set_source_site_url( $data['option_value'] );
					}
					break;
				case 'post':
					if ( isset( $data['post_type'] ) && 'attachment' === $data['post_type'] ) {
						/**
						 * Keep track of alternative domains used to reference attachments,
						 * e.g. Theme Unit Test Data site lives at https://wpthemetestdata.wordpress.com/
						 * but many attachments are served from https://wpthemetestdata.files.wordpress.com/
						 */
						$parsed_url = WPURL::parse( $data['attachment_url'] );
						if ( $parsed_url ) {
							$parsed_url->pathname = '';
							$parsed_url->search   = '';
							$parsed_url->hash     = '';
							$base_url             = $parsed_url->toString();
							if ( ! array_key_exists( $base_url, $this->site_url_mapping_candidates ) ) {
								$this->site_url_mapping_candidates[ $base_url ] = false;
							}
						}
						// @TODO: Consider using sha1 hashes to prevent huge URLs from blowing up the memory.
						if ( isset( $data['attachment_url'] ) ) {
							$this->indexed_assets_urls[ $data['attachment_url'] ] = true;
						}
					} elseif ( isset( $data['post_content'] ) ) {
						$post = $data;
						$p    = new BlockMarkupUrlProcessor( $post['post_content'], $this->get_post_base_url( $post ) );

						while ( $p->next_url() ) {
							if ( ! $this->url_processor_matched_asset_url( $p ) ) {
								continue;
							}
							// @TODO: Consider using sha1 hashes to prevent huge URLs from blowing up the memory.
							// @TODO: Use a consistent identifier for tracking download progress. Unfortunately,
							// $p->get_raw_url() does not line up with the resolved URL later on. The progress
							// events are emited with the full, resolved URL.
							$this->indexed_assets_urls[ $p->get_parsed_url() . '' ] = true;
						}
					}
					break;
			}

			$this->entity_iterator->next();
		}
		$this->resume_at_entity = $this->entity_iterator->get_reentrancy_cursor();

		return true;
	}

	public function get_new_site_content_root_url_mapping_candidates() {
		$candidates = array();
		foreach ( $this->site_url_mapping_candidates as $base_url => $status ) {
			if ( false === $status ) {
				$candidates[] = $base_url;
			}
		}

		return $candidates;
	}

	public function get_indexed_entities_counts() {
		return $this->indexed_entities_counts;
	}

	public function get_indexed_assets_urls() {
		return $this->indexed_assets_urls;
	}

	protected $frontloading_events = array();

	public function get_frontloading_events() {
		return $this->frontloading_events;
	}

	/**
	 * @return array<string, int> The frontloading progress per URL.
	 */
	public function get_frontloading_progress() {
		return $this->downloader ? $this->downloader->get_progress() : array();
	}

	/**
	 * Advance the cursor to the oldest finished download. For example:
	 *
	 * * We've started downloading files A, B, C, and D in this order.
	 * * D is the first to finish. We don't do anything yet.
	 * * A finishes next. We advance the cursor to A.
	 * * C finishes next. We don't do anything.
	 * * Then we pause.
	 *
	 * When we resume, we'll start where we left off, which is after A. The
	 * downloader will enqueue B for download and will skip C and D since
	 * the relevant files already exist in the filesystem.
	 */
	protected function frontloading_advance_reentrancy_cursor() {
		foreach ( $this->downloader->get_events() as $event ) {
			switch ( $event->type ) {
				case AttachmentDownloaderEvent::FAILURE:
				case AttachmentDownloaderEvent::SUCCESS:
				case AttachmentDownloaderEvent::IN_PROGRESS:
				case AttachmentDownloaderEvent::ALREADY_EXISTS:
					$this->frontloading_events[] = $event;
					foreach ( array_keys( $this->active_downloads ) as $entity_cursor ) {
						unset( $this->active_downloads[ $entity_cursor ][ $event->resource_id ] );
					}
					break;
			}
		}

		while ( count( $this->active_downloads ) > 0 ) {
			$oldest_download_cursor = key( $this->active_downloads );
			$downloads_completed    = empty( $this->active_downloads[ $oldest_download_cursor ] );
			if ( ! $downloads_completed ) {
				break;
			}
			// Process completed downloads.
			// Advance the cursor to the next entity.
			$this->resume_at_entity = $oldest_download_cursor;
			unset( $this->active_downloads[ $oldest_download_cursor ] );
		}
	}

	/**
	 * Downloads all the assets referenced in the imported entities.
	 *
	 * This method is idempotent, re-entrant, and should be called
	 * before import_entities() so that every inserted post already has
	 * all its attachments downloaded.
	 */
	protected function frontload_next_entity() {
		if ( null === $this->entity_iterator ) {
			$this->entity_iterator = new EntityIteratorChain();
			if ( null !== $this->frontloading_retries_iterator ) {
				$this->entity_iterator->set_assets_attempts_iterator( $this->frontloading_retries_iterator );
			}
			if ( null === $this->next_stage ) {
				$this->entity_iterator->set_entities_iterator( $this->create_entity_iterator() );
			}
			$this->downloader = new AttachmentDownloader(
				$this->options['uploads_path'],
				$this->options['attachment_downloader_options'] ?? array()
			);
		}

		// Clear the frontloading events from the previous pass.
		$this->frontloading_events = array();
		$this->frontloading_advance_reentrancy_cursor();

		// Poll the bytes between scheduling new downloads.
		$only_downloader_pending = ! $this->entity_iterator->valid() && $this->downloader->has_pending_requests();
		if ( $this->downloader->queue_full() || $only_downloader_pending ) {
			/**
			 * @TODO:
			 * * Process and store failures.
			 *   E.g. what if the attachment is not found? Error out? Ignore? In a UI-based
			 *   importer scenario, this is the time to log a failure to let the user
			 *   fix it later on. In a CLI-based Blueprint step importer scenario, we
			 *   might want to provide an "image not found" placeholder OR ignore the
			 *   failure.
			 *
			 * @TODO: Update the download progress:
			 * * After every downloaded file.
			 * * For large files, every time a full megabyte is downloaded above 10MB.
			 */
			if ( true === $this->downloader->poll() ) {
				$this->frontloading_advance_reentrancy_cursor();

				return true;
			}
		}

		// We're done if all the entities are processed and all the downloads are finished.
		if ( ! $this->entity_iterator->valid() && ! $this->downloader->has_pending_requests() ) {
			// This is an assertion to make double sure we're emptying the state queue.
			if ( ! empty( $this->active_downloads ) ) {
				_doing_it_wrong( __METHOD__, '$active_downloads queue was not empty at the end of the frontloading stage.', '1.0' );
			}
			$this->downloader          = null;
			$this->active_downloads    = array();
			$this->entity_iterator     = null;
			$this->resume_at_entity    = null;
			$this->frontloading_events = array();

			return false;
		}

		/**
		 * Identify the static assets referenced in the current entity
		 * and enqueue them for download.
		 */
		$entity                            = $this->get_current_entity();
		$cursor                            = $this->entity_iterator->get_reentrancy_cursor();
		$this->active_downloads[ $cursor ] = array();

		$data = $entity->get_data();
		switch ( $entity->get_type() ) {
			case 'asset_retry':
				$this->enqueue_attachment_download(
					$data['current_url'],
					array(
						'original_url' => $data['original_url'],
					)
				);
				break;
			case 'post':
				if ( isset( $data['post_type'] ) && 'attachment' === $data['post_type'] ) {
					if ( isset( $data['attachment_url'] ) ) {
						$this->enqueue_attachment_download( $data['attachment_url'] );
					} else {
						// @TODO: Emit warning / error event.
						_doing_it_wrong( __METHOD__, 'No attachment URL or file path found in the post entity.', '1.0' );
					}
				} elseif ( isset( $data['post_content'] ) ) {
					$post     = $data;
					$base_url = $this->get_post_base_url( $post );
					$p        = new BlockMarkupUrlProcessor( $post['post_content'], $base_url );
					while ( $p->next_url() ) {
						if ( ! $this->url_processor_matched_asset_url( $p ) ) {
							continue;
						}
						$this->enqueue_attachment_download(
							$p->get_raw_url(),
							array(
								'base_url' => $base_url,
							)
						);
					}
				}
				break;
		}

		// Move on to the next entity.
		$this->entity_iterator->next();

		$this->frontloading_advance_reentrancy_cursor();

		return true;
	}

	public function get_current_entity() {
		$entity = $this->entity_iterator->current();
		$entity = apply_filters(
			'data_liberation_stream_importer_preprocess_entity',
			$entity,
			array(
				'importer' => $this,
			)
		);

		return $entity;
	}

	protected function get_post_base_url( $post ) {
		return apply_filters(
			'data_liberation_stream_importer_post_base_url',
			$post['link'] ?? $this->source_site_url,
			array(
				'post'     => $post,
				'importer' => $this,
			)
		);
	}

	/**
	 * @TODO: Explore a way of making this idempotent. Maybe
	 *        use GUIDs to detect whether a post or an attachment
	 *        has already been imported? That would be slow on
	 *        large datasets, but maybe it could be a choice for
	 *        the API consumer?
	 */
	protected function import_next_entity() {
		if ( null !== $this->next_stage ) {
			return false;
		}

		$this->imported_entities_counts = array();

		if ( null === $this->entity_iterator ) {
			$this->entity_iterator = $this->create_entity_iterator();
			$this->entity_sink     = $this->options['entity_sink'] ?? new EntityImporter();
		}

		if ( ! $this->entity_iterator->valid() ) {
			// We're done.
			$this->stage           = self::STAGE_FINISHED;
			$this->entity_iterator = null;
			$this->entity_sink     = null;

			return false;
		}

		$entity = $this->get_current_entity();

		$attachments = array();
		// Rewrite the URLs in the post.
		switch ( $entity->get_type() ) {
			case 'post':
				$data = $entity->get_data();
				if ( isset( $data['post_type'] ) && 'attachment' === $data['post_type'] ) {
					if ( ! isset( $data['attachment_url'] ) ) {
						// @TODO: Emit warning / error event.
						_doing_it_wrong( __METHOD__, 'No attachment URL or file path found in the post entity.', '1.0' );
						break;
					}
					$asset_filename = $this->new_asset_filename(
						$data['attachment_url'],
						$data['link'] ?? null
					);
					unset( $data['attachment_url'] );
					$data['local_file_path'] = $this->options['uploads_path'] . '/' . $asset_filename;
				} else {
					/**
					 * @TODO: Think through guid rewriting. It may matter for WXR posts,
					 *        where the guid often describes the page URL, but it may
					 *        get in the way for Markdown imports, where the guid is
					 *        either inferred from the static file path or fixed and
					 *        sourced from metadata.
					 */
					foreach ( array( 'post_content', 'post_excerpt' ) as $key ) {
						if ( ! isset( $data[ $key ] ) ) {
							continue;
						}
						$base_url = $this->get_post_base_url( $data );
						$p        = new BlockMarkupUrlProcessor( $data[ $key ], $base_url );
						while ( $p->next_url() ) {
							// Relative URLs are okay at this stage.
							if ( ! $p->get_raw_url() ) {
								continue;
							}
							// No need to rewrite anchor links.
							if ( '#' === substr( $p->get_raw_url(), 0, 1 ) ) {
								continue;
							}

							/**
							 * Any URL that has a corresponding frontloaded file is an asset URL.
							 */
							$asset_filename = $this->new_asset_filename(
								$p->get_raw_url(),
								$base_url
							);

							if ( file_exists( $this->options['uploads_path'] . '/' . $asset_filename ) ) {
								$raw_url = rtrim( $this->options['new_media_root_url'], '/' ) . '/' . $asset_filename;
								$p->set_url(
									$raw_url,
									WPURL::parse( $raw_url )
								);
								/**
								 * @TODO: How would we know a specific image block refers to a specific
								 *        attachment? We need to cross-correlate that to rewrite the URL.
								 *        The image block could have query parameters, too, but presumably the
								 *        path would be the same at least? What if the same file is referred
								 *        to by two different URLs? e.g. assets.site.com and site.com/assets/ ?
								 *        A few ideas: GUID, block attributes, fuzzy matching. Maybe a configurable
								 *        strategy? And the API consumer would make the decision?
								 */
								continue;
							}

							// Absolute URLs are required at this stage.
							if ( ! $p->get_parsed_url() ) {
								continue;
							}

							$raw_url_before          = $p->get_raw_url();
							$mapping_pair            = $this->get_url_mapping_pair( $p->get_parsed_url() );
							$should_rewrite_base_url = false !== $mapping_pair;
							if ( $should_rewrite_base_url ) {
								$p->replace_base_url( $mapping_pair['to'], $mapping_pair['from'] );
							}
							do_action(
								'data_liberation_stream_importer_postprocess_url',
								$p,
								array(
									'applied_base_url_mapping' => $mapping_pair,
									'raw_url_before' => $raw_url_before,
									'entity'         => $entity,
								)
							);
						}
						$data[ $key ] = $p->get_updated_html();
					}
				}
				$entity->set_data( $data );
				break;
		}

		$post_id = $this->entity_sink->import_entity( $entity );
		if ( false !== $post_id ) {
			$this->count_imported_entity( $entity->get_type() );
		}
		// @TODO: Store error.
		// For now, we continue processing other entities.
		foreach ( $attachments as $filepath ) {
			// @TODO: Monitor failures.
			$attachment_id = $this->entity_sink->import_attachment( $filepath, $post_id );
			if ( false !== $attachment_id ) {
				// @TODO: How to count attachments?
				$this->count_imported_entity( 'post' );
			} else {
				// @TODO: Store error.
			}
		}

		/**
		 * @TODO: Update the progress information.
		 */
		$this->resume_at_entity = $this->entity_iterator->get_reentrancy_cursor();
		$this->entity_iterator->next();

		return true;
	}

	protected $imported_entities_counts = array();

	protected function count_imported_entity( $type ) {
		if ( ! array_key_exists( $type, $this->imported_entities_counts ) ) {
			$this->imported_entities_counts[ $type ] = 0;
		}
		++$this->imported_entities_counts[ $type ];
	}

	public function get_imported_entities_counts() {
		return $this->imported_entities_counts;
	}

	protected function enqueue_attachment_download( string $raw_url, $options = array() ) {
		$output_filename = $this->new_asset_filename(
			$options['original_url'] ?? $raw_url,
			$options['base_url'] ?? null
		);
		$download_url    = $this->rewrite_attachment_url( $raw_url, $options['base_url'] ?? null );
		$enqueued        = $this->downloader->enqueue_if_not_exists( $download_url, $output_filename );
		if ( false === $enqueued ) {
			_doing_it_wrong( __METHOD__, sprintf( 'Failed to enqueue attachment download: %s', $raw_url ), '1.0' );

			return false;
		}

		$entity_cursor                                        = $this->entity_iterator->get_reentrancy_cursor();
		$this->active_downloads[ $entity_cursor ][ $raw_url ] = true;

		return true;
	}

	/**
	 * The downloaded file name is based on the URL hash.
	 *
	 * Download the asset to a new path.
	 *
	 * Note the path here is different than on the original site.
	 * There isn't an easy way to preserve the original assets paths on
	 * the new site.
	 *
	 * * The assets may come from multiple domains
	 * * The paths may be outside of `/wp-content/uploads/`
	 * * The same path on multiple domains may point to different files
	 *
	 * Even if we tried to preserve the paths starting with `/wp-content/uploads/`,
	 * we would run into race conditions where, in case of overlapping paths,
	 * the first downloaded asset would win.
	 *
	 * The assets downloader is meant to be idempotent, deterministic, and re-entrant.
	 *
	 * Therefore, instead of trying to preserve the original paths, we'll just
	 * compute an idempotent and deterministic new path for each asset.
	 *
	 * While using a content hash is tempting, it has two downsides:
	 * * We'd need to download the asset before computing the hash.
	 * * It would de-duplicate the imported assets even if they have
	 *   different URLs. This would cause subtle issues in the new sites.
	 *   Imagine two users uploading the same image. Each user has
	 *   different permissions. Just because Bob deletes his copy, doesn't
	 *   mean we should delete Alice's copy.
	 */
	protected function new_asset_filename( string $raw_asset_url, $base_url = null ) {
		$raw_asset_url = $this->rewrite_attachment_url(
			$raw_asset_url,
			$base_url
		);

		$filename   = md5( $raw_asset_url );
		$parsed_url = WPURL::parse( $raw_asset_url );
		if ( false !== $parsed_url ) {
			$pathname = $parsed_url->pathname;
		} else {
			// Assume $raw_asset_url is a relative path when it cannot be
			// parsed as an absolute URL.
			$pathname = $raw_asset_url;
		}
		$extension = pathinfo( $pathname, PATHINFO_EXTENSION );
		if ( ! empty( $extension ) ) {
			$filename .= '.' . $extension;
		}

		return $filename;
	}

	protected function rewrite_attachment_url( string $raw_url, $base_url = null ) {
		if ( WPURL::can_parse( $raw_url ) ) {
			// Absolute URL, nothing to do.
			return $raw_url;
		}

		if ( ! $base_url ) {
			$base_url = $this->source_site_url;
		}
		$parsed_url = WPURL::parse( $raw_url, $base_url );

		if ( false === $parsed_url ) {
			return false;
		}

		return $parsed_url->toString();
	}

	/**
	 * By default, we want to download all the assets referenced in the
	 * posts that are hosted on the source site.
	 *
	 * @TODO: How can we process the videos?
	 * @TODO: What other asset types are there?
	 */
	protected function url_processor_matched_asset_url( BlockMarkupUrlProcessor $p ) {
		/**
		 * Decide whether the URL is an asset URL worth downloading.
		 *
		 * All URLs with an image-like extension are treated as images,
		 *
		 * For example, the background image in the following block would be accepted:
		 *
		 *     <div style="background-image: url(https://example.com/image.jpg)">
		 */
		$path      = $p->get_parsed_url()->pathname;
		$extension = pathinfo( $path, PATHINFO_EXTENSION );
		if ( ! in_array( $extension, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' ), true ) ) {
			/**
			 * Absent an extension, try to guess whether it's a static asset based
			 * on its location in the document. For now, we only accept images.
			 */
			if ( 'IMG' !== $p->get_tag() ) {
				return false;
			}
			if ( 'src' !== $p->get_inspected_attribute_name() ) {
				return false;
			}
		}

		/**
		 * Finally, confirm it comes from one of the allowed media root URLs.
		 */
		foreach ( $this->source_media_root_urls as $source_media_root_url ) {
			if ( is_child_url_of( $p->get_parsed_url(), $source_media_root_url ) ) {
				return true;
			}
		}

		return false;
	}

	protected function is_child_of_a_mapped_url( $url_detected_in_content ) {
		return false !== $this->get_url_mapping_pair( $url_detected_in_content );
	}

	protected function get_url_mapping_pair( $url_detected_in_content ) {
		foreach ( $this->site_url_mapping as $pair ) {
			if ( is_child_url_of( $url_detected_in_content, $pair['from'] ) ) {
				return $pair;
			}
		}

		return false;
	}

	protected function create_entity_iterator() {
		$factory = $this->entity_reader_factory;
		$cursor  = null;
		if ( $this->first_iterator ) {
			$this->first_iterator = false;
			$cursor               = $this->resume_at_entity;
		}

		return new EntityReaderIterator( $factory( $cursor ) );
	}
}
