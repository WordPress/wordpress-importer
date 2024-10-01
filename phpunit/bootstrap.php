<?php
/**
 * PHPUnit bootstrap file for the WordPress Importer
 *
 * @package Sample_Plugin
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

// Check if we're installed in a src checkout.
$pos = stripos( __FILE__, '/src/wp-content/plugins/' );
if ( ! $_tests_dir && false !== $pos ) {
	$_tests_dir = substr( __FILE__, 0, $pos ) . '/tests/phpunit/';
}

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php\n";
	exit( 1 );
}

define( 'WP_LOAD_IMPORTERS', true );

define( 'DIR_TESTDATA_WP_IMPORTER', __DIR__ . '/data' );

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the importer
 */
function _manually_load_importer() {
	if ( ! class_exists( 'WP_Import' ) ) {
		require dirname( __DIR__ ) . '/src/wordpress-importer.php';
	}
}
tests_add_filter( 'plugins_loaded', '_manually_load_importer' );

// Include the PHPUnit Polyfills autoloader.
require dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';
