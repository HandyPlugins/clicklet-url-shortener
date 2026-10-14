<?php
/**
 * Import runner.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Import;

use FastUrlShortener\Links\Repository;
use FastUrlShortener\Settings;
use FastUrlShortener\Tracking\ClickStore;
use FastUrlShortener\Tracking\Tracker;
use FastUrlShortener\Tracking\UserAgent;

/**
 * Imports a source in small steps, so each request stays short and an
 * interrupted import can continue. Progress lives in a non-autoloaded option.
 *
 * Steps: links → clicks → stats (daily totals rebuilt from imported clicks).
 * Existing slugs are skipped, so running an import twice adds nothing.
 */
class Importer {

	/**
	 * Links per step.
	 */
	const LINKS_BATCH = 200;

	/**
	 * Clicks per step.
	 */
	const CLICKS_BATCH = 2000;

	/**
	 * Links whose totals are rebuilt per step.
	 */
	const STATS_BATCH = 100;

	/**
	 * Skipped links kept for the report.
	 */
	const MAX_SKIPPED = 100;

	/**
	 * Link storage.
	 *
	 * @var Repository
	 */
	protected $links;

	/**
	 * Click storage.
	 *
	 * @var ClickStore
	 */
	protected $clicks;

	/**
	 * Constructor.
	 *
	 * @param Repository $links  Link storage.
	 * @param ClickStore $clicks Click storage.
	 */
	public function __construct( Repository $links, ClickStore $clicks ) {
		$this->links  = $links;
		$this->clicks = $clicks;
	}

	/**
	 * Supported sources.
	 *
	 * @return Source[] Keyed by ID.
	 */
	public static function sources() {
		$sources = [ new UrlShortify(), new PrettyLinks() ];
		$keyed   = [];

		foreach ( $sources as $source ) {
			$keyed[ $source->id() ] = $source;
		}

		return $keyed;
	}

	/**
	 * Start (or restart) an import.
	 *
	 * @param Source $source Source.
	 *
	 * @return array Progress.
	 */
	public function start( Source $source ) {
		$state = [
			'step'             => 'links',
			'cursor'           => 0,
			'map'              => [],
			'links_total'      => $source->count_links(),
			'links_processed'  => 0,
			'links_imported'   => 0,
			'links_skipped'    => 0,
			'clicks_total'     => $source->count_clicks(),
			'clicks_processed' => 0,
			'clicks_imported'  => 0,
			'skipped'          => [],
		];

		$this->save( $source, $state );

		return $this->progress( $state );
	}

	/**
	 * Run one step.
	 *
	 * @param Source $source Source.
	 *
	 * @return array Progress.
	 */
	public function step( Source $source ) {
		$state = $this->state( $source );

		if ( ! $state || 'done' === $state['step'] ) {
			return $this->progress( $state );
		}

		switch ( $state['step'] ) {
			case 'links':
				$state = $this->import_links( $source, $state );
				break;
			case 'clicks':
				$state = $this->import_clicks( $source, $state );
				break;
			case 'stats':
				$state = $this->rebuild_stats( $state );
				break;
		}

		$this->save( $source, $state );

		return $this->progress( $state );
	}

	/**
	 * Progress of the last import of a source, or null.
	 *
	 * @param Source $source Source.
	 *
	 * @return array|null
	 */
	public function status( Source $source ) {
		$state = $this->state( $source );

		return $state ? $this->progress( $state ) : null;
	}

	/**
	 * Import a batch of links.
	 *
	 * @param Source $source Source.
	 * @param array  $state  State.
	 *
	 * @return array
	 */
	protected function import_links( Source $source, array $state ) {
		$rows = $source->links_after( $state['cursor'], self::LINKS_BATCH );

		if ( ! $rows ) {
			return array_merge(
				$state,
				[
					'step'   => 'clicks',
					'cursor' => 0,
				]
			);
		}

		foreach ( $rows as $row ) {
			$state['cursor'] = $row['source_id'];
			++$state['links_processed'];

			if ( $row['skip'] ) {
				continue;
			}

			$link = $this->links->create(
				[
					'target_url'     => $row['target_url'],
					'slug'           => $this->slug( $row['slug'] ),
					'title'          => $row['title'],
					'redirect_type'  => $row['redirect_type'],
					'forward_params' => $row['forward_params'],
					'track_clicks'   => $row['track_clicks'],
					'status'         => $row['status'],
					'expires_at'     => $row['expires_at'] ? get_date_from_gmt( $row['expires_at'] ) : null,
					'created_at'     => $row['created_at'],
				]
			);

			if ( is_wp_error( $link ) ) {
				++$state['links_skipped'];

				if ( count( $state['skipped'] ) < self::MAX_SKIPPED ) {
					$state['skipped'][] = [
						'slug'   => $row['slug'],
						'reason' => $this->skip_reason( $this->slug( $row['slug'] ), $link ),
					];
				}

				continue;
			}

			$state['map'][ $row['source_id'] ] = $link->id;
			++$state['links_imported'];
		}

		return $state;
	}

	/**
	 * Import a batch of clicks for the imported links. Bots are left out.
	 *
	 * @param Source $source Source.
	 * @param array  $state  State.
	 *
	 * @return array
	 */
	protected function import_clicks( Source $source, array $state ) {
		global $wpdb;

		$rows = $state['map'] ? $source->clicks_after( $state['cursor'], self::CLICKS_BATCH ) : [];

		if ( ! $rows ) {
			return array_merge(
				$state,
				[
					'step'   => 'stats',
					'cursor' => 0,
				]
			);
		}

		// One transaction per batch keeps thousands of single-row inserts fast.
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $rows as $row ) {
			$state['cursor'] = $row['source_id'];
			++$state['clicks_processed'];

			$link_id = $state['map'][ $row['source_link_id'] ] ?? 0;
			$agent   = UserAgent::parse( $row['user_agent'] );

			if ( ! $link_id || $row['is_robot'] || $agent['is_bot'] ) {
				continue;
			}

			$this->clicks->insert(
				[
					'link_id'       => $link_id,
					'clicked_at'    => $row['clicked_at'],
					'is_unique'     => $row['is_first'] ? 1 : 0,
					'country'       => Tracker::country_code( $row['country'] ),
					'device'        => $agent['device'],
					'os'            => $agent['os'],
					'browser'       => $agent['browser'],
					'referrer_host' => Tracker::host( $row['referrer'] ),
				],
				ClickStore::visitor_hash( $row['ip'], $row['user_agent'], get_date_from_gmt( $row['clicked_at'], 'Y-m-d' ) )
			);

			++$state['clicks_imported'];
		}

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $state;
	}

	/**
	 * Rebuild daily totals and counters of a batch of imported links.
	 *
	 * @param array $state State.
	 *
	 * @return array
	 */
	protected function rebuild_stats( array $state ) {
		$ids = array_slice( array_values( $state['map'] ), $state['cursor'], self::STATS_BATCH );

		if ( ! $ids ) {
			return array_merge( $state, [ 'step' => 'done' ] );
		}

		foreach ( $ids as $id ) {
			$this->clicks->rebuild( $id );
		}

		$state['cursor'] += count( $ids );

		return $state;
	}

	/**
	 * Convert a source slug. A leading segment equal to our prefix is dropped,
	 * so "go/promo" imports as "promo" when the prefix is "go".
	 *
	 * @param string $slug Source slug.
	 *
	 * @return string
	 */
	protected function slug( $slug ) {
		$slug   = trim( $slug, '/' );
		$prefix = (string) Settings::get( 'prefix' );

		if ( '' !== $prefix && 0 === strpos( $slug, $prefix . '/' ) ) {
			$slug = substr( $slug, strlen( $prefix ) + 1 );
		}

		return $slug;
	}

	/**
	 * Why a link was skipped. Nested slugs get an actionable hint.
	 *
	 * @param string    $slug  Converted slug.
	 * @param \WP_Error $error Error from the repository.
	 *
	 * @return string
	 */
	protected function skip_reason( $slug, $error ) {
		if ( false !== strpos( $slug, '/' ) ) {
			return sprintf(
				/* translators: %s: first path segment of the slug, e.g. "go". */
				__( 'Slug has more than one path segment. Set the prefix to "%s" in Settings and import again.', 'fast-url-shortener' ),
				strtok( $slug, '/' )
			);
		}

		return $error->get_error_message();
	}

	/**
	 * Public progress data.
	 *
	 * @param array|null $state State.
	 *
	 * @return array|null
	 */
	protected function progress( $state ) {
		if ( ! $state ) {
			return null;
		}

		unset( $state['map'], $state['cursor'] );
		$state['done'] = 'done' === $state['step'];

		return $state;
	}

	/**
	 * Saved state.
	 *
	 * @param Source $source Source.
	 *
	 * @return array|null
	 */
	protected function state( Source $source ) {
		$state = get_option( self::option_name( $source ) );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Save state.
	 *
	 * @param Source $source Source.
	 * @param array  $state  State.
	 *
	 * @return void
	 */
	protected function save( Source $source, array $state ) {
		update_option( self::option_name( $source ), $state, false );
	}

	/**
	 * Option name for a source.
	 *
	 * @param Source $source Source.
	 *
	 * @return string
	 */
	public static function option_name( Source $source ) {
		return 'fast_url_shortener_import_' . str_replace( '-', '_', $source->id() );
	}
}
