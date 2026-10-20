<?php
/**
 * PSR-4 autoloader.
 *
 * The plugin has no runtime Composer dependencies, so a tiny autoloader keeps
 * the release package free of a vendor directory. It is also loaded by the
 * fast path drop-in, before the plugin itself, so it must be idempotent.
 *
 * @package Clicklet
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'CLICKLET_PATH' ) ) {
	define( 'CLICKLET_PATH', dirname( __DIR__ ) . '/' );
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Clicklet\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/classes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
