<?php

if (!defined('WPINC')) {
    die;
}

class CWP_Media_Organiser_Notice_Config
{
    /**
     * Get configuration for JavaScript
     */
    public static function get_js_config()
    {
        return array(
            'status_types' => array(
                'correct' => array(
                    'dot_class' => 'status-dot-correct',
                    'operation_class' => 'operation-correct',
                ),
                'move' => array(
                    'dot_class' => 'status-dot-moved',
                    'operation_class' => 'operation-move',
                ),
                'fail' => array(
                    'dot_class' => 'status-dot-failed',
                    'operation_class' => 'operation-fail',
                ),
                'skip' => array(
                    'dot_class' => 'status-dot-skipped',
                    'operation_class' => 'operation-skip',
                ),
            ),
            'operation_text' => array(
                'pre-save' => array(
                    'correct' => 'Already in correct location:',
                    'move' => 'Will move from',
                    'fail' => 'Cannot move from',
                    'skip' => 'Will skip:',
                ),
                'post-save' => array(
                    'correct' => 'Already in correct location:',
                    'move' => 'Moved from',
                    'fail' => 'Failed to move from',
                    'skip' => 'Skipped:',
                ),
            ),
        );
    }
}
