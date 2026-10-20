<?php
/**
 * Multisite.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Install;
use Clicklet\Redirect\FastPath;
use Clicklet\Schema;
use Clicklet\Uninstall;

/**
 * Runs with WP_MULTISITE=1; skipped on single sites.
 *
 * @covers \Clicklet\Install::install_new_site
 * @covers \Clicklet\Uninstall
 */
class MultisiteTest extends TestCase {

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		if ( is_multisite() ) {
			Install::activate();
			FastPath::uninstall();
		}

		parent::tear_down();
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 *
	 * @return bool
	 */
	protected function exists( $table ) {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Checking the schema itself.
	}

	/**
	 * A new site gets its own tables while the plugin is network active, and
	 * uninstall cleans every site.
	 */
	public function test_sites() {
		$basename = plugin_basename( CLICKLET_FILE );
		update_site_option( 'active_sitewide_plugins', [ $basename => time() ] );

		$site_id = self::factory()->blog->create();

		switch_to_blog( $site_id );
		$table = Schema::table( 'links' );
		$this->assertTrue( $this->exists( $table ), 'New site is installed.' );
		$this->assertTrue( Install::is_installed() );
		update_option( 'clicklet_settings', [ 'delete_data_on_uninstall' => true ] );
		restore_current_blog();

		$this->assertNotSame( $table, Schema::table( 'links' ), 'Each site has its own tables.' );

		Uninstall::run();

		$this->assertFalse( $this->exists( $table ), 'Uninstall reaches every site.' );
		$this->assertTrue( $this->exists( Schema::table( 'links' ) ), 'Sites that keep data keep it.' );

		update_site_option( 'active_sitewide_plugins', [] );
	}
}
