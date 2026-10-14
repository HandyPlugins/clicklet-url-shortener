<?php
/**
 * Uninstall routine.
 *
 * The fast path drop-in and the cron job are always removed. Links and click
 * data are only deleted when "delete data on uninstall" is enabled, because
 * losing every published short link by accident cannot be undone.
 *
 * @package FastUrlShortener
 */

namespace FastUrlShortener;

use FastUrlShortener\Import\Importer;
use FastUrlShortener\Redirect\FastPath;
use FastUrlShortener\Tracking\Retention;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/autoload.php';

FastPath::uninstall();

$fast_url_shortener_site_ids = is_multisite() ? get_sites(
	[
		'fields'                 => 'ids',
		'number'                 => 0,
		'update_site_meta_cache' => false,
	]
) : [ get_current_blog_id() ];

foreach ( $fast_url_shortener_site_ids as $fast_url_shortener_site_id ) {
	if ( is_multisite() ) {
		switch_to_blog( $fast_url_shortener_site_id );
	}

	Retention::unschedule();

	if ( Settings::get( 'delete_data_on_uninstall' ) ) {
		Schema::drop_tables();
		delete_option( Settings::OPTION );
		delete_option( Install::DB_VERSION_OPTION );
		delete_option( Install::SECRET_OPTION );

		foreach ( Importer::sources() as $fast_url_shortener_source ) {
			delete_option( Importer::option_name( $fast_url_shortener_source ) );
		}
	}

	if ( is_multisite() ) {
		restore_current_blog();
	}
}
