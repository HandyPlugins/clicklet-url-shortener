<?php
/**
 * PHPUnit bootstrap.
 *
 * @package Clicklet
 */

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

require dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$clicklet_tests_dir = getenv( 'WP_PHPUNIT__DIR' ) ? getenv( 'WP_PHPUNIT__DIR' ) : dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

require_once $clicklet_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/clicklet-url-shortener.php';
	}
);

// The import transaction would commit the transaction each test runs in.
tests_add_filter( 'clicklet_import_use_transaction', '__return_false' );

require $clicklet_tests_dir . '/includes/bootstrap.php';

// The test installer only resets core tables; start every run with empty plugin tables.
Clicklet\Schema::drop_tables();
Clicklet\Schema::create_tables();
require __DIR__ . '/php/TestCase.php';
require __DIR__ . '/php/Doubles/RecordingRouter.php';
