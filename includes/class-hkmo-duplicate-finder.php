<?php
/**
 * Duplicate-file detection engine.
 *
 * Follows the same batched, AJAX-driven pattern as HKMO_Scanner: a single
 * request never hashes the entire media library, only one batch (sized via
 * the existing batch_size setting) so large libraries don't risk a timeout
 * or memory spike on shared hosting.
 *
 * Files are matched by an MD5 hash of their full contents, which is the only
 * way to reliably catch true duplicates regardless of filename — two files
 * named "logo.png" and "logo-copy.png" with identical bytes will match, while
 * two different photos that happen to share a filename will not.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HKMO_Duplicate_Finder {

	/**
	 * Start a fresh duplicate scan: clears previously stored hashes and
	 * resets progress markers.
	 *
	 * @return array Initial progress info (total attachments to hash).
	 */
	public function start_scan() {
		global $wpdb;

		HKMO_DB::clear_hashes();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
		);

		$progress = array(
			'total'     => $total,
			'processed' => 0,
			'done'      => false,
			'started'   => current_time( 'mysql' ),
		);

		update_option( 'hkmo_dup_scan_progress', $progress, false );
		set_transient( 'hkmo_dup_scan_running', 1, HOUR_IN_SECONDS );

		return $progress;
	}

	/**
	 * Hash one batch of attachments starting at the given offset. Called
	 * repeatedly by AJAX requests from the browser until $done is true.
	 *
	 * @param int $offset Offset into the attachment list.
	 * @return array Updated progress info.
	 */
	public function scan_batch( $offset ) {
		global $wpdb;

		$batch_size = (int) HKMO_Settings::get( 'batch_size' );
		$batch_size = $batch_size > 0 ? $batch_size : 20;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID ASC LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			)
		);

		if ( empty( $attachment_ids ) ) {
			$progress         = get_option( 'hkmo_dup_scan_progress', array() );
			$progress['done'] = true;
			update_option( 'hkmo_dup_scan_progress', $progress, false );
			delete_transient( 'hkmo_dup_scan_running' );
			return $progress;
		}

		foreach ( $attachment_ids as $attachment_id ) {
			$this->hash_attachment( (int) $attachment_id );
		}

		$progress              = get_option( 'hkmo_dup_scan_progress', array() );
		$progress['processed'] = isset( $progress['processed'] ) ? $progress['processed'] + count( $attachment_ids ) : count( $attachment_ids );
		$progress['done']      = false;
		update_option( 'hkmo_dup_scan_progress', $progress, false );

		return $progress;
	}

	/**
	 * Hash a single attachment's file on disk and store the result.
	 * Silently skips files that are missing or unreadable (e.g. offloaded
	 * to remote storage by another plugin) rather than failing the batch.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function hash_attachment( $attachment_id ) {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
			return;
		}

		$hash = md5_file( $file );
		if ( ! $hash ) {
			return;
		}

		HKMO_DB::upsert_hash( $attachment_id, $hash, (int) filesize( $file ) );
	}
}
