<?php
/**
 * WordPress Importer
 *
 * @package WordPress
 * @subpackage Importer
 *
 * @wordpress-plugin
 * Plugin Name: WordPress Importer
 * Plugin URI: https://wordpress.org/plugins/wordpress-importer/
 * Description: Import posts, pages, comments, custom fields, categories, tags and more from a WordPress export file.
 * Author: wordpressdotorg
 * Author URI: https://wordpress.org/
 * Version: 0.6.5-alpha
 * Text Domain: wordpress-importer
 * License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
	return;
}

// Display verbose errors.
define( 'IMPORT_DEBUG', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

// Load Importer API.
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$wordpress_importer_import_class = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $wordpress_importer_import_class ) ) {
		require $wordpress_importer_import_class;
	}
}

// Include WXR file parsers.
require dirname( __FILE__ ) . '/class-wxr-parser.php';
require dirname( __FILE__ ) . '/class-wxr-parser-regex.php';
require dirname( __FILE__ ) . '/class-wxr-parser-simplexml.php';
require dirname( __FILE__ ) . '/class-wxr-parser-xml.php';

if ( class_exists( 'WP_Importer' ) ) {
	require dirname( __FILE__ ) . '/class-wp-import.php';
}

add_action( 'admin_init', 'wordpress_importer_init' );
/**
 * Initialize plugin.
 *
 * @global WP_Import $wp_import WordPress Importer object for registering the import callback.
 */
function wordpress_importer_init() {
	load_plugin_textdomain( 'wordpress-importer' );

	$GLOBALS['wp_import'] = new WP_Import();
	register_importer(
		'wordpress', // phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled
		'WordPress',
		__( 'Import <strong>posts, pages, comments, custom fields, categories, and tags</strong> from a WordPress export file.', 'wordpress-importer' ),
		array( $GLOBALS['wp_import'], 'dispatch' )
	);
}
