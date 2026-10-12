<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Routing;

use Nowo\BeaconBundle\Controller\SelfTestController;
use Nowo\BeaconBundle\Routing\BeaconRouteLoader;
use PHPUnit\Framework\TestCase;

final class BeaconRouteLoaderTest extends TestCase
{
    public function testDisabledByDefault(): void
    {
        $loader = new BeaconRouteLoader();

        self::assertTrue($loader->supports('.', 'nowo_beacon'));
        self::assertFalse($loader->supports('.', 'annotation'));
        self::assertCount(0, $loader->load('.', 'nowo_beacon'));
    }

    public function testEnabledRegistersStatusAndTriggerRoutes(): void
    {
        $routes = (new BeaconRouteLoader(true, '/admin/beacon/self-test/'))->load('.', 'nowo_beacon');

        $status = $routes->get('nowo_beacon_self_test');
        self::assertNotNull($status);
        self::assertSame('/admin/beacon/self-test', $status->getPath());
        self::assertSame(['GET'], $status->getMethods());
        self::assertSame(SelfTestController::class . '::status', $status->getDefault('_controller'));

        $trigger = $routes->get('nowo_beacon_self_test_trigger');
        self::assertNotNull($trigger);
        self::assertSame('/admin/beacon/self-test/{kind}', $trigger->getPath());
        self::assertSame(['POST'], $trigger->getMethods());
        self::assertSame('uncaught-exception|php-error|php-warning|captured-exception|captured-message|messenger-failure', $trigger->getRequirement('kind'));

        $root = (new BeaconRouteLoader(true, '/'))->load('.', 'nowo_beacon')->get('nowo_beacon_self_test');
        self::assertNotNull($root);
        self::assertSame('/_beacon/self-test', $root->getPath());
    }
}
