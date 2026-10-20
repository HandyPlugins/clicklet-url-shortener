<?php
/**
 * Password-protected links.
 *
 * @package Clicklet
 */

namespace Clicklet\Redirect;

use Clicklet\Links\Link;
use Clicklet\Tracking\Tracker;

/**
 * Asks for the password of a protected link before redirecting.
 *
 * Runs on template_redirect, after init, so the page can be translated. The
 * form is a bare wp_die() page: no theme, nothing to cache. Failed attempts
 * are limited per link and visitor.
 */
class PasswordGate {

	/**
	 * Form field name.
	 */
	const FIELD = 'clicklet_password';

	/**
	 * Failed attempts allowed per window.
	 */
	const MAX_ATTEMPTS = 10;

	/**
	 * Attempt window in seconds.
	 */
	const WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * Router, which sends the redirect once the password is right.
	 *
	 * @var Router
	 */
	protected $router;

	/**
	 * Protected link.
	 *
	 * @var Link
	 */
	protected $link;

	/**
	 * Constructor.
	 *
	 * @param Router $router Router.
	 * @param Link   $link   Protected link.
	 */
	public function __construct( Router $router, Link $link ) {
		$this->router = $router;
		$this->link   = $link;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'template_redirect', [ $this, 'handle' ], 0 );
	}

	/**
	 * Check a submitted password or show the form.
	 *
	 * @return void
	 */
	public function handle() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Page cache convention.
		}

		nocache_headers();

		// A password form for a public link: there is no user session to protect with a nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ self::FIELD ] ) ) {
			$this->render( '', 200 );
			return;
		}

		$key      = 'clicklet_pw_' . md5( $this->link->id . '|' . Tracker::client_ip() );
		$attempts = (int) get_transient( $key );

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$this->render( __( 'Too many attempts. Try again in a few minutes.', 'clicklet-url-shortener' ), 429 );
			return;
		}

		// Passwords are compared as typed; sanitizing would change them.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$password = is_string( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : '';

		if ( $this->link->check_password( $password ) ) {
			delete_transient( $key );
			$this->router->redirect( $this->link );
			return;
		}

		set_transient( $key, $attempts + 1, self::WINDOW );
		$this->render( __( 'That password is not correct.', 'clicklet-url-shortener' ), 403 );
	}

	/**
	 * Show the form and stop.
	 *
	 * @param string $error  Error message, or empty.
	 * @param int    $status HTTP status.
	 *
	 * @return void
	 */
	protected function render( $error, $status ) {
		$html  = '<h1>' . esc_html__( 'This link is password protected', 'clicklet-url-shortener' ) . '</h1>';
		$html .= '<p>' . esc_html__( 'Enter the password to continue.', 'clicklet-url-shortener' ) . '</p>';

		if ( '' !== $error ) {
			$html .= '<p role="alert"><strong>' . esc_html( $error ) . '</strong></p>';
		}

		$html .= '<form method="post">';
		$html .= '<p><label for="clicklet-password">' . esc_html__( 'Password', 'clicklet-url-shortener' ) . '</label><br />';
		$html .= '<input type="password" id="clicklet-password" name="' . esc_attr( self::FIELD ) . '" required autofocus autocomplete="current-password" style="width:100%;padding:6px;" /></p>';
		$html .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Continue', 'clicklet-url-shortener' ) . '</button></p>';
		$html .= '</form>';

		wp_die(
			$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
			esc_html__( 'Password required', 'clicklet-url-shortener' ),
			[ 'response' => absint( $status ) ]
		);
	}
}
