<?php

namespace JoliCode\MediaBundle\Tests\Metadata;

use JoliCode\MediaBundle\Tests\Application\Doctrine\QueryCounterMiddleware;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use JoliCode\MediaBundle\Tests\Twig\Component\ImgTest;
use JoliCode\MediaBundle\Tests\Twig\Component\PictureTest;
use JoliCode\MediaBundle\Twig\Components\Picture;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * The test application with the metadata disabled (the "no_metadata" environment): JoliMedia behaves as before.
 */
class MetadataDisabledApplicationTest extends WebTestCase
{
    use InteractsWithTwigComponents;

    public static function setUpBeforeClass(): void
    {
        // the medias of the component tests, stored by the regular test application (same files on disk)
        ImgTest::setUpBeforeClass();
        PictureTest::setUpBeforeClass();
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::ensureKernelShutdown();
        // it deletes the medias stored by PictureTest::setUpBeforeClass() too
        ImgTest::tearDownAfterClass();
        self::ensureKernelShutdown();
    }

    public function testNoMetadataServiceIsRegistered(): void
    {
        $container = static::getContainer();

        $this->assertFalse($container->getParameter('joli_media.metadata.enabled'));
        foreach (['provider', 'manager', 'storage', 'storage.doctrine', 'event_listener.follow'] as $id) {
            $this->assertFalse($container->has('joli_media.metadata.' . $id), $id);
        }

        $this->assertSame([], array_values(array_filter(
            $container->getServiceIds(),
            static fn (string $id): bool => str_starts_with($id, 'joli_media.metadata.'),
        )));
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[DataProviderExternal(ImgTest::class, 'provideComponentsData')]
    public function testTheImgMarkupIsUnchanged(string $component, array $configuration, string $expected): void
    {
        $this->assertSame($this->normalizeExpected($expected), $this->render($component, $configuration));
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[DataProviderExternal(PictureTest::class, 'provideComponentsData')]
    public function testThePictureMarkupIsUnchanged(string $component, array $configuration, string $expected): void
    {
        $this->assertSame($this->normalizeExpected($expected), $this->render($component, $configuration));
    }

    public function testAPictureWithoutAltKeepsItsBareAltAttribute(): void
    {
        // the markup of the previous versions: the Picture passes a null alt to the Img
        $html = $this->renderTwigComponent(name: Picture::class, data: ['path' => 'circle-pattern.jpg', 'variation' => 'variation-standard']);

        $this->assertMatchesRegularExpression('/\salt[\s>\/]/', $html);
    }

    public function testTheListDoesNotQueryTheMetadata(): void
    {
        $client = static::createClient();

        QueryCounterMiddleware::reset();
        $client->request(Request::METHOD_GET, '/admin/media/explore/sub/folder');
        $this->assertResponseIsSuccessful();

        $this->assertSame([], array_values(array_filter(
            QueryCounterMiddleware::getQueries(),
            static fn (string $sql): bool => str_contains($sql, 'joli_media_metadata'),
        )));
        $this->assertSelectorNotExists('[data-joli-media-missing-alt]');
        $this->assertSelectorNotExists('[data-joli-media-tag-filter]');
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => 'no_metadata'] + $options);
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function render(string $component, array $configuration): string
    {
        $crawler = new Crawler(\sprintf('<!DOCTYPE html><html><body>%s</body></html>', $this->renderTwigComponent(name: $component, data: $configuration)));

        return (string) preg_replace(['/(\n\s*)+/', '/\s+/'], ['', ' '], $crawler->filterXPath('//body/*')->first()->outerHtml());
    }

    /**
     * The same adaptation as PictureTest::testComponents().
     */
    private function normalizeExpected(string $expected): string
    {
        // @phpstan-ignore-next-line
        if (Kernel::MAJOR_VERSION < 8 && !(Kernel::MAJOR_VERSION === 7 && Kernel::MINOR_VERSION === 4 && \PHP_VERSION_ID >= 80400)) {
            // Since Symfony 7.4 with PHP 8.4, the native HTML5 parser is used unconditionally
            $expected = str_replace('</source>', '', $expected);
            $expected = str_replace('&gt;', '>', $expected);
        }

        return $expected;
    }
}
