<?php

namespace JoliCode\MediaBundle\Tests\Metadata;

use JoliCode\MediaBundle\Metadata\Storage\DoctrineMetadataStorage;

/**
 * Fails before touching the rows when a media is moved or deleted.
 */
readonly class FailingMetadataStorage extends DoctrineMetadataStorage
{
    #[\Override]
    public function delete(string $library, string $path): void
    {
        throw new \RuntimeException('The metadata storage is down.');
    }

    #[\Override]
    public function deleteFolder(string $library, string $folder): void
    {
        throw new \RuntimeException('The metadata storage is down.');
    }

    #[\Override]
    public function move(string $library, string $from, string $to): void
    {
        throw new \RuntimeException('The metadata storage is down.');
    }

    #[\Override]
    public function moveFolder(string $library, string $from, string $to): void
    {
        throw new \RuntimeException('The metadata storage is down.');
    }
}
