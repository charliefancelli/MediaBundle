<?php

namespace JoliCode\MediaBundle\Tests\Twig;

use JoliCode\MediaBundle\Binary\Binary;
use JoliCode\MediaBundle\Library\LibraryContainer;
use JoliCode\MediaBundle\Metadata\MetadataManager;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;
use JoliCode\MediaBundle\Model\Format;
use JoliCode\MediaBundle\Model\Media;
use JoliCode\MediaBundle\Resolver\Resolver;
use JoliCode\MediaBundle\Srcset\SrcsetBuilder;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use JoliCode\MediaBundle\Tests\BaseTestCase;
use JoliCode\MediaBundle\Twig\JoliMediaExtension;
use Twig\Environment;

class JoliMediaExtensionTest extends BaseTestCase
{
    private const FILTER_MEDIA = 'joli-media-extension-test.jpg';

    private const DIRECT_MEDIA = 'joli-media-extension-direct-test.jpg';

    public static function setUpBeforeClass(): void
    {
        $libraries = self::getLibraryContainer();

        foreach (['default', 'auto_generate'] as $libraryName) {
            $library = $libraries->get($libraryName);

            foreach ([self::FILTER_MEDIA, self::DIRECT_MEDIA] as $path) {
                $binary = new Binary('image/jpeg', Format::JPEG->value, BaseTestCase::getFixtureBinaryContent(BaseTestCase::JPEG_FIXTURE_PATH));
                $media = new Media($path, $library->getOriginalStorage(), $binary);
                $media->store();

                // remove variation files possibly left over by a previous run
                $library->deleteAllVariations($path);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        $libraries = self::getLibraryContainer();

        foreach (['default', 'auto_generate'] as $libraryName) {
            $library = $libraries->get($libraryName);

            foreach ([self::FILTER_MEDIA, self::DIRECT_MEDIA] as $path) {
                $library->getOriginalStorage()->delete($path);
                $library->deleteAllVariations($path);
            }
        }
    }

    protected function tearDown(): void
    {
        // the metadata rows of the database are shared between tests
        /** @var MetadataStorageInterface $storage */
        $storage = static::getContainer()->get('test.joli_media.metadata.storage');

        foreach (['default', 'auto_generate'] as $library) {
            $storage->delete($library, self::FILTER_MEDIA);
        }

        parent::tearDown();
    }

    public function testTheMetadataFiltersReadTheStoredMetadata(): void
    {
        $this->saveMetadata('default', self::FILTER_MEDIA);

        self::assertSame('A cat', $this->render("{{ path|joli_media_alt('Fallback') }}"));
        self::assertSame('A caption', $this->render("{{ path|joli_media_caption('Fallback') }}"));
        self::assertSame('Cat Corp', $this->render('{{ path|joli_media_copyright }}'));
        self::assertSame('pets,cats', $this->render("{{ path|joli_media_tags|join(',') }}"));
        self::assertSame('A cat', $this->render('{{ (path|joli_media_metadata).alt }}'));
    }

    public function testTheMetadataFiltersReturnTheFallbackWithoutMetadata(): void
    {
        self::assertSame('Fallback', $this->render("{{ path|joli_media_alt('Fallback') }}"));
        self::assertSame('Fallback', $this->render("{{ path|joli_media_caption('Fallback') }}"));
        self::assertSame('', $this->render('{{ path|joli_media_alt }}'));
        self::assertSame('', $this->render('{{ path|joli_media_copyright }}'));
        self::assertSame('', $this->render("{{ path|joli_media_tags|join(',') }}"));
        self::assertSame('none', $this->render("{{ (path|joli_media_metadata) is null ? 'none' : 'some' }}"));
    }

    public function testTheMetadataFiltersHonorTheLibraryName(): void
    {
        $this->saveMetadata('auto_generate', self::FILTER_MEDIA);

        self::assertSame('Fallback', $this->render("{{ path|joli_media_alt('Fallback') }}"));
        self::assertSame('A cat', $this->render("{{ path|joli_media_alt('Fallback', 'auto_generate') }}"));
        self::assertSame('Cat Corp', $this->render("{{ path|joli_media_copyright('auto_generate') }}"));
    }

    public function testTheMetadataFiltersAcceptMediaAndVariations(): void
    {
        $this->saveMetadata('auto_generate', self::FILTER_MEDIA);

        $library = self::getLibraryContainer()->get('auto_generate');
        $media = new Media(self::FILTER_MEDIA, $library->getOriginalStorage());

        self::assertSame('A cat', $this->render("{{ media|joli_media_alt('Fallback') }}", ['media' => $media]));
        // a variation reads the metadata of its original media
        self::assertSame('A cat', $this->render("{{ media|joli_media_alt('Fallback') }}", ['media' => $media->createVariation('variation-standard')]));
        self::assertSame('pets,cats', $this->render("{{ media|joli_media_tags|join(',') }}", ['media' => $media->createVariation('variation-standard')]));
    }

    public function testFiltersReturnTheFallbackWithoutProvider(): void
    {
        /** @var Resolver $resolver */
        $resolver = static::getContainer()->get('joli_media.resolver');
        /** @var SrcsetBuilder $srcsetBuilder */
        $srcsetBuilder = static::getContainer()->get('joli_media.srcset_builder');
        $extension = new JoliMediaExtension($resolver, $srcsetBuilder);
        $filters = [];

        foreach ($extension->getFilters() as $filter) {
            $callable = $filter->getCallable();
            \assert(\is_callable($callable));
            $filters[$filter->getName()] = $callable;
        }

        self::assertSame('Fallback', $filters['joli_media_alt'](self::FILTER_MEDIA, 'Fallback'));
        self::assertSame('Fallback', $filters['joli_media_caption'](self::FILTER_MEDIA, 'Fallback'));
        self::assertNull($filters['joli_media_alt'](self::FILTER_MEDIA));
        self::assertNull($filters['joli_media_copyright'](self::FILTER_MEDIA));
        self::assertSame([], $filters['joli_media_tags'](self::FILTER_MEDIA));
        self::assertNull($filters['joli_media_metadata'](self::FILTER_MEDIA));
    }

    public function testTheFilterStoresTheVariationWhenTheLibraryRequiresIt(): void
    {
        $url = $this->renderUrlFilter(self::FILTER_MEDIA, 'variation-standard', 'auto_generate');

        self::assertSame('/media-auto-generate/cache/variation-standard/' . self::FILTER_MEDIA, $url);
        self::assertTrue($this->isVariationStored('auto_generate', self::FILTER_MEDIA, 'variation-standard'));
    }

    public function testTheFilterDoesNotStoreTheVariationWhenTheLibraryDoesNotRequireIt(): void
    {
        $url = $this->renderUrlFilter(self::FILTER_MEDIA, 'variation-standard', 'default');

        self::assertSame('/media/cache/variation-standard/' . self::FILTER_MEDIA, $url);
        self::assertFalse($this->isVariationStored('default', self::FILTER_MEDIA, 'variation-standard'));
    }

    public function testTheFilterHonorsTheVariationLevelSetting(): void
    {
        // the "variation-auto-stored" variation enables the storage in the
        // "default" library, where it is disabled at the library level
        $this->renderUrlFilter(self::FILTER_MEDIA, 'variation-auto-stored', 'default');

        self::assertTrue($this->isVariationStored('default', self::FILTER_MEDIA, 'variation-auto-stored'));

        // the "variation-never-stored" variation disables the storage in the
        // "auto_generate" library, where it is enabled at the library level
        $this->renderUrlFilter(self::FILTER_MEDIA, 'variation-never-stored', 'auto_generate');

        self::assertFalse($this->isVariationStored('auto_generate', self::FILTER_MEDIA, 'variation-never-stored'));
    }

    public function testGeneratingTheUrlFromTheModelStoresTheVariation(): void
    {
        // the conversion is triggered by MediaVariation::getUrl() itself, without
        // any Twig involvement - eg. from an API Platform normalizer
        $library = self::getLibraryContainer()->get('auto_generate');
        $media = new Media(self::DIRECT_MEDIA, $library->getOriginalStorage());

        $url = $media->createVariation('variation-standard')->getUrl();

        self::assertSame('/media-auto-generate/cache/variation-standard/' . self::DIRECT_MEDIA, $url);
        self::assertTrue($this->isVariationStored('auto_generate', self::DIRECT_MEDIA, 'variation-standard'));
    }

    public function testTheSrcsetFilterBuildsWidthDescriptorsFromAListOfVariations(): void
    {
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        $srcset = $twig
            ->createTemplate("{{ path|joli_media_srcset(['variation-standard', 'variation-large'], 'default') }}")
            ->render(['path' => self::FILTER_MEDIA])
        ;

        self::assertSame(
            '/media/cache/variation-standard/' . self::FILTER_MEDIA . ' 145w, /media/cache/variation-large/' . self::FILTER_MEDIA . ' 800w',
            $srcset,
        );
    }

    public function testTheSrcsetFilterKeepsTheDescriptorsItIsGiven(): void
    {
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        $srcset = $twig
            ->createTemplate("{{ path|joli_media_srcset({ '': 'variation-standard', '2x': 'variation-large' }, 'default') }}")
            ->render(['path' => self::FILTER_MEDIA])
        ;

        self::assertSame(
            '/media/cache/variation-standard/' . self::FILTER_MEDIA . ', /media/cache/variation-large/' . self::FILTER_MEDIA . ' 2x',
            $srcset,
        );
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    private static function getLibraryContainer(): LibraryContainer
    {
        /** @var LibraryContainer $libraries */
        $libraries = static::getContainer()->get('joli_media.library_container');

        return $libraries;
    }

    private function saveMetadata(string $library, string $path): void
    {
        $metadata = new MediaMetadata($library, $path);
        $metadata->setAlt('A cat');
        $metadata->setCaption('A caption');
        $metadata->setCopyright('Cat Corp');
        $metadata->setTags(['pets', 'cats']);

        /** @var MetadataManager $manager */
        $manager = static::getContainer()->get('joli_media.metadata.manager');
        $manager->save($metadata);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context = []): string
    {
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        return $twig->createTemplate($template)->render($context + ['path' => self::FILTER_MEDIA]);
    }

    private function renderUrlFilter(string $path, string $variation, string $library): string
    {
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');

        return $twig
            ->createTemplate(\sprintf("{{ path|joli_media_url('%s', '%s') }}", $variation, $library))
            ->render(['path' => $path])
        ;
    }

    private function isVariationStored(string $libraryName, string $path, string $variationName): bool
    {
        $library = self::getLibraryContainer()->get($libraryName);

        return $library->getCacheStorage()->has($path, $library->getVariation($variationName));
    }
}
