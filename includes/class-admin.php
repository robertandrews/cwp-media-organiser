<?php
/**
 * Admin functionality for WP Media Organiser
 */

if (!defined('WPINC')) {
    die;
}

class WP_Media_Organiser_Admin
{
    private $processor;
    private $settings;
    private $logger;
    private static $instance = null;
    private $plugin_path;
    private $plugin_url;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        global $wpdb;

        $this->plugin_path = plugin_dir_path(dirname(__FILE__));
        $this->plugin_url = plugin_dir_url(dirname(__FILE__));
        $this->logger = WP_Media_Organiser_Logger::get_instance();

        $this->logger->log("Admin class constructor called", 'debug');

        // Initialize settings
        $settings_table = $wpdb->prefix . 'media_organiser_settings';
        $this->settings = new WP_Media_Organiser_Settings($settings_table, $this->plugin_path, $this->plugin_url);

        // Initialize processor
        $this->processor = new WP_Media_Organiser_Processor($this->settings);

        // Add bulk actions for all valid post types
        add_filter('bulk_actions-edit-post', array($this, 'register_bulk_actions'));
        add_filter('bulk_actions-edit-page', array($this, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-edit-post', array($this, 'handle_bulk_action'), 10, 3);
        add_filter('handle_bulk_actions-edit-page', array($this, 'handle_bulk_action'), 10, 3);

        // Add admin notices
        add_action('admin_notices', array($this, 'bulk_action_admin_notice'));

        $this->logger->log("Admin class initialization complete", 'debug');
    }

    /**
     * Register the bulk action
     */
    public function register_bulk_actions($bulk_actions)
    {
        $this->logger->log("Register bulk actions called. Current actions: " . print_r($bulk_actions, true), 'debug');
        $bulk_actions['reorganize_media'] = __('Reorganize Media', 'wp-media-organiser');
        $this->logger->log("Added reorganize_media action", 'debug');
        return $bulk_actions;
    }

    /**
     * Handle the bulk action
     */
    public function handle_bulk_action($redirect_to, $doaction, $post_ids)
    {
        if ($doaction !== 'reorganize_media') {
            return $redirect_to;
        }

        $this->logger->log("Handling bulk action for posts: " . implode(', ', $post_ids), 'info');
        $results = $this->processor->bulk_reorganize_media($post_ids);

        $redirect_to = add_query_arg(array(
            'bulk_reorganize_media' => '1',
            'processed' => count($post_ids),
            'success' => $results['success'],
            'already_organized' => $results['already_organized'],
            'failed' => $results['failed'],
            'skipped' => $results['skipped'],
        ), $redirect_to);

        // Store messages in transient for display
        set_transient('wp_media_organiser_bulk_messages', $results['post_messages'], 30);

        return $redirect_to;
    }

    /**
     * Display admin notice after bulk action
     */
    public function bulk_action_admin_notice()
    {
        if (!empty($_REQUEST['bulk_reorganize_media'])) {
            $processed = intval($_REQUEST['processed']);
            $success = intval($_REQUEST['success']);
            $already_organized = intval($_REQUEST['already_organized']);
            $failed = intval($_REQUEST['failed']);
            $skipped = intval($_REQUEST['skipped']);

            $post_messages = get_transient('wp_media_organiser_bulk_messages');
            delete_transient('wp_media_organiser_bulk_messages');

            $output = sprintf(
                '<div class="notice notice-info is-dismissible"><p>' .
                __('Media organization check completed. Files moved: %1$d, Already organized: %2$d, Failed: %3$d, Skipped: %4$d', 'wp-media-organiser') .
                '</p>',
                $success,
                $already_organized,
                $failed,
                $skipped
            );

            if (!empty($post_messages)) {
                foreach ($post_messages as $post_message) {
                    $output .= sprintf('<p><strong>%s</strong></p>', esc_html($post_message['title']));
                    if (!empty($post_message['items'])) {
                        $output .= '<ul style="margin-left: 20px;">';
                        foreach ($post_message['items'] as $item) {
                            $output .= sprintf('<li>%s</li>', wp_kses($item, array('code' => array())));
                        }
                        $output .= '</ul>';
                    }
                }
            }

            $output .= '</div>';
            echo $output;
        }
    }
}
