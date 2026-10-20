<?php
/**
 * Short links for posts.
 *
 * @package Clicklet
 */

namespace Clicklet\Links;

use Clicklet\Schema;
use Clicklet\Settings;
use WP_Error;
use WP_Post;

/**
 * Creates short links for published posts of selected types, keeps their
 * destination in sync with the permalink, and serves them as the WordPress
 * shortlink (admin bar, rel=shortlink, wp_get_shortlink()).
 */
class PostLinks {

	/**
	 * Post meta holding the link ID.
	 */
	const POST_META = '_clicklet_link_id';

	/**
	 * Link meta holding the post ID.
	 */
	const LINK_META = 'post_id';

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_after_insert_post', [ $this, 'sync' ], 10, 2 );
		add_filter( 'pre_get_shortlink', [ $this, 'shortlink' ], 10, 3 );
	}

	/**
	 * Post types that get short links automatically.
	 *
	 * @return string[]
	 */
	public static function auto_post_types() {
		return array_values( array_intersect( (array) Settings::get( 'auto_post_types' ), get_post_types( [ 'public' => true ] ) ) );
	}

	/**
	 * After a post is saved: create its link when published, or point the
	 * existing link at the current permalink.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 *
	 * @return void
	 */
	public function sync( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}

		$link = $this->for_post( $post_id );

		if ( $link ) {
			$permalink = get_permalink( $post );

			if ( $permalink && $permalink !== $link->target_url ) {
				$this->links->update( $link->id, [ 'target_url' => $permalink ] );
			}

			return;
		}

		if ( in_array( $post->post_type, self::auto_post_types(), true ) ) {
			$this->create( $post_id );
		}
	}

	/**
	 * Link of a post, or null.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return Link|null
	 */
	public function for_post( $post_id ) {
		$link_id = (int) get_post_meta( $post_id, self::POST_META, true );
		$link    = $link_id ? $this->links->find_cached( $link_id ) : null;

		// The link was deleted: forget it.
		if ( $link_id && ! $link ) {
			delete_post_meta( $post_id, self::POST_META );
		}

		return $link;
	}

	/**
	 * Create a link for a published post, or return the existing one.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return Link|WP_Error
	 */
	public function create( $post_id ) {
		$existing = $this->for_post( $post_id );

		if ( $existing ) {
			return $existing;
		}

		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || ! is_post_type_viewable( $post->post_type ) ) {
			return new WP_Error( 'clicklet_post_not_published', __( 'Short links can be created for published posts only.', 'clicklet-url-shortener' ) );
		}

		$link = $this->links->create(
			[
				'target_url' => get_permalink( $post ),
				'title'      => wp_strip_all_tags( get_the_title( $post ) ),
			]
		);

		if ( is_wp_error( $link ) ) {
			return $link;
		}

		update_post_meta( $post_id, self::POST_META, $link->id );
		update_metadata( Schema::META_TYPE, $link->id, self::LINK_META, $post_id );

		return $link;
	}

	/**
	 * Serve a post's short link as its WordPress shortlink.
	 *
	 * @param false|string $shortlink Shortlink from earlier filters.
	 * @param int          $id        Post ID, or 0 for the current post.
	 * @param string       $context   "post" or "query".
	 *
	 * @return false|string
	 */
	public function shortlink( $shortlink, $id, $context ) {
		if ( false !== $shortlink ) {
			return $shortlink;
		}

		$post_id = 'query' === $context && is_singular() ? get_queried_object_id() : (int) $id;

		if ( ! $post_id ) {
			$post    = get_post();
			$post_id = $post ? $post->ID : 0;
		}

		$link = $post_id ? $this->for_post( $post_id ) : null;

		return $link && $link->is_available() ? $link->short_url() : $shortlink;
	}
}
