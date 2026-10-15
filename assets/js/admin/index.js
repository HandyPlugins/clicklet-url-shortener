import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import App from './App';
import '../../css/admin.css';

// Mount once the DOM is ready, after add-on scripts that load later in the
// footer have registered their plugins and filters.
domReady( () => {
	const container = document.getElementById( 'fast-url-shortener-app' );

	if ( container ) {
		createRoot( container ).render(
			<App view={ container.dataset.view } />
		);
	}
} );
