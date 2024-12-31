<?php

class WP_Media_Organiser
{
    private static $instance = null;
    private $initializer;
    private $plugin_path;
    private $plugin_url;

    private function __construct()
    {
        $this->plugin_path = plugin_dir_path(dirname(dirname(__FILE__)));
        $this->plugin_url = plugin_dir_url(dirname(dirname(__FILE__)));

        $this->load_dependencies();
        $this->initializer = WP_Media_Organiser_Initializer::get_instance();

        // Initialize admin functionality if in admin area
        if (is_admin()) {
            WP_Media_Organiser_Admin::get_instance();
        }
    }

    private function load_dependencies()
    {
        require_once $this->plugin_path . 'includes/class-logger.php';
        require_once $this->plugin_path . 'includes/class-settings.php';
        require_once $this->plugin_path . 'includes/class-media-processor.php';
        require_once $this->plugin_path . 'includes/class-admin.php';
        require_once $this->plugin_path . 'includes/core/class-activator.php';
        require_once $this->plugin_path . 'includes/core/class-initializer.php';
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
        WP_Media_Organiser_Activator::activate();
    }
}
