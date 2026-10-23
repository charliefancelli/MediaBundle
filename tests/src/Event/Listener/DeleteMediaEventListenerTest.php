<?php

namespace JoliCode\MediaBundle\Tests\Event\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use JoliCode\MediaBundle\Exception\MediaInUseException;
use JoliCode\MediaBundle\Library\LibraryContainer;
use JoliCode\MediaBundle\Metadata\MetadataProvider;
use JoliCode\MediaBundle\Storage\OriginalStorage;
use JoliCode\MediaBundle\Tests\Application\Entity\Article;
use JoliCode\MediaBundle\Tests\Application\Entity\Page;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\StringInput;

class DeleteMediaEventListenerTest extends WebTestCase
{
    use MetadataTestTrait;

    private EntityRepository $articleRepository;

    private EntityRepository $pageRepository;

    private OriginalStorage $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $container = static::getContainer();

        /** @var EntityManagerInterface */
        $entityManager = $container->get(EntityManagerInterface::class);
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

    #[DataProvider('provideMediaPaths')]
    public function testMediaDeleteWhenMediaRestricted(string $folderPath): void
    {
        $filename = 'circle-pattern.png';
        $previousCount = $this->articleRepository->count([
            'media' => 'sub/folder/' . $filename,
        ]);

        try {
            $this->expectException(MediaInUseException::class);
            $this->expectExceptionMessage('The media "sub/folder/' . $filename . '" is used in the "mediaRestrict" field of the "JoliCode\MediaBundle\Tests\Application\Entity\Page" entity. It cannot be deleted.');
            $this->originalStorage->delete($folderPath . '/' . $filename);
        } finally {
            $this->assertTrue(
                $this->originalStorage->has($folderPath . '/' . $filename),
                'The media should still exist in the storage when it is used by one entity with restrictions.',
            );

            $count = $this->articleRepository->count([
                'media' => 'sub/folder/' . $filename,
            ]);

            $this->assertSame($previousCount, $count, 'The media should not be detached from any entity when it is used by one entity with restrictions.');
        }
    }

    #[DataProvider('provideMediaPaths')]
    public function testMediaDeleteWhenMediaSetNull(string $folderPath): void
    {
        $filename = 'deep/test.txt';

        $this->originalStorage->delete($folderPath . '/' . $filename);

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

    public static function provideMediaPaths(): \Generator
    {
        yield 'ok' => ['sub/folder'];
        yield 'leading_slash' => ['/sub/folder'];
        yield 'consecutive_slashes' => ['sub//folder'];
        yield 'consecutive_slashes_with_leading' => ['/sub//folder'];
        yield 'consecutive_slashes_with_leading_and_trailing' => ['/sub//folder/'];
    }

    public function testMetadataIsDeletedWithTheMedia(): void
    {
        $this->seed('default', 'not_used/default.pdf');
        $this->seed('auto_generate', 'not_used/default.pdf');

        $this->originalStorage->delete('/not_used//default.pdf');

        $this->assertFalse($this->originalStorage->has('not_used/default.pdf'));
        $this->assertNull($this->altOf('default', 'not_used/default.pdf'));
        // the same path in another library is another media
        $this->assertSame('Alt of not_used/default.pdf', $this->altOf('auto_generate', 'not_used/default.pdf'));
    }

    public function testMetadataStaysWhenTheDeletionIsRestricted(): void
    {
        $this->seed('default', 'restrict.pdf');

        try {
            $this->originalStorage->delete('restrict.pdf');
            $this->fail('The deletion should have been refused.');
        } catch (MediaInUseException) {
        }

        $this->assertTrue($this->originalStorage->has('restrict.pdf'));
        $this->assertSame('Alt of restrict.pdf', $this->altOf('default', 'restrict.pdf'));
    }

    public function testMetadataStaysWhenTheDeletionFails(): void
    {
        $this->seed('default', 'set_null.pdf');

        $this->makeTheMetadataStorageFail();

        try {
            $this->originalStorage->delete('set_null.pdf');
            $this->fail('The deletion should have failed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The metadata storage is down.', $exception->getMessage());
        }

        $this->assertTrue($this->originalStorage->has('set_null.pdf'));
        // the SET_NULL column update has been rolled back
        $this->assertSame(1, $this->pageRepository->count(['mediaSetNull' => 'set_null.pdf']));
        $this->assertSame('Alt of set_null.pdf', $this->altOf('default', 'set_null.pdf'));
    }

    public function testTheProviderDoesNotServeStaleMetadataAfterADeletion(): void
    {
        $this->seed('default', 'not_used/default.pdf');
        /** @var MetadataProvider $provider */
        $provider = static::getContainer()->get('joli_media.metadata.provider');
        $this->assertSame('Alt of not_used/default.pdf', $provider->get('not_used/default.pdf')?->getAlt());

        $this->originalStorage->delete('not_used/default.pdf');

        $this->assertNull($provider->get('not_used/default.pdf'));
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }
}
