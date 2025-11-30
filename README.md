# Jonakyds Stock Sync

A WordPress plugin to automatically sync WooCommerce product stock from a CSV URL.

## Features

- **CSV-based stock sync**: Fetch stock data from any publicly accessible CSV file
- **Automatic scheduling**: Set up hourly, twice-daily, or daily automatic syncs
- **Manual sync**: Trigger stock updates on-demand from the admin interface
- **Flexible column mapping**: Configure which CSV columns contain SKU and stock data
- **Comprehensive logging**: Track all sync operations with detailed logs
- **WooCommerce integration**: Seamlessly updates product stock quantities and availability status

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- PHP 7.2 or higher

## Installation

1. Upload the `jonakyds-stock-sync` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Stock Sync to configure the plugin

## Configuration

### CSV Format

Your CSV file should have a header row with at least two columns:
- One column for the product SKU
- One column for the stock quantity

Example CSV:

```csv
sku,stock,price
ABC123,50,29.99
XYZ789,100,49.99
DEF456,0,19.99
```

### Plugin Settings

1. **CSV URL**: Enter the full URL to your CSV file (must be publicly accessible)
2. **SKU Column Name**: The name of the column containing product SKUs (case insensitive)
3. **Stock Column Name**: The name of the column containing stock quantities (case insensitive)
4. **Enable Automatic Sync**: Check this to enable scheduled syncs
5. **Sync Schedule**: Choose how often to run automatic syncs (hourly, twice daily, or daily)

## Usage

### Manual Sync

1. Go to WooCommerce > Stock Sync
2. Click the "Sync Now" button
3. View the results in the sync logs

### Automatic Sync

Once enabled, the plugin will automatically sync stock based on your chosen schedule. You can view the next scheduled sync time on the settings page.

### Sync Logs

The plugin keeps a log of the last 10 sync operations, including:
- Timestamp of the sync
- Number of products updated
- Number of products skipped
- Any errors encountered

## How It Works

1. The plugin fetches the CSV file from the provided URL
2. It parses the CSV and extracts SKU and stock data
3. For each row, it finds the corresponding WooCommerce product by SKU
4. It updates the product's stock quantity
5. It automatically sets the stock status (in stock/out of stock) based on quantity
6. All operations are logged for review

## Troubleshooting

### Products not updating?

- Verify your CSV URL is publicly accessible
- Check that the SKU values in your CSV match exactly with your WooCommerce product SKUs
- Review the sync logs for specific errors
- Ensure column names are configured correctly (they are case insensitive)

### CSV not loading?

- Make sure the URL is accessible (try opening it in a browser)
- Check that the server allows external requests
- Verify the CSV file is properly formatted with headers

### Schedule not running?

- WordPress cron requires site traffic to trigger
- Consider using a server cron job to hit `wp-cron.php` regularly
- Check that WooCommerce is active

## Development

### File Structure

```
jonakyds-stock-sync/
├── jonakyds-stock-sync.php       # Main plugin file
├── includes/
│   ├── class-stock-sync.php      # Stock sync logic
│   └── class-admin.php           # Admin interface
└── README.md                      # Documentation
```

### Filters & Actions

The plugin uses standard WordPress hooks:
- `jonakyds_stock_sync_cron` - Action hook for scheduled syncs

## License

GPL v2 or later

## Support

For issues and feature requests, please use the plugin's support forum or repository.

## Changelog

### 1.0.0
- Initial release
- CSV-based stock sync functionality
- Automatic scheduling
- Admin interface with logs
- Manual sync capability
