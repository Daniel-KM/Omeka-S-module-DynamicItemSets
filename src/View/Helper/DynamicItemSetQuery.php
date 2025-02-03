<?php declare(strict_types=1);

namespace DynamicItemSets\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Entity\ItemSet;

class DynamicItemSetQuery extends AbstractHelper
{
    /**
     * Check if an item set is dynamic an returns its query as array.
     *
     * @param \Omeka\Api\Representation\ItemSetRepresentation|`int
     * @return array`null
     */
    public function __invoke($itemSetOrId): ?array
    {
        if (is_numeric($itemSetOrId)) {
            $itemSetId = (int) $itemSetOrId;
        } elseif ($itemSetOrId instanceof ItemSetRepresentation) {
            $itemSetId = $itemSetOrId->id();
        } elseif ($itemSetOrId instanceof ItemSet) {
            $itemSetId = $itemSetOrId->getId();
        } else {
            return null;
        }

        $itemSetQueries = $this->getView()->setting('dynamicitemsets_item_set_queries', []);
        return empty($itemSetQueries[$itemSetId])
            ? null
            : $itemSetQueries[$itemSetId];
    }
}
