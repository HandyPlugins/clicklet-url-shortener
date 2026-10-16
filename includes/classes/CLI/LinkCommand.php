<?php
/**
 * WP-CLI link commands.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\CLI;

use FastUrlShortener\Links\Link;
use FastUrlShortener\Links\Repository;
use FastUrlShortener\Links\Slug;
use WP_CLI;
use WP_CLI\Utils;

/**
 * Manage short links.
 *
 * ## EXAMPLES
 *
 *     wp fast-url-shortener link create https://example.com/a/very/long/url --slug=promo
 *     wp fast-url-shortener link list --search=promo
 *     wp fast-url-shortener link update 12 --status=disabled
 */
class LinkCommand extends \WP_CLI_Command {

	/**
	 * Default list columns.
	 */
	const FIELDS = [ 'id', 'slug', 'short_url', 'target_url', 'groups', 'status', 'total_clicks' ];

	/**
	 * Link storage.
	 *
	 * @var Repository
	 */
	protected $links;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->links = new Repository();
	}

	/**
	 * Create a short link.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : Destination URL.
	 *
	 * [--slug=<slug>]
	 * : Custom slug. Generated when omitted.
	 *
	 * [--title=<title>]
	 * : Internal title.
	 *
	 * [--type=<code>]
	 * : Redirect status code: 301, 302, 307 or 308. Defaults to the setting.
	 *
	 * [--forward-params]
	 * : Append the incoming query string to the destination.
	 *
	 * [--no-track]
	 * : Do not record clicks.
	 *
	 * [--expires=<date>]
	 * : Expiry date in the site timezone, e.g. "2026-12-31 23:59".
	 *
	 * [--max-clicks=<number>]
	 * : Stop redirecting after this many clicks.
	 *
	 * [--groups=<names>]
	 * : Comma-separated group names; missing groups are created.
	 *
	 * [--password=<password>]
	 * : Ask visitors for this password before redirecting.
	 *
	 * [--force]
	 * : Allow a slug that replaces published content.
	 *
	 * [--porcelain]
	 * : Output only the short URL.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function create( $args, $assoc_args ) {
		$data = array_merge( [ 'target_url' => $args[0] ], $this->fields( $assoc_args ) );
		$link = $this->links->create( $data, (bool) Utils\get_flag_value( $assoc_args, 'force', false ) );

		if ( is_wp_error( $link ) ) {
			WP_CLI::error( $link );
		}

		if ( Utils\get_flag_value( $assoc_args, 'porcelain', false ) ) {
			WP_CLI::line( $link->short_url() );
			return;
		}

		WP_CLI::success( sprintf( 'Created link %d: %s', $link->id, $link->short_url() ) );
	}

	/**
	 * Update a short link.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Link ID.
	 *
	 * [--url=<url>]
	 * : Destination URL.
	 *
	 * [--slug=<slug>]
	 * : Slug.
	 *
	 * [--title=<title>]
	 * : Internal title.
	 *
	 * [--type=<code>]
	 * : Redirect status code.
	 *
	 * [--status=<status>]
	 * : active or disabled.
	 *
	 * [--forward-params]
	 * : Append the incoming query string. Use --no-forward-params to turn off.
	 *
	 * [--track]
	 * : Record clicks. Use --no-track to turn off.
	 *
	 * [--expires=<date>]
	 * : Expiry date in the site timezone. Pass an empty value to remove.
	 *
	 * [--max-clicks=<number>]
	 * : Click limit. Pass 0 to remove.
	 *
	 * [--groups=<names>]
	 * : Comma-separated group names, replacing the current ones. Pass an empty value to remove all.
	 *
	 * [--password=<password>]
	 * : Password visitors must enter. Pass an empty value to remove it.
	 *
	 * [--force]
	 * : Allow a slug that replaces published content.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function update( $args, $assoc_args ) {
		$data = $this->fields( $assoc_args );

		if ( isset( $assoc_args['url'] ) ) {
			$data['target_url'] = $assoc_args['url'];
		}

		if ( ! $data ) {
			WP_CLI::error( 'Nothing to update.' );
		}

		$link = $this->links->update( (int) $args[0], $data, (bool) Utils\get_flag_value( $assoc_args, 'force', false ) );

		if ( is_wp_error( $link ) ) {
			WP_CLI::error( $link );
		}

		WP_CLI::success( sprintf( 'Updated link %d.', $link->id ) );
	}

	/**
	 * Show a short link.
	 *
	 * ## OPTIONS
	 *
	 * <link>
	 * : Link ID or slug.
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
	public function get( $args, $assoc_args ) {
		$link = self::resolve( $this->links, $args[0] );
		$data = self::row( $link );

		Utils\format_items( $assoc_args['format'], [ $data ], array_keys( $data ) );
	}

	/**
	 * List short links.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Match slug, title or destination.
	 *
	 * [--group=<group>]
	 * : Only links in this group (name, slug or ID).
	 *
	 * [--orderby=<field>]
	 * : id, slug, created_at or total_clicks.
	 * ---
	 * default: id
	 * ---
	 *
	 * [--order=<order>]
	 * : ASC or DESC.
	 * ---
	 * default: DESC
	 * ---
	 *
	 * [--per-page=<number>]
	 * : Links per page, up to 100.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--page=<number>]
	 * : Page number.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields to show.
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
	 *   - ids
	 *   - count
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$result = $this->links->query(
			[
				'search'   => $assoc_args['search'] ?? '',
				'group'    => $assoc_args['group'] ?? 0,
				'orderby'  => $assoc_args['orderby'],
				'order'    => $assoc_args['order'],
				'per_page' => $assoc_args['per-page'],
				'page'     => $assoc_args['page'],
			]
		);

		$items = array_map(
			[ self::class, 'row' ],
			$result['items']
		);

		if ( 'ids' === $assoc_args['format'] ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $items, 'id' ) ) );
			return;
		}

		if ( 'count' === $assoc_args['format'] ) {
			WP_CLI::line( (string) $result['total'] );
			return;
		}

		$fields = isset( $assoc_args['fields'] ) ? explode( ',', $assoc_args['fields'] ) : self::FIELDS;

		Utils\format_items( $assoc_args['format'], $items, $fields );
	}

	/**
	 * Delete short links with their click data.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more link IDs.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function delete( $args, $assoc_args ) {
		WP_CLI::confirm( sprintf( 'Delete %d link(s) and their click data?', count( $args ) ), $assoc_args );

		foreach ( $args as $id ) {
			if ( $this->links->delete( (int) $id ) ) {
				WP_CLI::log( sprintf( 'Deleted link %d.', $id ) );
			} else {
				WP_CLI::warning( sprintf( 'Link %d not found.', $id ) );
			}
		}
	}

	/**
	 * Find a link by ID or slug, or stop with an error.
	 *
	 * @param Repository $links Link storage.
	 * @param string     $ref   ID or slug.
	 *
	 * @return Link
	 */
	public static function resolve( Repository $links, $ref ) {
		$link = ctype_digit( (string) $ref ) ? $links->find( (int) $ref ) : null;

		if ( ! $link ) {
			$link = $links->find_by_slug( Slug::normalize( $ref ) );
		}

		if ( ! $link ) {
			WP_CLI::error( sprintf( 'Link "%s" not found.', $ref ) );
		}

		return $link;
	}

	/**
	 * Link as a flat row for output, with group names joined.
	 *
	 * @param Link $link Link.
	 *
	 * @return array
	 */
	public static function row( Link $link ) {
		$data           = $link->to_array();
		$data['groups'] = implode( ', ', wp_list_pluck( $data['groups'], 'name' ) );

		return $data;
	}

	/**
	 * Map CLI flags to link fields.
	 *
	 * @param array $assoc_args Flags.
	 *
	 * @return array
	 */
	protected function fields( array $assoc_args ) {
		$map  = [
			'slug'       => 'slug',
			'title'      => 'title',
			'type'       => 'redirect_type',
			'status'     => 'status',
			'expires'    => 'expires_at',
			'max-clicks' => 'max_clicks',
			'password'   => 'password',
		];
		$data = [];

		foreach ( $map as $flag => $field ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$data[ $field ] = $assoc_args[ $flag ];
			}
		}

		if ( isset( $assoc_args['groups'] ) ) {
			$data['groups'] = array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['groups'] ) ) );
		}

		// WP-CLI turns --no-track into track => false.
		foreach ( [
			'forward-params' => 'forward_params',
			'track'          => 'track_clicks',
		] as $flag => $field ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$data[ $field ] = (bool) $assoc_args[ $flag ];
			}
		}

		return $data;
	}
}
