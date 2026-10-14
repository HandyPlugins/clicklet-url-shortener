const config = window.fastUrlShortener || {};

/**
 * Admin URL of one of the plugin screens.
 *
 * @param {string} suffix Screen suffix, e.g. "import". Empty for the links screen.
 * @return {string} URL.
 */
export function screenUrl( suffix = '' ) {
	const page = suffix ? `${ config.pageSlug }-${ suffix }` : config.pageSlug;

	return `${ config.adminUrl }?page=${ page }`;
}

/**
 * Base of short URLs, e.g. "https://example.com/go/".
 *
 * @param {string} prefix Link prefix.
 * @return {string} URL.
 */
export function shortUrlBase( prefix = config.prefix ) {
	return config.homeUrl + ( prefix ? `${ prefix }/` : '' );
}

/**
 * Format a UTC MySQL datetime for display in the browser's locale.
 *
 * @param {string|null} value "YYYY-MM-DD HH:MM:SS" in UTC.
 * @return {string} Formatted date.
 */
export function formatDate( value ) {
	if ( ! value ) {
		return '—';
	}

	return new Date( value.replace( ' ', 'T' ) + 'Z' ).toLocaleDateString(
		undefined,
		{
			year: 'numeric',
			month: 'short',
			day: 'numeric',
			timeZone: config.timezone || undefined,
		}
	);
}

/**
 * Format a number with the browser's locale.
 *
 * @param {number} value Number.
 * @return {string} Formatted number.
 */
export const formatNumber = ( value ) => Number( value || 0 ).toLocaleString();

/**
 * Country name for an ISO code, falling back to the code.
 *
 * @param {string} code ISO 3166-1 alpha-2 code.
 * @return {string} Name.
 */
export function countryName( code ) {
	try {
		return (
			new Intl.DisplayNames( undefined, { type: 'region' } ).of( code ) ||
			code
		);
	} catch {
		return code;
	}
}
