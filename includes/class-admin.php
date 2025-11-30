<?php
/**
 * Admin Interface Class
 *
 * Handles the plugin admin settings page
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Jonakyds_Stock_Sync_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_jonakyds_sync_now', array($this, 'handle_manual_sync'));
        add_action('admin_post_jonakyds_clear_logs', array($this, 'handle_clear_logs'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_styles'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Stock Sync', 'jonakyds-stock-sync'),
            __('Stock Sync', 'jonakyds-stock-sync'),
            'manage_woocommerce',
            'jonakyds-stock-sync',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('jonakyds_stock_sync_settings', 'jonakyds_stock_sync_csv_url');
        register_setting('jonakyds_stock_sync_settings', 'jonakyds_stock_sync_enabled');
        register_setting('jonakyds_stock_sync_settings', 'jonakyds_stock_sync_schedule');
        register_setting('jonakyds_stock_sync_settings', 'jonakyds_stock_sync_sku_column');
        register_setting('jonakyds_stock_sync_settings', 'jonakyds_stock_sync_stock_column');
    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_styles($hook) {
        if ($hook !== 'woocommerce_page_jonakyds-stock-sync') {
            return;
        }

        wp_add_inline_style('wp-admin', '
            .jonakyds-sync-container {
                max-width: 1200px;
                margin: 20px 0;
            }
            .jonakyds-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0,0,0,.04);
                margin-bottom: 20px;
                padding: 20px;
            }
            .jonakyds-card h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }
            .jonakyds-form-row {
                margin-bottom: 20px;
            }
            .jonakyds-form-row label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
            }
            .jonakyds-form-row input[type="text"],
            .jonakyds-form-row select {
                width: 100%;
                max-width: 500px;
            }
            .jonakyds-form-row small {
                display: block;
                margin-top: 5px;
                color: #666;
            }
            .jonakyds-log-entry {
                padding: 10px;
                margin-bottom: 10px;
                border-left: 4px solid #ccc;
                background: #f9f9f9;
            }
            .jonakyds-log-entry.success {
                border-left-color: #46b450;
            }
            .jonakyds-log-entry.error {
                border-left-color: #dc3232;
            }
            .jonakyds-log-timestamp {
                font-size: 12px;
                color: #666;
            }
            .jonakyds-log-errors {
                margin-top: 5px;
                padding-left: 20px;
                font-size: 13px;
                color: #dc3232;
            }
        ');
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Check if user has permission
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'jonakyds-stock-sync'));
        }

        $csv_url = get_option('jonakyds_stock_sync_csv_url', '');
        $enabled = get_option('jonakyds_stock_sync_enabled', 'no');
        $schedule = get_option('jonakyds_stock_sync_schedule', 'hourly');
        $sku_column = get_option('jonakyds_stock_sync_sku_column', 'sku');
        $stock_column = get_option('jonakyds_stock_sync_stock_column', 'stock');
        $logs = Jonakyds_Stock_Sync::get_logs();
        $next_sync = wp_next_scheduled('jonakyds_stock_sync_cron');

        ?>
        <div class="wrap">
            <h1><?php _e('Stock Sync Settings', 'jonakyds-stock-sync'); ?></h1>
            
            <?php settings_errors(); ?>

            <div class="jonakyds-sync-container">
                <!-- Settings Card -->
                <div class="jonakyds-card">
                    <h2><?php _e('Configuration', 'jonakyds-stock-sync'); ?></h2>
                    <form method="post" action="options.php">
                        <?php settings_fields('jonakyds_stock_sync_settings'); ?>
                        
                        <div class="jonakyds-form-row">
                            <label for="jonakyds_stock_sync_csv_url">
                                <?php _e('CSV URL', 'jonakyds-stock-sync'); ?>
                            </label>
                            <input 
                                type="text" 
                                id="jonakyds_stock_sync_csv_url" 
                                name="jonakyds_stock_sync_csv_url" 
                                value="<?php echo esc_attr($csv_url); ?>" 
                                class="regular-text"
                                placeholder="https://example.com/stock.csv"
                            />
                            <small><?php _e('Enter the URL of the CSV file containing stock data.', 'jonakyds-stock-sync'); ?></small>
                        </div>

                        <div class="jonakyds-form-row">
                            <label for="jonakyds_stock_sync_sku_column">
                                <?php _e('SKU Column Name', 'jonakyds-stock-sync'); ?>
                            </label>
                            <input 
                                type="text" 
                                id="jonakyds_stock_sync_sku_column" 
                                name="jonakyds_stock_sync_sku_column" 
                                value="<?php echo esc_attr($sku_column); ?>" 
                                class="regular-text"
                                placeholder="sku"
                            />
                            <small><?php _e('The CSV column name that contains the product SKU (case insensitive).', 'jonakyds-stock-sync'); ?></small>
                        </div>

                        <div class="jonakyds-form-row">
                            <label for="jonakyds_stock_sync_stock_column">
                                <?php _e('Stock Column Name', 'jonakyds-stock-sync'); ?>
                            </label>
                            <input 
                                type="text" 
                                id="jonakyds_stock_sync_stock_column" 
                                name="jonakyds_stock_sync_stock_column" 
                                value="<?php echo esc_attr($stock_column); ?>" 
                                class="regular-text"
                                placeholder="stock"
                            />
                            <small><?php _e('The CSV column name that contains the stock quantity (case insensitive).', 'jonakyds-stock-sync'); ?></small>
                        </div>

                        <div class="jonakyds-form-row">
                            <label for="jonakyds_stock_sync_enabled">
                                <input 
                                    type="checkbox" 
                                    id="jonakyds_stock_sync_enabled" 
                                    name="jonakyds_stock_sync_enabled" 
                                    value="yes"
                                    <?php checked($enabled, 'yes'); ?>
                                />
                                <?php _e('Enable automatic sync', 'jonakyds-stock-sync'); ?>
                            </label>
                            <small><?php _e('When enabled, stock will be synced automatically based on the schedule below.', 'jonakyds-stock-sync'); ?></small>
                        </div>

                        <div class="jonakyds-form-row">
                            <label for="jonakyds_stock_sync_schedule">
                                <?php _e('Sync Schedule', 'jonakyds-stock-sync'); ?>
                            </label>
                            <select id="jonakyds_stock_sync_schedule" name="jonakyds_stock_sync_schedule">
                                <option value="hourly" <?php selected($schedule, 'hourly'); ?>><?php _e('Hourly', 'jonakyds-stock-sync'); ?></option>
                                <option value="twicedaily" <?php selected($schedule, 'twicedaily'); ?>><?php _e('Twice Daily', 'jonakyds-stock-sync'); ?></option>
                                <option value="daily" <?php selected($schedule, 'daily'); ?>><?php _e('Daily', 'jonakyds-stock-sync'); ?></option>
                            </select>
                            <?php if ($next_sync): ?>
                                <small>
                                    <?php 
                                    printf(
                                        __('Next scheduled sync: %s', 'jonakyds-stock-sync'),
                                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_sync)
                                    ); 
                                    ?>
                                </small>
                            <?php endif; ?>
                        </div>

                        <?php submit_button(__('Save Settings', 'jonakyds-stock-sync')); ?>
                    </form>
                </div>

                <!-- Manual Sync Card -->
                <div class="jonakyds-card">
                    <h2><?php _e('Manual Sync', 'jonakyds-stock-sync'); ?></h2>
                    <p><?php _e('Click the button below to sync stock immediately.', 'jonakyds-stock-sync'); ?></p>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <input type="hidden" name="action" value="jonakyds_sync_now" />
                        <?php wp_nonce_field('jonakyds_sync_now'); ?>
                        <?php submit_button(__('Sync Now', 'jonakyds-stock-sync'), 'primary', 'submit', false); ?>
                    </form>
                </div>

                <!-- Sync Logs Card -->
                <div class="jonakyds-card">
                    <h2>
                        <?php _e('Sync Logs', 'jonakyds-stock-sync'); ?>
                        <?php if (!empty($logs)): ?>
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline-block; float: right;">
                                <input type="hidden" name="action" value="jonakyds_clear_logs" />
                                <?php wp_nonce_field('jonakyds_clear_logs'); ?>
                                <button type="submit" class="button button-small"><?php _e('Clear Logs', 'jonakyds-stock-sync'); ?></button>
                            </form>
                        <?php endif; ?>
                    </h2>
                    
                    <?php if (empty($logs)): ?>
                        <p><?php _e('No sync logs available.', 'jonakyds-stock-sync'); ?></p>
                    <?php else: ?>
                        <?php foreach (array_reverse($logs) as $log): ?>
                            <div class="jonakyds-log-entry <?php echo $log['success'] ? 'success' : 'error'; ?>">
                                <div class="jonakyds-log-timestamp">
                                    <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log['timestamp'])); ?>
                                </div>
                                <div class="jonakyds-log-message">
                                    <strong><?php echo esc_html($log['message']); ?></strong>
                                </div>
                                <?php if (!empty($log['errors'])): ?>
                                    <div class="jonakyds-log-errors">
                                        <ul>
                                            <?php foreach ($log['errors'] as $error): ?>
                                                <li><?php echo esc_html($error); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle manual sync
     */
    public function handle_manual_sync() {
        // Check nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'jonakyds_sync_now')) {
            wp_die(__('Security check failed.', 'jonakyds-stock-sync'));
        }

        // Check permission
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions.', 'jonakyds-stock-sync'));
        }

        // Perform sync
        $result = Jonakyds_Stock_Sync::sync_stock();

        // Redirect with message
        $redirect_url = add_query_arg(
            array(
                'page' => 'jonakyds-stock-sync',
                'sync_result' => $result['success'] ? 'success' : 'error',
                'sync_message' => urlencode($result['message'])
            ),
            admin_url('admin.php')
        );

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Handle clear logs
     */
    public function handle_clear_logs() {
        // Check nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'jonakyds_clear_logs')) {
            wp_die(__('Security check failed.', 'jonakyds-stock-sync'));
        }

        // Check permission
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions.', 'jonakyds-stock-sync'));
        }

        // Clear logs
        Jonakyds_Stock_Sync::clear_logs();

        // Redirect
        wp_redirect(admin_url('admin.php?page=jonakyds-stock-sync'));
        exit;
    }
}
