<?php
/**
 * Short link router.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tests;

use FastUrlShortener\Links\Repository;
use FastUrlShortener\Redirect\Router;

/**
 * Redirects end the request with exit, so these tests cover how requests are
 * matched and how the destination is built.
 *
 * @covers \FastUrlShortener\Redirect\Router
 */
class RouterTest extends TestCase {

	/**
	 * Router under test.
	 *
	 * @var Router
	 */
	protected $router;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->router = new Router( new Repository() );
	}

	/**
	 * Call a protected method.
	 *
	 * @param string $method Method.
	 * @param array  $args   Arguments.
	 *
	 * @return mixed
	 */
	protected function call( $method, array $args = [] ) {
		$reflection = new \ReflectionMethod( $this->router, $method );
		$reflection->setAccessible( true );

		return $reflection->invokeArgs( $this->router, $args );
	}

	/**
	 * Only single-segment paths can be links at the root.
	 */
	public function test_slug_from_path_without_prefix() {
		$this->assertSame( 'promo', $this->router->slug_from_path( 'promo' ) );
		$this->assertSame( 'promo', $this->router->slug_from_path( 'PROMO' ) );
		$this->assertNull( $this->router->slug_from_path( '' ) );
		$this->assertNull( $this->router->slug_from_path( '2026/10/post' ) );
		$this->assertNull( $this->router->slug_from_path( 'style.css' ) );
		$this->assertNull( $this->router->slug_from_path( 'go/promo' ) );
	}

	/**
	 * With a prefix, only paths under it match.
	 */
	public function test_slug_from_path_with_prefix() {
		$this->settings( [ 'prefix' => 'go' ] );

		$this->assertSame( 'promo', $this->router->slug_from_path( 'go/promo' ) );
		$this->assertNull( $this->router->slug_from_path( 'promo' ) );
		$this->assertNull( $this->router->slug_from_path( 'go/a/b' ) );
		$this->assertNull( $this->router->slug_from_path( 'gone/promo' ) );
	}

	/**
	 * The request path is relative to the site home, also in a subdirectory.
	 */
	public function test_request_path() {
		$_SERVER['REQUEST_URI'] = '/promo/?utm_source=x';
		$this->assertSame( 'promo', $this->call( 'request_path' ) );

		update_option( 'home', 'http://example.org/blog' );

		$_SERVER['REQUEST_URI'] = '/blog/promo';
		$this->assertSame( 'promo', $this->call( 'request_path' ) );

		$_SERVER['REQUEST_URI'] = '/elsewhere/promo';
		$this->assertSame( '', $this->call( 'request_path' ) );
	}

	/**
	 * Query forwarding keeps percent-encoding and puts the fragment last.
	 */
	public function test_target_with_forwarded_params() {
		$link = $this->create_link(
			[
				'target_url'     => 'https://example.com/landing?ref=abc#top',
				'forward_params' => true,
			]
		);

		$_SERVER['QUERY_STRING'] = 'utm_source=a%20b&x=1';
		$this->assertSame( 'https://example.com/landing?ref=abc&utm_source=a%20b&x=1#top', $this->call( 'target', [ $link ] ) );

		$_SERVER['QUERY_STRING'] = "x=1\r\nSet-Cookie: evil";
		$this->assertStringNotContainsString( "\n", $this->call( 'target', [ $link ] ) );

		$plain = $this->create_link( [ 'target_url' => 'https://example.com/plain' ] );
		$this->assertSame( 'https://example.com/plain', $this->call( 'target', [ $plain ] ) );
	}

	/**
	 * HEAD requests, prefetches and untracked links are not counted.
	 */
	public function test_should_track() {
		$link = $this->create_link();

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertTrue( $this->call( 'should_track', [ $link ] ) );

		$_SERVER['HTTP_SEC_PURPOSE'] = 'prefetch;prerender';
		$this->assertFalse( $this->call( 'should_track', [ $link ] ) );
		unset( $_SERVER['HTTP_SEC_PURPOSE'] );

		$_SERVER['REQUEST_METHOD'] = 'HEAD';
		$this->assertFalse( $this->call( 'should_track', [ $link ] ) );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->settings( [ 'track_clicks' => false ] );
		$this->assertFalse( $this->call( 'should_track', [ $link ] ) );
	}

	/**
	 * Only front-end GET and HEAD requests are routed.
	 */
	public function test_is_routable_request() {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertTrue( $this->call( 'is_routable_request' ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertFalse( $this->call( 'is_routable_request' ) );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		set_current_screen( 'dashboard' );
		$this->assertFalse( $this->call( 'is_routable_request' ) );
		set_current_screen( 'front' );
	}
}
