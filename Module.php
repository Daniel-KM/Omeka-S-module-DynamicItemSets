<?php declare(strict_types=1);

namespace DynamicItemSets;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Module\AbstractModule;

/**
 * Dynamic Item Sets.
 *
 * @copyright Daniel Berthereau, 2023-2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    /**
     * @var bool
     */
    protected $isBatchUpdate;

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $translate = $plugins->get('translate');
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.65')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.65'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        // If present, AdvancedResourceTemplate should be at least 3.4.36.
        if ($this->isModuleActive('AdvancedResourceTemplate')
            && !$this->checkModuleActiveVersion('AdvancedResourceTemplate', '3.4.36')
        ) {
            $message = new PsrMessage(
                $translator->translate('If present, the module requires module "{module}" version "{version}" or greater.'), // @translate
                ['module' => 'Advanced Resource Template', 'version' => '3.4.36']
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    protected function postInstall()
    {
        // Whatever the version of AdvancedResourceTemplate, get its metadata.
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $itemSetQueries = $settings->get('advancedresourcetemplate_item_set_queries', []) ?: [];
        $settings->set('dynamicitemsets_item_set_queries', $itemSetQueries);

        // Set it by default in admin for module Advanced Search.
        $selectedSearchFields = $settings->get('advancedsearch_search_fields');
        if ($selectedSearchFields) {
            $selectedSearchFields[] = 'common/advanced-search/item-set-is-dynamic';
        }

        $this->postInstallAuto();
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        if (class_exists('AdvancedResourceTemplate', false)
            && !$this->checkModuleActiveVersion('AdvancedResourceTemplate', '3.4.36')
        ) {
            $services = $this->getServiceLocator();
            $services->get('Omeka\Logger')->err(
                'If present, the module requires module "{module}" version "{version}" or greater.', // @translate
                ['module' => 'Advanced Resource Template', 'version' => '3.4.36']
            );
            return;
        }

        // Manage the items to append to item sets.
        // The item should be created to be able to do a search on it.
        // An event is needed early to update item set queries one time only.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.batch_update.pre',
            [$this, 'preBatchUpdateItems'],
            -100
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.post',
            [$this, 'handleApiSavePostItem']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.update.post',
            [$this, 'handleApiSavePostItem']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.create.post',
            [$this, 'handleApiSavePostItemSet']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.update.post',
            [$this, 'handleApiSavePostItemSet']
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.delete.post',
            [$this, 'handleApiDeletePostItemSet']
        );

        // Search dynamic queries with "is_dynamic=0" or "is_dynamic=1".
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.search.query',
            [$this, 'searchDynamicItemSets']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.advanced_search',
            [$this, 'searchDynamicItemSetsPartial']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.search.filters',
            [$this, 'searchDynamicItemSetsFilters']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.details',
            [$this, 'handleItemSetDetails']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.show.sidebar',
            [$this, 'handleItemSetSidebar']
        );

        // Display the item set query for items in advanced tab.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.add.form.advanced',
            [$this, 'addAdvancedTabElements']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\ItemSet',
            'view.edit.form.advanced',
            [$this, 'addAdvancedTabElements']
        );
    }

    public function searchDynamicItemSets(Event $event): void
    {
        $query = $event->getParam('request')->getContent();
        if (!array_key_exists('is_dynamic', $query)) {
            return;
        } elseif ($query['is_dynamic'] === null || $query['is_dynamic'] === '') {
            // Clean query early.
            unset($query['is_dynamic']);
            $event->getParam('query', $query);
            return;
        }

        /**
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Api\Adapter\ItemSetAdapter $adapter
         * @var \Doctrine\ORM\QueryBuilder $qb
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $adapter = $event->getTarget();
        $qb = $event->getParam('queryBuilder');
        $expr = $qb->expr();

        $isDynamic = (bool) $query['is_dynamic'];
        $itemSetQueries = $settings->get('dynamicitemsets_item_set_queries', []);

        if (!$itemSetQueries) {
            if ($isDynamic) {
                $qb->andWhere($expr->eq('omeka_root.id', 0));
            }
        } elseif ($isDynamic) {
            $qb->andWhere($expr->in(
                'omeka_root.id',
                $adapter->createNamedParameter($qb, array_keys($itemSetQueries))
            ));
        } else {
            $qb->andWhere($expr->notIn(
                'omeka_root.id',
                $adapter->createNamedParameter($qb, array_keys($itemSetQueries))
            ));
        }
    }

    public function searchDynamicItemSetsPartial(Event $event): void
    {
        $partials = $event->getParam('partials', []);
        $partials[] = 'common/advanced-search/item-set-is-dynamic';
        $event->setParam('partials', $partials);
    }

    public function searchDynamicItemSetsFilters(Event $event): void
    {
        $query = $event->getParam('query', []);
        if (!array_key_exists('is_dynamic', $query)) {
            return;
        } elseif ($query['is_dynamic'] === null || $query['is_dynamic'] === '') {
            // Clean query early.
            unset($query['is_dynamic']);
            $event->getParam('query', $query);
            return;
        }

        $view = $event->getTarget();
        $plugins = $view->getHelperPluginManager();
        $translate = $plugins->get('translate');

        $filters = $event->getParam('filters', []);
        $filterLabel = $translate('Is dynamic'); // @translate

        // Manage the module Advanced Search that may add the filter previously.
        if (isset($filters[$filterLabel])) {
            return;
        }

        $value = (bool) $query['is_dynamic'];
        $filters[$filterLabel][] = $value
            ? $translate('yes') // @translate
            : $translate('no'); // @translate

        $event->setParam('filters', $filters);
    }

    public function handleItemSetDetails(Event $event): void
    {
        $itemSet = $event->getParam('entity');
        echo $this->showItemSetDynamic($event, $itemSet);
    }


    public function handleItemSetSidebar(Event $event)
    {
        $view = $event->getTarget();
        $itemSet = $view->vars()->offsetGet('resource');
        echo $this->showItemSetDynamic($event, $itemSet);
    }

    protected function showItemSetDynamic(Event $event, ItemSetRepresentation $itemSet): string
    {
        /**
         * @var \Omeka\Settings\Settings $settings
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $translate = $services->get('ControllerPluginManager')->get('translate');

        $itemSetQueries = $settings->get('dynamicitemsets_item_set_queries', []);

        $title = $translate('Is dynamic');

        $value = isset($itemSetQueries[$itemSet->id()])
            // No need to set a link: already set in sidebar.
            ? $translate('Yes') // @translate
            : $translate('No'); // @translate

        return <<<HTML
            <div class="meta-group">
                <h4>$title</h4>
                <div class="value">$value</div>
            </div>
            
            HTML;
    }

    public function preBatchUpdateItems(Event $event): void
    {
        $this->isBatchUpdate = true;
    }

    /**
     * Append item to items sets according to each request.
     *
     * A post event is required else the search query cannot be done.
     * Else process differently for "add".
     */
    public function handleApiSavePostItem(Event $event): void
    {
        $queries = $this->updateItemSetsQueries();
        if (!$queries) {
            return;
        }

        /**
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Api\Response $response
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Api\Adapter\ItemAdapter $adapter
         * @var \Omeka\Entity\Item|\Omeka\Api\Representation\ItemRepresentation $item
         */
        $services = $this->getServiceLocator();
        $request = $event->getParam('request');
        $settings = $services->get('Omeka\Settings');

        $adapter = $event->getTarget();
        $response = $event->getParam('response');

        $item = $response->getContent();

        if ($item instanceof \Omeka\Api\Representation\ItemRepresentation) {
            /** @var \Omeka\Entity\Item $item */
            $item = $adapter->getEntityManager()->find(\Omeka\Entity\Item::class, $item->id());
        }

        $itemId = $item->getId();

        $existingItemSetIds = [];
        foreach ($item->getItemSets() as $itemSet) {
            $existingItemSetIds[$itemSet->getId()] = $itemSet->getId();
        }

        // Don't check for existing item sets.
        // It may avoid an infinite loop too.
        $queries = array_diff_key($queries, $existingItemSetIds);
        if (!$queries) {
            return;
        }

        // The adapter cannot be used directly when module AdvancedSearch is
        // enabled, because some arguments are not supported.
        $api = $services->get('Omeka\ApiManager');

        // Check if the item belongs to each item set.
        $newItemSetIds = [];
        foreach ($queries as $itemSetId => $query) {
            $query['id'] = [$itemId];
            $result = $api->search('items', $query, ['returnScalar' => 'id'])->getTotalResults();
            if ($result) {
                $newItemSetIds[$itemSetId] = $itemSetId;
            }
        }

        if (!$newItemSetIds) {
            return;
        }

        // In a post event, an infinite loop should be avoided, so skip api.

        $data = [
            'o:item_set' => $newItemSetIds,
        ];

        $updateRequest = new \Omeka\Api\Request('update', 'items');
        $updateRequest
            ->setId($itemId)
            ->setOption('initialize', false)
            ->setOption('finalize', false)
            ->setOption('isPartial', true)
            ->setOption('collectionAction', 'append')
            // Manage single and batch update processes.
            ->setOption('flushEntityManager', (bool) $request->getOption('flushEntityManager', true))
            ->setContent($data);
        $newItem = $adapter->update($updateRequest)->getContent();

        // Set right content in response.
        $responseContent = $request->getOption('responseContent');
        if ($responseContent === 'representation') {
            $newItem = $adapter->getRepresentation($newItem);
        } elseif ($responseContent === 'reference') {
            $newItem = $adapter->getRepresentation($newItem)->getReference();
        }

        $response->setContent($newItem);
    }

    public function handleApiSavePostItemSet(Event $event): void
    {
        /**
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Api\Response $response
         * @var \Omeka\Entity\ItemSet|\Omeka\Api\Representation\ItemSetRepresentation $itemSet
         * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $request = $event->getParam('request');
        $response = $event->getParam('response');
        $messenger = $services->get('ControllerPluginManager')->get('messenger');

        $itemSet = $response->getContent();
        $itemSetId = method_exists($itemSet, 'getId') ? $itemSet->getId() : $itemSet->id();

        $queries = $this->updateItemSetsQueries();

        $existingQuery = $queries[$itemSetId] ?? null;

        // Store queries as array for cleaner storage and to avoid to parse it
        // each time and for quicker process.
        $queryString = $request->getValue('item_set_query_items') ?: null;
        if ($queryString) {
            $query = null;
            parse_str($queryString, $query);
        }

        if (empty($query)) {
            unset($queries[$itemSetId]);
            $query = null;
        } else {
            // Simplify the query for "id" if any (normally not present).
            if (empty($query['id'])) {
                unset($query['id']);
            } elseif (!is_array($query['id'])) {
                $query['id'] = [$query['id']];
            }
            // Of course, remove the current item set id from the query, else it
            // won't contains anything.
            if (!empty($query['item_set_id'])) {
                // Take care of module Advanced Search, that can search multiple
                // item set ids.
                $check = false;
                if (is_array($query['item_set_id'])) {
                    $query['item_set_id'] = array_diff($query['item_set_id'], [$itemSetId]);
                    $check = true;
                } elseif ((int) $query['item_set_id'] === (int) $itemSetId) {
                    unset($query['item_set_id']);
                    $check = true;
                }
                if ($check) {
                    $message = new PsrMessage(
                        'The query to attach items cannot contain the item set itself.' // @translate
                    );
                    $messenger->addWarning($message);
                }
            }
            $queries[$itemSetId] = $query;
        }

        $settings->set('dynamicitemsets_item_set_queries', $queries);

        if ($query === $existingQuery) {
            return;
        }

        // Exclude all existing items with this query and add new ones.
        // Don't use a sql query, but a batch update in order to manage api
        // calls (indexations).
        // Use a job: the process via api can be long with many items.
        $args = [
            'item_set_id' => $itemSetId,
        ];
        $job = $services->get(\Omeka\Job\Dispatcher::class)->dispatch(\DynamicItemSets\Job\AttachItemsToItemSet::class, $args);
        $urlHelper = $services->get('ViewHelperManager')->get('url');
        $message = new PsrMessage(
            'The query for the item set was changed: a job is run in background to detach and to attach items (job {link_job}#{job_id}{link_end}, {link_log}logs{link_end}).', // @translate
            [
                'link_job' => sprintf(
                    '<a href="%s">',
                    htmlspecialchars($urlHelper('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                ),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
                'link_log' => sprintf(
                    '<a href="%s">',
                    // Check if module Log is enabled (avoid issue when disabled).
                    htmlspecialchars(class_exists('Log\Module', false)
                        ? $urlHelper('admin/log/default', [], ['query' => ['job_id' => $job->getId()]])
                        : $urlHelper('admin/id', ['controller' => 'job', 'id' => $job->getId(), 'action' => 'log'])
                    )),
            ]
        );
        $message->setEscapeHtml(false);
        $messenger->addSuccess($message);
    }

    /**
     * Handle event to update list of all item sets queries.
     */
    public function handleApiDeletePostItemSet(Event $event): void
    {
        $this->updateItemSetsQueries();
    }

    /**
     * Update list of all item sets.
     *
     * @return array List of queries.
     */
    protected function updateItemSetsQueries(): array
    {
        static $queries;

        if ($this->isBatchUpdate && $queries !== null) {
            return $queries;
        }

        /**
         * @var \Omeka\Settings\Settings $settings
         * @var \Doctrine\DBAL\Connection $connection
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $queries = $settings->get('dynamicitemsets_item_set_queries') ?: [];
        if ($queries) {
            // Use connection because the current user may not have access to all
            // item sets. Check all item sets one time.
            $connection = $services->get('Omeka\Connection');
            $itemSetIds = $connection
                ->executeQuery(
                    'SELECT `id`, `id` FROM `item_set` WHERE `id` IN (:ids)',
                    ['ids' => array_keys($queries)],
                    ['ids' => \Doctrine\DBAL\Connection::PARAM_INT_ARRAY]
                )
                ->fetchAllKeyValue();
            $queries = array_intersect_key($queries, $itemSetIds);
            $settings->set('dynamicitemsets_item_set_queries', $queries);
        }

        return $queries;
    }

    public function addAdvancedTabElements(Event $event): void
    {
        $services = $this->getServiceLocator();
        $view = $event->getTarget();
        $resource = $view->resource;

        /** @var \Omeka\Settings\Settings $settings */
        $settings = $services->get('Omeka\Settings');
        $queries = $settings->get('dynamicitemsets_item_set_queries') ?: [];
        $query = $resource ? $queries[$resource->id()] ?? null : null;

        $query = $query ? http_build_query($query, '', '&', PHP_QUERY_RFC3986) : null;

        /** @var \Omeka\Form\Element\Query $element */
        $formManager = $services->get('FormElementManager');
        $element = $formManager->get(\Omeka\Form\Element\Query::class);
        $element
            ->setName('item_set_query_items')
            ->setLabel('Query to attach items dynamically to this item set') // @translate
            ->setOptions([
                'query_resource_type' => 'items',
            ])
            ->setAttributes([
                'id' => 'item_set_query_items',
                'value' => $query,
            ]);
        echo $view->formRow($element);
    }
}
