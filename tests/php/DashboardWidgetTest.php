<?php
/**
 * Dashboard widget.
 *
 * @package Clicklet
 */

namespace Clicklet\Tests;

use Clicklet\Admin\DashboardWidget;
use Clicklet\Links\Repository;
use Clicklet\Tracking\ClickStore;
use Clicklet\Tracking\Stats;

/**
 * @covers \Clicklet\Admin\DashboardWidget
 * @covers \Clicklet\Tracking\Stats::top_links
 */
class DashboardWidgetTest extends TestCase {

	/**
	 * Most clicked links of the last days, from daily totals.
	 */
	public function test_top_links() {
		$store = new ClickStore();
		$a     = $this->create_link(
			[
				'slug'  => 'a',
				'title' => 'Alpha',
			]
		);
		$b     = $this->create_link( [ 'slug' => 'b' ] );
		$c     = $this->create_link( [ 'slug' => 'c' ] );

		$store->set_daily( $a->id, wp_date( 'Y-m-d' ), 3, 3, 0 );
		$store->set_daily( $b->id, wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ), 5, 5, 0 );
		$store->set_daily( $c->id, wp_date( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ), 99, 9, 0 ); // Outside the window.

		$top = ( new Stats() )->top_links( 7, 5 );

		$this->assertSame( [ 'b', 'a' ], wp_list_pluck( $top, 'slug' ) );
		$this->assertSame( 5, $top[0]['clicks'] );
		$this->assertSame( 'Alpha', $top[1]['title'] );
	}

	/**
	 * The widget shows the form, the week's top links and a created link.
	 */
	public function test_render() {
		$link = $this->create_link(
			[
				'slug'  => 'hot',
				'title' => 'Hot link',
			]
		);
		( new ClickStore() )->set_daily( $link->id, wp_date( 'Y-m-d' ), 2, 2, 0 );
		$_GET[ DashboardWidget::CREATED_ARG ] = $link->id;

		ob_start();
		( new DashboardWidget( new Repository() ) )->render();
		$html = ob_get_clean();

		unset( $_GET[ DashboardWidget::CREATED_ARG ] );

		$this->assertStringContainsString( 'name="action" value="' . DashboardWidget::ACTION . '"', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
		$this->assertStringContainsString( 'Hot link', $html );
		$this->assertStringContainsString( 'value="' . esc_attr( $link->short_url() ) . '"', $html );
	}

	/**
	 * Only users who manage links get the widget.
	 */
	public function test_capability() {
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );

		$widget = new DashboardWidget( new Repository() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$widget->add();
		$this->assertFalse( isset( $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core']['clicklet'] ) );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$widget->add();
		$this->assertTrue( isset( $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core']['clicklet'] ) );

		set_current_screen( 'front' );
	}
}
