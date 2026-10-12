<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Routing;

use Nowo\BeaconBundle\Controller\SelfTestController;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTest;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function implode;
use function rtrim;

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
        $path = $path === '' ? '/_beacon/self-test' : $path;

        $collection->add(self::ROUTE_SELF_TEST, new Route(
            $path,
            ['_controller' => SelfTestController::class . '::status'],
            methods: ['GET'],
        ));
        $collection->add(self::ROUTE_SELF_TEST_TRIGGER, new Route(
            $path . '/{kind}',
            ['_controller' => SelfTestController::class . '::trigger'],
            ['kind' => implode('|', BeaconSelfTest::kinds())],
            methods: ['POST'],
        ));

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
