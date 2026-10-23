<?php

namespace JoliCode\MediaBundle\Tests\Metadata\EventListener;

use JoliCode\MediaBundle\Event\PostDeleteFolderEvent;
use JoliCode\MediaBundle\Event\PostDeleteMediaEvent;
use JoliCode\MediaBundle\Event\PostMoveFolderEvent;
use JoliCode\MediaBundle\Event\PostMoveMediaEvent;
use JoliCode\MediaBundle\Metadata\EventListener\MetadataFollowListener;
use JoliCode\MediaBundle\Tests\BaseTestCase;
use JoliCode\MediaBundle\Tests\Metadata\RecordingMetadataStorage;

class MetadataFollowListenerTest extends BaseTestCase
{
    public function testTheStorageFollowsTheMediaOfTheEventLibrary(): void
    {
        $storage = new RecordingMetadataStorage();
        $listener = new MetadataFollowListener($storage);
        $original = $this->libraries->get('default')->getOriginalStorage();

        $listener->onMediaPostMove(new PostMoveMediaEvent($original, 'a.png', 'b.png'));
        $listener->onFolderPostMove(new PostMoveFolderEvent($original, 'a', 'b'));
        $listener->onMediaPostDelete(new PostDeleteMediaEvent($original, 'c.png'));
        $listener->onFolderPostDelete(new PostDeleteFolderEvent($original, 'c'));

        self::assertSame([
            ['move', 'default', 'a.png', 'b.png'],
            ['moveFolder', 'default', 'a', 'b'],
            ['delete', 'default', 'c.png'],
            ['deleteFolder', 'default', 'c'],
        ], $storage->mutations);
    }
}
