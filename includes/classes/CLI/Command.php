<?php
/**
 * WP-CLI commands.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\CLI;

use FastUrlShortener\Links\Repository;
use FastUrlShortener\Redirect\FastPath;
use FastUrlShortener\Settings;
use FastUrlShortener\Tracking\Stats;
use FastUrlShortener\Tracking\UserAgent;
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
}
