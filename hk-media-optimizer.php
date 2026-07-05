<?php
/**
 * Plugin Name:       HK Media Optimizer
 * Plugin URI:        https://www.harikrut.com/plugins/hk-media-optimizer
 * Description:       Lightweight, batch-based scanner that finds unused media files in your Media Library and lets you safely review and delete them. Built to run on shared hosting without spiking server load.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Harikrut Technolab
 * Author URI:        https://www.harikrut.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hk-media-optimizer
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

define('HKMO_VERSION', '1.0.0');
define('HKMO_PLUGIN_FILE', __FILE__);
define('HKMO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HKMO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HKMO_DB_VERSION', '1.1');
define('HKMO_TABLE_NAME', 'hkmo_scan_results');

/**
 * Composer-free, lightweight autoloading of plugin classes.
 * Avoids loading every class on every request; only loads what's hooked.
 */
require_once HKMO_PLUGIN_DIR . 'includes/class-hkmo-activator.php';
require_once HKMO_PLUGIN_DIR . 'includes/class-hkmo-deactivator.php';
require_once HKMO_PLUGIN_DIR . 'includes/class-hkmo-db.php';
require_once HKMO_PLUGIN_DIR . 'includes/class-hkmo-settings.php';
require_once HKMO_PLUGIN_DIR . 'includes/class-hkmo-scanner.php';
require_once HKMO_PLUGIN_DIR . 'includes/class-hkmo-optimizer.php';
require_once HKMO_PLUGIN_DIR . 'includes/class-hkmo-ajax.php';
require_once HKMO_PLUGIN_DIR . 'includes/class-hkmo-duplicate-finder.php';
require_once HKMO_PLUGIN_DIR . 'includes/class-hkmo-exporter.php';

/**
 * Load Action Scheduler.
 *
 * Action Scheduler is designed to be safely bundled in multiple plugins.
 * By requiring its main file directly, it natively registers its version
 * and ensures only the most recent bundled version across all active plugins
 * is initialized, preventing fatal errors and version conflicts.
 */
require_once HKMO_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

require_once HKMO_PLUGIN_DIR . 'includes/class-hkmo-scheduler.php';

/**
 * Only load admin-facing code in wp-admin / AJAX context.
 * Front-end page loads never touch this plugin's code, by design,
 * since media cleaning is purely an admin task.
 */
if (is_admin()) {
	require_once HKMO_PLUGIN_DIR . 'admin/class-hkmo-admin.php';
}

/**
 * Activation / deactivation hooks.
 */
register_activation_hook(__FILE__, array('HKMO_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('HKMO_Deactivator', 'deactivate'));

/**
 * Boot the plugin.
 */
function hkmo_run()
{
	if (is_admin()) {
		$admin = new HKMO_Admin();
		$admin->init();
	}

	$ajax = new HKMO_Ajax();
	$ajax->init();

	// The cron hooks themselves must fire regardless of admin/AJAX context,
	// since wp-cron.php requests don't load as is_admin().
	$scheduler = new HKMO_Scheduler();
	$scheduler->init();
}
hkmo_run();

/**
 * Bring the database schema up to date for sites that activated the plugin
 * before the duplicate-finder feature (and its dedicated table) existed.
 * dbDelta() is idempotent, so this is a cheap no-op once everyone's current.
 */
function hkmo_maybe_upgrade_db()
{
	if (get_option('hkmo_db_version') === HKMO_DB_VERSION) {
		return;
	}

	HKMO_DB::create_table();
	HKMO_DB::create_hashes_table();
	update_option('hkmo_db_version', HKMO_DB_VERSION);
}
add_action('plugins_loaded', 'hkmo_maybe_upgrade_db');

