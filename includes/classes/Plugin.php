<?php
/**
 * Plugin bootstrap.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener;

use FastUrlShortener\Admin\DashboardWidget;
use FastUrlShortener\Admin\Editor;
use FastUrlShortener\Admin\Page;
use FastUrlShortener\CLI\Command;
use FastUrlShortener\CLI\LinkCommand;
use FastUrlShortener\Import\Importer;
use FastUrlShortener\Links\Groups;
use FastUrlShortener\Links\PostLinks;
use FastUrlShortener\Links\Repository;
use FastUrlShortener\Redirect\Router;
use FastUrlShortener\Rest\GroupsController;
use FastUrlShortener\Rest\ImportController;
use FastUrlShortener\Rest\LinksController;
use FastUrlShortener\Rest\PostLinksController;
use FastUrlShortener\Rest\SettingsController;
use FastUrlShortener\Rest\TransferController;
use FastUrlShortener\Tracking\ClickStore;
use FastUrlShortener\Tracking\Retention;
use FastUrlShortener\Transfer\CsvImporter;
use FastUrlShortener\Transfer\Exporter;

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
		Schema::register_meta_table();

		$install = new Install();
		$install->register();
		$install->maybe_upgrade();

		Router::route();

		$links        = new Repository();
		$clicks       = new ClickStore();
		$csv_importer = new CsvImporter( $links, $clicks );

		$post_links       = new PostLinks( $links );
		$links_controller = new LinksController( $links );

		( new Groups() )->register();
		$post_links->register();
		( new Retention() )->register();
		$csv_importer->register();
		$links_controller->register();
		( new PostLinksController( $post_links, $links_controller ) )->register();
		( new GroupsController() )->register();
		( new SettingsController() )->register();
		( new ImportController( new Importer( $links, $clicks ) ) )->register();
		( new TransferController( $csv_importer ) )->register();

		if ( is_admin() ) {
			( new Page() )->register();
			( new Editor() )->register();
			( new DashboardWidget( $links ) )->register();
			( new Exporter() )->register();
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
