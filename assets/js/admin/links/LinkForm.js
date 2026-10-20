import {
	BaseControl,
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
import { useInstanceId } from '@wordpress/compose';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { createLink, getGroups, updateLink } from '../api';
import { SLOTS } from '../extensions';
import { shortUrlBase } from '../utils';

export const REDIRECT_TYPES = [
	{
		value: 302,
		label: __( '302 – Temporary (recommended)', 'clicklet-url-shortener' ),
	},
	{
		value: 307,
		label: __(
			'307 – Temporary, keeps request method',
			'clicklet-url-shortener'
		),
	},
	{
		value: 301,
		label: __(
			'301 – Permanent (browsers cache it)',
			'clicklet-url-shortener'
		),
	},
	{
		value: 308,
		label: __(
			'308 – Permanent, keeps request method',
			'clicklet-url-shortener'
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
	const slugId = useInstanceId( LinkForm, 'clicklet-link-slug' );
	const base = shortUrlBase();
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
	const [ removePassword, setRemovePassword ] = useState( false );
	const [ groupNames, setGroupNames ] = useState( [] );

	useEffect( () => {
		getGroups()
			.then( ( groups ) =>
				setGroupNames( groups.map( ( group ) => group.name ) )
			)
			.catch( () => {} );
	}, [] );
	const [ error, setError ] = useState( null );
	// Slug the last error was about; a conflict only holds while the slug is unchanged.
	const [ errorSlug, setErrorSlug ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	const set = ( key ) => ( value ) =>
		setValues( ( v ) => ( { ...v, [ key ]: value } ) );

	const conflictError = error?.code === 'clicklet_slug_conflict';
	const conflict = conflictError && values.slug === errorSlug;
	// A conflict warning for a slug that has since been edited is stale.
	const showError = error && ( ! conflictError || conflict );

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
			// Only sent when changed: a new password, or '' to remove it.
			password: removePassword ? '' : values.password || undefined,
			// The confirmation only covers the slug it was given for.
			force: conflict && force,
		};

		try {
			const saved = isNew
				? await createLink( data )
				: await updateLink( link.id, data );
			onSaved( saved );
		} catch ( e ) {
			setError( e );
			setErrorSlug( values.slug );
			setForce( false );
			setSaving( false );
		}
	};

	return (
		<Modal
			title={
				isNew
					? __( 'Add New Link', 'clicklet-url-shortener' )
					: __( 'Edit Link', 'clicklet-url-shortener' )
			}
			onRequestClose={ onClose }
			className="clicklet-modal"
			size="medium"
		>
			<form onSubmit={ submit } className="clicklet-form">
				{ showError && (
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
					label={ __( 'Destination URL', 'clicklet-url-shortener' ) }
					value={ values.target_url }
					onChange={ set( 'target_url' ) }
					required
				/>

				<BaseControl
					__nextHasNoMarginBottom
					id={ slugId }
					label={ __( 'Short link', 'clicklet-url-shortener' ) }
					help={
						isNew
							? __(
									'Leave the slug empty to generate one.',
									'clicklet-url-shortener'
								)
							: undefined
					}
				>
					{ /* Reads like a browser address bar: the fixed base, then the editable slug. */ }
					<div className="clicklet-url-input">
						<span
							className="clicklet-url-input__base"
							title={ base }
						>
							{ base.replace( /^https?:\/\//, '' ) }
						</span>
						<input
							id={ slugId }
							type="text"
							className="clicklet-url-input__slug"
							value={ values.slug }
							onChange={ ( event ) =>
								set( 'slug' )( event.target.value )
							}
							placeholder={
								isNew
									? __( 'random', 'clicklet-url-shortener' )
									: undefined
							}
							pattern="[A-Za-z0-9][A-Za-z0-9_\-]*"
							maxLength={ 100 }
							autoComplete="off"
							spellCheck={ false }
							aria-describedby={
								isNew ? `${ slugId }__help` : undefined
							}
						/>
					</div>
				</BaseControl>

				{ conflict && (
					<CheckboxControl
						__nextHasNoMarginBottom
						label={ __(
							'Use this slug anyway. The short link will replace the existing page at this URL.',
							'clicklet-url-shortener'
						) }
						checked={ force }
						onChange={ setForce }
					/>
				) }

				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Title', 'clicklet-url-shortener' ) }
					help={ __(
						'Only visible to you.',
						'clicklet-url-shortener'
					) }
					value={ values.title }
					onChange={ set( 'title' ) }
				/>

				<FormTokenField
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					__experimentalExpandOnFocus
					label={ __( 'Groups', 'clicklet-url-shortener' ) }
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

				{ link.has_password && ! removePassword ? (
					<div className="clicklet-password-set">
						<p>
							{ __(
								'This link is password protected.',
								'clicklet-url-shortener'
							) }{ ' ' }
							<Button
								variant="link"
								isDestructive
								onClick={ () => setRemovePassword( true ) }
							>
								{ __(
									'Remove password',
									'clicklet-url-shortener'
								) }
							</Button>
						</p>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							type="password"
							autoComplete="new-password"
							label={ __(
								'New password',
								'clicklet-url-shortener'
							) }
							help={ __(
								'Leave empty to keep the current password.',
								'clicklet-url-shortener'
							) }
							value={ values.password || '' }
							onChange={ set( 'password' ) }
						/>
					</div>
				) : (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="password"
						autoComplete="new-password"
						label={ __( 'Password', 'clicklet-url-shortener' ) }
						help={
							removePassword
								? __(
										'The password will be removed when you save.',
										'clicklet-url-shortener'
									)
								: __(
										'Optional. Visitors must enter it before they are redirected.',
										'clicklet-url-shortener'
									)
						}
						value={ values.password || '' }
						onChange={ ( value ) => {
							setRemovePassword( false );
							set( 'password' )( value );
						} }
					/>
				) }

				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Redirect type', 'clicklet-url-shortener' ) }
					value={ values.redirect_type }
					options={ [
						...( isNew
							? [
									{
										value: '',
										label: __(
											'Default (from settings)',
											'clicklet-url-shortener'
										),
									},
								]
							: [] ),
						...REDIRECT_TYPES,
					] }
					onChange={ set( 'redirect_type' ) }
				/>

				<div className="clicklet-form-row">
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="datetime-local"
						label={ __( 'Expires', 'clicklet-url-shortener' ) }
						help={ __(
							'Optional. In the site timezone.',
							'clicklet-url-shortener'
						) }
						value={ values.expires_at }
						onChange={ set( 'expires_at' ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min="0"
						label={ __( 'Click limit', 'clicklet-url-shortener' ) }
						help={ __(
							'Optional. Stops redirecting after this many clicks.',
							'clicklet-url-shortener'
						) }
						value={ values.max_clicks }
						onChange={ set( 'max_clicks' ) }
					/>
				</div>

				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Forward query parameters',
						'clicklet-url-shortener'
					) }
					help={ __(
						'Adds ?utm_source=… and other parameters of the short link to the destination.',
						'clicklet-url-shortener'
					) }
					checked={ values.forward_params }
					onChange={ set( 'forward_params' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Track clicks', 'clicklet-url-shortener' ) }
					checked={ values.track_clicks }
					onChange={ set( 'track_clicks' ) }
				/>
				{ ! isNew && (
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Active', 'clicklet-url-shortener' ) }
						help={ __(
							'Disabled links stop redirecting.',
							'clicklet-url-shortener'
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

				<div className="clicklet-form-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __( 'Cancel', 'clicklet-url-shortener' ) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ saving }
						disabled={ saving || ( conflict && ! force ) }
					>
						{ isNew
							? __( 'Create Link', 'clicklet-url-shortener' )
							: __( 'Save', 'clicklet-url-shortener' ) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}
