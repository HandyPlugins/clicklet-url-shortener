<?php
/**
 * Extension points, used the way an add-on would.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Links\Repository;
use Clicklet\Redirect\Router;
use Clicklet\Schema;
use Clicklet\Tracking\ClickStore;
use Clicklet\Transfer\CsvImporter;
use Clicklet\Transfer\Exporter;
use WP_Error;
use WP_REST_Request;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Temporary CSV files.

/**
 * @covers \Clicklet\Links\Link::get_meta
 * @covers \Clicklet\Schema::register_meta_table
 */
class ExtensionTest extends TestCase {

	/**
	 * Fresh REST server per test, so routes pick up filters added by the test.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Link meta works through the WordPress metadata API and is removed with the link.
	 */
	public function test_link_meta() {
		$link = $this->create_link();

		update_metadata( Schema::META_TYPE, $link->id, 'targets', [ 'https://a.example', 'https://b.example' ] );

		$this->assertSame( [ 'https://a.example', 'https://b.example' ], $link->get_meta( 'targets' ) );
		$this->assertSame( [ 'https://a.example', 'https://b.example' ], get_metadata( Schema::META_TYPE, $link->id, 'targets', true ) );

		( new Repository() )->delete( $link->id );

		$this->assertSame( '', get_metadata( Schema::META_TYPE, $link->id, 'targets', true ) );
		$this->assertSame( 0, $this->count_rows( 'linkmeta' ) );
	}

	/**
	 * Add-ons can change or reject link fields before they are saved.
	 */
	public function test_prepare_link_filter() {
		add_filter(
			'clicklet_prepare_link',
			static function ( $fields, $data ) {
				if ( isset( $data['target_url'] ) && false !== strpos( $data['target_url'], 'blocked.example' ) ) {
					return new WP_Error( 'blocked', 'Blocked domain.' );
				}

				return array_merge( $fields, [ 'title' => 'Set by add-on' ] );
			},
			10,
			2
		);

		$this->assertSame( 'blocked', ( new Repository() )->create( [ 'target_url' => 'https://blocked.example/' ] )->get_error_code() );
		$this->assertSame( 'Set by add-on', $this->create_link()->title );
	}

	/**
	 * Add-ons can make links unavailable, e.g. outside a schedule.
	 */
	public function test_link_available_filter() {
		$link = $this->create_link();
		$this->assertTrue( $link->is_available() );

		add_filter( 'clicklet_link_available', '__return_false' );
		$this->assertFalse( $link->is_available() );
	}

	/**
	 * Links with rules are left to the normal path when routed before
	 * regular plugins load (the fast path).
	 */
	public function test_fast_path_defers_links_with_rules() {
		global $wp_actions;

		$this->create_link(
			[
				'slug'      => 'ruled',
				'has_rules' => true,
			]
		);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/ruled';

		$routed = new \ReflectionProperty( Router::class, 'routed' );
		$routed->setAccessible( true );
		$routed->setValue( null, true );

		$plugins_loaded               = $wp_actions['plugins_loaded'];
		$wp_actions['plugins_loaded'] = 0; // Pretend the fast path runs before plugins load.

		( new Router( new Repository() ) )->maybe_redirect();

		$wp_actions['plugins_loaded'] = $plugins_loaded;

		$this->assertFalse( $routed->getValue(), 'The normal path must get another chance.' );
		$this->assertTrue( ( new Repository() )->find_by_slug( 'ruled' )->has_rules );
	}

	/**
	 * Links and settings can require different capabilities.
	 */
	public function test_capability_contexts() {
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		add_filter(
			'clicklet_capability',
			static function ( $capability, $context ) {
				return 'links' === $context ? 'edit_posts' : $capability;
			},
			10,
			2
		);

		$this->assertSame( 200, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/clicklet-url-shortener/v1/links' ) )->get_status() );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/clicklet-url-shortener/v1/settings' ) )->get_status() );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/clicklet-url-shortener/v1/transfer' ) )->get_status() );
	}

	/**
	 * Add-ons can accept, save and return their own link fields over REST.
	 */
	public function test_rest_link_fields() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		add_filter(
			'clicklet_rest_link_args',
			static function ( $args ) {
				$args['campaign'] = [ 'type' => 'string' ];
				return $args;
			}
		);
		add_action(
			'clicklet_rest_save_link',
			static function ( $link, $request ) {
				if ( isset( $request['campaign'] ) ) {
					update_metadata( Schema::META_TYPE, $link->id, 'campaign', sanitize_text_field( $request['campaign'] ) );
				}
			},
			10,
			2
		);
		add_filter(
			'clicklet_rest_prepare_link',
			static function ( $data, $link ) {
				$data['campaign'] = (string) $link->get_meta( 'campaign' );
				return $data;
			},
			10,
			2
		);

		$request = new WP_REST_Request( 'POST', '/clicklet-url-shortener/v1/links' );
		$request->set_param( 'target_url', 'https://example.com/' );
		$request->set_param( 'campaign', 'black-friday' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'black-friday', $response->get_data()['campaign'] );

		$invalid = new WP_REST_Request( 'POST', '/clicklet-url-shortener/v1/links' );
		$invalid->set_param( 'target_url', 'https://example.com/' );
		$invalid->set_param( 'campaign', [ 'not', 'a', 'string' ] );
		$this->assertSame( 400, rest_get_server()->dispatch( $invalid )->get_status(), 'Add-on fields are validated by their schema.' );
	}

	/**
	 * Add-ons can add settings to the same screen and request.
	 */
	public function test_rest_settings_fields() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		add_filter(
			'clicklet_rest_settings_args',
			static function ( $args ) {
				$args['pro_mode'] = [ 'type' => 'boolean' ];
				return $args;
			}
		);
		add_action(
			'clicklet_rest_update_settings',
			static function ( $request ) {
				if ( isset( $request['pro_mode'] ) ) {
					update_option( 'test_pro_mode', (bool) $request['pro_mode'] );
				}
			}
		);
		add_filter(
			'clicklet_rest_settings',
			static function ( $settings ) {
				$settings['pro_mode'] = (bool) get_option( 'test_pro_mode' );
				return $settings;
			}
		);

		$request = new WP_REST_Request( 'POST', '/clicklet-url-shortener/v1/settings' );
		$request->set_param( 'pro_mode', true );
		$request->set_param( 'slug_length', 6 );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertTrue( $data['pro_mode'] );
		$this->assertSame( 6, $data['slug_length'] );
		$this->assertArrayNotHasKey( 'pro_mode', get_option( 'clicklet_settings' ), 'Core settings stay clean.' );
	}

	/**
	 * Add-ons can add columns to the links CSV and read them back on import.
	 */
	public function test_csv_link_columns() {
		add_filter(
			'clicklet_csv_link_columns',
			static function ( $columns ) {
				$columns[] = 'campaign';
				return $columns;
			}
		);
		add_filter(
			'clicklet_csv_export_link',
			static function ( $cells, $row ) {
				$cells['campaign'] = (string) get_metadata( Schema::META_TYPE, (int) $row['id'], 'campaign', true );
				return $cells;
			},
			10,
			2
		);
		add_action(
			'clicklet_csv_import_link',
			static function ( $link, $row ) {
				update_metadata( Schema::META_TYPE, $link->id, 'campaign', $row['campaign'] ?? '' );
			},
			10,
			2
		);

		$link = $this->create_link( [ 'slug' => 'promo' ] );
		update_metadata( Schema::META_TYPE, $link->id, 'campaign', 'summer' );

		$path   = get_temp_dir() . 'clicklet-ext-' . wp_generate_password( 8, false ) . '.csv';
		$handle = fopen( $path, 'w' );
		( new Exporter() )->write( 'links', $handle );
		fclose( $handle );

		$this->assertStringContainsString( ',campaign', file_get_contents( $path ) );

		( new Repository() )->delete( $link->id );

		$importer = new CsvImporter( new Repository(), new ClickStore() );
		$importer->start( $path, false, true );
		$importer->step();

		$this->assertSame( 'summer', ( new Repository() )->find_by_slug( 'promo' )->get_meta( 'campaign' ) );
		$this->assertFileDoesNotExist( $path );
	}
}
