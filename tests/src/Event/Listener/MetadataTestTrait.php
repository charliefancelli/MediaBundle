<?php

namespace JoliCode\MediaBundle\Tests\Event\Listener;

use Doctrine\Bundle\DoctrineBundle\Registry;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Metadata\Storage\DoctrineMetadataStorage;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;
use JoliCode\MediaBundle\Tests\Metadata\FailingMetadataStorage;

trait MetadataTestTrait
{
    private MetadataStorageInterface $metadataStorage;

    private Registry $registry;

    private function setUpMetadata(): void
    {
        /** @var Registry $registry */
        $registry = static::getContainer()->get('doctrine');
        $this->registry = $registry;
        // not the container's service: tests replace it to make it fail
        $this->metadataStorage = new DoctrineMetadataStorage($registry);
    }

    /**
     * Replaces the storage used by the listeners with one that applies the change, then throws.
     */
    private function makeTheMetadataStorageFail(): void
    {
        static::getContainer()->set('joli_media.metadata.storage.doctrine', new FailingMetadataStorage($this->registry));
    }

    private function seed(string $library, string $path): void
    {
        $metadata = new MediaMetadata($library, $path);
        $metadata->setAlt('Alt of ' . $path);

        $this->metadataStorage->save($metadata);
    }

    private function altOf(string $library, string $path): ?string
    {
        return $this->metadataStorage->get($library, $path)?->getAlt();
    }
}
