<?php

namespace JoliCode\MediaBundle\Tests\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use JoliCode\MediaBundle\DependencyInjection\Compiler\DoctrinePass;
use JoliCode\MediaBundle\Event\MediaEvents;
use JoliCode\MediaBundle\JoliMediaBundle;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

class MetadataDisabledTest extends TestCase
{
    public function testDisabledMetadataRegistersNothing(): void
    {
        $container = $this->createContainer(['enabled' => false]);

        // DoctrinePass must be inert for the metadata when they are off, even without DoctrineBundle
        // (without it, the pass still removes the four Doctrine listeners, as it always did)
        // (a full compilation is not possible here: the services depend on the application)
        (new DoctrinePass())->process($container);

        $this->assertFalse($container->getParameter('joli_media.metadata.enabled'));
        $this->assertFalse($container->hasParameter('joli_media.metadata.doctrine_mapping'));
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
        $this->assertFalse($container->hasParameter('joli_media.metadata.doctrine_mapping'));
        $this->assertFalse($container->hasDefinition('joli_media.metadata.storage.doctrine'));
        $this->assertSame('app.metadata_storage', (string) $container->getAlias('joli_media.metadata.storage'));
        $this->assertSame('joli_media.metadata.storage', (string) $container->getAlias(MetadataStorageInterface::class));
    }

    public function testTheDoctrineStorageIsTheDefaultOne(): void
    {
        $container = $this->createContainer(['enabled' => true]);

        $this->assertTrue($container->hasDefinition('joli_media.metadata.storage.doctrine'));
        $this->assertSame('joli_media.metadata.storage.doctrine', (string) $container->getAlias('joli_media.metadata.storage'));
        $this->assertSame('joli_media.metadata.storage', (string) $container->getAlias(MetadataStorageInterface::class));
    }

    public function testTheFollowListenerIsRemovedWhenDoctrineBundleIsPresent(): void
    {
        $container = $this->createContainer(['enabled' => true, 'storage' => 'app.metadata_storage'], ['DoctrineBundle' => DoctrineBundle::class]);

        $this->assertTrue($container->hasDefinition('joli_media.metadata.event_listener.follow'));

        (new DoctrinePass())->process($container);

        $this->assertFalse($container->hasDefinition('joli_media.metadata.event_listener.follow'));

        foreach (['media_move', 'folder_move', 'media_delete', 'folder_delete'] as $listener) {
            $arguments = $container->getDefinition('joli_media.event_listener.' . $listener)->getArguments();
            $this->assertSame('joli_media.metadata.storage', (string) $arguments['$metadataStorage']);
        }
    }

    public function testTheFollowListenerIsKeptWithoutDoctrineBundle(): void
    {
        $container = $this->createContainer(['enabled' => true, 'storage' => 'app.metadata_storage']);

        (new DoctrinePass())->process($container);

        $this->assertFalse($container->hasDefinition('joli_media.event_listener.media_move'));
        $this->assertTrue($container->hasDefinition('joli_media.metadata.event_listener.follow'));
        $events = array_column($container->getDefinition('joli_media.metadata.event_listener.follow')->getTag('kernel.event_listener'), 'method', 'event');
        $this->assertSame([
            MediaEvents::POST_MOVE_MEDIA => 'onMediaPostMove',
            MediaEvents::POST_MOVE_FOLDER => 'onFolderPostMove',
            MediaEvents::POST_DELETE_MEDIA => 'onMediaPostDelete',
            MediaEvents::POST_DELETE_FOLDER => 'onFolderPostDelete',
        ], $events);
    }

    /**
     * @param array<string, mixed>  $metadataConfig
     * @param array<string, string> $bundles
     */
    private function createContainer(array $metadataConfig, array $bundles = []): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.bundles' => $bundles,
            'kernel.project_dir' => __DIR__,
            'kernel.debug' => false,
            'kernel.environment' => 'test',
            'kernel.cache_dir' => sys_get_temp_dir(),
            'kernel.build_dir' => sys_get_temp_dir(),
        ]));

        $bundle = new JoliMediaBundle();
        $extension = $bundle->getContainerExtension();
        \assert($extension instanceof ExtensionInterface);
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
