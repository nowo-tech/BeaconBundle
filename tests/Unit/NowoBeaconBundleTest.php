<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit;

use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler;
use Nowo\BeaconBundle\EventListener\BeaconPhpWarningHandler;
use Nowo\BeaconBundle\NowoBeaconBundle;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;
use Symfony\Component\DependencyInjection\Container;
use WeakReference;

use function gc_collect_cycles;

final class NowoBeaconBundleTest extends TestCase
{
    public function testBundleCanBeConstructed(): void
    {
        $bundle = new NowoBeaconBundle();

        self::assertSame('NowoBeaconBundle', $bundle->getName());
    }

    public function testBootRegistersFatalAndPhpWarningHandlers(): void
    {
        $fatal     = new BeaconFatalErrorHandler(new NullBeaconClient(), true);
        $warning   = new BeaconPhpWarningHandler(new NullBeaconClient(), true);
        $container = new Container();
        $container->set(BeaconFatalErrorHandler::class, $fatal);
        $container->set(BeaconPhpWarningHandler::class, $warning);

        $bundle = new NowoBeaconBundle();
        $bundle->setContainer($container);
        $bundle->boot();
        $bundle->boot();

        self::assertTrue((new ReflectionProperty(BeaconFatalErrorHandler::class, 'registered'))->getValue($fatal));
        self::assertTrue((new ReflectionProperty(BeaconPhpWarningHandler::class, 'registered'))->getValue($warning));

        $warning->unregister();
    }

    public function testBootIsNoopWithoutContainerOrHandler(): void
    {
        $bundle = new NowoBeaconBundle();
        $bundle->setContainer(null);
        $bundle->boot();

        $container = new Container();
        $container->set(BeaconFatalErrorHandler::class, new stdClass());
        $container->set(BeaconPhpWarningHandler::class, new stdClass());
        $bundle->setContainer($container);
        $bundle->boot();

        $bundle->setContainer(new Container());
        $bundle->boot();

        $this->addToAssertionCount(1);
    }

    public function testShutdownReleasesTheHandlersSoTheContainerIsFreed(): void
    {
        $container = new Container();
        $container->set(BeaconFatalErrorHandler::class, new BeaconFatalErrorHandler(new NullBeaconClient(), true));
        $container->set(BeaconPhpWarningHandler::class, new BeaconPhpWarningHandler(new NullBeaconClient(), true));
        $fatal   = WeakReference::create($container->get(BeaconFatalErrorHandler::class));
        $warning = WeakReference::create($container->get(BeaconPhpWarningHandler::class));

        $bundle = new NowoBeaconBundle();
        $bundle->setContainer($container);
        $bundle->boot();
        $bundle->shutdown();
        unset($container);
        $bundle->setContainer(null);
        gc_collect_cycles();

        self::assertNull($fatal->get());
        self::assertNull($warning->get());
    }

    public function testShutdownIsNoopWithoutContainerOrHandler(): void
    {
        $bundle = new NowoBeaconBundle();
        $bundle->setContainer(null);
        $bundle->shutdown();

        $container = new Container();
        $container->set(BeaconFatalErrorHandler::class, new stdClass());
        $bundle->setContainer($container);
        $bundle->shutdown();

        $this->addToAssertionCount(1);
    }
}
