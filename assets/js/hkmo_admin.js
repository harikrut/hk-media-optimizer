/**
 * HK Media Optimizer — Modern Admin JS
 * All identifiers prefixed with hkmo_
 * @package HK_Media_Optimizer
 */
( function ( $ ) {
	'use strict';

	/* ─── State ─────────────────────────────────────────────────────────── */
	var hkmo_state = {
		scanning:      false,
		offset:        0,
		total:         0,
		currentStatus: 'unused',
		currentPage:   1,
		selectedIds:   {},
		pendingDeleteIds: [],
		deleteMode:    'main', // 'main' or 'duplicates'

		// Duplicate finder.
		dupScanning:    false,
		dupOffset:      0,
		dupTotal:       0,
		dupCurrentPage: 1,
		dupSelectedIds: {},
	};

	// Handle returned by setInterval() for the passive status-polling loop
	// used to show a live progress bar for a scan this tab isn't driving
	// itself (e.g. a scheduled/cron scan, or a manual scan started before
	// this page was loaded/reloaded).
	var hkmo_statusPollHandle = null;

	/* ─── Init ───────────────────────────────────────────────────────────── */
	$( function () {
		hkmo_bindEvents();
		hkmo_createToastContainer();
		if ( $( '#hkmo_results-wrap' ).is( ':visible' ) ) {
			hkmo_loadResults();
		}
		hkmo_updateExportLink();
		hkmo_bindSettingsEvents();

		// Only the Scanner screen has the progress panel markup at all.
		if ( hkmoData.isScannerPage ) {
			hkmo_checkRunningScan();
		}
	} );

	/* ─── Resume / observe a scan already in progress ────────────────────── */
	function hkmo_checkRunningScan() {
		$.post( hkmoData.ajaxUrl, {
			action: 'hkmo_get_scan_status',
			nonce:  hkmoData.nonce,
		} ).done( function ( response ) {
			if ( ! response.success || ! response.data || ! response.data.running ) {
				return;
			}
			hkmo_enterObserveMode( response.data );
		} );
	}

	/**
	 * Switch the UI into "a scan is running somewhere, just watch it"
	 * mode: shows the progress panel, disables starting a new scan, and
	 * polls hkmo_get_scan_status every few seconds for fresh numbers —
	 * without ever calling hkmo_scan_batch itself, so this tab can never
	 * race with whatever IS actually driving the batches (the background
	 * healthcheck, or another open tab).
	 *
	 * @param {Object} data Status payload from hkmo_get_scan_status.
	 */
	function hkmo_enterObserveMode( data ) {
		if ( hkmo_statusPollHandle ) { return; } // Already observing.

		hkmo_state.scanning = true;
		hkmo_state.total    = data.total || 0;

		$( '#hkmo_start-scan' ).prop( 'disabled', true ).addClass( 'hkmo_btn--loading' );
		$( '#hkmo_empty-state'   ).hide();
		$( '#hkmo_progress-wrap' ).show();

		hkmo_renderScanStatus( data );

		hkmo_statusPollHandle = setInterval( function () {
			$.post( hkmoData.ajaxUrl, {
				action: 'hkmo_get_scan_status',
				nonce:  hkmoData.nonce,
			} ).done( function ( response ) {
				if ( ! response.success || ! response.data ) { return; }

				if ( ! response.data.running ) {
					hkmo_exitObserveMode();
					return;
				}
				hkmo_renderScanStatus( response.data );
			} );
		}, 3000 );
	}

	function hkmo_renderScanStatus( data ) {
		hkmo_state.total = data.total || hkmo_state.total;
		var total  = hkmo_state.total || 1;
		var pct    = Math.min( 100, Math.round( ( data.processed / total ) * 100 ) );
		var origin = ( 'scheduled' === data.origin )
			? ( hkmoData.i18n.scheduledScanRunning || hkmoData.i18n.scanning )
			: hkmoData.i18n.scanning;

		hkmo_setProgress( pct, origin + ' — ' + data.processed + ' / ' + total + ' ' + hkmoData.i18n.files );
	}

	function hkmo_exitObserveMode() {
		if ( hkmo_statusPollHandle ) {
			clearInterval( hkmo_statusPollHandle );
			hkmo_statusPollHandle = null;
		}
		hkmo_finishScan();
	}

	/* ─── Event bindings ─────────────────────────────────────────────────── */
	function hkmo_bindEvents() {
		$( '#hkmo_start-scan'     ).on( 'click',  hkmo_startScan );
		$( '#hkmo_cancel-scan'    ).on( 'click',  hkmo_cancelScan );
		$( '.hkmo_tab'            ).on( 'click',  hkmo_onTabClick );
		$( '#hkmo_select-all'     ).on( 'change', hkmo_onSelectAll );
		$( '#hkmo_results-tbody'  ).on( 'change', '.hkmo_row-check', hkmo_onRowCheck );
		$( '#hkmo_delete-selected').on( 'click',  hkmo_onDeleteSelectedClick );
		$( '#hkmo_results-tbody'  ).on( 'click',  '.hkmo_delete-single', hkmo_onDeleteSingleClick );
		$( '#hkmo_modal-cancel'   ).on( 'click',  hkmo_closeModal );
		$( '#hkmo_modal-close-x'  ).on( 'click',  hkmo_closeModal );
		$( '#hkmo_modal-confirm'  ).on( 'click',  hkmo_confirmDelete );
		$( '#hkmo_export-csv'     ).on( 'click',  hkmo_onExportClick );

		// Duplicate finder.
		$( '#hkmo_start-dup-scan'  ).on( 'click',  hkmo_startDupScan );
		$( '#hkmo_rescan-dup'      ).on( 'click',  hkmo_startDupScan );
		$( '#hkmo_dup-groups'      ).on( 'change', '.hkmo_dup-check', hkmo_onDupCheck );
		$( '#hkmo_delete-duplicates' ).on( 'click', hkmo_onDeleteDuplicatesClick );

		// Close modal on overlay click
		$( '#hkmo_delete-modal' ).on( 'click', function ( e ) {
			if ( $( e.target ).is( '#hkmo_delete-modal' ) ) {
				hkmo_closeModal();
			}
		} );

		// Enable confirm button only when "DELETE" is typed
		$( '#hkmo_confirm-input' ).on( 'input', function () {
			var ok = $( this ).val() === 'DELETE';
			$( '#hkmo_modal-confirm' ).prop( 'disabled', ! ok );
		} );
	}

	/* ─── Settings page ──────────────────────────────────────────────────── */
	function hkmo_bindSettingsEvents() {
		var $enable = $( '#enable_scheduled_scan' );
		if ( ! $enable.length ) { return; } // Not on the Settings screen.

		function toggleScheduleFields() {
			$( '#hkmo_schedule-fields' ).toggle( $enable.is( ':checked' ) );
		}
		toggleScheduleFields();
		$enable.on( 'change', toggleScheduleFields );

		$( '#hkmo_send-test-report' ).on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true ).addClass( 'hkmo_btn--loading' );

			$.post( hkmoData.ajaxUrl, {
				action: 'hkmo_send_test_report',
				nonce:  hkmoData.nonce,
				email:  $( '#scheduled_scan_email' ).val(),
			} ).done( function ( response ) {
				var msg = response.data && response.data.message ? response.data.message : '';
				hkmo_toast( msg || ( response.success ? 'Sent.' : hkmoData.i18n.error ), response.success ? 'success' : 'error' );
			} ).fail( function () {
				hkmo_toast( hkmoData.i18n.error, 'error' );
			} ).always( function () {
				$btn.prop( 'disabled', false ).removeClass( 'hkmo_btn--loading' );
			} );
		} );
	}

	/* ─── Scan ───────────────────────────────────────────────────────────── */
	function hkmo_startScan() {
		if ( hkmo_state.scanning ) { return; }
		hkmo_state.scanning = true;
		hkmo_state.offset   = 0;

		$( '#hkmo_start-scan' ).prop( 'disabled', true ).addClass( 'hkmo_btn--loading' );
		$( '#hkmo_progress-wrap' ).slideDown( 200 );
		$( '#hkmo_empty-state'   ).hide();
		hkmo_setProgress( 0, hkmoData.i18n.scanning );

		$.post( hkmoData.ajaxUrl, {
			action: 'hkmo_start_scan',
			nonce:  hkmoData.nonce,
		} ).done( function ( response ) {
			if ( ! response.success ) {
				if ( response.data && response.data.already_running ) {
					hkmo_state.scanning = false;
					hkmo_checkRunningScan();
					return;
				}
				hkmo_onScanError( response );
				return;
			}
			hkmo_state.total = response.data.total || 0;
			hkmo_runNextBatch();
		} ).fail( hkmo_onScanError );
	}

	function hkmo_runNextBatch() {
		if ( ! hkmo_state.scanning ) { return; }

		$.post( hkmoData.ajaxUrl, {
			action:  'hkmo_scan_batch',
			nonce:   hkmoData.nonce,
			offset:  hkmo_state.offset,
		} ).done( function ( response ) {
			if ( ! response.success ) { hkmo_onScanError( response ); return; }

			var progress = response.data;
			var total    = hkmo_state.total || 1;
			var pct      = Math.min( 100, Math.round( ( progress.processed / total ) * 100 ) );
			hkmo_setProgress( pct, progress.processed + ' / ' + total + ' ' + hkmoData.i18n.files );

			if ( progress.done ) {
				hkmo_finishScan();
				return;
			}
			hkmo_state.offset = progress.processed || hkmo_state.offset;
			setTimeout( hkmo_runNextBatch, 200 );
		} ).fail( hkmo_onScanError );
	}

	function hkmo_setProgress( pct, text ) {
		$( '#hkmo_progress-fill' ).css( 'width', pct + '%' );
		$( '#hkmo_progress-pct'  ).text( pct + '%' );
		$( '#hkmo_progress-text' ).text( text );
	}

	function hkmo_finishScan() {
		hkmo_state.scanning = false;
		$( '#hkmo_start-scan' ).prop( 'disabled', false ).removeClass( 'hkmo_btn--loading' );
		hkmo_setProgress( 100, hkmoData.i18n.scanComplete );
		hkmo_toast( hkmoData.i18n.scanComplete, 'success' );
		setTimeout( function () {
			$( '#hkmo_progress-wrap' ).slideUp( 200 );
		}, 1800 );
		$( '#hkmo_results-wrap' ).slideDown( 200 );
		hkmo_loadResults();
	}

	function hkmo_cancelScan() {
		hkmo_state.scanning = false;
		if ( hkmo_statusPollHandle ) {
			clearInterval( hkmo_statusPollHandle );
			hkmo_statusPollHandle = null;
		}
		$.post( hkmoData.ajaxUrl, { action: 'hkmo_cancel_scan', nonce: hkmoData.nonce } )
			.always( function () {
				$( '#hkmo_start-scan' ).prop( 'disabled', false ).removeClass( 'hkmo_btn--loading' );
				$( '#hkmo_progress-wrap' ).slideUp( 200 );
				hkmo_loadResults();
			} );
	}

	function hkmo_onScanError( response ) {
		hkmo_state.scanning = false;
		$( '#hkmo_start-scan' ).prop( 'disabled', false ).removeClass( 'hkmo_btn--loading' );
		var msg = ( response && response.data && response.data.message )
			? response.data.message : hkmoData.i18n.error;
		$( '#hkmo_progress-text' ).text( msg );
		hkmo_toast( msg, 'error' );
	}

	/* ─── Tab switching ──────────────────────────────────────────────────── */
	function hkmo_onTabClick() {
		$( '.hkmo_tab' ).removeClass( 'active' );
		$( this ).addClass( 'active' );
		hkmo_state.currentStatus = $( this ).data( 'status' );
		hkmo_state.currentPage   = 1;
		hkmo_state.selectedIds   = {};

		if ( 'duplicates' === hkmo_state.currentStatus ) {
			$( '#hkmo_table-container, #hkmo_pagination' ).hide();
			$( '#hkmo_results-bulk-bar' ).hide();
			$( '#hkmo_duplicates-wrap' ).show();
			hkmo_loadDuplicates();
			return;
		}

		$( '#hkmo_duplicates-wrap' ).hide();
		$( '#hkmo_table-container, #hkmo_pagination' ).show();
		$( '#hkmo_results-bulk-bar' ).show();
		hkmo_updateExportLink();
		hkmo_loadResults();
	}

	/* ─── CSV export ─────────────────────────────────────────────────────── */
	function hkmo_updateExportLink() {
		var $link = $( '#hkmo_export-csv' );
		if ( ! $link.length || ! hkmoData.exportUrl ) { return; }
		$link.attr(
			'href',
			hkmoData.exportUrl + '?action=hkmo_export_csv&status=' + encodeURIComponent( hkmo_state.currentStatus ) + '&_wpnonce=' + hkmoData.exportNonce
		);
	}

	function hkmo_onExportClick() {
		hkmo_updateExportLink();
		// Let the default navigation happen (href already points at the file) —
		// nothing else to do here, this just guarantees the href is fresh.
	}

	/* ─── Load & render results ──────────────────────────────────────────── */
	function hkmo_loadResults() {
		var $tbody = $( '#hkmo_results-tbody' );
		$tbody.html(
			'<tr class="hkmo_loading-row"><td colspan="6" style="padding:32px;text-align:center;color:var(--hkmo_text-muted);">' +
			'<span class="hkmo_spinner" style="margin-right:8px;vertical-align:middle;"></span> Loading…</td></tr>'
		);

		$.post( hkmoData.ajaxUrl, {
			action:  'hkmo_get_results',
			nonce:   hkmoData.nonce,
			status:  hkmo_state.currentStatus,
			page:    hkmo_state.currentPage,
		} ).done( function ( response ) {
			if ( ! response.success ) { return; }
			hkmo_renderResults( response.data );
		} );
	}

	function hkmo_renderResults( data ) {
		var $tbody = $( '#hkmo_results-tbody' );
		$tbody.empty();

		// Update summary cards
		$( '#hkmo_unused-count'     ).text( hkmo_numberFormat( data.unused_count ) );
		$( '#hkmo_used-count'       ).text( hkmo_numberFormat( data.used_count ) );
		$( '#hkmo_reclaimable-size' ).text( hkmo_formatBytes( data.reclaimable ) );

		if ( ! data.items || ! data.items.length ) {
			$tbody.append(
				'<tr class="hkmo_no-results"><td colspan="6">' +
				'<div style="padding:40px;text-align:center;">' +
				'<div style="font-size:36px;margin-bottom:10px;">🎉</div>' +
				'<strong style="display:block;margin-bottom:4px;color:var(--hkmo_text);">No files found</strong>' +
				'<span style="color:var(--hkmo_text-muted);font-size:13px;">Nothing in this category right now.</span>' +
				'</div></td></tr>'
			);
			hkmo_renderPagination( data );
			return;
		}

		data.items.forEach( function ( item ) {
			var $row = $( '<tr>' )
				.attr( 'data-id', item.id )
				.addClass( hkmo_state.selectedIds[ item.id ] ? 'hkmo_row--selected' : '' );

			// Checkbox
			var $check = $( '<td class="hkmo_col-check">' ).append(
				$( '<input type="checkbox" class="hkmo_row-check">' )
					.val( item.id )
					.prop( 'checked', !! hkmo_state.selectedIds[ item.id ] )
			);

			// Thumbnail
			var $thumbCell = $( '<td class="hkmo_col-thumb">' );
			if ( item.thumb ) {
				$thumbCell.append( $( '<img class="hkmo_thumb">' ).attr( { src: item.thumb, alt: '' } ) );
			} else {
				$thumbCell.append( '<div class="hkmo_no-thumb"><span class="dashicons dashicons-format-image"></span></div>' );
			}

			// File info
			var $fileCell = $( '<td>' ).append(
				$( '<span class="hkmo_filename">' ).text( item.title ),
				$( '<span class="hkmo_file-meta">' ).text( item.filename )
			);

			// Reason badge
			var $reasonCell = $( '<td>' );
			if ( item.reason ) {
				var badgeClass = item.status === 'unused' ? 'hkmo_badge--unused' : 'hkmo_badge--used';
				$reasonCell.append( $( '<span class="hkmo_badge ' + badgeClass + '">' ).text( item.reason ) );
			} else {
				$reasonCell.append( '<span class="hkmo_badge hkmo_badge--neutral">—</span>' );
			}

			// Size
			var $sizeCell = $( '<td class="hkmo_col-size">' ).append(
				$( '<span class="hkmo_size-value">' ).text( hkmo_formatBytes( item.file_size ) )
			);

			// Actions
			var $actionsCell = $( '<td class="hkmo_col-actions">' );
			var $actionsDiv  = $( '<div class="hkmo_row-actions">' );

			if ( item.edit_link ) {
				$actionsDiv.append(
					$( '<a class="hkmo_btn hkmo_btn--ghost" target="_blank">' )
						.attr( 'href', item.edit_link )
						.html( '<span class="dashicons dashicons-external"></span> View' )
				);
			}

			$actionsDiv.append(
				$( '<button type="button" class="hkmo_btn hkmo_btn--ghost-danger hkmo_delete-single">' )
					.attr( 'data-id', item.id )
					.html( '<span class="dashicons dashicons-trash"></span> Delete' )
			);

			$actionsCell.append( $actionsDiv );
			$row.append( $check, $thumbCell, $fileCell, $reasonCell, $sizeCell, $actionsCell );
			$tbody.append( $row );
		} );

		hkmo_renderPagination( data );
		hkmo_updateDeleteButtonState();
	}

	/* ─── Pagination ─────────────────────────────────────────────────────── */
	function hkmo_renderPagination( data ) {
		var $pagination = $( '#hkmo_pagination' );
		$pagination.empty();

		var totalPages = Math.max( 1, Math.ceil( data.total / data.per_page ) );
		if ( totalPages <= 1 ) { return; }

		// Prev
		var $prev = $( '<button type="button" class="hkmo_page-btn">&lsaquo;</button>' )
			.prop( 'disabled', hkmo_state.currentPage <= 1 )
			.on( 'click', function () {
				if ( hkmo_state.currentPage > 1 ) {
					hkmo_state.currentPage--;
					hkmo_loadResults();
				}
			} );
		$pagination.append( $prev );

		// Page numbers (show max 7 with ellipsis)
		var pages = hkmo_getPageRange( hkmo_state.currentPage, totalPages );
		pages.forEach( function ( p ) {
			if ( p === '…' ) {
				$pagination.append( '<span style="padding:0 6px;color:var(--hkmo_text-subtle);">…</span>' );
				return;
			}
			var $btn = $( '<button type="button" class="hkmo_page-btn">' ).text( p );
			if ( p === hkmo_state.currentPage ) { $btn.addClass( 'active' ); }
			$btn.on( 'click', ( function ( pg ) {
				return function () { hkmo_state.currentPage = pg; hkmo_loadResults(); };
			} )( p ) );
			$pagination.append( $btn );
		} );

		// Next
		var $next = $( '<button type="button" class="hkmo_page-btn">&rsaquo;</button>' )
			.prop( 'disabled', hkmo_state.currentPage >= totalPages )
			.on( 'click', function () {
				if ( hkmo_state.currentPage < totalPages ) {
					hkmo_state.currentPage++;
					hkmo_loadResults();
				}
			} );
		$pagination.append( $next );
	}

	function hkmo_getPageRange( current, total ) {
		if ( total <= 7 ) {
			var pages = [];
			for ( var i = 1; i <= total; i++ ) { pages.push( i ); }
			return pages;
		}
		var result = [ 1 ];
		if ( current > 3 ) { result.push( '…' ); }
		for ( var p = Math.max( 2, current - 1 ); p <= Math.min( total - 1, current + 1 ); p++ ) {
			result.push( p );
		}
		if ( current < total - 2 ) { result.push( '…' ); }
		result.push( total );
		return result;
	}

	/* ─── Selection ──────────────────────────────────────────────────────── */
	function hkmo_onSelectAll() {
		var checked = $( this ).is( ':checked' );
		$( '.hkmo_row-check' ).prop( 'checked', checked ).each( function () {
			var id = $( this ).val();
			if ( checked ) {
				hkmo_state.selectedIds[ id ] = true;
				$( this ).closest( 'tr' ).addClass( 'hkmo_row--selected' );
			} else {
				delete hkmo_state.selectedIds[ id ];
				$( this ).closest( 'tr' ).removeClass( 'hkmo_row--selected' );
			}
		} );
		hkmo_updateDeleteButtonState();
	}

	function hkmo_onRowCheck() {
		var id = $( this ).val();
		if ( $( this ).is( ':checked' ) ) {
			hkmo_state.selectedIds[ id ] = true;
			$( this ).closest( 'tr' ).addClass( 'hkmo_row--selected' );
		} else {
			delete hkmo_state.selectedIds[ id ];
			$( this ).closest( 'tr' ).removeClass( 'hkmo_row--selected' );
		}
		hkmo_updateDeleteButtonState();
	}

	function hkmo_updateDeleteButtonState() {
		var count = Object.keys( hkmo_state.selectedIds ).length;
		var $btn  = $( '#hkmo_delete-selected' );
		$btn.prop( 'disabled', count === 0 );
		if ( count > 0 ) {
			$btn.html( '<span class="dashicons dashicons-trash"></span> Delete Selected (' + count + ')' );
		} else {
			$btn.html( '<span class="dashicons dashicons-trash"></span> Delete Selected' );
		}
	}

	/* ─── Delete flow ────────────────────────────────────────────────────── */
	function hkmo_onDeleteSelectedClick() {
		var ids = Object.keys( hkmo_state.selectedIds );
		if ( ! ids.length ) { return; }
		hkmo_state.deleteMode = 'main';
		hkmo_openModal( ids );
	}

	function hkmo_onDeleteSingleClick( e ) {
		e.preventDefault();
		hkmo_state.deleteMode = 'main';
		hkmo_openModal( [ String( $( this ).data( 'id' ) ) ] );
	}

	function hkmo_openModal( ids ) {
		hkmo_state.pendingDeleteIds = ids;

		$( '#hkmo_modal-summary' ).text(
			ids.length + ( ids.length === 1 ? ' file' : ' files' ) +
			' selected for permanent deletion.'
		);

		if ( hkmoData.requireTypeConfirm ) {
			$( '#hkmo_confirm-block' ).show();
			$( '#hkmo_confirm-input' ).val( '' );
			$( '#hkmo_modal-confirm' ).prop( 'disabled', true );
		} else {
			$( '#hkmo_confirm-block' ).hide();
			$( '#hkmo_modal-confirm' ).prop( 'disabled', false );
		}

		$( '#hkmo_delete-modal' ).fadeIn( 150 );
		if ( hkmoData.requireTypeConfirm ) {
			setTimeout( function () { $( '#hkmo_confirm-input' ).focus(); }, 160 );
		}
	}

	function hkmo_closeModal() {
		$( '#hkmo_delete-modal' ).fadeOut( 150 );
		hkmo_state.pendingDeleteIds = [];
	}

	function hkmo_confirmDelete() {
		if ( hkmoData.requireTypeConfirm && $( '#hkmo_confirm-input' ).val() !== 'DELETE' ) {
			hkmo_toast( hkmoData.i18n.deleteMismatch, 'error' );
			return;
		}

		var ids    = hkmo_state.pendingDeleteIds;
		var action = ( 'duplicates' === hkmo_state.deleteMode ) ? 'hkmo_delete_duplicates' : 'hkmo_delete_attachments';
		var $btn   = $( '#hkmo_modal-confirm' );
		$btn.prop( 'disabled', true ).addClass( 'hkmo_btn--loading' )
		    .find( 'span.dashicons' ).remove();

		$.post( hkmoData.ajaxUrl, {
			action: action,
			nonce:  hkmoData.nonce,
			ids:    ids,
		} ).done( function ( response ) {
			hkmo_closeModal();
			$btn.prop( 'disabled', false ).removeClass( 'hkmo_btn--loading' )
			    .html( '<span class="dashicons dashicons-trash"></span> Delete Permanently' );
			if ( response.success ) {
				hkmo_toast( ids.length + ' file(s) deleted successfully.', 'success' );
				if ( 'duplicates' === hkmo_state.deleteMode ) {
					hkmo_state.dupSelectedIds = {};
					hkmo_loadDuplicates();
				} else {
					hkmo_state.selectedIds = {};
					hkmo_loadResults();
				}
			} else {
				hkmo_toast( hkmoData.i18n.error, 'error' );
			}
		} ).fail( function () {
			hkmo_closeModal();
			$btn.prop( 'disabled', false ).removeClass( 'hkmo_btn--loading' )
			    .html( '<span class="dashicons dashicons-trash"></span> Delete Permanently' );
			hkmo_toast( hkmoData.i18n.error, 'error' );
		} );
	}

	/* ─── Duplicate finder ───────────────────────────────────────────────── */
	function hkmo_startDupScan() {
		if ( hkmo_state.dupScanning ) { return; }
		hkmo_state.dupScanning = true;
		hkmo_state.dupOffset   = 0;

		$( '#hkmo_start-dup-scan, #hkmo_rescan-dup' ).prop( 'disabled', true ).addClass( 'hkmo_btn--loading' );
		$( '#hkmo_progress-wrap' ).slideDown( 200 );
		hkmo_setProgress( 0, hkmoData.i18n.findingDuplicates );

		$.post( hkmoData.ajaxUrl, {
			action: 'hkmo_start_duplicate_scan',
			nonce:  hkmoData.nonce,
		} ).done( function ( response ) {
			if ( ! response.success ) { hkmo_onDupScanError( response ); return; }
			hkmo_state.dupTotal = response.data.total || 0;
			hkmo_runNextDupBatch();
		} ).fail( hkmo_onDupScanError );
	}

	function hkmo_runNextDupBatch() {
		if ( ! hkmo_state.dupScanning ) { return; }

		$.post( hkmoData.ajaxUrl, {
			action: 'hkmo_duplicate_scan_batch',
			nonce:  hkmoData.nonce,
			offset: hkmo_state.dupOffset,
		} ).done( function ( response ) {
			if ( ! response.success ) { hkmo_onDupScanError( response ); return; }

			var progress = response.data;
			var total    = hkmo_state.dupTotal || 1;
			var pct      = Math.min( 100, Math.round( ( progress.processed / total ) * 100 ) );
			hkmo_setProgress( pct, progress.processed + ' / ' + total + ' ' + hkmoData.i18n.files );

			if ( progress.done ) {
				hkmo_finishDupScan();
				return;
			}
			hkmo_state.dupOffset = progress.processed || hkmo_state.dupOffset;
			setTimeout( hkmo_runNextDupBatch, 200 );
		} ).fail( hkmo_onDupScanError );
	}

	function hkmo_finishDupScan() {
		hkmo_state.dupScanning = false;
		$( '#hkmo_start-dup-scan, #hkmo_rescan-dup' ).prop( 'disabled', false ).removeClass( 'hkmo_btn--loading' );
		hkmo_setProgress( 100, hkmoData.i18n.duplicateScanDone );
		hkmo_toast( hkmoData.i18n.duplicateScanDone, 'success' );
		setTimeout( function () {
			$( '#hkmo_progress-wrap' ).slideUp( 200 );
		}, 1800 );
		hkmo_state.dupCurrentPage = 1;
		hkmo_state.dupSelectedIds = {};
		hkmo_loadDuplicates();
	}

	function hkmo_onDupScanError( response ) {
		hkmo_state.dupScanning = false;
		$( '#hkmo_start-dup-scan, #hkmo_rescan-dup' ).prop( 'disabled', false ).removeClass( 'hkmo_btn--loading' );
		var msg = ( response && response.data && response.data.message )
			? response.data.message : hkmoData.i18n.error;
		$( '#hkmo_progress-text' ).text( msg );
		hkmo_toast( msg, 'error' );
	}

	function hkmo_loadDuplicates() {
		var $groups = $( '#hkmo_dup-groups' );
		$groups.html(
			'<div style="padding:32px;text-align:center;color:var(--hkmo_text-muted);">' +
			'<span class="hkmo_spinner" style="margin-right:8px;vertical-align:middle;"></span> Loading…</div>'
		);

		$.post( hkmoData.ajaxUrl, {
			action: 'hkmo_get_duplicate_groups',
			nonce:  hkmoData.nonce,
			page:   hkmo_state.dupCurrentPage,
		} ).done( function ( response ) {
			if ( ! response.success ) { return; }
			hkmo_renderDuplicates( response.data );
		} );
	}

	function hkmo_renderDuplicates( data ) {
		var $cta     = $( '#hkmo_dup-cta' );
		var $toolbar = $( '#hkmo_dup-toolbar' );
		var $groups  = $( '#hkmo_dup-groups' );
		$groups.empty();

		if ( ! data.has_data ) {
			$cta.show();
			$toolbar.hide();
			$( '#hkmo_dup-pagination' ).empty();
			return;
		}

		$cta.hide();
		$toolbar.show();
		$( '#hkmo_dup-group-count' ).text( hkmo_numberFormat( data.total_groups ) );
		$( '#hkmo_dup-reclaimable' ).text( hkmo_formatBytes( data.reclaimable ) );

		if ( ! data.groups || ! data.groups.length ) {
			$groups.append(
				'<div style="padding:40px;text-align:center;">' +
				'<div style="font-size:36px;margin-bottom:10px;">🎉</div>' +
				'<strong style="display:block;margin-bottom:4px;color:var(--hkmo_text);">' + hkmoData.i18n.noDuplicates + '</strong>' +
				'</div>'
			);
			hkmo_renderDupPagination( data );
			return;
		}

		data.groups.forEach( function ( group ) {
			var $card = $( '<div class="hkmo_dup-group">' ).attr( 'data-hash', group.hash );

			var $header = $( '<div class="hkmo_dup-group-header">' ).append(
				$( '<span class="hkmo_dup-group-title">' ).text(
					group.count + ' copies · ' + hkmo_formatBytes( group.file_size ) + ' each'
				),
				$( '<span class="hkmo_dup-group-wasted">' ).text( hkmo_formatBytes( group.wasted ) + ' wasted' )
			);

			var $members = $( '<div class="hkmo_dup-group-members">' );

			group.members.forEach( function ( member, idx ) {
				// Pre-check every member except the first (oldest upload) as a
				// sensible default — the server still refuses to delete every
				// copy in a group even if all boxes end up checked.
				var $label = $( '<label class="hkmo_dup-member">' );
				var $check = $( '<input type="checkbox" class="hkmo_dup-check">' ).val( member.id );

				// Default: every member except the first (oldest upload) is
				// pre-checked for deletion. Once the admin manually toggles a
				// box, their choice is remembered across re-renders (pagination,
				// rescans) via dupSelectedIds rather than being reset each time.
				var preChecked = ( idx !== 0 );
				if ( Object.prototype.hasOwnProperty.call( hkmo_state.dupSelectedIds, member.id ) ) {
					preChecked = !! hkmo_state.dupSelectedIds[ member.id ];
				} else if ( preChecked ) {
					hkmo_state.dupSelectedIds[ member.id ] = true;
				}
				$check.prop( 'checked', preChecked );

				var $thumb = member.thumb
					? $( '<img class="hkmo_thumb">' ).attr( { src: member.thumb, alt: '' } )
					: $( '<div class="hkmo_no-thumb"><span class="dashicons dashicons-format-image"></span></div>' );

				$label.append(
					$check,
					$thumb,
					$( '<span class="hkmo_dup-member-name">' ).text( member.filename ),
					$( '<span class="hkmo_dup-member-date">' ).text( member.uploaded )
				);

				if ( idx === 0 ) {
					$label.append( $( '<span class="hkmo_dup-keep-badge">' ).text( 'Oldest' ) );
				}

				$members.append( $label );
			} );

			$card.append( $header, $members );
			$groups.append( $card );
		} );

		hkmo_renderDupPagination( data );
		hkmo_updateDupDeleteButtonState();
	}

	function hkmo_renderDupPagination( data ) {
		var $pagination = $( '#hkmo_dup-pagination' );
		$pagination.empty();

		var totalPages = Math.max( 1, Math.ceil( data.total_groups / data.per_page ) );
		if ( totalPages <= 1 ) { return; }

		var $prev = $( '<button type="button" class="hkmo_page-btn">&lsaquo;</button>' )
			.prop( 'disabled', hkmo_state.dupCurrentPage <= 1 )
			.on( 'click', function () {
				if ( hkmo_state.dupCurrentPage > 1 ) {
					hkmo_state.dupCurrentPage--;
					hkmo_loadDuplicates();
				}
			} );
		$pagination.append( $prev );

		var pages = hkmo_getPageRange( hkmo_state.dupCurrentPage, totalPages );
		pages.forEach( function ( p ) {
			if ( p === '…' ) {
				$pagination.append( '<span style="padding:0 6px;color:var(--hkmo_text-subtle);">…</span>' );
				return;
			}
			var $btn = $( '<button type="button" class="hkmo_page-btn">' ).text( p );
			if ( p === hkmo_state.dupCurrentPage ) { $btn.addClass( 'active' ); }
			$btn.on( 'click', ( function ( pg ) {
				return function () { hkmo_state.dupCurrentPage = pg; hkmo_loadDuplicates(); };
			} )( p ) );
			$pagination.append( $btn );
		} );

		var $next = $( '<button type="button" class="hkmo_page-btn">&rsaquo;</button>' )
			.prop( 'disabled', hkmo_state.dupCurrentPage >= totalPages )
			.on( 'click', function () {
				if ( hkmo_state.dupCurrentPage < totalPages ) {
					hkmo_state.dupCurrentPage++;
					hkmo_loadDuplicates();
				}
			} );
		$pagination.append( $next );
	}

	function hkmo_onDupCheck() {
		var id = $( this ).val();
		hkmo_state.dupSelectedIds[ id ] = $( this ).is( ':checked' );
		hkmo_updateDupDeleteButtonState();
	}

	function hkmo_updateDupDeleteButtonState() {
		var count = Object.keys( hkmo_state.dupSelectedIds ).filter( function ( id ) {
			return hkmo_state.dupSelectedIds[ id ];
		} ).length;
		var $btn = $( '#hkmo_delete-duplicates' );
		$btn.prop( 'disabled', count === 0 );
		if ( count > 0 ) {
			$btn.html( '<span class="dashicons dashicons-trash"></span> Delete Selected (' + count + ')' );
		} else {
			$btn.html( '<span class="dashicons dashicons-trash"></span> Delete Selected' );
		}
	}

	function hkmo_onDeleteDuplicatesClick() {
		var ids = Object.keys( hkmo_state.dupSelectedIds ).filter( function ( id ) {
			return hkmo_state.dupSelectedIds[ id ];
		} );
		if ( ! ids.length ) { return; }
		hkmo_state.deleteMode = 'duplicates';
		hkmo_openModal( ids );
	}

	/* ─── Toast notifications ────────────────────────────────────────────── */
	function hkmo_createToastContainer() {
		if ( ! $( '#hkmo_toast-wrap' ).length ) {
			$( 'body' ).append( '<div id="hkmo_toast-wrap" class="hkmo_toast-wrap"></div>' );
		}
	}

	function hkmo_toast( message, type ) {
		var icon = type === 'success' ? 'dashicons-yes-alt'
		         : type === 'error'   ? 'dashicons-warning'
		         :                      'dashicons-info';

		var $toast = $( '<div class="hkmo_toast hkmo_toast--' + ( type || 'info' ) + '">' ).html(
			'<span class="dashicons ' + icon + '"></span><span>' + $( '<div>' ).text( message ).html() + '</span>'
		);

		$( '#hkmo_toast-wrap' ).append( $toast );
		setTimeout( function () {
			$toast.css( { opacity: 0, transform: 'translateX(24px)', transition: 'opacity .3s, transform .3s' } );
			setTimeout( function () { $toast.remove(); }, 320 );
		}, 3500 );
	}

	/* ─── Helpers ────────────────────────────────────────────────────────── */
	function hkmo_formatBytes( bytes ) {
		if ( ! bytes ) { return '0 B'; }
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = 0;
		while ( bytes >= 1024 && i < units.length - 1 ) { bytes /= 1024; i++; }
		return bytes.toFixed( 1 ) + ' ' + units[ i ];
	}

	function hkmo_numberFormat( n ) {
		return ( n || 0 ).toLocaleString();
	}

} )( jQuery );
