<?php declare(strict_types=1);

namespace DynamicItemSets\ColumnType;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\ColumnType\ColumnTypeInterface;

class IsDynamic implements ColumnTypeInterface
{
    public function getLabel() : string
    {
        return 'Is dynamic'; // @translate
    }

    public function getResourceTypes() : array
    {
        return [
            'item_sets',
        ];
    }

    public function getMaxColumns() : ?int
    {
        return 1;
    }

    public function renderDataForm(PhpRenderer $view, array $data) : string
    {
        return '';
    }

    /**
     * @todo Managed sort by is_dynamic.
     *
     * {@inheritDoc}
     * @see \Omeka\ColumnType\ColumnTypeInterface::getSortBy()
     */
    public function getSortBy(array $data) : ?string
    {
        return 'is_dynamic';
    }

    public function renderHeader(PhpRenderer $view, array $data) : string
    {
        return $this->getLabel();
    }

    public function renderContent(PhpRenderer $view, AbstractEntityRepresentation $resource, array $data) : ?string
    {
        /**
         * @var \DynamicItemSets\View\Helper\DynamicItemSetQuery $dynamicItemSetQuery
         */
        $plugins = $view->getHelperPluginManager();
        $translate = $plugins->get('translate');
        $dynamicItemSetQuery = $plugins->get('dynamicItemSetQuery');
        return $dynamicItemSetQuery($resource)
            ? $translate('Yes')
            : $translate('No');
    }
}
