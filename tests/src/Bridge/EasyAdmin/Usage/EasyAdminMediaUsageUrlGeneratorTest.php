<?php

namespace JoliCode\MediaBundle\Tests\Bridge\EasyAdmin\Usage;

use JoliCode\MediaBundle\Bridge\EasyAdmin\Usage\EasyAdminMediaUsageUrlGenerator;
use JoliCode\MediaBundle\Tests\Application\Entity\Page;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use JoliCode\MediaBundle\Usage\MediaUsage;
use JoliCode\MediaBundle\Usage\MediaUsageUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EasyAdminMediaUsageUrlGeneratorTest extends WebTestCase
{
    private MediaUsageUrlGeneratorInterface $generator;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var MediaUsageUrlGeneratorInterface $generator */
        $generator = static::getContainer()->get(MediaUsageUrlGeneratorInterface::class);
        $this->generator = $generator;
    }

    public function testItIsTheRegisteredGenerator(): void
    {
        $this->assertInstanceOf(EasyAdminMediaUsageUrlGenerator::class, $this->generator);
    }

    public function testEntityWithACrudControllerHasAnEditUrl(): void
    {
        $url = $this->generator->generateEditUrl(new MediaUsage('Page', Page::class, 'mediaRestrict', 'abc-123'));

        $this->assertNotNull($url);
        $this->assertStringContainsString('abc-123', $url);
        $this->assertStringContainsString('edit', $url);
    }

    public function testEntityWithoutCrudControllerHasNoUrl(): void
    {
        $this->assertNull($this->generator->generateEditUrl(new MediaUsage('Thing', \stdClass::class, null, 1)));
    }

    public function testUsageWithoutEntityOrIdentifierHasNoUrl(): void
    {
        $this->assertNull($this->generator->generateEditUrl(new MediaUsage('Block')));
        $this->assertNull($this->generator->generateEditUrl(new MediaUsage('Page', Page::class)));
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }
}
