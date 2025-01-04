jQuery(document).ready(function ($) {
    console.log('Preview script initialized');

    const noticeRenderer = new NoticeRenderer();
    let currentPreviewData = null;

    // Initialize the notice renderer
    noticeRenderer.init().then(() => {
        console.log('Notice renderer initialized');
    }).catch(error => {
        console.error('Failed to initialize notice renderer:', error);
    });

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
                        notice_type: 'Pre-save',
                        media_items: response.data.map(item => ({
                            media_id: item.id,
                            media_title: item.title || '',
                            media_edit_url: `/wp-admin/post.php?post=${item.id}&action=edit`,
                            thumbnail_url: item.thumbnail_url || '',
                            status: item.status,
                            status_class: item.status.replace('will_', ''),
                            operation_text: wpMediaOrganiser.noticeConfig.operation_text['pre-save'][item.status],
                            current_path: item.current_path,
                            colored_path: item.preferred_path,
                            paths_match: item.status === 'correct',
                            is_pre_save: true
                        })),
                        post_info: {
                            post_id: wpMediaOrganiser.postId,
                            post_title: $('#title').val()
                        }
                    };
                    console.log('Prepared notice data:', noticeData);
                    console.log('Media items:', JSON.stringify(noticeData.media_items, null, 2));

                    try {
                        // Render notice
                        const html = await noticeRenderer.renderNotice('post.php', 'pre-save', noticeData);
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