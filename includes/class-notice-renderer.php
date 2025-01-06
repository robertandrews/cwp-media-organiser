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
    private $initialized = false;

    private function __construct()
    {
        $this->logger = WP_Media_Organiser_Logger::get_instance();
        $this->logger->log("=== Initializing Notice Renderer ===", 'debug');

        $templates_dir = plugin_dir_path(dirname(__FILE__)) . 'templates/notice';
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
            'cache' => new Mustache_Cache_NoopCache(),
        ]);

        $this->initialize();
    }

    private function initialize()
    {
        if ($this->initialized) {
            return;
        }

        $this->logger->log("=== Starting initialize ===", 'debug');

        // Log available templates and components
        $templates_dir = plugin_dir_path(dirname(__FILE__)) . 'templates/notice';
        $this->logger->log("Available templates and components:", 'debug');
        foreach (glob($templates_dir . '/*.html') as $file) {
            $this->logger->log("  Template: " . basename($file), 'debug');
        }
        foreach (glob($templates_dir . '/components/*.html') as $file) {
            $this->logger->log("  Component: " . basename($file), 'debug');
        }

        $this->initialized = true;
        $this->logger->log("=== Initialization complete ===", 'debug');
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
        $this->logger->log("=== Starting render_notice ===", 'debug');
        $this->logger->log("Context: $context", 'debug');
        $this->logger->log("Type: $type", 'debug');
        $this->logger->log("Data: " . print_r($data, true), 'debug');

        // Set notice display properties based on context and type
        $data['notice_class'] = $type === 'preview' ? 'notice-warning' : 'notice-success';
        $data['notice_type'] = $type;
        $data['show_summary'] = $context === 'edit.php';

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
                    $item['components']['component-media-info'] = $this->mustache->render('components/component-media-info', $item);
                    $item['components']['component-operation-text'] = $this->mustache->render('components/component-operation-text', $item);

                    // Pre-render path components
                    $item['components']['component-path-wrong'] = $this->mustache->render('components/media-path/component-path-wrong', $item);
                    $item['components']['component-path-preferred-move'] = $this->mustache->render('components/media-path/component-path-preferred-move', $item);
                    $item['components']['component-path-preferred-correct'] = $this->mustache->render('components/media-path/component-path-preferred-correct', $item);

                    // Add path display flags based on status
                    $item['show_current_path'] = !$item['paths_match'];
                    $item['is_correct'] = $item['paths_match'];
                    $item['needs_move'] = !$item['paths_match'] && isset($item['is_preview']) && $item['is_preview'];
                    $item['is_dynamic'] = isset($item['is_preview']) && $item['is_preview'];
                }
            }

            // Pre-render the media items list with the processed items
            $data['components']['component-media-items-list'] = $this->mustache->render('components/component-media-items-list', $data);

            // Render the final notice
            $html = $this->mustache->render('notice', $data);
            $this->logger->log("Successfully rendered notice", 'debug');
            $this->logger->log("HTML output: " . $html, 'debug');
            return $html;
        } catch (Exception $e) {
            $this->logger->log("Error rendering notice: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    public function get_templates_url()
    {
        return plugin_dir_url(dirname(__FILE__)) . 'templates/notice';
    }
}
