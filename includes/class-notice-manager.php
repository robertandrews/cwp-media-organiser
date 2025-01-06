<?php

if (!defined('WPINC')) {
    die;
}

class CWP_Media_Organiser_Notice_Manager
{
    // Singleton instance
    private static $instance = null;
    private $logger;
    private $plugin_url;
    private $settings;
    private $processor;

    // Notice configuration constants
    const STATUS_TYPES = [
        'correct' => [
            'dot_class' => 'status-dot-correct',
            'operation_class' => 'operation-correct',
            'color' => '#46b450',
        ],
        'move' => [
            'dot_class' => 'status-dot-moved',
            'operation_class' => 'operation-move',
            'color' => '#ffb900',
        ],
        'fail' => [
            'dot_class' => 'status-dot-failed',
            'operation_class' => 'operation-fail',
            'color' => '#dc3232',
        ],
        'skip' => [
            'dot_class' => 'status-dot-skipped',
            'operation_class' => 'operation-skip',
            'color' => '#888888',
        ],
        'preview' => [
            'dot_class' => 'status-dot-preview',
            'operation_class' => 'operation-preview',
            'color' => '#888888',
        ],
    ];

    // Operation text templates - single source of truth for both PHP and JS
    const OPERATION_TEXT = [
        'preview' => [
            'correct' => 'Already in correct location:',
            'will_move' => 'Will move to preferred path',
            'will_fail' => 'Cannot move from',
            'will_skip' => 'Will skip:',
        ],
        'post-save' => [
            'correct' => 'Already in correct location:',
            'moved' => 'Moved from',
            'failed' => 'Failed to move from',
            'skipped' => 'Skipped:',
        ],
    ];

    private function __construct()
    {
        $this->logger = WP_Media_Organiser_Logger::get_instance();
        $this->plugin_url = plugin_dir_url(dirname(__FILE__));
        $this->settings = WP_Media_Organiser_Initializer::get_instance()->get_settings();
        $this->processor = new WP_Media_Organiser_Processor($this->settings);

        // Add notice actions
        add_action('admin_notices', array($this, 'display_bulk_action_notice'));
        add_action('admin_notices', array($this, 'display_post_update_notice'));
        add_action('edit_form_after_title', array($this, 'display_preview_notice'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_notice_scripts'));
    }

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get operation text based on status and context
     */
    public function get_operation_text($status, $context = 'post-save')
    {
        // For preview context, handle will_* statuses
        if ($context === 'preview') {
            return self::OPERATION_TEXT['preview'][$status] ?? '';
        }

        // For post-save context, convert will_* to base status
        $lookup_status = str_replace('will_', '', $status);
        return self::OPERATION_TEXT['post-save'][$lookup_status] ?? '';
    }

    /**
     * Get configuration for JavaScript
     */
    public function get_js_config()
    {
        return [
            'status_types' => self::STATUS_TYPES,
            'operation_text' => self::OPERATION_TEXT,
        ];
    }

    /**
     * Prepare notice data for preview display
     */
    public function prepare_preview_notice_data($preview_data, $post_id)
    {
        if (empty($preview_data)) {
            return null;
        }

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
                'operation_text' => $this->get_operation_text($status, 'preview'),
                'current_path' => $current_path,
                'paths_match' => $paths_match,
                'is_preview' => true,
                'post_type' => isset($item['post_type']) ? $item['post_type'] : '',
                'taxonomy' => isset($item['taxonomy']) ? $item['taxonomy'] : '',
                'term' => isset($item['term']) ? $item['term'] : '',
                'year' => isset($item['year']) ? $item['year'] : '',
                'month' => isset($item['month']) ? $item['month'] : '',
                'post_id' => isset($item['post_id']) ? $item['post_id'] : '',
                'filename' => isset($item['filename']) ? $item['filename'] : basename($preferred_path),
            );
        }

        return $notice_data;
    }

    /**
     * Extract path from message with various formats
     *
     * @param string $message The message containing the path
     * @param string $type The type of path to extract ('current', 'new', or 'single')
     * @return string The extracted path
     */
    private function extract_path($message, $type = 'single')
    {
        if ($type === 'current') {
            if (preg_match('/<del><code>(.*?)<\/code><\/del>/', $message, $matches)) {
                return $this->normalize_path($matches[1]);
            } elseif (preg_match('/<code>(.*?)<\/code>/', $message, $matches)) {
                return $this->normalize_path($matches[1]);
            }
        } else if ($type === 'new' && preg_match('/to <code.*?>(.*?)<\/code>/', $message, $matches)) {
            return $matches[1]; // Already normalized and color-coded
        } else if ($type === 'single' && preg_match('/<code>([^<]+)<\/code>/', $message, $matches)) {
            return $this->normalize_path($matches[1]);
        }
        return '';
    }

    /**
     * Normalize a path without color coding
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
     * Color-code path components based on their type
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
            // Next part could be taxonomy name
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
     * Display admin notice after bulk action
     */
    public function display_bulk_action_notice()
    {
        if (!empty($_REQUEST['bulk_reorganize_media'])) {
            // Check if all required parameters are present
            $required_params = array('processed', 'success', 'already_organized', 'failed', 'skipped');
            foreach ($required_params as $param) {
                if (!isset($_REQUEST[$param])) {
                    return;
                }
            }

            $post_messages = get_transient('wp_media_organiser_bulk_messages');
            $this->logger->log("Post messages from transient: " . print_r($post_messages, true), 'debug');
            delete_transient('wp_media_organiser_bulk_messages');

            $notice_data = array(
                'show_summary' => true,
                'processed' => intval($_REQUEST['processed']),
                'success' => intval($_REQUEST['success']),
                'already_organized' => intval($_REQUEST['already_organized']),
                'failed' => intval($_REQUEST['failed']),
                'skipped' => intval($_REQUEST['skipped']),
            );

            if (!empty($post_messages)) {
                $posts = array();
                foreach ($post_messages as $message) {
                    if (preg_match('/Post ID (\d+): "([^"]+)" \((\d+) media items\)/', $message['title'], $matches)) {
                        $post_id = intval($matches[1]);
                        $post_data = array(
                            'post_id' => $post_id,
                            'post_title' => html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                            'media_count' => intval($matches[3]),
                            'post_edit_url' => get_edit_post_link($post_id),
                            'media_items' => array(),
                        );

                        if (!empty($message['items'])) {
                            $post_data['media_items'] = $this->prepare_media_items_data(array(
                                array('items' => $message['items']),
                            ));
                        }

                        $posts[] = $post_data;
                    }
                }

                if (!empty($posts)) {
                    $notice_data['posts'] = $posts;
                }
            }

            echo CWP_Media_Organiser_Notice_Renderer::get_instance()->render_notice(
                'edit.php',
                'post-save',
                $notice_data
            );
        }
    }

    /**
     * Display admin notice after post update
     */
    public function display_post_update_notice()
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
            'post.php',
            'post-save',
            $notice_data
        );
    }

    /**
     * Display preview notice on post edit screen
     */
    public function display_preview_notice()
    {
        $this->logger->log("=== Starting display_preview_notice ===", 'debug');

        $screen = get_current_screen();
        if (!$screen || !in_array($screen->base, array('post', 'page'))) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        $preview_data = $this->processor->preview_media_reorganization($post_id);
        if (empty($preview_data)) {
            return;
        }

        $notice_data = $this->prepare_preview_notice_data($preview_data, $post_id);
        ?>
        <div id="media-organiser-notice-container">
            <?php
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
     * Enqueue notice-related scripts and styles
     */
    public function enqueue_notice_scripts($hook)
    {
        // Enqueue notice styles for both post editing and list screens
        if (in_array($hook, array('post.php', 'post-new.php', 'edit.php'))) {
            wp_enqueue_style(
                'wp-media-organiser-notice',
                $this->plugin_url . 'assets/css/notice.css',
                array(),
                filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/css/notice.css')
            );
        }

        // Only enqueue the preview scripts on post editing screens
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        // Enqueue Mustache.js
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
            'noticeConfig' => $this->get_js_config(),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_media_organiser_preview'),
            'templatesUrl' => $this->plugin_url . 'templates/notice',
        ));
    }

    /**
     * Prepare media items data for notice display
     */
    public function prepare_media_items_data($post_messages)
    {
        $this->logger->log("Preparing media items data from messages: " . print_r($post_messages, true), 'debug');
        $media_items = array();
        foreach ($post_messages as $message) {
            if (empty($message['items'])) {
                continue;
            }

            foreach ($message['items'] as $item_text) {
                // Parse the media item text
                if (preg_match('/Media ID (\d+) \("([^"]+)"\): (.+)/', $item_text, $matches)) {
                    $media_id = intval($matches[1]);
                    $media_title = $matches[2];
                    $operation_text = $matches[3];

                    // Extract paths from the operation text
                    $current_path = '';
                    $preferred_path = '';
                    $status = 'correct';

                    if (strpos($operation_text, 'Already in correct location') !== false) {
                        $current_path = $this->extract_path($operation_text, 'single');
                        $preferred_path = $current_path;
                        $status = 'correct';
                    } else {
                        $current_path = $this->extract_path($operation_text, 'current');
                        $preferred_path = $this->extract_path($operation_text, 'new');
                        $status = 'moved';
                    }

                    $media_item = array(
                        'media_id' => $media_id,
                        'media_title' => $media_title,
                        'media_edit_url' => get_edit_post_link($media_id),
                        'thumbnail_url' => wp_get_attachment_image_url($media_id, 'thumbnail'),
                        'status' => $status,
                        'status_class' => $status,
                        'operation_text' => $this->get_operation_text($status),
                        'current_path' => $current_path,
                        'paths_match' => ($current_path === $preferred_path),
                        'is_preview' => false,
                    );

                    // Extract path components from preferred path
                    $path_parts = explode('/', trim($preferred_path, '/'));
                    foreach ($path_parts as $part) {
                        if (preg_match('/^\d{4}$/', $part)) {
                            $media_item['year'] = $part;
                        } elseif (preg_match('/^\d{2}$/', $part)) {
                            $media_item['month'] = $part;
                        } elseif ($part === 'post' || $part === 'page') {
                            $media_item['post_type'] = $part;
                        } elseif ($part === 'client' || $part === 'category') {
                            $media_item['taxonomy'] = $part;
                        } elseif (preg_match('/\.(jpg|jpeg|png|gif)$/i', $part)) {
                            $media_item['filename'] = $part;
                        }
                    }

                    $media_items[] = $media_item;
                }
            }
        }
        return $media_items;
    }
}
