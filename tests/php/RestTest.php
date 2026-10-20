<?php
/**
 * REST API.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use WP_REST_Request;

/**
 * @covers \Clicklet\Rest\LinksController
 * @covers \Clicklet\Rest\SettingsController
 * @covers \Clicklet\Rest\ImportController
 * @covers \Clicklet\Rest\TransferController
 */
class RestTest extends TestCase {

	/**
	 * Administrator ID.
	 *
	 * @var int
	 */
	protected static $admin;

	/**
	 * Editor ID.
	 *
	 * @var int
	 */
	protected static $editor;

	/**
	 * Create users.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin  = $factory->user->create( [ 'role' => 'administrator' ] );
		self::$editor = $factory->user->create( [ 'role' => 'editor' ] );
	}

	/**
	 * Set up: routes are registered and an admin is logged in.
	 */
	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init', rest_get_server() );
		wp_set_current_user( self::$admin );
	}

	/**
	 * Dispatch a request.
	 *
	 * @param string $method Method.
	 * @param string $path   Path after the namespace.
	 * @param array  $params Parameters.
	 *
	 * @return \WP_REST_Response
	 */
	protected function request( $method, $path, array $params = [] ) {
		$request = new WP_REST_Request( $method, '/clicklet-url-shortener/v1' . $path );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Every route requires the capability.
	 */
	public function test_permissions() {
		// Valid parameters: WordPress validates them before checking permissions.
		$routes = [
			[ 'GET', '/links' ],
			[ 'POST', '/links', [ 'target_url' => 'https://example.com/' ] ],
			[ 'GET', '/settings' ],
			[ 'GET', '/import' ],
			[ 'GET', '/transfer' ],
		];

		wp_set_current_user( 0 );
		foreach ( $routes as $route ) {
			$this->assertSame( 401, $this->request( ...$route )->get_status(), $route[0] . ' ' . $route[1] );
		}

		wp_set_current_user( self::$editor );
		foreach ( $routes as $route ) {
			$this->assertSame( 403, $this->request( ...$route )->get_status(), $route[0] . ' ' . $route[1] );
		}

		add_filter(
			'clicklet_capability',
			static function () {
				return 'edit_posts';
			}
		);
		$this->assertSame( 200, $this->request( 'GET', '/links' )->get_status() );
	}

	/**
	 * Create, read, update, delete.
	 */
	public function test_crud() {
		$created = $this->request(
			'POST',
			'/links',
			[
				'target_url' => 'https://example.com/rest',
				'slug'       => 'rest',
				'expires_at' => '2030-01-01T10:00',
			]
		);
		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( home_url( '/rest' ), $created->get_data()['short_url'] );
		$this->assertSame( '2030-01-01T10:00', $created->get_data()['expires_at_local'] );

		$id = $created->get_data()['id'];

		$this->assertSame( 'rest', $this->request( 'GET', "/links/{$id}" )->get_data()['slug'] );

		$updated = $this->request(
			'PUT',
			"/links/{$id}",
			[
				'status'        => 'disabled',
				'redirect_type' => 301,
			]
		);
		$this->assertSame( 'disabled', $updated->get_data()['status'] );
		$this->assertSame( 301, $updated->get_data()['redirect_type'] );

		$this->assertSame( 200, $this->request( 'DELETE', "/links/{$id}" )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', "/links/{$id}" )->get_status() );
		$this->assertSame( 404, $this->request( 'DELETE', "/links/{$id}" )->get_status() );
	}

	/**
	 * Validation errors are 400s with their codes.
	 */
	public function test_validation() {
		$this->assertSame( 'clicklet_invalid_target', $this->request( 'POST', '/links', [ 'target_url' => 'not a url' ] )->get_data()['code'] );
		$this->assertSame( 'rest_missing_callback_param', $this->request( 'POST', '/links', [] )->get_data()['code'] );
		$this->assertSame(
			'rest_invalid_param',
			$this->request(
				'POST',
				'/links',
				[
					'target_url'    => 'https://example.com/',
					'redirect_type' => 303,
				]
			)->get_data()['code']
		);

		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'pricing',
				'post_status' => 'publish',
			]
		);
		$conflict = $this->request(
			'POST',
			'/links',
			[
				'target_url' => 'https://example.com/',
				'slug'       => 'pricing',
			]
		);
		$this->assertSame( 400, $conflict->get_status() );
		$this->assertSame( 'clicklet_slug_conflict', $conflict->get_data()['code'] );

		$forced = $this->request(
			'POST',
			'/links',
			[
				'target_url' => 'https://example.com/',
				'slug'       => 'pricing',
				'force'      => true,
			]
		);
		$this->assertSame( 201, $forced->get_status() );
	}

	/**
	 * Listing with pagination headers.
	 */
	public function test_list() {
		foreach ( [ 'a1', 'a2', 'a3' ] as $slug ) {
			$this->create_link( [ 'slug' => $slug ] );
		}

		$response = $this->request(
			'GET',
			'/links',
			[
				'per_page' => 2,
				'orderby'  => 'slug',
				'order'    => 'asc',
			]
		);

		$this->assertSame( [ 'a1', 'a2' ], wp_list_pluck( $response->get_data(), 'slug' ) );
		$this->assertSame( '3', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
	}

	/**
	 * Stats include labeled breakdowns.
	 */
	public function test_stats() {
		$link = $this->create_link();

		$data = $this->request( 'GET', "/links/{$link->id}/stats", [ 'days' => 7 ] )->get_data();

		$this->assertCount( 7, $data['daily'] );
		$this->assertSame( [ 'country', 'device', 'os', 'browser', 'referrer_host', 'source' ], array_keys( $data['breakdowns'] ) );
		$this->assertSame( 404, $this->request( 'GET', '/links/999999/stats' )->get_status() );
	}

	/**
	 * Settings are validated by schema and unknown keys are ignored.
	 */
	public function test_settings() {
		$response = $this->request(
			'POST',
			'/settings',
			[
				'prefix'      => 'Go Links',
				'slug_length' => 8,
				'unknown'     => 'x',
			]
		);

		$this->assertSame( 'go-links', $response->get_data()['prefix'] );
		$this->assertSame( 8, $response->get_data()['slug_length'] );
		$this->assertArrayNotHasKey( 'unknown', get_option( 'clicklet_settings' ) );

		$this->assertSame( 400, $this->request( 'POST', '/settings', [ 'slug_length' => 2 ] )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/settings', [ 'ip_header' => 'HTTP_X_EVIL' ] )->get_status() );
	}

	/**
	 * Unknown import sources and steps before an upload are rejected.
	 */
	public function test_import_endpoints() {
		$this->assertSame( [ 'url-shortify', 'pretty-links' ], wp_list_pluck( $this->request( 'GET', '/import' )->get_data(), 'id' ) );
		$this->assertSame( 404, $this->request( 'POST', '/import/unknown/start' )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/transfer/step' )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/transfer/upload' )->get_status() );
	}
}
