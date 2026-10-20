import { Button, Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const config = window.clicklet || {};

const FILES = [
	{
		type: 'links',
		label: __( 'Links', 'clicklet-url-shortener' ),
		description: __(
			'Slugs, destinations and settings. Edit it in a spreadsheet and import it back, or move it to another site.',
			'clicklet-url-shortener'
		),
	},
	{
		type: 'stats',
		label: __( 'Daily stats', 'clicklet-url-shortener' ),
		description: __(
			'Clicks, unique visitors and bots per link and day. Small, and enough for the charts.',
			'clicklet-url-shortener'
		),
	},
	{
		type: 'clicks',
		label: __( 'Click details', 'clicklet-url-shortener' ),
		description: __(
			'One row per click with country, device, browser and referrer. Can be large. No IP addresses.',
			'clicklet-url-shortener'
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
				<h2>{ __( 'Export to CSV', 'clicklet-url-shortener' ) }</h2>
			</CardHeader>
			<CardBody>
				<p className="clicklet-muted">
					{ __(
						'To move everything to another site, export all three files and import them there in the same order.',
						'clicklet-url-shortener'
					) }
				</p>
				<ul className="clicklet-export-list">
					{ FILES.map( ( file ) => (
						<li key={ file.type }>
							<div>
								<strong>{ file.label }</strong>
								<p className="clicklet-muted">
									{ file.description }
								</p>
							</div>
							<Button
								variant="secondary"
								href={ config.exportUrl?.[ file.type ] }
							>
								{ __( 'Download', 'clicklet-url-shortener' ) }
							</Button>
						</li>
					) ) }
				</ul>
			</CardBody>
		</Card>
	);
}
