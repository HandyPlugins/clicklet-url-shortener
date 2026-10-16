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
	const DIMENSIONS = [ 'country', 'device', 'os', 'browser', 'referrer_host', 'source' ];

	/**
	 * Click sources: the short link itself, or a scan of its QR code.
	 */
	const SOURCE_LINK = 0;
	const SOURCE_QR   = 1;

	/**
	 * Stable keys for sources, used in CSV files.
	 */
	const SOURCE_KEYS = [
		self::SOURCE_LINK => 'link',
		self::SOURCE_QR   => 'qr',
	];

	/**
	 * Labels for stored dimension values (devices, systems, browsers, sources).
	 *
	 * @return array
	 */
	public static function labels() {
		return array_merge(
			UserAgent::labels(),
			[
				'source' => [
					self::SOURCE_LINK => __( 'Link', 'fast-url-shortener' ),
					self::SOURCE_QR   => __( 'QR code', 'fast-url-shortener' ),
				],
			]
		);
	}

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
	 * Most clicked links over the last N days, from daily totals.
	 *
	 * @param int $days  Number of days, including today.
	 * @param int $limit Links to return.
	 *
	 * @return array<int, array{link_id: int, slug: string, title: string, clicks: int}>
	 */
	public function top_links( $days = 7, $limit = 5 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.link_id, l.slug, l.title, SUM(s.clicks) AS clicks FROM %i s INNER JOIN %i l ON l.id = s.link_id
				WHERE s.day >= %s GROUP BY s.link_id, l.slug, l.title HAVING clicks > 0 ORDER BY clicks DESC LIMIT %d',
				Schema::table( 'stats_daily' ),
				Schema::table( 'links' ),
				wp_date( 'Y-m-d', time() - ( max( 1, absint( $days ) ) - 1 ) * DAY_IN_SECONDS ),
				max( 1, absint( $limit ) )
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return [
					'link_id' => (int) $row['link_id'],
					'slug'    => (string) $row['slug'],
					'title'   => (string) $row['title'],
					'clicks'  => (int) $row['clicks'],
				];
			},
			$rows
		);
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
