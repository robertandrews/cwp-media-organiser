<?php

if (!defined('WPINC')) {
    die;
}

class CWP_Media_Organiser_Notice_Config
{
    // Status types and their corresponding classes
    const STATUS_TYPES = [
        'correct' => [
            'dot_class' => 'status-dot-correct',
            'operation_class' => 'operation-correct',
            'color' => '#46b450',
        ],
        'move' => [
            'dot_class' => 'status-dot-moved',
            'operation_class' => 'operation-move',
            'color' => '#ffb900',
        ],
        'fail' => [
            'dot_class' => 'status-dot-failed',
            'operation_class' => 'operation-fail',
            'color' => '#dc3232',
        ],
        'skip' => [
            'dot_class' => 'status-dot-skipped',
            'operation_class' => 'operation-skip',
            'color' => '#888888',
        ],
    ];

    // Operation text templates
    const OPERATION_TEXT = [
        'pre-save' => [
            'correct' => 'Already in correct location:',
            'move' => 'Will move from',
            'fail' => 'Cannot move from',
            'skip' => 'Will skip:',
        ],
        'post-save' => [
            'correct' => 'Already in correct location:',
            'move' => 'Moved from',
            'fail' => 'Failed to move from',
            'skip' => 'Skipped:',
        ],
    ];

    // Get the configuration as JSON for JavaScript
    public static function get_js_config()
    {
        return [
            'status_types' => self::STATUS_TYPES,
            'operation_text' => self::OPERATION_TEXT,
        ];
    }
}
