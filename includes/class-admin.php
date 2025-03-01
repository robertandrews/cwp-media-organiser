<?php
/**
 * Admin functionality for WP Media Organiser
 */

if (! defined('WPINC')) {
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
            $settings    = $initializer->get_settings();
            $processor   = new WP_Media_Organiser_Processor($settings);
            $plugin_url  = plugin_dir_url(dirname(__FILE__));

            self::$instance = new self($plugin_url, $settings, $processor);
        }
        return self::$instance;
    }

    private function __construct($plugin_url, $settings, $processor)
    {
        $this->plugin_url     = $plugin_url;
        $this->settings       = $settings;
        $this->processor      = $processor;
        $this->logger         = WP_Media_Organiser_Logger::get_instance();
        $this->notice_manager = CWP_Media_Organiser_Notice_Manager::get_instance();

        // Add bulk actions
        add_filter('bulk_actions-edit-post', [$this, 'register_bulk_actions']);
        add_filter('bulk_actions-edit-page', [$this, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-edit-post', [$this, 'handle_bulk_action'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [$this, 'handle_bulk_action'], 10, 3);

        // Handle media reorganization on legitimate post saves
        add_action('save_post', [$this, 'handle_save_post'], 10, 3);

        // Add AJAX handlers
        add_action('wp_ajax_wp_media_organiser_preview', [$this, 'ajax_get_preview_paths']);
        add_action('wp_ajax_wp_media_organiser_get_term_slug', [$this, 'ajax_get_term_slug']);
        add_action('wp_ajax_wp_media_organiser_get_term_by_name', [$this, 'ajax_get_term_by_name']);

        // Add admin scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Handle post deletion
        add_action('before_delete_post', [$this, 'handle_post_deletion'], 10, 1);
    }

    private function log_save_post_debug($post_id, $post, $update)
    {
        if (! WP_DEBUG) {
            return;
        }

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
            $file   = isset($trace['file']) ? basename($trace['file']) : 'unknown';
            $line   = isset($trace['line']) ? $trace['line'] : 'unknown';
            $this->logger->log("  #{$index} {$caller} in {$file}:{$line}", 'debug');
        }
    }

    /**
     * Handle media reorganization on post save
     */
    public function handle_save_post($post_id, $post, $update)
    {
        $this->log_save_post_debug($post_id, $post, $update);

        // Skip if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            $this->logger->log("Skipping: This is an autosave", 'debug');
            return;
        }

        // Skip auto-drafts
        if ($post->post_status === 'auto-draft') {
            $this->logger->log("Skipping: This is an auto-draft", 'debug');
            return;
        }

        // Skip if we're just updating content URLs
        if ($this->processor->is_updating_content()) {
            $this->logger->log("Skipping: This is a content URL update", 'debug');
            return;
        }

        // Skip built-in post types that we don't want to process
        $built_in_skip_types = ['attachment', 'nav_menu_item', 'customize_changeset', 'custom_css'];
        if (in_array($post->post_type, $built_in_skip_types)) {
            $this->logger->log("Skipping: Post type {$post->post_type} is in skip list", 'debug');
            return;
        }

        // Skip if not a valid post type
        $valid_types = array_keys($this->settings->get_valid_post_types());
        if (! in_array($post->post_type, $valid_types)) {
            $this->logger->log("Skipping: Post type {$post->post_type} is not in valid types list", 'debug');
            return;
        }

        // More selective Ajax request handling
        if (wp_doing_ajax()) {
            // Get the current Ajax action
            $current_action = $_REQUEST['action'] ?? '';

            // List of Ajax actions to skip
            $skip_actions = [
                'heartbeat',
                'wp-remove-post-lock',
                'wp-remove-post-autosave',
                'wp_ajax_wp_media_organiser_preview',
                'wp-preview',
                'autosave',
            ];

            // Check if this is a preview or autosave action
            if (in_array($current_action, $skip_actions)) {
                $this->logger->log("Skipping: This is a {$current_action} Ajax request", 'debug');
                return;
            }

            // Check for post preview
            if (isset($_POST['wp-preview']) && $_POST['wp-preview'] === 'dopreview') {
                $this->logger->log("Skipping: This is a post preview request", 'debug');
                return;
            }

            // If we get here, it's potentially a legitimate Ajax save
            $this->logger->log("Processing Ajax request: {$current_action}", 'debug');
        }

        // Skip if this is a REST API request (Gutenberg)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $this->logger->log("Skipping: This is a REST API request", 'debug');
            return;
        }

        // Skip if this is a post update without actual content changes or taxonomy changes
        if ($update) {
            $old_post      = get_post($post_id);
            $taxonomy_name = $this->settings->get_setting('taxonomy_name');

            // Get old and new terms
            $old_terms   = $taxonomy_name ? get_the_terms($post_id, $taxonomy_name) : [];
            $old_term_id = $old_terms && ! is_wp_error($old_terms) ? reset($old_terms)->term_id : 0;

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
        $results = $this->processor->bulk_reorganize_media([$post_id]);

        // Store results in a transient specific to this post
        set_transient('wp_media_organiser_post_' . $post_id, $results, 30);

        $this->logger->log("=== Completed handle_save_post ===", 'debug');
    }

    /**
     * Register the bulk action
     */
    public function register_bulk_actions($bulk_actions)
    {
        $this->logger->log("=== Register bulk actions called ===", 'debug');
        $this->logger->log("Current actions: " . print_r($bulk_actions, true), 'debug');
        $bulk_actions['reorganize_media'] = __('Reorganize Media', 'wp-media-organiser');
        $this->logger->log("Added reorganize_media action", 'debug');
        $this->logger->log("Final actions: " . print_r($bulk_actions, true), 'debug');
        return $bulk_actions;
    }

    /**
     * Handle the bulk action
     */
    public function handle_bulk_action($redirect_to, $doaction, $post_ids)
    {
        $this->logger->log("=== Starting bulk action handler ===", 'debug');
        $this->logger->log("Action: $doaction", 'debug');
        $this->logger->log("Post IDs: " . implode(', ', $post_ids), 'debug');

        if ($doaction !== 'reorganize_media') {
            $this->logger->log("Skipping: Not a reorganize_media action", 'debug');
            return $redirect_to;
        }

        $this->logger->log("Processing bulk media reorganization for " . count($post_ids) . " posts", 'info');
        $results = $this->processor->bulk_reorganize_media($post_ids);

        $this->logger->log("Bulk action results:", 'debug');
        $this->logger->log("  Success: {$results['success']}", 'debug');
        $this->logger->log("  Already organized: {$results['already_organized']}", 'debug');
        $this->logger->log("  Failed: {$results['failed']}", 'debug');
        $this->logger->log("  Skipped: {$results['skipped']}", 'debug');

        $redirect_to = add_query_arg([
            'bulk_reorganize_media' => '1',
            'processed'             => count($post_ids),
            'success'               => $results['success'],
            'already_organized'     => $results['already_organized'],
            'failed'                => $results['failed'],
            'skipped'               => $results['skipped'],
        ], $redirect_to);

        // Store messages in transient for display
        set_transient('wp_media_organiser_bulk_messages', $results['post_messages'], 30);
        $this->logger->log("Stored bulk action messages in transient", 'debug');

        $this->logger->log("=== Completed bulk action handler ===", 'debug');
        $this->logger->log("Redirecting to: $redirect_to", 'debug');

        return $redirect_to;
    }

    /**
     * AJAX handler for getting preview paths
     */
    public function ajax_get_preview_paths()
    {
        $this->logger->log("=== Starting AJAX preview paths handler ===", 'debug');
        check_ajax_referer('wp_media_organiser_preview', 'nonce');

        $post_id = intval($_POST['post_id']);
        if (! $post_id) {
            $this->logger->log("Invalid post ID provided", 'error');
            wp_send_json_error('Invalid post ID');
        }

        $post = get_post($post_id);
        if (! $post) {
            $this->logger->log("Post not found for ID: $post_id", 'error');
            wp_send_json_error('Post not found');
        }

        $this->logger->log("Processing preview for post: '{$post->post_title}' (ID: $post_id)", 'debug');

        // Create a temporary copy of the post for path generation
        $temp_post = clone $post;

        // If a post slug was provided and slug is used as identifier
        if (isset($_POST['post_slug']) && $this->settings->get_setting('post_identifier') === 'slug') {
            $old_slug             = $temp_post->post_name;
            $temp_post->post_name = sanitize_title($_POST['post_slug']);
            $this->logger->log("Updated post slug for preview from '$old_slug' to '{$temp_post->post_name}'", 'debug');
        }

        // If taxonomy is enabled in settings, handle term selection/deselection
        if ($this->settings->get_setting('taxonomy_name')) {
            $taxonomy_name = $this->settings->get_setting('taxonomy_name');
            $this->logger->log("Processing taxonomy: $taxonomy_name", 'debug');

            if (isset($_POST['taxonomy_term'])) {
                $old_terms   = get_the_terms($post_id, $taxonomy_name);
                $old_term_id = $old_terms && ! is_wp_error($old_terms) ? reset($old_terms)->term_id : 0;

                if ($_POST['taxonomy_term'] === '') {
                    $this->logger->log("Clearing taxonomy terms for preview", 'debug');
                    wp_set_object_terms($post_id, [], $taxonomy_name);
                } elseif (is_numeric($_POST['taxonomy_term'])) {
                    $new_term_id = intval($_POST['taxonomy_term']);
                    $this->logger->log("Updating taxonomy term for preview from $old_term_id to $new_term_id", 'debug');
                    wp_set_object_terms($post_id, $new_term_id, $taxonomy_name);
                }
            }
        }

        // Get preview data
        $this->logger->log("Generating preview data", 'debug');
        $preview_data = $this->processor->preview_media_reorganization($post_id);
        if (empty($preview_data)) {
            $this->logger->log("No media files found for post", 'debug');
            wp_send_json_error('No media files found');
        }

        $this->logger->log("Found " . count($preview_data) . " media items to preview", 'debug');

        // Enhance preview data with additional media information
        foreach ($preview_data as &$item) {
            $item['title']         = get_the_title($item['id']);
            $item['thumbnail_url'] = wp_get_attachment_image_url($item['id'], 'thumbnail');
            $this->logger->log("Added preview data for media ID {$item['id']}: {$item['title']}", 'debug');
        }

        $this->logger->log("=== Completed AJAX preview paths handler ===", 'debug');
        wp_send_json_success($preview_data);
    }

    /**
     * AJAX handler for getting term slugs
     */
    public function ajax_get_term_slug()
    {
        check_ajax_referer('wp_media_organiser_preview', 'nonce');

        $term_id = intval($_POST['term_id']);
        if (! $term_id) {
            wp_send_json_error('Invalid term ID');
        }

        $taxonomy = $this->settings->get_setting('taxonomy_name');
        if (! $taxonomy) {
            wp_send_json_error('No taxonomy configured');
        }

        $term = get_term($term_id, $taxonomy);
        if (! $term || is_wp_error($term)) {
            wp_send_json_error('Term not found');
        }

        wp_send_json_success($term->slug);
    }

    /**
     * AJAX handler for getting term data by name
     */
    public function ajax_get_term_by_name()
    {
        check_ajax_referer('wp_media_organiser_preview', 'nonce');

        $term_name = sanitize_text_field($_POST['term_name']);
        if (! $term_name) {
            wp_send_json_error('Invalid term name');
        }

        $taxonomy = sanitize_text_field($_POST['taxonomy']);
        if (! $taxonomy) {
            wp_send_json_error('No taxonomy provided');
        }

        // Try to get the term by name
        $term = get_term_by('name', $term_name, $taxonomy);
        if (! $term || is_wp_error($term)) {
            // If not found by name, try by slug
            $term = get_term_by('slug', sanitize_title($term_name), $taxonomy);
            if (! $term || is_wp_error($term)) {
                wp_send_json_error('Term not found');
            }
        }

        wp_send_json_success([
            'id'   => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
        ]);
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook)
    {
        // Enqueue on settings page
        if ($hook === 'settings_page_wp-media-organiser') {
            wp_enqueue_style('wp-media-organiser-admin', plugin_dir_url(dirname(__FILE__)) . 'assets/css/admin.css', [], '1.0.0');
            wp_enqueue_script('wp-media-organiser-admin', plugin_dir_url(dirname(__FILE__)) . 'assets/js/admin.js', ['jquery'], '1.0.0', true);

            // Pass data to admin.js
            wp_localize_script('wp-media-organiser-admin', 'wpMediaOrganiser', [
                'uploadsPath'         => '/wp-content/uploads',
                'useYearMonthFolders' => get_option('uploads_use_yearmonth_folders'),
                'postTypes'           => $this->settings->get_valid_post_types(),
            ]);
        }

        // Enqueue on post edit page
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            // First enqueue the notice CSS
            wp_enqueue_style('wp-media-organiser-notice', plugin_dir_url(dirname(__FILE__)) . 'assets/css/notice.css', [], '1.0.0');

            // Then enqueue Underscore.js as a dependency
            wp_enqueue_script('underscore');

            // Finally enqueue our post.js with both jQuery and Underscore as dependencies
            wp_enqueue_script(
                'wp-media-organiser-post',
                plugin_dir_url(dirname(__FILE__)) . 'assets/js/post.js',
                ['jquery', 'underscore'],
                filemtime(plugin_dir_path(dirname(__FILE__)) . 'assets/js/post.js'),
                true
            );

            // Pass settings and nonce to post.js
            wp_localize_script('wp-media-organiser-post', 'wpMediaOrganiser', [
                'settings' => [
                    'taxonomyName'   => $this->settings->get_setting('taxonomy_name'),
                    'postIdentifier' => $this->settings->get_setting('post_identifier'),
                ],
                'nonce'    => wp_create_nonce('wp_media_organiser_preview'),
                'ajaxurl'  => admin_url('admin-ajax.php'),
            ]);

            // Add debug info
            if (WP_DEBUG) {
                $this->logger->log("Enqueued post.js with settings:", 'debug');
                $this->logger->log("Taxonomy Name: " . $this->settings->get_setting('taxonomy_name'), 'debug');
                $this->logger->log("Post Identifier: " . $this->settings->get_setting('post_identifier'), 'debug');
            }
        }
    }

    /**
     * Handle media cleanup when a post is permanently deleted
     */
    public function handle_post_deletion($post_id)
    {
        $post = get_post($post_id);

        if (! $post) {
            return;
        }

        // Skip if not a valid post type
        $valid_types = array_keys($this->settings->get_valid_post_types());
        if (! in_array($post->post_type, $valid_types)) {
            $this->logger->log("Skipping media cleanup - post type '{$post->post_type}' not supported", 'debug');
            return;
        }

        $this->logger->log("Starting media cleanup for deleted post: '{$post->post_title}' (ID: $post_id)", 'info');

        // Get all media files associated with this post
        $media_files = $this->processor->get_post_media_files($post_id);
        $this->logger->log("Found " . count($media_files) . " media files associated with post '{$post->post_title}'", 'info');

        foreach ($media_files as $attachment_id => $file) {
            $attachment = get_post($attachment_id);
            if (! $attachment) {
                continue;
            }

            // Check if this attachment is used by other posts
            $used_by_others = false;

            // Check posts that have this media in their content
            global $wpdb;
            $like_pattern      = '%' . $wpdb->esc_like(wp_get_attachment_url($attachment_id)) . '%';
            $posts_using_media = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM $wpdb->posts
                WHERE ID != %d
                AND post_status != 'trash'
                AND post_type IN ('" . implode("','", $valid_types) . "')
                AND post_content LIKE %s",
                $post_id,
                $like_pattern
            ));

            // Check if it's used as featured image
            $posts_with_thumbnail = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta
                WHERE meta_key = '_thumbnail_id'
                AND meta_value = %d
                AND post_id != %d",
                $attachment_id,
                $post_id
            ));

            // Check if it's directly attached to other posts
            $other_parents = $wpdb->get_col($wpdb->prepare(
                "SELECT post_parent FROM $wpdb->posts
                WHERE ID = %d
                AND post_parent != %d
                AND post_parent != 0",
                $attachment_id,
                $post_id
            ));

            if (! empty($posts_using_media) || ! empty($posts_with_thumbnail) || ! empty($other_parents)) {
                $used_by_others = true;
                $this->logger->log("Media file '{$attachment->post_title}' (ID: $attachment_id) is used by other posts - skipping deletion", 'info');
                continue;
            }

            // If not used by others, delete the attachment
            $this->logger->log("Deleting unused media file '{$attachment->post_title}' (ID: $attachment_id)", 'info');

            // Get the directory before deleting the attachment
            $file_dir = dirname($file);

            // Delete the attachment (this will also delete the files)
            wp_delete_attachment($attachment_id, true);

            // Clean up empty directories
            $this->processor->cleanup_empty_directory($file_dir);
        }

        $this->logger->log("Completed media cleanup for deleted post: '{$post->post_title}' (ID: $post_id)", 'info');
    }
}
