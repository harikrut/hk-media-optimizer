<?php // phpcs:ignoreFile WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
/**
 * View: Main scanner page — modern UI.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$unused_count = HKMO_DB::count_results( 'unused' );
$used_count   = HKMO_DB::count_results( 'used' );
$reclaimable  = HKMO_DB::get_reclaimable_size();
$has_dup_data = HKMO_DB::has_hash_data();
// The results panel hosts all three tabs (Unused / In Use / Duplicates), so
// it needs to be reachable if *either* a regular scan or a duplicate scan
// has ever produced data — otherwise the Duplicates tab would be unreachable
// until the user ran an unrelated unused/used scan first.
$has_results = ( $unused_count + $used_count ) > 0 || $has_dup_data;
?>
<div class="hkmo_wrap">

	<!-- ── Header ───────────────────────────────────────────────────── -->
	<div class="hkmo_header">
		<div class="hkmo_header-left">
			<div class="hkmo_header-icon">
				<span class="dashicons dashicons-trash"></span>
			</div>
			<div>
				<h1 class="hkmo_header-title"><?php esc_html_e( 'HK Media Optimizer', 'hk-media-optimizer' ); ?></h1>
				<p class="hkmo_header-subtitle"><?php esc_html_e( 'Find and remove unused media files from your library.', 'hk-media-optimizer' ); ?></p>
			</div>
		</div>
		<div class="hkmo_header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=hk-media-optimizer-settings' ) ); ?>" class="hkmo_btn hkmo_btn--secondary">
				<span class="dashicons dashicons-admin-settings"></span>
				<?php esc_html_e( 'Settings', 'hk-media-optimizer' ); ?>
			</a>
			<button type="button" class="hkmo_btn hkmo_btn--primary" id="hkmo_start-scan">
				<span class="dashicons dashicons-search"></span>
				<?php esc_html_e( 'Start New Scan', 'hk-media-optimizer' ); ?>
			</button>
		</div>
	</div>

	<!-- ── Summary cards ─────────────────────────────────────────────── -->
	<div class="hkmo_cards">
		<div class="hkmo_card">
			<div class="hkmo_card-icon hkmo_card-icon--danger">
				<span class="dashicons dashicons-dismiss"></span>
			</div>
			<div class="hkmo_card-body">
				<span class="hkmo_card-number" id="hkmo_unused-count"><?php echo esc_html( number_format_i18n( $unused_count ) ); ?></span>
				<span class="hkmo_card-label"><?php esc_html_e( 'Unused files', 'hk-media-optimizer' ); ?></span>
			</div>
		</div>
		<div class="hkmo_card">
			<div class="hkmo_card-icon hkmo_card-icon--success">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
			<div class="hkmo_card-body">
				<span class="hkmo_card-number" id="hkmo_used-count"><?php echo esc_html( number_format_i18n( $used_count ) ); ?></span>
				<span class="hkmo_card-label"><?php esc_html_e( 'In-use files', 'hk-media-optimizer' ); ?></span>
			</div>
		</div>
		<div class="hkmo_card">
			<div class="hkmo_card-icon hkmo_card-icon--warning">
				<span class="dashicons dashicons-chart-pie"></span>
			</div>
			<div class="hkmo_card-body">
				<span class="hkmo_card-number" id="hkmo_reclaimable-size"><?php echo esc_html( size_format( $reclaimable, 1 ) ); ?></span>
				<span class="hkmo_card-label"><?php esc_html_e( 'Space reclaimable', 'hk-media-optimizer' ); ?></span>
			</div>
		</div>
	</div>

	<!-- ── Toolbar ───────────────────────────────────────────────────── -->
	<div class="hkmo_toolbar">
		<span class="hkmo_batch-note">
			<?php
			printf(
				/* translators: %d: batch size */
				esc_html__( 'Processes %d attachments per request · adjust in Settings', 'hk-media-optimizer' ),
				(int) HKMO_Settings::get( 'batch_size' )
			);
			?>
		</span>
	</div>

	<!-- ── Progress panel ────────────────────────────────────────────── -->
	<div class="hkmo_progress-panel" id="hkmo_progress-wrap" style="display:none;">
		<div class="hkmo_progress-header">
			<span class="hkmo_progress-label">
				<span class="hkmo_spinner"></span>
				<?php esc_html_e( 'Scanning…', 'hk-media-optimizer' ); ?>
			</span>
			<span class="hkmo_progress-pct" id="hkmo_progress-pct">0%</span>
		</div>
		<div class="hkmo_progress-track">
			<div class="hkmo_progress-fill" id="hkmo_progress-fill"></div>
		</div>
		<div class="hkmo_progress-sub">
			<span class="hkmo_progress-text" id="hkmo_progress-text"></span>
			<button type="button" class="hkmo_btn hkmo_btn--secondary" id="hkmo_cancel-scan" style="padding:6px 14px;font-size:12px;">
				<?php esc_html_e( 'Cancel', 'hk-media-optimizer' ); ?>
			</button>
		</div>
	</div>

	<!-- ── Results panel ─────────────────────────────────────────────── -->
	<div class="hkmo_results-panel" id="hkmo_results-wrap" <?php echo $has_results ? '' : 'style="display:none;"'; ?>>
		<div class="hkmo_results-toolbar">
			<div class="hkmo_tabs">
				<button type="button" class="hkmo_tab active" data-status="unused">
					<?php esc_html_e( 'Unused', 'hk-media-optimizer' ); ?>
				</button>
				<button type="button" class="hkmo_tab" data-status="used">
					<?php esc_html_e( 'In Use', 'hk-media-optimizer' ); ?>
				</button>
				<button type="button" class="hkmo_tab" data-status="duplicates">
					<?php esc_html_e( 'Duplicates', 'hk-media-optimizer' ); ?>
				</button>
			</div>
			<div class="hkmo_bulk-bar" id="hkmo_results-bulk-bar">
				<label class="hkmo_select-all-label">
					<input type="checkbox" id="hkmo_select-all" />
					<?php esc_html_e( 'Select All', 'hk-media-optimizer' ); ?>
				</label>
				<a href="#" class="hkmo_btn hkmo_btn--secondary" id="hkmo_export-csv">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Export CSV', 'hk-media-optimizer' ); ?>
				</a>
				<button type="button" class="hkmo_btn hkmo_btn--danger" id="hkmo_delete-selected" disabled>
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Delete Selected', 'hk-media-optimizer' ); ?>
				</button>
			</div>
		</div>

		<div class="hkmo_table-container" id="hkmo_table-container">
			<table class="hkmo_table">
				<thead>
					<tr>
						<th class="hkmo_col-check"></th>
						<th class="hkmo_col-thumb"></th>
						<th><?php esc_html_e( 'File', 'hk-media-optimizer' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'hk-media-optimizer' ); ?></th>
						<th class="hkmo_col-size"><?php esc_html_e( 'Size', 'hk-media-optimizer' ); ?></th>
						<th class="hkmo_col-actions"><?php esc_html_e( 'Actions', 'hk-media-optimizer' ); ?></th>
					</tr>
				</thead>
				<tbody id="hkmo_results-tbody">
					<!-- Populated via AJAX -->
				</tbody>
			</table>
		</div>

		<div class="hkmo_pagination" id="hkmo_pagination"></div>

		<!-- ── Duplicates tab content ─────────────────────────────────── -->
		<div class="hkmo_duplicates-wrap" id="hkmo_duplicates-wrap" style="display:none;">

			<div class="hkmo_dup-cta" id="hkmo_dup-cta">
				<div class="hkmo_dup-cta-icon"><span class="dashicons dashicons-image-flip-horizontal"></span></div>
				<h3><?php esc_html_e( 'Find duplicate files', 'hk-media-optimizer' ); ?></h3>
				<p><?php esc_html_e( 'Compares every file in your library by its actual contents, so exact duplicates are found even if they were uploaded under different filenames.', 'hk-media-optimizer' ); ?></p>
				<button type="button" class="hkmo_btn hkmo_btn--primary" id="hkmo_start-dup-scan">
					<span class="dashicons dashicons-search"></span>
					<?php esc_html_e( 'Find Duplicate Files', 'hk-media-optimizer' ); ?>
				</button>
			</div>

			<div class="hkmo_dup-toolbar" id="hkmo_dup-toolbar" style="display:none;">
				<div class="hkmo_dup-summary">
					<strong id="hkmo_dup-group-count">0</strong>
					<?php esc_html_e( 'duplicate groups', 'hk-media-optimizer' ); ?>
					<span class="hkmo_dup-summary-divider">·</span>
					<strong id="hkmo_dup-reclaimable">0 B</strong>
					<?php esc_html_e( 'reclaimable', 'hk-media-optimizer' ); ?>
				</div>
				<div class="hkmo_bulk-bar">
					<button type="button" class="hkmo_btn hkmo_btn--secondary" id="hkmo_rescan-dup">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Rescan', 'hk-media-optimizer' ); ?>
					</button>
					<button type="button" class="hkmo_btn hkmo_btn--danger" id="hkmo_delete-duplicates" disabled>
						<span class="dashicons dashicons-trash"></span>
						<?php esc_html_e( 'Delete Selected', 'hk-media-optimizer' ); ?>
					</button>
				</div>
			</div>

			<div class="hkmo_dup-groups" id="hkmo_dup-groups"><!-- Populated via AJAX --></div>
			<div class="hkmo_pagination" id="hkmo_dup-pagination"></div>

		</div>
	</div>

	<!-- ── Empty state ───────────────────────────────────────────────── -->
	<div class="hkmo_empty-state" id="hkmo_empty-state" <?php echo $has_results ? 'style="display:none;"' : ''; ?>>
		<div class="hkmo_empty-icon">🧹</div>
		<h3><?php esc_html_e( 'No scan results yet', 'hk-media-optimizer' ); ?></h3>
		<p><?php esc_html_e( 'Click "Start New Scan" to find unused media files in your library.', 'hk-media-optimizer' ); ?></p>
	</div>

</div><!-- .hkmo_wrap -->

<!-- ── Delete confirmation modal ─────────────────────────────────────────── -->
<div class="hkmo_overlay" id="hkmo_delete-modal" style="display:none;">
	<div class="hkmo_modal" role="dialog" aria-modal="true" aria-labelledby="hkmo_modal-heading">

		<button type="button" class="hkmo_modal-close" id="hkmo_modal-close-x" aria-label="<?php esc_attr_e( 'Close', 'hk-media-optimizer' ); ?>">
			<span class="dashicons dashicons-no-alt"></span>
		</button>

		<div class="hkmo_modal-icon">
			<span class="dashicons dashicons-trash"></span>
		</div>

		<h2 class="hkmo_modal-title" id="hkmo_modal-heading">
			<?php esc_html_e( 'Confirm Permanent Deletion', 'hk-media-optimizer' ); ?>
		</h2>

		<p class="hkmo_modal-summary" id="hkmo_modal-summary"></p>

		<div class="hkmo_modal-warning">
			<span class="dashicons dashicons-warning"></span>
			<?php esc_html_e( 'This action cannot be undone. Files will be permanently removed from your server.', 'hk-media-optimizer' ); ?>
		</div>

		<div class="hkmo_confirm-block" id="hkmo_confirm-block" style="display:none;">
			<label for="hkmo_confirm-input"><?php esc_html_e( 'Type DELETE to confirm:', 'hk-media-optimizer' ); ?></label>
			<input type="text" id="hkmo_confirm-input" class="hkmo_confirm-input" autocomplete="off" placeholder="DELETE" />
		</div>

		<div class="hkmo_modal-actions">
			<button type="button" class="hkmo_btn hkmo_btn--secondary" id="hkmo_modal-cancel">
				<?php esc_html_e( 'Cancel', 'hk-media-optimizer' ); ?>
			</button>
			<button type="button" class="hkmo_btn hkmo_btn--danger" id="hkmo_modal-confirm">
				<span class="dashicons dashicons-trash"></span>
				<?php esc_html_e( 'Delete Permanently', 'hk-media-optimizer' ); ?>
			</button>
		</div>

	</div>
</div>
