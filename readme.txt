=== Fast URL Shortener ===
Contributors:      handyplugins, m_uysl
Tags:              url shortener, short links, link tracking, affiliate links, redirect
Requires at least: 6.5
Tested up to:      7.1
Requires PHP:      7.4
Stable tag:        0.1.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Fast, self-hosted URL shortener for WordPress. Branded short links on your own domain with privacy-friendly click tracking.

== Description ==

Fast URL Shortener turns long URLs into short links on your own domain, like `example.com/promo`, and tells you how they perform. No third-party service, no monthly fee, and your data stays on your site.

= Built for speed =

* Short links resolve before the theme and most of WordPress load. With the optional fast path, they resolve before other plugins load too.
* Pages that are not short links cost nothing: they are filtered out from the URL alone, without a database query.
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
* Query string forwarding
* Optional prefix, for example `/go/promo`
* Protection against slugs that would hide existing pages
* Import & export links, daily stats and click details as CSV: move everything between sites or edit links in a spreadsheet (Excel files with semicolons work too)
* One-click migration from URL Shortify and Pretty Links, including click history and groups
* REST API and WP-CLI commands
* Multisite support

== Installation ==

1. Install the plugin from Plugins → Add New, or upload the `fast-url-shortener` folder to `/wp-content/plugins/`.
2. Activate it.

= WP-CLI =

`wp fast-url-shortener link create https://example.com/a/very/long/url --slug=promo`
`wp fast-url-shortener link list`
`wp fast-url-shortener stats promo --days=7`
`wp fast-url-shortener export links --file=links.csv`
`wp fast-url-shortener import links.csv`
`wp fast-url-shortener migrate pretty-links`
`wp fast-url-shortener post-links --post_type=post,page`
`wp fast-url-shortener fast-path enable`

== Frequently Asked Questions ==

= How do I move my links to another site? =

In Short Links → Import / Export, download the links, daily stats and click details files, then import them on the other site in that order. Files reference links by slug, so they work across sites, and importing the same file twice changes nothing.

= What is the fast path? =

An optional must-use plugin that resolves short links before other plugins load. It is a tiny loader file in `wp-content/mu-plugins`; turning it off removes the file. It is also removed when the plugin is deactivated.

= What happens to my links if I uninstall the plugin? =

Links and statistics are kept unless you turn on "delete data on uninstall", so reinstalling brings everything back.

= Are short links case-sensitive? =

Not by default: `/Promo` and `/promo` are the same link. Set `case_sensitive` to make them case-sensitive, e.g. `wp fast-url-shortener settings --case_sensitive=1`.

== Changelog ==

= 0.1.0 =
* Initial release.
