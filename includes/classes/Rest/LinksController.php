<?php
/**
 * Links REST endpoints.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Rest;

use FastUrlShortener\Links\Link;
use FastUrlShortener\Links\Repository;
use FastUrlShortener\Tracking\Stats;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * /links and /links/{id}/stats.
 */
class LinksController extends Controller {

	/**
	 * Link storage.
	 *
	 * @var Repository
	 */
	protected $links;

	/**
	 * Constructor.
	 *
	 * @param Repository $links Link storage.
	 */
	public function __construct( Repository $links ) {
		$this->links = $links;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/links',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => [
						'search'   => [
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'group'    => [
							'type'    => 'integer',
							'default' => 0,
							'minimum' => 0,
						],
						'orderby'  => [
							'type'    => 'string',
							'default' => 'id',
							'enum'    => [ 'id', 'slug', 'created_at', 'total_clicks' ],
						],
						'order'    => [
							'type'    => 'string',
							'default' => 'desc',
							'enum'    => [ 'asc', 'desc' ],
						],
						'per_page' => [
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						],
						'page'     => [
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => $this->link_args( true ),
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/links/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => $this->link_args( false ),
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/links/(?P<id>\d+)/stats',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => [ $this, 'permission_check' ],
				'args'                => [
					'days' => [
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 3650,
					],
				],
			]
		);
	}

	/**
	 * List links.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( WP_REST_Request $request ) {
		$result = $this->links->query(
			[
				'search'   => $request['search'],
				'group'    => $request['group'],
				'orderby'  => $request['orderby'],
				'order'    => $request['order'],
				'per_page' => $request['per_page'],
				'page'     => $request['page'],
			]
		);

		$response = new WP_REST_Response( array_map( [ $this, 'prepare_link' ], $result['items'] ) );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / $request['per_page'] ) ) );

		return $response;
	}

	/**
	 * Single link.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( WP_REST_Request $request ) {
		$link = $this->links->find( (int) $request['id'] );

		return $link ? rest_ensure_response( $this->prepare_link( $link ) ) : $this->not_found();
	}

	/**
	 * Create a link.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( WP_REST_Request $request ) {
		$link = $this->links->create( $this->link_data( $request ), (bool) $request['force'] );

		if ( is_wp_error( $link ) ) {
			return $this->with_status( $link );
		}

		$this->saved( $link, $request, true );

		$response = rest_ensure_response( $this->prepare_link( $link ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Update a link.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ) {
		$link = $this->links->update( (int) $request['id'], $this->link_data( $request ), (bool) $request['force'] );

		if ( is_wp_error( $link ) ) {
			return $this->with_status( $link );
		}

		$this->saved( $link, $request, false );

		return rest_ensure_response( $this->prepare_link( $link ) );
	}

	/**
	 * Delete a link.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( WP_REST_Request $request ) {
		if ( ! $this->links->delete( (int) $request['id'] ) ) {
			return $this->not_found();
		}

		return rest_ensure_response( [ 'deleted' => true ] );
	}

	/**
	 * Daily totals and breakdowns for a link.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_stats( WP_REST_Request $request ) {
		$link = $this->links->find( (int) $request['id'] );

		if ( ! $link ) {
			return $this->not_found();
		}

		$stats  = new Stats();
		$days   = (int) $request['days'];
		$since  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$labels = Stats::labels();
		$data   = [
			'daily'      => $stats->daily( $link->id, $days ),
			'breakdowns' => [],
		];

		foreach ( Stats::DIMENSIONS as $dimension ) {
			$rows = $stats->breakdown( $link->id, $dimension, $since, 10 );

			foreach ( $rows as &$row ) {
				if ( isset( $labels[ $dimension ] ) ) {
					$row['label'] = $labels[ $dimension ][ (int) $row['value'] ] ?? $row['value'];
				} else {
					$row['label'] = '' !== $row['value'] ? $row['value'] : __( 'Direct / unknown', 'fast-url-shortener' );
				}
			}
			unset( $row );

			$data['breakdowns'][ $dimension ] = $rows;
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Link as returned by the API.
	 *
	 * @param Link $link Link.
	 *
	 * @return array
	 */
	public function prepare_link( Link $link ) {
		$data = $link->to_array();

		// Expiry in the site timezone, for date inputs.
		$data['expires_at_local'] = $link->expires_at ? get_date_from_gmt( $link->expires_at, 'Y-m-d\TH:i' ) : '';

		/**
		 * Filter a link as returned by the REST API, e.g. to add fields
		 * stored in link meta.
		 *
		 * @hook fast_url_shortener_rest_prepare_link
		 *
		 * @param array $data Link data.
		 * @param Link  $link Link.
		 */
		return apply_filters( 'fast_url_shortener_rest_prepare_link', $data, $link );
	}

	/**
	 * Let extensions save their own fields from the request.
	 *
	 * @param Link            $link     Saved link.
	 * @param WP_REST_Request $request  Request.
	 * @param bool            $creating Whether the link was just created.
	 *
	 * @return void
	 */
	protected function saved( Link $link, WP_REST_Request $request, $creating ) {
		/**
		 * Fires after a link is created or updated through the REST API.
		 *
		 * @hook fast_url_shortener_rest_save_link
		 *
		 * @param Link            $link     Saved link.
		 * @param WP_REST_Request $request  Request.
		 * @param bool            $creating Whether the link was just created.
		 */
		do_action( 'fast_url_shortener_rest_save_link', $link, $request, $creating );
	}

	/**
	 * Link fields present in the request.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array
	 */
	protected function link_data( WP_REST_Request $request ) {
		$fields = [ 'target_url', 'slug', 'title', 'redirect_type', 'status', 'forward_params', 'track_clicks', 'expires_at', 'max_clicks', 'groups', 'password' ];
		$data   = [];
		$params = $request->get_params();

		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $params ) ) {
				$data[ $field ] = $params[ $field ];
			}
		}

		return $data;
	}

	/**
	 * Argument schema for create and update.
	 *
	 * @param bool $creating Whether the URL is required.
	 *
	 * @return array
	 */
	protected function link_args( $creating ) {
		$args = [
			'target_url'     => [
				'type'     => 'string',
				'required' => $creating,
			],
			'slug'           => [ 'type' => 'string' ],
			'title'          => [ 'type' => 'string' ],
			'redirect_type'  => [
				'type' => 'integer',
				'enum' => Repository::REDIRECT_TYPES,
			],
			'status'         => [
				'type' => 'string',
				'enum' => Repository::STATUSES,
			],
			'forward_params' => [ 'type' => 'boolean' ],
			'track_clicks'   => [ 'type' => 'boolean' ],
			'expires_at'     => [ 'type' => [ 'string', 'null' ] ],
			'max_clicks'     => [
				'type'    => [ 'integer', 'null' ],
				'minimum' => 0,
			],
			'groups'         => [
				'type'  => 'array',
				'items' => [ 'type' => [ 'integer', 'string' ] ],
			],
			// Write-only: responses only say has_password. An empty string removes the password.
			'password'       => [ 'type' => 'string' ],
			'force'          => [
				'type'    => 'boolean',
				'default' => false,
			],
		];

		/**
		 * Filter the REST arguments accepted when creating or updating a link.
		 *
		 * @hook fast_url_shortener_rest_link_args
		 *
		 * @param array $args     Argument schema.
		 * @param bool  $creating Whether this is the create endpoint.
		 */
		return apply_filters( 'fast_url_shortener_rest_link_args', $args, $creating );
	}

	/**
	 * Add an HTTP status to a validation error.
	 *
	 * @param WP_Error $error Error.
	 *
	 * @return WP_Error
	 */
	protected function with_status( WP_Error $error ) {
		$status = 'fast_url_shortener_not_found' === $error->get_error_code() ? 404 : 400;
		$error->add_data( [ 'status' => $status ] );

		return $error;
	}

	/**
	 * 404 error.
	 *
	 * @return WP_Error
	 */
	protected function not_found() {
		return new WP_Error( 'fast_url_shortener_not_found', __( 'Link not found.', 'fast-url-shortener' ), [ 'status' => 404 ] );
	}
}
