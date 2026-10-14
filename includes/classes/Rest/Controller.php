<?php
/**
 * Shared REST controller logic.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Rest;

/**
 * Base class for the plugin's REST controllers.
 */
abstract class Controller {

	/**
	 * Route namespace.
	 */
	const NAMESPACE = 'fast-url-shortener/v1';

	/**
	 * Register routes. Called on `rest_api_init`.
	 *
	 * @return void
	 */
	abstract public function register_routes();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Capability required to manage links and settings.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filter the capability required to manage short links.
		 *
		 * @hook fast_url_shortener_capability
		 *
		 * @param string $capability Capability. Default manage_options.
		 */
		return (string) apply_filters( 'fast_url_shortener_capability', 'manage_options' );
	}

	/**
	 * Permission callback shared by all routes.
	 *
	 * @return bool
	 */
	public function permission_check() {
		return current_user_can( self::capability() );
	}
}
