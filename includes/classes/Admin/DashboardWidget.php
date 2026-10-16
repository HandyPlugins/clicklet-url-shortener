<?php
/**
 * Dashboard widget.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Admin;

use FastUrlShortener\Links\Repository;
use FastUrlShortener\Rest\Controller;
use FastUrlShortener\Tracking\Stats;

/**
 * "Short links" dashboard widget: shorten a URL and see the most clicked
 * links of the week. Server-rendered, no scripts.
 */
class DashboardWidget {

	/**
	 * admin-post action and nonce action.
	 */
	const ACTION = 'fast_url_shortener_quick_add';

	/**
	 * Query argument carrying the created link ID back to the dashboard.
	 */
	const CREATED_ARG = 'fus_created';

	/**
	 * Transient prefix for quick add errors, per user.
	 */
	const ERROR_TRANSIENT = 'fast_url_shortener_quick_add_error_';

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
		add_action( 'wp_dashboard_setup', [ $this, 'add' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'quick_add' ] );
	}

	/**
	 * Add the widget for users who manage links.
	 *
	 * @return void
	 */
	public function add() {
		if ( current_user_can( Controller::capability( 'links' ) ) ) {
			wp_add_dashboard_widget( 'fast_url_shortener', __( 'Short Links', 'fast-url-shortener' ), [ $this, 'render' ] );
		}
	}

	/**
	 * Widget content.
	 *
	 * @return void
	 */
	public function render() {
		$created = isset( $_GET[ self::CREATED_ARG ] ) ? $this->links->find( absint( $_GET[ self::CREATED_ARG ] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$error   = (string) get_transient( self::ERROR_TRANSIENT . get_current_user_id() );

		if ( '' !== $error ) {
			delete_transient( self::ERROR_TRANSIENT . get_current_user_id() );
			printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $error ) );
		}

		if ( $created ) {
			printf(
				'<div class="notice notice-success inline"><p>%1$s</p><p><input type="text" class="large-text" readonly value="%2$s" onfocus="this.select()" aria-label="%3$s" /></p></div>',
				esc_html__( 'Short link ready:', 'fast-url-shortener' ),
				esc_attr( $created->short_url() ),
				esc_attr__( 'Short link', 'fast-url-shortener' )
			);
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;margin:0 0 16px;">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
			<?php wp_nonce_field( self::ACTION ); ?>
			<label class="screen-reader-text" for="fus-quick-url"><?php esc_html_e( 'Long URL', 'fast-url-shortener' ); ?></label>
			<input type="url" id="fus-quick-url" name="target_url" class="regular-text" style="flex:1;min-width:0;" required placeholder="<?php esc_attr_e( 'Paste a long URL to shorten it', 'fast-url-shortener' ); ?>" />
			<?php submit_button( __( 'Shorten', 'fast-url-shortener' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
		$this->render_top_links();
	}

	/**
	 * Most clicked links of the last 7 days.
	 *
	 * @return void
	 */
	protected function render_top_links() {
		$top   = ( new Stats() )->top_links( 7, 5 );
		$links = admin_url( 'admin.php?page=' . Page::SLUG );

		echo '<h3>' . esc_html__( 'Most clicked this week', 'fast-url-shortener' ) . '</h3>';

		if ( ! $top ) {
			echo '<p>' . esc_html__( 'No clicks in the last 7 days.', 'fast-url-shortener' ) . '</p>';
		} else {
			echo '<ul>';

			foreach ( $top as $row ) {
				printf(
					'<li style="display:flex;justify-content:space-between;gap:8px;"><a href="%1$s">%2$s</a><span>%3$s</span></li>',
					esc_url( add_query_arg( 's', $row['slug'], $links ) ),
					esc_html( '' !== $row['title'] ? $row['title'] : '/' . $row['slug'] ),
					esc_html(
						sprintf(
							/* translators: %s: number of clicks. */
							_n( '%s click', '%s clicks', $row['clicks'], 'fast-url-shortener' ),
							number_format_i18n( $row['clicks'] )
						)
					)
				);
			}

			echo '</ul>';
		}

		printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $links ), esc_html__( 'All short links', 'fast-url-shortener' ) );
	}

	/**
	 * Create a link from the widget form and return to the dashboard.
	 *
	 * @return void
	 */
	public function quick_add() {
		if ( ! current_user_can( Controller::capability( 'links' ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to create short links.', 'fast-url-shortener' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$link     = $this->links->create( [ 'target_url' => isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '' ] );
		$redirect = admin_url( 'index.php' );

		if ( is_wp_error( $link ) ) {
			set_transient( self::ERROR_TRANSIENT . get_current_user_id(), $link->get_error_message(), MINUTE_IN_SECONDS );
		} else {
			$redirect = add_query_arg( self::CREATED_ARG, $link->id, $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
