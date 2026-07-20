<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\EventListener\BeaconExceptionListener;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final class BeaconExceptionListenerTest extends TestCase
{
    public function testSubscribesToExceptionEvent(): void
    {
        self::assertArrayHasKey(KernelEvents::EXCEPTION, BeaconExceptionListener::getSubscribedEvents());
    }

    public function testReportsExceptionWhenEnabled(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client
            ->expects(self::once())
            ->method('captureException')
            ->with(
                self::isInstanceOf(RuntimeException::class),
                self::callback(static function (array $extra): bool {
                    return str_ends_with($extra['request_uri'], '/boom?foo=1')
                        && $extra['request_method'] === 'POST';
                }),
            );

        $listener = new BeaconExceptionListener($client, true, []);
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $event    = new ExceptionEvent(
            $kernel,
            Request::create('/boom?foo=1', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            new RuntimeException('boom'),
        );
        $listener->onKernelException($event);
    }

    public function testIgnoresConfiguredExceptions(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::never())->method('captureException');

        $listener = new BeaconExceptionListener($client, true, [RuntimeException::class]);
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $event    = new ExceptionEvent($kernel, Request::create('/boom'), HttpKernelInterface::MAIN_REQUEST, new RuntimeException('boom'));
        $listener->onKernelException($event);
    }

    public function testDoesNothingWhenListenerIsDisabled(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->expects(self::never())->method('isEnabled');
        $client->expects(self::never())->method('captureException');

        $listener = new BeaconExceptionListener($client, false, []);
        $listener->onKernelException($this->createEvent(new RuntimeException('boom')));
    }

    public function testDoesNothingWhenClientIsDisabled(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->expects(self::once())->method('isEnabled')->willReturn(false);
        $client->expects(self::never())->method('captureException');

        $listener = new BeaconExceptionListener($client, true, []);
        $listener->onKernelException($this->createEvent(new RuntimeException('boom')));
    }

    public function testIgnoresSubclassOfConfiguredException(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::never())->method('captureException');

        $listener = new BeaconExceptionListener($client, true, [RuntimeException::class]);
        $listener->onKernelException($this->createEvent(new class('boom') extends RuntimeException {
        }));
    }

    public function testOmitsRequestExtraWhenSendRequestIsDisabled(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client
            ->expects(self::once())
            ->method('captureException')
            ->with(
                self::isInstanceOf(RuntimeException::class),
                [],
            );

        $listener = new BeaconExceptionListener($client, true, [], false);
        $listener->onKernelException($this->createEvent(new RuntimeException('boom')));
    }

    private function createEvent(Throwable $throwable): ExceptionEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ExceptionEvent(
            $kernel,
            Request::create('/boom?foo=1', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }
}
