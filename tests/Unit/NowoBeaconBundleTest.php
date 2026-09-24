<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit;

use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler;
use Nowo\BeaconBundle\NowoBeaconBundle;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Symfony\Component\DependencyInjection\Container;

final class NowoBeaconBundleTest extends TestCase
{
    public function testBundleCanBeConstructed(): void
    {
        $bundle = new NowoBeaconBundle();

        self::assertSame('NowoBeaconBundle', $bundle->getName());
    }

    public function testBootRegistersFatalErrorHandler(): void
    {
        $handler   = new BeaconFatalErrorHandler(new NullBeaconClient(), true);
        $container = new Container();
        $container->set(BeaconFatalErrorHandler::class, $handler);

        $bundle = new NowoBeaconBundle();
        $bundle->setContainer($container);
        $bundle->boot();
        $bundle->boot();

        self::assertTrue((new ReflectionProperty(BeaconFatalErrorHandler::class, 'registered'))->getValue($handler));
    }

    public function testBootIsNoopWithoutContainerOrHandler(): void
    {
        $bundle = new NowoBeaconBundle();
        $bundle->setContainer(null);
        $bundle->boot();

        $container = new Container();
        $container->set(BeaconFatalErrorHandler::class, new stdClass());
        $bundle->setContainer($container);
        $bundle->boot();

        $bundle->setContainer(new Container());
        $bundle->boot();

        $this->addToAssertionCount(1);
    }
}
