import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const config = window.fastUrlShortener || {};

const FILES = [
	{
		type: 'links',
		label: __( 'Links', 'fast-url-shortener' ),
		description: __(
			'Slugs, destinations and settings. Edit it in a spreadsheet and import it back, or move it to another site.',
			'fast-url-shortener'
		),
	},
	{
		type: 'stats',
		label: __( 'Daily stats', 'fast-url-shortener' ),
		description: __(
			'Clicks, unique visitors and bots per link and day. Small, and enough for the charts.',
			'fast-url-shortener'
		),
	},
	{
		type: 'clicks',
		label: __( 'Click details', 'fast-url-shortener' ),
		description: __(
			'One row per click with country, device, browser and referrer. Can be large. No IP addresses.',
			'fast-url-shortener'
		),
	},
];

/**
 * CSV downloads.
 */
export default function ExportCard() {
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Export to CSV', 'fast-url-shortener' ) }</h2>
			</CardHeader>
			<CardBody>
				<p className="fus-muted">
					{ __(
						'To move everything to another site, export all three files and import them there in the same order.',
						'fast-url-shortener'
					) }
				</p>
				<ul className="fus-export-list">
					{ FILES.map( ( file ) => (
						<li key={ file.type }>
							<div>
								<strong>{ file.label }</strong>
								<p className="fus-muted">
									{ file.description }
								</p>
							</div>
							<Button
								variant="secondary"
								href={ config.exportUrl?.[ file.type ] }
							>
								{ __( 'Download', 'fast-url-shortener' ) }
							</Button>
						</li>
					) ) }
				</ul>
			</CardBody>
		</Card>
	);
}
