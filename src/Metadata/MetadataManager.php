<?php

namespace JoliCode\MediaBundle\Metadata;

use JoliCode\MediaBundle\Event\MediaEvents;
use JoliCode\MediaBundle\Event\PostSaveMetadataEvent;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Writes the metadata of the media: an empty metadata deletes its row.
 */
class MetadataManager
{
    public function __construct(
        private readonly MetadataStorageInterface $storage,
        private readonly MetadataProvider $provider,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function save(MediaMetadata $metadata): void
    {
        $deleted = $metadata->isEmpty();

        $this->storage->save($metadata);
        $this->provider->forget($metadata->getLibrary(), $metadata->getPath());

        if ($this->dispatcher->hasListeners(MediaEvents::POST_SAVE_METADATA)) {
            $this->dispatcher->dispatch(new PostSaveMetadataEvent($metadata, $deleted), MediaEvents::POST_SAVE_METADATA);
        }
    }
}
