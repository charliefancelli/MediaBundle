<?php

namespace JoliCode\MediaBundle\Tests\Usage\EventListener;

use JoliCode\MediaBundle\Event\PreDeleteFolderEvent;
use JoliCode\MediaBundle\Event\PreDeleteMediaEvent;
use JoliCode\MediaBundle\Library\Library;
use JoliCode\MediaBundle\Model\Media;
use JoliCode\MediaBundle\Storage\OriginalStorage;
use JoliCode\MediaBundle\Usage\EventListener\UsageDeleteGuardListener;
use JoliCode\MediaBundle\Usage\MediaUsageProviderInterface;
use PHPUnit\Framework\TestCase;

class UsageDeleteGuardListenerUnitTest extends TestCase
{
    public function testNothingIsListedWithoutProvider(): void
    {
        $storage = $this->createMock(OriginalStorage::class);
        $storage->expects($this->never())->method('listMedias');

        $listener = new UsageDeleteGuardListener([]);
        $listener->onFolderPreDelete(new PreDeleteFolderEvent($storage, 'folder'));
        $listener->onMediaPreDelete(new PreDeleteMediaEvent($storage, 'folder/a.png'));
    }

    public function testMediasCheckedByTheFolderAreNotCheckedAgain(): void
    {
        $library = $this->createStub(Library::class);
        $library->method('getName')->willReturn('default');

        $media = $this->createStub(Media::class);
        $media->method('getPath')->willReturn('folder/a.png');

        $storage = $this->createStub(OriginalStorage::class);
        $storage->method('getLibrary')->willReturn($library);
        $storage->method('isTrashPath')->willReturn(false);
        $storage->method('listMedias')->willReturn([$media]);

        $provider = new class implements MediaUsageProviderInterface {
            public int $calls = 0;

            public function getUsages(string $libraryName, string $path): iterable
            {
                ++$this->calls;

                return [];
            }
        };

        $listener = new UsageDeleteGuardListener([$provider]);
        $listener->onFolderPreDelete(new PreDeleteFolderEvent($storage, 'folder'));
        $listener->onMediaPreDelete(new PreDeleteMediaEvent($storage, 'folder/a.png'));
        $this->assertSame(1, $provider->calls, 'The folder check must cover the pre-delete event of its medias.');

        // the entry is consumed: a later deletion of the same media is checked again
        $listener->onMediaPreDelete(new PreDeleteMediaEvent($storage, 'folder/a.png'));
        $this->assertSame(2, $provider->calls);
    }
}
