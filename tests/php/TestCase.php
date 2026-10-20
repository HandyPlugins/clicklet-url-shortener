<?php
/**
 * Base test case.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Links\Link;
use Clicklet\Links\Repository;
use Clicklet\Schema;
use Clicklet\Settings;

/**
 * Shared helpers. Each test runs in a transaction that is rolled back, and
 * $_SERVER is restored afterwards.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * $_SERVER before the test.
	 *
	 * @var array
	 */
	protected $server_backup = [];

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->server_backup = $_SERVER;
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$_SERVER = $this->server_backup;
		parent::tear_down();
	}

	/**
	 * Create a link or fail the test.
	 *
	 * @param array $data Link fields.
	 *
	 * @return Link
	 */
	protected function create_link( array $data = [] ) {
		$link = ( new Repository() )->create( array_merge( [ 'target_url' => 'https://example.com/' ], $data ) );

		$this->assertInstanceOf( Link::class, $link, is_wp_error( $link ) ? $link->get_error_message() : '' );

		return $link;
	}

	/**
	 * Change settings for this test.
	 *
	 * @param array $values Settings.
	 */
	protected function settings( array $values ) {
		Settings::update( $values );
	}

	/**
	 * Count rows in a plugin table.
	 *
	 * @param string $table Table name without prefixes.
	 * @param string $where Optional SQL condition (trusted, test-only).
	 *
	 * @return int
	 */
	protected function count_rows( $table, $where = '1=1' ) {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $table ) . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB
	}
}
