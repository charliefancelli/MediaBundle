<?php

namespace JoliCode\MediaBundle\Tests\Usage\EventListener;

use JoliCode\MediaBundle\Event\PreDeleteFolderEvent;
use JoliCode\MediaBundle\Storage\OriginalStorage;
use JoliCode\MediaBundle\Usage\EventListener\UsageDeleteGuardListener;
use PHPUnit\Framework\TestCase;

class UsageDeleteGuardListenerUnitTest extends TestCase
{
    public function testNothingIsListedWithoutProvider(): void
    {
        $storage = $this->createMock(OriginalStorage::class);
        $storage->expects($this->never())->method('listMedias');

        $listener = new UsageDeleteGuardListener([]);
        $listener->onFolderPreDelete(new PreDeleteFolderEvent($storage, 'folder'));
    }
}
