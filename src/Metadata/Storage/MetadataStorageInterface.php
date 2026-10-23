<?php

namespace JoliCode\MediaBundle\Metadata\Storage;

use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;

/**
 * Stores the editorial metadata of the media, by library and path.
 */
interface MetadataStorageInterface
{
    public function get(string $library, string $path): ?MediaMetadata;

    /**
     * @param list<string> $paths
     *
     * @return array<string, MediaMetadata> indexed by (normalized) path, missing paths are absent
     */
    public function getMany(string $library, array $paths): array;

    /**
     * Persists the metadata, or deletes its row when it is empty.
     */
    public function save(MediaMetadata $metadata): void;

    public function delete(string $library, string $path): void;

    public function deleteFolder(string $library, string $folder): void;

    /**
     * Any row already stored at $to is replaced.
     */
    public function move(string $library, string $from, string $to): void;

    /**
     * Any row already stored under $to is replaced.
     */
    public function moveFolder(string $library, string $from, string $to): void;

    /**
     * @return list<string> distinct tag names, most used first, then alphabetically
     */
    public function listTags(string $library, int $limit = 100): array;

    /**
     * @return list<string>
     */
    public function findPathsByTag(string $library, string $tag): array;
}
