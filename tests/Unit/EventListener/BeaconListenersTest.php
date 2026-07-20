<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\EventListener\BeaconRequestTransactionListener;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class BeaconListenersTest extends TestCase
{
    public function testMessengerListenerReportsOnlyFinalFailures(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException');

        $listener = new BeaconMessengerFailedListener($client, true, []);
        $envelope = new Envelope(new stdClass());
        $event    = new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('boom'));
        // Default willRetry is false when not set for retry — ensure final failure path
        $listener($event);
    }

    public function testRequestTransactionListenerCapturesOnTerminate(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureTransaction');

        $listener = new BeaconRequestTransactionListener($client, true);
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $request  = Request::create('/dashboard');
        $request->attributes->set('_route', 'dashboard_home');

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok', 200)));
    }

    public function testRequestTransactionListenerSkipsHealth(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::never())->method('captureTransaction');

        $listener = new BeaconRequestTransactionListener($client, true);
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $request  = Request::create('/health/live');

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok', 200)));
    }
}
