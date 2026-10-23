<?php

namespace JoliCode\MediaBundle\Tests\Usage;

use Symfony\Component\Routing\RouterInterface;

trait WarmsAdminRoutesTrait
{
    /**
     * EasyAdmin 5 fills the cache of its CRUD controller registry when the routes are loaded: without it,
     * no CRUD controller is found for an entity in a test run on a cold cache.
     */
    private function warmAdminRoutes(): void
    {
        $router = static::getContainer()->get('router');
        \assert($router instanceof RouterInterface);
        $router->getRouteCollection();
    }
}
