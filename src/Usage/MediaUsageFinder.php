<?php

namespace JoliCode\MediaBundle\Usage;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManagerInterface;
use JoliCode\MediaBundle\Cache\MediaEntityMetadata;
use JoliCode\MediaBundle\Cache\MediaEntityMetadataWarmer;
use JoliCode\MediaBundle\DeleteBehavior\Strategy;
use JoliCode\MediaBundle\Doctrine\Type\MediaType;
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
        if (null === $this->managerRegistry) {
            return [];
        }

        /** @var list<MediaEntityMetadata> $fields */
        $fields = $this->cache->get(MediaEntityMetadataWarmer::MEDIA_FIELDS_CACHE_KEY, static fn (): array => []);

        /** @var array<string, array<class-string, array<string, Strategy>>> $deleteBehaviors */
        $deleteBehaviors = $this->cache->get(MediaEntityMetadataWarmer::MEDIA_DELETE_BEHAVIORS_CACHE_KEY, static fn (): array => []);

        $mediaType = new MediaType();
        $usages = [];

        foreach ($fields as $field) {
            $remaining = $limit - \count($usages);

            if ($remaining < 1) {
                break;
            }

            /** @var EntityManagerInterface $objectManager */
            $objectManager = $this->managerRegistry->getManager($field->objectManagerName);
            $databasePath = (string) $mediaType->convertToDatabaseValue($path, $objectManager->getConnection()->getDatabasePlatform());
            $blocksDeletion = Strategy::RESTRICT === ($deleteBehaviors[$field->objectManagerName][$field->entityName][$field->fieldName] ?? null);

            $entities = $objectManager->getRepository($field->entityName)->findBy([$field->fieldName => $databasePath], null, $remaining);

            foreach ($entities as $entity) {
                $identifier = $objectManager->getClassMetadata($field->entityName)->getIdentifierValues($entity);

                $usages[] = new MediaUsage(
                    $entity instanceof \Stringable ? (string) $entity : \sprintf('%s #%s', (new \ReflectionClass($entity))->getShortName(), implode('-', $identifier)),
                    $field->entityName,
                    $field->fieldName,
                    1 === \count($identifier) ? reset($identifier) : ([] === $identifier ? null : implode('-', $identifier)),
                    null,
                    $blocksDeletion,
                );
            }
        }

        return $usages;
    }

    private function completeUsage(MediaUsage $usage): MediaUsage
    {
        if (null !== $this->urlGenerator && null === $usage->editUrl) {
            return $usage->withEditUrl($this->urlGenerator->generateEditUrl($usage));
        }

        return $usage;
    }
}
