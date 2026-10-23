<?php

namespace JoliCode\MediaBundle\Usage;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManagerInterface;
use JoliCode\MediaBundle\Cache\MediaEntityMetadata;
use JoliCode\MediaBundle\Cache\MediaEntityMetadataWarmer;
use JoliCode\MediaBundle\DeleteBehavior\Strategy;
use JoliCode\MediaBundle\Doctrine\Type\MediaLongType;
use JoliCode\MediaBundle\Doctrine\Type\MediaType;
use JoliCode\MediaBundle\Doctrine\Types;
use JoliCode\MediaBundle\Library\LibraryContainer;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Lists where a media is used: the Doctrine columns of type "media" / "media_long" and the registered providers.
 */
readonly class MediaUsageFinder
{
    /**
     * @param iterable<MediaUsageProviderInterface> $providers
     */
    public function __construct(
        private ?Registry $managerRegistry,
        private CacheInterface $cache,
        private LibraryContainer $libraries,
        private iterable $providers = [],
        private ?MediaUsageUrlGeneratorInterface $urlGenerator = null,
    ) {
    }

    /**
     * The Doctrine columns do not carry the library: like the delete and move listeners, their usages are
     * returned whatever the library. The providers receive the library (the default one when null).
     *
     * $limit applies to the whole returned list (columns first, then providers).
     *
     * @return list<MediaUsage>
     */
    public function find(string $path, ?string $libraryName = null, int $limit = 50): array
    {
        $usages = [];

        if ($limit < 1) {
            return $usages;
        }

        foreach ($this->findColumnUsages($path, $limit) as $usage) {
            $usages[] = $this->completeUsage($usage);
        }

        $libraryName ??= $this->libraries->getDefaultName();

        foreach ($this->providers as $provider) {
            foreach ($provider->getUsages($libraryName, $path) as $usage) {
                if (\count($usages) >= $limit) {
                    return $usages;
                }

                $usages[] = $this->completeUsage($usage);
            }
        }

        return $usages;
    }

    /**
     * @return list<MediaUsage>
     */
    private function findColumnUsages(string $path, int $limit): array
    {
        if (!$this->managerRegistry instanceof Registry) {
            return [];
        }

        /** @var list<MediaEntityMetadata> $fields */
        $fields = $this->cache->get(MediaEntityMetadataWarmer::MEDIA_FIELDS_CACHE_KEY, static fn (): array => []);

        /** @var array<string, array<class-string, array<string, Strategy>>> $deleteBehaviors */
        $deleteBehaviors = $this->cache->get(MediaEntityMetadataWarmer::MEDIA_DELETE_BEHAVIORS_CACHE_KEY, static fn (): array => []);

        $usages = [];

        foreach ($fields as $field) {
            $remaining = $limit - \count($usages);

            if ($remaining < 1) {
                break;
            }

            /** @var EntityManagerInterface $objectManager */
            $objectManager = $this->managerRegistry->getManager($field->objectManagerName);
            $classMetadata = $objectManager->getClassMetadata($field->entityName);
            $type = Types::MEDIA_LONG === $classMetadata->getTypeOfField($field->fieldName) ? new MediaLongType() : new MediaType();

            try {
                $databasePath = (string) $type->convertToDatabaseValue($path, $objectManager->getConnection()->getDatabasePlatform());
            } catch (\InvalidArgumentException) {
                // the path is too long for a "media" column: nothing can match there
                continue;
            }

            $blocksDeletion = Strategy::RESTRICT === ($deleteBehaviors[$field->objectManagerName][$field->entityName][$field->fieldName] ?? null);
            $orderBy = array_fill_keys($classMetadata->getIdentifierFieldNames(), 'ASC');

            $entities = $objectManager->getRepository($field->entityName)->findBy([$field->fieldName => $databasePath], [] === $orderBy ? null : $orderBy, $remaining);

            foreach ($entities as $entity) {
                $identifier = $this->normalizeIdentifier($objectManager, $classMetadata->getIdentifierValues($entity));

                $usages[] = new MediaUsage(
                    $entity instanceof \Stringable ? (string) $entity : \sprintf('%s #%s', $classMetadata->getReflectionClass()->getShortName(), $identifier),
                    $field->entityName,
                    $field->fieldName,
                    $identifier,
                    null,
                    $blocksDeletion,
                );
            }
        }

        return $usages;
    }

    /**
     * Flattens the identifier values (they may be objects for association keys) into a scalar,
     * composite keys being joined with "-".
     *
     * @param array<mixed> $values
     */
    private function normalizeIdentifier(EntityManagerInterface $objectManager, array $values): int|string|null
    {
        $parts = [];

        foreach ($values as $value) {
            if (\is_int($value) || \is_string($value)) {
                $parts[] = $value;
            } elseif ($value instanceof \Stringable) {
                $parts[] = (string) $value;
            } elseif (\is_object($value)) {
                $nested = $this->normalizeIdentifier($objectManager, $objectManager->getClassMetadata($value::class)->getIdentifierValues($value));

                if (null === $nested) {
                    return null;
                }

                $parts[] = $nested;
            } else {
                return null;
            }
        }

        if ([] === $parts) {
            return null;
        }

        return 1 === \count($parts) ? $parts[0] : implode('-', $parts);
    }

    private function completeUsage(MediaUsage $usage): MediaUsage
    {
        if ($this->urlGenerator instanceof MediaUsageUrlGeneratorInterface && null === $usage->editUrl) {
            return $usage->withEditUrl($this->urlGenerator->generateEditUrl($usage));
        }

        return $usage;
    }
}
