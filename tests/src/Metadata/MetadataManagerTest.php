<?php

namespace JoliCode\MediaBundle\Tests\Metadata;

use JoliCode\MediaBundle\Event\MediaEvents;
use JoliCode\MediaBundle\Event\PostSaveMetadataEvent;
use JoliCode\MediaBundle\Metadata\MetadataManager;
use JoliCode\MediaBundle\Metadata\MetadataProvider;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Tests\BaseTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class MetadataManagerTest extends BaseTestCase
{
    private RecordingMetadataStorage $storage;

    private MetadataProvider $provider;

    private EventDispatcher $dispatcher;

    private MetadataManager $manager;

    /**
     * @var list<PostSaveMetadataEvent>
     */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new RecordingMetadataStorage();
        $this->provider = new MetadataProvider($this->storage, $this->libraries);
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(MediaEvents::POST_SAVE_METADATA, function (PostSaveMetadataEvent $event): void {
            $this->events[] = $event;
        });
        $this->manager = new MetadataManager($this->storage, $this->provider, $this->dispatcher);
    }

    public function testSaveDispatchesTheEventAndRefreshesTheProvider(): void
    {
        // memoizes the absence, save() must invalidate it
        self::assertNull($this->provider->get('a.png'));

        $metadata = new MediaMetadata('default', 'a.png');
        $metadata->setAlt('An alt');

        $this->manager->save($metadata);

        self::assertNotNull($this->provider->get('a.png'));
        self::assertSame('An alt', $this->provider->get('a.png')->getAlt());
        self::assertCount(1, $this->events);
        self::assertSame($metadata, $this->events[0]->metadata);
        self::assertFalse($this->events[0]->deleted);
    }

    public function testSavingAnEmptyMetadataDispatchesADeletion(): void
    {
        $metadata = $this->storage->put('default', 'a.png', 'An alt');
        self::assertNotNull($this->provider->get('a.png'));

        $metadata->setAlt(null);
        $this->manager->save($metadata);

        self::assertNull($this->provider->get('a.png'));
        self::assertCount(1, $this->events);
        self::assertTrue($this->events[0]->deleted);
    }

    public function testSavingAnEmptyMetadataWhichWasNeverStoredIsNotADeletion(): void
    {
        $this->manager->save(new MediaMetadata('default', 'never.png'));

        self::assertCount(1, $this->events);
        self::assertFalse($this->events[0]->deleted);
    }

    public function testSavingAnEmptyInstanceWhoseRowIsGoneIsNotADeletion(): void
    {
        // an instance once stored (it has an id), whose row was deleted since
        $metadata = new MediaMetadata('default', 'gone.png');
        (new \ReflectionProperty(MediaMetadata::class, 'id'))->setValue($metadata, 42);

        $this->manager->save($metadata);

        self::assertCount(1, $this->events);
        self::assertFalse($this->events[0]->deleted);
    }

    public function testSavingAnEmptyCopyOfStoredMetadataIsADeletion(): void
    {
        $this->storage->put('default', 'a.png', 'An alt');

        $this->manager->save(new MediaMetadata('default', 'a.png'));

        self::assertNull($this->provider->get('a.png'));
        self::assertCount(1, $this->events);
        self::assertTrue($this->events[0]->deleted);
    }

    public function testAFailedSaveForgetsTheMemoizedMetadata(): void
    {
        $stored = $this->storage->put('default', 'a.png', 'An alt');
        self::assertSame($stored, $this->provider->get('a.png'));
        $this->storage->failOnSave = true;

        try {
            $this->manager->save(new MediaMetadata('default', 'a.png'));
            self::fail('An exception was expected.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->events);
        $this->storage->getCalls = 0;
        $this->provider->get('a.png');
        self::assertSame(1, $this->storage->getCalls);
    }

    public function testSaveWorksWithoutListeners(): void
    {
        $manager = new MetadataManager($this->storage, $this->provider, new EventDispatcher());

        $metadata = new MediaMetadata('default', 'a.png');
        $metadata->setAlt('An alt');

        $manager->save($metadata);

        self::assertSame('An alt', $this->provider->get('a.png')?->getAlt());
    }
}
