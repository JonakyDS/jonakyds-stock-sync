<?php
/**
 * Stock Sync Class
 *
 * Handles fetching CSV data and syncing stock quantities
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Jonakyds_Stock_Sync {

    /**
     * Sync stock from CSV URL
     *
     * @return array Result of the sync operation
     */
    public static function sync_stock() {
        // Increase time limit and memory for large syncs
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        
        $csv_url = get_option('jonakyds_stock_sync_csv_url');
        $sku_column = get_option('jonakyds_stock_sync_sku_column', 'Artnr');
        $stock_column = get_option('jonakyds_stock_sync_stock_column', 'Lagerbestand');

        $result = array(
            'success' => false,
            'message' => '',
            'updated' => 0,
            'skipped' => 0,
            'errors' => array()
        );

        // Validate CSV URL
        if (empty($csv_url)) {
            $result['message'] = __('CSV URL is not configured.', 'jonakyds-stock-sync');
            return $result;
        }

        // Fetch CSV data
        $csv_data = self::fetch_csv($csv_url);
        if (is_wp_error($csv_data)) {
            $result['message'] = $csv_data->get_error_message();
            return $result;
        }

        // Parse CSV data
        $parsed_data = self::parse_csv($csv_data, $sku_column, $stock_column);
        if (is_wp_error($parsed_data)) {
            $result['message'] = $parsed_data->get_error_message();
            return $result;
        }

        // Disable object cache to prevent memory issues with large datasets
        wp_suspend_cache_addition(true);
        
        // Pre-load all products with SKUs for faster lookup
        $products_map = self::build_products_map();
        
        // Update stock quantities in batches
        $batch_size = 100;
        $batches = array_chunk($parsed_data, $batch_size);
        
        foreach ($batches as $batch) {
            foreach ($batch as $item) {
                $sku = sanitize_text_field($item['sku']);
                $stock_qty = intval($item['stock']);

                // Find product by SKU from pre-loaded map
                if (!isset($products_map[$sku])) {
                    $result['skipped']++;
                    continue; // Skip silently - SKU not found in this site
                }
                
                $product_id = $products_map[$sku];

                // Get product
                $product = wc_get_product($product_id);
                if (!$product) {
                    $result['skipped']++;
                    continue;
                }

                // Update stock quantity
                $product->set_stock_quantity($stock_qty);
                
                // Set stock status based on quantity
                if ($stock_qty > 0) {
                    $product->set_stock_status('instock');
                } else {
                    $product->set_stock_status('outofstock');
                }

                // Save product
                $product->save();
                $result['updated']++;
            }
            
            // Clear product cache between batches
            wp_cache_flush();
        }
        
        // Re-enable cache
        wp_suspend_cache_addition(false);

        $result['success'] = true;
        $result['message'] = sprintf(
            __('Stock sync completed. Updated: %d, Skipped: %d', 'jonakyds-stock-sync'),
            $result['updated'],
            $result['skipped']
        );

        // Log the sync
        self::log_sync($result);

        return $result;
    }

    /**
     * Fetch CSV data from URL
     *
     * @param string $url CSV URL
     * @return string|WP_Error CSV content or error
     */
    public static function fetch_csv($url) {
        $ssl_verify = get_option('jonakyds_stock_sync_ssl_verify', 'yes');
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => ($ssl_verify === 'yes')
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new WP_Error(
                'invalid_response',
                sprintf(__('Failed to fetch CSV. Response code: %d', 'jonakyds-stock-sync'), $response_code)
            );
        }

        $csv_content = wp_remote_retrieve_body($response);
        if (empty($csv_content)) {
            return new WP_Error('empty_csv', __('CSV file is empty.', 'jonakyds-stock-sync'));
        }

        return $csv_content;
    }

    /**
     * Parse CSV content
     *
     * @param string $csv_content CSV content
     * @param string $sku_column Name of SKU column
     * @param string $stock_column Name of stock column
     * @return array|WP_Error Parsed data or error
     */
    public static function parse_csv($csv_content, $sku_column, $stock_column) {
        $lines = str_getcsv($csv_content, "\n");
        if (empty($lines)) {
            return new WP_Error('invalid_csv', __('Could not parse CSV data.', 'jonakyds-stock-sync'));
        }

        // Detect delimiter (comma or semicolon)
        $first_line = $lines[0];
        $delimiter = ',';
        if (strpos($first_line, ';') !== false && strpos($first_line, ',') === false) {
            $delimiter = ';';
        }

        // Get headers from first line
        $headers = str_getcsv(array_shift($lines), $delimiter);
        $headers = array_map('trim', $headers);
        
        // Store original headers for error message
        $original_headers = $headers;
        
        // Normalize headers for comparison
        $headers_normalized = array_map('strtolower', $headers);

        // Find column indexes (case insensitive)
        $sku_index = array_search(strtolower(trim($sku_column)), $headers_normalized);
        $stock_index = array_search(strtolower(trim($stock_column)), $headers_normalized);

        if ($sku_index === false || $stock_index === false) {
            return new WP_Error(
                'invalid_columns',
                sprintf(
                    __('Could not find required columns. Looking for: "%s" and "%s". Found headers: %s', 'jonakyds-stock-sync'),
                    $sku_column,
                    $stock_column,
                    implode(', ', $original_headers)
                )
            );
        }

        // Parse data rows
        $parsed_data = array();
        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $row = str_getcsv($line, $delimiter);
            if (isset($row[$sku_index]) && isset($row[$stock_index])) {
                $parsed_data[] = array(
                    'sku' => trim($row[$sku_index]),
                    'stock' => trim($row[$stock_index])
                );
            }
        }

        if (empty($parsed_data)) {
            return new WP_Error('no_data', __('No valid data found in CSV.', 'jonakyds-stock-sync'));
        }

        return $parsed_data;
    }

    /**
     * Log sync operation
     *
     * @param array $result Sync result
     */
    public static function log_sync($result) {
        $logs = get_option('jonakyds_stock_sync_logs', array());
        
        // Keep only last 10 logs
        if (count($logs) >= 10) {
            array_shift($logs);
        }

        $logs[] = array(
            'timestamp' => current_time('mysql'),
            'success' => $result['success'],
            'message' => $result['message'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'errors' => array() // Don't log individual errors to save space
        );

        update_option('jonakyds_stock_sync_logs', $logs);
    }

    /**
     * Build a map of SKU to product ID for faster lookups
     *
     * @return array Map of SKU => product_id
     */
    public static function build_products_map() {
        global $wpdb;
        
        $products_map = array();
        
        // Query all product SKUs directly from database for performance
        $results = $wpdb->get_results(
            "SELECT post_id, meta_value as sku 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_sku' 
            AND meta_value != ''",
            ARRAY_A
        );
        
        foreach ($results as $row) {
            $products_map[$row['sku']] = $row['post_id'];
        }
        
        return $products_map;
    }
    
    /**
     * Get sync logs
     *
     * @return array Sync logs
     */
    public static function get_logs() {
        return get_option('jonakyds_stock_sync_logs', array());
    }

    /**
     * Clear sync logs
     */
    public static function clear_logs() {
        delete_option('jonakyds_stock_sync_logs');
    }
}

// Hook for cron job - use background sync handler for progress tracking
add_action('jonakyds_stock_sync_cron', function() {
    $enabled = get_option('jonakyds_stock_sync_enabled', 'no');
    if ($enabled === 'yes') {
        // Check if there's already an active sync
        $existing_sync_id = get_option('jonakyds_active_sync_id');
        if ($existing_sync_id) {
            $existing_progress = get_transient('jonakyds_sync_progress_' . $existing_sync_id);
            if ($existing_progress && ($existing_progress['status'] === 'running' || $existing_progress['status'] === 'init')) {
                // Sync already in progress, skip
                return;
            }
        }
        
        // Generate unique sync ID for cron sync
        $sync_id = uniqid('cron_sync_', true);
        update_option('jonakyds_active_sync_id', $sync_id);
        
        // Initialize progress
        Jonakyds_Sync_Handler::update_progress_public($sync_id, array(
            'status' => 'running',
            'step' => 'init',
            'percent' => 0,
            'message' => __('Automated sync starting...', 'jonakyds-stock-sync'),
            'updated' => 0,
            'skipped' => 0,
            'total' => 0,
            'is_cron' => true
        ));
        
        // Run background sync
        Jonakyds_Sync_Handler::background_sync($sync_id);
    }
});
