<?php
/**
 * Plugin bootstrap.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener;

use FastUrlShortener\Admin\Page;
use FastUrlShortener\CLI\Command;
use FastUrlShortener\CLI\LinkCommand;
use FastUrlShortener\Import\Importer;
use FastUrlShortener\Links\Repository;
use FastUrlShortener\Redirect\Router;
use FastUrlShortener\Rest\ImportController;
use FastUrlShortener\Rest\LinksController;
use FastUrlShortener\Rest\SettingsController;
use FastUrlShortener\Tracking\ClickStore;
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

		Router::route();

		$links = new Repository();

		( new Retention() )->register();
		( new LinksController( $links ) )->register();
		( new SettingsController() )->register();
		( new ImportController( new Importer( $links, new ClickStore() ) ) )->register();

		if ( is_admin() ) {
			( new Page() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'fast-url-shortener', Command::class );
			\WP_CLI::add_command( 'fast-url-shortener link', LinkCommand::class );
		}

		/**
		 * Fires once Fast URL Shortener has loaded.
		 *
		 * @hook fast_url_shortener_loaded
		 */
		do_action( 'fast_url_shortener_loaded' );
	}
}
