if (typeof window.NoticeRenderer === 'undefined') {
    window.NoticeRenderer = class {
        constructor() {
            this.templates = {};
            this.components = {};
            this.initialized = false;
        }

        async init() {
            if (this.initialized) return;

            try {
                // Get templates URL from PHP
                const templatesUrl = window.cwpMediaOrganiser.templatesUrl;
                console.log('Loading templates from:', templatesUrl);

                // Load variants
                const variants = ['variant-post-pre-save', 'variant-post-after-save', 'variant-list-after-save'];
                for (const variant of variants) {
                    try {
                        const response = await fetch(`${templatesUrl}/variants/${variant}.html?_=${Date.now()}`);
                        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                        const content = await response.text();
                        this.templates[`variants/${variant}`] = content;
                        console.log(`Loaded variant template: ${variant}`);
                    } catch (error) {
                        console.error(`Failed to load variant template ${variant}:`, error);
                        throw error;
                    }
                }

                // Load components
                const components = [
                    'component-title',
                    'component-summary-counts',
                    'component-post-info',
                    'component-media-items-list',
                    'component-thumbnail',
                    'component-media-info',
                    'component-operation-text',
                    'component-path-display',
                    'component-path-wrong',
                    'component-path-preferred-correct',
                    'component-path-preferred-move',
                    'component-error-message'
                ];

                // Load all components first
                for (const component of components) {
                    try {
                        const response = await fetch(`${templatesUrl}/components/${component}.html?_=${Date.now()}`);
                        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                        const content = await response.text();
                        this.components[`components/${component}`] = content;
                        console.log(`Loaded component template: ${component}`);
                    } catch (error) {
                        console.error(`Failed to load component template ${component}:`, error);
                        throw error;
                    }
                }

                this.initialized = true;
                console.log('Notice renderer initialization complete');
            } catch (error) {
                console.error('Failed to initialize notice renderer:', error);
                throw error;
            }
        }

        renderComponent(name, data) {
            console.log(`Rendering component ${name} with data:`, data);
            const template = this.components[`components/${name}`];
            if (!template) {
                console.error(`Component template ${name} not found`);
                return '';
            }
            return Mustache.render(template, data);
        }

        async renderNotice(context, type, data) {
            if (!this.initialized) {
                console.log('Initializing notice renderer before rendering...');
                await this.init();
            }

            const variant = this.getVariantTemplate(context, type);
            if (!variant) {
                console.log(`No variant template found for context: ${context}, type: ${type}`);
                return ''; // No notice for this combination
            }

            console.log(`Rendering notice with variant: ${variant}`, data);

            try {
                // Add notice type to data
                const renderData = {
                    ...data,
                    notice_type: type.charAt(0).toUpperCase() + type.slice(1),
                    components: {}
                };

                // Pre-render components that don't need media item context
                console.log('Rendering title component with data:', renderData);
                renderData.components['component-title'] = Mustache.render(this.components['components/component-title'], renderData);

                console.log('Rendering summary counts component with data:', renderData);
                renderData.components['component-summary-counts'] = Mustache.render(this.components['components/component-summary-counts'], renderData);

                console.log('Rendering post info component with data:', renderData);
                renderData.components['component-post-info'] = Mustache.render(this.components['components/component-post-info'], renderData);

                // For each media item, pre-render its components
                if (renderData.media_items) {
                    renderData.media_items = renderData.media_items.map(item => {
                        console.log('Processing media item:', item);
                        const itemContext = {
                            ...item,
                            components: {}
                        };

                        // Pre-render each component for this media item
                        console.log('Rendering thumbnail component with data:', item);
                        itemContext.components['component-thumbnail'] = Mustache.render(this.components['components/component-thumbnail'], item);

                        console.log('Rendering media info component with data:', item);
                        itemContext.components['component-media-info'] = Mustache.render(this.components['components/component-media-info'], item);

                        console.log('Rendering operation text component with data:', item);
                        itemContext.components['component-operation-text'] = Mustache.render(this.components['components/component-operation-text'], item);

                        // Add path display flags based on status
                        itemContext.show_current_path = !item.paths_match;
                        itemContext.is_correct = item.paths_match;
                        itemContext.needs_move = !item.paths_match && item.is_pre_save;
                        itemContext.is_dynamic = item.is_pre_save;

                        console.log('Rendering path display component with data:', itemContext);
                        itemContext.components['component-path-display'] = Mustache.render(this.components['components/component-path-display'], itemContext);

                        return itemContext;
                    });
                }

                // Pre-render the media items list with the processed items
                console.log('Rendering media items list component with data:', renderData);
                renderData.components['component-media-items-list'] = Mustache.render(this.components['components/component-media-items-list'], renderData);

                // Render the final variant with all components
                console.log('Rendering final variant with data:', renderData);
                const html = Mustache.render(this.templates[variant], renderData);
                console.log('Successfully rendered notice');
                return html;
            } catch (error) {
                console.error('Error rendering notice:', error);
                throw error;
            }
        }

        getVariantTemplate(context, type) {
            if (context === 'post.php') {
                return type === 'pre-save' ? 'variants/variant-post-pre-save' : 'variants/variant-post-after-save';
            } else if (context === 'edit.php' && type === 'post-save') {
                return 'variants/variant-list-after-save';
            }
            return null;
        }
    };
} 