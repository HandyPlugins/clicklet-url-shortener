<?php
/**
 * Click tracking.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tests;

use FastUrlShortener\Links\Repository;
use FastUrlShortener\Schema;
use FastUrlShortener\Tracking\ClickStore;
use FastUrlShortener\Tracking\Tracker;
use FastUrlShortener\Tracking\UserAgent;

/**
 * @covers \FastUrlShortener\Tracking\Tracker
 */
class TrackerTest extends TestCase {

	const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

	/**
	 * Tracker under test.
	 *
	 * @var Tracker
	 */
	protected $tracker;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->tracker              = new Tracker( new Repository(), new ClickStore() );
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = self::BROWSER;
	}

	/**
	 * Stored click rows of a link.
	 *
	 * @param int $link_id Link ID.
	 *
	 * @return array[]
	 */
	protected function clicks( $link_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB
		return $wpdb->get_results( $wpdb->prepare( 'SELECT *, HEX(visitor_hash) AS hash FROM %i WHERE link_id = %d ORDER BY id', Schema::table( 'clicks' ), $link_id ), ARRAY_A );
	}

	/**
	 * Today's totals of a link.
	 *
	 * @param int $link_id Link ID.
	 *
	 * @return array|null
	 */
	protected function today( $link_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB
		return $wpdb->get_row( $wpdb->prepare( 'SELECT clicks, uniques, bots FROM %i WHERE link_id = %d AND day = %s', Schema::table( 'stats_daily' ), $link_id, wp_date( 'Y-m-d' ) ), ARRAY_A );
	}

	/**
	 * A human click stores a row without the IP, counts a unique visitor
	 * once per day and bumps the totals.
	 */
	public function test_records_human_clicks() {
		$link                         = $this->create_link();
		$_SERVER['HTTP_REFERER']      = 'https://www.Facebook.com/some/post';
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'tr';

		$this->tracker->record( $link );
		$this->tracker->record( $link );

		$rows = $this->clicks( $link->id );
		$this->assertCount( 2, $rows );
		$this->assertSame( [ '1', '0' ], wp_list_pluck( $rows, 'is_unique' ) );
		$this->assertSame( 'TR', $rows[0]['country'] );
		$this->assertSame( 'facebook.com', $rows[0]['referrer_host'] );
		$this->assertSame( (string) UserAgent::DEVICE_MOBILE, $rows[0]['device'] );
		$this->assertSame( $rows[0]['hash'], $rows[1]['hash'] );
		$this->assertStringNotContainsString( '203.0.113.7', wp_json_encode( $rows ) );

		$this->assertSame(
			[
				'clicks'  => '2',
				'uniques' => '1',
				'bots'    => '0',
			],
			$this->today( $link->id )
		);
		$this->assertSame( 2, ( new Repository() )->find( $link->id )->total_clicks );
	}

	/**
	 * Another visitor is a new unique.
	 */
	public function test_different_visitor_is_unique() {
		$link = $this->create_link();

		$this->tracker->record( $link );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$this->tracker->record( $link );

		$this->assertSame( [ '1', '1' ], wp_list_pluck( $this->clicks( $link->id ), 'is_unique' ) );
	}

	/**
	 * Bots only bump the daily bot counter.
	 */
	public function test_bots_are_counted_separately() {
		$link                       = $this->create_link();
		$_SERVER['HTTP_USER_AGENT'] = 'facebookexternalhit/1.1';

		$this->tracker->record( $link );

		$this->assertSame( [], $this->clicks( $link->id ) );
		$this->assertSame( '1', $this->today( $link->id )['bots'] );
		$this->assertSame( 0, ( new Repository() )->find( $link->id )->total_clicks );
	}

	/**
	 * Forwarded headers are ignored unless configured, because anyone can send them.
	 */
	public function test_ip_header_is_opt_in() {
		$link                            = $this->create_link();
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.50, 10.0.0.1';

		$this->tracker->record( $link );
		$this->settings( [ 'ip_header' => 'HTTP_X_FORWARDED_FOR' ] );
		$this->tracker->record( $link );

		// Different IPs, so the hashes differ and both clicks are unique.
		$rows = $this->clicks( $link->id );
		$this->assertNotSame( $rows[0]['hash'], $rows[1]['hash'] );
	}

	/**
	 * QR scans are stored with their own source.
	 */
	public function test_qr_source() {
		$link = $this->create_link();

		$this->tracker->record( $link );
		$this->tracker->record( $link, \FastUrlShortener\Tracking\Stats::SOURCE_QR );

		$this->assertSame( [ '0', '1' ], wp_list_pluck( $this->clicks( $link->id ), 'source' ) );
	}

	/**
	 * Helpers.
	 */
	public function test_helpers() {
		$this->assertSame( 'news.ycombinator.com', Tracker::host( 'https://news.ycombinator.com/item?id=1' ) );
		$this->assertSame( '', Tracker::host( '' ) );
		$this->assertSame( 'US', Tracker::country_code( 'us' ) );
		$this->assertSame( '', Tracker::country_code( 'XX' ) );
		$this->assertSame( '', Tracker::country_code( 'T1' ) );
		$this->assertSame( '', Tracker::country_code( 'Turkey' ) );
	}

	/**
	 * Visitor hashes rotate daily and differ per site secret.
	 */
	public function test_visitor_hash_rotates_daily() {
		$monday  = ClickStore::visitor_hash( '203.0.113.7', self::BROWSER, '2026-10-12' );
		$tuesday = ClickStore::visitor_hash( '203.0.113.7', self::BROWSER, '2026-10-13' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $monday );
		$this->assertNotSame( $monday, $tuesday );
		$this->assertSame( $monday, ClickStore::visitor_hash( '203.0.113.7', self::BROWSER, '2026-10-12' ) );
	}
}
