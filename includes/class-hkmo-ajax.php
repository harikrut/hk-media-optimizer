<?php
/**
 * AJAX endpoint handlers.
 *
 * Every handler enforces a nonce check and a capability check
 * (manage_options) before doing any work, per WordPress.org plugin
 * security guidelines.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HKMO_Ajax {

	/**
	 * Register AJAX hooks. Only fires for logged-in admin users since
	 * this plugin has no front-end/public-facing functionality.
	 */
	public function init() {
		add_action( 'wp_ajax_hkmo_start_scan', array( $this, 'handle_start_scan' ) );
		add_action( 'wp_ajax_hkmo_scan_batch', array( $this, 'handle_scan_batch' ) );
		add_action( 'wp_ajax_hkmo_get_scan_status', array( $this, 'handle_get_scan_status' ) );
		add_action( 'wp_ajax_hkmo_get_results', array( $this, 'handle_get_results' ) );
		add_action( 'wp_ajax_hkmo_delete_attachments', array( $this, 'handle_delete_attachments' ) );
		add_action( 'wp_ajax_hkmo_cancel_scan', array( $this, 'handle_cancel_scan' ) );

		// Duplicate finder.
		add_action( 'wp_ajax_hkmo_start_duplicate_scan', array( $this, 'handle_start_duplicate_scan' ) );
		add_action( 'wp_ajax_hkmo_duplicate_scan_batch', array( $this, 'handle_duplicate_scan_batch' ) );
		add_action( 'wp_ajax_hkmo_cancel_duplicate_scan', array( $this, 'handle_cancel_duplicate_scan' ) );
		add_action( 'wp_ajax_hkmo_get_duplicate_groups', array( $this, 'handle_get_duplicate_groups' ) );
		add_action( 'wp_ajax_hkmo_delete_duplicates', array( $this, 'handle_delete_duplicates' ) );

		// Scheduled scan test email.
		add_action( 'wp_ajax_hkmo_send_test_report', array( $this, 'handle_send_test_report' ) );
	}

	/**
	 * Shared guard: verifies nonce and capability for every AJAX action here.
	 * Dies with a JSON error response if either check fails.
	 */
	private function verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'hk-media-optimizer' ) ), 403 );
		}

		check_ajax_referer( 'hkmo_admin_nonce', 'nonce' );
	}

	/**
	 * Start a fresh scan: clears previous results, returns total count.
	 */
	public function handle_start_scan() {
		$this->verify_request();

		// A scan (manual or scheduled/cron-driven) is already in progress —
		// starting another would race with it over the same results table
		// and progress option. Tell the browser to just pick up the
		// existing one instead via hkmo_get_scan_status.
		if ( get_transient( 'hkmo_scan_running' ) ) {
			wp_send_json_error(
				array(
					'message'          => __( 'A scan is already in progress.', 'hk-media-optimizer' ),
					'already_running'  => true,
				)
			);
		}

		$scanner  = new HKMO_Scanner();
		$progress = $scanner->start_scan( 'manual' );

		// Safety net: if this browser tab closes or the request gets
		// interrupted partway through, the minute-by-minute healthcheck
		// (Action Scheduler if available, otherwise WP-Cron) keeps the
		// scan moving forward in the background until it finishes.
		HKMO_Scheduler::start_healthcheck();

		wp_send_json_success( $progress );
	}

	/**
	 * Process a single batch and return updated progress.
	 * Called repeatedly by the browser in a loop until 'done' is true.
	 */
	public function handle_scan_batch() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$scanner  = new HKMO_Scanner();
		$progress = $scanner->scan_batch( $offset );

		wp_send_json_success( $progress );
	}

	/**
	 * Report whether a scan is currently running (started either by the
	 * browser or by the cron/Action Scheduler healthcheck in the
	 * background) and how far along it is. Polled by the Scanner screen on
	 * load/reload so a scan that's progressing server-side — e.g. a
	 * scheduled scan working through a large library — still shows a live
	 * progress bar instead of looking like nothing is happening.
	 */
	public function handle_get_scan_status() {
		$this->verify_request();

		wp_send_json_success( HKMO_Scanner::get_status() );
	}

	/**
	 * Return a page of results for the results table (used after scan completes,
	 * and also used for the filterable/paginated view).
	 */
	public function handle_get_results() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$status   = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'unused';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$per_page = 50;

		$allowed_statuses = array( 'used', 'unused', 'all' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'unused';
		}

		$rows  = HKMO_DB::get_results( $status, $per_page, $page );
		$total = HKMO_DB::count_results( $status );

		$items = array();
		foreach ( $rows as $row ) {
			$attachment_id = (int) $row->attachment_id;
			$thumb         = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
			$title         = get_the_title( $attachment_id );
			$filename      = wp_basename( get_attached_file( $attachment_id ) );

			$items[] = array(
				'id'        => $attachment_id,
				'title'     => $title ? $title : $filename,
				'filename'  => $filename,
				'thumb'     => $thumb ? $thumb[0] : '',
				'status'    => $row->status,
				'reason'    => $row->reason,
				'file_size' => (int) $row->file_size,
				'edit_link' => get_edit_post_link( $attachment_id, 'raw' ),
			);
		}

		wp_send_json_success(
			array(
				'items'           => $items,
				'total'           => $total,
				'page'            => $page,
				'per_page'        => $per_page,
				'reclaimable'     => HKMO_DB::get_reclaimable_size(),
				'unused_count'    => HKMO_DB::count_results( 'unused' ),
				'used_count'      => HKMO_DB::count_results( 'used' ),
			)
		);
	}

	/**
	 * Delete the submitted list of attachment IDs, after re-verifying
	 * server-side that each one is actually marked unused.
	 */
	public function handle_delete_attachments() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No attachments selected.', 'hk-media-optimizer' ) ) );
		}

		$cleaner = new HKMO_Optimizer();
		$result  = $cleaner->delete_attachments( $ids );

		wp_send_json_success( $result );
	}

	/**
	 * Cancel an in-progress scan (clears the running lock so the UI can
	 * let the user start over without waiting for a stale lock to expire).
	 */
	public function handle_cancel_scan() {
		$this->verify_request();

		delete_transient( 'hkmo_scan_running' );
		delete_option( 'hkmo_scan_origin' );
		delete_option( 'hkmo_scheduled_scan_offset' );
		HKMO_Scheduler::stop_healthcheck();

		$progress         = get_option( 'hkmo_scan_progress', array() );
		$progress['done'] = true;
		update_option( 'hkmo_scan_progress', $progress, false );

		wp_send_json_success( array( 'cancelled' => true ) );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Duplicate finder.
	 * ───────────────────────────────────────────────────────────────────── */

	/**
	 * Start a fresh duplicate-file hash scan.
	 */
	public function handle_start_duplicate_scan() {
		$this->verify_request();

		$finder   = new HKMO_Duplicate_Finder();
		$progress = $finder->start_scan();

		wp_send_json_success( $progress );
	}

	/**
	 * Hash one batch of attachments. Called repeatedly by the browser until done.
	 */
	public function handle_duplicate_scan_batch() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$finder   = new HKMO_Duplicate_Finder();
		$progress = $finder->scan_batch( $offset );

		wp_send_json_success( $progress );
	}

	/**
	 * Cancel an in-progress duplicate scan.
	 */
	public function handle_cancel_duplicate_scan() {
		$this->verify_request();

		delete_transient( 'hkmo_dup_scan_running' );
		$progress         = get_option( 'hkmo_dup_scan_progress', array() );
		$progress['done'] = true;
		update_option( 'hkmo_dup_scan_progress', $progress, false );

		wp_send_json_success( array( 'cancelled' => true ) );
	}

	/**
	 * Return a page of duplicate groups, each with enough info per member
	 * (thumbnail, title, filename, edit link) to render the comparison UI.
	 */
	public function handle_get_duplicate_groups() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$per_page = 10;

		$groups       = HKMO_DB::get_duplicate_groups( $per_page, $page );
		$total_groups = HKMO_DB::count_duplicate_groups();

		$items = array();
		foreach ( $groups as $group ) {
			$members = array();
			foreach ( $group['attachment_ids'] as $attachment_id ) {
				$thumb    = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
				$title    = get_the_title( $attachment_id );
				$filename = wp_basename( get_attached_file( $attachment_id ) );

				$members[] = array(
					'id'        => $attachment_id,
					'title'     => $title ? $title : $filename,
					'filename'  => $filename,
					'thumb'     => $thumb ? $thumb[0] : '',
					'edit_link' => get_edit_post_link( $attachment_id, 'raw' ),
					'uploaded'  => get_the_date( get_option( 'date_format' ), $attachment_id ),
				);
			}

			$items[] = array(
				'hash'      => $group['hash'],
				'count'     => $group['count'],
				'file_size' => $group['file_size'],
				'wasted'    => $group['wasted'],
				'members'   => $members,
			);
		}

		wp_send_json_success(
			array(
				'groups'       => $items,
				'total_groups' => $total_groups,
				'per_page'     => $per_page,
				'page'         => $page,
				'reclaimable'  => HKMO_DB::get_duplicate_reclaimable_size(),
				'has_data'     => HKMO_DB::has_hash_data(),
			)
		);
	}

	/**
	 * Delete the submitted list of duplicate attachment IDs. Server-side
	 * verification (see HKMO_Optimizer::delete_duplicate_attachments()) makes
	 * sure at least one copy from every group always survives.
	 */
	public function handle_delete_duplicates() {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids );

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No attachments selected.', 'hk-media-optimizer' ) ) );
		}

		$cleaner = new HKMO_Optimizer();
		$result  = $cleaner->delete_duplicate_attachments( $ids );

		wp_send_json_success( $result );
	}

	/* ─────────────────────────────────────────────────────────────────────
	 * Scheduled scan.
	 * ───────────────────────────────────────────────────────────────────── */

	/**
	 * Send a one-off test copy of the scan-report email to the currently
	 * configured recipient, so admins can confirm delivery without waiting
	 * for the next scheduled run.
	 */
	public function handle_send_test_report() {
		$this->verify_request();

		// Prefer whatever the admin currently has typed in the field (even if
		// they haven't clicked "Save Settings" yet) over the stored value,
		// so the test actually reflects what's on screen.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$typed = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$to    = $typed ? $typed : HKMO_Settings::get( 'scheduled_scan_email' );
		$to    = $to ? $to : get_option( 'admin_email' );

		if ( ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => __( 'No valid recipient email address is configured.', 'hk-media-optimizer' ) ) );
		}

		$scheduler = new HKMO_Scheduler();
		$sent      = $scheduler->send_report_email( $to );

		if ( $sent ) {
			/* translators: %s: recipient email address */
			wp_send_json_success( array( 'message' => sprintf( __( 'Test report sent to %s.', 'hk-media-optimizer' ), $to ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'wp_mail() could not send the test report. Check your site\'s email configuration.', 'hk-media-optimizer' ) ) );
		}
	}
}
