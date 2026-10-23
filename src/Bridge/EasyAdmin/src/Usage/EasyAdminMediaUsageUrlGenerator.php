<?php

namespace JoliCode\MediaBundle\Bridge\EasyAdmin\Usage;

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
    ) {
    }

    #[\Override]
    public function generateEditUrl(MediaUsage $usage): ?string
    {
        if (null === $usage->entityClass || null === $usage->identifier || '' === $usage->identifier) {
            return null;
        }

        $crudController = $this->findCrudController($usage->entityClass);

        if (null === $crudController) {
            return null;
        }

        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController($crudController)
            ->setAction(Action::EDIT)
            ->setEntityId($usage->identifier)
            ->generateUrl()
        ;
    }

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
