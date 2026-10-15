<?php
/**
 * WordPress test suite configuration.
 *
 * Defaults fit a local install where this plugin lives in wp-content/plugins.
 * Override with environment variables, e.g. in CI.
 *
 * @package FastUrlShortener
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test suite configuration constants.

$fus_env = static function ( $name, $fallback ) {
	$value = getenv( $name );

	return false === $value || '' === $value ? $fallback : $value;
};

define( 'ABSPATH', rtrim( $fus_env( 'WP_CORE_DIR', dirname( __DIR__, 4 ) ), '/' ) . '/' );

define( 'DB_NAME', $fus_env( 'WP_TESTS_DB_NAME', 'local_tests' ) );
define( 'DB_USER', $fus_env( 'WP_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', $fus_env( 'WP_TESTS_DB_PASSWORD', 'root' ) );
define( 'DB_HOST', $fus_env( 'WP_TESTS_DB_HOST', 'localhost' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
// The installer runs in a separate PHP process: quote the binary (paths may contain spaces) and reuse the loaded php.ini.
define( 'WP_PHP_BINARY', escapeshellarg( PHP_BINARY ) . ( php_ini_loaded_file() ? ' -c ' . escapeshellarg( php_ini_loaded_file() ) : '' ) );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

// Keep the fast path drop-in out of the real mu-plugins folder.
define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/fast-url-shortener-tests/mu-plugins' );
