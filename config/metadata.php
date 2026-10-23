<?php

use JoliCode\MediaBundle\Metadata\MetadataManager;
use JoliCode\MediaBundle\Metadata\MetadataProvider;
use JoliCode\MediaBundle\Metadata\Storage\DoctrineMetadataStorage;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('joli_media.metadata.storage.doctrine', DoctrineMetadataStorage::class)
        ->args([
            service('doctrine'),
            param('joli_media.metadata.entity_manager'),
        ])

        ->alias('joli_media.metadata.storage', 'joli_media.metadata.storage.doctrine')
        ->alias(MetadataStorageInterface::class, 'joli_media.metadata.storage')

        ->set('joli_media.metadata.provider', MetadataProvider::class)
        ->public()
        ->args([
            service('joli_media.metadata.storage'),
            service('joli_media.library_container'),
        ])
        ->tag('kernel.reset', ['method' => 'reset'])
        ->alias(MetadataProvider::class, 'joli_media.metadata.provider')

        ->set('joli_media.metadata.manager', MetadataManager::class)
        ->public()
        ->args([
            service('joli_media.metadata.storage'),
            service('joli_media.metadata.provider'),
            service(EventDispatcherInterface::class),
        ])
        ->alias(MetadataManager::class, 'joli_media.metadata.manager')
    ;
};
