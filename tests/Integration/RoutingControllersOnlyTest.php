<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Integration;

use Nowo\BeaconBundle\Tests\Fixtures\Routing\RoutingControllersKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function array_filter;
use function array_keys;
use function array_values;
use function class_exists;
use function str_starts_with;
use function sys_get_temp_dir;

/**
 * REQ-SF-004 rule 7: the self-test routes are opt-in. A host whose routing is only the skeleton's
 * `routing.controllers` import must not get them, even with `self_test.http.enabled: true`.
 */
final class RoutingControllersOnlyTest extends TestCase
{
    protected function tearDown(): void
    {
        (new Filesystem())->remove([
            sys_get_temp_dir() . '/nowo-beacon-routing-controllers',
            sys_get_temp_dir() . '/nowo-beacon-routing-import',
        ]);
    }

    public function testRoutingControllersAloneExposesNoBeaconRoute(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers needs Symfony >= 7.3.');
        }

        self::assertSame([], $this->beaconRouteNames(new RoutingControllersKernel(false)));
    }

    public function testTheDocumentedImportExposesTheSelfTestRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers needs Symfony >= 7.3.');
        }

        self::assertSame(
            ['nowo_beacon_self_test', 'nowo_beacon_self_test_trigger'],
            $this->beaconRouteNames(new RoutingControllersKernel(true)),
        );
    }

    /**
     * @return list<string>
     */
    private function beaconRouteNames(RoutingControllersKernel $kernel): array
    {
        $kernel->boot();
        try {
            $router = $kernel->getContainer()->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);
            $names = array_keys($router->getRouteCollection()->all());

            return array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'nowo_beacon')));
        } finally {
            $kernel->shutdown();
        }
    }
}
