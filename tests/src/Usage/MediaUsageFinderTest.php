<?php

namespace JoliCode\MediaBundle\Tests\Usage;

use JoliCode\MediaBundle\Tests\Application\Entity\Page;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use JoliCode\MediaBundle\Tests\Application\Usage\StaticMediaUsageProvider;
use JoliCode\MediaBundle\Usage\MediaUsage;
use JoliCode\MediaBundle\Usage\MediaUsageFinder;
use JoliCode\MediaBundle\Usage\MediaUsageUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\StringInput;

class MediaUsageFinderTest extends WebTestCase
{
    private MediaUsageFinder $finder;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var MediaUsageFinder $finder */
        $finder = static::getContainer()->get('joli_media.usage_finder');
        $this->finder = $finder;

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

    public function testFindsTheDoctrineColumns(): void
    {
        $usages = $this->finder->find('restrict.pdf');

        $this->assertCount(2, $usages);

        $labels = array_map(static fn (MediaUsage $usage): string => $usage->label, $usages);
        sort($labels);
        $this->assertSame(['Page 1', 'Page 2'], $labels);

        foreach ($usages as $usage) {
            $this->assertSame(Page::class, $usage->entityClass);
            $this->assertSame('mediaRestrict', $usage->field);
            $this->assertIsString($usage->identifier);
            $this->assertNotSame('', $usage->identifier);
            $this->assertTrue($usage->blocksDeletion);
            $this->assertNull($usage->editUrl);
        }
    }

    public function testSetNullColumnsDoNotBlock(): void
    {
        $usages = $this->finder->find('set_null.pdf');

        $this->assertCount(1, $usages);
        $this->assertSame('mediaSetNull', $usages[0]->field);
        $this->assertFalse($usages[0]->blocksDeletion);
    }

    public function testColumnsWithoutDeleteBehaviorDoNotBlock(): void
    {
        $usages = $this->finder->find('default.pdf');

        $this->assertCount(1, $usages);
        $this->assertSame('mediaDefault', $usages[0]->field);
        $this->assertFalse($usages[0]->blocksDeletion);
    }

    public function testFindsTheProviderUsages(): void
    {
        $usage = new MediaUsage('A CMS block', field: 'content', identifier: 12, blocksDeletion: true);
        StaticMediaUsageProvider::setUsages('default', 'not_used/default.pdf', [$usage]);

        $this->assertSame([$usage], $this->finder->find('not_used/default.pdf', 'default'));
        $this->assertSame([$usage], $this->finder->find('not_used/default.pdf'), 'The default library is used when none is given.');
        $this->assertSame([], $this->finder->find('not_used/default.pdf', 'auto_generate'));
    }

    public function testUnusedMediaHasNoUsage(): void
    {
        $this->assertSame([], $this->finder->find('not_used/default.pdf'));
    }

    public function testLimitIsHonoured(): void
    {
        StaticMediaUsageProvider::setUsages('default', 'restrict.pdf', [new MediaUsage('Block 1'), new MediaUsage('Block 2')]);

        $this->assertCount(4, $this->finder->find('restrict.pdf'));
        $this->assertCount(1, $this->finder->find('restrict.pdf', limit: 1));

        $usages = $this->finder->find('restrict.pdf', limit: 3);
        $this->assertCount(3, $usages);
        $this->assertSame('Block 1', $usages[2]->label);
    }

    public function testEditUrlIsFilledWhenAGeneratorExists(): void
    {
        StaticMediaUsageProvider::setUsages('default', 'restrict.pdf', [
            new MediaUsage('Block with URL', editUrl: '/already'),
            new MediaUsage('Block'),
            new MediaUsage('No URL'),
        ]);

        $generator = new class implements MediaUsageUrlGeneratorInterface {
            public function generateEditUrl(MediaUsage $usage): ?string
            {
                return 'No URL' === $usage->label ? null : '/edit/' . $usage->label;
            }
        };

        $reflection = new \ReflectionClass(MediaUsageFinder::class);
        $args = [];

        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $property = $reflection->getProperty($parameter->getName());
            $args[] = $property->getValue($this->finder);
        }

        $args[4] = $generator;
        $finder = new MediaUsageFinder(...$args);

        $urls = array_map(static fn (MediaUsage $usage): ?string => $usage->editUrl, $finder->find('restrict.pdf'));

        $this->assertSame(['/edit/Page 1', '/edit/Page 2', '/already', '/edit/Block', null], $this->sortedColumnsFirst($urls));
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /**
     * @param list<string|null> $urls
     *
     * @return list<string|null>
     */
    private function sortedColumnsFirst(array $urls): array
    {
        $columns = \array_slice($urls, 0, 2);
        sort($columns);

        return [...$columns, ...\array_slice($urls, 2)];
    }
}
