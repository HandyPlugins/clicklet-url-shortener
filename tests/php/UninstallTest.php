<?php
/**
 * Uninstall routine.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Install;
use Clicklet\Links\Groups;
use Clicklet\Links\PostLinks;
use Clicklet\Links\Repository;
use Clicklet\Redirect\FastPath;
use Clicklet\Schema;
use Clicklet\Settings;
use Clicklet\Tracking\Retention;
use Clicklet\Uninstall;

/**
 * Uninstall drops real tables, so the temporary-table rewrite of the test
 * suite is switched off here and the plugin is reinstalled afterwards.
 *
 * @covers \Clicklet\Uninstall
 */
class UninstallTest extends TestCase {

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
	}

	/**
	 * Reinstall for the tests that follow.
	 */
	public function tear_down() {
		Install::activate();
		FastPath::uninstall();
		parent::tear_down();
	}

	/**
	 * Whether a plugin table exists.
	 *
	 * @param string $name Table name without prefixes.
	 *
	 * @return bool
	 */
	protected function table_exists( $name ) {
		global $wpdb;

		return Schema::table( $name ) === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Checking the schema itself.
	}

	/**
	 * By default links survive; the drop-in and cron job do not.
	 */
	public function test_keeps_data_by_default() {
		$this->create_link( [ 'slug' => 'kept' ] );
		FastPath::install();
		Retention::schedule();

		Uninstall::run();

		$this->assertFalse( FastPath::is_installed() );
		$this->assertFalse( wp_next_scheduled( Retention::HOOK ) );
		$this->assertTrue( $this->table_exists( 'links' ) );
		$this->assertSame( 1, $this->count_rows( 'links' ) );
		$this->assertNotFalse( get_option( Install::DB_VERSION_OPTION ) );
	}

	/**
	 * With "delete data on uninstall", everything goes.
	 */
	public function test_deletes_data_when_asked() {
		$this->settings( [ 'delete_data_on_uninstall' => true ] );
		$post = self::factory()->post->create();
		$link = ( new PostLinks( new Repository() ) )->create( $post );
		Groups::set( $link->id, [ 'Doomed' ] );

		Uninstall::run();

		foreach ( Schema::TABLES as $table ) {
			$this->assertFalse( $this->table_exists( $table ), $table );
		}

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_option( Install::DB_VERSION_OPTION ) );
		$this->assertFalse( get_option( Install::SECRET_OPTION ) );
		$this->assertSame( '', get_post_meta( $post, PostLinks::POST_META, true ) );
		$this->assertEmpty( term_exists( 'Doomed', Groups::TAXONOMY ) );
	}
}
