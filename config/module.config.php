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
                'type' => \Laminas\I18n\Translator\Loader\Gettext::class,
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
    // Rules of the background jobs for the module Job Queue, used only when it
    // is installed: merge the jobs dispatched again before they start and limit
    // the number of heavy jobs running at the same time.
    'jobqueue' => [
        'jobs' => [
            'DynamicItemSets\Job\AttachItemsToItemSets' => [
                'merge' => ['item_set_ids'],
            ],
        ],
    ],
];
