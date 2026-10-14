<?php
/**
 * WP-CLI commands.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\CLI;

use FastUrlShortener\Import\Importer;
use FastUrlShortener\Links\Repository;
use FastUrlShortener\Redirect\FastPath;
use FastUrlShortener\Settings;
use FastUrlShortener\Tracking\ClickStore;
use FastUrlShortener\Tracking\Stats;
use FastUrlShortener\Tracking\UserAgent;
use FastUrlShortener\Transfer\CsvImporter;
use FastUrlShortener\Transfer\Exporter;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Fast URL Shortener tools.
 */
class Command extends \WP_CLI_Command {

	/**
	 * Show click statistics for a link.
	 *
	 * ## OPTIONS
	 *
	 * <link>
	 * : Link ID or slug.
	 *
	 * [--days=<number>]
	 * : Days to show, including today.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--by=<dimension>]
	 * : Break down raw clicks by country, device, os, browser or referrer_host instead.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fast-url-shortener stats promo --days=7
	 *     wp fast-url-shortener stats 12 --by=country
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function stats( $args, $assoc_args ) {
		$link  = LinkCommand::resolve( new Repository(), $args[0] );
		$stats = new Stats();
		$days  = absint( $assoc_args['days'] );

		if ( empty( $assoc_args['by'] ) ) {
			Utils\format_items( $assoc_args['format'], $stats->daily( $link->id, $days ), [ 'day', 'clicks', 'uniques', 'bots' ] );
			return;
		}

		$dimension = $assoc_args['by'];

		if ( ! in_array( $dimension, Stats::DIMENSIONS, true ) ) {
			WP_CLI::error( 'Unknown dimension.' );
		}

		$rows   = $stats->breakdown( $link->id, $dimension, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ), 20 );
		$labels = UserAgent::labels();

		if ( isset( $labels[ $dimension ] ) ) {
			foreach ( $rows as &$row ) {
				$row['value'] = $labels[ $dimension ][ (int) $row['value'] ] ?? $row['value'];
			}
			unset( $row );
		}

		Utils\format_items( $assoc_args['format'], $rows, [ 'value', 'clicks' ] );
	}

	/**
	 * Manage the fast path drop-in, which resolves short links before other plugins load.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - enable
	 *   - disable
	 *   - status
	 * ---
	 *
	 * @subcommand fast-path
	 *
	 * @param array $args Positional arguments.
	 *
	 * @return void
	 */
	public function fast_path( $args ) {
		switch ( $args[0] ) {
			case 'enable':
				$result = FastPath::install();

				if ( is_wp_error( $result ) ) {
					WP_CLI::error( $result );
				}

				Settings::update( [ 'fast_path' => true ] );
				WP_CLI::success( 'Fast path enabled: ' . FastPath::path() );
				break;

			case 'disable':
				Settings::update( [ 'fast_path' => false ] );

				if ( ! FastPath::uninstall() ) {
					WP_CLI::error( 'Could not remove ' . FastPath::path() );
				}

				WP_CLI::success( 'Fast path disabled.' );
				break;

			default:
				WP_CLI::line( FastPath::is_installed() ? 'enabled' : 'disabled' );
		}
	}

	/**
	 * Show or change settings.
	 *
	 * Pass any setting as a flag to change it, e.g. --prefix=go or --track_clicks=0.
	 *
	 * ## OPTIONS
	 *
	 * [--<field>=<value>]
	 * : Setting to change.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function settings( $args, $assoc_args ) {
		$format   = $assoc_args['format'] ?? 'table';
		$defaults = Settings::defaults();
		$changes  = array_intersect_key( $assoc_args, $defaults );

		if ( array_diff_key( $assoc_args, $defaults, [ 'format' => true ] ) ) {
			WP_CLI::error( 'Unknown setting. Available: ' . implode( ', ', array_keys( $defaults ) ) );
		}

		if ( isset( $changes['fast_path'] ) ) {
			WP_CLI::error( 'Use `wp fast-url-shortener fast-path enable|disable`.' );
		}

		if ( $changes ) {
			foreach ( $changes as $key => $value ) {
				// Cast to the type of the default value ("false" and "0" are false).
				if ( is_bool( $defaults[ $key ] ) ) {
					$value = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
				} elseif ( is_int( $defaults[ $key ] ) ) {
					$value = absint( $value );
				} else {
					$value = sanitize_text_field( $value );
				}

				$changes[ $key ] = 'prefix' === $key ? sanitize_title( $value ) : $value;
			}

			Settings::update( $changes );
			WP_CLI::success( 'Settings updated.' );
		}

		$rows = [];

		foreach ( Settings::all() as $key => $value ) {
			$rows[] = [
				'setting' => $key,
				'value'   => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : $value,
			];
		}

		Utils\format_items( $format, $rows, [ 'setting', 'value' ] );
	}

	/**
	 * Export links, daily totals or raw clicks as CSV.
	 *
	 * Files reference links by slug, so they can be imported on another site.
	 * Export and import links first, then stats, then (optionally) clicks.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : What to export.
	 * ---
	 * options:
	 *   - links
	 *   - stats
	 *   - clicks
	 * ---
	 *
	 * [--file=<path>]
	 * : Write to a file instead of standard output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fast-url-shortener export links --file=links.csv
	 *     wp fast-url-shortener export stats > stats.csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		$file   = $assoc_args['file'] ?? '';
		$handle = fopen( '' !== $file ? $file : 'php://stdout', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streams CSV output.

		if ( ! $handle ) {
			WP_CLI::error( 'Could not open the output file.' );
		}

		$rows = ( new Exporter() )->write( $args[0], $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( '' !== $file ) {
			WP_CLI::success( sprintf( 'Exported %d rows to %s.', $rows, $file ) );
		}
	}

	/**
	 * Import a links, stats or clicks CSV. The type is detected from the header row.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : CSV file.
	 *
	 * [--overwrite]
	 * : Update links whose slug already exists instead of skipping them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fast-url-shortener import links.csv
	 *     wp fast-url-shortener import stats.csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		$importer = new CsvImporter( new Repository(), new ClickStore() );
		$progress = $importer->start( realpath( $args[0] ) ? realpath( $args[0] ) : $args[0], (bool) Utils\get_flag_value( $assoc_args, 'overwrite', false ), false );

		if ( is_wp_error( $progress ) ) {
			WP_CLI::error( $progress );
		}

		WP_CLI::log( sprintf( 'Importing %d %s rows…', $progress['total'], $progress['type'] ) );

		while ( ! $progress['done'] ) {
			$progress = $importer->step();

			if ( is_wp_error( $progress ) ) {
				WP_CLI::error( $progress );
			}
		}

		$this->report( $progress['imported'], $progress['updated'], $progress['skipped'], $progress['skipped_rows'] );
	}

	/**
	 * Migrate links and click history from another plugin on this site.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Plugin to migrate from.
	 * ---
	 * options:
	 *   - url-shortify
	 *   - pretty-links
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp fast-url-shortener migrate pretty-links
	 *
	 * @param array $args Positional arguments.
	 *
	 * @return void
	 */
	public function migrate( $args ) {
		$source = Importer::sources()[ $args[0] ];

		if ( ! $source->is_available() ) {
			WP_CLI::error( sprintf( 'No %s data found on this site.', $source->label() ) );
		}

		$importer = new Importer( new Repository(), new ClickStore() );
		$progress = $importer->start( $source );

		while ( ! $progress['done'] ) {
			$progress = $importer->step( $source );
		}

		WP_CLI::log( sprintf( 'Clicks imported: %d', $progress['clicks_imported'] ) );
		$this->report( $progress['links_imported'], 0, $progress['links_skipped'], $progress['skipped'] );
	}

	/**
	 * Print an import summary.
	 *
	 * @param int   $imported Created rows.
	 * @param int   $updated  Updated rows.
	 * @param int   $skipped  Skipped rows.
	 * @param array $rows     Skipped row details.
	 *
	 * @return void
	 */
	protected function report( $imported, $updated, $skipped, array $rows ) {
		if ( $rows ) {
			Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		}

		WP_CLI::success( sprintf( 'Imported %d, updated %d, skipped %d.', $imported, $updated, $skipped ) );
	}
}
