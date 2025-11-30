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
        $csv_url = get_option('jonakyds_stock_sync_csv_url');
        $sku_column = get_option('jonakyds_stock_sync_sku_column', 'sku');
        $stock_column = get_option('jonakyds_stock_sync_stock_column', 'stock');

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

        // Update stock quantities
        foreach ($parsed_data as $item) {
            $sku = sanitize_text_field($item['sku']);
            $stock_qty = intval($item['stock']);

            // Find product by SKU
            $product_id = wc_get_product_id_by_sku($sku);

            if (!$product_id) {
                $result['skipped']++;
                $result['errors'][] = sprintf(__('Product with SKU "%s" not found.', 'jonakyds-stock-sync'), $sku);
                continue;
            }

            // Get product
            $product = wc_get_product($product_id);
            if (!$product) {
                $result['skipped']++;
                $result['errors'][] = sprintf(__('Could not load product with SKU "%s".', 'jonakyds-stock-sync'), $sku);
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
    private static function fetch_csv($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => true
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
    private static function parse_csv($csv_content, $sku_column, $stock_column) {
        $lines = str_getcsv($csv_content, "\n");
        if (empty($lines)) {
            return new WP_Error('invalid_csv', __('Could not parse CSV data.', 'jonakyds-stock-sync'));
        }

        // Get headers from first line
        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);
        $headers = array_map('strtolower', $headers);

        // Find column indexes
        $sku_index = array_search(strtolower($sku_column), $headers);
        $stock_index = array_search(strtolower($stock_column), $headers);

        if ($sku_index === false || $stock_index === false) {
            return new WP_Error(
                'invalid_columns',
                sprintf(
                    __('Could not find required columns. Looking for: "%s" and "%s"', 'jonakyds-stock-sync'),
                    $sku_column,
                    $stock_column
                )
            );
        }

        // Parse data rows
        $parsed_data = array();
        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $row = str_getcsv($line);
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
    private static function log_sync($result) {
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
            'errors' => $result['errors']
        );

        update_option('jonakyds_stock_sync_logs', $logs);
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

// Hook for cron job
add_action('jonakyds_stock_sync_cron', function() {
    $enabled = get_option('jonakyds_stock_sync_enabled', 'no');
    if ($enabled === 'yes') {
        Jonakyds_Stock_Sync::sync_stock();
    }
});
