<?php
/**
 * Settings REST endpoint.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Rest;

use FastUrlShortener\Links\Repository;
use FastUrlShortener\Redirect\FastPath;
use FastUrlShortener\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * /settings.
 */
class SettingsController extends Controller {

	/**
	 * Capability context.
	 *
	 * @var string
	 */
	protected $capability_context = 'settings';

	/**
	 * Proxy headers the visitor IP may be read from.
	 */
	const IP_HEADERS = [ '', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_TRUE_CLIENT_IP' ];

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => $this->args(),
				],
			]
		);
	}

	/**
	 * Argument schema for updates.
	 *
	 * @return array
	 */
	protected function args() {
		$args = [
			'prefix'                   => [ 'type' => 'string' ],
			'redirect_type'            => [
				'type' => 'integer',
				'enum' => Repository::REDIRECT_TYPES,
			],
			'case_sensitive'           => [ 'type' => 'boolean' ],
			'slug_length'              => [
				'type'    => 'integer',
				'minimum' => 3,
				'maximum' => 20,
			],
			'track_clicks'             => [ 'type' => 'boolean' ],
			'ip_header'                => [
				'type' => 'string',
				'enum' => self::IP_HEADERS,
			],
			'retention_days'           => [
				'type'    => 'integer',
				'minimum' => 0,
			],
			'fast_path'                => [ 'type' => 'boolean' ],
			'delete_data_on_uninstall' => [ 'type' => 'boolean' ],
		];

		/**
		 * Filter the settings accepted by the REST API, e.g. to add an
		 * extension's own settings. Save them on fast_url_shortener_rest_update_settings.
		 *
		 * @hook fast_url_shortener_rest_settings_args
		 *
		 * @param array $args Argument schema.
		 */
		return apply_filters( 'fast_url_shortener_rest_settings_args', $args );
	}

	/**
	 * Current settings.
	 *
	 * @return array
	 */
	public function get_settings() {
		/**
		 * Filter the settings returned by the REST API.
		 *
		 * @hook fast_url_shortener_rest_settings
		 *
		 * @param array $settings Settings.
		 */
		return apply_filters( 'fast_url_shortener_rest_settings', array_merge( Settings::all(), [ 'fast_path' => FastPath::is_installed() ] ) );
	}

	/**
	 * Update settings. The REST schema has already validated each value.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$values = array_intersect_key( $request->get_params(), Settings::defaults() );

		if ( isset( $values['prefix'] ) ) {
			$values['prefix'] = sanitize_title( $values['prefix'] );
		}

		if ( isset( $values['fast_path'] ) && FastPath::is_installed() !== (bool) $values['fast_path'] ) {
			$result = $values['fast_path'] ? FastPath::install() : FastPath::uninstall();

			if ( is_wp_error( $result ) ) {
				$result->add_data( [ 'status' => 500 ] );
				return $result;
			}

			if ( ! $result ) {
				return new WP_Error( 'fast_url_shortener_filesystem', __( 'The fast path file could not be removed.', 'fast-url-shortener' ), [ 'status' => 500 ] );
			}
		}

		Settings::update( $values );

		/**
		 * Fires after settings are saved through the REST API, so extensions
		 * can save their own values from the same request.
		 *
		 * @hook fast_url_shortener_rest_update_settings
		 *
		 * @param WP_REST_Request $request Request.
		 */
		do_action( 'fast_url_shortener_rest_update_settings', $request );

		return $this->get_settings();
	}
}
