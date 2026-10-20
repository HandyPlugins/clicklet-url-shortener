# Clicklet URL Shortener

Fast, self-hosted URL shortener for WordPress with privacy-friendly click tracking. The WordPress.org description lives in `readme.txt`.

## Development

Requirements: PHP 7.4+, Composer, Node 20.19+ (see `.nvmrc`).

```bash
composer install
npm install
npm run build   # admin assets → dist/
npm run start   # rebuild on change
```

## Tests

Integration tests run against a real WordPress install and a separate, disposable database (default `local_tests`; it is reset on every run).

```bash
composer test
WP_MULTISITE=1 composer test   # the same suite on a multisite network
```

Defaults in `tests/wp-tests-config.php` fit a plugin checked out in `wp-content/plugins` of a local site. Override them with environment variables: `WP_CORE_DIR`, `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD`, `WP_TESTS_DB_HOST`.

## Code quality

```bash
composer lint        # PHPCS (10up-Default)
npm run lint:js
npm run lint:css
npm run lint:typos   # spell check; needs typos (brew install typos-cli)
```

Spell checking uses [typos](https://github.com/crate-ci/typos) with American English (`_typos.toml`). It also runs in CI on every push and pull request.

## Extending

Add-ons extend the plugin through these points; the plugin itself ships no add-on code.

**Data**

- Link meta uses the WordPress metadata API with the object type `Schema::META_TYPE` (`clicklet_link`): `get_metadata()`, `update_metadata()`, `delete_metadata()`, or `$link->get_meta( $key )`. Meta is deleted with its link.
- Set `has_rules` on a link when it needs every plugin loaded to redirect (geo rules, rotation…). The fast path then leaves it to the normal path.

**PHP hooks**

| Hook | Type | Use |
| --- | --- | --- |
| `clicklet_capability` | filter | Capability per context: `links` or `settings`. |
| `clicklet_prepare_link` | filter | Change or reject (`WP_Error`) link columns before saving. |
| `clicklet_link_available` | filter | Whether a link redirects now. |
| `clicklet_redirect_target` | filter | Destination URL. |
| `clicklet_redirect_status` | filter | HTTP status code. |
| `clicklet_before_redirect` | action | Right before the redirect is sent. |
| `clicklet_click_recorded` | action | After a human click is stored. |
| `clicklet_link_created` / `_updated` / `_deleted` | action | Link lifecycle. |
| `clicklet_rest_link_args` | filter | Extra REST fields for links (validated by their schema). |
| `clicklet_rest_save_link` | action | Save extra fields from the request. |
| `clicklet_rest_prepare_link` | filter | Add fields to REST responses. |
| `clicklet_rest_settings_args` / `_rest_settings` | filter | Extra settings in the REST schema and response. |
| `clicklet_rest_update_settings` | action | Save extra settings from the request. |
| `clicklet_csv_link_columns` / `_csv_export_link` | filter | Extra columns in the links CSV. |
| `clicklet_csv_import_link` | action | Read extra columns on import. |

Hooks on the redirect path run before regular plugins load when the fast path is on; use `has_rules` for links that need them.

**Admin UI**

Register a plugin with `registerPlugin( name, { scope: 'clicklet-url-shortener', render } )` and render `<Fill>`s into these slots (see `assets/js/admin/extensions.js`):

| Slot | Fill props |
| --- | --- |
| `ClickletLinkForm` | `link`, `values`, `setValue( key, value )` |
| `ClickletSettings` | `values`, `setValue( key, value )` |
| `ClickletStats` | `link`, `days`, `stats` |
| `ClickletApp` | `view` |

Values set with `setValue()` are sent with the link or settings request. The links table takes extra columns from the `clicklet.linkColumns` filter (`{ id, label, render( link ) }`) and row actions from `clicklet.rowActions` (`{ id, label, onClick( link ) }`). Load add-on scripts after `clicklet-url-shortener-admin`.

## Release

```bash
bin/build.sh   # build/clicklet-url-shortener.zip, without files in .distignore
```

Releases are driven by tags (GitHub Actions):

- `preview-*` (e.g. `preview-1.0`): builds the package and publishes it as a GitHub pre-release. Nothing goes to WordPress.org. Use it for packages sent to plugin review, so each submission can be rebuilt from its tag.
- `X.Y.Z` (e.g. `1.0.1`): checks that the tag matches `Version` in the plugin header and `Stable tag` in readme.txt, builds the admin assets, deploys to WordPress.org and attaches the package to a GitHub release.
- Pushes to `main` update readme.txt and `.wordpress-org` assets on WordPress.org.

The WordPress.org workflows need the `SVN_USERNAME` and `SVN_PASSWORD` repository secrets.

Run Plugin Check on the built package, not the working copy:

```bash
wp plugin check /path/to/build/clicklet-url-shortener
```
