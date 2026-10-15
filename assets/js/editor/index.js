/**
 * Block editor: "Short link" panel in the document sidebar.
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, ExternalLink, Notice, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import * as editPost from '@wordpress/edit-post';
import * as editor from '@wordpress/editor';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

import CopyButton from '../admin/components/CopyButton';

// WordPress 6.6 moved the panel from edit-post to editor.
const PluginDocumentSettingPanel =
	editor.PluginDocumentSettingPanel || editPost.PluginDocumentSettingPanel;

const config = window.fastUrlShortenerEditor || {};

/**
 * Panel content.
 */
function ShortLinkPanel() {
	const { postId, postType, status, isSaving } = useSelect( ( select ) => {
		const store = select( 'core/editor' );

		return {
			postId: store.getCurrentPostId(),
			postType: store.getCurrentPostType(),
			status: store.getCurrentPostAttribute( 'status' ),
			isSaving: store.isSavingPost() && ! store.isAutosavingPost(),
		};
	}, [] );
	// undefined while loading, null when the post has no link.
	const [ link, setLink ] = useState( undefined );
	const [ error, setError ] = useState( '' );
	const [ creating, setCreating ] = useState( false );
	const wasSaving = useRef( false );

	const path = `/fast-url-shortener/v1/posts/${ postId }/link`;

	const load = useCallback( () => {
		apiFetch( { path } )
			.then( ( response ) => setLink( response.link ) )
			.catch( ( e ) => setError( e.message ) );
	}, [ path ] );

	useEffect( () => {
		if ( postId ) {
			load();
		}
	}, [ postId, load ] );

	// Publishing may create the link and permalink changes update it: reload after saves.
	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			load();
		}

		wasSaving.current = isSaving;
	}, [ isSaving, load ] );

	const create = async () => {
		setCreating( true );
		setError( '' );

		try {
			setLink( ( await apiFetch( { path, method: 'POST' } ) ).link );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setCreating( false );
		}
	};

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	if ( undefined === link ) {
		return <Spinner />;
	}

	if ( link ) {
		return (
			<>
				<p style={ { wordBreak: 'break-all' } }>
					<a href={ link.short_url } target="_blank" rel="noreferrer">
						{ link.short_url }
					</a>
				</p>
				<p>
					<CopyButton text={ link.short_url } variant="secondary" />
				</p>
				<p>
					{ sprintf(
						/* translators: %s: number of clicks. */
						_n(
							'%s click',
							'%s clicks',
							link.total_clicks,
							'fast-url-shortener'
						),
						Number( link.total_clicks ).toLocaleString()
					) }
				</p>
				<ExternalLink
					href={ `${ config.adminUrl }?page=fast-url-shortener&s=${ encodeURIComponent(
						link.slug
					) }` }
				>
					{ __( 'Manage short link', 'fast-url-shortener' ) }
				</ExternalLink>
			</>
		);
	}

	if ( 'publish' === status ) {
		return (
			<Button
				variant="secondary"
				onClick={ create }
				isBusy={ creating }
				disabled={ creating }
			>
				{ __( 'Create short link', 'fast-url-shortener' ) }
			</Button>
		);
	}

	return (
		<p>
			{ ( config.autoPostTypes || [] ).includes( postType )
				? __(
						'A short link is created when you publish.',
						'fast-url-shortener'
					)
				: __(
						'You can create a short link once this is published.',
						'fast-url-shortener'
					) }
		</p>
	);
}

registerPlugin( 'fast-url-shortener', {
	render: () => (
		<PluginDocumentSettingPanel
			name="fast-url-shortener"
			title={ __( 'Short link', 'fast-url-shortener' ) }
		>
			<ShortLinkPanel />
		</PluginDocumentSettingPanel>
	),
} );
