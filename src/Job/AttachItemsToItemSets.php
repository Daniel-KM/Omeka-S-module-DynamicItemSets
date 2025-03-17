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
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

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
        $this->connection = $services->get('Omeka\Connection');
        $this->entityManager = $services->get('Omeka\EntityManager');

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('dynis/attach_to_itemset/job_' . $this->job->getId());
        $this->logger->addProcessor($referenceIdProcessor);

        // Null means all dynamic item sets.
        $itemSetIds = $this->getArg('item_set_ids', null);
        if (is_array($itemSetIds) && !count($itemSetIds)) {
            $this->logger->info('No item set to process.'); // @translate
            return;
        }

        $useDirectSql = (bool) $this->getArg('direct');

        $settings = $services->get('Omeka\Settings');
        $queries = $settings->get('dynamicitemsets_item_set_queries') ?: [];

        if ($itemSetIds === null) {
            $itemSetIds = array_keys($queries);
        }

        $this->logger->info(
            'Processing attach/detach items from {total} item sets.', // @translate
            ['total' => count($itemSetIds)]
        );

        // Process by step to avoid to update items multiple times.

        // Clean the list of item sets.
        $itemSetIds = array_unique(array_map('intval', $itemSetIds));
        $itemSetIds = array_combine($itemSetIds, $itemSetIds);

        // List existing item sets.
        $existingItemSets = $this->api->search('item_sets', ['id' => $itemSetIds], ['returnScalar' => 'id'])->getContent();
        if (count($existingItemSets) !== count($itemSetIds)) {
            $this->logger->notice(
                'The following item sets does not exist or you don’t have the right to manage them: #{item_set_ids}.', // @translate
                ['item_set_ids' => implode(', #', array_diff_key($itemSetIds, $existingItemSets))]
            );
            $itemSetIds = array_intersect_key($itemSetIds, $existingItemSets);
        }

        // Clean queries. Normally useless.
        foreach ($itemSetIds as $itemSetId) {
            $query = $queries[$itemSetId] ?? null;
            if (is_string($query)) {
                $nQuery = null;
                parse_str($query, $nQuery);
                $query = $nQuery ?: null;
            }
            $queries[$itemSetId] = $query;
        }

        $queries = array_filter($queries);

        // Skip item sets without query.
        $itemSetsWithoutQuery = array_diff_key($itemSetIds, $queries);
        if (count($itemSetsWithoutQuery)) {
            $this->logger->info(
                'The following item sets have no more query and items attached to it are kept: #{item_set_ids}.', // @translate
                ['item_set_ids' => implode(', #', $itemSetsWithoutQuery)]
            );
        }

        // Check remaining item sets.
        $itemSetIds = array_intersect_key($itemSetIds, $queries);
        if (!count($itemSetIds)) {
            $this->logger->info(
                'No more item sets to process.' // @translate
            );
            return;
        }

        // There are two strategies:
        // - process by item set, so items may be managed multiple times;
        // - process by item, removing and appending item sets one time.
        // This second way seems quicker, but requires to prepare all items.
        // But the batch update process use two loops to remove and to append
        // item sets. And each item may not have the same item sets to remove
        // and append. And in fact, batch update is a loop on update.
        // So the two strategies are probably similar in real cases.
        // A third strategy is to process via sql and to do a simple loop on
        // items and item sets.

        // In direct mode, detach all item sets early.
        // This is useless with "on duplicate key update".
        /*
        if ($useDirectSql) {
            $sql = <<<'SQL'
                DELETE FROM `item_item_set`
                WHERE `item_set_id` IN (:item_set_ids);
                SQL;
            $this->connection->executeStatement($sql, ['item_set_ids' => $itemSetIds], ['item_set_ids' => Connection::PARAM_INT_ARRAY]);
        }
        */

        // Detach all item sets.
        foreach ($itemSetIds as $itemSetId) {
            $useDirectSql
                ? $this->attachItemsToItemSet($itemSetId, $queries[$itemSetId])
                : $this->attachItemsToItemSetFull($itemSetId, $queries[$itemSetId]);
            $this->entityManager->flush();
            $this->entityManager->clear();
        }

        $this->logger->info(
            'Processing attach/detach items from {total} item sets ended.', // @translate
            ['total' => count($itemSetIds)]
        );
    }

    protected function attachItemsToItemSet(int $itemSetId, array $query): void
    {
        $newItemIds = $this->api->search('items', $query, ['returnScalar' => 'id'])->getContent();
        $newItemIds = array_values(array_map('intval', $newItemIds));

        // Default size of mysql/mariadb is 4MB, so about 200000 rows in big
        // bases, but in big bases, the max size of the request is probably
        // increased.
        foreach (array_chunk($newItemIds, 100000) as $ids) {
            $values = '(' . implode(",$itemSetId),\n(", $ids) . ",$itemSetId)";
            $sql = <<<SQL
                INSERT INTO `item_item_set` (`item_id`, `item_set_id`)
                VALUES
                $values
                ON DUPLICATE KEY UPDATE
                `item_id` = `item_id`,
                `item_set_id` = `item_set_id`;
                SQL;
            $this->connection->executeStatement($sql);
        }
    }

    protected function attachItemsToItemSetFull(int $itemSetId, array $query): void
    {
        $existingItemIds = $this->api->search('items', ['item_set_id' => $itemSetId], ['returnScalar' => 'id'])->getContent();
        $newItemIds = $this->api->search('items', $query, ['returnScalar' => 'id'])->getContent();

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
                if (count($detachItemIds) <= 100) {
                    $this->logger->info(
                        '{total} items detached from item set #{item_set_id}.', // @translate
                        ['total' => count($detachItemIds), 'item_set_id' => $itemSetId]
                    );
                } else {
                    $this->logger->info(
                        '{count}/{total} items detached from item set #{item_set_id}.', // @translate
                        ['count' => min(++$i * 100, count($detachItemIds)), 'total' => count($detachItemIds), 'item_set_id' => $itemSetId]
                    );
                }
                $this->entityManager->flush();
                $this->entityManager->clear();
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
                if (count($newItemIds) <= 100) {
                    $this->logger->info(
                        '{total} new items attached to item set #{item_set_id}.', // @translate
                        ['total' => count($newItemIds), 'item_set_id' => $itemSetId]
                    );
                } else {
                    $this->logger->info(
                        '{count}/{total} new items attached to item set #{item_set_id}.', // @translate
                        ['count' => min(++$i * 100, count($newItemIds)), 'total' => count($newItemIds), 'item_set_id' => $itemSetId]
                    );
                }
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        $this->logger->info(
            'Process ended for item set #{item_set_id}: {count} items were attached, {count_2} items were detached, {count_3} new items were attached.', // @translate
            ['item_set_id' => $itemSetId, 'count' => count($existingItemIds), 'count_2' => count($detachItemIds), 'count_3' => count($newItemIds)]
        );
    }
}
