<?php
/**
 * Admin-facing functionality: menu registration, asset loading, settings save.
 *
 * @package HK_Media_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HKMO_Admin {

	/**
	 * Hook suffix of the main scanner page, used to scope asset loading
	 * so CSS/JS only loads on this plugin's own admin screens — never
	 * site-wide — keeping the "lightweight" promise on every other admin page.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $settings_page_hook = '';

	/**
	 * Register WordPress hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_hkmo_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_hkmo_export_csv', array( $this, 'handle_export_csv' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( HKMO_PLUGIN_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Register the top-level menu and its two subpages (Scanner, Settings).
	 */
	public function register_menu() {
		$this->page_hook = add_menu_page(
			__( 'HK Media Optimizer', 'hk-media-optimizer' ),
			__( 'Media Optimizer', 'hk-media-optimizer' ),
			'manage_options',
			'hk-media-optimizer',
			array( $this, 'render_scanner_page' ),
			'dashicons-trash',
			null
		);

		add_submenu_page(
			'hk-media-optimizer',
			__( 'Scanner', 'hk-media-optimizer' ),
			__( 'Scanner', 'hk-media-optimizer' ),
			'manage_options',
			'hk-media-optimizer',
			array( $this, 'render_scanner_page' )
		);

		$this->settings_page_hook = add_submenu_page(
			'hk-media-optimizer',
			__( 'Settings', 'hk-media-optimizer' ),
			__( 'Settings', 'hk-media-optimizer' ),
			'manage_options',
			'hk-media-optimizer-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue CSS/JS only on this plugin's own admin pages.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->page_hook && $hook !== $this->settings_page_hook ) {
			return;
		}

		wp_enqueue_style(
			'hkmo_admin',
			HKMO_PLUGIN_URL . 'assets/css/hkmo_admin.css',
			array(),
			HKMO_VERSION
		);

		// Both the Scanner and Settings screens need the script now: Scanner
		// for scanning/duplicates/export, Settings for the "send test report"
		// button and toggling the schedule fields.
		wp_enqueue_script(
			'hkmo_admin',
			HKMO_PLUGIN_URL . 'assets/js/hkmo_admin.js',
			array( 'jquery' ),
			HKMO_VERSION,
			true
		);

		wp_localize_script(
			'hkmo_admin',
			'hkmoData',
			array(
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'nonce'               => wp_create_nonce( 'hkmo_admin_nonce' ),
				'exportUrl'           => admin_url( 'admin-post.php' ),
				'exportNonce'         => wp_create_nonce( 'hkmo_export_csv' ),
				'isScannerPage'       => ( $hook === $this->page_hook ),
				'requireTypeConfirm'  => (bool) HKMO_Settings::get( 'require_type_confirm' ),
				'i18n'                => array(
					'confirmDelete'        => __( 'Type DELETE to confirm permanent removal of the selected files.', 'hk-media-optimizer' ),
					'deleteMismatch'       => __( 'Text did not match. Deletion cancelled.', 'hk-media-optimizer' ),
					'scanning'             => __( 'Scanning…', 'hk-media-optimizer' ),
					'scheduledScanRunning' => __( 'Scheduled scan running in background…', 'hk-media-optimizer' ),
					'scanComplete'         => __( 'Scan complete.', 'hk-media-optimizer' ),
					'noSelection'          => __( 'Please select at least one file.', 'hk-media-optimizer' ),
					'deleting'             => __( 'Deleting…', 'hk-media-optimizer' ),
					'deleted'              => __( 'Deleted successfully.', 'hk-media-optimizer' ),
					'error'                => __( 'Something went wrong. Please try again.', 'hk-media-optimizer' ),
					'findingDuplicates'    => __( 'Looking for duplicate files…', 'hk-media-optimizer' ),
					'duplicateScanDone'    => __( 'Duplicate scan complete.', 'hk-media-optimizer' ),
					'noDuplicates'         => __( 'No duplicate files found. Your library is clean!', 'hk-media-optimizer' ),
					'sendingTestReport'    => __( 'Sending…', 'hk-media-optimizer' ),
					'files'                => __( 'files', 'hk-media-optimizer' ),
				),
			)
		);
	}

	/**
	 * Render the main scanner/results admin page.
	 */
	public function render_scanner_page() {
		require_once HKMO_PLUGIN_DIR . 'admin/views/view-scanner.php';
	}

	/**
	 * Render the settings admin page.
	 */
	public function render_settings_page() {
		require_once HKMO_PLUGIN_DIR . 'admin/views/view-settings.php';
	}

	/**
	 * Handle the settings form submission (non-AJAX, standard POST to admin-post.php).
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hk-media-optimizer' ) );
		}

		check_admin_referer( 'hkmo_save_settings' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via check_admin_referer.
		$input = wp_unslash( $_POST );
		HKMO_Settings::save_from_request( $input );

		wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * Stream a CSV export of scan results and end the request.
	 * Triggered by a plain link/navigation (not AJAX) since the browser
	 * needs to treat the response as a file download.
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'hk-media-optimizer' ) );
		}

		check_admin_referer( 'hkmo_export_csv' );

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'unused';

		$exporter = new HKMO_Exporter();
		$exporter->export_csv( $status ); // Streams the file and calls exit().
	}

	/**
	 * Add a "Settings" link on the Plugins list page for quick access.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=hk-media-optimizer-settings' ) ) . '">' . esc_html__( 'Settings', 'hk-media-optimizer' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}
