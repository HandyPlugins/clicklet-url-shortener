<?php
/**
 * Slug helpers.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Links\Slug;

/**
 * @covers \Clicklet\Links\Slug
 */
class SlugTest extends TestCase {

	/**
	 * Slugs are lowercased unless case-sensitive, and trimmed.
	 */
	public function test_normalize() {
		$this->assertSame( 'promo', Slug::normalize( ' /Promo/ ' ) );

		$this->settings( [ 'case_sensitive' => true ] );
		$this->assertSame( 'Promo', Slug::normalize( 'Promo' ) );
	}

	/**
	 * Valid formats.
	 */
	public function test_is_valid() {
		foreach ( [ 'a', 'promo', 'Promo_2026', 'black-friday', str_repeat( 'a', 100 ) ] as $slug ) {
			$this->assertTrue( Slug::is_valid( $slug ), $slug );
		}

		foreach ( [ '', '-promo', '_x', 'go/promo', 'çiçek', 'a b', 'a.b', str_repeat( 'a', 101 ) ] as $slug ) {
			$this->assertFalse( Slug::is_valid( $slug ), $slug );
		}
	}

	/**
	 * WordPress paths are reserved at the root only.
	 */
	public function test_is_reserved() {
		$this->assertTrue( Slug::is_reserved( 'wp-admin' ) );
		$this->assertTrue( Slug::is_reserved( 'Feed' ) );
		$this->assertFalse( Slug::is_reserved( 'promo' ) );

		$this->settings( [ 'prefix' => 'go' ] );
		$this->assertFalse( Slug::is_reserved( 'wp-admin' ) );
	}

	/**
	 * Published content at the root conflicts, unless a prefix is used.
	 */
	public function test_conflicting_post() {
		$page = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => 'about',
				'post_status' => 'publish',
			]
		);

		$this->assertSame( $page, Slug::conflicting_post( 'about' )->ID );
		$this->assertNull( Slug::conflicting_post( 'nothing-here' ) );

		$this->settings( [ 'prefix' => 'go' ] );
		$this->assertNull( Slug::conflicting_post( 'about' ) );
	}

	/**
	 * Generated slugs have the requested length and avoid easily confused characters.
	 */
	public function test_generate() {
		$slug = Slug::generate( 12 );

		$this->assertSame( 12, strlen( $slug ) );
		$this->assertMatchesRegularExpression( '/^[abcdefghjkmnpqrstuvwxyz23456789]+$/', $slug );

		$this->settings( [ 'case_sensitive' => true ] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z2-9]{40}$/', Slug::generate( 40 ) );
	}
}
