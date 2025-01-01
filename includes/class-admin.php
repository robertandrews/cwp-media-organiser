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

        // Get settings instance from initializer
        $initializer = WP_Media_Organiser_Initializer::get_instance();
        $this->settings = $initializer->get_settings();

        // Initialize processor
        $this->processor = new WP_Media_Organiser_Processor($this->settings);

        // Add bulk actions
        add_filter('bulk_actions-edit-post', array($this, 'register_bulk_actions'));
        add_filter('bulk_actions-edit-page', array($this, 'register_bulk_actions'));
        add_filter('handle_bulk_actions-edit-post', array($this, 'handle_bulk_action'), 10, 3);
        add_filter('handle_bulk_actions-edit-page', array($this, 'handle_bulk_action'), 10, 3);

        // Add admin notices
        add_action('admin_notices', array($this, 'bulk_action_admin_notice'));
        add_action('admin_notices', array($this, 'post_update_admin_notice'));
        add_action('edit_form_after_title', array($this, 'add_preview_notice'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_preview_scripts'));

        // Handle post updates
        add_action('post_updated', array($this, 'handle_post_update'), 10, 3);

        // Add AJAX handlers
        add_action('wp_ajax_get_preview_paths', array($this, 'ajax_get_preview_paths'));
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

            $success = intval($_REQUEST['success']);
            $already_organized = intval($_REQUEST['already_organized']);
            $failed = intval($_REQUEST['failed']);
            $skipped = intval($_REQUEST['skipped']);

            $post_messages = get_transient('wp_media_organiser_bulk_messages');
            delete_transient('wp_media_organiser_bulk_messages');

            echo $this->render_media_status_notice($success, $already_organized, $failed, $skipped, $post_messages);
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

        echo $this->render_media_status_notice(
            $results['success'],
            $results['already_organized'],
            $results['failed'],
            $results['skipped'],
            $results['post_messages']
        );
    }

    /**
     * Render the media status notice HTML
     *
     * @param int $success Number of successfully moved files
     * @param int $already_organized Number of already organized files
     * @param int $failed Number of failed operations
     * @param int $skipped Number of skipped files
     * @param array $post_messages Array of post-specific messages
     * @return string The rendered HTML
     */
    private function render_media_status_notice($success, $already_organized, $failed, $skipped, $post_messages)
    {
        $output = sprintf(
            '<div class="notice notice-info is-dismissible">' .
            '<style>
                .media-status-item { display: flex; align-items: flex-start; margin-bottom: 5px; }
                .media-status-item img { width: 36px; height: 36px; object-fit: cover; margin-right: 10px; }
                .media-status-item .status-text { flex: 1; padding-bottom: 8px; }
                .status-dot { margin-right: 5px; }
                .status-dot-moved { color: #ffb900; }
                .status-dot-existing { color: #46b450; }
                .status-dot-failed { color: #dc3232; }
                .status-dot-skipped { color: #888888; }
                .summary-counts span { margin-right: 15px; }
                /* Path component styles */
                .path-component { padding: 2px 4px; border-radius: 2px; }
                .path-post-type { background-color: #ffebee; color: #c62828; }
                .path-taxonomy { background-color: #e8f5e9; color: #2e7d32; }
                .path-term { background-color: #e3f2fd; color: #1565c0; }
                .path-post-identifier { background-color: #fff3e0; color: #ef6c00; }
                code {
                    background: #f5f5f5 !important;
                    margin: 0 !important;
                    display: inline-block !important;
                    border-radius: 4px !important;
                }
            </style>' .
            '<p class="summary-counts">' .
            '<span><span class="status-dot status-dot-existing">●</span>Already organized: %2$d</span>' .
            '<span><span class="status-dot status-dot-moved">●</span>Files moved: %1$d</span>' .
            '<span><span class="status-dot status-dot-failed">●</span>Failed: %3$d</span>' .
            '<span><span class="status-dot status-dot-skipped">●</span>Skipped: %4$d</span>' .
            '</p>',
            $success,
            $already_organized,
            $failed,
            $skipped
        );

        if (!empty($post_messages)) {
            foreach ($post_messages as $post_message) {
                // Extract post ID from the title using regex
                if (preg_match('/Post ID (\d+):/', $post_message['title'], $matches)) {
                    $post_id = $matches[1];
                    $post_title = preg_replace('/^Post ID \d+: /', '', $post_message['title']);
                    $post_edit_link = get_edit_post_link($post_id);
                    $output .= sprintf(
                        '<p><strong>Post ID <a href="%s">%d</a>: %s</strong></p>',
                        esc_url($post_edit_link),
                        $post_id,
                        esc_html($post_title)
                    );
                } else {
                    $output .= sprintf('<p><strong>%s</strong></p>', esc_html($post_message['title']));
                }

                if (!empty($post_message['items'])) {
                    $output .= '<ul style="margin-left: 20px;">';
                    foreach ($post_message['items'] as $item) {
                        // Extract media ID from the message using regex
                        if (preg_match('/Media ID (\d+) \("([^"]+)"\)/', $item, $matches)) {
                            $media_id = $matches[1];
                            $media_title = $matches[2];
                            $media_edit_link = get_edit_post_link($media_id);
                            $thumbnail = wp_get_attachment_image($media_id, array(36, 36), true);

                            // Determine status type and apply appropriate styling
                            $dot_class = 'status-dot-existing';
                            if (strpos($item, 'Moved from') !== false) {
                                $dot_class = 'status-dot-moved';
                            } elseif (strpos($item, 'Cannot generate') !== false || strpos($item, 'Error:') !== false) {
                                $dot_class = 'status-dot-failed';
                            }

                            // Replace the original "Media ID X" text with a linked version
                            $linked_item = preg_replace(
                                '/Media ID \d+ \("([^"]+)"\)/',
                                sprintf('Media ID <a href="%s">%d</a> ("%s")', esc_url($media_edit_link), $media_id, $media_title),
                                $item
                            );

                            // Color-code the file path components
                            $path_pattern = '/<code>(.*?)<\/code>/';
                            if (preg_match_all($path_pattern, $linked_item, $path_matches)) {
                                // If we have two paths (from -> to), only color-code the second one
                                if (count($path_matches[0]) === 2) {
                                    $from_path = $this->normalize_path($path_matches[1][0]);
                                    $to_path = $this->color_code_path_components($path_matches[1][1]);
                                    $linked_item = str_replace(
                                        array($path_matches[0][0], $path_matches[0][1]),
                                        array('<code><del>' . $from_path . '</del></code>', '<code>' . $to_path . '</code>'),
                                        $linked_item
                                    );
                                } else {
                                    // Single path (e.g., "already in correct location") - color-code it
                                    $path = $path_matches[1][0];
                                    $colored_path = $this->color_code_path_components($path);
                                    $linked_item = str_replace($path_matches[0][0], '<code>' . $colored_path . '</code>', $linked_item);
                                }
                            }

                            $output .= sprintf(
                                '<li class="media-status-item"><div>%s</div><span class="status-text"><span class="status-dot %s">●</span>%s</span></li>',
                                $thumbnail,
                                $dot_class,
                                wp_kses($linked_item, array(
                                    'code' => array(),
                                    'a' => array('href' => array()),
                                    'span' => array('class' => array()),
                                    'del' => array(),
                                ))
                            );
                        } else {
                            // For messages without media ID (like errors)
                            $output .= sprintf(
                                '<li><span class="status-text"><span class="status-dot status-dot-failed">●</span>%s</span></li>',
                                wp_kses($item, array('code' => array()))
                            );
                        }
                    }
                    $output .= '</ul>';
                }
            }
        }

        $output .= '</div>';
        return $output;
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
        }

        return $path;
    }

    /**
     * Handle post updates and reorganize media if needed
     */
    public function handle_post_update($post_id, $post_after, $post_before)
    {
        // Skip if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Skip if this is a revision
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Process the media reorganization
        $results = $this->processor->bulk_reorganize_media(array($post_id));

        // Store results in a transient specific to this post
        set_transient('wp_media_organiser_post_' . $post_id, $results, 30);
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

        echo '<div id="media-organiser-preview" class="notice notice-warning is-dismissible" style="display:none;">';
        echo '<style>
            .media-status-item { display: flex; align-items: flex-start; margin-bottom: 5px; }
            .media-status-item img { width: 36px; height: 36px; object-fit: cover; margin-right: 10px; }
            .media-status-item .status-text { flex: 1; padding-bottom: 8px; }
            .status-dot { margin-right: 5px; }
            .status-dot-preview { color: #ffb900; }
            .status-dot-existing { color: #46b450; }
            .summary-counts span { margin-right: 15px; }
            .path-component { padding: 2px 4px; border-radius: 2px; }
            .path-post-type { background-color: #ffebee; color: #c62828; }
            .path-taxonomy { background-color: #e8f5e9; color: #2e7d32; }
            .path-term { background-color: #e3f2fd; color: #1565c0; }
            .path-post-identifier { background-color: #fff3e0; color: #ef6c00; }
            code {
                background: #f5f5f5 !important;
                margin: 0 !important;
                display: inline-block !important;
                border-radius: 4px !important;
            }
        </style>';

        // Check if any files need to be moved
        $needs_moving = false;
        foreach ($media_files as $attachment_id => $current_path) {
            $new_path = $this->processor->get_new_file_path($attachment_id, $post_id);
            $normalized_new_path = strtolower(str_replace('\\', '/', $new_path));
            $normalized_file = strtolower(str_replace('\\', '/', $current_path));
            if ($normalized_new_path !== $normalized_file) {
                $needs_moving = true;
                break;
            }
        }

        echo '<p><strong>' . ($needs_moving ? 'Preview: The following media files will be moved when you save:' : 'Media files organization status:') . '</strong></p>';
        echo '<ul style="margin-left: 20px;">';

        foreach ($media_files as $attachment_id => $current_path) {
            $attachment = get_post($attachment_id);
            $thumbnail = wp_get_attachment_image($attachment_id, array(36, 36), true);
            $new_path = $this->processor->get_new_file_path($attachment_id, $post_id);

            // Normalize paths for comparison
            $normalized_new_path = strtolower(str_replace('\\', '/', $new_path));
            $normalized_file = strtolower(str_replace('\\', '/', $current_path));

            $is_in_place = $normalized_new_path === $normalized_file;

            echo sprintf(
                '<li class="media-status-item" data-media-id="%d"><div>%s</div><span class="status-text"><span class="status-dot status-dot-%s">●</span>Media ID <a href="%s">%d</a> ("%s"): %s %s</span></li>',
                $attachment_id,
                $thumbnail,
                $is_in_place ? 'existing' : 'preview',
                get_edit_post_link($attachment_id),
                $attachment_id,
                $attachment->post_title,
                $is_in_place ? 'Already in correct location:' : 'Will move from',
                $is_in_place
                ? '<code>' . $this->color_code_path_components($current_path) . '</code>'
                : sprintf('<code><del>%s</del></code> to <code class="preview-path-%d">%s</code>',
                    $this->normalize_path($current_path),
                    $attachment_id,
                    $this->color_code_path_components($new_path)
                )
            );
        }

        echo '</ul></div>';

        // Add data for JavaScript
        wp_localize_script('wp-media-organiser-preview', 'wpMediaOrganiser', array(
            'postId' => $post_id,
            'settings' => array(
                'taxonomyName' => $this->settings->get_setting('taxonomy_name'),
                'postIdentifier' => $this->settings->get_setting('post_identifier'),
            ),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_media_organiser_preview'),
        ));
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
            // Pass the temporary post to get_new_file_path
            $new_path = $this->processor->get_new_file_path($attachment_id, $post_id, $temp_post);
            if ($new_path) {
                $preview_paths[$attachment_id] = $this->color_code_path_components($new_path);
            }
        }

        wp_send_json_success($preview_paths);
    }
}
