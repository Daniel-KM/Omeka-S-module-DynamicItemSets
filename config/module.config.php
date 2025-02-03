<?php declare(strict_types=1);

namespace DynamicItemSets;

return [
    'view_helpers' => [
        'invokables' => [
            'dynamicItemSetQuery' => View\Helper\DynamicItemSetQuery::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'dynamicitemsets' => [
        'settings' => [
            // Hidden settings.
            'dynamicitemsets_item_set_queries' => [],
        ],
    ],
];
