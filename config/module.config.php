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
    'form_elements' => [
        'invokables' => [
            Form\Element\ArrayTextareaQueries::class => Form\Element\ArrayTextareaQueries::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
    ],
    'column_types' => [
        'invokables' => [
            'is_dynamic' => ColumnType\IsDynamic::class,
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
        'config' => [
            // Hidden settings.
            'dynamicitemsets_item_sets_queries_dynamic' => [],
        ],
        'settings' => [
            'dynamicitemsets_item_sets_queries_static' => [],
        ],
    ],
];
