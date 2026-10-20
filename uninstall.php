<?php
/**
 * Uninstall entry point. See Clicklet\Uninstall.
 *
 * @package Clicklet
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/autoload.php';

Clicklet\Uninstall::run();
