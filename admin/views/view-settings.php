<?php // phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
/**
 * View: Settings page — modern UI.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s = HKMO_Settings::get_all();
?>
<div class="hkmo_wrap hkmo_settings-wrap">

	<!-- ── Header ───────────────────────────────────────────────────── -->
	<div class="hkmo_header">
		<div class="hkmo_header-left">
			<div class="hkmo_header-icon">
				<span class="dashicons dashicons-admin-settings"></span>
			</div>
			<div>
				<h1 class="hkmo_header-title"><?php esc_html_e( 'Settings', 'hk-media-optimizer' ); ?></h1>
				<p class="hkmo_header-subtitle"><?php esc_html_e( 'Configure how HK Media Optimizer scans and protects your files.', 'hk-media-optimizer' ); ?></p>
			</div>
		</div>
		<div class="hkmo_header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hk-media-optimizer' ) ); ?>" class="hkmo_btn hkmo_btn--secondary">
				<span class="dashicons dashicons-arrow-left-alt"></span>
				<?php esc_html_e( 'Back to Scanner', 'hk-media-optimizer' ); ?>
			</a>
		</div>
	</div>

	<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="hkmo_notice hkmo_notice--success">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( 'Settings saved successfully.', 'hk-media-optimizer' ); ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="hkmo_save_settings" />
		<?php wp_nonce_field( 'hkmo_save_settings' ); ?>

		<!-- ── Scan Sources ──────────────────────────────────────────── -->
		<div class="hkmo_settings-section">
			<div class="hkmo_section-header">
				<div class="hkmo_section-icon hkmo_section-icon--blue">
					<span class="dashicons dashicons-visibility"></span>
				</div>
				<div>
					<h2 class="hkmo_section-title"><?php esc_html_e( 'Where to Check for Media Usage', 'hk-media-optimizer' ); ?></h2>
					<p class="hkmo_section-desc"><?php esc_html_e( 'Disable sources you don\'t need to speed up scanning.', 'hk-media-optimizer' ); ?></p>
				</div>
			</div>
			<div class="hkmo_section-body">
				<div class="hkmo_toggle-grid">
					<label class="hkmo_toggle-row">
						<input type="checkbox" name="scan_post_content" value="1" <?php checked( $s['scan_post_content'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Post &amp; page content', 'hk-media-optimizer' ); ?></span>
					</label>
					<label class="hkmo_toggle-row">
						<input type="checkbox" name="scan_featured_image" value="1" <?php checked( $s['scan_featured_image'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Featured images', 'hk-media-optimizer' ); ?></span>
					</label>
					<label class="hkmo_toggle-row">
						<input type="checkbox" name="scan_attachment_parent" value="1" <?php checked( $s['scan_attachment_parent'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Attached directly to a post/page', 'hk-media-optimizer' ); ?></span>
					</label>
					<label class="hkmo_toggle-row">
						<input type="checkbox" name="scan_post_meta" value="1" <?php checked( $s['scan_post_meta'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Custom fields / post meta', 'hk-media-optimizer' ); ?></span>
					</label>
					<label class="hkmo_toggle-row">
						<input type="checkbox" name="scan_acf_fields" value="1" <?php checked( $s['scan_acf_fields'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Advanced Custom Fields (ACF)', 'hk-media-optimizer' ); ?></span>
					</label>
					<label class="hkmo_toggle-row">
						<input type="checkbox" name="scan_widgets" value="1" <?php checked( $s['scan_widgets'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Widgets (classic &amp; block-based)', 'hk-media-optimizer' ); ?></span>
					</label>
					<label class="hkmo_toggle-row">
						<input type="checkbox" name="scan_customizer" value="1" <?php checked( $s['scan_customizer'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Theme Customizer settings', 'hk-media-optimizer' ); ?></span>
					</label>
					<label class="hkmo_toggle-row">
						<input type="checkbox" name="scan_site_icon_logo" value="1" <?php checked( $s['scan_site_icon_logo'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Site icon &amp; custom logo', 'hk-media-optimizer' ); ?></span>
					</label>
				</div>
			</div>
		</div>

		<!-- ── Post Statuses ─────────────────────────────────────────── -->
		<div class="hkmo_settings-section">
			<div class="hkmo_section-header">
				<div class="hkmo_section-icon hkmo_section-icon--green">
					<span class="dashicons dashicons-media-document"></span>
				</div>
				<div>
					<h2 class="hkmo_section-title"><?php esc_html_e( 'Post Statuses to Include', 'hk-media-optimizer' ); ?></h2>
					<p class="hkmo_section-desc"><?php esc_html_e( 'Published content is always checked.', 'hk-media-optimizer' ); ?></p>
				</div>
			</div>
			<div class="hkmo_section-body">
				<div class="hkmo_toggle-grid">
					<label class="hkmo_toggle-row">
						<input type="checkbox" name="include_drafts" value="1" <?php checked( $s['include_drafts'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Drafts', 'hk-media-optimizer' ); ?></span>
					</label>
					<label class="hkmo_toggle-row">
						<input type="checkbox" name="include_pending" value="1" <?php checked( $s['include_pending'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Pending review', 'hk-media-optimizer' ); ?></span>
					</label>
					<label class="hkmo_toggle-row">
						<input type="checkbox" name="include_private" value="1" <?php checked( $s['include_private'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Private', 'hk-media-optimizer' ); ?></span>
					</label>
					<label class="hkmo_toggle-row">
						<input type="checkbox" name="include_trashed" value="1" <?php checked( $s['include_trashed'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Trashed posts (slower)', 'hk-media-optimizer' ); ?></span>
					</label>
				</div>
			</div>
		</div>

		<!-- ── Safety Rules ──────────────────────────────────────────── -->
		<div class="hkmo_settings-section">
			<div class="hkmo_section-header">
				<div class="hkmo_section-icon hkmo_section-icon--yellow">
					<span class="dashicons dashicons-shield"></span>
				</div>
				<div>
					<h2 class="hkmo_section-title"><?php esc_html_e( 'Safety Rules', 'hk-media-optimizer' ); ?></h2>
					<p class="hkmo_section-desc"><?php esc_html_e( 'Protect files from being flagged as unused.', 'hk-media-optimizer' ); ?></p>
				</div>
			</div>
			<div class="hkmo_section-body">

				<div class="hkmo_field">
					<label class="hkmo_field-label" for="exclude_newer_than_days">
						<?php esc_html_e( 'Protect recently uploaded files', 'hk-media-optimizer' ); ?>
					</label>
					<div class="hkmo_field-row">
						<input type="number" min="0" max="365" id="exclude_newer_than_days" name="exclude_newer_than_days"
							value="<?php echo esc_attr( $s['exclude_newer_than_days'] ); ?>"
							class="hkmo_input-number" />
						<span class="hkmo_input-unit"><?php esc_html_e( 'days', 'hk-media-optimizer' ); ?></span>
					</div>
					<p class="hkmo_field-hint">
						<?php esc_html_e( 'Files uploaded within this many days are always treated as "in use". Set to 0 to disable.', 'hk-media-optimizer' ); ?>
					</p>
				</div>

				<div class="hkmo_field">
					<label class="hkmo_field-label" for="exclude_folders">
						<?php esc_html_e( 'Exclude folders', 'hk-media-optimizer' ); ?>
					</label>
					<textarea id="exclude_folders" name="exclude_folders" rows="4"
						class="hkmo_textarea"
						placeholder="2024/05/logos&#10;branding"><?php echo esc_textarea( implode( "\n", (array) $s['exclude_folders'] ) ); ?></textarea>
					<p class="hkmo_field-hint">
						<?php esc_html_e( 'One path fragment per line. Files in matching folders are never flagged as unused.', 'hk-media-optimizer' ); ?>
					</p>
				</div>

				<div class="hkmo_field">
					<label class="hkmo_field-label" for="whitelist_ids">
						<?php esc_html_e( 'Whitelist specific attachment IDs', 'hk-media-optimizer' ); ?>
					</label>
					<textarea id="whitelist_ids" name="whitelist_ids" rows="2"
						class="hkmo_textarea"
						placeholder="123, 456, 789"><?php echo esc_textarea( implode( ', ', (array) $s['whitelist_ids'] ) ); ?></textarea>
					<p class="hkmo_field-hint">
						<?php esc_html_e( 'Comma-separated attachment IDs that should never be flagged as unused.', 'hk-media-optimizer' ); ?>
					</p>
				</div>

				<div class="hkmo_field">
					<span class="hkmo_field-label"><?php esc_html_e( 'Exclude mime types', 'hk-media-optimizer' ); ?></span>
					<?php
					$mime_options  = array(
						'application/pdf' => 'PDF',
						'application/zip' => 'ZIP',
						'audio/mpeg'       => 'MP3 / Audio',
						'video/mp4'        => 'MP4 / Video',
					);
					$excluded_mimes = (array) $s['exclude_mime_types'];
					?>
					<div class="hkmo_toggle-grid" style="margin-top:8px;">
						<?php foreach ( $mime_options as $mime => $label ) : ?>
							<label class="hkmo_toggle-row">
								<input type="checkbox" name="exclude_mime_types[]" value="<?php echo esc_attr( $mime ); ?>"
									<?php checked( in_array( $mime, $excluded_mimes, true ) ); ?> />
								<span class="hkmo_toggle-text"><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="hkmo_field-hint">
						<?php esc_html_e( 'Checked types are always treated as "in use" and skipped from deletion candidacy.', 'hk-media-optimizer' ); ?>
					</p>
				</div>

			</div>
		</div>

		<!-- ── Performance ───────────────────────────────────────────── -->
		<div class="hkmo_settings-section">
			<div class="hkmo_section-header">
				<div class="hkmo_section-icon hkmo_section-icon--blue">
					<span class="dashicons dashicons-performance"></span>
				</div>
				<div>
					<h2 class="hkmo_section-title"><?php esc_html_e( 'Performance', 'hk-media-optimizer' ); ?></h2>
					<p class="hkmo_section-desc"><?php esc_html_e( 'Tune scanning speed vs. server load.', 'hk-media-optimizer' ); ?></p>
				</div>
			</div>
			<div class="hkmo_section-body">
				<div class="hkmo_field">
					<label class="hkmo_field-label" for="batch_size">
						<?php esc_html_e( 'Batch size', 'hk-media-optimizer' ); ?>
					</label>
					<div class="hkmo_field-row">
						<input type="number" min="5" max="100" id="batch_size" name="batch_size"
							value="<?php echo esc_attr( $s['batch_size'] ); ?>"
							class="hkmo_input-number" />
						<span class="hkmo_input-unit"><?php esc_html_e( 'attachments / request', 'hk-media-optimizer' ); ?></span>
					</div>
					<p class="hkmo_field-hint">
						<?php esc_html_e( 'Range: 5–100. Lower on shared hosting; raise for faster scans on capable servers.', 'hk-media-optimizer' ); ?>
					</p>
				</div>
			</div>
		</div>

		<!-- ── Scheduled Scans ───────────────────────────────────────── -->
		<div class="hkmo_settings-section">
			<div class="hkmo_section-header">
				<div class="hkmo_section-icon hkmo_section-icon--green">
					<span class="dashicons dashicons-clock"></span>
				</div>
				<div>
					<h2 class="hkmo_section-title"><?php esc_html_e( 'Scheduled Scans', 'hk-media-optimizer' ); ?></h2>
					<p class="hkmo_section-desc"><?php esc_html_e( 'Automatically run a scan on a recurring schedule and get an email summary. Nothing is ever deleted automatically.', 'hk-media-optimizer' ); ?></p>
				</div>
			</div>
			<div class="hkmo_section-body">

				<label class="hkmo_toggle-row" style="max-width:420px;margin-bottom:18px;">
					<input type="checkbox" id="enable_scheduled_scan" name="enable_scheduled_scan" value="1" <?php checked( $s['enable_scheduled_scan'] ); ?> />
					<span class="hkmo_toggle-text"><?php esc_html_e( 'Run a scan automatically on a schedule', 'hk-media-optimizer' ); ?></span>
				</label>

				<div id="hkmo_schedule-fields">

					<div class="hkmo_field">
						<label class="hkmo_field-label" for="scheduled_scan_frequency">
							<?php esc_html_e( 'Frequency', 'hk-media-optimizer' ); ?>
						</label>
						<select id="scheduled_scan_frequency" name="scheduled_scan_frequency" class="hkmo_input-number" style="width:auto;min-width:140px;">
							<option value="daily" <?php selected( $s['scheduled_scan_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'hk-media-optimizer' ); ?></option>
							<option value="hkmo_weekly" <?php selected( $s['scheduled_scan_frequency'], 'hkmo_weekly' ); ?>><?php esc_html_e( 'Weekly', 'hk-media-optimizer' ); ?></option>
							<option value="hkmo_monthly" <?php selected( $s['scheduled_scan_frequency'], 'hkmo_monthly' ); ?>><?php esc_html_e( 'Monthly', 'hk-media-optimizer' ); ?></option>
						</select>
						<p class="hkmo_field-hint">
							<?php esc_html_e( 'How often the automatic scan runs.', 'hk-media-optimizer' ); ?>
						</p>
					</div>

					<?php
					$last_run_raw = get_option( 'hkmo_last_scheduled_scan' );
					$next_run_ts  = HKMO_Scheduler::get_next_scheduled_run();
					$engine_label = HKMO_Scheduler::get_engine_label();
					?>
					<div class="hkmo_schedule-status">
						<span>
							<?php esc_html_e( 'Last scheduled run:', 'hk-media-optimizer' ); ?>
							<strong>
								<?php
								echo $last_run_raw
									? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last_run_raw ) ) )
									: esc_html__( 'Never yet', 'hk-media-optimizer' );
								?>
							</strong>
						</span>
						<span>
							<?php esc_html_e( 'Next scheduled run:', 'hk-media-optimizer' ); ?>
							<strong>
								<?php
								echo $next_run_ts
									? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run_ts ) )
									: esc_html__( 'Not scheduled', 'hk-media-optimizer' );
								?>
							</strong>
						</span>
						<span>
							<?php esc_html_e( 'Running on:', 'hk-media-optimizer' ); ?>
							<strong><?php echo esc_html( $engine_label ); ?></strong>
						</span>
					</div>

					<p class="hkmo_field-hint" style="margin-bottom: 24px;">
						<?php if ( HKMO_Scheduler::is_action_scheduler_available() ) : ?>
							<?php esc_html_e( 'Action Scheduler was detected (loaded by another active plugin, e.g. WooCommerce) and is being used to run and continue scheduled scans in the background — more reliable on low-traffic sites than WordPress cron alone.', 'hk-media-optimizer' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Relies on WordPress cron, which only fires on a site visit. Low-traffic sites may need a real server cron job pointed at wp-cron.php for this to run reliably. (Installing a plugin that bundles Action Scheduler, such as WooCommerce, lets Media Optimizer use that instead automatically.)', 'hk-media-optimizer' ); ?>
						<?php endif; ?>
					</p>


					<label class="hkmo_toggle-row" style="max-width:420px;margin-bottom:14px;">
						<input type="checkbox" name="scheduled_scan_notify" value="1" <?php checked( $s['scheduled_scan_notify'] ); ?> />
						<span class="hkmo_toggle-text"><?php esc_html_e( 'Email a summary report after each scheduled scan', 'hk-media-optimizer' ); ?></span>
					</label>

					<div class="hkmo_field">
						<label class="hkmo_field-label" for="scheduled_scan_email">
							<?php esc_html_e( 'Report recipient', 'hk-media-optimizer' ); ?>
						</label>
						<div class="hkmo_field-row">
							<input type="email" id="scheduled_scan_email" name="scheduled_scan_email"
								value="<?php echo esc_attr( $s['scheduled_scan_email'] ); ?>"
								class="hkmo_input-number" style="width:260px;text-align:left;"
								placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
							<button type="button" class="hkmo_btn hkmo_btn--secondary" id="hkmo_send-test-report">
								<span class="dashicons dashicons-email-alt"></span>
								<?php esc_html_e( 'Send Test Report', 'hk-media-optimizer' ); ?>
							</button>
						</div>
						<p class="hkmo_field-hint">
							<?php esc_html_e( 'Leave blank to use the site admin email.', 'hk-media-optimizer' ); ?>
						</p>
					</div>

				</div>

			</div>
		</div>

		<!-- ── Deletion Safety ───────────────────────────────────────── -->
		<div class="hkmo_settings-section">
			<div class="hkmo_section-header">
				<div class="hkmo_section-icon hkmo_section-icon--red">
					<span class="dashicons dashicons-lock"></span>
				</div>
				<div>
					<h2 class="hkmo_section-title"><?php esc_html_e( 'Deletion Safety', 'hk-media-optimizer' ); ?></h2>
					<p class="hkmo_section-desc"><?php esc_html_e( 'Extra safeguards against accidental deletion.', 'hk-media-optimizer' ); ?></p>
				</div>
			</div>
			<div class="hkmo_section-body">
				<label class="hkmo_toggle-row" style="max-width:420px;">
					<input type="checkbox" name="require_type_confirm" value="1" <?php checked( $s['require_type_confirm'] ); ?> />
					<span class="hkmo_toggle-text"><?php esc_html_e( 'Require typing "DELETE" before permanently removing files', 'hk-media-optimizer' ); ?></span>
				</label>
				<p class="hkmo_field-hint" style="margin-top:10px;">
					<?php esc_html_e( 'Recommended. Adds a typed confirmation step before bulk deletion.', 'hk-media-optimizer' ); ?>
				</p>
			</div>
		</div>

		<div class="hkmo_settings-footer">
			<button type="submit" class="hkmo_btn hkmo_btn--primary">
				<span class="dashicons dashicons-saved"></span>
				<?php esc_html_e( 'Save Settings', 'hk-media-optimizer' ); ?>
			</button>
		</div>

	</form>
</div><!-- .hkmo_wrap -->
