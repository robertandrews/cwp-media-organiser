<?php
if (!defined('WPINC')) {
    die;
}
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