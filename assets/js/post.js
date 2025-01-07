jQuery(document).ready(function ($) {
    console.log('WP Media Organiser post.js loaded');
    console.log('Settings:', wpMediaOrganiser);
    console.log('Post Identifier Setting:', wpMediaOrganiser.settings.postIdentifier);

    // Store initial states
    let initialSlug = $('#editable-post-name-full').text();
    let initialTaxonomyTermId = '';
    if (wpMediaOrganiser.settings.taxonomyName) {
        const $checked = $(`#${wpMediaOrganiser.settings.taxonomyName}checklist input[type="checkbox"]:checked`);
        initialTaxonomyTermId = $checked.length ? $checked.val() : '';
    }
    console.log('Initial states - Slug:', initialSlug, 'Taxonomy Term ID:', initialTaxonomyTermId);

    // Store the original templates
    let correctTemplate = '';
    let moveTemplate = '';

    // Store complete media operation templates
    console.log('Looking for media operation templates...');
    $('.media-operation').each(function () {
        console.log('Found media-operation:', $(this).html());
        if ($(this).find('.path-preferred-correct').length) {
            correctTemplate = $(this).html();
            console.log('Stored correct template:', correctTemplate);

            // Create move template from correct template
            moveTemplate = correctTemplate
                .replace('operation-text correct">Already in correct location:', 'operation-text move">Will move to:')
                .replace('path-preferred-correct', 'path-preferred-move')
                .replace('dashicons-yes-alt correct', 'dashicons-arrow-right-alt move');
            console.log('Created move template:', moveTemplate);
        }
    });

    async function updatePathDisplayState() {
        // Check if we're in a different state from initial
        const currentSlug = $('#editable-post-name-full').text();
        let currentTaxonomyTermId = '';
        if (wpMediaOrganiser.settings.taxonomyName) {
            const $checked = $(`#${wpMediaOrganiser.settings.taxonomyName}checklist input[type="checkbox"]:checked`);
            currentTaxonomyTermId = $checked.length ? $checked.val() : '';
        }

        const hasChanged = (currentSlug !== initialSlug || currentTaxonomyTermId !== initialTaxonomyTermId);
        console.log('State check - Changed:', hasChanged, 'Current Slug:', currentSlug, 'Current Term:', currentTaxonomyTermId);

        // Prepare the new template content
        let newTemplate = hasChanged ? moveTemplate : correctTemplate;

        // If we're showing the move template, update the path immediately
        if (hasChanged) {
            const $tempDiv = $('<div>').html(newTemplate);
            const $pathElement = $tempDiv.find('.path-preferred-move');

            // Update taxonomy and term if present
            if (currentTaxonomyTermId) {
                const termSlug = await getTaxonomyValue(wpMediaOrganiser.settings.taxonomyName);
                if (termSlug) {
                    // First remove any existing taxonomy/term spans
                    let pathHtml = $pathElement.html();
                    pathHtml = pathHtml.replace(/\/(<span[^>]*class="[^"]*path-taxonomy[^"]*"[^>]*>[^<]*<\/span>)\/(<span[^>]*class="[^"]*path-term[^"]*"[^>]*>[^<]*<\/span>)\//, '/');
                    $pathElement.html(pathHtml);

                    // Then insert the new taxonomy/term after post-type
                    pathHtml = $pathElement.html().replace(/\/(<span[^>]*class="[^"]*path-post-type[^"]*"[^>]*>[^<]*<\/span>)\//,
                        '/$1/<span class="path-component path-taxonomy">client</span>/<span class="path-component path-term">' + termSlug + '</span>/');
                    $pathElement.html(pathHtml);
                }
            } else {
                // Remove taxonomy and term if no term selected
                let pathHtml = $pathElement.html();
                pathHtml = pathHtml.replace(/\/(<span[^>]*class="[^"]*path-taxonomy[^"]*"[^>]*>[^<]*<\/span>)\/(<span[^>]*class="[^"]*path-term[^"]*"[^>]*>[^<]*<\/span>)\//, '/');
                $pathElement.html(pathHtml);
            }

            // Update post identifier
            $pathElement.find('.path-post-identifier').text(currentSlug);

            // Get the updated HTML
            newTemplate = $tempDiv.html();
        }

        // Switch templates based on state
        console.log('Looking for media operations to update...');
        $('.media-operation').each(function () {
            const $operation = $(this);
            console.log('Found media operation:', $operation.html());
            $operation.html(newTemplate);
        });
    }

    // Watch for changes to the editable post slug if settings allow
    if (wpMediaOrganiser.settings.postIdentifier === 'slug') {
        console.log('Post slug setting enabled, setting up delegated listener');

        // Use event delegation for the dynamically added slug input
        $(document).on('input', '#new-post-slug', function () {
            const newSlug = $(this).val();
            console.log('Slug changed to:', newSlug);
            $('.path-component.path-post-identifier').each(function () {
                $(this).text(newSlug);
            });
            updatePathDisplayState();
        });

        // Handle escape key (cancel) and restore original slug
        $(document).on('keydown', '#new-post-slug', function (e) {
            if (e.key === 'Escape') {
                const originalSlug = $('#editable-post-name-full').text();
                console.log('Escape pressed, reverting to original slug:', originalSlug);
                $('.path-component.path-post-identifier').each(function () {
                    $(this).text(originalSlug);
                });
                updatePathDisplayState();
            }
        });
    }

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
                // Update the path with the new taxonomy term
                const $taxonomySpan = $path.find('.path-component.path-taxonomy');
                const $termSpan = $path.find('.path-component.path-term');
                if ($taxonomySpan.length && $termSpan.length) {
                    $taxonomySpan.text('client');
                    $termSpan.text(taxonomyTerm);
                    console.log('Updated taxonomy term to:', taxonomyTerm);
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
            updatePathDisplayState();
            // Trigger preview update if the function exists
            if (typeof updatePreviewPaths === 'function') {
                updatePreviewPaths();
            }
        });
    }

    // Initial update
    updatePreferredMovePath();
}); 