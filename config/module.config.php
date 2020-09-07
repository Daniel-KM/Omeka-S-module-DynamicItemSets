<?php

namespace AdvancedResourceTemplate;

return [
    'autofillers' => [
        'factories' => [
            Autofiller\IdRefAutofiller::class => Service\Autofiller\AutofillerFactory::class,
        ],
        'aliases' => [
            'idref' => Autofiller\IdRefAutofiller::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            Autofiller\AutofillerPluginManager::class => Service\Autofiller\AutofillerPluginManagerFactory::class,
        ],
        'aliases' => [
            'Autofiller\Manager' => Autofiller\AutofillerPluginManager::class,
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
    'controllers' => [
        'factories' => [
            'AdvancedResourceTemplate\Controller\Admin\Index' => Service\Controller\Admin\IndexControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'fieldNameToProperty' => Mvc\Controller\Plugin\FieldNameToProperty::class,
            'mapper' => Mvc\Controller\Plugin\Mapper::class,
        ],
        'factories' => [
            'mapperHelper' => Service\ControllerPlugin\MapperHelperFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'values' => [
                        'type' => \Zend\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/values',
                            'defaults' => [
                                '__NAMESPACE__' => 'AdvancedResourceTemplate\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'values',
                            ],
                        ],
                    ],
                    'autofiller' => [
                        'type' => \Zend\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/autofiller',
                            'defaults' => [
                                '__NAMESPACE__' => 'AdvancedResourceTemplate\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'autofiller',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'settings' => [
                                'type' => \Zend\Router\Http\Literal::class,
                                'options' => [
                                    'route' => '/settings',
                                    'defaults' => [
                                        'action' => 'autofillerSettings',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
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
    'js_translate_strings' => [
        'New item', // @translate
        'New item set', // @translate
    ],
    'advancedresourcetemplate' => [
        'settings' => [
            // The default autofillers are in /data/mapping/mappings.ini.
            'advancedresourcetemplate_autofillers' => [],
        ],
    ],
];
