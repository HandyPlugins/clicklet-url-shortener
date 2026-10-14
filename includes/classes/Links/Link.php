<?php
/**
 * Link value object.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Links;

use FastUrlShortener\Settings;

/**
 * A short link, as stored in the links table. Dates are UTC (MySQL format).
 */
final class Link {

	/**
	 * Link ID.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Slug, without the global prefix.
	 *
	 * @var string
	 */
	public $slug = '';

	/**
	 * Destination URL.
	 *
	 * @var string
	 */
	public $target_url = '';

	/**
	 * Internal title.
	 *
	 * @var string
	 */
	public $title = '';

	/**
	 * HTTP status code used for the redirect.
	 *
	 * @var int
	 */
	public $redirect_type = 302;

	/**
	 * Either "active" or "disabled".
	 *
	 * @var string
	 */
	public $status = 'active';

	/**
	 * Append the incoming query string to the target.
	 *
	 * @var bool
	 */
	public $forward_params = false;

	/**
	 * Record clicks for this link.
	 *
	 * @var bool
	 */
	public $track_clicks = true;

	/**
	 * Expiry date, or null.
	 *
	 * @var string|null
	 */
	public $expires_at = null;

	/**
	 * Click limit, or null.
	 *
	 * @var int|null
	 */
	public $max_clicks = null;

	/**
	 * Human clicks recorded so far.
	 *
	 * @var int
	 */
	public $total_clicks = 0;

	/**
	 * Last human click, or null.
	 *
	 * @var string|null
	 */
	public $last_clicked_at = null;

	/**
	 * Creator user ID.
	 *
	 * @var int
	 */
	public $author_id = 0;

	/**
	 * Creation date.
	 *
	 * @var string
	 */
	public $created_at = '';

	/**
	 * Last update date.
	 *
	 * @var string
	 */
	public $updated_at = '';

	/**
	 * Build a link from a database row.
	 *
	 * @param array $row Row as an associative array.
	 *
	 * @return self
	 */
	public static function from_row( array $row ) {
		$link = new self();

		$link->id              = (int) $row['id'];
		$link->slug            = (string) $row['slug'];
		$link->target_url      = (string) $row['target_url'];
		$link->title           = (string) $row['title'];
		$link->redirect_type   = (int) $row['redirect_type'];
		$link->status          = (string) $row['status'];
		$link->forward_params  = (bool) $row['forward_params'];
		$link->track_clicks    = (bool) $row['track_clicks'];
		$link->expires_at      = $row['expires_at'] ? (string) $row['expires_at'] : null;
		$link->max_clicks      = null !== $row['max_clicks'] ? (int) $row['max_clicks'] : null;
		$link->total_clicks    = (int) $row['total_clicks'];
		$link->last_clicked_at = $row['last_clicked_at'] ? (string) $row['last_clicked_at'] : null;
		$link->author_id       = (int) $row['author_id'];
		$link->created_at      = (string) $row['created_at'];
		$link->updated_at      = (string) $row['updated_at'];

		return $link;
	}

	/**
	 * Whether the link should redirect right now.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'active' !== $this->status ) {
			return false;
		}

		if ( $this->expires_at && strtotime( $this->expires_at . ' UTC' ) <= time() ) {
			return false;
		}

		if ( null !== $this->max_clicks && $this->total_clicks >= $this->max_clicks ) {
			return false;
		}

		return true;
	}

	/**
	 * Public short URL.
	 *
	 * @return string
	 */
	public function short_url() {
		$prefix = trim( (string) Settings::get( 'prefix' ), '/' );

		return home_url( '/' . ( '' !== $prefix ? $prefix . '/' : '' ) . $this->slug );
	}

	/**
	 * Array representation, for the CLI and REST API.
	 *
	 * @return array
	 */
	public function to_array() {
		return array_merge( get_object_vars( $this ), [ 'short_url' => $this->short_url() ] );
	}
}
