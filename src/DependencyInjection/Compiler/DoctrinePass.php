<?php

namespace JoliCode\MediaBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class DoctrinePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $bundles = $container->getParameter('kernel.bundles');

        if ($container->hasParameter('joli_media.metadata.doctrine_mapping') && $container->getParameter('joli_media.metadata.doctrine_mapping') && !isset($bundles['DoctrineBundle'])) {
            throw new \LogicException('The "doctrine" metadata storage requires the Doctrine ORM. Run "composer require doctrine/orm doctrine/doctrine-bundle", or set "joli_media.metadata.storage" to your own storage service.');
        }

        if (isset($bundles['DoctrineBundle'])) {
            // the Doctrine listeners move and delete the metadata inside their transaction
            if ($container->hasDefinition('joli_media.metadata.event_listener.follow')) {
                $container->removeDefinition('joli_media.metadata.event_listener.follow');
            }

            return;
        }

        $container->removeDefinition('joli_media.cache_warmer.media_entity_metadata');
        $container->removeDefinition('joli_media.event_listener.folder_delete');
        $container->removeDefinition('joli_media.event_listener.folder_move');
        $container->removeDefinition('joli_media.event_listener.media_delete');
        $container->removeDefinition('joli_media.event_listener.media_move');
    }
}
