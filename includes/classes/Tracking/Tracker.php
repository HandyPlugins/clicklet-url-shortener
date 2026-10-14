<?php
/**
 * Click recording.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tracking;

use FastUrlShortener\Links\Link;
use FastUrlShortener\Links\Repository;
use FastUrlShortener\Settings;

/**
 * Records clicks without remote calls and without storing raw IPs.
 *
 * A human click costs one lookup and three writes, run after the redirect
 * response has been sent where the server allows it. Bots only bump a daily
 * counter.
 */
class Tracker {

	/**
	 * Headers CDNs and hosts use to pass the visitor's country.
	 */
	const COUNTRY_HEADERS = [
		'HTTP_CF_IPCOUNTRY',
		'HTTP_CLOUDFRONT_VIEWER_COUNTRY',
		'HTTP_X_COUNTRY_CODE',
		'HTTP_X_GEO_COUNTRY',
		'HTTP_X_APPENGINE_COUNTRY',
		'GEOIP_COUNTRY_CODE',
	];

	/**
	 * Link storage.
	 *
	 * @var Repository
	 */
	protected $links;

	/**
	 * Click storage.
	 *
	 * @var ClickStore
	 */
	protected $clicks;

	/**
	 * Constructor.
	 *
	 * @param Repository $links  Link storage.
	 * @param ClickStore $clicks Click storage.
	 */
	public function __construct( Repository $links, ClickStore $clicks ) {
		$this->links  = $links;
		$this->clicks = $clicks;
	}

	/**
	 * Record a click on a link.
	 *
	 * @param Link $link Link.
	 *
	 * @return void
	 */
	public function record( Link $link ) {
		$user_agent = $this->server( 'HTTP_USER_AGENT' );
		$agent      = UserAgent::parse( $user_agent );
		$day        = wp_date( 'Y-m-d' );

		if ( $agent['is_bot'] ) {
			$this->clicks->bump_daily( $link->id, $day, 0, 0, 1 );
			return;
		}

		$now  = current_time( 'mysql', true );
		$hash = ClickStore::visitor_hash( $this->ip(), $user_agent, $day );

		$click = [
			'link_id'       => $link->id,
			'clicked_at'    => $now,
			'is_unique'     => $this->clicks->is_new_visitor( $link->id, $hash ) ? 1 : 0,
			'country'       => $this->country(),
			'device'        => $agent['device'],
			'os'            => $agent['os'],
			'browser'       => $agent['browser'],
			'referrer_host' => self::host( $this->server( 'HTTP_REFERER' ) ),
		];

		$this->clicks->insert( $click, $hash );
		$this->clicks->bump_daily( $link->id, $day, 1, $click['is_unique'], 0 );
		$this->links->increment_clicks( $link, $now );

		/**
		 * Fires after a human click is recorded.
		 *
		 * @hook fast_url_shortener_click_recorded
		 *
		 * @param Link  $link  Link.
		 * @param array $click Stored click data.
		 */
		do_action( 'fast_url_shortener_click_recorded', $link, $click );
	}

	/**
	 * Host of a URL, lowercased and without "www.", or an empty string.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	public static function host( $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );

		return substr( preg_replace( '/^www\./', '', $host ), 0, 191 );
	}

	/**
	 * Uppercase ISO country code, or an empty string for unknown values.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	public static function country_code( $value ) {
		$code = strtoupper( trim( (string) $value ) );

		// XX and T1 are Cloudflare's unknown and Tor values.
		return preg_match( '/^[A-Z]{2}$/', $code ) && 'XX' !== $code && 'T1' !== $code ? $code : '';
	}

	/**
	 * Visitor IP. REMOTE_ADDR unless a trusted proxy header is configured,
	 * because forwarded headers can be forged by anyone.
	 *
	 * @return string
	 */
	protected function ip() {
		$header = (string) Settings::get( 'ip_header' );
		$value  = '' !== $header ? $this->server( $header ) : '';

		if ( '' === $value ) {
			$value = $this->server( 'REMOTE_ADDR' );
		}

		// Forwarded headers may hold a list; the client is first.
		$ip = trim( explode( ',', $value )[0] );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Country from CDN or host headers, or an empty string.
	 *
	 * @return string
	 */
	protected function country() {
		foreach ( self::COUNTRY_HEADERS as $header ) {
			$code = self::country_code( $this->server( $header ) );

			if ( '' !== $code ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * Sanitized $_SERVER value.
	 *
	 * @param string $key Key.
	 *
	 * @return string
	 */
	protected function server( $key ) {
		return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}
}
