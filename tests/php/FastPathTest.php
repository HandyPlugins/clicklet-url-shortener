<?php
/**
 * Fast path drop-in and lifecycle.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Install;
use Clicklet\Redirect\FastPath;
use Clicklet\Settings;
use Clicklet\Tracking\Retention;

/**
 * WPMU_PLUGIN_DIR points to a temporary folder in the test config.
 *
 * @covers \Clicklet\Redirect\FastPath
 * @covers \Clicklet\Install
 */
class FastPathTest extends TestCase {

	/**
	 * Tear down.
	 */
	public function tear_down() {
		FastPath::uninstall();
		parent::tear_down();
	}

	/**
	 * The drop-in only loads the plugin's entry point, via a relative path.
	 */
	public function test_install_and_uninstall() {
		$this->assertStringContainsString( 'clicklet-url-shortener-tests', WPMU_PLUGIN_DIR, 'Tests must not touch the real mu-plugins folder.' );
		$this->assertFalse( FastPath::is_installed() );

		$this->assertTrue( FastPath::install() );
		$this->assertTrue( FastPath::is_installed() );

		$contents = file_get_contents( FastPath::path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( "WP_PLUGIN_DIR . '/" . basename( CLICKLET_PATH ) . "/includes/fast-path.php'", $contents );
		$this->assertStringNotContainsString( ABSPATH, $contents );

		$this->assertTrue( FastPath::uninstall() );
		$this->assertFalse( FastPath::is_installed() );
		$this->assertTrue( FastPath::uninstall(), 'Removing a missing drop-in is fine.' );
	}

	/**
	 * The drop-in only acts while the plugin is active.
	 */
	public function test_is_plugin_active() {
		$basename = basename( CLICKLET_PATH ) . '/clicklet-url-shortener.php';

		update_option( 'active_plugins', [] );
		$this->assertFalse( FastPath::is_plugin_active() );

		update_option( 'active_plugins', [ $basename ] );
		$this->assertTrue( FastPath::is_plugin_active() );
	}

	/**
	 * Deactivation removes the drop-in and the cron job but keeps settings;
	 * activation restores them.
	 */
	public function test_deactivate_and_activate() {
		Settings::update( [ 'fast_path' => true ] );
		FastPath::install();
		Retention::schedule();

		Install::deactivate();
		$this->assertFalse( FastPath::is_installed() );
		$this->assertFalse( wp_next_scheduled( Retention::HOOK ) );
		$this->assertTrue( Settings::get( 'fast_path' ) );

		Install::activate();
		$this->assertTrue( FastPath::is_installed() );
		$this->assertNotFalse( wp_next_scheduled( Retention::HOOK ) );
		$this->assertTrue( Install::is_installed() );
		$this->assertNotEmpty( get_option( Install::SECRET_OPTION ) );
	}
}
