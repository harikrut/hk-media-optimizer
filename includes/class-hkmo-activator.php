<?php
/**
 * Fired during plugin activation.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HKMO_Activator {

	/**
	 * Runs on plugin activation: creates the custom table and seeds default settings.
	 * Kept minimal and fast since activation runs synchronously on the request.
	 */
	public static function activate() {
		HKMO_DB::create_table();
		HKMO_DB::create_hashes_table();

		$defaults = HKMO_Settings::get_defaults();
		// Only add options that don't already exist (preserves settings on reactivation).
		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( 'hkmo_' . $key, false ) ) {
				add_option( 'hkmo_' . $key, $value );
			}
		}

		// Clear any stale scan progress from a previous install.
		delete_option( 'hkmo_scan_progress' );
		delete_option( 'hkmo_scan_origin' );
		delete_option( 'hkmo_dup_scan_progress' );
		delete_option( 'hkmo_scheduled_scan_offset' );
		delete_transient( 'hkmo_scan_running' );
		delete_transient( 'hkmo_dup_scan_running' );

		// In case settings were preserved from a prior activation (deactivate
		// then reactivate), make sure the cron schedule matches them.
		if ( class_exists( 'HKMO_Scheduler' ) ) {
			HKMO_Scheduler::reschedule();
		}
	}
}
