<?php
/**
 * CSV import.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Transfer;

use FastUrlShortener\Links\Repository;
use FastUrlShortener\Links\Slug;
use FastUrlShortener\Tracking\ClickStore;
use FastUrlShortener\Tracking\Retention;
use FastUrlShortener\Tracking\Stats;
use FastUrlShortener\Tracking\UserAgent;
use WP_Error;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Reads uploaded CSV files row by row from a byte offset; WP_Filesystem has no streaming API.

/**
 * Imports a links, stats or clicks CSV in small steps. The file type comes
 * from its header row; progress (including the byte offset) lives in a
 * non-autoloaded option, so large files survive across requests.
 *
 * Re-importing is harmless: existing links are skipped (or updated when
 * overwriting), daily totals are set rather than added, and identical clicks
 * are not stored twice.
 */
class CsvImporter {

	/**
	 * Progress option.
	 */
	const OPTION = 'fast_url_shortener_csv_import';

	/**
	 * Uploads subdirectory for pending files.
	 */
	const UPLOAD_DIR = 'fast-url-shortener-import';

	/**
	 * Rows per step, per file type.
	 */
	const BATCH = [
		'links'  => 200,
		'stats'  => 2000,
		'clicks' => 1000,
	];

	/**
	 * Skipped rows kept for the report.
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
	 * Slug => link ID lookups within one step.
	 *
	 * @var array
	 */
	protected $link_ids = [];

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( Retention::HOOK, [ $this, 'cleanup' ] );
	}

	/**
	 * Read the header of a file and get ready to import it.
	 *
	 * @param string $file        Absolute path.
	 * @param bool   $overwrite   Update links whose slug already exists.
	 * @param bool   $delete_file Delete the file when finished (uploads).
	 *
	 * @return array|WP_Error Progress.
	 */
	public function start( $file, $overwrite, $delete_file ) {
		$this->cancel();

		$handle = is_readable( $file ) ? fopen( $file, 'r' ) : false;

		if ( ! $handle ) {
			return new WP_Error( 'fast_url_shortener_csv_unreadable', __( 'The file could not be read.', 'fast-url-shortener' ) );
		}

		$delimiter = Csv::detect_delimiter( (string) fgets( $handle ) );
		rewind( $handle );

		$header = Csv::normalize_header( (array) fgetcsv( $handle, 0, $delimiter, '"', '' ) );
		$type   = Csv::detect_type( $header );
		$offset = ftell( $handle );
		$total  = 0;
		$ranges = [];

		if ( 'clicks' === $type ) {
			// Time span per slug, so each link's clicks in that span can be replaced.
			$slug_index = array_search( 'slug', $header, true );
			$time_index = array_search( 'clicked_at_utc', $header, true );

			while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( [ null ] === $cells || ! isset( $cells[ $slug_index ], $cells[ $time_index ] ) ) {
					continue;
				}

				++$total;
				$slug = Slug::normalize( Csv::unescape( $cells[ $slug_index ] ) );
				$time = strtotime( $cells[ $time_index ] . ' UTC' );

				if ( false !== $time ) {
					$ranges[ $slug ] = [
						min( $ranges[ $slug ][0] ?? $time, $time ),
						max( $ranges[ $slug ][1] ?? $time, $time ),
					];
				}
			}
		} else {
			while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( '' !== trim( $line ) ) {
					++$total;
				}
			}
		}

		fclose( $handle );

		if ( ! $type ) {
			if ( $delete_file ) {
				wp_delete_file( $file );
			}

			return new WP_Error( 'fast_url_shortener_csv_unknown', __( 'This file does not look like a links, stats or clicks CSV. A links file needs at least a target_url column.', 'fast-url-shortener' ) );
		}

		$state = [
			'file'         => $file,
			'delete_file'  => $delete_file,
			'type'         => $type,
			'delimiter'    => $delimiter,
			'header'       => $header,
			'offset'       => $offset,
			'line'         => 1,
			'overwrite'    => $overwrite,
			'total'        => $total,
			'processed'    => 0,
			'imported'     => 0,
			'updated'      => 0,
			'skipped'      => 0,
			'skipped_rows' => [],
			'touched'      => [],
			'ranges'       => $ranges,
			'cleared'      => [],
			'done'         => false,
		];

		update_option( self::OPTION, $state, false );

		return $this->progress( $state );
	}

	/**
	 * Import the next batch of rows.
	 *
	 * @return array|WP_Error|null Progress, or null when nothing is pending.
	 */
	public function step() {
		$state = get_option( self::OPTION );

		if ( ! is_array( $state ) || $state['done'] ) {
			return is_array( $state ) ? $this->progress( $state ) : null;
		}

		$handle = is_readable( $state['file'] ) ? fopen( $state['file'], 'r' ) : false;

		if ( ! $handle ) {
			delete_option( self::OPTION );
			return new WP_Error( 'fast_url_shortener_csv_unreadable', __( 'The import file is gone. Upload it again.', 'fast-url-shortener' ) );
		}

		fseek( $handle, $state['offset'] );

		$batch_links = [];

		for ( $i = 0; $i < self::BATCH[ $state['type'] ]; $i++ ) {
			$cells = fgetcsv( $handle, 0, $state['delimiter'], '"', '' );

			if ( false === $cells ) {
				break;
			}

			++$state['line'];

			if ( [ null ] === $cells ) {
				continue; // Blank line.
			}

			++$state['processed'];

			$row    = $this->combine( $state['header'], $cells );
			$method = 'import_' . $state['type'];
			$result = $this->$method( $row, $state );

			if ( is_int( $result ) ) {
				$batch_links[ $result ] = true;
			} elseif ( is_string( $result ) ) {
				$this->skip( $state, $row, $result );
			}
		}

		$state['offset'] = ftell( $handle );
		$finished        = feof( $handle ) || false === fgetcsv( $handle, 0, $state['delimiter'], '"', '' );
		fclose( $handle );

		$this->after_batch( $state, array_keys( $batch_links ) );

		if ( $finished ) {
			$this->finish( $state );
		}

		update_option( self::OPTION, $state, false );

		return $this->progress( $state );
	}

	/**
	 * Progress of the current or last import, or null.
	 *
	 * @return array|null
	 */
	public function status() {
		$state = get_option( self::OPTION );

		return is_array( $state ) ? $this->progress( $state ) : null;
	}

	/**
	 * Drop the current import and its uploaded file.
	 *
	 * @return void
	 */
	public function cancel() {
		$state = get_option( self::OPTION );

		if ( is_array( $state ) && ! empty( $state['delete_file'] ) && file_exists( $state['file'] ) ) {
			wp_delete_file( $state['file'] );
		}

		delete_option( self::OPTION );
	}

	/**
	 * Delete uploads abandoned for more than a day.
	 *
	 * @return void
	 */
	public function cleanup() {
		foreach ( (array) glob( self::upload_path() . '/*.csv' ) as $file ) {
			if ( $file && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Directory for uploaded files, protected from directory listing and,
	 * on Apache, from direct access. Files also get random names.
	 *
	 * @return string
	 */
	public static function upload_path() {
		$path = wp_upload_dir()['basedir'] . '/' . self::UPLOAD_DIR;

		if ( ! is_dir( $path ) && wp_mkdir_p( $path ) ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Two static guard files.
			file_put_contents( $path . '/index.php', "<?php\n// Silence is golden.\n" );
			file_put_contents( $path . '/.htaccess', "Require all denied\nDeny from all\n" );
			// phpcs:enable
		}

		return $path;
	}

	/**
	 * Import a links row.
	 *
	 * @param array $row   Cells by column.
	 * @param array $state State.
	 *
	 * @return int|string|null Link ID, skip reason, or null.
	 */
	protected function import_links( array $row, array &$state ) {
		$data = [
			'target_url'   => $row['target_url'],
			'title'        => $row['title'] ?? '',
			'track_clicks' => Csv::to_bool( $row['track_clicks'] ?? '', true ),
		];

		if ( isset( $row['forward_params'] ) && '' !== $row['forward_params'] ) {
			$data['forward_params'] = Csv::to_bool( $row['forward_params'], false );
		}

		foreach ( [ 'redirect_type', 'status', 'max_clicks', 'password_hash' ] as $field ) {
			if ( isset( $row[ $field ] ) && '' !== $row[ $field ] ) {
				$data[ $field ] = $row[ $field ];
			}
		}

		// Only files with a groups column change groups; an empty cell clears them.
		if ( array_key_exists( 'groups', $row ) ) {
			$data['groups'] = array_filter( array_map( 'trim', explode( Csv::GROUP_SEPARATOR, $row['groups'] ) ) );
		}

		if ( ! empty( $row['expires_at_utc'] ) ) {
			// The repository expects the site timezone.
			$data['expires_at'] = get_date_from_gmt( $row['expires_at_utc'] );
		}

		$slug     = Slug::normalize( $row['slug'] ?? '' );
		$existing = '' !== $slug ? $this->links->find_by_slug( $slug ) : null;

		if ( $existing ) {
			if ( ! $state['overwrite'] ) {
				return __( 'A link with this slug already exists.', 'fast-url-shortener' );
			}

			$link = $this->links->update( $existing->id, $data );

			if ( is_wp_error( $link ) ) {
				return $link->get_error_message();
			}

			++$state['updated'];
			$this->link_imported( $link, $row );

			return null;
		}

		$data['slug']       = $slug;
		$data['created_at'] = $row['created_at_utc'] ?? '';

		$link = $this->links->create( $data );

		if ( is_wp_error( $link ) ) {
			return $link->get_error_message();
		}

		++$state['imported'];
		$this->link_imported( $link, $row );

		return null;
	}

	/**
	 * Let extensions read their own columns of a links row.
	 *
	 * @param \FastUrlShortener\Links\Link $link Created or updated link.
	 * @param array                        $row  Cells by column.
	 *
	 * @return void
	 */
	protected function link_imported( $link, array $row ) {
		/**
		 * Fires after a links CSV row is imported (created or updated).
		 *
		 * @hook fast_url_shortener_csv_import_link
		 *
		 * @param \FastUrlShortener\Links\Link $link Link.
		 * @param array                        $row  Cells by column, including extra columns.
		 */
		do_action( 'fast_url_shortener_csv_import_link', $link, $row );
	}

	/**
	 * Import a daily totals row.
	 *
	 * @param array $row   Cells by column.
	 * @param array $state State.
	 *
	 * @return int|string Link ID or skip reason.
	 */
	protected function import_stats( array $row, array &$state ) {
		$link_id = $this->link_id( $row['slug'] );

		if ( ! $link_id ) {
			return __( 'No link with this slug. Import the links file first.', 'fast-url-shortener' );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $row['day'] ) ) {
			return __( 'Invalid day.', 'fast-url-shortener' );
		}

		$this->clicks->set_daily( $link_id, $row['day'], absint( $row['clicks'] ), absint( $row['uniques'] ?? 0 ), absint( $row['bots'] ?? 0 ) );
		++$state['imported'];

		return $link_id;
	}

	/**
	 * Import a raw click row.
	 *
	 * @param array $row   Cells by column.
	 * @param array $state State.
	 *
	 * @return int|string|null Link ID, skip reason, or null for duplicates.
	 */
	protected function import_clicks( array $row, array &$state ) {
		$link_id = $this->link_id( $row['slug'] );

		if ( ! $link_id ) {
			return __( 'No link with this slug. Import the links file first.', 'fast-url-shortener' );
		}

		$hash       = strtolower( $row['visitor_hash'] );
		$clicked_at = $row['clicked_at_utc'];

		if ( ! preg_match( '/^[0-9a-f]{32}$/', $hash ) || false === strtotime( $clicked_at . ' UTC' ) ) {
			return __( 'Invalid click data.', 'fast-url-shortener' );
		}

		$clicked_at = gmdate( 'Y-m-d H:i:s', strtotime( $clicked_at . ' UTC' ) );

		// First click of a link in this file: clear the span the file covers.
		if ( ! isset( $state['cleared'][ $link_id ] ) ) {
			$range = $state['ranges'][ Slug::normalize( $row['slug'] ) ] ?? null;

			if ( $range ) {
				$this->clicks->delete_range( $link_id, gmdate( 'Y-m-d H:i:s', $range[0] ), gmdate( 'Y-m-d H:i:s', $range[1] ) );
			}

			$state['cleared'][ $link_id ] = true;
		}

		$this->clicks->insert(
			[
				'link_id'       => $link_id,
				'clicked_at'    => $clicked_at,
				'is_unique'     => Csv::to_bool( $row['is_unique'] ?? '', false ) ? 1 : 0,
				'country'       => preg_match( '/^[A-Z]{2}$/', strtoupper( $row['country'] ?? '' ) ) ? strtoupper( $row['country'] ) : '',
				'device'        => $this->key_value( 'device', $row['device'] ?? '' ),
				'os'            => $this->key_value( 'os', $row['os'] ?? '' ),
				'browser'       => $this->key_value( 'browser', $row['browser'] ?? '' ),
				'referrer_host' => substr( strtolower( $row['referrer_host'] ?? '' ), 0, 191 ),
				'source'        => 'qr' === strtolower( $row['source'] ?? '' ) ? Stats::SOURCE_QR : Stats::SOURCE_LINK,
			],
			$hash
		);

		$state['touched'][ $link_id ] = true;
		++$state['imported'];

		return $link_id;
	}

	/**
	 * Per-batch follow-up: click counters follow imported daily totals.
	 *
	 * @param array $state    State.
	 * @param int[] $link_ids Links changed in this batch.
	 *
	 * @return void
	 */
	protected function after_batch( array $state, array $link_ids ) {
		if ( 'stats' !== $state['type'] ) {
			return;
		}

		foreach ( $link_ids as $link_id ) {
			$this->clicks->recount( $link_id );
		}
	}

	/**
	 * Finish: links that got raw clicks but have no daily totals (no stats
	 * file was imported) get them built from the clicks.
	 *
	 * @param array $state State.
	 *
	 * @return void
	 */
	protected function finish( array &$state ) {
		foreach ( array_keys( $state['touched'] ) as $link_id ) {
			if ( ! $this->clicks->has_daily( $link_id ) ) {
				$this->clicks->rebuild( $link_id );
			}
		}

		if ( $state['delete_file'] && file_exists( $state['file'] ) ) {
			wp_delete_file( $state['file'] );
		}

		$state['touched'] = [];
		$state['ranges']  = [];
		$state['cleared'] = [];
		$state['done']    = true;
	}

	/**
	 * Link ID for a slug, cached for the step.
	 *
	 * @param string $slug Slug.
	 *
	 * @return int
	 */
	protected function link_id( $slug ) {
		$slug = Slug::normalize( $slug );

		if ( ! isset( $this->link_ids[ $slug ] ) ) {
			$link                    = '' !== $slug ? $this->links->find_by_slug( $slug ) : null;
			$this->link_ids[ $slug ] = $link ? $link->id : 0;
		}

		return $this->link_ids[ $slug ];
	}

	/**
	 * Stored value for a CSV key such as "mobile".
	 *
	 * @param string $dimension device, os or browser.
	 * @param string $key       Key.
	 *
	 * @return int
	 */
	protected function key_value( $dimension, $key ) {
		$value = array_search( strtolower( trim( $key ) ), UserAgent::KEYS[ $dimension ], true );

		return false === $value ? UserAgent::UNKNOWN : (int) $value;
	}

	/**
	 * Map cells to column names, unescaping spreadsheet-safe values. Missing
	 * cells become empty strings.
	 *
	 * @param string[] $header Column names.
	 * @param array    $cells  Cells.
	 *
	 * @return array
	 */
	protected function combine( array $header, array $cells ) {
		$row = [];

		foreach ( $header as $index => $column ) {
			$row[ $column ] = Csv::unescape( $cells[ $index ] ?? '' );
		}

		return $row;
	}

	/**
	 * Record a skipped row.
	 *
	 * @param array  $state  State.
	 * @param array  $row    Row.
	 * @param string $reason Reason.
	 *
	 * @return void
	 */
	protected function skip( array &$state, array $row, $reason ) {
		++$state['skipped'];

		if ( count( $state['skipped_rows'] ) < self::MAX_SKIPPED ) {
			$state['skipped_rows'][] = [
				'line'   => $state['line'],
				'slug'   => $row['slug'] ?? '',
				'reason' => $reason,
			];
		}
	}

	/**
	 * Public progress data.
	 *
	 * @param array $state State.
	 *
	 * @return array
	 */
	protected function progress( array $state ) {
		return array_intersect_key( $state, array_flip( [ 'type', 'total', 'processed', 'imported', 'updated', 'skipped', 'skipped_rows', 'overwrite', 'done' ] ) );
	}
}
