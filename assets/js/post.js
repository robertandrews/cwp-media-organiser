jQuery(document).ready(function ($) {
    console.log('WP Media Organiser post.js loaded');
    console.log('Settings:', wpMediaOrganiser);
    console.log('Post Identifier Setting:', wpMediaOrganiser.settings.postIdentifier);

    // Store initial states
    let initialSlug = $('#editable-post-name-full').text();
    let initialTaxonomyTermId = '';
    let initialTaxonomyTermName = '';
    if (wpMediaOrganiser.settings.taxonomyName) {
        const $checked = $(`#${wpMediaOrganiser.settings.taxonomyName}checklist input[type="checkbox"]:checked`);
        initialTaxonomyTermId = $checked.length ? $checked.val() : '';

        // Also store initial non-hierarchical term if present
        const $tagDiv = $(`#tagsdiv-${wpMediaOrganiser.settings.taxonomyName}`);
        if ($tagDiv.length) {
            const termText = $tagDiv.find('.tagchecklist li').first().clone()
                .children()
                .remove()
                .end()
                .text()
                .trim();
            initialTaxonomyTermName = termText || '';
        }
    }
    console.log('Initial states - Slug:', initialSlug, 'Taxonomy Term ID:', initialTaxonomyTermId, 'Term Name:', initialTaxonomyTermName);

    async function updatePreferredMovePath() {
        // Get the current post slug
        const postSlug = $('#post_name').val() || $('#editable-post-name-full').text();
        console.log('Current post slug:', postSlug);

        // Get the selected taxonomy term (if taxonomy is enabled)
        let taxonomyTerm = '';
        if (wpMediaOrganiser.settings.taxonomyName) {
            taxonomyTerm = await getTaxonomyValue(wpMediaOrganiser.settings.taxonomyName);
            console.log('Selected taxonomy term slug:', taxonomyTerm);
        }

        // Update all .path-preferred-move elements
        $('.path-preferred-move, .path-preferred-correct').each(function () {
            const $path = $(this);
            const $operation = $path.closest('.media-operation');
            const $pathDisplay = $operation.find('.path-display');
            const mediaId = $path.data('media-id');
            console.log('Processing path for media ID:', mediaId);

            // Store original filename from the path
            const pathText = $path.html();
            const filenameMatch = pathText.match(/[^\/]+$/);
            if (!filenameMatch) return;

            const originalFilename = filenameMatch[0];
            console.log('Original filename:', originalFilename);

            // Get the current path for wrong display
            const currentPath = $pathDisplay.find('.path-wrong del').text() ||
                $path.text().replace(/^[^\/]*\//, '/');

            // Function to normalize path for comparison
            function normalizePath(path) {
                return path.replace(/^[^\/]*\//, '/').replace(/\/+/g, '/');
            }

            // Get the original path components
            const originalPath = $pathDisplay.data('original-path') || currentPath;
            console.log('Original path:', originalPath);

            if (!taxonomyTerm) {
                // Remove taxonomy and term parts for zero-term scenario
                let pathHtml = $path.html();
                pathHtml = pathHtml.replace(/\/(<span[^>]*class="[^"]*path-taxonomy[^"]*"[^>]*>[^<]*<\/span>)\/(<span[^>]*class="[^"]*path-term[^"]*"[^>]*>[^<]*<\/span>)\//, '/');
                $path.html(pathHtml);
                console.log('Removed taxonomy and term spans with slashes for media ID:', mediaId);
            } else {
                // Update the path with the new taxonomy term
                const $taxonomySpan = $path.find('.path-component.path-taxonomy');
                const $termSpan = $path.find('.path-component.path-term');

                if (!$taxonomySpan.length || !$termSpan.length) {
                    let pathHtml = $path.html();
                    pathHtml = pathHtml.replace(/\/(<span[^>]*class="[^"]*path-post-type[^"]*"[^>]*>[^<]*<\/span>)\//,
                        `/$1/<span class="path-component path-taxonomy" data-media-id="${mediaId}">${wpMediaOrganiser.settings.taxonomyName}</span>/<span class="path-component path-term" data-media-id="${mediaId}">${taxonomyTerm}</span>/`);
                    $path.html(pathHtml);
                } else {
                    $taxonomySpan.text(wpMediaOrganiser.settings.taxonomyName);
                    $termSpan.text(taxonomyTerm);
                }
                console.log('Updated taxonomy term to:', taxonomyTerm, 'for media ID:', mediaId);
            }

            // Update post identifier if it's a slug
            const $postIdSpan = $path.find('.path-component.path-post-identifier');
            if ($postIdSpan.length && wpMediaOrganiser.settings.postIdentifier === 'slug') {
                $postIdSpan.text(postSlug || '');
                console.log('Updated post identifier to:', postSlug, 'for media ID:', mediaId);
            }

            // Ensure the original filename is preserved
            let pathHtml = $path.html();
            pathHtml = pathHtml.replace(/[^\/]+$/, originalFilename);

            // Clean up any remaining multiple slashes
            pathHtml = pathHtml.replace(/\/+/g, '/');
            $path.html(pathHtml);

            // Store the original path if not already stored
            if (!$pathDisplay.data('original-path')) {
                $pathDisplay.data('original-path', originalPath);
            }

            // Compare current path with original path
            const currentNormalizedPath = normalizePath($path.text());
            const originalNormalizedPath = normalizePath(originalPath);
            const hasChanged = currentNormalizedPath !== originalNormalizedPath;

            console.log('Path comparison:', {
                current: currentNormalizedPath,
                original: originalNormalizedPath,
                hasChanged: hasChanged
            });

            if (hasChanged) {
                $operation.find('.operation-text').text('Will move to preferred path').addClass('move');
                $path.removeClass('path-preferred-correct').addClass('path-preferred-move');

                // Add or update wrong path
                let $pathWrong = $pathDisplay.find('.path-wrong');

                if (!$pathWrong.length) {
                    $pathWrong = $(`<code class="path-wrong" data-media-id="${mediaId}"><span class="dashicons dashicons-dismiss fail"></span><del>${originalPath}</del></code>`);
                    $pathDisplay.prepend($pathWrong);
                }
            } else {
                $operation.find('.operation-text').text('Already in correct location').removeClass('move');
                $path.removeClass('path-preferred-move').addClass('path-preferred-correct');
                $operation.find('.path-wrong').remove();
            }

            console.log('Final path HTML for media ID', mediaId, ':', pathHtml);
        });
    }

    // Watch for changes to the editable post slug if settings allow
    if (wpMediaOrganiser.settings.postIdentifier === 'slug') {
        console.log('Post slug setting enabled, setting up delegated listener');

        $(document).on('input', '#new-post-slug, #post_name', function () {
            const newSlug = $(this).val();
            console.log('Slug changed to:', newSlug);
            $('#editable-post-name-full').text(newSlug);
            updatePreferredMovePath();
        });

        $(document).on('keydown', '#new-post-slug, #post_name', function (e) {
            if (e.key === 'Escape') {
                const originalSlug = initialSlug;
                console.log('Escape pressed, reverting to original slug:', originalSlug);
                $(this).val(originalSlug);
                $('#editable-post-name-full').text(originalSlug);
                updatePreferredMovePath();
            }
        });
    }

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

        // Use event delegation for the hierarchical taxonomy checklist
        $(document).on('change', `#${taxonomyName}checklist input[type="checkbox"]`, function () {
            console.log('Checkbox changed - checked:', this.checked);
            console.log('Checkbox value (term ID):', $(this).val());
            updatePreferredMovePath();
            if (typeof updatePreviewPaths === 'function') {
                updatePreviewPaths();
            }
        });

        // Handle non-hierarchical taxonomies
        const $tagDiv = $(`#tagsdiv-${taxonomyName}`);
        if ($tagDiv.length) {
            console.log('Found non-hierarchical taxonomy interface');

            // Create a debounced version of the update function
            const debouncedUpdate = _.debounce(() => {
                console.log('Running debounced update');
                updatePreferredMovePath();
                if (typeof updatePreviewPaths === 'function') {
                    updatePreviewPaths();
                }
            }, 250);

            const tagObserver = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    console.log('Mutation detected:', mutation.type, mutation.target);

                    // Check if this is a relevant change
                    if (mutation.type === 'childList' ||
                        (mutation.type === 'characterData' && mutation.target.parentNode.closest('.tagchecklist')) ||
                        (mutation.type === 'attributes' && mutation.target.matches('.the-tags'))) {

                        console.log('Tag checklist mutation detected:', mutation.type);
                        debouncedUpdate();
                    }
                });
            });

            // Observe both the tagchecklist and the hidden input
            const tagChecklist = $tagDiv.find('.tagchecklist')[0];
            const hiddenInput = $tagDiv.find('.the-tags')[0];

            if (tagChecklist) {
                tagObserver.observe(tagChecklist, {
                    childList: true,
                    subtree: true,
                    characterData: true
                });
                console.log('Started observing tagchecklist for changes');
            }

            if (hiddenInput) {
                tagObserver.observe(hiddenInput, {
                    attributes: true,
                    characterData: true
                });
                console.log('Started observing hidden input for changes');
            }

            // Also listen for the WordPress term-added event
            $(document).on('wpTagsSuggestResults', function () {
                console.log('WordPress term-added event detected');
                debouncedUpdate();
            });
        }
    }

    // Helper function to get taxonomy term slug
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
        const $checked = $(`#${taxonomyName}checklist input[type="checkbox"]:checked`);
        console.log(`Found ${$checked.length} checked checkboxes`);

        // Check for non-hierarchical taxonomy first
        const $tagDiv = $(`#tagsdiv-${taxonomyName}`);
        if ($tagDiv.length) {
            // Get the most recently added term from the tagchecklist
            const $tagChecklist = $tagDiv.find('.tagchecklist li').first();
            if ($tagChecklist.length) {
                const termText = $tagChecklist.clone()
                    .children()
                    .remove()
                    .end()
                    .text()
                    .trim();

                console.log('Found term text:', termText);

                if (termText) {
                    // First try to get the term ID from the hidden input
                    const hiddenInput = $tagDiv.find('.the-tags').val();
                    console.log('Hidden input value:', hiddenInput);

                    if (hiddenInput) {
                        // Get all term IDs
                        const termIds = hiddenInput.split(',').filter(id => id.trim());
                        console.log('Term IDs from hidden input:', termIds);

                        if (termIds.length > 0) {
                            // Get the most recently added term ID
                            const termId = termIds[termIds.length - 1];
                            const termSlug = await getTermSlug(termId);
                            if (termSlug) {
                                console.log('Got non-hierarchical term slug from hidden input:', termSlug);
                                return termSlug;
                            }
                        }
                    }

                    // If we couldn't get the term from the hidden input, try by name
                    try {
                        const response = await $.ajax({
                            url: wpMediaOrganiser.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wp_media_organiser_get_term_by_name',
                                nonce: wpMediaOrganiser.nonce,
                                term_name: termText,
                                taxonomy: taxonomyName
                            }
                        });

                        console.log('Term by name response:', response);

                        if (response.success && response.data && response.data.slug) {
                            console.log('Got term slug by name:', response.data.slug);
                            return response.data.slug;
                        }
                    } catch (error) {
                        console.log('AJAX error getting term slug by name:', error);
                    }

                    // If we still don't have a slug, try using the term text as a fallback
                    console.log('Using sanitized term text as fallback');
                    return termText.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
                }
            }
            return '';
        }

        // Fall back to hierarchical taxonomy handling
        if (!$checked.length) {
            console.log('No checked checkbox found');
            return '';
        }

        const termId = $checked.val();
        console.log('Found checked term ID:', termId);

        if (!termId) return '';

        const termSlug = await getTermSlug(termId);
        console.log('Got term slug:', termSlug);
        return termSlug;
    }
}); 