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
    private static $is_processing = false;
    private $notice_manager;

    public static function get_instance()
    {
        if (null === self::$instance) {
            // Get dependencies from initializer
            $initializer = WP_Media_Organiser_Initializer::get_instance();
            $settings = $initializer->get_settings();
            $processor = new WP_Media_Organiser_Processor($settings);
            $plugin_url = plugin_dir_url(dirname(__FILE__));

            self::$instance = new self($plugin_url, $settings, $processor);
        }
        return self::$instance;
    }

    private function __construct($plugin_url, $settings, $processor)
    {
        $this->plugin_url = $plugin_url;
        $this->settings = $settings;
        $this->processor = $processor;
        $this->logger = WP_Media_Organiser_Logger::get_instance();
        $this->notice_manager = CWP_Media_Organiser_Notice_Manager::get_instance();

        // Add bulk actions
        add_filter('bulk_actions-edit-post', array($this, 'register_bulk_actions'));
        add_filter('bulk_actions-edit-page', array($this, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-edit-post', array($this, 'handle_bulk_action'), 10, 3);
        add_filter('handle_bulk_actions-edit-page', array($this, 'handle_bulk_action'), 10, 3);

        // Handle media reorganization on legitimate post saves
        add_action('save_post', array($this, 'handle_save_post'), 10, 3);

        // Add AJAX handlers for preview
        add_action('wp_ajax_wp_media_organiser_preview', array($this, 'ajax_get_preview_paths'));
    }

    /**
     * Handle media reorganization on post save
     */
    public function handle_save_post($post_id, $post, $update)
    {
        $this->logger->log("=== Starting handle_save_post ===", 'debug');
        $this->logger->log("Post ID: $post_id", 'debug');
        $this->logger->log("Post Type: {$post->post_type}", 'debug');
        $this->logger->log("Post Status: {$post->post_status}", 'debug');
        $this->logger->log("Is Update: " . ($update ? 'yes' : 'no'), 'debug');
        $this->logger->log("Current Action: " . current_action(), 'debug');
        $this->logger->log("Current Filter: " . current_filter(), 'debug');
        $this->logger->log("Is AJAX: " . (wp_doing_ajax() ? 'yes' : 'no'), 'debug');
        $this->logger->log("Is REST: " . ((defined('REST_REQUEST') && REST_REQUEST) ? 'yes' : 'no'), 'debug');
        $this->logger->log("Is Autosave: " . ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) ? 'yes' : 'no'), 'debug');
        $this->logger->log("Backtrace:", 'debug');
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        foreach ($backtrace as $index => $trace) {
            $caller = isset($trace['class']) ? "{$trace['class']}::{$trace['function']}" : $trace['function'];
            $file = isset($trace['file']) ? basename($trace['file']) : 'unknown';
            $line = isset($trace['line']) ? $trace['line'] : 'unknown';
            $this->logger->log("  #{$index} {$caller} in {$file}:{$line}", 'debug');
        }

        // Skip if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            $this->logger->log("Skipping: This is an autosave", 'debug');
            return;
        }

        // Skip if this is a revision
        if (wp_is_post_revision($post_id)) {
            $this->logger->log("Skipping: This is a revision", 'debug');
            return;
        }

        // Skip auto-drafts
        if ($post->post_status === 'auto-draft') {
            $this->logger->log("Skipping: This is an auto-draft", 'debug');
            return;
        }

        // Skip built-in post types that we don't want to process
        $built_in_skip_types = array('revision', 'attachment', 'nav_menu_item', 'customize_changeset', 'custom_css');
        if (in_array($post->post_type, $built_in_skip_types)) {
            $this->logger->log("Skipping: Post type {$post->post_type} is in skip list", 'debug');
            return;
        }

        // Skip if not a valid post type
        $valid_types = array_keys($this->settings->get_valid_post_types());
        if (!in_array($post->post_type, $valid_types)) {
            $this->logger->log("Skipping: Post type {$post->post_type} is not in valid types list", 'debug');
            return;
        }

        // Skip if this is an AJAX request (preview updates)
        if (wp_doing_ajax()) {
            $this->logger->log("Skipping: This is an AJAX request", 'debug');
            return;
        }

        // Skip if this is a REST API request (Gutenberg)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $this->logger->log("Skipping: This is a REST API request", 'debug');
            return;
        }

        // Skip if this is a post update without actual content changes or taxonomy changes
        if ($update) {
            $old_post = get_post($post_id);
            $taxonomy_name = $this->settings->get_setting('taxonomy_name');

            // Get old and new terms
            $old_terms = $taxonomy_name ? get_the_terms($post_id, $taxonomy_name) : array();
            $old_term_id = $old_terms && !is_wp_error($old_terms) ? reset($old_terms)->term_id : 0;

            // Check if anything relevant has changed
            if ($old_post &&
                $old_post->post_content === $post->post_content &&
                $old_post->post_title === $post->post_title &&
                $old_post->post_status === $post->post_status &&
                $old_post->post_name === $post->post_name &&
                $old_term_id === (isset($_POST['tax_input'][$taxonomy_name]) ? (int) $_POST['tax_input'][$taxonomy_name] : 0)) {
                $this->logger->log("Skipping: No relevant content or taxonomy changes detected", 'debug');
                return;
            }
        }

        $this->logger->log("=== Proceeding with media reorganization ===", 'debug');

        // Process the media reorganization
        $results = $this->processor->bulk_reorganize_media(array($post_id));

        // Store results in a transient specific to this post
        set_transient('wp_media_organiser_post_' . $post_id, $results, 30);

        $this->logger->log("=== Completed handle_save_post ===", 'debug');
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
     * AJAX handler for getting preview paths
     */
    public function ajax_get_preview_paths()
    {
        check_ajax_referer('wp_media_organiser_preview', 'nonce');

        $post_id = intval($_POST['post_id']);
        if (!$post_id) {
            wp_send_json_error('Invalid post ID');
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error('Post not found');
        }

        // Create a temporary copy of the post for path generation
        $temp_post = clone $post;

        // If a post slug was provided and slug is used as identifier
        if (isset($_POST['post_slug']) && $this->settings->get_setting('post_identifier') === 'slug') {
            $temp_post->post_name = sanitize_title($_POST['post_slug']);
        }

        // If taxonomy is enabled in settings, handle term selection/deselection
        if ($this->settings->get_setting('taxonomy_name')) {
            $taxonomy_name = $this->settings->get_setting('taxonomy_name');
            if (isset($_POST['taxonomy_term'])) {
                if ($_POST['taxonomy_term'] === '') {
                    wp_set_object_terms($post_id, array(), $taxonomy_name);
                } elseif (is_numeric($_POST['taxonomy_term'])) {
                    wp_set_object_terms($post_id, intval($_POST['taxonomy_term']), $taxonomy_name);
                }
            }
        }

        // Get preview data
        $preview_data = $this->processor->preview_media_reorganization($post_id);
        if (empty($preview_data)) {
            wp_send_json_error('No media files found');
        }

        // Enhance preview data with additional media information
        foreach ($preview_data as &$item) {
            $item['title'] = get_the_title($item['id']);
            $item['thumbnail_url'] = wp_get_attachment_image_url($item['id'], 'thumbnail');
        }

        wp_send_json_success($preview_data);
    }
}
