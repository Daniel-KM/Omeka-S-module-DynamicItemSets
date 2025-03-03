<?php declare(strict_types=1);

namespace DynamicItemSets;

use Common\Stdlib\PsrMessage;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Laminas\Log\Logger $logger
 * @var \Omeka\Settings\Settings $settings
 * @var \Laminas\I18n\View\Helper\Translate $translate
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Laminas\Mvc\I18n\Translator $translator
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Settings\SiteSettings $siteSettings
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $plugins->get('url');
$api = $plugins->get('api');
$logger = $services->get('Omeka\Logger');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$translator = $services->get('MvcTranslator');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$siteSettings = $services->get('Omeka\Settings\Site');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.66')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.66'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
}

if ($this->isModuleActive('AdvancedResourceTemplate')
    && !$this->checkModuleActiveVersion('AdvancedResourceTemplate', '3.4.38')
) {
    $message = new PsrMessage(
        $translator->translate('To avoid compatibility issue, the module {module} should be version {version} or greater.'), // @translate
        ['module' => 'Advanced Resource Template', 'version' => '3.4.38']
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
}

if (version_compare($oldVersion, '3.4.35', '<')) {
    // Clean queries.
    $queries = $settings->get('dynamicitemsets_item_set_queries', []);
    if ($queries) {
        foreach ($queries as $key => $query) {
            $this->arrayFilterRecursiveEmpty($query);
            $query = $query ?: null;
            if (!$query) {
                unset($queries[$key]);
            } else {
                $queries[$key] = $query;
            }
        }
    }
    ksort($queries);
    $settings->set('dynamicitemsets_item_set_queries', $queries);

    $message = new PsrMessage(
        'The attached items to an item set are no more detached when the query is removed.' // @translate
    );
    $messenger->addSuccess($message);
}
