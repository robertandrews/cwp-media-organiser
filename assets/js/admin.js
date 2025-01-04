jQuery(document).ready(function ($) {
    function updatePreview() {
        var usePostType = $('#use_post_type').is(':checked');
        var taxonomyName = $('#taxonomy_name').val();
        var postIdentifier = $('#post_identifier').val();

        // Get uploads path from WordPress settings
        var previewHtml = '';
        if (typeof wpMediaOrganiser !== 'undefined' && wpMediaOrganiser.uploadsPath) {
            previewHtml = wpMediaOrganiser.uploadsPath;
        }

        // Add post type if enabled
        if (usePostType) {
            previewHtml += '/<span class="post-type">{post}</span>';
        }

        if (taxonomyName) {
            previewHtml += '/<span class="taxonomy">' + taxonomyName + '</span>/<span class="term">{term_slug}</span>';
        }

        // Only show year/month folders if WordPress setting is enabled
        if (typeof wpMediaOrganiser !== 'undefined' && wpMediaOrganiser.useYearMonthFolders) {
            previewHtml += '/{YYYY}/{MM}';
        }

        if (postIdentifier === 'slug') {
            previewHtml += '/<span class="post-identifier">{post-slug}</span>';
        } else if (postIdentifier === 'id') {
            previewHtml += '/<span class="post-identifier">{post-id}</span>';
        }

        previewHtml += '/image.jpg';

        $('.wp-media-organiser-preview code').html(previewHtml);
    }

    // Add event listeners for form changes
    $('#use_post_type, #taxonomy_name, #post_identifier').on('change', updatePreview);

    // Add post type info after the checkbox description
    if (typeof wpMediaOrganiser !== 'undefined' && wpMediaOrganiser.postTypes) {
        var postTypeInfo = $('<div class="post-type-info" style="margin-top: 10px; color: #666;"></div>');
        var postTypeList = '<strong>Available Post Types:</strong> ';
        var types = [];
        $.each(wpMediaOrganiser.postTypes, function (key, label) {
            types.push(label + ' (' + key + ')');
        });
        postTypeList += types.join(', ');
        postTypeInfo.html(postTypeList);
        $('#use_post_type').closest('td').find('.description').after(postTypeInfo);
    }
}); 