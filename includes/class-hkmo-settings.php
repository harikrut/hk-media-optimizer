<?php
/**
 * Handles plugin settings: defaults, registration, and retrieval.
 *
 * This is where the "maximum customer choice" requirement lives — every
 * scan source and safety rule is independently toggleable so site owners
 * can tune behavior (and server load) to their own setup.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HKMO_Settings {

	/**
	 * Default values for every setting. Stored as individual options
	 * (prefixed hkmo_) rather than one big array, so each is only
	 * autoloaded if actually needed and updates don't risk clobbering
	 * unrelated settings.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			// Scan sources - where to look for media usage.
			'scan_post_content'        => 1,
			'scan_featured_image'      => 1,
			'scan_post_meta'           => 1,
			'scan_widgets'             => 1,
			'scan_customizer'          => 1,
			'scan_acf_fields'          => 1,
			'scan_attachment_parent'   => 1,
			'scan_site_icon_logo'      => 1,

			// Post statuses to include when scanning content.
			'include_drafts'           => 1,
			'include_trashed'          => 0,
			'include_pending'          => 1,
			'include_private'          => 1,

			// Safety / exclude rules.
			'exclude_newer_than_days'  => 7, // Don't flag files uploaded in the last N days.
			'exclude_mime_types'       => array(), // e.g. ['application/pdf'].
			'exclude_folders'          => array(), // e.g. ['2024/logos'].
			'whitelist_ids'            => array(), // Attachment IDs never flagged.

			// Performance.
			'batch_size'               => 20, // Attachments processed per AJAX request.

			// Deletion behavior.
			'require_type_confirm'     => 1, // Require typing DELETE to confirm bulk delete.

			// Scheduled scans.
			'enable_scheduled_scan'    => 0,        // Run scans automatically on a recurring schedule.
			'scheduled_scan_frequency' => 'daily',  // 'daily', 'hkmo_weekly', or 'hkmo_monthly'.
			'scheduled_scan_notify'    => 1,        // Email a summary report after each scheduled scan.
			'scheduled_scan_email'     => '',       // Report recipient; empty = site admin email.
		);
	}

	/**
	 * Get a single setting value, falling back to its default.
	 *
	 * @param string $key Setting key (without hkmo_ prefix).
	 * @return mixed
	 */
	public static function get( $key ) {
		$defaults = self::get_defaults();
		$default  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : false;
		return get_option( 'hkmo_' . $key, $default );
	}

	/**
	 * Update a single setting value.
	 *
	 * @param string $key   Setting key (without hkmo_ prefix).
	 * @param mixed  $value New value.
	 * @return bool
	 */
	public static function update( $key, $value ) {
		return update_option( 'hkmo_' . $key, $value );
	}

	/**
	 * Get all current settings as an associative array (key => value),
	 * used to populate the settings form.
	 *
	 * @return array
	 */
	public static function get_all() {
		$defaults = self::get_defaults();
		$current  = array();
		foreach ( $defaults as $key => $default ) {
			$current[ $key ] = get_option( 'hkmo_' . $key, $default );
		}
		return $current;
	}

	/**
	 * Sanitize and save settings submitted from the admin form.
	 * Every field is explicitly allow-listed and cast to its expected type.
	 *
	 * @param array $input Raw $_POST data (already unslashed by caller).
	 */
	public static function save_from_request( $input ) {
		$checkboxes = array(
			'scan_post_content',
			'scan_featured_image',
			'scan_post_meta',
			'scan_widgets',
			'scan_customizer',
			'scan_acf_fields',
			'scan_attachment_parent',
			'scan_site_icon_logo',
			'include_drafts',
			'include_trashed',
			'include_pending',
			'include_private',
			'require_type_confirm',
			'enable_scheduled_scan',
			'scheduled_scan_notify',
		);

		foreach ( $checkboxes as $key ) {
			self::update( $key, isset( $input[ $key ] ) ? 1 : 0 );
		}

		if ( isset( $input['exclude_newer_than_days'] ) ) {
			self::update( 'exclude_newer_than_days', absint( $input['exclude_newer_than_days'] ) );
		}

		if ( isset( $input['batch_size'] ) ) {
			$batch_size = absint( $input['batch_size'] );
			// Clamp between 5 and 100 to prevent accidental server overload
			// from a too-high value, or an unusably slow scan from too-low.
			$batch_size = max( 5, min( 100, $batch_size ) );
			self::update( 'batch_size', $batch_size );
		}

		if ( isset( $input['exclude_mime_types'] ) && is_array( $input['exclude_mime_types'] ) ) {
			$mimes = array_map( 'sanitize_text_field', $input['exclude_mime_types'] );
			self::update( 'exclude_mime_types', $mimes );
		} else {
			self::update( 'exclude_mime_types', array() );
		}

		if ( isset( $input['exclude_folders'] ) ) {
			$folders_raw = sanitize_textarea_field( $input['exclude_folders'] );
			$folders     = array_filter( array_map( 'trim', explode( "\n", $folders_raw ) ) );
			self::update( 'exclude_folders', array_values( $folders ) );
		}

		if ( isset( $input['whitelist_ids'] ) ) {
			$ids_raw = sanitize_textarea_field( $input['whitelist_ids'] );
			$ids     = array_filter( array_map( 'absint', explode( ',', $ids_raw ) ) );
			self::update( 'whitelist_ids', array_values( $ids ) );
		}

		if ( isset( $input['scheduled_scan_frequency'] ) ) {
			$frequency = sanitize_key( $input['scheduled_scan_frequency'] );
			$allowed   = array( 'daily', 'hkmo_weekly', 'hkmo_monthly' );
			if ( ! in_array( $frequency, $allowed, true ) ) {
				$frequency = 'daily';
			}
			self::update( 'scheduled_scan_frequency', $frequency );
		}

		if ( isset( $input['scheduled_scan_email'] ) ) {
			$email = sanitize_email( $input['scheduled_scan_email'] );
			// Empty is valid (falls back to the site admin email at send time);
			// anything non-empty must be a real address or it's discarded.
			if ( '' !== trim( (string) $input['scheduled_scan_email'] ) && ! $email ) {
				$email = '';
			}
			self::update( 'scheduled_scan_email', $email );
		}

		// Keep the WP-Cron schedule in sync with whatever was just saved.
		if ( class_exists( 'HKMO_Scheduler' ) ) {
			HKMO_Scheduler::reschedule();
		}
	}
}
