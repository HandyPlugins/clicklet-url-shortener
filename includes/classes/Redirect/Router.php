<?php
/**
 * Short link router.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Redirect;

use FastUrlShortener\Install;
use FastUrlShortener\Links\Link;
use FastUrlShortener\Links\Repository;
use FastUrlShortener\Links\Slug;
use FastUrlShortener\Settings;
use FastUrlShortener\Tracking\ClickStore;
use FastUrlShortener\Tracking\Stats;
use FastUrlShortener\Tracking\Tracker;

/**
 * Matches the request against short links and redirects.
 *
 * Runs from the fast path drop-in on `muplugins_loaded` or from the plugin on
 * `plugins_loaded`, whichever comes first. Requests that cannot be a short
 * link are rejected from the path alone, without touching the database.
 */
class Router {

	/**
	 * Query parameter QR codes add to the short URL, to count scans separately.
	 */
	const QR_PARAM = 'qr';

	/**
	 * Whether this request was already routed.
	 *
	 * @var bool
	 */
	protected static $routed = false;

	/**
	 * Link storage.
	 *
	 * @var Repository
	 */
	protected $links;

	/**
	 * Constructor.
	 *
	 * @param Repository $links Link storage.
	 */
	public function __construct( Repository $links ) {
		$this->links = $links;
	}

	/**
	 * Route the current request once.
	 *
	 * @return void
	 */
	public static function route() {
		if ( self::$routed ) {
			return;
		}

		self::$routed = true;

		( new self( new Repository() ) )->maybe_redirect();
	}

	/**
	 * Redirect when the request matches an available link.
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( ! $this->is_routable_request() || ! Install::is_installed() ) {
			return;
		}

		$slug = $this->slug_from_path( $this->request_path() );

		if ( null === $slug ) {
			return;
		}

		$link = $this->links->find_by_slug( $slug );

		if ( ! $link ) {
			return;
		}

		// Links with extension rules need every plugin loaded: leave them to the normal path.
		if ( $link->has_rules && ! did_action( 'plugins_loaded' ) ) {
			self::$routed = false;
			return;
		}

		if ( ! $link->is_available() ) {
			return;
		}

		// The password form is translated, so it waits for template_redirect (after init).
		if ( $link->has_password() ) {
			if ( did_action( 'plugins_loaded' ) ) {
				( new PasswordGate( $this, $link ) )->register();
			} else {
				self::$routed = false;
			}

			return;
		}

		if ( 'POST' === $this->method() ) {
			return;
		}

		$this->redirect( $link );
	}

	/**
	 * Extract the slug from a site-relative path.
	 *
	 * Links are a single segment at the root, or under the configured prefix.
	 *
	 * @param string $path Path relative to the site home, without slashes around it.
	 *
	 * @return string|null
	 */
	public function slug_from_path( $path ) {
		$prefix = trim( (string) Settings::get( 'prefix' ), '/' );

		if ( '' !== $prefix ) {
			if ( 0 !== strpos( $path, $prefix . '/' ) ) {
				return null;
			}

			$path = substr( $path, strlen( $prefix ) + 1 );
		}

		$slug = Slug::normalize( $path );

		return Slug::is_valid( $slug ) ? $slug : null;
	}

	/**
	 * Only front-end GET/HEAD requests can be short links.
	 *
	 * @return bool
	 */
	protected function is_routable_request() {
		$method = $this->method();

		// POST only reaches password-protected links (see maybe_redirect()).
		if ( ! in_array( $method, [ 'GET', 'HEAD', 'POST' ], true ) ) {
			return false;
		}

		return ! ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) );
	}

	/**
	 * HTTP method.
	 *
	 * @return string
	 */
	protected function method() {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/**
	 * Request path relative to the site home, without slashes around it.
	 *
	 * @return string
	 */
	protected function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );

		// WordPress installed in a subdirectory.
		if ( '' !== $home ) {
			if ( 0 !== strpos( $path . '/', $home . '/' ) ) {
				return '';
			}

			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}

		return $path;
	}

	/**
	 * Send the redirect, then record the click after the visitor has left.
	 * Ends the request.
	 *
	 * @param Link $link Link.
	 *
	 * @return void
	 */
	public function redirect( Link $link ) {
		/**
		 * Filter the redirect destination.
		 *
		 * Note: with the fast path enabled this runs before regular plugins load,
		 * so only callbacks added by must-use plugins apply.
		 *
		 * @hook fast_url_shortener_redirect_target
		 *
		 * @param string $target Destination URL.
		 * @param Link   $link   Link.
		 */
		$target = (string) apply_filters( 'fast_url_shortener_redirect_target', $this->target( $link ), $link );

		// Guard against header injection from filtered values.
		$target = str_replace( [ "\r", "\n" ], '', $target );

		if ( '' === $target ) {
			return;
		}

		/**
		 * Fires right before a short link redirects.
		 *
		 * @hook fast_url_shortener_before_redirect
		 *
		 * @param Link   $link   Link.
		 * @param string $target Destination URL.
		 */
		do_action( 'fast_url_shortener_before_redirect', $link, $target );

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Page cache convention.
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Redirect-By: Fast URL Shortener' );
		/**
		 * Filter the HTTP status code of the redirect.
		 *
		 * @hook fast_url_shortener_redirect_status
		 *
		 * @param int  $status Status code from the link.
		 * @param Link $link   Link.
		 */
		$status = (int) apply_filters( 'fast_url_shortener_redirect_status', $link->redirect_type, $link );

		header( 'Location: ' . $target, true, $status );

		if ( $this->should_track( $link ) ) {
			$this->finish_response();
			( new Tracker( $this->links, new ClickStore() ) )->record( $link, $this->is_qr_scan() ? Stats::SOURCE_QR : Stats::SOURCE_LINK );
		}

		exit;
	}

	/**
	 * Destination URL, with the incoming query string when forwarding is on.
	 *
	 * @param Link $link Link.
	 *
	 * @return string
	 */
	protected function target( Link $link ) {
		$target = $link->target_url;
		// Keep percent-encoding intact (sanitize_text_field() would strip it); allow printable ASCII only.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the regex.
		$query = isset( $_SERVER['QUERY_STRING'] ) ? (string) preg_replace( '/[^\x21-\x7E]/', '', wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';

		// The QR marker is ours; never pass it on.
		$query = trim( (string) preg_replace( '/(^|&)' . self::QR_PARAM . '=1(?=&|$)/', '', $query ), '&' );

		if ( ! $link->forward_params || '' === $query ) {
			return $target;
		}

		$fragment = '';
		$hash     = strpos( $target, '#' );

		if ( false !== $hash ) {
			$fragment = substr( $target, $hash );
			$target   = substr( $target, 0, $hash );
		}

		return $target . ( false === strpos( $target, '?' ) ? '?' : '&' ) . $query . $fragment;
	}

	/**
	 * Whether the visit came from the link's QR code, which encodes ?qr=1.
	 *
	 * @return bool
	 */
	protected function is_qr_scan() {
		return isset( $_GET[ self::QR_PARAM ] ) && '1' === $_GET[ self::QR_PARAM ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- Read-only flag compared to a literal.
	}

	/**
	 * Whether this request should be counted.
	 *
	 * HEAD requests and browser prefetches are not visits.
	 *
	 * @param Link $link Link.
	 *
	 * @return bool
	 */
	protected function should_track( Link $link ) {
		if ( ! $link->track_clicks || ! Settings::get( 'track_clicks' ) ) {
			return false;
		}

		if ( 'HEAD' === $this->method() ) {
			return false;
		}

		foreach ( [ 'HTTP_SEC_PURPOSE', 'HTTP_PURPOSE', 'HTTP_X_PURPOSE', 'HTTP_X_MOZ' ] as $header ) {
			if ( isset( $_SERVER[ $header ] ) && false !== stripos( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ), 'prefetch' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Send the response to the visitor before recording the click.
	 *
	 * Without FastCGI or LiteSpeed the click is recorded inline, which costs a
	 * few indexed queries and no remote calls.
	 *
	 * @return void
	 */
	protected function finish_response() {
		ignore_user_abort( true );

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
	}
}
