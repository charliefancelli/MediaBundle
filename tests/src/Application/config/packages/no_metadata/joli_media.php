<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// the "no_metadata" environment is the test application with the metadata disabled
return static function (ContainerConfigurator $container): void {
    $container->extension('joli_media', [
        'metadata' => ['enabled' => false],
    ]);
};
