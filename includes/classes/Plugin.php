<?php
/**
 * Plugin bootstrap.
 *
 * @package Clicklet
 */

namespace Clicklet;

use Clicklet\Admin\DashboardWidget;
use Clicklet\Admin\Editor;
use Clicklet\Admin\Page;
use Clicklet\CLI\Command;
use Clicklet\CLI\LinkCommand;
use Clicklet\Import\Importer;
use Clicklet\Links\Groups;
use Clicklet\Links\PostLinks;
use Clicklet\Links\Repository;
use Clicklet\Redirect\Router;
use Clicklet\Rest\GroupsController;
use Clicklet\Rest\ImportController;
use Clicklet\Rest\LinksController;
use Clicklet\Rest\PostLinksController;
use Clicklet\Rest\SettingsController;
use Clicklet\Rest\TransferController;
use Clicklet\Tracking\ClickStore;
use Clicklet\Tracking\Retention;
use Clicklet\Transfer\CsvImporter;
use Clicklet\Transfer\Exporter;

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
			\WP_CLI::add_command( 'clicklet', Command::class );
			\WP_CLI::add_command( 'clicklet link', LinkCommand::class );
		}

		/**
		 * Fires once Clicklet URL Shortener has loaded.
		 *
		 * @hook clicklet_loaded
		 */
		do_action( 'clicklet_loaded' );
	}
}
