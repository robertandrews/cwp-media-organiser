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

            echo CWP_Media_Organiser_Notice_Renderer::get_instance()->render_notice(
                'edit.php', // we're on the list screen
                'post-save',
                $notice_data
            );
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

        echo CWP_Media_Organiser_Notice_Renderer::get_instance()->render_notice(
            'post.php', // we're on single post screen
            'post-save',
            $notice_data
        );
    }

    /**
     * Add the preview notice container to the post edit screen
     */
    public function add_preview_notice()
    {
        $this->logger->log("=== Starting add_preview_notice ===", 'debug');

        $screen = get_current_screen();
        if (!$screen || !in_array($screen->base, array('post', 'page'))) {
            $this->logger->log("Skipping: Not on post/page edit screen", 'debug');
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            $this->logger->log("Skipping: No post ID found", 'debug');
            return;
        }

        // Get preview data
        $preview_data = $this->processor->preview_media_reorganization($post_id);
        $this->logger->log("Preview data: " . print_r($preview_data, true), 'debug');

        if (empty($preview_data)) {
            $this->logger->log("Skipping: No media files found", 'debug');
            return;
        }

        // Prepare notice data
        $notice_data = array(
            'notice_type' => 'Preview',
            'media_items' => array(),
            'post_info' => array(
                'post_id' => $post_id,
                'post_title' => get_the_title($post_id),
            ),
        );

        foreach ($preview_data as $item) {
            $media_id = $item['id'];
            $current_path = $this->normalize_path($item['current_path']);
            $preferred_path = $this->normalize_path($item['preferred_path']);
            $paths_match = ($current_path === $preferred_path);
            $status = $item['status'];

            $notice_data['media_items'][] = array(
                'media_id' => $media_id,
                'media_title' => get_the_title($media_id),
                'media_edit_url' => get_edit_post_link($media_id),
                'thumbnail_url' => wp_get_attachment_image_url($media_id, 'thumbnail'),
                'status' => $status,
                'status_class' => str_replace('will_', '', $status),
                'operation_text' => $this->get_operation_text($status),
                'current_path' => $current_path,
                'paths_match' => $paths_match,
                'is_pre_save' => true,
                // Add path components
                'post_type' => isset($item['post_type']) ? $item['post_type'] : '',
                'taxonomy' => isset($item['taxonomy']) ? $item['taxonomy'] : '',
                'term' => isset($item['term']) ? $item['term'] : '',
                'year' => isset($item['year']) ? $item['year'] : '',
                'month' => isset($item['month']) ? $item['month'] : '',
                'post_id' => isset($item['post_id']) ? $item['post_id'] : '',
                'filename' => isset($item['filename']) ? $item['filename'] : basename($preferred_path),
            );
        }

        $this->logger->log("Notice data prepared: " . print_r($notice_data, true), 'debug');

        // Output the notice after the title
        ?>
        <div id="media-organiser-notice-container">
            <?php
// Render the notice
        echo CWP_Media_Organiser_Notice_Renderer::get_instance()->render_notice(
            'post.php',
            'preview',
            $notice_data
        );
        ?>
        </div>
        <?php
}

    /**
     * Prepare media items data for notice display
     */
    private function prepare_media_items_data($post_messages)
    {
        $media_items = array();
        foreach ($post_messages as $message) {
            if (empty($message['media_items'])) {
                continue;
            }

            foreach ($message['media_items'] as $item) {
                $media_id = $item['id'];
                $current_path = $this->normalize_path($item['current_path']);
                $preferred_path = $this->normalize_path($item['preferred_path']);
                $paths_match = ($current_path === $preferred_path);

                $media_items[] = array(
                    'media_id' => $media_id,
                    'media_title' => get_the_title($media_id),
                    'media_edit_url' => get_edit_post_link($media_id),
                    'thumbnail_url' => wp_get_attachment_image_url($media_id, 'thumbnail'),
                    'status' => $item['status'],
                    'status_class' => str_replace('will_', '', $item['status']), // Convert will_move to move, etc.
                    'operation_text' => $this->get_operation_text($item['status']),
                    'current_path' => $current_path,
                    'colored_path' => $this->color_code_path_components($preferred_path),
                    'paths_match' => $paths_match,
                    'is_pre_save' => false, // This method is only used for post-save notices
                );
            }
        }
        return $media_items;
    }

    /**
     * Get operation text based on status
     */
    private function get_operation_text($status)
    {
        $pre_save_text = array(
            'correct' => 'Already in correct location:',
            'will_move' => 'Will move to preferred path',
            'will_fail' => 'Cannot move from',
            'will_skip' => 'Will skip:',
        );

        $post_save_text = array(
            'correct' => 'Already in correct location:',
            'moved' => 'Moved from',
            'failed' => 'Failed to move from',
            'skipped' => 'Skipped:',
        );

        // Convert will_* statuses to their base form for post-save text lookup
        $lookup_status = str_replace('will_', '', $status);

        return isset($pre_save_text[$status])
        ? $pre_save_text[$status]
        : (isset($post_save_text[$lookup_status])
            ? $post_save_text[$lookup_status]
            : '');
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

        // Enqueue notice styles
        wp_enqueue_style(
            'wp-media-organiser-notice',
            $this->plugin_url . 'assets/css/notice.css',
            array(),
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/css/notice.css')
        );

        // Enqueue Mustache.js first
        wp_enqueue_script(
            'mustache',
            $this->plugin_url . 'assets/js/lib/mustache.min.js',
            array('jquery'),
            '4.2.0',
            true
        );

        // Enqueue notice renderer
        wp_enqueue_script(
            'wp-media-organiser-notice-renderer',
            $this->plugin_url . 'assets/js/notice-renderer.js',
            array('jquery', 'mustache'),
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/js/notice-renderer.js'),
            true
        );

        // Enqueue preview script
        wp_enqueue_script(
            'wp-media-organiser-preview',
            $this->plugin_url . 'assets/js/preview.js',
            array('jquery', 'mustache', 'wp-media-organiser-notice-renderer'),
            filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/js/preview.js'),
            true
        );

        // Add data for JavaScript
        wp_localize_script('wp-media-organiser-preview', 'wpMediaOrganiser', array(
            'postId' => get_the_ID(),
            'settings' => array(
                'taxonomyName' => $this->settings->get_setting('taxonomy_name'),
                'postIdentifier' => $this->settings->get_setting('post_identifier'),
            ),
            'noticeConfig' => CWP_Media_Organiser_Notice_Config::get_js_config(),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_media_organiser_preview'),
            'templatesUrl' => $this->plugin_url . 'templates/notices',
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
