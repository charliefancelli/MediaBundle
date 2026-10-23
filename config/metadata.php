<?php

use JoliCode\MediaBundle\Metadata\Storage\DoctrineMetadataStorage;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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
    ;
};
