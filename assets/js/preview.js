jQuery(document).ready(function ($) {
    console.log('Preview script initialized');

    let noticeRenderer = null;
    let currentPreviewData = null;

    // Function to ensure notice renderer is initialized
    async function ensureNoticeRenderer() {
        if (!noticeRenderer) {
            noticeRenderer = new NoticeRenderer();
            await noticeRenderer.init();
        }
        return noticeRenderer;
    }

    // Function to update preview paths
    async function updatePreviewPaths() {
        console.log('Updating preview paths...');

        // Get the current post slug and taxonomy term
        const postSlug = $('#post_name').val();
        const taxonomyTerm = $('#' + wpMediaOrganiser.settings.taxonomyName).val();

        console.log('Update data:', {
            post_id: wpMediaOrganiser.postId,
            post_slug: postSlug,
            taxonomy_term: taxonomyTerm
        });

        $.ajax({
            url: wpMediaOrganiser.ajaxurl,
            type: 'POST',
            data: {
                action: 'wp_media_organiser_preview',
                nonce: wpMediaOrganiser.nonce,
                post_id: wpMediaOrganiser.postId,
                post_slug: postSlug,
                taxonomy_term: taxonomyTerm
            },
            success: async function (response) {
                console.log('AJAX response:', response);
                console.log('Response data structure:', JSON.stringify(response.data, null, 2));

                if (response.success && response.data) {
                    // Store the current preview data
                    currentPreviewData = response.data;

                    // Prepare notice data
                    const noticeData = {
                        notice_type: 'Preview',
                        media_items: response.data.map(item => ({
                            media_id: item.id,
                            media_title: item.title || '',
                            media_edit_url: `/wp-admin/post.php?post=${item.id}&action=edit`,
                            thumbnail_url: item.thumbnail_url || '',
                            status: item.status,
                            status_class: item.status.replace('will_', ''),
                            operation_text: wpMediaOrganiser.noticeConfig.operation_text['preview'][item.status],
                            current_path: item.current_path,
                            paths_match: item.status === 'correct',
                            is_pre_save: true,
                            // Add path components for dynamic updates
                            post_type: item.post_type,
                            taxonomy: item.taxonomy,
                            term: item.term,
                            year: item.year,
                            month: item.month,
                            post_id: item.post_id,
                            filename: item.filename
                        })),
                        post_info: {
                            post_id: wpMediaOrganiser.postId,
                            post_title: $('#title').val()
                        }
                    };
                    console.log('Prepared notice data:', noticeData);
                    console.log('Media items:', JSON.stringify(noticeData.media_items, null, 2));

                    try {
                        // Initialize renderer if needed and render notice
                        const renderer = await ensureNoticeRenderer();
                        const html = await renderer.renderNotice('post.php', 'preview', noticeData);
                        console.log('Rendered HTML:', html);

                        // Update notice container
                        $('#media-organiser-notice-container').html(html);
                    } catch (error) {
                        console.error('Error rendering notice:', error);
                    }
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', error);
                if (xhr.responseText) {
                    console.error('Server response:', xhr.responseText);
                }
            }
        });
    }

    // Watch for changes to post slug
    $('#post_name').on('input', _.debounce(updatePreviewPaths, 500));

    // Watch for changes to taxonomy term if it exists
    if (wpMediaOrganiser.settings.taxonomyName) {
        $('#' + wpMediaOrganiser.settings.taxonomyName).on('change', updatePreviewPaths);
    }
}); 