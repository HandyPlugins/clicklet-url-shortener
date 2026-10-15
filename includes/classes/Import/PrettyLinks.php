<?php
/**
 * Pretty Links import source.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Import;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads another plugin's tables during a one-off import.

/**
 * Reads the prli_links and prli_clicks tables of Pretty Links 3.x and 4.x.
 * Dates are stored in UTC. Columns differ between versions, so rows are read
 * whole and optional columns are checked in PHP.
 */
class PrettyLinks extends Source {

	/**
	 * Source ID.
	 *
	 * @return string
	 */
	public function id() {
		return 'pretty-links';
	}

	/**
	 * Display name.
	 *
	 * @return string
	 */
	public function label() {
		return 'Pretty Links';
	}

	/**
	 * Links table.
	 *
	 * @return string
	 */
	protected function links_table() {
		global $wpdb;

		return $wpdb->prefix . 'prli_links';
	}

	/**
	 * Clicks table.
	 *
	 * @return string
	 */
	protected function clicks_table() {
		global $wpdb;

		return $wpdb->prefix . 'prli_clicks';
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
			$wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $this->links_table(), $after, $limit ),
			ARRAY_A
		);

		return array_map(
			function ( $row ) {
				// 4.x trashes links with deleted_at; 3.x used link_status.
				$trashed  = ! empty( $row['deleted_at'] );
				$disabled = isset( $row['link_status'] ) && 'enabled' !== $row['link_status'];

				return [
					'source_id'      => (int) $row['id'],
					'slug'           => (string) $row['slug'],
					'target_url'     => (string) $row['url'],
					'title'          => (string) $row['name'],
					'redirect_type'  => $this->redirect_type( $row['redirect_type'] ),
					'forward_params' => ! in_array( (string) $row['param_forwarding'], [ '', '0', 'off' ], true ),
					'track_clicks'   => (bool) $row['track_me'],
					'status'         => $disabled ? 'disabled' : 'active',
					'expires_at'     => null,
					'created_at'     => (string) $row['created_at'],
					'skip'           => $trashed,
					'groups'         => $trashed ? [] : $this->groups( $row ),
				];
			},
			$rows
		);
	}

	/**
	 * Group names of a link: the 3.x group and 4.x categories and tags.
	 *
	 * @param array $row Link row.
	 *
	 * @return string[]
	 */
	protected function groups( array $row ) {
		global $wpdb;

		$names  = [];
		$groups = $wpdb->prefix . 'prli_groups';
		$terms  = $wpdb->prefix . 'prli_link_terms';

		if ( ! empty( $row['group_id'] ) && $this->table_exists( $groups ) ) {
			$names[] = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', $groups, $row['group_id'] ) );
		}

		if ( $this->table_exists( $terms ) ) {
			$names = array_merge(
				$names,
				$wpdb->get_col(
					$wpdb->prepare( 'SELECT t.name FROM %i lt INNER JOIN %i t ON t.term_id = lt.term_id WHERE lt.link_id = %d', $terms, $wpdb->terms, $row['id'] )
				)
			);
		}

		return array_values( array_unique( array_filter( $names ) ) );
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
			$wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $this->clicks_table(), $after, $limit ),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return [
					'source_id'      => (int) $row['id'],
					'source_link_id' => (int) $row['link_id'],
					'clicked_at'     => (string) $row['created_at'],
					'ip'             => (string) $row['ip'],
					// The "browser" column holds the full user agent.
					'user_agent'     => (string) $row['browser'],
					'referrer'       => (string) $row['referer'],
					'country'        => isset( $row['country'] ) ? (string) $row['country'] : '',
					'is_first'       => (bool) $row['first_click'],
					'is_robot'       => (bool) $row['robot'],
				];
			},
			$rows
		);
	}
}
