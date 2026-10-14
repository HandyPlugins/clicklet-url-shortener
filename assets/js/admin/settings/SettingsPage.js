import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getSettings, saveSettings } from '../api';
import { REDIRECT_TYPES } from '../links/LinkForm';
import { shortUrlBase } from '../utils';

const IP_HEADERS = [
	{
		value: '',
		label: __(
			'Direct connection (REMOTE_ADDR) – recommended',
			'fast-url-shortener'
		),
	},
	{
		value: 'HTTP_CF_CONNECTING_IP',
		label: __( 'Cloudflare (CF-Connecting-IP)', 'fast-url-shortener' ),
	},
	{ value: 'HTTP_X_FORWARDED_FOR', label: 'X-Forwarded-For' },
	{ value: 'HTTP_X_REAL_IP', label: 'X-Real-IP' },
	{ value: 'HTTP_TRUE_CLIENT_IP', label: 'True-Client-IP' },
	{ value: 'HTTP_CLIENT_IP', label: 'Client-IP' },
];

/**
 * Settings screen.
 */
export default function SettingsPage() {
	const [ saved, setSaved ] = useState( null );
	const [ values, setValues ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		getSettings()
			.then( ( settings ) => {
				setSaved( settings );
				setValues( settings );
			} )
			.catch( ( e ) =>
				setNotice( { status: 'error', message: e.message } )
			);
	}, [] );

	if ( ! values ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<Spinner />
		);
	}

	const set = ( key ) => ( value ) =>
		setValues( ( v ) => ( { ...v, [ key ]: value } ) );

	const submit = async ( event ) => {
		event.preventDefault();
		setSaving( true );
		setNotice( null );

		try {
			const settings = await saveSettings( {
				...values,
				redirect_type: Number( values.redirect_type ),
				slug_length: Number( values.slug_length ),
				retention_days: Number( values.retention_days ),
			} );
			setSaved( settings );
			setValues( settings );
			setNotice( {
				status: 'success',
				message: __( 'Settings saved.', 'fast-url-shortener' ),
			} );
		} catch ( e ) {
			setNotice( { status: 'error', message: e.message } );
		} finally {
			setSaving( false );
		}
	};

	const prefixChanged = values.prefix !== saved.prefix;
	const caseChanged = values.case_sensitive !== saved.case_sensitive;

	return (
		<form onSubmit={ submit } className="fus-settings">
			<h1>{ __( 'Short Link Settings', 'fast-url-shortener' ) }</h1>

			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<Card>
				<CardHeader>
					<h2>{ __( 'Links', 'fast-url-shortener' ) }</h2>
				</CardHeader>
				<CardBody>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Prefix', 'fast-url-shortener' ) }
						help={ sprintf(
							/* translators: %s: example short URL. */
							__(
								'Optional path segment for all short links. Example: %s',
								'fast-url-shortener'
							),
							shortUrlBase( values.prefix ) + 'promo'
						) }
						value={ values.prefix }
						onChange={ set( 'prefix' ) }
					/>
					{ prefixChanged && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Changing the prefix changes every existing short URL. Links you already shared will stop working.',
								'fast-url-shortener'
							) }
						</Notice>
					) }

					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Default redirect type',
							'fast-url-shortener'
						) }
						value={ values.redirect_type }
						options={ REDIRECT_TYPES }
						onChange={ set( 'redirect_type' ) }
					/>

					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min="3"
						max="20"
						label={ __(
							'Generated slug length',
							'fast-url-shortener'
						) }
						value={ values.slug_length }
						onChange={ set( 'slug_length' ) }
					/>

					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Case-sensitive slugs',
							'fast-url-shortener'
						) }
						help={ __(
							'When on, /Promo and /promo are different links.',
							'fast-url-shortener'
						) }
						checked={ values.case_sensitive }
						onChange={ set( 'case_sensitive' ) }
					/>
					{ caseChanged && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'Existing slugs are not changed. Links created while slugs were case-insensitive are stored in lowercase.',
								'fast-url-shortener'
							) }
						</Notice>
					) }
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>
						{ __( 'Tracking and privacy', 'fast-url-shortener' ) }
					</h2>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Track clicks', 'fast-url-shortener' ) }
						help={ __(
							'No raw IP addresses or cookies are stored. Visitors are counted with an anonymous hash that changes daily.',
							'fast-url-shortener'
						) }
						checked={ values.track_clicks }
						onChange={ set( 'track_clicks' ) }
					/>

					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min="0"
						label={ __(
							'Keep detailed click data for (days)',
							'fast-url-shortener'
						) }
						help={ __(
							'0 keeps everything. Daily totals are always kept, so charts stay complete.',
							'fast-url-shortener'
						) }
						value={ values.retention_days }
						onChange={ set( 'retention_days' ) }
					/>

					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __(
							'Visitor IP source',
							'fast-url-shortener'
						) }
						help={ __(
							'Only change this behind a proxy or CDN you trust. Forwarded headers can be faked otherwise.',
							'fast-url-shortener'
						) }
						value={ values.ip_header }
						options={ IP_HEADERS }
						onChange={ set( 'ip_header' ) }
					/>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'Performance', 'fast-url-shortener' ) }</h2>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Fast path', 'fast-url-shortener' ) }
						help={ __(
							'Resolves short links before other plugins load, using a small file in wp-content/mu-plugins. Redirect filters from regular plugins do not run for short links when this is on.',
							'fast-url-shortener'
						) }
						checked={ values.fast_path }
						onChange={ set( 'fast_path' ) }
					/>
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2>{ __( 'Uninstall', 'fast-url-shortener' ) }</h2>
				</CardHeader>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Delete all links and statistics when the plugin is deleted',
							'fast-url-shortener'
						) }
						help={ __(
							'Off by default, so reinstalling brings everything back.',
							'fast-url-shortener'
						) }
						checked={ values.delete_data_on_uninstall }
						onChange={ set( 'delete_data_on_uninstall' ) }
					/>
				</CardBody>
			</Card>

			<p>
				<Button
					variant="primary"
					type="submit"
					isBusy={ saving }
					disabled={ saving }
				>
					{ __( 'Save Settings', 'fast-url-shortener' ) }
				</Button>
			</p>
		</form>
	);
}
