import { createRoot } from '@wordpress/element';

import App from './App';
import '../../css/admin.css';

const container = document.getElementById( 'fast-url-shortener-app' );

if ( container ) {
	createRoot( container ).render( <App view={ container.dataset.view } /> );
}
