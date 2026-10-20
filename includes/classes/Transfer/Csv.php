<?php
/**
 * CSV format.
 *
 * @package Clicklet
 */

namespace Clicklet\Transfer;

/**
 * The three CSV file types and shared reading/writing rules.
 *
 * Files are UTF-8 with a BOM (so Excel shows non-ASCII text correctly),
 * comma-separated, and dates are UTC. Rows reference links by slug, so files
 * can move between sites where link IDs differ.
 */
class Csv {

	/**
	 * Columns per file type, in export order.
	 */
	const COLUMNS = [
		'links'  => [ 'slug', 'target_url', 'title', 'groups', 'redirect_type', 'status', 'forward_params', 'track_clicks', 'expires_at_utc', 'max_clicks', 'password_hash', 'created_at_utc', 'total_clicks' ],
		'stats'  => [ 'slug', 'day', 'clicks', 'uniques', 'bots' ],
		'clicks' => [ 'slug', 'clicked_at_utc', 'visitor_hash', 'is_unique', 'country', 'device', 'os', 'browser', 'referrer_host', 'source' ],
	];

	/**
	 * Columns a file must have to be recognized as a type.
	 */
	const REQUIRED = [
		'links'  => [ 'target_url' ],
		'stats'  => [ 'slug', 'day', 'clicks' ],
		'clicks' => [ 'slug', 'clicked_at_utc', 'visitor_hash' ],
	];

	/**
	 * Separator between group names in the groups column.
	 */
	const GROUP_SEPARATOR = '|';

	/**
	 * UTF-8 byte order mark.
	 */
	const BOM = "\xEF\xBB\xBF";

	/**
	 * File type for a header row, or null.
	 *
	 * Checked from the most to the least specific, since a clicks file also
	 * has the columns of a stats file's first two.
	 *
	 * @param string[] $header Column names.
	 *
	 * @return string|null
	 */
	public static function detect_type( array $header ) {
		foreach ( [ 'clicks', 'stats', 'links' ] as $type ) {
			if ( ! array_diff( self::REQUIRED[ $type ], $header ) ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * Delimiter of a header line: semicolon when it appears more often than
	 * commas (Excel uses it in many locales), otherwise comma.
	 *
	 * @param string $line First line.
	 *
	 * @return string
	 */
	public static function detect_delimiter( $line ) {
		return substr_count( $line, ';' ) > substr_count( $line, ',' ) ? ';' : ',';
	}

	/**
	 * Normalize header cells: strip the BOM, trim and lowercase.
	 *
	 * @param array $header Raw header cells.
	 *
	 * @return string[]
	 */
	public static function normalize_header( array $header ) {
		if ( isset( $header[0] ) ) {
			$header[0] = (string) preg_replace( '/^' . self::BOM . '/', '', (string) $header[0] );
		}

		return array_map(
			static function ( $cell ) {
				return strtolower( trim( (string) $cell ) );
			},
			$header
		);
	}

	/**
	 * Protect a cell from formula injection in spreadsheet apps.
	 *
	 * @param mixed $value Cell value.
	 *
	 * @return string
	 */
	public static function escape( $value ) {
		$value = (string) $value;

		return '' !== $value && false !== strpos( '=+-@', $value[0] ) && ! is_numeric( $value ) ? "'" . $value : $value;
	}

	/**
	 * Undo escape().
	 *
	 * @param string $value Cell value.
	 *
	 * @return string
	 */
	public static function unescape( $value ) {
		$value = trim( (string) $value );

		return strlen( $value ) > 1 && "'" === $value[0] && false !== strpos( '=+-@', $value[1] ) ? substr( $value, 1 ) : $value;
	}

	/**
	 * Read a boolean cell. Empty cells use the default.
	 *
	 * @param string $value   Cell value.
	 * @param bool   $fallback Value for empty cells.
	 *
	 * @return bool
	 */
	public static function to_bool( $value, $fallback ) {
		$value = strtolower( trim( (string) $value ) );

		return '' === $value ? $fallback : in_array( $value, [ '1', 'true', 'yes', 'on' ], true );
	}
}
