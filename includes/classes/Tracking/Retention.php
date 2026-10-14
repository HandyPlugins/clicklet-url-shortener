<?php
/**
 * Raw click retention.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tracking;

use FastUrlShortener\Schema;
use FastUrlShortener\Settings;

/**
 * Deletes raw click rows older than the retention setting, in small batches.
 * Daily totals are never pruned.
 */
class Retention {

	/**
	 * Cron hook.
	 */
	const HOOK = 'fast_url_shortener_prune_clicks';

	/**
	 * Rows deleted per query.
	 */
	const BATCH_SIZE = 5000;

	/**
	 * Batches per cron run, so one run never holds locks for long.
	 */
	const MAX_BATCHES = 20;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, [ $this, 'prune' ] );
	}

	/**
	 * Schedule the daily job.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Remove the daily job.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Delete expired raw clicks.
	 *
	 * @return void
	 */
	public function prune() {
		global $wpdb;

		$days = absint( Settings::get( 'retention_days' ) );

		if ( ! $days ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		for ( $i = 0; $i < self::MAX_BATCHES; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched delete on the plugin's own table.
			$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE clicked_at < %s LIMIT %d', Schema::table( 'clicks' ), $cutoff, self::BATCH_SIZE ) );

			if ( $deleted < self::BATCH_SIZE ) {
				break;
			}
		}
	}
}
