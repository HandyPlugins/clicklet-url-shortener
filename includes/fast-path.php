<?php
/**
 * Fast path entry point, loaded by the must-use drop-in.
 *
 * @package Clicklet
 */

namespace Clicklet;

use Clicklet\Redirect\FastPath;
use Clicklet\Redirect\Router;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/autoload.php';

if ( FastPath::is_plugin_active() ) {
	Schema::register_meta_table();
	add_action( 'muplugins_loaded', [ Router::class, 'route' ] );
}
