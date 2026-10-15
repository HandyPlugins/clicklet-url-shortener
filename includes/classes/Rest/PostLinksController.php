<?php
/**
 * Post short link REST endpoints.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Rest;

use FastUrlShortener\Links\PostLinks;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * /posts/{id}/link: read or create the short link of a post (block editor).
 */
class PostLinksController extends Controller {

	/**
	 * Post links.
	 *
	 * @var PostLinks
	 */
	protected $post_links;

	/**
	 * Links controller, for the response format.
	 *
	 * @var LinksController
	 */
	protected $links_controller;

	/**
	 * Constructor.
	 *
	 * @param PostLinks       $post_links       Post links.
	 * @param LinksController $links_controller Links controller.
	 */
	public function __construct( PostLinks $post_links, LinksController $links_controller ) {
		$this->post_links       = $post_links;
		$this->links_controller = $links_controller;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/link',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'post_permission_check' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'post_permission_check' ],
				],
			]
		);
	}

	/**
	 * Managing links and editing the post are both required.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return bool
	 */
	public function post_permission_check( WP_REST_Request $request ) {
		return $this->permission_check() && current_user_can( 'edit_post', (int) $request['id'] );
	}

	/**
	 * The post's link: { link: object|null }.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response
	 */
	public function get_item( WP_REST_Request $request ) {
		$link = $this->post_links->for_post( (int) $request['id'] );

		// Wrapped in an object: WordPress sends no body at all for a null response.
		return rest_ensure_response( [ 'link' => $link ? $this->links_controller->prepare_link( $link ) : null ] );
	}

	/**
	 * Create the post's link (or return the existing one).
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|\WP_Error
	 */
	public function create_item( WP_REST_Request $request ) {
		$link = $this->post_links->create( (int) $request['id'] );

		if ( is_wp_error( $link ) ) {
			$link->add_data( [ 'status' => 400 ] );
			return $link;
		}

		return rest_ensure_response( [ 'link' => $this->links_controller->prepare_link( $link ) ] );
	}
}
