<?php
/**
 * PHPUnit bootstrap.
 *
 * @package FastUrlShortener
 */

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

require dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$fus_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ? getenv( 'WP_PHPUNIT__DIR' ) : dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require_once $fus_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/fast-url-shortener.php';
	}
);

// The import transaction would commit the transaction each test runs in.
tests_add_filter( 'fast_url_shortener_import_use_transaction', '__return_false' );

require $fus_tests_dir . '/includes/bootstrap.php';

// The test installer only resets core tables; start every run with empty plugin tables.
FastUrlShortener\Schema::drop_tables();
FastUrlShortener\Schema::create_tables();
require __DIR__ . '/php/TestCase.php';
