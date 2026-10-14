<?php
/**
 * Plugin Name:       Fast URL Shortener
 * Plugin URI:        https://handyplugins.co/fast-url-shortener/
 * Description:       Fast, self-hosted URL shortener with privacy-friendly click tracking.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            HandyPlugins
 * Author URI:        https://handyplugins.co/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fast-url-shortener
 *
 * @package FastUrlShortener
 */

/*
 * Copyright (c) 2026 HandyPlugins
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */

namespace FastUrlShortener;

defined( 'ABSPATH' ) || exit;

define( 'FAST_URL_SHORTENER_VERSION', '0.1.0' );
define( 'FAST_URL_SHORTENER_FILE', __FILE__ );

require_once __DIR__ . '/includes/autoload.php';

register_activation_hook( __FILE__, [ Install::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Install::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function () {
		( new Plugin() )->register();
	},
	0
);
