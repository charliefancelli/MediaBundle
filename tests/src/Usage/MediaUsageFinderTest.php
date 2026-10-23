<?php

namespace JoliCode\MediaBundle\Tests\Usage;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use JoliCode\MediaBundle\Cache\MediaEntityMetadata;
use JoliCode\MediaBundle\Cache\MediaEntityMetadataWarmer;
use JoliCode\MediaBundle\Doctrine\Types;
use JoliCode\MediaBundle\Library\LibraryContainer;
use JoliCode\MediaBundle\Tests\Application\Entity\Article;
use JoliCode\MediaBundle\Tests\Application\Entity\Page;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use JoliCode\MediaBundle\Tests\Application\Usage\StaticMediaUsageProvider;
use JoliCode\MediaBundle\Usage\MediaUsage;
use JoliCode\MediaBundle\Usage\MediaUsageFinder;
use JoliCode\MediaBundle\Usage\MediaUsageUrlGeneratorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Contracts\Cache\CacheInterface;

class MediaUsageFinderTest extends WebTestCase
{
    private MediaUsageFinder $finder;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var MediaUsageFinder $finder */
        $finder = static::getContainer()->get('joli_media.usage_finder');
        $this->finder = $finder;

        if (self::$kernel instanceof Kernel) {
            $application = new Application(self::$kernel);
            $application->setAutoExit(false);
            $application->run(new StringInput('doctrine:fixtures:load --purge-with-truncate --no-interaction --quiet'));
        }
    }

    protected function tearDown(): void
    {
        StaticMediaUsageProvider::reset();

        parent::tearDown();
    }

    public function testFindsTheDoctrineColumns(): void
    {
        $usages = $this->finder->find('restrict.pdf');

        $this->assertCount(2, $usages);

        $labels = array_map(static fn (MediaUsage $usage): string => $usage->label, $usages);
        sort($labels);
        $this->assertSame(['Page 1', 'Page 2'], $labels);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $expectedIds = array_map(static fn (Page $page): string => $page->getId(), $entityManager->getRepository(Page::class)->findBy(['mediaRestrict' => 'restrict.pdf']));
        sort($expectedIds);
        $ids = array_map(static fn (MediaUsage $usage): int|string|null => $usage->identifier, $usages);
        sort($ids);
        $this->assertSame($expectedIds, $ids);

        foreach ($usages as $usage) {
            $this->assertSame(Page::class, $usage->entityClass);
            $this->assertSame('mediaRestrict', $usage->field);
            $this->assertTrue($usage->blocksDeletion);
            // the EasyAdmin bridge of the test application links the usages to their edit page
            $this->assertNotNull($usage->editUrl);
            $this->assertStringContainsString((string) $usage->identifier, $usage->editUrl);
        }
    }

    public function testSetNullColumnsDoNotBlock(): void
    {
        $usages = $this->finder->find('set_null.pdf');

        $this->assertCount(1, $usages);
        $this->assertSame('mediaSetNull', $usages[0]->field);
        $this->assertFalse($usages[0]->blocksDeletion);
    }

    public function testColumnsWithoutDeleteBehaviorDoNotBlock(): void
    {
        $usages = $this->finder->find('default.pdf');

        $this->assertCount(1, $usages);
        $this->assertSame('mediaDefault', $usages[0]->field);
        $this->assertFalse($usages[0]->blocksDeletion);
    }

    public function testFindsTheProviderUsages(): void
    {
        $usage = new MediaUsage('A CMS block', field: 'content', identifier: 12, blocksDeletion: true);
        StaticMediaUsageProvider::setUsages('default', 'not_used/default.pdf', [$usage]);

        $this->assertEquals([$usage], $this->finder->find('not_used/default.pdf', 'default'));
        $this->assertEquals([$usage], $this->finder->find('not_used/default.pdf'), 'The default library is used when none is given.');
        $this->assertSame([], $this->finder->find('not_used/default.pdf', 'auto_generate'));
    }

    public function testUnusedMediaHasNoUsage(): void
    {
        $this->assertSame([], $this->finder->find('not_used/default.pdf'));
    }

    public function testLimitIsHonoured(): void
    {
        StaticMediaUsageProvider::setUsages('default', 'restrict.pdf', [new MediaUsage('Block 1'), new MediaUsage('Block 2')]);

        $this->assertCount(4, $this->finder->find('restrict.pdf'));
        $this->assertCount(1, $this->finder->find('restrict.pdf', limit: 1));

        $usages = $this->finder->find('restrict.pdf', limit: 3);
        $this->assertCount(3, $usages);
        $this->assertSame('Block 1', $usages[2]->label);
    }

    public function testEditUrlIsFilledWhenAGeneratorExists(): void
    {
        StaticMediaUsageProvider::setUsages('default', 'restrict.pdf', [
            new MediaUsage('Block with URL', editUrl: '/already'),
            new MediaUsage('Block'),
            new MediaUsage('No URL'),
        ]);

        $generator = new class implements MediaUsageUrlGeneratorInterface {
            public function generateEditUrl(MediaUsage $usage): ?string
            {
                return 'No URL' === $usage->label ? null : '/edit/' . $usage->label;
            }
        };

        $finder = new MediaUsageFinder(
            $this->service('doctrine', Registry::class),
            $this->service(CacheInterface::class, CacheInterface::class),
            $this->service(LibraryContainer::class, LibraryContainer::class),
            [new StaticMediaUsageProvider()],
            urlGenerator: $generator,
        );

        $urls = array_map(static fn (MediaUsage $usage): ?string => $usage->editUrl, $finder->find('restrict.pdf'));

        $this->assertSame(['/edit/Page 1', '/edit/Page 2', '/already', '/edit/Block', null], $this->sortedColumnsFirst($urls));
    }

    public function testPathsTooLongForMediaColumnsOnlyMatchMediaLongColumns(): void
    {
        $path = 'a-folder-with-a-very-long-name-level-1/a-folder-with-a-very-long-name-level-2/a-folder-with-a-very-long-name-level-3/a-folder-with-a-very-long-name-level-4/a-folder-with-a-very-long-name-level-5/a-folder-with-a-very-long-name-level-6/a-folder-with-a-very-long-name-level-7/a-folder-with-a-very-long-name-level-8/a-folder-with-a-very-long-name-level-9/a-folder-with-a-very-long-name-level-10/test.txt';
        $this->assertGreaterThan(255, \strlen($path));

        $usages = $this->finder->find($path);

        $this->assertCount(1, $usages);
        $this->assertSame(Article::class, $usages[0]->entityClass);
        $this->assertSame('media', $usages[0]->field);

        $this->assertSame([], $this->finder->find(str_repeat('x', 300) . '.txt'));
    }

    public function testWithoutDoctrineOnlyTheProvidersAreUsed(): void
    {
        $usage = new MediaUsage('Block');
        StaticMediaUsageProvider::setUsages('default', 'restrict.pdf', [$usage]);

        $finder = new MediaUsageFinder(
            null,
            $this->service(CacheInterface::class, CacheInterface::class),
            $this->service(LibraryContainer::class, LibraryContainer::class),
            [new StaticMediaUsageProvider()],
        );

        $this->assertSame([$usage], $finder->find('restrict.pdf'));
    }

    public function testCompositeAndAssociationIdentifiersAreNormalised(): void
    {
        $entity = new class {
        };
        $related = new class {
        };
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'str';
            }
        };

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->method('getTypeOfField')->willReturn(Types::MEDIA);
        $classMetadata->method('getIdentifierFieldNames')->willReturn(['a', 'b', 'c']);
        $classMetadata->method('getReflectionClass')->willReturn(new \ReflectionClass($entity));
        $classMetadata->method('getIdentifierValues')->willReturnCallback(
            static fn (object $object): array => $object === $entity ? [7, $stringable, $related] : ['child'],
        );

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$entity]);

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($classMetadata);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('getConnection')->willReturn($connection);

        $registry = $this->createMock(Registry::class);
        $registry->method('getManager')->willReturn($entityManager);

        $cache = new ArrayAdapter();
        $cache->get(MediaEntityMetadataWarmer::MEDIA_FIELDS_CACHE_KEY, static fn (): array => [new MediaEntityMetadata('default', $entity::class, 'media')]);

        $finder = new MediaUsageFinder($registry, $cache, $this->service(LibraryContainer::class, LibraryContainer::class));
        $usages = $finder->find('a.png');

        $this->assertCount(1, $usages);
        $this->assertSame('7-str-child', $usages[0]->identifier);
        $this->assertStringEndsWith('#7-str-child', $usages[0]->label);
    }

    public function testInheritedFieldsAreOnlyReportedOnTheirDeclaringEntity(): void
    {
        $parent = new class {
        };
        $child = new class {
        };

        $parentMetadata = $this->createMock(ClassMetadata::class);
        $parentMetadata->method('getTypeOfField')->willReturn(Types::MEDIA);
        $parentMetadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $parentMetadata->method('getReflectionClass')->willReturn(new \ReflectionClass($parent));
        $parentMetadata->method('getIdentifierValues')->willReturn([1]);
        $parentMetadata->method('isInheritedField')->willReturn(false);

        $childMetadata = $this->createMock(ClassMetadata::class);
        $childMetadata->method('getTypeOfField')->willReturn(Types::MEDIA);
        $childMetadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $childMetadata->method('getReflectionClass')->willReturn(new \ReflectionClass($child));
        $childMetadata->method('getIdentifierValues')->willReturn([1]);
        $childMetadata->method('isInheritedField')->willReturn(true);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())->method('findBy')->willReturn([$parent]);

        $entityManager = $this->createEntityManager([$parent::class => $parentMetadata, $child::class => $childMetadata], $repository);

        $registry = $this->createMock(Registry::class);
        $registry->method('getManager')->willReturn($entityManager);

        $cache = new ArrayAdapter();
        $cache->get(MediaEntityMetadataWarmer::MEDIA_FIELDS_CACHE_KEY, static fn (): array => [
            new MediaEntityMetadata('default', $parent::class, 'media'),
            new MediaEntityMetadata('default', $child::class, 'media'),
        ]);

        $finder = new MediaUsageFinder($registry, $cache, $this->service(LibraryContainer::class, LibraryContainer::class));

        $this->assertCount(1, $finder->find('a.png'));
    }

    public function testMappedSuperclassesAreSkipped(): void
    {
        $superclass = new class {
        };

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->isMappedSuperclass = true;
        $classMetadata->method('getTypeOfField')->willReturn(Types::MEDIA);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->never())->method('findBy');

        $entityManager = $this->createEntityManager([$superclass::class => $classMetadata], $repository);

        $registry = $this->createMock(Registry::class);
        $registry->method('getManager')->willReturn($entityManager);

        $cache = new ArrayAdapter();
        $cache->get(MediaEntityMetadataWarmer::MEDIA_FIELDS_CACHE_KEY, static fn (): array => [new MediaEntityMetadata('default', $superclass::class, 'media')]);

        $finder = new MediaUsageFinder($registry, $cache, $this->service(LibraryContainer::class, LibraryContainer::class));

        $this->assertSame([], $finder->find('a.png'));
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /**
     * @param array<class-string, ClassMetadata<object>&MockObject> $metadata
     */
    private function createEntityManager(array $metadata, EntityRepository $repository): EntityManagerInterface
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturnCallback(static fn (string $class): ClassMetadata => $metadata[$class]);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('getConnection')->willReturn($connection);

        return $entityManager;
    }

    /**
     * @param list<string|null> $urls
     *
     * @return list<string|null>
     */
    private function sortedColumnsFirst(array $urls): array
    {
        $columns = \array_slice($urls, 0, 2);
        sort($columns);

        return [...$columns, ...\array_slice($urls, 2)];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $id, string $class): object
    {
        $service = static::getContainer()->get($id);
        $this->assertInstanceOf($class, $service);

        return $service;
    }
}
