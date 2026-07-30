<?php
/**
 * Integration test bootstrap.
 *
 * @package Inline_Woo_AI
 */

$plugin_root     = dirname( __DIR__ );
$autoload_path   = $plugin_root . '/vendor/autoload.php';
$polyfills_path  = $plugin_root . '/vendor/yoast/phpunit-polyfills';
$_tests_dir      = getenv( 'WP_TESTS_DIR' );

if ( ! file_exists( $autoload_path ) ) {
	fwrite( STDERR, "Composer dependencies are missing. Run composer install before the tests.\n" );
	exit( 1 );
}

require_once $autoload_path;

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_path );
}

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress test suite. Run the tests through wp-env.\n" );
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require_once dirname( __DIR__ ) . '/includes/class-ai-service.php';
		require_once dirname( __DIR__ ) . '/includes/class-rest-controller.php';
		require_once __DIR__ . '/includes/class-test-prompt-builder.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
