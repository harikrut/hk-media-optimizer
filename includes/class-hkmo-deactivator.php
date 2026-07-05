<?php
/**
 * Fired during plugin deactivation.
 *
 * Note: Deactivation intentionally does NOT delete settings, scan results,
 * or the custom table. That destructive cleanup only happens on uninstall
 * (see uninstall.php), so users who deactivate temporarily (e.g. to test
 * for plugin conflicts) don't lose their data.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HKMO_Deactivator {

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate() {
		// Clear any in-progress scan state/locks so a stuck scan doesn't
		// block reactivation later.
		delete_transient( 'hkmo_scan_running' );
		delete_option( 'hkmo_scan_progress' );
		delete_option( 'hkmo_scan_origin' );
		delete_option( 'hkmo_scheduled_scan_offset' );
		delete_transient( 'hkmo_dup_scan_running' );
		delete_option( 'hkmo_dup_scan_progress' );

		// Stop the recurring scheduled-scan cron event (and any pending
		// continuation event) so it doesn't fire while the plugin is inactive.
		if ( class_exists( 'HKMO_Scheduler' ) ) {
			HKMO_Scheduler::unschedule();
		} else {
			// Fallback in case the class somehow isn't loaded yet.
			$timestamp = wp_next_scheduled( 'hkmo_scheduled_scan' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'hkmo_scheduled_scan' );
			}
		}
	}
}
