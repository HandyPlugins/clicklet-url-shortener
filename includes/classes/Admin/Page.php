<?php
/**
 * Admin screens.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Admin;

use FastUrlShortener\Rest\Controller;
use FastUrlShortener\Settings;
use FastUrlShortener\Transfer\Exporter;

/**
 * Registers the admin menu and mounts the React app. All data goes through
 * the REST API.
 */
class Page {

	/**
	 * Menu slug of the links screen; the others append a suffix.
	 */
	const SLUG = 'fast-url-shortener';

	/**
	 * Script and style handle.
	 */
	const HANDLE = 'fast-url-shortener-admin';

	/**
	 * Screen hook suffixes.
	 *
	 * @var string[]
	 */
	protected $hooks = [];

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( FAST_URL_SHORTENER_FILE ), [ $this, 'action_links' ] );
	}

	/**
	 * Add the menu.
	 *
	 * @return void
	 */
	public function menu() {
		$capability = Controller::capability();

		$this->hooks['links'] = add_menu_page(
			__( 'Short Links', 'fast-url-shortener' ),
			__( 'Short Links', 'fast-url-shortener' ),
			$capability,
			self::SLUG,
			[ $this, 'render_links' ],
			'dashicons-admin-links',
			26
		);

		$this->hooks['links'] = add_submenu_page( self::SLUG, __( 'Short Links', 'fast-url-shortener' ), __( 'All Links', 'fast-url-shortener' ), $capability, self::SLUG, [ $this, 'render_links' ] );

		$this->hooks['import'] = add_submenu_page( self::SLUG, __( 'Import & Export', 'fast-url-shortener' ), __( 'Import / Export', 'fast-url-shortener' ), $capability, self::SLUG . '-import', [ $this, 'render_import' ] );

		$this->hooks['settings'] = add_submenu_page( self::SLUG, __( 'Short Link Settings', 'fast-url-shortener' ), __( 'Settings', 'fast-url-shortener' ), $capability, self::SLUG . '-settings', [ $this, 'render_settings' ] );
	}

	/**
	 * Links screen.
	 *
	 * @return void
	 */
	public function render_links() {
		$this->render( 'links' );
	}

	/**
	 * Import screen.
	 *
	 * @return void
	 */
	public function render_import() {
		$this->render( 'import' );
	}

	/**
	 * Settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		$this->render( 'settings' );
	}

	/**
	 * App container.
	 *
	 * @param string $view View name.
	 *
	 * @return void
	 */
	protected function render( $view ) {
		if ( ! file_exists( FAST_URL_SHORTENER_PATH . 'dist/admin.asset.php' ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Admin assets are missing. Run "npm run build" in the plugin folder.', 'fast-url-shortener' ) . '</p></div></div>';
			return;
		}

		printf( '<div class="wrap fast-url-shortener"><div id="fast-url-shortener-app" data-view="%s"></div></div>', esc_attr( $view ) );
	}

	/**
	 * Load the app on plugin screens only.
	 *
	 * @param string $hook Current screen hook.
	 *
	 * @return void
	 */
	public function enqueue( $hook ) {
		$asset_file = FAST_URL_SHORTENER_PATH . 'dist/admin.asset.php';

		if ( ! in_array( $hook, $this->hooks, true ) || ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;
		$url   = plugins_url( 'dist/', FAST_URL_SHORTENER_FILE );

		wp_enqueue_script( self::HANDLE, $url . 'admin.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( self::HANDLE, $url . 'admin.css', [ 'wp-components' ], $asset['version'] );
		wp_set_script_translations( self::HANDLE, 'fast-url-shortener' );

		wp_add_inline_script(
			self::HANDLE,
			'window.fastUrlShortener = ' . wp_json_encode(
				[
					'homeUrl'   => trailingslashit( home_url() ),
					'prefix'    => Settings::get( 'prefix' ),
					'adminUrl'  => admin_url( 'admin.php' ),
					'pageSlug'  => self::SLUG,
					'timezone'  => wp_timezone_string(),
					'canManage' => current_user_can( 'manage_options' ),
					'exportUrl' => [
						'links'  => Exporter::url( 'links' ),
						'stats'  => Exporter::url( 'stats' ),
						'clicks' => Exporter::url( 'clicks' ),
					],
				]
			) . ';',
			'before'
		);
	}

	/**
	 * Links on the Plugins screen.
	 *
	 * @param array $links Action links.
	 *
	 * @return array
	 */
	public function action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ),
			esc_html__( 'Settings', 'fast-url-shortener' )
		);

		array_unshift( $links, $settings );

		return $links;
	}
}
