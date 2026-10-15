import {
	Button,
	CheckboxControl,
	FormTokenField,
	Modal,
	Notice,
	SelectControl,
	Slot,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { createLink, getGroups, updateLink } from '../api';
import { SLOTS } from '../extensions';
import { shortUrlBase } from '../utils';

export const REDIRECT_TYPES = [
	{
		value: 302,
		label: __( '302 – Temporary (recommended)', 'fast-url-shortener' ),
	},
	{
		value: 307,
		label: __(
			'307 – Temporary, keeps request method',
			'fast-url-shortener'
		),
	},
	{
		value: 301,
		label: __(
			'301 – Permanent (browsers cache it)',
			'fast-url-shortener'
		),
	},
	{
		value: 308,
		label: __(
			'308 – Permanent, keeps request method',
			'fast-url-shortener'
		),
	},
];

/**
 * Create or edit a link in a modal.
 *
 * @param {Object}                 props
 * @param {Object}                 props.link    Link to edit; an empty object creates a new one.
 * @param {() => void}             props.onClose Close without saving.
 * @param {(link: Object) => void} props.onSaved Called with the saved link.
 */
export default function LinkForm( { link, onClose, onSaved } ) {
	const isNew = ! link.id;
	const [ values, setValues ] = useState( {
		target_url: link.target_url || '',
		slug: link.slug || '',
		title: link.title || '',
		groups: ( link.groups || [] ).map( ( group ) => group.name ),
		redirect_type: link.redirect_type || '',
		status: link.status || 'active',
		forward_params: !! link.forward_params,
		track_clicks: isNew ? true : !! link.track_clicks,
		expires_at: link.expires_at_local || '',
		max_clicks: link.max_clicks || '',
	} );
	const [ force, setForce ] = useState( false );
	const [ groupNames, setGroupNames ] = useState( [] );

	useEffect( () => {
		getGroups()
			.then( ( groups ) =>
				setGroupNames( groups.map( ( group ) => group.name ) )
			)
			.catch( () => {} );
	}, [] );
	const [ error, setError ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	const set = ( key ) => ( value ) =>
		setValues( ( v ) => ( { ...v, [ key ]: value } ) );

	const submit = async ( event ) => {
		event.preventDefault();
		setSaving( true );
		setError( null );

		const data = {
			...values,
			redirect_type: values.redirect_type
				? Number( values.redirect_type )
				: undefined,
			expires_at: values.expires_at || null,
			max_clicks: values.max_clicks ? Number( values.max_clicks ) : null,
			force,
		};

		try {
			const saved = isNew
				? await createLink( data )
				: await updateLink( link.id, data );
			onSaved( saved );
		} catch ( e ) {
			setError( e );
			setSaving( false );
		}
	};

	const conflict = error?.code === 'fast_url_shortener_slug_conflict';

	return (
		<Modal
			title={
				isNew
					? __( 'Add New Link', 'fast-url-shortener' )
					: __( 'Edit Link', 'fast-url-shortener' )
			}
			onRequestClose={ onClose }
			className="fus-modal"
			size="medium"
		>
			<form onSubmit={ submit } className="fus-form">
				{ error && (
					<Notice
						status={ conflict ? 'warning' : 'error' }
						isDismissible={ false }
					>
						{ error.message }
					</Notice>
				) }

				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					type="url"
					label={ __( 'Destination URL', 'fast-url-shortener' ) }
					value={ values.target_url }
					onChange={ set( 'target_url' ) }
					required
				/>

				<div className="fus-slug-field">
					<span className="fus-slug-base">{ shortUrlBase() }</span>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Slug', 'fast-url-shortener' ) }
						help={
							isNew
								? __(
										'Leave empty to generate one.',
										'fast-url-shortener'
									)
								: undefined
						}
						value={ values.slug }
						onChange={ set( 'slug' ) }
						pattern="[A-Za-z0-9][A-Za-z0-9_\-]*"
						maxLength={ 100 }
					/>
				</div>

				{ conflict && (
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'Use this slug anyway. The short link will replace the existing page at this URL.',
							'fast-url-shortener'
						) }
						checked={ force }
						onChange={ setForce }
					/>
				) }

				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Title', 'fast-url-shortener' ) }
					help={ __( 'Only visible to you.', 'fast-url-shortener' ) }
					value={ values.title }
					onChange={ set( 'title' ) }
				/>

				<FormTokenField
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					__experimentalExpandOnFocus
					label={ __( 'Groups', 'fast-url-shortener' ) }
					value={ values.groups }
					suggestions={ groupNames }
					onChange={ ( tokens ) =>
						set( 'groups' )(
							tokens.map( ( token ) =>
								typeof token === 'string' ? token : token.value
							)
						)
					}
				/>

				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Redirect type', 'fast-url-shortener' ) }
					value={ values.redirect_type }
					options={ [
						...( isNew
							? [
									{
										value: '',
										label: __(
											'Default (from settings)',
											'fast-url-shortener'
										),
									},
								]
							: [] ),
						...REDIRECT_TYPES,
					] }
					onChange={ set( 'redirect_type' ) }
				/>

				<div className="fus-form-row">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="datetime-local"
						label={ __( 'Expires', 'fast-url-shortener' ) }
						help={ __(
							'Optional. In the site timezone.',
							'fast-url-shortener'
						) }
						value={ values.expires_at }
						onChange={ set( 'expires_at' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min="0"
						label={ __( 'Click limit', 'fast-url-shortener' ) }
						help={ __(
							'Optional. Stops redirecting after this many clicks.',
							'fast-url-shortener'
						) }
						value={ values.max_clicks }
						onChange={ set( 'max_clicks' ) }
					/>
				</div>

				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Forward query parameters',
						'fast-url-shortener'
					) }
					help={ __(
						'Adds ?utm_source=… and other parameters of the short link to the destination.',
						'fast-url-shortener'
					) }
					checked={ values.forward_params }
					onChange={ set( 'forward_params' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Track clicks', 'fast-url-shortener' ) }
					checked={ values.track_clicks }
					onChange={ set( 'track_clicks' ) }
				/>
				{ ! isNew && (
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Active', 'fast-url-shortener' ) }
						help={ __(
							'Disabled links stop redirecting.',
							'fast-url-shortener'
						) }
						checked={ 'active' === values.status }
						onChange={ ( on ) =>
							set( 'status' )( on ? 'active' : 'disabled' )
						}
					/>
				) }

				<Slot
					name={ SLOTS.linkForm }
					fillProps={ {
						link,
						values,
						setValue: ( key, value ) => set( key )( value ),
					} }
				/>

				<div className="fus-form-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'fast-url-shortener' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ saving }
						disabled={ saving || ( conflict && ! force ) }
					>
						{ isNew
							? __( 'Create Link', 'fast-url-shortener' )
							: __( 'Save', 'fast-url-shortener' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}
