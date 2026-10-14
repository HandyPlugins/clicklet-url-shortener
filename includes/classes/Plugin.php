<?php
/**
 * Plugin bootstrap.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener;

use FastUrlShortener\CLI\Command;
use FastUrlShortener\CLI\LinkCommand;
use FastUrlShortener\Redirect\Router;
use FastUrlShortener\Tracking\Retention;

/**
 * Wires the plugin modules together.
 */
class Plugin {

	/**
	 * Register all modules.
	 *
	 * Runs on `plugins_loaded`: pluggable functions are available, while the
	 * theme and `init` have not run yet, so short links resolve before most of
	 * the request is built.
	 *
	 * @return void
	 */
	public function register() {
		$install = new Install();
		$install->register();
		$install->maybe_upgrade();

		( new Retention() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'fast-url-shortener', Command::class );
			\WP_CLI::add_command( 'fast-url-shortener link', LinkCommand::class );
		}

		Router::route();

		/**
		 * Fires once Fast URL Shortener has loaded.
		 *
		 * @hook fast_url_shortener_loaded
		 */
		do_action( 'fast_url_shortener_loaded' );
	}
}
