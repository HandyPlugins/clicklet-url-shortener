<?php
/**
 * Import REST endpoints.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Rest;

use FastUrlShortener\Import\Importer;
use FastUrlShortener\Import\Source;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * /import, /import/{source}/start and /import/{source}/step.
 */
class ImportController extends Controller {

	/**
	 * Import runner.
	 *
	 * @var Importer
	 */
	protected $importer;

	/**
	 * Constructor.
	 *
	 * @param Importer $importer Import runner.
	 */
	public function __construct( Importer $importer ) {
		$this->importer = $importer;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/import',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_sources' ],
				'permission_callback' => [ $this, 'permission_check' ],
			]
		);

		foreach ( [ 'start', 'step' ] as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/import/(?P<source>[a-z-]+)/' . $action,
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, $action ],
					'permission_callback' => [ $this, 'permission_check' ],
				]
			);
		}
	}

	/**
	 * Available sources with their size and last import progress.
	 *
	 * @return array
	 */
	public function get_sources() {
		$data = [];

		foreach ( Importer::sources() as $source ) {
			$available = $source->is_available();
			$data[]    = [
				'id'        => $source->id(),
				'label'     => $source->label(),
				'available' => $available,
				'links'     => $available ? $source->count_links() : 0,
				'clicks'    => $available ? $source->count_clicks() : 0,
				'prefix'    => $available ? $source->common_prefix() : '',
				'progress'  => $this->importer->status( $source ),
			];
		}

		return $data;
	}

	/**
	 * Start an import.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array|WP_Error
	 */
	public function start( WP_REST_Request $request ) {
		$source = $this->source( $request );

		return is_wp_error( $source ) ? $source : $this->importer->start( $source );
	}

	/**
	 * Run one import step.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array|WP_Error
	 */
	public function step( WP_REST_Request $request ) {
		$source = $this->source( $request );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$progress = $this->importer->step( $source );

		return $progress ? $progress : new WP_Error( 'fast_url_shortener_import_not_started', __( 'Start the import first.', 'fast-url-shortener' ), [ 'status' => 400 ] );
	}

	/**
	 * Source from the request.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return Source|WP_Error
	 */
	protected function source( WP_REST_Request $request ) {
		$sources = Importer::sources();
		$source  = $sources[ $request['source'] ] ?? null;

		if ( ! $source || ! $source->is_available() ) {
			return new WP_Error( 'fast_url_shortener_import_unavailable', __( 'This import source is not available.', 'fast-url-shortener' ), [ 'status' => 404 ] );
		}

		return $source;
	}
}
