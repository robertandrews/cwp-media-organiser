<?php

if (!defined('WPINC')) {
    die;
}

class CWP_Media_Organiser_Notice_Components
{
    /**
     * Render the notice container
     *
     * @param string $type Either 'pre-save' or 'post-save'
     * @param array $data Notice data
     * @param bool $is_list_screen Whether we're on the post list screen (edit.php)
     * @return string HTML output
     */
    public static function render_notice($type, $data, $is_list_screen = false)
    {
        $notice_class = $type === 'pre-save' ? 'notice-warning' : 'notice-info';
        $output = sprintf(
            '<div class="notice %s is-dismissible media-organiser-notice" data-notice-type="%s">',
            esc_attr($notice_class),
            esc_attr($type)
        );

        // Add common styles
        $output .= self::get_notice_styles();

        // Title component is always present
        $output .= self::render_title_component($type);

        // Summary counts only on post-save AND list screen
        if ($type === 'post-save' && $is_list_screen && isset($data['counts'])) {
            $output .= self::render_summary_counts_component($data['counts']);
        }

        // Post info component (if we have post data)
        if (isset($data['post'])) {
            $output .= self::render_post_info_component($data['post']);
        }

        // Media items list
        if (isset($data['media_items'])) {
            $output .= self::render_media_items_list($data['media_items'], $type);
        }

        $output .= '</div>';
        return $output;
    }

    /**
     * Get common notice styles
     */
    private static function get_notice_styles()
    {
        return '<style>
            .media-organiser-notice { padding: 10px 12px; }
            .media-organiser-notice .notice-title { font-size: inherit; margin: 0 0 5px; }
            .media-status-item { display: flex; align-items: flex-start; margin-bottom: 5px; }
            .media-status-item img { width: 36px; height: 36px; object-fit: cover; margin-right: 10px; }
            .media-status-item .status-text { flex: 1; padding-bottom: 8px; }
            .status-dot { margin-right: 5px; }
            .status-dot-moved { color: #ffb900; }
            .status-dot-correct { color: #46b450; }
            .status-dot-failed { color: #dc3232; }
            .status-dot-skipped { color: #888888; }
            .status-dot-preview { color: #ffb900; }
            .summary-counts span { margin-right: 15px; }
            /* Operation status styles */
            .component-operation.operation-correct { color: #46b450; }
            .component-operation.operation-move { color: #ffb900; }
            .component-operation.operation-fail { color: #dc3232; }
            .component-operation.operation-skip { color: #888888; }
            /* Path component styles */
            .path-component { padding: 2px 4px; border-radius: 2px; }
            .path-post-type { background-color: #ffebee; color: #c62828; }
            .path-taxonomy { background-color: #e8f5e9; color: #2e7d32; }
            .path-term { background-color: #e3f2fd; color: #1565c0; }
            .path-post-identifier { background-color: #fff3e0; color: #ef6c00; }
            code {
                background: #f5f5f5 !important;
                margin: 0 !important;
                display: inline-block !important;
                border-radius: 4px !important;
            }
        </style>';
    }

    /**
     * Render the title component
     */
    private static function render_title_component($type)
    {
        $prefix = $type === 'pre-save' ? 'Pre-save: ' : 'Post-save: ';
        return sprintf(
            '<span class="component-title"><p class="notice-title"><strong>%sMedia files organization:</strong></p></span>',
            $prefix
        );
    }

    /**
     * Get operation text based on state
     */
    private static function get_operation_text($status, $notice_type)
    {
        if ($notice_type === 'pre-save') {
            switch ($status) {
                case 'correct':
                    return 'Already in correct location:';
                case 'move':
                    return 'Will move from';
                case 'fail':
                    return 'Cannot move from';
                case 'skip':
                    return 'Will skip:';
                default:
                    return '';
            }
        } else {
            switch ($status) {
                case 'correct':
                    return 'Already in correct location:';
                case 'move':
                    return 'Moved from';
                case 'fail':
                    return 'Failed to move from';
                case 'skip':
                    return 'Skipped:';
                default:
                    return '';
            }
        }
    }

    /**
     * Get status dot class based on status
     */
    private static function get_status_class($status)
    {
        $status_map = [
            'correct' => 'status-dot-correct',
            'move' => 'status-dot-moved',
            'fail' => 'status-dot-failed',
            'skip' => 'status-dot-skipped',
        ];

        return isset($status_map[$status]) ? $status_map[$status] : 'status-dot-correct';
    }

    /**
     * Render summary counts component
     */
    private static function render_summary_counts_component($counts)
    {
        return sprintf(
            '<span class="component-summary-counts"><p class="summary-counts">
                <span class="count-item"><span class="status-dot status-dot-correct">●</span>In correct location: %d</span>
                <span class="count-item"><span class="status-dot status-dot-moved">●</span>Files moved: %d</span>
                <span class="count-item"><span class="status-dot status-dot-failed">●</span>Failed: %d</span>
                <span class="count-item"><span class="status-dot status-dot-skipped">●</span>Skipped: %d</span>
            </p></span>',
            $counts['already_organized'],
            $counts['success'],
            $counts['failed'],
            $counts['skipped']
        );
    }

    /**
     * Render post info component
     */
    private static function render_post_info_component($post_data)
    {
        return sprintf(
            '<span class="component-post-info"><p class="post-info"><strong>Post ID <a href="%s">%d</a>: "%s" (%d media items)</strong></p></span>',
            esc_url(get_edit_post_link($post_data['id'])),
            $post_data['id'],
            esc_html($post_data['title']),
            $post_data['media_count']
        );
    }

    /**
     * Render media items list
     */
    private static function render_media_items_list($items, $notice_type)
    {
        if (empty($items)) {
            return '<span class="component-media-items"><p>No media files found</p></span>';
        }

        $output = '<span class="component-media-items"><ul style="margin-left: 20px;">';
        foreach ($items as $item) {
            $output .= self::render_media_item($item, $notice_type);
        }
        $output .= '</ul></span>';
        return $output;
    }

    /**
     * Render individual media item
     */
    private static function render_media_item($item, $notice_type)
    {
        $status_class = self::get_status_class($item['status']);
        $operation_text = self::get_operation_text($item['status'], $notice_type);
        $path_display = self::render_path_display($item, $notice_type);

        // Add operation status class for pre-save notices
        $operation_class = $notice_type === 'pre-save' ? sprintf(' operation-%s', $item['status']) : '';

        return sprintf(
            '<li class="media-status-item" data-media-id="%d">
                <span class="component-thumbnail">%s</span>
                <span class="component-media-info">
                    <span class="status-text">
                        <span class="component-status-dot"><span class="status-dot %s">●</span></span>
                        <span class="component-media-id">Media ID <a href="%s">%d</a></span>
                        <span class="component-media-title">("%s")</span>:
                        <span class="component-operation%s">%s</span>
                        <span class="component-path">%s</span>
                    </span>
                </span>
            </li>',
            $item['id'],
            $item['thumbnail'],
            $status_class,
            esc_url(get_edit_post_link($item['id'])),
            $item['id'],
            esc_html($item['title']),
            $operation_class,
            $operation_text,
            $path_display
        );
    }

    /**
     * Render path display component
     */
    private static function render_path_display($item, $notice_type)
    {
        if ($item['status'] === 'correct') {
            // If path is correct, only show the preferred path
            return sprintf(
                '<span class="component-path-single"><code>%s</code></span>',
                $item['preferred_path']
            );
        }

        // For any other status, show both current and preferred paths
        if ($notice_type === 'pre-save') {
            return sprintf(
                '<span class="component-path-from-to"><code><del>%s</del></code> to <code class="preview-path-%d">%s</code></span>',
                esc_html($item['current_path']),
                $item['id'],
                $item['preferred_path']
            );
        } else {
            return sprintf(
                '<span class="component-path-from-to"><code><del>%s</del></code> to <code>%s</code></span>',
                esc_html($item['current_path']),
                $item['preferred_path']
            );
        }
    }
}
