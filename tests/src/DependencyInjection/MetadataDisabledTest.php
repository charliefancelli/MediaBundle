<?php

namespace JoliCode\MediaBundle\Tests\DependencyInjection;

use JoliCode\MediaBundle\DependencyInjection\Compiler\DoctrinePass;
use JoliCode\MediaBundle\JoliMediaBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class MetadataDisabledTest extends TestCase
{
    public function testDisabledMetadataRegistersNothing(): void
    {
        $container = $this->createContainer(['enabled' => false]);

        // DoctrinePass must be a no-op when the metadata are off, even without DoctrineBundle
        // (a full compilation is not possible here: the services depend on the application)
        (new DoctrinePass())->process($container);

        $this->assertFalse($container->getParameter('joli_media.metadata.enabled'));
        $this->assertFalse($container->getParameter('joli_media.metadata.doctrine_mapping'));
        $this->assertNull($container->getParameter('joli_media.metadata.entity_manager'));
        $this->assertSame(['alt', 'caption', 'copyright', 'tags'], $container->getParameter('joli_media.metadata.fields'));
        $this->assertSame([], array_filter(
            array_keys($container->getDefinitions()),
            static fn (string $id): bool => str_starts_with($id, 'joli_media.metadata.'),
        ));
    }

    public function testEnablingTheDoctrineStorageWithoutDoctrineBundleFails(): void
    {
        $container = $this->createContainer(['enabled' => true]);

        $this->assertTrue($container->getParameter('joli_media.metadata.doctrine_mapping'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The "doctrine" metadata storage requires the Doctrine ORM.');

        $bundle = new JoliMediaBundle();
        $bundle->build($container);
        $container->compile();
    }

    public function testACustomStorageDoesNotNeedTheDoctrineMapping(): void
    {
        $container = $this->createContainer(['enabled' => true, 'storage' => 'app.metadata_storage']);

        $this->assertTrue($container->getParameter('joli_media.metadata.enabled'));
        $this->assertFalse($container->getParameter('joli_media.metadata.doctrine_mapping'));
    }

    /**
     * @param array<string, mixed> $metadataConfig
     */
    private function createContainer(array $metadataConfig): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.bundles' => [],
            'kernel.project_dir' => __DIR__,
            'kernel.debug' => false,
            'kernel.environment' => 'test',
            'kernel.cache_dir' => sys_get_temp_dir(),
            'kernel.build_dir' => sys_get_temp_dir(),
        ]));

        $bundle = new JoliMediaBundle();
        $extension = $bundle->getContainerExtension();
        \assert(null !== $extension);
        $extension->load([[
            'libraries' => [
                'default' => [
                    'original' => ['flysystem' => 'flysystem.original', 'url_generator' => ['path' => '/media/default']],
                    'cache' => ['flysystem' => 'flysystem.cache', 'url_generator' => ['path' => '/media/default']],
                ],
            ],
            'metadata' => $metadataConfig,
        ]], $container);

        return $container;
    }
}
