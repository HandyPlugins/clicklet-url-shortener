<?php
/**
 * Admin screens.
 *
 * @package Clicklet
 */

namespace Clicklet\Admin;

use Clicklet\Rest\Controller;
use Clicklet\Settings;
use Clicklet\Transfer\Exporter;

/**
 * Registers the admin menu and mounts the React app. All data goes through
 * the REST API.
 */
class Page {

	/**
	 * Menu slug of the links screen; the others append a suffix.
	 */
	const SLUG = 'clicklet-url-shortener';

	/**
	 * Script and style handle.
	 */
	const HANDLE = 'clicklet-url-shortener-admin';

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
		add_filter( 'plugin_action_links_' . plugin_basename( CLICKLET_FILE ), [ $this, 'action_links' ] );
	}

	/**
	 * Add the menu.
	 *
	 * @return void
	 */
	public function menu() {
		$capability = Controller::capability( 'links' );
		$settings   = Controller::capability( 'settings' );

		$this->hooks['links'] = add_menu_page(
			__( 'Short Links', 'clicklet-url-shortener' ),
			__( 'Short Links', 'clicklet-url-shortener' ),
			$capability,
			self::SLUG,
			[ $this, 'render_links' ],
			'dashicons-admin-links',
			26
		);

		$this->hooks['links'] = add_submenu_page( self::SLUG, __( 'Short Links', 'clicklet-url-shortener' ), __( 'All Links', 'clicklet-url-shortener' ), $capability, self::SLUG, [ $this, 'render_links' ] );

		$this->hooks['import'] = add_submenu_page( self::SLUG, __( 'Import & Export', 'clicklet-url-shortener' ), __( 'Import / Export', 'clicklet-url-shortener' ), $settings, self::SLUG . '-import', [ $this, 'render_import' ] );

		$this->hooks['settings'] = add_submenu_page( self::SLUG, __( 'Short Link Settings', 'clicklet-url-shortener' ), __( 'Settings', 'clicklet-url-shortener' ), $settings, self::SLUG . '-settings', [ $this, 'render_settings' ] );
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
		if ( ! file_exists( CLICKLET_PATH . 'dist/admin.asset.php' ) ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Admin assets are missing. Run "npm run build" in the plugin folder.', 'clicklet-url-shortener' ) . '</p></div></div>';
			return;
		}

		printf( '<div class="wrap clicklet-url-shortener"><div id="clicklet-url-shortener-app" data-view="%s"></div></div>', esc_attr( $view ) );
	}

	/**
	 * Load the app on plugin screens only.
	 *
	 * @param string $hook Current screen hook.
	 *
	 * @return void
	 */
	public function enqueue( $hook ) {
		$asset_file = CLICKLET_PATH . 'dist/admin.asset.php';

		if ( ! in_array( $hook, $this->hooks, true ) || ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;
		$url   = plugins_url( 'dist/', CLICKLET_FILE );

		wp_enqueue_script( self::HANDLE, $url . 'admin.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( self::HANDLE, $url . 'admin.css', [ 'wp-components' ], $asset['version'] );
		wp_set_script_translations( self::HANDLE, 'clicklet-url-shortener' );

		wp_add_inline_script(
			self::HANDLE,
			'window.clicklet = ' . wp_json_encode(
				[
					'homeUrl'   => trailingslashit( home_url() ),
					'prefix'    => Settings::get( 'prefix' ),
					'adminUrl'  => admin_url( 'admin.php' ),
					'pageSlug'  => self::SLUG,
					'timezone'  => wp_timezone_string(),
					'canManage' => current_user_can( 'manage_options' ),
					'postTypes' => self::post_types(),
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
	 * Public post types that can get automatic short links.
	 *
	 * @return array[] { name, label }
	 */
	protected static function post_types() {
		$types = [];

		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$types[] = [
					'name'  => $type->name,
					'label' => $type->labels->name,
				];
			}
		}

		return $types;
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
			esc_html__( 'Settings', 'clicklet-url-shortener' )
		);

		array_unshift( $links, $settings );

		return $links;
	}
}
