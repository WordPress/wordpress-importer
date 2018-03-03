<?php
/**
 * WordPress Importer class for managing parsing of WordPress eXtended RSS files
 *
 * @package WordPress
 * @subpackage Importer
 */

/**
 * WordPress Importer class for managing parsing of WordPress eXtended RSS files.
 *
 * Hard-coded into WordPress Core, so cannot be renamed.
 *
 * @package WordPress
 * @subpackage Importer
 */
class WXR_Parser {
	/**
	 * Parse a WXR file.
	 *
	 * @param string $file Path to WXR file for parsing.
	 * @return array Information gathered from the WXR file.
	 */
	public function parse( $file ) {
		// Attempt to use proper XML parsers first.
		if ( extension_loaded( 'simplexml' ) ) {
			$parser = new WXR_Parser_SimpleXML();
			$result = $parser->parse( $file );

			// If SimpleXML succeeds or this is an invalid WXR file then return the results.
			if ( ! is_wp_error( $result ) || 'SimpleXML_parse_error' !== $result->get_error_code() ) {
				return $result;
			}
		} elseif ( extension_loaded( 'xml' ) ) {
			$parser = new WXR_Parser_XML();
			$result = $parser->parse( $file );

			// If XMLParser succeeds or this is an invalid WXR file then return the results.
			if ( ! is_wp_error( $result ) || 'XML_parse_error' !== $result->get_error_code() ) {
				return $result;
			}
		}

		// We have a malformed XML file, so display the error and fallthrough to regex.
		if ( isset( $result ) && defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
			echo '<pre>';
			if ( 'SimpleXML_parse_error' === $result->get_error_code() ) {
				foreach ( $result->get_error_data() as $error ) {
					echo $error->line . ':' . $error->column . ' ' . esc_html( $error->message ) . "\n"; //phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
				}
			} elseif ( 'XML_parse_error' === $result->get_error_code() ) {
				$error = $result->get_error_data();
				echo $error[0] . ':' . $error[1] . ' ' . esc_html( $error[2] ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
			}
			echo '</pre>';
			echo '<p><strong>' . esc_html__( 'There was an error when reading this WXR file', 'wordpress-importer' ) . '</strong><br />';
			echo esc_html__( 'Details are shown above. The importer will now try again with a different parser...', 'wordpress-importer' ) . '</p>';
		}

		// Use regular expressions if nothing else available or this is bad XML.
		$parser = new WXR_Parser_Regex();
		return $parser->parse( $file );
	}
}
