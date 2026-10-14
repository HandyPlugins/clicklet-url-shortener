const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		ignores: [ 'dist/**' ],
	},
	{
		rules: {
			// @wordpress/* packages are provided by WordPress at runtime and left
			// out of the bundle, so they are intentionally not installed.
			'import/no-extraneous-dependencies': 'off',
			'import/no-unresolved': [ 'error', { ignore: [ '^@wordpress/' ] } ],
		},
	},
];
