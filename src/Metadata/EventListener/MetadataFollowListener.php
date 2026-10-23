<?php

namespace JoliCode\MediaBundle\Metadata\EventListener;

use JoliCode\MediaBundle\Event\PostDeleteFolderEvent;
use JoliCode\MediaBundle\Event\PostDeleteMediaEvent;
use JoliCode\MediaBundle\Event\PostMoveFolderEvent;
use JoliCode\MediaBundle\Event\PostMoveMediaEvent;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;

/**
 * Moves and deletes the metadata along with their media when DoctrineBundle is absent: with it, the Doctrine
 * listeners do the same inside their own transaction, and this one is removed.
 */
readonly class MetadataFollowListener
{
    public function __construct(
        private MetadataStorageInterface $metadataStorage,
    ) {
    }

    public function onFolderPostDelete(PostDeleteFolderEvent $event): void
    {
        $this->metadataStorage->deleteFolder($event->originalStorage->getLibrary()->getName(), $event->path);
    }

    public function onFolderPostMove(PostMoveFolderEvent $event): void
    {
        $this->metadataStorage->moveFolder($event->originalStorage->getLibrary()->getName(), $event->from, $event->to);
    }

    public function onMediaPostDelete(PostDeleteMediaEvent $event): void
    {
        $this->metadataStorage->delete($event->originalStorage->getLibrary()->getName(), $event->path);
    }

    public function onMediaPostMove(PostMoveMediaEvent $event): void
    {
        $this->metadataStorage->move($event->originalStorage->getLibrary()->getName(), $event->from, $event->to);
    }
}
