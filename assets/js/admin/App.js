import { Slot, SlotFillProvider } from '@wordpress/components';
import { PluginArea } from '@wordpress/plugins';

import { PLUGIN_SCOPE, SLOTS } from './extensions';
import LinksPage from './links/LinksPage';
import ImportPage from './import/ImportPage';
import SettingsPage from './settings/SettingsPage';

const VIEWS = {
	links: LinksPage,
	import: ImportPage,
	settings: SettingsPage,
};

/**
 * Root component. Each admin screen mounts the app with its own view.
 * Add-ons registered for the plugin scope render into the slots.
 *
 * @param {Object} props
 * @param {string} props.view View name.
 */
export default function App( { view } ) {
	const View = VIEWS[ view ] || LinksPage;

	return (
		<SlotFillProvider>
			<View />
			<Slot name={ SLOTS.app } fillProps={ { view } } />
			<PluginArea scope={ PLUGIN_SCOPE } />
		</SlotFillProvider>
	);
}
