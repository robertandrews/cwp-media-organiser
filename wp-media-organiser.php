<?php
/**
 * Plugin Name: WP Media Organiser
 * Plugin URI:
 * Description: Custom control over where media files are stored in WordPress
 * Version: 1.0.0
 * Author:
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-media-organiser
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class WP_Media_Organiser_Logger
{
    private $log_file;
    private static $instance = null;

    private function __construct()
    {
        // Get plugin directory path
        $plugin_dir = plugin_dir_path(__FILE__);
        $this->log_file = $plugin_dir . 'wp-media-organiser.log';

        // Ensure the log file is writable
        if (!file_exists($this->log_file)) {
            touch($this->log_file);
        }

        if (!is_writable($this->log_file)) {
            // Try to make it writable
            chmod($this->log_file, 0666);
        }
    }

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function log($message, $type = 'info')
    {
        if (!is_string($message)) {
            $message = print_r($message, true);
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $formatted_message = sprintf("[%s] [%s] %s\n", $timestamp, strtoupper($type), $message);

        if (is_writable($this->log_file)) {
            error_log($formatted_message, 3, $this->log_file);
        }
    }

    public function clear_log()
    {
        if (file_exists($this->log_file) && is_writable($this->log_file)) {
            unlink($this->log_file);
            touch($this->log_file);
        }
    }

    public function get_log_path()
    {
        return $this->log_file;
    }
}

class WP_Media_Organiser
{
    private static $instance = null;
    private $settings_table;
    private $plugin_path;
    private $plugin_url;
    private $logger;

    private function __construct()
    {
        global $wpdb;
        $this->settings_table = $wpdb->prefix . 'media_organiser_settings';
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url = plugin_dir_url(__FILE__);
        $this->logger = WP_Media_Organiser_Logger::get_instance();

        // Initialize hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('save_post', array($this, 'reorganize_media'), 10, 3);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_get_taxonomies', array($this, 'ajax_get_taxonomies'));
    }

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate()
    {
        global $wpdb;
        $logger = WP_Media_Organiser_Logger::get_instance();
        $logger->log('Plugin activation started', 'info');

        $table_name = $wpdb->prefix . 'media_organiser_settings';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            setting_name varchar(255) NOT NULL,
            setting_value longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY setting_name (setting_name)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Set default settings
        $default_settings = array(
            'use_post_type' => '1',
            'taxonomy_name' => '',
            'post_identifier' => 'slug',
        );

        foreach ($default_settings as $name => $value) {
            $wpdb->replace(
                $table_name,
                array(
                    'setting_name' => $name,
                    'setting_value' => $value,
                ),
                array('%s', '%s')
            );
            $logger->log("Default setting created: $name = $value", 'info');
        }

        $logger->log('Plugin activation completed', 'info');
    }

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

    public function register_settings()
    {
        // Registration will be handled directly through our custom table
    }

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

        // Get all valid post types
        $post_types = $this->get_valid_post_types();

        // Pass the current taxonomy value and post types to JavaScript
        wp_localize_script(
            'wp-media-organiser-admin',
            'wpMediaOrganiser',
            array(
                'currentTaxonomy' => $this->get_setting('taxonomy_name'),
                'postTypes' => $post_types,
                'useYearMonthFolders' => get_option('uploads_use_yearmonth_folders'),
            )
        );
    }

    private function get_valid_post_types()
    {
        // Get all post types
        $post_types = get_post_types(array('public' => true), 'objects');

        // Add 'page' explicitly if not already included
        if (!isset($post_types['page'])) {
            $post_types['page'] = get_post_type_object('page');
        }

        // Built-in types to exclude
        $exclude_types = array(
            'attachment',
            'revision',
            'nav_menu_item',
            'custom_css',
            'customize_changeset',
            'oembed_cache',
            'user_request',
            'wp_block',
        );

        // Filter out unwanted post types
        foreach ($exclude_types as $exclude) {
            unset($post_types[$exclude]);
        }

        // Convert to simple array of labels
        $formatted_types = array();
        foreach ($post_types as $type) {
            $formatted_types[$type->name] = $type->label;
        }

        return $formatted_types;
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save settings
        if (isset($_POST['submit'])) {
            check_admin_referer('wp_media_organiser_settings');

            $taxonomy_value = sanitize_text_field($_POST['taxonomy_name']);
            $this->logger->log("Saving settings from form submission", 'info');
            $this->logger->log("Taxonomy value submitted: $taxonomy_value", 'debug');

            $this->update_setting('use_post_type', isset($_POST['use_post_type']) ? '1' : '0');
            $this->update_setting('taxonomy_name', $taxonomy_value);
            $this->update_setting('post_identifier', sanitize_text_field($_POST['post_identifier']));

            $saved_value = $this->get_setting('taxonomy_name');
            $this->logger->log("Settings saved successfully", 'info');

            echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'wp-media-organiser') . '</p></div>';
        }

        // Get current settings
        $use_post_type = $this->get_setting('use_post_type');
        $taxonomy_name = $this->get_setting('taxonomy_name');
        $post_identifier = $this->get_setting('post_identifier');

        ?>
        <div class="wrap wp-media-organiser-settings">
            <h1><?php _e('Media Organiser Settings', 'wp-media-organiser');?></h1>

            <div class="wp-media-organiser-preview">
                <p><?php _e('Preview of media file path:', 'wp-media-organiser');?></p>
                <code></code>
            </div>

            <form method="post" action="" class="wp-media-organiser-form">
                <?php wp_nonce_field('wp_media_organiser_settings');?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="use_post_type"><?php _e('Include Post Type', 'wp-media-organiser');?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="use_post_type" name="use_post_type" value="1" <?php checked($use_post_type, '1');?>>
                            <p class="description"><?php _e('Include the post type in the file path', 'wp-media-organiser');?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="taxonomy_name"><?php _e('Taxonomy', 'wp-media-organiser');?></label>
                        </th>
                        <td>
                            <select id="taxonomy_name" name="taxonomy_name">
                                <option value=""><?php _e('None', 'wp-media-organiser');?></option>
                            </select>
                            <p class="description"><?php _e('Select a taxonomy to include in the file path', 'wp-media-organiser');?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <?php _e('Date Folders', 'wp-media-organiser');?>
                        </th>
                        <td>
                            <p class="description">
                                <?php
printf(
            __('Date-based folders (YYYY/MM) are controlled in %sMedia Settings%s', 'wp-media-organiser'),
            '<a href="' . admin_url('options-media.php') . '">',
            '</a>'
        );
        ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="post_identifier"><?php _e('Post Identifier', 'wp-media-organiser');?></label>
                        </th>
                        <td>
                            <select id="post_identifier" name="post_identifier">
                                <option value="none" <?php selected($post_identifier, 'none');?>><?php _e('None', 'wp-media-organiser');?></option>
                                <option value="slug" <?php selected($post_identifier, 'slug');?>><?php _e('Post Slug', 'wp-media-organiser');?></option>
                                <option value="id" <?php selected($post_identifier, 'id');?>><?php _e('Post ID', 'wp-media-organiser');?></option>
                            </select>
                            <p class="description"><?php _e('Choose how to identify the post in the file path', 'wp-media-organiser');?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Save Changes', 'wp-media-organiser');?>">
                </p>
            </form>
        </div>
        <?php
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
            if ($new_path && $new_path !== $file) {
                $this->logger->log("Moving file: $file to $new_path", 'info');
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
        if ($this->get_setting('use_post_type') === '1' && $post && isset($post->post_type)) {
            // Check if it's a valid post type
            $valid_types = array_keys($this->get_valid_post_types());
            if (in_array($post->post_type, $valid_types)) {
                $path_parts[] = $post->post_type;
            }
        }

        // Add taxonomy and term if set
        $taxonomy_name = $this->get_setting('taxonomy_name');
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
        $post_identifier = $this->get_setting('post_identifier');
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
        }
    }

    public function get_setting($name)
    {
        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT setting_value FROM {$this->settings_table} WHERE setting_name = %s",
            $name
        );
        $this->logger->log("Getting setting: $name", 'debug');
        $value = $wpdb->get_var($query);
        $this->logger->log("Retrieved setting $name = " . ($value !== null ? $value : 'null'), 'debug');
        return $value !== null ? $value : '';
    }

    public function update_setting($name, $value)
    {
        global $wpdb;
        $this->logger->log("Updating setting: $name = $value", 'debug');

        $result = $wpdb->replace(
            $this->settings_table,
            array(
                'setting_name' => $name,
                'setting_value' => $value,
            ),
            array('%s', '%s')
        );

        if ($result === false) {
            $this->logger->log("Failed to update setting: $name. Error: " . $wpdb->last_error, 'error');
        } else {
            $this->logger->log("Successfully updated setting: $name", 'debug');
        }
    }

    public function ajax_get_taxonomies()
    {
        $this->logger->log('AJAX request: get_taxonomies', 'debug');
        $taxonomies = get_taxonomies(array('public' => true), 'objects');
        $response = array();

        foreach ($taxonomies as $tax) {
            $response[$tax->name] = $tax->label;
        }

        $this->logger->log('Available taxonomies: ' . print_r($response, true), 'debug');
        wp_send_json_success($response);
    }
}

// Initialize the plugin
add_action('plugins_loaded', array('WP_Media_Organiser', 'get_instance'));

// Register activation hook
register_activation_hook(__FILE__, array('WP_Media_Organiser', 'activate'));
