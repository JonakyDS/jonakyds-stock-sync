<?php
/**
 * Plugin Updater Class
 *
 * Handles automatic plugin updates from GitHub
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Jonakyds_Stock_Sync_Updater {

    /**
     * GitHub username
     */
    private $username = 'JonakyDS';

    /**
     * GitHub repository name
     */
    private $repository = 'jonakyds-stock-sync';

    /**
     * Plugin basename
     */
    private $basename;

    /**
     * Plugin data
     */
    private $plugin_data;

    /**
     * GitHub API URL
     */
    private $github_api_url;

    /**
     * Constructor
     */
    public function __construct($plugin_file) {
        $this->basename = plugin_basename($plugin_file);
        $this->github_api_url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";
        
        // Get plugin data
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $this->plugin_data = get_plugin_data($plugin_file);

        // Hook into WordPress update system
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        
        // Clear update cache when requested
        add_action('admin_init', array($this, 'maybe_clear_cache'));
    }

    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get release info from GitHub
        $release_info = $this->get_release_info();

        if ($release_info && version_compare($this->plugin_data['Version'], $release_info->version, '<')) {
            $plugin_data = array(
                'slug' => dirname($this->basename),
                'new_version' => $release_info->version,
                'url' => $this->plugin_data['PluginURI'],
                'package' => $release_info->download_url,
                'tested' => $release_info->tested,
                'requires' => $release_info->requires,
                'requires_php' => $release_info->requires_php,
            );

            $transient->response[$this->basename] = (object) $plugin_data;
        }

        return $transient;
    }

    /**
     * Get release information from GitHub
     */
    private function get_release_info() {
        // Check cache first (24 hours)
        $cache_key = 'jonakyds_stock_sync_release_info';
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }

        // Fetch from GitHub API
        $response = wp_remote_get($this->github_api_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (empty($data) || !isset($data->tag_name)) {
            return false;
        }

        // Parse version from tag (remove 'v' prefix if present)
        $version = ltrim($data->tag_name, 'v');

        // Get additional info from release body
        $description = isset($data->body) ? $data->body : '';
        
        // Try to extract WordPress compatibility info from release notes
        $requires = $this->plugin_data['RequiresWP'] ?: '5.0';
        $tested = $this->plugin_data['Tested up to'] ?: get_bloginfo('version');
        $requires_php = $this->plugin_data['RequiresPHP'] ?: '7.2';
        
        // Look for compatibility info in release notes
        if (preg_match('/Tested up to:?\s*(\d+\.\d+(?:\.\d+)?)/i', $description, $matches)) {
            $tested = $matches[1];
        }
        if (preg_match('/Requires at least:?\s*(\d+\.\d+(?:\.\d+)?)/i', $description, $matches)) {
            $requires = $matches[1];
        }
        if (preg_match('/Requires PHP:?\s*(\d+\.\d+(?:\.\d+)?)/i', $description, $matches)) {
            $requires_php = $matches[1];
        }

        $release_info = (object) array(
            'version' => $version,
            'download_url' => $data->zipball_url,
            'body' => $description,
            'requires' => $requires,
            'tested' => $tested,
            'requires_php' => $requires_php,
        );

        // Cache for 24 hours
        set_transient($cache_key, $release_info, DAY_IN_SECONDS);

        return $release_info;
    }

    /**
     * Provide plugin information for the update screen
     */
    public function plugin_info($false, $action, $args) {
        if ($action !== 'plugin_information') {
            return $false;
        }

        if (!isset($args->slug) || $args->slug !== dirname($this->basename)) {
            return $false;
        }

        $release_info = $this->get_release_info();

        if (!$release_info) {
            return $false;
        }

        $plugin_info = array(
            'name' => $this->plugin_data['Name'],
            'slug' => dirname($this->basename),
            'version' => $release_info->version,
            'author' => $this->plugin_data['Author'],
            'author_profile' => $this->plugin_data['AuthorURI'],
            'homepage' => $this->plugin_data['PluginURI'],
            'requires' => $release_info->requires,
            'tested' => $release_info->tested,
            'requires_php' => $release_info->requires_php,
            'download_link' => $release_info->download_url,
            'sections' => array(
                'description' => $this->plugin_data['Description'],
                'changelog' => $this->parse_changelog($release_info->body),
            ),
            'banners' => array(),
        );

        return (object) $plugin_info;
    }

    /**
     * Parse changelog from release notes
     */
    private function parse_changelog($body) {
        if (empty($body)) {
            return __('No changelog available.', 'jonakyds-stock-sync');
        }

        // Convert markdown to HTML (basic conversion)
        $changelog = wpautop($body);
        $changelog = str_replace('###', '<h3>', $changelog);
        $changelog = str_replace('##', '<h2>', $changelog);
        $changelog = preg_replace('/^\* /m', '• ', $changelog);

        return $changelog;
    }

    /**
     * Perform additional actions after installation
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        // Get plugin directory
        $install_directory = plugin_dir_path(WP_PLUGIN_DIR . '/' . $this->basename);
        
        // Move files from GitHub's extracted subdirectory to plugin directory
        if (isset($result['destination'])) {
            $remote_destination = $result['destination'];
            
            // GitHub extracts to a subdirectory like "username-repo-hash"
            $remote_source = $remote_destination;
            
            // Find the actual source directory
            $source_files = array_values($wp_filesystem->dirlist($remote_source));
            if (!empty($source_files)) {
                $source_name = $source_files[0]['name'];
                $remote_source = trailingslashit($remote_source) . $source_name;
            }
            
            // Move files to the correct location
            if ($wp_filesystem->exists($remote_source)) {
                $wp_filesystem->move($remote_source, $install_directory, true);
            }
        }

        // Clear the update cache
        delete_transient('jonakyds_stock_sync_release_info');

        return $response;
    }

    /**
     * Maybe clear the update cache
     */
    public function maybe_clear_cache() {
        if (isset($_GET['jonakyds_clear_update_cache']) && current_user_can('update_plugins')) {
            delete_transient('jonakyds_stock_sync_release_info');
            delete_site_transient('update_plugins');
            wp_redirect(admin_url('plugins.php'));
            exit;
        }
    }

    /**
     * Get the GitHub username
     */
    public function get_username() {
        return $this->username;
    }

    /**
     * Get the repository name
     */
    public function get_repository() {
        return $this->repository;
    }

    /**
     * Set GitHub repository (for customization)
     */
    public function set_repository($username, $repository) {
        $this->username = $username;
        $this->repository = $repository;
        $this->github_api_url = "https://api.github.com/repos/{$username}/{$repository}/releases/latest";
        
        // Clear cache when repository changes
        delete_transient('jonakyds_stock_sync_release_info');
    }
}
