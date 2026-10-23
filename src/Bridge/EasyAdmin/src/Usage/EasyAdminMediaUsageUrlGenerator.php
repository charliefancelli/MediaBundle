<?php

namespace JoliCode\MediaBundle\Bridge\EasyAdmin\Usage;

use Doctrine\Persistence\ManagerRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use JoliCode\MediaBundle\Usage\MediaUsage;
use JoliCode\MediaBundle\Usage\MediaUsageUrlGeneratorInterface;

/**
 * Links a usage to the edit page of the EasyAdmin CRUD controller managing its entity.
 *
 * The CRUD controller registry is not type-hinted on purpose: it is
 * `Registry\CrudControllerRegistry::findCrudFqcnByEntityFqcn()` in EasyAdmin 4.x and
 * `Registry\AdminControllerRegistry::findCrudControllerByEntity()` in EasyAdmin 5.x.
 * Both return a single controller: when several CRUD controllers manage the same
 * entity, EasyAdmin picks one (the last one registered) and so does this class.
 */
readonly class EasyAdminMediaUsageUrlGenerator implements MediaUsageUrlGeneratorInterface
{
    public function __construct(
        private object $crudControllerRegistry,
        private AdminUrlGenerator $adminUrlGenerator,
        private ?ManagerRegistry $doctrine = null,
    ) {
    }

    #[\Override]
    public function generateEditUrl(MediaUsage $usage): ?string
    {
        if (null === $usage->entityClass || null === $usage->identifier || '' === $usage->identifier) {
            return null;
        }

        // the finder joins the parts of a composite identifier, which EasyAdmin cannot resolve
        if ($this->hasCompositeIdentifier($usage->entityClass)) {
            return null;
        }

        $crudController = $this->findCrudController($usage->entityClass);

        if (null === $crudController) {
            return null;
        }

        try {
            return $this->adminUrlGenerator
                ->unsetAll()
                ->setController($crudController)
                ->setAction(Action::EDIT)
                ->setEntityId($usage->identifier)
                ->generateUrl()
            ;
        } catch (\RuntimeException|\InvalidArgumentException) {
            // no usable dashboard context, or the CRUD controller is not routed for it:
            // a missing link must never break the page listing the usages
            return null;
        }
    }

    /**
     * @param class-string $entityClass
     */
    private function hasCompositeIdentifier(string $entityClass): bool
    {
        $manager = $this->doctrine?->getManagerForClass($entityClass);

        return null !== $manager && \count($manager->getClassMetadata($entityClass)->getIdentifierFieldNames()) > 1;
    }

    /**
     * @param class-string $entityClass
     */
    private function findCrudController(string $entityClass): ?string
    {
        $registry = $this->crudControllerRegistry;

        if (method_exists($registry, 'findCrudControllerByEntity')) {
            return $registry->findCrudControllerByEntity($entityClass);
        }

        if (method_exists($registry, 'findCrudFqcnByEntityFqcn')) {
            return $registry->findCrudFqcnByEntityFqcn($entityClass);
        }

        return null;
    }
}
