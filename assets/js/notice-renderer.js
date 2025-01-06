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
                const templatesUrl = window.cwpMediaOrganiser.templatesUrl;

                // Load main notice template
                try {
                    const response = await fetch(`${templatesUrl}/notice.html?_=${Date.now()}`);
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    const content = await response.text();
                    this.templates['notice'] = content;
                } catch (error) {
                    console.error('Failed to load notice template:', error);
                    throw error;
                }

                // Load components
                const components = [
                    'media-operation/component-operation-text',
                    'media-operation/media-operation-preview-correct',
                    'media-operation/media-operation-preview-move'
                ];

                // Load all components
                for (const component of components) {
                    try {
                        const response = await fetch(`${templatesUrl}/components/${component}.html?_=${Date.now()}`);
                        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                        const content = await response.text();
                        this.components[`components/${component}`] = content;
                    } catch (error) {
                        console.error(`Failed to load component template ${component}:`, error);
                        throw error;
                    }
                }

                this.initialized = true;
            } catch (error) {
                console.error('Failed to initialize notice renderer:', error);
                throw error;
            }
        }

        prepareNoticeData(data, context, type) {
            const noticeData = {
                notice_type: type,
                notice_class: type === 'preview' ? 'notice-warning' : 'notice-success',
                show_summary: context === 'edit.php',
                media_items: data.media_items ? data.media_items.map(item => ({
                    media_id: item.id || item.media_id,
                    media_title: item.title || item.media_title || '',
                    media_edit_url: `/wp-admin/post.php?post=${item.id || item.media_id}&action=edit`,
                    thumbnail_url: item.thumbnail_url || '',
                    status: item.status,
                    status_class: item.status.replace('will_', ''),
                    operation_text: wpMediaOrganiser.noticeConfig.operation_text[type === 'preview' ? 'preview' : 'post-save'][item.status],
                    current_path: item.current_path,
                    paths_match: type === 'preview' ? item.status === 'correct' : item.paths_match,
                    is_preview: type === 'preview',
                    post_type: item.post_type,
                    taxonomy: item.taxonomy,
                    term: item.term,
                    year: item.year,
                    month: item.month,
                    post_id: item.post_id,
                    filename: item.filename
                })) : [],
                post_info: data.post_info || {}
            };

            return noticeData;
        }

        renderComponent(name, data) {
            const template = this.components[`components/${name}`];
            if (!template) {
                console.error(`Component template ${name} not found`);
                return '';
            }
            return Mustache.render(template, data);
        }

        async renderNotice(context, type, data) {
            if (!this.initialized) {
                await this.init();
            }

            try {
                // Prepare notice data
                const renderData = this.prepareNoticeData(data, context, type);

                // For each media item, pre-render its components
                if (renderData.media_items) {
                    renderData.media_items = renderData.media_items.map(item => {
                        const itemContext = {
                            ...item,
                            components: {}
                        };

                        // Pre-render each component for this media item
                        itemContext.components['component-thumbnail'] = Mustache.render(this.components['components/component-thumbnail'], item);
                        itemContext.components['component-media-info'] = Mustache.render(this.components['components/component-media-info'], item);
                        itemContext.components['component-operation-text'] = Mustache.render(this.components['components/component-operation-text'], item);

                        // Add path display flags based on status
                        itemContext.show_current_path = !item.paths_match;
                        itemContext.is_correct = item.paths_match;
                        itemContext.needs_move = !item.paths_match && item.is_preview;
                        itemContext.is_dynamic = item.is_preview;

                        return itemContext;
                    });
                }

                // Render the final notice with all components
                const html = Mustache.render(this.templates['notice'], renderData);
                return html;
            } catch (error) {
                console.error("Error rendering notice:", error);
                throw error;
            }
        }
    };
} 