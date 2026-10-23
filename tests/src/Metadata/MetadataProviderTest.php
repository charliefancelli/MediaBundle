<?php

namespace JoliCode\MediaBundle\Tests\Metadata;

use JoliCode\MediaBundle\Metadata\MetadataProvider;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Model\Media;
use JoliCode\MediaBundle\Model\MediaVariation;
use JoliCode\MediaBundle\Tests\BaseTestCase;

class MetadataProviderTest extends BaseTestCase
{
    private RecordingMetadataStorage $storage;

    private MetadataProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new RecordingMetadataStorage();
        $this->provider = new MetadataProvider($this->storage, $this->libraries);
    }

    public function testGetManyThenGetHitsTheStorageOnce(): void
    {
        $this->storage->put('default', 'a.png', 'A');
        $this->storage->put('default', 'b.png', 'B');

        $found = $this->provider->getMany(['a.png', 'b.png']);

        self::assertSame(['a.png', 'b.png'], array_keys($found));
        self::assertSame('A', $this->provider->get('a.png')?->getAlt());
        self::assertSame('B', $this->provider->get('b.png')?->getAlt());
        self::assertSame(1, $this->storage->getManyCalls);
        self::assertSame(0, $this->storage->getCalls);
    }

    public function testGetManyOnlyAsksForThePathsNotMemoizedYet(): void
    {
        $this->storage->put('default', 'a.png', 'A');
        $this->storage->put('default', 'b.png', 'B');

        $this->provider->get('a.png');
        $found = $this->provider->getMany(['./a.png', '/b.png', 'none.png']);

        self::assertSame(['a.png', 'b.png'], array_keys($found));
        self::assertSame([['default', ['b.png', 'none.png']]], $this->storage->getManyArguments);
    }

    public function testGetManyMakesOneStorageCallPerLibrary(): void
    {
        $this->storage->put('default', 'a.png', 'A');
        $this->storage->put('auto_generate', 'b.png', 'B');

        $this->provider->getMany(['a.png', 'c.png']);
        $this->provider->getMany(['b.png'], 'auto_generate');

        self::assertSame(2, $this->storage->getManyCalls);
        self::assertSame([['default', ['a.png', 'c.png']], ['auto_generate', ['b.png']]], $this->storage->getManyArguments);
    }

    public function testAbsenceIsMemoized(): void
    {
        self::assertNull($this->provider->get('none.png'));
        self::assertNull($this->provider->get('none.png'));
        self::assertSame(1, $this->storage->getCalls);

        $this->provider->getMany(['none.png']);
        self::assertSame(0, $this->storage->getManyCalls);
    }

    public function testResetForgetsEverything(): void
    {
        $this->storage->put('default', 'a.png', 'A');
        $this->provider->get('a.png');

        $this->provider->reset();
        $this->provider->get('a.png');

        self::assertSame(2, $this->storage->getCalls);
    }

    public function testForgetOnlyForgetsOneEntry(): void
    {
        $this->storage->put('default', 'a.png', 'A');
        $this->storage->put('default', 'b.png', 'B');
        $this->provider->getMany(['a.png', 'b.png']);

        $this->provider->forget('default', './a.png');

        self::assertSame('A', $this->provider->get('a.png')?->getAlt());
        $this->provider->get('b.png');
        self::assertSame(1, $this->storage->getCalls);
    }

    public function testTheLibraryIsTheOneOfTheMediaThenTheArgumentThenTheDefaultOne(): void
    {
        $this->storage->put('default', 'a.png', 'default');
        $this->storage->put('auto_generate', 'a.png', 'auto');

        $media = new Media('a.png', $this->originalStorage);

        self::assertSame('default', $this->provider->get('a.png')?->getAlt());
        self::assertSame('auto', $this->provider->get('a.png', 'auto_generate')?->getAlt());
        self::assertSame('default', $this->provider->get($media, 'auto_generate')?->getAlt());
        self::assertSame('default', $this->provider->get(new MediaVariation($media, $this->variation), 'auto_generate')?->getAlt());
    }

    public function testAPathTooLongNeverReachesTheStorage(): void
    {
        $path = str_repeat('a', MediaMetadata::PATH_MAX_LENGTH + 1) . '.png';

        self::assertNull($this->provider->get($path));
        self::assertSame([], $this->provider->getMany([$path]));
        self::assertSame(0, $this->storage->getCalls);
        self::assertSame(0, $this->storage->getManyCalls);
    }
}
