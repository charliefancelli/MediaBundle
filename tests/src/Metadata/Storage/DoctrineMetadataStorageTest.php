<?php

namespace JoliCode\MediaBundle\Tests\Metadata\Storage;

use Doctrine\ORM\EntityManagerInterface;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadataTag;
use JoliCode\MediaBundle\Metadata\Storage\DoctrineMetadataStorage;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;
use JoliCode\MediaBundle\Tests\Application\Doctrine\QueryCounterMiddleware;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\StringInput;

class DoctrineMetadataStorageTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    private MetadataStorageInterface $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $container = static::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        /** @var MetadataStorageInterface $storage */
        $storage = $container->get('test.joli_media.metadata.storage');
        $this->storage = $storage;

        if (self::$kernel instanceof Kernel) {
            $application = new Application(self::$kernel);
            $application->setAutoExit(false);
            $application->run(new StringInput('doctrine:fixtures:load --purge-with-truncate --no-interaction --quiet'));
        }
    }

    public function testTheStorageIsAutowirableAndDoctrineBased(): void
    {
        $this->assertInstanceOf(DoctrineMetadataStorage::class, $this->storage);
    }

    public function testSaveThenGet(): void
    {
        $metadata = new MediaMetadata('default', 'a.png');
        $metadata->setAlt('An alt');
        $metadata->setTags(['one', 'two']);

        $this->storage->save($metadata);

        $this->entityManager->clear();

        $found = $this->storage->get('default', 'a.png');
        $this->assertNotNull($found);
        $this->assertNotSame($metadata, $found);
        $this->assertSame('An alt', $found->getAlt());
        $this->assertSame(['one', 'two'], $found->getTags());
        $this->assertNull($this->storage->get('default', 'missing.png'));
        $this->assertNull($this->storage->get('auto_generate', 'a.png'));
    }

    public function testSavingAnEmptyMetadataDeletesItsRow(): void
    {
        $metadata = $this->store('default', 'a.png', 'Alt', ['one']);

        $metadata->setAlt(null);
        $metadata->setTags([]);
        $this->assertTrue($metadata->isEmpty());
        $this->storage->save($metadata);

        $this->entityManager->clear();
        $this->assertNull($this->storage->get('default', 'a.png'));
        $this->assertSame(0, $this->countTags());

        // an empty metadata that was never stored is simply ignored
        $this->storage->save(new MediaMetadata('default', 'never.png'));
        $this->assertNull($this->storage->get('default', 'never.png'));
    }

    public function testSavingAnEmptyDetachedMetadataDeletesItsRow(): void
    {
        $this->store('default', 'a.png', 'Alt');
        $this->entityManager->clear();

        $copy = $this->storage->get('default', 'a.png');
        $this->assertNotNull($copy);
        $this->entityManager->detach($copy);
        $copy->setAlt(null);
        $this->storage->save($copy);

        $this->entityManager->clear();
        $this->assertNull($this->storage->get('default', 'a.png'));
    }

    public function testGetManyRunsOneQuery(): void
    {
        $this->store('default', 'a.png', 'A', ['one', 'two']);
        $this->store('default', 'b.png', 'B', ['two', 'three']);
        $this->store('default', 'c.png', 'C', ['four']);
        $this->store('default', 'd.png', 'D');
        $this->entityManager->clear();

        QueryCounterMiddleware::reset();
        $result = $this->storage->getMany('default', ['a.png', 'b.png', 'c.png', 'missing.png']);
        $queries = QueryCounterMiddleware::getQueries();

        $this->assertCount(1, $queries, implode("\n", $queries));
        $this->assertSame(['a.png', 'b.png', 'c.png'], array_keys($result));

        // reading the tags does not query again
        $this->assertSame(['one', 'two'], $result['a.png']->getTags());
        $this->assertSame(['two', 'three'], $result['b.png']->getTags());
        $this->assertSame(['four'], $result['c.png']->getTags());
        $this->assertCount(1, QueryCounterMiddleware::getQueries());
    }

    public function testGetManyWithoutPathsDoesNotQuery(): void
    {
        QueryCounterMiddleware::reset();

        $this->assertSame([], $this->storage->getMany('default', []));
        $this->assertSame([], QueryCounterMiddleware::getQueries());
    }

    public function testDeleteRemovesTheRowAndItsTags(): void
    {
        $this->store('default', 'a.png', 'A', ['one']);
        $this->store('default', 'b.png', 'B', ['two']);

        $this->storage->delete('default', 'a.png');

        $this->assertNull($this->storage->get('default', 'a.png'));
        $this->assertNotNull($this->storage->get('default', 'b.png'));
        $this->assertSame(1, $this->countTags());
    }

    public function testMoveRenamesTheRowAndDoesNotReturnAStaleInstance(): void
    {
        $this->store('default', 'a.png', 'A', ['one']);
        $managed = $this->storage->get('default', 'a.png');

        $this->storage->move('default', 'a.png', 'new/b.png');

        $this->assertNull($this->storage->get('default', 'a.png'));
        $moved = $this->storage->get('default', 'new/b.png');
        $this->assertNotNull($moved);
        $this->assertNotSame($managed, $moved);
        $this->assertSame('new/b.png', $moved->getPath());
        $this->assertSame('A', $moved->getAlt());
        $this->assertSame(['one'], $moved->getTags());
    }

    public function testMoveToTheSamePathChangesNothing(): void
    {
        $this->store('default', 'a.png', 'A');

        $this->storage->move('default', 'a.png', '/a.png');

        $this->assertSame('A', $this->storage->get('default', 'a.png')?->getAlt());
    }

    public function testMoveReplacesStaleMetadataAtTarget(): void
    {
        $this->store('default', 'a.png', 'Alt of a', ['one']);
        $this->store('default', 'b.png', 'Stale alt of b', ['stale']);

        $this->storage->move('default', 'a.png', 'b.png');

        $this->assertSame('Alt of a', $this->storage->get('default', 'b.png')?->getAlt());
        $this->assertSame(['one'], $this->storage->get('default', 'b.png')->getTags());
        $this->assertNull($this->storage->get('default', 'a.png'));
        $this->assertSame(1, $this->countTags());
    }

    public function testMoveFolderReplacesStaleMetadataUnderTarget(): void
    {
        $this->store('default', 'src/x.png', 'Alt of x');
        $this->store('default', 'dst/x.png', 'Stale');
        $this->store('default', 'dst/y.png', 'Stale y');

        $this->storage->moveFolder('default', 'src', 'dst');

        $this->assertSame('Alt of x', $this->storage->get('default', 'dst/x.png')?->getAlt());
        $this->assertNull($this->storage->get('default', 'dst/y.png'));
        $this->assertNull($this->storage->get('default', 'src/x.png'));
    }

    public function testMoveFolderRejectsAnEmptyTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->moveFolder('default', 'src', '');
    }

    #[DataProvider('provideNestedFolders')]
    public function testMoveFolderRejectsNestedFolders(string $from, string $to): void
    {
        $this->store('default', 'a/x.png', 'A');

        try {
            $this->storage->moveFolder('default', $from, $to);
            $this->fail('An exception was expected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame('A', $this->storage->get('default', 'a/x.png')?->getAlt());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNestedFolders(): iterable
    {
        yield 'into a child' => ['a', 'a/b'];
        yield 'onto its parent' => ['a/b', 'a'];
        yield 'onto itself' => ['a', '/a/'];
    }

    public function testDeleteFolderDoesNotReturnAStaleInstance(): void
    {
        $this->store('default', 'dir/a.png', 'A');
        $managed = $this->storage->get('default', 'dir/a.png');
        $this->assertNotNull($managed);

        $this->storage->deleteFolder('default', 'dir');

        $this->assertNull($this->storage->get('default', 'dir/a.png'));
        $this->assertFalse($this->entityManager->contains($managed));
    }

    public function testMoveFolderDoesNotReturnAStaleInstance(): void
    {
        $this->store('default', 'dir/a.png', 'A', ['one']);
        $managed = $this->storage->get('default', 'dir/a.png');

        $this->storage->moveFolder('default', 'dir', 'new');

        $this->assertNull($this->storage->get('default', 'dir/a.png'));
        $moved = $this->storage->get('default', 'new/a.png');
        $this->assertNotNull($moved);
        $this->assertNotSame($managed, $moved);
        $this->assertSame('new/a.png', $moved->getPath());
        $this->assertSame(['one'], $moved->getTags());
    }

    public function testPathsAreNormalizedOnSave(): void
    {
        $this->store('default', '/sub//a.png', 'A');

        $this->assertSame('sub/a.png', $this->storage->get('default', 'sub/a.png')?->getPath());
        $this->assertSame('A', $this->storage->get('default', '/sub/a.png')?->getAlt());
    }

    public function testSavingADetachedMetadataUpdatesTheStoredRow(): void
    {
        $this->store('default', 'a.png', 'A', ['one']);
        $detached = $this->storage->get('default', 'a.png');
        $this->assertNotNull($detached);
        $this->entityManager->detach($detached);

        $detached->setAlt('Changed');
        $detached->setTags(['two']);

        $this->storage->save($detached);

        $this->entityManager->clear();
        $found = $this->storage->get('default', 'a.png');
        $this->assertNotNull($found);
        $this->assertSame('Changed', $found->getAlt());
        $this->assertSame(['two'], $found->getTags());
        $this->assertSame(1, $this->countTags());
    }

    public function testSavingADetachedMetadataWhoseRowIsGoneInsertsItAgain(): void
    {
        $this->store('default', 'a.png', 'A');
        $metadata = $this->storage->get('default', 'a.png');
        $this->assertNotNull($metadata);

        $this->storage->delete('default', 'a.png');
        $this->storage->save($metadata);

        $this->entityManager->clear();
        $this->assertSame('A', $this->storage->get('default', 'a.png')?->getAlt());
    }

    public function testDeleteAndMoveDoNotFlushPendingChanges(): void
    {
        $this->store('default', 'a.png', 'A');
        $this->store('default', 'b.png', 'B');
        $other = $this->storage->get('default', 'b.png');
        $this->assertNotNull($other);
        $other->setAlt('Pending');

        $this->storage->delete('default', 'a.png');
        $this->storage->move('default', 'c.png', 'd.png');
        $this->storage->deleteFolder('default', 'nothing');
        $this->storage->moveFolder('default', 'nothing', 'else');

        $alt = $this->entityManager->getConnection()->fetchOne('SELECT alt FROM joli_media_metadata WHERE path = ?', ['b.png']);
        $this->assertSame('B', $alt);
        $this->assertTrue($this->entityManager->isOpen());
    }

    public function testFolderOperationsDoNotTouchSiblingPrefixes(): void
    {
        $this->store('default', 'sub/folder/x.png', 'in', ['t']);
        $this->store('default', 'sub/folder-2/x.png', 'dash');
        $this->store('default', 'sub/folderx.png', 'suffix');
        $this->store('default', 'sub/fo%der/x.png', 'percent', ['p']);
        $this->store('default', 'sub/fo_der/x.png', 'underscore');

        $this->storage->moveFolder('default', 'sub/folder/', 'moved');

        $this->assertSame('in', $this->storage->get('default', 'moved/x.png')?->getAlt());
        $this->assertNull($this->storage->get('default', 'sub/folder/x.png'));
        $this->assertNotNull($this->storage->get('default', 'sub/folder-2/x.png'));
        $this->assertNotNull($this->storage->get('default', 'sub/folderx.png'));
        $this->assertNotNull($this->storage->get('default', 'sub/fo%der/x.png'));

        $this->storage->deleteFolder('default', 'sub/fo%der');

        $this->assertNull($this->storage->get('default', 'sub/fo%der/x.png'));
        $this->assertNotNull($this->storage->get('default', 'sub/fo_der/x.png'));
        $this->assertNotNull($this->storage->get('default', 'sub/folder-2/x.png'));
        $this->assertNotNull($this->storage->get('default', 'sub/folderx.png'));
        $this->assertNotNull($this->storage->get('default', 'moved/x.png'));
    }

    public function testDeleteFolderAlsoDeletesTags(): void
    {
        $this->store('default', 'dir/a.png', 'A', ['one', 'two']);
        $this->store('default', 'dir/sub/b.png', 'B', ['three']);
        $this->store('default', 'other/c.png', 'C', ['four']);

        $this->storage->deleteFolder('default', 'dir');

        $this->assertNull($this->storage->get('default', 'dir/a.png'));
        $this->assertNull($this->storage->get('default', 'dir/sub/b.png'));
        $this->assertNotNull($this->storage->get('default', 'other/c.png'));
        $this->assertSame(1, $this->countTags());

        $this->storage->deleteFolder('default', 'other');
        $this->assertSame(0, $this->countTags());
    }

    public function testOperationsAreScopedToTheirLibrary(): void
    {
        $this->store('default', 'a.png', 'default a', ['one']);
        $this->store('auto_generate', 'a.png', 'auto a', ['one']);
        $this->store('default', 'dir/x.png', 'default x');
        $this->store('auto_generate', 'dir/x.png', 'auto x');

        $this->storage->move('default', 'a.png', 'b.png');
        $this->storage->moveFolder('default', 'dir', 'moved');

        $this->assertSame('auto a', $this->storage->get('auto_generate', 'a.png')?->getAlt());
        $this->assertSame('auto x', $this->storage->get('auto_generate', 'dir/x.png')?->getAlt());
        $this->assertNull($this->storage->get('default', 'a.png'));
        $this->assertSame('default x', $this->storage->get('default', 'moved/x.png')?->getAlt());

        $this->storage->delete('default', 'b.png');
        $this->storage->deleteFolder('default', 'moved');

        $this->assertNotNull($this->storage->get('auto_generate', 'a.png'));
        $this->assertNotNull($this->storage->get('auto_generate', 'dir/x.png'));
        $this->assertSame(1, $this->countTags());
    }

    public function testListTagsAndFindPathsByTag(): void
    {
        $this->store('default', 'a.png', null, ['summer', 'beach']);
        $this->store('default', 'b.png', null, ['summer', 'city']);
        $this->store('default', 'c.png', null, ['summer', 'Beach']);
        $this->store('auto_generate', 'd.png', null, ['other']);

        $tags = $this->storage->listTags('default');
        $this->assertSame('summer', $tags[0]);
        $this->assertContains('city', $tags);
        $this->assertContains('beach', array_map(strtolower(...), $tags));
        $this->assertSame('summer', $this->storage->listTags('default', 1)[0]);
        $this->assertCount(2, $this->storage->listTags('default', 2));
        $this->assertSame(['other'], $this->storage->listTags('auto_generate'));
        $this->assertSame(['a.png', 'b.png', 'c.png'], $this->storage->findPathsByTag('default', 'summer'));
        $this->assertContains('a.png', $this->storage->findPathsByTag('default', 'beach'));
        $this->assertSame([], $this->storage->findPathsByTag('default', 'other'));
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /**
     * @param list<string> $tags
     */
    private function store(string $library, string $path, ?string $alt, array $tags = []): MediaMetadata
    {
        $metadata = new MediaMetadata($library, $path);
        $metadata->setAlt($alt);
        $metadata->setTags($tags);

        $this->storage->save($metadata);

        return $metadata;
    }

    private function countTags(): int
    {
        return (int) $this->entityManager->createQuery('SELECT COUNT(t.id) FROM ' . MediaMetadataTag::class . ' t')->getSingleScalarResult();
    }
}
