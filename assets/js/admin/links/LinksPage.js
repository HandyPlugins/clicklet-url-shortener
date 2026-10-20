import {
	Button,
	Modal,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useDebounce } from '@wordpress/compose';
import {
	lazy,
	Suspense,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { deleteLink, getGroups, getLinks } from '../api';
import CopyButton from '../components/CopyButton';
import { linkColumns, rowActions } from '../extensions';
import { formatDate, formatNumber, screenUrl } from '../utils';
import GroupsManager from './GroupsManager';
import LinkForm from './LinkForm';
import QuickCreate from './QuickCreate';
import StatsModal from './StatsModal';

const PER_PAGE = 20;

// The QR library is only loaded when a QR code is opened.
const QrModal = lazy( () => import( './QrModal' ) );

/**
 * Sortable column header.
 *
 * @param {Object}                  props
 * @param {string}                  props.field     Field name.
 * @param {string}                  props.label     Label.
 * @param {Object}                  props.query     Current query.
 * @param {(field: string) => void} props.onSort    Called with the field.
 * @param {string}                  props.className Extra class.
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
				className="clicklet-sort-button"
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
	// ?s= and ?group= open the list filtered, e.g. from the block editor panel.
	const params = new window.URLSearchParams( window.location.search );
	const [ query, setQuery ] = useState( {
		search: params.get( 's' ) || '',
		group: Number( params.get( 'group' ) ) || 0,
		orderby: 'id',
		order: 'desc',
		page: 1,
	} );
	const [ groups, setGroups ] = useState( [] );
	const [ managingGroups, setManagingGroups ] = useState( false );

	const loadGroups = useCallback( () => {
		getGroups()
			.then( setGroups )
			.catch( () => {} );
	}, [] );

	useEffect( loadGroups, [ loadGroups ] );
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
	const [ viewingQr, setViewingQr ] = useState( null );

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
	const [ searchInput, setSearchInput ] = useState( query.search );

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
		loadGroups();
	};

	const filterByGroup = ( group ) =>
		setQuery( ( q ) => ( { ...q, group: Number( group ), page: 1 } ) );

	const isEmpty =
		! loading && ! result.total && ! query.search && ! query.group;
	const extraColumns = linkColumns();
	const colSpan = 5 + extraColumns.length;

	return (
		<>
			<h1 className="wp-heading-inline">
				{ __( 'Short Links', 'clicklet-url-shortener' ) }
			</h1>
			<Button
				variant="secondary"
				className="page-title-action"
				onClick={ () => setEditing( {} ) }
			>
				{ __( 'Add New Link', 'clicklet-url-shortener' ) }
			</Button>
			<hr className="wp-header-end" />

			<QuickCreate onCreated={ load } />

			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ isEmpty ? (
				<div className="clicklet-empty">
					<p>
						{ __(
							'No short links yet. Paste a URL above to create your first one.',
							'clicklet-url-shortener'
						) }
					</p>
					<p>
						<a href={ screenUrl( 'import' ) }>
							{ __(
								'Switching from another plugin? Import your links and their click history.',
								'clicklet-url-shortener'
							) }
						</a>
					</p>
				</div>
			) : (
				<>
					<div className="clicklet-toolbar">
						<span className="displaying-num">
							{ sprintf(
								/* translators: %s: number of links. */
								_n(
									'%s link',
									'%s links',
									result.total,
									'clicklet-url-shortener'
								),
								formatNumber( result.total )
							) }
						</span>
						<div className="clicklet-toolbar-filters">
							{ groups.length > 0 && (
								<SelectControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Filter by group',
										'clicklet-url-shortener'
									) }
									hideLabelFromVision
									value={ query.group }
									options={ [
										{
											value: 0,
											label: __(
												'All groups',
												'clicklet-url-shortener'
											),
										},
										...groups.map( ( group ) => ( {
											value: group.id,
											label: `${ group.name } (${ formatNumber(
												group.count
											) })`,
										} ) ),
									] }
									onChange={ filterByGroup }
								/>
							) }
							<Button
								variant="tertiary"
								onClick={ () => setManagingGroups( true ) }
							>
								{ __(
									'Manage groups',
									'clicklet-url-shortener'
								) }
							</Button>
						</div>
						<SearchControl
							__nextHasNoMarginBottom
							value={ searchInput }
							onChange={ ( value ) => {
								setSearchInput( value );
								search( value );
							} }
							placeholder={ __(
								'Search links',
								'clicklet-url-shortener'
							) }
						/>
					</div>

					<table className="wp-list-table widefat fixed striped table-view-list clicklet-links">
						<thead>
							<tr>
								<SortableHeader
									field="slug"
									label={ __(
										'Short link',
										'clicklet-url-shortener'
									) }
									query={ query }
									onSort={ sort }
									className="column-primary"
								/>
								<th scope="col" className="column-target">
									{ __(
										'Destination',
										'clicklet-url-shortener'
									) }
								</th>
								{ extraColumns.map( ( column ) => (
									<th
										key={ column.id }
										scope="col"
										className={ `column-${ column.id }` }
									>
										{ column.label }
									</th>
								) ) }
								<SortableHeader
									field="total_clicks"
									label={ __(
										'Clicks',
										'clicklet-url-shortener'
									) }
									query={ query }
									onSort={ sort }
									className="column-clicks"
								/>
								<th scope="col" className="column-status">
									{ __( 'Status', 'clicklet-url-shortener' ) }
								</th>
								<SortableHeader
									field="created_at"
									label={ __(
										'Created',
										'clicklet-url-shortener'
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
									<td colSpan={ colSpan }>
										<Spinner />
									</td>
								</tr>
							) }
							{ ! loading && ! result.items.length && (
								<tr>
									<td colSpan={ colSpan }>
										{ __(
											'No links match your search.',
											'clicklet-url-shortener'
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
													className="clicklet-link-title"
													onClick={ () =>
														setEditing( link )
													}
												>
													{ link.title ||
														`/${ link.slug }` }
												</button>
											</strong>
											<div className="clicklet-short-url">
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
											{ link.groups?.length > 0 && (
												<div className="clicklet-badges">
													{ link.groups.map(
														( group ) => (
															<button
																key={ group.id }
																type="button"
																className="clicklet-badge"
																onClick={ () =>
																	filterByGroup(
																		group.id
																	)
																}
															>
																{ group.name }
															</button>
														)
													) }
												</div>
											) }
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
															'clicklet-url-shortener'
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
															'clicklet-url-shortener'
														) }
													</Button>{ ' ' }
													|{ ' ' }
												</span>
												<span>
													<Button
														variant="link"
														onClick={ () =>
															setViewingQr( link )
														}
													>
														{ __(
															'QR code',
															'clicklet-url-shortener'
														) }
													</Button>{ ' ' }
													|{ ' ' }
												</span>
												{ rowActions( link ).map(
													( action ) => (
														<span key={ action.id }>
															<Button
																variant="link"
																onClick={ () =>
																	action.onClick(
																		link
																	)
																}
															>
																{ action.label }
															</Button>{ ' ' }
															|{ ' ' }
														</span>
													)
												) }
												<span className="trash">
													<Button
														variant="link"
														isDestructive
														onClick={ () =>
															setDeleting( link )
														}
													>
														{ __(
															'Delete',
															'clicklet-url-shortener'
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
												className="clicklet-target"
												title={ link.target_url }
											>
												{ link.target_url }
											</a>
										</td>
										{ extraColumns.map( ( column ) => (
											<td
												key={ column.id }
												className={ `column-${ column.id }` }
											>
												{ column.render( link ) }
											</td>
										) ) }
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
												className={ `clicklet-status clicklet-status-${ link.status }` }
											>
												{ 'active' === link.status
													? __(
															'Active',
															'clicklet-url-shortener'
														)
													: __(
															'Disabled',
															'clicklet-url-shortener'
														) }
											</span>
											{ link.has_password && (
												<span className="clicklet-status clicklet-status-locked">
													{ __(
														'Password',
														'clicklet-url-shortener'
													) }
												</span>
											) }
										</td>
										<td className="column-date">
											{ formatDate( link.created_at ) }
										</td>
									</tr>
								) ) }
						</tbody>
					</table>

					{ result.totalPages > 1 && (
						<div className="clicklet-pagination">
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
								{ __( 'Previous', 'clicklet-url-shortener' ) }
							</Button>
							<span>
								{ sprintf(
									/* translators: 1: current page, 2: total pages. */
									__(
										'Page %1$d of %2$d',
										'clicklet-url-shortener'
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
								{ __( 'Next', 'clicklet-url-shortener' ) }
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
					title={ __( 'Delete link?', 'clicklet-url-shortener' ) }
					onRequestClose={ () => setDeleting( null ) }
					size="small"
				>
					<p>
						{ sprintf(
							/* translators: %s: short URL. */
							__(
								'%s will stop working and its click data will be deleted. This cannot be undone.',
								'clicklet-url-shortener'
							),
							deleting.short_url
						) }
					</p>
					<div className="clicklet-form-actions">
						<Button
							variant="tertiary"
							onClick={ () => setDeleting( null ) }
						>
							{ __( 'Cancel', 'clicklet-url-shortener' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ () => remove( deleting ) }
						>
							{ __( 'Delete', 'clicklet-url-shortener' ) }
						</Button>
					</div>
				</Modal>
			) }
			{ viewingQr && (
				<Suspense fallback={ <Spinner /> }>
					<QrModal
						link={ viewingQr }
						onClose={ () => setViewingQr( null ) }
					/>
				</Suspense>
			) }
			{ managingGroups && (
				<GroupsManager
					groups={ groups }
					onChange={ () => {
						loadGroups();
						load();
					} }
					onClose={ () => setManagingGroups( false ) }
				/>
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
