<?php
/**
 * Fast path drop-in.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Redirect;

use WP_Error;

/**
 * Manages a small must-use plugin that routes short links on
 * `muplugins_loaded`, before regular plugins and the theme load.
 *
 * The drop-in only points back to includes/fast-path.php, so plugin updates
 * never require rewriting it.
 */
class FastPath {

	/**
	 * Drop-in file name.
	 */
	const FILENAME = 'fast-url-shortener-fast-path.php';

	/**
	 * Absolute path of the drop-in.
	 *
	 * @return string
	 */
	public static function path() {
		return WPMU_PLUGIN_DIR . '/' . self::FILENAME;
	}

	/**
	 * Whether the drop-in is in place.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		return file_exists( self::path() );
	}

	/**
	 * Whether the plugin itself is active on this site or network.
	 *
	 * Called before plugin.php is loaded, so it reads the options directly.
	 *
	 * @return bool
	 */
	public static function is_plugin_active() {
		$basename = basename( FAST_URL_SHORTENER_PATH ) . '/fast-url-shortener.php';

		if ( in_array( $basename, (array) get_option( 'active_plugins', [] ), true ) ) {
			return true;
		}

		return is_multisite() && isset( get_site_option( 'active_sitewide_plugins', [] )[ $basename ] );
	}

	/**
	 * Write the drop-in.
	 *
	 * @return true|WP_Error
	 */
	public static function install() {
		// Many sites have no mu-plugins folder yet; the write check below needs it to exist.
		if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return new WP_Error( 'fast_url_shortener_filesystem', __( 'The mu-plugins directory could not be created.', 'fast-url-shortener' ) );
		}

		$filesystem = self::filesystem();

		if ( ! $filesystem ) {
			return new WP_Error( 'fast_url_shortener_filesystem', __( 'The file system is not writable without credentials.', 'fast-url-shortener' ) );
		}

		if ( ! $filesystem->put_contents( self::path(), self::contents(), FS_CHMOD_FILE ) ) {
			return new WP_Error( 'fast_url_shortener_filesystem', __( 'The fast path file could not be written.', 'fast-url-shortener' ) );
		}

		return true;
	}

	/**
	 * Remove the drop-in.
	 *
	 * @return bool
	 */
	public static function uninstall() {
		if ( ! self::is_installed() ) {
			return true;
		}

		$filesystem = self::filesystem();

		return $filesystem && $filesystem->delete( self::path() );
	}

	/**
	 * Drop-in source.
	 *
	 * @return string
	 */
	protected static function contents() {
		$loader = basename( FAST_URL_SHORTENER_PATH ) . '/includes/fast-path.php';

		return implode(
			"\n",
			[
				'<?php',
				'/**',
				' * Plugin Name: Fast URL Shortener (Fast Path)',
				' * Description: Resolves short links before other plugins load. Managed by Fast URL Shortener; turn it off from the plugin settings.',
				' *',
				' * @package FastUrlShortener',
				' */',
				'',
				"defined( 'ABSPATH' ) || exit;",
				'',
				'$fast_url_shortener_loader = WP_PLUGIN_DIR . ' . var_export( '/' . $loader, true ) . ';', // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Builds a PHP string literal.
				'',
				'if ( is_readable( $fast_url_shortener_loader ) ) {',
				"\trequire_once \$fast_url_shortener_loader;",
				'}',
				'',
				'unset( $fast_url_shortener_loader );',
				'',
			]
		);
	}

	/**
	 * Direct file system access, or null when credentials would be needed.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	protected static function filesystem() {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( 'direct' !== get_filesystem_method( [], WPMU_PLUGIN_DIR, true ) || ! WP_Filesystem() ) {
			return null;
		}

		return $wp_filesystem;
	}
}
