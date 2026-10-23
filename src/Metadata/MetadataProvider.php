<?php

namespace JoliCode\MediaBundle\Metadata;

use JoliCode\MediaBundle\Library\LibraryContainer;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;
use JoliCode\MediaBundle\Model\Media;
use JoliCode\MediaBundle\Model\MediaVariation;
use JoliCode\MediaBundle\Resolver\Resolver;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads the metadata of the media, memoizing them (and their absence) for the lifetime of the service.
 */
class MetadataProvider implements ResetInterface
{
    /**
     * @var array<string, MediaMetadata|null> indexed by "library\0path"
     */
    private array $memo = [];

    public function __construct(
        private readonly MetadataStorageInterface $storage,
        private readonly LibraryContainer $libraryContainer,
    ) {
    }

    public function forget(string $libraryName, string $path): void
    {
        unset($this->memo[$this->key($libraryName, Resolver::normalizePath($path))]);
    }

    public function get(Media|MediaVariation|string $media, ?string $libraryName = null): ?MediaMetadata
    {
        [$library, $path] = $this->resolve($media, $libraryName);

        // such a path can never have metadata
        if (mb_strlen($path) > MediaMetadata::PATH_MAX_LENGTH) {
            return null;
        }

        $key = $this->key($library, $path);

        if (!\array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->storage->get($library, $path);
        }

        return $this->memo[$key];
    }

    /**
     * @param iterable<Media|MediaVariation|string> $medias
     *
     * The result is indexed by path only: when the media belong to several libraries, two media with the same path
     * collide (the last one wins). Call it once per library in that case.
     *
     * @return array<string, MediaMetadata> indexed by path, media without metadata are absent
     */
    public function getMany(iterable $medias, ?string $libraryName = null): array
    {
        /** @var array<string, list<string>> $missing */
        $missing = [];
        $targets = [];

        foreach ($medias as $media) {
            [$library, $path] = $this->resolve($media, $libraryName);

            if (mb_strlen($path) > MediaMetadata::PATH_MAX_LENGTH) {
                continue;
            }

            $targets[] = [$library, $path];

            if (!\array_key_exists($this->key($library, $path), $this->memo)) {
                $missing[$library][$path] = $path;
            }
        }

        foreach ($missing as $library => $paths) {
            $found = $this->storage->getMany((string) $library, array_values($paths));

            foreach ($paths as $path) {
                $this->memo[$this->key((string) $library, $path)] = $found[$path] ?? null;
            }
        }

        $result = [];

        foreach ($targets as [$library, $path]) {
            $metadata = $this->memo[$this->key($library, $path)] ?? null;

            if (null !== $metadata) {
                $result[$path] = $metadata;
            }
        }

        return $result;
    }

    #[\Override]
    public function reset(): void
    {
        $this->memo = [];
    }

    private function key(string $library, string $path): string
    {
        return $library . "\0" . $path;
    }

    /**
     * @return array{string, string} the library name and the normalized path
     */
    private function resolve(Media|MediaVariation|string $media, ?string $libraryName): array
    {
        if ($media instanceof MediaVariation) {
            $media = $media->getMedia();
        }

        if ($media instanceof Media) {
            return [$media->getLibrary()->getName(), Resolver::normalizePath($media->getPath())];
        }

        return [$libraryName ?? $this->libraryContainer->getDefaultName(), Resolver::normalizePath($media)];
    }
}
