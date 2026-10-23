<?php

namespace JoliCode\MediaBundle\Tests\Metadata\Model;

use Doctrine\ORM\EntityManagerInterface;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class MediaMetadataPersistenceTest extends KernelTestCase
{
    public function testTagsKeepTheirOrderAndUnchangedTagsAreNotReinserted(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->assertInstanceOf(EntityManagerInterface::class, $em);

        $metadata = new MediaMetadata('default', 'persistence-test/' . uniqid() . '.png');
        $metadata->setTags(['zebra', 'apple', 'mango']);
        $em->persist($metadata);
        $em->flush();
        $id = $metadata->getId();
        $em->clear();

        $metadata = $em->find(MediaMetadata::class, $id);
        $this->assertInstanceOf(MediaMetadata::class, $metadata);
        $this->assertSame(['zebra', 'apple', 'mango'], $metadata->getTags());

        $metadata->setTags(['mango', 'zebra', 'kiwi']);
        $em->flush();
        $em->clear();

        $metadata = $em->find(MediaMetadata::class, $id);
        $this->assertInstanceOf(MediaMetadata::class, $metadata);
        $this->assertSame(['mango', 'zebra', 'kiwi'], $metadata->getTags());

        $em->remove($metadata);
        $em->flush();
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }
}
