<?php
/**
 * Block editor integration.
 *
 * @package Clicklet
 */

namespace Clicklet\Admin;

use Clicklet\Links\PostLinks;
use Clicklet\Rest\Controller;

/**
 * Adds the "Short link" panel to the post editor.
 */
class Editor {

	/**
	 * Script handle.
	 */
	const HANDLE = 'clicklet-url-shortener-editor';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
	}

	/**
	 * Load the panel in the post editor (not the site editor) for viewable
	 * post types, for users who manage links.
	 *
	 * @return void
	 */
	public function enqueue() {
		$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$asset_file = CLICKLET_PATH . 'dist/editor.asset.php';

		if (
			! $screen
			|| 'post' !== $screen->base
			|| ! is_post_type_viewable( $screen->post_type )
			|| ! current_user_can( Controller::capability( 'links' ) )
			|| ! file_exists( $asset_file )
		) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script( self::HANDLE, plugins_url( 'dist/editor.js', CLICKLET_FILE ), $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( self::HANDLE, 'clicklet-url-shortener' );

		wp_add_inline_script(
			self::HANDLE,
			'window.clickletEditor = ' . wp_json_encode(
				[
					'adminUrl'      => admin_url( 'admin.php' ),
					'autoPostTypes' => PostLinks::auto_post_types(),
				]
			) . ';',
			'before'
		);
	}
}
