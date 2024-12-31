jQuery(document).ready(function ($) {
    var preview = $('#media-organiser-preview');
    if (!preview.length) return;

    // Show the preview notice
    preview.show();

    // Function to get current post slug
    function getCurrentSlug() {
        // Check for active slug input field first
        var slugInput = $('#new-post-slug');
        if (slugInput.length && slugInput.is(':visible')) {
            var value = slugInput.val();
            if (value) {
                return value;
            }
        }

        // Always use the full slug version when not editing
        var editableSlugFull = $('#editable-post-name-full');
        if (editableSlugFull.length) {
            var fullSlug = editableSlugFull.text();
            return fullSlug;
        }

        return '';
    }

    // Function to update preview paths
    function updatePreviewPaths() {
        var data = {
            action: 'get_preview_paths',
            post_id: wpMediaOrganiser.postId,
            nonce: wpMediaOrganiser.nonce
        };

        // Add taxonomy term if taxonomy is enabled in settings
        if (wpMediaOrganiser.settings.taxonomyName) {
            var termInputs = $('#taxonomy-' + wpMediaOrganiser.settings.taxonomyName + ' input:checked');
            if (termInputs.length > 0) {
                data.taxonomy_term = termInputs.first().val();
            } else {
                data.taxonomy_term = '';
            }
        }

        // Add post slug if it's being used as identifier
        if (wpMediaOrganiser.settings.postIdentifier === 'slug') {
            data.post_slug = getCurrentSlug();
        }

        // Make AJAX call to get preview paths
        $.ajax({
            url: wpMediaOrganiser.ajaxurl,
            type: 'POST',
            data: data,
            success: function (response) {
                if (response.success && response.data) {
                    Object.keys(response.data).forEach(function (mediaId) {
                        var selector = '.preview-path-' + mediaId;
                        var element = $(selector);
                        if (element.length) {
                            element.html(response.data[mediaId]);
                        }
                    });
                }
            }
        });
    }

    // Set up event listeners based on settings
    if (wpMediaOrganiser.settings.taxonomyName) {
        var taxonomyBox = $('#taxonomy-' + wpMediaOrganiser.settings.taxonomyName);
        taxonomyBox.on('change', 'input[type="checkbox"], input[type="radio"]', updatePreviewPaths);
    }

    if (wpMediaOrganiser.settings.postIdentifier === 'slug') {
        // Listen for real-time changes in the slug editor
        $(document).on('input keyup', '#new-post-slug', function (e) {
            updatePreviewPaths();
        });

        // Listen for any changes to the full slug
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type === 'characterData' || mutation.type === 'childList') {
                    updatePreviewPaths();
                }
            });
        });

        var fullSlugElement = document.getElementById('editable-post-name-full');
        if (fullSlugElement) {
            observer.observe(fullSlugElement, {
                characterData: true,
                childList: true,
                subtree: true
            });
        }

        // Listen for the WordPress permalink editor buttons
        $(document).on('click', '#edit-slug-buttons button', function (e) {
            // Wait for WordPress to update the UI
            setTimeout(updatePreviewPaths, 100);
        });

        // Listen for WordPress's permalink update event
        $(document).on('ajaxSuccess', function (event, xhr, settings) {
            if (settings.data && settings.data.indexOf('action=sample-permalink') !== -1) {
                setTimeout(updatePreviewPaths, 100);
            }
        });
    }

    // Initial update
    updatePreviewPaths();
}); 