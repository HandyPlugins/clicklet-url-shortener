<?php
/**
 * Link storage.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Links\Repository;
use Clicklet\Tracking\ClickStore;

/**
 * @covers \Clicklet\Links\Repository
 */
class RepositoryTest extends TestCase {

	/**
	 * Repository under test.
	 *
	 * @var Repository
	 */
	protected $links;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->links = new Repository();
	}

	/**
	 * Defaults and generated slugs.
	 */
	public function test_create_with_defaults() {
		$this->settings(
			[
				'redirect_type' => 307,
				'slug_length'   => 7,
			]
		);

		$link = $this->create_link( [ 'target_url' => 'https://example.com/landing' ] );

		$this->assertSame( 7, strlen( $link->slug ) );
		$this->assertSame( 307, $link->redirect_type );
		$this->assertSame( 'active', $link->status );
		$this->assertTrue( $link->track_clicks );
		$this->assertSame( $link->slug, $this->links->find_by_slug( $link->slug )->slug );
	}

	/**
	 * Target URLs need an http(s) scheme and a host without spaces.
	 *
	 * @return array
	 */
	public function target_urls() {
		return [
			[ 'https://example.com', true ],
			[ 'http://localhost:8080/x', true ],
			[ 'https://example.com?x=1', true ],
			[ 'https://örnek.com.tr/çiçek', true ],
			[ 'https://example.com/a b', true ],
			[ 'not a url', false ],
			[ 'example.com/x', false ],
			[ 'https://exa mple.com', false ],
			[ 'ftp://example.com', false ],
			[ 'javascript:alert(1)', false ],
			[ '', false ],
		];
	}

	/**
	 * @dataProvider target_urls
	 *
	 * @param string $url   URL.
	 * @param bool   $valid Expected validity.
	 */
	public function test_target_url_validation( $url, $valid ) {
		$result = $this->links->create( [ 'target_url' => $url ] );

		if ( $valid ) {
			$this->assertNotWPError( $result );
		} else {
			$this->assertWPError( $result );
			$this->assertSame( 'clicklet_invalid_target', $result->get_error_code() );
		}
	}

	/**
	 * Slug rules: format, reserved names, uniqueness (case-insensitive by
	 * default) and conflicts with published content.
	 */
	public function test_slug_validation() {
		$this->create_link( [ 'slug' => 'taken' ] );
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_status' => 'publish',
			]
		);

		$cases = [
			'go/promo' => 'clicklet_invalid_slug',
			'wp-admin' => 'clicklet_reserved_slug',
			'TAKEN'    => 'clicklet_slug_exists',
			'about'    => 'clicklet_slug_conflict',
		];

		foreach ( $cases as $slug => $code ) {
			$result = $this->links->create(
				[
					'target_url' => 'https://example.com/',
					'slug'       => $slug,
				]
			);
			$this->assertWPError( $result, $slug );
			$this->assertSame( $code, $result->get_error_code(), $slug );
		}

		$forced = $this->links->create(
			[
				'target_url' => 'https://example.com/',
				'slug'       => 'about',
			],
			true
		);
		$this->assertNotWPError( $forced );
	}

	/**
	 * Other fields are validated and normalized.
	 */
	public function test_field_validation() {
		$this->assertSame(
			'clicklet_invalid_redirect_type',
			$this->links->create(
				[
					'target_url'    => 'https://example.com/',
					'redirect_type' => 303,
				]
			)->get_error_code()
		);
		$this->assertSame(
			'clicklet_invalid_status',
			$this->links->create(
				[
					'target_url' => 'https://example.com/',
					'status'     => 'trash',
				]
			)->get_error_code()
		);
		$this->assertSame(
			'clicklet_invalid_expiry',
			$this->links->create(
				[
					'target_url' => 'https://example.com/',
					'expires_at' => 'not a date',
				]
			)->get_error_code()
		);

		$link = $this->create_link(
			[
				'title'          => '<b>Promo</b>',
				'forward_params' => '1',
				'max_clicks'     => 0,
			]
		);

		$this->assertSame( 'Promo', $link->title );
		$this->assertTrue( $link->forward_params );
		$this->assertNull( $link->max_clicks );
	}

	/**
	 * Expiry is entered in the site timezone and stored in UTC.
	 */
	public function test_expiry_is_stored_in_utc() {
		update_option( 'timezone_string', 'Europe/Istanbul' );

		$link = $this->create_link( [ 'expires_at' => '2030-06-01 12:00' ] );

		$this->assertSame( '2030-06-01 09:00:00', $link->expires_at );
	}

	/**
	 * Importers may keep the original creation date; updates may not change it.
	 */
	public function test_created_at_only_on_create() {
		$link = $this->create_link( [ 'created_at' => '2024-11-20 08:30:00' ] );
		$this->assertSame( '2024-11-20 08:30:00', $link->created_at );

		$updated = $this->links->update( $link->id, [ 'created_at' => '2020-01-01 00:00:00' ] );
		$this->assertSame( '2024-11-20 08:30:00', $updated->created_at );
	}

	/**
	 * Updates only touch given fields and refresh the slug cache.
	 */
	public function test_update_and_cache() {
		$link = $this->create_link(
			[
				'slug'  => 'old',
				'title' => 'Keep me',
			]
		);

		// Warm the cache, including a miss for the new slug.
		$this->assertNotNull( $this->links->find_by_slug( 'old' ) );
		$this->assertNull( $this->links->find_by_slug( 'new' ) );

		$updated = $this->links->update(
			$link->id,
			[
				'slug'       => 'new',
				'target_url' => 'https://example.com/changed',
			]
		);

		$this->assertSame( 'Keep me', $updated->title );
		$this->assertNull( $this->links->find_by_slug( 'old' ) );
		$this->assertSame( 'https://example.com/changed', $this->links->find_by_slug( 'new' )->target_url );
		$this->assertSame( 'clicklet_not_found', $this->links->update( 999999, [ 'title' => 'x' ] )->get_error_code() );
	}

	/**
	 * Deleting a link removes its clicks and daily totals.
	 */
	public function test_delete_removes_click_data() {
		$link   = $this->create_link();
		$other  = $this->create_link();
		$clicks = new ClickStore();

		foreach ( [ $link, $other ] as $item ) {
			$clicks->insert( $this->click( $item->id ), str_repeat( 'a', 32 ) );
			$clicks->bump_daily( $item->id, '2026-10-01', 1, 1, 0 );
		}

		$this->assertTrue( $this->links->delete( $link->id ) );
		$this->assertFalse( $this->links->delete( $link->id ) );
		$this->assertNull( $this->links->find_by_slug( $link->slug ) );
		$this->assertSame( 0, $this->count_rows( 'clicks', 'link_id = ' . $link->id ) );
		$this->assertSame( 0, $this->count_rows( 'stats_daily', 'link_id = ' . $link->id ) );
		$this->assertSame( 1, $this->count_rows( 'clicks', 'link_id = ' . $other->id ) );
	}

	/**
	 * Search, sorting and pagination.
	 */
	public function test_query() {
		$this->create_link(
			[
				'slug'  => 'alpha',
				'title' => 'Summer sale',
			]
		);
		$this->create_link(
			[
				'slug'       => 'beta',
				'target_url' => 'https://shop.example.com/sale',
			]
		);
		$this->create_link( [ 'slug' => 'gamma' ] );

		$result = $this->links->query(
			[
				'search'  => 'sale',
				'orderby' => 'slug',
				'order'   => 'asc',
			]
		);
		$this->assertSame( 2, $result['total'] );
		$this->assertSame( [ 'alpha', 'beta' ], wp_list_pluck( $result['items'], 'slug' ) );

		$page = $this->links->query(
			[
				'orderby'  => 'slug',
				'order'    => 'desc',
				'per_page' => 2,
				'page'     => 2,
			]
		);
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( [ 'alpha' ], wp_list_pluck( $page['items'], 'slug' ) );

		// Unknown sort columns fall back to the ID instead of reaching SQL.
		$this->assertSame( 3, $this->links->query( [ 'orderby' => 'slug; DROP TABLE x' ] )['total'] );
	}

	/**
	 * Click counter increments are atomic and refresh the cache for limited links.
	 */
	public function test_increment_clicks() {
		$link = $this->create_link( [ 'max_clicks' => 1 ] );
		$this->assertTrue( $this->links->find_by_slug( $link->slug )->is_available() );

		$this->links->increment_clicks( $link, '2026-10-01 10:00:00' );

		$fresh = $this->links->find_by_slug( $link->slug );
		$this->assertSame( 1, $fresh->total_clicks );
		$this->assertSame( '2026-10-01 10:00:00', $fresh->last_clicked_at );
		$this->assertFalse( $fresh->is_available() );
	}

	/**
	 * Click row for a link.
	 *
	 * @param int $link_id Link ID.
	 *
	 * @return array
	 */
	protected function click( $link_id ) {
		return [
			'link_id'       => $link_id,
			'clicked_at'    => '2026-10-01 10:00:00',
			'is_unique'     => 1,
			'country'       => '',
			'device'        => 0,
			'os'            => 0,
			'browser'       => 0,
			'referrer_host' => '',
		];
	}
}
