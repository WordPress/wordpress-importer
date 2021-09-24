<?php
/**
 * PHPUnit bootstrap file for the WordPress Importer
 *
 * @package WordPress
 * @subpackage Importer
 */

use Yoast\WPTestUtils\WPIntegration;

require_once dirname( __DIR__ ) . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

$_tests_dir = WPIntegration\get_path_to_wp_test_dir();

define( 'WP_LOAD_IMPORTERS', true );

define( 'DIR_TESTDATA_WP_IMPORTER', dirname( __FILE__ ) . '/data' );

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php\n";
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . 'includes/functions.php';

/**
 * Manually load the importer
 */
function _manually_load_importer() {
	if ( ! class_exists( 'WP_Import' ) ) {
		require dirname( __DIR__ ) . '/src/wordpress-importer.php';
	}
}
tests_add_filter( 'plugins_loaded', '_manually_load_importer' );

WPIntegration\bootstrap_it();

require_once __DIR__ . '/tests/base.php';
