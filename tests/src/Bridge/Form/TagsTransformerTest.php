<?php

namespace JoliCode\MediaBundle\Tests\Bridge\Form;

use JoliCode\MediaBundle\Bridge\Form\DataTransformer\TagsTransformer;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use PHPUnit\Framework\TestCase;

class TagsTransformerTest extends TestCase
{
    public function testReverseTransformSplitsOnCommas(): void
    {
        $transformer = new TagsTransformer();

        $this->assertSame(['a', ' b ', 'c'], $transformer->reverseTransform('a, b ,c'));
        $this->assertSame([], $transformer->reverseTransform(null));
        $this->assertSame([], $transformer->reverseTransform(''));
    }

    public function testTheModelNormalizesTheSplitTags(): void
    {
        $metadata = new MediaMetadata('default', 'a.png');
        $metadata->setTags((new TagsTransformer())->reverseTransform('a, b ,c, A,, '));

        $this->assertSame(['a', 'b', 'c'], $metadata->getTags());
    }

    public function testTransform(): void
    {
        $transformer = new TagsTransformer();

        $this->assertSame('', $transformer->transform([]));
        $this->assertSame('', $transformer->transform(null));
        $this->assertSame('a, b', $transformer->transform(['a', 'b']));
    }
}
