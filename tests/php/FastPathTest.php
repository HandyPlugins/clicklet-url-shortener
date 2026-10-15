<?php
/**
 * Fast path drop-in and lifecycle.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tests;

use FastUrlShortener\Install;
use FastUrlShortener\Redirect\FastPath;
use FastUrlShortener\Settings;
use FastUrlShortener\Tracking\Retention;

/**
 * WPMU_PLUGIN_DIR points to a temporary folder in the test config.
 *
 * @covers \FastUrlShortener\Redirect\FastPath
 * @covers \FastUrlShortener\Install
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
		$this->assertStringContainsString( 'fast-url-shortener-tests', WPMU_PLUGIN_DIR, 'Tests must not touch the real mu-plugins folder.' );
		$this->assertFalse( FastPath::is_installed() );

		$this->assertTrue( FastPath::install() );
		$this->assertTrue( FastPath::is_installed() );

		$contents = file_get_contents( FastPath::path() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( "WP_PLUGIN_DIR . '/" . basename( FAST_URL_SHORTENER_PATH ) . "/includes/fast-path.php'", $contents );
		$this->assertStringNotContainsString( ABSPATH, $contents );

		$this->assertTrue( FastPath::uninstall() );
		$this->assertFalse( FastPath::is_installed() );
		$this->assertTrue( FastPath::uninstall(), 'Removing a missing drop-in is fine.' );
	}

	/**
	 * The drop-in only acts while the plugin is active.
	 */
	public function test_is_plugin_active() {
		$basename = basename( FAST_URL_SHORTENER_PATH ) . '/fast-url-shortener.php';

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
