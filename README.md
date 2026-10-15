# Fast URL Shortener

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
```

Defaults in `tests/wp-tests-config.php` fit a plugin checked out in `wp-content/plugins` of a local site. Override them with environment variables: `WP_CORE_DIR`, `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD`, `WP_TESTS_DB_HOST`.

## Code quality

```bash
composer lint        # PHPCS (10up-Default)
npm run lint:js
npm run lint:css
```

## Release

```bash
bin/build.sh   # build/fast-url-shortener.zip, without files in .distignore
```

Run Plugin Check on the built package, not the working copy:

```bash
wp plugin check /path/to/build/fast-url-shortener
```
