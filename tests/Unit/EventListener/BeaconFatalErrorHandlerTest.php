<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\EventListener\BeaconFatalErrorHandler;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WeakReference;

use function gc_collect_cycles;

final class BeaconFatalErrorHandlerTest extends TestCase
{
    public function testRegisterRegistersTheShutdownFunctionOnceAndTracksTheLatestHandler(): void
    {
        $first  = new BeaconFatalErrorHandler(new NullBeaconClient(), true);
        $second = new BeaconFatalErrorHandler(new NullBeaconClient(), true);
        $first->register();
        $second->register();

        self::assertTrue((new ReflectionProperty(BeaconFatalErrorHandler::class, 'shutdownFunctionRegistered'))->getValue());
        self::assertSame($second, $this->active());

        $first->unregister();
        self::assertSame($second, $this->active(), 'a handler that is no longer active leaves the active one alone');

        $second->unregister();
        $second->unregister();
        self::assertNull($this->active());
    }

    public function testDispatchShutdownForwardsToTheActiveHandler(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->expects(self::once())->method('isEnabled')->willReturn(false);

        $handler = new BeaconFatalErrorHandler($client, true);
        $handler->register();
        BeaconFatalErrorHandler::dispatchShutdown();

        $handler->unregister();
        BeaconFatalErrorHandler::dispatchShutdown();
    }

    public function testUnregisteredHandlerIsFreed(): void
    {
        $handler = new BeaconFatalErrorHandler(new NullBeaconClient(), true);
        $handler->register();
        $handler->unregister();
        $reference = WeakReference::create($handler);
        unset($handler);
        gc_collect_cycles();

        self::assertNull($reference->get());
    }

    private function active(): ?BeaconFatalErrorHandler
    {
        $value = (new ReflectionProperty(BeaconFatalErrorHandler::class, 'active'))->getValue();

        return $value instanceof BeaconFatalErrorHandler ? $value : null;
    }
}
