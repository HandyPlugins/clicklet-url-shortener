<?php
/**
 * CSV import REST endpoints.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Rest;

use FastUrlShortener\Transfer\CsvImporter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * /transfer (status, cancel), /transfer/upload and /transfer/step.
 * Exports are plain downloads through admin-post.php; see Exporter.
 */
class TransferController extends Controller {

	/**
	 * CSV importer.
	 *
	 * @var CsvImporter
	 */
	protected $importer;

	/**
	 * Constructor.
	 *
	 * @param CsvImporter $importer CSV importer.
	 */
	public function __construct( CsvImporter $importer ) {
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
			'/transfer',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'status' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'cancel' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/transfer/upload',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'upload' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'overwrite' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/transfer/step',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'step' ],
				'permission_callback' => [ $this, 'permission_check' ],
			]
		);
	}

	/**
	 * Current or last import.
	 *
	 * @return array|null
	 */
	public function status() {
		return $this->importer->status();
	}

	/**
	 * Cancel the current import.
	 *
	 * @return array
	 */
	public function cancel() {
		$this->importer->cancel();

		return [ 'cancelled' => true ];
	}

	/**
	 * Store an uploaded CSV and read its header.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'fast_url_shortener_csv_missing', __( 'Choose a CSV file.', 'fast-url-shortener' ), [ 'status' => 400 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$path   = CsvImporter::upload_path();
		$filter = static function ( $dirs ) use ( $path ) {
			return array_merge(
				$dirs,
				[
					'path'   => $path,
					'subdir' => '/' . CsvImporter::UPLOAD_DIR,
				]
			);
		};

		add_filter( 'upload_dir', $filter );
		$result = wp_handle_upload(
			$files['file'],
			[
				'test_form'                => false,
				'mimes'                    => [
					'csv' => 'text/csv',
					'txt' => 'text/plain',
				],
				// Random names: the uploads folder may be publicly reachable.
				'unique_filename_callback' => static function () {
					return wp_generate_password( 32, false ) . '.csv';
				},
			]
		);
		remove_filter( 'upload_dir', $filter );

		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'fast_url_shortener_csv_upload', $result['error'], [ 'status' => 400 ] );
		}

		$progress = $this->importer->start( $result['file'], (bool) $request['overwrite'], true );

		if ( is_wp_error( $progress ) ) {
			$progress->add_data( [ 'status' => 400 ] );
		}

		return $progress;
	}

	/**
	 * Import the next batch.
	 *
	 * @return array|WP_Error
	 */
	public function step() {
		$progress = $this->importer->step();

		if ( null === $progress ) {
			return new WP_Error( 'fast_url_shortener_csv_not_started', __( 'Upload a file first.', 'fast-url-shortener' ), [ 'status' => 400 ] );
		}

		if ( is_wp_error( $progress ) ) {
			$progress->add_data( [ 'status' => 400 ] );
		}

		return $progress;
	}
}
