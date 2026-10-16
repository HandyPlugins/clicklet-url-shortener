<?php
/**
 * Password-protected links.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tests;

use FastUrlShortener\Links\Link;
use FastUrlShortener\Links\Repository;
use FastUrlShortener\Redirect\PasswordGate;
use FastUrlShortener\Tests\Doubles\RecordingRouter;
use WP_REST_Request;

/**
 * @covers \FastUrlShortener\Redirect\PasswordGate
 * @covers \FastUrlShortener\Links\Link::check_password
 */
class PasswordGateTest extends TestCase {

	/**
	 * Router double.
	 *
	 * @var RecordingRouter
	 */
	protected $router;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->router           = new RecordingRouter( new Repository() );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$_POST                  = [];
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$_POST                     = [];
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Run the gate and return the wp_die() message, or null when it redirected.
	 *
	 * @param Link        $link     Link.
	 * @param string|null $password Submitted password, or null for a plain visit.
	 *
	 * @return string|null
	 */
	protected function visit( Link $link, $password = null ) {
		$_POST = null === $password ? [] : [ PasswordGate::FIELD => $password ];

		try {
			( new PasswordGate( $this->router, $link ) )->handle();
		} catch ( \WPDieException $e ) {
			return $e->getMessage();
		}

		return null;
	}

	/**
	 * Passwords are hashed, checked, and removable.
	 */
	public function test_password_storage() {
		$link = $this->create_link( [ 'password' => 's3cret' ] );

		$this->assertTrue( $link->has_password() );
		$this->assertTrue( $link->check_password( 's3cret' ) );
		$this->assertFalse( $link->check_password( 'S3cret' ) );
		$this->assertFalse( $link->check_password( '' ) );
		$this->assertNotSame( 's3cret', $link->password_hash() );
		$this->assertArrayNotHasKey( 'password_hash', $link->to_array() );
		$this->assertTrue( $link->to_array()['has_password'] );

		$links = new Repository();
		$this->assertTrue( $links->update( $link->id, [ 'title' => 'x' ] )->has_password(), 'Other updates keep it.' );
		$this->assertFalse( $links->update( $link->id, [ 'password' => '' ] )->has_password() );
		$this->assertSame( 'fast_url_shortener_invalid_password_hash', $links->update( $link->id, [ 'password_hash' => 'plain' ] )->get_error_code() );
	}

	/**
	 * A visit shows the form; the right password redirects.
	 */
	public function test_form_and_redirect() {
		$link = $this->create_link( [ 'password' => 's3cret' ] );

		$form = $this->visit( $link );
		$this->assertStringContainsString( 'name="' . PasswordGate::FIELD . '"', $form );
		$this->assertNull( $this->router->redirected );

		$this->assertNull( $this->visit( $link, 's3cret' ) );
		$this->assertSame( $link->id, $this->router->redirected->id );
	}

	/**
	 * Wrong passwords are rejected and limited per visitor.
	 */
	public function test_wrong_password_and_rate_limit() {
		$link = $this->create_link( [ 'password' => 's3cret' ] );

		$this->assertStringContainsString( 'not correct', $this->visit( $link, 'guess' ) );

		for ( $i = 1; $i < PasswordGate::MAX_ATTEMPTS; $i++ ) {
			$this->visit( $link, 'guess' );
		}

		$this->assertStringContainsString( 'Too many attempts', $this->visit( $link, 's3cret' ), 'Even the right password waits.' );
		$this->assertNull( $this->router->redirected );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->assertNull( $this->visit( $link, 's3cret' ), 'Other visitors are not blocked.' );
	}

	/**
	 * REST accepts a password but never returns it.
	 */
	public function test_rest_write_only() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new WP_REST_Request( 'POST', '/fast-url-shortener/v1/links' );
		$request->set_param( 'target_url', 'https://example.com/' );
		$request->set_param( 'password', 's3cret' );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertTrue( $data['has_password'] );
		$this->assertArrayNotHasKey( 'password_hash', $data );
		$this->assertStringNotContainsString( 's3cret', wp_json_encode( $data ) );
		$this->assertTrue( ( new Repository() )->find( $data['id'] )->check_password( 's3cret' ) );
	}
}
