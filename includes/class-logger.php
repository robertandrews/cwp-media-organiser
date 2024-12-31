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

    private function __construct()
    {
        // Get plugin directory path
        $plugin_dir = plugin_dir_path(dirname(__FILE__));
        $this->log_file = $plugin_dir . 'wp-media-organiser.log';

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
        if (!is_string($message)) {
            $message = print_r($message, true);
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $formatted_message = sprintf("[%s] [%s] %s\n", $timestamp, strtoupper($type), $message);

        if (is_writable($this->log_file)) {
            error_log($formatted_message, 3, $this->log_file);
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
