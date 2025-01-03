jQuery(document).ready(function ($) {
    var preview = $('.media-organiser-notice[data-notice-type="pre-save"]');
    if (!preview.length) return;

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

    // Function to update media item status
    function updateMediaItemStatus($item, currentPath, newPath, isUserChange) {
        var $statusDot = $item.find('.status-dot');
        var $operationSpan = $item.find('.component-operation');
        var $pathSpan = $item.find('.component-path');
        var noticeType = 'pre-save';

        // If this is the initial load and item is in "correct" state, don't change anything
        if (!isUserChange && $statusDot.hasClass(wpMediaOrganiser.noticeConfig.status_types.correct.dot_class)) {
            // Store the initial path and settings for future comparisons
            $item.data('original-path', newPath);
            $item.data('original-identifier', wpMediaOrganiser.settings.postIdentifier);
            console.log('Storing original state:', {
                path: newPath,
                identifier: wpMediaOrganiser.settings.postIdentifier
            });
            return;
        }

        // Normalize paths for comparison
        var normalizedCurrent = currentPath.toLowerCase().replace(/\\/g, '/');
        var normalizedNew = newPath.toLowerCase().replace(/\\/g, '/');
        var normalizedOriginal = $item.data('original-path') ?
            $item.data('original-path').toLowerCase().replace(/\\/g, '/') :
            normalizedNew;

        // Check if we're back to the original state
        var isBackToOriginal = normalizedNew === normalizedOriginal &&
            wpMediaOrganiser.settings.postIdentifier === $item.data('original-identifier');

        console.log('Path comparison:', {
            mediaId: $item.data('media-id'),
            normalizedNew,
            normalizedOriginal,
            currentIdentifier: wpMediaOrganiser.settings.postIdentifier,
            originalIdentifier: $item.data('original-identifier'),
            isBackToOriginal
        });

        var status = isBackToOriginal ? 'correct' : 'move';
        var statusConfig = wpMediaOrganiser.noticeConfig.status_types[status];
        var operationText = wpMediaOrganiser.noticeConfig.operation_text[noticeType][status];

        // Update status classes and text
        $statusDot.removeClass(Object.values(wpMediaOrganiser.noticeConfig.status_types).map(c => c.dot_class).join(' '))
            .addClass(statusConfig.dot_class);
        $operationSpan.removeClass(Object.values(wpMediaOrganiser.noticeConfig.status_types).map(c => c.operation_class).join(' '))
            .addClass(statusConfig.operation_class)
            .text(operationText);

        // Update path display
        if (status === 'correct') {
            // Show only the preferred path
            $pathSpan.html('<span class="component-path-single"><code>' + newPath + '</code></span>');
        } else {
            // Show both paths
            $pathSpan.html('<span class="component-path-from-to">From: <code><del>' +
                currentPath + '</del></code><br>To: <code class="preview-path-' +
                $item.data('media-id') + '">' + newPath + '</code></span>');
        }
    }

    // Function to update preview paths
    function updatePreviewPaths(isUserChange = false) {
        console.log('Updating preview paths...', isUserChange ? 'User change' : 'Initial load'); // Debug log

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

        console.log('AJAX data:', data); // Debug log

        // Make AJAX call to get preview paths
        $.ajax({
            url: wpMediaOrganiser.ajaxurl,
            type: 'POST',
            data: data,
            success: function (response) {
                console.log('AJAX response:', response); // Debug log
                if (response.success && response.data) {
                    Object.keys(response.data).forEach(function (mediaId) {
                        var $mediaItem = $('.media-status-item[data-media-id="' + mediaId + '"]');
                        if ($mediaItem.length) {
                            // Get the current path from the existing markup
                            var currentPath = $mediaItem.find('.component-path code:first').text();
                            // Update status and paths
                            updateMediaItemStatus($mediaItem, currentPath, response.data[mediaId], isUserChange);
                        }
                    });
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', status, error); // Debug log
            }
        });
    }

    // Set up event listeners based on settings
    if (wpMediaOrganiser.settings.taxonomyName) {
        var taxonomyBox = $('#taxonomy-' + wpMediaOrganiser.settings.taxonomyName);
        taxonomyBox.on('change', 'input[type="checkbox"], input[type="radio"]', function () {
            console.log('Taxonomy changed'); // Debug log
            updatePreviewPaths(true);
        });
    }

    if (wpMediaOrganiser.settings.postIdentifier === 'slug') {
        // Listen for real-time changes in the slug editor
        $(document).on('input keyup', '#new-post-slug', function (e) {
            console.log('Slug changed'); // Debug log
            updatePreviewPaths(true);
        });

        // Listen for any changes to the full slug
        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type === 'characterData' || mutation.type === 'childList') {
                    console.log('Slug mutation detected'); // Debug log
                    updatePreviewPaths(true);
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
            console.log('Permalink button clicked'); // Debug log
            // Wait for WordPress to update the UI
            setTimeout(function () { updatePreviewPaths(true); }, 100);
        });

        // Listen for WordPress's permalink update event
        $(document).on('ajaxSuccess', function (event, xhr, settings) {
            if (settings.data && settings.data.indexOf('action=sample-permalink') !== -1) {
                console.log('WordPress permalink updated'); // Debug log
                setTimeout(function () { updatePreviewPaths(true); }, 100);
            }
        });
    }

    // Initial update - not a user change
    console.log('Initial update'); // Debug log
    updatePreviewPaths(false);
}); 