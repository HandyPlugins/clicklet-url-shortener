<?php
/**
 * Uninstall routine.
 *
 * @package Clicklet
 */

namespace Clicklet;

use Clicklet\Import\Importer;
use Clicklet\Links\Groups;
use Clicklet\Links\PostLinks;
use Clicklet\Redirect\FastPath;
use Clicklet\Tracking\Retention;
use Clicklet\Transfer\CsvImporter;

/**
 * Cleans up when the plugin is deleted.
 *
 * The fast path drop-in, cron job and pending uploads are always removed.
 * Links and click data are only deleted when "delete data on uninstall" is
 * on, because losing every published short link by accident cannot be undone.
 */
class Uninstall {

	/**
	 * Clean up every site.
	 *
	 * @return void
	 */
	public static function run() {
		FastPath::uninstall();

		if ( ! is_multisite() ) {
			self::site();
			return;
		}

		$site_ids = get_sites(
			[
				'fields'                 => 'ids',
				'number'                 => 0,
				'update_site_meta_cache' => false,
			]
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			self::site();
			restore_current_blog();
		}
	}

	/**
	 * Clean up the current site.
	 *
	 * @return void
	 */
	protected static function site() {
		Retention::unschedule();
		self::delete_uploads();

		if ( ! Settings::get( 'delete_data_on_uninstall' ) ) {
			return;
		}

		// Group terms live in core tables; the taxonomy must be registered to delete them.
		Groups::register_taxonomy();

		$terms = get_terms(
			[
				'taxonomy'   => Groups::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);

		foreach ( is_array( $terms ) ? $terms : [] as $term_id ) {
			wp_delete_term( $term_id, Groups::TAXONOMY );
		}

		delete_post_meta_by_key( PostLinks::POST_META );
		Schema::drop_tables();

		foreach ( [ Settings::OPTION, Install::DB_VERSION_OPTION, Install::SECRET_OPTION, CsvImporter::OPTION ] as $option ) {
			delete_option( $option );
		}

		foreach ( Importer::sources() as $source ) {
			delete_option( Importer::option_name( $source ) );
		}

		if ( wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( Links\Repository::CACHE_GROUP );
		}
	}

	/**
	 * Remove pending CSV uploads and their folder.
	 *
	 * @return void
	 */
	protected static function delete_uploads() {
		$dir = wp_upload_dir()['basedir'] . '/' . CsvImporter::UPLOAD_DIR;

		foreach ( (array) glob( $dir . '/{*.csv,index.php,.htaccess}', GLOB_BRACE ) as $file ) {
			if ( $file ) {
				wp_delete_file( $file );
			}
		}

		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty temp directory.
		}
	}
}
