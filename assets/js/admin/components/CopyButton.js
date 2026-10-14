import { Button } from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Copies text to the clipboard and confirms for two seconds.
 *
 * @param {Object} props
 * @param {string} props.text    Text to copy.
 * @param {string} props.variant Button variant.
 */
export default function CopyButton( { text, variant = 'link' } ) {
	const [ copied, setCopied ] = useState( false );
	const ref = useCopyToClipboard( text, () => setCopied( true ) );

	useEffect( () => {
		if ( ! copied ) {
			return undefined;
		}

		const timeout = setTimeout( () => setCopied( false ), 2000 );

		return () => clearTimeout( timeout );
	}, [ copied ] );

	return (
		<Button ref={ ref } variant={ variant } size="small">
			{ copied
				? __( 'Copied!', 'fast-url-shortener' )
				: __( 'Copy', 'fast-url-shortener' ) }
		</Button>
	);
}
