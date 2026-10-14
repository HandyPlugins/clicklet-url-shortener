import {
	Button,
	Card,
	CardBody,
	CardHeader,
	FormFileUpload,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	cancelCsvImport,
	csvImportStep,
	getCsvImport,
	uploadCsv,
} from '../api';
import { formatNumber, screenUrl } from '../utils';

const TYPE_LABELS = {
	links: __( 'links', 'fast-url-shortener' ),
	stats: __( 'daily stats', 'fast-url-shortener' ),
	clicks: __( 'click details', 'fast-url-shortener' ),
};

/**
 * Upload a links, stats or clicks CSV and import it in steps.
 */
export default function CsvImportCard() {
	const [ overwrite, setOverwrite ] = useState( false );
	const [ progress, setProgress ] = useState( null );
	const [ running, setRunning ] = useState( false );
	const [ error, setError ] = useState( '' );

	// Resume view of an import left unfinished (e.g. the tab was closed).
	useEffect( () => {
		getCsvImport()
			.then(
				( current ) =>
					current && ! current.done && setProgress( current )
			)
			.catch( () => {} );
	}, [] );

	const run = async ( first ) => {
		let current = first;
		setProgress( current );

		while ( ! current.done ) {
			current = await csvImportStep();
			setProgress( current );
		}
	};

	const upload = async ( event ) => {
		const file = event.target.files?.[ 0 ];
		event.target.value = '';

		if ( ! file ) {
			return;
		}

		setRunning( true );
		setError( '' );
		setProgress( null );

		try {
			await run( await uploadCsv( file, overwrite ) );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setRunning( false );
		}
	};

	const resume = async () => {
		setRunning( true );
		setError( '' );

		try {
			await run( progress );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setRunning( false );
		}
	};

	const cancel = async () => {
		await cancelCsvImport();
		setProgress( null );
	};

	const percent =
		progress && progress.total
			? Math.round( ( progress.processed / progress.total ) * 100 )
			: 0;

	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Import from CSV', 'fast-url-shortener' ) }</h2>
			</CardHeader>
			<CardBody>
				<p className="fus-muted">
					{ __(
						'Upload a links, daily stats or click details file; the type is detected from its header row. Import links first. Only a target_url column is required, so you can also create links in bulk from a spreadsheet.',
						'fast-url-shortener'
					) }
				</p>

				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Update links that already exist',
						'fast-url-shortener'
					) }
					help={ __(
						'Off: links whose slug already exists are skipped. Stats and click files can be imported again safely either way.',
						'fast-url-shortener'
					) }
					checked={ overwrite }
					onChange={ setOverwrite }
					disabled={ running }
				/>

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
							aria-valuenow={ percent }
							aria-valuemin="0"
							aria-valuemax="100"
						>
							<span style={ { width: `${ percent }%` } } />
						</div>
						<p>
							{ running && <Spinner /> }
							{ sprintf(
								/* translators: 1: file type, 2: processed rows, 3: total rows, 4: imported, 5: updated, 6: skipped. */
								__(
									'Importing %1$s: %2$s of %3$s rows. %4$s imported, %5$s updated, %6$s skipped.',
									'fast-url-shortener'
								),
								TYPE_LABELS[ progress.type ] || progress.type,
								formatNumber( progress.processed ),
								formatNumber( progress.total ),
								formatNumber( progress.imported ),
								formatNumber( progress.updated ),
								formatNumber( progress.skipped )
							) }
						</p>
					</div>
				) }

				{ progress?.done && (
					<Notice status="success" isDismissible={ false }>
						<p>
							{ __( 'Import complete.', 'fast-url-shortener' ) }{ ' ' }
							<a href={ screenUrl() }>
								{ __( 'View links', 'fast-url-shortener' ) }
							</a>
						</p>
					</Notice>
				) }

				{ progress?.skipped_rows?.length > 0 && ! running && (
					<details className="fus-skipped">
						<summary>
							{ __( 'Skipped rows', 'fast-url-shortener' ) }
						</summary>
						<table className="widefat striped">
							<thead>
								<tr>
									<th>
										{ __( 'Line', 'fast-url-shortener' ) }
									</th>
									<th>
										{ __( 'Slug', 'fast-url-shortener' ) }
									</th>
									<th>
										{ __( 'Reason', 'fast-url-shortener' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ progress.skipped_rows.map( ( row ) => (
									<tr key={ row.line }>
										<td>{ row.line }</td>
										<td>
											<code>{ row.slug }</code>
										</td>
										<td>{ row.reason }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</details>
				) }

				<div className="fus-import-actions">
					{ progress && ! progress.done && ! running ? (
						<>
							<Button variant="primary" onClick={ resume }>
								{ __(
									'Continue import',
									'fast-url-shortener'
								) }
							</Button>
							<Button variant="tertiary" onClick={ cancel }>
								{ __( 'Cancel', 'fast-url-shortener' ) }
							</Button>
						</>
					) : (
						<FormFileUpload
							accept=".csv,text/csv"
							onChange={ upload }
							render={ ( { openFileDialog } ) => (
								<Button
									variant="primary"
									onClick={ openFileDialog }
									isBusy={ running }
									disabled={ running }
								>
									{ __(
										'Choose CSV file…',
										'fast-url-shortener'
									) }
								</Button>
							) }
						/>
					) }
				</div>
			</CardBody>
		</Card>
	);
}
