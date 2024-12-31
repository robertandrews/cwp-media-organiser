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

    private function __construct()
    {
        // Get plugin directory path
        $plugin_dir = plugin_dir_path(dirname(__FILE__));
        $this->log_file = $plugin_dir . 'wp-media-organiser.log';

        // Set minimum log level to INFO (suppressing DEBUG)
        $this->min_level = $this->levels['INFO'];

        // Ensure the log file is writable
        if (!file_exists($this->log_file)) {
            touch($this->log_file);
        }

        if (!is_writable($this->log_file)) {
            // Try to make it writable
            chmod($this->log_file, 0666);
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

        // Check if this log level should be recorded
        if (!isset($this->levels[$type]) || $this->levels[$type] < $this->min_level) {
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
