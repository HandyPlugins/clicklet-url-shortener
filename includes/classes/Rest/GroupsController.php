<?php
/**
 * Groups REST endpoints.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Rest;

use FastUrlShortener\Links\Groups;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * /groups and /groups/{id}. Groups are created by assigning them to links.
 */
class GroupsController extends Controller {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/groups',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ Groups::class, 'all' ],
				'permission_callback' => [ $this, 'permission_check' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/groups/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'permission_check' ],
					'args'                => [
						'name' => [
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'permission_check' ],
				],
			]
		);
	}

	/**
	 * Rename a group.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array|WP_Error
	 */
	public function update_item( WP_REST_Request $request ) {
		$name = trim( (string) $request['name'] );

		if ( '' === $name ) {
			return new WP_Error( 'fast_url_shortener_invalid_group', __( 'Enter a group name.', 'fast-url-shortener' ), [ 'status' => 400 ] );
		}

		$result = wp_update_term(
			(int) $request['id'],
			Groups::TAXONOMY,
			[
				'name' => $name,
				'slug' => sanitize_title( $name ),
			]
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 'invalid_term' === $result->get_error_code() ? 404 : 400 ] );
			return $result;
		}

		return [
			'id'   => (int) $result['term_id'],
			'name' => $name,
		];
	}

	/**
	 * Delete a group. Its links are kept.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return array|WP_Error
	 */
	public function delete_item( WP_REST_Request $request ) {
		$result = wp_delete_term( (int) $request['id'], Groups::TAXONOMY );

		if ( ! $result || is_wp_error( $result ) ) {
			return new WP_Error( 'fast_url_shortener_group_not_found', __( 'Group not found.', 'fast-url-shortener' ), [ 'status' => 404 ] );
		}

		return [ 'deleted' => true ];
	}
}
