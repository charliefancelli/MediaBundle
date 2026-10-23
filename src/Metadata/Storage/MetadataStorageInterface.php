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
     *
     * An instance which is not the stored one (a copy, a detached instance, or a new instance for a library and a path
     * which already have a row) is merged onto the stored row: its values replace the stored ones, and an empty one
     * deletes the row. An empty instance which matches no row is ignored.
     *
     * A non-empty instance which matches no row (its row was deleted since it was read, for instance) is stored again,
     * with its values: save() stores what it is given.
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
     * Names are compared as the database collation does (case and accent insensitive with MySQL's default collations).
     *
     * @return list<string> distinct tag names, most used first, then alphabetically
     */
    public function listTags(string $library, int $limit = 100): array;

    /**
     * The tag is compared as the database collation does (case and accent insensitive with MySQL's default collations).
     *
     * @return list<string>
     */
    public function findPathsByTag(string $library, string $tag): array;
}
