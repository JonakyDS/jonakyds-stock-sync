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
        register_setting('jonakyds_stock_sync_settings', 'jonakyds_stock_sync_schedule', array($this, 'reschedule_cron_on_change'));
        register_setting('jonakyds_stock_sync_settings', 'jonakyds_stock_sync_sku_column');
        register_setting('jonakyds_stock_sync_settings', 'jonakyds_stock_sync_stock_column');
        register_setting('jonakyds_stock_sync_settings', 'jonakyds_stock_sync_ssl_verify');
    }

    /**
     * Reschedule cron when schedule setting changes
     */
    public function reschedule_cron_on_change($new_value) {
        $old_value = get_option('jonakyds_stock_sync_schedule');
        
        // Only reschedule if the value actually changed
        if ($old_value !== $new_value) {
            // Clear existing schedule
            $timestamp = wp_next_scheduled('jonakyds_stock_sync_cron');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'jonakyds_stock_sync_cron');
            }
            
            // Schedule with new interval
            wp_schedule_event(time(), $new_value, 'jonakyds_stock_sync_cron');
        }
        
        return $new_value;
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
            
            /* Modern Progress UI */
            .jonakyds-progress-container {
                display: none;
                margin: 20px 0;
                padding: 30px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
                color: white;
            }
            .jonakyds-progress-container.active {
                display: block;
            }
            .jonakyds-progress-title {
                font-size: 20px;
                font-weight: 600;
                margin-bottom: 10px;
                text-align: center;
            }
            .jonakyds-progress-message {
                text-align: center;
                margin-bottom: 20px;
                font-size: 14px;
                opacity: 0.9;
            }
            .jonakyds-progress-bar-container {
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50px;
                height: 30px;
                overflow: hidden;
                margin-bottom: 15px;
                position: relative;
            }
            .jonakyds-progress-bar {
                height: 100%;
                background: linear-gradient(90deg, #4ade80 0%, #22c55e 100%);
                border-radius: 50px;
                transition: width 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                font-weight: 600;
                font-size: 13px;
                box-shadow: 0 2px 10px rgba(34, 197, 94, 0.4);
            }
            .jonakyds-progress-stats {
                display: flex;
                justify-content: space-around;
                margin-top: 20px;
                flex-wrap: wrap;
                gap: 15px;
            }
            .jonakyds-progress-stat {
                background: rgba(255, 255, 255, 0.15);
                padding: 15px 25px;
                border-radius: 8px;
                text-align: center;
                backdrop-filter: blur(10px);
                flex: 1;
                min-width: 120px;
            }
            .jonakyds-progress-stat-value {
                font-size: 28px;
                font-weight: 700;
                display: block;
                margin-bottom: 5px;
            }
            .jonakyds-progress-stat-label {
                font-size: 12px;
                opacity: 0.9;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .jonakyds-sync-button {
                position: relative;
                overflow: hidden;
            }
            .jonakyds-sync-button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            .jonakyds-spinner {
                display: inline-block;
                width: 16px;
                height: 16px;
                border: 2px solid rgba(255,255,255,0.3);
                border-top-color: white;
                border-radius: 50%;
                animation: spin 0.8s linear infinite;
                margin-right: 8px;
                vertical-align: middle;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
            .jonakyds-complete-message {
                display: none;
                padding: 20px;
                background: #fff;
                border-left: 4px solid #46b450;
                border-radius: 4px;
                margin-top: 20px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            .jonakyds-complete-message.show {
                display: block;
            }
            .jonakyds-complete-message.error {
                border-left-color: #dc3232;
            }
            .jonakyds-complete-icon {
                font-size: 24px;
                margin-right: 10px;
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
        $sku_column = get_option('jonakyds_stock_sync_sku_column', 'Artnr');
        $stock_column = get_option('jonakyds_stock_sync_stock_column', 'Lagerbestand');
        $ssl_verify = get_option('jonakyds_stock_sync_ssl_verify', 'yes');
        $logs = Jonakyds_Stock_Sync::get_logs();
        $next_sync = wp_next_scheduled('jonakyds_stock_sync_cron');

        ?>
        <div class="wrap">
            <h1><?php _e('Stock Sync Settings', 'jonakyds-stock-sync'); ?></h1>
            
            <?php settings_errors(); ?>

            <?php if (isset($_GET['sync_result'])): ?>
                <div class="notice notice-<?php echo $_GET['sync_result'] === 'success' ? 'success' : 'error'; ?> is-dismissible">
                    <p><?php echo esc_html(urldecode($_GET['sync_message'])); ?></p>
                </div>
            <?php endif; ?>

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
                                placeholder="Artnr"
                            />
                            <small><?php _e('The CSV column name that contains the product SKU (case insensitive). Default: Artnr', 'jonakyds-stock-sync'); ?></small>
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
                                placeholder="Lagerbestand"
                            />
                            <small><?php _e('The CSV column name that contains the stock quantity (case insensitive). Default: Lagerbestand', 'jonakyds-stock-sync'); ?></small>
                        </div>

                        <div class="jonakyds-form-row">
                            <label for="jonakyds_stock_sync_ssl_verify">
                                <input 
                                    type="checkbox" 
                                    id="jonakyds_stock_sync_ssl_verify" 
                                    name="jonakyds_stock_sync_ssl_verify" 
                                    value="yes"
                                    <?php checked($ssl_verify, 'yes'); ?>
                                />
                                <?php _e('Verify SSL certificate', 'jonakyds-stock-sync'); ?>
                            </label>
                            <small><?php _e('Disable this if you get SSL certificate errors (not recommended for production).', 'jonakyds-stock-sync'); ?></small>
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
                                <option value="every_10_minutes" <?php selected($schedule, 'every_10_minutes'); ?>><?php _e('Every 10 Minutes', 'jonakyds-stock-sync'); ?></option>
                                <option value="hourly" <?php selected($schedule, 'hourly'); ?>><?php _e('Hourly', 'jonakyds-stock-sync'); ?></option>
                                <option value="twicedaily" <?php selected($schedule, 'twicedaily'); ?>><?php _e('Twice Daily', 'jonakyds-stock-sync'); ?></option>
                                <option value="daily" <?php selected($schedule, 'daily'); ?>><?php _e('Daily', 'jonakyds-stock-sync'); ?></option>
                            </select>
                            <?php if ($next_sync): ?>
                                <small>
                                    <?php 
                                    $timezone_string = get_option('timezone_string');
                                    if (empty($timezone_string)) {
                                        $timezone_string = 'UTC';
                                    }
                                    
                                    // Check if next sync is in the past (shouldn't happen, but just in case)
                                    $current_time = current_time('timestamp');
                                    if ($next_sync < $current_time) {
                                        _e('Waiting for WordPress cron to run...', 'jonakyds-stock-sync');
                                    } else {
                                        printf(
                                            __('Next scheduled sync: %s (%s)', 'jonakyds-stock-sync'),
                                            date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_sync),
                                            $timezone_string
                                        );
                                    }
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
                    <p><?php _e('Click the button below to sync stock immediately with real-time progress.', 'jonakyds-stock-sync'); ?></p>
                    
                    <button type="button" id="jonakyds-sync-now" class="button button-primary button-hero jonakyds-sync-button">
                        <?php _e('Sync Now', 'jonakyds-stock-sync'); ?>
                    </button>

                    <!-- Progress Container -->
                    <div id="jonakyds-progress-container" class="jonakyds-progress-container">
                        <div class="jonakyds-progress-title"><?php _e('Syncing Stock...', 'jonakyds-stock-sync'); ?></div>
                        <div id="jonakyds-progress-message" class="jonakyds-progress-message">
                            <?php _e('Initializing...', 'jonakyds-stock-sync'); ?>
                        </div>
                        <div class="jonakyds-progress-bar-container">
                            <div id="jonakyds-progress-bar" class="jonakyds-progress-bar" style="width: 0%;">
                                <span id="jonakyds-progress-percent">0%</span>
                            </div>
                        </div>
                        <div class="jonakyds-progress-stats">
                            <div class="jonakyds-progress-stat">
                                <span id="jonakyds-stat-updated" class="jonakyds-progress-stat-value">0</span>
                                <span class="jonakyds-progress-stat-label"><?php _e('Updated', 'jonakyds-stock-sync'); ?></span>
                            </div>
                            <div class="jonakyds-progress-stat">
                                <span id="jonakyds-stat-skipped" class="jonakyds-progress-stat-value">0</span>
                                <span class="jonakyds-progress-stat-label"><?php _e('Skipped', 'jonakyds-stock-sync'); ?></span>
                            </div>
                            <div class="jonakyds-progress-stat">
                                <span id="jonakyds-stat-total" class="jonakyds-progress-stat-value">-</span>
                                <span class="jonakyds-progress-stat-label"><?php _e('Total', 'jonakyds-stock-sync'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Completion Message -->
                    <div id="jonakyds-complete-message" class="jonakyds-complete-message"></div>
                </div>

                <script>
                jQuery(document).ready(function($) {
                    let syncInterval = null;
                    let currentSyncId = null;
                    let isCheckingSync = false;
                    let isSyncButtonDisabled = false;
                    
                    // Check for active sync on page load
                    checkActiveSync();
                    
                    function checkActiveSync() {
                        if (isCheckingSync) {
                            return; // Already checking, prevent duplicate
                        }
                        
                        isCheckingSync = true;
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'jonakyds_get_active_sync',
                                nonce: '<?php echo wp_create_nonce('jonakyds_ajax_sync'); ?>'
                            },
                            success: function(response) {
                                if (response.success && response.data.active) {
                                    // Resume the active sync
                                    currentSyncId = response.data.sync_id;
                                    const $button = $('#jonakyds-sync-now');
                                    const $progressContainer = $('#jonakyds-progress-container');
                                    const $completeMessage = $('#jonakyds-complete-message');
                                    
                                    isSyncButtonDisabled = true;
                                    $button.prop('disabled', true).html('<span class="jonakyds-spinner"></span><?php _e('Syncing...', 'jonakyds-stock-sync'); ?>');
                                    $progressContainer.addClass('active');
                                    $completeMessage.removeClass('show error');
                                    
                                    // Update with current progress
                                    const data = response.data.progress;
                                    updateProgress(data.percent || 0, data.message || '<?php _e('Resuming sync...', 'jonakyds-stock-sync'); ?>');
                                    if (data.updated !== undefined) $('#jonakyds-stat-updated').text(data.updated);
                                    if (data.skipped !== undefined) $('#jonakyds-stat-skipped').text(data.skipped);
                                    if (data.total !== undefined && data.total > 0) $('#jonakyds-stat-total').text(data.total);
                                    
                                    // Start polling
                                    pollProgress();
                                } else {
                                    // No active sync
                                    isSyncButtonDisabled = false;
                                }
                                isCheckingSync = false;
                            },
                            error: function() {
                                isCheckingSync = false;
                                isSyncButtonDisabled = false;
                            }
                        });
                    }
                    
                    $('#jonakyds-sync-now').on('click', function() {
                        // Prevent multiple clicks
                        if (isSyncButtonDisabled) {
                            return false;
                        }
                        
                        isSyncButtonDisabled = true;
                        
                        const $button = $(this);
                        const $progressContainer = $('#jonakyds-progress-container');
                        const $completeMessage = $('#jonakyds-complete-message');
                        
                        // Reset UI
                        $button.prop('disabled', true).html('<span class="jonakyds-spinner"></span><?php _e('Syncing...', 'jonakyds-stock-sync'); ?>');
                        $progressContainer.addClass('active');
                        $completeMessage.removeClass('show error');
                        
                        // Reset progress
                        updateProgress(0, '<?php _e('Initializing...', 'jonakyds-stock-sync'); ?>');
                        $('#jonakyds-stat-updated').text('0');
                        $('#jonakyds-stat-skipped').text('0');
                        $('#jonakyds-stat-total').text('-');
                        
                        // Start sync
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'jonakyds_start_sync',
                                nonce: '<?php echo wp_create_nonce('jonakyds_ajax_sync'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    currentSyncId = response.data.sync_id;
                                    // Start polling for progress
                                    pollProgress();
                                } else {
                                    // Check if there's an active sync to resume
                                    if (response.data && response.data.active_sync_id) {
                                        currentSyncId = response.data.active_sync_id;
                                        pollProgress();
                                    } else {
                                        showComplete(false, response.data.message || '<?php _e('Failed to start sync', 'jonakyds-stock-sync'); ?>');
                                        $button.prop('disabled', false).html('<?php _e('Sync Now', 'jonakyds-stock-sync'); ?>');
                                        $progressContainer.removeClass('active');
                                        isSyncButtonDisabled = false;
                                    }
                                }
                            },
                            error: function() {
                                showComplete(false, '<?php _e('Failed to start sync', 'jonakyds-stock-sync'); ?>');
                                $button.prop('disabled', false).html('<?php _e('Sync Now', 'jonakyds-stock-sync'); ?>');
                                $progressContainer.removeClass('active');
                                isSyncButtonDisabled = false;
                            }
                        });
                    });
                    
                    function pollProgress() {
                        syncInterval = setInterval(function() {
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'jonakyds_get_progress',
                                    nonce: '<?php echo wp_create_nonce('jonakyds_ajax_sync'); ?>',
                                    sync_id: currentSyncId
                                },
                                success: function(response) {
                                    if (response.success) {
                                        const data = response.data;
                                        
                                        updateProgress(data.percent, data.message);
                                        
                                        if (data.updated !== undefined) {
                                            $('#jonakyds-stat-updated').text(data.updated);
                                        }
                                        if (data.skipped !== undefined) {
                                            $('#jonakyds-stat-skipped').text(data.skipped);
                                        }
                                        if (data.total !== undefined && data.total > 0) {
                                            $('#jonakyds-stat-total').text(data.total);
                                        }
                                        
                                        // Check if complete
                                        if (data.status === 'complete') {
                                            clearInterval(syncInterval);
                                            const $button = $('#jonakyds-sync-now');
                                            const $progressContainer = $('#jonakyds-progress-container');
                                            
                                            isSyncButtonDisabled = false;
                                            $button.prop('disabled', false).html('<?php _e('Sync Now', 'jonakyds-stock-sync'); ?>');
                                            
                                            setTimeout(() => {
                                                showComplete(true, data.message);
                                                $progressContainer.removeClass('active');
                                                
                                                // Reload logs after 2 seconds
                                                setTimeout(() => {
                                                    location.reload();
                                                }, 2000);
                                            }, 500);
                                        } else if (data.status === 'error') {
                                            clearInterval(syncInterval);
                                            const $button = $('#jonakyds-sync-now');
                                            const $progressContainer = $('#jonakyds-progress-container');
                                            
                                            isSyncButtonDisabled = false;
                                            $button.prop('disabled', false).html('<?php _e('Sync Now', 'jonakyds-stock-sync'); ?>');
                                            showComplete(false, data.message || '<?php _e('Sync failed', 'jonakyds-stock-sync'); ?>');
                                            $progressContainer.removeClass('active');
                                        }
                                    }
                                }
                            });
                        }, 1000); // Poll every 1 second
                    }
                    
                    function updateProgress(percent, message) {
                        $('#jonakyds-progress-bar').css('width', percent + '%');
                        $('#jonakyds-progress-percent').text(percent + '%');
                        $('#jonakyds-progress-message').text(message);
                    }
                    
                    function showComplete(success, message) {
                        const $msg = $('#jonakyds-complete-message');
                        const icon = success ? '✓' : '✗';
                        $msg.html('<span class="jonakyds-complete-icon">' + icon + '</span>' + message);
                        $msg.addClass('show');
                        if (!success) {
                            $msg.addClass('error');
                        }
                    }
                });
                </script>

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
                                    <?php 
                                    $timezone_string = get_option('timezone_string');
                                    if (empty($timezone_string)) {
                                        $timezone_string = 'UTC';
                                    }
                                    echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log['timestamp']));
                                    echo ' (' . $timezone_string . ')';
                                    ?>
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
