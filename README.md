# WordPress Media Organiser

A powerful WordPress plugin that gives you complete control over your media file organization. Stop settling for the default year/month folders and organize your media files in a way that makes sense for your content.

Go from:

```text
/wp-content/uploads/YYYY/MM/filename.jpeg
```

to:

```text
/wp-content/uploads/post_type/taxonomy_name/term_slug/YYYY/MM/post-slug-or-id/image.jpeg
```

## Features

- **Smart Media Organization**: Automatically organizes media files based on posts they're associated with
- **Custom Folder Structure**: Create meaningful hierarchies using post types, taxonomies, and custom identifiers
- **Real-time Preview**: See how your media will be organized before making changes
- **Bulk Operations**: Easily reorganize multiple posts' media files at once

## Installation

1. Download the plugin zip file
2. Go to WordPress admin panel > Plugins > Add New
3. Click "Upload Plugin" and select the downloaded zip file
4. Click "Install Now" and then "Activate"

## Configuration

1. Navigate to Media > Media Organiser in your WordPress admin panel
2. Configure your preferred organization structure:
   - Enable/disable post type folders
   - Select a taxonomy for organization (optional)
   - Choose between post slug or ID for folder names
3. Save your settings

## File Organization Structure

By default (upon fresh installation), your media files will follow the WordPress standard year/month structure:

```text
/wp-content/uploads/YYYY/MM/filename.jpeg
```

Once configured through the settings page, you can organize files using any combination of these components:

- Post type folders
- Taxonomy folders
- Term slugs
- Year/Month folders
- Post slug or ID folders

Example of a fully configured path:

```text
/wp-content/uploads/post_type/taxonomy_name/term_slug/YYYY/MM/post-slug-or-id/image.jpeg
```

All path components are optional and can be enabled/disabled individually through the settings page.

## Usage

### Basic Usage

1. Configure your preferred organization structure in the settings
2. Create or edit a post
3. Add media files as usual
4. Save the post - media files will be automatically organized

### Bulk Operations

1. Go to Posts or Pages list
2. Select multiple items
3. Choose "Organize Media" from the Bulk Actions dropdown
4. Click "Apply"

### Real-time Preview

When editing a post, you'll see a preview of where your media files will be moved before saving.

## Supported Media Types

- Images
- Videos
- Audio files
- Documents
- Any media file uploaded through WordPress media library

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Modern web browser with JavaScript enabled
- Standard WordPress hosting environment

## FAQ

### Will this plugin move my existing media files?

Yes, but only when you explicitly choose to do so through bulk actions or by resaving posts.

### What happens if I deactivate the plugin?

Your existing file structure remains unchanged. New media uploads will follow WordPress default organization.

### Can I customize the folder structure?

Yes, you can enable/disable components and choose between different identifiers for organization.

### Will this break my media links?

No, WordPress automatically handles media URLs regardless of their file location.

## Database Changes

This plugin creates a single custom table in your WordPress database:

```sql
wp_media_organiser_settings
```

### Table Structure

- `id` (mediumint, auto-increment) - Primary key
- `setting_name` (varchar) - Name of the setting
- `setting_value` (longtext) - Value of the setting

The plugin:

- Does NOT modify any core WordPress tables except for necessary URL updates
- Does NOT store settings in wp_options
- Preserves all data upon deactivation
- Cleanly removes only its own table upon uninstallation

All media file relationships and WordPress attachments remain in their standard WordPress tables, only the physical file locations and corresponding URLs are changed.

### Media File Operations

When a media file is moved, the plugin performs two types of updates:

1. **File System Changes**:
   - Physical movement of the media file to its new location
   - Creation of new directories if needed

2. **Database Updates**:
   - Updates `guid` in `wp_posts` table for the attachment
   - Updates `_wp_attached_file` meta in `wp_postmeta`
   - Updates any instances of the old URL in post content
   - Maintains all relationships between posts and attachments

Note: While the plugin attempts to handle file operations and database updates carefully, these operations are not atomic. In case of failure during file movement or database updates, manual intervention may be required to restore consistency. It is recommended to maintain regular backups of both your files and database.

## Support

For bug reports and feature requests, please use the GitHub issues page.

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the GPL v2 or later - see the LICENSE file for details.

## Credits

Developed with ❤️ for the WordPress community.
