<?php
/**
 * Link groups.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Links\Groups;
use Clicklet\Links\Repository;
use WP_REST_Request;

/**
 * @covers \Clicklet\Links\Groups
 * @covers \Clicklet\Rest\GroupsController
 */
class GroupsTest extends TestCase {

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * Names create groups; IDs reuse them; links can be in several groups.
	 */
	public function test_assign_and_read() {
		$link = $this->create_link( [ 'groups' => [ 'Summer', 'Social', 'Social', '' ] ] );

		$this->assertSame( [ 'Social', 'Summer' ], wp_list_pluck( Groups::of( $link->id ), 'name' ) );

		$social = get_term_by( 'name', 'Social', Groups::TAXONOMY );
		$other  = $this->create_link( [ 'groups' => [ $social->term_id ] ] );

		$this->assertSame( [ 'Social' ], wp_list_pluck( Groups::of( $other->id ), 'name' ) );
		$this->assertSame( [ 'Social', 'Summer' ], wp_list_pluck( $link->to_array()['groups'], 'name' ) );
		$this->assertSame( 2, (int) get_term( $social->term_id, Groups::TAXONOMY )->count );
	}

	/**
	 * Updates replace groups only when given; an empty list clears them.
	 */
	public function test_update_groups() {
		$links = new Repository();
		$link  = $this->create_link( [ 'groups' => [ 'A' ] ] );

		$links->update( $link->id, [ 'title' => 'No group change' ] );
		$this->assertSame( [ 'A' ], wp_list_pluck( Groups::of( $link->id ), 'name' ) );

		$links->update( $link->id, [ 'groups' => [ 'B' ] ] );
		$this->assertSame( [ 'B' ], wp_list_pluck( Groups::of( $link->id ), 'name' ) );

		$links->update( $link->id, [ 'groups' => [] ] );
		$this->assertSame( [], Groups::of( $link->id ) );

		$this->assertSame( 'clicklet_invalid_groups', $links->update( $link->id, [ 'groups' => 'A' ] )->get_error_code() );
	}

	/**
	 * The list filters by group ID, slug or name; unknown groups match nothing.
	 */
	public function test_query_by_group() {
		$this->create_link(
			[
				'slug'   => 'one',
				'groups' => [ 'Summer Sale' ],
			]
		);
		$this->create_link(
			[
				'slug'   => 'two',
				'groups' => [ 'Summer Sale', 'Social' ],
			]
		);
		$this->create_link( [ 'slug' => 'three' ] );

		$links = new Repository();
		$term  = get_term_by( 'name', 'Summer Sale', Groups::TAXONOMY );

		$this->assertSame( 2, $links->query( [ 'group' => $term->term_id ] )['total'] );
		$this->assertSame( 2, $links->query( [ 'group' => 'summer-sale' ] )['total'] );
		$this->assertSame( [ 'two' ], wp_list_pluck( $links->query( [ 'group' => 'Social' ] )['items'], 'slug' ) );
		$this->assertSame( 0, $links->query( [ 'group' => 'missing' ] )['total'] );
		$this->assertSame( 3, $links->query()['total'] );
	}

	/**
	 * Deleting a link removes it from its groups.
	 */
	public function test_delete_link_updates_counts() {
		$link = $this->create_link( [ 'groups' => [ 'Temp' ] ] );
		$term = get_term_by( 'name', 'Temp', Groups::TAXONOMY );

		( new Repository() )->delete( $link->id );

		$this->assertSame( 0, (int) get_term( $term->term_id, Groups::TAXONOMY )->count );
		$this->assertSame( [], Groups::of( $link->id ) );
	}

	/**
	 * Group names are not exposed through the core REST API.
	 */
	public function test_not_public() {
		$this->create_link( [ 'groups' => [ 'Secret campaign' ] ] );

		$this->assertFalse( get_taxonomy( Groups::TAXONOMY )->public );
		$this->assertFalse( get_taxonomy( Groups::TAXONOMY )->show_in_rest );
	}

	/**
	 * REST: list, rename and delete, behind the links capability.
	 */
	public function test_rest() {
		$this->create_link( [ 'groups' => [ 'Old name' ] ] );
		$term = get_term_by( 'name', 'Old name', Groups::TAXONOMY );

		$this->assertSame( 401, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/clicklet-url-shortener/v1/groups' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$list = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/clicklet-url-shortener/v1/groups' ) )->get_data();
		$this->assertSame( 'Old name', $list[0]['name'] );
		$this->assertSame( 1, $list[0]['count'] );

		$rename = new WP_REST_Request( 'PUT', '/clicklet-url-shortener/v1/groups/' . $term->term_id );
		$rename->set_param( 'name', 'New name' );
		$this->assertSame( 'New name', rest_get_server()->dispatch( $rename )->get_data()['name'] );
		$this->assertSame( 'new-name', get_term( $term->term_id, Groups::TAXONOMY )->slug );

		$delete = new WP_REST_Request( 'DELETE', '/clicklet-url-shortener/v1/groups/' . $term->term_id );
		$this->assertSame( 200, rest_get_server()->dispatch( $delete )->get_status() );
		$this->assertSame( 404, rest_get_server()->dispatch( $delete )->get_status() );
		$this->assertSame( 1, $this->count_rows( 'links' ), 'Links survive their group.' );
	}
}
