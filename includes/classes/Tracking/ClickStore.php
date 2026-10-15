<?php
/**
 * Click storage.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tracking;

use FastUrlShortener\Install;
use FastUrlShortener\Links\Repository;
use FastUrlShortener\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Write path on the plugin's own tables.

/**
 * Writes clicks and daily totals. Shared by live tracking and importers.
 */
class ClickStore {

	/**
	 * Anonymous visitor ID: keyed hash of IP and user agent with a secret that
	 * changes every day. It cannot be reversed or linked across days.
	 *
	 * @param string $ip         IP address.
	 * @param string $user_agent User agent.
	 * @param string $day        Site-local date, Y-m-d.
	 *
	 * @return string 32 hex characters.
	 */
	public static function visitor_hash( $ip, $user_agent, $day ) {
		$secret = (string) get_option( Install::SECRET_OPTION, '' );

		return substr( hash_hmac( 'sha256', $ip . '|' . $user_agent, $secret . '|' . $day ), 0, 32 );
	}

	/**
	 * Insert a click row. The binary hash goes in as hex so the query stays ASCII.
	 *
	 * @param array  $click {
	 *     Click columns.
	 *
	 *     @type int    $link_id       Link ID.
	 *     @type string $clicked_at    UTC datetime.
	 *     @type int    $is_unique     1 for the visitor's first click of the day.
	 *     @type string $country       ISO country code or empty.
	 *     @type int    $device        UserAgent::DEVICE_* value.
	 *     @type int    $os            UserAgent::OS_* value.
	 *     @type int    $browser       UserAgent::BROWSER_* value.
	 *     @type string $referrer_host Referring host or empty.
	 * }
	 * @param string $hash  Visitor hash, 32 hex characters.
	 *
	 * @return void
	 */
	public function insert( array $click, $hash ) {
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
	 * Whether the visitor has not clicked the link yet today.
	 *
	 * The hash rotates daily, so a match within the last day is the same
	 * visitor on the same day.
	 *
	 * @param int    $link_id Link ID.
	 * @param string $hash    Visitor hash.
	 *
	 * @return bool
	 */
	public function is_new_visitor( $link_id, $hash ) {
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
	 * Increment today's totals for a link.
	 *
	 * @param int    $link_id Link ID.
	 * @param string $day   Site-local date, Y-m-d.
	 * @param int    $clicks  Human clicks to add.
	 * @param int    $uniques Unique visitors to add.
	 * @param int    $bots    Bot hits to add.
	 *
	 * @return void
	 */
	public function bump_daily( $link_id, $day, $clicks, $uniques, $bots ) {
		global $wpdb;

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
	 * Delete a link's raw clicks within a time range (inclusive). Importers
	 * use it to replace a range instead of adding to it.
	 *
	 * @param int    $link_id Link ID.
	 * @param string $from    UTC datetime.
	 * @param string $to      UTC datetime.
	 *
	 * @return void
	 */
	public function delete_range( $link_id, $from, $to ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE link_id = %d AND clicked_at BETWEEN %s AND %s',
				Schema::table( 'clicks' ),
				$link_id,
				$from,
				$to
			)
		);
	}

	/**
	 * Set (not add to) a day's totals for a link.
	 *
	 * @param int    $link_id Link ID.
	 * @param string $day     Site-local date, Y-m-d.
	 * @param int    $clicks  Human clicks.
	 * @param int    $uniques Unique visitors.
	 * @param int    $bots    Bot hits.
	 *
	 * @return void
	 */
	public function set_daily( $link_id, $day, $clicks, $uniques, $bots ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (link_id, day, clicks, uniques, bots) VALUES (%d, %s, %d, %d, %d)
				ON DUPLICATE KEY UPDATE clicks = %d, uniques = %d, bots = %d',
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
	 * Whether a link has any daily totals.
	 *
	 * @param int $link_id Link ID.
	 *
	 * @return bool
	 */
	public function has_daily( $link_id ) {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE link_id = %d LIMIT 1', Schema::table( 'stats_daily' ), $link_id ) );
	}

	/**
	 * Set a link's click counter to the sum of its daily totals.
	 *
	 * @param int $link_id Link ID.
	 *
	 * @return void
	 */
	public function recount( $link_id ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET total_clicks = (SELECT COALESCE(SUM(clicks), 0) FROM %i WHERE link_id = %d) WHERE id = %d',
				Schema::table( 'links' ),
				Schema::table( 'stats_daily' ),
				$link_id,
				$link_id
			)
		);

		// Cached links carry the old counter.
		Repository::flush_all();
	}

	/**
	 * Recompute daily totals and the click counter of a link from raw clicks.
	 *
	 * Used after importing history. Days are bucketed with the site's current
	 * UTC offset, so days around a DST change may shift by an hour.
	 *
	 * @param int $link_id Link ID.
	 *
	 * @return void
	 */
	public function rebuild( $link_id ) {
		global $wpdb;

		$clicks = Schema::table( 'clicks' );
		$offset = wp_timezone()->getOffset( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) );

		$wpdb->delete( Schema::table( 'stats_daily' ), [ 'link_id' => $link_id ], [ '%d' ] );

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (link_id, day, clicks, uniques, bots)
				SELECT link_id, DATE(DATE_ADD(clicked_at, INTERVAL %d SECOND)) AS local_day, COUNT(*), SUM(is_unique), 0
				FROM %i WHERE link_id = %d GROUP BY link_id, local_day',
				Schema::table( 'stats_daily' ),
				$offset,
				$clicks,
				$link_id
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET total_clicks = (SELECT COUNT(*) FROM %i WHERE link_id = %d), last_clicked_at = (SELECT MAX(clicked_at) FROM %i WHERE link_id = %d) WHERE id = %d',
				Schema::table( 'links' ),
				$clicks,
				$link_id,
				$clicks,
				$link_id,
				$link_id
			)
		);

		// Cached links carry the old counter.
		Repository::flush_all();
	}
}
