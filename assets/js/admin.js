/**
 * HK Media Optimizer - Admin scripts.
 *
 * The scan loop deliberately runs one batch at a time, waiting for each
 * AJAX response before requesting the next batch. This keeps server load
 * spread out (one small query burst at a time) instead of firing dozens
 * of parallel requests, which is the main lightweight design choice on
 * the front-end side.
 */
( function ( $ ) {
	'use strict';

	var state = {
		scanning: false,
		offset: 0,
		total: 0,
		currentStatus: 'unused',
		currentPage: 1,
		selectedIds: {},
		pendingDeleteIds: [],
	};

	$( function () {
		bindEvents();
		if ( $( '#hkmo-results-wrap' ).is( ':visible' ) ) {
			loadResults();
		}
	} );

	function bindEvents() {
		$( '#hkmo-start-scan' ).on( 'click', startScan );
		$( '#hkmo-cancel-scan' ).on( 'click', cancelScan );
		$( '.hkmo-tab' ).on( 'click', onTabClick );
		$( '#hkmo-select-all' ).on( 'change', onSelectAll );
		$( '#hkmo-results-tbody' ).on( 'change', '.hkmo-row-check', onRowCheck );
		$( '#hkmo-delete-selected' ).on( 'click', onDeleteSelectedClick );
		$( '#hkmo-results-tbody' ).on( 'click', '.hkmo-delete-single', onDeleteSingleClick );
		$( '#hkmo-modal-cancel' ).on( 'click', closeModal );
		$( '#hkmo-modal-confirm' ).on( 'click', confirmDelete );
	}

	function startScan() {
		if ( state.scanning ) {
			return;
		}
		state.scanning = true;
		state.offset = 0;

		$( '#hkmo-start-scan' ).prop( 'disabled', true );
		$( '#hkmo-progress-wrap' ).show();
		$( '#hkmo-empty-state' ).hide();
		$( '#hkmo-progress-fill' ).css( 'width', '0%' );
		$( '#hkmo-progress-text' ).text( hkmoData.i18n.scanning );

		$.post( hkmoData.ajaxUrl, {
			action: 'hkmo_start_scan',
			nonce: hkmoData.nonce,
		} ).done( function ( response ) {
			if ( ! response.success ) {
				onScanError( response );
				return;
			}
			state.total = response.data.total || 0;
			runNextBatch();
		} ).fail( onScanError );
	}

	function runNextBatch() {
		if ( ! state.scanning ) {
			return;
		}

		$.post( hkmoData.ajaxUrl, {
			action: 'hkmo_scan_batch',
			nonce: hkmoData.nonce,
			offset: state.offset,
		} ).done( function ( response ) {
			if ( ! response.success ) {
				onScanError( response );
				return;
			}

			var progress = response.data;
			updateProgressUI( progress );

			if ( progress.done ) {
				finishScan();
				return;
			}

			state.offset = progress.processed || state.offset;
			// Small delay between batches keeps DB load spread out rather
			// than hammering the server with back-to-back requests.
			setTimeout( runNextBatch, 200 );
		} ).fail( onScanError );
	}

	function updateProgressUI( progress ) {
		var total = state.total || 1;
		var pct = Math.min( 100, Math.round( ( progress.processed / total ) * 100 ) );
		$( '#hkmo-progress-fill' ).css( 'width', pct + '%' );
		$( '#hkmo-progress-text' ).text(
			progress.processed + ' / ' + total + ' (' + pct + '%)'
		);
	}

	function finishScan() {
		state.scanning = false;
		$( '#hkmo-start-scan' ).prop( 'disabled', false );
		$( '#hkmo-progress-text' ).text( hkmoData.i18n.scanComplete );
		setTimeout( function () {
			$( '#hkmo-progress-wrap' ).hide();
		}, 1500 );
		$( '#hkmo-results-wrap' ).show();
		loadResults();
	}

	function cancelScan() {
		state.scanning = false;
		$.post( hkmoData.ajaxUrl, {
			action: 'hkmo_cancel_scan',
			nonce: hkmoData.nonce,
		} ).always( function () {
			$( '#hkmo-start-scan' ).prop( 'disabled', false );
			$( '#hkmo-progress-wrap' ).hide();
			loadResults();
		} );
	}

	function onScanError( response ) {
		state.scanning = false;
		$( '#hkmo-start-scan' ).prop( 'disabled', false );
		var msg = ( response && response.data && response.data.message ) ? response.data.message : hkmoData.i18n.error;
		$( '#hkmo-progress-text' ).text( msg );
	}

	function onTabClick() {
		$( '.hkmo-tab' ).removeClass( 'active' );
		$( this ).addClass( 'active' );
		state.currentStatus = $( this ).data( 'status' );
		state.currentPage = 1;
		state.selectedIds = {};
		loadResults();
	}

	function loadResults() {
		$.post( hkmoData.ajaxUrl, {
			action: 'hkmo_get_results',
			nonce: hkmoData.nonce,
			status: state.currentStatus,
			page: state.currentPage,
		} ).done( function ( response ) {
			if ( ! response.success ) {
				return;
			}
			renderResults( response.data );
		} );
	}

	function renderResults( data ) {
		var $tbody = $( '#hkmo-results-tbody' );
		$tbody.empty();

		$( '#hkmo-unused-count' ).text( numberFormat( data.unused_count ) );
		$( '#hkmo-used-count' ).text( numberFormat( data.used_count ) );
		$( '#hkmo-reclaimable-size' ).text( formatBytes( data.reclaimable ) );

		if ( ! data.items.length ) {
			$tbody.append(
				'<tr><td colspan="6" class="hkmo-no-results">' +
				$( '<div>' ).text( 'No items found.' ).html() +
				'</td></tr>'
			);
			renderPagination( data );
			return;
		}

		data.items.forEach( function ( item ) {
			var $row = $( '<tr>' ).attr( 'data-id', item.id );

			var $check = $( '<td>' ).append(
				$( '<input type="checkbox" class="hkmo-row-check">' ).val( item.id )
			);

			var $thumbCell = $( '<td>' );
			if ( item.thumb ) {
				$thumbCell.append( $( '<img class="hkmo-thumb">' ).attr( 'src', item.thumb ).attr( 'alt', '' ) );
			} else {
				$thumbCell.append( $( '<div class="hkmo-no-thumb">—</div>' ) );
			}

			var $fileCell = $( '<td>' );
			var $titleSpan = $( '<span class="hkmo-filename">' ).text( item.title );
			var $metaSpan = $( '<span class="hkmo-file-meta">' ).text( item.filename );
			$fileCell.append( $titleSpan, $metaSpan );

			var $reasonCell = $( '<td>' ).text( item.reason || '—' );
			var $sizeCell = $( '<td>' ).text( formatBytes( item.file_size ) );

			var $actionsCell = $( '<td>' );
			if ( item.edit_link ) {
				$actionsCell.append(
					$( '<a target="_blank">' ).attr( 'href', item.edit_link ).text( 'View' ),
					document.createTextNode( ' | ' )
				);
			}
			if ( 'unused' === item.status ) {
				$actionsCell.append(
					$( '<a href="#" class="hkmo-delete-single">' ).attr( 'data-id', item.id ).text( 'Delete' )
				);
			}

			$row.append( $check, $thumbCell, $fileCell, $reasonCell, $sizeCell, $actionsCell );
			$tbody.append( $row );
		} );

		renderPagination( data );
		updateDeleteButtonState();
	}

	function renderPagination( data ) {
		var $pagination = $( '#hkmo-pagination' );
		$pagination.empty();

		var totalPages = Math.max( 1, Math.ceil( data.total / data.per_page ) );
		if ( totalPages <= 1 ) {
			return;
		}

		for ( var i = 1; i <= totalPages; i++ ) {
			var $btn = $( '<button type="button">' ).text( i );
			if ( i === state.currentPage ) {
				$btn.addClass( 'active' );
			}
			$btn.on( 'click', ( function ( pageNum ) {
				return function () {
					state.currentPage = pageNum;
					loadResults();
				};
			} )( i ) );
			$pagination.append( $btn );
		}
	}

	function onSelectAll() {
		var checked = $( this ).is( ':checked' );
		$( '.hkmo-row-check' ).prop( 'checked', checked ).trigger( 'change' );
	}

	function onRowCheck() {
		var id = $( this ).val();
		if ( $( this ).is( ':checked' ) ) {
			state.selectedIds[ id ] = true;
		} else {
			delete state.selectedIds[ id ];
		}
		updateDeleteButtonState();
	}

	function updateDeleteButtonState() {
		var count = Object.keys( state.selectedIds ).length;
		$( '#hkmo-delete-selected' ).prop( 'disabled', count === 0 );
	}

	function onDeleteSelectedClick() {
		var ids = Object.keys( state.selectedIds );
		if ( ! ids.length ) {
			return;
		}
		openModal( ids );
	}

	function onDeleteSingleClick( e ) {
		e.preventDefault();
		var id = $( this ).data( 'id' );
		openModal( [ String( id ) ] );
	}

	function openModal( ids ) {
		state.pendingDeleteIds = ids;
		$( '#hkmo-modal-summary' ).text(
			ids.length + ' file(s) selected for permanent deletion.'
		);

		if ( hkmoData.requireTypeConfirm ) {
			$( '#hkmo-modal-confirm-type' ).show();
			$( '#hkmo-confirm-input' ).val( '' );
		} else {
			$( '#hkmo-modal-confirm-type' ).hide();
		}

		$( '#hkmo-delete-modal' ).show();
	}

	function closeModal() {
		$( '#hkmo-delete-modal' ).hide();
		state.pendingDeleteIds = [];
	}

	function confirmDelete() {
		if ( hkmoData.requireTypeConfirm ) {
			var typed = $( '#hkmo-confirm-input' ).val();
			if ( 'DELETE' !== typed ) {
				alert( hkmoData.i18n.deleteMismatch );
				return;
			}
		}

		var ids = state.pendingDeleteIds;
		$( '#hkmo-modal-confirm' ).prop( 'disabled', true ).text( hkmoData.i18n.deleting );

		$.post( hkmoData.ajaxUrl, {
			action: 'hkmo_delete_attachments',
			nonce: hkmoData.nonce,
			ids: ids,
		} ).done( function ( response ) {
			closeModal();
			$( '#hkmo-modal-confirm' ).prop( 'disabled', false ).text( 'Delete Permanently' );
			if ( response.success ) {
				state.selectedIds = {};
				loadResults();
			} else {
				alert( hkmoData.i18n.error );
			}
		} ).fail( function () {
			closeModal();
			$( '#hkmo-modal-confirm' ).prop( 'disabled', false ).text( 'Delete Permanently' );
			alert( hkmoData.i18n.error );
		} );
	}

	function formatBytes( bytes ) {
		if ( ! bytes ) {
			return '0 B';
		}
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = 0;
		while ( bytes >= 1024 && i < units.length - 1 ) {
			bytes /= 1024;
			i++;
		}
		return bytes.toFixed( 1 ) + ' ' + units[ i ];
	}

	function numberFormat( n ) {
		return ( n || 0 ).toLocaleString();
	}
} )( jQuery );
