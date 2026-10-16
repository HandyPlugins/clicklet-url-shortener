<?php
/**
 * Database schema.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener;

/**
 * Custom tables. Links and clicks are high-volume, so they live in lean
 * tables with only the indexes the queries actually use, instead of posts
 * and post meta.
 */
class Schema {

	/**
	 * Bump whenever the schema below changes; Install runs dbDelta again.
	 */
	const VERSION = '3';

	/**
	 * Table names without the WordPress or plugin prefix.
	 */
	const TABLES = [ 'links', 'linkmeta', 'clicks', 'stats_daily' ];

	/**
	 * Object type for the WordPress metadata API: get_metadata( Schema::META_TYPE, $link_id, … ).
	 */
	const META_TYPE = 'fast_url_shortener_link';

	/**
	 * Full table name for the current site.
	 *
	 * @param string $name One of self::TABLES.
	 *
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'fast_url_shortener_' . $name;
	}

	/**
	 * Make the link meta table known to the WordPress metadata API, which
	 * looks it up as $wpdb->{$type}meta. Re-registered on blog switches.
	 *
	 * @return void
	 */
	public static function register_meta_table() {
		global $wpdb;

		$wpdb->{self::META_TYPE . 'meta'} = self::table( 'linkmeta' );

		if ( ! has_action( 'switch_blog', [ self::class, 'register_meta_table' ] ) ) {
			add_action( 'switch_blog', [ self::class, 'register_meta_table' ] );
		}
	}

	/**
	 * Create or update tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$links   = self::table( 'links' );
		$meta    = self::table( 'linkmeta' );
		$clicks  = self::table( 'clicks' );
		$stats   = self::table( 'stats_daily' );

		// Slugs are restricted to [A-Za-z0-9_-], so an ASCII binary column gives
		// a small, exact-match unique index.
		dbDelta(
			"CREATE TABLE {$links} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				slug varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				target_url text NOT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				redirect_type smallint(3) unsigned NOT NULL DEFAULT 302,
				status varchar(20) NOT NULL DEFAULT 'active',
				forward_params tinyint(1) unsigned NOT NULL DEFAULT 0,
				track_clicks tinyint(1) unsigned NOT NULL DEFAULT 1,
				has_rules tinyint(1) unsigned NOT NULL DEFAULT 0,
				password_hash varchar(255) NOT NULL DEFAULT '',
				expires_at datetime DEFAULT NULL,
				max_clicks int(10) unsigned DEFAULT NULL,
				total_clicks bigint(20) unsigned NOT NULL DEFAULT 0,
				last_clicked_at datetime DEFAULT NULL,
				author_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY created_at (created_at)
			) {$collate};"
		);

		// Per-link extra data (used by extensions), in the layout the WordPress
		// metadata API expects for the fast_url_shortener_link object type.
		dbDelta(
			"CREATE TABLE {$meta} (
				meta_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				fast_url_shortener_link_id bigint(20) unsigned NOT NULL DEFAULT 0,
				meta_key varchar(255) DEFAULT NULL,
				meta_value longtext,
				PRIMARY KEY  (meta_id),
				KEY fast_url_shortener_link_id (fast_url_shortener_link_id),
				KEY meta_key (meta_key(191))
			) {$collate};"
		);

		// One row per human click. Visitors are identified by a daily-rotating
		// hash; the raw IP is never stored.
		dbDelta(
			"CREATE TABLE {$clicks} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				link_id bigint(20) unsigned NOT NULL,
				clicked_at datetime NOT NULL,
				visitor_hash binary(16) NOT NULL,
				is_unique tinyint(1) unsigned NOT NULL DEFAULT 0,
				country char(2) NOT NULL DEFAULT '',
				device tinyint(3) unsigned NOT NULL DEFAULT 0,
				os tinyint(3) unsigned NOT NULL DEFAULT 0,
				browser tinyint(3) unsigned NOT NULL DEFAULT 0,
				referrer_host varchar(191) NOT NULL DEFAULT '',
				source tinyint(3) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY link_clicked (link_id,clicked_at),
				KEY clicked_at (clicked_at)
			) {$collate};"
		);

		// Daily totals in the site's timezone. Kept forever, so long-range
		// charts never scan raw clicks.
		dbDelta(
			"CREATE TABLE {$stats} (
				link_id bigint(20) unsigned NOT NULL,
				day date NOT NULL,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				uniques int(10) unsigned NOT NULL DEFAULT 0,
				bots int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (link_id,day)
			) {$collate};"
		);
	}

	/**
	 * Drop all plugin tables.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		foreach ( self::TABLES as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables on uninstall.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $name ) ) );
		}
	}
}
