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
use Symfony\Component\HttpKernel\KernelEvents;
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

    public function testMessengerListenerSkipsWhenDisabledOrRetryingOrIgnored(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::never())->method('captureException');

        $envelope = new Envelope(new stdClass());

        $disabled = new BeaconMessengerFailedListener($client, false, []);
        $disabled(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('x')));

        $retryEvent = new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('x'));
        $retryEvent->setForRetry();
        (new BeaconMessengerFailedListener($client, true, []))($retryEvent);

        $ignored = new BeaconMessengerFailedListener($client, true, [RuntimeException::class]);
        $ignored(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('ignored')));

        $clientReports = $this->createMock(BeaconClientInterface::class);
        $clientReports->method('isEnabled')->willReturn(true);
        $clientReports->expects(self::once())->method('captureException');
        /** @var list<mixed> $ignore */
        $ignore = ['', 123];
        (new BeaconMessengerFailedListener($clientReports, true, $ignore))(
            new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('report')),
        );
    }

    public function testMessengerListenerSkipsWhenClientDisabled(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(false);
        $client->expects(self::never())->method('captureException');

        $listener = new BeaconMessengerFailedListener($client, true, []);
        $listener(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('x')));
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

        self::assertArrayHasKey(KernelEvents::REQUEST, BeaconRequestTransactionListener::getSubscribedEvents());
        self::assertArrayHasKey(KernelEvents::TERMINATE, BeaconRequestTransactionListener::getSubscribedEvents());

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok', 200)));
    }

    public function testRequestTransactionListenerUsesMethodPathWhenRouteMissing(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureTransaction')->with(
            'GET /api/items',
            self::anything(),
            self::anything(),
            [],
            self::isType('array'),
        );

        $listener = new BeaconRequestTransactionListener($client, true);
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $request  = Request::create('/api/items');

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok', 200)));
    }

    public function testRequestTransactionListenerSkipsWhenDisabled(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->expects(self::never())->method('captureTransaction');

        $listener = new BeaconRequestTransactionListener($client, false);
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $request  = Request::create('/dashboard');

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
