<?php

namespace JoliCode\MediaBundle\Metadata\Storage;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadataTag;
use JoliCode\MediaBundle\Resolver\Resolver;

/**
 * Folder operations compare a path prefix with SUBSTRING(), never with LIKE, so "%" and "_" are plain characters
 * and "sub/folder" never touches "sub/folder-2" or "sub/folderx".
 *
 * Bulk DQL statements bypass the unit of work: the managed instances they affect are detached, so that the next
 * read hits the database again. Pending changes on those instances are lost. They run in a DBAL transaction (nested
 * ones are fine) and never flush nor close the entity manager.
 *
 * Path and tag comparisons follow the database collation: they are case and accent insensitive with the default
 * "*_ci" collations of MySQL, like the MediaType columns.
 */
readonly class DoctrineMetadataStorage implements MetadataStorageInterface
{
    /**
     * The maximum number of paths read by one query of getMany().
     */
    public const GET_MANY_CHUNK_SIZE = 500;

    public function __construct(
        private Registry $managerRegistry,
        private ?string $entityManagerName = null,
    ) {
    }

    #[\Override]
    public function get(string $library, string $path): ?MediaMetadata
    {
        return $this->getEntityManager()->getRepository(MediaMetadata::class)->findOneBy([
            'library' => $library,
            'path' => Resolver::normalizePath($path),
        ]);
    }

    #[\Override]
    public function getMany(string $library, array $paths): array
    {
        if ([] === $paths) {
            return [];
        }

        $paths = array_values(array_unique(array_map(Resolver::normalizePath(...), $paths)));
        $result = [];

        // the list is cut in chunks, as databases bound the number of parameters of a query
        foreach (array_chunk($paths, self::GET_MANY_CHUNK_SIZE) as $chunk) {
            /** @var list<MediaMetadata> $rows */
            $rows = $this->getEntityManager()
                ->createQuery('SELECT m, t FROM ' . MediaMetadata::class . ' m LEFT JOIN m.tags t WHERE m.library = :library AND m.path IN (:paths) ORDER BY m.id ASC, t.position ASC')
                ->setParameter('library', $library)
                ->setParameter('paths', $chunk, ArrayParameterType::STRING)
                ->getResult()
            ;

            foreach ($rows as $metadata) {
                $result[$metadata->getPath()] = $metadata;
            }
        }

        return $result;
    }

    /**
     * The whole entity manager is flushed (ORM 3 cannot flush a single entity).
     *
     * An instance which is not managed (detached by a move or a delete, or created for a library and a path which
     * already have a row) is merged onto the stored row: the row of its id first (it may have been moved since), then
     * the row of its library and path. When the flush fails, the stored instance is detached, so that the next read
     * does not return the values which were not saved.
     */
    #[\Override]
    public function save(MediaMetadata $metadata): void
    {
        $entityManager = $this->getEntityManager();
        $stored = $this->findStored($entityManager, $metadata);

        if (!$metadata->isEmpty()) {
            if ($stored instanceof MediaMetadata && $stored !== $metadata) {
                $stored->setAlt($metadata->getAlt());
                $stored->setCaption($metadata->getCaption());
                $stored->setCopyright($metadata->getCopyright());
                $stored->setTags($metadata->getTags());
                $metadata = $stored;
            }

            $this->flush($entityManager, $metadata, static fn () => $entityManager->persist($metadata));

            return;
        }

        if (!$stored instanceof MediaMetadata) {
            return; // never stored, or already deleted
        }

        $this->flush($entityManager, $stored, static fn () => $entityManager->remove($stored));
    }

    #[\Override]
    public function delete(string $library, string $path): void
    {
        $path = Resolver::normalizePath($path);
        $entityManager = $this->getEntityManager();

        $this->detach($entityManager, static fn (MediaMetadata $metadata): bool => $library === $metadata->getLibrary() && $path === $metadata->getPath());
        $this->deleteRows($entityManager, 'm.path = :path', ['library' => $library, 'path' => $path]);
    }

    #[\Override]
    public function deleteFolder(string $library, string $folder): void
    {
        $prefix = $this->folderPrefix($folder);
        $entityManager = $this->getEntityManager();

        $this->detach($entityManager, static fn (MediaMetadata $metadata): bool => $library === $metadata->getLibrary() && str_starts_with($metadata->getPath(), $prefix));
        $this->deleteRows($entityManager, $this->prefixCondition($prefix), ['library' => $library, 'prefix' => $prefix]);
    }

    #[\Override]
    public function move(string $library, string $from, string $to): void
    {
        $from = Resolver::normalizePath($from);
        $to = Resolver::normalizePath($to);

        if ($from === $to) {
            return;
        }

        $entityManager = $this->getEntityManager();

        $this->detach($entityManager, static fn (MediaMetadata $metadata): bool => $library === $metadata->getLibrary() && ($from === $metadata->getPath() || $to === $metadata->getPath()));

        if (mb_strlen($to) > MediaMetadata::PATH_MAX_LENGTH) {
            // such a path can never have metadata (the column would refuse or truncate it): they are dropped
            $this->deleteRows($entityManager, 'm.path = :path', ['library' => $library, 'path' => $from]);

            return;
        }

        $entityManager->getConnection()->transactional(function () use ($entityManager, $library, $from, $to): void {
            // the source is excluded: with a case or accent insensitive collation, "A.png" equals "a.png"
            $this->deleteRows($entityManager, 'm.path = :path AND m.path <> :from', ['library' => $library, 'path' => $to, 'from' => $from]);

            $entityManager
                ->createQuery('UPDATE ' . MediaMetadata::class . ' m SET m.path = :to WHERE m.library = :library AND m.path = :from')
                ->setParameter('to', $to)
                ->setParameter('library', $library)
                ->setParameter('from', $from)
                ->execute()
            ;
        });
    }

    #[\Override]
    public function moveFolder(string $library, string $from, string $to): void
    {
        $fromPrefix = $this->folderPrefix($from);
        $toPrefix = $this->folderPrefix($to);

        if (str_starts_with($fromPrefix, $toPrefix) || str_starts_with($toPrefix, $fromPrefix)) {
            throw new \InvalidArgumentException('A folder cannot be moved into itself or onto one of its parents.');
        }

        $entityManager = $this->getEntityManager();

        $this->detach($entityManager, static fn (MediaMetadata $metadata): bool => $library === $metadata->getLibrary() && (str_starts_with($metadata->getPath(), $fromPrefix) || str_starts_with($metadata->getPath(), $toPrefix)));

        $entityManager->getConnection()->transactional(function () use ($entityManager, $library, $fromPrefix, $toPrefix): void {
            // the source is excluded: with a case or accent insensitive collation, "Photos/" equals "photos/"
            $this->deleteRows(
                $entityManager,
                $this->prefixCondition($toPrefix) . ' AND NOT (' . $this->prefixCondition($fromPrefix, 'fromPrefix') . ')',
                ['library' => $library, 'prefix' => $toPrefix, 'fromPrefix' => $fromPrefix],
            );

            // the paths which would get too long can never have metadata (the column would refuse or truncate them)
            $this->deleteRows(
                $entityManager,
                $this->prefixCondition($fromPrefix) . ' AND LENGTH(m.path) > ' . (MediaMetadata::PATH_MAX_LENGTH - mb_strlen($toPrefix) + mb_strlen($fromPrefix)),
                ['library' => $library, 'prefix' => $fromPrefix],
            );

            $entityManager
                ->createQuery('UPDATE ' . MediaMetadata::class . ' m SET m.path = CONCAT(:toPrefix, SUBSTRING(m.path, ' . (mb_strlen($fromPrefix) + 1) . ')) WHERE m.library = :library AND ' . $this->prefixCondition($fromPrefix))
                ->setParameter('toPrefix', $toPrefix)
                ->setParameter('library', $library)
                ->setParameter('prefix', $fromPrefix)
                ->execute()
            ;
        });
    }

    /**
     * Tag names are grouped as the database groups them: "Summer" and "summer" are two names or one, depending on the collation.
     */
    #[\Override]
    public function listTags(string $library, int $limit = 100): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->getEntityManager()
            ->createQuery('SELECT t.name AS name, COUNT(t.id) AS uses FROM ' . MediaMetadataTag::class . ' t JOIN t.metadata m WHERE m.library = :library GROUP BY t.name ORDER BY uses DESC, name ASC')
            ->setParameter('library', $library)
            ->setMaxResults($limit)
            ->getArrayResult()
        ;

        return array_column($rows, 'name');
    }

    /**
     * The tag name is compared as the database collation does.
     */
    #[\Override]
    public function findPathsByTag(string $library, string $tag): array
    {
        /** @var list<array{path: string}> $rows */
        $rows = $this->getEntityManager()
            ->createQuery('SELECT m.path AS path FROM ' . MediaMetadata::class . ' m JOIN m.tags t WHERE m.library = :library AND t.name = :tag ORDER BY m.path ASC')
            ->setParameter('library', $library)
            ->setParameter('tag', $tag)
            ->getArrayResult()
        ;

        return array_column($rows, 'path');
    }

    private function getEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->managerRegistry->getManager($this->entityManagerName);

        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('The "%s" object manager is not a Doctrine ORM entity manager.', $this->entityManagerName ?? 'default'));
        }

        return $entityManager;
    }

    private function folderPrefix(string $folder): string
    {
        $folder = rtrim(Resolver::normalizePath($folder), '/');

        if ('' === $folder) {
            throw new \InvalidArgumentException('The folder must not be empty.');
        }

        return $folder . '/';
    }

    private function prefixCondition(string $prefix, string $parameter = 'prefix'): string
    {
        return 'SUBSTRING(m.path, 1, ' . mb_strlen($prefix) . ') = :' . $parameter;
    }

    private function findStored(EntityManagerInterface $entityManager, MediaMetadata $metadata): ?MediaMetadata
    {
        if ($entityManager->contains($metadata)) {
            return $metadata;
        }

        $id = $metadata->getId();

        if (null !== $id) {
            $stored = $entityManager->find(MediaMetadata::class, $id);

            if ($stored instanceof MediaMetadata) {
                return $stored;
            }
        }

        return $this->get($metadata->getLibrary(), $metadata->getPath());
    }

    /**
     * @param callable(): void $schedule
     */
    private function flush(EntityManagerInterface $entityManager, MediaMetadata $metadata, callable $schedule): void
    {
        try {
            $schedule();
            $entityManager->flush();
        } catch (\Throwable $e) {
            $this->detach($entityManager, static fn (MediaMetadata $managed): bool => $managed === $metadata);

            throw $e;
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private function deleteRows(EntityManagerInterface $entityManager, string $condition, array $parameters): void
    {
        $entityManager->getConnection()->transactional(static function () use ($entityManager, $condition, $parameters): void {
            $subQuery = 'SELECT m.id FROM ' . MediaMetadata::class . ' m WHERE m.library = :library AND ' . $condition;

            $tags = $entityManager->createQuery('DELETE ' . MediaMetadataTag::class . ' t WHERE t.metadata IN (' . $subQuery . ')');
            $rows = $entityManager->createQuery('DELETE ' . MediaMetadata::class . ' m WHERE m.library = :library AND ' . $condition);

            foreach ($parameters as $name => $value) {
                $tags->setParameter($name, $value);
                $rows->setParameter($name, $value);
            }

            $tags->execute();
            $rows->execute();
        });
    }

    /**
     * @param callable(MediaMetadata): bool $affected
     */
    private function detach(EntityManagerInterface $entityManager, callable $affected): void
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $identityMap = $unitOfWork->getIdentityMap();

        $detached = [];
        foreach ($identityMap[MediaMetadata::class] ?? [] as $metadata) {
            if ($metadata instanceof MediaMetadata && $affected($metadata)) {
                $detached[spl_object_id($metadata)] = true;
                $entityManager->detach($metadata);
            }
        }

        if ([] === $detached) {
            return;
        }

        foreach ($identityMap[MediaMetadataTag::class] ?? [] as $tag) {
            if ($tag instanceof MediaMetadataTag && isset($detached[spl_object_id($tag->getMetadata())])) {
                $entityManager->detach($tag);
            }
        }
    }
}
