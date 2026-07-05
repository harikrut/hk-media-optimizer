<?php
/**
 * CSV export of scan results.
 *
 * Streams the file directly to the browser in chunks (reusing HKMO_DB's
 * existing paginated query) instead of loading every row into memory at
 * once, keeping the same "never do more work than necessary in one request"
 * design principle the rest of the plugin follows.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HKMO_Exporter {

	/**
	 * Number of rows fetched from the database per chunk while streaming.
	 * Kept separate from the scan batch_size setting since exporting a row
	 * is far cheaper than scanning one.
	 *
	 * @var int
	 */
	const CHUNK_SIZE = 500;

	/**
	 * Neutralize CSV "formula injection": a cell beginning with =, +, -, @, or a
	 * control character can be executed as a formula by Excel/Sheets when the
	 * exported file is opened. Prefixing such values with a single quote forces
	 * them to be treated as plain text, without altering what the user sees.
	 *
	 * @param mixed $value Raw cell value.
	 * @return mixed Sanitized value (non-strings returned unchanged).
	 */
	private function escape_cell( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Output a CSV of scan results for the given status filter and end the request.
	 *
	 * @param string $status 'used', 'unused', or 'all'.
	 */
	public function export_csv( $status ) {
		$allowed_statuses = array( 'used', 'unused', 'all' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'unused';
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="hk-media-optimizer-' . $status . '-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM so the file opens correctly (with non-ASCII filenames/titles
		// intact) in Excel, which otherwise assumes ANSI encoding for CSVs.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $output, "\xEF\xBB\xBF" );

		fputcsv(
			$output,
			array(
				__( 'Attachment ID', 'hk-media-optimizer' ),
				__( 'Title', 'hk-media-optimizer' ),
				__( 'Filename', 'hk-media-optimizer' ),
				__( 'Status', 'hk-media-optimizer' ),
				__( 'Reason', 'hk-media-optimizer' ),
				__( 'File Size (bytes)', 'hk-media-optimizer' ),
				__( 'File URL', 'hk-media-optimizer' ),
				__( 'Scanned At', 'hk-media-optimizer' ),
			)
		);

		$page = 1;

		do {
			$rows = HKMO_DB::get_results( $status, self::CHUNK_SIZE, $page );

			foreach ( $rows as $row ) {
				$attachment_id = (int) $row->attachment_id;
				$title         = get_the_title( $attachment_id );
				$filename      = wp_basename( get_attached_file( $attachment_id ) );
				$url           = wp_get_attachment_url( $attachment_id );

				fputcsv(
					$output,
					array(
						$attachment_id,
						$this->escape_cell( $title ? $title : $filename ),
						$this->escape_cell( $filename ),
						$this->escape_cell( $row->status ),
						$this->escape_cell( $row->reason ),
						(int) $row->file_size,
						$this->escape_cell( $url ? $url : '' ),
						$this->escape_cell( $row->scanned_at ),
					)
				);
			}

			// Flush periodically so a large export streams progressively
			// instead of buffering everything before the browser sees it.
			flush();
			++$page;
		} while ( count( $rows ) === self::CHUNK_SIZE );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );
		exit;
	}
}
