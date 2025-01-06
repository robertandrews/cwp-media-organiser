jQuery(document).ready(function ($) {
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
        // Get the current post slug and taxonomy term
        const postSlug = $('#post_name').val();
        const taxonomyTerm = $('#' + wpMediaOrganiser.settings.taxonomyName).val();

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
                if (response.success && response.data) {
                    // Store the current preview data
                    currentPreviewData = response.data;

                    try {
                        // Initialize renderer if needed and render notice
                        const renderer = await ensureNoticeRenderer();
                        const data = {
                            media_items: response.data,
                            post_info: {
                                post_id: wpMediaOrganiser.postId,
                                post_title: $('#title').val()
                            }
                        };
                        const html = await renderer.renderNotice('post.php', 'preview', data);

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