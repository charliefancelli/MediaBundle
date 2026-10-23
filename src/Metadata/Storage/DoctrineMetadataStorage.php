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

        /** @var list<MediaMetadata> $rows */
        $rows = $this->getEntityManager()
            ->createQuery('SELECT m, t FROM ' . MediaMetadata::class . ' m LEFT JOIN m.tags t WHERE m.library = :library AND m.path IN (:paths) ORDER BY m.id ASC, t.position ASC')
            ->setParameter('library', $library)
            ->setParameter('paths', $paths, ArrayParameterType::STRING)
            ->getResult()
        ;

        $result = [];
        foreach ($rows as $metadata) {
            $result[$metadata->getPath()] = $metadata;
        }

        return $result;
    }

    /**
     * The whole entity manager is flushed (ORM 3 cannot flush a single entity).
     */
    #[\Override]
    public function save(MediaMetadata $metadata): void
    {
        $entityManager = $this->getEntityManager();

        if (!$metadata->isEmpty()) {
            if (null !== $metadata->getId() && !$entityManager->contains($metadata)) {
                // detached (e.g. by a move or a delete): ORM would insert it again, so update the managed row instead
                $managed = $this->get($metadata->getLibrary(), $metadata->getPath());

                if ($managed instanceof MediaMetadata) {
                    $managed->setAlt($metadata->getAlt());
                    $managed->setCaption($metadata->getCaption());
                    $managed->setCopyright($metadata->getCopyright());
                    $managed->setTags($metadata->getTags());
                    $metadata = $managed;
                }
            }

            $entityManager->persist($metadata);
            $entityManager->flush();

            return;
        }

        $id = $metadata->getId();

        if (null === $id) {
            return; // never stored
        }

        $managed = $entityManager->contains($metadata) ? $metadata : $entityManager->getReference(MediaMetadata::class, $id);

        if (null !== $managed) {
            $entityManager->remove($managed);
            $entityManager->flush();
        }
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

        $entityManager->getConnection()->transactional(function () use ($entityManager, $library, $from, $to): void {
            $this->deleteRows($entityManager, 'm.path = :path', ['library' => $library, 'path' => $to]);

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
            $this->deleteRows($entityManager, $this->prefixCondition($toPrefix), ['library' => $library, 'prefix' => $toPrefix]);

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

    private function prefixCondition(string $prefix): string
    {
        return 'SUBSTRING(m.path, 1, ' . mb_strlen($prefix) . ') = :prefix';
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
