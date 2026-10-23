<?php

namespace JoliCode\MediaBundle\Tests\Event\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use JoliCode\MediaBundle\Exception\MediaInUseException;
use JoliCode\MediaBundle\Library\LibraryContainer;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Storage\OriginalStorage;
use JoliCode\MediaBundle\Tests\Application\Entity\Article;
use JoliCode\MediaBundle\Tests\Application\Entity\Page;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\StringInput;

class DeleteFolderEventListenerTest extends WebTestCase
{
    use MetadataTestTrait;

    private EntityManagerInterface $entityManager;

    private EntityRepository $articleRepository;

    private EntityRepository $pageRepository;

    private OriginalStorage $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $container = static::getContainer();

        /** @var EntityManagerInterface */
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        $this->articleRepository = $entityManager->getRepository(Article::class);
        $this->pageRepository = $entityManager->getRepository(Page::class);

        /** @var LibraryContainer */
        $libraries = $container->get('joli_media.library_container');
        $this->originalStorage = $libraries->get('default')->getOriginalStorage();

        $this->setUpMetadata();

        if (self::$kernel instanceof Kernel) {
            $application = new Application(self::$kernel);
            $application->setAutoExit(false);
            $application->run(new StringInput('doctrine:fixtures:load --purge-with-truncate --no-interaction --quiet'));
        }
    }

    #[DataProvider('provideFolderPaths')]
    public function testFolderDeleteWhenNoMediaInUse(string $folderPath): void
    {
        $baseFolderName = 'test-folder-empty';
        $fullFolderPath = $folderPath . '/' . $baseFolderName;

        $dummyFilePath = $fullFolderPath . '/dummy.txt';
        $this->originalStorage->createMedia($dummyFilePath, 'dummy content')->store();
        $this->assertTrue($this->originalStorage->has($dummyFilePath), \sprintf('Dummy file %s should exist after creation.', $dummyFilePath));

        $this->originalStorage->deleteDirectory($fullFolderPath);
        $this->assertFalse($this->originalStorage->has($dummyFilePath), \sprintf('Dummy file %s should not exist after folder deletion.', $dummyFilePath));
        $this->assertFalse($this->originalStorage->has($fullFolderPath), \sprintf('Folder %s should not exist after deletion.', $fullFolderPath));

        // the deletion stages the folder in the trash before removing it: the trash
        // is hidden from the listings, but the deletion must still empty it
        $trashPath = $this->originalStorage->getTrashPath();
        $this->assertSame(
            [],
            $this->originalStorage->getFilesystem()->listContents($trashPath, true)->toArray(),
            'The temporary trash directory should be emptied after the folder deletion.',
        );
    }

    #[DataProvider('provideFolderPaths')]
    public function testFolderDeleteWhenMediaInUseRestrict(string $folderPath): void
    {
        $filename = 'circle-pattern.png';
        $previousCount = $this->articleRepository->count([
            'media' => 'sub/folder/' . $filename,
        ]);

        try {
            $this->expectException(MediaInUseException::class);
            $this->expectExceptionMessage('The media "sub/folder/circle-pattern.png" is used in the "mediaRestrict" field of the "JoliCode\MediaBundle\Tests\Application\Entity\Page" entity. It cannot be deleted.');
            $this->originalStorage->deleteDirectory($folderPath);
        } finally {
            $this->assertTrue(
                $this->originalStorage->has('sub/folder/' . $filename),
                'The media should still exist in the storage when it is used by one entity with restrictions.',
            );

            $count = $this->articleRepository->count([
                'media' => 'sub/folder/' . $filename,
            ]);

            $this->assertSame($previousCount, $count, 'The media should not be detached from any entity when it is used by one entity with restrictions.');
        }
    }

    #[DataProvider('provideFolderPaths')]
    public function testFolderDeleteWhenMediaInUseSetNull(string $folderPath): void
    {
        $subfolder = 'deep';
        $filename = $subfolder . '/test.txt';

        $this->originalStorage->deleteDirectory($folderPath . '/' . $subfolder);

        $this->assertFalse(
            $this->originalStorage->has($folderPath . '/' . $filename),
            'The media should be deleted from the storage when it is not used in any protected field.',
        );

        $count = $this->pageRepository->count([
            'mediaDefault' => 'sub/folder/' . $filename,
        ]);
        $this->assertSame(1, $count, 'The media path should be let intact in entity fields that do not define a deletion behavior.');

        $count = $this->pageRepository->count([
            'mediaSetNull' => 'sub/folder/' . $filename,
        ]);
        $this->assertSame(0, $count, 'The media path should be set to null in all entities the define the SET_NULL deletion behavior.');

        $count = $this->articleRepository->count([
            'media' => 'sub/folder/' . $filename,
        ]);
        $this->assertSame(0, $count, 'The media path should be let intact in entity fields that do not define a deletion behavior.');
    }

    public static function provideFolderPaths(): \Generator
    {
        yield 'ok' => ['sub/folder'];
        yield 'leading_slash' => ['/sub/folder'];
        yield 'consecutive_slashes' => ['sub//folder'];
        yield 'consecutive_slashes_with_leading' => ['/sub//folder'];
        yield 'consecutive_slashes_with_leading_and_trailing' => ['/sub//folder/'];
    }

    public function testMetadataIsDeletedWithTheFolder(): void
    {
        $this->seed('default', 'sub/folder/deep/test.txt');
        $this->seed('default', 'sub/folder/deep/nested/a.png');
        $this->seed('default', 'sub/folder/circle-pattern.png');
        $this->seed('default', 'sub/folder/deeper/b.png');
        $this->seed('auto_generate', 'sub/folder/deep/test.txt');

        $this->originalStorage->deleteDirectory('/sub//folder/deep/');

        $this->assertFalse($this->originalStorage->has('sub/folder/deep/test.txt'));
        $this->assertNull($this->altOf('default', 'sub/folder/deep/test.txt'));
        $this->assertNull($this->altOf('default', 'sub/folder/deep/nested/a.png'));
        // neither the parent folder nor a sibling sharing the prefix are affected
        $this->assertSame('Alt of sub/folder/circle-pattern.png', $this->altOf('default', 'sub/folder/circle-pattern.png'));
        $this->assertSame('Alt of sub/folder/deeper/b.png', $this->altOf('default', 'sub/folder/deeper/b.png'));
        // the same path in another library is another media
        $this->assertSame('Alt of sub/folder/deep/test.txt', $this->altOf('auto_generate', 'sub/folder/deep/test.txt'));

        // no row is left under the path of the trash either
        $this->entityManager->clear();
        $trashed = array_filter(
            $this->entityManager->getRepository(MediaMetadata::class)->findAll(),
            static fn (MediaMetadata $metadata): bool => str_contains($metadata->getPath(), 'deep/test.txt') && 'default' === $metadata->getLibrary(),
        );
        $this->assertSame([], $trashed);
    }

    public function testMetadataStaysWhenTheFolderDeletionIsRestricted(): void
    {
        $this->seed('default', 'sub/folder/circle-pattern.png');
        $this->seed('default', 'sub/folder/deep/test.txt');

        try {
            $this->originalStorage->deleteDirectory('sub/folder');
            $this->fail('The deletion should have been refused.');
        } catch (MediaInUseException) {
        }

        $this->assertSame('Alt of sub/folder/circle-pattern.png', $this->altOf('default', 'sub/folder/circle-pattern.png'));
        $this->assertSame('Alt of sub/folder/deep/test.txt', $this->altOf('default', 'sub/folder/deep/test.txt'));
    }

    public function testMetadataStaysWhenTheFolderDeletionFails(): void
    {
        $this->seed('default', 'sub/folder/deep/test.txt');

        $this->makeTheMetadataStorageFail();

        try {
            $this->originalStorage->deleteDirectory('sub/folder/deep');
            $this->fail('The deletion should have failed.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertSame('The metadata storage is down.', $runtimeException->getMessage());
        }

        $this->assertTrue($this->originalStorage->has('sub/folder/deep/test.txt'));
        // the SET_NULL column update has been rolled back
        $this->assertSame(1, $this->pageRepository->count(['mediaSetNull' => 'sub/folder/deep/test.txt']));
        $this->assertSame('Alt of sub/folder/deep/test.txt', $this->altOf('default', 'sub/folder/deep/test.txt'));
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }
}
