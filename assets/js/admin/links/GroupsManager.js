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
			title={ __( 'Groups', 'fast-url-shortener' ) }
			onRequestClose={ onClose }
			className="fus-modal"
			size="medium"
		>
			{ error && (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) }

			{ ! groups.length && (
				<p className="fus-muted">
					{ __(
						'No groups yet. Add groups to a link when you create or edit it.',
						'fast-url-shortener'
					) }
				</p>
			) }

			<ul className="fus-groups">
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
										'fast-url-shortener'
									) }
									hideLabelFromVision
									value={ name }
									onChange={ setName }
								/>
								<Button variant="primary" type="submit">
									{ __( 'Save', 'fast-url-shortener' ) }
								</Button>
								<Button
									variant="tertiary"
									onClick={ () => setEditing( null ) }
								>
									{ __( 'Cancel', 'fast-url-shortener' ) }
								</Button>
							</form>
						) : (
							<>
								<span>
									<strong>{ group.name }</strong>{ ' ' }
									<span className="fus-muted">
										{ sprintf(
											/* translators: %s: number of links. */
											_n(
												'%s link',
												'%s links',
												group.count,
												'fast-url-shortener'
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
										{ __( 'Rename', 'fast-url-shortener' ) }
									</Button>{ ' ' }
									|{ ' ' }
									<Button
										variant="link"
										isDestructive
										onClick={ () =>
											run( () => deleteGroup( group.id ) )
										}
									>
										{ __( 'Delete', 'fast-url-shortener' ) }
									</Button>
								</span>
							</>
						) }
					</li>
				) ) }
			</ul>
			<p className="fus-muted">
				{ __(
					'Deleting a group keeps its links.',
					'fast-url-shortener'
				) }
			</p>
		</Modal>
	);
}
