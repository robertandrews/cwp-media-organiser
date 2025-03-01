<?php
/**
 * Media processing functionality
 */

if (! defined('WPINC')) {
    die;
}

class WP_Media_Organiser_Processor
{
    private $logger;
    private $settings;
    private $is_processing             = false;
    private $should_handle_wp_suffixes = false; // Control whether to handle WordPress numeric suffixes
    private $is_updating_content       = false; // Flag to prevent recursive save_post triggers

    public function __construct($settings)
    {
        $this->logger   = WP_Media_Organiser_Logger::get_instance();
        $this->settings = $settings;
    }

    /**
     * Check if we're currently updating post content URLs
     *
     * @return bool True if we're updating content URLs, false otherwise
     */
    public function is_updating_content()
    {
        return $this->is_updating_content;
    }

    public function reorganize_media($post_id, $post, $update)
    {
        // Skip autosaves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Log the call stack to identify where this is being called from
        $backtrace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $call_stack = array_map(function ($trace) {
            return isset($trace['class'])
            ? $trace['class'] . '::' . $trace['function']
            : (isset($trace['file'])
                ? basename($trace['file']) . ':' . $trace['function']
                : $trace['function']);
        }, $backtrace);

        $this->logger->log("Media reorganization triggered for post {$post_id}. Call stack:", 'debug');
        $this->logger->log("  " . implode(" -> ", array_reverse($call_stack)), 'debug');
        $this->logger->log("  Action: " . (current_action() ?: 'unknown'), 'debug');
        $this->logger->log("  Is AJAX: " . (wp_doing_ajax() ? 'yes' : 'no'), 'debug');
        $this->logger->log("  Is Admin: " . (is_admin() ? 'yes' : 'no'), 'debug');
        $this->logger->log("  Current filter: " . (current_filter() ?: 'none'), 'debug');

        // Skip if not a valid post type
        $valid_types = array_keys($this->settings->get_valid_post_types());
        if (! in_array($post->post_type, $valid_types)) {
            $this->logger->log("Skipping media reorganization - post type '{$post->post_type}' not supported", 'debug');
            return;
        }

        // Prevent recursive processing
        if ($this->is_processing) {
            $this->logger->log("Skipping recursive media reorganization for post: '{$post->post_title}' (ID: $post_id)", 'debug');
            return;
        }

        $this->is_processing = true;

        try {
            $this->logger->log("Starting media reorganization for post: '{$post->post_title}' (ID: $post_id)", 'info');

            // Get all media files associated with this post
            $media_files = $this->get_post_media_files($post_id);
            $this->logger->log("Found " . count($media_files) . " media files associated with post '{$post->post_title}'", 'info');

            foreach ($media_files as $attachment_id => $file) {
                $attachment = get_post($attachment_id);
                $new_path   = $this->get_new_file_path($attachment_id, $post_id);
                if (! $new_path) {
                    $this->logger->log("Cannot generate new path for media file: '{$attachment->post_title}' (ID: $attachment_id)", 'info');
                    continue;
                }

                if ($new_path === $file) {
                    $this->logger->log("Media file already in correct location: '{$attachment->post_title}' at $file", 'info');
                } else {
                    $this->logger->log("Moving media file '{$attachment->post_title}' (ID: $attachment_id)", 'info');
                    $this->logger->log("  From: $file", 'info');
                    $this->logger->log("  To: $new_path", 'info');
                    $this->move_media_file($attachment_id, $file, $new_path);
                }
            }

            $this->logger->log("Completed media reorganization for post: '{$post->post_title}' (ID: $post_id)", 'info');
        } finally {
            $this->is_processing = false;
        }
    }

    /**
     * Get all media files associated with a post
     *
     * @param int $post_id The post ID
     * @return array Array of media file paths indexed by attachment ID
     */
    public function get_post_media_files($post_id)
    {
        $media_files = [];

        // Get attached media
        $attachments = get_attached_media('', $post_id);
        foreach ($attachments as $attachment) {
            $media_files[$attachment->ID] = get_attached_file($attachment->ID);
        }

        // Get featured image
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $media_files[$thumbnail_id] = get_attached_file($thumbnail_id);
        }

        // Get media from post content
        $post    = get_post($post_id);
        $content = $post->post_content;

        // Regular expression to find media URLs in content
        $pattern = '/<(?:img|video|audio|embed|object)[^>]*?src=[\'"](.*?)[\'"][^>]*?>/i';
        preg_match_all($pattern, $content, $matches);

        if (! empty($matches[1])) {
            foreach ($matches[1] as $url) {
                $attachment_id = attachment_url_to_postid($url);
                if ($attachment_id) {
                    $media_files[$attachment_id] = get_attached_file($attachment_id);
                } elseif ($this->is_remote_url($url) && $this->settings->get_setting('localize_remote_media') === '1') {
                    // Handle remote URL only if the setting is enabled
                    $this->logger->log("Found remote URL: $url", 'info');
                    $new_attachment_id = $this->import_remote_media($url, $post_id);
                    if ($new_attachment_id) {
                        $media_files[$new_attachment_id] = get_attached_file($new_attachment_id);
                        // Update post content with new URL
                        $this->update_content_url($post_id, $url, wp_get_attachment_url($new_attachment_id));
                    }
                }
            }
        }

        return $media_files;
    }

    /**
     * Check if a URL is truly remote (not just absolute)
     *
     * @param string $url The URL to check
     * @return bool True if the URL is remote, false otherwise
     */
    private function is_remote_url($url)
    {
        // Get the site URL and home URL
        $site_url   = trailingslashit(site_url());
        $home_url   = trailingslashit(home_url());
        $upload_url = trailingslashit(wp_upload_dir()['baseurl']);

        // Check if the URL starts with any of our local URLs
        $is_local = (
            strpos($url, $site_url) === 0 ||
            strpos($url, $home_url) === 0 ||
            strpos($url, $upload_url) === 0 ||
            strpos($url, '/') === 0// Relative URLs
        );

        return ! $is_local && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Import remote media into the WordPress media library
     *
     * @param string $url The remote URL
     * @param int $post_id The post ID to attach the media to
     * @return int|false The new attachment ID or false on failure
     */
    private function import_remote_media($url, $post_id)
    {
        $this->logger->log("Attempting to import remote media from URL: $url", 'info');

        // Get the file
        $temp_file = download_url($url);
        if (is_wp_error($temp_file)) {
            $this->logger->log("Failed to download remote media: " . $temp_file->get_error_message(), 'error');
            return false;
        }

        // Get the file name and type
        $file_name = basename(parse_url($url, PHP_URL_PATH));
        $file_type = wp_check_filetype($file_name);

        if (empty($file_type['type'])) {
            @unlink($temp_file);
            $this->logger->log("Could not determine file type for URL: $url", 'error');
            return false;
        }

        // Prepare the file array
        $file = [
            'name'     => $file_name,
            'type'     => $file_type['type'],
            'tmp_name' => $temp_file,
            'error'    => 0,
            'size'     => filesize($temp_file),
        ];

        // Move the temporary file into the uploads directory
        $attachment = media_handle_sideload($file, $post_id);

        // Clean up
        @unlink($temp_file);

        if (is_wp_error($attachment)) {
            $this->logger->log("Failed to import remote media: " . $attachment->get_error_message(), 'error');
            return false;
        }

        $this->logger->log("Successfully imported remote media. New attachment ID: $attachment", 'info');
        return $attachment;
    }

    /**
     * Update post content with new media URL
     *
     * @param int $post_id The post ID
     * @param string $old_url The old URL
     * @param string $new_url The new URL
     * @return bool True on success, false on failure
     */
    private function update_content_url($post_id, $old_url, $new_url)
    {
        $post = get_post($post_id);
        if (! $post) {
            return false;
        }

        $this->logger->log("Updating content URL in post $post_id", 'info');
        $this->logger->log("  From: $old_url", 'info');
        $this->logger->log("  To: $new_url", 'info');

        // Prevent recursive processing
        $this->is_updating_content = true;

        // Update the content
        $new_content = str_replace($old_url, $new_url, $post->post_content);

        if ($new_content !== $post->post_content) {
            $update_result = wp_update_post([
                'ID'           => $post_id,
                'post_content' => $new_content,
            ]);

            $this->is_updating_content = false;

            if (is_wp_error($update_result)) {
                $this->logger->log("Failed to update post content: " . $update_result->get_error_message(), 'error');
                return false;
            }

            $this->logger->log("Successfully updated post content with new URL", 'info');
            return true;
        }

        $this->is_updating_content = false;
        return false;
    }

    /**
     * Get the new file path for a media file
     *
     * @param int $attachment_id The attachment ID
     * @param int $post_id The post ID
     * @param WP_Post|null $temp_post Optional temporary post object for path generation
     * @param string $context The context of the path generation (e.g., 'move' or 'preview')
     * @return string|false The new file path or false if it cannot be generated
     */
    public function get_new_file_path($attachment_id, $post_id, $temp_post = null, $context = 'move')
    {
        $upload_dir = wp_upload_dir();
        $file_info  = pathinfo(get_attached_file($attachment_id));
        $post       = $temp_post ?: get_post($post_id);

        // Only log details for actual moves, not previews
        if ($context === 'move') {
            $this->logger->log("Post object details:", 'info');
            $this->logger->log("Post ID: " . $post->ID, 'info');
            $this->logger->log("Post title: " . $post->post_title, 'info');
            $this->logger->log("Post name: " . $post->post_name, 'info');
            $this->logger->log("Post status: " . $post->post_status, 'info');
            $this->logger->log("Post type: " . $post->post_type, 'info');
        }

        $path_parts = [];

        // Add post type if enabled
        if ($this->settings->get_setting('use_post_type') === '1' && $post && isset($post->post_type)) {
            // Check if it's a valid post type
            $valid_types = array_keys($this->settings->get_valid_post_types());
            if (in_array($post->post_type, $valid_types)) {
                $path_parts[] = $post->post_type;
            }
        }

        // Add taxonomy and term if set
        $taxonomy_name = $this->settings->get_setting('taxonomy_name');
        if ($taxonomy_name) {
            $terms = get_the_terms($post_id, $taxonomy_name);
            if ($terms && ! is_wp_error($terms) && ! empty($terms)) {
                $term = reset($terms);
                if (! empty($term->slug)) {
                    // Only add taxonomy components if we have a valid term
                    $path_parts[] = $taxonomy_name;
                    $path_parts[] = $term->slug;
                }
            }
        }

        // Add year/month structure only if WordPress setting is enabled
        if (get_option('uploads_use_yearmonth_folders')) {
            // Use the post's date for year/month structure
            $time         = strtotime($post->post_date);
            $path_parts[] = date('Y', $time);
            $path_parts[] = date('m', $time);
        }

        // Add post identifier if set
        $post_identifier = $this->settings->get_setting('post_identifier');
        if ($context === 'move') {
            $this->logger->log("Post identifier setting: " . $post_identifier, 'info');
        }

        if ($post_identifier === 'slug') {
            // Get the post slug, or generate one from title if it's empty (draft posts)
            $slug = $post->post_name;
            if (empty($slug)) {
                $slug = sanitize_title($post->post_title);
                if ($context === 'move') {
                    $this->logger->log("Generated temporary slug from title: " . $slug, 'info');
                }
            }
            if (! empty($slug)) {
                $path_parts[] = $slug;
                if ($context === 'move') {
                    $this->logger->log("Added slug to path parts: " . $slug, 'info');
                }
            }
        } elseif ($post_identifier === 'id') {
            $path_parts[] = $post_id;
            if ($context === 'move') {
                $this->logger->log("Added ID to path parts: " . $post_id, 'info');
            }
        }

        // Log the final path parts array only for actual moves
        if ($context === 'move') {
            $this->logger->log("Path parts array: " . print_r($path_parts, true), 'info');
        }

        // Combine parts
        $path       = implode('/', array_filter($path_parts));
        $target_dir = trailingslashit($upload_dir['basedir']) . $path;

        // Get original filename without numeric suffix if enabled
        $basename = $file_info['basename'];
        if ($this->should_handle_wp_suffixes) {
            $basename = $this->handle_filename_suffix($basename, $target_dir, $attachment_id);
        }

        $new_file = $target_dir . '/' . $basename;

        // Add debug logging for path comparison
        if ($context === 'move' || $context === 'preview') {
            $current_file = get_attached_file($attachment_id);
            $this->logger->log("Path comparison:", 'debug');
            $this->logger->log("Current file: " . $current_file, 'debug');
            $this->logger->log("New file: " . $new_file, 'debug');
            $this->logger->log("Upload base dir: " . $upload_dir['basedir'], 'debug');
            $this->logger->log("Path parts: " . implode('/', $path_parts), 'debug');
        }

        return $new_file;
    }

    private function move_media_file($attachment_id, $old_file, $new_file)
    {
        global $wpdb;

        $attachment = get_post($attachment_id);
        $this->logger->log("=== Starting media file move ===", 'info');
        $this->logger->log("Attachment details:", 'info');
        $this->logger->log("  ID: $attachment_id", 'info');
        $this->logger->log("  Title: {$attachment->post_title}", 'info');
        $this->logger->log("  Original filename: " . basename($old_file), 'info');
        $this->logger->log("  New filename: " . basename($new_file), 'info');

        // Check source and destination paths
        if (! file_exists($old_file)) {
            $this->logger->log("Source file does not exist at '$old_file', checking if already moved", 'info');
            if (file_exists($new_file)) {
                $this->logger->log("File already exists at destination '$new_file', assuming already moved", 'info');
                return true;
            }
            $this->logger->log("File not found at source or destination", 'error');
            return false;
        }

        // Check if the destination file already exists
        if (file_exists($new_file)) {
            if (filesize($old_file) === filesize($new_file) && md5_file($old_file) === md5_file($new_file)) {
                $this->logger->log("Identical file already exists at destination '$new_file', cleaning up source", 'info');
                @unlink($old_file);
                return true;
            }
            $this->logger->log("Different file exists at destination '$new_file', cannot proceed", 'error');
            return false;
        }

        // Log current metadata before move
        $this->logger->log("Current metadata state (before move):", 'info');
        $old_attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $this->logger->log("  _wp_attached_file (in wp_postmeta):", 'info');
        $this->logger->log("    Value: " . $old_attached_file, 'info');
        $this->logger->log("    Meta ID: " . $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = '_wp_attached_file'",
            $attachment_id
        )), 'info');

        $metadata = wp_get_attachment_metadata($attachment_id);
        $this->logger->log("  _wp_attachment_metadata (in wp_postmeta):", 'info');
        $this->logger->log("    Raw value: " . print_r($metadata, true), 'info');
        if (is_array($metadata)) {
            $this->logger->log("    Main file path: " . (isset($metadata['file']) ? $metadata['file'] : 'not set'), 'info');
            if (isset($metadata['sizes'])) {
                $this->logger->log("    Size variants:", 'info');
                foreach ($metadata['sizes'] as $size => $info) {
                    $this->logger->log("      $size: {$info['file']} ({$info['width']}x{$info['height']})", 'info');
                }
            }
        }

        // Create the directory if it doesn't exist
        $new_dir = dirname($new_file);
        if (! file_exists($new_dir)) {
            wp_mkdir_p($new_dir);
        }

        // Move the file
        if (@rename($old_file, $new_file)) {
            $this->logger->log("Successfully moved file from '$old_file' to '$new_file'", 'info');

            // Ensure original file is deleted if rename resulted in a copy
            if (file_exists($old_file)) {
                $this->logger->log("Original file still exists after move, attempting to delete", 'info');
                if (@unlink($old_file)) {
                    $this->logger->log("Successfully deleted original file at '$old_file'", 'info');
                } else {
                    $this->logger->log("Failed to delete original file at '$old_file'", 'warning');
                }
            }

            // Update _wp_attached_file
            $new_attached_file = str_replace(wp_upload_dir()['basedir'] . '/', '', $new_file);
            $this->logger->log("Updating _wp_attached_file meta:", 'info');
            $this->logger->log("  From: $old_attached_file", 'info');
            $this->logger->log("  To: $new_attached_file", 'info');
            update_attached_file($attachment_id, $new_file);

            // Update _wp_attachment_metadata
            $metadata = wp_get_attachment_metadata($attachment_id);
            if (! is_array($metadata)) {
                $metadata = [];
                $this->logger->log("Creating new metadata array as none existed", 'info');
            }

            // Update the main file path in metadata
            $old_file_in_meta = isset($metadata['file']) ? $metadata['file'] : 'not set';
            $metadata['file'] = $new_attached_file;
            $this->logger->log("Updating main file path in _wp_attachment_metadata:", 'info');
            $this->logger->log("  From: $old_file_in_meta", 'info');
            $this->logger->log("  To: $new_attached_file", 'info');

            // Store old directory for cleanup after all moves are complete
            $old_dir = dirname($old_file);

            // Handle size variants
            if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                $this->logger->log("Moving and updating size variants:", 'info');

                // Check for and move original (pre-scaled) image if it exists
                if (isset($metadata['original_image'])) {
                    $old_original_path = trailingslashit($old_dir) . $metadata['original_image'];
                    $new_original_path = trailingslashit($new_dir) . $metadata['original_image'];

                    $this->logger->log("Original (pre-scaled) image found:", 'info');
                    $this->logger->log("  Moving from: $old_original_path", 'info');
                    $this->logger->log("  Moving to: $new_original_path", 'info');

                    if (file_exists($old_original_path)) {
                        if (@rename($old_original_path, $new_original_path)) {
                            $this->logger->log("  ✓ Successfully moved original image", 'info');
                        } else {
                            $this->logger->log("  ✗ Failed to move original image", 'error');
                        }
                    } else {
                        $this->logger->log("  ! Original image file not found at source", 'warning');
                    }
                }

                // Track which files we've already moved to handle duplicates
                $processed_files = [];

                foreach ($metadata['sizes'] as $size => $size_info) {
                    $this->logger->log("  Size variant '$size':", 'info');

                    // Skip if this exact file has already been processed
                    if (isset($processed_files[$size_info['file']])) {
                        $this->logger->log("    ↳ Skipping duplicate size variant (already processed with '{$processed_files[$size_info['file']]}')", 'info');
                        continue;
                    }

                    $old_size_path = trailingslashit($old_dir) . $size_info['file'];
                    $new_size_path = trailingslashit($new_dir) . $size_info['file'];

                    $this->logger->log("    Moving from: $old_size_path", 'info');
                    $this->logger->log("    Moving to: $new_size_path", 'info');

                    if (! file_exists($old_size_path)) {
                        $this->logger->log("    ↳ Size variant file not found at source (may be a duplicate reference)", 'info');
                        continue;
                    }

                    if (@rename($old_size_path, $new_size_path)) {
                        $this->logger->log("    ✓ Successfully moved size variant", 'info');
                        $processed_files[$size_info['file']] = $size; // Track that we've processed this file
                    } else {
                        $this->logger->log("    ✗ Failed to move size variant", 'error');
                    }
                }
            }

            // Save the updated metadata
            $this->logger->log("Saving updated _wp_attachment_metadata:", 'info');
            $this->logger->log("  New value: " . print_r($metadata, true), 'info');
            wp_update_attachment_metadata($attachment_id, $metadata);

            // Update URLs in post content
            $this->update_post_content_urls($attachment_id, $old_file, $new_file);

            // Log final metadata state
            $this->logger->log("Final metadata state (after move):", 'info');
            $this->logger->log("  _wp_attached_file: " . get_post_meta($attachment_id, '_wp_attached_file', true), 'info');
            $final_metadata = wp_get_attachment_metadata($attachment_id);
            $this->logger->log("  _wp_attachment_metadata:", 'info');
            $this->logger->log("    Raw value: " . print_r($final_metadata, true), 'info');
            if (is_array($final_metadata)) {
                $this->logger->log("    Main file path: " . (isset($final_metadata['file']) ? $final_metadata['file'] : 'not set'), 'info');
                if (isset($final_metadata['sizes'])) {
                    $this->logger->log("    Size variants:", 'info');
                    foreach ($final_metadata['sizes'] as $size => $info) {
                        $this->logger->log("      $size: {$info['file']} ({$info['width']}x{$info['height']})", 'info');
                    }
                }
            }

            // Clear image caches
            $this->logger->log("Clearing attachment image caches", 'info');
            clean_attachment_cache($attachment_id);

            // Now that all files have been moved, check and clean up the old directory
            $this->logger->log("Checking old directory for cleanup: $old_dir", 'debug');
            $this->cleanup_empty_directory($old_dir);

            return true;
        } else {
            $this->logger->log("Failed to move file from '$old_file' to '$new_file'", 'error');
            return false;
        }
    }

    /**
     * Check if a directory is empty and delete it if it is, then recursively check parent directories
     *
     * @param string $dir_path Path to the directory to check and potentially delete
     * @return bool True if directory was deleted, false otherwise
     */
    public function cleanup_empty_directory($dir_path)
    {
        // Don't delete the uploads base directory
        $upload_dir = wp_upload_dir();
        $base_dir   = rtrim($upload_dir['basedir'], '/');

        // Normalize the paths for comparison
        $dir_path = rtrim($dir_path, '/');

        $this->logger->log("Attempting to clean up directory: $dir_path", 'debug');

        if ($dir_path === $base_dir) {
            $this->logger->log("Skipping cleanup of uploads base directory", 'debug');
            return false;
        }

        // Check if directory exists and is readable
        if (! is_dir($dir_path)) {
            $this->logger->log("Directory does not exist: $dir_path", 'debug');
            return false;
        }
        if (! is_readable($dir_path)) {
            $this->logger->log("Directory is not readable: $dir_path", 'debug');
            return false;
        }

        // Get all files in directory
        $files = array_diff(scandir($dir_path), ['.', '..']);
        $this->logger->log("Directory contents (" . count($files) . " items): " . print_r($files, true), 'debug');

        // If directory is empty
        if (empty($files)) {
            $this->logger->log("Found empty directory: $dir_path", 'info');

            // Check if directory is writable
            if (! is_writable($dir_path)) {
                $this->logger->log("Directory is not writable: $dir_path", 'error');
                return false;
            }

            if (@rmdir($dir_path)) {
                $this->logger->log("Successfully deleted empty directory: $dir_path", 'info');

                // After successful deletion, check the parent directory
                $parent_dir = dirname($dir_path);
                if ($parent_dir !== $base_dir) {
                    $this->logger->log("Checking parent directory: $parent_dir", 'debug');
                    $this->cleanup_empty_directory($parent_dir);
                }

                return true;
            } else {
                $error = error_get_last();
                $this->logger->log("Failed to delete empty directory: $dir_path. Error: " . ($error ? $error['message'] : 'Unknown error'), 'error');
                return false;
            }
        } else {
            $this->logger->log("Directory is not empty: $dir_path", 'debug');
        }

        return false;
    }

    private function update_post_content_urls($attachment_id, $old_file, $new_file)
    {
        global $wpdb;

        $attachment = get_post($attachment_id);
        $post       = get_post($attachment->post_parent);

        if (! $post) {
            $this->logger->log("No parent post found for attachment ID: $attachment_id", 'info');
            return;
        }

        // Get the old and new URLs
        $upload_dir = wp_upload_dir();
        $old_url    = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $old_file);
        $new_url    = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $new_file);

        // Skip if URLs are the same
        if ($old_url === $new_url) {
            $this->logger->log("URLs are identical, no content update needed", 'info');
            return;
        }

        // Create URL variations with format preservation
        $old_url_variations = [];
        $new_url_variations = [];

        // 1. Full URLs (both http and https)
        $old_url_variations['full_http']  = str_replace('https://', 'http://', $old_url);
        $old_url_variations['full_https'] = str_replace('http://', 'https://', $old_url);
        $new_url_variations['full_http']  = str_replace('https://', 'http://', $new_url);
        $new_url_variations['full_https'] = str_replace('http://', 'https://', $new_url);

        // 2. Relative to domain root
        $old_url_variations['relative_root'] = str_replace($upload_dir['baseurl'], '', $old_url);
        $new_url_variations['relative_root'] = str_replace($upload_dir['baseurl'], '', $new_url);

        // 3. WordPress relative URLs
        $old_url_variations['wp_relative'] = wp_make_link_relative($old_url);
        $new_url_variations['wp_relative'] = wp_make_link_relative($new_url);

        // 4. HTML entities
        $old_url_variations['entities'] = htmlentities($old_url);
        $new_url_variations['entities'] = htmlentities($new_url);

        // Update post content if it contains any of the old URLs
        if ($post->post_content) {
            $updated_content = $post->post_content;
            $content_updated = false;

            // First, detect which URL format is being used in the content
            foreach ($old_url_variations as $format => $old_variation) {
                if (strpos($updated_content, $old_variation) !== false) {
                    // Replace with the corresponding format
                    $updated_content = str_replace($old_variation, $new_url_variations[$format], $updated_content);
                    $content_updated = true;
                    $this->logger->log("Found and replaced URL in format: $format", 'info');
                }
            }

            if ($content_updated) {
                $this->logger->log("Content was updated. Saving changes to post '{$post->post_title}' (ID: {$post->ID})", 'info');
                $this->logger->log("Original content contained: " . $post->post_content, 'info');
                $this->logger->log("Updated content contains: " . $updated_content, 'info');

                // Set flag to prevent recursive save_post triggers
                $this->is_updating_content = true;
                wp_update_post([
                    'ID'           => $post->ID,
                    'post_content' => $updated_content,
                ]);
                $this->is_updating_content = false;
            } else {
                $this->logger->log("No changes were made to post content", 'info');
            }
        }

        // Also update any serialized metadata that might contain the URL
        $meta_conditions = [];
        $meta_params     = [];
        foreach ($old_url_variations as $old_variation) {
            if (! empty($old_variation)) {
                $meta_conditions[] = "meta_value LIKE %s";
                $meta_params[]     = '%' . $wpdb->esc_like($old_variation) . '%';
            }
        }

        if (! empty($meta_conditions)) {
            $meta_query = $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value
                FROM {$wpdb->postmeta}
                WHERE " . implode(" OR ", $meta_conditions),
                $meta_params
            );

            $meta_rows = $wpdb->get_results($meta_query);
            if (! empty($meta_rows)) {
                $this->logger->log("Found " . count($meta_rows) . " meta entries containing URLs for '{$attachment->post_title}'", 'info');

                foreach ($meta_rows as $meta) {
                    $updated_value = $meta->meta_value;

                    foreach ($old_url_variations as $format => $old_variation) {
                        if (! empty($old_variation)) {
                            $new_variation = $new_url_variations[$format];
                            $updated_value = $this->update_serialized_url($updated_value, $old_variation, $new_variation);
                        }
                    }

                    if ($updated_value !== $meta->meta_value) {
                        update_post_meta($meta->post_id, $meta->meta_key, $updated_value);
                    }
                }
            }
        }
    }

    private function update_serialized_url($data, $old_url, $new_url)
    {
        if (is_serialized($data)) {
            $unserialized = unserialize($data);
            $updated      = $this->replace_urls_recursive($unserialized, $old_url, $new_url);
            return serialize($updated);
        }
        return str_replace($old_url, $new_url, $data);
    }

    private function replace_urls_recursive($data, $old_url, $new_url)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->replace_urls_recursive($value, $old_url, $new_url);
            }
        } elseif (is_string($data)) {
            $data = str_replace($old_url, $new_url, $data);
        }
        return $data;
    }

    /**
     * Bulk reorganize media for multiple posts
     *
     * @param array $post_ids Array of post IDs to process
     * @return array Results of the operation
     */
    public function bulk_reorganize_media($post_ids)
    {
        $results = [
            'success'           => 0,
            'skipped'           => 0,
            'failed'            => 0,
            'already_organized' => 0,
            'messages'          => [],
            'post_messages'     => [],
        ];

        if (empty($post_ids)) {
            $results['messages'][] = "No posts selected for media reorganization.";
            return $results;
        }

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (! $post) {
                $results['failed']++;
                $results['messages'][] = "Post ID $post_id not found.";
                continue;
            }

            try {
                $media_files  = $this->get_post_media_files($post_id);
                $files_moved  = false;
                $post_message = [
                    'title' => sprintf('Post ID %d: "%s" (%d media items)',
                        $post_id,
                        $post->post_title,
                        count($media_files)
                    ),
                    'items' => [],
                ];

                $this->logger->log("Starting media reorganization for post: '{$post->post_title}' (ID: $post_id)", 'info');
                $this->logger->log("Found " . count($media_files) . " media files associated with post '{$post->post_title}'", 'info');

                foreach ($media_files as $attachment_id => $file) {
                    $attachment = get_post($attachment_id);
                    $new_path   = $this->get_new_file_path($attachment_id, $post_id, $post);

                    if (! $new_path) {
                        $results['skipped']++;
                        $post_message['items'][] = sprintf(
                            'Media ID %d ("%s"): Cannot generate new path',
                            $attachment_id,
                            $attachment->post_title
                        );
                        continue;
                    }

                    // Normalize paths for comparison
                    $normalized_new_path = str_replace('\\', '/', $new_path);
                    $normalized_file     = str_replace('\\', '/', $file);

                    if (strtolower($normalized_new_path) === strtolower($normalized_file)) {
                        $results['already_organized']++;
                        $post_message['items'][] = sprintf(
                            'Media ID %d ("%s"): Already in correct location: <code>%s</code>',
                            $attachment_id,
                            $attachment->post_title,
                            esc_html($file)
                        );
                        $this->logger->log("Media file already in correct location: '{$attachment->post_title}' at $file", 'info');
                    } else {
                        $this->logger->log("Moving media file '{$attachment->post_title}' (ID: $attachment_id)", 'info');
                        $this->logger->log("  From: $file", 'info');
                        $this->logger->log("  To: $new_path", 'info');

                        if ($this->move_media_file($attachment_id, $file, $new_path)) {
                            $files_moved = true;
                            $results['success']++;
                            $post_message['items'][] = sprintf(
                                'Media ID %d ("%s"): Moved from <del><code>%s</code></del> to <code>%s</code>',
                                $attachment_id,
                                $attachment->post_title,
                                esc_html($file),
                                esc_html($new_path)
                            );
                        } else {
                            $results['failed']++;
                            $post_message['items'][] = sprintf(
                                'Media ID %d ("%s"): Failed to move from <code>%s</code> to <code>%s</code>',
                                $attachment_id,
                                $attachment->post_title,
                                esc_html($file),
                                esc_html($new_path)
                            );
                        }
                    }
                }

                $this->logger->log("Completed media reorganization for post: '{$post->post_title}' (ID: $post_id)", 'info');
                $results['post_messages'][] = $post_message;

            } catch (Exception $e) {
                $results['failed']++;
                $post_message = [
                    'title' => sprintf('Post ID %d: "%s"', $post_id, $post->post_title),
                    'items' => [sprintf('Error: %s', $e->getMessage())],
                ];
                $results['post_messages'][] = $post_message;
            }
        }

        return $results;
    }

    /**
     * Preview media reorganization without actually moving files
     *
     * @param int $post_id The post ID to preview reorganization for
     * @return array Preview data including current and preferred paths
     */
    public function preview_media_reorganization($post_id)
    {
        $media_files = $this->get_post_media_files($post_id);
        if (empty($media_files)) {
            return [];
        }

        $post         = get_post($post_id);
        $preview_data = [];

        foreach ($media_files as $attachment_id => $current_path) {
            $preferred_path = $this->get_new_file_path($attachment_id, $post_id, null, 'preview');

            // Normalize paths for comparison
            $normalized_current   = $this->normalize_path($current_path);
            $normalized_preferred = $this->normalize_path($preferred_path);

            // Extract path components
            $path_components = [];

            // Add post type if enabled
            if ($this->settings->get_setting('use_post_type') === '1' && $post && isset($post->post_type)) {
                $valid_types = array_keys($this->settings->get_valid_post_types());
                if (in_array($post->post_type, $valid_types)) {
                    $path_components['post_type'] = $post->post_type;
                }
            }

            // Add taxonomy and term if enabled
            $taxonomy_name = $this->settings->get_setting('taxonomy_name');
            if (! empty($taxonomy_name)) {
                $terms = wp_get_post_terms($post_id, $taxonomy_name);
                if (! empty($terms) && ! is_wp_error($terms)) {
                    $path_components['taxonomy'] = $taxonomy_name;
                    $path_components['term']     = $terms[0]->slug;
                }
            }

            // Add year/month
            $post_date = get_post_time('Y-m', false, $post_id);
            if ($post_date) {
                list($year, $month)       = explode('-', $post_date);
                $path_components['year']  = $year;
                $path_components['month'] = $month;
            }

            // Add post identifier (slug or ID)
            $post_identifier = $this->settings->get_setting('post_identifier');
            if ($post_identifier === 'slug') {
                $path_components['post_id'] = $post->post_name ?: sanitize_title($post->post_title);
            } elseif ($post_identifier === 'id') {
                $path_components['post_id'] = $post_id;
            }

            // Get filename
            $path_components['filename'] = basename($preferred_path);

            $preview_data[] = array_merge([
                'id'             => $attachment_id,
                'current_path'   => $current_path,
                'preferred_path' => $preferred_path,
                'status'         => $normalized_current === $normalized_preferred ? 'correct' : 'will_move',
            ], $path_components);
        }

        return $preview_data;
    }

    /**
     * Normalize a file path for consistent comparison
     *
     * @param string $path The path to normalize
     * @param bool $convert_case Whether to convert to lowercase (default: true)
     * @return string The normalized path
     */
    private function normalize_path($path, $convert_case = true)
    {
        // Convert backslashes to forward slashes
        $path = str_replace('\\', '/', $path);

        // Convert to lowercase for case-insensitive comparison if requested
        if ($convert_case) {
            $path = strtolower($path);
        }

        return $path;
    }

    /**
     * Handle WordPress numeric suffix in filenames, potentially removing them if no conflict exists
     *
     * @param string $basename The original filename with potential numeric suffix
     * @param string $target_dir The directory where the file will be placed
     * @param int $attachment_id The ID of the attachment being processed
     * @return string The filename to use, either with or without numeric suffix
     */
    private function handle_filename_suffix($basename, $target_dir, $attachment_id)
    {
        if (preg_match('/^(.+)-\d+(\.[^.]+)$/', $basename, $matches)) {
            $original_name  = $matches[1] . $matches[2];
            $potential_path = $target_dir . '/' . $original_name;

            // Check if we can use the original filename (no conflict)
            if (! file_exists($potential_path) || realpath($potential_path) === realpath(get_attached_file($attachment_id))) {
                return $original_name;
            }
        }
        return $basename;
    }
}
