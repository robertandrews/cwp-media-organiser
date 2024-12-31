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

        $this->logger->log("Starting media reorganization for post ID: $post_id", 'info');

        // Get all media files associated with this post
        $media_files = $this->get_post_media_files($post_id);
        $this->logger->log("Found " . count($media_files) . " media files to process", 'info');

        foreach ($media_files as $attachment_id => $file) {
            $new_path = $this->get_new_file_path($attachment_id, $post_id);
            if (!$new_path) {
                $this->logger->log("Skipping file (no new path generated): $file", 'info');
                continue;
            }

            if ($new_path === $file) {
                $this->logger->log("File already in correct location (no move needed): $file", 'info');
            } else {
                $this->logger->log("Moving file from: $file", 'info');
                $this->logger->log("Moving file to: $new_path", 'info');
                $this->move_media_file($attachment_id, $file, $new_path);
            }
        }

        $this->logger->log("Completed media reorganization for post ID: $post_id", 'info');
    }

    private function get_post_media_files($post_id)
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

    private function get_new_file_path($attachment_id, $post_id)
    {
        $upload_dir = wp_upload_dir();
        $file_info = pathinfo(get_attached_file($attachment_id));
        $post = get_post($post_id);

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
            $time = strtotime($post->post_date);
            $path_parts[] = date('Y', $time);
            $path_parts[] = date('m', $time);
        }

        // Add post identifier if set
        $post_identifier = $this->settings->get_setting('post_identifier');
        if ($post_identifier === 'slug') {
            $path_parts[] = $post->post_name;
        } elseif ($post_identifier === 'id') {
            $path_parts[] = $post_id;
        }

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

        // Get the old URL before the move
        $upload_dir = wp_upload_dir();
        $old_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $old_file);
        $new_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $new_file);

        if ($old_url === $new_url) {
            $this->logger->log("URLs are identical, no content updates needed", 'debug');
            return;
        }

        $this->logger->log("Updating URLs in post content from: $old_url to: $new_url", 'info');

        // Also handle URLs without scheme (//example.com/...)
        $old_url_no_scheme = preg_replace('/^https?:/', '', $old_url);
        $new_url_no_scheme = preg_replace('/^https?:/', '', $new_url);

        // Also handle relative URLs (/wp-content/...)
        $old_url_relative = wp_make_link_relative($old_url);
        $new_url_relative = wp_make_link_relative($new_url);

        // Get all posts that might contain any version of the old URL
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                WHERE post_content LIKE %s
                OR post_content LIKE %s
                OR post_content LIKE %s",
                '%' . $wpdb->esc_like($old_url) . '%',
                '%' . $wpdb->esc_like($old_url_no_scheme) . '%',
                '%' . $wpdb->esc_like($old_url_relative) . '%'
            )
        );

        foreach ($posts as $post) {
            $updated_content = str_replace(
                array($old_url, $old_url_no_scheme, $old_url_relative),
                array($new_url, $new_url_no_scheme, $new_url_relative),
                $post->post_content
            );

            if ($updated_content !== $post->post_content) {
                $this->logger->log("Updating URLs in post ID: {$post->ID}", 'info');

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
        foreach ($meta_rows as $meta) {
            $updated_value = $this->update_serialized_url($meta->meta_value, $old_url, $new_url);
            $updated_value = $this->update_serialized_url($updated_value, $old_url_no_scheme, $new_url_no_scheme);
            $updated_value = $this->update_serialized_url($updated_value, $old_url_relative, $new_url_relative);

            if ($updated_value !== $meta->meta_value) {
                $this->logger->log("Updating URL in post meta - Post ID: {$meta->post_id}, Meta key: {$meta->meta_key}", 'info');
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
}
