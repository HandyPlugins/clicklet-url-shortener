<?php
/**
 * Click statistics.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tracking;

use FastUrlShortener\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reporting queries on the plugin's own tables; admin-only.

/**
 * Read-side queries for reports.
 */
class Stats {

	/**
	 * Breakdown dimensions and their columns in the clicks table.
	 */
	const DIMENSIONS = [ 'country', 'device', 'os', 'browser', 'referrer_host' ];

	/**
	 * Daily totals for a link over the last N days, oldest first, with
	 * missing days filled with zeros.
	 *
	 * @param int $link_id Link ID.
	 * @param int $days    Number of days, including today.
	 *
	 * @return array<int, array{day: string, clicks: int, uniques: int, bots: int}>
	 */
	public function daily( $link_id, $days = 30 ) {
		global $wpdb;

		$days  = max( 1, absint( $days ) );
		$start = wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT day, clicks, uniques, bots FROM %i WHERE link_id = %d AND day >= %s ORDER BY day ASC',
				Schema::table( 'stats_daily' ),
				$link_id,
				$start
			),
			OBJECT_K
		);

		$series = [];

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day      = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$series[] = [
				'day'     => $day,
				'clicks'  => isset( $rows[ $day ] ) ? (int) $rows[ $day ]->clicks : 0,
				'uniques' => isset( $rows[ $day ] ) ? (int) $rows[ $day ]->uniques : 0,
				'bots'    => isset( $rows[ $day ] ) ? (int) $rows[ $day ]->bots : 0,
			];
		}

		return $series;
	}

	/**
	 * Top values of a dimension from raw clicks since a UTC datetime.
	 *
	 * @param int    $link_id   Link ID.
	 * @param string $dimension One of self::DIMENSIONS.
	 * @param string $since     UTC datetime.
	 * @param int    $limit     Rows to return.
	 *
	 * @return array<int, array{value: string, clicks: int}>
	 */
	public function breakdown( $link_id, $dimension, $since, $limit = 10 ) {
		global $wpdb;

		if ( ! in_array( $dimension, self::DIMENSIONS, true ) ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT %i AS value, COUNT(*) AS clicks FROM %i WHERE link_id = %d AND clicked_at >= %s GROUP BY %i ORDER BY clicks DESC LIMIT %d',
				$dimension,
				Schema::table( 'clicks' ),
				$link_id,
				$since,
				$dimension,
				max( 1, absint( $limit ) )
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return [
					'value'  => (string) $row['value'],
					'clicks' => (int) $row['clicks'],
				];
			},
			$rows
		);
	}
}
