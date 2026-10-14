<?php
/**
 * Slug helpers.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener\Links;

use FastUrlShortener\Settings;

/**
 * Slug normalization, validation and generation.
 */
class Slug {

	/**
	 * Allowed slug format. One path segment, ASCII only.
	 */
	const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/';

	/**
	 * Characters for generated slugs. Look-alikes (0/o, 1/l/i) are left out.
	 */
	const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

	/**
	 * Root paths WordPress or common tooling already uses.
	 */
	const RESERVED = [
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-json',
		'wp-login',
		'wp-signup',
		'wp-activate',
		'wp-cron',
		'xmlrpc',
		'feed',
		'comments',
		'page',
		'search',
		'author',
		'embed',
		'trackback',
		'sitemap',
		'robots',
		'favicon',
	];

	/**
	 * Normalize a slug for storage and lookup.
	 *
	 * @param string $slug Raw slug.
	 *
	 * @return string
	 */
	public static function normalize( $slug ) {
		$slug = trim( (string) $slug, " \t\n\r\0\x0B/" );

		return Settings::get( 'case_sensitive' ) ? $slug : strtolower( $slug );
	}

	/**
	 * Whether the slug has a valid format.
	 *
	 * @param string $slug Normalized slug.
	 *
	 * @return bool
	 */
	public static function is_valid( $slug ) {
		return (bool) preg_match( self::PATTERN, $slug );
	}

	/**
	 * Whether the slug would shadow a WordPress path.
	 *
	 * Only applies when links live at the site root.
	 *
	 * @param string $slug Normalized slug.
	 *
	 * @return bool
	 */
	public static function is_reserved( $slug ) {
		if ( '' !== Settings::get( 'prefix' ) ) {
			return false;
		}

		return in_array( strtolower( $slug ), self::RESERVED, true );
	}

	/**
	 * Find published content the slug would hide.
	 *
	 * Short links resolve before WordPress routing, so a link at the site root
	 * named like a page takes that page's URL.
	 *
	 * @param string $slug Normalized slug.
	 *
	 * @return \WP_Post|null
	 */
	public static function conflicting_post( $slug ) {
		if ( '' !== Settings::get( 'prefix' ) ) {
			return null;
		}

		return get_page_by_path( $slug, OBJECT, get_post_types( [ 'public' => true ] ) );
	}

	/**
	 * Random slug.
	 *
	 * @param int $length Length.
	 *
	 * @return string
	 */
	public static function generate( $length ) {
		$alphabet = self::ALPHABET;

		if ( Settings::get( 'case_sensitive' ) ) {
			$alphabet .= 'ABCDEFGHJKLMNPQRSTUVWXYZ';
		}

		$max  = strlen( $alphabet ) - 1;
		$slug = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$slug .= $alphabet[ random_int( 0, $max ) ];
		}

		return $slug;
	}
}
