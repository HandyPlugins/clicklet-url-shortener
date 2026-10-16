<?php
/**
 * Link value object.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Links;

use FastUrlShortener\Schema;
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
	 * Set by extensions when the link has redirect rules that need every
	 * plugin loaded; the fast path then leaves the link to the normal path.
	 *
	 * @var bool
	 */
	public $has_rules = false;

	/**
	 * Password hash, or an empty string. Never part of the public data.
	 *
	 * @var string
	 */
	protected $password_hash = '';

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
		$link->has_rules       = ! empty( $row['has_rules'] );
		$link->password_hash   = (string) ( $row['password_hash'] ?? '' );
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
		$available = 'active' === $this->status
			&& ! ( $this->expires_at && strtotime( $this->expires_at . ' UTC' ) <= time() )
			&& ! ( null !== $this->max_clicks && $this->total_clicks >= $this->max_clicks );

		/**
		 * Filter whether a link redirects right now.
		 *
		 * @hook fast_url_shortener_link_available
		 *
		 * @param bool $available Availability from status, expiry and click limit.
		 * @param Link $link      Link.
		 */
		return (bool) apply_filters( 'fast_url_shortener_link_available', $available, $this );
	}

	/**
	 * Whether visitors need a password.
	 *
	 * @return bool
	 */
	public function has_password() {
		return '' !== $this->password_hash;
	}

	/**
	 * Check a password.
	 *
	 * @param string $password Plain password.
	 *
	 * @return bool
	 */
	public function check_password( $password ) {
		return $this->has_password() && '' !== $password && wp_check_password( $password, $this->password_hash );
	}

	/**
	 * Password hash, for exports to another site.
	 *
	 * @return string
	 */
	public function password_hash() {
		return $this->password_hash;
	}

	/**
	 * Read link meta.
	 *
	 * @param string $key    Meta key.
	 * @param bool   $single Return a single value.
	 *
	 * @return mixed
	 */
	public function get_meta( $key, $single = true ) {
		return get_metadata( Schema::META_TYPE, $this->id, $key, $single );
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
		$data = get_object_vars( $this );
		unset( $data['password_hash'] );

		return array_merge(
			$data,
			[
				'has_password' => $this->has_password(),
				'short_url'    => $this->short_url(),
				'groups'       => Groups::of( $this->id ),
			]
		);
	}
}
