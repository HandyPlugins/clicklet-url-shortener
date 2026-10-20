<?php
/**
 * Short links for posts.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Links\PostLinks;
use Clicklet\Links\Repository;
use Clicklet\Schema;
use WP_REST_Request;

/**
 * @covers \Clicklet\Links\PostLinks
 * @covers \Clicklet\Rest\PostLinksController
 */
class PostLinksTest extends TestCase {

	/**
	 * Post links under test.
	 *
	 * @var PostLinks
	 */
	protected $post_links;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->post_links          = new PostLinks( new Repository() );
		$GLOBALS['wp_rest_server'] = null;
		$this->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Publishing a post of an enabled type creates its link.
	 */
	public function test_auto_create_on_publish() {
		$this->settings( [ 'auto_post_types' => [ 'post' ] ] );

		$draft = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->assertNull( $this->post_links->for_post( $draft ) );

		wp_publish_post( $draft );

		$link = $this->post_links->for_post( $draft );
		$this->assertNotNull( $link );
		$this->assertSame( get_permalink( $draft ), $link->target_url );
		$this->assertSame( $draft, (int) $link->get_meta( PostLinks::LINK_META ) );

		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$this->assertNull( $this->post_links->for_post( $page ), 'Pages are not enabled.' );
	}

	/**
	 * Nothing happens while automatic links are off.
	 */
	public function test_off_by_default() {
		$post = self::factory()->post->create();

		$this->assertNull( $this->post_links->for_post( $post ) );
		$this->assertSame( 0, $this->count_rows( 'links' ) );
	}

	/**
	 * A permalink change updates the destination, even when automatic
	 * links were turned off later.
	 */
	public function test_destination_follows_permalink() {
		$post = self::factory()->post->create( [ 'post_name' => 'old-name' ] );
		$link = $this->post_links->create( $post );

		wp_update_post(
			[
				'ID'        => $post,
				'post_name' => 'new-name',
			]
		);

		$this->assertStringEndsWith( '/new-name/', ( new Repository() )->find( $link->id )->target_url );
	}

	/**
	 * Only published posts get links, and create() is idempotent.
	 */
	public function test_create() {
		$draft = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->assertSame( 'clicklet_post_not_published', $this->post_links->create( $draft )->get_error_code() );

		$post  = self::factory()->post->create();
		$first = $this->post_links->create( $post );

		$this->assertSame( $first->id, $this->post_links->create( $post )->id );
		$this->assertSame( 1, $this->count_rows( 'links' ) );
	}

	/**
	 * A deleted link is forgotten by its post.
	 */
	public function test_deleted_link() {
		$post = self::factory()->post->create();
		$link = $this->post_links->create( $post );

		( new Repository() )->delete( $link->id );

		$this->assertNull( $this->post_links->for_post( $post ) );
		$this->assertSame( '', get_post_meta( $post, PostLinks::POST_META, true ) );
	}

	/**
	 * The link is the WordPress shortlink, while it redirects.
	 */
	public function test_shortlink() {
		$post = self::factory()->post->create();

		$this->assertStringContainsString( '?p=' . $post, wp_get_shortlink( $post ) );

		$link = $this->post_links->create( $post );
		$this->assertSame( $link->short_url(), wp_get_shortlink( $post ) );

		( new Repository() )->update( $link->id, [ 'status' => 'disabled' ] );
		$this->assertStringContainsString( '?p=' . $post, wp_get_shortlink( $post ) );
	}

	/**
	 * Block editor endpoint: needs the links capability and the right to edit the post.
	 */
	public function test_rest() {
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$post   = self::factory()->post->create( [ 'post_author' => $admin ] );
		$route  = '/clicklet-url-shortener/v1/posts/' . $post . '/link';

		wp_set_current_user( $author );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) )->get_status() );

		wp_set_current_user( $admin );
		// Always an object: WordPress sends an empty body for null, which is not valid JSON.
		$this->assertSame( [ 'link' => null ], rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) )->get_data() );

		$created = rest_get_server()->dispatch( new WP_REST_Request( 'POST', $route ) );
		$this->assertSame( 200, $created->get_status() );
		$this->assertSame( get_permalink( $post ), $created->get_data()['link']['target_url'] );
		$this->assertSame( $created->get_data()['link']['id'], rest_get_server()->dispatch( new WP_REST_Request( 'GET', $route ) )->get_data()['link']['id'] );

		$draft = self::factory()->post->create( [ 'post_status' => 'draft' ] );
		$this->assertSame( 400, rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/clicklet-url-shortener/v1/posts/' . $draft . '/link' ) )->get_status() );
	}

	/**
	 * Settings only keep public post types.
	 */
	public function test_auto_post_types_setting() {
		$this->settings( [ 'auto_post_types' => [ 'post', 'page', 'revision', 'nope' ] ] );

		$this->assertSame( [ 'post', 'page' ], PostLinks::auto_post_types() );
	}

	/**
	 * Cached ID lookups follow slug changes and deletes.
	 */
	public function test_find_cached() {
		$links = new Repository();
		$link  = $this->create_link( [ 'slug' => 'before' ] );

		$this->assertSame( 'before', $links->find_cached( $link->id )->slug );

		$links->update( $link->id, [ 'slug' => 'after' ] );
		$this->assertSame( 'after', $links->find_cached( $link->id )->slug );

		$links->delete( $link->id );
		$this->assertNull( $links->find_cached( $link->id ) );
		$this->assertSame( '', get_metadata( Schema::META_TYPE, $link->id, PostLinks::LINK_META, true ) );
	}
}
