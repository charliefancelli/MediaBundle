<?php

namespace JoliCode\MediaBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers the Doctrine mapping of the metadata entities only when the "joli_media.metadata.doctrine_mapping" parameter is true.
 *
 * The mapping pass of DoctrineBundle only checks that its "enabled" parameter exists, and this one always exists (it is false when disabled).
 */
class MetadataMappingPass implements CompilerPassInterface
{
    public function __construct(private readonly CompilerPassInterface $mappingPass)
    {
    }

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('joli_media.metadata.doctrine_mapping') || !$container->getParameter('joli_media.metadata.doctrine_mapping')) {
            return;
        }

        $this->mappingPass->process($container);
    }
}
