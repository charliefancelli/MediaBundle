<?php

namespace JoliCode\MediaBundle\Tests\Metadata\Model;

use Doctrine\ORM\EntityManagerInterface;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class MediaMetadataPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private ?int $id = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    protected function tearDown(): void
    {
        if (null !== $this->id) {
            $this->em->clear();
            $metadata = $this->em->find(MediaMetadata::class, $this->id);

            if (null !== $metadata) {
                $this->em->remove($metadata);
                $this->em->flush();
            }
        }

        parent::tearDown();
    }

    public function testTagsKeepTheirOrderAndUnchangedTagsAreNotReinserted(): void
    {
        $metadata = $this->createMetadata(['zebra', 'apple', 'mango']);
        $this->assertSame(['zebra', 'apple', 'mango'], $this->reload()->getTags());
        $zebraId = $this->tagIds($this->reload())['zebra'];

        $metadata = $this->reload();
        $metadata->setTags(['mango', 'zebra', 'kiwi']);
        $this->em->flush();

        $metadata = $this->reload();
        $this->assertSame(['mango', 'zebra', 'kiwi'], $metadata->getTags());
        $this->assertSame($zebraId, $this->tagIds($metadata)['zebra']);
    }

    public function testCaseOnlyTagChangeUpdatesTheSameRow(): void
    {
        $this->createMetadata(['Sport']);
        $sportId = $this->tagIds($this->reload())['Sport'];

        $metadata = $this->reload();
        $metadata->setTags(['sport']);
        $this->em->flush();

        $metadata = $this->reload();
        $this->assertSame(['sport'], $metadata->getTags());
        $this->assertSame($sportId, $this->tagIds($metadata)['sport']);
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /**
     * @param list<string> $tags
     */
    private function createMetadata(array $tags): MediaMetadata
    {
        $metadata = new MediaMetadata('default', 'persistence-test/' . uniqid() . '.png');
        $metadata->setTags($tags);
        $this->em->persist($metadata);
        $this->em->flush();
        $this->id = $metadata->getId();

        return $metadata;
    }

    private function reload(): MediaMetadata
    {
        $this->em->clear();
        $metadata = $this->em->find(MediaMetadata::class, $this->id);
        $this->assertInstanceOf(MediaMetadata::class, $metadata);

        return $metadata;
    }

    /**
     * @return array<string, int|null> tag ids by name
     */
    private function tagIds(MediaMetadata $metadata): array
    {
        $ids = [];
        foreach ((new \ReflectionProperty($metadata, 'tags'))->getValue($metadata) as $tag) {
            $ids[$tag->getName()] = $tag->getId();
        }

        return $ids;
    }
}
