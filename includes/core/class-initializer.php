<?php

class WP_Media_Organiser_Initializer
{
    private $settings;
    private $processor;
    private $settings_table;
    private $plugin_path;
    private $plugin_url;
    private $logger;
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self(plugin_dir_path(dirname(dirname(__FILE__))), plugin_dir_url(dirname(dirname(__FILE__))));
        }
        return self::$instance;
    }

    private function __construct($plugin_path, $plugin_url)
    {
        global $wpdb;
        $this->settings_table = $wpdb->prefix . 'media_organiser_settings';
        $this->plugin_path = $plugin_path;
        $this->plugin_url = $plugin_url;
        $this->logger = WP_Media_Organiser_Logger::get_instance();

        $this->init_components();
        $this->init_hooks();
    }

    private function init_components()
    {
        // Initialize components
        $this->settings = new WP_Media_Organiser_Settings(
            $this->settings_table,
            $this->plugin_path,
            $this->plugin_url
        );

        $this->processor = new WP_Media_Organiser_Processor($this->settings);
    }

    private function init_hooks()
    {
        // Initialize hooks
        // Removing duplicate save_post hook as it's handled in admin class
    }

    public function get_settings()
    {
        return $this->settings;
    }

    public function get_processor()
    {
        return $this->processor;
    }
}
