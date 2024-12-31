jQuery(document).ready(function ($) {
    function updatePreview() {
        var usePostType = $('#use_post_type').is(':checked');
        var taxonomyName = $('#taxonomy_name').val();
        var postIdentifier = $('#post_identifier').val();

        console.log('Current taxonomy value:', taxonomyName);

        var preview = '/wp-content/uploads/';
        var previewHtml = '';

        if (usePostType) {
            // Get a sample post type for preview
            var samplePostType = '{post}';
            if (typeof wpMediaOrganiser !== 'undefined' && wpMediaOrganiser.postTypes) {
                // Get the first available post type
                var postTypes = Object.keys(wpMediaOrganiser.postTypes);
                if (postTypes.length > 0) {
                    samplePostType = '{' + postTypes[0] + '}';
                }
            }
            previewHtml += '<span class="post-type">' + samplePostType + '</span>/';
        }

        if (taxonomyName) {
            previewHtml += '<span class="taxonomy">' + taxonomyName + '</span>/<span class="term">{term_slug}</span>/';
        }

        previewHtml += '{YYYY}/{MM}/';

        if (postIdentifier === 'slug') {
            previewHtml += '<span class="post-identifier">{post-slug}</span>/';
        } else if (postIdentifier === 'id') {
            previewHtml += '<span class="post-identifier">{post-id}</span>/';
        }

        previewHtml += 'image.jpg';

        $('.wp-media-organiser-preview code').html(previewHtml);
    }

    function loadTaxonomies() {
        var $select = $('#taxonomy_name');

        if ($select.data('loaded')) {
            return;
        }

        console.log('Loading taxonomies...');
        $.ajax({
            url: ajaxurl,
            data: {
                action: 'get_taxonomies'
            },
            success: function (response) {
                console.log('Taxonomy response:', response);
                if (response.success && response.data) {
                    var options = '<option value="">None</option>';
                    $.each(response.data, function (key, label) {
                        options += '<option value="' + key + '">' + label + '</option>';
                    });
                    $select.html(options);

                    // Set the current value if it exists
                    if (typeof wpMediaOrganiser !== 'undefined' && wpMediaOrganiser.currentTaxonomy) {
                        $select.val(wpMediaOrganiser.currentTaxonomy);
                        console.log('Set saved taxonomy value:', wpMediaOrganiser.currentTaxonomy);
                    }

                    // Update the preview after setting the value
                    updatePreview();
                }
                $select.data('loaded', true);
            },
            error: function (xhr, status, error) {
                console.error('Failed to load taxonomies:', error);
            }
        });
    }

    // Add post type info to the preview
    if (typeof wpMediaOrganiser !== 'undefined' && wpMediaOrganiser.postTypes) {
        var postTypeInfo = $('<div class="post-type-info" style="margin: 10px 0;"></div>');
        var postTypeList = '<strong>Available Post Types:</strong> ';
        var types = [];
        $.each(wpMediaOrganiser.postTypes, function (key, label) {
            types.push(label + ' (' + key + ')');
        });
        postTypeList += types.join(', ');
        postTypeInfo.html(postTypeList);
        $('.wp-media-organiser-preview').after(postTypeInfo);
    }

    // Update preview on any form change
    $('#use_post_type, #taxonomy_name, #post_identifier').on('change', function (e) {
        console.log('Form field changed:', e.target.id, 'New value:', $(e.target).val());
        updatePreview();
    });

    // Load taxonomies immediately on page load
    loadTaxonomies();

    // Also load taxonomies on focus (as a backup)
    $('#taxonomy_name').on('focus', function () {
        loadTaxonomies();
    });
}); 