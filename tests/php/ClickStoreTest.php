<?php
/**
 * Click storage and retention.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Links\Repository;
use Clicklet\Tracking\ClickStore;
use Clicklet\Tracking\Retention;
use Clicklet\Tracking\Stats;

/**
 * @covers \Clicklet\Tracking\ClickStore
 * @covers \Clicklet\Tracking\Retention
 * @covers \Clicklet\Tracking\Stats
 */
class ClickStoreTest extends TestCase {

	/**
	 * Store under test.
	 *
	 * @var ClickStore
	 */
	protected $store;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->store = new ClickStore();
	}

	/**
	 * Insert a click.
	 *
	 * @param int    $link_id    Link ID.
	 * @param string $clicked_at UTC datetime.
	 * @param int    $unique     Unique flag.
	 * @param string $country    Country.
	 */
	protected function add( $link_id, $clicked_at, $unique = 1, $country = 'DE' ) {
		$this->store->insert(
			[
				'link_id'       => $link_id,
				'clicked_at'    => $clicked_at,
				'is_unique'     => $unique,
				'country'       => $country,
				'device'        => 1,
				'os'            => 1,
				'browser'       => 1,
				'referrer_host' => 't.co',
			],
			md5( $clicked_at . $country )
		);
	}

	/**
	 * bump_daily adds, set_daily replaces, recount follows the totals.
	 */
	public function test_daily_totals() {
		$link = $this->create_link();

		$this->store->bump_daily( $link->id, '2026-10-01', 1, 1, 0 );
		$this->store->bump_daily( $link->id, '2026-10-01', 1, 0, 2 );
		$this->store->set_daily( $link->id, '2026-10-02', 10, 4, 1 );
		$this->store->set_daily( $link->id, '2026-10-02', 7, 3, 0 );
		$this->store->recount( $link->id );

		$this->assertTrue( $this->store->has_daily( $link->id ) );
		$this->assertSame( 9, ( new Repository() )->find( $link->id )->total_clicks );
	}

	/**
	 * Daily totals and the counter can be rebuilt from raw clicks.
	 */
	public function test_rebuild() {
		$link = $this->create_link();
		$this->add( $link->id, '2026-10-01 10:00:00' );
		$this->add( $link->id, '2026-10-01 11:00:00', 0 );
		$this->add( $link->id, '2026-10-03 09:00:00' );
		$this->store->set_daily( $link->id, '2020-01-01', 99, 0, 0 ); // Stale data is replaced.

		$this->store->rebuild( $link->id );

		$daily = array_column( ( new Stats() )->daily( $link->id, 3650 ), null, 'day' );
		$this->assertSame( 2, $daily['2026-10-01']['clicks'] );
		$this->assertSame( 1, $daily['2026-10-01']['uniques'] );
		$this->assertSame( 1, $daily['2026-10-03']['clicks'] );
		$this->assertSame( 0, $daily['2020-01-01']['clicks'] ?? 0 );

		$fresh = ( new Repository() )->find( $link->id );
		$this->assertSame( 3, $fresh->total_clicks );
		$this->assertSame( '2026-10-03 09:00:00', $fresh->last_clicked_at );
	}

	/**
	 * Range deletes only touch the given link and span.
	 */
	public function test_delete_range() {
		$link  = $this->create_link();
		$other = $this->create_link();
		$this->add( $link->id, '2026-10-01 10:00:00' );
		$this->add( $link->id, '2026-10-05 10:00:00' );
		$this->add( $other->id, '2026-10-01 10:00:00' );

		$this->store->delete_range( $link->id, '2026-10-01 00:00:00', '2026-10-02 00:00:00' );

		$this->assertSame( 1, $this->count_rows( 'clicks', 'link_id = ' . $link->id ) );
		$this->assertSame( 1, $this->count_rows( 'clicks', 'link_id = ' . $other->id ) );
	}

	/**
	 * Stats fill missing days and break raw clicks down by dimension.
	 */
	public function test_stats() {
		$link = $this->create_link();
		$now  = time();
		$this->add( $link->id, gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS ), 1, 'DE' );
		$this->add( $link->id, gmdate( 'Y-m-d H:i:s', $now - 2 * HOUR_IN_SECONDS ), 1, 'DE' );
		$this->add( $link->id, gmdate( 'Y-m-d H:i:s', $now - 3 * HOUR_IN_SECONDS ), 1, 'TR' );
		$this->add( $link->id, gmdate( 'Y-m-d H:i:s', $now - 40 * DAY_IN_SECONDS ), 1, 'US' );

		$stats = new Stats();

		$daily = $stats->daily( $link->id, 7 );
		$this->assertCount( 7, $daily );
		$this->assertSame( wp_date( 'Y-m-d' ), end( $daily )['day'] );

		$countries = $stats->breakdown( $link->id, 'country', gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS ) );
		$this->assertSame(
			[
				[
					'value'  => 'DE',
					'clicks' => 2,
				],
				[
					'value'  => 'TR',
					'clicks' => 1,
				],
			],
			$countries
		);
		$this->assertSame( [], $stats->breakdown( $link->id, 'visitor_hash', '2000-01-01 00:00:00' ) );
	}

	/**
	 * Retention deletes old raw clicks only when configured; daily totals stay.
	 */
	public function test_retention() {
		$link = $this->create_link();
		$this->add( $link->id, gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) );
		$this->add( $link->id, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$this->store->set_daily( $link->id, '2020-01-01', 5, 5, 0 );

		( new Retention() )->prune();
		$this->assertSame( 2, $this->count_rows( 'clicks' ) );

		$this->settings( [ 'retention_days' => 30 ] );
		( new Retention() )->prune();
		$this->assertSame( 1, $this->count_rows( 'clicks' ) );
		$this->assertTrue( $this->store->has_daily( $link->id ) );
	}
}
