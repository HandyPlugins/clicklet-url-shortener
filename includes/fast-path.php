<?php
/**
 * Fast path entry point, loaded by the must-use drop-in.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener;

use FastUrlShortener\Redirect\FastPath;
use FastUrlShortener\Redirect\Router;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/autoload.php';

if ( FastPath::is_plugin_active() ) {
	add_action( 'muplugins_loaded', [ Router::class, 'route' ] );
}
