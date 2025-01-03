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

        // Add settings page
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Add bulk actions
        add_filter('bulk_actions-edit-post', array($this, 'register_bulk_actions'));
        add_filter('bulk_actions-edit-page', array($this, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-edit-post', array($this, 'handle_bulk_action'), 10, 3);
        add_filter('handle_bulk_actions-edit-page', array($this, 'handle_bulk_action'), 10, 3);

        // Add admin notices
        add_action('admin_notices', array($this, 'bulk_action_admin_notice'));
        add_action('edit_form_after_title', array($this, 'add_preview_notice'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_preview_scripts'));

        // Handle media reorganization on legitimate post saves
        add_action('save_post', array($this, 'handle_save_post'), 10, 3);

        // Add AJAX handlers for preview
        add_action('wp_ajax_get_preview_paths', array($this, 'ajax_get_preview_paths'));
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
     * Display admin notice after bulk action
     */
    public function bulk_action_admin_notice()
    {
        if (!empty($_REQUEST['bulk_reorganize_media'])) {
            // Check if all required parameters are present
            $required_params = array('processed', 'success', 'already_organized', 'failed', 'skipped');
            foreach ($required_params as $param) {
                if (!isset($_REQUEST[$param])) {
                    return; // Exit if any required parameter is missing
                }
            }

            $post_messages = get_transient('wp_media_organiser_bulk_messages');
            delete_transient('wp_media_organiser_bulk_messages');

            $notice_data = array(
                'counts' => array(
                    'success' => intval($_REQUEST['success']),
                    'already_organized' => intval($_REQUEST['already_organized']),
                    'failed' => intval($_REQUEST['failed']),
                    'skipped' => intval($_REQUEST['skipped']),
                ),
            );

            if (!empty($post_messages)) {
                // Extract post info from the first message
                if (isset($post_messages[0])) {
                    if (preg_match('/Post ID (\d+): "([^"]+)" \((\d+) media items\)/', $post_messages[0]['title'], $matches)) {
                        $notice_data['post'] = array(
                            'id' => intval($matches[1]),
                            'title' => $matches[2],
                            'media_count' => intval($matches[3]),
                        );
                    }
                }
                $notice_data['media_items'] = $this->prepare_media_items_data($post_messages);
            }

            echo CWP_Media_Organiser_Notice_Components::render_notice('post-save', $notice_data, true); // true = is list screen
        }
    }

    /**
     * Display admin notice after post update
     */
    public function post_update_admin_notice()
    {
        $screen = get_current_screen();
        if ($screen->base !== 'post') {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        $results = get_transient('wp_media_organiser_post_' . $post_id);
        if (!$results) {
            return;
        }

        // Delete the transient immediately to prevent showing the notice again
        delete_transient('wp_media_organiser_post_' . $post_id);

        $notice_data = array(
            'counts' => array(
                'success' => $results['success'],
                'already_organized' => $results['already_organized'],
                'failed' => $results['failed'],
                'skipped' => $results['skipped'],
            ),
        );

        if (!empty($results['post_messages'])) {
            $notice_data['media_items'] = $this->prepare_media_items_data($results['post_messages']);
        }

        echo CWP_Media_Organiser_Notice_Components::render_notice('post-save', $notice_data, false); // false = not list screen
    }

    /**
     * Add the preview notice container to the post edit screen
     */
    public function add_preview_notice()
    {
        $screen = get_current_screen();
        if ($screen->base !== 'post') {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        // Get current media files for this post
        $media_files = $this->processor->get_post_media_files($post_id);
        if (empty($media_files)) {
            return;
        }

        $notice_data = array(
            'post' => array(
                'id' => $post_id,
                'title' => get_the_title($post_id),
                'media_count' => count($media_files),
            ),
            'media_items' => array(),
        );

        foreach ($media_files as $attachment_id => $current_path) {
            $attachment = get_post($attachment_id);
            $preferred_path = $this->processor->get_new_file_path($attachment_id, $post_id, null, 'preview');
            $normalized_preferred = strtolower(str_replace('\\', '/', $preferred_path));
            $normalized_current = strtolower(str_replace('\\', '/', $current_path));

            // Determine operation status
            $status = ($normalized_preferred === $normalized_current) ? 'correct' : 'move';

            $notice_data['media_items'][] = array(
                'id' => $attachment_id,
                'title' => $attachment->post_title,
                'thumbnail' => wp_get_attachment_image($attachment_id, array(36, 36), true),
                'status' => $status,
                'current_path' => $this->normalize_path($current_path),
                'preferred_path' => $this->color_code_path_components($preferred_path),
            );
        }

        echo CWP_Media_Organiser_Notice_Components::render_notice('pre-save', $notice_data, false);
    }

    /**
     * Prepare media items data for notice display
     */
    private function prepare_media_items_data($post_messages)
    {
        $media_items = array();

        foreach ($post_messages as $post_message) {
            if (empty($post_message['items'])) {
                continue;
            }

            foreach ($post_message['items'] as $item) {
                // Extract media ID and other info using regex
                if (preg_match('/Media ID (\d+) \("([^"]+)"\)/', $item, $matches)) {
                    $media_id = $matches[1];
                    $media_title = $matches[2];

                    // Determine operation status based on the message content
                    if (strpos($item, 'Already in correct location') !== false) {
                        $status = 'correct';
                    } elseif (strpos($item, 'Moved from') !== false) {
                        $status = 'move';
                    } elseif (strpos($item, 'Cannot generate') !== false || strpos($item, 'Error:') !== false || strpos($item, 'Failed to move') !== false) {
                        $status = 'fail';
                    } elseif (strpos($item, 'Skipped') !== false) {
                        $status = 'skip';
                    } else {
                        $status = 'correct'; // Default fallback
                    }

                    // Extract current and preferred paths
                    $current_path = $this->extract_path($item, 'current');
                    $preferred_path = $this->extract_path($item, 'new');

                    // If we have a preferred path, color code it
                    if ($preferred_path) {
                        $preferred_path = $this->color_code_path_components($preferred_path);
                    } else {
                        // If no preferred path (e.g., for 'correct' status), use current path
                        $preferred_path = $this->color_code_path_components($current_path);
                    }

                    $media_items[] = array(
                        'id' => $media_id,
                        'title' => $media_title,
                        'thumbnail' => wp_get_attachment_image($media_id, array(36, 36), true),
                        'status' => $status,
                        'current_path' => $current_path,
                        'preferred_path' => $preferred_path,
                    );
                }
            }
        }

        return $media_items;
    }

    /**
     * Get operation text based on status
     */
    private function get_operation_text($status)
    {
        switch ($status) {
            case 'moved':
                return 'Moved from';
            case 'existing':
                return 'Already in correct location:';
            case 'failed':
                return 'Failed to move from';
            case 'skipped':
                return 'Skipped:';
            default:
                return '';
        }
    }

    /**
     * Extract path from message
     */
    private function extract_path($message, $type)
    {
        if ($type === 'current') {
            if (preg_match('/<del><code>(.*?)<\/code><\/del>/', $message, $matches)) {
                return $this->normalize_path($matches[1]);
            } elseif (preg_match('/<code>(.*?)<\/code>/', $message, $matches)) {
                return $this->normalize_path($matches[1]);
            }
        } else if ($type === 'new' && preg_match('/to <code.*?>(.*?)<\/code>/', $message, $matches)) {
            return $matches[1]; // Already normalized and color-coded
        }
        return '';
    }

    /**
     * Color-code path components based on their type
     *
     * @param string $path The file path to color-code
     * @return string The path with colored components
     */
    private function color_code_path_components($path)
    {
        // First, identify the uploads base path and normalize slashes
        $path = str_replace('\\', '/', $path);

        // Extract the path starting from /wp-content/
        if (strpos($path, '/wp-content/') !== false) {
            $path = substr($path, strpos($path, '/wp-content/'));
        }

        // Split the path into parts, removing empty elements
        $parts = array_filter(explode('/', $path), 'strlen');

        $colored_path = '/';
        $current_part = 0;
        $date_parts_found = false;

        foreach ($parts as $part) {
            // Handle wp-content and uploads directories
            if ($part === 'wp-content' || $part === 'uploads') {
                $colored_path .= $part . '/';
                continue;
            }

            // Skip the first part if it's 'post' or 'page' (post type)
            if ($current_part === 0 && in_array($part, array('post', 'page'))) {
                $colored_path .= sprintf('<span class="path-component path-post-type">%s</span>/', $part);
            }
            // Next part could be taxonomy name (e.g., 'client', 'category')
            elseif ($current_part === 1 && in_array($part, array('client', 'category', 'tag'))) {
                $colored_path .= sprintf('<span class="path-component path-taxonomy">%s</span>/', $part);
            }
            // Next part could be the term slug
            elseif ($current_part === 2 && !is_numeric($part) && !preg_match('/^\d{4}$/', $part)) {
                $colored_path .= sprintf('<span class="path-component path-term">%s</span>/', $part);
            }
            // Handle date components (YYYY/MM)
            elseif (preg_match('/^\d{4}$/', $part)) {
                $colored_path .= $part . '/';
                $date_parts_found = true;
            } elseif (preg_match('/^\d{2}$/', $part)) {
                $colored_path .= $part . '/';
            }
            // Handle post identifier (ID or slug) - only after date parts
            elseif ($date_parts_found && !preg_match('/\.(jpg|jpeg|png|gif|webp|mp4|mp3|pdf)$/i', $part)) {
                $colored_path .= sprintf('<span class="path-component path-post-identifier">%s</span>/', $part);
            }
            // The last part is the filename
            else {
                $colored_path .= $part;
            }

            $current_part++;
        }

        return rtrim($colored_path, '/');
    }

    /**
     * Normalize a path without color coding
     *
     * @param string $path The file path to normalize
     * @return string The normalized path
     */
    private function normalize_path($path)
    {
        // First, normalize slashes
        $path = str_replace('\\', '/', $path);

        // Extract the path starting from /wp-content/
        if (strpos($path, '/wp-content/') !== false) {
            $path = substr($path, strpos($path, '/wp-content/'));
        } elseif (strpos($path, 'wp-content/') === 0) {
            $path = '/' . $path;
        }

        return $path;
    }

    /**
     * Enqueue scripts for the preview functionality
     */
    public function enqueue_preview_scripts($hook)
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        wp_enqueue_script(
            'wp-media-organiser-preview',
            $this->plugin_url . 'assets/js/preview.js',
            array('jquery'),
            '1.0.0',
            true
        );

        // Add data for JavaScript
        wp_localize_script('wp-media-organiser-preview', 'wpMediaOrganiser', array(
            'postId' => get_the_ID(),
            'settings' => array(
                'taxonomyName' => $this->settings->get_setting('taxonomy_name'),
                'postIdentifier' => $this->settings->get_setting('post_identifier'),
            ),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_media_organiser_preview'),
        ));
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
                    // Clear terms if explicitly set to empty
                    wp_set_object_terms($post_id, array(), $taxonomy_name);
                } elseif (is_numeric($_POST['taxonomy_term'])) {
                    // Set term if a valid term ID is provided
                    wp_set_object_terms($post_id, intval($_POST['taxonomy_term']), $taxonomy_name);
                }
            }
        }

        $media_files = $this->processor->get_post_media_files($post_id);
        if (empty($media_files)) {
            wp_send_json_error('No media files found');
        }

        $preview_paths = array();
        foreach ($media_files as $attachment_id => $current_path) {
            // Pass the temporary post to get_new_file_path with preview context
            $new_path = $this->processor->get_new_file_path($attachment_id, $post_id, $temp_post, 'preview');
            if ($new_path) {
                $preview_paths[$attachment_id] = $this->color_code_path_components($new_path);
            }
        }

        wp_send_json_success($preview_paths);
    }

    /**
     * Add the admin menu item
     */
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

    /**
     * Register settings
     */
    public function register_settings()
    {
        // Registration will be handled directly through our custom table
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook)
    {
        if ('media_page_wp-media-organiser' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wp-media-organiser-admin',
            $this->plugin_url . 'assets/css/admin.css',
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'wp-media-organiser-admin',
            $this->plugin_url . 'assets/js/admin.js',
            array('jquery'),
            '1.0.0',
            true
        );

        // Add data for JavaScript
        wp_localize_script('wp-media-organiser-admin', 'wpMediaOrganiser', array(
            'postTypes' => $this->settings->get_valid_post_types(),
            'uploadsPath' => '/wp-content/uploads',
            'useYearMonthFolders' => get_option('uploads_use_yearmonth_folders'),
        ));
    }

    /**
     * Render the settings page
     */
    public function render_settings_page()
    {
        include plugin_dir_path(dirname(__FILE__)) . 'templates/settings-page.php';
    }
}
