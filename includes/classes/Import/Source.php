<?php
/**
 * Import source contract.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Import;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads another plugin's tables during a one-off import.

/**
 * Another plugin's links and clicks, read in ID order and normalized.
 *
 * Sources read the other plugin's tables directly, so the import works even
 * when that plugin is inactive.
 */
abstract class Source {

	/**
	 * Unique ID, used in URLs and option names.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Display name.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Links table name, with the WordPress prefix.
	 *
	 * @return string
	 */
	abstract protected function links_table();

	/**
	 * Clicks table name, with the WordPress prefix.
	 *
	 * @return string
	 */
	abstract protected function clicks_table();

	/**
	 * Links with an ID above $after.
	 *
	 * @param int $after Last processed source ID.
	 * @param int $limit Batch size.
	 *
	 * @return array[] {
	 *     Normalized links.
	 *
	 *     @type int         $source_id      ID in the source.
	 *     @type string      $slug           Slug as stored by the source.
	 *     @type string      $target_url     Destination.
	 *     @type string      $title          Title.
	 *     @type int         $redirect_type  HTTP status; non-HTTP types become 302.
	 *     @type bool        $forward_params Query string forwarding.
	 *     @type bool        $track_clicks   Click tracking.
	 *     @type string      $status         active or disabled.
	 *     @type string|null $expires_at     UTC datetime.
	 *     @type string      $created_at     UTC datetime.
	 *     @type string[]    $groups         Group, category or tag names.
	 *     @type string      $password       Plain password, if any. Optional.
	 *     @type bool        $skip           Leave the link out (e.g. trashed).
	 * }
	 */
	abstract public function links_after( $after, $limit );

	/**
	 * Clicks with an ID above $after.
	 *
	 * @param int $after Last processed source ID.
	 * @param int $limit Batch size.
	 *
	 * @return array[] {
	 *     Normalized clicks.
	 *
	 *     @type int    $source_id      ID in the source.
	 *     @type int    $source_link_id Link ID in the source.
	 *     @type string $clicked_at     UTC datetime.
	 *     @type string $ip             IP address.
	 *     @type string $user_agent     User agent.
	 *     @type string $referrer       Referrer URL.
	 *     @type string $country        Country code, if known.
	 *     @type bool   $is_first       First click of the visitor.
	 *     @type bool   $is_robot       Flagged as a bot by the source.
	 *     @type bool   $is_qr          A QR code scan. Optional.
	 * }
	 */
	abstract public function clicks_after( $after, $limit );

	/**
	 * Whether a table exists, cached per request.
	 *
	 * @param string $table Table name.
	 *
	 * @return bool
	 */
	protected function table_exists( $table ) {
		global $wpdb;

		static $exists = [];

		if ( ! isset( $exists[ $table ] ) ) {
			$exists[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		}

		return $exists[ $table ];
	}

	/**
	 * Whether the source tables exist.
	 *
	 * @return bool
	 */
	public function is_available() {
		global $wpdb;

		return $this->links_table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->links_table() ) ) );
	}

	/**
	 * Number of links in the source.
	 *
	 * @return int
	 */
	public function count_links() {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->links_table() ) );
	}

	/**
	 * Number of clicks in the source.
	 *
	 * @return int
	 */
	public function count_clicks() {
		global $wpdb;

		if ( $this->clicks_table() !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $this->clicks_table() ) ) ) ) {
			return 0;
		}

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->clicks_table() ) );
	}

	/**
	 * Most common first path segment of slugs, when most slugs have one
	 * (e.g. "go" for "go/promo"). Suggests a prefix setting before importing.
	 *
	 * @return string
	 */
	public function common_prefix() {
		global $wpdb;

		$slugs = $wpdb->get_col( $wpdb->prepare( 'SELECT slug FROM %i LIMIT 500', $this->links_table() ) );

		if ( ! $slugs ) {
			return '';
		}

		$prefixes = [];

		foreach ( $slugs as $slug ) {
			$parts = explode( '/', trim( (string) $slug, '/' ) );

			if ( count( $parts ) > 1 ) {
				$prefixes[ $parts[0] ] = ( $prefixes[ $parts[0] ] ?? 0 ) + 1;
			}
		}

		arsort( $prefixes );
		$top = key( $prefixes );

		return $top && $prefixes[ $top ] > count( $slugs ) / 2 ? (string) $top : '';
	}

	/**
	 * Map a source redirect type to an HTTP status code.
	 *
	 * @param string $type Source value.
	 *
	 * @return int
	 */
	protected function redirect_type( $type ) {
		return in_array( (int) $type, [ 301, 302, 307, 308 ], true ) ? (int) $type : 302;
	}
}
