import { Button, Modal, Notice, Slot, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getStats } from '../api';
import { SLOTS } from '../extensions';
import { countryName, formatNumber } from '../utils';
import BarChart from './BarChart';

const PERIODS = [ 7, 30, 90, 365 ];

const BREAKDOWNS = [
	{
		key: 'referrer_host',
		title: __( 'Referrers', 'clicklet-url-shortener' ),
	},
	{ key: 'country', title: __( 'Countries', 'clicklet-url-shortener' ) },
	{ key: 'device', title: __( 'Devices', 'clicklet-url-shortener' ) },
	{ key: 'browser', title: __( 'Browsers', 'clicklet-url-shortener' ) },
	{ key: 'os', title: __( 'Operating systems', 'clicklet-url-shortener' ) },
	{ key: 'source', title: __( 'Sources', 'clicklet-url-shortener' ) },
];

/**
 * Ranked list with a proportional bar per row.
 *
 * @param {Object} props
 * @param {string} props.title     Heading.
 * @param {Array}  props.rows      Rows with label and clicks.
 * @param {string} props.dimension Dimension key.
 */
function Breakdown( { title, rows, dimension } ) {
	const max = Math.max( 1, ...rows.map( ( row ) => row.clicks ) );

	return (
		<div className="clicklet-breakdown">
			<h3>{ title }</h3>
			{ ! rows.length && (
				<p className="clicklet-muted">
					{ __( 'No data yet.', 'clicklet-url-shortener' ) }
				</p>
			) }
			<ul>
				{ rows.map( ( row ) => (
					<li key={ row.value }>
						<span
							className="clicklet-breakdown-bar"
							style={ {
								width: `${ ( row.clicks / max ) * 100 }%`,
							} }
						/>
						<span className="clicklet-breakdown-label">
							{ 'country' === dimension && row.value
								? countryName( row.value )
								: row.label }
						</span>
						<span className="clicklet-breakdown-value">
							{ formatNumber( row.clicks ) }
						</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * Click statistics of a link.
 *
 * @param {Object}     props
 * @param {Object}     props.link    Link.
 * @param {() => void} props.onClose Close handler.
 */
export default function StatsModal( { link, onClose } ) {
	const [ days, setDays ] = useState( 30 );
	const [ stats, setStats ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		setStats( null );
		getStats( link.id, days )
			.then( setStats )
			.catch( ( e ) => setError( e.message ) );
	}, [ link.id, days ] );

	const totals = ( stats?.daily || [] ).reduce(
		( sum, day ) => ( {
			clicks: sum.clicks + day.clicks,
			uniques: sum.uniques + day.uniques,
			bots: sum.bots + day.bots,
		} ),
		{ clicks: 0, uniques: 0, bots: 0 }
	);

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: short URL. */
				__( 'Stats for %s', 'clicklet-url-shortener' ),
				link.short_url
			) }
			onRequestClose={ onClose }
			className="clicklet-modal clicklet-stats"
			size="large"
		>
			<div className="clicklet-stats-toolbar">
				<div
					className="clicklet-periods"
					role="group"
					aria-label={ __( 'Period', 'clicklet-url-shortener' ) }
				>
					{ PERIODS.map( ( period ) => (
						<Button
							key={ period }
							variant={
								period === days ? 'primary' : 'secondary'
							}
							isPressed={ period === days }
							size="compact"
							onClick={ () => setDays( period ) }
						>
							{ sprintf(
								/* translators: %d: number of days. */
								__( '%d days', 'clicklet-url-shortener' ),
								period
							) }
						</Button>
					) ) }
				</div>
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! stats && ! error && <Spinner /> }

			{ stats && (
				<>
					<div className="clicklet-totals">
						<div>
							<strong>{ formatNumber( totals.clicks ) }</strong>
							<span>
								{ __( 'Clicks', 'clicklet-url-shortener' ) }
							</span>
						</div>
						<div>
							<strong>{ formatNumber( totals.uniques ) }</strong>
							<span>
								{ __(
									'Unique visitors',
									'clicklet-url-shortener'
								) }
							</span>
						</div>
						<div>
							<strong>{ formatNumber( totals.bots ) }</strong>
							<span>
								{ __(
									'Bots filtered out',
									'clicklet-url-shortener'
								) }
							</span>
						</div>
					</div>

					<BarChart data={ stats.daily } />

					<div className="clicklet-breakdowns">
						{ BREAKDOWNS.map( ( { key, title } ) => (
							<Breakdown
								key={ key }
								title={ title }
								dimension={ key }
								rows={ stats.breakdowns[ key ] || [] }
							/>
						) ) }
					</div>

					<Slot
						name={ SLOTS.stats }
						fillProps={ { link, days, stats } }
					/>
				</>
			) }
		</Modal>
	);
}
