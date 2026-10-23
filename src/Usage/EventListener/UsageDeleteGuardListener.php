<?php

namespace JoliCode\MediaBundle\Usage\EventListener;

use JoliCode\MediaBundle\Event\PreDeleteFolderEvent;
use JoliCode\MediaBundle\Event\PreDeleteMediaEvent;
use JoliCode\MediaBundle\Exception\MediaInUseException;
use JoliCode\MediaBundle\Storage\OriginalStorage;
use JoliCode\MediaBundle\Usage\MediaUsageProviderInterface;

/**
 * Prevents the deletion of a media (or of a folder holding one) when a usage provider reports a blocking usage.
 *
 * Only the providers are consulted: the Doctrine columns with a "restrict" behavior are already guarded
 * by the delete listeners.
 */
class UsageDeleteGuardListener
{
    /**
     * Medias already cleared by the check of the folder being deleted (key: "library|path"), so that their own
     * pre-delete event does not ask the providers again. An entry is dropped once used, the set is replaced by
     * the next folder check: it stays bounded by the size of one folder.
     *
     * @var array<string, true>
     */
    private array $clearedByFolder = [];

    /**
     * @param iterable<MediaUsageProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers = [],
    ) {
    }

    public function onMediaPreDelete(PreDeleteMediaEvent $event): void
    {
        $key = $this->key($event->originalStorage, $event->path);

        if (isset($this->clearedByFolder[$key])) {
            unset($this->clearedByFolder[$key]);

            return;
        }

        $this->assertNotBlocked($event->originalStorage, $event->path);
    }

    public function onFolderPreDelete(PreDeleteFolderEvent $event): void
    {
        $storage = $event->originalStorage;

        if (!$this->hasProvider()) {
            return;
        }

        $this->clearedByFolder = [];

        // listMedias() loads the whole listing before returning: there is no lazy public API to stop early
        foreach ($storage->listMedias($event->path, recursive: true) as $media) {
            $this->assertNotBlocked($storage, $media->getPath());
            $this->clearedByFolder[$this->key($storage, $media->getPath())] = true;
        }
    }

    private function assertNotBlocked(OriginalStorage $storage, string $path): void
    {
        // deleting a folder stages its medias in the trash: they were already checked under their real path
        if ($storage->isTrashPath($path)) {
            return;
        }

        $libraryName = $storage->getLibrary()->getName();

        foreach ($this->providers as $provider) {
            foreach ($provider->getUsages($libraryName, $path) as $usage) {
                if ($usage->blocksDeletion) {
                    throw new MediaInUseException($path, $usage->entityClass ?? $usage->label, $usage->field ?? '');
                }
            }
        }
    }

    private function key(OriginalStorage $storage, string $path): string
    {
        return $storage->getLibrary()->getName() . '|' . $path;
    }

    private function hasProvider(): bool
    {
        foreach ($this->providers as $_) {
            return true;
        }

        return false;
    }
}
