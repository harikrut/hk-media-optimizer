<?php
/**
 * Scheduled (cron-based) automatic scanning, with an optional email report.
 *
 * A scheduled run still goes through HKMO_Scanner::scan_batch() one batch at
 * a time — the exact same code path the browser's AJAX loop uses — so the
 * "never do more work than necessary in a single request" design holds for
 * cron the same way it does for manual scans.
 *
 * Two different background engines can drive things, picked automatically:
 *
 * - Action Scheduler (if some other active plugin — WooCommerce, etc. — has
 *   loaded the library): far more reliable for "keep ticking every minute
 *   until a big job is done", since it runs its own async queue and isn't
 *   solely dependent on a site visitor triggering wp-cron.php.
 * - WordPress core cron: used automatically whenever Action Scheduler isn't
 *   available, exactly as before.
 *
 * Either way, a single "healthcheck" event re-fires roughly once a minute
 * for as long as ANY scan (scheduled or manual) is in progress, processing
 * one more time-budgeted slice of batches each time. That's what lets a
 * 1,000–5,000+ attachment library finish over several minutes in the
 * background instead of requiring one impossible single request.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HKMO_Scheduler {

	/**
	 * Recurring cron hook that kicks off a new scheduled scan.
	 */
	const CRON_HOOK = 'hkmo_scheduled_scan';

	/**
	 * Recurring "healthcheck" hook. Fires roughly once a minute for as long
	 * as a scan (scheduled OR manual) is in progress, processing one more
	 * time-budgeted slice of batches each time.
	 */
	const HEALTHCHECK_HOOK = 'hkmo_scan_healthcheck';

	/**
	 * Single-event continuation hook used by older versions of this plugin.
	 * Still handled (routed into the same healthcheck logic) so a stray
	 * event already scheduled on an upgrading site doesn't just do nothing.
	 */
	const CONTINUE_HOOK = 'hkmo_scheduled_scan_continue';

	/**
	 * Soft time budget (seconds) for how long a single healthcheck tick will
	 * keep processing batches before yielding, so it stays well clear of
	 * PHP/host execution time limits on shared hosting.
	 *
	 * @var int
	 */
	const TIME_BUDGET = 20;

	/**
	 * How often the healthcheck re-fires while a scan is in progress.
	 *
	 * @var int
	 */
	const HEALTHCHECK_INTERVAL = MINUTE_IN_SECONDS;

	/**
	 * Register WordPress hooks.
	 */
	public function init() {
		add_filter( 'cron_schedules', array( $this, 'add_custom_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_scan' ) );
		add_action( self::HEALTHCHECK_HOOK, array( $this, 'run_healthcheck' ) );
		add_action( self::CONTINUE_HOOK, array( $this, 'run_healthcheck' ) );

		// Keep the report email / "last run" bookkeeping decoupled from the
		// scanner itself — it just announces "a scan finished", we decide
		// here what that means for scheduling and reporting.
		add_action( 'hkmo_scan_finished', array( $this, 'on_scan_finished' ) );
	}

	/**
	 * Whether Action Scheduler (bundled by WooCommerce or any other active
	 * plugin) is loaded and ready to use. Checked live rather than cached,
	 * since plugin activation order can change between requests.
	 *
	 * @return bool
	 */
	public static function is_action_scheduler_available() {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_unschedule_action' )
			&& function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Human-readable label for whichever engine is currently in use —
	 * surfaced in the Settings screen so the admin knows what's running
	 * their scheduled scans.
	 *
	 * @return string
	 */
	public static function get_engine_label() {
		return self::is_action_scheduler_available()
			? __( 'Action Scheduler', 'hk-media-optimizer' )
			: __( 'WordPress Cron', 'hk-media-optimizer' );
	}

	/**
	 * Add weekly/monthly/every-minute intervals, since WordPress core only
	 * ships with hourly, twicedaily, and daily by default. The per-minute
	 * interval is only ever used as the WP-Cron fallback for the healthcheck
	 * when Action Scheduler isn't available.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public function add_custom_schedules( $schedules ) {
		$schedules['hkmo_weekly']  = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly (HK Media Optimizer)', 'hk-media-optimizer' ),
		);
		$schedules['hkmo_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once Monthly (HK Media Optimizer)', 'hk-media-optimizer' ),
		);
		$schedules['hkmo_every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every Minute (HK Media Optimizer healthcheck)', 'hk-media-optimizer' ),
		);
		return $schedules;
	}

	/**
	 * Convert a stored frequency key into a plain interval in seconds, for
	 * Action Scheduler's recurring action (which takes seconds rather than
	 * a registered schedule name).
	 *
	 * @param string $frequency 'daily', 'hkmo_weekly', or 'hkmo_monthly'.
	 * @return int
	 */
	private static function frequency_to_seconds( $frequency ) {
		switch ( $frequency ) {
			case 'hkmo_weekly':
				return WEEK_IN_SECONDS;
			case 'hkmo_monthly':
				return 30 * DAY_IN_SECONDS;
			default:
				return DAY_IN_SECONDS;
		}
	}

	/**
	 * (Re)schedule the recurring scan event to match current settings.
	 * Safe to call any time settings are saved — clears any existing
	 * schedule first so changing frequency doesn't stack duplicate events.
	 */
	public static function reschedule() {
		self::unschedule();

		$enabled = HKMO_Settings::get( 'enable_scheduled_scan' );

		if ( ! $enabled ) {
			return;
		}

		// Start a minute out rather than immediately, so saving settings
		// during a busy traffic period doesn't trigger a scan right away.
		$start_at = time() + MINUTE_IN_SECONDS;

		$frequency = HKMO_Settings::get( 'scheduled_scan_frequency' );
		$allowed   = array( 'daily', 'hkmo_weekly', 'hkmo_monthly' );
		if ( ! in_array( $frequency, $allowed, true ) ) {
			$frequency = 'daily';
		}

		$as_available = self::is_action_scheduler_available();

		if ( $as_available ) {
			try {
				$action_id = as_schedule_recurring_action(
					$start_at,
					self::frequency_to_seconds( $frequency ),
					self::CRON_HOOK,
					array(),
					'hkmo'
				);
			} catch ( Exception $e ) {
				// Action Scheduler exception gracefully caught
			}
			return;
		}

		wp_schedule_event( $start_at, $frequency, self::CRON_HOOK );
	}

	/**
	 * Clear the recurring scan event, any legacy continuation event, and
	 * the healthcheck — across both WP-Cron and Action Scheduler, so
	 * switching engines (e.g. activating/deactivating WooCommerce) never
	 * leaves a stale event behind on either system.
	 */
	public static function unschedule() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );

		$next_continue = wp_next_scheduled( self::CONTINUE_HOOK );
		if ( $next_continue ) {
			wp_unschedule_event( $next_continue, self::CONTINUE_HOOK );
		}
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );

		self::stop_healthcheck();

		if ( self::is_action_scheduler_available() && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK );
		}
	}

	/**
	 * Make sure the minute-by-minute healthcheck is running. Called whenever
	 * a scan starts — scheduled OR manual — so a large library keeps making
	 * progress in the background even with no browser tab open, rather than
	 * depending on one request to finish a library that's too big to finish
	 * in one shot.
	 */
	public static function start_healthcheck() {
		if ( self::is_action_scheduler_available() ) {
			if ( ! as_has_scheduled_action( self::HEALTHCHECK_HOOK ) ) {
				as_schedule_recurring_action(
					time() + self::HEALTHCHECK_INTERVAL,
					self::HEALTHCHECK_INTERVAL,
					self::HEALTHCHECK_HOOK,
					array(),
					'hkmo'
				);
			}
			return;
		}

		if ( ! wp_next_scheduled( self::HEALTHCHECK_HOOK ) ) {
			wp_schedule_event( time() + self::HEALTHCHECK_INTERVAL, 'hkmo_every_minute', self::HEALTHCHECK_HOOK );
		}
	}

	/**
	 * Stop the healthcheck — called once a scan finishes, and as a safety
	 * net if the healthcheck ever fires and finds nothing left to do.
	 */
	public static function stop_healthcheck() {
		if ( self::is_action_scheduler_available() && function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::HEALTHCHECK_HOOK );
		}

		$next = wp_next_scheduled( self::HEALTHCHECK_HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::HEALTHCHECK_HOOK );
		}
		wp_clear_scheduled_hook( self::HEALTHCHECK_HOOK );
	}

	/**
	 * Timestamp of the next scheduled scan, checking whichever engine is
	 * actually in use. Used by the Settings screen status display.
	 *
	 * @return int|false
	 */
	public static function get_next_scheduled_run() {
		if ( self::is_action_scheduler_available() && function_exists( 'as_next_scheduled_action' ) ) {
			// First try with exact args and group used in reschedule()
			$next_exact = as_next_scheduled_action( self::CRON_HOOK, array(), 'hkmo' );
			$next_hook = as_next_scheduled_action( self::CRON_HOOK );

			if ( $next_exact ) {
				return (int) $next_exact;
			}
			if ( $next_hook ) {
				return (int) $next_hook;
			}
		}
		return wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Entry point fired by the recurring cron/Action Scheduler event.
	 */
	public function run_scheduled_scan() {
		if ( ! HKMO_Settings::get( 'enable_scheduled_scan' ) ) {
			return;
		}

		// Don't collide with a scan that's already running (manual, or a
		// previous scheduled run still catching up) — the healthcheck is
		// already ticking for it, so just make sure it's running and bail.
		if ( get_transient( 'hkmo_scan_running' ) ) {
			self::start_healthcheck();
			return;
		}

		$scanner = new HKMO_Scanner();
		$scanner->start_scan( 'scheduled' );
		update_option( 'hkmo_scheduled_scan_offset', 0, false );

		self::start_healthcheck();
		$this->process_time_budget();
	}

	/**
	 * Entry point fired by the recurring healthcheck (and the legacy
	 * single-event continuation hook). Keeps a large scan moving forward
	 * roughly once a minute, regardless of whether it was started manually
	 * or by the scheduled-scan cron event.
	 */
	public function run_healthcheck() {
		if ( ! get_transient( 'hkmo_scan_running' ) ) {
			// Nothing left to do — most likely the scan already finished
			// (and cleared the healthcheck), but if an event somehow
			// survives past that, clear it now rather than ticking forever.
			self::stop_healthcheck();
			return;
		}

		$this->process_time_budget();
	}

	/**
	 * Process batches until either the scan finishes or this invocation's
	 * time budget runs out, whichever comes first.
	 */
	private function process_time_budget() {
		$scanner    = new HKMO_Scanner();
		$offset     = (int) get_option( 'hkmo_scheduled_scan_offset', 0 );
		$started_at = time();
		$progress   = array( 'done' => false );

		do {
			$progress = $scanner->scan_batch( $offset );
			$offset   = isset( $progress['processed'] ) ? (int) $progress['processed'] : $offset;
			update_option( 'hkmo_scheduled_scan_offset', $offset, false );
		} while ( empty( $progress['done'] ) && ( time() - $started_at ) < self::TIME_BUDGET );

		// If not done, nothing else to schedule here — the recurring
		// healthcheck is already in place and will simply fire again in
		// about a minute and pick up right where this left off.
	}

	/**
	 * Fired via the 'hkmo_scan_finished' action once a scan (manual or
	 * scheduled) has processed every attachment. Stops the healthcheck and,
	 * only for scans that the schedule itself kicked off, records the run
	 * time and sends the report email.
	 */
	public function on_scan_finished() {
		self::stop_healthcheck();
		delete_option( 'hkmo_scheduled_scan_offset' );

		$origin = get_option( 'hkmo_scan_origin', 'manual' );
		delete_option( 'hkmo_scan_origin' );

		if ( 'scheduled' === $origin ) {
			update_option( 'hkmo_last_scheduled_scan', current_time( 'mysql' ), false );
			$this->maybe_send_report();
		}
	}

	/**
	 * Email a short summary of the just-completed scan, if report emails are enabled.
	 */
	private function maybe_send_report() {
		if ( ! HKMO_Settings::get( 'scheduled_scan_notify' ) ) {
			return;
		}

		$to = HKMO_Settings::get( 'scheduled_scan_email' );
		$to = $to ? $to : get_option( 'admin_email' );

		$this->send_report_email( $to );
	}

	/**
	 * Build and send the scan-report email.
	 *
	 * @param string $to Recipient email address.
	 * @return bool Whether wp_mail() reported success.
	 */
	public function send_report_email( $to ) {
		$unused_count = HKMO_DB::count_results( 'unused' );
		$used_count   = HKMO_DB::count_results( 'used' );
		$reclaimable  = HKMO_DB::get_reclaimable_size();
		$site_name    = get_bloginfo( 'name' );

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] HK Media Optimizer scan report', 'hk-media-optimizer' ), $site_name );

		$lines   = array();
		$lines[] = sprintf(
			/* translators: %s: site name */
			__( 'Your scheduled media scan on %s just finished.', 'hk-media-optimizer' ),
			$site_name
		);
		$lines[] = '';
		/* translators: %s: number of unused files found */
		$lines[] = sprintf( __( 'Unused files found: %s', 'hk-media-optimizer' ), number_format_i18n( $unused_count ) );
		/* translators: %s: number of in-use files */
		$lines[] = sprintf( __( 'In-use files: %s', 'hk-media-optimizer' ), number_format_i18n( $used_count ) );
		/* translators: %s: human-readable reclaimable disk space */
		$lines[] = sprintf( __( 'Space you could reclaim: %s', 'hk-media-optimizer' ), size_format( $reclaimable, 1 ) );
		$lines[] = '';
		$lines[] = __( 'Nothing was deleted automatically — review and delete unused files from the Media Optimizer screen:', 'hk-media-optimizer' );
		$lines[] = admin_url( 'admin.php?page=hk-media-optimizer' );

		$message = implode( "\n", $lines );

		return wp_mail( $to, $subject, $message );
	}
}
