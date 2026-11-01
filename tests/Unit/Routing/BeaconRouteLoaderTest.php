<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Routing;

use Nowo\BeaconBundle\Controller\SelfTestController;
use Nowo\BeaconBundle\Routing\BeaconRouteLoader;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTest;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\Loader\LoaderResolver;

use function implode;

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
        $routes = $this->loader('/admin/beacon/self-test/')->load('.', 'nowo_beacon');

        self::assertCount(2, $routes);
        $status = $routes->get('nowo_beacon_self_test');
        self::assertNotNull($status);
        self::assertSame('/admin/beacon/self-test', $status->getPath());
        self::assertSame(['GET'], $status->getMethods());
        self::assertSame(SelfTestController::class . '::status', $status->getDefault('_controller'));

        $trigger = $routes->get('nowo_beacon_self_test_trigger');
        self::assertNotNull($trigger);
        self::assertSame('/admin/beacon/self-test/{kind}', $trigger->getPath());
        self::assertSame(['POST'], $trigger->getMethods());
        self::assertSame(SelfTestController::class . '::trigger', $trigger->getDefault('_controller'));
        self::assertSame('uncaught-exception|php-error|php-warning|captured-exception|captured-message|messenger-failure', $trigger->getRequirement('kind'));

        $root = $this->loader('/')->load('.', 'nowo_beacon')->get('nowo_beacon_self_test');
        self::assertNotNull($root);
        self::assertSame('/_beacon/self-test', $root->getPath());
    }

    public function testDefaultPathKeepsTheAttributePaths(): void
    {
        $routes = $this->loader('/_beacon/self-test')->load('.', 'nowo_beacon');

        self::assertSame('/_beacon/self-test', $routes->get('nowo_beacon_self_test')?->getPath());
        self::assertSame('/_beacon/self-test/{kind}', $routes->get('nowo_beacon_self_test_trigger')?->getPath());
    }

    public function testKindRequirementListsEverySelfTestKind(): void
    {
        self::assertSame(implode('|', BeaconSelfTest::kinds()), SelfTestController::KIND_REQUIREMENT);
    }

    private function loader(string $path): BeaconRouteLoader
    {
        $loader = new BeaconRouteLoader(true, $path);
        new LoaderResolver([$loader, new AttributeRouteControllerLoader()]);

        return $loader;
    }
}
