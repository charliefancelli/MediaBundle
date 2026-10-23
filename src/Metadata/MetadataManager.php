<?php

namespace JoliCode\MediaBundle\Metadata;

use JoliCode\MediaBundle\Event\MediaEvents;
use JoliCode\MediaBundle\Event\PostSaveMetadataEvent;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Writes the metadata of the media: an empty metadata deletes its row.
 *
 * The given instance does not need to be the stored one: a copy, or a new instance for a media which already has
 * metadata, replaces the stored values (see MetadataStorageInterface::save()).
 */
readonly class MetadataManager
{
    public function __construct(
        private MetadataStorageInterface $storage,
        private MetadataProvider $provider,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function save(MediaMetadata $metadata): void
    {
        // a row is deleted only when the metadata were stored: they have an id, or a row exists at their path
        $deleted = $metadata->isEmpty()
            && (null !== $metadata->getId() || $this->storage->get($metadata->getLibrary(), $metadata->getPath()) instanceof MediaMetadata);

        try {
            $this->storage->save($metadata);
        } finally {
            // even after a failure: the memoized instance may hold the values which were not saved
            $this->provider->forget($metadata->getLibrary(), $metadata->getPath());
        }

        if ($this->dispatcher->hasListeners(MediaEvents::POST_SAVE_METADATA)) {
            $this->dispatcher->dispatch(new PostSaveMetadataEvent($metadata, $deleted), MediaEvents::POST_SAVE_METADATA);
        }
    }
}
