import { Button, Modal, SelectControl } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import qrcode from 'qrcode-generator';

const MARGIN = 4;

/**
 * QR code URL for a link: scans add ?qr=1 so they are counted separately.
 *
 * @param {string} shortUrl Short URL.
 * @return {string} URL to encode.
 */
export const qrUrl = ( shortUrl ) =>
	shortUrl + ( shortUrl.includes( '?' ) ? '&' : '?' ) + 'qr=1';

/**
 * QR module matrix.
 *
 * @param {string} text Text to encode.
 * @return {boolean[][]} Rows of dark (true) and light modules.
 */
function matrix( text ) {
	const qr = qrcode( 0, 'M' );
	qr.addData( text );
	qr.make();

	const count = qr.getModuleCount();

	return Array.from( { length: count }, ( _, row ) =>
		Array.from( { length: count }, ( __unused, col ) =>
			qr.isDark( row, col )
		)
	);
}

/**
 * SVG path covering the dark modules, offset by the quiet zone.
 *
 * @param {boolean[][]} modules Module matrix.
 * @return {string} Path data.
 */
function path( modules ) {
	let d = '';

	modules.forEach( ( row, y ) =>
		row.forEach( ( dark, x ) => {
			if ( dark ) {
				d += `M${ x + MARGIN } ${ y + MARGIN }h1v1h-1z`;
			}
		} )
	);

	return d;
}

/**
 * Trigger a file download.
 *
 * @param {string} href     URL or data URL.
 * @param {string} filename File name.
 */
function download( href, filename ) {
	const anchor = document.createElement( 'a' );
	anchor.href = href;
	anchor.download = filename;
	anchor.click();
}

/**
 * QR code for a link, generated in the browser, with SVG and PNG downloads.
 *
 * @param {Object}     props
 * @param {Object}     props.link    Link.
 * @param {() => void} props.onClose Close handler.
 */
export default function QrModal( { link, onClose } ) {
	const [ size, setSize ] = useState( 1024 );
	const text = qrUrl( link.short_url );
	const modules = useMemo( () => matrix( text ), [ text ] );
	const d = useMemo( () => path( modules ), [ modules ] );
	const box = modules.length + MARGIN * 2;
	const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${ box } ${ box }" shape-rendering="crispEdges"><rect width="${ box }" height="${ box }" fill="#fff"/><path d="${ d }" fill="#000"/></svg>`;

	const downloadSvg = () =>
		download(
			'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( svg ),
			`qr-${ link.slug }.svg`
		);

	const downloadPng = () => {
		const canvas = document.createElement( 'canvas' );
		const scale = Math.max( 1, Math.floor( size / box ) );

		canvas.width = box * scale;
		canvas.height = box * scale;

		const context = canvas.getContext( '2d' );
		context.fillStyle = '#fff';
		context.fillRect( 0, 0, canvas.width, canvas.height );
		context.fillStyle = '#000';

		modules.forEach( ( row, y ) =>
			row.forEach( ( dark, x ) => {
				if ( dark ) {
					context.fillRect(
						( x + MARGIN ) * scale,
						( y + MARGIN ) * scale,
						scale,
						scale
					);
				}
			} )
		);

		download( canvas.toDataURL( 'image/png' ), `qr-${ link.slug }.png` );
	};

	return (
		<Modal
			title={ __( 'QR code', 'clicklet-url-shortener' ) }
			onRequestClose={ onClose }
			className="clicklet-modal clicklet-qr"
			size="small"
		>
			<svg
				className="clicklet-qr-code"
				viewBox={ `0 0 ${ box } ${ box }` }
				shapeRendering="crispEdges"
				role="img"
				aria-label={ text }
			>
				<rect width={ box } height={ box } fill="#fff" />
				<path d={ d } fill="#000" />
			</svg>
			<p className="clicklet-muted">
				{ __(
					'Scans of this code are counted as "QR code" in the stats.',
					'clicklet-url-shortener'
				) }
			</p>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'PNG size', 'clicklet-url-shortener' ) }
				value={ size }
				options={ [ 512, 1024, 2048 ].map( ( value ) => ( {
					value,
					label: `${ value } × ${ value } px`,
				} ) ) }
				onChange={ ( value ) => setSize( Number( value ) ) }
			/>
			<div className="clicklet-form-actions">
				<Button variant="secondary" onClick={ downloadSvg }>
					{ __( 'Download SVG', 'clicklet-url-shortener' ) }
				</Button>
				<Button variant="primary" onClick={ downloadPng }>
					{ __( 'Download PNG', 'clicklet-url-shortener' ) }
				</Button>
			</div>
		</Modal>
	);
}
