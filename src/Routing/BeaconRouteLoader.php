<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Routing;

use Nowo\BeaconBundle\Controller\SelfTestController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

use function rtrim;
use function strlen;
use function substr;

/**
 * Route loader for optional BeaconBundle endpoints (`type: nowo_beacon`).
 *
 * Import once from the host (`config/routes/nowo_beacon.yaml`):
 *
 *     nowo_beacon:
 *         resource: .
 *         type: nowo_beacon
 *
 * Returns an empty collection unless `nowo_beacon.self_test.http.enabled` is true, so the import is safe to keep.
 * When enabled it imports the `#[Route]` attributes of {@see SelfTestController} (type `attribute`) and moves them from
 * {@see SelfTestController::DEFAULT_PATH} to `self_test.http.path`. Route names and paths are unchanged from 1.12.
 *
 * The loader stays the only way to expose these routes (REQ-SF-004 rule 7): the controller is not autoconfigured, so
 * the host's `routing.controllers` import does not see it, and the configurable path and the enabled flag live here.
 */
final class BeaconRouteLoader extends Loader
{
    public const TYPE = 'nowo_beacon';

    public const ROUTE_SELF_TEST = 'nowo_beacon_self_test';

    public const ROUTE_SELF_TEST_TRIGGER = 'nowo_beacon_self_test_trigger';

    public function __construct(
        private readonly bool $selfTestEnabled = false,
        private readonly string $selfTestPath = '/_beacon/self-test',
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $collection = new RouteCollection();
        if (!$this->selfTestEnabled) {
            return $collection;
        }

        $path = rtrim($this->selfTestPath, '/');
        $path = $path === '' ? SelfTestController::DEFAULT_PATH : $path;

        /** @var RouteCollection $routes every SelfTestController route starts with DEFAULT_PATH */
        $routes = $this->import(SelfTestController::class, 'attribute');
        foreach ($routes->all() as $route) {
            $route->setPath($path . substr($route->getPath(), strlen(SelfTestController::DEFAULT_PATH)));
        }

        $collection->addCollection($routes);

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
