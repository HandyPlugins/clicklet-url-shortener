<?php
/**
 * Plugin settings.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener;

/**
 * Typed access to the single, autoloaded settings option.
 */
class Settings {

	/**
	 * Option name.
	 */
	const OPTION = 'fast_url_shortener_settings';

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return [
			// Path segment short links live under, e.g. "go" for /go/abc. Empty means the site root.
			'prefix'                   => '',
			'redirect_type'            => 302,
			'case_sensitive'           => false,
			'slug_length'              => 5,
			'track_clicks'             => true,
			// $_SERVER key holding the visitor IP behind a trusted proxy, e.g. HTTP_CF_CONNECTING_IP.
			'ip_header'                => '',
			// Days to keep raw click rows. Daily totals are always kept. 0 keeps everything.
			'retention_days'           => 0,
			// Post types whose published posts get a short link automatically.
			'auto_post_types'          => [],
			'fast_path'                => false,
			'delete_data_on_uninstall' => false,
		];
	}

	/**
	 * All settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, [] );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Setting key.
	 *
	 * @return mixed
	 */
	public static function get( $key ) {
		$settings = self::all();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Update settings. Unknown keys are ignored.
	 *
	 * @param array $values Key/value pairs.
	 *
	 * @return bool
	 */
	public static function update( array $values ) {
		$settings = array_merge( self::all(), array_intersect_key( $values, self::defaults() ) );

		return update_option( self::OPTION, $settings, true );
	}
}
