<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('locale', 'en');

    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
    ;

    $services->load('JoliCode\MediaBundle\Tests\Application\\', '../src/*')
        ->exclude('../{Entity,Tests,Kernel.php}')
    ;

    // nothing else injects the metadata storage yet, and private services without users are removed
    $services->alias('test.joli_media.metadata.storage', MetadataStorageInterface::class)->public();

    $services->load('JoliCode\MediaBundle\Tests\Application\Controller\\', '../src/Controller/')
        ->tag('controller.service_arguments')
    ;
};
