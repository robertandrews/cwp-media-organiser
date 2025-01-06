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

                // Load main notice template
                try {
                    const response = await fetch(`${templatesUrl}/notice.html?_=${Date.now()}`);
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    const content = await response.text();
                    this.templates['notice'] = content;
                    console.log('Loaded notice template');
                } catch (error) {
                    console.error('Failed to load notice template:', error);
                    throw error;
                }

                // Load components
                const components = [
                    'component-title',
                    'component-summary-counts',
                    'component-post-info',
                    'component-media-items-list',
                    'component-thumbnail',
                    'component-media-info',
                    'media-operation/component-operation-text',
                    'media-path/component-path-wrong',
                    'media-path/component-path-preferred-correct',
                    'media-path/component-path-preferred-move',
                    'media-operation/media-operation-preview-correct',
                    'media-operation/media-operation-preview-move'
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

            console.log(`Rendering notice for context: ${context}, type: ${type}`, data);

            try {
                // Add notice display properties
                const renderData = {
                    ...data,
                    notice_type: type,
                    notice_class: type === 'preview' ? 'notice-warning' : 'notice-success',
                    show_summary: context === 'edit.php',
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
                        itemContext.needs_move = !item.paths_match && item.is_preview;
                        itemContext.is_dynamic = item.is_preview;

                        return itemContext;
                    });
                }

                // Pre-render the media items list with the processed items
                console.log('Rendering media items list component with data:', renderData);
                renderData.components['component-media-items-list'] = Mustache.render(this.components['components/component-media-items-list'], renderData);

                // Render the final notice with all components
                const html = Mustache.render(this.templates['notice'], renderData);
                console.log("Successfully rendered notice");
                return html;
            } catch (error) {
                console.error("Error rendering notice:", error);
                throw error;
            }
        }
    };
} 