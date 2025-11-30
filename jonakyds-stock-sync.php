<?php
/**
 * Plugin Name: Stock Sync (JonakyDS)
 * Plugin URI: https://github.com/JonakyDS/jonakyds-stock-sync
 * Description: Sync WooCommerce product stock from a CSV URL
 * Version: 1.2.3
 * Author: Jonaky Adhikary
 * Author URI: https://jonakyds.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jonakyds-stock-sync
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('JONAKYDS_STOCK_SYNC_VERSION', '1.2.3');
define('JONAKYDS_STOCK_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JONAKYDS_STOCK_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once JONAKYDS_STOCK_SYNC_PLUGIN_DIR . 'includes/class-stock-sync.php';
require_once JONAKYDS_STOCK_SYNC_PLUGIN_DIR . 'includes/class-sync-handler.php';
require_once JONAKYDS_STOCK_SYNC_PLUGIN_DIR . 'includes/class-admin.php';

// Initialize auto-update checker
require_once JONAKYDS_STOCK_SYNC_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$jonakydsUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/SourovCodes/jonakyds-stock-sync',
    __FILE__,
    'jonakyds-stock-sync'
);

// Set the branch that contains the stable release (optional, defaults to 'master')
$jonakydsUpdateChecker->setBranch('main');

// Enable release assets - the plugin will be downloaded from GitHub releases
$jonakydsUpdateChecker->getVcsApi()->enableReleaseAssets();

// Add filter to fix directory structure from GitHub ZIP
add_filter('upgrader_source_selection', 'jonakyds_stock_sync_fix_update_directory', 10, 4);

/**
 * Initialize the plugin
 */
function jonakyds_stock_sync_init() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'jonakyds_stock_sync_woocommerce_missing_notice');
        return;
    }

    // Initialize admin interface
    new Jonakyds_Stock_Sync_Admin();
}
add_action('plugins_loaded', 'jonakyds_stock_sync_init');

/**
 * Display admin notice if WooCommerce is not active
 */
function jonakyds_stock_sync_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('Jonakyds Stock Sync requires WooCommerce to be installed and activated.', 'jonakyds-stock-sync'); ?></p>
    </div>
    <?php
}

/**
 * Activation hook
 */
function jonakyds_stock_sync_activate() {
    // Set default options
    if (!get_option('jonakyds_stock_sync_csv_url')) {
        add_option('jonakyds_stock_sync_csv_url', '');
    }
    if (!get_option('jonakyds_stock_sync_schedule')) {
        add_option('jonakyds_stock_sync_schedule', 'hourly');
    }
    if (!get_option('jonakyds_stock_sync_enabled')) {
        add_option('jonakyds_stock_sync_enabled', 'no');
    }
    if (!get_option('jonakyds_stock_sync_sku_column')) {
        add_option('jonakyds_stock_sync_sku_column', 'sku');
    }
    if (!get_option('jonakyds_stock_sync_stock_column')) {
        add_option('jonakyds_stock_sync_stock_column', 'stock');
    }
    
    // Schedule cron job
    if (!wp_next_scheduled('jonakyds_stock_sync_cron')) {
        $schedule = get_option('jonakyds_stock_sync_schedule', 'hourly');
        // Schedule for 5 minutes after activation
        wp_schedule_event(time() + 300, $schedule, 'jonakyds_stock_sync_cron');
    }
}
register_activation_hook(__FILE__, 'jonakyds_stock_sync_activate');

/**
 * Deactivation hook
 */
function jonakyds_stock_sync_deactivate() {
    // Clear scheduled cron
    $timestamp = wp_next_scheduled('jonakyds_stock_sync_cron');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'jonakyds_stock_sync_cron');
    }
}
register_deactivation_hook(__FILE__, 'jonakyds_stock_sync_deactivate');

/**
 * Add custom cron schedule for 10 minutes
 */
function jonakyds_stock_sync_cron_schedules($schedules) {
    $schedules['every_10_minutes'] = array(
        'interval' => 600, // 10 minutes in seconds
        'display' => __('Every 10 Minutes', 'jonakyds-stock-sync')
    );
    return $schedules;
}
add_filter('cron_schedules', 'jonakyds_stock_sync_cron_schedules');

/**
 * Fix directory structure from GitHub ZIP
 * GitHub creates ZIPs with format: repo-name-tag/files
 * WordPress expects: plugin-slug/files
 */
function jonakyds_stock_sync_fix_update_directory($source, $remote_source, $upgrader, $extra) {
    global $wp_filesystem;
    
    // Only apply to our plugin
    if (!isset($extra['plugin']) || $extra['plugin'] !== 'jonakyds-stock-sync/jonakyds-stock-sync.php') {
        return $source;
    }
    
    // Get the expected directory name
    $new_source = trailingslashit($remote_source) . 'jonakyds-stock-sync/';
    
    // If directory structure is correct, return as is
    if ($source === $new_source) {
        return $source;
    }
    
    // Rename the directory to match expected structure
    if ($wp_filesystem->move($source, $new_source)) {
        return $new_source;
    }
    
    return new WP_Error('rename_failed', __('Unable to rename update directory.', 'jonakyds-stock-sync'));
}
