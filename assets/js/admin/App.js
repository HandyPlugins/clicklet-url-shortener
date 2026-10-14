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
 *
 * @param {Object} props
 * @param {string} props.view View name.
 */
export default function App( { view } ) {
	const View = VIEWS[ view ] || LinksPage;

	return <View />;
}
