<?php
/**
 * Core scanning engine.
 *
 * Design goals for lightweight operation:
 * - Never loads all attachments into memory at once; works on a small batch
 *   per request (size configurable via settings, default 20).
 * - Uses targeted SQL (with proper $wpdb->prepare) instead of WP_Query +
 *   get_post_meta loops wherever possible, since WP_Query with 'posts_per_page'
 *   => -1 across thousands of rows is one of the most common causes of memory
 *   exhaustion in plugins like this.
 * - Caches expensive "global" lookups (widgets, customizer values, ACF field
 *   groups) once per batch request rather than once per attachment.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HKMO_Scanner {

	/**
	 * Cached set of media IDs referenced anywhere in widgets, populated once
	 * per scan batch (not once per attachment) to avoid repeated work.
	 *
	 * @var array|null
	 */
	private $widget_media_ids = null;

	/**
	 * Cached set of media IDs referenced in the customizer / site icon / logo.
	 *
	 * @var array|null
	 */
	private $customizer_media_ids = null;

	/**
	 * Start a new scan: clears previous results and resets progress markers.
	 *
	 * @param string $origin Who kicked this off — 'manual' (browser button)
	 *                        or 'scheduled' (cron / Action Scheduler). Used
	 *                        later to decide whether a finished scan should
	 *                        update "last scheduled run" / send the report
	 *                        email, and is shown back to the browser so a
	 *                        reloaded page can explain why a scan is running.
	 * @return array Initial progress info (total attachments to scan).
	 */
	public function start_scan( $origin = 'manual' ) {
		global $wpdb;

		HKMO_DB::clear_results();

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

		$origin = ( 'scheduled' === $origin ) ? 'scheduled' : 'manual';

		update_option( 'hkmo_scan_progress', $progress, false );
		update_option( 'hkmo_scan_origin', $origin, false );
		set_transient( 'hkmo_scan_running', 1, HOUR_IN_SECONDS );

		/**
		 * Fires when a new scan starts (manual or scheduled).
		 *
		 * @param string $origin 'manual' or 'scheduled'.
		 */
		do_action( 'hkmo_scan_started', $origin );

		return $progress;
	}

	/**
	 * Current scan status, regardless of what started it — used by the
	 * "is a scan running?" AJAX check so the admin screen can pick back up
	 * and show a live progress bar after a page reload, even when the
	 * batches are actually being driven by cron / Action Scheduler in the
	 * background rather than by this browser tab.
	 *
	 * @return array
	 */
	public static function get_status() {
		$progress = get_option( 'hkmo_scan_progress', array() );
		$running  = (bool) get_transient( 'hkmo_scan_running' );

		return array(
			'running'   => $running,
			'origin'    => get_option( 'hkmo_scan_origin', 'manual' ),
			'total'     => isset( $progress['total'] ) ? (int) $progress['total'] : 0,
			'processed' => isset( $progress['processed'] ) ? (int) $progress['processed'] : 0,
			'done'      => $running ? false : ! empty( $progress['done'] ),
		);
	}

	/**
	 * Process one batch of attachments starting at the given offset.
	 * This is the function called repeatedly by AJAX requests from the
	 * browser until $done is true, AND by the scheduled-scan healthcheck
	 * in the background — so it's wrapped in a short-lived lock to make
	 * sure those two callers can never end up processing the very same
	 * batch at the same moment (which would double-count progress).
	 *
	 * @param int $offset Offset into the attachment list.
	 * @return array Updated progress info.
	 */
	public function scan_batch( $offset ) {
		if ( ! self::acquire_batch_lock() ) {
			// Someone else (the background healthcheck, or another open
			// browser tab) is mid-batch right now. Just hand back the
			// current progress unchanged — the caller's own retry/poll
			// loop will pick up the real progress on its next tick.
			return get_option( 'hkmo_scan_progress', array() );
		}

		try {
			return $this->process_batch( $offset );
		} finally {
			self::release_batch_lock();
		}
	}

	/**
	 * Acquire the short-lived batch-processing lock.
	 *
	 * @return bool True if the lock was acquired.
	 */
	private static function acquire_batch_lock() {
		if ( get_transient( 'hkmo_batch_lock' ) ) {
			return false;
		}
		set_transient( 'hkmo_batch_lock', 1, 30 );
		return true;
	}

	/**
	 * Release the batch-processing lock.
	 */
	private static function release_batch_lock() {
		delete_transient( 'hkmo_batch_lock' );
	}

	/**
	 * Actual batch-processing work, run while the batch lock is held.
	 *
	 * @param int $offset Offset into the attachment list.
	 * @return array Updated progress info.
	 */
	private function process_batch( $offset ) {
		global $wpdb;

		$batch_size = (int) HKMO_Settings::get( 'batch_size' );
		$batch_size = $batch_size > 0 ? $batch_size : 20;

		$attachment_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID ASC LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			)
		);

		if ( empty( $attachment_ids ) ) {
			$progress         = get_option( 'hkmo_scan_progress', array() );
			$progress['done'] = true;
			update_option( 'hkmo_scan_progress', $progress, false );
			delete_transient( 'hkmo_scan_running' );

			/**
			 * Fires once a scan (manual or scheduled) has processed every
			 * attachment. Used by HKMO_Scheduler to stop the recurring
			 * healthcheck and, for scheduled runs, send the report email.
			 *
			 * @param array $progress Final progress array.
			 */
			do_action( 'hkmo_scan_finished', $progress );

			return $progress;
		}

		// Warm caches that are expensive to build but reusable across the whole batch.
		$this->maybe_build_widget_cache();
		$this->maybe_build_customizer_cache();

		$whitelist  = array_map( 'absint', (array) HKMO_Settings::get( 'whitelist_ids' ) );
		$exclude_days = (int) HKMO_Settings::get( 'exclude_newer_than_days' );
		$exclude_mimes = (array) HKMO_Settings::get( 'exclude_mime_types' );
		$exclude_folders = (array) HKMO_Settings::get( 'exclude_folders' );

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$file_size     = $this->get_file_size( $attachment_id );

			if ( in_array( $attachment_id, $whitelist, true ) ) {
				$this->record( $attachment_id, 'used', __( 'Whitelisted by admin', 'hk-media-optimizer' ), $file_size );
				continue;
			}

			if ( $this->is_excluded_by_age( $attachment_id, $exclude_days ) ) {
				$this->record( $attachment_id, 'used', __( 'Recently uploaded (protected by safety window)', 'hk-media-optimizer' ), $file_size );
				continue;
			}

			if ( $this->is_excluded_by_mime( $attachment_id, $exclude_mimes ) ) {
				$this->record( $attachment_id, 'used', __( 'Excluded mime type', 'hk-media-optimizer' ), $file_size );
				continue;
			}

			if ( $this->is_excluded_by_folder( $attachment_id, $exclude_folders ) ) {
				$this->record( $attachment_id, 'used', __( 'Excluded folder', 'hk-media-optimizer' ), $file_size );
				continue;
			}

			$reason = $this->find_usage_reason( $attachment_id );

			if ( $reason ) {
				$this->record( $attachment_id, 'used', $reason, $file_size );
			} else {
				$this->record( $attachment_id, 'unused', '', $file_size );
			}
		}

		$progress              = get_option( 'hkmo_scan_progress', array() );
		$progress['processed'] = isset( $progress['processed'] ) ? $progress['processed'] + count( $attachment_ids ) : count( $attachment_ids );
		$progress['done']      = false;
		update_option( 'hkmo_scan_progress', $progress, false );

		return $progress;
	}

	/**
	 * Run through every enabled scan source for a single attachment and
	 * return the first matching reason found, or empty string if unused.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string
	 */
	private function find_usage_reason( $attachment_id ) {
		if ( HKMO_Settings::get( 'scan_attachment_parent' ) && $this->has_post_parent( $attachment_id ) ) {
			return __( 'Attached to a post/page', 'hk-media-optimizer' );
		}

		if ( HKMO_Settings::get( 'scan_featured_image' ) && $this->is_featured_image( $attachment_id ) ) {
			return __( 'Used as a featured image', 'hk-media-optimizer' );
		}

		if ( HKMO_Settings::get( 'scan_site_icon_logo' ) && $this->is_site_icon_or_logo( $attachment_id ) ) {
			return __( 'Used as site icon or logo', 'hk-media-optimizer' );
		}

		if ( HKMO_Settings::get( 'scan_customizer' ) && $this->customizer_media_ids && in_array( $attachment_id, $this->customizer_media_ids, true ) ) {
			return __( 'Referenced in Customizer settings', 'hk-media-optimizer' );
		}

		if ( HKMO_Settings::get( 'scan_widgets' ) && $this->widget_media_ids && in_array( $attachment_id, $this->widget_media_ids, true ) ) {
			return __( 'Referenced in a widget', 'hk-media-optimizer' );
		}

		if ( HKMO_Settings::get( 'scan_post_content' ) && $this->is_in_post_content( $attachment_id ) ) {
			return __( 'Referenced in post/page content', 'hk-media-optimizer' );
		}

		if ( HKMO_Settings::get( 'scan_post_meta' ) && $this->is_in_post_meta( $attachment_id ) ) {
			return __( 'Referenced in custom field / post meta', 'hk-media-optimizer' );
		}

		if ( HKMO_Settings::get( 'scan_acf_fields' ) && function_exists( 'acf_get_field_groups' ) && $this->is_in_acf_fields( $attachment_id ) ) {
			return __( 'Referenced in an ACF field', 'hk-media-optimizer' );
		}

		return '';
	}

	/**
	 * Check whether attachment has a post_parent (i.e. uploaded directly into a post/page).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function has_post_parent( $attachment_id ) {
		global $wpdb;
		$parent = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d",
				$attachment_id
			)
		);
		return ! empty( $parent );
	}

	/**
	 * Check if attachment is set as any post's featured image.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_featured_image( $attachment_id ) {
		global $wpdb;
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1",
				$attachment_id
			)
		);
		return ! empty( $found );
	}

	/**
	 * Check if attachment is used as the site icon or custom logo.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_site_icon_or_logo( $attachment_id ) {
		if ( (int) get_option( 'site_icon' ) === $attachment_id ) {
			return true;
		}
		$custom_logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id === $attachment_id ) {
			return true;
		}
		return false;
	}

	/**
	 * Build (once per batch) a cache of every media ID referenced by any
	 * theme_mod (covers custom backgrounds, header images, etc.) Cheap
	 * because theme_mods is a single small row, not a per-post query.
	 */
	private function maybe_build_customizer_cache() {
		if ( null !== $this->customizer_media_ids ) {
			return;
		}

		$ids = array();
		$theme_mods = get_option( 'theme_mods_' . get_stylesheet(), array() );

		if ( is_array( $theme_mods ) ) {
			$serialized = wp_json_encode( $theme_mods );
			// Extract numeric attachment IDs from common keys plus a broad
			// pattern match for any "_id" style value, then validate each
			// candidate against the attachment table later via in_array.
			preg_match_all( '/"(?:[a-zA-Z_]*_id|custom_logo)":\s*"?(\d+)"?/', (string) $serialized, $matches );
			if ( ! empty( $matches[1] ) ) {
				$ids = array_map( 'absint', $matches[1] );
			}
		}

		// Custom CSS attachments (rare, but covers background-image: url() set via Additional CSS attachments).
		$header_image = get_theme_mod( 'header_image_data' );
		if ( is_object( $header_image ) && isset( $header_image->attachment_id ) ) {
			$ids[] = absint( $header_image->attachment_id );
		}

		$this->customizer_media_ids = array_unique( $ids );
	}

	/**
	 * Build (once per batch) a cache of every media ID referenced inside
	 * any widget instance, including block-based widgets (which store an
	 * image block's ID in the block markup as "id":123).
	 */
	private function maybe_build_widget_cache() {
		if ( null !== $this->widget_media_ids ) {
			return;
		}

		global $wpdb;
		$ids = array();

		// Classic + block widgets are stored as options like widget_media_image-2, widget_block-3, etc.
		$widget_options = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget\_%'"
		);

		foreach ( $widget_options as $row ) {
			$value = $row->option_value;
			// Covers serialized PHP arrays (classic widgets store attachment_id, id) and
			// any literal numeric IDs found in block markup stored as text.
			preg_match_all( '/\[(?:attachment_id|id)\]\s*=>\s*(?:i:)?(\d+)/', (string) $value, $matches_serialized );
			preg_match_all( '/"id":\s*(\d+)/', (string) $value, $matches_json );

			if ( ! empty( $matches_serialized[1] ) ) {
				$ids = array_merge( $ids, array_map( 'absint', $matches_serialized[1] ) );
			}
			if ( ! empty( $matches_json[1] ) ) {
				$ids = array_merge( $ids, array_map( 'absint', $matches_json[1] ) );
			}
		}

		$this->widget_media_ids = array_unique( $ids );
	}

	/**
	 * Check whether the attachment URL or ID appears in any published/eligible
	 * post's content. Uses a single targeted LIKE query rather than looping
	 * through WP_Query results in PHP.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_in_post_content( $attachment_id ) {
		global $wpdb;

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! $url ) {
			return false;
		}

		// Search by filename rather than full URL so it still matches if the
		// site URL changed (migrations) or content uses a relative path.
		// Additionally, search for the attachment ID in common shortcode/block
		// patterns (e.g. ids="123", {"id":123}) to prevent false positives.
		$filename = wp_basename( $url );
		$id_str   = (string) $attachment_id;
		$statuses = $this->get_eligible_post_statuses();
		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$query = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"WHERE post_status IN ({$status_placeholders}) " . // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			"AND post_type != 'attachment' " .
			"AND ( " .
			"post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			"OR post_content LIKE %s " .
			") LIMIT 1",
			array_merge( $statuses, array(
				'%' . $wpdb->esc_like( $filename ) . '%',
				'%' . $wpdb->esc_like( '"' . $id_str . '"' ) . '%',
				'%' . $wpdb->esc_like( "'" . $id_str . "'" ) . '%',
				'%' . $wpdb->esc_like( '[' . $id_str . ']' ) . '%',
				'%' . $wpdb->esc_like( ',' . $id_str . ',' ) . '%',
				'%' . $wpdb->esc_like( '"' . $id_str . ',' ) . '%',
				'%' . $wpdb->esc_like( ',' . $id_str . '"' ) . '%',
				'%' . $wpdb->esc_like( "'" . $id_str . ',' ) . '%',
				'%' . $wpdb->esc_like( ',' . $id_str . "'" ) . '%',
				'%' . $wpdb->esc_like( '[' . $id_str . ',' ) . '%',
				'%' . $wpdb->esc_like( ',' . $id_str . ']' ) . '%',
				'%' . $wpdb->esc_like( 'id=' . $id_str . ' ' ) . '%',
				'%' . $wpdb->esc_like( 'id=' . $id_str . ']' ) . '%',
				'%' . $wpdb->esc_like( 'id=' . $id_str . ',' ) . '%',
				'%' . $wpdb->esc_like( '="' . $id_str . '"' ) . '%',
				'%' . $wpdb->esc_like( ':' . $id_str . '}' ) . '%',
				'%' . $wpdb->esc_like( ':' . $id_str . ',' ) . '%'
			) )
		);

		$found = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return ! empty( $found );
	}

	/**
	 * Check whether the attachment is referenced in any post meta value
	 * (covers many page builders and custom fields not handled by ACF check).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_in_post_meta( $attachment_id ) {
		global $wpdb;

		// Direct numeric ID match (covers custom fields storing just the attachment ID)
		// or serialized array containing the ID, or the filename appearing in a text value.
		$url = wp_get_attachment_url( $attachment_id );
		$filename = $url ? wp_basename( $url ) : '';

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT meta_id FROM {$wpdb->postmeta}
				WHERE meta_key NOT IN ('_thumbnail_id', '_wp_attached_file', '_wp_attachment_metadata')
				AND (
					meta_value = %s 
					OR meta_value LIKE %s 
					OR meta_value LIKE %s
					OR FIND_IN_SET(%s, meta_value) > 0
				)
				LIMIT 1",
				(string) $attachment_id,
				'%' . $wpdb->esc_like( ':"' . $attachment_id . '"' ) . '%', // serialized string value.
				$filename ? '%' . $wpdb->esc_like( $filename ) . '%' : '___no_match___',
				(string) $attachment_id
			)
		);

		return ! empty( $found );
	}

	/**
	 * Check ACF field values specifically, since ACF often stores image
	 * fields as serialized arrays with an 'ID' key rather than a flat value.
	 * Only runs if ACF is detected active, and only does a light check
	 * since the broader post_meta scan already covers most simple cases.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_in_acf_fields( $attachment_id ) {
		global $wpdb;

		$attachment_id = (int) $attachment_id;

		// ACF stores image/gallery field values either as a plain attachment ID
		// (meta_value = '42'), or as a serialized array/string containing the ID
		// (e.g. a gallery: a:2:{i:0;s:2:"42";...}). The leading-underscore "_field_key"
		// reference rows store the field's key, not the value, so we only need to
		// check the regular (non-underscore) meta rows here.
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT meta_id FROM {$wpdb->postmeta}
				WHERE meta_key NOT LIKE %s
				AND (meta_value = %s OR meta_value LIKE %s)
				LIMIT 1",
				$wpdb->esc_like( '_' ) . '%',
				(string) $attachment_id,
				'%"' . $attachment_id . '"%'
			)
		);

		return ! empty( $found );
	}

	/**
	 * Determine which post_status values should be searched, based on settings.
	 *
	 * @return array
	 */
	private function get_eligible_post_statuses() {
		$statuses = array( 'publish' );

		if ( HKMO_Settings::get( 'include_drafts' ) ) {
			$statuses[] = 'draft';
			$statuses[] = 'auto-draft';
		}
		if ( HKMO_Settings::get( 'include_pending' ) ) {
			$statuses[] = 'pending';
		}
		if ( HKMO_Settings::get( 'include_private' ) ) {
			$statuses[] = 'private';
		}
		if ( HKMO_Settings::get( 'include_trashed' ) ) {
			$statuses[] = 'trash';
		}

		return $statuses;
	}

	/**
	 * Check if attachment was uploaded within the protected safety window.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $days          Number of days to protect.
	 * @return bool
	 */
	private function is_excluded_by_age( $attachment_id, $days ) {
		if ( $days <= 0 ) {
			return false;
		}

		$post = get_post( $attachment_id );
		if ( ! $post ) {
			return false;
		}

		$uploaded_time = strtotime( $post->post_date_gmt );
		$cutoff        = time() - ( $days * DAY_IN_SECONDS );

		return $uploaded_time > $cutoff;
	}

	/**
	 * Check if attachment's mime type is in the exclude list.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $excluded_mimes List of mime type strings.
	 * @return bool
	 */
	private function is_excluded_by_mime( $attachment_id, $excluded_mimes ) {
		if ( empty( $excluded_mimes ) ) {
			return false;
		}
		$mime = get_post_mime_type( $attachment_id );
		return in_array( $mime, $excluded_mimes, true );
	}

	/**
	 * Check if attachment's file path matches any excluded folder pattern.
	 *
	 * @param int   $attachment_id    Attachment ID.
	 * @param array $excluded_folders List of folder path fragments.
	 * @return bool
	 */
	private function is_excluded_by_folder( $attachment_id, $excluded_folders ) {
		if ( empty( $excluded_folders ) ) {
			return false;
		}

		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $file ) {
			return false;
		}

		foreach ( $excluded_folders as $folder ) {
			if ( '' !== $folder && false !== strpos( $file, $folder ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the file size in bytes for an attachment, used for the
	 * "space you'll reclaim" total shown in the UI.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	private function get_file_size( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( $file && file_exists( $file ) ) {
			return (int) filesize( $file );
		}
		return 0;
	}

	/**
	 * Persist a single scan result.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $status        'used' or 'unused'.
	 * @param string $reason        Reason text.
	 * @param int    $file_size     File size in bytes.
	 */
	private function record( $attachment_id, $status, $reason, $file_size = 0 ) {
		HKMO_DB::upsert_result( $attachment_id, $status, $reason, $file_size );
	}
}
