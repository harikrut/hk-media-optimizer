<?php
/**
 * Handles deletion of attachments confirmed unused by the scanner.
 *
 * Per product decision: no soft-trash state. Deletion is permanent but
 * gated behind an explicit confirmation step in the UI (and optionally a
 * "type DELETE to confirm" requirement), since adding a recoverable trash
 * layer would mean duplicating file storage temporarily — the opposite of
 * staying lightweight.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HKMO_Optimizer {

	/**
	 * Delete a list of attachments by ID.
	 * Re-validates each ID is actually marked 'unused' in our results table
	 * before deleting, so a stale/tampered request from the browser can't be
	 * used to delete arbitrary attachments.
	 *
	 * @param array $attachment_ids Array of attachment post IDs.
	 * @return array { deleted: int[], skipped: int[], freed_bytes: int }
	 */
	public function delete_attachments( $attachment_ids ) {
		global $wpdb;

		$attachment_ids = array_map( 'absint', (array) $attachment_ids );
		$attachment_ids = array_filter( $attachment_ids );

		$deleted     = array();
		$skipped     = array();
		$freed_bytes = 0;

		if ( empty( $attachment_ids ) ) {
			return array(
				'deleted'     => $deleted,
				'skipped'     => $skipped,
				'freed_bytes' => $freed_bytes,
			);
		}

		$table_name   = HKMO_DB::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );

		// Only attachments our own scan marked 'unused' are eligible for deletion here.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$verified_rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				"SELECT attachment_id, file_size FROM {$table_name} WHERE attachment_id IN ({$placeholders}) AND status = 'unused'",
				$attachment_ids
			)
		);

		$verified_ids = array();
		$size_map     = array();
		foreach ( $verified_rows as $row ) {
			$verified_ids[]                       = (int) $row->attachment_id;
			$size_map[ (int) $row->attachment_id ] = (int) $row->file_size;
		}

		foreach ( $attachment_ids as $attachment_id ) {
			if ( ! in_array( $attachment_id, $verified_ids, true ) ) {
				$skipped[] = $attachment_id;
				continue;
			}

			// Double-check it's actually an attachment post type before deleting,
			// as an extra guard against acting on the wrong post ID.
			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				$skipped[] = $attachment_id;
				continue;
			}

			$result = wp_delete_attachment( $attachment_id, true );

			if ( $result ) {
				$deleted[]    = $attachment_id;
				$freed_bytes += isset( $size_map[ $attachment_id ] ) ? $size_map[ $attachment_id ] : 0;
			} else {
				$skipped[] = $attachment_id;
			}
		}

		// Remove deleted (and confirmed-skipped-because-already-gone) rows from our results table.
		if ( ! empty( $deleted ) ) {
			HKMO_DB::delete_results( $deleted );
		}

		return array(
			'deleted'     => $deleted,
			'skipped'     => $skipped,
			'freed_bytes' => $freed_bytes,
		);
	}

	/**
	 * Delete attachments selected from the duplicate-finder UI.
	 *
	 * Every requested ID is re-grouped by its *current* stored hash (never
	 * trusting group membership reported by the browser) before anything is
	 * deleted. If a request would remove every member of a group, the lowest
	 * attachment ID (the oldest upload) is automatically kept so at least one
	 * copy always survives, even if the request itself didn't account for it.
	 *
	 * @param array $attachment_ids Array of attachment post IDs.
	 * @return array { deleted: int[], skipped: int[], freed_bytes: int }
	 */
	public function delete_duplicate_attachments( $attachment_ids ) {
		$attachment_ids = array_map( 'absint', (array) $attachment_ids );
		$attachment_ids = array_filter( $attachment_ids );

		$deleted     = array();
		$skipped     = array();
		$freed_bytes = 0;

		if ( empty( $attachment_ids ) ) {
			return array(
				'deleted'     => $deleted,
				'skipped'     => $skipped,
				'freed_bytes' => $freed_bytes,
			);
		}

		// Group the requested IDs by their current hash.
		$by_hash = array();
		foreach ( $attachment_ids as $attachment_id ) {
			$hash = HKMO_DB::get_hash_for_attachment( $attachment_id );
			if ( ! $hash ) {
				$skipped[] = $attachment_id;
				continue;
			}
			$by_hash[ $hash ][] = $attachment_id;
		}

		foreach ( $by_hash as $hash => $requested_in_group ) {
			$all_members = HKMO_DB::get_group_members_for_hash( $hash );

			// No longer actually a duplicate (e.g. the other copy was already removed) — skip.
			if ( count( $all_members ) <= 1 ) {
				foreach ( $requested_in_group as $attachment_id ) {
					$skipped[] = $attachment_id;
				}
				continue;
			}

			// Never let a single request wipe out an entire group: always keep
			// the lowest (oldest) ID if the request would otherwise remove all of them.
			if ( count( $requested_in_group ) >= count( $all_members ) ) {
				sort( $requested_in_group );
				array_shift( $requested_in_group );
			}

			foreach ( $requested_in_group as $attachment_id ) {
				if ( 'attachment' !== get_post_type( $attachment_id ) ) {
					$skipped[] = $attachment_id;
					continue;
				}

				$file      = get_attached_file( $attachment_id );
				$file_size = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;

				$result = wp_delete_attachment( $attachment_id, true );

				if ( $result ) {
					$deleted[]    = $attachment_id;
					$freed_bytes += $file_size;
				} else {
					$skipped[] = $attachment_id;
				}
			}
		}

		if ( ! empty( $deleted ) ) {
			HKMO_DB::delete_hash_results( $deleted );
			HKMO_DB::delete_results( $deleted );
		}

		return array(
			'deleted'     => $deleted,
			'skipped'     => $skipped,
			'freed_bytes' => $freed_bytes,
		);
	}
}
