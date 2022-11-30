<?php
/**
 * A memory efficient drop in replacement for WXR_Parser
 *
 * WXR_Parser_Large_File is a drop-in replacement for WXR_Parser that should
 * be completely compatible, but massively more memory efficient (although
 * it is very slightly slower). The end goal of this is to allow importing
 * very large WordPress export files without the need to split them up into
 * smaller chunks.
 *
 * Example: A WXR that was 229,407,801 bytes, 10,311 posts, 21,083 comments
 *		WXR_Parser:            7.574581 seconds, 767,295,488 bytes of RAM
 *		WXR_Parser_Large_File: 9.951963 seconds,   8,388,608 bytes of RAM
 * See? Memory efficient.
 *
 * How it works:
 *
 * Step 1: Read everything that isn't an rss/channel/item into a header string
 * or a footer string. This will be used later in creating smaller WXR files.
 *
 * Step 2: All rss/channel/item entries are put into an open, but otherwise
 * orphaned file handle. On insertion, an offset and byte length is recorded
 * for the entry. This is essentially an indexed database file. Most of the
 * extra time parsing is spent here literally just shuffling bytes around.
 *
 * Step 3: Parse an itemless WXR once, and store the data in memory (containing all
 * the authors, cats, etc). Any access on the object that is not for ['posts']
 * is instead fetched from this itemless in-memory structure (this is why
 * ArrayAccess and __get were implemented.) ['posts'] returns itself.
 *
 * Step 4: Allow the class to be foreached over and counted so that it behaves
 * like the ['posts'] from the return value of WXR_Parser::parse(). Foreach
 * uses the current() method to get the current item.
 *
 * Step 5: in current(), look up the offset and length of the post data in the
 * database, seek to the offset, read that number of bytes. Sandwich it
 * between the header and the footer strings, and write that to a temp file.
 * It has to be a file because WXR_Parser:parse() requires a file. Finally
 * parse the file with the original WXR_Parser, and return ['posts'][0] (since
 * we know there's only one post in the import that we created for this purpose).
 *
 * Why would we bother using WXR_Parser when we could be more efficient by
 * also rewriting what it does? The answer is that this code is as close as
 * possible to the original while allowing huge files to be streamed in where
 * the original would die horribly and out of memory.
 */
class WXR_Parser_Large_File implements Iterator, Countable, ArrayAccess {
	var $raw_header = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
	var $raw_footer = "\n";

	var $tiny_header = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
	var $tiny_header_size = 0;

	var $current_post = 0;
	var $posts_metadata = null;
	var $posts_found = 0;
	var $post_fp = 0;

	var $tmp = null;
	var $tmp_bytes = 0;

	var $mini_parsed_wxr = null;

	var $invalid_xml = false;

	var $do_compress = false;
	var $large_file_size = 524288000; // 500 MB

	public $wxr_parser_class = 'WXR_Parser';

	/**
 	 * Clean up resources so that they are not orphaned when the object is
 	 * no longer in scope. Specifically open file handles.
 	 */
	function close() {
		fclose( $this->tmp );
	}

	function __construct( $file, $override_wxr_parser = '' ) {
		/**
		 * Detect compressed import files.
		 */
		$is_forced_compressed_file = self::is_file_compressed( $file );

		$source_uri = sprintf("file://%s", realpath( $file ) );

		if (
			( preg_match( '/\.gz$/i', $file ) || $is_forced_compressed_file ) &&
			in_array( 'compress.zlib', stream_get_wrappers() )
		) {
			// 100 MB of compressed data is quite a lot
			$this->large_file_size = 104857600;
			$source_uri = sprintf( "compress.zlib://%s", $file );
		}

		// Prepend stream filter to strip out control characters XMLReader doesn't like
		$source_uri = XML_CHARACTER_FILTER_PREFIX . $source_uri;

		if ( filesize( $file ) > $this->large_file_size && function_exists( 'gzencode' ) ) {
			$this->do_compress = true;
		}

		/**
		 * Check if the WXR_Parser class needs to be overriden for another class.
		 *
		 * This is used to plug in the Site_Importer_WXR_Parser to be used with the Large File parser.
		 */
		if ( $override_wxr_parser && class_exists( $override_wxr_parser ) ) {
			$this->wxr_parser_class = $override_wxr_parser;
		}

		// Create a file pointer with no filesystem references to act as our simple
		// <item> database container. We want no filesystem references, so that when
		// the process dies the file is orphaned and space reclaimed by the OS
		$tmp = tempnam( sys_get_temp_dir(), "import-" );
		$this->tmp = fopen( $tmp, 'w+' );
		unlink( $tmp );

		// Create a similar orphaned file descriptor to house the index fata for seeking
		// to posts in our data file (above). Exactly 12 bytes per item entry will be used:
		// an unsigned 64 bit unsinged int for the offset and a 32 bit unsigned int for
		// the length of the data. Therefore seeking to $id * 12 and unpacking the next 12
		// bytes gives us everything we need to pull data from $this->tmp
		$tmp = tempnam( sys_get_temp_dir(), "import-" );
		$this->posts_metadata = fopen( $tmp, 'w+' );
		unlink( $tmp );

		// XMLReader is a stream parser. It does not need to read the entire file,
		// and is therefore very memory efficient.  It uses the same parsing engine
		// that simplexml does (I believe) and so should work precisely the same
		$reader = new XMLReader();

		libxml_use_internal_errors(true);

		$libxml_options = LIBXML_NOBLANKS;

		if ( defined( 'LIBXML_COMPACT' ) ) {
			$libxml_options = $libxml_options | LIBXML_COMPACT;
		}

		if( defined( 'LIBXML_PARSEHUGE' ) ) {
			$libxml_options = $libxml_options | LIBXML_PARSEHUGE;
		}

		// Using `false` here is bad practice, but we're limiting it to the open
		// step, in which XMLReader (unlike other XML tools) does not attempt to
		// load or parse external entities. We go back to best practice during the
		// read steps.
		$old_disable_entity_loader_value = libxml_disable_entity_loader( false );
		$opened = $reader->open( $source_uri, null, $libxml_options );
		libxml_disable_entity_loader( true );

		if ( ! $opened ) {
			libxml_disable_entity_loader( $old_disable_entity_loader_value );
			return new WP_Error( 'xml_parse_error', __( 'We had trouble opening the import file. Please make sure it\'s valid XML.', 'wordpress-importer') );
		}

		// Be explicit about this default behavior for the read steps
		$reader->setParserProperty( XMLReader::SUBST_ENTITIES, false );

		$writing_to = 0;
		$found_channel = false;
		$reader->read();
		while( true ) {
			switch( $reader->name ) {
				case 'channel':
					$found_channel = true;
				case 'rss':
					// rss and rss/channel are both parts of the header or footer
					// depending on whether we have an opening tag or not.
					$node_name = $reader->name;
					switch ( $reader->nodeType ) {
						case XMLReader::ELEMENT:
							// Trap any attributes on these kinds of elements so that
							// we can include them in the header as well
							$attrs = array();
							if ( $reader->moveToFirstAttribute() ) {
								$attrs[] = sprintf(
									'%s="%s"',
									preg_replace( '/[^0-9a-z:]/i', '', $reader->name ),
									str_replace( array( '"', '\\' ), "", $reader->value )
								);
								while ( $reader->moveToNextAttribute() ) {
									$attrs[] = sprintf(
										'%s="%s"',
										preg_replace( '/[^0-9a-z:]/i', '', $reader->name ),
										str_replace( array( '"', '\\' ), "", $reader->value )
									);
								}
							}
							if ( !empty( $attrs ) ) {
								$this->raw_header .= "<$node_name " . implode( " ", $attrs ) . ">\n";
								$this->tiny_header .= "<$node_name " . implode( " ", $attrs ) . ">\n";
							} else {
								$this->raw_header .= "<$node_name>\n";
								$this->tiny_header .= "<$node_name>\n";
							}
							$attrs = array();
							break;
						case XMLReader::END_ELEMENT:
							$writing_to = 1;
							$this->raw_footer .= "</$reader->name>\n";
							break;
					}
					if ( !$reader->read() ) {
						break 2;
					}
					break;
				case "item":
					// Write rss/channel/item elements into ur pseudo database file pointer
					// and update the in-memory index about where the data starts and how
					// many bytes long it is so that we know how to read it all back later.
					$inner_xml = $reader->readInnerXML();
					if ( $this->do_compress ) {
						$bytes = fwrite( $this->tmp, gzencode( "<item>\n" . $inner_xml . "</item>\n", 1 ) );
					} else {
						$bytes = fwrite( $this->tmp, "<item>\n" . $inner_xml . "</item>\n" );
					}
					$this->posts_found++;
					// I do this because it's memory efficient. I can index about 44.5 million posts
					// in ram this way using only 512MB
					fwrite( $this->posts_metadata, pack( 'QL', $this->tmp_bytes, $bytes ) );
					$this->tmp_bytes += $bytes;
					if ( !$reader->next() ) {
						break 2;
					}
					break;
				default:
					if ( !$found_channel ) {
						$this->raw_header .= $reader->readOuterXML() . "\n";
					} else {
						if ( $reader->nodeType === XMLReader::ELEMENT ) {
							if ( $writing_to === 0 ) {
								switch( $reader->name ) {
								case 'wp:tag':
								case 'wp:author':
								case 'wp:wp_author':
								case 'wp:term':
								case 'wp:category':
									$this->raw_header .= $reader->readOuterXML() . "\n";
									break;
								default:
									$xml = $reader->readOuterXML();
									$this->raw_header .= $xml . "\n";
									$this->tiny_header .= $xml . "\n";
									break;
								}
							} else {
								$this->raw_footer .= $reader->readOuterXML() . "\n";
							}
						}
					}
					if ( !$reader->next() ) {
						break 2;
					}
					break;
			}
		}
		fflush( $this->tmp );
		$reader->close();
		libxml_disable_entity_loader( $old_disable_entity_loader_value );

		// XMLReader may have come across errors caused by bad characters which don't show up on $reader->open().
		// We follow the example above and die here because the error handling in the WP_Import plugin which
		// calls this parser is weird, but we can't change it for fear of breaking things for other users of the plugin.
		// Dying also ensures the shutdown process deletes the temp file.
		$libxml_errors = libxml_get_errors();
		libxml_clear_errors();
		if ( ! empty ( $libxml_errors ) ) {
			return new WP_Error( 'xml_parse_error', __( 'We had trouble reading the import file. Please make sure it\'s valid XML.', 'wordpress-importer' ) );
		}

		$this->init_mini();

		// If the file isn't a valid import file, $this->mini_parsed_wxr can be a WP_Error
		if ( is_wp_error( $this->mini_parsed_wxr ) ) {
			return;
		}

		$tmp = tempnam( sys_get_temp_dir(), "import-" );
		$this->post_fp = fopen( $tmp, 'w+' );
		unlink( $tmp );
		fwrite( $this->post_fp, $this->tiny_header );
		$this->tiny_header_size = strlen( $this->tiny_header );
	}

	function init_mini() {
		// initialize our persistent mini WXR data structure. This is where
		// imports will read authors, tags, cats, etc from.
		$tmp = tempnam( sys_get_temp_dir(), 'import-mini-' );
		$fp = fopen( $tmp, 'w+' );
		unlink( $tmp );
		fwrite( $fp, $this->raw_header );
		$this->raw_header = '';
		fwrite( $fp, $this->raw_footer );
		fflush( $fp );
		fseek( $fp, 0, SEEK_SET );
		$parser = $this->get_wxr_parser_instance();
		$this->mini_parsed_wxr = $parser->parse( $fp );
		fclose( $fp );
	}

	public function get_wxr_parser_instance() {
		$parser_class = $this->wxr_parser_class;
		if ( class_exists( $parser_class ) ) {
			return new $parser_class();
		}
		else {
			// This is a precaution and fallback to the default parser if the override class doesn't exist
			return new WXR_Parser();
		}
	}

	/**
	 * Check if file is compressed with zlib/gzip.
	 *
	 * Inspired by https://stackoverflow.com/a/29268776/153310
	 *
	 * @param string $file_path The file to check for compression
	 *
	 * @return bool
	 */
	public static function is_file_compressed( $file_path ) {
		if ( ! is_file( $file_path ) ) {
			return false;
		}

		$handle = fopen( $file_path, 'r' );
		$header = fread( $handle, 8 );
		$is_compressed = 0 === strpos( $header, "\x1f" . "\x8b" . "\x08" );
		fclose( $handle );

		return $is_compressed;
	}

	// For ArrayAccess
	function offsetSet( $offset, $val ) {
		$this->mini_parsed_wxr[$offset] = $val;
	}

	function offsetExists( $offset ) {
		return isset( $this->mini_parsed_wxr[$offset] );
	}

	function offsetUnset( $offset ) {
		unset( $this->mini_parsed_wxr[$offset] );
	}

	function offsetGet( $offset ) {
		return $this->$offset;
	}

	// For Iterator

	/**
 	 * Provide the $post to the foreach( $posts as $post ) loop
 	 *
 	 * It should be noted that there is a real possibility that, if the process
 	 * dies or is killed between making the temp file and unlinking it that we
 	 * will leave orphaned bits of WXR on the filesystem. It's very difficult to
 	 * make PHP clean up after itself when it's been the victim of kill -9
 	 */
	function current() {
		// Create a real filesystem file to write a single rss/channel/item WXR into

		$index_offset = 12 * $this->current_post;
		fseek( $this->posts_metadata, $index_offset, SEEK_SET );
		$index = unpack( 'Qo/Ll', fread( $this->posts_metadata, 12 ) );

		// Hop to the appropriate starting byte in our database file.
		fseek( $this->tmp, $index['o'], SEEK_SET );
		ftruncate( $this->post_fp, $this->tiny_header_size );
		fseek( $this->post_fp, $this->tiny_header_size, SEEK_SET );

		// Compose the WXR from the header, bytes from the database for the item and footer
		if ( $this->do_compress ) {
			fwrite( $this->post_fp, gzdecode( fread( $this->tmp, $index['l'] ) ) );
		} else {
			fwrite( $this->post_fp, fread( $this->tmp, $index['l'] ) );
		}
		fwrite( $this->post_fp, $this->raw_footer );
		fflush( $this->post_fp );
		fseek( $this->post_fp, 0, SEEK_SET );

		// Create a normal WXR_Parser data structure from the file
		$parser = $this->get_wxr_parser_instance();
		$parsed = $parser->parse( $this->post_fp );

		// Clean up
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		// There is exactly one post in this WXR so we can just return that.
		// It's all we really wanted anyway.
		return $parsed["posts"][0];
	}

	function key() {
		return $this->current_post;
	}

	function next() {
		$this->current_post++;
	}

	function rewind() {
		$this->current_post = 0;
	}

	function valid() {
		$post_number = $this->current_post + 1;
		return ( $post_number > 0 && $post_number <= $this->posts_found );
	}

	// Magic Methods
	function __get( $key ) {
		switch ( $key ) {
			case 'posts':
				// $this['posts'] returns $this.
				// For use in the foreach( $data['posts'] as $post ) loop
				return $this;
			default:
				// Anything else we're passing through to our embedded empty WXR
				// that we keep around for just these purposes... eg: $data['authors']
				if ( isset( $this->mini_parsed_wxr[$key] ) ) {
					return $this->mini_parsed_wxr[$key];
				}
				return null;
		}
	}

	// For Countable
	function count() {
		return $this->posts_found;
	}
}
