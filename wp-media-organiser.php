<?php
/**
 * Plugin Name: WP Media Organiser
 * Plugin URI:
 * Description: Custom control over where media files are stored in WordPress
 * Version: 1.0.0
 * Author:
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-media-organiser
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Load required files
require_once plugin_dir_path(__FILE__) . 'includes/class-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-media-processor.php';

class WP_Media_Organiser
{
    private static $instance = null;
    private $settings;
    private $processor;
    private $settings_table;
    private $plugin_path;
    private $plugin_url;
    private $logger;

    private function __construct()
    {
        global $wpdb;
        $this->settings_table = $wpdb->prefix . 'media_organiser_settings';
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);
        $this->logger = WP_Media_Organiser_Logger::get_instance();

        // Initialize components
        $this->settings = new WP_Media_Organiser_Settings(
            $this->settings_table,
            $this->plugin_path,
            $this->plugin_url
        );

        $this->processor = new WP_Media_Organiser_Processor($this->settings);

        // Initialize hooks
        add_action('save_post', array($this->processor, 'reorganize_media'), 10, 3);
    }

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate()
    {
        global $wpdb;
        $logger = WP_Media_Organiser_Logger::get_instance();
        $logger->log('Plugin activation started', 'info');

        $table_name = $wpdb->prefix . 'media_organiser_settings';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            setting_name varchar(255) NOT NULL,
            setting_value longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY setting_name (setting_name)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Set default settings
        $default_settings = array(
            'use_post_type' => '1',
            'taxonomy_name' => '',
            'post_identifier' => 'slug',
        );

        foreach ($default_settings as $name => $value) {
            $wpdb->replace(
                $table_name,
                array(
                    'setting_name' => $name,
                    'setting_value' => $value,
                ),
                array('%s', '%s')
            );
            $logger->log("Default setting created: $name = $value", 'info');
        }

        $logger->log('Plugin activation completed', 'info');
    }
}

// Initialize the plugin
add_action('plugins_loaded', array('WP_Media_Organiser', 'get_instance'));

// Register activation hook
register_activation_hook(__FILE__, array('WP_Media_Organiser', 'activate'));
