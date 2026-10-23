<?php

namespace JoliCode\MediaBundle\Tests\Metadata;

use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;

/**
 * In-memory storage counting its calls.
 */
class RecordingMetadataStorage implements MetadataStorageInterface
{
    public int $getCalls = 0;

    public int $getManyCalls = 0;

    /**
     * @var list<array{string, list<string>}>
     */
    public array $getManyArguments = [];

    /**
     * @var array<string, MediaMetadata>
     */
    private array $rows = [];

    public function put(string $library, string $path, string $alt): MediaMetadata
    {
        $metadata = new MediaMetadata($library, $path);
        $metadata->setAlt($alt);

        return $this->rows[$library . "\0" . $path] = $metadata;
    }

    #[\Override]
    public function get(string $library, string $path): ?MediaMetadata
    {
        ++$this->getCalls;

        return $this->rows[$library . "\0" . $path] ?? null;
    }

    #[\Override]
    public function getMany(string $library, array $paths): array
    {
        ++$this->getManyCalls;
        $this->getManyArguments[] = [$library, $paths];

        $found = [];
        foreach ($paths as $path) {
            if (isset($this->rows[$library . "\0" . $path])) {
                $found[$path] = $this->rows[$library . "\0" . $path];
            }
        }

        return $found;
    }

    #[\Override]
    public function save(MediaMetadata $metadata): void
    {
        $key = $metadata->getLibrary() . "\0" . $metadata->getPath();

        if ($metadata->isEmpty()) {
            unset($this->rows[$key]);
        } else {
            $this->rows[$key] = $metadata;
        }
    }

    #[\Override]
    public function delete(string $library, string $path): void
    {
        unset($this->rows[$library . "\0" . $path]);
    }

    #[\Override]
    public function deleteFolder(string $library, string $folder): void
    {
    }

    #[\Override]
    public function move(string $library, string $from, string $to): void
    {
    }

    #[\Override]
    public function moveFolder(string $library, string $from, string $to): void
    {
    }

    #[\Override]
    public function listTags(string $library, int $limit = 100): array
    {
        return [];
    }

    #[\Override]
    public function findPathsByTag(string $library, string $tag): array
    {
        return [];
    }
}
