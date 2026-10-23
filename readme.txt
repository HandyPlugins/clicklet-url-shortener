=== Clicklet URL Shortener ===
Contributors:      handyplugins, m_uysl
Tags:              url shortener, short links, link tracking, affiliate links, redirect
Requires at least: 6.5
Tested up to:      7.1
Requires PHP:      7.4
Stable tag:        1.0.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Fast, self-hosted URL shortener for WordPress. Branded short links on your own domain with privacy-friendly click tracking.

== Description ==

Clicklet URL Shortener turns long URLs into short links on your own domain, like `example.com/promo`, and tells you how they perform. No third-party service, no monthly fee, and your data stays on your site.

= Built for speed =

* Short links resolve before the theme and most of WordPress load. With the optional fast path, they resolve before other plugins load too.
* Requests that cannot be a short link (multi-segment paths, files, admin, REST) are filtered out from the URL alone, without a database query.
* Clicks are recorded after the visitor has been redirected, so tracking never slows a redirect down.
* Works with persistent object caches such as Redis and Memcached.

= Privacy friendly by default =

* No raw IP addresses are stored. Visitors are counted with an anonymous hash that changes every day.
* No cookies, and no requests to external services.
* Countries come from your CDN or host (for example Cloudflare) when available.

= Features =

* Unlimited short links with custom or generated slugs
* Groups to organize links (a link can be in several groups)
* Automatic short links for posts, pages and custom post types, kept in sync when the permalink changes
* Short link panel in the block editor, and short links as the WordPress shortlink (admin bar, rel=shortlink)
* 301, 302, 307 and 308 redirects
* Click history with no time limit: clicks, unique visitors, countries, devices, browsers, operating systems and referrers
* Bots, link previews (Slack, WhatsApp, Facebook…) and browser prefetches are not counted as clicks
* Expiring links, by date or number of clicks
* Password-protected links (passwords are stored hashed, with brute-force protection)
* QR codes generated on your site (SVG and PNG), with QR scans counted separately in the stats
* Dashboard widget: shorten a URL and see the week's most clicked links
* Query string forwarding
* Optional prefix, for example `/go/promo`
* Protection against slugs that would hide existing pages
* Import & export links, daily stats and click details as CSV: move everything between sites or edit links in a spreadsheet (Excel files with semicolons work too)
* One-click migration from URL Shortify and Pretty Links, including click history, groups and link passwords
* REST API and WP-CLI commands
* Multisite support

== Installation ==

1. Install the plugin from Plugins → Add New, or upload the `clicklet-url-shortener` folder to `/wp-content/plugins/`.
2. Activate it.

= WP-CLI =

`wp clicklet link create https://example.com/a/very/long/url --slug=promo`
`wp clicklet link list`
`wp clicklet stats promo --days=7`
`wp clicklet export links --file=links.csv`
`wp clicklet import links.csv`
`wp clicklet migrate pretty-links`
`wp clicklet post-links --post_type=post,page`
`wp clicklet fast-path enable`

== Frequently Asked Questions ==

= How do I move my links to another site? =

In Short Links → Import / Export, download the links, daily stats and click details files, then import them on the other site in that order. Files reference links by slug, so they work across sites, and importing the same file twice changes nothing.

= What is the fast path? =

An optional must-use plugin that resolves short links before other plugins load. It is a tiny loader file in `wp-content/mu-plugins`; turning it off removes the file. It is also removed when the plugin is deactivated.

= What happens to my links if I uninstall the plugin? =

Links and statistics are kept unless you turn on "delete data on uninstall", so reinstalling brings everything back.

= Are short links case-sensitive? =

Not by default: `/Promo` and `/promo` are the same link. Turn on "Case-sensitive slugs" in Short Links → Settings to make them different links.

= Does the plugin send data to external services? =

No. Short links, click statistics and QR codes are all handled on your site. Country information comes from request headers your CDN or host already adds, when available.

= Where is the source code of the admin scripts? =

The scripts in `dist/` are built from the readable sources in `assets/js/`, which are included in the plugin together with `package.json`. To rebuild them, run `npm install && npm run build` in the plugin folder. Development happens on GitHub: https://github.com/HandyPlugins/clicklet-url-shortener. QR codes use the qrcode-generator library (MIT): https://github.com/kazuhikoarase/qrcode-generator.

== Changelog ==

= 1.0.0 =
* Initial release.
