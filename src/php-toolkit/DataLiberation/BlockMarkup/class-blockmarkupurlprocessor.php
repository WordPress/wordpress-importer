<?php

namespace WordPress\DataLiberation\BlockMarkup;

use Rowbot\URL\URL;
use WordPress\DataLiberation\URL\URLInTextProcessor;
use WordPress\DataLiberation\URL\WPURL;

use function WordPress\DataLiberation\URL\urldecode_n;

/**
 * Reports all the URLs in the imported post and enables rewriting them.
 */
class BlockMarkupUrlProcessor extends BlockMarkupProcessor {

	private $raw_url;
	/**
	 * @var URL
	 */
	private $parsed_url;
	private $base_url_string;
	private $base_url_object;
	private $url_in_text_processor;
	private $url_in_text_node_updated;

	/**
	 * The list of names of URL-related HTML attributes that may be available on
	 * the current token. They will be inspected by next_url_attribute().
	 *
	 * Possible values:
	 *
	 * - null: We haven't inspected any attribute yet.
	 * - array: The first element is the currently inspected attribute
	 *          and the rest of the list are elements yet to be inspected on
	 *          the upcoming next_url_attribute() call.
	 * - empty array: We've already inspected all the URL-related attributes.
	 *
	 * @var array<string>|null
	 */
	private $inspecting_html_attributes;

	public function __construct( $html, ?string $base_url_string = null ) {
		parent::__construct( $html );
		$this->base_url_string = $base_url_string;
		$this->base_url_object = $base_url_string ? WPURL::parse( $base_url_string ) : null;
	}

	public function get_updated_html(): string {
		if ( $this->url_in_text_node_updated ) {
			$this->set_modifiable_text( $this->url_in_text_processor->get_updated_text() );
			$this->url_in_text_node_updated = false;
		}

		return parent::get_updated_html();
	}

	public function get_raw_url() {
		return $this->raw_url;
	}

	public function get_parsed_url() {
		return $this->parsed_url;
	}

	public function next_token(): bool {
		$this->get_updated_html();

		$this->raw_url                    = null;
		$this->parsed_url                 = null;
		$this->inspecting_html_attributes = null;
		$this->url_in_text_processor      = null;
		// Do not reset url_in_text_node_updated – it's reset in get_updated_html() which
		// is called in parent::next_token().

		return parent::next_token();
	}

	public function next_url() {
		do {
			if ( $this->next_url_in_current_token() ) {
				return true;
			}
		} while ( false !== $this->next_token() );

		return false;
	}

	public function next_url_in_current_token() {
		$this->raw_url = null;
		switch ( parent::get_token_type() ) {
			case '#tag':
				return $this->next_url_attribute();
			case '#block-comment':
				return $this->next_url_block_attribute();
			case '#text':
				return $this->next_url_in_text_node();
			default:
				return false;
		}
	}

	private function next_url_in_text_node() {
		if ( '#text' !== $this->get_token_type() ) {
			return false;
		}

		if ( null === $this->url_in_text_processor ) {
			/*
			 * Use the base URL for URLs matched in text nodes. This is the only
			 * way to recognize a substring "WordPress.org" as a URL. We might
			 * get some false positives this way, e.g. in this string:
			 *
			 * > And that's how you build a theme.Now let's take a look at..."
			 *
			 * `theme.Now` would be recognized as a URL. It's up to the API consumer
			 * to filter out such false positives e.g. by checking the domain against
			 * a list of accepted domains, or the TLD against a list of public suffixes.
			 */
			$this->url_in_text_processor = new URLInTextProcessor( $this->get_modifiable_text(), $this->base_url_string );
		}

		while ( $this->url_in_text_processor->next_url() ) {
			$this->raw_url    = $this->url_in_text_processor->get_raw_url();
			$this->parsed_url = $this->url_in_text_processor->get_parsed_url();

			return true;
		}

		return false;
	}

	private function next_url_attribute() {
		$tag = $this->get_tag();

		if ( ! array_key_exists( $tag, self::URL_ATTRIBUTES ) ) {
			return false;
		}

		if ( null === $this->inspecting_html_attributes ) {
			/**
			 * Initialize the list on the first call to next_url_attribute()
			 * for the current token. The last element is the attribute we'll
			 * inspect in the while() loop below.
			 */
			$this->inspecting_html_attributes = self::URL_ATTRIBUTES[ $tag ];
		} else {
			/**
			 * Forget the attribute we've inspected on the previous call to
			 * next_url_attribute().
			 */
			array_pop( $this->inspecting_html_attributes );
		}

		while ( count( $this->inspecting_html_attributes ) > 0 ) {
			$attr      = $this->inspecting_html_attributes[ count( $this->inspecting_html_attributes ) - 1 ];
			$url_maybe = $this->get_attribute( $attr );
			if ( ! is_string( $url_maybe ) ) {
				array_pop( $this->inspecting_html_attributes );
				continue;
			}

			/*
			 * Use base URL to resolve known URI attributes as we are certain we're
			 * dealing with URI values.
			 * With a base URL, the string "plugins.php" in <a href="plugins.php"> will
			 * be correctly recognized as a URL.
			 * Without a base URL, this Processor would incorrectly skip it.
			 */
			$parsed_url = WPURL::parse( $url_maybe, $this->base_url_string );

			if ( false === $parsed_url ) {
				array_pop( $this->inspecting_html_attributes );
				continue;
			}
			$this->raw_url    = $url_maybe;
			$this->parsed_url = $parsed_url;

			return true;
		}

		return false;
	}

	private function next_url_block_attribute() {
		while ( $this->next_block_attribute() ) {
			$url_maybe = $this->get_block_attribute_value();
			/*
			 * Do not use base URL for block attributes. to avoid false positives.
			 * When a base URL is present, any word is a valid URL relative to the
			 * base URL.
			 * When a base URL is missing, the string must start with a protocol to
			 * be considered a URL.
			 */
			if ( is_string( $url_maybe ) ) {
				$parsed_url = WPURL::parse( $url_maybe );
				if ( false !== $parsed_url ) {
					$this->raw_url    = $url_maybe;
					$this->parsed_url = $parsed_url;

					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Replaces the currently matched URL with a new one.
	 *
	 * @param  string $raw_url  The raw URL.
	 * @param  URL    $parsed_url  The parsed version of the raw URL. It is required
	 *                             as $raw_url might be a relative URL pointing to a different
	 *                             host than this processor's base URL.
	 *
	 * @return bool True if the URL was set, false otherwise.
	 */
	public function set_url( $raw_url, $parsed_url ) {
		if ( null === $this->raw_url ) {
			return false;
		}
		$this->raw_url    = $raw_url;
		$this->parsed_url = $parsed_url;
		switch ( parent::get_token_type() ) {
			case '#tag':
				$attr = $this->get_inspected_attribute_name();
				if ( false === $attr ) {
					return false;
				}
				$this->set_attribute( $attr, $raw_url );

				return true;

			case '#block-comment':
				return $this->set_block_attribute_value( $raw_url );

			case '#text':
				if ( null === $this->url_in_text_processor ) {
					return false;
				}
				$this->url_in_text_node_updated = true;

				return $this->url_in_text_processor->set_raw_url( $raw_url );
		}
	}

	/**
	 * Rewrites the components of the currently matched URL from ones
	 * provided in $from_url to ones specified in $to_url.
	 *
	 * It preserves the relative nature of the matched URL.
	 *
	 * @TODO: Should this method live in this class? It's specific to the import process
	 *        and the URL rewriting logic and has knowledge about the quirks of detecting
	 *        relative URLs in text nodes. On the other hand, the detection is performed
	 *        by this WPURL_In_Text_Processor class so maybe the two do go hand in hand?
	 */
	public function replace_base_url( URL $to_url, ?URL $base_url = null ) {
		$updated_url = clone $this->get_parsed_url();

		$updated_url->hostname = $to_url->hostname;
		$updated_url->protocol = $to_url->protocol;
		$updated_url->port     = $to_url->port;

		// Update the pathname if needed.
		$from_url      = $this->get_parsed_url();
		$from_pathname = $from_url->pathname;
		$to_pathname   = $to_url->pathname;

		$base_url = $base_url ?? $this->base_url_object;
		if ( $base_url->pathname !== $to_pathname ) {
			$base_pathname_with_trailing_slash = rtrim( $base_url->pathname, '/' ) . '/';
			$decoded_matched_pathname          = urldecode_n(
				$from_pathname,
				strlen( $base_pathname_with_trailing_slash )
			);
			$to_pathname_with_trailing_slash   = rtrim( $to_pathname, '/' ) . '/';
			$remaining_pathname                =
				substr(
					$decoded_matched_pathname,
					strlen( $base_pathname_with_trailing_slash )
				);

			$updated_url->pathname = $to_pathname_with_trailing_slash . $remaining_pathname;
		}

		/*
		 * Stylistic choice – if the updated URL has no trailing slash,
		 * do not add it to the new URL. The WHATWG URL parser will
		 * add one automatically if the path is empty, so we have to
		 * explicitly remove it.
		 */
		$new_raw_url = $updated_url->toString();
		if (
			'/' !== $from_url->pathname[ strlen( $from_url->pathname ) - 1 ] &&
			'/' !== $from_url->pathname &&
			'' === $from_url->search &&
			'' === $from_url->hash
		) {
			$new_raw_url = rtrim( $new_raw_url, '/' );
		}
		if ( ! $new_raw_url ) {
			// @TODO: When does this happen? Let's add the test coverage and
			// doubly verify the logic.
			return false;
		}

		if ( ! $this->is_url_relative() ) {
			$this->set_url( $new_raw_url, $updated_url );

			return true;
		}

		$new_relative_url = $updated_url->pathname;
		if ( '' !== $updated_url->search ) {
			$new_relative_url .= $updated_url->search;
		}
		if ( '' !== $updated_url->hash ) {
			$new_relative_url .= $updated_url->hash;
		}

		$this->set_url( $new_relative_url, $updated_url );

		return true;
	}

	/**
	 * Returns true if the currently matched URL is relative.
	 *
	 * @return bool Whether the currently matched URL is relative.
	 */
	public function is_url_relative() {
		return (
			! WPURL::can_parse( $this->get_raw_url() ) &&
			// only absolute URLs are detected in text nodes.
			'#text' !== $this->get_token_type()
		);
	}

	/**
	 * Returns true if the currently matched URL is absolute.
	 *
	 * @return bool Whether the currently matched URL is absolute.
	 */
	public function is_url_absolute() {
		return WPURL::can_parse( $this->get_raw_url() );
	}

	public function get_inspected_attribute_name() {
		if ( '#tag' !== $this->get_token_type() ) {
			return false;
		}

		if ( null === $this->inspecting_html_attributes ) {
			return false;
		}

		if ( empty( $this->inspecting_html_attributes ) ) {
			return false;
		}

		return $this->inspecting_html_attributes[ count( $this->inspecting_html_attributes ) - 1 ];
	}


	/**
	 * A list of HTML attributes meant to contain URLs, as defined in the HTML specification.
	 * It includes some deprecated attributes like `lowsrc` and `highsrc` for the `IMG` element.
	 *
	 * See https://html.spec.whatwg.org/multipage/indices.html#attributes-1.
	 * See https://stackoverflow.com/questions/2725156/complete-list-of-html-tag-attributes-which-have-a-url-value.
	 */
	public const URL_ATTRIBUTES = array(
		'A'          => array( 'href' ),
		'APPLET'     => array( 'codebase', 'archive' ),
		'AREA'       => array( 'href' ),
		'AUDIO'      => array( 'src' ),
		'BASE'       => array( 'href' ),
		'BLOCKQUOTE' => array( 'cite' ),
		'BODY'       => array( 'background' ),
		'BUTTON'     => array( 'formaction' ),
		'COMMAND'    => array( 'icon' ),
		'DEL'        => array( 'cite' ),
		'EMBED'      => array( 'src' ),
		'FORM'       => array( 'action' ),
		'FRAME'      => array( 'longdesc', 'src' ),
		'HEAD'       => array( 'profile' ),
		'HTML'       => array( 'manifest' ),
		'IFRAME'     => array( 'longdesc', 'src' ),
		// SVG <image> element.
		'IMAGE'      => array( 'href' ),
		'IMG'        => array( 'longdesc', 'src', 'usemap', 'lowsrc', 'highsrc' ),
		'INPUT'      => array( 'src', 'usemap', 'formaction' ),
		'INS'        => array( 'cite' ),
		'LINK'       => array( 'href' ),
		'OBJECT'     => array( 'classid', 'codebase', 'data', 'usemap' ),
		'Q'          => array( 'cite' ),
		'SCRIPT'     => array( 'src' ),
		'SOURCE'     => array( 'src' ),
		'TRACK'      => array( 'src' ),
		'VIDEO'      => array( 'poster', 'src' ),
	);

	/**
	 * @TODO: Either explicitly support these attributes, or explicitly drop support for
	 *        handling their subsyntax. A generic URL matcher might be good enough.
	 */
	public const URL_ATTRIBUTES_WITH_SUBSYNTAX = array(
		'*'      => array( 'style' ), // background(), background-image().
		'APPLET' => array( 'archive' ),
		'IMG'    => array( 'srcset' ),
		'META'   => array( 'content' ),
		'SOURCE' => array( 'srcset' ),
		'OBJECT' => array( 'archive' ),
	);

	/**
	 * Also <style> and <script> tag content can contain URLs.
	 * <style> has specific syntax rules we can use for matching, but perhaps a generic matcher would be good enough?
	 *
	 * <style>
	 * #domID { background:url(https://mysite.com/wp-content/uploads/image.png) }
	 * </style>
	 *
	 * @TODO: Either explicitly support these tags, or explicitly drop support for
	 *         handling their subsyntax. A generic URL matcher might be good enough.
	 */
	public const URL_CONTAINING_TAGS_WITH_SUBSYNTAX = array(
		'STYLE',
		'SCRIPT',
	);
}
