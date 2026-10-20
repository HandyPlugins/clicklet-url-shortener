<?php
/**
 * Link value object.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Links\Link;

/**
 * @covers \Clicklet\Links\Link
 */
class LinkTest extends TestCase {

	/**
	 * A link built from a row.
	 *
	 * @param array $overrides Column overrides.
	 *
	 * @return Link
	 */
	protected function link( array $overrides = [] ) {
		return Link::from_row(
			array_merge(
				[
					'id'              => '7',
					'slug'            => 'promo',
					'target_url'      => 'https://example.com/',
					'title'           => '',
					'redirect_type'   => '302',
					'status'          => 'active',
					'forward_params'  => '0',
					'track_clicks'    => '1',
					'expires_at'      => null,
					'max_clicks'      => null,
					'total_clicks'    => '0',
					'last_clicked_at' => null,
					'author_id'       => '1',
					'created_at'      => '2026-01-01 00:00:00',
					'updated_at'      => '2026-01-01 00:00:00',
				],
				$overrides
			)
		);
	}

	/**
	 * Row values are cast to their types.
	 */
	public function test_from_row_casts() {
		$link = $this->link();

		$this->assertSame( 7, $link->id );
		$this->assertSame( 302, $link->redirect_type );
		$this->assertFalse( $link->forward_params );
		$this->assertTrue( $link->track_clicks );
		$this->assertNull( $link->max_clicks );
	}

	/**
	 * Status, expiry and click limits.
	 */
	public function test_is_available() {
		$this->assertTrue( $this->link()->is_available() );
		$this->assertFalse( $this->link( [ 'status' => 'disabled' ] )->is_available() );
		$this->assertFalse( $this->link( [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] )->is_available() );
		$this->assertTrue( $this->link( [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ] )->is_available() );
		$this->assertFalse(
			$this->link(
				[
					'max_clicks'   => '5',
					'total_clicks' => '5',
				]
			)->is_available()
		);
		$this->assertTrue(
			$this->link(
				[
					'max_clicks'   => '5',
					'total_clicks' => '4',
				]
			)->is_available()
		);
	}

	/**
	 * Short URLs include the prefix.
	 */
	public function test_short_url() {
		$this->assertSame( home_url( '/promo' ), $this->link()->short_url() );

		$this->settings( [ 'prefix' => 'go' ] );
		$this->assertSame( home_url( '/go/promo' ), $this->link()->short_url() );
		$this->assertSame( home_url( '/go/promo' ), $this->link()->to_array()['short_url'] );
	}
}
