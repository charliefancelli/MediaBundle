<?php

namespace JoliCode\MediaBundle\Tests\Bridge\EasyAdmin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use JoliCode\MediaBundle\Bridge\EasyAdmin\Asset\AssetRegistrar;
use JoliCode\MediaBundle\Bridge\EasyAdmin\Config\Config;
use JoliCode\MediaBundle\Bridge\EasyAdmin\Controller\MediaAdminController;
use JoliCode\MediaBundle\Bridge\EasyAdmin\Paginator\MediaPaginator;
use JoliCode\MediaBundle\Bridge\EasyAdmin\Router\MediaAdminRouter;
use JoliCode\MediaBundle\Conversion\Converter;
use JoliCode\MediaBundle\Library\LibraryContainer;
use JoliCode\MediaBundle\Metadata\MetadataProvider;
use JoliCode\MediaBundle\Metadata\Model\MediaMetadata;
use JoliCode\MediaBundle\Metadata\Storage\MetadataStorageInterface;
use JoliCode\MediaBundle\Resolver\Resolver;
use JoliCode\MediaBundle\Tests\Application\Doctrine\QueryCounterMiddleware;
use JoliCode\MediaBundle\Tests\Application\Entity\Page;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use JoliCode\MediaBundle\Tests\Application\Security\TestMediaVoter;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class MediaAdminControllerTest extends WebTestCase
{
    private const METADATA_PATH = 'sub/folder/circle-pattern.png';

    private const OTHER_PATH = 'not_used/default.pdf';

    private const LONG_PATH = 'a-folder-with-a-very-long-name-level-1/a-folder-with-a-very-long-name-level-2/a-folder-with-a-very-long-name-level-3/a-folder-with-a-very-long-name-level-4/a-folder-with-a-very-long-name-level-5/a-folder-with-a-very-long-name-level-6/a-folder-with-a-very-long-name-level-7/a-folder-with-a-very-long-name-level-8/a-folder-with-a-very-long-name-level-9/a-folder-with-a-very-long-name-level-10/test.txt';

    private KernelBrowser $client;

    private EntityRepository $pageRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        /** @var EntityManagerInterface */
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->pageRepository = $entityManager->getRepository(Page::class);

        if (self::$kernel instanceof Kernel) {
            $application = new Application(self::$kernel);
            $application->setAutoExit(false);
            $application->run(new StringInput('doctrine:fixtures:load --purge-with-truncate --no-interaction --quiet'));
        }
    }

    protected function tearDown(): void
    {
        TestMediaVoter::$denyEditMetadata = false;

        $storage = static::getContainer()->get('test.joli_media.metadata.storage');
        \assert($storage instanceof MetadataStorageInterface);
        foreach ([self::METADATA_PATH, self::OTHER_PATH, self::LONG_PATH] as $path) {
            $storage->delete('default', $path);
        }

        parent::tearDown();
    }

    public function testMetadataTabIsDisplayed(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/show/' . self::METADATA_PATH);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#tablist-tab-metadata');
        $this->assertSelectorExists('#tab-metadata');
        foreach (['alt', 'caption', 'copyright', 'tags'] as $field) {
            $this->assertCount(1, $crawler->filter(\sprintf('#tab-metadata [name="metadata[%s]"]', $field)), $field);
        }
        $this->assertSelectorExists('#tab-metadata input[name="metadata[tags]"][list="joli-media-tags"]');
        $this->assertSelectorExists('datalist#joli-media-tags');
    }

    public function testTheTagsDatalistSuggestsTheExistingTags(): void
    {
        $this->saveMetadata('Alt', 'Caption', 'Copyright', ['suggested-one', 'suggested-two']);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/show/' . self::METADATA_PATH);

        $options = $crawler->filter('datalist#joli-media-tags option')->each(static fn (Crawler $node): string => (string) $node->attr('value'));
        $this->assertContains('suggested-one', $options);
        $this->assertContains('suggested-two', $options);
    }

    public function testSavingMetadata(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/show/' . self::METADATA_PATH);
        $form = $crawler->filter('#tab-metadata form')->selectButton('Save the metadata')->form([
            'metadata[alt]' => 'A circle pattern',
            'metadata[caption]' => 'The caption',
            'metadata[copyright]' => 'Me',
            'metadata[tags]' => 'one, two ,One, three',
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/media/show/' . self::METADATA_PATH . '?_tab=3');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'The metadata of the media "circle-pattern.png" were successfully saved');

        $metadata = $this->getMetadata();
        $this->assertSame('A circle pattern', $metadata?->getAlt());
        $this->assertSame('The caption', $metadata->getCaption());
        $this->assertSame('Me', $metadata->getCopyright());
        $this->assertSame(['one', 'two', 'three'], $metadata->getTags());
    }

    public function testEmptyingEveryFieldDeletesTheMetadata(): void
    {
        $this->saveMetadata('Alt', 'Caption', 'Copyright', ['one']);
        $this->assertInstanceOf(MediaMetadata::class, $this->getMetadata());

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/show/' . self::METADATA_PATH);
        $form = $crawler->filter('#tab-metadata form')->selectButton('Save the metadata')->form([
            'metadata[alt]' => '',
            'metadata[caption]' => '',
            'metadata[copyright]' => '',
            'metadata[tags]' => '',
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/admin/media/show/' . self::METADATA_PATH . '?_tab=3');
        $this->assertNotInstanceOf(MediaMetadata::class, $this->getMetadata());
    }

    public function testInvalidMetadataIsRefused(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/show/' . self::METADATA_PATH);
        $form = $crawler->filter('#tab-metadata form')->selectButton('Save the metadata')->form([
            'metadata[alt]' => str_repeat('a', MediaMetadata::ALT_MAX_LENGTH + 1),
        ]);
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-danger', 'Failed to save the metadata of the media "circle-pattern.png"');
        $this->assertSelectorTextContains('#tab-metadata #metadata_alt_error1', 'This value is too long');
        $this->assertNotInstanceOf(MediaMetadata::class, $this->getMetadata());
    }

    public function testEditingMetadataRequiresTheGrant(): void
    {
        TestMediaVoter::$denyEditMetadata = true;

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/show/' . self::METADATA_PATH);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#tab-metadata');
        $this->assertSelectorNotExists('form[name="metadata"]');
        $this->assertCount(0, $crawler->filter('[name="metadata[alt]"]'));

        $this->client->request(Request::METHOD_POST, '/admin/media/show/' . self::METADATA_PATH, [
            'metadata' => ['alt' => 'Denied'],
        ]);
        $this->assertResponseStatusCodeSame(403);
        $this->assertNotInstanceOf(MediaMetadata::class, $this->getMetadata());
    }

    public function testAnInvalidSubmissionDoesNotTouchTheStoredMetadata(): void
    {
        $this->saveMetadata('Stored alt', 'Stored caption', 'Stored copyright', ['stored']);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/show/' . self::METADATA_PATH);
        $form = $crawler->filter('#tab-metadata form')->selectButton('Save the metadata')->form([
            'metadata[alt]' => str_repeat('a', MediaMetadata::ALT_MAX_LENGTH + 1),
            'metadata[tags]' => 'changed, other',
        ]);
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-danger', 'Failed to save the metadata');
        $this->assertStoredMetadataIsUnchanged();
    }

    public function testADeniedSubmissionDoesNotTouchTheStoredMetadata(): void
    {
        $this->saveMetadata('Stored alt', 'Stored caption', 'Stored copyright', ['stored']);
        TestMediaVoter::$denyEditMetadata = true;

        $this->client->request(Request::METHOD_POST, '/admin/media/show/' . self::METADATA_PATH, [
            'metadata' => ['alt' => 'Denied', 'tags' => 'changed'],
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertStoredMetadataIsUnchanged();
    }

    public function testAnUnavailableTabFallsBackToTheFirstOne(): void
    {
        $this->client->request(Request::METHOD_GET, '/admin/media/show/' . self::METADATA_PATH . '?_tab=9');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#tab-general.active');

        TestMediaVoter::$denyEditMetadata = true;
        $this->client->request(Request::METHOD_GET, '/admin/media/show/' . self::METADATA_PATH . '?_tab=3');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#tab-metadata');
    }

    public function testNothingMetadataRelatedIsDisplayedWhenTheMetadataAreDisabled(): void
    {
        $container = static::getContainer();
        // same wiring as the bridge, without the metadata services (what an application without metadata gets)
        $disabled = new MediaAdminController(
            $this->service('joli_media.library_container', LibraryContainer::class),
            $this->service('joli_media_easy_admin.config', Config::class),
            $this->service('joli_media.resolver', Resolver::class),
            $this->service('joli_media.converter', Converter::class),
            $this->service('translator', TranslatorInterface::class),
            $this->service('twig', Environment::class),
            $this->service('form.factory', FormFactoryInterface::class),
            $this->service('joli_media_easy_admin.router', MediaAdminRouter::class),
            $this->service('joli_media_easy_admin.paginator', MediaPaginator::class),
            $this->service('joli_media_easy_admin.asset.registrar', AssetRegistrar::class),
            $this->service('security.authorization_checker', AuthorizationCheckerInterface::class),
        );
        $disabled->setContainer($this->service('service_container', ContainerInterface::class));
        $container->set('joli_media_easy_admin.controller.admin', $disabled);

        $this->client->request(Request::METHOD_POST, '/admin/media/show/' . self::METADATA_PATH, [
            'metadata' => ['alt' => 'Ignored'],
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#tablist-tab-metadata');
        $this->assertSelectorNotExists('#tab-metadata');
        $this->assertSelectorNotExists('datalist');
        $this->assertNotInstanceOf(MediaMetadata::class, $this->getMetadata());
    }

    public function testMetadataTabIsHiddenForTooLongPaths(): void
    {
        $this->assertGreaterThan(MediaMetadata::PATH_MAX_LENGTH, mb_strlen(self::LONG_PATH));

        $this->client->request(Request::METHOD_GET, '/admin/media/show/' . self::LONG_PATH);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#tablist-tab-metadata');
        $this->assertSelectorTextContains('#tab-metadata', 'The path of this media is too long');
        $this->assertSelectorNotExists('#tab-metadata form');
    }

    public function testImagesWithoutAltAreMarked(): void
    {
        $views = [
            'explore grid' => ['/admin/media/explore/sub/folder', null],
            'explore list' => ['/admin/media/explore/sub/folder', 'list'],
            'choose grid' => ['/admin/media/choose-file/sub/folder', 'grid'],
            'choose list' => ['/admin/media/choose-file/sub/folder', 'list'],
        ];

        foreach ($views as $label => [$url, $viewMode]) {
            $this->setViewMode($viewMode);
            $crawler = $this->client->request(Request::METHOD_GET, $url);
            $this->assertResponseIsSuccessful();
            $marker = $this->findMediaNode($crawler, 'circle-pattern.png')->filter('[data-joli-media-missing-alt]');
            $this->assertCount(1, $marker, $label);
            $this->assertNotSame('', (string) $marker->attr('title'), $label);
        }

        // a stored alt removes the marker
        $this->saveMetadata('A circle', '', '', []);
        foreach ($views as $label => [$url, $viewMode]) {
            $this->setViewMode($viewMode);
            $crawler = $this->client->request(Request::METHOD_GET, $url);
            $this->assertCount(1, $this->findMediaNode($crawler, 'circle-pattern.png'), $label);
            $this->assertCount(0, $this->findMediaNode($crawler, 'circle-pattern.png')->filter('[data-joli-media-missing-alt]'), $label);
        }

        // a document is never marked
        $this->setViewMode('grid');
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/explore');
        $pdf = $this->findInGalleryFromName($crawler, 'default.pdf');
        $this->assertCount(1, $pdf);
        $this->assertCount(0, $pdf->filter('[data-joli-media-missing-alt]'));
    }

    public function testTheMissingAltMarkerNeedsTheAltField(): void
    {
        // the kernel is rebooted between requests: the controller is swapped right before its only request
        $this->useController(['caption', 'copyright', 'tags']);
        $this->client->request(Request::METHOD_GET, '/admin/media/explore/sub/folder');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.gallery-grid-item__link');
        $this->assertSelectorNotExists('[data-joli-media-missing-alt]');
    }

    public function testTheListLoadsMetadataInOneQuery(): void
    {
        $this->saveMetadata('Alt', '', '', ['one']);

        QueryCounterMiddleware::reset();
        $this->client->request(Request::METHOD_GET, '/admin/media/explore/sub/folder');
        $this->assertResponseIsSuccessful();

        $queries = array_filter(
            QueryCounterMiddleware::getQueries(),
            static fn (string $sql): bool => str_contains($sql, 'joli_media_metadata') && str_starts_with(ltrim($sql), 'SELECT'),
        );
        $this->assertCount(1, $queries, implode("\n", $queries));
    }

    public function testFilterByTag(): void
    {
        $this->saveMetadata('Alt', '', '', ['sport']);
        $other = new MediaMetadata('default', self::OTHER_PATH);
        $other->setTags(['sport', 'other']);
        $storage = $this->service('test.joli_media.metadata.storage', MetadataStorageInterface::class);
        $storage->save($other);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/explore?tag=sport');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(2, 'ul.gallery-grid--files .gallery-grid-item');
        $this->assertSelectorCount(0, 'ul.gallery-grid--folders .gallery-grid-item');
        $hrefs = $crawler->filter('ul.gallery-grid--files .gallery-grid-item__link')->each(static fn (Crawler $node): string => (string) $node->attr('href'));
        $this->assertCount(2, array_filter($hrefs, static fn (string $href): bool => str_ends_with($href, self::METADATA_PATH) || str_ends_with($href, self::OTHER_PATH)));
        $this->assertSelectorTextContains('[data-joli-media-tag-filter]', 'Tag: sport');
        $this->assertStringEndsWith('/admin/media/explore', (string) $crawler->filter('[data-joli-media-tag-filter] a')->attr('href'));

        // the filter is recursive from the current folder
        $this->client->request(Request::METHOD_GET, '/admin/media/explore/sub?tag=sport');
        $this->assertSelectorCount(1, 'ul.gallery-grid--files .gallery-grid-item');

        // the media picker supports it too
        $this->client->request(Request::METHOD_GET, '/admin/media/choose-file?tag=sport');
        $this->assertSelectorCount(2, 'ul.gallery-grid--files .gallery-grid-item');

        $this->client->request(Request::METHOD_GET, '/admin/media/explore?tag=unknown');
        $this->assertSelectorCount(0, 'ul.gallery-grid--files .gallery-grid-item');
    }

    public function testThePaginationKeepsTheTag(): void
    {
        $paginator = $this->service('joli_media_easy_admin.paginator', MediaPaginator::class);
        $paginator->paginateMedias(['items' => [], 'total' => 100, 'page' => 1, 'perPage' => 20], 'explore', 'sub', '', 'sport');

        $this->assertStringContainsString('tag=sport', $paginator->generateUrlForPage(2));
        $this->assertStringContainsString('page=2', $paginator->generateUrlForPage(2));
    }

    public function testTagsLinkToTheFilteredList(): void
    {
        $this->saveMetadata('Alt', '', '', ['sport', 'to see']);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/show/' . self::METADATA_PATH);

        $links = $crawler->filter('#tab-general a[data-joli-media-tag]');
        $this->assertCount(2, $links);
        $this->assertSame('sport', trim($links->eq(0)->text()));
        $this->assertStringEndsWith('/admin/media/explore?tag=sport', (string) $links->eq(0)->attr('href'));
        $this->assertStringEndsWith('/admin/media/explore?tag=to%20see', (string) $links->eq(1)->attr('href'));
    }

    public function testAStaleTaggedRowIsSkipped(): void
    {
        $this->saveMetadata('Alt', '', '', ['stale-test']);
        $gone = new MediaMetadata('default', 'sub/folder/gone.png');
        $gone->setTags(['stale-test']);
        $storage = $this->service('test.joli_media.metadata.storage', MetadataStorageInterface::class);
        $storage->save($gone);

        try {
            $this->client->request(Request::METHOD_GET, '/admin/media/explore?tag=stale-test');
            $this->assertResponseIsSuccessful();
            $this->assertSelectorCount(1, 'ul.gallery-grid--files .gallery-grid-item');
            $this->assertSelectorTextContains('ul.gallery-grid--files', 'circle-pattern.png');
        } finally {
            $storage->delete('default', 'sub/folder/gone.png');
        }
    }

    public function testTheTagFilterIsIgnoredWithoutTheTagsField(): void
    {
        $this->saveMetadata('Alt', '', '', ['sport']);
        $this->useController(['alt', 'caption', 'copyright']);

        $this->client->request(Request::METHOD_GET, '/admin/media/explore?tag=sport');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-joli-media-tag-filter]');
        // not filtered: the directories are listed
        $this->assertSelectorExists('ul.gallery-grid--folders .gallery-grid-item');
    }

    public function testTagsAreNotLinkedWithoutTheTagsField(): void
    {
        $this->saveMetadata('Alt', '', '', ['sport']);
        $this->useController(['alt', 'caption', 'copyright']);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/show/' . self::METADATA_PATH);
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('a[data-joli-media-tag]'));
    }

    public function testTheSearchFormsKeepTheTag(): void
    {
        $this->saveMetadata('Alt', '', '', ['sport']);

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/explore?tag=sport');
        $this->assertSame('sport', $crawler->filter('form[data-component="media-search"] input[name="tag"]')->attr('value'));

        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/choose-file?tag=sport');
        $this->assertSame('sport', $crawler->filter('form[data-component="media-search"] input[name="tag"]')->attr('value'));

        // submitting the form searches inside the tagged medias
        $form = $crawler->filter('form[data-component="media-search"]')->form(['query' => 'circle']);
        $crawler = $this->client->submit($form);
        $this->assertSelectorCount(1, 'ul.gallery-grid--files .gallery-grid-item');
        $this->assertStringContainsString('tag=sport', $this->client->getRequest()->getUri());

        $form = $this->client->request(Request::METHOD_GET, '/admin/media/choose-file?tag=sport')->filter('form[data-component="media-search"]')->form(['query' => 'nothing-like-this']);
        $this->client->submit($form);
        $this->assertSelectorCount(0, 'ul.gallery-grid--files .gallery-grid-item');

        $this->assertSelectorExists('[data-joli-media-tag-filter]');
    }

    public function testTheViewModeLinksKeepTheSearchAndTheTag(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/choose-file?tag=sport&query=circle');
        $href = (string) $crawler->filter('.joli-media-view-switch a')->first()->attr('href');
        $this->assertStringContainsString('view_mode=list', $href);
        $this->assertStringContainsString('tag=sport', $href);
        $this->assertStringContainsString('query=circle', $href);

        $this->client->request(Request::METHOD_GET, $href);
        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('tag=sport', $location);
        $this->assertStringContainsString('query=circle', $location);
        $this->assertStringNotContainsString('view_mode', $location);
    }

    public function testTheRenderedPaginationKeepsTheTag(): void
    {
        $this->saveMetadata('Alt', '', '', ['sport']);
        $other = new MediaMetadata('default', self::OTHER_PATH);
        $other->setTags(['sport']);
        $this->service('test.joli_media.metadata.storage', MetadataStorageInterface::class)->save($other);

        $this->useController(['alt', 'caption', 'copyright', 'tags'], 1);
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/explore?tag=sport');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(1, 'ul.gallery-grid--files .gallery-grid-item');

        $links = $crawler->filter('.pagination a')->each(static fn (Crawler $node): string => (string) $node->attr('href'));
        $pageLinks = array_filter($links, static fn (string $href): bool => str_contains($href, 'page=2'));
        $this->assertNotEmpty($pageLinks);
        foreach ($pageLinks as $href) {
            $this->assertStringContainsString('tag=sport', $href);
        }
    }

    public function testDelete(): void
    {
        // MediaDeleteBehavior::RESTRICT
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/explore');
        $media = $this->findInGalleryFromName($crawler, 'restrict.pdf');
        $crawler = $this->client->click($media->link());
        $this->assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="delete"]')->form());
        $this->assertResponseRedirects('/admin/media/show/restrict.pdf');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-danger', 'The media "restrict.pdf" is used in the "mediaRestrict" field of the "JoliCode\MediaBundle\Tests\Application\Entity\Page" entity. It cannot be deleted.');

        // MediaDeleteBehavior::SET_NULL
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/explore');
        $media = $this->findInGalleryFromName($crawler, 'set_null.pdf');
        $crawler = $this->client->click($media->link());
        $this->assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="delete"]')->form());
        $this->assertResponseRedirects('/admin/media/explore');
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Media "set_null.pdf" deleted successfully');

        $this->client->request(Request::METHOD_GET, '/admin/media/show/set_null.pdf');
        $this->assertResponseStatusCodeSame(404);

        /** @var Page $page */
        $page = $this->pageRepository->findOneBy(['title' => 'Page 1']);
        $this->assertNull($page->getMediaSetNull());

        // no MediaDeleteBahavior defined
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/explore');
        $media = $this->findInGalleryFromName($crawler, 'default.pdf');
        $crawler = $this->client->click($media->link());
        $this->assertResponseIsSuccessful();

        $this->client->submit($crawler->filter('form[name="delete"]')->form());
        $this->assertResponseRedirects('/admin/media/explore');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.alert-success', 'Media "default.pdf" deleted successfully');

        $this->client->request(Request::METHOD_GET, '/admin/media/show/default.pdf');
        $this->assertResponseStatusCodeSame(404);

        /** @var Page $page */
        $page = $this->pageRepository->findOneBy(['title' => 'Page 1']);
        $this->assertSame('default.pdf', $page->getMediaDefault()->getPath());
    }

    public function testUploadAtRoot(): void
    {
        // test upload at the root of the media library
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/explore');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="upload"]');
        $form = $crawler->filter('form[name="upload"]')->form();

        $tmpFile = tempnam(sys_get_temp_dir(), 'upload-test');
        copy(__DIR__ . '/../../../../fixtures/circle-pattern.png', $tmpFile);

        $phpValues = $form->getPhpValues();
        unset($phpValues['upload']['path']);

        $this->client->request($form->getMethod(), $form->getUri(), $phpValues, [
            'upload' => [
                'file' => new UploadedFile(
                    $tmpFile,
                    'circle-pattern.png',
                    'image/png',
                    null,
                    true,
                ),
            ],
        ]);

        $this->assertResponseFormatSame('json');
        $this->assertResponseIsSuccessful();
        $responseContent = $this->client->getResponse()->getContent();
        $this->assertIsString($responseContent);

        $response = json_decode($responseContent, true);
        $this->assertArrayHasKey('files', $response);
        $this->assertCount(1, $response['files']);

        $this->assertArrayHasKey('name', $response['files'][0]);
        $this->assertArrayHasKey('url', $response['files'][0]);
        $this->assertArrayHasKey('size', $response['files'][0]);
        $this->assertArrayHasKey('type', $response['files'][0]);
        $this->assertArrayHasKey('thumbnailUrl', $response['files'][0]);
        $this->assertArrayHasKey('link', $response['files'][0]);

        $this->assertSame('circle-pattern.png', $response['files'][0]['name']);
        $this->assertSame('/media/original/circle-pattern.png', $response['files'][0]['url']);
        $this->assertSame(62563, $response['files'][0]['size']);
        $this->assertSame('image/png', $response['files'][0]['type']);
        $this->assertSame('/media/cache/joli-media-easy-admin/circle-pattern.png', $response['files'][0]['thumbnailUrl']);
        $this->assertSame('http://localhost/admin/media/show/circle-pattern.png', $response['files'][0]['link']);
    }

    public function testViewMode(): void
    {
        // test switching view mode between grid and list
        $this->client->followRedirects();
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/explore');

        $gridViewLink = $crawler->selectLink('Grid view');
        $this->assertStringContainsString('active', (string) $gridViewLink->attr('class')); // grid is default
        $this->assertSelectorCount(6, '.gallery-grid-item');
        $this->assertSelectorNotExists('.gallery-list-item');

        $listViewLink = $crawler->selectLink('List view');
        $this->assertStringNotContainsString('active', (string) $listViewLink->attr('class'));

        $crawler = $this->client->click($listViewLink->link());

        $listViewLink = $crawler->selectLink('List view');
        $this->assertStringContainsString('active', (string) $listViewLink->attr('class'));
        $this->assertSelectorNotExists('.gallery-grid-item');
        $this->assertSelectorCount(6, '.gallery-list-item');

        $gridViewLink = $crawler->selectLink('Grid view');
        $this->assertStringNotContainsString('active', (string) $gridViewLink->attr('class'));

        $crawler = $this->client->click($gridViewLink->link()); // back to grid

        $this->assertStringContainsString('active', (string) $crawler->selectLink('Grid view')->attr('class'));
        $this->assertSelectorCount(6, '.gallery-grid-item');
        $this->assertSelectorNotExists('.gallery-list-item');

        // test the view mode inside a subfolder
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/explore/sub/folder');
        $gridViewLink = $crawler->selectLink('Grid view');
        $this->assertStringContainsString('active', (string) $gridViewLink->attr('class')); // grid is default
        $this->assertSelectorCount(3, '.gallery-grid-item');
        $this->assertSelectorNotExists('.gallery-list-item');

        $listViewLink = $crawler->selectLink('List view');
        $this->assertStringNotContainsString('active', (string) $listViewLink->attr('class'));

        $crawler = $this->client->click($listViewLink->link());

        $listViewLink = $crawler->selectLink('List view');
        $this->assertStringContainsString('active', (string) $listViewLink->attr('class'));
        $this->assertSelectorNotExists('.gallery-grid-item');
        $this->assertSelectorCount(3, '.gallery-list-item');
    }

    public function testExploringAFileRedirectsToItsShowPage(): void
    {
        $this->client->request(Request::METHOD_GET, '/admin/media/explore/sub/folder/circle-pattern.png');
        $this->assertResponseRedirects('http://localhost/admin/media/show/sub/folder/circle-pattern.png');

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    public function testExploringAMissingDirectoryShowsAnEmptyFolder(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/explore/does-not-exist');
        $this->assertResponseIsSuccessful();

        $this->assertSelectorExists('.gallery');
        $this->assertCount(0, $crawler->filter('ul.gallery-grid--folders .gallery-grid-item:not(.gallery-grid-item--back)'));
        $this->assertCount(0, $crawler->filter('ul.gallery-grid--files .gallery-grid-item'));
    }

    public function testTheMediaLibraryRootRedirectsToTheExplorer(): void
    {
        $this->client->request(Request::METHOD_GET, '/admin/media');
        $this->assertResponseRedirects('http://localhost/admin/media/explore');

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.gallery');
    }

    public function testMediaPickerButton(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/page/new');
        $this->assertResponseIsSuccessful();

        // the folder to open is only known client-side, so the picker URL carries a
        // placeholder which the JavaScript replaces with the folder to browse
        $pickerUrl = $crawler->filter('a[data-component="media-choice-edit"]')->first()->attr('href');
        $this->assertIsString($pickerUrl);
        $this->assertStringEndsWith('/admin/media/choose-file/__FOLDER__', $pickerUrl);

        $this->client->request(Request::METHOD_GET, str_replace('__FOLDER__', 'sub/folder', $pickerUrl));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.breadcrumb-item.active', 'folder');
    }

    public function testTheTextEditorMediaPickerCarriesItsVariation(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/page/new');
        $this->assertResponseIsSuccessful();

        // the variation of the field wins over the one configured for the project
        $this->assertSame(
            'variation-large',
            $crawler->filter('#modal-media-choice_Page_body')->attr('data-variation'),
        );

        // a field without variation falls back to the one configured for the project
        $this->assertSame(
            'variation-standard',
            $crawler->filter('#modal-media-choice_Page_summary')->attr('data-variation'),
        );
    }

    public function testTheMediaPickerRendersTheRequestedVariation(): void
    {
        // without a variation, picking a media inserts the original one
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/choose-file/sub/folder');
        $this->assertResponseIsSuccessful();
        $template = (string) $this->findInGalleryFromName($crawler, 'circle-pattern.png')->attr('data-media-original-template');
        $this->assertStringContainsString('/media/original/sub/folder/circle-pattern.png', $template);

        // when a variation is requested, its markup is inserted instead
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/choose-file/sub/folder?variation=variation-large');
        $this->assertResponseIsSuccessful();
        $template = (string) $this->findInGalleryFromName($crawler, 'circle-pattern.png')->attr('data-media-original-template');
        $this->assertStringNotContainsString('/media/original/sub/folder/circle-pattern.png', $template);
        $this->assertStringContainsString('variation-large', $template);

        // media which cannot be declined in this variation keep their original markup
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/choose-file?variation=variation-large');
        $this->assertResponseIsSuccessful();
        $template = (string) $this->findInGalleryFromName($crawler, 'default.pdf')->attr('data-media-original-template');
        $this->assertStringContainsString('/media/original/default.pdf', $template);

        // an undefined variation is a configuration error
        $this->client->request(Request::METHOD_GET, '/admin/media/choose-file?variation=nope');
        $this->assertResponseStatusCodeSame(400);
    }

    public function testSearchFormDisplay(): void
    {
        // the choose media modal has a toggable search panel
        $this->client->request(Request::METHOD_GET, '/admin/media/choose-file');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-component="search"]');
        $this->assertSelectorExists('.search-container [data-component="media-search-input"]');

        // the media library has an always visible search bar next to the breadcrumb
        $this->client->request(Request::METHOD_GET, '/admin/media/explore');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-component="search"]');
        $this->assertSelectorNotExists('[data-component="media-search-input"]');
        $this->assertSelectorExists('form[data-component="media-search"] input[name="query"]');
        // with pretty URLs, searching only needs to submit the query to the current URL
        $this->assertSelectorNotExists('form[data-component="media-search"] input[name="routeName"]');

        // the folder picker modal has no JS support for the search form, it must not be displayed there
        $this->client->request(Request::METHOD_GET, '/admin/media/choose-directory');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-component="search"]');
        $this->assertSelectorNotExists('[data-component="media-search-input"]');
    }

    public function testSearch(): void
    {
        // matches both directories and medias, recursively
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/choose-file?query=deep');
        $this->assertResponseIsSuccessful();
        $this->assertSame('deep', $crawler->filter('[data-component="media-search-input"]')->attr('value'));
        $this->assertSelectorCount(1, 'ul.gallery-grid--folders .gallery-grid-item');
        $this->assertSelectorTextContains('ul.gallery-grid--folders .gallery-grid-item__name', 'deep');
        $this->assertSelectorCount(1, 'ul.gallery-grid--files .gallery-grid-item');
        $this->assertSame('sub/folder/deep/test.txt', $crawler->filter('ul.gallery-grid--files .gallery-grid-item__link')->attr('data-media-url'));

        // the search is case-insensitive
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/choose-file?query=DEEP');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(1, 'ul.gallery-grid--folders .gallery-grid-item');
        $this->assertSelectorCount(1, 'ul.gallery-grid--files .gallery-grid-item');
        $this->assertSame('sub/folder/deep/test.txt', $crawler->filter('ul.gallery-grid--files .gallery-grid-item__link')->attr('data-media-url'));

        // the search is scoped to the current directory
        $this->client->request(Request::METHOD_GET, '/admin/media/choose-file/sub?query=circle');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(1, 'ul.gallery-grid--files .gallery-grid-item');

        $this->client->request(Request::METHOD_GET, '/admin/media/choose-file/not_used?query=circle');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(0, 'ul.gallery-grid--files .gallery-grid-item');

        // a search without any match displays empty results
        $this->client->request(Request::METHOD_GET, '/admin/media/choose-file?query=doesnotexist');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorCount(0, 'ul.gallery-grid--folders .gallery-grid-item');
        $this->assertSelectorCount(0, 'ul.gallery-grid--files .gallery-grid-item');
    }

    public function testSearchResultsAreSorted(): void
    {
        // recursive listings are not sorted by the storage, the controller must
        // sort them to guarantee a stable pagination
        $crawler = $this->client->request(Request::METHOD_GET, '/admin/media/choose-file?query=pdf');
        $this->assertResponseIsSuccessful();

        $paths = $crawler->filter('ul.gallery-grid--files .gallery-grid-item__link')
            ->each(static fn (Crawler $node): string => (string) $node->attr('data-media-url'))
        ;
        $this->assertSame([
            'default.pdf',
            'not_used/default.pdf',
            'restrict.pdf',
            'set_null.pdf',
        ], $paths);
    }

    public function testSearchWithAnOutOfRangePageNumber(): void
    {
        $this->client->request(Request::METHOD_GET, '/admin/media/choose-file?query=pdf&page=1');
        $this->assertResponseIsSuccessful();

        $this->client->request(Request::METHOD_GET, '/admin/media/choose-file?query=pdf&page=2');
        $this->assertResponseStatusCodeSame(400);
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $id, string $class): object
    {
        $service = static::getContainer()->get($id);
        $this->assertInstanceOf($class, $service);

        return $service;
    }

    private function assertStoredMetadataIsUnchanged(): void
    {
        // anything left dirty in the entity manager would be written by this flush
        $this->service('doctrine', ManagerRegistry::class)->getManager()->flush();

        $metadata = $this->getMetadata();
        $this->assertSame('Stored alt', $metadata?->getAlt());
        $this->assertSame('Stored caption', $metadata->getCaption());
        $this->assertSame('Stored copyright', $metadata->getCopyright());
        $this->assertSame(['stored'], $metadata->getTags());
    }

    private function getMetadata(): ?MediaMetadata
    {
        $provider = static::getContainer()->get('joli_media.metadata.provider');
        \assert($provider instanceof MetadataProvider);
        $provider->reset();

        return $provider->get(self::METADATA_PATH, 'default');
    }

    /**
     * @param list<string> $tags
     */
    private function saveMetadata(string $alt, string $caption, string $copyright, array $tags): void
    {
        $metadata = new MediaMetadata('default', self::METADATA_PATH);
        $metadata->setAlt($alt);
        $metadata->setCaption($caption);
        $metadata->setCopyright($copyright);
        $metadata->setTags($tags);

        $storage = static::getContainer()->get('test.joli_media.metadata.storage');
        \assert($storage instanceof MetadataStorageInterface);
        $storage->save($metadata);
    }

    private function setViewMode(?string $mode): void
    {
        // the view mode lives in the session: switch it through the redirecting URL
        $this->client->request(Request::METHOD_GET, '/admin/media/explore?view_mode=' . ($mode ?? 'grid'));
    }

    private function findMediaNode(Crawler $crawler, string $name): Crawler
    {
        $grid = $this->findInGalleryFromName($crawler, $name);
        if (\count($grid) > 0) {
            return $grid;
        }

        return $crawler->filter('table.gallery-list .gallery-list-item')
            ->reduce(static fn (Crawler $node): bool => $node->filter('.gallery-list-item__name')->count() > 0 && str_contains($node->filter('.gallery-list-item__name')->text(), $name))
        ;
    }

    /**
     * Replaces the controller by one with other metadata fields and, optionally, another page size.
     *
     * @param list<string> $fields
     */
    private function useController(array $fields, ?int $paginationSize = null): void
    {
        $container = static::getContainer();
        $config = $this->service('joli_media_easy_admin.config', Config::class);

        if (null !== $paginationSize) {
            $reflection = new \ReflectionClass($config);
            $parent = $reflection->getParentClass();
            \assert($parent instanceof \ReflectionClass);
            $read = static fn (string $name): mixed => $parent->getProperty($name)->getValue($config);
            $config = new Config(
                $read('translator'),
                $read('visibility'),
                $read('acceptedFiles'),
                $read('maxFileSize'),
                $read('maxFiles'),
                $paginationSize,
            );
        }

        $controller = new MediaAdminController(
            $this->service('joli_media.library_container', LibraryContainer::class),
            $config,
            $this->service('joli_media.resolver', Resolver::class),
            $this->service('joli_media.converter', Converter::class),
            $this->service('translator', TranslatorInterface::class),
            $this->service('twig', Environment::class),
            $this->service('form.factory', FormFactoryInterface::class),
            $this->service('joli_media_easy_admin.router', MediaAdminRouter::class),
            $this->service('joli_media_easy_admin.paginator', MediaPaginator::class),
            $this->service('joli_media_easy_admin.asset.registrar', AssetRegistrar::class),
            $this->service('security.authorization_checker', AuthorizationCheckerInterface::class),
            $this->service('joli_media.metadata.provider', MetadataProvider::class),
            null,
            $this->service('test.joli_media.metadata.storage', MetadataStorageInterface::class),
            $fields,
        );
        $controller->setContainer($this->service('service_container', ContainerInterface::class));
        $container->set('joli_media_easy_admin.controller.admin', $controller);
    }

    private function findInGalleryFromName(Crawler $crawler, string $name): Crawler
    {
        return $crawler->filter('ul.gallery-grid--files .gallery-grid-item__link')
            ->reduce(static fn (Crawler $node): bool => str_contains($node->filter('.gallery-grid-item__name')->text(), $name))
        ;
    }
}
