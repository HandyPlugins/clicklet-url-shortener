/**
 * Extension points for add-ons.
 *
 * Add-ons render into the slots with `registerPlugin( name, { scope:
 * 'fast-url-shortener', render } )` and `<Fill name={ … }>`, and extend the
 * links table with `addFilter()` from `@wordpress/hooks`. Fields a Fill sets
 * with `setValue()` are sent with the link or settings request; save them on
 * the `fast_url_shortener_rest_save_link` or
 * `fast_url_shortener_rest_update_settings` PHP actions.
 */
import { applyFilters } from '@wordpress/hooks';

export const PLUGIN_SCOPE = 'fast-url-shortener';

/**
 * Slot names. Fill props:
 * - linkForm: { link, values, setValue( key, value ) }
 * - settings: { values, setValue( key, value ) }
 * - stats:    { link, days, stats }
 * - app:      { view } (rendered on every screen, e.g. for notices)
 */
export const SLOTS = {
	linkForm: 'FastUrlShortenerLinkForm',
	settings: 'FastUrlShortenerSettings',
	stats: 'FastUrlShortenerStats',
	app: 'FastUrlShortenerApp',
};

/**
 * Extra columns for the links table.
 *
 * Filter `fastUrlShortener.linkColumns`: an array of
 * { id, label, render( link ) }.
 *
 * @return {Array} Columns.
 */
export const linkColumns = () =>
	applyFilters( 'fastUrlShortener.linkColumns', [] );

/**
 * Extra row actions for a link.
 *
 * Filter `fastUrlShortener.rowActions`: an array of
 * { id, label, onClick( link ) }.
 *
 * @param {Object} link Link.
 * @return {Array} Actions.
 */
export const rowActions = ( link ) =>
	applyFilters( 'fastUrlShortener.rowActions', [], link );
