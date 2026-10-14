<?php
/**
 * Click recording.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tracking;

use FastUrlShortener\Install;
use FastUrlShortener\Links\Link;
use FastUrlShortener\Links\Repository;
use FastUrlShortener\Schema;
use FastUrlShortener\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write path on the plugin's own tables.

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
	 * Constructor.
	 *
	 * @param Repository $links Link storage.
	 */
	public function __construct( Repository $links ) {
		$this->links = $links;
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
			$this->bump_daily( $link->id, $day, 'bots' );
			return;
		}

		$now  = current_time( 'mysql', true );
		$hash = $this->visitor_hash( $user_agent, $day );

		$click = [
			'link_id'       => $link->id,
			'clicked_at'    => $now,
			'is_unique'     => $this->is_new_visitor( $link->id, $hash ) ? 1 : 0,
			'country'       => $this->country(),
			'device'        => $agent['device'],
			'os'            => $agent['os'],
			'browser'       => $agent['browser'],
			'referrer_host' => $this->referrer_host(),
		];

		$this->insert_click( $click, $hash );
		$this->bump_daily( $link->id, $day, $click['is_unique'] ? 'unique' : 'click' );
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
	 * Insert the click row. The binary hash goes in as hex so the query stays ASCII.
	 *
	 * @param array  $click Click columns.
	 * @param string $hash  Visitor hash, 32 hex characters.
	 *
	 * @return void
	 */
	protected function insert_click( array $click, $hash ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (link_id, clicked_at, visitor_hash, is_unique, country, device, os, browser, referrer_host) VALUES (%d, %s, UNHEX(%s), %d, %s, %d, %d, %d, %s)',
				Schema::table( 'clicks' ),
				$click['link_id'],
				$click['clicked_at'],
				$hash,
				$click['is_unique'],
				$click['country'],
				$click['device'],
				$click['os'],
				$click['browser'],
				$click['referrer_host']
			)
		);
	}

	/**
	 * Increment today's totals for a link.
	 *
	 * @param int    $link_id Link ID.
	 * @param string $day     Site-local date, Y-m-d.
	 * @param string $kind    "click", "unique" (a click by a new visitor) or "bots".
	 *
	 * @return void
	 */
	protected function bump_daily( $link_id, $day, $kind ) {
		global $wpdb;

		$clicks  = 'bots' === $kind ? 0 : 1;
		$uniques = 'unique' === $kind ? 1 : 0;
		$bots    = 'bots' === $kind ? 1 : 0;

		$wpdb->query(
			$wpdb->prepare(
				// VALUES() is deprecated on MySQL 8 and row aliases are not supported by MariaDB, so the increments are repeated.
				'INSERT INTO %i (link_id, day, clicks, uniques, bots) VALUES (%d, %s, %d, %d, %d)
				ON DUPLICATE KEY UPDATE clicks = clicks + %d, uniques = uniques + %d, bots = bots + %d',
				Schema::table( 'stats_daily' ),
				$link_id,
				$day,
				$clicks,
				$uniques,
				$bots,
				$clicks,
				$uniques,
				$bots
			)
		);
	}

	/**
	 * Whether this visitor has not clicked the link yet today.
	 *
	 * The hash rotates daily, so a match within the last day is the same
	 * visitor on the same day.
	 *
	 * @param int    $link_id Link ID.
	 * @param string $hash    Visitor hash.
	 *
	 * @return bool
	 */
	protected function is_new_visitor( $link_id, $hash ) {
		global $wpdb;

		$seen = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE link_id = %d AND clicked_at >= %s AND visitor_hash = UNHEX(%s) LIMIT 1',
				Schema::table( 'clicks' ),
				$link_id,
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
				$hash
			)
		);

		return ! $seen;
	}

	/**
	 * Anonymous visitor ID: keyed hash of IP and user agent with a secret that
	 * changes every day. It cannot be reversed or linked across days.
	 *
	 * @param string $user_agent User agent.
	 * @param string $day        Site-local date.
	 *
	 * @return string 32 hex characters.
	 */
	protected function visitor_hash( $user_agent, $day ) {
		$secret = (string) get_option( Install::SECRET_OPTION, '' );

		return substr( hash_hmac( 'sha256', $this->ip() . '|' . $user_agent, $secret . '|' . $day ), 0, 32 );
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
	 * ISO country code from CDN or host headers, or an empty string.
	 *
	 * @return string
	 */
	protected function country() {
		foreach ( self::COUNTRY_HEADERS as $header ) {
			$code = strtoupper( $this->server( $header ) );

			// XX and T1 are Cloudflare's unknown and Tor values.
			if ( preg_match( '/^[A-Z]{2}$/', $code ) && 'XX' !== $code && 'T1' !== $code ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * Referring host without "www.", or an empty string for direct visits.
	 *
	 * @return string
	 */
	protected function referrer_host() {
		$host = strtolower( (string) wp_parse_url( $this->server( 'HTTP_REFERER' ), PHP_URL_HOST ) );

		return substr( preg_replace( '/^www\./', '', $host ), 0, 191 );
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
