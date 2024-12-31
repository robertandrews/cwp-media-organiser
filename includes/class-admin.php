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
        add_action('admin_notices', array($this, 'post_update_admin_notice'));

        // Handle post updates
        add_action('post_updated', array($this, 'handle_post_update'), 10, 3);

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
                .media-status-item .status-text { flex: 1; padding-top: 8px; }
                .status-dot { margin-right: 5px; }
                .status-dot-moved { color: #46b450; }
                .status-dot-existing { color: #ffb900; }
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
            '<span><span class="status-dot status-dot-moved">●</span>Files moved: %1$d</span>' .
            '<span><span class="status-dot status-dot-existing">●</span>Already organized: %2$d</span>' .
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
                            if (preg_match($path_pattern, $linked_item, $path_matches)) {
                                $path = $path_matches[1];
                                $colored_path = $this->color_code_path_components($path);
                                $linked_item = str_replace($path_matches[0], '<code>' . $colored_path . '</code>', $linked_item);
                            }

                            $output .= sprintf(
                                '<li class="media-status-item"><div>%s</div><span class="status-text"><span class="status-dot %s">●</span>%s</span></li>',
                                $thumbnail,
                                $dot_class,
                                wp_kses($linked_item, array(
                                    'code' => array(),
                                    'a' => array('href' => array()),
                                    'span' => array('class' => array()),
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
            } elseif (preg_match('/^\d{2}$/', $part)) {
                $colored_path .= $part . '/';
            }
            // Handle post ID
            elseif (is_numeric($part)) {
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
}
