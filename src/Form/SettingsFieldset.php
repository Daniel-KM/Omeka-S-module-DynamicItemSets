<?php declare(strict_types=1);

namespace DynamicItemSets\Form;

use DynamicItemSets\Form\Element as DynamicItemSetsElement;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Dynamic item sets'; // @translate

    protected $elementGroups = [
        'dynamic_item_sets' => 'Dynamic item sets', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'dynamic_item_sets')
            // ->setOption('element_groups', $this->elementGroups)

            ->add([
                'name' => 'dynamicitemsets_item_sets_queries_static',
                'type' => DynamicItemSetsElement\ArrayTextareaQueries::class,
                'options' => [
                    'element_group' => 'editing',
                    'label' => 'Static item sets filled dynamically', // @translateAttach items to items sets according to queries
                    'info' => 'When an item is saved, it will be included in item sets matching queries, separated with a "=". Queries are standard Omeka searches. Unlike dynamic item sets, an item won’t be removed from an item set automatically when the metadata does not match a query any more. Dynamic item sets should not be set here.', // @translate
                    'as_key_value' => true,
                    'remove_arguments_page_and_sort' => true,
                    'remove_arguments_useless' => true,
                ],
                'attributes' => [
                    'id' => 'dynamicitemsets_item_sets_queries_static',
                    'placeholder' => <<<'TXT'
                        17 = resource_class_term=bibo:Book
                        89 = resource_class_term=foaf:Organization
                        TXT,
                    'rows' => 3,
                ],
            ])
        ;
    }
}
