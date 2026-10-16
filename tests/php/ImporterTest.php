<?php
/**
 * Migration from other plugins.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tests;

use FastUrlShortener\Import\Importer;
use FastUrlShortener\Import\PrettyLinks;
use FastUrlShortener\Import\UrlShortify;
use FastUrlShortener\Links\Groups;
use FastUrlShortener\Links\Repository;
use FastUrlShortener\Tracking\ClickStore;

// phpcs:disable WordPress.DB -- Test fixtures for other plugins' tables.

/**
 * @covers \FastUrlShortener\Import\Importer
 * @covers \FastUrlShortener\Import\Source
 * @covers \FastUrlShortener\Import\UrlShortify
 * @covers \FastUrlShortener\Import\PrettyLinks
 */
class ImporterTest extends TestCase {

	const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

	/**
	 * Create the other plugins' tables for real: tables created inside a test
	 * become temporary, and SHOW TABLES cannot see those.
	 */
	public static function wpSetUpBeforeClass() {
		global $wpdb;

		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kc_us_links (
				id int(10) NOT NULL AUTO_INCREMENT, slug varchar(255), name varchar(255), url text,
				redirect_type varchar(255) DEFAULT '307', params_forwarding tinyint(1) DEFAULT 0, track_me tinyint(1) DEFAULT 1,
				status tinyint(1) DEFAULT 1, password varchar(255), expires_at datetime, created_at datetime, PRIMARY KEY (id)
			)"
		);
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kc_us_clicks (
				id int(11) NOT NULL AUTO_INCREMENT, link_id int(11), created_at datetime NOT NULL, ip varchar(255), user_agent text,
				referer varchar(255), country varchar(50), is_first_click tinyint(1) DEFAULT 0, is_robot tinyint(1) DEFAULT 0, PRIMARY KEY (id)
			)"
		);
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}prli_links (
				id int(11) NOT NULL AUTO_INCREMENT, name varchar(255), url text, slug varchar(255), track_me tinyint(1) DEFAULT 1,
				param_forwarding varchar(255), redirect_type varchar(255) DEFAULT '307', created_at datetime NOT NULL,
				deleted_at datetime DEFAULT NULL, PRIMARY KEY (id)
			)"
		);
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}prli_clicks (
				id int(11) NOT NULL AUTO_INCREMENT, ip varchar(255), browser varchar(255), referer varchar(255), robot tinyint DEFAULT 0,
				first_click tinyint DEFAULT 0, created_at datetime NOT NULL, link_id int(11), country char(2) NOT NULL DEFAULT '', PRIMARY KEY (id)
			)"
		);
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kc_us_groups ( id int(10) NOT NULL AUTO_INCREMENT, name varchar(255), PRIMARY KEY (id) )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}kc_us_links_groups ( id int(10) NOT NULL AUTO_INCREMENT, link_id int(10) NOT NULL, group_id int(10) NOT NULL, PRIMARY KEY (id) )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}prli_groups ( id int(11) NOT NULL AUTO_INCREMENT, name varchar(255), PRIMARY KEY (id) )" );
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}prli_link_terms ( link_id bigint unsigned NOT NULL, term_id bigint unsigned NOT NULL, taxonomy varchar(32) NOT NULL, PRIMARY KEY (link_id, term_id, taxonomy) )" );
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}prli_links ADD COLUMN group_id int(11) DEFAULT NULL" );
	}

	/**
	 * Drop the fixture tables.
	 */
	public static function wpTearDownAfterClass() {
		global $wpdb;

		foreach ( [ 'kc_us_links', 'kc_us_clicks', 'kc_us_groups', 'kc_us_links_groups', 'prli_links', 'prli_clicks', 'prli_groups', 'prli_link_terms' ] as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
		}
	}

	/**
	 * Run an import to the end.
	 *
	 * @param \FastUrlShortener\Import\Source $source Source.
	 *
	 * @return array Final progress.
	 */
	protected function run_import( $source ) {
		$importer = new Importer( new Repository(), new ClickStore() );
		$progress = $importer->start( $source );

		for ( $i = 0; ! $progress['done'] && $i < 100; $i++ ) {
			$progress = $importer->step( $source );
		}

		$this->assertTrue( $progress['done'] );

		return $progress;
	}

	/**
	 * Insert URL Shortify fixtures.
	 *
	 * @return int[] Source link IDs by slug.
	 */
	protected function seed_url_shortify() {
		global $wpdb;

		$links = [
			[ 'us-promo', 'https://example.com/promo', '301', 1, null, 1 ],
			[ 'go/us-blog', 'https://example.com/blog', '307', 1, null, 0 ],
			[ 'US-Upper', 'https://example.com/upper', '302', 1, null, 0 ],
			[ 'us-secret', 'https://example.com/secret', '302', 1, 'hunter2', 0 ],
			[ 'us-off', 'https://example.com/off', '302', 0, null, 0 ],
			[ 'us-bad', 'javascript:alert(1)', '302', 1, null, 0 ],
		];
		$ids   = [];

		foreach ( $links as $link ) {
			$wpdb->insert(
				"{$wpdb->prefix}kc_us_links",
				[
					'slug'              => $link[0],
					'name'              => 'Title ' . $link[0],
					'url'               => $link[1],
					'redirect_type'     => $link[2],
					'status'            => $link[3],
					'password'          => $link[4],
					'params_forwarding' => $link[5],
					'created_at'        => '2025-03-01 10:00:00',
				]
			);
			$ids[ $link[0] ] = $wpdb->insert_id;
		}

		// us-promo: 3 human clicks (2 first) and a bot; go/us-blog: one click.
		$clicks = [
			[ 'us-promo', '2026-10-01 10:00:00', 1, 0 ],
			[ 'us-promo', '2026-10-01 11:00:00', 0, 0 ],
			[ 'us-promo', '2026-10-02 09:00:00', 1, 0 ],
			[ 'us-promo', '2026-10-02 09:30:00', 0, 1 ],
			[ 'go/us-blog', '2026-10-02 09:00:00', 1, 0 ],
		];

		foreach ( $clicks as $click ) {
			$wpdb->insert(
				"{$wpdb->prefix}kc_us_clicks",
				[
					'link_id'        => $ids[ $click[0] ],
					'created_at'     => $click[1],
					'ip'             => '203.0.113.' . wp_rand( 1, 250 ),
					'user_agent'     => $click[3] ? 'Googlebot/2.1' : self::BROWSER,
					'referer'        => 'https://www.google.com/',
					'country'        => 'DE',
					'is_first_click' => $click[2],
					'is_robot'       => $click[3],
				]
			);
		}

		return $ids;
	}

	/**
	 * Clear fixture rows (tables are real, so rows outlive the test transaction).
	 */
	public function tear_down() {
		global $wpdb;

		foreach ( [ 'kc_us_links', 'kc_us_clicks', 'kc_us_groups', 'kc_us_links_groups', 'prli_links', 'prli_clicks', 'prli_groups', 'prli_link_terms' ] as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}

		parent::tear_down();
	}

	/**
	 * URL Shortify links, statuses, skips and click history.
	 */
	public function test_url_shortify() {
		$this->seed_url_shortify();
		$this->create_link( [ 'slug' => 'us-off' ] ); // Already exists here.

		$source = new UrlShortify();
		$this->assertTrue( $source->is_available() );
		$this->assertSame( 6, $source->count_links() );

		$progress = $this->run_import( $source );
		$links    = new Repository();

		$this->assertSame( 3, $progress['links_imported'] );
		$this->assertSame( 3, $progress['links_skipped'] );
		$this->assertSame( 3, $progress['clicks_imported'] );

		$reasons = array_column( $progress['skipped'], 'reason', 'slug' );
		$this->assertStringContainsString( '"go"', $reasons['go/us-blog'] );
		$this->assertArrayHasKey( 'us-bad', $reasons );
		$this->assertArrayHasKey( 'us-off', $reasons );

		$promo = $links->find_by_slug( 'us-promo' );
		$this->assertSame( 301, $promo->redirect_type );
		$this->assertTrue( $promo->forward_params );
		$this->assertSame( 'Title us-promo', $promo->title );
		$this->assertSame( '2025-03-01 10:00:00', $promo->created_at );
		$this->assertSame( 3, $promo->total_clicks );

		$this->assertNotNull( $links->find_by_slug( 'us-upper' ) );
		// URL Shortify keeps plain-text passwords; they arrive hashed and still protect the link.
		$secret = $links->find_by_slug( 'us-secret' );
		$this->assertSame( 'active', $secret->status );
		$this->assertTrue( $secret->has_password() );
		$this->assertTrue( $secret->check_password( 'hunter2' ) );
		$this->assertNotSame( 'hunter2', $secret->password_hash() );

		// Running it again adds nothing.
		$again = $this->run_import( $source );
		$this->assertSame( 0, $again['links_imported'] );
		$this->assertSame( 3, $this->count_rows( 'clicks' ) );
	}

	/**
	 * A matching prefix setting makes nested slugs importable.
	 */
	public function test_url_shortify_with_prefix() {
		$this->seed_url_shortify();
		$this->settings( [ 'prefix' => 'go' ] );

		$this->run_import( new UrlShortify() );

		$blog = ( new Repository() )->find_by_slug( 'us-blog' );
		$this->assertNotNull( $blog );
		$this->assertSame( 1, $blog->total_clicks );
		$this->assertSame( home_url( '/go/us-blog' ), $blog->short_url() );
	}

	/**
	 * Most common nested segment is suggested as the prefix.
	 */
	public function test_common_prefix() {
		global $wpdb;

		foreach ( [ 'go/a', 'go/b', 'go/c', 'd' ] as $slug ) {
			$wpdb->insert(
				"{$wpdb->prefix}prli_links",
				[
					'slug'       => $slug,
					'url'        => 'https://example.com/',
					'created_at' => '2025-01-01 00:00:00',
				]
			);
		}

		$this->assertSame( 'go', ( new PrettyLinks() )->common_prefix() );
		$this->assertSame( '', ( new UrlShortify() )->common_prefix() );
	}

	/**
	 * Pretty Links: trashed links are left out, non-HTTP redirects become 302.
	 */
	public function test_pretty_links() {
		global $wpdb;

		$rows = [
			[ 'pl-deal', '307', 'on', null ],
			[ 'pl-cloak', 'cloak', 'off', null ],
			[ 'pl-trash', '302', 'off', '2026-01-01 00:00:00' ],
		];

		foreach ( $rows as $row ) {
			$wpdb->insert(
				"{$wpdb->prefix}prli_links",
				[
					'slug'             => $row[0],
					'name'             => $row[0],
					'url'              => 'https://example.com/' . $row[0],
					'redirect_type'    => $row[1],
					'param_forwarding' => $row[2],
					'deleted_at'       => $row[3],
					'created_at'       => '2024-11-20 08:30:00',
				]
			);
			$wpdb->insert(
				"{$wpdb->prefix}prli_clicks",
				[
					'link_id'     => $wpdb->insert_id,
					'ip'          => '198.51.100.1',
					'browser'     => self::BROWSER,
					'first_click' => 1,
					'created_at'  => '2026-10-01 10:00:00',
				]
			);
		}

		$progress = $this->run_import( new PrettyLinks() );
		$links    = new Repository();

		$this->assertSame( 2, $progress['links_imported'] );
		$this->assertSame( 0, $progress['links_skipped'] );
		$this->assertSame( 2, $progress['clicks_imported'] );
		$this->assertNull( $links->find_by_slug( 'pl-trash' ) );
		$this->assertTrue( $links->find_by_slug( 'pl-deal' )->forward_params );
		$this->assertSame( 307, $links->find_by_slug( 'pl-deal' )->redirect_type );
		$this->assertSame( 302, $links->find_by_slug( 'pl-cloak' )->redirect_type );
		$this->assertFalse( $links->find_by_slug( 'pl-cloak' )->forward_params );
	}

	/**
	 * Groups come along: URL Shortify groups, the Pretty Links 3.x group and
	 * 4.x categories/tags.
	 */
	public function test_groups_import() {
		global $wpdb;

		$ids = $this->seed_url_shortify();

		$wpdb->insert( "{$wpdb->prefix}kc_us_groups", [ 'name' => 'Campaign A' ] );
		$campaign = $wpdb->insert_id;
		$wpdb->insert( "{$wpdb->prefix}kc_us_groups", [ 'name' => 'Partners' ] );
		$partners = $wpdb->insert_id;

		foreach ( [ [ 'us-promo', $campaign ], [ 'us-promo', $partners ], [ 'US-Upper', $campaign ] ] as $pair ) {
			$wpdb->insert(
				"{$wpdb->prefix}kc_us_links_groups",
				[
					'link_id'  => $ids[ $pair[0] ],
					'group_id' => $pair[1],
				]
			);
		}

		$this->run_import( new UrlShortify() );

		$links = new Repository();
		$this->assertSame( [ 'Campaign A', 'Partners' ], wp_list_pluck( Groups::of( $links->find_by_slug( 'us-promo' )->id ), 'name' ) );
		$this->assertSame( [ 'Campaign A' ], wp_list_pluck( Groups::of( $links->find_by_slug( 'us-upper' )->id ), 'name' ) );

		// Pretty Links: a 3.x group and a 4.x category on the same link.
		$wpdb->insert( "{$wpdb->prefix}prli_groups", [ 'name' => 'Legacy' ] );
		$legacy = $wpdb->insert_id;
		$term   = wp_insert_term( 'Deals', 'category' );

		$wpdb->insert(
			"{$wpdb->prefix}prli_links",
			[
				'slug'       => 'pl-grouped',
				'url'        => 'https://example.com/pl',
				'group_id'   => $legacy,
				'created_at' => '2024-11-20 08:30:00',
			]
		);
		$wpdb->insert(
			"{$wpdb->prefix}prli_link_terms",
			[
				'link_id'  => $wpdb->insert_id,
				'term_id'  => $term['term_id'],
				'taxonomy' => 'category',
			]
		);

		$this->run_import( new PrettyLinks() );

		$this->assertSame( [ 'Deals', 'Legacy' ], wp_list_pluck( Groups::of( $links->find_by_slug( 'pl-grouped' )->id ), 'name' ) );
	}

	/**
	 * Steps before a start report nothing.
	 */
	public function test_step_without_start() {
		$this->assertNull( ( new Importer( new Repository(), new ClickStore() ) )->step( new PrettyLinks() ) );
	}
}
