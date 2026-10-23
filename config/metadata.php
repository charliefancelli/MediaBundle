<?php

use JoliCode\MediaBundle\Event\MediaEvents;
use JoliCode\MediaBundle\Metadata\MetadataManager;
use JoliCode\MediaBundle\Metadata\MetadataProvider;
use JoliCode\MediaBundle\Metadata\Storage\DoctrineMetadataStorage;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
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

    // the listeners below move and delete the metadata along with their media...
    foreach (['media_move', 'folder_move', 'media_delete', 'folder_delete'] as $listener) {
        $services->get('joli_media.event_listener.' . $listener)
            ->arg('$metadataStorage', service('joli_media.metadata.storage'))
        ;
    }

    // ...so the provider must forget what it memoized once they are done (low priority: after the bundle listeners)
    $provider = $services->get('joli_media.metadata.provider');

    foreach ([MediaEvents::POST_MOVE_MEDIA, MediaEvents::POST_MOVE_FOLDER, MediaEvents::POST_DELETE_MEDIA, MediaEvents::POST_DELETE_FOLDER] as $event) {
        $provider->tag('kernel.event_listener', ['event' => $event, 'method' => 'reset', 'priority' => -1024]);
    }
};
