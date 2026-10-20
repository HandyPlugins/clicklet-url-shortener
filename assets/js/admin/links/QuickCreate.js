import { Button, Notice, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { createLink } from '../api';
import CopyButton from '../components/CopyButton';

/**
 * One-field form: paste a URL, get a short link.
 *
 * @param {Object}     props
 * @param {() => void} props.onCreated Called after a link is created.
 */
export default function QuickCreate( { onCreated } ) {
	const [ url, setUrl ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ created, setCreated ] = useState( null );
	const [ error, setError ] = useState( '' );

	const submit = async ( event ) => {
		event.preventDefault();
		setSaving( true );
		setError( '' );

		try {
			const link = await createLink( { target_url: url.trim() } );
			setCreated( link );
			setUrl( '' );
			onCreated();
		} catch ( e ) {
			setError( e.message );
		} finally {
			setSaving( false );
		}
	};

	return (
		<div className="clicklet-quick-create">
			<form onSubmit={ submit }>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="url"
					label={ __( 'Long URL', 'clicklet-url-shortener' ) }
					hideLabelFromVision
					placeholder={ __(
						'Paste a long URL to shorten it',
						'clicklet-url-shortener'
					) }
					value={ url }
					onChange={ setUrl }
					required
				/>
				<Button
					variant="primary"
					type="submit"
					isBusy={ saving }
					disabled={ saving || ! url.trim() }
					__next40pxDefaultSize
				>
					{ __( 'Shorten', 'clicklet-url-shortener' ) }
				</Button>
			</form>

			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ created && (
				<Notice status="success" onRemove={ () => setCreated( null ) }>
					{ __( 'Short link ready:', 'clicklet-url-shortener' ) }{ ' ' }
					<a
						href={ created.short_url }
						target="_blank"
						rel="noreferrer"
					>
						<strong>{ created.short_url }</strong>
					</a>{ ' ' }
					<CopyButton
						text={ created.short_url }
						variant="secondary"
					/>
				</Notice>
			) }
		</div>
	);
}
