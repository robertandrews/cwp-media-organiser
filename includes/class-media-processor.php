<?php
/**
 * Media processing functionality
 */

if (!defined('WPINC')) {
    die;
}

class WP_Media_Organiser_Processor
{
    private $logger;
    private $settings;
    private $is_processing = false;

    public function __construct($settings)
    {
        $this->logger = WP_Media_Organiser_Logger::get_instance();
        $this->settings = $settings;
    }

    public function reorganize_media($post_id, $post, $update)
    {
        // Skip autosaves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Skip if not a valid post type
        $valid_types = array_keys($this->settings->get_valid_post_types());
        if (!in_array($post->post_type, $valid_types)) {
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
                $new_path = $this->get_new_file_path($attachment_id, $post_id);
                if (!$new_path) {
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
        $media_files = array();

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
        $post = get_post($post_id);
        $content = $post->post_content;

        // Regular expression to find media URLs in content
        $pattern = '/<(?:img|video|audio|embed|object)[^>]*?src=[\'"](.*?)[\'"][^>]*?>/i';
        preg_match_all($pattern, $content, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $url) {
                $attachment_id = attachment_url_to_postid($url);
                if ($attachment_id) {
                    $media_files[$attachment_id] = get_attached_file($attachment_id);
                }
            }
        }

        return $media_files;
    }

    /**
     * Get the new file path for a media file
     *
     * @param int $attachment_id The attachment ID
     * @param int $post_id The post ID
     * @param WP_Post|null $temp_post Optional temporary post object for path generation
     * @return string|false The new file path or false if it cannot be generated
     */
    public function get_new_file_path($attachment_id, $post_id, $temp_post = null)
    {
        $upload_dir = wp_upload_dir();
        $file_info = pathinfo(get_attached_file($attachment_id));
        $post = $temp_post ?: get_post($post_id);

        // Debug post object
        $this->logger->log("Post object details:", 'info');
        $this->logger->log("Post ID: " . $post->ID, 'info');
        $this->logger->log("Post title: " . $post->post_title, 'info');
        $this->logger->log("Post name: " . $post->post_name, 'info');
        $this->logger->log("Post status: " . $post->post_status, 'info');
        $this->logger->log("Post type: " . $post->post_type, 'info');

        $path_parts = array();

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
            if ($terms && !is_wp_error($terms)) {
                $term = reset($terms);
                $path_parts[] = $taxonomy_name;
                $path_parts[] = $term->slug;
            }
        }

        // Add year/month structure only if WordPress setting is enabled
        if (get_option('uploads_use_yearmonth_folders')) {
            // Get the attachment's date, not the post's date
            $attachment = get_post($attachment_id);
            $time = strtotime($attachment->post_date);
            $path_parts[] = date('Y', $time);
            $path_parts[] = date('m', $time);
        }

        // Add post identifier if set (moved after date structure)
        $post_identifier = $this->settings->get_setting('post_identifier');
        $this->logger->log("Post identifier setting: " . $post_identifier, 'info');

        if ($post_identifier === 'slug') {
            // Get the post slug, or generate one from title if it's empty (draft posts)
            $slug = $post->post_name;
            if (empty($slug)) {
                $slug = sanitize_title($post->post_title);
                $this->logger->log("Generated temporary slug from title: " . $slug, 'info');
            }
            if (!empty($slug)) {
                $path_parts[] = $slug;
                $this->logger->log("Added slug to path parts: " . $slug, 'info');
            }
        } elseif ($post_identifier === 'id') {
            $path_parts[] = $post_id;
            $this->logger->log("Added ID to path parts: " . $post_id, 'info');
        }

        // Log the final path parts array
        $this->logger->log("Path parts array: " . print_r($path_parts, true), 'info');

        // Combine parts and add filename
        $path = implode('/', array_filter($path_parts));
        return trailingslashit($upload_dir['basedir']) . $path . '/' . $file_info['basename'];
    }

    private function move_media_file($attachment_id, $old_file, $new_file)
    {
        // Create the directory if it doesn't exist
        $new_dir = dirname($new_file);
        if (!file_exists($new_dir)) {
            wp_mkdir_p($new_dir);
        }

        // Move the file
        if (@rename($old_file, $new_file)) {
            // Update attachment metadata
            update_attached_file($attachment_id, $new_file);

            // Update metadata sizes
            $metadata = wp_get_attachment_metadata($attachment_id);
            if (is_array($metadata) && isset($metadata['sizes'])) {
                $old_dir = dirname($old_file);
                $new_dir = dirname($new_file);

                foreach ($metadata['sizes'] as $size => $sizeinfo) {
                    $old_size_path = $old_dir . '/' . $sizeinfo['file'];
                    $new_size_path = $new_dir . '/' . $sizeinfo['file'];

                    if (file_exists($old_size_path)) {
                        @rename($old_size_path, $new_size_path);
                    }
                }
            }

            // Update attachment metadata
            wp_update_attachment_metadata($attachment_id, $metadata);

            // Update URLs in post content
            $this->update_post_content_urls($attachment_id, $old_file, $new_file);
        }
    }

    private function update_post_content_urls($attachment_id, $old_file, $new_file)
    {
        global $wpdb;

        $attachment = get_post($attachment_id);
        $upload_dir = wp_upload_dir();
        $old_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $old_file);
        $new_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $new_file);

        if ($old_url === $new_url) {
            $this->logger->log("No URL update needed for '{$attachment->post_title}' - URLs are identical", 'debug');
            return;
        }

        $this->logger->log("Updating media library database record for: '{$attachment->post_title}' (ID: $attachment_id)", 'info');
        $this->logger->log("  From: $old_url", 'info');
        $this->logger->log("  To: $new_url", 'info');

        // Also handle URLs without scheme (//example.com/...)
        $old_url_no_scheme = preg_replace('/^https?:/', '', $old_url);
        $new_url_no_scheme = preg_replace('/^https?:/', '', $new_url);

        // Also handle relative URLs (/wp-content/...)
        $old_url_relative = wp_make_link_relative($old_url);
        $new_url_relative = wp_make_link_relative($new_url);

        // Get all posts that might contain any version of the old URL
        $valid_types = array_keys($this->settings->get_valid_post_types());
        $valid_types_list = "'" . implode("','", array_map('esc_sql', $valid_types)) . "'";

        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content, post_title, post_type FROM {$wpdb->posts}
                WHERE post_type IN ($valid_types_list)
                AND (
                    post_content LIKE %s
                    OR post_content LIKE %s
                    OR post_content LIKE %s
                )",
                '%' . $wpdb->esc_like($old_url) . '%',
                '%' . $wpdb->esc_like($old_url_no_scheme) . '%',
                '%' . $wpdb->esc_like($old_url_relative) . '%'
            )
        );

        if (empty($posts)) {
            $this->logger->log("No posts found containing URLs for '{$attachment->post_title}'", 'info');
        } else {
            $this->logger->log("Found " . count($posts) . " posts containing URLs for '{$attachment->post_title}'", 'info');
        }

        foreach ($posts as $post) {
            $updated_content = str_replace(
                array($old_url, $old_url_no_scheme, $old_url_relative),
                array($new_url, $new_url_no_scheme, $new_url_relative),
                $post->post_content
            );

            if ($updated_content !== $post->post_content) {
                $this->logger->log("Updating image URL in post content for: '{$post->post_title}' (ID: {$post->ID})", 'info');
                $this->logger->log("  From: $old_url", 'info');
                $this->logger->log("  To: $new_url", 'info');

                wp_update_post(array(
                    'ID' => $post->ID,
                    'post_content' => $updated_content,
                ));
            }
        }

        // Also update any serialized metadata that might contain the URL
        $meta_query = $wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_value LIKE %s
            OR meta_value LIKE %s
            OR meta_value LIKE %s",
            '%' . $wpdb->esc_like($old_url) . '%',
            '%' . $wpdb->esc_like($old_url_no_scheme) . '%',
            '%' . $wpdb->esc_like($old_url_relative) . '%'
        );

        $meta_rows = $wpdb->get_results($meta_query);
        if (!empty($meta_rows)) {
            $this->logger->log("Found " . count($meta_rows) . " meta entries containing URLs for '{$attachment->post_title}'", 'info');
        }

        foreach ($meta_rows as $meta) {
            $post = get_post($meta->post_id);
            $updated_value = $this->update_serialized_url($meta->meta_value, $old_url, $new_url);
            $updated_value = $this->update_serialized_url($updated_value, $old_url_no_scheme, $new_url_no_scheme);
            $updated_value = $this->update_serialized_url($updated_value, $old_url_relative, $new_url_relative);

            if ($updated_value !== $meta->meta_value) {
                $this->logger->log("Updating URL in meta field '{$meta->meta_key}' for post: '{$post->post_title}' (ID: {$meta->post_id})", 'info');
                update_post_meta($meta->post_id, $meta->meta_key, $updated_value);
            }
        }
    }

    private function update_serialized_url($data, $old_url, $new_url)
    {
        if (is_serialized($data)) {
            $unserialized = unserialize($data);
            $updated = $this->replace_urls_recursive($unserialized, $old_url, $new_url);
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
        $results = array(
            'success' => 0,
            'skipped' => 0,
            'failed' => 0,
            'already_organized' => 0,
            'messages' => array(),
            'post_messages' => array(),
        );

        if (empty($post_ids)) {
            $results['messages'][] = "No posts selected for media reorganization.";
            return $results;
        }

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                $results['failed']++;
                $results['messages'][] = "Post ID $post_id not found.";
                continue;
            }

            try {
                $media_files = $this->get_post_media_files($post_id);
                $files_moved = false;
                $post_message = array(
                    'title' => sprintf('Post ID %d: "%s" (%d media items)',
                        $post_id,
                        $post->post_title,
                        count($media_files)
                    ),
                    'items' => array(),
                );

                foreach ($media_files as $attachment_id => $file) {
                    $attachment = get_post($attachment_id);
                    $new_path = $this->get_new_file_path($attachment_id, $post_id, $post);

                    if (!$new_path) {
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
                    $normalized_file = str_replace('\\', '/', $file);

                    if (strtolower($normalized_new_path) === strtolower($normalized_file)) {
                        $results['already_organized']++;
                        $post_message['items'][] = sprintf(
                            'Media ID %d ("%s"): Already in correct location: <code>%s</code>',
                            $attachment_id,
                            $attachment->post_title,
                            esc_html($file)
                        );
                    } else {
                        $this->move_media_file($attachment_id, $file, $new_path);
                        $files_moved = true;
                        $results['success']++;
                        $post_message['items'][] = sprintf(
                            'Media ID %d ("%s"): Moved from <del><code>%s</code></del> to <code>%s</code>',
                            $attachment_id,
                            $attachment->post_title,
                            esc_html($file),
                            esc_html($new_path)
                        );
                    }
                }

                if (count($media_files) === 0) {
                    $post_message['items'][] = "No media files found";
                }

                $results['post_messages'][] = $post_message;

            } catch (Exception $e) {
                $results['failed']++;
                $post_message = array(
                    'title' => sprintf('Post ID %d: "%s"', $post_id, $post->post_title),
                    'items' => array(sprintf('Error: %s', $e->getMessage())),
                );
                $results['post_messages'][] = $post_message;
            }
        }

        return $results;
    }
}
