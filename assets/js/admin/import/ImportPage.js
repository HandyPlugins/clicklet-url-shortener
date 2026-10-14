import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	getImportSources,
	importStep,
	saveSettings,
	startImport,
} from '../api';
import { formatNumber, screenUrl } from '../utils';

const STEP_LABELS = {
	links: __( 'Importing links…', 'fast-url-shortener' ),
	clicks: __( 'Importing click history…', 'fast-url-shortener' ),
	stats: __( 'Building statistics…', 'fast-url-shortener' ),
	done: __( 'Done', 'fast-url-shortener' ),
};

/**
 * Overall progress, 0–100. Links count for 20%, clicks for 70%, stats for 10%.
 *
 * @param {Object} progress Import progress.
 * @return {number} Percent.
 */
function percent( progress ) {
	if ( progress.done ) {
		return 100;
	}

	const links = progress.links_total
		? progress.links_processed / progress.links_total
		: 1;
	const clicks = progress.clicks_total
		? progress.clicks_processed / progress.clicks_total
		: 1;
	const stats = 'stats' === progress.step ? 0.5 : 0;

	return Math.min(
		99,
		Math.round(
			links * 20 +
				( 'links' === progress.step ? 0 : clicks * 70 ) +
				stats * 10
		)
	);
}

/**
 * One import source: summary, prefix hint, start button and progress.
 *
 * @param {Object}   props
 * @param {Object}   props.source         Source from the API.
 * @param {string}   props.prefix         Current prefix setting.
 * @param {Function} props.onPrefixChange Called after the prefix is changed.
 */
function SourceCard( { source, prefix, onPrefixChange } ) {
	const [ progress, setProgress ] = useState( source.progress );
	const [ running, setRunning ] = useState( false );
	const [ error, setError ] = useState( '' );

	const run = async ( restart ) => {
		setRunning( true );
		setError( '' );

		try {
			let current = restart ? await startImport( source.id ) : progress;
			setProgress( current );

			while ( ! current.done ) {
				current = await importStep( source.id );
				setProgress( current );
			}
		} catch ( e ) {
			setError( e.message );
		} finally {
			setRunning( false );
		}
	};

	const usePrefix = async () => {
		try {
			await saveSettings( { prefix: source.prefix } );
			onPrefixChange( source.prefix );
		} catch ( e ) {
			setError( e.message );
		}
	};

	const unfinished = progress && ! progress.done;

	return (
		<Card className="fus-import-card">
			<CardHeader>
				<h2>{ source.label }</h2>
				{ source.available && (
					<span className="fus-muted">
						{ sprintf(
							/* translators: 1: number of links, 2: number of clicks. */
							__(
								'%1$s links · %2$s clicks',
								'fast-url-shortener'
							),
							formatNumber( source.links ),
							formatNumber( source.clicks )
						) }
					</span>
				) }
			</CardHeader>
			<CardBody>
				{ ! source.available && (
					<p className="fus-muted">
						{ sprintf(
							/* translators: %s: plugin name. */
							__(
								'No %s data found on this site.',
								'fast-url-shortener'
							),
							source.label
						) }
					</p>
				) }

				{ source.available &&
					source.prefix &&
					source.prefix !== prefix && (
						<Notice status="warning" isDismissible={ false }>
							<p>
								{ sprintf(
									/* translators: 1: plugin name, 2: prefix. */
									__(
										'Most %1$s links start with /%2$s/. Use the same prefix so they keep their URLs; otherwise those links are skipped.',
										'fast-url-shortener'
									),
									source.label,
									source.prefix
								) }
							</p>
							<Button
								variant="secondary"
								size="compact"
								onClick={ usePrefix }
							>
								{ sprintf(
									/* translators: %s: prefix. */
									__(
										'Use /%s/ as prefix',
										'fast-url-shortener'
									),
									source.prefix
								) }
							</Button>
						</Notice>
					) }

				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }

				{ progress && (
					<div className="fus-import-progress">
						<div
							className="fus-progress"
							role="progressbar"
							aria-valuenow={ percent( progress ) }
							aria-valuemin="0"
							aria-valuemax="100"
						>
							<span
								style={ { width: `${ percent( progress ) }%` } }
							/>
						</div>
						<p>
							{ running && <Spinner /> }
							{ STEP_LABELS[ progress.step ] }{ ' ' }
							{ sprintf(
								/* translators: 1: imported links, 2: skipped links, 3: imported clicks. */
								__(
									'%1$s links imported, %2$s skipped, %3$s clicks imported.',
									'fast-url-shortener'
								),
								formatNumber( progress.links_imported ),
								formatNumber( progress.links_skipped ),
								formatNumber( progress.clicks_imported )
							) }
						</p>
					</div>
				) }

				{ progress?.done && (
					<Notice status="success" isDismissible={ false }>
						<p>
							{ sprintf(
								/* translators: %s: plugin name. */
								__(
									'Import complete. You can deactivate %s: imported links keep working through Fast URL Shortener.',
									'fast-url-shortener'
								),
								source.label
							) }{ ' ' }
							<a href={ screenUrl() }>
								{ __( 'View links', 'fast-url-shortener' ) }
							</a>
						</p>
					</Notice>
				) }

				{ progress?.skipped?.length > 0 && ! running && (
					<details className="fus-skipped">
						<summary>
							{ __( 'Skipped links', 'fast-url-shortener' ) }
						</summary>
						<table className="widefat striped">
							<thead>
								<tr>
									<th>
										{ __( 'Slug', 'fast-url-shortener' ) }
									</th>
									<th>
										{ __( 'Reason', 'fast-url-shortener' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ progress.skipped.map( ( item, index ) => (
									<tr key={ index }>
										<td>
											<code>{ item.slug }</code>
										</td>
										<td>{ item.reason }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</details>
				) }

				{ source.available && (
					<div className="fus-import-actions">
						{ unfinished && ! running && (
							<Button
								variant="primary"
								onClick={ () => run( false ) }
							>
								{ __(
									'Continue import',
									'fast-url-shortener'
								) }
							</Button>
						) }
						<Button
							variant={ unfinished ? 'secondary' : 'primary' }
							onClick={ () => run( true ) }
							disabled={ running }
							isBusy={ running }
						>
							{ progress
								? __( 'Import again', 'fast-url-shortener' )
								: __(
										'Import links and clicks',
										'fast-url-shortener'
									) }
						</Button>
						{ progress?.done && (
							<span className="fus-muted">
								{ __(
									'Links that already exist are skipped.',
									'fast-url-shortener'
								) }
							</span>
						) }
					</div>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * Import screen.
 */
export default function ImportPage() {
	const [ sources, setSources ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ prefix, setPrefix ] = useState(
		( window.fastUrlShortener || {} ).prefix || ''
	);

	useEffect( () => {
		getImportSources()
			.then( setSources )
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	return (
		<>
			<h1>{ __( 'Import Links', 'fast-url-shortener' ) }</h1>
			<p className="fus-intro">
				{ __(
					'Bring your short links and their click history from another plugin. Slugs stay the same, so links you already shared keep working. The other plugin is not changed.',
					'fast-url-shortener'
				) }
			</p>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! sources && ! error && <Spinner /> }

			<div className="fus-import-grid">
				{ ( sources || [] ).map( ( source ) => (
					<SourceCard
						key={ source.id }
						source={ source }
						prefix={ prefix }
						onPrefixChange={ setPrefix }
					/>
				) ) }
			</div>
		</>
	);
}
