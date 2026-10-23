<?php

namespace JoliCode\MediaBundle\Tests\Usage\EventListener;

use JoliCode\MediaBundle\Exception\MediaInUseException;
use JoliCode\MediaBundle\Library\LibraryContainer;
use JoliCode\MediaBundle\Storage\OriginalStorage;
use JoliCode\MediaBundle\Tests\Application\Entity\Page;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use JoliCode\MediaBundle\Tests\Application\Usage\StaticMediaUsageProvider;
use JoliCode\MediaBundle\Usage\MediaUsage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\StringInput;

class UsageDeleteGuardListenerTest extends WebTestCase
{
    private OriginalStorage $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var LibraryContainer $libraries */
        $libraries = static::getContainer()->get('joli_media.library_container');
        $this->originalStorage = $libraries->get('default')->getOriginalStorage();

        if (self::$kernel instanceof Kernel) {
            $application = new Application(self::$kernel);
            $application->setAutoExit(false);
            $application->run(new StringInput('doctrine:fixtures:load --purge-with-truncate --no-interaction --quiet'));
        }
    }

    protected function tearDown(): void
    {
        StaticMediaUsageProvider::reset();

        parent::tearDown();
    }

    public function testDeleteIsPreventedByABlockingUsage(): void
    {
        StaticMediaUsageProvider::setUsages('default', 'not_used/default.pdf', [
            new MediaUsage('Home banner', Page::class, 'image', 3, null, true),
        ]);

        try {
            $this->originalStorage->delete('not_used/default.pdf');
            $this->fail('A MediaInUseException was expected.');
        } catch (MediaInUseException $exception) {
            $this->assertSame('not_used/default.pdf', $exception->getPath());
            $this->assertSame(Page::class, $exception->getEntityName());
            $this->assertSame('image', $exception->getPropertyName());
        }

        $this->assertTrue($this->originalStorage->has('not_used/default.pdf'));
    }

    public function testExceptionFallsBackOnTheLabelWhenTheUsageHasNoEntity(): void
    {
        StaticMediaUsageProvider::setUsages('default', 'not_used/default.pdf', [
            new MediaUsage('Footer block', blocksDeletion: true),
        ]);

        try {
            $this->originalStorage->delete('not_used/default.pdf');
            $this->fail('A MediaInUseException was expected.');
        } catch (MediaInUseException $exception) {
            $this->assertSame('Footer block', $exception->getEntityName());
            $this->assertSame('', $exception->getPropertyName());
        }
    }

    public function testDeleteDirectoryIsPreventedByABlockingUsage(): void
    {
        StaticMediaUsageProvider::setUsages('default', 'not_used/default.pdf', [
            new MediaUsage('Home banner', Page::class, 'image', 3, null, true),
        ]);

        try {
            $this->originalStorage->deleteDirectory('not_used');
            $this->fail('A MediaInUseException was expected.');
        } catch (MediaInUseException $exception) {
            $this->assertSame('not_used/default.pdf', $exception->getPath());
        }

        $this->assertTrue($this->originalStorage->has('not_used/default.pdf'));
    }

    public function testNonBlockingUsageAllowsTheDeletion(): void
    {
        StaticMediaUsageProvider::setUsages('default', 'not_used/default.pdf', [
            new MediaUsage('Home banner', Page::class, 'image', 3),
        ]);

        $this->originalStorage->delete('not_used/default.pdf');
        $this->assertFalse($this->originalStorage->has('not_used/default.pdf'));
    }

    public function testNonBlockingUsageAllowsTheDirectoryDeletion(): void
    {
        StaticMediaUsageProvider::setUsages('default', 'not_used/default.pdf', [
            new MediaUsage('Home banner', Page::class, 'image', 3),
        ]);

        $this->originalStorage->deleteDirectory('not_used');
        $this->assertFalse($this->originalStorage->has('not_used/default.pdf'));
    }

    public function testBlockingUsageOfAnotherLibraryDoesNotPreventTheDeletion(): void
    {
        StaticMediaUsageProvider::setUsages('auto_generate', 'not_used/default.pdf', [
            new MediaUsage('Home banner', blocksDeletion: true),
        ]);

        $this->originalStorage->delete('not_used/default.pdf');
        $this->assertFalse($this->originalStorage->has('not_used/default.pdf'));
    }

    public function testExceptionMessageForAUsageWithoutField(): void
    {
        StaticMediaUsageProvider::setUsages('default', 'not_used/default.pdf', [
            new MediaUsage('Footer block', blocksDeletion: true),
        ]);

        try {
            $this->originalStorage->delete('not_used/default.pdf');
            $this->fail('A MediaInUseException was expected.');
        } catch (MediaInUseException $exception) {
            $this->assertSame('The media "not_used/default.pdf" is used by "Footer block". It cannot be deleted.', $exception->getMessage());
        }
    }

    public function testTrashedMediasAreNotAskedToTheProviders(): void
    {
        // the Doctrine POST listeners are active: the folder is staged in the trash and each media deleted from there
        $this->originalStorage->deleteDirectory('not_used');

        $this->assertSame(['default|not_used/default.pdf'], StaticMediaUsageProvider::getCalls());
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }
}
