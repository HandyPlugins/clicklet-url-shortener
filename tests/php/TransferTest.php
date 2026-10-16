<?php
/**
 * CSV export and import.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Tests;

use FastUrlShortener\Links\Groups;
use FastUrlShortener\Links\Repository;
use FastUrlShortener\Schema;
use FastUrlShortener\Tracking\ClickStore;
use FastUrlShortener\Transfer\CsvImporter;
use FastUrlShortener\Transfer\Exporter;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB -- Test files and snapshots.

/**
 * @covers \FastUrlShortener\Transfer\Exporter
 * @covers \FastUrlShortener\Transfer\CsvImporter
 */
class TransferTest extends TestCase {

	/**
	 * Temporary directory for CSV files.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->dir = get_temp_dir() . 'fus-transfer-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		array_map( 'unlink', glob( $this->dir . '/*' ) );
		rmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Two links with clicks, including two identical clicks in the same second.
	 */
	protected function seed() {
		$store = new ClickStore();
		$promo = $this->create_link(
			[
				'slug'           => 'promo',
				'title'          => '=HYPERLINK("http://evil","x") Ünicode',
				'forward_params' => true,
				'expires_at'     => '2031-05-06 07:08',
				'max_clicks'     => 500,
				'created_at'     => '2024-01-02 03:04:05',
				'groups'         => [ 'Summer', 'Social' ],
				'password'       => 's3cret',
			]
		);
		$other = $this->create_link(
			[
				'slug'          => 'other',
				'redirect_type' => 301,
			]
		);

		$clicks = [
			[ $promo->id, '2026-10-01 10:00:00', 'aa', 1 ],
			[ $promo->id, '2026-10-01 10:00:00', 'aa', 0 ], // Same visitor, same second.
			[ $promo->id, '2026-10-02 12:00:00', 'bb', 1 ],
			[ $other->id, '2026-09-30 08:00:00', 'cc', 1 ],
		];

		foreach ( $clicks as $click ) {
			$store->insert(
				[
					'link_id'       => $click[0],
					'clicked_at'    => $click[1],
					'is_unique'     => $click[3],
					'country'       => 'TR',
					'device'        => 2,
					'os'            => 3,
					'browser'       => 2,
					'referrer_host' => 't.co',
					'source'        => 'bb' === $click[2] ? 1 : 0,
				],
				str_repeat( $click[2], 16 )
			);
		}

		$store->rebuild( $promo->id );
		$store->rebuild( $other->id );
		$store->bump_daily( $other->id, '2026-09-30', 0, 0, 4 ); // Bots survive through the stats file.
	}

	/**
	 * Export a file type.
	 *
	 * @param string $type Type.
	 *
	 * @return string Path.
	 */
	protected function export( $type ) {
		$path   = "{$this->dir}/{$type}.csv";
		$handle = fopen( $path, 'w' );
		( new Exporter() )->write( $type, $handle );
		fclose( $handle );

		return $path;
	}

	/**
	 * Import a file to the end.
	 *
	 * @param string $path      File.
	 * @param bool   $overwrite Overwrite existing links.
	 *
	 * @return array Progress.
	 */
	protected function import( $path, $overwrite = false ) {
		$importer = new CsvImporter( new Repository(), new ClickStore() );
		$progress = $importer->start( $path, $overwrite, false );

		$this->assertIsArray( $progress, is_wp_error( $progress ) ? $progress->get_error_message() : '' );

		for ( $i = 0; ! $progress['done'] && $i < 100; $i++ ) {
			$progress = $importer->step();
		}

		$this->assertTrue( $progress['done'] );

		return $progress;
	}

	/**
	 * Everything that must survive a move, keyed by slug.
	 *
	 * @return array
	 */
	protected function snapshot() {
		global $wpdb;

		$links  = Schema::table( 'links' );
		$clicks = Schema::table( 'clicks' );
		$stats  = Schema::table( 'stats_daily' );

		$groups = [];

		foreach ( ( new Repository() )->query( [ 'per_page' => 100 ] )['items'] as $link ) {
			$groups[ $link->slug ] = wp_list_pluck( Groups::of( $link->id ), 'name' );
		}

		ksort( $groups );

		return [
			'groups' => $groups,
			'links'  => $wpdb->get_results( "SELECT slug, target_url, title, redirect_type, status, forward_params, track_clicks, expires_at, max_clicks, password_hash, created_at, total_clicks FROM {$links} ORDER BY slug", ARRAY_A ),
			'stats'  => $wpdb->get_results( "SELECT l.slug, s.day, s.clicks, s.uniques, s.bots FROM {$stats} s JOIN {$links} l ON l.id = s.link_id ORDER BY l.slug, s.day", ARRAY_A ),
			'clicks' => $wpdb->get_results( "SELECT l.slug, c.clicked_at, HEX(c.visitor_hash) h, c.is_unique, c.country, c.device, c.os, c.browser, c.referrer_host, c.source FROM {$clicks} c JOIN {$links} l ON l.id = c.link_id ORDER BY l.slug, c.clicked_at, h, c.is_unique", ARRAY_A ),
		];
	}

	/**
	 * Delete every link (and with it, all click data).
	 */
	protected function empty_site() {
		$links = new Repository();

		foreach ( $links->query( [ 'per_page' => 100 ] )['items'] as $link ) {
			$links->delete( $link->id );
		}
	}

	/**
	 * Export, wipe, import: the site ends up identical, and importing the
	 * same files again changes nothing.
	 */
	public function test_round_trip_and_reimport() {
		$this->seed();
		$before = $this->snapshot();
		$files  = array_map( [ $this, 'export' ], [ 'links', 'stats', 'clicks' ] );

		$this->assertSame( "\xEF\xBB\xBF", file_get_contents( $files[0], false, null, 0, 3 ) );
		$this->assertStringContainsString( "'=HYPERLINK", file_get_contents( $files[0] ) );
		$this->assertStringContainsString( ',Social|Summer,', file_get_contents( $files[0] ) );
		$this->assertStringContainsString( ',mobile,ios,safari,', file_get_contents( $files[2] ) );

		$this->empty_site();
		$this->assertSame( 0, $this->count_rows( 'links' ) );

		foreach ( $files as $file ) {
			$this->import( $file );
		}

		$this->assertSame( $before, $this->snapshot() );
		$this->assertTrue( ( new Repository() )->find_by_slug( 'promo' )->check_password( 's3cret' ), 'Passwords survive the move.' );

		foreach ( $files as $file ) {
			$this->import( $file );
		}

		$this->assertSame( $before, $this->snapshot() );
	}

	/**
	 * Without a stats file, daily totals are rebuilt from clicks.
	 */
	public function test_clicks_without_stats() {
		$this->seed();
		$links  = $this->export( 'links' );
		$clicks = $this->export( 'clicks' );

		$this->empty_site();
		$this->import( $links );
		$this->import( $clicks );

		$this->assertSame( 3, ( new Repository() )->find_by_slug( 'promo' )->total_clicks );
		$this->assertTrue( ( new ClickStore() )->has_daily( ( new Repository() )->find_by_slug( 'promo' )->id ) );
	}

	/**
	 * Existing slugs are skipped, or updated with overwrite.
	 */
	public function test_skip_and_overwrite() {
		$this->seed();
		$path = $this->export( 'links' );
		file_put_contents( $path, str_replace( 'https://example.com/', 'https://changed.example.com/', file_get_contents( $path ) ) );

		$skipped = $this->import( $path );
		$this->assertSame( 2, $skipped['skipped'] );
		$this->assertSame( 'https://example.com/', ( new Repository() )->find_by_slug( 'other' )->target_url );

		$updated = $this->import( $path, true );
		$this->assertSame( 2, $updated['updated'] );
		$this->assertSame( 'https://changed.example.com/', ( new Repository() )->find_by_slug( 'other' )->target_url );
	}

	/**
	 * Hand-made files: semicolons, CRLF, minimal columns, mixed-case headers.
	 */
	public function test_spreadsheet_file() {
		$path = "{$this->dir}/excel.csv";
		file_put_contents(
			$path,
			"\xEF\xBB\xBFTarget_URL;Slug;Title\r\n" .
			"https://example.com/one;one;First\r\n" .
			"https://example.com/two;;\r\n" .
			"not a url;bad;\r\n" .
			"\r\n"
		);

		$progress = $this->import( $path );

		$this->assertSame( 2, $progress['imported'] );
		$this->assertSame( 1, $progress['skipped'] );
		$this->assertSame( 4, $progress['skipped_rows'][0]['line'] );
		$this->assertSame( 'First', ( new Repository() )->find_by_slug( 'one' )->title );
		$this->assertSame( 2, $this->count_rows( 'links' ) );
	}

	/**
	 * Stats and clicks for unknown slugs are reported, not stored.
	 */
	public function test_unknown_slugs() {
		$path = "{$this->dir}/stats.csv";
		file_put_contents( $path, "slug,day,clicks,uniques,bots\nmissing,2026-10-01,5,1,0\n" );

		$progress = $this->import( $path );

		$this->assertSame( 1, $progress['skipped'] );
		$this->assertSame( 0, $this->count_rows( 'stats_daily' ) );
	}

	/**
	 * Files of an unknown shape are rejected.
	 */
	public function test_unknown_file() {
		$path = "{$this->dir}/random.csv";
		file_put_contents( $path, "foo,bar\n1,2\n" );

		$result = ( new CsvImporter( new Repository(), new ClickStore() ) )->start( $path, false, false );

		$this->assertWPError( $result );
		$this->assertSame( 'fast_url_shortener_csv_unknown', $result->get_error_code() );
		$this->assertFileExists( $path, 'Files passed in by path are not deleted.' );
	}
}
