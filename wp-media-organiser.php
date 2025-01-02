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

// Include required files
require_once plugin_dir_path(__FILE__) . 'includes/core/class-main.php';
require_once plugin_dir_path(__FILE__) . 'includes/core/class-initializer.php';
require_once plugin_dir_path(__FILE__) . 'includes/core/class-activator.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-media-processor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-notice-components.php';

// Initialize the plugin
add_action('plugins_loaded', array('WP_Media_Organiser', 'get_instance'));

// Register activation hook
register_activation_hook(__FILE__, array('WP_Media_Organiser', 'activate'));
