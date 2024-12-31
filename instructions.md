# WP Media Organiser

Write a comprehensive WordPress plugin offering the user custom control over where media files are stored.

/wp-content/uploads/wp-content/uploads/YYYY/MM does not offer enough control.

The organisation should fire on save_post, whether publish or draft - but not auto-saves.

It should reorganise media files that are either a) attached to a post, b) the featured image or c) contained in a post body but not otherwise *attached* to a post. c) includes: img src, embed src, video src, audio src, object src

## Admin Settings

There should be a dedicated options page in the WordPress admin, under Media.

User settings should not leverage wp_options or any WordPress database tables except a custom table. The plugin should only store settings in its own database table, wp_media_organiser_settings.

## Features

1. Custom folder structure

/wp-content/uploads/wp-content/uploads/post_type/taxonomy_name/term_slug/2024/12/post-slug-or-id/image.jpeg

Folders for images should depend on criteria of the post to which the media is attached or in whose body the media is contained.

The file path should be highly composable by the site user using admin settings. They should compose file path using any of:

- post_type, also inc page (yes/no)
- taxonomy_name with term_slug (choose a taxonomy)
- YYYY/MM (actually, already set by WordPress Media Settings - options-media.php - so not user-editable)
- post slug, post id or no post identifier (select one of these three options)

Admin settings should show a preview of the file path, dynamically adjusting based on the user's choices. In the preview, each file path component should be a different colour for clarity...

- post_type (red)
- taxonomy_name (green)
- term_slug (blue)
- YYYY/MM (no custom colour)
- post slug, post id or no post identifier (yellow)

On save_post, the plugin should check whether the location of the media file (whether a) attached, b) featured image or c) contained in the body) matches the user's preferred path. If not, it should move the file to the correct location.

Importantly, it should also update the database to record the new location of the file. It is important that all aspects of media storage in the database are updated accurately.