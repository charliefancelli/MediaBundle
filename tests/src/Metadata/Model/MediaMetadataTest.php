<?php

namespace JoliCode\MediaBundle\Tests\Metadata\Model;

use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class MediaMetadataTest extends TestCase
{
    public function testTextsAreTrimmedAndEmptyBecomesNull(): void
    {
        $m = new MediaMetadata('default', 'a.png');
        $m->setAlt('  A cat  ');
        $m->setCaption('   ');

        $this->assertSame('A cat', $m->getAlt());
        $this->assertNull($m->getCaption());
    }

    public function testTagsAreNormalized(): void
    {
        $m = new MediaMetadata('default', 'a.png');
        $m->setTags(['  Sport ', 'sport', 'Beach   volley', '', 'Ocean']);

        $this->assertSame(['Sport', 'Beach volley', 'Ocean'], $m->getTags());
    }

    public function testSetTagsReusesExistingTagsAndKeepsTheInputOrder(): void
    {
        $m = new MediaMetadata('default', 'a.png');
        $m->setTags(['a', 'b', 'c']);

        $kept = $this->tagEntities($m)[1];

        $m->setTags(['c', 'b', 'd']);

        $this->assertSame(['c', 'b', 'd'], $m->getTags());
        $this->assertSame($kept, $this->tagEntities($m)[1]);
    }

    public function testCaseOnlyTagChangeReusesTheTagEntity(): void
    {
        $m = new MediaMetadata('default', 'a.png');
        $m->setTags(['Sport']);

        $tag = $this->tagEntities($m)[0];

        $m->setTags(['sport']);

        $this->assertSame(['sport'], $m->getTags());
        $this->assertSame($tag, $this->tagEntities($m)[0]);
    }

    public function testIsEmpty(): void
    {
        $m = new MediaMetadata('default', 'a.png');
        $this->assertTrue($m->isEmpty());

        $m->setCopyright('© Jane');
        $this->assertFalse($m->isEmpty());

        $m->setCopyright(null);
        $m->setTags(['x']);
        $this->assertFalse($m->isEmpty());
    }

    public function testTooLongPathIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MediaMetadata('default', str_repeat('a', 256));
    }

    public function testTooLongLibraryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MediaMetadata(str_repeat('a', 65), 'a.png');
    }

    public function testConstraints(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $m = new MediaMetadata('default', 'a.png');
        $this->assertCount(0, $validator->validate($m));

        $m->setAlt(str_repeat('a', 501));
        $this->assertCount(1, $validator->validate($m));
        $m->setAlt(null);

        $m->setTags(array_map(static fn (int $i): string => 'tag' . $i, range(1, 51)));
        $this->assertCount(1, $validator->validate($m));

        $m->setTags([str_repeat('a', 101)]);
        $this->assertCount(1, $validator->validate($m));
    }

    /**
     * @return list<object>
     */
    private function tagEntities(MediaMetadata $metadata): array
    {
        return (new \ReflectionProperty($metadata, 'tags'))->getValue($metadata)->getValues();
    }
}
