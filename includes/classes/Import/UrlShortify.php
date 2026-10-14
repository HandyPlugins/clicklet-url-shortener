<?php
/**
 * URL Shortify import source.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Import;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads another plugin's tables during a one-off import.

/**
 * Reads the kc_us_links and kc_us_clicks tables. Dates are stored in UTC.
 */
class UrlShortify extends Source {

	/**
	 * Source ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'url-shortify';
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function label() {
		return 'URL Shortify';
	}

	/**
	 * Links table.
	 *
	 * @return string
	 */
	protected function links_table() {
		global $wpdb;

		return $wpdb->prefix . 'kc_us_links';
	}

	/**
	 * Clicks table.
	 *
	 * @return string
	 */
	protected function clicks_table() {
		global $wpdb;

		return $wpdb->prefix . 'kc_us_clicks';
	}

	/**
	 * Links with an ID above $after.
	 *
	 * @param int $after Last processed source ID.
	 * @param int $limit Batch size.
	 *
	 * @return array[]
	 */
	public function links_after( $after, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, slug, name, url, redirect_type, params_forwarding, track_me, status, password, expires_at, created_at FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				$this->links_table(),
				$after,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			function ( $row ) {
				return [
					'source_id'      => (int) $row['id'],
					'slug'           => (string) $row['slug'],
					'target_url'     => (string) $row['url'],
					'title'          => (string) $row['name'],
					'redirect_type'  => $this->redirect_type( $row['redirect_type'] ),
					'forward_params' => (bool) $row['params_forwarding'],
					'track_clicks'   => (bool) $row['track_me'],
					// Password protection is not supported yet; keep those links private.
					'status'         => 1 === (int) $row['status'] && empty( $row['password'] ) ? 'active' : 'disabled',
					'expires_at'     => $row['expires_at'] ? (string) $row['expires_at'] : null,
					'created_at'     => (string) $row['created_at'],
					'skip'           => false,
				];
			},
			$rows
		);
	}

	/**
	 * Clicks with an ID above $after.
	 *
	 * @param int $after Last processed source ID.
	 * @param int $limit Batch size.
	 *
	 * @return array[]
	 */
	public function clicks_after( $after, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, link_id, created_at, ip, user_agent, referer, country, is_first_click, is_robot FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				$this->clicks_table(),
				$after,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return [
					'source_id'      => (int) $row['id'],
					'source_link_id' => (int) $row['link_id'],
					'clicked_at'     => (string) $row['created_at'],
					'ip'             => (string) $row['ip'],
					'user_agent'     => (string) $row['user_agent'],
					'referrer'       => (string) $row['referer'],
					'country'        => (string) $row['country'],
					'is_first'       => (bool) $row['is_first_click'],
					'is_robot'       => (bool) $row['is_robot'],
				];
			},
			$rows
		);
	}
}
