<?php
/**
 * Link groups.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Links;

use FastUrlShortener\Rest\Controller;
use FastUrlShortener\Schema;
use WP_Error;

/**
 * Groups are a private taxonomy on the link object type: a link can be in any
 * number of groups, so they work as tags too. Term relationships and counts
 * come from WordPress core.
 */
class Groups {

	/**
	 * Taxonomy name.
	 */
	const TAXONOMY = 'fast_url_shortener_group';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', [ self::class, 'register_taxonomy' ] );
	}

	/**
	 * Register the taxonomy. Not exposed publicly or through the core REST
	 * API; see Rest\GroupsController.
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		$capability = Controller::capability( 'links' );

		register_taxonomy(
			self::TAXONOMY,
			Schema::META_TYPE,
			[
				'labels'                => [
					'name'          => __( 'Groups', 'fast-url-shortener' ),
					'singular_name' => __( 'Group', 'fast-url-shortener' ),
				],
				'public'                => false,
				'show_ui'               => false,
				'show_in_rest'          => false,
				'hierarchical'          => false,
				'rewrite'               => false,
				'query_var'             => false,
				'capabilities'          => [
					'manage_terms' => $capability,
					'edit_terms'   => $capability,
					'delete_terms' => $capability,
					'assign_terms' => $capability,
				],
				// Counts relationships of any object type; the default counts posts only.
				'update_count_callback' => '_update_generic_term_count',
			]
		);
	}

	/**
	 * Replace the groups of a link.
	 *
	 * @param int   $link_id Link ID.
	 * @param array $groups  Group names (created when missing) or term IDs.
	 *
	 * @return true|WP_Error
	 */
	public static function set( $link_id, array $groups ) {
		$terms = [];

		foreach ( $groups as $group ) {
			if ( is_int( $group ) || ctype_digit( (string) $group ) ) {
				if ( term_exists( (int) $group, self::TAXONOMY ) ) {
					$terms[] = (int) $group;
				}
			} else {
				$name = trim( sanitize_text_field( (string) $group ) );

				if ( '' !== $name ) {
					$terms[] = $name;
				}
			}
		}

		$result = wp_set_object_terms( $link_id, array_values( array_unique( $terms, SORT_REGULAR ) ), self::TAXONOMY );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Groups of a link, sorted by name.
	 *
	 * @param int $link_id Link ID.
	 *
	 * @return array[] { id, name, slug }
	 */
	public static function of( $link_id ) {
		$terms = get_object_term_cache( $link_id, self::TAXONOMY );

		if ( false === $terms ) {
			self::prime( [ $link_id ] );
			$terms = get_object_term_cache( $link_id, self::TAXONOMY );
		}

		$groups = array_map(
			static function ( $term ) {
				return [
					'id'   => (int) $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				];
			},
			is_array( $terms ) ? $terms : []
		);

		usort(
			$groups,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $groups;
	}

	/**
	 * Load the groups of many links with one query.
	 *
	 * @param int[] $link_ids Link IDs.
	 *
	 * @return void
	 */
	public static function prime( array $link_ids ) {
		if ( $link_ids ) {
			update_object_term_cache( $link_ids, Schema::META_TYPE );
		}
	}

	/**
	 * Remove a link from all groups.
	 *
	 * @param int $link_id Link ID.
	 *
	 * @return void
	 */
	public static function remove( $link_id ) {
		wp_delete_object_term_relationships( $link_id, self::TAXONOMY );
	}

	/**
	 * All groups with their link counts, sorted by name.
	 *
	 * @return array[] { id, name, slug, count }
	 */
	public static function all() {
		$terms = get_terms(
			[
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			]
		);

		return is_wp_error( $terms ) ? [] : array_map(
			static function ( $term ) {
				return [
					'id'    => (int) $term->term_id,
					'name'  => $term->name,
					'slug'  => $term->slug,
					'count' => (int) $term->count,
				];
			},
			$terms
		);
	}

	/**
	 * Term taxonomy ID of a group, for filtering queries.
	 *
	 * @param int|string $group Term ID, slug or name.
	 *
	 * @return int 0 when the group does not exist.
	 */
	public static function term_taxonomy_id( $group ) {
		if ( is_int( $group ) || ctype_digit( (string) $group ) ) {
			$term = get_term( (int) $group, self::TAXONOMY );
		} else {
			$term = get_term_by( 'slug', sanitize_title( $group ), self::TAXONOMY );
			$term = $term ? $term : get_term_by( 'name', $group, self::TAXONOMY );
		}

		return $term && ! is_wp_error( $term ) ? (int) $term->term_taxonomy_id : 0;
	}
}
