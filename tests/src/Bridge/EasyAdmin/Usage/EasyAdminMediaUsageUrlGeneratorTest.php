<?php

namespace JoliCode\MediaBundle\Tests\Bridge\EasyAdmin\Usage;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Registry\AdminControllerRegistryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Router\AdminRouteGeneratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use JoliCode\MediaBundle\Bridge\EasyAdmin\Usage\EasyAdminMediaUsageUrlGenerator;
use JoliCode\MediaBundle\Tests\Application\Entity\Page;
use JoliCode\MediaBundle\Tests\Application\Kernel;
use JoliCode\MediaBundle\Usage\MediaUsage;
use JoliCode\MediaBundle\Usage\MediaUsageUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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

    public function testAFailingUrlGenerationGivesNoUrl(): void
    {
        // the constructor of AdminUrlGenerator is the EasyAdmin 5.x one
        if (!interface_exists(AdminControllerRegistryInterface::class)) {
            $this->markTestSkipped('The AdminUrlGenerator cannot be built this way before EasyAdmin 5.');
        }

        // outside of an EasyAdmin request, with several dashboards, EasyAdmin refuses to generate URLs
        $controllers = $this->createStub(AdminControllerRegistryInterface::class);
        $controllers->method('getDashboardCount')->willReturn(2);
        $adminUrlGenerator = new AdminUrlGenerator(
            $this->createStub(AdminContextProviderInterface::class),
            $this->createStub(UrlGeneratorInterface::class),
            $controllers,
            $this->createStub(AdminRouteGeneratorInterface::class),
            new ArrayAdapter(),
        );
        $registry = new class {
            public function findCrudControllerByEntity(string $entityClass): string
            {
                return 'App\Controller\AnyCrudController';
            }
        };
        $generator = new EasyAdminMediaUsageUrlGenerator($registry, $adminUrlGenerator);

        $this->assertNull($generator->generateEditUrl(new MediaUsage('Page', Page::class, null, 1)));
    }

    public function testCompositeIdentifiersHaveNoUrl(): void
    {
        $metadata = new ClassMetadata(Page::class);
        $metadata->identifier = ['first', 'second'];
        $manager = $this->createStub(ObjectManager::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn($manager);

        $registry = new class {
            public function findCrudControllerByEntity(string $entityClass): string
            {
                return 'App\Controller\AnyCrudController';
            }
        };
        /** @var AdminUrlGenerator $adminUrlGenerator */
        $adminUrlGenerator = static::getContainer()->get(AdminUrlGenerator::class);
        $generator = new EasyAdminMediaUsageUrlGenerator($registry, $adminUrlGenerator, $doctrine);

        $this->assertNull($generator->generateEditUrl(new MediaUsage('Page', Page::class, null, '1-2')));
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }
}
