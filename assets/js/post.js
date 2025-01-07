jQuery(document).ready(function ($) {
    console.log('WP Media Organiser post.js loaded');
    console.log('Settings:', wpMediaOrganiser);

    // Store the original template structure on page load
    let originalTemplate = '';
    $('.path-preferred-move').each(function () {
        if (!originalTemplate) {
            originalTemplate = $(this).html();
        }
    });

    function getTermSlug(termId) {
        return new Promise((resolve) => {
            $.ajax({
                url: wpMediaOrganiser.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_media_organiser_get_term_slug',
                    nonce: wpMediaOrganiser.nonce,
                    term_id: termId
                },
                success: function (response) {
                    if (response.success && response.data) {
                        console.log('Got term slug from server:', response.data);
                        resolve(response.data);
                    } else {
                        console.log('Failed to get term slug');
                        resolve('');
                    }
                },
                error: function () {
                    console.log('AJAX error getting term slug');
                    resolve('');
                }
            });
        });
    }

    async function getTaxonomyValue(taxonomyName) {
        // Get checked checkbox from the taxonomy checklist
        const $checked = $(`#${taxonomyName}checklist input[type="checkbox"]:checked`);
        console.log(`Found ${$checked.length} checked checkboxes`);

        if (!$checked.length) {
            console.log('No checked checkbox found');
            return '';
        }

        // Get the term ID from the checkbox value
        const termId = $checked.val();
        console.log('Found checked term ID:', termId);

        if (!termId) return '';

        // Get the term slug from WordPress
        const termSlug = await getTermSlug(termId);
        console.log('Got term slug:', termSlug);
        return termSlug;
    }

    async function updatePreferredMovePath() {
        // Get the current post slug
        const postSlug = $('#post_name').val();
        console.log('Current post slug:', postSlug);

        // Get the selected taxonomy term (if taxonomy is enabled)
        let taxonomyTerm = '';
        if (wpMediaOrganiser.settings.taxonomyName) {
            taxonomyTerm = await getTaxonomyValue(wpMediaOrganiser.settings.taxonomyName);
            console.log('Selected taxonomy term slug:', taxonomyTerm);
        }

        // Update all .path-preferred-move elements
        $('.path-preferred-move').each(function () {
            const $path = $(this);
            console.log('Processing path:', $path.html());

            if (!taxonomyTerm) {
                // Remove taxonomy and term parts for zero-term scenario
                let pathHtml = $path.html();
                pathHtml = pathHtml.replace(/\/(<span[^>]*class="[^"]*path-taxonomy[^"]*"[^>]*>[^<]*<\/span>)\/(<span[^>]*class="[^"]*path-term[^"]*"[^>]*>[^<]*<\/span>)\//, '/');
                $path.html(pathHtml);
                console.log('Removed taxonomy and term spans with slashes');
            } else {
                // Restore template and update values
                $path.html(originalTemplate);
                const $taxonomySpan = $path.find('.path-component.path-taxonomy');
                const $termSpan = $path.find('.path-component.path-term');
                if ($taxonomySpan.length && $termSpan.length) {
                    $taxonomySpan.text('client');
                    $termSpan.text(taxonomyTerm);
                    console.log('Restored template and updated values');
                }
            }

            // Update post identifier if it's a slug
            const $postIdSpan = $path.find('.path-component.path-post-identifier');
            if ($postIdSpan.length && wpMediaOrganiser.settings.postIdentifier === 'slug') {
                $postIdSpan.text(postSlug || '');
                console.log('Updated post identifier to:', postSlug);
            }

            // Clean up any remaining multiple slashes
            let pathHtml = $path.html();
            pathHtml = pathHtml.replace(/\/+/g, '/');
            $path.html(pathHtml);
            console.log('Final path HTML:', pathHtml);
        });
    }

    // Watch for changes to post slug
    const $postName = $('#post_name');
    $postName.on('input', _.debounce(() => updatePreferredMovePath(), 500));

    // Watch for changes to taxonomy term if it exists
    if (wpMediaOrganiser.settings.taxonomyName) {
        const taxonomyName = wpMediaOrganiser.settings.taxonomyName;
        console.log('Setting up listener for taxonomy:', taxonomyName);

        // Log the initial state of the taxonomy checklist
        const $checklist = $(`#${taxonomyName}checklist`);
        console.log('Found taxonomy checklist:', $checklist.length ? 'yes' : 'no');
        if ($checklist.length) {
            console.log('Checklist HTML:', $checklist.html());
        }

        // Use event delegation for the taxonomy checklist
        $(document).on('change', `#${taxonomyName}checklist input[type="checkbox"]`, function () {
            console.log('Checkbox changed - checked:', this.checked);
            console.log('Checkbox value (term ID):', $(this).val());
            updatePreferredMovePath();
        });
    }

    // Initial update
    updatePreferredMovePath();
}); 