<?php declare(strict_types=1);

namespace DynamicItemSets\Job;

use Omeka\Job\AbstractJob;

class AttachItemsToItemSets extends AbstractJob
{
    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * Pass args "item_set_ids" as a list of ids to process only them, or null
     * to update all item sets with a query. An empty array performs nothing.
     *
     * {@inheritDoc}
     * @see \Omeka\Job\JobInterface::perform()
     */
    public function perform(): void
    {
        /**
         * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
         * @var \Omeka\Settings\Settings $settings
         */
        $services = $this->getServiceLocator();
        $this->api = $services->get('Omeka\ApiManager');
        $this->logger = $services->get('Omeka\Logger');

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('dynis/attach_to_itemset/job_' . $this->job->getId());
        $this->logger->addProcessor($referenceIdProcessor);

        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $services->get('Omeka\EntityManager');

        // Null means all dynamic item sets.
        $itemSetIds = $this->getArg('item_set_ids', null);
        if (is_array($itemSetIds) && !count($itemSetIds)) {
            $this->logger->info('No item set to process.'); // @translate
            return;
        }

        $settings = $services->get('Omeka\Settings');
        $queries = $settings->get('dynamicitemsets_item_set_queries') ?: [];

        if ($itemSetIds === null) {
            $itemSetIds = array_keys($queries);
        }

        $this->logger->info(
            'Processing attach/detach items from {total} item sets.', // @translate
            ['total' => count($itemSetIds)]
        );

        foreach ($itemSetIds as $itemSetId) {
            $query = $queries[$itemSetId] ?? null;
            if (is_string($query)) {
                $nQuery = null;
                parse_str($query, $nQuery);
                $query = $nQuery ?: null;
            }
            $this->attachItemsToItemSet($itemSetId, $query);
            $entityManager->clear();
        }

        $this->logger->info(
            'Processing attach/detach items from {total} item sets ended.', // @translate
            ['total' => count($itemSetIds)]
        );
    }

    protected function attachItemsToItemSet(int $itemSetId, ?array $query): void
    {
        try {
            $this->api->read('item_sets', ['id' => $itemSetId]);
        } catch (\Exception $e) {
            $this->logger->notice(
                'The item set #{item_set_id} does not exist or you don’t have the right to manage it.', // @translate
                ['item_set_id' => $itemSetId]
            );
            return;
        }

        if (!$query) {
            $this->logger->info(
                'The item set #{item_set_id} has no more query and items attached to it are kept.', // @translate
                ['item_set_id' => $itemSetId]
            );
            return;
        }

        $existingItemIds = $this->api->search('items', ['item_set_id' => $itemSetId], ['returnScalar' => 'id'])->getContent();
        $newItemIds = $query ? $this->api->search('items', $query, ['returnScalar' => 'id'])->getContent() : [];

        /**
         * Batch update the resources in chunks to fix a memory issue.
         *
         * @see \Omeka\Job\BatchUpdate::perform()
         */

        // Detach all items that are not in new items.
        $detachItemIds = array_diff($existingItemIds, $newItemIds);
        if ($detachItemIds) {
            $i = 0;
            foreach (array_chunk($detachItemIds, 100) as $idsChunk) {
                if ($this->shouldStop()) {
                    return;
                }
                $this->api->batchUpdate('items', $idsChunk, ['o:item_set' => [$itemSetId]], ['continueOnError' => true, 'collectionAction' => 'remove', 'isPartial' => true]);
                $this->logger->info(
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
                /** @see \Omeka\Api\Adapter\AbstractEntityAdapter::batchUpdate() */
                // $this->api->batchUpdate('items', $idsChunk, ['o:item_set' => [$itemSetId]], ['continueOnError' => true, 'collectionAction' => 'append', 'isPartial' => true]);
                foreach ($idsChunk as $id) {
                    try {
                        $item = $this->api->read('items', $id)->getContent();
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
                    $this->api->update('items', $id, $item, ['continueOnError' => true, 'collectionAction' => 'append', 'isPartial' => true]);
                }
                $this->logger->info(
                    '{count}/{total} new items attached to item set #{item_set_id}.', // @translate
                    ['count' => min(++$i * 100, count($newItemIds)), 'total' => count($newItemIds), 'item_set_id' => $itemSetId]
                );
            }
        }

        $this->logger->info(
            'Process ended for item set #{item_set_id}: {count} items were attached, {count_2} items were detached, {count_3} new items were attached.', // @translate
            ['item_set_id' => $itemSetId, 'count' => count($existingItemIds), 'count_2' => count($detachItemIds), 'count_3' => count($newItemIds)]
        );
    }
}
