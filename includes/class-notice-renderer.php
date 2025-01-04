<?php

if (!defined('WPINC')) {
    die;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'vendor/autoload.php';

class CWP_Media_Organiser_Notice_Renderer
{
    private $mustache;
    private static $instance = null;
    private $logger;

    private function __construct()
    {
        $this->logger = WP_Media_Organiser_Logger::get_instance();
        $this->logger->log("=== Initializing Notice Renderer ===", 'debug');

        $templates_dir = plugin_dir_path(dirname(__FILE__)) . 'templates/notices';
        $this->logger->log("Templates directory: " . $templates_dir, 'debug');

        $this->mustache = new Mustache_Engine([
            'loader' => new Mustache_Loader_FilesystemLoader(
                $templates_dir,
                ['extension' => '.html']
            ),
            'partials_loader' => new Mustache_Loader_FilesystemLoader(
                $templates_dir,
                ['extension' => '.html']
            ),
            'escape' => function ($value) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            },
        ]);

        // Log available templates
        $this->logger->log("Available templates:", 'debug');
        foreach (glob($templates_dir . '/variants/*.html') as $file) {
            $this->logger->log("  Variant: " . basename($file), 'debug');
        }
        foreach (glob($templates_dir . '/components/*.html') as $file) {
            $this->logger->log("  Component: " . basename($file), 'debug');
        }
    }

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function render_notice($context, $type, $data)
    {
        $this->logger->log("=== Rendering Notice ===", 'debug');
        $this->logger->log("Context: " . $context, 'debug');
        $this->logger->log("Type: " . $type, 'debug');
        $this->logger->log("Data: " . print_r($data, true), 'debug');

        // Determine which variant to use based on context and type
        $variant = $this->get_variant_template($context, $type);
        if (!$variant) {
            $this->logger->log("No variant template found for context: $context, type: $type", 'debug');
            return ''; // No notice for this combination (e.g., edit.php pre-save)
        }

        $this->logger->log("Using variant template: " . $variant, 'debug');

        try {
            // Pre-render components that don't need media item context
            $data['components'] = array();
            $data['components']['component-title'] = $this->mustache->render('components/component-title', $data);
            $data['components']['component-summary-counts'] = $this->mustache->render('components/component-summary-counts', $data);
            $data['components']['component-post-info'] = $this->mustache->render('components/component-post-info', $data);

            // For each media item, pre-render its components
            if (!empty($data['media_items'])) {
                foreach ($data['media_items'] as &$item) {
                    $item['components'] = array();
                    $item['components']['component-thumbnail'] = $this->mustache->render('components/component-thumbnail', $item);
                    $item['components']['component-operation-status'] = $this->mustache->render('components/component-operation-status', $item);

                    if ($item['paths_match']) {
                        $item['components']['component-path-display-static'] = $this->mustache->render('components/component-path-display-static', $item);
                    } else {
                        if ($item['is_pre_save']) {
                            $item['components']['component-path-display-dynamic'] = $this->mustache->render('components/component-path-display-dynamic', $item);
                        } else {
                            $item['components']['component-path-display-static'] = $this->mustache->render('components/component-path-display-static', $item);
                        }
                    }
                }
            }

            // Pre-render the media items list with the processed items
            $data['components']['component-media-items-list'] = $this->mustache->render('components/component-media-items-list', $data);

            // Render the final variant with all components
            $html = $this->mustache->render($variant, $data);
            $this->logger->log("Successfully rendered notice", 'debug');
            $this->logger->log("HTML output: " . $html, 'debug');
            return $html;
        } catch (Exception $e) {
            $this->logger->log("Error rendering notice: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    private function get_variant_template($context, $type)
    {
        if ($context === 'post.php') {
            return $type === 'pre-save' ? 'variants/variant-post-pre-save' : 'variants/variant-post-after-save';
        } elseif ($context === 'edit.php' && $type === 'post-save') {
            return 'variants/variant-list-after-save';
        }
        return null;
    }

    public function get_templates_url()
    {
        return plugin_dir_url(dirname(__FILE__)) . 'templates/notices';
    }
}
