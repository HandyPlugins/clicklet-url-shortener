<?php
/**
 * Link storage.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Links;

use FastUrlShortener\Schema;
use FastUrlShortener\Settings;
use WP_Error;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables. The redirect path reads through the object cache in find_by_slug(); the rest are writes and admin/CLI reads.

/**
 * CRUD for links, with an object cache in front of slug lookups.
 */
class Repository {

	/**
	 * Object cache group.
	 */
	const CACHE_GROUP = 'fast_url_shortener';

	/**
	 * Redirect status codes a link may use.
	 */
	const REDIRECT_TYPES = [ 301, 302, 307, 308 ];

	/**
	 * Link statuses.
	 */
	const STATUSES = [ 'active', 'disabled' ];

	/**
	 * Find a link by ID.
	 *
	 * @param int $id Link ID.
	 *
	 * @return Link|null
	 */
	public function find( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'links' ), absint( $id ) ), ARRAY_A );

		return $row ? Link::from_row( $row ) : null;
	}

	/**
	 * Find a link by slug. Misses are cached too, so unknown paths stay cheap
	 * with a persistent object cache.
	 *
	 * @param string $slug Normalized slug.
	 *
	 * @return Link|null
	 */
	public function find_by_slug( $slug ) {
		global $wpdb;

		$key    = $this->cache_key( $slug );
		$found  = false;
		$cached = wp_cache_get( $key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return is_array( $cached ) ? Link::from_row( $cached ) : null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s', Schema::table( 'links' ), $slug ), ARRAY_A );

		wp_cache_set( $key, $row ? $row : 0, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $row ? Link::from_row( $row ) : null;
	}

	/**
	 * Paginated list of links.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type string $search   Matches slug, title or target URL.
	 *     @type string $orderby  id, slug, created_at or total_clicks.
	 *     @type string $order    ASC or DESC.
	 *     @type int    $per_page 1-100.
	 *     @type int    $page     Page number.
	 * }
	 *
	 * @return array{items: Link[], total: int}
	 */
	public function query( array $args = [] ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			[
				'search'   => '',
				'orderby'  => 'id',
				'order'    => 'DESC',
				'per_page' => 20,
				'page'     => 1,
			]
		);

		$table    = Schema::table( 'links' );
		$orderby  = in_array( $args['orderby'], [ 'id', 'slug', 'created_at', 'total_clicks' ], true ) ? $args['orderby'] : 'id';
		$per_page = min( 100, max( 1, absint( $args['per_page'] ) ) );
		$offset   = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;

		// An empty search becomes "%%", which matches every row.
		$like = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';

		// ORDER BY direction cannot be a placeholder, so each direction gets its own query.
		if ( 'ASC' === strtoupper( $args['order'] ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE slug LIKE %s OR title LIKE %s OR target_url LIKE %s ORDER BY %i ASC LIMIT %d OFFSET %d', $table, $like, $like, $like, $orderby, $per_page, $offset ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE slug LIKE %s OR title LIKE %s OR target_url LIKE %s ORDER BY %i DESC LIMIT %d OFFSET %d', $table, $like, $like, $like, $orderby, $per_page, $offset ),
				ARRAY_A
			);
		}

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE slug LIKE %s OR title LIKE %s OR target_url LIKE %s', $table, $like, $like, $like )
		);

		return [
			'items' => array_map( [ Link::class, 'from_row' ], $rows ),
			'total' => $total,
		];
	}

	/**
	 * Create a link.
	 *
	 * @param array $data  Link fields. `target_url` is required; a slug is generated when missing.
	 * @param bool  $force Allow a slug that hides published content.
	 *
	 * @return Link|WP_Error
	 */
	public function create( array $data, $force = false ) {
		global $wpdb;

		$fields = $this->prepare( $data, null, $force );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$now    = current_time( 'mysql', true );
		$fields = array_merge(
			[
				'redirect_type' => (int) Settings::get( 'redirect_type' ),
				'author_id'     => get_current_user_id(),
			],
			$fields,
			[
				'created_at' => $now,
				'updated_at' => $now,
			]
		);

		if ( ! $wpdb->insert( Schema::table( 'links' ), $fields ) ) {
			return new WP_Error( 'fast_url_shortener_db_error', __( 'The link could not be saved.', 'fast-url-shortener' ) );
		}

		$this->flush_cache( $fields['slug'] );

		$link = $this->find( $wpdb->insert_id );

		/**
		 * Fires after a link is created.
		 *
		 * @hook fast_url_shortener_link_created
		 *
		 * @param Link $link New link.
		 */
		do_action( 'fast_url_shortener_link_created', $link );

		return $link;
	}

	/**
	 * Update a link. Only the given fields change.
	 *
	 * @param int   $id    Link ID.
	 * @param array $data  Fields to change.
	 * @param bool  $force Allow a slug that hides published content.
	 *
	 * @return Link|WP_Error
	 */
	public function update( $id, array $data, $force = false ) {
		global $wpdb;

		$link = $this->find( $id );

		if ( ! $link ) {
			return new WP_Error( 'fast_url_shortener_not_found', __( 'Link not found.', 'fast-url-shortener' ) );
		}

		$fields = $this->prepare( $data, $link, $force );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$fields['updated_at'] = current_time( 'mysql', true );

		if ( false === $wpdb->update( Schema::table( 'links' ), $fields, [ 'id' => $link->id ] ) ) {
			return new WP_Error( 'fast_url_shortener_db_error', __( 'The link could not be saved.', 'fast-url-shortener' ) );
		}

		$this->flush_cache( $link->slug );

		if ( isset( $fields['slug'] ) ) {
			$this->flush_cache( $fields['slug'] );
		}

		$updated = $this->find( $link->id );

		/**
		 * Fires after a link is updated.
		 *
		 * @hook fast_url_shortener_link_updated
		 *
		 * @param Link $updated Link after the update.
		 * @param Link $link    Link before the update.
		 */
		do_action( 'fast_url_shortener_link_updated', $updated, $link );

		return $updated;
	}

	/**
	 * Delete a link with its click data.
	 *
	 * @param int $id Link ID.
	 *
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;

		$link = $this->find( $id );

		if ( ! $link ) {
			return false;
		}

		$wpdb->delete( Schema::table( 'links' ), [ 'id' => $link->id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'clicks' ), [ 'link_id' => $link->id ], [ '%d' ] );
		$wpdb->delete( Schema::table( 'stats_daily' ), [ 'link_id' => $link->id ], [ '%d' ] );

		$this->flush_cache( $link->slug );

		/**
		 * Fires after a link is deleted.
		 *
		 * @hook fast_url_shortener_link_deleted
		 *
		 * @param Link $link Deleted link.
		 */
		do_action( 'fast_url_shortener_link_deleted', $link );

		return true;
	}

	/**
	 * Count a human click. Atomic, so concurrent clicks are not lost.
	 *
	 * @param Link   $link       Link.
	 * @param string $clicked_at UTC datetime.
	 *
	 * @return void
	 */
	public function increment_clicks( Link $link, $clicked_at ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET total_clicks = total_clicks + 1, last_clicked_at = %s WHERE id = %d',
				Schema::table( 'links' ),
				$clicked_at,
				$link->id
			)
		);

		// The cached copy only matters for the click limit; refresh it then.
		if ( null !== $link->max_clicks ) {
			$this->flush_cache( $link->slug );
		}
	}

	/**
	 * Whether a slug is taken.
	 *
	 * @param string $slug       Normalized slug.
	 * @param int    $exclude_id Link ID to ignore.
	 *
	 * @return bool
	 */
	public function slug_exists( $slug, $exclude_id = 0 ) {
		global $wpdb;

		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE slug = %s', Schema::table( 'links' ), $slug ) );

		return $id && (int) $id !== (int) $exclude_id;
	}

	/**
	 * Validate and sanitize link fields.
	 *
	 * @param array     $data     Raw fields.
	 * @param Link|null $existing Link being updated, or null when creating.
	 * @param bool      $force    Allow a slug that hides published content.
	 *
	 * @return array|WP_Error Column => value.
	 */
	protected function prepare( array $data, $existing, $force ) {
		$fields = [];

		if ( ! $existing || array_key_exists( 'target_url', $data ) ) {
			$url = esc_url_raw( trim( (string) ( $data['target_url'] ?? '' ) ), [ 'http', 'https' ] );

			if ( '' === $url || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
				return new WP_Error( 'fast_url_shortener_invalid_target', __( 'Enter a valid http or https URL.', 'fast-url-shortener' ) );
			}

			$fields['target_url'] = $url;
		}

		if ( isset( $data['slug'] ) && '' !== $data['slug'] ) {
			$slug  = Slug::normalize( $data['slug'] );
			$error = $this->validate_slug( $slug, $existing ? $existing->id : 0, $force );

			if ( $error ) {
				return $error;
			}

			$fields['slug'] = $slug;
		} elseif ( ! $existing ) {
			$fields['slug'] = $this->unique_slug();
		}

		if ( isset( $data['title'] ) ) {
			$fields['title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['redirect_type'] ) ) {
			if ( ! in_array( (int) $data['redirect_type'], self::REDIRECT_TYPES, true ) ) {
				return new WP_Error( 'fast_url_shortener_invalid_redirect_type', __( 'Redirect type must be 301, 302, 307 or 308.', 'fast-url-shortener' ) );
			}

			$fields['redirect_type'] = (int) $data['redirect_type'];
		}

		if ( isset( $data['status'] ) ) {
			if ( ! in_array( $data['status'], self::STATUSES, true ) ) {
				return new WP_Error( 'fast_url_shortener_invalid_status', __( 'Status must be active or disabled.', 'fast-url-shortener' ) );
			}

			$fields['status'] = $data['status'];
		}

		foreach ( [ 'forward_params', 'track_clicks' ] as $flag ) {
			if ( isset( $data[ $flag ] ) ) {
				$fields[ $flag ] = (int) (bool) $data[ $flag ];
			}
		}

		if ( array_key_exists( 'expires_at', $data ) ) {
			if ( empty( $data['expires_at'] ) ) {
				$fields['expires_at'] = null;
			} elseif ( false === strtotime( $data['expires_at'] ) ) {
				return new WP_Error( 'fast_url_shortener_invalid_expiry', __( 'Enter a valid expiry date.', 'fast-url-shortener' ) );
			} else {
				// Input is in the site's timezone; storage is UTC.
				$fields['expires_at'] = get_gmt_from_date( $data['expires_at'] );
			}
		}

		if ( array_key_exists( 'max_clicks', $data ) ) {
			$fields['max_clicks'] = absint( $data['max_clicks'] ) ? absint( $data['max_clicks'] ) : null;
		}

		return $fields;
	}

	/**
	 * Validate a custom slug.
	 *
	 * @param string $slug       Normalized slug.
	 * @param int    $exclude_id Link being updated.
	 * @param bool   $force      Allow a slug that hides published content.
	 *
	 * @return WP_Error|null
	 */
	protected function validate_slug( $slug, $exclude_id, $force ) {
		if ( ! Slug::is_valid( $slug ) ) {
			return new WP_Error( 'fast_url_shortener_invalid_slug', __( 'Slugs may contain letters, numbers, hyphens and underscores, up to 100 characters.', 'fast-url-shortener' ) );
		}

		if ( Slug::is_reserved( $slug ) ) {
			return new WP_Error( 'fast_url_shortener_reserved_slug', __( 'This slug is reserved by WordPress.', 'fast-url-shortener' ) );
		}

		if ( $this->slug_exists( $slug, $exclude_id ) ) {
			return new WP_Error( 'fast_url_shortener_slug_exists', __( 'Another link already uses this slug.', 'fast-url-shortener' ) );
		}

		if ( ! $force && Slug::conflicting_post( $slug ) ) {
			return new WP_Error( 'fast_url_shortener_slug_conflict', __( 'Published content already uses this URL. The short link would replace it.', 'fast-url-shortener' ) );
		}

		return null;
	}

	/**
	 * Generate an unused slug, growing longer if the space gets crowded.
	 *
	 * @return string
	 */
	protected function unique_slug() {
		$length = max( 3, (int) Settings::get( 'slug_length' ) );

		for ( $attempt = 0; ; $attempt++ ) {
			$slug = Slug::generate( $length + intdiv( $attempt, 5 ) );

			if ( ! Slug::is_reserved( $slug ) && ! $this->slug_exists( $slug ) ) {
				return $slug;
			}
		}
	}

	/**
	 * Remove a slug from the object cache.
	 *
	 * @param string $slug Slug.
	 *
	 * @return void
	 */
	public function flush_cache( $slug ) {
		wp_cache_delete( $this->cache_key( $slug ), self::CACHE_GROUP );
	}

	/**
	 * Cache key for a slug.
	 *
	 * @param string $slug Slug.
	 *
	 * @return string
	 */
	protected function cache_key( $slug ) {
		return 'slug_' . $slug;
	}
}
