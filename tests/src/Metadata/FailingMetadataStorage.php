<?php

namespace JoliCode\MediaBundle\Tests\Metadata;

use JoliCode\MediaBundle\Metadata\Storage\DoctrineMetadataStorage;

/**
 * Does the work, then fails: the caller's transaction must roll the rows back.
 */
readonly class FailingMetadataStorage extends DoctrineMetadataStorage
{
    #[\Override]
    public function delete(string $library, string $path): void
    {
        parent::delete($library, $path);

        throw new \RuntimeException('The metadata storage is down.');
    }

    #[\Override]
    public function deleteFolder(string $library, string $folder): void
    {
        parent::deleteFolder($library, $folder);

        throw new \RuntimeException('The metadata storage is down.');
    }

    #[\Override]
    public function move(string $library, string $from, string $to): void
    {
        parent::move($library, $from, $to);

        throw new \RuntimeException('The metadata storage is down.');
    }

    #[\Override]
    public function moveFolder(string $library, string $from, string $to): void
    {
        parent::moveFolder($library, $from, $to);

        throw new \RuntimeException('The metadata storage is down.');
    }
}
