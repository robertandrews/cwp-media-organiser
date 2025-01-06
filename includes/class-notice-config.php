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
                    'operation_class' => 'operation-correct',
                ),
                'move' => array(
                    'operation_class' => 'operation-move',
                ),
                'fail' => array(
                    'operation_class' => 'operation-fail',
                ),
                'skip' => array(
                    'operation_class' => 'operation-skip',
                ),
            ),
            'operation_text' => array(
                'preview' => array(
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
