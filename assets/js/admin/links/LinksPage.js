import {
	Button,
	Modal,
	Notice,
	SearchControl,
	Spinner,
} from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { deleteLink, getLinks } from '../api';
import CopyButton from '../components/CopyButton';
import { formatDate, formatNumber, screenUrl } from '../utils';
import LinkForm from './LinkForm';
import QuickCreate from './QuickCreate';
import StatsModal from './StatsModal';

const PER_PAGE = 20;

/**
 * Sortable column header.
 *
 * @param {Object}   props
 * @param {string}   props.field     Field name.
 * @param {string}   props.label     Label.
 * @param {Object}   props.query     Current query.
 * @param {Function} props.onSort    Called with the field.
 * @param {string}   props.className Extra class.
 */
function SortableHeader( { field, label, query, onSort, className = '' } ) {
	const active = query.orderby === field;
	const order = active ? query.order : 'desc';

	return (
		<th
			scope="col"
			className={ `manage-column sortable ${ active ? 'sorted' : '' } ${ order } ${ className }` }
			aria-sort={ active ? `${ order }ending` : undefined }
		>
			<button
				type="button"
				className="fus-sort-button"
				onClick={ () => onSort( field ) }
			>
				<span>{ label }</span>
				<span className="sorting-indicators">
					<span
						className="sorting-indicator asc"
						aria-hidden="true"
					/>
					<span
						className="sorting-indicator desc"
						aria-hidden="true"
					/>
				</span>
			</button>
		</th>
	);
}

/**
 * Links screen: quick create, searchable list, edit and stats.
 */
export default function LinksPage() {
	const [ query, setQuery ] = useState( {
		search: '',
		orderby: 'id',
		order: 'desc',
		page: 1,
	} );
	const [ result, setResult ] = useState( {
		items: [],
		total: 0,
		totalPages: 1,
	} );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ editing, setEditing ] = useState( null );
	const [ viewingStats, setViewingStats ] = useState( null );
	const [ deleting, setDeleting ] = useState( null );

	const load = useCallback( () => {
		setLoading( true );
		getLinks( { ...query, per_page: PER_PAGE } )
			.then( setResult )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setLoading( false ) );
	}, [ query ] );

	useEffect( load, [ load ] );

	// The callback must be stable: useDebounce cancels pending calls whenever it changes.
	const applySearch = useCallback(
		( value ) => setQuery( ( q ) => ( { ...q, search: value, page: 1 } ) ),
		[]
	);
	const search = useDebounce( applySearch, 300 );
	const [ searchInput, setSearchInput ] = useState( '' );

	const sort = ( field ) =>
		setQuery( ( q ) => ( {
			...q,
			orderby: field,
			order: q.orderby === field && q.order === 'desc' ? 'asc' : 'desc',
			page: 1,
		} ) );

	const remove = async ( link ) => {
		setDeleting( null );

		try {
			await deleteLink( link.id );
			load();
		} catch ( e ) {
			setError( e.message );
		}
	};

	const onSaved = () => {
		setEditing( null );
		load();
	};

	const isEmpty = ! loading && ! result.total && ! query.search;

	return (
		<>
			<h1 className="wp-heading-inline">
				{ __( 'Short Links', 'fast-url-shortener' ) }
			</h1>
			<Button
				variant="secondary"
				className="page-title-action"
				onClick={ () => setEditing( {} ) }
			>
				{ __( 'Add New Link', 'fast-url-shortener' ) }
			</Button>
			<hr className="wp-header-end" />

			<QuickCreate onCreated={ load } />

			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ isEmpty ? (
				<div className="fus-empty">
					<p>
						{ __(
							'No short links yet. Paste a URL above to create your first one.',
							'fast-url-shortener'
						) }
					</p>
					<p>
						<a href={ screenUrl( 'import' ) }>
							{ __(
								'Switching from another plugin? Import your links and their click history.',
								'fast-url-shortener'
							) }
						</a>
					</p>
				</div>
			) : (
				<>
					<div className="fus-toolbar">
						<span className="displaying-num">
							{ sprintf(
								/* translators: %s: number of links. */
								_n(
									'%s link',
									'%s links',
									result.total,
									'fast-url-shortener'
								),
								formatNumber( result.total )
							) }
						</span>
						<SearchControl
							__nextHasNoMarginBottom
							value={ searchInput }
							onChange={ ( value ) => {
								setSearchInput( value );
								search( value );
							} }
							placeholder={ __(
								'Search links',
								'fast-url-shortener'
							) }
						/>
					</div>

					<table className="wp-list-table widefat fixed striped table-view-list fus-links">
						<thead>
							<tr>
								<SortableHeader
									field="slug"
									label={ __(
										'Short link',
										'fast-url-shortener'
									) }
									query={ query }
									onSort={ sort }
									className="column-primary"
								/>
								<th scope="col" className="column-target">
									{ __(
										'Destination',
										'fast-url-shortener'
									) }
								</th>
								<SortableHeader
									field="total_clicks"
									label={ __(
										'Clicks',
										'fast-url-shortener'
									) }
									query={ query }
									onSort={ sort }
									className="column-clicks"
								/>
								<th scope="col" className="column-status">
									{ __( 'Status', 'fast-url-shortener' ) }
								</th>
								<SortableHeader
									field="created_at"
									label={ __(
										'Created',
										'fast-url-shortener'
									) }
									query={ query }
									onSort={ sort }
									className="column-date"
								/>
							</tr>
						</thead>
						<tbody>
							{ loading && (
								<tr>
									<td colSpan="5">
										<Spinner />
									</td>
								</tr>
							) }
							{ ! loading && ! result.items.length && (
								<tr>
									<td colSpan="5">
										{ __(
											'No links match your search.',
											'fast-url-shortener'
										) }
									</td>
								</tr>
							) }
							{ ! loading &&
								result.items.map( ( link ) => (
									<tr key={ link.id }>
										<td className="column-primary">
											<strong>
												<button
													type="button"
													className="fus-link-title"
													onClick={ () =>
														setEditing( link )
													}
												>
													{ link.title ||
														`/${ link.slug }` }
												</button>
											</strong>
											<div className="fus-short-url">
												<a
													href={ link.short_url }
													target="_blank"
													rel="noreferrer"
												>
													{ link.short_url }
												</a>{ ' ' }
												<CopyButton
													text={ link.short_url }
												/>
											</div>
											<div className="row-actions visible">
												<span className="edit">
													<Button
														variant="link"
														onClick={ () =>
															setEditing( link )
														}
													>
														{ __(
															'Edit',
															'fast-url-shortener'
														) }
													</Button>{ ' ' }
													|{ ' ' }
												</span>
												<span>
													<Button
														variant="link"
														onClick={ () =>
															setViewingStats(
																link
															)
														}
													>
														{ __(
															'Stats',
															'fast-url-shortener'
														) }
													</Button>{ ' ' }
													|{ ' ' }
												</span>
												<span className="trash">
													<Button
														variant="link"
														isDestructive
														onClick={ () =>
															remove( link )
														}
													>
														{ __(
															'Delete',
															'fast-url-shortener'
														) }
													</Button>
												</span>
											</div>
										</td>
										<td className="column-target">
											<a
												href={ link.target_url }
												target="_blank"
												rel="noreferrer"
												className="fus-target"
												title={ link.target_url }
											>
												{ link.target_url }
											</a>
										</td>
										<td className="column-clicks">
											<Button
												variant="link"
												onClick={ () =>
													setViewingStats( link )
												}
											>
												{ formatNumber(
													link.total_clicks
												) }
											</Button>
										</td>
										<td className="column-status">
											<span
												className={ `fus-status fus-status-${ link.status }` }
											>
												{ 'active' === link.status
													? __(
															'Active',
															'fast-url-shortener'
														)
													: __(
															'Disabled',
															'fast-url-shortener'
														) }
											</span>
										</td>
										<td className="column-date">
											{ formatDate( link.created_at ) }
										</td>
									</tr>
								) ) }
						</tbody>
					</table>

					{ result.totalPages > 1 && (
						<div className="fus-pagination">
							<Button
								variant="secondary"
								disabled={ query.page <= 1 }
								onClick={ () =>
									setQuery( ( q ) => ( {
										...q,
										page: q.page - 1,
									} ) )
								}
							>
								{ __( 'Previous', 'fast-url-shortener' ) }
							</Button>
							<span>
								{ sprintf(
									/* translators: 1: current page, 2: total pages. */
									__(
										'Page %1$d of %2$d',
										'fast-url-shortener'
									),
									query.page,
									result.totalPages
								) }
							</span>
							<Button
								variant="secondary"
								disabled={ query.page >= result.totalPages }
								onClick={ () =>
									setQuery( ( q ) => ( {
										...q,
										page: q.page + 1,
									} ) )
								}
							>
								{ __( 'Next', 'fast-url-shortener' ) }
							</Button>
						</div>
					) }
				</>
			) }

			{ editing && (
				<LinkForm
					link={ editing }
					onClose={ () => setEditing( null ) }
					onSaved={ onSaved }
				/>
			) }
			{ deleting && (
				<Modal
					title={ __( 'Delete link?', 'fast-url-shortener' ) }
					onRequestClose={ () => setDeleting( null ) }
					size="small"
				>
					<p>
						{ sprintf(
							/* translators: %s: short URL. */
							__(
								'%s will stop working and its click data will be deleted. This cannot be undone.',
								'fast-url-shortener'
							),
							deleting.short_url
						) }
					</p>
					<div className="fus-form-actions">
						<Button
							variant="tertiary"
							onClick={ () => setDeleting( null ) }
						>
							{ __( 'Cancel', 'fast-url-shortener' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ () => remove( deleting ) }
						>
							{ __( 'Delete', 'fast-url-shortener' ) }
						</Button>
					</div>
				</Modal>
			) }
			{ viewingStats && (
				<StatsModal
					link={ viewingStats }
					onClose={ () => setViewingStats( null ) }
				/>
			) }
		</>
	);
}
