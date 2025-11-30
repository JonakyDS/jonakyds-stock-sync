<?php
/**
 * Sync Handler with AJAX Progress
 *
 * Handles real-time stock sync with progress updates via AJAX polling
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Jonakyds_Sync_Handler {

    /**
     * Start async sync
     */
    public static function start_sync() {
        // Security check
        check_ajax_referer('jonakyds_ajax_sync', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'jonakyds-stock-sync')));
        }

        // Check if there's already an active sync
        $existing_sync_id = get_option('jonakyds_active_sync_id');
        if ($existing_sync_id) {
            $existing_progress = get_transient('jonakyds_sync_progress_' . $existing_sync_id);
            if ($existing_progress && $existing_progress['status'] === 'running') {
                wp_send_json_error(array(
                    'message' => __('A sync is already in progress. Please wait for it to complete.', 'jonakyds-stock-sync'),
                    'active_sync_id' => $existing_sync_id
                ));
                return;
            }
        }

        // Generate unique sync ID
        $sync_id = uniqid('sync_', true);
        
        // Store as active sync
        update_option('jonakyds_active_sync_id', $sync_id);
        
        // Initialize progress
        self::update_progress($sync_id, array(
            'status' => 'running',
            'step' => 'init',
            'percent' => 0,
            'message' => __('Starting sync...', 'jonakyds-stock-sync'),
            'updated' => 0,
            'skipped' => 0,
            'total' => 0
        ));

        // Start background process
        wp_schedule_single_event(time(), 'jonakyds_background_sync', array($sync_id));
        spawn_cron();

        wp_send_json_success(array('sync_id' => $sync_id));
    }

    /**
     * Get sync progress
     */
    public static function get_progress() {
        check_ajax_referer('jonakyds_ajax_sync', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'jonakyds-stock-sync')));
        }

        $sync_id = isset($_POST['sync_id']) ? sanitize_text_field($_POST['sync_id']) : '';
        
        if (empty($sync_id)) {
            wp_send_json_error(array('message' => __('Invalid sync ID', 'jonakyds-stock-sync')));
        }

        $progress = get_transient('jonakyds_sync_progress_' . $sync_id);
        
        if ($progress === false) {
            wp_send_json_error(array('message' => __('Progress not found', 'jonakyds-stock-sync')));
        }

        wp_send_json_success($progress);
    }

    /**
     * Get active sync ID
     */
    public static function get_active_sync() {
        check_ajax_referer('jonakyds_ajax_sync', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'jonakyds-stock-sync')));
        }

        $active_sync_id = get_option('jonakyds_active_sync_id');
        
        if (!$active_sync_id) {
            wp_send_json_success(array('active' => false));
            return;
        }

        $progress = get_transient('jonakyds_sync_progress_' . $active_sync_id);
        
        if ($progress === false || $progress['status'] === 'complete' || $progress['status'] === 'error') {
            delete_option('jonakyds_active_sync_id');
            wp_send_json_success(array('active' => false));
            return;
        }

        wp_send_json_success(array(
            'active' => true,
            'sync_id' => $active_sync_id,
            'progress' => $progress
        ));
    }

    /**
     * Background sync process
     */
    public static function background_sync($sync_id) {
        // Increase time limit and memory
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $csv_url = get_option('jonakyds_stock_sync_csv_url');
        $sku_column = get_option('jonakyds_stock_sync_sku_column', 'Artnr');
        $stock_column = get_option('jonakyds_stock_sync_stock_column', 'Lagerbestand');

        // Validate CSV URL
        if (empty($csv_url)) {
            self::update_progress($sync_id, array(
                'status' => 'error',
                'message' => __('CSV URL is not configured.', 'jonakyds-stock-sync')
            ));
            delete_option('jonakyds_active_sync_id');
            return;
        }

        // Fetch CSV
        self::update_progress($sync_id, array(
            'status' => 'running',
            'step' => 'fetch',
            'percent' => 10,
            'message' => __('Fetching CSV data...', 'jonakyds-stock-sync')
        ));

        $csv_data = Jonakyds_Stock_Sync::fetch_csv($csv_url);
        if (is_wp_error($csv_data)) {
            self::update_progress($sync_id, array(
                'status' => 'error',
                'message' => $csv_data->get_error_message()
            ));
            delete_option('jonakyds_active_sync_id');
            return;
        }

        // Parse CSV
        self::update_progress($sync_id, array(
            'status' => 'running',
            'step' => 'parse',
            'percent' => 20,
            'message' => __('Parsing CSV data...', 'jonakyds-stock-sync')
        ));

        $parsed_data = Jonakyds_Stock_Sync::parse_csv($csv_data, $sku_column, $stock_column);
        if (is_wp_error($parsed_data)) {
            self::update_progress($sync_id, array(
                'status' => 'error',
                'message' => $parsed_data->get_error_message()
            ));
            delete_option('jonakyds_active_sync_id');
            return;
        }

        $total_items = count($parsed_data);

        // Build products map
        self::update_progress($sync_id, array(
            'status' => 'running',
            'step' => 'mapping',
            'percent' => 30,
            'message' => __('Loading product database...', 'jonakyds-stock-sync'),
            'total' => $total_items
        ));

        $products_map = Jonakyds_Stock_Sync::build_products_map();

        // Disable object cache
        wp_suspend_cache_addition(true);

        // Update stock with progress
        $updated = 0;
        $skipped = 0;
        $batch_size = 50;
        $batches = array_chunk($parsed_data, $batch_size);

        foreach ($batches as $batch_index => $batch) {
            foreach ($batch as $item) {
                $sku = sanitize_text_field($item['sku']);
                $stock_qty = intval($item['stock']);

                if (!isset($products_map[$sku])) {
                    $skipped++;
                    continue;
                }

                $product_id = $products_map[$sku];
                $product = wc_get_product($product_id);

                if (!$product) {
                    $skipped++;
                    continue;
                }

                $product->set_stock_quantity($stock_qty);
                $product->set_stock_status($stock_qty > 0 ? 'instock' : 'outofstock');
                $product->save();

                $updated++;
            }

            $processed = $updated + $skipped;
            $percent = 30 + (($processed / $total_items) * 65);

            // Update progress
            self::update_progress($sync_id, array(
                'status' => 'running',
                'step' => 'sync',
                'percent' => round($percent),
                'message' => sprintf(
                    __('Syncing products... %d updated, %d skipped', 'jonakyds-stock-sync'),
                    $updated,
                    $skipped
                ),
                'updated' => $updated,
                'skipped' => $skipped,
                'total' => $total_items,
                'processed' => $processed
            ));

            wp_cache_flush();
        }

        wp_suspend_cache_addition(false);

        // Complete
        $message = sprintf(
            __('Stock sync completed. Updated: %d, Skipped: %d', 'jonakyds-stock-sync'),
            $updated,
            $skipped
        );

        self::update_progress($sync_id, array(
            'status' => 'complete',
            'step' => 'complete',
            'percent' => 100,
            'message' => $message,
            'updated' => $updated,
            'skipped' => $skipped,
            'total' => $total_items
        ));
        
        // Clear active sync
        delete_option('jonakyds_active_sync_id');

        // Log the sync
        $result = array(
            'success' => true,
            'message' => $message,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => array()
        );
        Jonakyds_Stock_Sync::log_sync($result);

        // Keep progress for 5 minutes
        set_transient('jonakyds_sync_progress_' . $sync_id, get_transient('jonakyds_sync_progress_' . $sync_id), 300);
    }

    /**
     * Update progress
     */
    private static function update_progress($sync_id, $data) {
        self::update_progress_public($sync_id, $data);
    }
    
    /**
     * Public update progress method for cron access
     */
    public static function update_progress_public($sync_id, $data) {
        $current = get_transient('jonakyds_sync_progress_' . $sync_id);
        
        if ($current === false) {
            $current = array(
                'status' => 'init',
                'step' => '',
                'percent' => 0,
                'message' => '',
                'updated' => 0,
                'skipped' => 0,
                'total' => 0,
                'processed' => 0,
                'is_cron' => false
            );
        }

        $progress = array_merge($current, $data);
        set_transient('jonakyds_sync_progress_' . $sync_id, $progress, 3600); // 1 hour
    }
}

// Register AJAX handlers
add_action('wp_ajax_jonakyds_start_sync', array('Jonakyds_Sync_Handler', 'start_sync'));
add_action('wp_ajax_jonakyds_get_progress', array('Jonakyds_Sync_Handler', 'get_progress'));
add_action('wp_ajax_jonakyds_get_active_sync', array('Jonakyds_Sync_Handler', 'get_active_sync'));

// Register background sync action
add_action('jonakyds_background_sync', array('Jonakyds_Sync_Handler', 'background_sync'));

