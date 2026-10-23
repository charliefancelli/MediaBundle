<?php

namespace JoliCode\MediaBundle\Tests\Metadata;

use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Metadata\Storage\DoctrineMetadataStorage;

/**
 * Reads like the Doctrine storage, but never manages to save.
 */
readonly class UnsavableMetadataStorage extends DoctrineMetadataStorage
{
    #[\Override]
    public function save(MediaMetadata $metadata): void
    {
        throw new \RuntimeException('The metadata storage is down.');
    }
}
