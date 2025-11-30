# Auto-Update Setup Guide

This plugin supports automatic updates from GitHub releases.

## How It Works

The plugin uses the [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) library to check for updates from GitHub releases.

## Creating a Release

1. **Commit and push your changes:**
   ```bash
   git add .
   git commit -m "Release version 1.2.3"
   git push origin main
   ```

2. **Create a new release on GitHub:**
   - Go to: https://github.com/SourovCodes/jonakyds-stock-sync/releases/new
   - Tag version: `v1.2.3` (must match the version in `jonakyds-stock-sync.php`)
   - Release title: `Version 1.2.3`
   - Description: List the changes/improvements
   - Click "Publish release"

3. **WordPress will automatically detect the update:**
   - Users will see an update notification in their WordPress admin
   - They can update with one click from the Plugins page

## Version Numbering

- Update the version in `jonakyds-stock-sync.php` header: `Version: 1.2.3`
- Update the constant: `define('JONAKYDS_STOCK_SYNC_VERSION', '1.2.3');`
- Git tag must match: `v1.2.3`

## Private Repository Support

If your repository is private, users will need to:
1. Generate a GitHub Personal Access Token
2. Add this code to their `wp-config.php`:

```php
define('JONAKYDS_GITHUB_TOKEN', 'your_github_personal_access_token');
```

Then update the plugin code to use the token:
```php
if (defined('JONAKYDS_GITHUB_TOKEN')) {
    $jonakydsUpdateChecker->setAuthentication(JONAKYDS_GITHUB_TOKEN);
}
```

## Testing Updates

- The plugin checks for updates every 12 hours
- To force an immediate check, go to: Dashboard → Updates → Check Again
- Or use: `delete_site_transient('update_plugins');` in wp-admin

## No Release Assets Needed

The update checker will automatically use the source code from the release tag. You don't need to manually upload ZIP files.
