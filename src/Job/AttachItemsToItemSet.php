<?php declare(strict_types=1);

namespace DynamicItemSets\Job;

use Omeka\Job\AbstractJob;

class AttachItemsToItemSet extends AbstractJob
{
    public function perform(): void
    {
        /**
         * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Settings\Settings $settings
         * @var \Laminas\Log\Logger $logger
         */
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');
        $logger = $services->get('Omeka\Logger');

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('dynis/attach_to_itemset/job_' . $this->job->getId());
        $logger->addProcessor($referenceIdProcessor);

        $itemSetId = (int) $this->getArg('item_set_id');
        if (!$itemSetId) {
            $logger->err('No item set defined.'); // @translate
            return;
        }

        try {
            $api->read('item_sets', ['id' => $itemSetId]);
        } catch (\Exception $e) {
            $logger->err(
                'The item set #{item_set_id} does not exist.', // @translate
                ['item_set_id' => $itemSetId]
            );
            return;
        }

        $queries = $settings->get('dynamicitemsets_item_set_queries') ?: [];
        $query = $queries[$itemSetId] ?? null;

        if (!$query) {
            $logger->notice(
                'The item set #{item_set_id} has no more query and items attached to it are kept.', // @translate
                ['item_set_id' => $itemSetId]
            );
            return;
        }

        $existingItemIds = $api->search('items', ['item_set_id' => $itemSetId], ['returnScalar' => 'id'])->getContent();
        $newItemIds = $query ? $api->search('items', $query, ['returnScalar' => 'id'])->getContent() : [];

        // Batch update the resources in chunks.

        // Detach all items that are not in new items.
        $detachItemIds = array_diff($existingItemIds, $newItemIds);
        if ($detachItemIds) {
            $i = 0;
            foreach (array_chunk($detachItemIds, 100) as $idsChunk) {
                if ($this->shouldStop()) {
                    return;
                }
                $api->batchUpdate('items', $idsChunk, ['o:item_set' => [$itemSetId]], ['continueOnError' => true, 'collectionAction' => 'remove', 'isPartial' => true]);
                $logger->info(
                    '{count}/{total} items detached from item set #{item_set_id}.', // @translate
                    ['count' => min(++$i * 100, count($detachItemIds)), 'total' => count($detachItemIds), 'item_set_id' => $itemSetId]
                );
            }
        }

        // Attach new items only.
        $newItemIds = $newItemIds ? array_diff($newItemIds, $existingItemIds) : [];
        if ($newItemIds) {
            $i = 0;
            foreach (array_chunk($newItemIds, 100) as $idsChunk) {
                if ($this->shouldStop()) {
                    return;
                }
                // TODO The use of batchUpdate() may throw exception for recursive loop, so loop items here for now.
                // $api->batchUpdate('items', $idsChunk, ['o:item_set' => [$itemSetId]], ['continueOnError' => true, 'collectionAction' => 'append', 'isPartial' => true]);
                foreach ($idsChunk as $id) {
                    try {
                        $item = $api->read('items', $id)->getContent();
                    } catch (\Exception $e) {
                        continue;
                    }
                    $item = json_decode(json_encode($item), true);
                    // Avoid issues with duplicated item set ids. Normally none.
                    $itemSetIds = [];
                    foreach ($item['o:item_set'] ?? [] as $existingItemSet) {
                        $itemSetIds[$existingItemSet['o:id']] = $existingItemSet['o:id'];
                    }
                    if (isset($itemSetIds[$itemSetId])) {
                        continue;
                    }
                    $item['o:item_set'][] = ['o:id' => $itemSetId];
                    $api->update('items', $id, $item, ['continueOnError' => true, 'collectionAction' => 'append', 'isPartial' => true]);
                }
                $logger->info(
                    '{count}/{total} new items attached to item set #{item_set_id}.', // @translate
                    ['count' => min(++$i * 100, count($newItemIds)), 'total' => count($newItemIds), 'item_set_id' => $itemSetId]
                );
            }
        }

        $logger->notice(
            'Process ended for item set #{item_set_id}: {count} items were attached, {count_2} items were detached, {count_3} new items were attached.', // @translate
            ['item_set_id' => $itemSetId, 'count' => count($existingItemIds), 'count_2' => count($detachItemIds), 'count_3' => count($newItemIds)]
        );
    }
}
