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
