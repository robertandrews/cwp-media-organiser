<?php
    if (! defined('WPINC')) {
        die;
    }
?>
<div class="wrap wp-media-organiser-settings">
    <h1><?php _e('Media Organiser Settings', 'wp-media-organiser'); ?></h1>

    <div class="wp-media-organiser-preview">
        <p><?php _e('Preview of media file path:', 'wp-media-organiser'); ?></p>
        <code>/wp-content/uploads/<?php
                                  if ($template_data['use_post_type']): ?><span class="path-component path-post-type">{post}</span>/<?php endif;
if ($template_data['taxonomy_name']): ?><span class="path-component path-taxonomy"><?php echo esc_html($template_data['taxonomy_name']); ?></span>/<span class="path-component path-term">{term_slug}</span>/<?php endif;
if (get_option('uploads_use_yearmonth_folders')): ?>{YYYY}/{MM}/<?php endif;
if ($template_data['post_identifier'] === 'slug'): ?><span class="path-component path-post-identifier">{post-slug}</span>/<?php
elseif ($template_data['post_identifier'] === 'id'): ?><span class="path-component path-post-identifier">{post-id}</span>/<?php endif;
?>image.jpg</code>
    </div>

    <form method="post" action="" class="wp-media-organiser-form">
        <?php wp_nonce_field('wp_media_organiser_settings'); ?>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="use_post_type"><?php _e('Include Post Type', 'wp-media-organiser'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="use_post_type" name="use_post_type" value="1"                                                                                             <?php checked($template_data['use_post_type'], '1'); ?>>
                    <p class="description"><?php _e('Include the post type in the file path', 'wp-media-organiser'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="taxonomy_name"><?php _e('Taxonomy', 'wp-media-organiser'); ?></label>
                </th>
                <td>
                    <select id="taxonomy_name" name="taxonomy_name">
                        <option value=""><?php _e('None', 'wp-media-organiser'); ?></option>
                        <?php foreach ($template_data['available_taxonomies'] as $tax_name => $tax_label): ?>
                            <option value="<?php echo esc_attr($tax_name); ?>"<?php selected($template_data['taxonomy_name'], $tax_name); ?>>
                                <?php echo esc_html($tax_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php _e('Select a taxonomy to include in the file path', 'wp-media-organiser'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <?php _e('Date Folders', 'wp-media-organiser'); ?>
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
                    <label for="post_identifier"><?php _e('Post Identifier', 'wp-media-organiser'); ?></label>
                </th>
                <td>
                    <select id="post_identifier" name="post_identifier">
                        <option value="none"                                             <?php selected($template_data['post_identifier'], 'none'); ?>><?php _e('None', 'wp-media-organiser'); ?></option>
                        <option value="slug"                                             <?php selected($template_data['post_identifier'], 'slug'); ?>><?php _e('Post Slug', 'wp-media-organiser'); ?></option>
                        <option value="id"                                           <?php selected($template_data['post_identifier'], 'id'); ?>><?php _e('Post ID', 'wp-media-organiser'); ?></option>
                    </select>
                    <p class="description"><?php _e('Choose how to identify the post in the file path', 'wp-media-organiser'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="localize_remote_media"><?php _e('Remote Media', 'wp-media-organiser'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="localize_remote_media" name="localize_remote_media" value="1"                                                                                                             <?php checked($template_data['localize_remote_media'], '1'); ?>>
                    <p class="description"><?php _e('Automatically download and import remote media (e.g., images from external URLs) into the WordPress media library', 'wp-media-organiser'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label><?php _e('Log Levels', 'wp-media-organiser'); ?></label>
                </th>
                <td>
                    <?php
                        $log_levels       = explode(',', $template_data['log_levels']);
                        $available_levels = [
                            'DEBUG'   => __('Debug - Most detailed level', 'wp-media-organiser'),
                            'INFO'    => __('Info - General operations', 'wp-media-organiser'),
                            'WARNING' => __('Warning - Important notices', 'wp-media-organiser'),
                            'ERROR'   => __('Error - Critical issues', 'wp-media-organiser'),
                        ];
                    foreach ($available_levels as $level => $description): ?>
                        <label style="display: block; margin-bottom: 8px;">
                            <input type="checkbox"
                                   name="log_levels[]"
                                   value="<?php echo esc_attr($level); ?>"
                                   <?php checked(in_array($level, $log_levels)); ?>>
                            <strong><?php echo esc_html($level); ?></strong> -
                            <?php echo esc_html($description); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description"><?php _e('Select which log levels should be recorded in the log file', 'wp-media-organiser'); ?></p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Save Changes', 'wp-media-organiser'); ?>">
        </p>
    </form>
</div>