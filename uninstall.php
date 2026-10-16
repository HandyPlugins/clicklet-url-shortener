<?php
/**
 * Uninstall entry point. See FastUrlShortener\Uninstall.
 *
 * @package FastUrlShortener
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/autoload.php';

FastUrlShortener\Uninstall::run();
