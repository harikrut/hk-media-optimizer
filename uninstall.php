<?php
// phpcs:ignoreFile
/**
 * Fired when the plugin is deleted via the Plugins screen.
 *
 * WordPress only loads this file in a context where WP_UNINSTALL_PLUGIN
 * is defined, so this check protects against direct access.
 *
 * This removes ALL plugin data: the custom table, every hkmo_ option,
 * and any leftover transients. This is the only place destructive cleanup
 * happens — deactivation (see includes/class-hkmo-deactivator.php) leaves
 * everything intact so users can safely deactivate/reactivate without loss.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the custom results table.
$table_name = $wpdb->prefix . 'hkmo_scan_results';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Drop the file-hashes table used by the duplicate finder.
$hashes_table = $wpdb->prefix . 'hkmo_file_hashes';
$wpdb->query( "DROP TABLE IF EXISTS {$hashes_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Delete every option this plugin created (all prefixed hkmo_).
$option_names = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'hkmo\_%'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);

foreach ( $option_names as $option_name ) {
	delete_option( $option_name );
}

// Clean up any transients explicitly (covers cases where they weren't
// caught by the LIKE query due to the _transient_ prefix).
delete_transient( 'hkmo_scan_running' );
delete_transient( 'hkmo_dup_scan_running' );

// Clear any scheduled cron events.
$timestamp = wp_next_scheduled( 'hkmo_scheduled_scan' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'hkmo_scheduled_scan' );
}
$continue_timestamp = wp_next_scheduled( 'hkmo_scheduled_scan_continue' );
if ( $continue_timestamp ) {
	wp_unschedule_event( $continue_timestamp, 'hkmo_scheduled_scan_continue' );
}

// For multisite: repeat cleanup across all sites in the network.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );

		$table_name = $wpdb->prefix . 'hkmo_scan_results';
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$hashes_table = $wpdb->prefix . 'hkmo_file_hashes';
		$wpdb->query( "DROP TABLE IF EXISTS {$hashes_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$option_names = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'hkmo\_%'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		foreach ( $option_names as $option_name ) {
			delete_option( $option_name );
		}

		$site_timestamp = wp_next_scheduled( 'hkmo_scheduled_scan' );
		if ( $site_timestamp ) {
			wp_unschedule_event( $site_timestamp, 'hkmo_scheduled_scan' );
		}
		$site_continue_timestamp = wp_next_scheduled( 'hkmo_scheduled_scan_continue' );
		if ( $site_continue_timestamp ) {
			wp_unschedule_event( $site_continue_timestamp, 'hkmo_scheduled_scan_continue' );
		}

		restore_current_blog();
	}
}
