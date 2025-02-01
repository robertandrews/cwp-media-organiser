<?php
/**
 * Settings functionality
 */

if (!defined('WPINC')) {
    die;
}

class WP_Media_Organiser_Settings
{
    private $settings_table;
    private $plugin_path;
    private $plugin_url;
    private $logger;

    public function __construct($settings_table, $plugin_path, $plugin_url)
    {
        $this->settings_table = $settings_table;
        $this->plugin_path = $plugin_path;
        $this->plugin_url = $plugin_url;
        $this->logger = WP_Media_Organiser_Logger::get_instance();

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function get_setting($name)
    {
        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT setting_value FROM {$this->settings_table} WHERE setting_name = %s",
            $name
        );
        $this->logger->log("Getting setting: $name", 'debug');
        $value = $wpdb->get_var($query);
        $this->logger->log("Retrieved setting $name = " . ($value !== null ? $value : 'null'), 'debug');
        return $value !== null ? $value : '';
    }

    public function update_setting($name, $value)
    {
        global $wpdb;
        $this->logger->log("Updating setting: $name = $value", 'debug');

        $result = $wpdb->replace(
            $this->settings_table,
            array(
                'setting_name' => $name,
                'setting_value' => $value,
            ),
            array('%s', '%s')
        );

        if ($result === false) {
            $this->logger->log("Failed to update setting: $name. Error: " . $wpdb->last_error, 'error');
        } else {
            $this->logger->log("Successfully updated setting: $name", 'debug');
        }
    }

    public function get_valid_post_types()
    {
        // Get all post types
        $post_types = get_post_types(array('public' => true), 'objects');

        // Add 'page' explicitly if not already included
        if (!isset($post_types['page'])) {
            $post_types['page'] = get_post_type_object('page');
        }

        // Built-in types to exclude
        $exclude_types = array(
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
        );

        // Filter out unwanted post types
        foreach ($exclude_types as $exclude) {
            unset($post_types[$exclude]);
        }

        // Convert to simple array of labels
        $formatted_types = array();
        foreach ($post_types as $type) {
            $formatted_types[$type->name] = $type->label;
        }

        return $formatted_types;
    }

    public function add_admin_menu()
    {
        add_submenu_page(
            'upload.php',
            __('Media Organiser Settings', 'wp-media-organiser'),
            __('Media Organiser', 'wp-media-organiser'),
            'manage_options',
            'wp-media-organiser',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings()
    {
        // Registration will be handled directly through our custom table
    }

    public function enqueue_admin_scripts($hook)
    {
        if ('media_page_wp-media-organiser' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wp-media-organiser-notice',
            $this->plugin_url . 'assets/css/notice.css',
            array(),
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/css/notice.css')
        );

        wp_enqueue_style(
            'wp-media-organiser-admin',
            $this->plugin_url . 'assets/css/admin.css',
            array('wp-media-organiser-notice'),
            '1.0.0'
        );

        wp_enqueue_script(
            'wp-media-organiser-admin',
            $this->plugin_url . 'assets/js/admin.js',
            array('jquery'),
            '1.0.0',
            true
        );

        // Get all valid post types
        $post_types = $this->get_valid_post_types();

        // Pass data to JavaScript
        wp_localize_script(
            'wp-media-organiser-admin',
            'wpMediaOrganiser',
            array(
                'postTypes' => $post_types,
                'useYearMonthFolders' => get_option('uploads_use_yearmonth_folders'),
                'uploadsPath' => str_replace(site_url(), '', wp_get_upload_dir()['baseurl']),
            )
        );
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save settings
        if (isset($_POST['submit'])) {
            check_admin_referer('wp_media_organiser_settings');

            $taxonomy_value = sanitize_text_field($_POST['taxonomy_name']);
            $this->logger->log("Saving settings from form submission", 'info');
            $this->logger->log("Taxonomy value submitted: $taxonomy_value", 'debug');

            // Handle log levels
            $log_levels = isset($_POST['log_levels']) ? $_POST['log_levels'] : array();
            $log_levels = array_map('sanitize_text_field', $log_levels);
            $log_levels = array_filter($log_levels, function ($level) {
                return in_array($level, array('DEBUG', 'INFO', 'WARNING', 'ERROR'));
            });
            $this->update_setting('log_levels', implode(',', $log_levels));

            $this->update_setting('use_post_type', isset($_POST['use_post_type']) ? '1' : '0');
            $this->update_setting('taxonomy_name', $taxonomy_value);
            $this->update_setting('post_identifier', sanitize_text_field($_POST['post_identifier']));

            $this->logger->log("Settings saved successfully", 'info');
            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'wp-media-organiser') . '</p></div>';
        }

        // Get current settings
        $use_post_type = $this->get_setting('use_post_type');
        $taxonomy_name = $this->get_setting('taxonomy_name');
        $post_identifier = $this->get_setting('post_identifier');
        $log_levels = $this->get_setting('log_levels');

        // Get available taxonomies
        $taxonomies = get_taxonomies(array('public' => true), 'objects');
        $available_taxonomies = array();
        foreach ($taxonomies as $tax) {
            $available_taxonomies[$tax->name] = $tax->label;
        }

        // Generate initial path preview
        $preview_path = '/wp-content/uploads/';
        if ($use_post_type === '1') {
            $preview_path .= '<span class="post-type">{post}</span>/';
        }
        if ($taxonomy_name) {
            $preview_path .= '<span class="taxonomy">' . $taxonomy_name . '</span>/<span class="term">{term_slug}</span>/';
        }
        if (get_option('uploads_use_yearmonth_folders')) {
            $preview_path .= '{YYYY}/{MM}/';
        }
        if ($post_identifier === 'slug') {
            $preview_path .= '<span class="post-identifier">{post-slug}</span>/';
        } else if ($post_identifier === 'id') {
            $preview_path .= '<span class="post-identifier">{post-id}</span>/';
        }
        $preview_path .= 'image.jpg';

        // Add the data to the template
        $template_data = array(
            'preview_path' => $preview_path,
            'use_post_type' => $use_post_type,
            'taxonomy_name' => $taxonomy_name,
            'post_identifier' => $post_identifier,
            'available_taxonomies' => $available_taxonomies,
            'log_levels' => $log_levels,
        );

        include $this->plugin_path . 'templates/settings-page.php';
    }
}
