<?php
/**
 * Plugin Updater Class
 *
 * Handles automatic plugin updates from GitHub
 * Follows WordPress plugin standards
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Prevent loading if already loaded (handles duplicate plugin uploads)
if (class_exists('Jonakyds_Stock_Sync_Updater')) {
    return;
}

class Jonakyds_Stock_Sync_Updater {

    /**
     * Plugin file path
     * 
     * @var string
     */
    private $plugin_file;

    /**
     * Plugin slug (folder name)
     * 
     * @var string
     */
    private $plugin_slug;

    /**
     * Plugin basename (folder/file.php)
     * 
     * @var string
     */
    private $basename;

    /**
     * Plugin data from headers
     * 
     * @var array
     */
    private $plugin_data;

    /**
     * GitHub username/organization
     * 
     * @var string
     */
    private $github_username = 'JonakyDS';

    /**
     * GitHub repository name
     * 
     * @var string
     */
    private $github_repository = 'jonakyds-stock-sync';

    /**
     * Cache key for storing release info
     * 
     * @var string
     */
    private $cache_key = 'jonakyds_stock_sync_github_release';

    /**
     * Cache duration in seconds (12 hours)
     * 
     * @var int
     */
    private $cache_duration = 43200;

    /**
     * Constructor
     * 
     * @param string $plugin_file Full path to the main plugin file
     */
    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->basename = plugin_basename($plugin_file);
        $this->plugin_slug = dirname($this->basename);
        
        // Ensure we have plugin data functions
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $this->plugin_data = get_plugin_data($plugin_file);

        // Register hooks
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Check for updates
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        
        // Provide plugin information
        add_filter('plugins_api', array($this, 'plugins_api_filter'), 10, 3);
        
        // Handle source selection (rename directory from GitHub format BEFORE install)
        add_filter('upgrader_source_selection', array($this, 'fix_source_dir'), 10, 4);
        
        // Clear caches after plugin update completes
        add_action('upgrader_process_complete', array($this, 'after_update'), 10, 2);
        
        // Add action link to check for updates
        add_filter('plugin_action_links_' . $this->basename, array($this, 'plugin_action_links'));
        
        // Handle manual update check
        add_action('admin_init', array($this, 'handle_manual_update_check'));
        
        // Show admin notice after update check
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    /**
     * Check for plugin updates
     * 
     * @param object $transient WordPress update transient
     * @return object Modified transient
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get remote version info
        $remote_info = $this->get_remote_info();

        if (!$remote_info) {
            return $transient;
        }

        // Compare versions
        $current_version = $this->plugin_data['Version'];
        $remote_version = $remote_info['version'];

        if (version_compare($current_version, $remote_version, '<')) {
            $transient->response[$this->basename] = (object) array(
                'slug'         => $this->plugin_slug,
                'plugin'       => $this->basename,
                'new_version'  => $remote_version,
                'url'          => $remote_info['homepage'],
                'package'      => $remote_info['download_url'],
                'icons'        => array(),
                'banners'      => array(),
                'tested'       => $remote_info['tested'],
                'requires'     => $remote_info['requires'],
                'requires_php' => $remote_info['requires_php'],
            );
        } else {
            // No update available - add to no_update to show "up to date"
            $transient->no_update[$this->basename] = (object) array(
                'slug'         => $this->plugin_slug,
                'plugin'       => $this->basename,
                'new_version'  => $current_version,
                'url'          => $remote_info['homepage'],
                'package'      => '',
            );
        }

        return $transient;
    }

    /**
     * Get remote plugin information from GitHub
     * 
     * @param bool $force_check Force check, bypassing cache
     * @return array|false Remote info or false on failure
     */
    private function get_remote_info($force_check = false) {
        // Check cache first
        if (!$force_check) {
            $cached = get_transient($this->cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Build GitHub API URL
        $api_url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_username,
            $this->github_repository
        );

        // Make API request
        $response = wp_remote_get($api_url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ),
        ));

        // Handle errors
        if (is_wp_error($response)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return false;
        }

        // Parse response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !isset($data['tag_name'])) {
            return false;
        }

        // Extract version (remove 'v' prefix if present)
        $version = ltrim($data['tag_name'], 'v');

        // Find the zip asset
        $download_url = '';
        if (!empty($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (isset($asset['name']) && substr($asset['name'], -4) === '.zip') {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        // Fallback to zipball URL if no asset found
        if (empty($download_url) && !empty($data['zipball_url'])) {
            $download_url = $data['zipball_url'];
        }

        if (empty($download_url)) {
            return false;
        }

        // Build info array
        $info = array(
            'version'      => $version,
            'download_url' => $download_url,
            'homepage'     => $this->plugin_data['PluginURI'] ?: "https://github.com/{$this->github_username}/{$this->github_repository}",
            'requires'     => '5.0',
            'tested'       => get_bloginfo('version'),
            'requires_php' => '7.2',
            'changelog'    => isset($data['body']) ? $data['body'] : '',
            'last_updated' => isset($data['published_at']) ? $data['published_at'] : '',
        );

        // Try to extract compatibility info from release notes
        if (!empty($data['body'])) {
            $body_text = $data['body'];
            
            if (preg_match('/Tested up to:?\s*(\d+\.\d+(?:\.\d+)?)/i', $body_text, $matches)) {
                $info['tested'] = $matches[1];
            }
            if (preg_match('/Requires at least:?\s*(\d+\.\d+(?:\.\d+)?)/i', $body_text, $matches)) {
                $info['requires'] = $matches[1];
            }
            if (preg_match('/Requires PHP:?\s*(\d+\.\d+(?:\.\d+)?)/i', $body_text, $matches)) {
                $info['requires_php'] = $matches[1];
            }
        }

        // Cache the result
        set_transient($this->cache_key, $info, $this->cache_duration);

        return $info;
    }

    /**
     * Filter the plugins_api to provide plugin information
     * 
     * @param false|object|array $result The result object or array
     * @param string $action The type of information being requested
     * @param object $args Plugin API arguments
     * @return false|object Plugin info or false
     */
    public function plugins_api_filter($result, $action, $args) {
        // Only handle plugin_information action
        if ($action !== 'plugin_information') {
            return $result;
        }

        // Check if this is our plugin
        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        // Get remote info
        $remote_info = $this->get_remote_info();

        if (!$remote_info) {
            return $result;
        }

        // Build plugin info object
        $plugin_info = new stdClass();
        $plugin_info->name           = $this->plugin_data['Name'];
        $plugin_info->slug           = $this->plugin_slug;
        $plugin_info->version        = $remote_info['version'];
        $plugin_info->author         = $this->plugin_data['Author'];
        $plugin_info->author_profile = $this->plugin_data['AuthorURI'];
        $plugin_info->homepage       = $remote_info['homepage'];
        $plugin_info->requires       = $remote_info['requires'];
        $plugin_info->tested         = $remote_info['tested'];
        $plugin_info->requires_php   = $remote_info['requires_php'];
        $plugin_info->downloaded     = 0;
        $plugin_info->last_updated   = $remote_info['last_updated'];
        $plugin_info->download_link  = $remote_info['download_url'];
        
        $plugin_info->sections = array(
            'description' => $this->plugin_data['Description'],
            'changelog'   => $this->format_changelog($remote_info['changelog']),
        );

        $plugin_info->banners = array();

        return $plugin_info;
    }

    /**
     * Format changelog from markdown to HTML
     * 
     * @param string $changelog Raw changelog text
     * @return string Formatted HTML
     */
    private function format_changelog($changelog) {
        if (empty($changelog)) {
            return '<p>' . __('No changelog available.', 'jonakyds-stock-sync') . '</p>';
        }

        // Basic markdown to HTML conversion
        $html = esc_html($changelog);
        $html = nl2br($html);
        
        // Convert markdown headers
        $html = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $html);
        
        // Convert markdown lists
        $html = preg_replace('/^[\*\-] (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.+<\/li>\n?)+/', '<ul>$0</ul>', $html);

        return $html;
    }

    /**
     * Fix the source directory name before installation
     * 
     * GitHub zip files extract to folders like "user-repo-hash" instead of just "repo"
     * This filter renames the source folder BEFORE WordPress moves it to plugins directory
     * 
     * @param string $source        File source location
     * @param string $remote_source Remote file source location
     * @param WP_Upgrader $upgrader WP_Upgrader instance
     * @param array $hook_extra     Extra arguments passed to hooked filters
     * @return string|WP_Error
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra) {
        global $wp_filesystem;

        // Only process plugin updates
        if (!isset($hook_extra['plugin'])) {
            return $source;
        }

        // Only process our plugin
        if ($hook_extra['plugin'] !== $this->basename) {
            return $source;
        }

        // Check if the source folder name is incorrect
        $source_name = basename(untrailingslashit($source));
        
        // If source already has correct name, return as is
        if ($source_name === $this->plugin_slug) {
            return $source;
        }

        // Build the new source path with correct folder name
        $new_source = trailingslashit(dirname(untrailingslashit($source))) . $this->plugin_slug . '/';

        // Rename the folder
        if ($wp_filesystem->move($source, $new_source, true)) {
            return $new_source;
        }

        // If rename failed, return original (WordPress will handle it)
        return $source;
    }

    /**
     * Clear caches after plugin update completes
     * 
     * @param WP_Upgrader $upgrader WP_Upgrader instance
     * @param array $options Update options
     */
    public function after_update($upgrader, $options) {
        // Check if this is a plugin update
        if ($options['action'] !== 'update' || $options['type'] !== 'plugin') {
            return;
        }

        // Check if our plugin was updated
        $dominated = false;
        
        if (isset($options['plugins']) && is_array($options['plugins'])) {
            // Bulk update
            $our_plugin_updated = in_array($this->basename, $options['plugins'], true);
        } elseif (isset($options['plugin'])) {
            // Single plugin update
            $our_plugin_updated = ($options['plugin'] === $this->basename);
        } else {
            $our_plugin_updated = false;
        }

        if ($our_plugin_updated) {
            // Clear our GitHub release cache
            delete_transient($this->cache_key);
            
            // Clear WordPress plugin update transient
            delete_site_transient('update_plugins');
            
            // Clear plugin cache
            wp_clean_plugins_cache(true);
        }
    }

    /**
     * Add plugin action links
     * 
     * @param array $links Existing links
     * @return array Modified links
     */
    public function plugin_action_links($links) {
        $check_link = sprintf(
            '<a href="%s">%s</a>',
            wp_nonce_url(
                add_query_arg('jonakyds_check_update', '1', admin_url('plugins.php')),
                'jonakyds_check_update'
            ),
            __('Check for updates', 'jonakyds-stock-sync')
        );
        
        array_unshift($links, $check_link);
        
        return $links;
    }

    /**
     * Handle manual update check request
     */
    public function handle_manual_update_check() {
        if (!isset($_GET['jonakyds_check_update'])) {
            return;
        }

        if (!current_user_can('update_plugins')) {
            return;
        }

        // Verify nonce
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'jonakyds_check_update')) {
            return;
        }

        // Clear cache and force check
        delete_transient($this->cache_key);
        delete_site_transient('update_plugins');

        // Force WordPress to check for updates
        wp_clean_plugins_cache(true);

        // Redirect with message
        wp_safe_redirect(add_query_arg(
            array(
                'jonakyds_update_checked' => '1',
            ),
            admin_url('plugins.php')
        ));
        exit;
    }

    /**
     * Show admin notices
     */
    public function admin_notices() {
        if (!isset($_GET['jonakyds_update_checked'])) {
            return;
        }

        $remote_info = $this->get_remote_info(true);
        $current_version = $this->plugin_data['Version'];

        if ($remote_info && version_compare($current_version, $remote_info['version'], '<')) {
            $message = sprintf(
                __('A new version of Stock Sync is available! Version %s is available (you have %s).', 'jonakyds-stock-sync'),
                '<strong>' . esc_html($remote_info['version']) . '</strong>',
                '<strong>' . esc_html($current_version) . '</strong>'
            );
            echo '<div class="notice notice-info is-dismissible"><p>' . $message . '</p></div>';
        } else {
            $message = sprintf(
                __('Stock Sync is up to date! You are running version %s.', 'jonakyds-stock-sync'),
                '<strong>' . esc_html($current_version) . '</strong>'
            );
            echo '<div class="notice notice-success is-dismissible"><p>' . $message . '</p></div>';
        }
    }
}
