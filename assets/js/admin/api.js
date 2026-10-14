import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const BASE = '/fast-url-shortener/v1';

/**
 * Fetch a page of links.
 *
 * @param {Object} query Query args: search, orderby, order, page, per_page.
 * @return {Promise<{items: Array, total: number, totalPages: number}>} Links and pagination.
 */
export async function getLinks( query ) {
	const response = await apiFetch( {
		path: addQueryArgs( `${ BASE }/links`, query ),
		parse: false,
	} );

	return {
		items: await response.json(),
		total: Number( response.headers.get( 'X-WP-Total' ) || 0 ),
		totalPages: Number( response.headers.get( 'X-WP-TotalPages' ) || 1 ),
	};
}

export const createLink = ( data ) =>
	apiFetch( { path: `${ BASE }/links`, method: 'POST', data } );

export const updateLink = ( id, data ) =>
	apiFetch( { path: `${ BASE }/links/${ id }`, method: 'PUT', data } );

export const deleteLink = ( id ) =>
	apiFetch( { path: `${ BASE }/links/${ id }`, method: 'DELETE' } );

export const getStats = ( id, days ) =>
	apiFetch( {
		path: addQueryArgs( `${ BASE }/links/${ id }/stats`, { days } ),
	} );

export const getSettings = () => apiFetch( { path: `${ BASE }/settings` } );

export const saveSettings = ( data ) =>
	apiFetch( { path: `${ BASE }/settings`, method: 'POST', data } );

export const getImportSources = () => apiFetch( { path: `${ BASE }/import` } );

export const startImport = ( source ) =>
	apiFetch( { path: `${ BASE }/import/${ source }/start`, method: 'POST' } );

export const importStep = ( source ) =>
	apiFetch( { path: `${ BASE }/import/${ source }/step`, method: 'POST' } );
