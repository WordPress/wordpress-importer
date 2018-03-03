<?php
/**
 * WXR Parser that uses regular expressions
 *
 * @package WordPress
 * @subpackage Importer
 */

/**
 * WXR Parser that uses regular expressions.
 *
 * Fallback for installs without an XML parser.
 *
 * @package WordPress
 * @subpackage Importer
 */
class WXR_Parser_Regex {
	/**
	 * List of authors to import.
	 *
	 * @var array
	 */
	public $authors = array();

	/**
	 * List of posts to import.
	 *
	 * @var array
	 */
	public $posts = array();

	/**
	 * List of categories to import.
	 *
	 * @var array
	 */
	public $categories = array();

	/**
	 * List of tags to import.
	 *
	 * @var array
	 */
	public $tags = array();

	/**
	 * List of terms to import.
	 *
	 * @var array
	 */
	public $terms = array();

	/**
	 * Base URL.
	 *
	 * @var string
	 */
	public $base_url = '';

	/**
	 * Initialise WXR_Parser_Regex object.
	 */
	public function __construct() {
		$this->has_gzip = is_callable( 'gzopen' );
	}

	/**
	 * Parse a WXR file.
	 *
	 * @param string $file Path to WXR file for parsing.
	 * @return array|WP_Error Information gathered from the WXR file.
	 */
	public function parse( $file ) {
		$wxr_version  = false;
		$in_multiline = false;

		$multiline_content = '';

		$multiline_tags = array(
			'item'        => array( 'posts', array( $this, 'process_post' ) ),
			'wp:category' => array( 'categories', array( $this, 'process_category' ) ),
			'wp:tag'      => array( 'tags', array( $this, 'process_tag' ) ),
			'wp:term'     => array( 'terms', array( $this, 'process_term' ) ),
		);

		$fp = $this->fopen( $file, 'r' );
		if ( $fp ) {
			while ( ! $this->feof( $fp ) ) {
				$importline = rtrim( $this->fgets( $fp ) );

				if ( ! $wxr_version && preg_match( '|<wp:wxr_version>(\d+\.\d+)</wp:wxr_version>|', $importline, $version ) ) {
					$wxr_version = $version[1];
				}

				if ( false !== strpos( $importline, '<wp:base_site_url>' ) ) {
					preg_match( '|<wp:base_site_url>(.*?)</wp:base_site_url>|is', $importline, $url );
					$this->base_url = $url[1];
					continue;
				}

				if ( false !== strpos( $importline, '<wp:author>' ) ) {
					preg_match( '|<wp:author>(.*?)</wp:author>|is', $importline, $author );
					$a                                   = $this->process_author( $author[1] );
					$this->authors[ $a['author_login'] ] = $a;
					continue;
				}

				foreach ( $multiline_tags as $tag => $handler ) {
					$open_pos  = strpos( $importline, "<$tag>" );
					$close_pos = strpos( $importline, "</$tag>" );
					// Handle multi-line tags on a singular line.
					if ( preg_match( '|<' . $tag . '>(.*?)</' . $tag . '>|is', $importline, $matches ) ) {
						$this->{$handler[0]}[] = call_user_func( $handler[1], $matches[1] );
					} elseif ( false !== $open_pos ) {
						// Take note of any content after the opening tag.
						$multiline_content = trim( substr( $importline, $open_pos + strlen( $tag ) + 2 ) );

						// We don't want to have this line added to `$is_multiline` below.
						$importline   = '';
						$in_multiline = $tag;
					} elseif ( false !== $close_pos ) {
						$in_multiline       = false;
						$multiline_content .= trim( substr( $importline, 0, $close_pos ) );

						$this->{$handler[0]}[] = call_user_func( $handler[1], $multiline_content );
					}
				}

				if ( $in_multiline && $importline ) {
					$multiline_content .= $importline . "\n";
				}
			}

			$this->fclose( $fp );
		}

		if ( ! $wxr_version ) {
			return new WP_Error( 'WXR_parse_error', __( 'This does not appear to be a WXR file, missing/invalid WXR version number', 'wordpress-importer' ) );
		}

		return array(
			'authors'    => $this->authors,
			'posts'      => $this->posts,
			'categories' => $this->categories,
			'tags'       => $this->tags,
			'terms'      => $this->terms,
			'base_url'   => $this->base_url,
			'version'    => $wxr_version,
		);
	}

	/**
	 * Get term.
	 *
	 * @param string $string String to process.
	 * @param string $tag    Name of term to find.
	 * @return null|string|string[]
	 */
	public function get_tag( $string, $tag ) {
		preg_match( "|<$tag.*?>(.*?)</$tag>|is", $string, $return );
		if ( isset( $return[1] ) ) {
			if ( substr( $return[1], 0, 9 ) === '<![CDATA[' ) {
				if ( strpos( $return[1], ']]]]><![CDATA[>' ) !== false ) {
					preg_match_all( '|<!\[CDATA\[(.*?)\]\]>|s', $return[1], $matches );
					$return = '';
					foreach ( $matches[1] as $match ) {
						$return .= $match;
					}
				} else {
					$return = preg_replace( '|^<!\[CDATA\[(.*)\]\]>$|s', '$1', $return[1] );
				}
			} else {
				$return = $return[1];
			}
		} else {
			$return = '';
		}
		return $return;
	}

	/**
	 * Process category.
	 *
	 * @param string $c Category element.
	 * @return array Category data.
	 */
	public function process_category( $c ) {
		return array(
			'term_id'              => $this->get_tag( $c, 'wp:term_id' ),
			'cat_name'             => $this->get_tag( $c, 'wp:cat_name' ),
			'category_nicename'    => $this->get_tag( $c, 'wp:category_nicename' ),
			'category_parent'      => $this->get_tag( $c, 'wp:category_parent' ),
			'category_description' => $this->get_tag( $c, 'wp:category_description' ),
		);
	}

	/**
	 * Process tag.
	 *
	 * @param string $t Tag element.
	 * @return array Tag data.
	 */
	public function process_tag( $t ) {
		return array(
			'term_id'         => $this->get_tag( $t, 'wp:term_id' ),
			'tag_name'        => $this->get_tag( $t, 'wp:tag_name' ),
			'tag_slug'        => $this->get_tag( $t, 'wp:tag_slug' ),
			'tag_description' => $this->get_tag( $t, 'wp:tag_description' ),
		);
	}

	/**
	 * Process term.
	 *
	 * @param string $t Term element.
	 * @return array Term data.
	 */
	public function process_term( $t ) {
		return array(
			'term_id'          => $this->get_tag( $t, 'wp:term_id' ),
			'term_taxonomy'    => $this->get_tag( $t, 'wp:term_taxonomy' ),
			'slug'             => $this->get_tag( $t, 'wp:term_slug' ),
			'term_parent'      => $this->get_tag( $t, 'wp:term_parent' ),
			'term_name'        => $this->get_tag( $t, 'wp:term_name' ),
			'term_description' => $this->get_tag( $t, 'wp:term_description' ),
		);
	}

	/**
	 * Process author.
	 *
	 * @param string $a Author element.
	 * @return array Author data.
	 */
	public function process_author( $a ) {
		return array(
			'author_id'           => $this->get_tag( $a, 'wp:author_id' ),
			'author_login'        => $this->get_tag( $a, 'wp:author_login' ),
			'author_email'        => $this->get_tag( $a, 'wp:author_email' ),
			'author_display_name' => $this->get_tag( $a, 'wp:author_display_name' ),
			'author_first_name'   => $this->get_tag( $a, 'wp:author_first_name' ),
			'author_last_name'    => $this->get_tag( $a, 'wp:author_last_name' ),
		);
	}

	/**
	 * Process post.
	 *
	 * @param string $post Post element.
	 * @return array Post data.
	 */
	public function process_post( $post ) {
		$post_id        = $this->get_tag( $post, 'wp:post_id' );
		$post_title     = $this->get_tag( $post, 'title' );
		$post_date      = $this->get_tag( $post, 'wp:post_date' );
		$post_date_gmt  = $this->get_tag( $post, 'wp:post_date_gmt' );
		$comment_status = $this->get_tag( $post, 'wp:comment_status' );
		$ping_status    = $this->get_tag( $post, 'wp:ping_status' );
		$status         = $this->get_tag( $post, 'wp:status' );
		$post_name      = $this->get_tag( $post, 'wp:post_name' );
		$post_parent    = $this->get_tag( $post, 'wp:post_parent' );
		$menu_order     = $this->get_tag( $post, 'wp:menu_order' );
		$post_type      = $this->get_tag( $post, 'wp:post_type' );
		$post_password  = $this->get_tag( $post, 'wp:post_password' );
		$is_sticky      = $this->get_tag( $post, 'wp:is_sticky' );
		$guid           = $this->get_tag( $post, 'guid' );
		$post_author    = $this->get_tag( $post, 'dc:creator' );

		$post_excerpt = $this->get_tag( $post, 'excerpt:encoded' );
		$post_excerpt = preg_replace_callback( '|<(/?[A-Z]+)|', array( &$this, 'normalize_tag' ), $post_excerpt );
		$post_excerpt = str_replace( '<br>', '<br />', $post_excerpt );
		$post_excerpt = str_replace( '<hr>', '<hr />', $post_excerpt );

		$post_content = $this->get_tag( $post, 'content:encoded' );
		$post_content = preg_replace_callback( '|<(/?[A-Z]+)|', array( &$this, 'normalize_tag' ), $post_content );
		$post_content = str_replace( '<br>', '<br />', $post_content );
		$post_content = str_replace( '<hr>', '<hr />', $post_content );

		$postdata = compact(
			'post_id', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_excerpt',
			'post_title', 'status', 'post_name', 'comment_status', 'ping_status', 'guid', 'post_parent',
			'menu_order', 'post_type', 'post_password', 'is_sticky'
		);

		$attachment_url = $this->get_tag( $post, 'wp:attachment_url' );
		if ( $attachment_url ) {
			$postdata['attachment_url'] = $attachment_url;
		}

		preg_match_all( '|<category domain="([^"]+?)" nicename="([^"]+?)">(.+?)</category>|is', $post, $terms, PREG_SET_ORDER );
		foreach ( $terms as $t ) {
			$post_terms[] = array(
				'slug'   => $t[2],
				'domain' => $t[1],
				'name'   => str_replace( array( '<![CDATA[', ']]>' ), '', $t[3] ),
			);
		}
		if ( ! empty( $post_terms ) ) {
			$postdata['terms'] = $post_terms;
		}

		preg_match_all( '|<wp:comment>(.+?)</wp:comment>|is', $post, $comments );
		$comments = $comments[1];
		if ( $comments ) {
			foreach ( $comments as $comment ) {
				preg_match_all( '|<wp:commentmeta>(.+?)</wp:commentmeta>|is', $comment, $commentmeta );
				$commentmeta = $commentmeta[1];
				$c_meta      = array();
				foreach ( $commentmeta as $m ) {
					$c_meta[] = array(
						'key'   => $this->get_tag( $m, 'wp:meta_key' ),
						'value' => $this->get_tag( $m, 'wp:meta_value' ),
					);
				}

				$post_comments[] = array(
					'comment_id'           => $this->get_tag( $comment, 'wp:comment_id' ),
					'comment_author'       => $this->get_tag( $comment, 'wp:comment_author' ),
					'comment_author_email' => $this->get_tag( $comment, 'wp:comment_author_email' ),
					'comment_author_IP'    => $this->get_tag( $comment, 'wp:comment_author_IP' ),
					'comment_author_url'   => $this->get_tag( $comment, 'wp:comment_author_url' ),
					'comment_date'         => $this->get_tag( $comment, 'wp:comment_date' ),
					'comment_date_gmt'     => $this->get_tag( $comment, 'wp:comment_date_gmt' ),
					'comment_content'      => $this->get_tag( $comment, 'wp:comment_content' ),
					'comment_approved'     => $this->get_tag( $comment, 'wp:comment_approved' ),
					'comment_type'         => $this->get_tag( $comment, 'wp:comment_type' ),
					'comment_parent'       => $this->get_tag( $comment, 'wp:comment_parent' ),
					'comment_user_id'      => $this->get_tag( $comment, 'wp:comment_user_id' ),
					'commentmeta'          => $c_meta,
				);
			}
		}
		if ( ! empty( $post_comments ) ) {
			$postdata['comments'] = $post_comments;
		}

		preg_match_all( '|<wp:postmeta>(.+?)</wp:postmeta>|is', $post, $postmeta );
		$postmeta = $postmeta[1];
		if ( $postmeta ) {
			foreach ( $postmeta as $p ) {
				$post_postmeta[] = array(
					'key'   => $this->get_tag( $p, 'wp:meta_key' ),
					'value' => $this->get_tag( $p, 'wp:meta_value' ),
				);
			}
		}
		if ( ! empty( $post_postmeta ) ) {
			$postdata['postmeta'] = $post_postmeta;
		}

		return $postdata;
	}

	/**
	 * Normalize tag to lowercase.
	 *
	 * @param array $matches Regular expression matches.
	 * @return string Partial opening tag.
	 */
	protected function normalize_tag( $matches ) {
		return '<' . strtolower( $matches[1] );
	}

	/**
	 * Open a file.
	 *
	 * @param string $filename File name to open.
	 * @param string $mode     The mode parameter specifies the type of access required for the stream.
	 * @return bool|resource
	 */
	public function fopen( $filename, $mode = 'r' ) {
		if ( $this->has_gzip ) {
			return gzopen( $filename, $mode );
		}

		return fopen( $filename, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
	}

	/**
	 * Tests for end-of-file on a file pointer.
	 *
	 * @param resource $fp The file pointer must be valid, and must point to a file successfully opened by
	 *                     `fopen()` or `fsockopen()` (and not yet closed by fclose()) (or gzip equivalents).
	 * @return bool|int
	 */
	public function feof( $fp ) {
		if ( $this->has_gzip ) {
			return gzeof( $fp );
		}

		return feof( $fp );
	}

	/**
	 * Gets line from file pointer.
	 *
	 * @param resource $fp  The file pointer must be valid, and must point to a file successfully opened by
	 *                      `fopen()` or `fsockopen()` (and not yet closed by fclose()) (or gzip equivalents).
	 * @param int      $len Optional. Reading ends when length - 1 bytes have been read, on a newline (which
	 *                      is included in the return value), or on EOF (whichever comes first). If no length
	 *                      is specified, it will keep reading from the stream until it reaches the end of the line.
	 *                      Default is 8192.
	 * @return bool|string
	 */
	public function fgets( $fp, $len = 8192 ) {
		if ( $this->has_gzip ) {
			return gzgets( $fp, $len );
		}

		return fgets( $fp, $len );
	}

	/**
	 * Closes an open file pointer.
	 *
	 * @param resource $fp The file pointer must be valid, and must point to a file successfully opened by
	 *                     `fopen()` or `fsockopen()` (and not yet closed by fclose()) (or gzip equivalents).
	 * @return bool
	 */
	public function fclose( $fp ) {
		if ( $this->has_gzip ) {
			return gzclose( $fp );
		}

		return fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
	}
}
