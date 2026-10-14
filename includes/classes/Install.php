<?php
/**
 * Install, upgrade and deactivation routines.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener;

use FastUrlShortener\Redirect\FastPath;
use FastUrlShortener\Tracking\Retention;

/**
 * Plugin lifecycle.
 */
class Install {

	/**
	 * Option holding the installed schema version.
	 */
	const DB_VERSION_OPTION = 'fast_url_shortener_db_version';

	/**
	 * Option holding the secret used to hash visitors.
	 */
	const SECRET_OPTION = 'fast_url_shortener_secret';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_initialize_site', [ $this, 'install_new_site' ], 200 );
	}

	/**
	 * Activation hook.
	 *
	 * On a network activation the other sites are installed lazily by
	 * maybe_upgrade() on their first request, so large networks do not time out.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install();
		Retention::schedule();

		if ( Settings::get( 'fast_path' ) ) {
			FastPath::install();
		}
	}

	/**
	 * Deactivation hook. Settings and data are kept.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Retention::unschedule();
		FastPath::uninstall();
	}

	/**
	 * Whether the tables exist for the current site.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		return (bool) get_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Install or upgrade the current site when the schema version changed.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		if ( Schema::VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Install the plugin on a site created after a network activation.
	 *
	 * @param \WP_Site $site New site.
	 *
	 * @return void
	 */
	public function install_new_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( FAST_URL_SHORTENER_FILE ) ) ) {
			return;
		}

		switch_to_blog( $site->blog_id );
		self::install();
		Retention::schedule();
		restore_current_blog();
	}

	/**
	 * Create tables and options for the current site.
	 *
	 * @return void
	 */
	protected static function install() {
		Schema::create_tables();

		add_option( self::SECRET_OPTION, wp_generate_password( 64, true, true ), '', true );
		add_option( Settings::OPTION, Settings::defaults(), '', true );
		update_option( self::DB_VERSION_OPTION, Schema::VERSION, true );
	}
}
