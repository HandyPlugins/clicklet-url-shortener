<?php
/**
 * CSV export.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Transfer;

use FastUrlShortener\Rest\Controller;
use FastUrlShortener\Schema;
use FastUrlShortener\Tracking\UserAgent;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched export reads on the plugin's own tables.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streams CSV rows to the response or a file; WP_Filesystem has no streaming API.

/**
 * Streams links, daily totals or raw clicks as CSV in batches, so memory use
 * stays flat however large the tables are.
 */
class Exporter {

	/**
	 * admin-post action and nonce action.
	 */
	const ACTION = 'fast_url_shortener_export';

	/**
	 * Rows per query.
	 */
	const BATCH = 2000;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'download' ] );
	}

	/**
	 * Download URL for a file type.
	 *
	 * @param string $type links, stats or clicks.
	 *
	 * @return string
	 */
	public static function url( $type ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&type=' . $type ), self::ACTION );
	}

	/**
	 * Send a CSV file to the browser.
	 *
	 * @return void
	 */
	public function download() {
		if ( ! current_user_can( Controller::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export links.', 'fast-url-shortener' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';

		if ( ! isset( Csv::COLUMNS[ $type ] ) ) {
			wp_die( esc_html__( 'Unknown export type.', 'fast-url-shortener' ), 400 );
		}

		$filename = sprintf( 'fast-url-shortener-%s-%s.csv', $type, wp_date( 'Y-m-d' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );
		$this->write( $type, $output );
		fclose( $output );

		exit;
	}

	/**
	 * Write a file type to a stream.
	 *
	 * @param string   $type   links, stats or clicks.
	 * @param resource $handle Writable stream.
	 *
	 * @return int Rows written, without the header.
	 */
	public function write( $type, $handle ) {
		fwrite( $handle, Csv::BOM );
		$this->put( $handle, Csv::COLUMNS[ $type ] );

		$method = 'write_' . $type;

		return $this->$method( $handle );
	}

	/**
	 * Links.
	 *
	 * @param resource $handle Stream.
	 *
	 * @return int
	 */
	protected function write_links( $handle ) {
		global $wpdb;

		$count = 0;
		$after = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', Schema::table( 'links' ), $after, self::BATCH ),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$this->put(
					$handle,
					[
						$row['slug'],
						$row['target_url'],
						$row['title'],
						$row['redirect_type'],
						$row['status'],
						$row['forward_params'],
						$row['track_clicks'],
						(string) $row['expires_at'],
						(string) $row['max_clicks'],
						$row['created_at'],
						$row['total_clicks'],
					]
				);
				$after = (int) $row['id'];
				++$count;
			}

			$fetched = count( $rows );
		} while ( self::BATCH === $fetched );

		return $count;
	}

	/**
	 * Daily totals.
	 *
	 * @param resource $handle Stream.
	 *
	 * @return int
	 */
	protected function write_stats( $handle ) {
		global $wpdb;

		$count     = 0;
		$after_id  = 0;
		$after_day = '0000-00-00';

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT s.link_id, s.day, s.clicks, s.uniques, s.bots, l.slug FROM %i s INNER JOIN %i l ON l.id = s.link_id
					WHERE s.link_id > %d OR ( s.link_id = %d AND s.day > %s ) ORDER BY s.link_id ASC, s.day ASC LIMIT %d',
					Schema::table( 'stats_daily' ),
					Schema::table( 'links' ),
					$after_id,
					$after_id,
					$after_day,
					self::BATCH
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$this->put( $handle, [ $row['slug'], $row['day'], $row['clicks'], $row['uniques'], $row['bots'] ] );
				$after_id  = (int) $row['link_id'];
				$after_day = $row['day'];
				++$count;
			}

			$fetched = count( $rows );
		} while ( self::BATCH === $fetched );

		return $count;
	}

	/**
	 * Raw clicks.
	 *
	 * @param resource $handle Stream.
	 *
	 * @return int
	 */
	protected function write_clicks( $handle ) {
		global $wpdb;

		$count = 0;
		$after = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT c.id, c.clicked_at, HEX(c.visitor_hash) AS visitor_hash, c.is_unique, c.country, c.device, c.os, c.browser, c.referrer_host, l.slug
					FROM %i c INNER JOIN %i l ON l.id = c.link_id WHERE c.id > %d ORDER BY c.id ASC LIMIT %d',
					Schema::table( 'clicks' ),
					Schema::table( 'links' ),
					$after,
					self::BATCH
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$this->put(
					$handle,
					[
						$row['slug'],
						$row['clicked_at'],
						strtolower( $row['visitor_hash'] ),
						$row['is_unique'],
						$row['country'],
						UserAgent::KEYS['device'][ (int) $row['device'] ] ?? '',
						UserAgent::KEYS['os'][ (int) $row['os'] ] ?? '',
						UserAgent::KEYS['browser'][ (int) $row['browser'] ] ?? '',
						$row['referrer_host'],
					]
				);
				$after = (int) $row['id'];
				++$count;
			}

			$fetched = count( $rows );
		} while ( self::BATCH === $fetched );

		return $count;
	}

	/**
	 * Write one escaped row.
	 *
	 * @param resource $handle Stream.
	 * @param array    $cells  Cells.
	 *
	 * @return void
	 */
	protected function put( $handle, array $cells ) {
		fputcsv( $handle, array_map( [ Csv::class, 'escape' ], $cells ), ',', '"', '' );
	}
}
