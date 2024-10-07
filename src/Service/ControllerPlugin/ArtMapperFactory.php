<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Service\ControllerPlugin;

use AdvancedResourceTemplate\Mvc\Controller\Plugin\ArtMapper;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ArtMapperFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new ArtMapper(
            $services->get('Omeka\ApiManager'),
            $services->get('Common\EasyMeta'),
            $services->get('ControllerPluginManager')->get('translate')
        );
    }
}
