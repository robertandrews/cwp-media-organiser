<?php
/**
 * Logger functionality
 */

if (!defined('WPINC')) {
    die;
}

class WP_Media_Organiser_Logger
{
    private $log_file;
    private static $instance = null;
    private $min_level;
    private $levels = array(
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
    );
    private $settings_table;

    private function __construct()
    {
        global $wpdb;
        $this->settings_table = $wpdb->prefix . 'media_organiser_settings';

        // Get plugin directory path
        $plugin_dir = plugin_dir_path(dirname(__FILE__));
        $this->log_file = $plugin_dir . 'wp-media-organiser.log';

        // Set minimum log level to DEBUG
        $this->min_level = $this->levels['DEBUG'];

        // Ensure the log file exists with secure permissions
        if (!file_exists($this->log_file)) {
            touch($this->log_file);
            chmod($this->log_file, 0640); // Owner can read/write, group can read, others no access
        } else if (is_writable($this->log_file)) {
            // If file exists and is writable, ensure it has secure permissions
            chmod($this->log_file, 0640);
        }

        // If file is not writable after setting permissions, try to make it writable by owner only
        if (!is_writable($this->log_file)) {
            chmod($this->log_file, 0600); // Last resort: only owner can read/write
        }
    }

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function log($message, $type = 'info')
    {
        $type = strtoupper($type);

        // Get enabled log levels from settings
        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT setting_value FROM {$this->settings_table} WHERE setting_name = %s",
            'log_levels'
        );
        $enabled_levels = $wpdb->get_var($query);
        $enabled_levels = $enabled_levels ? explode(',', $enabled_levels) : array('ERROR', 'WARNING');

        // Check if this log level is enabled
        if (!in_array($type, $enabled_levels)) {
            return;
        }

        if (!is_string($message)) {
            $message = print_r($message, true);
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $formatted_message = sprintf("[%s] [%s] %s\n", $timestamp, $type, $message);

        if (is_writable($this->log_file)) {
            error_log($formatted_message, 3, $this->log_file);
        }
    }

    public function set_min_level($level)
    {
        $level = strtoupper($level);
        if (isset($this->levels[$level])) {
            $this->min_level = $this->levels[$level];
        }
    }

    public function clear_log()
    {
        if (file_exists($this->log_file) && is_writable($this->log_file)) {
            unlink($this->log_file);
            touch($this->log_file);
        }
    }

    public function get_log_path()
    {
        return $this->log_file;
    }
}
