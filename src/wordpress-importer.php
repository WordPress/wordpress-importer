<?php
/*
 * @wordpress-plugin
 * Plugin Name:       WordPress Importer
 * Plugin URI:        https://wordpress.org/plugins/wordpress-importer/
 * Description:       Import posts, pages, comments, custom fields, categories, tags and more from a WordPress export file.
 * Author:            wordpressdotorg
 * Author URI:        https://wordpress.org/
 * Version:           0.9.2
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Text Domain:       wordpress-importer
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

// Admin menu and scripts should always be available
add_action( 'admin_menu', 'wordpress_importer_add_admin_page' );
add_action( 'admin_enqueue_scripts', 'enqueue_wordpress_importer_scripts' );

function wordpress_importer_add_admin_page() {
	$hook = add_submenu_page(
		'tools.php',
		__( 'Import Results', 'wordpress-importer' ),
		__( 'Import Results', 'wordpress-importer' ),
		'manage_options',
		'wordpress-importer-results',
		'wordpress_importer_results_page'
	);
	
	// Debug: Log the hook to see if it's being registered
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'WordPress Importer admin page hook: ' . $hook );
	}
}

function wordpress_importer_results_page() {
	// Debug: Log when the page is being called
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'WordPress Importer results page called' );
	}
	
	// Check if we can access the admin page file
	$admin_page_file = __DIR__ . '/admin-page.php';
	if ( ! file_exists( $admin_page_file ) ) {
		wp_die( __( 'Admin page file not found.', 'wordpress-importer' ) );
	}
	
	require_once $admin_page_file;
}

function enqueue_wordpress_importer_scripts( $hook_suffix ) {
	wp_register_script_module(
		'@wordpress-importer/import-screen',
		plugin_dir_url( __FILE__ ) . 'import-screen.js',
		array( '@wordpress/interactivity', '@wordpress/interactivity-router', 'wp-api-fetch' )
	);
	wp_enqueue_script( 'wp-api-fetch' );
	wp_enqueue_script_module(
		'@wordpress-importer/import-screen',
		plugin_dir_url( __FILE__ ) . 'import-screen.js',
		array( '@wordpress/interactivity', '@wordpress/interactivity-router' )
	);

	// Enqueue styles and scripts for the results page
	if ( $hook_suffix === 'tools_page_wordpress-importer-results' ) {
		wp_enqueue_style(
			'wordpress-importer-admin',
			plugin_dir_url( __FILE__ ) . 'admin-page.css',
			array(),
			'0.9.0'
		);
		
		// Ensure interactivity API is available for results page
		wp_enqueue_script_module( '@wordpress/interactivity' );
		
		wp_enqueue_script_module(
			'@wordpress-importer/results',
			plugin_dir_url( __FILE__ ) . 'results.js',
			array( '@wordpress/interactivity' ),
			'0.9.0'
		);
	}
}

if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
	return;
}

/** Display verbose errors */
if ( ! defined( 'IMPORT_DEBUG' ) ) {
	define( 'IMPORT_DEBUG', WP_DEBUG );
}

/** WordPress Import Administration API */
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) ) {
		require $class_wp_importer;
	}
}

/** Functions missing in older WordPress versions. */
require_once __DIR__ . '/compat.php';

if ( ! class_exists( 'WordPress\XML\XMLProcessor' ) ) {
	require_once __DIR__ . '/php-toolkit/load.php';
}

/** WXR_Parser class */
require_once __DIR__ . '/parsers/class-wxr-parser.php';

/** WXR_Parser_SimpleXML class */
require_once __DIR__ . '/parsers/class-wxr-parser-simplexml.php';

/** WXR_Parser_XML class */
require_once __DIR__ . '/parsers/class-wxr-parser-xml.php';

/**
 * WXR_Parser_Regex class
 * @deprecated 0.9.0 Use WXR_Parser_XML_Processor instead. The WXR_Parser_Regex class
 *             is no longer used by the importer or maintained with bug fixes. The only
 *             reason it is still included in the codebase is for backwards compatibility
 *             with plugins that directly reference it.
 */
require_once __DIR__ . '/parsers/class-wxr-parser-regex.php';

/** WXR_Parser_XML_Processor class */
require_once __DIR__ . '/parsers/class-wxr-parser-xml-processor.php';

/** WP_Import class */
require_once __DIR__ . '/class-wp-import.php';

function wordpress_importer_init() {
	load_plugin_textdomain( 'wordpress-importer' );

	/**
	 * WordPress Importer object for registering the import callback
	 * @global WP_Import $wp_import
	 */
	$GLOBALS['wp_import'] = new WP_Import();
	// phpcs:ignore WordPress.WP.CapitalPDangit
	register_importer( 'wordpress', 'WordPress', __( 'Import <strong>posts, pages, comments, custom fields, categories, and tags</strong> from a WordPress export file.', 'wordpress-importer' ), array( $GLOBALS['wp_import'], 'dispatch' ) );
}
add_action( 'admin_init', 'wordpress_importer_init' );

add_action( 'rest_api_init', function () {
	register_rest_route( 'wordpress-importer/v1', '/state', array(
		'methods' => 'GET',
		'callback' => function () {
			return WP_Import::get_state();
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );

	register_rest_route( 'wordpress-importer/v1', '/continue', array(
		'methods' => 'GET',
		'callback' => function () {
			$state = WP_Import::get_state();

			if ( ! $state['running'] ) {
				return new WP_Error( 'not_running', __( 'Import is not running.', 'wordpress-importer' ), array( 'status' => 400 ) );
			}

			$importer = new WP_Import();
			$importer->continue_import();
		},
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );
} );
