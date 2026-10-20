import { Button, Modal, Notice, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { deleteGroup, renameGroup } from '../api';
import { formatNumber } from '../utils';

/**
 * Rename and delete groups. Groups are created from the link form.
 *
 * @param {Object}     props
 * @param {Array}      props.groups   Groups with id, name and count.
 * @param {() => void} props.onChange Called after a change.
 * @param {() => void} props.onClose  Close handler.
 */
export default function GroupsManager( { groups, onChange, onClose } ) {
	const [ editing, setEditing ] = useState( null );
	const [ name, setName ] = useState( '' );
	const [ error, setError ] = useState( '' );

	const run = async ( action ) => {
		setError( '' );

		try {
			await action();
			setEditing( null );
			onChange();
		} catch ( e ) {
			setError( e.message );
		}
	};

	return (
		<Modal
			title={ __( 'Groups', 'clicklet-url-shortener' ) }
			onRequestClose={ onClose }
			className="clicklet-modal"
			size="medium"
		>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ ! groups.length && (
				<p className="clicklet-muted">
					{ __(
						'No groups yet. Add groups to a link when you create or edit it.',
						'clicklet-url-shortener'
					) }
				</p>
			) }

			<ul className="clicklet-groups">
				{ groups.map( ( group ) => (
					<li key={ group.id }>
						{ editing === group.id ? (
							<form
								onSubmit={ ( event ) => {
									event.preventDefault();
									run( () => renameGroup( group.id, name ) );
								} }
							>
								<TextControl
									__nextHasNoMarginBottom
									__next40pxDefaultSize
									label={ __(
										'Group name',
										'clicklet-url-shortener'
									) }
									hideLabelFromVision
									value={ name }
									onChange={ setName }
								/>
								<Button variant="primary" type="submit">
									{ __( 'Save', 'clicklet-url-shortener' ) }
								</Button>
								<Button
									variant="tertiary"
									onClick={ () => setEditing( null ) }
								>
									{ __( 'Cancel', 'clicklet-url-shortener' ) }
								</Button>
							</form>
						) : (
							<>
								<span>
									<strong>{ group.name }</strong>{ ' ' }
									<span className="clicklet-muted">
										{ sprintf(
											/* translators: %s: number of links. */
											_n(
												'%s link',
												'%s links',
												group.count,
												'clicklet-url-shortener'
											),
											formatNumber( group.count )
										) }
									</span>
								</span>
								<span>
									<Button
										variant="link"
										onClick={ () => {
											setEditing( group.id );
											setName( group.name );
										} }
									>
										{ __(
											'Rename',
											'clicklet-url-shortener'
										) }
									</Button>{ ' ' }
									|{ ' ' }
									<Button
										variant="link"
										isDestructive
										onClick={ () =>
											run( () => deleteGroup( group.id ) )
										}
									>
										{ __(
											'Delete',
											'clicklet-url-shortener'
										) }
									</Button>
								</span>
							</>
						) }
					</li>
				) ) }
			</ul>
			<p className="clicklet-muted">
				{ __(
					'Deleting a group keeps its links.',
					'clicklet-url-shortener'
				) }
			</p>
		</Modal>
	);
}
