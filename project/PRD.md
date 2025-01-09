# WordPress Media Organiser - Product Requirements Document

## Overview

WordPress Media Organiser is a powerful plugin that provides WordPress administrators with granular control over media file organization within their WordPress installation. The plugin intelligently reorganizes media files based on their relationship with posts and custom taxonomies, offering a more structured and maintainable media library.

## Problem Statement

The default WordPress media organization structure (`/wp-content/uploads/YYYY/MM`) lacks flexibility and meaningful organization, making it difficult for administrators to maintain and navigate large media libraries. This plugin solves this problem by providing a customizable, hierarchical file structure based on post types, taxonomies, and other meaningful metadata.

## Core Features

### 1. Intelligent Media Organization

- Automatically reorganizes media files upon post save (both draft and publish)
- Handles three types of media associations:
  - Media files attached to posts
  - Featured images
  - Media embedded in post content (img, embed, video, audio, and object sources)
- Prevents reorganization during auto-saves to maintain performance

### 2. Customizable File Structure

- Supports hierarchical organization pattern:

  ```text
  /wp-content/uploads/post_type/taxonomy_name/term_slug/YYYY/MM/post-slug-or-id/image.jpeg
  ```

- Configurable components:
  - Post type inclusion
  - Taxonomy selection
  - Post identifier (slug or ID)
  - Date-based folders (year/month)

### 3. Admin Interface

- Dedicated settings page under WordPress Media menu
- User-friendly interface for configuration
- Real-time preview of file organization structure
- Bulk action support for post management

### 4. Security & Performance

- Custom database table (`wp_media_organiser_settings`) for settings storage
- Secure handling of file operations
- Efficient processing with background task support
- Proper validation and sanitization of user inputs

## Technical Requirements

### Database

- Custom table: `wp_media_organiser_settings`
- Schema:
  - id (mediumint, auto-increment)
  - setting_name (varchar)
  - setting_value (longtext)
- No dependency on WordPress options table

### Settings Management

- Default settings on activation:
  - use_post_type: enabled
  - taxonomy_name: configurable
  - post_identifier: slug (default)

### User Interface Components

- Settings page with:
  - Post type selection
  - Taxonomy configuration
  - Path structure preview
  - File operation status notifications
- Integration with post editor for real-time previews
- Bulk action integration in post list views

### File Operations

- Safe file movement with error handling
- Preservation of file relationships in WordPress database
- Handling of various media types and sources
- Proper permissions checking

## Notifications & Feedback

- Visual feedback for file operations
- Operation status categories:
  - Already in correct location
  - Successfully moved
  - Failed operations
  - Skipped items
- Detailed logging for debugging purposes

## Technical Dependencies

- WordPress core media functions
- jQuery for UI interactions
- Mustache.js for templating
- Custom notice rendering system

## Security Considerations

- WordPress nonce verification
- Capability checking for administrative functions
- Sanitization of user inputs
- Secure file operations

## Performance Considerations

- Efficient file operations
- Background processing for bulk operations
- Caching of templates and settings
- Minimal impact on post save operations

## Future Enhancements

1. Support for additional media types
2. Enhanced bulk operation capabilities
3. Media migration tools
4. Custom taxonomy support
5. Advanced path templating

## Compatibility

- WordPress version: Latest
- PHP version: 7.4+
- Server requirements: Standard WordPress hosting environment
- Browser support: Modern browsers with JavaScript enabled
