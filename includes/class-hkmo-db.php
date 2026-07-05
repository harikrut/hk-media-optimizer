<?php
/**
 * Handles creation and access to the custom scan-results table.
 *
 * Why a custom table instead of postmeta/options:
 * Storing a "used/unused" flag for every attachment as postmeta would bloat
 * wp_postmeta (one of the most frequently queried tables on a normal page load)
 * and risks accidentally autoloading large data sets. A dedicated table keeps
 * this plugin's data fully isolated, indexable, and easy to clean up on uninstall.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HKMO_DB {

	/**
	 * Get the fully prefixed table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . HKMO_TABLE_NAME;
	}

	/**
	 * Create the custom table. Called on activation and on version bumps.
	 */
	public static function create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta requires two spaces after PRIMARY KEY and specific formatting.
		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			reason VARCHAR(191) DEFAULT NULL,
			file_size BIGINT(20) UNSIGNED DEFAULT 0,
			scanned_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'hkmo_db_version', HKMO_DB_VERSION );
	}

	/**
	 * Drop the custom table entirely (used on uninstall, not deactivation).
	 */
	public static function drop_table() {
		global $wpdb;
		$table_name = self::table_name();
		// Table name is built from trusted constants/prefix, not user input.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
	}

	/**
	 * Truncate results (used when starting a fresh scan).
	 */
	public static function clear_results() {
		global $wpdb;
		$table_name = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );
	}

	/**
	 * Insert or update a single scan result row.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $status        'used' or 'unused'.
	 * @param string $reason        Human-readable reason (where it was found, or empty if unused).
	 * @param int    $file_size     File size in bytes.
	 */
	public static function upsert_result( $attachment_id, $status, $reason, $file_size ) {
		global $wpdb;
		$table_name = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table_name} (attachment_id, status, reason, file_size, scanned_at) " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"VALUES (%d, %s, %s, %d, %s) " .
				"ON DUPLICATE KEY UPDATE status = %s, reason = %s, file_size = %d, scanned_at = %s",
				$attachment_id,
				$status,
				$reason,
				$file_size,
				current_time( 'mysql' ),
				$status,
				$reason,
				$file_size,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Get paginated results, optionally filtered by status.
	 *
	 * @param string $status   'used', 'unused', or 'all'.
	 * @param int    $per_page Items per page.
	 * @param int    $page     Current page (1-indexed).
	 * @return array
	 */
	public static function get_results( $status = 'unused', $per_page = 50, $page = 1 ) {
		global $wpdb;
		$table_name = self::table_name();
		$offset     = max( 0, ( $page - 1 ) * $per_page );

		if ( 'all' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table_name} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d",
					$status,
					$per_page,
					$offset
				)
			);
		}

		return $rows ? $rows : array();
	}

	/**
	 * Count results by status.
	 *
	 * @param string $status 'used', 'unused', or 'all'.
	 * @return int
	 */
	public static function count_results( $status = 'unused' ) {
		global $wpdb;
		$table_name = self::table_name();

		if ( 'all' === $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table_name} WHERE status = %s",
				$status
			)
		);
	}

	/**
	 * Get total reclaimable file size (sum of unused file sizes).
	 *
	 * @return int Bytes.
	 */
	public static function get_reclaimable_size() {
		global $wpdb;
		$table_name = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT SUM(file_size) FROM {$table_name} WHERE status = %s",
				'unused'
			)
		);
	}

	/**
	 * Delete specific result rows by attachment ID (after the attachment itself is deleted).
	 *
	 * @param array $attachment_ids Array of attachment IDs.
	 */
	public static function delete_results( $attachment_ids ) {
		global $wpdb;
		$table_name = self::table_name();

		if ( empty( $attachment_ids ) ) {
			return;
		}

		$attachment_ids = array_map( 'absint', $attachment_ids );
		$placeholders   = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				"DELETE FROM {$table_name} WHERE attachment_id IN ({$placeholders})",
				$attachment_ids
			)
		);
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Duplicate-finder storage.
	 *
	 * File hashes live in their own dedicated table rather than as a column
	 * on hkmo_scan_results, because the results table is fully truncated at
	 * the start of every "Start New Scan" run (see clear_results() above).
	 * Keeping hashes isolated means a regular unused/used scan never wipes
	 * out duplicate-finder data, and vice versa.
	 * ───────────────────────────────────────────────────────────────────── */

	/**
	 * Get the fully prefixed file-hashes table name.
	 *
	 * @return string
	 */
	public static function hashes_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'hkmo_file_hashes';
	}

	/**
	 * Create the file-hashes table. Called on activation and on version bumps.
	 */
	public static function create_hashes_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::hashes_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			file_hash VARCHAR(32) NOT NULL,
			file_size BIGINT(20) UNSIGNED DEFAULT 0,
			hashed_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY file_hash (file_hash)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Drop the file-hashes table entirely (used on uninstall).
	 */
	public static function drop_hashes_table() {
		global $wpdb;
		$table_name = self::hashes_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
	}

	/**
	 * Truncate all stored hashes (used when starting a fresh duplicate scan).
	 */
	public static function clear_hashes() {
		global $wpdb;
		$table_name = self::hashes_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );
	}

	/**
	 * Insert or update the stored hash for a single attachment.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $hash          MD5 hash of the file contents.
	 * @param int    $file_size     File size in bytes.
	 */
	public static function upsert_hash( $attachment_id, $hash, $file_size ) {
		global $wpdb;
		$table_name = self::hashes_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table_name} (attachment_id, file_hash, file_size, hashed_at) " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"VALUES (%d, %s, %d, %s) " .
				"ON DUPLICATE KEY UPDATE file_hash = %s, file_size = %d, hashed_at = %s",
				$attachment_id,
				$hash,
				$file_size,
				current_time( 'mysql' ),
				$hash,
				$file_size,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Remove hash rows for the given attachment IDs (after deletion).
	 *
	 * @param array $attachment_ids Array of attachment IDs.
	 */
	public static function delete_hash_results( $attachment_ids ) {
		global $wpdb;
		$table_name = self::hashes_table_name();

		if ( empty( $attachment_ids ) ) {
			return;
		}

		$attachment_ids = array_map( 'absint', $attachment_ids );
		$placeholders   = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				"DELETE FROM {$table_name} WHERE attachment_id IN ({$placeholders})",
				$attachment_ids
			)
		);
	}

	/**
	 * Get a page of duplicate groups (file hashes shared by 2+ attachments),
	 * ordered by wasted space (the size that would be reclaimed by keeping
	 * just one copy) descending, so the biggest opportunities surface first.
	 *
	 * @param int $per_page Groups per page.
	 * @param int $page     Current page (1-indexed).
	 * @return array
	 */
	public static function get_duplicate_groups( $per_page = 10, $page = 1 ) {
		global $wpdb;
		$table_name = self::hashes_table_name();
		$offset     = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT file_hash, COUNT(*) as cnt, MAX(file_size) as file_size, ( ( COUNT(*) - 1 ) * MAX(file_size) ) as wasted " .
				"FROM {$table_name} " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"GROUP BY file_hash " .
				"HAVING cnt > 1 " .
				"ORDER BY wasted DESC " .
				"LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		$groups = array();
		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
			$member_ids = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT attachment_id FROM {$table_name} WHERE file_hash = %s ORDER BY attachment_id ASC",
					$row->file_hash
				)
			);

			$groups[] = array(
				'hash'           => $row->file_hash,
				'count'          => (int) $row->cnt,
				'file_size'      => (int) $row->file_size,
				'wasted'         => (int) $row->wasted,
				'attachment_ids' => array_map( 'absint', $member_ids ),
			);
		}

		return $groups;
	}

	/**
	 * Count total duplicate groups (distinct hashes shared by 2+ files).
	 *
	 * @return int
	 */
	public static function count_duplicate_groups() {
		global $wpdb;
		$table_name = self::hashes_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM ( SELECT file_hash " .
			"FROM {$table_name} " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"GROUP BY file_hash HAVING COUNT(*) > 1 ) as hkmo_dup_groups"
		);
	}

	/**
	 * Total bytes reclaimable by keeping only one copy from every duplicate group.
	 *
	 * @return int
	 */
	public static function get_duplicate_reclaimable_size() {
		global $wpdb;
		$table_name = self::hashes_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = $wpdb->get_var(
			"SELECT SUM(wasted) FROM ( " .
				"SELECT ( ( COUNT(*) - 1 ) * MAX(file_size) ) as wasted " .
				"FROM {$table_name} " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"GROUP BY file_hash " .
				"HAVING COUNT(*) > 1 " .
			") as hkmo_dup_sizes"
		);

		return (int) $total;
	}

	/**
	 * Get every attachment ID currently sharing a given file hash. Used to
	 * verify deletion requests server-side rather than trusting group
	 * membership reported by the browser.
	 *
	 * @param string $hash File hash.
	 * @return array
	 */
	public static function get_group_members_for_hash( $hash ) {
		global $wpdb;
		$table_name = self::hashes_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT attachment_id FROM {$table_name} WHERE file_hash = %s",
				$hash
			)
		);

		return array_map( 'absint', $ids );
	}

	/**
	 * Get the currently stored hash for a single attachment, or null if it
	 * hasn't been hashed (yet).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null
	 */
	public static function get_hash_for_attachment( $attachment_id ) {
		global $wpdb;
		$table_name = self::hashes_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT file_hash FROM {$table_name} WHERE attachment_id = %d",
				$attachment_id
			)
		);
	}

	/**
	 * Whether any duplicate-finder data exists at all (used to decide whether
	 * the Duplicates tab should show the "run a scan" prompt or results).
	 *
	 * @return bool
	 */
	public static function has_hash_data() {
		global $wpdb;
		$table_name = self::hashes_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$table_name} LIMIT 1" );
	}
}
